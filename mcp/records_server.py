"""MaluMail's records MCP — THE QUERIES slice 2 makes real (docs/build-specs/mail-list.md "MCP tools this slice makes real";
docs/malumail-mcp-tool-surface.md). Phase 4 wires the server (FastMCP, the bearer gate, the run facts, fence_untrusted()); this file
holds what each tool reads, so the screens and the tools read the same SQL: `mail_list` is ml_mail_list() — the list screens' own
gatherer (decision 1) — and every query runs with app.member_id and app.on_behalf_of set transaction-locally, so the wall
(ml_level(), ml_my_mailbox_ids()) decides every row exactly as it does for the person's own screens. A mailbox the caller cannot open is
`not_found`, in the same words as one that does not exist. A read of a mailbox that is not the caller's own writes `mcp.read` through
ml_log_mcp_read() — once per tool call per mailbox.

  find_mailboxes(q, kind, limit)          resolver: the mailboxes the caller may open, with their unread counts
  mailbox_get(mailbox)                    one mailbox, the caller's level and why
  mailbox_overview(mailbox)               ml_mailbox_counts()
  mail_list(mailbox, view, folder, label, unread_only, before, limit)     ml_mail_list()
  find_folders(mailbox, q, limit)         resolver: a mailbox's folders
"""

from __future__ import annotations

import datetime as dt
import json
import re
import sys

import asyncpg

VIEWS = ("inbox", "starred", "snoozed", "sent", "drafts", "scheduled", "outbox", "all", "spam", "trash")
_CURSOR = re.compile(r"^([0-9]{1,15})\.([0-9]{1,18})$")


def not_found(what: str = "mailbox") -> dict:
    return {"ok": False, "error": {"code": "not_found", "message": f"No such {what}.", "retry": False}}


def bad(message: str) -> dict:
    return {"ok": False, "error": {"code": "bad_query", "message": message, "retry": False}}


class Caller:
    """The caller as the gate resolved it: the member, and the person behind an agent's run (K38), or None."""

    def __init__(self, member_id: int, on_behalf: int | None = None, run_id: int | None = None, token_id: int | None = None):
        self.member_id, self.on_behalf, self.run_id, self.token_id = member_id, on_behalf, run_id, token_id


async def _scoped(con: asyncpg.Connection, caller: Caller) -> None:
    await con.execute("SELECT set_config('app.member_id', $1, true), set_config('app.on_behalf_of', $2, true)",
                      str(caller.member_id), "" if caller.on_behalf is None else str(caller.on_behalf))


async def _mailbox_id(con: asyncpg.Connection, mailbox) -> int | None:
    """A mailbox named by id or address, only when the caller opens it (the wall in mcp_mailboxes)."""
    if isinstance(mailbox, int) or (isinstance(mailbox, str) and mailbox.isdigit()):
        row = await con.fetchrow("SELECT id FROM mcp_mailboxes WHERE id = $1 AND my_level <> 'none'", int(mailbox))
    else:
        row = await con.fetchrow("SELECT id FROM mcp_mailboxes WHERE address = lower($1) AND my_level <> 'none'", str(mailbox or "").strip())
    return int(row["id"]) if row else None


async def _log_read(con: asyncpg.Connection, caller: Caller, mailbox_id: int, tool: str) -> None:
    await con.execute("SELECT ml_log_mcp_read($1, $2, $3, $4)", mailbox_id, tool, caller.run_id, caller.token_id)


async def find_mailboxes(pool: asyncpg.Pool, caller: Caller, q: str = "", kind: str | None = None, limit: int = 25) -> dict:
    limit = max(1, min(100, int(limit or 25)))
    if kind is not None and kind not in ("person", "agent", "shared"):
        return bad("kind is person, agent or shared")
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        rows = await con.fetch(
            """SELECT b.id AS mailbox_id, b.address, b.display_name, b.kind, b.my_level AS level,
                      CASE WHEN b.last_sync_at IS NULL THEN NULL ELSE ml_unread_count(b.id) END AS unread
                 FROM mcp_mailboxes b
                WHERE b.my_level <> 'none' AND ($1 = '' OR b.address ILIKE '%' || $1 || '%' OR b.display_name ILIKE '%' || $1 || '%')
                  AND ($2::text IS NULL OR b.kind = $2)
                ORDER BY b.address LIMIT $3""", (q or "").strip(), kind, limit)
    return {"ok": True, "mailboxes": [dict(r) for r in rows]}


