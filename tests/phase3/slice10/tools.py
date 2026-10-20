"""Slice 10's tool proof (home-admin.md "MCP tools this slice makes real"): mailbox_health as the records role — every mailbox with its last error for the
Mail admin (ml_sync_health()), only the mailboxes a person opens (and no error text) for everyone else, the state filters, and the SQL function
answering nothing to a person who is no Mail admin. Run by run.sh after checks.php (which leaves Ada and the shared mailbox failing)."""

import asyncio
import json
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / "mcp"))
import asyncpg  # noqa: E402

import records_server as rs  # noqa: E402

FAILS = PASSES = 0
SEED = json.loads(Path(os.environ["ML_P2_SEED"]).read_text())


def check(cond, label, detail=""):
    global FAILS, PASSES
    if cond:
        PASSES += 1
        print(f"  ok   {label}")
    else:
        FAILS += 1
        print(f"  FAIL {label}" + (f" — {str(detail)[:400]}" if detail else ""))


async def main():
    pool = await asyncpg.create_pool(host=os.environ["DB_HOST"], port=int(os.environ["DB_PORT"]), database=os.environ["DB_NAME"], user=os.environ["MCP_RECORDS_DB_USER"],
                                     password=os.environ["MCP_RECORDS_DB_PASSWORD"], min_size=1, max_size=2)
    admin, priya, dana, expert = rs.Caller(1), rs.Caller(26), rs.Caller(30), rs.Caller(40)

    print("mailbox_health")
    a = await rs.mailbox_health(pool, admin)
    names = {m["address"].split("@")[0]: m for m in a["mailboxes"]}
    check(a["ok"] and set(names) == set(SEED), "the Mail admin: every mailbox", sorted(names))
    check(names["ada"]["sync_state"] == "failed" and names["ada"]["credential"] == "failed" and "refused the password" in (names["ada"]["last_error"] or ""), "…with the failing mailbox's credential and last error", names["ada"])
    check(names["priya"]["sync_state"] == "live" and names["priya"]["credential"] == "ok" and names["priya"]["last_error"] is None, "…and Priya's live and clean")
    f = await rs.mailbox_health(pool, admin, state="failing")
    check({m["address"].split("@")[0] for m in f["mailboxes"]} == {"ada", "cred"}, "state=failing: the failed sync and the failed credential", f)
    p = await rs.mailbox_health(pool, priya)
    pn = {m["address"].split("@")[0] for m in p["mailboxes"]}
    check(p["ok"] and pn == {"priya", "sales", "expert"}, "Priya: only the mailboxes she opens", sorted(pn))
    check(all(m["last_error"] is None for m in p["mailboxes"]), "…and no error text")
    d = await rs.mailbox_health(pool, dana)
    check("priya" not in {m["address"].split("@")[0] for m in d["mailboxes"]}, "Dana does not see Priya's mailbox")
    s = await rs.mailbox_health(pool, admin, state="suspended")
    check(s["ok"] and s["mailboxes"] == [], "state=suspended: none")
    pz = await rs.mailbox_health(pool, admin, state="paused")
    check(pz["ok"] and pz["mailboxes"] == [], "state=paused: none")
    bad = await rs.mailbox_health(pool, admin, state="nonsense")
    check(not bad["ok"] and bad["error"]["code"] == "bad_query", "an unknown state: bad_query")
    lim = await rs.mailbox_health(pool, admin, limit=2)
    check(len(lim["mailboxes"]) == 2, "limit")
    async with pool.acquire() as con, con.transaction():
        await con.execute("SELECT set_config('app.member_id', '26', true)")
        n = await con.fetchval("SELECT count(*) FROM ml_sync_health()")
        await con.execute("SELECT set_config('app.member_id', '1', true)")
        n1 = await con.fetchval("SELECT count(*) FROM ml_sync_health()")
    check(n == 0 and n1 == len(SEED), "ml_sync_health() answers nothing to a person who is no Mail admin", (n, n1))
    print(f"all {PASSES} passed" if not FAILS else f"{FAILS} FAILED ({PASSES} passed)")
    sys.exit(1 if FAILS else 0)


asyncio.run(main())
