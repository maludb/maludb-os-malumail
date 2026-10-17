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


async def _mailbox_id_seen(con: asyncpg.Connection, mailbox) -> int | None:
    """A mailbox named by id or address that the caller SEES in mcp_mailboxes — at any level, a Mail admin's sight of a person's mailbox included
    (slice 7: who can open it and who looked are the Mail admins' to read without holding a level on it). The tools that use it answer from views
    that decide again who reads what."""
    if isinstance(mailbox, int) or (isinstance(mailbox, str) and mailbox.isdigit()):
        row = await con.fetchrow("SELECT id FROM mcp_mailboxes WHERE id = $1", int(mailbox))
    else:
        row = await con.fetchrow("SELECT id FROM mcp_mailboxes WHERE address = lower($1)", str(mailbox or "").strip())
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


# ================================================================================================================================
# SLICE 3 — reading (docs/build-specs/reading.md "MCP tools this slice makes real"; reading-and-rendering.md §6): find_threads,
# thread_read, message_read, message_headers, find_attachments, attachment_read. Agents never receive HTML: the text comes from the mail
# service's `fetch` with form=text (extract.py: quotes and signatures cut, hidden content counted, links with hosts), and every string
# that came in a message is wrapped by Phase 4's tool in fence_untrusted() (server_common.py). The mail service computes the level itself
# from the actor and the person behind the run (on_behalf); a read of a mailbox not the caller's own writes mcp.read.

import os as _os
import re as _re
import subprocess as _subprocess
import zipfile as _zipfile
import io as _io
from pathlib import Path as _Path

import httpx as _httpx

_ROOT = _Path(__file__).resolve().parents[1]
if str(_ROOT) not in sys.path:
    sys.path.insert(0, str(_ROOT))
from services.mailer import door as _door  # noqa: E402

THREAD_TEXT_LIMIT = 20_000
MESSAGE_TEXT_LIMIT = 100_000
ATTACHMENT_TEXT_LIMIT = 100_000
LATEST_IN_FULL = 5
ATTACHMENT_TYPES = {"pdf": ("application/pdf",), "image": ("image/",), "document": ("application/msword", "application/vnd.openxmlformats-officedocument.wordprocessingml", "text/plain", "application/rtf"),
                    "spreadsheet": ("application/vnd.ms-excel", "application/vnd.openxmlformats-officedocument.spreadsheetml", "text/csv")}


def _mailer_env() -> tuple[str, bytes]:
    from services.mailer.config import environment
    e = environment()
    return f"http://127.0.0.1:{int(e.get('ML_MAILER_PORT') or 0)}", (e.get("ML_MAILER_KEY") or "").encode()


async def _mailer(path: str, caller: Caller, args: dict, timeout: float = 40.0) -> tuple[int, dict]:
    base, key = _mailer_env()
    body = json.dumps({"actor": caller.member_id, "on_behalf": caller.on_behalf, "args": args}).encode()
    async with _httpx.AsyncClient(timeout=timeout) as c:
        try:
            r = await c.post(base + path, content=body, headers={_door.HEADER: _door.sign(key, "POST", path, body), "content-type": "application/json"})
        except _httpx.HTTPError:
            return 503, {"ok": False, "error": {"code": "unavailable", "message": "The mail service is not answering."}}
    try:
        return r.status_code, r.json()
    except ValueError:
        return r.status_code, {"ok": False, "error": {"code": "unavailable", "message": "The mail service answered something it should not have."}}


def _mailer_error(status: int, doc: dict) -> dict:
    err = (doc or {}).get("error") or {}
    code = err.get("code") or "unavailable"
    return {"ok": False, "error": {"code": "not_found" if code == "not_found" else ("moved" if code == "moved" else code), "message": err.get("message") or "The mail service refused that.",
                                   "retry": code in ("busy", "unavailable", "not_live")}}


async def _thread_messages(con, mid: int, thread_id: int) -> list:
    return await con.fetch(
        """SELECT m.id, m.message_key_id, m.thread_id, m.folder_id, f.special_use, m.from_address, m.from_name, m.to_addresses, m.cc_addresses, m.subject,
                  m.sent_at, m.received_at, m.seen, m.flagged, m.has_attachments, m.attachment_names, m.dmarc_pass, m.spam_score, m.from_outside,
                  m.automated, m.local_submission, m.snippet, m.uidvalidity, f.uidvalidity AS folder_uidvalidity
             FROM mcp_messages m JOIN mcp_folders f ON f.id = m.folder_id
            WHERE m.mailbox_id = $1 AND m.thread_id = $2 ORDER BY m.received_at, m.id""", mid, thread_id)


def _message_head(r) -> dict:
    return {"message": r["id"], "key": r["message_key_id"], "thread_id": r["thread_id"], "from": r["from_address"], "from_name": r["from_name"],
            "to": list(r["to_addresses"] or []), "cc": list(r["cc_addresses"] or []), "subject": r["subject"], "date": r["sent_at"] or r["received_at"],
            "dmarc": None if r["dmarc_pass"] is None else ("pass" if r["dmarc_pass"] else "fail"), "spam_score": float(r["spam_score"]) if r["spam_score"] is not None else None,
            "from_outside": r["from_outside"], "automated": r["automated"], "sent_inside": bool(r["local_submission"]) and not r["from_outside"],
            "folder": r["special_use"], "unread": not r["seen"], "starred": r["flagged"], "attachment_names": list(r["attachment_names"] or [])}


async def find_threads(pool: asyncpg.Pool, caller: Caller, mailbox, q: str = "", limit: int = 25) -> dict:
    """Resolver: conversations of a mailbox by words of their subject, participants or snippet (a message is never resolved by name —
    an agent passes ids from here or from mail_search)."""
    limit = max(1, min(100, int(limit or 25)))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        words = (q or "").strip()
        rows = await con.fetch(
            """SELECT t.id AS thread_id, t.subject, t.participants, t.last_message_at, t.message_count, t.unread_count > 0 AS unread, t.has_attachments, left(t.snippet, 200) AS snippet
                 FROM mcp_threads t
                WHERE t.mailbox_id = $1 AND ($2 = '' OR t.subject ILIKE '%' || $2 || '%' OR t.snippet ILIKE '%' || $2 || '%'
                                              OR EXISTS (SELECT 1 FROM unnest(t.participants) p WHERE p ILIKE '%' || $2 || '%'))
                ORDER BY t.last_message_at DESC LIMIT $3""", mid, words, limit)
        owner = await con.fetchval("SELECT owner_member_id FROM mcp_mailboxes WHERE id = $1", mid)
        if owner != caller.member_id:
            await _log_read(con, caller, mid, "find_threads")
    return {"ok": True, "mailbox_id": mid, "threads": [dict(r) | {"participants": list(r["participants"] or [])} for r in rows]}


