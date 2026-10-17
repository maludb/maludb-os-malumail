"""Slice 8's tool proof (correspondents.md "MCP tools this slice makes real"): correspondence, find_correspondents and find_members, run against the
scratch database as the records role — the same views as the screens. As Pat (26, owner of priya@), Dana (30: view on priya@), the Expert (40, an
agent acting for Pat, K38), Marco (27: no mailbox). Run by run.sh after checks.php, whose rows it reads."""

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
P, E, S, D = SEED["priya"], SEED["expert"], SEED["sales"], SEED["dana"]


def check(cond, label, detail=""):
    global FAILS, PASSES
    if cond:
        PASSES += 1
        print(f"  ok   {label}")
    else:
        FAILS += 1
        print(f"  FAIL {label}" + (f" — {str(detail)[:500]}" if detail else ""))


def sql(q: str) -> str:
    return subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-At", "-d", DB, "-c", q], capture_output=True, text=True).stdout.strip()


async def main():
    pool = await asyncpg.create_pool(host=os.environ["DB_HOST"], port=int(os.environ["DB_PORT"]), database=DB, user=os.environ["MCP_RECORDS_DB_USER"],
                                     password=os.environ["MCP_RECORDS_DB_PASSWORD"], min_size=1, max_size=2)
    Pr, Dn, X, M, A = rs.Caller(26), rs.Caller(30), rs.Caller(40, on_behalf=26), rs.Caller(27), rs.Caller(40)

    print("find_correspondents")
    r = await rs.find_correspondents(pool, Pr, P, q="zed")
    check(r["ok"] and [c["address"] for c in r["correspondents"]] == ["zed.sent@zedco.example", "zoe@zedco.example", "zed.heard@zedco.example"] or {c["address"] for c in r["correspondents"]} == {"zed.sent@zedco.example", "zoe@zedco.example", "zed.heard@zedco.example"},
          "Pat finds the three Zeds in her mailbox", r)
    c = {x["address"]: x for x in r["correspondents"]}["zed.sent@zedco.example"]
    check(set(c) == {"address", "name", "first_seen", "last_seen", "sent", "received", "known", "known_how", "internal"} and c["sent"] == 5 and c["received"] == 3 and c["known"] and not c["internal"],
          "…each with the resolver's fields", c)
    r = await rs.find_correspondents(pool, Pr, P, known_only=True, limit=100)
    check(r["ok"] and all(x["known"] for x in r["correspondents"]) and len(r["correspondents"]) >= 3, "known_only")
    r = await rs.find_correspondents(pool, Pr, "priya@business.example", q="zoe")
    check(r["ok"] and len(r["correspondents"]) == 1, "a mailbox can be named by its address")
    check((await rs.find_correspondents(pool, Pr, P, limit=1000))["ok"] and len((await rs.find_correspondents(pool, Pr, P, limit=1000))["correspondents"]) == 100, "limit is capped at 100")
    check((await rs.find_correspondents(pool, M, P))["error"]["code"] == "not_found", "Marco: not_found, in the words of a mailbox that does not exist")
    check((await rs.find_correspondents(pool, Pr, SEED["support"]))["error"]["code"] == "not_found", "a mailbox she cannot open: not_found")
    before = int(sql(f"SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND mailbox_id = {P}"))
    await rs.find_correspondents(pool, Pr, P, q="zed")
    check(int(sql(f"SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND mailbox_id = {P}")) == before, "the owner's own read writes no mcp.read")
    r = await rs.find_correspondents(pool, Dn, P, q="zed")
    check(r["ok"] and len(r["correspondents"]) == 3, "Dana (view) reads them too…")
    check(int(sql(f"SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND mailbox_id = {P} AND actor_member_id = 30")) == 1, "…and that is one mcp.read in Pat's access log")
    r = await rs.find_correspondents(pool, X, E, q="vendor")
    check(r["ok"] and {c["address"] for c in r["correspondents"]} >= {"approved@vendor.example", "agentonly@vendor.example"}, "the Expert reads its own mailbox's correspondents")
    check(next(c for c in r["correspondents"] if c["address"] == "approved@vendor.example")["known"] and not next(c for c in r["correspondents"] if c["address"] == "agentonly@vendor.example")["known"],
          "…the approved one known, the unreviewed one not")

    print("correspondence")
    r = await rs.correspondence(pool, Pr, "jon@supplier.example")
    check(r["ok"] and len(r["conversations"]) >= 3 and all("jon@supplier.example" in c["participants"] for c in r["conversations"]), "conversations with an address across the mailboxes she opens", [c["subject"] for c in r["conversations"]])
    check({c["mailbox"] for c in r["conversations"]} == {"priya@business.example", "expert@business.example"}, "…Pat's own mailbox and the Expert's, nothing else")
    d = await rs.correspondence(pool, Pr, "supplier.example")
    check(len(d["conversations"]) > len(r["conversations"]), "a domain finds more (Kay too)")
    check(all(set(c) == {"thread_id", "mailbox_id", "mailbox", "subject", "participants", "first_message_at", "last_message_at", "message_count", "snippet"} for c in d["conversations"]), "the fields, and no body")
    one = await rs.correspondence(pool, Pr, "jon@supplier.example", mailbox=P)
    check(one["ok"] and {c["mailbox"] for c in one["conversations"]} == {"priya@business.example"}, "one mailbox named")
    check((await rs.correspondence(pool, Pr, "jon@supplier.example", mailbox=SEED["support"]))["error"]["code"] == "not_found", "a mailbox she cannot open: not_found")
    check((await rs.correspondence(pool, Pr, "jon@supplier.example", limit=2))["conversations"].__len__() == 2, "limit")
    check(not (await rs.correspondence(pool, Pr, "nonsense"))["ok"] and not (await rs.correspondence(pool, Pr, ""))["ok"] and not (await rs.correspondence(pool, Pr, "jon@supplier.example", since="soon"))["ok"], "bad_query for a malformed ask")
    check((await rs.correspondence(pool, Pr, "jon@supplier.example", since="2999-01-01"))["conversations"] == [], "since")
    check((await rs.correspondence(pool, M, "jon@supplier.example"))["conversations"] == [], "Marco opens no mailbox: finds nothing")
    check("Sensitive quote" in json.dumps(r, default=str), "Pat, who marked it, finds the sensitive conversation")
    x = await rs.correspondence(pool, X, "jon@supplier.example", mailbox=E)
    check(x["ok"] and x["conversations"] == [], "the Expert (an agent) never finds the sensitive conversation (the only one it has with Jon)", [c["subject"] for c in x["conversations"]])
    dn = await rs.correspondence(pool, Dn, "jon@supplier.example")
    check({c["mailbox"] for c in dn["conversations"]} >= {"priya@business.example"}, "Dana (view) finds the conversations of Pat's mailbox")
    check(int(sql(f"SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND mailbox_id = {P} AND actor_member_id = 30 AND after->>'tool' = 'correspondence'")) == 1, "…logged once in Pat's access log, naming the tool")

    print("find_members")
    r = await rs.find_members(pool, Pr, q="dana")
    check(r["ok"] and any(m["display_name"] == "SMOKE Dana" and m["kind"] == "person" and m["email"] == "dana@example.invalid" for m in r["members"]), "a person by name, with the directory's address")
    r = await rs.find_members(pool, Pr, kind="agent", limit=50)
    check(r["ok"] and r["members"] and all(m["kind"] == "agent" and m["email"] is None for m in r["members"]), "agents: no address at all")
    check(set(r["members"][0]) == {"member_id", "display_name", "kind", "email"}, "…and the resolver's four fields only")
    check(not (await rs.find_members(pool, Pr, kind="robot"))["ok"], "an unknown kind is bad_query")
    check(not (await rs.find_members(pool, rs.Caller(999999), q="dana"))["members"], "a caller who is no member of this installation sees nobody")
    check(rs.TOOLS["correspondence"] is rs.correspondence and "find_correspondents" in rs.TOOLS and "find_members" in rs.TOOLS, "the three are in TOOLS")

    await pool.close()
    print(f"{PASSES} passed, {FAILS} failed")
    sys.exit(1 if FAILS else 0)


asyncio.run(main())
