"""MaluMail's Mailbox MCP, the administration plane (design §6.1 point 5; research 01 §2): `POST <MALUMAIL_MCP_URL>`, JSON-RPC
`tools/call`, the customer key as the bearer, one call per request — the kernel's `malumail_mcp()` in Python. Only this service
holds the customer key; PHP asks through the door.

The policy is here, not in the caller:
- **Admin tools only**, each behind the right a Mail admin holds for it. The content tools (list/read/search/send/move/mark) are
  refused: a mailbox's mail goes through IMAP and SMTP. Webhooks are never set or deleted: a mailbox's one webhook belongs to
  whichever reader takes its mail (the kernel's intake, Help Desk, the CRM).
- **Served domains only**: the customer key reaches every domain of the MaluMail customer, so the served-domain list (`ml_domains`)
  is the application's wall. Listing the customer's hosted domains is the one call that names others (to choose what to serve).
- **A password never leaves this service.** `create_mailbox` and `reset_mailbox_password` answer a password once; it is sealed here
  under the service's own public key and only the sealed box is returned, for the caller to store. Their reply is cut to named
  fields, and a reply whose password this service cannot find is refused — it never passes through under a name not foreseen.
  Every other reply loses any `password` or `secret` field before it is returned.
"""

from __future__ import annotations

import base64
import json
from dataclasses import dataclass
from typing import Awaitable, Callable

import httpx

from services.mailer import seal as sealing


@dataclass(frozen=True)
class Tool:
    right: str
    target: str | None          # the argument naming the domain or address the call concerns (None: the customer's domain list)
    args: frozenset
    seals: bool = False


TOOLS: dict[str, Tool] = {
    "list_hosted_domains": Tool("domains.manage", None, frozenset()),
    "list_mailboxes": Tool("mailboxes.manage", "domain", frozenset({"domain"})),
    "create_mailbox": Tool("mailboxes.manage", "domain", frozenset({"domain", "local_part", "quota_gib"}), seals=True),
    "reset_mailbox_password": Tool("mailboxes.manage", "address", frozenset({"address"}), seals=True),
    "set_mailbox_quota": Tool("mailboxes.manage", "address", frozenset({"address", "quota_gib"})),
    "set_mailbox_active": Tool("mailboxes.manage", "address", frozenset({"address", "active"})),
    "get_mailbox_webhook": Tool("mailboxes.manage", "address", frozenset({"address"})),
    "list_aliases": Tool("aliases.manage", "domain", frozenset({"domain"})),
    "create_alias": Tool("aliases.manage", "domain", frozenset({"domain", "local_part", "forwards_to"})),
    "update_alias": Tool("aliases.manage", "address", frozenset({"address", "forwards_to"})),
    "delete_alias": Tool("aliases.manage", "address", frozenset({"address"})),
}
REFUSED = {
    **{t: "A mailbox's mail goes through IMAP and SMTP here, never the Mailbox MCP's content tools." for t in
       ("list_messages", "read_message", "search_messages", "send_message", "move_message", "mark_message")},
    **{t: "MaluMail's webmail sets no webhook: a mailbox's one webhook belongs to whichever reader takes its mail." for t in
       ("set_mailbox_webhook", "delete_mailbox_webhook")},
}
SEALED_REPLY_FIELDS = ("address", "domain", "local_part", "quota_gib", "active", "created", "created_at")


class McpError(Exception):
    """kind: policy (this service will not ask) · forbidden (the member may not) · unconfigured (no key here) · refused (MaluMail
    said no, in a sentence) · key (MaluMail refused the customer key) · unreachable · protocol (an answer not as promised)."""

    def __init__(self, kind: str, message: str):
        super().__init__(message)
        self.kind = kind


def domain_of(tool: Tool, args: dict) -> str | None:
    if tool.target is None:
        return None
    value = str(args.get(tool.target) or "").strip().lower()
    if tool.target == "address":
        return value.rsplit("@", 1)[1] if "@" in value else ""
    return value