async def thread_read(pool: asyncpg.Pool, caller: Caller, mailbox, thread=None, message=None, messages: str = "latest", include_quoted: bool = False) -> dict:
    """The conversation, oldest first: the five latest messages in full (the extracted reply text, links with hosts, deceptive_links,
    hidden content flagged, attachments listed), earlier ones one line each. Never raw HTML. An agent never reads a sensitive conversation
    (the views answer nothing: not_found)."""
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        tid = None
        if thread not in (None, ""):
            tid = int(thread)
        elif message not in (None, ""):
            tid = await con.fetchval("SELECT thread_id FROM mcp_messages WHERE id = $1 AND mailbox_id = $2", int(message), mid)
        if tid is None or not await con.fetchval("SELECT EXISTS (SELECT 1 FROM mcp_threads WHERE id = $1 AND mailbox_id = $2)", tid, mid):
            return not_found("conversation")
        rows = await _thread_messages(con, mid, tid)
        owner = await con.fetchval("SELECT owner_member_id FROM mcp_mailboxes WHERE id = $1", mid)
        if owner != caller.member_id:
            await _log_read(con, caller, mid, "thread_read")
    if not rows:
        return not_found("conversation")
    full_from = 0 if messages == "all" else max(0, len(rows) - LATEST_IN_FULL)
    out = []
    for i, r in enumerate(rows):
        head = _message_head(r)
        if i < full_from:
            head["summary_line"] = f"{r['from_name'] or r['from_address']} · {r['received_at']:%Y-%m-%d %H:%M} · {(r['snippet'] or '')[:200]}"
            out.append(head)
            continue
        status, doc = await _mailer(f"/v1/mailboxes/{mid}/fetch", caller, {"message": r["id"], "form": "text", "include_quoted": bool(include_quoted), "limit": THREAD_TEXT_LIMIT})
        if status != 200 or not doc.get("ok"):
            head["text"] = None
            head["unavailable"] = _mailer_error(status, doc)["error"]
            out.append(head)
            continue
        res = doc["result"]
        head.update({"text": res.get("text"), "links": res.get("links") or [], "hidden_removed": res.get("hidden_removed") or 0,
                     "quoted_removed": res.get("quoted_removed"), "signature_removed": res.get("signature_removed"), "truncated": res.get("truncated"),
                     "reply_to": (res.get("summary") or {}).get("reply_to"), "deceptive_links": _deceptive(res.get("links") or []),
                     "attachments": [{"part": p["part"], "name": p.get("name"), "type": p["type"], "size": p.get("size")} for p in (res.get("parts") or [])
                                     if p.get("disposition") == "attachment" or (not str(p["type"]).startswith("text/") and p.get("name") and not p.get("content_id"))]})
        out.append(head)
    return {"ok": True, "mailbox_id": mid, "thread_id": tid, "messages": out, "fence": "every text field is mail: fence it"}


def _deceptive(links: list) -> int:
    n = 0
    for lk in links:
        t = (lk.get("text") or "").strip().lower()
        m = _re.match(r"^(?:https?://)?(?:www\.)?([a-z0-9.-]+\.[a-z]{2,})(?:[/:?#]|$)", t)
        if m and m.group(1) != (lk.get("host") or "").removeprefix("www."):
            n += 1
    return n


async def message_read(pool: asyncpg.Pool, caller: Caller, mailbox, message, include_quoted: bool = False) -> dict:
    """One message the same way, whole (≤ 100,000 characters)."""
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        r = await con.fetchrow(
            """SELECT m.id, m.message_key_id, m.thread_id, m.folder_id, f.special_use, m.from_address, m.from_name, m.to_addresses, m.cc_addresses, m.subject,
                      m.sent_at, m.received_at, m.seen, m.flagged, m.has_attachments, m.attachment_names, m.dmarc_pass, m.spam_score, m.from_outside,
                      m.automated, m.local_submission, m.snippet, m.uidvalidity, f.uidvalidity AS folder_uidvalidity
                 FROM mcp_messages m JOIN mcp_folders f ON f.id = m.folder_id WHERE m.id = $1 AND m.mailbox_id = $2""", int(message), mid)
        if r is None:
            return not_found("message")
        owner = await con.fetchval("SELECT owner_member_id FROM mcp_mailboxes WHERE id = $1", mid)
        if owner != caller.member_id:
            await _log_read(con, caller, mid, "message_read")
    head = _message_head(r)
    status, doc = await _mailer(f"/v1/mailboxes/{mid}/fetch", caller, {"message": r["id"], "form": "text", "include_quoted": bool(include_quoted), "limit": MESSAGE_TEXT_LIMIT}, 60)
    if status != 200 or not doc.get("ok"):
        return _mailer_error(status, doc)
    res = doc["result"]
    head.update({"text": res.get("text"), "links": res.get("links") or [], "hidden_removed": res.get("hidden_removed") or 0, "quoted_removed": res.get("quoted_removed"),
                 "signature_removed": res.get("signature_removed"), "truncated": res.get("truncated"), "deceptive_links": _deceptive(res.get("links") or []),
                 "reply_to": (res.get("summary") or {}).get("reply_to"),
                 "attachments": [{"part": p["part"], "name": p.get("name"), "type": p["type"], "size": p.get("size")} for p in (res.get("parts") or [])
                                 if p.get("disposition") == "attachment" or (not str(p["type"]).startswith("text/") and p.get("name") and not p.get("content_id"))]})
    return {"ok": True, "mailbox_id": mid, "message": head}


async def message_headers(pool: asyncpg.Pool, caller: Caller, mailbox, message) -> dict:
    """The headers that matter, as the mail service parsed them (From, Sender, Reply-To, To, Cc, Date, Message-ID, In-Reply-To, References,
    List-*, Auto-Submitted, Precedence, the DMARC result and spam score as Rspamd reported them, X-OS-Agent)."""
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        ok = await con.fetchval("SELECT EXISTS (SELECT 1 FROM mcp_messages WHERE id = $1 AND mailbox_id = $2)", int(message), mid)
        if not ok:
            return not_found("message")
        owner = await con.fetchval("SELECT owner_member_id FROM mcp_mailboxes WHERE id = $1", mid)
        if owner != caller.member_id:
            await _log_read(con, caller, mid, "message_headers")
    status, doc = await _mailer(f"/v1/mailboxes/{mid}/fetch", caller, {"message": int(message), "form": "headers"})
    if status != 200 or not doc.get("ok"):
        return _mailer_error(status, doc)
    res = doc["result"]
    return {"ok": True, "mailbox_id": mid, "message": int(message), "headers": res.get("summary") or {},
            "parts": [{"part": p["part"], "name": p.get("name"), "type": p["type"], "size": p.get("size"), "disposition": p.get("disposition")} for p in (res.get("parts") or [])]}


async def find_attachments(pool: asyncpg.Pool, caller: Caller, q: str = "", mailbox=None, thread=None, type: str | None = None, since=None, limit: int = 25) -> dict:
    """Attachments by a file name's words across the mailboxes the caller may open (the index's names; `part` is read from the mail service
    when a message is opened through message_headers / attachment_read)."""
    limit = max(1, min(100, int(limit or 25)))
    if type is not None and type not in ("pdf", "image", "document", "spreadsheet", "other"):
        return bad("type is pdf, image, document, spreadsheet or other")
    ext = {"pdf": ("pdf",), "image": ("png", "jpg", "jpeg", "gif", "webp", "heic", "tif", "tiff"), "document": ("doc", "docx", "txt", "rtf", "odt"),
           "spreadsheet": ("xls", "xlsx", "csv", "ods")}.get(type or "", None)
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = None
        if mailbox not in (None, ""):
            mid = await _mailbox_id(con, mailbox)
            if mid is None:
                return not_found()
        rows = await con.fetch(
            """SELECT m.id AS message, m.thread_id, m.mailbox_id AS mailbox, a.name, m.received_at AS date, m.from_address AS "from"
                 FROM mcp_messages m CROSS JOIN LATERAL unnest(m.attachment_names) a(name)
                WHERE ($1::bigint IS NULL OR m.mailbox_id = $1) AND ($2::bigint IS NULL OR m.thread_id = $2)
                  AND ($3 = '' OR a.name ILIKE '%' || $3 || '%') AND ($4::timestamptz IS NULL OR m.received_at >= $4)
                ORDER BY m.received_at DESC LIMIT $5""", mid, int(thread) if thread not in (None, "") else None, (q or "").strip(),
            dt.datetime.fromisoformat(since) if since else None, limit * 4)
        for mb in {r["mailbox"] for r in rows}:
            owner = await con.fetchval("SELECT owner_member_id FROM mcp_mailboxes WHERE id = $1", mb)
            if owner != caller.member_id:
                await _log_read(con, caller, mb, "find_attachments")
    out = []
    for r in rows:
        name = r["name"] or ""
        e = name.rsplit(".", 1)[-1].lower() if "." in name else ""
        if ext is not None and e not in ext:
            continue
        if type == "other" and any(e in x for x in ({"pdf"}, {"png", "jpg", "jpeg", "gif", "webp"}, {"doc", "docx", "txt"}, {"xls", "xlsx", "csv"})):
            continue
        out.append({"message": r["message"], "thread_id": r["thread_id"], "mailbox": r["mailbox"], "name": name, "type": e or None, "date": r["date"], "from": r["from"]})
        if len(out) >= limit:
            break
    return {"ok": True, "attachments": out}


