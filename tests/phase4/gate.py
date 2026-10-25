"""The gate (docs/malumail-mcp-tool-surface.md "The caller"): who may ask, as whom, and what is listed — a person's token, an agent's run in a person's turn
(the Mail Expert capped at triage with ai_read and only the owner's own person mailbox; any other agent the lesser of the two), an agent's own run, a run
with no person, a person who holds no role (on_behalf_of), the kernel's token (app_roles and the share alone, K26's consumer headers), no / bad / revoked /
expired tokens, an evaluation run, the first contact of an agent the mirror has not admitted, and the kernel's run facts unavailable (fail closed)."""
import json
import os
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from lib import (ACTIVITY, ADA, APACHE, BEA, CLERK, DANA, EXPERT, MARCO, PRIYA, RECORDS, ROOT, SEED, VHOST, WATCHER, Refused, action_token, call, check, code, done, facts, grants_of,
                 kernel_token, person_token, raw_call, revoke_token, run, run_token, schemas, sql, tools, world)

W = world()
RUN = iter(range(7000, 7999))
SALES, PRIYAB, DANAB, MARCOB, EXPB = SEED["sales"], SEED["priya"], SEED["dana"], SEED["marco"], SEED["expert"]
ALL_RECORDS = 36
CLERK_RECORDS = ["find_mailboxes", "find_threads", "find_labels", "review_queue", "review_get", "oversight_summary", "agent_mail_summary", "agent_mail_policy_get", "unscreened_mail", "mailbox_health"]


async def level(token, mailbox, headers=None):
    """The caller's level on a mailbox through mailbox_get — or find_mailboxes for an agent not granted it (the Clerk)."""
    d, _, _ = await call(RECORDS, token, "mailbox_get", {"mailbox": mailbox})
    if d is None:
        d, _, _ = await call(RECORDS, token, "find_mailboxes", {"q": mailbox})
        row = [m for m in d if m["address"] == mailbox] if isinstance(d, list) else []
        return row[0]["level"] if row else "not_found"
    return d["mailbox"]["level"] if d.get("ok") else code(d)


def agent_token(member, by=None, kind="human", holds=True, agent="expert", **kw):
    r = next(RUN)
    facts(r, member, grants_of(agent), by=by, kind=kind, holds=holds, **kw)
    return run_token(member, r), r


