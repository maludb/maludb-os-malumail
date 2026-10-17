"""Slice 8's proof of the K7 share (correspondents.md "The K7 share", Proof 4): `correspondence_index` through mcp/share_correspondence.py as the kernel's
call would reach it — a stand-in that passes the consumer the KERNEL named (the headers' values) and the consumer's own arguments. Mailboxes made
here carry no credential (nothing syncs them): an agent's, a shared one, two persons' (shared with applications only by their owners' switch), a
suspended one, an inactive one and one that fills a 256 kB answer. Run by run.sh after checks.php."""

import asyncio
import json
import os
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / "mcp"))
import asyncpg  # noqa: E402

import records_server as rs  # noqa: E402
import server_common  # noqa: E402
import share_correspondence as sc  # noqa: E402

FAILS = PASSES = 0
DB = os.environ["DB_NAME"]
APP_URL = os.environ["APP_URL"].rstrip("/")
ASK = "kay@acme-k7.example"
DOMAIN = "acme-k7.example"


def check(cond, label, detail=""):
    global FAILS, PASSES
    if cond:
        PASSES += 1
        print(f"  ok   {label}")
    else:
        FAILS += 1
        print(f"  FAIL {label}" + (f" — {str(detail)[:600]}" if detail else ""))


def sql(q: str) -> str:
    r = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-v", "ON_ERROR_STOP=1", "-d", DB, "-c", q], capture_output=True, text=True)
    return (r.stdout + r.stderr).strip()


SEED = f"""
INSERT INTO mailboxes (address, display_name, kind, owner_member_id, sync_state, last_sync_at) VALUES
 ('k7shared@business.example', 'K7 shared', 'shared', NULL, 'live', now()), ('k7agent@business.example', 'K7 agent', 'agent', 40, 'live', now()),
 ('k7pat@business.example', 'K7 Pat', 'person', 26, 'live', now()), ('k7dana@business.example', 'K7 Dana', 'person', 30, 'live', now()),
 ('k7susp@business.example', 'K7 suspended', 'shared', NULL, 'live', now()), ('k7off@business.example', 'K7 off', 'shared', NULL, 'live', now()),
 ('k7big@business.example', 'K7 big', 'shared', NULL, 'live', now());
INSERT INTO mail_folders (mailbox_id, name, special_use, uidvalidity, uidnext) SELECT id, 'INBOX', 'inbox', 1, 1000 FROM mailboxes WHERE address LIKE 'k7%@business.example';
-- three conversations in each (distinct subjects), the asked-about address among the participants; a body no answer may carry
INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, from_address, from_name, to_addresses, subject, sent_at, received_at, snippet, body_text)
SELECT f.id, 1, g, ml_message_key_for(f.mailbox_id, '<k7-' || f.mailbox_id || '-' || g || '@x>', encode(digest('k7' || f.mailbox_id || '-' || g, 'sha256'), 'hex')), '<k7-' || f.mailbox_id || '-' || g || '@x>',
       '{ASK}', 'Kay Acme', ARRAY[b.address::text], 'Conversation number ' || g || ' ' || b.address, now() - g * interval '1 day', now() - g * interval '1 day',
       repeat('snippet text ', 40), 'SECRETBODYTEXT should never leave'
  FROM mail_folders f JOIN mailboxes b ON b.id = f.mailbox_id CROSS JOIN generate_series(1, 3) g WHERE b.address LIKE 'k7%@business.example' AND b.address <> 'k7big@business.example';
INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, from_address, from_name, to_addresses, subject, sent_at, received_at, snippet, body_text)
SELECT f.id, 1, g, ml_message_key_for(f.mailbox_id, '<k7b-' || g || '@x>', encode(digest('k7b' || g, 'sha256'), 'hex')), '<k7b-' || g || '@x>',
       '{ASK}', 'Kay Acme', ARRAY[b.address::text], 'Big conversation ' || g || ' ' || md5(g::text), now() - g * interval '1 hour', now() - g * interval '1 hour', 'big snippet', 'SECRETBODYTEXT'
  FROM mail_folders f JOIN mailboxes b ON b.id = f.mailbox_id CROSS JOIN generate_series(1, 100) g WHERE b.address = 'k7big@business.example';
UPDATE mailboxes SET suspended_at = now() WHERE address = 'k7susp@business.example';
UPDATE mailboxes SET active = false WHERE address = 'k7off@business.example';
UPDATE mailboxes SET share_with_apps = false WHERE address = 'k7big@business.example';
"""