def _docx_text(data: bytes) -> str:
    with _zipfile.ZipFile(_io.BytesIO(data)) as z:
        xml = z.read("word/document.xml").decode("utf-8", "replace")
    xml = _re.sub(r"</w:p>", "\n", xml)
    return _re.sub(r"<[^>]+>", "", xml)


def _xlsx_text(data: bytes) -> str:
    with _zipfile.ZipFile(_io.BytesIO(data)) as z:
        shared = []
        if "xl/sharedStrings.xml" in z.namelist():
            s = z.read("xl/sharedStrings.xml").decode("utf-8", "replace")
            shared = [_re.sub(r"<[^>]+>", "", x) for x in _re.findall(r"<si>(.*?)</si>", s, _re.S)]
        out = []
        for n in sorted(x for x in z.namelist() if x.startswith("xl/worksheets/sheet")):
            sheet = z.read(n).decode("utf-8", "replace")
            for row in _re.findall(r"<row[^>]*>(.*?)</row>", sheet, _re.S):
                cells = []
                for cm in _re.finditer(r"<c([^>]*)>(.*?)</c>", row, _re.S):
                    attrs, inner = cm.group(1), cm.group(2)
                    v = _re.search(r"<v>(.*?)</v>", inner, _re.S)
                    val = v.group(1) if v else _re.sub(r"<[^>]+>", "", inner)
                    if 't="s"' in attrs and val.isdigit() and int(val) < len(shared):
                        val = shared[int(val)]
                    cells.append(val)
                out.append("\t".join(cells))
    return "\n".join(out)


async def attachment_read(pool: asyncpg.Pool, caller: Caller, mailbox, message, part) -> dict:
    """The text of an allowed type — text/plain, text/csv, text/calendar, PDF text (pdftotext), .docx and .xlsx text — ≤ 100,000 characters;
    any other type answers its name and size only. The bytes come from the mail service's `part` and are never kept."""
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        ok = await con.fetchval("SELECT EXISTS (SELECT 1 FROM mcp_messages WHERE id = $1 AND mailbox_id = $2)", int(message), mid)
        if not ok:
            return not_found("message")
        owner = await con.fetchval("SELECT owner_member_id FROM mcp_mailboxes WHERE id = $1", mid)
        if owner != caller.member_id:
            await _log_read(con, caller, mid, "attachment_read")
    part = str(part or "").strip()
    if not _re.fullmatch(r"[0-9]+(\.[0-9]+)*", part):
        return bad("part is a section like 2 or 1.2")
    base, key = _mailer_env()
    path = f"/v1/mailboxes/{mid}/part"
    body = json.dumps({"actor": caller.member_id, "on_behalf": caller.on_behalf, "args": {"message": int(message), "part": part}}).encode()
    data = b""
    cap = 20 * 1024 * 1024
    async with _httpx.AsyncClient(timeout=120) as c:
        try:
            async with c.stream("POST", base + path, content=body, headers={_door.HEADER: _door.sign(key, "POST", path, body), "content-type": "application/json"}) as r:
                if r.status_code != 200:
                    raw = await r.aread()
                    try:
                        return _mailer_error(r.status_code, json.loads(raw))
                    except ValueError:
                        return _mailer_error(r.status_code, {})
                ctype = (r.headers.get("content-type") or "application/octet-stream").split(";")[0].strip().lower()
                name = r.headers.get("x-ml-name")
                charset = r.headers.get("x-ml-charset")
                async for chunk in r.aiter_bytes():
                    data += chunk
                    if len(data) > cap:
                        break
        except _httpx.HTTPError:
            return {"ok": False, "error": {"code": "unavailable", "message": "The mail service is not answering.", "retry": True}}
    from urllib.parse import unquote
    name = unquote(name) if name else None
    size = len(data)
    text = None
    kind = None
    try:
        if ctype in ("text/plain", "text/csv", "text/calendar", "text/markdown"):
            text, kind = data.decode(charset or "utf-8", "replace"), ctype
        elif ctype == "application/pdf" or (name or "").lower().endswith(".pdf"):
            kind = "application/pdf"
            try:
                text = _subprocess.run(["pdftotext", "-layout", "-", "-"], input=data, capture_output=True, timeout=30).stdout.decode("utf-8", "replace")
            except (OSError, _subprocess.SubprocessError):
                text = None
        elif ctype == "application/vnd.openxmlformats-officedocument.wordprocessingml.document" or (name or "").lower().endswith(".docx"):
            text, kind = _docx_text(data), "docx"
        elif ctype == "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" or (name or "").lower().endswith(".xlsx"):
            text, kind = _xlsx_text(data), "xlsx"
    except Exception:  # noqa: BLE001 — a file that is not what it says
        text = None
    if text is None:
        return {"ok": True, "mailbox_id": mid, "message": int(message), "part": part, "name": name, "type": ctype, "size": size, "text": None,
                "note": "not a type whose text is read; the name and size only"}
    return {"ok": True, "mailbox_id": mid, "message": int(message), "part": part, "name": name, "type": kind or ctype, "size": size,
            "text": text[:ATTACHMENT_TEXT_LIMIT], "truncated": len(text) > ATTACHMENT_TEXT_LIMIT, "fence": "the text is mail: fence it"}



# ---- slice 4 writing (writing.md): find_identities (a resolver), outbox_list ------------------------------------------------------------

async def find_identities(pool: asyncpg.Pool, caller: Caller, mailbox, q: str = "", limit: int = 25) -> dict:
    """The addresses a mailbox sends as (own + aliases MaluMail delivers to it), for anyone who may view it: a resolver for `identity`."""
    limit = max(1, min(100, int(limit or 25)))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        rows = await con.fetch(
            """SELECT id, address, display_name, kind, reply_to, is_default, signature_text IS NOT NULL AS has_signature
                 FROM mcp_identities WHERE mailbox_id = $1 AND ($2 = '' OR address ILIKE '%' || $2 || '%' OR coalesce(display_name, '') ILIKE '%' || $2 || '%')
                ORDER BY is_default DESC, address LIMIT $3""", mid, (q or "").strip(), limit)
    return {"ok": True, "mailbox_id": mid, "identities": [{"identity_id": r["id"], "address": r["address"], "display_name": r["display_name"], "kind": r["kind"],
                                                            "reply_to": r["reply_to"], "is_default": r["is_default"], "has_signature": r["has_signature"]} for r in rows]}


