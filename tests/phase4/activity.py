"""The activity server (docs/malumail-mcp-tool-surface.md "Activity server"): the seven tools behind the same wall as the mailboxes — a mailbox's rows for whoever may
open it, its administration for the Mail admins, a row about no mailbox for its actor —, no mail in a payload (a subject excerpt of an agent's mailbox arrives
fenced), an agent's run in a person's turn as capped as on the records server, and the two read roles' limits (no write but the access-log row, no base table)."""
import asyncio
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import asyncpg  # noqa: E402

from lib import ACTIVITY, ADA, ANN, CLERK, DANA, EXPERT, MARCO, OWNER, PRIYA, RECORDS, SEED, call, check, code, done, facts, grants_of, person_token, run, run_token, sql, unfenced, world  # noqa: E402

W = world()
PR, DA, MA, SA, EX, AD = (SEED[k] for k in ("priya", "dana", "marco", "sales", "expert", "ada"))
START = int(sql("SELECT coalesce(max(id), 0) FROM activity_log"))
EVENTS = [  # actor, source, action, entity_type, entity_id, mailbox, after
    (PRIYA, "web", "policy.set", "agent_mail_policy", EX, EX, '{"keys": ["send_mode"]}'),
    (PRIYA, "web", "mailbox.update", "mailbox", PR, PR, '{"display_name": "Priya"}'),
    (PRIYA, "web", "message.archive", "mail_message", W["priya_msg"], PR, '{"count": 1}'),
    (DANA, "web", "message.archive", "mail_message", W["dana_msg"], DA, '{"count": 2}'),
    (DANA, "web", "permission.grant", "mailbox_permission", 1, DA, '{"level": "view"}'),
    (DANA, "web", "outbound.send", "outbound_email", W["held"], EX, '{"subject": "PLANT_SUBJ_a1 excerpt of an agent mailbox subject", "domains": ["outside.example"]}'),
    (OWNER, "web", "alias.create", "alias", 1, None, '{"domain": "business.example", "destinations": 1}'),
    (OWNER, "web", "mailbox.create", "mailbox", SA, SA, '{"kind": "shared"}'),
    (MARCO, "web", "message.label", "mail_message", W["marco_msg"], MA, '{"count": 1}'),
]


