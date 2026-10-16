"""MaluMail's activity MCP — THE QUERIES slice 5 makes real: `review_trail` (A2) and `agent_activity` (A5) (docs/build-specs/oversight.md
"MCP tools this slice makes real"; docs/malumail-mcp-tool-surface.md "Activity server"). Phase 4 wires the server (and may answer the
same facts from the tenant's MaluDB memory, where activity_log's episodes go); these answer them from their source rows through the
records views, so the wall decides exactly as for the screens: decisions on held mail only in mailboxes the caller triages
(mcp_mail_reviews, mcp_outbound), an agent's counts only for the agent mailboxes the caller oversees. Never a body.

  review_trail(mailbox, since, limit)   the decisions on held mail: who, what, how long after holding
  agent_activity(agent, since, limit)   per agent: sent, held, rejected, edited, refused, dispatches
"""

from __future__ import annotations

import datetime as dt

import asyncpg

from records_server import Caller, _log_read, _mailbox_id, _scoped, bad, not_found


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


TOOLS = {"review_trail": review_trail, "agent_activity": agent_activity}