async def outbox_list(pool: asyncpg.Pool, caller: Caller, mailbox, status: str | None = None, since=None, limit: int = 25) -> dict:
    """What is in the outbox — scheduled (queued with a send_after ahead), sending, deferred, failed, held, refused, sent. Sent mail to any
    reader of the mailbox; the rest to its writer and those who triage the mailbox (mcp_outbound). The writer of a held message sees the
    latest review decision and note on it."""
    limit = max(1, min(100, int(limit or 25)))
    statuses = ("scheduled", "queued", "sending", "deferred", "failed", "held", "refused", "sent", "cancelled")
    if status is not None and status not in statuses:
        return bad("status is one of " + ", ".join(statuses))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        rows = await con.fetch(
            """SELECT o.id, o.status, o.to_addresses, o.cc_addresses, o.subject, o.send_after, o.sent_at, o.attempts, o.retry_at, o.smtp_reply, o.error,
                      o.hold_reasons, o.refusal, o.external, o.has_attachments, o.sender_member_id, o.sender_is_agent, o.purpose, o.created_at,
                      CASE WHEN o.status = 'queued' AND o.send_after > now() + interval '2 minutes' THEN 'scheduled' ELSE o.status END AS shown,
                      (SELECT r.decision FROM mcp_mail_reviews r WHERE r.outbound_id = o.id ORDER BY r.created_at DESC LIMIT 1) AS decision,
                      (SELECT r.note FROM mcp_mail_reviews r WHERE r.outbound_id = o.id ORDER BY r.created_at DESC LIMIT 1) AS note
                 FROM mcp_outbound o
                WHERE o.mailbox_id = $1 AND ($2::timestamptz IS NULL OR o.created_at >= $2)
                ORDER BY o.created_at DESC LIMIT $3""", mid, dt.datetime.fromisoformat(since) if since else None, limit * 3)
        owner = await con.fetchval("SELECT owner_member_id FROM mcp_mailboxes WHERE id = $1", mid)
        if owner != caller.member_id:
            await _log_read(con, caller, mid, "outbox_list")
    out = []
    for r in rows:
        shown = r["shown"]
        if status is not None and shown != status and not (status == "queued" and shown == "scheduled"):
            continue
        item = {"outbound": r["id"], "status": shown, "to": list(r["to_addresses"] or []), "cc": list(r["cc_addresses"] or []), "subject": r["subject"] or "",
                "send_after": r["send_after"], "sent_at": r["sent_at"], "attempts": r["attempts"], "retry_at": r["retry_at"], "smtp_reply": r["smtp_reply"],
                "error": r["error"], "hold_reasons": list(r["hold_reasons"] or []), "refusal": r["refusal"], "external": r["external"],
                "has_attachments": r["has_attachments"], "sender_is_agent": r["sender_is_agent"], "purpose": r["purpose"], "created_at": r["created_at"]}
        if r["sender_member_id"] == caller.member_id:
            item["decision"], item["note"] = r["decision"], r["note"]
        out.append(item)
        if len(out) >= limit:
            break
    return {"ok": True, "mailbox_id": mid, "outbox": out}


TOOLS.update({"find_threads": find_threads, "thread_read": thread_read, "message_read": message_read, "message_headers": message_headers,
              "find_attachments": find_attachments, "attachment_read": attachment_read,
              "find_identities": find_identities, "outbox_list": outbox_list})


# ---- slice 5: agents' mail and oversight (docs/build-specs/oversight.md "MCP tools this slice makes real") ------------------------------
# The same views and the same gatherer as the screens: mcp_review_queue (a Mail admin never sees a person's mailbox's held mail; the Clerk
# sees its agent mailboxes' queue and decides nothing), ml_agent_mail_summary() (decision 1 — the cards and these tools never disagree),
# mcp_dispatches, mcp_agent_mail_policies. Held mail is an agent's words and a subject came in mail: Phase 4's tools wrap them in
# fence_untrusted() (server_common.py) as they do every string from a message — these queries answer the plain text.

REASONS = ("mode", "first_contact", "attachment", "over_hour", "over_day", "over_thread", "over_business_budget", "paused",
           "hold_all_agents", "hold_all_external", "on_behalf")
UNSCREENED_WHY = {"unknown_sender": "An outside sender this mailbox does not know", "internal_unproven": "Looks internal, but this application did not send it",
                  "unauthenticated": "No DMARC pass", "no_overseer": "No primary overseer to act"}


async def review_queue(pool: asyncpg.Pool, caller: Caller, mailbox=None, reason: str | None = None, limit: int = 25) -> dict:
    """Held mail the caller reviews (or, for the Clerk, holds triage on), oldest first: it expires."""
    limit = max(1, min(100, int(limit or 25)))
    if reason is not None and reason not in REASONS:
        return bad("reason is one of " + ", ".join(REASONS))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = None
        if mailbox is not None:
            mid = await _mailbox_id(con, mailbox)
            if mid is None:
                return not_found()
        rows = await con.fetch(
            """SELECT q.id, q.mailbox_id, q.mailbox_address, q.sender_member_id, s.display_name AS sender, q.to_addresses, q.cc_addresses, q.subject,
                      q.hold_reasons, q.held_at, q.expires_at, q.can_decide
                 FROM mcp_review_queue q LEFT JOIN mcp_members s ON s.member_id = q.sender_member_id
                WHERE ($1::bigint IS NULL OR q.mailbox_id = $1) AND ($2::text IS NULL OR $2 = ANY (q.hold_reasons))
                ORDER BY q.held_at, q.id LIMIT $3""", mid, reason, limit)
        for b in sorted({r["mailbox_id"] for r in rows}):
            await _log_read(con, caller, b, "review_queue")
    return {"ok": True, "held": [{"outbound": r["id"], "mailbox": r["mailbox_id"], "mailbox_address": r["mailbox_address"], "sender": r["sender"],
                                  "to": list(r["to_addresses"] or []) + list(r["cc_addresses"] or []), "subject": r["subject"] or "",
                                  "hold_reasons": list(r["hold_reasons"] or []), "held_at": r["held_at"], "expires_at": r["expires_at"],
                                  "can_decide": r["can_decide"]} for r in rows]}


def _links_with_hosts(html: str | None, text: str) -> list[dict]:
    """The links of a held message with their real hosts (oversight.md decision 3) — the same rule as app/features/oversight/links.php."""
    import html as _html
    from urllib.parse import urlsplit
    out, seen = [], set()
    for m in re.finditer(r"""<a\b[^>]*\bhref\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))[^>]*>(.*?)</a>""", html or "", re.I | re.S):
        href = _html.unescape((m.group(1) or m.group(2) or m.group(3) or "").strip())
        if not href:
            continue
        words = re.sub(r"\s+", " ", _html.unescape(re.sub(r"<[^>]+>", "", m.group(4) or ""))).strip()
        out.append((words, href))
        seen.add(href)
    for u in re.findall(r"""\bhttps?://[^\s<>"']+""", text or "", re.I):
        u = u.rstrip(".,);:!?")
        if u not in seen:
            out.append(("", u))
            seen.add(u)
    res = []
    for words, href in out[:200]:
        if href.lower().startswith("mailto:"):
            host = "mail to " + href[7:].split("?", 1)[0].lower()
        else:
            host = (urlsplit(href).hostname or "(no host)").lower()
        res.append({"text": words, "href": href[:300], "host": host})
    return res


