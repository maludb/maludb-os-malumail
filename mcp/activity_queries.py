"""MaluMail's activity MCP — THE QUERIES slice 5 makes real: `review_trail` (A2) and `agent_activity` (A5) (docs/build-specs/oversight.md
"MCP tools this slice makes real"; docs/malumail-mcp-tool-surface.md "Activity server"). Phase 4 wires the server (and may answer the
same facts from the tenant's MaluDB memory, where activity_log's episodes go); these answer them from their source rows through the
records views, so the wall decides exactly as for the screens: decisions on held mail only in mailboxes the caller triages
(mcp_mail_reviews, mcp_outbound), an agent's counts only for the agent mailboxes the caller oversees. Never a body.

  review_trail(mailbox, since, limit)   the decisions on held mail: who, what, how long after holding
  agent_activity(agent, since, limit)   per agent: sent, held, rejected, edited, refused, dispatches
  mailbox_trail(mailbox, since, limit)  (slice 7) who opened a mailbox, not its owner — the owner's and the Mail admins'
"""

from __future__ import annotations

import datetime as dt

import asyncpg

from records_server import Caller, _log_read, _log_reads, _mailbox_id, _mailbox_id_seen, _scoped, bad, not_found


def _since(since) -> dt.datetime | None:
    if since in (None, ""):
        return None
    try:
        d = dt.datetime.fromisoformat(str(since))
    except ValueError:
        return None
    return d if d.tzinfo else d.replace(tzinfo=dt.timezone.utc)


async def review_trail(pool: asyncpg.Pool, caller: Caller, mailbox=None, since=None, limit: int = 50) -> dict:
    limit = max(1, min(200, int(limit or 50)))
    if since not in (None, "") and _since(since) is None:
        return bad("since is an ISO 8601 date or time")
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = None
        if mailbox is not None:
            mid = await _mailbox_id(con, mailbox)
            if mid is None:
                return not_found()
        rows = await con.fetch(
            """SELECT r.outbound_id, o.mailbox_id, b.address::text AS mailbox_address, r.decision, m.display_name AS by, r.created_at,
                      EXTRACT(EPOCH FROM (r.created_at - o.held_at))::bigint AS after_seconds, o.hold_reasons
                 FROM mcp_mail_reviews r JOIN mcp_outbound o ON o.id = r.outbound_id JOIN mcp_mailboxes b ON b.id = o.mailbox_id
                 LEFT JOIN mcp_members m ON m.member_id = r.reviewer_member_id
                WHERE o.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('triage'))::bigint[])
                  AND ($1::bigint IS NULL OR o.mailbox_id = $1) AND ($2::timestamptz IS NULL OR r.created_at >= $2)
                ORDER BY r.created_at DESC, r.id DESC LIMIT $3""", mid, _since(since), limit)
        for b in sorted({r["mailbox_id"] for r in rows}):
            await _log_read(con, caller, b, "review_trail")
    return {"ok": True, "decisions": [{"outbound": r["outbound_id"], "mailbox": r["mailbox_id"], "mailbox_address": r["mailbox_address"],
                                       "decision": r["decision"], "by": r["by"], "at": r["created_at"], "after_seconds": r["after_seconds"],
                                       "hold_reasons": list(r["hold_reasons"] or [])} for r in rows]}


async def agent_activity(pool: asyncpg.Pool, caller: Caller, agent=None, since=None, limit: int = 25) -> dict:
    limit = max(1, min(100, int(limit or 25)))
    if since not in (None, "") and _since(since) is None:
        return bad("since is an ISO 8601 date or time")
    start = _since(since) or (dt.datetime.now(dt.timezone.utc) - dt.timedelta(days=7))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        agent_id = None
        if agent is not None:
            agent_id = await con.fetchval("SELECT member_id FROM mcp_members WHERE kind = 'agent' AND (member_id::text = $1 OR display_name ILIKE $1)", str(agent))
            if agent_id is None:
                return not_found("agent")
        rows = await con.fetch(
            """WITH boxes AS (SELECT b.id FROM mcp_mailboxes b WHERE b.kind = 'agent' AND ml_is_decider(b.id, $1)),
                    o AS (SELECT o.id, o.status, o.held_at, o.sender_member_id FROM mcp_outbound o
                           WHERE o.mailbox_id IN (SELECT id FROM boxes) AND o.sender_is_agent AND o.created_at >= $2
                             AND ($3::bigint IS NULL OR o.sender_member_id = $3)),
                    r AS (SELECT o.sender_member_id, r.decision FROM mcp_mail_reviews r JOIN o ON o.id = r.outbound_id),
                    d AS (SELECT d.agent_member_id, count(*) AS n FROM mcp_dispatches d
                           WHERE d.mailbox_id IN (SELECT id FROM boxes) AND d.created_at >= $2 GROUP BY 1)
               SELECT m.member_id AS agent, m.display_name AS name,
                      count(*) FILTER (WHERE o.status = 'sent') AS sent,
                      count(*) FILTER (WHERE o.held_at IS NOT NULL) AS held,
                      (SELECT count(*) FROM r WHERE r.sender_member_id = m.member_id AND r.decision = 'reject') AS rejected,
                      (SELECT count(*) FROM r WHERE r.sender_member_id = m.member_id AND r.decision = 'edit') AS edited,
                      count(*) FILTER (WHERE o.status = 'refused') AS refused,
                      COALESCE((SELECT d.n FROM d WHERE d.agent_member_id = m.member_id), 0) AS dispatches
                 FROM o JOIN mcp_members m ON m.member_id = o.sender_member_id
                GROUP BY m.member_id, m.display_name ORDER BY m.display_name LIMIT $4""", caller.member_id, start, agent_id, limit)
    return {"ok": True, "since": start, "agents": [dict(r) for r in rows]}


