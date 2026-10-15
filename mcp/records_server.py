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


TOOLS.update({"find_threads": find_threads, "thread_read": thread_read, "message_read": message_read, "message_headers": message_headers,
              "find_attachments": find_attachments, "attachment_read": attachment_read})