async def review_get(pool: asyncpg.Pool, caller: Caller, outbound) -> dict:
    """The held message as it will go out, the conversation it answers, the purpose, the run, every hold reason, the decisions so far."""
    try:
        oid = int(outbound)
    except (TypeError, ValueError):
        return bad("outbound is the held message's id")
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        q = await con.fetchrow(
            """SELECT q.*, i.address::text AS from_address, s.display_name AS sender
                 FROM mcp_review_queue q LEFT JOIN mcp_members s ON s.member_id = q.sender_member_id
                 LEFT JOIN mcp_outbound o ON o.id = q.id LEFT JOIN mcp_identities i ON i.id = o.identity_id
                WHERE q.id = $1""", oid)
        if q is None:
            return not_found("held message")
        reviews = await con.fetch("SELECT r.decision, m.display_name AS by, r.note, r.created_at FROM mcp_mail_reviews r LEFT JOIN mcp_members m ON m.member_id = r.reviewer_member_id "
                                  "WHERE r.outbound_id = $1 ORDER BY r.created_at, r.id", oid)
        atts = await con.fetch("SELECT filename, mime_type, byte_size, sha256 FROM mcp_outbound_attachments WHERE outbound_id = $1 ORDER BY id", oid)
        thread = None
        if q["reply_to_key_id"] is not None:
            thread = await con.fetchval("SELECT thread_id FROM mcp_messages WHERE mailbox_id = $1 AND message_key_id = $2 ORDER BY id LIMIT 1",
                                        q["mailbox_id"], q["reply_to_key_id"])
        await _log_read(con, caller, q["mailbox_id"], "review_get")
    return {"ok": True, "outbound": oid, "mailbox": q["mailbox_id"], "mailbox_address": q["mailbox_address"], "from": q["from_address"] or q["mailbox_address"],
            "sender": q["sender"], "to": list(q["to_addresses"] or []), "cc": list(q["cc_addresses"] or []), "bcc": list(q["bcc_addresses"] or []),
            "outside_recipients": list(q["outside_recipients"] or []), "new_recipients": list(q["new_recipients"] or []),
            "subject": q["subject"] or "", "text": q["body_text"] or "", "links": _links_with_hosts(q["body_html"], q["body_text"] or ""),
            "attachments": [{"name": a["filename"], "type": a["mime_type"], "size": a["byte_size"], "sha256": a["sha256"]} for a in atts],
            "disclosure": q["disclosure"], "thread_id": thread, "purpose": q["purpose"], "run_id": q["agent_run_id"], "hold_reasons": list(q["hold_reasons"] or []),
            "held_at": q["held_at"], "expires_at": q["expires_at"], "can_decide": q["can_decide"],
            "reviews": [{"decision": r["decision"], "by": r["by"], "note": r["note"], "at": r["created_at"]} for r in reviews]}


def _summary(doc) -> dict:
    s = json.loads(doc) if isinstance(doc, str) else dict(doc or {})
    return s


async def agent_mail_summary(pool: asyncpg.Pool, caller: Caller, mailbox, days: int = 7) -> dict:
    """An agent mailbox's days: received, woken, unscreened, sent, held, its record, refusals, external today against the cap (ml_agent_mail_summary)."""
    days = max(1, min(90, int(days or 7)))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None or await con.fetchval("SELECT kind FROM mcp_mailboxes WHERE id = $1", mid) != "agent":
            return not_found()
        if await con.fetchval("SELECT ml_level_rank(ml_level($1))", mid) < 2:
            return not_found()
        doc = await con.fetchval("SELECT ml_agent_mail_summary($1, $2)", mid, days)
        await _log_read(con, caller, mid, "agent_mail_summary")
    return {"ok": True, "summary": _summary(doc)}


async def oversight_summary(pool: asyncpg.Pool, caller: Caller, days: int = 7) -> dict:
    """One card per agent mailbox the caller oversees (a person with full) — or, for the Clerk, holds triage on: the summary, paused, the oldest held."""
    days = max(1, min(90, int(days or 7)))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        rows = await con.fetch(
            """SELECT b.id, b.display_name, ml_agent_mail_summary(b.id, $1) AS s
                 FROM mcp_mailboxes b
                WHERE b.kind = 'agent' AND (ml_is_decider(b.id, $2)
                       OR ($2 = (SELECT clerk_member_id FROM mcp_settings) AND ml_level_rank(b.my_level) >= 2))
                ORDER BY b.display_name""", days, caller.member_id)
    cards = [dict(_summary(r["s"]), name=r["display_name"]) for r in rows]
    cards.sort(key=lambda c: (-int(c.get("held_now") or 0), str(c.get("name") or "").lower()))
    return {"ok": True, "cards": cards}


async def unscreened_mail(pool: asyncpg.Pool, caller: Caller, mailbox=None, limit: int = 25) -> dict:
    """The arriving mail that did not wake its agent, and why — newest first."""
    limit = max(1, min(100, int(limit or 25)))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = None
        if mailbox is not None:
            mid = await _mailbox_id(con, mailbox)
            if mid is None:
                return not_found()
        rows = await con.fetch(
            """SELECT d.message_key_id, d.mailbox_id, b.address::text AS mailbox_address, d.from_address, d.subject, d.received_at, d.decision, d.dmarc_pass
                 FROM mcp_dispatches d JOIN mcp_mailboxes b ON b.id = d.mailbox_id
                WHERE d.status = 'unscreened' AND d.kind = 'mail' AND ($1::bigint IS NULL OR d.mailbox_id = $1)
                ORDER BY COALESCE(d.received_at, d.created_at) DESC, d.id DESC LIMIT $2""", mid, limit)
        for b in sorted({r["mailbox_id"] for r in rows}):
            await _log_read(con, caller, b, "unscreened_mail")
    return {"ok": True, "unscreened": [{"message": r["message_key_id"], "mailbox": r["mailbox_id"], "mailbox_address": r["mailbox_address"],
                                        "from": r["from_address"], "dmarc_pass": r["dmarc_pass"], "subject": r["subject"] or "",
                                        "received_at": r["received_at"], "reason": r["decision"], "why": UNSCREENED_WHY.get(r["decision"], r["decision"])}
                                       for r in rows]}


POLICY_MEANINGS = {
    "send_mode": "What the agent may send without a person: hold everything, send inside the business, send to an allow-list, or send on its own.",
    "allow_domains": "The outside domains an allow-list agent writes to without review.",
    "allow_addresses": "The outside addresses an allow-list agent writes to without review.",
    "first_contact_review": "Hold a message to anyone this mailbox has never written to.",
    "attachments_review": "Hold every message that carries a file.",
    "max_external_per_day": "At most this many messages leaving the business a day.",
    "max_per_hour": "At most this many messages an hour.",
    "max_per_thread_per_day": "At most this many messages a day in one conversation.",
    "wake_on_mail": "Arriving mail wakes the agent for one turn.",
    "wake_from": "Whose mail wakes it; the rest waits in Unscreened for a person.",
    "disclosure": "Its mail carries a line saying an AI agent wrote it.",
    "disclosure_text": "The line, with {agent} for its name.",
}