async def mailbox_trail(pool: asyncpg.Pool, caller: Caller, mailbox, since=None, limit: int = 50) -> dict:
    """Who opened a mailbox (not its owner), when, through what (A1): its owner's and the Mail admins' — `below_level` for anyone else, even a
    full holder of an agent's mailbox (the records tool mailbox_access_log is the wider one). Read from mcp_mailbox_access_log; ids and kinds, never mail."""
    limit = max(1, min(200, int(limit or 50)))
    if since not in (None, "") and _since(since) is None:
        return bad("since is an ISO 8601 date or time")
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id_seen(con, mailbox)
        if mid is None:
            return not_found()
        b = await con.fetchrow("SELECT kind, owner_member_id FROM mcp_mailboxes WHERE id = $1", mid)
        if not (b["owner_member_id"] == caller.member_id or await con.fetchval("SELECT ml_has_right('access.audit')")):
            return {"ok": False, "error": {"code": "below_level", "message": "Only its owner and the Mail admins read who opened a mailbox.", "retry": False}}
        rows = await con.fetch(
            """SELECT a.occurred_at, m.display_name AS actor, a.action, a.source, a.consumer
                 FROM mcp_mailbox_access_log a LEFT JOIN mcp_members m ON m.member_id = a.actor_member_id
                WHERE a.mailbox_id = $1 AND ($2::timestamptz IS NULL OR a.occurred_at >= $2) ORDER BY a.id DESC LIMIT $3""", mid, _since(since), limit)
        if b["owner_member_id"] != caller.member_id:
            await _log_read(con, caller, mid, "mailbox_trail")
    return {"ok": True, "mailbox_id": mid, "opened": [{"at": r["occurred_at"], "by": r["actor"], "did": r["action"],
                                                       "through": "another application" if r["action"] == "k7.read" else ("a tool" if r["action"] == "mcp.read" or r["source"] in ("mcp", "agent", "assistant") else "the webmail"),
                                                       "consumer": r["consumer"]} for r in rows]}


# ---- Phase 4: the four trail tools no slice made real (A3, A4, A6) ----------------------------------------------------------------
# Read from mcp_activity_log (db/023): the rows activity_ingest.py ships to the tenant's MaluDB as `activity` episodes, behind the same wall the
# mailboxes have — a mailbox's rows for whoever may open it, its administration for the Mail admins, a row about no mailbox for its actor.
# A payload carries ids, counts, levels and domains, never mail; a subject excerpt of an agent's or shared mailbox only — fenced by the tool.
NOISE = ("screen.view", "mailbox.view", "mcp.read", "k7.read", "share.read")
WORDS = {
    "mailbox.create": "created the mailbox", "mailbox.connect": "connected the mailbox", "mailbox.password_reset": "reset the mailbox's password", "mailbox.pause": "paused the agent's mail",
    "mailbox.resume": "resumed the agent's mail", "mailbox.quota": "changed the quota", "mailbox.reactivate": "reactivated the mailbox", "mailbox.suspend": "suspended the mailbox",
    "mailbox.settings": "changed the mailbox's settings", "mailbox.update": "changed the mailbox", "mailbox.reindex": "asked to re-index the text", "mailbox.resync": "asked to re-sync",
    "alias.create": "made a forwarding address", "alias.update": "changed a forwarding address", "alias.delete": "removed a forwarding address",
    "permission.grant": "gave access", "permission.revoke": "took access away", "permission.break_glass": "was given emergency access", "policy.set": "changed the agent's mail policy",
    "outbound.send": "sent a message", "outbound.hold": "held a message for review", "review.approve": "approved a held message", "review.reject": "rejected a held message",
    "review.edit": "edited and sent a held message", "message.move": "moved mail", "message.archive": "archived mail", "message.label": "labelled mail", "message.trash": "moved mail to the trash",
}


