"""Slice 6's tool proof (search-labels-snooze.md "MCP tools this slice makes real"): mail_search, find_folders, find_labels, find_templates and
records_search, run against the scratch database as the records role — the same views and the same ml_search() as the screens. As Priya,
Dana (a delegate), the Expert (an agent), the Mail admin and Marco (no mailbox). Run by run.sh after checks.php."""

import asyncio
import json
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / "mcp"))
import asyncpg  # noqa: E402

import records_server as rs  # noqa: E402

FAILS = PASSES = 0
DB = os.environ["DB_NAME"]
SEED = json.loads(Path(os.environ["ML_P2_SEED"]).read_text())
B1, B3, H, AB, P, D = SEED["big1"], SEED["big3"], SEED["hush"], SEED["agentbox"], SEED["priya"], SEED["dana"]


def check(cond, label, detail=""):
    global FAILS, PASSES
    if cond:
        PASSES += 1
        print(f"  ok   {label}")
    else:
        FAILS += 1
        print(f"  FAIL {label}" + (f" — {str(detail)[:400]}" if detail else ""))


def sql(q: str) -> str:
    return subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-d", DB, "-c", q], capture_output=True, text=True).stdout.strip()


async def main():
    pool = await asyncpg.create_pool(host=os.environ["DB_HOST"], port=int(os.environ["DB_PORT"]), database=DB, user=os.environ["MCP_RECORDS_DB_USER"],
                                     password=os.environ["MCP_RECORDS_DB_PASSWORD"], min_size=1, max_size=2)
    Pr, Dn, X, O, M = rs.Caller(26), rs.Caller(30), rs.Caller(40, on_behalf=26), rs.Caller(1), rs.Caller(27)
    sql(f"INSERT INTO tags (slug, label, colour, created_by, mailbox_id) VALUES ('tool-label', 'Tool label', '#198754', 26, {B1})")
    sql(f"INSERT INTO record_tags (record_type, record_id, tag_id) SELECT 'mail_message', message_key_id, (SELECT id FROM tags WHERE slug = 'tool-label' AND mailbox_id = {B1}) "
        f"FROM mail_messages WHERE mailbox_id = {B1} AND uid IN (20, 21)")
    sql("INSERT INTO email_templates (name, subject, body, is_shared, owner_member_id, created_by) VALUES ('Tool shared', 'Hello', 'Shared body text', true, 26, 26), ('Tool private', '', 'Private body', false, 26, 26)")

    print("mail_search")
    r = await rs.mail_search(pool, Pr, "subject:invoice", mailbox=B1, limit=10)
    check(r["ok"] and len(r["conversations"]) == 10 and r["before"] is not None and r["unknown_operators"] == [], "Priya: a page of ten conversations and the cursor", r)
    c0 = r["conversations"][0]
    check(c0["mailbox"] == "big1@business.example" and c0["messages"] and "Invoice" in (c0["subject"] or ""), "…each with its mailbox, its matching message ids and its subject")
    seen = [x["thread_id"] for x in r["conversations"]]
    r2 = await rs.mail_search(pool, Pr, "subject:invoice", mailbox=B1, before=r["before"], limit=10)
    check(r2["ok"] and not set(seen) & {x["thread_id"] for x in r2["conversations"]}, "the next page repeats nothing")
    all_threads, cur = [], None
    while True:
        p = await rs.mail_search(pool, Pr, "in:anywhere", before=cur, limit=100)
        all_threads += [(x["mailbox_id"], x["thread_id"]) for x in p["conversations"]]
        cur = p["before"]
        if not cur:
            break
    check(len(all_threads) == len(set(all_threads)) > 1200, f"every mailbox she opens, paged to the end: {len(all_threads)} conversations, none twice")
    u = await rs.mail_search(pool, Pr, "foo:bar is:weird invoice", mailbox=B1)
    check(u["ok"] and u["unknown_operators"] == ["foo:", "is:weird"], "unknown operators are searched as words and named", u.get("unknown_operators"))
    bq = await rs.mail_search(pool, Pr, "before:2026-13-40", mailbox=B1)
    check(not bq["ok"] and bq["error"]["code"] == "bad_query", "a malformed date is bad_query", bq)
    bq2 = await rs.mail_search(pool, Pr, "larger:big", mailbox=B1)
    check(not bq2["ok"] and bq2["error"]["code"] == "bad_query", "a malformed size is bad_query too", bq2)
    check((await rs.mail_search(pool, Pr, "x", before="nonsense"))["error"]["code"] == "bad_query", "a made-up cursor is bad_query")
    check((await rs.mail_search(pool, Pr, ""))["error"]["code"] == "bad_query", "an empty query is bad_query")
    check((await rs.mail_search(pool, Pr, "lunch", mailbox=H))["error"]["code"] == "not_found", "a mailbox she cannot open is not_found")
    d = await rs.mail_search(pool, Dn, "in:anywhere", limit=100)
    check(d["ok"] and {x["mailbox"] for x in d["conversations"]} <= {"big1@business.example", "dana@business.example", "hush@business.example", "intake@business.example", "sales@business.example"},
          "Dana's search-all finds only her mailboxes")
    a = await rs.mail_search(pool, X, "in:anywhere", mailbox=AB, limit=100)
    sens = sql(f"SELECT m.thread_id FROM mail_messages m WHERE m.mailbox_id = {AB} AND m.uid = 5")
    check(a["ok"] and a["conversations"] and str(sens) not in {str(x["thread_id"]) for x in a["conversations"]}, "the Expert never finds the sensitive conversation", a.get("error"))
    pa = await rs.mail_search(pool, Pr, "in:anywhere", mailbox=AB, limit=100)
    check(sens in {str(x["thread_id"]) for x in pa["conversations"]}, "…which the person who marked it does")
    check((await rs.mail_search(pool, M, "lunch"))["conversations"] == [], "Marco (no mailbox) finds nothing")
    lab = await rs.mail_search(pool, Pr, "label:tool-label", mailbox=B1)
    check(lab["ok"] and lab["conversations"] and lab["conversations"][0]["labels"][0]["label"] == "Tool label", "a result carries its labels")
    check(int(sql("SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND after::text LIKE '%mail_search%'") or 0) >= 1, "a read of a mailbox that is not hers writes mcp.read")

    print("find_folders")
    f = await rs.find_folders(pool, Pr, P)
    check(f["ok"] and {"INBOX", "Sent"} <= {x["name"] for x in f["folders"]} and all(set(x) >= {"folder_id", "name", "special_use", "unread", "total"} for x in f["folders"]), "a mailbox's folders with unread and total", f)
    check((await rs.find_folders(pool, Pr, P, q="sen"))["folders"][0]["name"] == "Sent", "…narrowed by q")
    check((await rs.find_folders(pool, Dn, P))["error"]["code"] == "not_found", "Dana cannot list Priya's folders")

    print("find_labels")
    fl = await rs.find_labels(pool, Pr, B1)
    check(fl["ok"] and any(x["label"] == "Tool label" and x["conversations"] == 2 for x in fl["labels"]), "labels with the conversations carrying each", fl)
    check((await rs.find_labels(pool, Dn, B1))["ok"] and (await rs.find_labels(pool, Dn, B3))["error"]["code"] == "not_found", "a view holder lists them; a stranger gets not_found")
    check([x["label"] for x in (await rs.find_labels(pool, Pr, B1, q="tool"))["labels"]] == ["Tool label"], "…narrowed by q")

    print("find_templates")
    t = await rs.find_templates(pool, Pr)
    names = {x["name"] for x in t["templates"]}
    check(t["ok"] and {"Tool shared", "Tool private"} <= names and all("body" not in x for x in t["templates"]), "Priya: her own and the shared ones, without bodies", t)
    td = await rs.find_templates(pool, Dn, q="Tool")
    check({x["name"] for x in td["templates"]} == {"Tool shared"}, "Dana: the shared one only")
    check((await rs.find_templates(pool, Pr, q="shared", with_body=True))["templates"][0]["body"] == "Shared body text", "…the body on request")

    print("records_search")
    rr = await rs.records_search(pool, Pr, "tool")
    kinds = {(h["kind"]) for h in rr["hits"]}
    check(rr["ok"] and {"label", "template"} <= kinds, "across what she may open: labels and templates", kinds)
    rr = await rs.records_search(pool, Pr, "big")
    check({"mailbox"} <= {h["kind"] for h in rr["hits"]} and all(isinstance(h["id"], int) for h in rr["hits"]), "mailboxes, each with its kind and id")
    rr = await rs.records_search(pool, Pr, "Invoice 5")
    check(any(h["kind"] == "conversation" for h in rr["hits"]), "conversations by subject")
    rr = await rs.records_search(pool, Dn, "hush")
    check(any(h["kind"] == "mailbox" and h["label"].startswith("hush") for h in rr["hits"]), "Dana finds hush@")
    rr = await rs.records_search(pool, Pr, "hush")
    check(not any("hush" in str(h["label"]) for h in rr["hits"]), "…and Priya does not")
    check(not (await rs.records_search(pool, Pr, ""))["ok"], "an empty query is refused")
    await pool.close()
    print(f"{'all ' + str(PASSES) + ' passed' if not FAILS else str(FAILS) + ' FAILED (' + str(PASSES) + ' passed)'}")
    sys.exit(1 if FAILS else 0)


asyncio.run(main())