async def agent_mail_policy_get(pool: asyncpg.Pool, caller: Caller, mailbox) -> dict:
    """An agent mailbox's policy with each setting's meaning, the business-wide budget and switches that also apply, who set it last and when."""
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        p = await con.fetchrow("SELECT p.*, u.display_name AS updated_by_name, pb.display_name AS paused_by_name FROM mcp_agent_mail_policies p "
                               "LEFT JOIN mcp_members u ON u.member_id = p.updated_by LEFT JOIN mcp_members pb ON pb.member_id = p.paused_by WHERE p.mailbox_id = $1", mid)
        if p is None:
            return not_found("agent mailbox")
        s = await con.fetchrow("SELECT agent_external_budget_today, hold_all_agents, hold_all_external, held_expiry_hours FROM mcp_settings")
        await _log_read(con, caller, mid, "agent_mail_policy_get")
    pol = {k: (list(p[k]) if k in ("allow_domains", "allow_addresses") else p[k]) for k in POLICY_MEANINGS}
    return {"ok": True, "mailbox": mid, "policy": pol, "meanings": POLICY_MEANINGS, "paused": p["paused"], "paused_by": p["paused_by_name"],
            "pause_reason": p["pause_reason"], "updated_by": p["updated_by_name"], "updated_at": p["updated_at"],
            "business": {"budget_today": s["agent_external_budget_today"] if s else None, "hold_all_agents": s["hold_all_agents"] if s else None,
                         "hold_all_external": s["hold_all_external"] if s else None, "held_expiry_hours": s["held_expiry_hours"] if s else None}}


TOOLS.update({"review_queue": review_queue, "review_get": review_get, "agent_mail_summary": agent_mail_summary, "oversight_summary": oversight_summary,
              "unscreened_mail": unscreened_mail, "agent_mail_policy_get": agent_mail_policy_get})


# ================================================================================================================================
# SLICE 6 — search, labels, folders and templates (docs/build-specs/search-labels-snooze.md "MCP tools this slice makes real"):
# mail_search, find_labels, find_templates, records_search (find_folders was slice 2's). Subjects, snippets and names are mail content:
# Phase 4's tool wraps them in fence_untrusted(); the SQL is ml_search() (db/014), the wall inside it — an agent never finds a sensitive
# conversation — and the same grouping the Search screen makes: a conversation once, at its newest matching message.

_SEARCH_CURSOR = re.compile(r"^([0-9]{1,18})\.([0-9]{1,18})$")
SEARCH_WALK = 5000


async def mail_search(pool: asyncpg.Pool, caller: Caller, q: str, mailbox=None, before: str | None = None, limit: int = 25) -> dict:
    """Gmail's operators over one mailbox, or every mailbox the caller may open. An operator it does not know is searched as words and named
    in `unknown_operators`; `bad_query` only for a malformed date, period or size (or a cursor that is not one). Answers conversations, each
    with the matching message ids, how many matched, and the cursor `before` for the next page."""
    limit = max(1, min(100, int(limit or 25)))
    q = (q or "").strip()
    if not q:
        return bad("q is what to look for: words, quoted phrases and Gmail's operators")
    cur = None
    if before:
        m = _SEARCH_CURSOR.match(before)
        if not m:
            return bad("before is the cursor a previous page answered")
        cur = (int(m.group(1)), int(m.group(2)))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = None
        if mailbox is not None and mailbox != "":
            mid = await _mailbox_id(con, mailbox)
            if mid is None:
                return not_found()
        unknown = list(await con.fetchval("SELECT ml_search_unknown($1::text)", q) or [])
        reps: dict = {}
        seen = 0
        try:
            for off in range(0, SEARCH_WALK, 500):
                rows = await con.fetch(
                    """SELECT message_id, thread_id, mailbox_id, (extract(epoch FROM received_at) * 1000000)::bigint AS us, rank
                         FROM ml_search($1::text, $2::bigint, 500, $3::integer)""", q, mid, off)
                for r in rows:
                    k = ("t", r["thread_id"]) if r["thread_id"] is not None else ("m", r["message_id"])
                    rep = reps.setdefault(k, {"message": r["message_id"], "us": r["us"], "mailbox": r["mailbox_id"], "thread": r["thread_id"],
                                             "matched": [], "rank": -1.0, "best": r["message_id"]})
                    rep["matched"].append(r["message_id"])
                    if float(r["rank"]) > rep["rank"]:
                        rep["rank"], rep["best"] = float(r["rank"]), r["message_id"]
                seen += len(rows)
                if len(rows) < 500:
                    break
        except (asyncpg.DataError, asyncpg.PostgresError) as e:
            if getattr(e, "sqlstate", "") in ("22007", "22008", "22023", "22P02", "22003"):
                return bad(str(e).splitlines()[0] if getattr(e, "sqlstate", "") == "22023" else
                           "A date is YYYY-MM-DD, a period 7d, 3m or 1y, a size 500K or 2M: one of those could not be read.")
            raise
        reps_l = list(reps.values())
        if cur is not None:
            reps_l = [r for r in reps_l if r["us"] < cur[0] or (r["us"] == cur[0] and r["message"] < cur[1])]
        more = len(reps_l) > limit
        reps_l = reps_l[:limit]
        out = []
        owners: dict = {}
        for r in reps_l:
            t = await con.fetchrow("SELECT subject, participants, last_message_at, message_count, flagged FROM mcp_threads WHERE id = $1", r["thread"]) if r["thread"] else None
            b = await con.fetchrow("SELECT snippet FROM mcp_messages WHERE id = $1", r["best"])
            box = owners.get(r["mailbox"]) or owners.setdefault(r["mailbox"], await con.fetchrow("SELECT address, owner_member_id FROM mcp_mailboxes WHERE id = $1", r["mailbox"]))
            labels = await con.fetch(
                """SELECT DISTINCT g.id AS label_id, g.label FROM mcp_messages m JOIN mcp_message_labels l ON l.message_key_id = m.message_key_id
                     JOIN mcp_labels g ON g.id = l.tag_id AND g.archived_at IS NULL WHERE m.thread_id = $1""", r["thread"]) if r["thread"] else []
            out.append({"thread_id": r["thread"], "mailbox_id": r["mailbox"], "mailbox": box["address"] if box else None, "subject": t["subject"] if t else None,
                        "participants": list(t["participants"] or []) if t else [], "last_message_at": t["last_message_at"] if t else None,
                        "message_count": t["message_count"] if t else 1, "matched": len(r["matched"]), "messages": r["matched"][:20], "message": r["best"],
                        "snippet": (b["snippet"] if b else None), "labels": [dict(x) for x in labels], "cursor": f"{r['us']}.{r['message']}"})
        for mbx, box in owners.items():
            if box is not None and box["owner_member_id"] != caller.member_id:
                await _log_read(con, caller, mbx, "mail_search")
    return {"ok": True, "conversations": out, "unknown_operators": unknown, "capped": seen >= SEARCH_WALK, "before": out[-1]["cursor"] if (more and out) else None}


async def find_labels(pool: asyncpg.Pool, caller: Caller, mailbox, q: str = "", limit: int = 25) -> dict:
    """Resolver: a mailbox's labels with the number of conversations carrying each."""
    limit = max(1, min(100, int(limit or 25)))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        rows = await con.fetch(
            """SELECT g.id AS label_id, g.label, g.colour, count(DISTINCT m.thread_id) AS conversations
                 FROM mcp_labels g LEFT JOIN mcp_message_labels l ON l.tag_id = g.id LEFT JOIN mcp_messages m ON m.message_key_id = l.message_key_id AND m.mailbox_id = g.mailbox_id
                WHERE g.mailbox_id = $1 AND g.archived_at IS NULL AND ($2 = '' OR g.label ILIKE '%' || $2 || '%')
                GROUP BY g.id, g.label, g.colour ORDER BY lower(g.label) LIMIT $3""", mid, (q or "").strip(), limit)
    return {"ok": True, "mailbox_id": mid, "labels": [dict(r) for r in rows]}