async def main():
    print("tokens that open nothing")
    for label, tok in (("no token", None), ("a token nobody issued", "mcp_" + "0" * 48), ("a malformed bearer", "x.y.z")):
        try:
            await tools(RECORDS, tok)
            check(False, f"{label} is refused")
        except Refused:
            check(True, f"{label} is refused at the door (401)")
    t = person_token(PRIYA, "revoked")
    check(len(await tools(RECORDS, t)) > 0, "a fresh person's token lists tools")
    revoke_token(t)
    try:
        await tools(RECORDS, t)
        check(False, "a revoked token is refused")
    except Refused:
        check(True, "a revoked token is refused")
    try:
        await tools(RECORDS, run_token(EXPERT, 7990, ttl=-5))
        check(False, "an expired run token is refused")
    except Refused:
        check(True, "an expired run token is refused")
    tbea = person_token(BEA, "no-role")
    d, _, _ = await call(RECORDS, tbea, "find_mailboxes", {})
    check(d == [] and code((await call(RECORDS, tbea, "mailbox_get", {"mailbox": "priya@business.example"}))[0]) == "not_found",
          "a person the directory gave no role (Bea) opens nothing: no mailbox listed, every one not_found", d)
    try:
        await tools(RECORDS, run_token(EXPERT, 7991)[:-3] + "abc")
        check(False, "a run token with a bad signature is refused")
    except Refused:
        check(True, "a run token with a bad signature is refused")

    print("a person")
    tp = person_token(PRIYA)
    rec = await tools(RECORDS, tp)
    act = await tools(ACTIVITY, tp)
    check(len(rec) == ALL_RECORDS - 1 and "correspondence_index" not in rec and "app_roles" in rec, f"Priya lists {ALL_RECORDS - 1} records tools (the share is the kernel's alone, app_roles anyone's)", len(rec))
    check(len(act) == 7, "…and the seven activity tools", act)
    check(await level(tp, "priya@business.example") == "full", "her own mailbox at full")
    check(await level(tp, "dana@business.example") == "not_found", "Dana's mailbox is not_found to her")
    ta = action_token(PRIYA)
    check(len(await tools(RECORDS, ta)) == ALL_RECORDS - 1, "the command bar's action token is a person's too")

    print("the schema every tool carries")
    sch = await schemas(RECORDS, tp)
    sch.update(await schemas(ACTIVITY, tp))
    bad = [n for n, (s, a) in sch.items() if not (a and a.readOnlyHint is True and a.openWorldHint is False)]
    check(not bad, "every tool is readOnlyHint and not openWorldHint", bad)
    undescribed, loose = [], []
    for n, (s, a) in sch.items():
        p = s.get("properties", {}).get("params")
        if p is None:
            continue
        ref = p.get("$ref") or (p.get("anyOf") or [{}])[0].get("$ref")
        d = s["$defs"][ref.split("/")[-1]] if ref else p
        if d.get("additionalProperties") is not False:
            loose.append(n)
        for fname, f in d.get("properties", {}).items():
            if not f.get("description"):
                undescribed.append(f"{n}.{fname}")
    check(not undescribed, "every parameter is described", undescribed[:6])
    check(not loose, "every params object forbids extra fields", loose)
    d, _, _ = await call(RECORDS, tp, "find_mailboxes", {"limit": 0})
    check(code(d) == "bad_query" and "limit" in d["error"].get("fields", []), "limit 0 is bad_query naming the field", d)
    d, _, _ = await call(RECORDS, tp, "find_mailboxes", {"nonsense": 1})
    check(code(d) == "bad_query", "an unknown field is bad_query", d)
    d, _, _ = await call(RECORDS, tp, "find_mailboxes", {"limit": 101})
    check(code(d) == "bad_query", "limit 101 is bad_query (1–100)", d)

    print("an agent's run in a person's turn — the Mail Expert")
    tx, r = agent_token(EXPERT, by=PRIYA)
    ex_rec = await tools(RECORDS, tx)
    want = set(grants_of("expert")["Records MCP"])
    check(set(ex_rec) == want and len(want) == 33, "the Expert lists exactly the kernel's grants here (33 records tools)", (len(ex_rec), sorted(want ^ set(ex_rec))))
    check(not ({"domains_list", "app_roles", "correspondence_index"} & set(ex_rec)), "…without domains_list, app_roles or the share")
    check(len(await tools(ACTIVITY, tx)) == 7, "…and the seven activity tools")
    check(await level(tx, "priya@business.example") == "triage", "Priya's own mailbox, ai_read on: at triage (capped), not full")
    check(await level(tx, "dana@business.example") == "not_found", "Dana's mailbox: not_found (a person's mailbox only for its own owner)")
    check(await level(tx, "marco@business.example") == "not_found", "Marco's: not_found")
    check(await level(tx, "sales@business.example") == "triage", "the shared mailbox Priya holds full on: triage (capped)")
    check(await level(tx, "expert@business.example") == "full", "the Expert's own mailbox at full (its own row)")
    d, _, _ = await call(RECORDS, tx, "thread_read", {"mailbox": "priya@business.example", "thread": W["priya_thread"]})
    check(d["ok"] and len(d["messages"]) == 3, "it reads Priya's conversation for her", d)
    sql(f"UPDATE mailboxes SET ai_read = false WHERE id = {PRIYAB}")
    check(await level(tx, "priya@business.example") == "not_found", "ai_read off: Priya's mailbox is not_found to the Expert in her turn")
    check(await level(tp, "priya@business.example") == "full", "…and still hers at full")
    sql(f"UPDATE mailboxes SET ai_read = true WHERE id = {PRIYAB}")
    check(await level(tx, "priya@business.example") == "triage", "ai_read back on")
    sql(f"UPDATE mailboxes SET ai_read = false WHERE id = {SALES}")
    check(await level(tx, "sales@business.example") == "not_found", "ai_read off on the shared mailbox: not_found to the Expert")
    sql(f"UPDATE mailboxes SET ai_read = true WHERE id = {SALES}")
    txm, _ = agent_token(EXPERT, by=MARCO)
    check(await level(txm, "marco@business.example") == "not_found", "Marco's mailbox has ai_read off: not_found to the Expert in his turn")
    d, _, _ = await call(RECORDS, txm, "find_mailboxes", {})
    names = {m["address"] for m in d} if isinstance(d, list) else set()
    check("priya@business.example" not in names and "dana@business.example" not in names, "…and no person's mailbox but one he may open is listed", names)

    print("any other agent: the lesser of the two")
    ta1, _ = agent_token(ADA, by=PRIYA)
    check(await level(ta1, "sales@business.example") == "view", "Ada (view on sales) in Priya's turn (full): view — the lesser")
    check(await level(ta1, "priya@business.example") == "not_found", "Ada in Priya's turn cannot open Priya's own mailbox")
    ta2, _ = agent_token(ADA, by=MARCO)
    check(await level(ta2, "sales@business.example") == "not_found", "Ada in Marco's turn (he holds nothing on sales): nothing")
    ta3, _ = agent_token(ADA)
    check(await level(ta3, "sales@business.example") == "view", "Ada's own run: her own rows (view on sales)")
    check(await level(ta3, "ada@business.example") == "full", "…and her own mailbox at full")

    print("an agent's own run, and a run with no person")
    tc, _ = agent_token(CLERK, agent="clerk")
    check(set(await tools(RECORDS, tc)) == set(CLERK_RECORDS), "the Clerk lists exactly its ten records tools", sorted(set(await tools(RECORDS, tc)) ^ set(CLERK_RECORDS)))
    check(sorted(await tools(ACTIVITY, tc)) == ["agent_activity", "review_trail"], "…and its two activity tools")
    check(await level(tc, "expert@business.example") == "triage", "the Clerk on the Expert's mailbox: triage (its row)")
    check(await level(tc, "priya@business.example") == "not_found", "…and a person's mailbox: nothing")
    tx0, _ = agent_token(EXPERT)
    check(await level(tx0, "priya@business.example") == "not_found", "the Expert with no person: Priya's mailbox is not_found (no run for her)")
    check(await level(tx0, "expert@business.example") == "full", "…its own at full")
    txa, _ = agent_token(EXPERT, by=ADA, kind="agent")
    check(await level(txa, "priya@business.example") == "not_found", "an orchestrating agent as requester is not a person: the Expert reads as itself")

    print("a person who holds no role in MaluMail")
    tb, _ = agent_token(EXPERT, by=BEA, holds=False)
    d, _, err = await call(RECORDS, tb, "mailbox_get", {"mailbox": "expert@business.example"})
    check(code(d) == "on_behalf_of" and d["ok"] is False, "the turn is refused: on_behalf_of", d)
    d, _, _ = await call(ACTIVITY, tb, "recent_activity", {})
    check(code(d) == "on_behalf_of", "…on the activity server too", d)

    print("an evaluation run, a stranger's run, a run granted nothing here")
    te, _ = agent_token(EXPERT, by=PRIYA, is_eval=True)
    check(await level(te, "priya@business.example") == "triage", "an evaluation run may read")
    r = next(RUN)
    facts(r, EXPERT, grants_of("expert"), by=PRIYA, valid=False)
    try:
        names = await tools(RECORDS, run_token(EXPERT, r))
        check(names == [], "a run the kernel does not vouch for lists nothing", names)
        d, _, err = await call(RECORDS, run_token(EXPERT, r), "mailbox_get", {"mailbox": "priya@business.example"})
        check(err and d is None, "…and calls nothing", d)
    except Refused:
        check(True, "a run the kernel does not vouch for is refused")
    r = next(RUN)
    facts(r, EXPERT, grants_of("expert"), by=PRIYA, endpoints=False)
    check(await tools(RECORDS, run_token(EXPERT, r)) == [], "an agent with no endpoint here lists nothing")
    r = next(RUN)
    facts(r, EXPERT, {"Records MCP": {"find_mailboxes": {}}, "Activity MCP": {}}, by=PRIYA)
    t1 = run_token(EXPERT, r)
    _, txt, err = await call(RECORDS, t1, "mailbox_get", {"mailbox": "priya@business.example"})
    check(err and "not among the tools" in txt, "a tool the kernel did not grant is refused by name", txt)

    print("the first contact of an agent the mirror has not admitted")
    check(sql(f"SELECT coalesce(capability, 'none') FROM members WHERE id = {WATCHER}") == "none", "the Watcher has no capability yet")
    r = next(RUN)
    facts(r, WATCHER, {"Records MCP": {"find_mailboxes": {}}, "Activity MCP": {}}, by=None)
    tw = run_token(WATCHER, r)
    check(await tools(RECORDS, tw) == ["find_mailboxes"], "the kernel vouches: it is admitted and lists its one tool")
    check(sql(f"SELECT capability FROM members WHERE id = {WATCHER}") == "write", "…the mirror now holds its capability")
    d, _, _ = await call(RECORDS, tw, "find_mailboxes", {})
    check(d == [], "…it holds no role here, so it opens nothing", d)

    print("the kernel's token")
    tk = kernel_token()
    kt = await tools(RECORDS, tk)
    check(sorted(kt) == ["app_roles", "correspondence_index"], "lists app_roles and the share, nothing else", kt)
    check(await tools(ACTIVITY, tk) == [], "the activity server lists nothing to it")
    d, _, _ = await call(RECORDS, tk, "app_roles")
    check(d["schema"] == "os.app-roles/1" and d["application"] == "malumail" and {r["key"] for r in d["roles"]} >= {"user", "admin"} and d["rights"], "app_roles: os.app-roles/1 with the roles and their rights", d and d.get("roles"))
    check(all(isinstance(r["rights"], list) for r in d["roles"]) and any(r["is_admin"] for r in d["roles"]), "…the admin role marked, rights as lists")
    _, txt, err = await call(RECORDS, tk, "mailbox_get", {"mailbox": 1})
    check(err and "reaches" in txt, "any other tool is refused to the kernel's token", txt)
    _, txt, err = await raw_call(ACTIVITY, tk, "recent_activity", {"params": {}})
    check(err, "the activity server refuses it too", txt)
    # the share: the consumer is what the headers say; none named reads nothing
    h = {"X-OS-Consumer": "inventory", "X-OS-Consumer-Agent": "40", "X-Request-Id": "req-p4-1"}
    before = sql("SELECT count(*) FROM activity_log WHERE action = 'k7.read'")
    d, _, _ = await raw_call(RECORDS, tk, "correspondence_index", {"with": "outside.example", "as_agent": "99"}, h)
    check(d and d.get("ok") and d["schema"] == "os.malumail-correspondence/1", "correspondence_index answers the kernel's flat arguments (an as_agent dropped)", d)
    n = int(sql("SELECT count(*) FROM activity_log WHERE action = 'k7.read'")) - int(before)
    check(n >= 1, "…and writes k7.read rows")
    row = sql("SELECT after::text FROM activity_log WHERE action = 'k7.read' ORDER BY id DESC LIMIT 1")
    check('"inventory"' in row and '"consumer_agent": 40' in row and "outside.example" in row, "…naming the consumer and its agent from the headers", row)
    d, _, _ = await raw_call(RECORDS, tk, "correspondence_index", {"with": "outside.example"})
    check(code(d) == "consumer_required" or (d and d.get("ok") is False), "without the kernel's headers it reads nothing", d)
    _, txt, err = await call(RECORDS, tp, "correspondence_index", {"with": "outside.example"})
    check(err and "kernel" in txt, "a person cannot call the share", txt)
    _, txt, err = await call(RECORDS, tx, "correspondence_index", {"with": "outside.example"})
    check(err, "an agent cannot call the share", txt)

    print("the kernel's run facts unavailable: fail closed")
    subprocess.run([str(ROOT / "tests/phase4/kernel_ctl.sh"), "stop"], check=False)
    try:
        fresh = run_token(EXPERT, next(RUN))
        try:
            names = await tools(RECORDS, fresh)
            _, txt, err = await call(RECORDS, fresh, "mailbox_get", {"mailbox": "priya@business.example"})
            check(names == [] and err, "an agent run the kernel cannot vouch for lists and calls nothing", (names, txt))
        except Refused:
            check(True, "an agent run the kernel cannot vouch for is refused")
        try:
            await tools(RECORDS, run_token(WATCHER + 1000, next(RUN)))
            check(False, "an unknown agent while the kernel is down is refused")
        except Refused:
            check(True, "an unknown agent while the kernel is down is refused")
        check(len(await tools(RECORDS, tp)) == ALL_RECORDS - 1, "a person's token does not depend on the kernel")
        check(await level(tp, "priya@business.example") == "full", "…and reads")
    finally:
        subprocess.run([str(ROOT / "tests/phase4/kernel_ctl.sh"), "start"], check=False)

    if APACHE:
        print("through the vhost's own /mcp/records and /mcp/activity lines")
        check(sorted(await tools(VHOST + "/mcp/records", tk)) == ["app_roles", "correspondence_index"], "the kernel's token through the proxy reaches the same two tools")
        d, _, _ = await raw_call(VHOST + "/mcp/records", tk, "correspondence_index", {"with": "outside.example"}, h)
        check(d and d.get("ok"), "the consumer headers cross the proxy", d)
        row = sql("SELECT after::text FROM activity_log WHERE action = 'k7.read' ORDER BY id DESC LIMIT 1")
        check("inventory" in row, "…and arrive", row)
        check(len(await tools(VHOST + "/mcp/records", tp)) == ALL_RECORDS - 1 and len(await tools(VHOST + "/mcp/activity", tp)) == 7, "a person reaches both servers through the vhost")
    return done("gate")


if __name__ == "__main__":
    sys.exit(run(main()))
