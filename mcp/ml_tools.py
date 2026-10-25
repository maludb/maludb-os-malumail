"""The plumbing both MCP servers share (docs/malumail-mcp-tool-surface.md "Conventions"): the parameter base (Pydantic v2, extra="forbid"), the caller
the gate resolved, the error codes of the surface's table, the resolvers' plain-list shape and THE FENCE — every string that came in a message
reaches a model inside fence_untrusted()'s block, applied here to every answer of every tool that carries one, in ONE place, so a new field cannot
slip past it by being forgotten in a query. Links are listed with their real hosts and never fetched; hidden content is flagged, never shown."""
import json
import re
from typing import Any

from pydantic import BaseModel, ConfigDict

import db
from records_server import Caller
from server_common import fence_untrusted

RO = {"readOnlyHint": True, "openWorldHint": False}
Ref = int | str


class Base(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True, populate_by_name=True)


def caller() -> Caller:
    """The member the gate admitted (a person, or an agent) and, in an agent's run for a person, that person (K38)."""
    return Caller(db.request_member_id.get(), db.request_on_behalf.get(), db.request_run_id.get(), None)


# ---- errors ----------------------------------------------------------------------------------------------------------------------------
# The mail service's words → the surface's codes. A refusal by the service's own wall is the same `not_found` the index gives (§3: no row, no mailbox).
_CODE = {"unavailable": "mailer_down", "busy": "mailer_down", "not_live": "mailer_down", "forbidden": "not_found", "bad_request": "bad_query", "too_large": "bad_query"}
CODES = {"not_found", "below_level", "not_yours", "mailer_down", "moved", "bad_query", "on_behalf_of"}


def _coded(err: dict) -> dict:
    code = _CODE.get(err["code"], err["code"])
    if code not in CODES:
        code = "not_found"
    return {**err, "code": code, "retry": bool(err.get("retry")) or code == "mailer_down"}


def normalize(obj: Any) -> Any:
    """Every error is {ok: false, error: {code, message, retry}} with a code of the table; a service error nested in an answer (a message whose text did not
    come, under `unavailable`) is mapped the same way."""
    if isinstance(obj, dict):
        out = {}
        for k, v in obj.items():
            if k in ("error", "unavailable") and isinstance(v, dict) and "code" in v:
                out[k] = _coded(v)
            else:
                out[k] = normalize(v)
        return out
    if isinstance(obj, list):
        return [normalize(x) for x in obj]
    return obj


# ---- the fence -------------------------------------------------------------------------------------------------------------------------
# key -> the kind the block is marked with. Applied at any depth of an answer of a tool in FENCED.
COMMON = {"subject": "subject", "snippet": "snippet", "text": "text", "summary_line": "summary", "from_name": "name", "body": "text", "excerpt": "subject",
          "subject_excerpt": "subject", "purpose": "purpose", "href": "link", "attachment_names": "attachment"}
NAME_TOOLS = {"thread_read", "message_read", "message_headers", "find_attachments", "attachment_read", "review_get", "find_correspondents", "outbox_list"}
FENCED = {"mail_list", "mail_search", "find_threads", "thread_read", "message_read", "message_headers", "find_attachments", "attachment_read", "correspondence",
          "find_correspondents", "outbox_list", "review_queue", "review_get", "unscreened_mail", "records_search", "correspondence_index",
          "mailbox_trail", "review_trail", "member_activity", "record_history", "agent_activity", "activity_search", "recent_activity"}
_HOST = re.compile(r"^[a-z0-9]([a-z0-9._-]{0,251}[a-z0-9])?(:[0-9]{1,5})?$|^mail to [^\s]{1,200}$|^\(no host\)$")
HEADER_PLAIN = {"dmarc", "dmarc_result", "spam_score", "spam", "size"}


def _fence_value(kind: str, v: Any) -> Any:
    if isinstance(v, str):
        return fence_untrusted(kind, v) if v != "" else v
    if isinstance(v, list):
        return [_fence_value(kind, x) for x in v]
    return v


def _fence_headers(obj: Any, key: str = "") -> Any:
    """A header's value is whatever the sender wrote: fenced, except the verdicts our own filters wrote and the bare addresses (data, not prose)."""
    if isinstance(obj, dict):
        return {k: _fence_headers(v, k) for k, v in obj.items()}
    if isinstance(obj, list):
        return [_fence_headers(x, key) for x in obj]
    if isinstance(obj, str) and obj != "" and key.lower().replace("-", "_") not in HEADER_PLAIN | {"address", "host"}:
        return fence_untrusted("name" if key == "name" else "header", obj)
    return obj


