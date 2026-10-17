"""The K7 share `correspondence_index` (docs/build-specs/correspondents.md "The K7 share"; design §3 "The K7 exception", §4, D15).

THE ONE THING THAT LEAVES MALUMAIL FOR ANOTHER APPLICATION. Phase 4's records server mounts `correspondence_index()` for the kernel's token
alone and passes it the consumer the KERNEL named (K26: the headers `X-OS-Consumer` and `X-OS-Consumer-Agent`); nothing the consumer put in
its arguments is read as identity — an `as_agent` argument is ignored here as the kernel strips it before the call.

What it answers, and only this: conversations (never a message, a body, an attachment or a header) of the mailboxes whose OWNER or deciders
marked them *shared with applications* (agents' and shared mailboxes by default; a person's only by its owner's switch), active and not
suspended, never a conversation marked sensitive — all of it decided in SQL by ml_correspondence_index() (db/014), which this module does not
second-guess or widen. Per conversation: subject, participants, first and last message time, message count, a snippet of at most 200
characters and the conversation's own URL (the consumer's person opens it only if MaluMail's wall lets them). At most 256 kB (K7's cap): rows
past it are cut and `truncated` says so.

Every answer that drew on a mailbox writes ONE `k7.read` row per mailbox (ml_log_k7_read, db/014/021): the consumer, its agent, the count and the
DOMAIN asked about — never a subject, an address or a snippet. The mailbox's owner and the Mail admins read those rows in the access log.
"""

from __future__ import annotations

import json
import re

import asyncpg

import db
from records_server import _log_since, bad

SCHEMA = "os.malumail-correspondence/1"
CAP_BYTES = 256 * 1024                                   # K7's cap on an answer
SNIPPET = 200
_ADDRESS = re.compile(r"^[^@\s<>,;\"]{1,200}@[a-z0-9][a-z0-9.-]{0,250}\.[a-z]{2,}$")
_DOMAIN = re.compile(r"^[a-z0-9][a-z0-9.-]{0,250}\.[a-z]{2,}$")
_CONSUMER = re.compile(r"^[a-z0-9_]{1,60}$")


def _refuse(code: str, message: str) -> dict:
    return {"ok": False, "error": {"code": code, "message": message, "retry": False}}


def _item(r, base: str) -> dict:
    return {"thread_id": int(r["thread_id"]), "mailbox": int(r["mailbox_id"]), "mailbox_address": r["mailbox_address"], "subject": r["subject"],
            "participants": list(r["participants"] or []), "first_message_at": r["first_message_at"], "last_message_at": r["last_message_at"],
            "message_count": int(r["message_count"]), "snippet": (r["snippet"] or "")[:SNIPPET],
            "url": f"{base}/m/{int(r['mailbox_id'])}/t/{int(r['thread_id'])}"}


async def correspondence_index(pool: asyncpg.Pool, consumer: str | None, consumer_agent: int | None, params: dict | None) -> dict:
    """The share. `consumer` and `consumer_agent` are what the kernel's headers said (the caller passes them through untouched); `params` is the
    consumer's own argument object — `with`, `since`, `limit` are read from it, nothing else (`as_agent` in particular is never read)."""
    params = params or {}
    # fail closed: a call the kernel did not name a consumer for reads nothing and writes nothing
    if not isinstance(consumer, str) or not _CONSUMER.match(consumer):
        return _refuse("consumer_required", "The kernel names the consuming application; this call named none, so nothing was read.")
    agent = consumer_agent if isinstance(consumer_agent, int) and not isinstance(consumer_agent, bool) and consumer_agent > 0 else None

    ask = str(params.get("with") or "").strip().lower()
    if not ask:
        return bad("with is an address or a domain")
    if "@" in ask:
        if not _ADDRESS.match(ask):
            return bad("with is an address (name@example.com) or a domain (example.com)")
        address, domain = ask, ask.rsplit("@", 1)[1]
    else:
        if not _DOMAIN.match(ask):
            return bad("with is an address (name@example.com) or a domain (example.com)")
        address, domain = None, ask
    since = _log_since(params.get("since"))
    if since is False:
        return bad("since is an ISO 8601 date or time")
    try:
        limit = 25 if params.get("limit") is None else max(1, min(100, int(params["limit"])))
    except (TypeError, ValueError):
        return bad("limit is a number from 1 to 100")

    base = (db.ENV.get("APP_URL") or "").rstrip("/")
    async with pool.acquire() as con, con.transaction():
        # no app.member_id is set: this door has no member, and the SQL function asks no wall — the owner's switch is the rule it holds
        rows = await con.fetch("SELECT * FROM ml_correspondence_index($1, $2, $3, $4)", address, domain if address is None else None, limit, since)
        out, truncated = [], False
        for r in rows:
            out.append(_item(r, base))
            if len(json.dumps({"schema": SCHEMA, "truncated": True, "conversations": out}, default=str).encode()) > CAP_BYTES:
                out.pop()
                truncated = True
                break
        counts: dict[int, int] = {}
        for it in out:
            counts[it["mailbox"]] = counts.get(it["mailbox"], 0) + 1
        if counts:
            await con.execute("SELECT ml_log_k7_read($1::bigint[], $2, $3, $4::integer[], $5)", list(counts.keys()), consumer, agent, list(counts.values()), domain)
    return {"ok": True, "schema": SCHEMA, "truncated": truncated, "conversations": out}