def _row(r) -> dict:
    return {"at": r["occurred_at"], "by": r["actor"], "actor_member_id": r["actor_member_id"], "action": r["action"], "did": WORDS.get(r["action"], r["action"].replace(".", " ").replace("_", " ")),
            "through": r["source"], "entity_type": r["entity_type"], "entity_id": r["entity_id"], "mailbox_id": r["mailbox_id"], "run_id": r["agent_run_id"],
            "before": r["before"], "after": r["after"]}


_COLS = """l.occurred_at, l.actor_member_id, COALESCE(m.display_name, CASE WHEN l.actor_member_id IS NULL THEN 'the system' ELSE 'someone' END) AS actor, l.source, l.action,
           l.entity_type, l.entity_id, l.mailbox_id, l.agent_run_id, l.before, l.after"""


async def _trail(con, caller: Caller, tool: str, where: str, args: list, limit: int) -> list:
    rows = await con.fetch(
        f"""SELECT {_COLS} FROM mcp_activity_log l LEFT JOIN mcp_members m ON m.member_id = l.actor_member_id
             WHERE l.action <> ALL ($1::text[]) AND {where} ORDER BY l.log_id DESC LIMIT {limit}""", list(NOISE), *args)
    await _log_reads(con, caller, [r["mailbox_id"] for r in rows], tool)
    return [_row(r) for r in rows]


async def member_activity(pool: asyncpg.Pool, caller: Caller, member, since=None, limit: int = 50) -> dict:
    """What a person or an agent did in mail — sends, files, decisions — in the mailboxes the caller may open (A3)."""
    limit = max(1, min(200, int(limit or 50)))
    if since not in (None, "") and _since(since) is None:
        return bad("since is an ISO 8601 date or time")
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        who = None
        if str(member).strip().lower() == "me":
            who = caller.member_id
        else:
            who = await con.fetchval("SELECT member_id FROM mcp_members WHERE member_id::text = $1 OR display_name ILIKE $1 ORDER BY (member_id::text = $1) DESC, member_id LIMIT 1", str(member))
        if who is None:
            return not_found("person")
        rows = await _trail(con, caller, "member_activity", "l.actor_member_id = $2 AND ($3::timestamptz IS NULL OR l.occurred_at >= $3)", [who, _since(since)], limit)
    return {"ok": True, "member_id": int(who), "events": rows}


RECORD_TYPES = {"mailbox": "mailbox", "alias": "alias", "policy": "agent_mail_policy", "permission": "mailbox_permission", "domain": "domain", "outbound": "outbound_email"}


async def record_history(pool: asyncpg.Pool, caller: Caller, record_type: str, record_id, limit: int = 50) -> dict:
    """One record's changes, by whom and when (A4): a mailbox, an alias, an agent mailbox's policy (by the mailbox's id), a permission, a domain, a message in the outbox."""
    limit = max(1, min(200, int(limit or 50)))
    if record_type not in RECORD_TYPES:
        return bad("record_type is " + ", ".join(RECORD_TYPES))
    try:
        rid = int(record_id)
    except (TypeError, ValueError):
        return bad("record_id is the record's number")
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        rows = await _trail(con, caller, "record_history", "l.entity_type = $2 AND l.entity_id = $3", [RECORD_TYPES[record_type], rid], limit)
        if not rows:
            return not_found("record")
    return {"ok": True, "record_type": record_type, "record_id": rid, "history": rows}


async def activity_search(pool: asyncpg.Pool, caller: Caller, q: str, since=None, limit: int = 25) -> dict:
    """The guarded search over the trail (A6): words, each of which must be in the action, the actor's name or the payload's words; never SQL."""
    limit = max(1, min(100, int(limit or 25)))
    words = [w for w in str(q or "").lower().split() if w][:6]
    if not words or any(len(w) < 2 for w in words):
        return bad("q is words of two letters or more: an action (permission), a person's name, a domain")
    if since not in (None, "") and _since(since) is None:
        return bad("since is an ISO 8601 date or time")
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        cond = " AND ".join(f"(l.action ILIKE ${i + 3} OR m.display_name ILIKE ${i + 3} OR l.after::text ILIKE ${i + 3} OR l.before::text ILIKE ${i + 3})" for i in range(len(words)))
        pats = ["%" + w.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%" for w in words]
        rows = await _trail(con, caller, "activity_search", f"($2::timestamptz IS NULL OR l.occurred_at >= $2) AND {cond}", [_since(since), *pats], limit)
    return {"ok": True, "events": rows}


async def recent_activity(pool: asyncpg.Pool, caller: Caller, limit: int = 50) -> dict:
    """The latest events in the mailboxes the caller may open (A6)."""
    limit = max(1, min(200, int(limit or 50)))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        rows = await _trail(con, caller, "recent_activity", "true", [], limit)
    return {"ok": True, "events": rows}


TOOLS = {"review_trail": review_trail, "agent_activity": agent_activity, "mailbox_trail": mailbox_trail, "member_activity": member_activity,
         "record_history": record_history, "activity_search": activity_search, "recent_activity": recent_activity}