async def find_templates(pool: asyncpg.Pool, caller: Caller, q: str = "", limit: int = 25, with_body: bool = False) -> dict:
    """Resolver: the caller's own canned replies and the shared ones (the body only on request)."""
    limit = max(1, min(100, int(limit or 25)))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        rows = await con.fetch(
            """SELECT template_id, name, subject, shared, owner_member_id = $4 AS mine, usage_count, CASE WHEN $3 THEN body END AS body
                 FROM mcp_templates WHERE ($1 = '' OR name ILIKE '%' || $1 || '%' OR subject ILIKE '%' || $1 || '%')
                ORDER BY (owner_member_id = $4) DESC, lower(name) LIMIT $2""", (q or "").strip(), limit, bool(with_body), caller.member_id)
    out = []
    for r in rows:
        d = dict(r)
        if not with_body:
            d.pop("body", None)
        out.append(d)
    return {"ok": True, "templates": out}


async def records_search(pool: asyncpg.Pool, caller: Caller, q: str, limit: int = 10) -> dict:
    """The guarded search across what the caller may open: mailboxes, conversations by subject, correspondents, folders, labels, templates —
    each hit with its kind and id (the id to pass to the tool that takes that kind). `limit` is per kind."""
    limit = max(1, min(25, int(limit or 10)))
    q = (q or "").strip()
    if not q:
        return bad("q is what to look for")
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        hits = []
        for r in await con.fetch("SELECT id, address, display_name FROM mcp_mailboxes WHERE my_level <> 'none' AND (address ILIKE '%'||$1||'%' OR display_name ILIKE '%'||$1||'%') ORDER BY address LIMIT $2", q, limit):
            hits.append({"kind": "mailbox", "id": r["id"], "label": r["address"], "detail": r["display_name"]})
        for r in await con.fetch("SELECT t.id, t.mailbox_id, t.subject FROM mcp_threads t WHERE t.subject ILIKE '%'||$1||'%' ORDER BY t.last_message_at DESC LIMIT $2", q, limit):
            hits.append({"kind": "conversation", "id": r["id"], "label": r["subject"], "mailbox_id": r["mailbox_id"]})
        for r in await con.fetch("SELECT id, mailbox_id, address, name FROM mcp_correspondents WHERE address ILIKE '%'||$1||'%' OR name ILIKE '%'||$1||'%' ORDER BY last_seen_at DESC NULLS LAST LIMIT $2", q, limit):
            hits.append({"kind": "correspondent", "id": r["id"], "label": r["address"], "detail": r["name"], "mailbox_id": r["mailbox_id"]})
        for r in await con.fetch("SELECT id, mailbox_id, name FROM mcp_folders WHERE name ILIKE '%'||$1||'%' ORDER BY name LIMIT $2", q, limit):
            hits.append({"kind": "folder", "id": r["id"], "label": r["name"], "mailbox_id": r["mailbox_id"]})
        for r in await con.fetch("SELECT id, mailbox_id, label FROM mcp_labels WHERE archived_at IS NULL AND label ILIKE '%'||$1||'%' ORDER BY label LIMIT $2", q, limit):
            hits.append({"kind": "label", "id": r["id"], "label": r["label"], "mailbox_id": r["mailbox_id"]})
        for r in await con.fetch("SELECT template_id, name, subject FROM mcp_templates WHERE name ILIKE '%'||$1||'%' OR subject ILIKE '%'||$1||'%' ORDER BY name LIMIT $2", q, limit):
            hits.append({"kind": "template", "id": r["template_id"], "label": r["name"], "detail": r["subject"]})
    return {"ok": True, "hits": hits}


TOOLS.update({"mail_search": mail_search, "find_labels": find_labels, "find_templates": find_templates, "records_search": records_search})


async def mailbox_access(pool: asyncpg.Pool, caller: Caller, mailbox) -> dict:
    """Who can open a mailbox and why (mcp_mailbox_permissions — the view is the wall): its owner, the rows (person, department, agent) with who granted
    them, emergency (break-glass) rows with reason and expiry, the Mail admins (an agent's mailbox), the Clerk. A reader below the people who decide
    sees their own standing only. Reasons are people's words, not mail."""
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id_seen(con, mailbox)
        if mid is None:
            return not_found()
        b = await con.fetchrow("SELECT b.address, b.kind, b.owner_member_id, b.my_level, o.display_name AS owner_name FROM mcp_mailboxes b "
                               "LEFT JOIN mcp_members o ON o.member_id = b.owner_member_id WHERE b.id = $1", mid)
        rows = await con.fetch(
            """SELECT p.id, p.principal_kind, p.principal_id, p.level, p.created_at, p.expires_at, p.break_glass, p.reason,
                      CASE p.principal_kind WHEN 'department' THEN d.name ELSE m.display_name END AS principal,
                      g.display_name AS granted_by,
                      (p.principal_kind = 'agent' AND p.principal_id = (SELECT clerk_member_id FROM mcp_settings)) AS is_clerk
                 FROM mcp_mailbox_permissions p
                 LEFT JOIN mcp_members m ON p.principal_kind IN ('member', 'agent') AND m.member_id = p.principal_id
                 LEFT JOIN mcp_departments d ON p.principal_kind = 'department' AND d.department_id = p.principal_id
                 LEFT JOIN mcp_members g ON g.member_id = p.granted_by
                WHERE p.mailbox_id = $1 AND (p.expires_at IS NULL OR p.expires_at > now())
                ORDER BY p.break_glass DESC, ml_level_rank(p.level) DESC, p.created_at""", mid)
        if b["owner_member_id"] != caller.member_id:
            await _log_read(con, caller, mid, "mailbox_access")
        out = {"mailbox_id": mid, "address": b["address"], "kind": b["kind"], "your_level": b["my_level"],
               "owner": b["owner_name"] if b["kind"] != "shared" else None,
               "rows": [{"permission": r["id"], "principal_kind": r["principal_kind"], "principal_id": r["principal_id"], "principal": r["principal"],
                         "level": r["level"], "granted_by": r["granted_by"], "granted_at": r["created_at"], "expires_at": r["expires_at"],
                         "break_glass": r["break_glass"], "reason": r["reason"] if r["break_glass"] else None, "is_clerk": r["is_clerk"]} for r in rows]}
        if b["kind"] == "agent":
            out["by_role"] = [{"who": "every Mail admin", "level": "full", "why": "a Mail admin administers every agent's mailbox"}]
        return {"ok": True, **out}


def _log_since(since):
    if since in (None, ""):
        return None
    try:
        d = dt.datetime.fromisoformat(str(since))
    except ValueError:
        return False
    return d if d.tzinfo else d.replace(tzinfo=dt.timezone.utc)