def fence_tree(tool: str, obj: Any, parent: dict | None = None) -> Any:
    if isinstance(obj, list):
        return [fence_tree(tool, x, parent) for x in obj]
    if not isinstance(obj, dict):
        return obj
    out: dict = {}
    for k, v in obj.items():
        kind = COMMON.get(k)
        if kind is None and k == "name" and tool in NAME_TOOLS:
            kind = "name"
        if kind is None and k == "detail" and tool == "records_search" and obj.get("kind") == "correspondent":
            kind = "name"
        if kind is None and k == "label" and tool == "records_search" and obj.get("kind") == "conversation":
            kind = "subject"
        if tool == "message_headers" and k == "headers":
            out[k] = _fence_headers(v, k)
        elif kind is not None and isinstance(v, (str, list)) and (k != "text" or tool != "notice_status"):
            out[k] = _fence_value(kind, v) if not (isinstance(v, list) and any(isinstance(x, (dict, list)) for x in v)) else fence_tree(tool, v, obj)
        elif k == "host" and isinstance(v, str):
            out[k] = v if _HOST.match(v) else "(invalid host)"
        else:
            out[k] = fence_tree(tool, v, obj)
    if "hidden_removed" in out:
        out["hidden_content"] = bool(out["hidden_removed"])           # flagged, never shown: the extracted text has none of it
    return out


def finish(tool: str, res: Any) -> str:
    """One answer: error codes of the table, the fence, JSON."""
    res = normalize(res)
    if tool in FENCED:
        res = fence_tree(tool, res)
    return db.to_json(res)


# ---- the resolvers ---------------------------------------------------------------------------------------------------------------------
# tool -> (key of the list in the query's answer, id field, label field). The kernel's resolver reads a plain LIST of rows carrying them when it sends
# `q` alone; the exact name wins, and a row that is the same record in several mailboxes (a correspondent) is one row.
RESOLVERS = {"find_mailboxes": ("mailboxes", "mailbox_id", "address"), "find_folders": ("folders", "folder_id", "name"), "find_labels": ("labels", "label_id", "label"),
             "find_threads": ("threads", "thread_id", "subject"), "find_correspondents": ("correspondents", "address", "name"), "find_templates": ("templates", "template_id", "name"),
             "find_identities": ("identities", "identity_id", "address"), "find_members": ("members", "member_id", "display_name"),
             "find_departments": ("departments", "department_id", "name")}


def resolver_rows(tool: str, res: dict, q: str) -> Any:
    if not res.get("ok"):
        return res
    key, idf, lab = RESOLVERS[tool]
    rows, seen = [], set()
    for r in res.get(key) or []:
        if r.get(idf) in seen:
            continue
        seen.add(r.get(idf))
        rows.append(r)
    exact = [r for r in rows if q and str(r.get(lab) or "").strip().lower() == q.strip().lower()]
    if len(exact) == 1:
        rows = exact
    return rows


# ---- a message is named by its KEY id (mail_message_keys.id) ---------------------------------------------------------------------------
# The surface: "a message by its message key id (`message`) — stable across moves and UIDVALIDITY changes; the tools answer it as `message`", and the actions
# (mail_reply, mail_forward, mail_flag …) take that same key. The queries read the index row (mail_messages.id); these two functions are the one place the
# two meet: an incoming `message` is a key and becomes the newest index row of it, an outgoing one is made a key again. The wall decides what is visible.
MSG_IN = {"thread_read", "message_read", "message_headers", "attachment_read"}
MSG_OUT = {"mail_list", "mail_search", "thread_read", "message_read", "message_headers", "attachment_read", "find_attachments"}


async def key_to_row(pool, c: Caller, key, mailbox) -> int:
    """The index row a message key stands for in this mailbox (the newest copy); 0 for a key that is nowhere or hidden — the query then answers not_found."""
    import records_server as rs
    async with pool.acquire() as con, con.transaction():
        await rs._scoped(con, c)
        mid = await rs._mailbox_id(con, mailbox) if mailbox not in (None, "") else None
        row = await con.fetchval("SELECT id FROM mcp_messages WHERE message_key_id = $1 AND ($2::bigint IS NULL OR mailbox_id = $2) ORDER BY id DESC LIMIT 1", int(key), mid)
    return int(row) if row else 0


def _collect(obj: Any, out: set) -> None:
    if isinstance(obj, dict):
        for k, v in obj.items():
            if k == "message" and isinstance(v, int) and not isinstance(v, bool):
                out.add(v)
            elif k == "messages" and isinstance(v, list) and all(isinstance(x, int) for x in v):
                out.update(x for x in v if not isinstance(x, bool))
            else:
                _collect(v, out)
    elif isinstance(obj, list):
        for x in obj:
            _collect(x, out)


def _rekey(obj: Any, m: dict) -> Any:
    if isinstance(obj, dict):
        out = {}
        for k, v in obj.items():
            if k == "message" and isinstance(v, int) and not isinstance(v, bool):
                out[k] = m.get(v, v)
            elif k == "messages" and isinstance(v, list) and all(isinstance(x, int) for x in v):
                out[k] = [m.get(x, x) if not isinstance(x, bool) else x for x in v]
            else:
                out[k] = _rekey(v, m)
        return out
    if isinstance(obj, list):
        return [_rekey(x, m) for x in obj]
    return obj


async def rows_to_keys(pool, c: Caller, res: Any) -> Any:
    """Every index row id an answer names (`message`, `messages`) as the message key it stands for."""
    import records_server as rs
    ids: set = set()
    _collect(res, ids)
    if not ids:
        return res
    async with pool.acquire() as con, con.transaction():
        await rs._scoped(con, c)
        m = {r["id"]: r["message_key_id"] for r in await con.fetch("SELECT id, message_key_id FROM mcp_messages WHERE id = ANY($1::bigint[])", sorted(ids))}
    return _rekey(res, m)