async def mailbox_get(pool: asyncpg.Pool, caller: Caller, mailbox) -> dict:
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        b = await con.fetchrow(
            """SELECT b.id, b.address, b.display_name, b.kind, b.owner_member_id, b.my_level, b.sync_state, b.last_sync_at, b.quota_bytes,
                      b.quota_used_bytes, b.intake_owner, b.ai_read, b.share_with_apps, b.primary_overseer_id, b.suspended_at,
                      b.credential_status,
                      COALESCE((SELECT p.paused FROM mcp_agent_mail_policies p WHERE p.mailbox_id = b.id), false) AS paused
                 FROM mcp_mailboxes b WHERE b.id = $1""", mid)
        why = "owner" if b["owner_member_id"] == caller.member_id else (
            "row" if await con.fetchval(
                """SELECT EXISTS (SELECT 1 FROM mcp_mailbox_permissions p WHERE p.mailbox_id = $1 AND p.principal_kind IN ('member', 'agent')
                                     AND p.principal_id = $2)""", mid, caller.member_id)
            else ("on_behalf" if caller.on_behalf is not None else "department or Mail admin"))
        overseers = await con.fetch(
            """SELECT p.principal_id AS member_id, m.display_name FROM mcp_mailbox_permissions p JOIN mcp_members m ON m.member_id = p.principal_id
                WHERE p.mailbox_id = $1 AND p.principal_kind = 'member' AND p.level = 'full' ORDER BY m.display_name""", mid) \
            if b["kind"] == "agent" else []
        if b["owner_member_id"] != caller.member_id:
            await _log_read(con, caller, mid, "mailbox_get")
        out = {"mailbox_id": b["id"], "address": b["address"], "display_name": b["display_name"], "kind": b["kind"],
               "level": b["my_level"], "why": why, "owner_member_id": b["owner_member_id"], "sync_state": b["sync_state"],
               "last_sync_at": b["last_sync_at"], "quota_bytes": b["quota_bytes"], "quota_used_bytes": b["quota_used_bytes"],
               "intake_owner": b["intake_owner"], "paused": b["paused"], "suspended": b["suspended_at"] is not None,
               "credential": b["credential_status"] or "none",
               "overseers": [dict(o) for o in overseers], "primary_overseer_id": b["primary_overseer_id"]}
        if b["owner_member_id"] == caller.member_id or await con.fetchval("SELECT ml_is_mail_admin()"):
            out.update(ai_read=b["ai_read"], share_with_apps=b["share_with_apps"])
        return {"ok": True, "mailbox": out}


async def mailbox_overview(pool: asyncpg.Pool, caller: Caller, mailbox) -> dict:
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        counts = await con.fetchval("SELECT ml_mailbox_counts($1)", mid)
        if counts is None:
            return not_found()
        owner = await con.fetchval("SELECT owner_member_id FROM mcp_mailboxes WHERE id = $1", mid)
        if owner != caller.member_id:
            await _log_read(con, caller, mid, "mailbox_overview")
        c = json.loads(counts) if isinstance(counts, str) else counts
        kind = await con.fetchval("SELECT kind FROM mcp_mailboxes WHERE id = $1", mid)
        if kind != "agent":
            c.pop("held", None)
            c.pop("unscreened", None)
        return {"ok": True, "mailbox_id": mid, "counts": c}


async def mail_list(pool: asyncpg.Pool, caller: Caller, mailbox, view: str = "inbox", folder: int | None = None, label: int | None = None,
                    unread_only: bool = False, before: str | None = None, limit: int = 25) -> dict:
    """ml_mail_list() — the list screens' own gatherer. Subjects, names and snippets are mail content: Phase 4's tool wraps them in
    fence_untrusted() before they reach a model."""
    limit = max(1, min(100, int(limit or 25)))
    if folder is None and label is None and view not in VIEWS:
        return bad("view is one of " + ", ".join(VIEWS))
    bat = bid = None
    if before:
        m = _CURSOR.match(before)
        if not m:
            return bad("before is the cursor a previous page answered")
        bat = dt.datetime.fromtimestamp(int(m.group(1)) / 1000, tz=dt.timezone.utc)
        bid = int(m.group(2))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        if folder is not None and not await con.fetchval("SELECT EXISTS (SELECT 1 FROM mcp_folders WHERE id = $1 AND mailbox_id = $2)", int(folder), mid):
            return not_found("folder")
        rows = await con.fetch(
            """SELECT thread_id, subject, participants, last_message_at, (floor(extract(epoch FROM last_message_at) * 1000))::bigint AS at_ms,
                      message_count, unread, flagged AS starred, has_attachments, snippet, labels, snoozed_until, agent_badge,
                      latest_message AS message, outbound_id, outbound_status, send_after, hold_reasons
                 FROM ml_mail_list($1, $2, $3, $4, $5, $6, $7, $8)""",
            mid, None if (folder is not None or label is not None) else view, folder, label, bool(unread_only), bat, bid, limit + 1)
        more = len(rows) > limit
        rows = rows[:limit]
        owner = await con.fetchval("SELECT owner_member_id FROM mcp_mailboxes WHERE id = $1", mid)
        if owner != caller.member_id:
            await _log_read(con, caller, mid, "mail_list")
    out = []
    for r in rows:
        d = dict(r)
        d["labels"] = json.loads(d["labels"]) if isinstance(d["labels"], str) else d["labels"]
        d["participants"] = list(d["participants"] or [])
        d["hold_reasons"] = list(d["hold_reasons"] or []) if d["hold_reasons"] is not None else None
        d["cursor"] = f"{d.pop('at_ms')}.{d['outbound_id'] or d['thread_id']}"
        out.append(d)
    return {"ok": True, "mailbox_id": mid, "conversations": out, "before": out[-1]["cursor"] if (more and out) else None}


async def find_folders(pool: asyncpg.Pool, caller: Caller, mailbox, q: str = "", limit: int = 25) -> dict:
    limit = max(1, min(100, int(limit or 25)))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        rows = await con.fetch(
            """SELECT id AS folder_id, name, special_use, messages_unseen AS unread, messages_total AS total FROM mcp_folders
                WHERE mailbox_id = $1 AND ($2 = '' OR name ILIKE '%' || $2 || '%') ORDER BY special_use NULLS LAST, name LIMIT $3""",
            mid, (q or "").strip(), limit)
    return {"ok": True, "folders": [dict(r) for r in rows]}


TOOLS = {"find_mailboxes": find_mailboxes, "mailbox_get": mailbox_get, "mailbox_overview": mailbox_overview, "mail_list": mail_list,
         "find_folders": find_folders}


if __name__ == "__main__":
    # Phase 4 wires the server (server_common.serve, the bearer gate, fence_untrusted()); until then this file is the tools' queries.
    print("malumail-records-mcp: the tools' queries are written (slice 2); Phase 4 serves them.", file=sys.stderr)
    sys.exit(0)