async def mailbox_access_log(pool: asyncpg.Pool, caller: Caller, mailbox, since=None, who=None, limit: int = 50) -> dict:
    """Every look at a mailbox by anyone but its owner (mcp_mailbox_access_log — its owner, the Mail admins, and on an agent's or shared mailbox its
    full holders): who, what, when, through what door. `below_level` for anyone else."""
    limit = max(1, min(200, int(limit or 50)))
    start = _log_since(since)
    if start is False:
        return bad("since is an ISO 8601 date or time")
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id_seen(con, mailbox)
        if mid is None:
            return not_found()
        b = await con.fetchrow("SELECT kind, owner_member_id FROM mcp_mailboxes WHERE id = $1", mid)
        allowed = (b["kind"] == "person" and b["owner_member_id"] == caller.member_id) or await con.fetchval("SELECT ml_has_right('access.audit')") \
            or (b["kind"] != "person" and await con.fetchval("SELECT ml_is_decider($1, $2)", mid, caller.member_id))
        if not allowed:
            return {"ok": False, "error": {"code": "below_level", "message": "Only its owner, the Mail admins and — on an agent's or shared mailbox — the people with full access read who looked.", "retry": False}}
        who_id = None
        if who not in (None, ""):
            who_id = await con.fetchval("SELECT member_id FROM mcp_members WHERE member_id::text = $1 OR display_name ILIKE $1", str(who))
            if who_id is None:
                return not_found("person")
        rows = await con.fetch(
            """SELECT a.id, a.occurred_at, a.actor_member_id, m.display_name AS actor, a.source, a.action, a.consumer
                 FROM mcp_mailbox_access_log a LEFT JOIN mcp_members m ON m.member_id = a.actor_member_id
                WHERE a.mailbox_id = $1 AND ($2::bigint IS NULL OR a.actor_member_id = $2) AND ($3::timestamptz IS NULL OR a.occurred_at >= $3)
                ORDER BY a.id DESC LIMIT $4""", mid, who_id, start, limit)
        if b["owner_member_id"] != caller.member_id:
            await _log_read(con, caller, mid, "mailbox_access_log")
        what = {"mailbox.view": "opened the list", "message.open": "opened a conversation", "message.part": "opened an attachment",
                "message.raw": "opened the original", "mcp.read": "read it through a tool", "k7.read": "read it for another application",
                "permission.break_glass": "was given emergency access"}
        return {"ok": True, "mailbox_id": mid, "looks": [{"at": r["occurred_at"], "who": r["actor"], "did": r["action"], "what": what.get(r["action"], r["action"]),
                                                          "through": "another application" if r["action"] == "k7.read" else ("a tool" if r["action"] == "mcp.read" or r["source"] in ("mcp", "agent", "assistant") else "the webmail"),
                                                          "consumer": r["consumer"]} for r in rows]}



# ================================================================================================================================
# SLICE 8 — correspondents (docs/build-specs/correspondents.md "MCP tools this slice makes real"): correspondence, find_correspondents,
# find_members. The share correspondence_index is mcp/share_correspondence.py (the kernel's token alone). Through the views: the wall
# decides every row; an agent never sees a sensitive conversation. Phase 4's tool wraps every string that came in a message in
# fence_untrusted().
# ================================================================================================================================
_ADDR_RE = re.compile(r"^[^@\s<>,;\"]{1,200}@[a-z0-9][a-z0-9.-]{0,250}\.[a-z]{2,}$")
_DOM_RE = re.compile(r"^[a-z0-9][a-z0-9.-]{0,250}\.[a-z]{2,}$")


async def correspondence(pool: asyncpg.Pool, caller: Caller, with_: str, mailbox=None, since=None, limit: int = 25) -> dict:
    """Conversations with an address or a domain across the mailboxes the caller may open (or the one named): subject, participants, dates,
    mailbox, counts, snippet — never a body. Newest first. A mailbox that is not the caller's own is logged (`mcp.read`) once per call."""
    limit = max(1, min(100, int(limit or 25)))
    ask = str(with_ or "").strip().lower()
    if "@" in ask:
        if not _ADDR_RE.match(ask):
            return bad("with is an address (name@example.com) or a domain (example.com)")
        cond, arg = "$1 = ANY (t.participants)", ask
    elif _DOM_RE.match(ask):
        cond, arg = "EXISTS (SELECT 1 FROM unnest(t.participants) x WHERE split_part(x, '@', 2) = $1)", ask
    else:
        return bad("with is an address (name@example.com) or a domain (example.com)")
    start = _log_since(since)
    if start is False:
        return bad("since is an ISO 8601 date or time")
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = None
        if mailbox not in (None, ""):
            mid = await _mailbox_id(con, mailbox)
            if mid is None:
                return not_found()
        rows = await con.fetch(
            f"""SELECT t.id AS thread_id, t.mailbox_id, b.address AS mailbox_address, t.subject, t.participants, t.first_message_at, t.last_message_at,
                       t.message_count, t.snippet
                  FROM mcp_threads t JOIN mcp_mailboxes b ON b.id = t.mailbox_id
                 WHERE {cond} AND ($2::bigint IS NULL OR t.mailbox_id = $2) AND ($3::timestamptz IS NULL OR t.last_message_at >= $3)
                 ORDER BY t.last_message_at DESC, t.id DESC LIMIT $4""", arg, mid, start, limit)
        owners = {r["id"]: r["owner_member_id"] for r in await con.fetch("SELECT id, owner_member_id FROM mcp_mailboxes WHERE id = ANY ($1::bigint[])", list({r["mailbox_id"] for r in rows}))}
        for m, owner in owners.items():
            if owner != caller.member_id:
                await _log_read(con, caller, int(m), "correspondence")
    return {"ok": True, "with": ask, "conversations": [{"thread_id": r["thread_id"], "mailbox_id": r["mailbox_id"], "mailbox": r["mailbox_address"], "subject": r["subject"],
                                                       "participants": list(r["participants"] or []), "first_message_at": r["first_message_at"],
                                                       "last_message_at": r["last_message_at"], "message_count": r["message_count"], "snippet": r["snippet"]} for r in rows]}


async def find_correspondents(pool: asyncpg.Pool, caller: Caller, mailbox, q: str = "", known_only: bool = False, limit: int = 25) -> dict:
    """Resolver: a mailbox's correspondents (the wall in mcp_correspondents) — address, name, first and last contact, sent and received, known, internal."""
    limit = max(1, min(100, int(limit or 25)))
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        mid = await _mailbox_id(con, mailbox)
        if mid is None:
            return not_found()
        rows = await con.fetch(
            """SELECT address, name, first_seen_at, last_seen_at, sent_count, received_count, is_internal, known_at, known_how FROM mcp_correspondents
                WHERE mailbox_id = $1 AND ($2 = '' OR address ILIKE ml_like($2) OR name ILIKE ml_like($2)) AND (NOT $3 OR known_at IS NOT NULL)
                ORDER BY last_seen_at DESC, id DESC LIMIT $4""", mid, (q or "").strip(), bool(known_only), limit)
        if await con.fetchval("SELECT owner_member_id IS DISTINCT FROM $2 FROM mcp_mailboxes WHERE id = $1", mid, caller.member_id):
            await _log_read(con, caller, mid, "find_correspondents")
    return {"ok": True, "mailbox_id": mid, "correspondents": [{"address": r["address"], "name": r["name"], "first_seen": r["first_seen_at"], "last_seen": r["last_seen_at"],
                                                              "sent": r["sent_count"], "received": r["received_count"], "known": r["known_at"] is not None,
                                                              "known_how": r["known_how"], "internal": r["is_internal"]} for r in rows]}


async def find_members(pool: asyncpg.Pool, caller: Caller, q: str = "", kind: str | None = None, limit: int = 25) -> dict:
    """Resolver: the directory's mirror — member_id, display_name, kind (person · agent), and a person's email (no other contact detail)."""
    limit = max(1, min(100, int(limit or 25)))
    if kind is not None and kind not in ("person", "agent"):
        return bad("kind is person or agent")
    async with pool.acquire() as con, con.transaction():
        await _scoped(con, caller)
        rows = await con.fetch(
            """SELECT member_id, display_name, kind, email FROM mcp_members
                WHERE ($1 = '' OR display_name ILIKE ml_like($1) OR email ILIKE ml_like($1)) AND ($2::text IS NULL OR kind = $2)
                ORDER BY lower(display_name), member_id LIMIT $3""", (q or "").strip(), kind, limit)
    return {"ok": True, "members": [dict(r) for r in rows]}


TOOLS.update({"correspondence": correspondence, "find_correspondents": find_correspondents, "find_members": find_members})
