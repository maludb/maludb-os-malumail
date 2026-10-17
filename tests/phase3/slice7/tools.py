"""Slice 7's tool proof (delegation-access.md "MCP tools this slice makes real"): mailbox_access, mailbox_access_log (the records queries) and
mailbox_trail (the activity query), run against the scratch database as the records role — the same views as the screens. As Pat (26, the
owner of priya@), the Mail admin (1), Dana (30: the department's view), Marco (27: nothing on it any more) and the Expert (40). Run by run.sh
after checks.php, whose rows it reads."""

import asyncio
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / "mcp"))
import asyncpg  # noqa: E402

import activity_queries as aq  # noqa: E402
import records_server as rs  # noqa: E402

FAILS = PASSES = 0
DB = os.environ["DB_NAME"]
import json  # noqa: E402

SEED = json.loads(Path(os.environ["ML_P2_SEED"]).read_text())
P, E, S = SEED["priya"], SEED["expert"], SEED["sales"]


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
    Pat, Dana, Adm, Marco, Exp = rs.Caller(26), rs.Caller(30), rs.Caller(1), rs.Caller(27), rs.Caller(40)

    print("mailbox_access")
    a = await rs.mailbox_access(pool, Pat, P)
    kinds = {(r["principal_kind"], r["principal"]) for r in a.get("rows", [])}
    check(a["ok"] and a["kind"] == "person" and a["owner"] == "SMOKE Priya" and ("department", "Sales") in kinds and ("agent", "SMOKE Expert") in kinds,
          "Pat: the owner, the Sales department's row and the Expert's", a)
    check(all(r["level"] in ("view", "send") and r["break_glass"] is False and r["reason"] is None for r in a["rows"]), "…levels, none of them break-glass")
    a = await rs.mailbox_access(pool, Dana, P)
    check(a["ok"] and a["your_level"] == "view" and a["rows"] == [], "Dana (the department's view): her own standing, no one else's row", a)
    a = await rs.mailbox_access(pool, Marco, P)
    check(a["ok"] is False and a["error"]["code"] == "not_found", "Marco, with no row: not_found, as if it did not exist")
    a = await rs.mailbox_access(pool, Adm, P)
    check(a["ok"] and a["your_level"] == "none" and len(a["rows"]) == 2, "the Mail admin sees every row without holding a level on a person's mailbox", a)
    a = await rs.mailbox_access(pool, Adm, E)
    check(a["ok"] and a["kind"] == "agent" and a["by_role"][0]["who"] == "every Mail admin" and any(r["is_clerk"] and r["level"] == "triage" for r in a["rows"]),
          "an agent's mailbox: the Mail admins at full by role, the Clerk at triage", a)
    a = await rs.mailbox_access(pool, Exp, P)
    check(a["ok"] and a["your_level"] == "send" and [r["principal"] for r in a["rows"]] == ["SMOKE Expert"], "the Expert: its own row on Pat's mailbox, nothing more", a)
    a = await rs.mailbox_access(pool, Pat, S)
    check(a["ok"] is False or a["owner"] is None, "a shared mailbox has no owner")
    check(sql(f"SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND actor_member_id = 1 AND mailbox_id = {P}") != "0", "the Mail admin's read of Pat's mailbox is in Pat's access log (mcp.read)")

    print("mailbox_access_log")
    l = await rs.mailbox_access_log(pool, Pat, P)
    dids = {x["did"] for x in l.get("looks", [])}
    check(l["ok"] and {"mailbox.view", "message.open", "permission.break_glass"} <= dids and all(x["who"] != "SMOKE Priya" for x in l["looks"]),
          "Pat reads who looked: the list, the conversation, the emergency grant — never herself", l)
    check(all(x["through"] in ("the webmail", "a tool", "another application") for x in l["looks"]) and any(x["through"] == "a tool" for x in l["looks"]), "…each with its door; the Mail admin's tool read says so")
    l = await rs.mailbox_access_log(pool, Pat, P, who="SMOKE Owner", limit=2)
    check(l["ok"] and len(l["looks"]) == 2 and all(x["who"] == "SMOKE Owner" for x in l["looks"]), "filtered by person, limited", l)
    l = await rs.mailbox_access_log(pool, Pat, P, since="2999-01-01")
    check(l["ok"] and l["looks"] == [], "since the far future: nothing")
    check((await rs.mailbox_access_log(pool, Pat, P, since="soon"))["error"]["code"] == "bad_query", "a bad date is refused in words")
    check((await rs.mailbox_access_log(pool, Pat, P, who="Nobody At All"))["error"]["code"] == "not_found", "an unknown person is not_found")
    l = await rs.mailbox_access_log(pool, Adm, P)
    check(l["ok"] and len(l["looks"]) > 0, "the Mail admin reads it (access.audit)")
    l = await rs.mailbox_access_log(pool, Dana, P)
    check(l["ok"] is False and l["error"]["code"] == "below_level", "Dana: below_level", l)
    l = await rs.mailbox_access_log(pool, Exp, P)
    check(l["ok"] is False and l["error"]["code"] == "below_level", "the Expert: below_level")
    l = await rs.mailbox_access_log(pool, Pat, E)
    check(l["ok"] is True, "Pat, full on the agent's mailbox, reads ITS log (an overseer)")
    l = await rs.mailbox_access_log(pool, Dana, E)
    check(l["ok"] is False and l["error"]["code"] == "below_level", "…Dana, at triage, does not")

    print("mailbox_trail (activity)")
    t = await aq.mailbox_trail(pool, Pat, P)
    check(t["ok"] and {x["did"] for x in t["opened"]} >= {"mailbox.view", "message.open"}, "Pat: who opened her mailbox, when, through what", t)
    t = await aq.mailbox_trail(pool, Adm, P, since="2000-01-01", limit=3)
    check(t["ok"] and len(t["opened"]) == 3, "the Mail admin: the same, limited")
    t = await aq.mailbox_trail(pool, Dana, P)
    check(t["ok"] is False and t["error"]["code"] == "below_level", "Dana: below_level")
    t = await aq.mailbox_trail(pool, Pat, E)
    check(t["ok"] is False and t["error"]["code"] == "below_level", "Pat, an overseer of the agent's mailbox, is below_level on the TRAIL (owner and Mail admins only)")
    t = await aq.mailbox_trail(pool, Adm, E)
    check(t["ok"] is True, "…the Mail admin reads an agent mailbox's trail")
    check((await aq.mailbox_trail(pool, Marco, P))["error"]["code"] == "not_found", "Marco: not_found")
    await pool.close()


asyncio.run(main())
print(f"{FAILS} FAILED ({PASSES} passed)" if FAILS else f"all {PASSES} passed")
sys.exit(1 if FAILS else 0)
