"""Slice 5's tool proof (oversight.md "MCP tools this slice makes real"): the six records queries and the two activity queries, run against
the scratch database as the records role — the same views and the same gatherer as the screens. As Priya (an overseer), Dana (triage, no
decider), the Clerk (42, triage on the Expert's mailbox), the Mail admin (1), Marco (none) and the Expert itself. Run by run.sh after
checks.php, whose held messages it reads from <state>/s5-held.json."""

import asyncio
import json
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
SEED = json.loads(Path(os.environ["ML_P2_SEED"]).read_text())
HELD = json.loads((Path(os.environ["ML_DEV_STATE"]) / "s5-held.json").read_text())
E, A, D = SEED["expert"], SEED["ada"], SEED["dana"]


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
    P, Dn, C, O, M, X = rs.Caller(26), rs.Caller(30), rs.Caller(42), rs.Caller(1), rs.Caller(27), rs.Caller(40)
    att = HELD["held"]["attachment"]

    print("review_queue")
    q = await rs.review_queue(pool, P)
    ids = {h["outbound"] for h in q["held"]}
    check(q["ok"] and att in ids and all(h["can_decide"] for h in q["held"] if h["mailbox"] == E), "Priya's queue: the Expert's held mail, hers to decide", q)
    check(all(isinstance(h["hold_reasons"], list) for h in q["held"]) and q["held"] == sorted(q["held"], key=lambda h: h["held_at"]), "…with its reasons, oldest first")
    qc = await rs.review_queue(pool, C)
    check(qc["ok"] and att in {h["outbound"] for h in qc["held"]} and not any(h["can_decide"] for h in qc["held"]), "the Clerk sees the Expert's queue and decides nothing", qc)
    qx = await rs.review_queue(pool, X)
    check(qx["ok"] and qx["held"] == [], "the Expert itself sees no review queue")
    qd = await rs.review_queue(pool, Dn)
    check(att in {h["outbound"] for h in qd["held"]} and not any(h["can_decide"] for h in qd["held"] if h["mailbox"] == E), "Dana (triage) sees it, not hers to decide")
    qr = await rs.review_queue(pool, P, reason="attachment")
    check({h["outbound"] for h in qr["held"]} == {att}, "filtered by reason")
    check((await rs.review_queue(pool, P, reason="nonsense"))["ok"] is False, "an unknown reason is refused in words")
    check((await rs.review_queue(pool, M, mailbox=E))["error"]["code"] == "not_found", "Marco: the Expert's mailbox is not_found")

    print("review_get")
    g = await rs.review_get(pool, P, att)
    check(g["ok"] and g["purpose"] == "answer the customer" and g["run_id"] == 9501 and g["hold_reasons"] == ["attachment"] and g["attachments"][0]["name"] == "figures.csv"
          and len(g["attachments"][0]["sha256"]) == 64, "the held message: purpose, run, reasons, the attachment with its digest", g)
    check("files@outside.example" in g["outside_recipients"] and g["text"].startswith("Body"), "…the outside recipient marked, the text")
    sql(f"UPDATE outbound_email SET body_html = '<p>See <a href=\"https://evil.example/login\">our site at bank.example</a> and https://plain.example/x</p>' WHERE id = {att}")
    g = await rs.review_get(pool, P, att)
    hosts = {link["host"] for link in g["links"]}
    check({"evil.example"} <= hosts and any(link["text"] == "our site at bank.example" and link["host"] == "evil.example" for link in g["links"]),
          "every link with its REAL host (the words say bank.example; the host is evil.example)", g["links"])
    check((await rs.review_get(pool, M, att))["error"]["code"] == "not_found" and (await rs.review_get(pool, X, att))["error"]["code"] == "not_found",
          "not_found to Marco and to the agent")
    check(sql(f"SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND mailbox_id = {E} AND actor_member_id = 26") != "0", "a read of the Expert's mailbox writes mcp.read")

    print("agent_mail_summary / oversight_summary")
    s = await rs.agent_mail_summary(pool, P, E)
    direct = json.loads(sql(f"SET app.member_id = '26'; SELECT ml_agent_mail_summary({E}, 7)").splitlines()[-1])
    check(s["ok"] and s["summary"] == direct, "agent_mail_summary is ml_agent_mail_summary() — the cards' own gatherer", (s, direct))
    check(s["summary"]["rejected"] >= 1 and s["summary"]["edited"] >= 1 and s["summary"]["expired"] >= 1 and s["summary"]["refused"].get("loop_rule") == 3, "…rejected, edited, expired, refusals by rule", s["summary"])
    check((await rs.agent_mail_summary(pool, M, E))["ok"] is False and (await rs.agent_mail_summary(pool, P, SEED["priya"]))["ok"] is False, "not_found to Marco; a person's mailbox has no summary")
    o = await rs.oversight_summary(pool, P)
    check(o["ok"] and [c["mailbox_id"] for c in o["cards"]] == [E], "oversight_summary: Priya's one card", o)
    oc = await rs.oversight_summary(pool, C)
    check([c["mailbox_id"] for c in oc["cards"]] == [E], "the Clerk's cards: the mailbox it holds triage on", oc)
    oo = await rs.oversight_summary(pool, O)
    check(sorted(c["mailbox_id"] for c in oo["cards"]) == sorted([E, A]), "the Mail admin's: both agent mailboxes")
    check((await rs.oversight_summary(pool, Dn))["cards"] == [], "Dana (no decider): none")

    print("unscreened_mail")
    u = await rs.unscreened_mail(pool, O)
    reasons = {x["reason"] for x in u["unscreened"]}
    check(u["ok"] and "no_overseer" in reasons and all(x["why"] for x in u["unscreened"]), "the Mail admin: the unscreened mail with why in words", u)
    up = await rs.unscreened_mail(pool, P)
    check(all(x["mailbox"] == E for x in up["unscreened"]), "Priya: only the Expert's", up)
    check((await rs.unscreened_mail(pool, M, mailbox=A))["error"]["code"] == "not_found", "Marco: Ada's mailbox not_found")

    print("agent_mail_policy_get")
    pg = await rs.agent_mail_policy_get(pool, P, E)
    check(pg["ok"] and pg["policy"]["send_mode"] == "hold_all" and len(pg["meanings"]) == 12 and pg["business"]["held_expiry_hours"] == 72 and pg["updated_by"],
          "the policy with each setting's meaning, the business's switches, who set it", pg)
    check((await rs.agent_mail_policy_get(pool, P, SEED["priya"]))["ok"] is False, "a person's mailbox has no policy")

    print("outbox_list (the agent reads its own rows and decisions)")
    ol = await rs.outbox_list(pool, X, E, limit=100)
    rej = next((r for r in ol["outbox"] if r["outbound"] == HELD["held"]["over_day"]), None)
    check(ol["ok"] and rej is not None and rej["status"] == "rejected" or (rej and rej.get("decision") == "reject"), "the Expert's outbox shows its rejected message", ol)
    check(rej is not None and rej.get("decision") == "reject" and "ask them for one" in (rej.get("note") or ""), "…with the decision and the reviewer's note", rej)

    print("review_trail / agent_activity (activity)")
    t = await aq.review_trail(pool, P, mailbox=E)
    decs = {x["decision"] for x in t["decisions"]}
    check(t["ok"] and {"approve", "edit", "reject", "reply", "take_over", "expire"} <= decs and all(x["after_seconds"] is not None for x in t["decisions"]),
          "review_trail: every decision, who, and how long after holding", t)
    check((await aq.review_trail(pool, M, mailbox=E))["error"]["code"] == "not_found", "Marco: not_found")
    a = await aq.agent_activity(pool, P)
    ex = next((x for x in a["agents"] if x["agent"] == 40), None)
    check(a["ok"] and ex is not None and ex["sent"] >= 3 and ex["held"] >= 10 and ex["rejected"] >= 1 and ex["edited"] >= 1 and ex["refused"] >= 4 and ex["dispatches"] >= 3,
          "agent_activity: the Expert's sent, held, rejected, edited, refused, dispatches", a)
    check((await aq.agent_activity(pool, Dn))["agents"] == [], "Dana oversees no agent mailbox: nothing")
    await pool.close()


asyncio.run(main())
print(f"{FAILS} FAILED ({PASSES} passed)" if FAILS else f"all {PASSES} passed")
sys.exit(1 if FAILS else 0)
