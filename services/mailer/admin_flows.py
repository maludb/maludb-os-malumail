"""Slice 1's administration flows (docs/build-specs/domains-mailboxes.md): what a Mail admin's New mailbox, Connect and Reset password
do, done here — not in PHP — because each holds a password for a moment and writes rows that must agree with MaluMail.

- **create** — MaluMail's `create_mailbox`, then in ONE transaction the mailbox row (`ml_mailbox_create()`), its overseers' `full` rows,
  and — for an agent's or a shared mailbox — its sealed credential (`ml_credential_store()`). A person's mailbox stores nothing (D3,
  until MM1): its new password is answered ONCE as `revealed`, to a person's call only.
- **connect** — an existing MaluMail mailbox on a served domain (or one already here: a RECONNECT gives it a new credential): its row, MaluMail's webhook record read to PROPOSE its intake reader,
  and for an agent's or a shared mailbox a password RESET at MaluMail (the application cannot learn an existing one), sealed and
  stored, then probed. A person's is registered without a credential ("waits on MM1").
- **reset** — what a reset reveals is decided HERE from the mailbox's kind in the database, never from the caller's word: a person's
  password once as `revealed`, nothing stored; an agent's or a shared mailbox's sealed and stored (the old credential revoked), never
  answered. So a compromised web server can reset a person's mailbox (logged, and the person's devices notice) but can never carry an
  agent's or a shared mailbox's credential away.
"""

from __future__ import annotations

import asyncio
import base64
import re
from urllib.parse import urlparse

from services.mailer import imap, mailbox_mcp, seal
from services.mailer.mailbox_mcp import McpError

KINDS = ("person", "agent", "shared")
_LOCAL = re.compile(r"[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?")
_ADDRESS = re.compile(r"([a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?)@([a-z0-9.-]+\.[a-z]{2,})")


def propose_intake(url: str | None) -> str | None:
    """Which estate reader a mailbox's own webhook points at (design §3, D13) — a proposal a Mail admin confirms."""
    if not url:
        return None
    u = urlparse(url)
    host = (u.hostname or "").lower()
    if "/channels/malumail" in u.path:
        return "kernel"
    if host.startswith("helpdesk."):
        return "helpdesk"
    if host.startswith("crm.") or host.startswith("sales."):
        return "sales_crm"
    return "other"


def _ids(value) -> list[int]:
    if value is None or value == "":
        return []
    items = value if isinstance(value, list) else str(value).split(",")
    out = []
    for x in items:
        x = str(x).strip()
        if x == "":
            continue
        if not x.isdigit():
            raise McpError("policy", "Overseers are named by their member ids.")
        out.append(int(x))
    return list(dict.fromkeys(out))


def _id(value) -> int | None:
    if value is None or str(value).strip() in ("", "0"):
        return None
    if not str(value).strip().isdigit():
        raise McpError("policy", "A member is named by their member id.")
    return int(str(value).strip())