def _strip_secrets(value):
    if isinstance(value, dict):
        return {k: _strip_secrets(v) for k, v in value.items() if k.lower() not in ("password", "secret")}
    if isinstance(value, list):
        return [_strip_secrets(v) for v in value]
    return value


async def call_raw(client: httpx.AsyncClient, url: str, key: str, tool: str, args: dict, timeout: float = 30.0) -> dict:
    payload = {"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": tool, "arguments": args}}
    try:
        r = await client.post(url, json=payload, timeout=timeout, headers={
            "Authorization": f"Bearer {key}", "Accept": "application/json, text/event-stream"})
    except httpx.HTTPError as e:
        raise McpError("unreachable", f"MaluMail did not answer {tool} ({type(e).__name__}).") from None
    if r.status_code in (401, 403):
        raise McpError("key", "MaluMail refused the customer key (HTTP %d): a Mail admin checks the key's Mailbox MCP switch." % r.status_code)
    if r.status_code != 200:
        raise McpError("unreachable", f"MaluMail did not answer {tool} (HTTP {r.status_code}).")
    try:
        body = r.json()
    except ValueError:
        raise McpError("protocol", f"MaluMail's answer to {tool} was not JSON.") from None
    if "error" in body:
        raise McpError("refused", f"MaluMail refused {tool}: {str((body['error'] or {}).get('message', 'no reason'))[:300]}")
    result = body.get("result") or {}
    text = str(((result.get("content") or [{}])[0] or {}).get("text", ""))
    if result.get("isError"):
        raise McpError("refused", text[:300] or f"MaluMail refused {tool}.")
    try:
        out = json.loads(text) if text else {}
    except ValueError:
        raise McpError("protocol", f"MaluMail's answer to {tool} was not the JSON it promises.") from None
    return out if isinstance(out, dict) else {"items": out}


async def admin_call(client: httpx.AsyncClient, url: str, key: str | None, public_key: bytes | None, tool_name: str, args: dict,
                     may: Callable[[str], Awaitable[bool]], served: Callable[[str], Awaitable[bool]]) -> dict:
    """One administration call on behalf of a member: `may(right)` and `served(domain)` are the database's answers for them."""
    if tool_name in REFUSED:
        raise McpError("policy", REFUSED[tool_name])
    tool = TOOLS.get(tool_name)
    if tool is None:
        raise McpError("policy", "That is not something MaluMail's administration does here.")
    if not isinstance(args, dict) or not set(args) <= tool.args:
        raise McpError("policy", f"{tool_name} takes {', '.join(sorted(tool.args)) or 'no arguments'}.")
    if not await may(tool.right):
        raise McpError("forbidden", "MaluMail's administration is a Mail admin's: a super-admin of the OS.")
    domain = domain_of(tool, args)
    if domain is not None and (not domain or not await served(domain)):
        raise McpError("policy", f"MaluMail does not serve {domain or 'that domain'} here: a Mail admin adds it to the served domains first.")
    if key is None:
        raise McpError("unconfigured", "The mail service holds no MaluMail customer key (MALUMAIL_MAILBOX_API_KEY in /etc/malumail/mailer.env).")
    if tool.seals and public_key is None:
        raise McpError("unconfigured", "The mail service holds no sealing key, so it cannot keep a new password: nothing was asked of MaluMail.")
    out = await call_raw(client, url, key, tool_name, args)
    if not tool.seals:
        return _strip_secrets(out)
    password = out.get("password")
    if not isinstance(password, str) or not password:
        raise McpError("protocol", f"MaluMail answered {tool_name} with no password this service recognises, so nothing was kept or shown. "
                                   "A Mail admin resets the mailbox's password to get a credential.")
    box = sealing.seal(public_key, password)
    del password, out["password"]
    reply = {k: out[k] for k in SEALED_REPLY_FIELDS if k in out}
    reply["credential"] = {"kind": "mailbox_password", "sealed": base64.b64encode(box).decode(), "last4": sealing.last4(box)}
    return reply