async def main():
    out = sql(SEED)
    check("ERROR" not in out, "the scratch mailboxes are seeded", out)
    mb = {a: int(i) for a, i in (r.split("|") for r in sql("SELECT split_part(address, '@', 1), id FROM mailboxes WHERE address LIKE 'k7%@business.example'").splitlines())}
    pool = await asyncpg.create_pool(host=os.environ["DB_HOST"], port=int(os.environ["DB_PORT"]), database=DB, user=os.environ["MCP_RECORDS_DB_USER"],
                                     password=os.environ["MCP_RECORDS_DB_PASSWORD"], min_size=1, max_size=2)

    def mbs(ans):
        return {c["mailbox_address"].split("@")[0] for c in ans["conversations"]}

    async def share(params, consumer="sales_crm", agent=40):
        return await sc.correspondence_index(pool, consumer, agent, params)

    def n_k7():
        return int(sql("SELECT count(*) FROM activity_log WHERE action = 'k7.read'"))

    print("the door is the kernel's alone")
    check("correspondence_index" in server_common.KERNEL_TOOLS and "correspondence_index" in server_common.KERNEL_ONLY, "the kernel's token reaches it and nobody else's does (KERNEL_TOOLS, KERNEL_ONLY)")
    n0 = n_k7()
    for bad_consumer in (None, "", "Sales CRM", "x" * 61, 42, "a;b"):
        r = await share({"with": ASK}, consumer=bad_consumer)
        check(not r["ok"] and r["error"]["code"] == "consumer_required", f"no named consumer {bad_consumer!r}: refused, nothing read")
    check(n_k7() == n0, "…and nothing written")

    print("who answers: agents' and shared mailboxes by default, a person's by its owner's switch")
    r = await share({"with": ASK})
    check(r["ok"] and r["schema"] == "os.malumail-correspondence/1" and r["truncated"] is False, "an answer in os.malumail-correspondence/1", r)
    check(mbs(r) == {"k7shared", "k7agent"}, "an agent's and a shared mailbox answer — a person's two do not, a suspended one and an inactive one do not", mbs(r))
    check(len(r["conversations"]) == 6, "three conversations from each")
    keys = sorted(r["conversations"][0].keys())
    check(keys == sorted(["thread_id", "mailbox", "mailbox_address", "subject", "participants", "first_message_at", "last_message_at", "message_count", "snippet", "url"]),
          "exactly the spec's fields", keys)
    blob = json.dumps(r, default=str)
    check("SECRETBODYTEXT" not in blob and "body" not in "".join(keys), "no body, anywhere in the answer")
    check(all(len(c["snippet"]) <= 200 for c in r["conversations"]) and max(len(c["snippet"]) for c in r["conversations"]) == 200, "snippets are cut at 200 characters")
    c0 = r["conversations"][0]
    check(c0["url"] == f"{APP_URL}/m/{c0['mailbox']}/t/{c0['thread_id']}" and c0["mailbox"] in (mb["k7shared"], mb["k7agent"]), "the url is the conversation's own page", c0["url"])
    check([c["last_message_at"] for c in r["conversations"]] == sorted((c["last_message_at"] for c in r["conversations"]), reverse=True), "newest first")
    d = await share({"with": DOMAIN})
    check(mbs(d) == {"k7shared", "k7agent"} and len(d["conversations"]) == 6, "a domain asks the same question")
    check((await share({"with": "Other-Domain.EXAMPLE"}))["conversations"] == [] and (await share({"with": "nobody@acme-k7.example"}))["conversations"] == [], "another domain or address: nothing")
    check(mbs(await share({"with": ASK.upper()})) == {"k7shared", "k7agent"}, "case does not matter")

    print("the owner's switch is the only door to a person's mailbox")
    before = mbs(await share({"with": ASK}))
    out = sql(f"SELECT ml_mailbox_settings_save({mb['k7dana']}, 30, '{{}}'::jsonb, NULL, true)")
    check("ERROR" not in out, "Dana (its owner) switches sharing on", out)
    check(mbs(await share({"with": ASK})) == before | {"k7dana"} and "k7pat" not in mbs(await share({"with": ASK})), "her mailbox answers now; Pat's, whose owner did nothing, still does not")
    out = sql(f"SELECT ml_mailbox_settings_save({mb['k7pat']}, 30, '{{}}'::jsonb, NULL, true)")
    check("insufficient" in out.lower() or "ERROR" in out, "Dana cannot switch Pat's mailbox on", out)
    check("k7pat" not in mbs(await share({"with": ASK})), "…so it still does not answer")
    out = sql(f"SELECT ml_mailbox_settings_save({mb['k7dana']}, 30, '{{}}'::jsonb, NULL, false)")
    check("k7dana" not in mbs(await share({"with": ASK})), "switched off again: it closes at once")
    sql(f"SELECT ml_mailbox_settings_save({mb['k7dana']}, 30, '{{}}'::jsonb, NULL, true)")

    print("never a sensitive conversation, a suspended or an inactive mailbox")
    sens = sql(f"SELECT m.message_key_id FROM mail_messages m WHERE m.mailbox_id = {mb['k7shared']} AND m.uid = 2")
    sql(f"INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, sensitive, sensitive_by) VALUES ({mb['k7shared']}, {sens}, true, 26)")
    r = await share({"with": ASK})
    check(not any(c["mailbox"] == mb["k7shared"] and "number 2" in c["subject"] for c in r["conversations"]) and len(r["conversations"]) == 9 - 1, "a conversation marked sensitive is not in the answer", len(r["conversations"]))
    check(not any(c["mailbox_address"].startswith(("k7susp", "k7off")) for c in r["conversations"]), "a suspended or an inactive mailbox never answers")
    sql(f"UPDATE mailboxes SET share_with_apps = true WHERE address IN ('k7susp@business.example', 'k7off@business.example')")
    check(not any(c["mailbox_address"].startswith(("k7susp", "k7off")) for c in (await share({"with": ASK}))["conversations"]), "…even marked shared")

    print("since, limit")
    r = await share({"with": ASK, "limit": 3})
    check(len(r["conversations"]) == 3 and (await share({"with": ASK, "limit": 1000}))["ok"], "limit holds (and 1000 is clamped, not an error)")
    check(len((await share({"with": ASK, "limit": 0}))["conversations"]) == 1, "limit 0 is 1")
    r = await share({"with": ASK, "since": (__import__("datetime").datetime.now(__import__("datetime").timezone.utc) - __import__("datetime").timedelta(hours=36)).isoformat()})
    check(r["ok"] and r["conversations"] and all("number 1" in c["subject"] for c in r["conversations"]), "since: only what changed after it (the newest day)", [c["subject"] for c in r["conversations"]])
    check((await share({"with": ASK, "since": "2999-01-01"}))["conversations"] == [], "since in the future: nothing")
    for badp in ({"with": ""}, {}, {"with": "not an address"}, {"with": "a b@c.d"}, {"with": ASK, "since": "yesterday-ish"}, {"with": ASK, "limit": "many"}):
        r = await share(badp)
        check(not r["ok"] and r["error"]["code"] == "bad_query", f"{badp}: bad_query")

    print("every answer is logged, per mailbox, naming its consumer")
    sql("DELETE FROM activity_log WHERE action = 'k7.read'")
    r = await share({"with": DOMAIN})
    rows = [json.loads(x) for x in sql("SELECT row_to_json(t) FROM (SELECT mailbox_id, actor_member_id, source, after FROM activity_log WHERE action = 'k7.read' ORDER BY mailbox_id) t").splitlines()]
    want = {}
    for c in r["conversations"]:
        want[c["mailbox"]] = want.get(c["mailbox"], 0) + 1
    check({x["mailbox_id"]: x["after"]["count"] for x in rows} == want and len(rows) == len(want), "one k7.read row per mailbox the answer drew on, with its count", rows)
    check(all(x["source"] == "application" and x["actor_member_id"] == 40 and x["after"]["consumer"] == "sales_crm" and x["after"]["consumer_agent"] == 40 and x["after"]["domain"] == DOMAIN for x in rows),
          "…naming the consumer, its agent and the domain asked about")
    txt = json.dumps(rows)
    check(not re.search(r"Conversation number|snippet|SECRET|kay@", txt), "…and not a subject, a snippet, a body or the address")
    n = n_k7()
    r = await share({"with": ASK}, agent=None)
    rows2 = sql("SELECT actor_member_id IS NULL AND after->>'consumer_agent' IS NULL FROM activity_log WHERE action = 'k7.read' ORDER BY id DESC LIMIT 1")
    check(n_k7() > n and rows2 == "t", "no agent asked: the row names none")
    r = await share({"with": ASK, "as_agent": 26}, agent=40)
    last = sql("SELECT actor_member_id FROM activity_log WHERE action = 'k7.read' ORDER BY id DESC LIMIT 1")
    check(last == "40", "an `as_agent` the consumer supplied is ignored: the kernel's header names the agent", last)
    await share({"with": ASK}, agent=4242)
    last = sql("SELECT coalesce(actor_member_id::text, 'none') || '|' || coalesce(after->>'consumer_agent', 'none') FROM activity_log WHERE action = 'k7.read' ORDER BY id DESC LIMIT 1")
    check(last == "none|none", "an agent id that is no member of this installation is recorded as none", last)
    await share({"with": ASK}, agent=True)
    last = sql("SELECT coalesce(actor_member_id::text, 'none') FROM activity_log WHERE action = 'k7.read' ORDER BY id DESC LIMIT 1")
    check(last == "none", "…and a boolean is not an agent id")
    r = await share({"with": "nobody@nowhere-k7.example"})
    n = n_k7()
    await share({"with": "nobody@nowhere-k7.example"})
    check(n_k7() == n, "an answer that drew on no mailbox writes no row (nothing of any mailbox was read out)")

    print("the owner reads it in the access log; others do not")
    async def log_as(member):
        async with pool.acquire() as con, con.transaction():
            await rs._scoped(con, rs.Caller(member))
            return await con.fetch("SELECT consumer, action FROM mcp_mailbox_access_log WHERE mailbox_id = $1 AND action = 'k7.read'", mb["k7dana"])
    check(len(await log_as(30)) >= 1 and (await log_as(30))[0]["consumer"] == "sales_crm", "Dana, whose mailbox it was, reads the K7 read and its consumer")
    check(await log_as(27) == [] and await log_as(26) == [], "Marco and Pat (not the owner, no audit right) read nothing of it")
    check(len(await log_as(1)) >= 1, "the Mail admin (access.audit) reads it")
    async with pool.acquire() as con, con.transaction():
        await rs._scoped(con, rs.Caller(30))
        looks = await rs.mailbox_access_log(pool, rs.Caller(30), mb["k7dana"])
    check(looks["ok"] and any(x["did"] == "k7.read" and x["through"] == "another application" and x["consumer"] == "sales_crm" for x in looks["looks"]), "mailbox_access_log tells it: read for another application, by sales_crm")

    print("the 256 kB cap")
    sql(f"UPDATE mailboxes SET share_with_apps = true WHERE address = 'k7big@business.example'")
    sql(f"UPDATE mail_threads SET participants = ARRAY['{ASK}'] || ARRAY(SELECT 'participant-' || g || '-' || repeat('x', 28) || '@bigcompany.example' FROM generate_series(1, 90) g) WHERE mailbox_id = {mb['k7big']}")
    sql("DELETE FROM activity_log WHERE action = 'k7.read'")
    r = await share({"with": ASK, "limit": 100})
    size = len(json.dumps(r, default=str).encode())
    bigs = [c for c in r["conversations"] if c["mailbox"] == mb["k7big"]]
    check(r["ok"] and r["truncated"] is True and size <= 256 * 1024 and 20 < len(r["conversations"]) < 100, f"past 256 kB the rows are cut and `truncated` says so ({size} bytes, {len(r['conversations'])} rows)")
    cnt = {int(x.split("|")[0]): int(x.split("|")[1]) for x in sql("SELECT mailbox_id || '|' || (after->>'count') FROM activity_log WHERE action = 'k7.read'").splitlines()}
    check(cnt.get(mb["k7big"]) == len(bigs) and sum(cnt.values()) == len(r["conversations"]), "the log counts what was SENT, not what was found", cnt)
    r = await share({"with": ASK, "limit": 5})
    check(r["truncated"] is False, "a small answer is not truncated")

    print("the read servers' role cannot reach what it should not")
    for q in ("SELECT 1 FROM mailboxes LIMIT 1", "SELECT 1 FROM mail_messages LIMIT 1", "SELECT body_text FROM mail_messages LIMIT 1"):
        try:
            async with pool.acquire() as con:
                await con.fetch(q)
            check(False, f"the records role reads {q!r}")
        except asyncpg.InsufficientPrivilegeError:
            check(True, f"the records role is refused: {q}")
    try:
        async with pool.acquire() as con:
            await con.fetch("SELECT ml_correspondent_received_for(1)")
        check(False, "the records role cannot write correspondents")
    except asyncpg.InsufficientPrivilegeError:
        check(True, "the records role cannot call the upkeep function")

    await pool.close()
    print(f"{PASSES} passed, {FAILS} failed")
    sys.exit(1 if FAILS else 0)


asyncio.run(main())