async def main():
    for a, src, act, et, eid, mb, after in EVENTS:
        sql(f"INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, mailbox_id, after) VALUES ({a}, '{src}', '{act}', '{et}', {eid}, {mb if mb else 'NULL'}, '{after}'::jsonb)")
    sql("INSERT INTO activity_log (actor_member_id, source, action, screen) VALUES (26, 'web', 'screen.view', 'x')")
    tp, td, tm, to, ta = (person_token(m) for m in (PRIYA, DANA, MARCO, OWNER, ANN))
    A = lambda tok, tool, params=None: call(ACTIVITY, tok, tool, params)  # noqa: E731
    print("recent_activity: the events of the mailboxes the caller may open")
    d = (await A(tp, "recent_activity", {"limit": 100}))[0]
    acts = {(e["action"], e["mailbox_id"]) for e in d["events"]}
    check(d["ok"] and ("policy.set", EX) in acts and ("message.archive", PR) in acts and not any(m == DA for _, m in acts) and not any(a == "screen.view" for a, _ in acts),
          "Priya: the Expert's policy change and her own mail acts — nothing in Dana's mailbox, no screen views", acts)
    d = (await A(td, "recent_activity", {"limit": 100}))[0]
    acts = {(e["action"], e["mailbox_id"]) for e in d["events"]}
    check(("message.archive", DA) in acts and ("permission.grant", DA) in acts and ("message.archive", PR) not in acts and ("policy.set", EX) in acts, "Dana: her own acts and the Expert's mailbox she triages — none of Priya's", acts)
    d = (await A(tm, "recent_activity", {"limit": 100}))[0]
    check({e["mailbox_id"] for e in d["events"] if e["mailbox_id"]} <= {MA}, "Marco: his own mailbox's events alone", {e["mailbox_id"] for e in d["events"]})
    d = (await A(to, "recent_activity", {"limit": 100}))[0]
    acts = {(e["action"], e["mailbox_id"]) for e in d["events"]}
    check(("alias.create", None) in acts and ("permission.grant", DA) in acts and ("mailbox.create", SA) in acts and ("message.archive", DA) not in acts and ("message.archive", PR) not in acts and ("message.label", MA) not in acts,
          "the Mail admin: administration (a forwarding address, a permission, a new mailbox) — never a person's mail acts", acts)
    d = (await A(ta, "recent_activity", {"limit": 100}))[0]
    check(d["ok"] and d["events"] == [], "Ann (an external viewer, no mailbox): nothing", d)
    print("member_activity, record_history, activity_search")
    d = (await A(tp, "member_activity", {"member": "me", "limit": 100}))[0]
    check(d["ok"] and d["member_id"] == PRIYA and {e["actor_member_id"] for e in d["events"]} == {PRIYA} and len(d["events"]) >= 3, "member_activity(me): Priya's own events", d)
    d = (await A(td, "member_activity", {"member": PRIYA, "limit": 100}))[0]
    check(d["ok"] and {e["action"] for e in d["events"]} == {"policy.set"}, "…Dana asking about Priya sees only what happened in mailboxes Dana may open (the Expert's)", d)
    d = (await A(tp, "member_activity", {"member": "SMOKE Priya"}))[0]
    check(d["ok"] and d["member_id"] == PRIYA, "…by name")
    check(code((await A(tp, "member_activity", {"member": "nobody at all"}))[0]) == "not_found", "…an unknown person is not_found")
    d = (await A(tp, "record_history", {"record_type": "policy", "record_id": EX}))[0]
    check(d["ok"] and [e["action"] for e in d["history"]] == ["policy.set"], "record_history(policy, the Expert's mailbox): Priya reads its changes", d)
    check(code((await A(tm, "record_history", {"record_type": "policy", "record_id": EX}))[0]) == "not_found", "…Marco, who cannot open it, gets not_found")
    d = (await A(to, "record_history", {"record_type": "alias", "record_id": 1}))[0]
    check(d["ok"] and d["history"][0]["action"] == "alias.create", "record_history(alias): the Mail admin", d)
    check(code((await A(tp, "record_history", {"record_type": "alias", "record_id": 1}))[0]) == "not_found", "…and not Priya")
    check(code((await A(tp, "record_history", {"record_type": "nonsense", "record_id": 1}))[0]) == "bad_query", "an unknown record_type is bad_query")
    d, txt, _ = await A(td, "activity_search", {"q": "excerpt agent"})
    check(d["ok"] and len(d["events"]) == 1 and "PLANT_SUBJ" in txt and not unfenced(d, ["PLANT_"]), "activity_search: a subject excerpt of an agent's mailbox is found and arrives fenced", d)
    d = (await A(tp, "activity_search", {"q": "policy"}))[0]
    check(d["ok"] and any(e["action"] == "policy.set" for e in d["events"]), "…search by an action's word", d)
    d = (await A(tp, "activity_search", {"q": "a"}))[0]
    check(code(d) == "bad_query", "…a one-letter search is refused in words", d)
    d = (await A(tp, "activity_search", {"q": "archive", "since": "2999-01-01"}))[0]
    check(d["ok"] and d["events"] == [], "…since in the future finds nothing")
    check(code((await A(tp, "recent_activity", {"limit": 0}))[0]) == "bad_query", "limit 0 is bad_query")
    print("review_trail, agent_activity, mailbox_trail")
    d = (await A(tp, "review_trail", {}))[0]
    check(d["ok"] and [x["decision"] for x in d["decisions"]] == ["reject"] and d["decisions"][0]["mailbox"] == EX, "review_trail: the decision on the Expert's held message, for its overseer", d)
    d = (await A(tm, "review_trail", {}))[0]
    check(d["ok"] and d["decisions"] == [], "…none for Marco")
    d = (await A(tp, "agent_activity", {}))[0]
    row = [a for a in d["agents"] if a["agent"] == EXPERT]
    check(d["ok"] and row and row[0]["held"] == 1 and row[0]["rejected"] == 1, "agent_activity: the Expert held one message and had it rejected", d)
    d = (await A(tm, "agent_activity", {}))[0]
    check(d["ok"] and d["agents"] == [], "…nothing for Marco")
    print("an agent's run in a person's turn is capped as on the records server")
    r = 8800
    facts(r, EXPERT, grants_of("expert"), by=PRIYA)
    tx = run_token(EXPERT, r)
    d = (await A(tx, "recent_activity", {"limit": 100}))[0]
    mbs = {e["mailbox_id"] for e in d["events"] if e["mailbox_id"]}
    check(d["ok"] and DA not in mbs and MA not in mbs, "the Expert in Priya's turn: nothing of Dana's or Marco's mailboxes", mbs)
    facts(r + 1, EXPERT, grants_of("expert"), by=ANN, kind="human", holds=False)
    check(code((await A(run_token(EXPERT, r + 1), "recent_activity", {}))[0]) == "on_behalf_of", "a turn for a person who holds no role: on_behalf_of")
    print("the payload rule, the access log, the roles")
    bad = sql(f"SELECT count(*) FROM activity_log WHERE id > {START} AND action = 'mcp.read' AND (after::text ILIKE '%PLANT%' OR after::text ILIKE '%Synthetic%')")
    check(bad == "0", "no mcp.read payload holds a word of mail", bad)
    n0 = int(sql(f"SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND mailbox_id = {EX} AND actor_member_id = {DANA}"))
    await A(td, "member_activity", {"member": PRIYA})
    check(int(sql(f"SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND mailbox_id = {EX} AND actor_member_id = {DANA}")) == n0 + 1, "a trail read of a mailbox that is not hers is itself in that mailbox's access log, once")
    env = os.environ
    for role, pw, view in (("MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", "mcp_activity_log"), ("MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD", "mcp_mailboxes")):
        con = await asyncpg.connect(host=env["DB_HOST"], port=int(env["DB_PORT"]), database=env["DB_NAME"], user=env[role], password=env[pw])
        refused = []
        for stmt in ("INSERT INTO activity_log (action) VALUES ('x')", "UPDATE mailboxes SET active = false", "DELETE FROM mail_messages", "SELECT * FROM mail_messages", "SELECT * FROM mailbox_credentials",
                     "SELECT * FROM mcp_access_tokens", "SELECT * FROM activity_log"):
            try:
                await con.execute(stmt)
                refused.append(("ALLOWED", stmt))
            except asyncpg.InsufficientPrivilegeError:
                pass
        check(not refused, f"the {role.split('_')[1].lower()} role can write nothing and read no base table, credential or token", refused)
        await con.fetch(f"SELECT * FROM {view} LIMIT 1")
        await con.close()
    return done("activity")


if __name__ == "__main__":
    sys.exit(run(main()))