class Flows:
    def __init__(self, cfg, store, http, public_key: bytes | None):
        self.cfg, self.store, self.http, self.public_key = cfg, store, http, public_key

    async def _admin(self, actor: int, tool: str, args: dict) -> dict:
        return await mailbox_mcp.admin_call(self.http, self.cfg.mcp_url, self.cfg.customer_key, self.public_key, tool, args,
                                            may=lambda right: self.store.member_may(actor, right), served=self.store.domain_served)

    def _box(self, reply: dict) -> bytes:
        return base64.b64decode(reply["credential"]["sealed"])

    def _reveal(self, box: bytes) -> str:
        return seal.open_sealed(self.cfg.seal_secret, box)

    async def _plan(self, actor: int, args: dict, connect: bool) -> dict:
        """The checked request, or a refusal in a sentence — every check made BEFORE MaluMail is asked anything."""
        if not await self.store.member_may(actor, "mailboxes.manage"):
            raise McpError("forbidden", "MaluMail's administration is a Mail admin's: a super-admin of the OS.")
        kind = str(args.get("kind") or "")
        if kind not in KINDS:
            raise McpError("policy", "A mailbox is a person's, an agent's or a shared one.")
        if connect:
            m = _ADDRESS.fullmatch(str(args.get("address") or "").strip().lower())
            if not m:
                raise McpError("policy", "Name the existing mailbox by its address.")
            local, domain = m.group(1), m.group(2)
        else:
            local = str(args.get("local_part") or "").strip().lower()
            domain = str(args.get("domain") or "").strip().lower()
            if not _LOCAL.fullmatch(local):
                raise McpError("policy", "A local part is letters, digits, dots, hyphens, plus signs or underscores — before the @.")
        if not domain or not await self.store.domain_served(domain):
            raise McpError("policy", f"MaluMail does not serve {domain or 'that domain'} here: a Mail admin adds it to the served domains first.")
        address = f"{local}@{domain}"
        if await self.store.mailbox_by_address(address) is not None:
            raise McpError("policy", f"{address} is already one of this business's mailboxes.")
        display = str(args.get("display_name") or "").strip() or local
        if len(display) > 120:
            raise McpError("policy", "A display name is up to 120 characters.")
        owner = _id(args.get("owner"))
        if kind == "shared":
            if owner is not None:
                raise McpError("policy", "A shared mailbox has no owner: its people are given rows on its Access page.")
        else:
            want = "human" if kind == "person" else "agent"
            if owner is None or await self.store.member_kind(owner) != want:
                raise McpError("policy", f"A {kind}'s mailbox belongs to {'a person' if kind == 'person' else 'an agent'}: choose one.")
        primary = _id(args.get("primary_overseer"))
        others = [o for o in _ids(args.get("overseers") if args.get("overseers") is not None else args.get("overseer")) if o != primary]
        if kind == "agent":
            if primary is None and others:
                primary, others = others[0], others[1:]
            if primary is None:
                raise McpError("policy", "An agent's mailbox needs a person who oversees it (the primary overseer).")
            for o in [primary, *others]:
                if await self.store.member_kind(o) != "human":
                    raise McpError("policy", "An overseer is a person.")
        elif primary is not None or others:
            raise McpError("policy", "Only an agent's mailbox has overseers.")
        quota = 2
        if not connect:
            try:
                quota = int(args.get("quota_gib") or 2)
            except (TypeError, ValueError):
                quota = 0
            if not 1 <= quota <= 50:
                raise McpError("policy", "A quota is 1 to 50 GiB.")
        return {"kind": kind, "local": local, "domain": domain, "address": address, "display": display, "owner": owner,
                "primary": primary, "others": others, "quota": quota}

    async def create(self, actor: int, args: dict) -> dict:
        p = await self._plan(actor, args, connect=False)
        reply = await self._admin(actor, "create_mailbox", {"domain": p["domain"], "local_part": p["local"], "quota_gib": p["quota"]})
        box = self._box(reply)
        try:
            mb = await self.store.create_mailbox_rows(actor, p["address"], p["display"], p["kind"], p["owner"], p["primary"], p["others"],
                                                      p["quota"] * 1073741824, None if p["kind"] == "person" else box, "created", None)
        except Exception as e:  # noqa: BLE001 — MaluMail has the mailbox; nothing here holds it, and no password goes anywhere
            raise McpError("created_at_malumail_only",
                           f"MaluMail created {p['address']}, but it could not be recorded here ({type(e).__name__}). Connect it from the "
                           "list of mailboxes: that resets its password and records it.") from None
        out = {"mailbox_id": mb, "address": p["address"], "kind": p["kind"]}
        if p["kind"] == "person" and await self.store.member_kind(actor) == "human":
            out["revealed"] = self._reveal(box)
        return out

    async def connect(self, actor: int, args: dict) -> dict:
        existing = await self.store.mailbox_by_address(str(args.get("address") or "").strip().lower())
        if existing is not None:
            return await self._reconnect(actor, existing, str(args.get("address")).strip().lower())
        p = await self._plan(actor, args, connect=True)
        listed = await self._admin(actor, "list_mailboxes", {"domain": p["domain"]})
        if not any(str(m.get("address", "")).lower() == p["address"] for m in listed.get("mailboxes", []) if isinstance(m, dict)):
            raise McpError("refused", f"MaluMail has no mailbox {p['address']} for this customer.")
        hook = await self._admin(actor, "get_mailbox_webhook", {"address": p["address"]})
        proposed = propose_intake(hook.get("url") if isinstance(hook, dict) else None)
        box = None
        if p["kind"] != "person":
            box = self._box(await self._admin(actor, "reset_mailbox_password", {"address": p["address"]}))
        mb = await self.store.create_mailbox_rows(actor, p["address"], p["display"], p["kind"], p["owner"], p["primary"], p["others"],
                                                  None, box, "connected", proposed)
        out = {"mailbox_id": mb, "address": p["address"], "kind": p["kind"], "intake_proposed": proposed, "probe": None}
        if box is not None and self.cfg.seal_secret is not None:
            password = self._reveal(box)
            outcome = await asyncio.to_thread(imap.probe, self.cfg.imap_host, self.cfg.imap_port, p["address"], password, self.cfg.tls_ca_file)
            del password
            row = await self.store.mailbox_credential(mb)
            if outcome.kind == "ok":
                await self.store.note_ok(mb, row["credential_id"])
            elif outcome.kind == "auth_failed":
                await self.store.trip_breaker(mb, row["credential_id"], p["address"], outcome.detail, actor)
            else:
                await self.store.note_error(mb, outcome.detail)
            out["probe"] = outcome.kind
        return out

    async def _reconnect(self, actor: int, row, address: str) -> dict:
        """A mailbox already recorded here, given a new credential: reset at MaluMail, sealed, stored (the old one revoked), probed."""
        if not await self.store.member_may(actor, "mailboxes.manage"):
            raise McpError("forbidden", "MaluMail's administration is a Mail admin's: a super-admin of the OS.")
        if row["kind"] == "person":
            raise McpError("policy", "A person's mailbox is read on their own devices until MaluMail issues application passwords (MM1).")
        box = self._box(await self._admin(actor, "reset_mailbox_password", {"address": address}))
        await self.store.credential_store(row["id"], actor, box, "reconnected")
        out = {"mailbox_id": row["id"], "address": address, "kind": row["kind"], "reconnected": True, "probe": None}
        if self.cfg.seal_secret is not None:
            password = self._reveal(box)
            outcome = await asyncio.to_thread(imap.probe, self.cfg.imap_host, self.cfg.imap_port, address, password, self.cfg.tls_ca_file)
            del password
            cred = await self.store.mailbox_credential(row["id"])
            if outcome.kind == "ok":
                await self.store.note_ok(row["id"], cred["credential_id"])
            elif outcome.kind == "auth_failed":
                await self.store.trip_breaker(row["id"], cred["credential_id"], address, outcome.detail, actor)
            else:
                await self.store.note_error(row["id"], outcome.detail)
            out["probe"] = outcome.kind
        return out

    async def after_reset(self, actor: int, address: str, reply: dict) -> dict:
        """The passthrough's sealed reply turned into what the mailbox's KIND allows (read here, never taken from the caller)."""
        row = await self.store.mailbox_by_address(address)
        if row is None:
            return reply                                  # not one of this business's mailboxes: the sealed box as before
        box = self._box(reply)
        if row["kind"] == "person":
            out = {"address": address, "kind": "person"}
            if await self.store.member_kind(actor) == "human":
                out["revealed"] = self._reveal(box)
            return out
        await self.store.credential_store(row["id"], actor, box, "reset")
        return {"address": address, "kind": row["kind"], "credential": {"stored": True, "last4": seal.last4(box)}}
