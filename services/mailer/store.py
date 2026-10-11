"""The mail service's database calls (as malumail_rw). Rights and levels are READ here, for the member a signed call names — never
taken from the caller. The circuit breaker's state lives in mailbox_credentials (db/006), so every screen and every process sees it,
and a failed credential is never used again: db/006's guard refuses to bring it back; a person stores a new one."""

from __future__ import annotations

import json

import asyncpg


class Store:
    def __init__(self, pool: asyncpg.Pool):
        self.pool = pool

    @staticmethod
    async def open(dsn: dict) -> "Store":
        return Store(await asyncpg.create_pool(min_size=1, max_size=4, command_timeout=30, **dsn))

    async def close(self) -> None:
        await self.pool.close()

    async def ping(self) -> bool:
        try:
            return await self.pool.fetchval("SELECT 1") == 1
        except (asyncpg.PostgresError, OSError):
            return False

    async def active_member(self, member_id: int) -> bool:
        return bool(await self.pool.fetchval("SELECT EXISTS (SELECT 1 FROM members WHERE id = $1 AND status = 'active')", member_id))

    async def member_may(self, member_id: int, right: str) -> bool:
        return bool(await self.pool.fetchval("SELECT ml_member_has_right($1, $2)", member_id, right))

    async def domain_served(self, domain: str) -> bool:
        return bool(await self.pool.fetchval("SELECT EXISTS (SELECT 1 FROM ml_domains WHERE domain = $1 AND served)", domain))

    async def mailbox_credential(self, mailbox_id: int):
        """The mailbox and its live credential (None when it has none, or it failed or was revoked)."""
        return await self.pool.fetchrow(
            """SELECT m.id, m.address::text AS address, m.active, c.id AS credential_id, c.ciphertext, c.status AS credential_status
                 FROM mailboxes m LEFT JOIN mailbox_credentials c ON c.id = m.credential_id
                WHERE m.id = $1""", mailbox_id)

    async def note_ok(self, mailbox_id: int, credential_id: int) -> None:
        async with self.pool.acquire() as c, c.transaction():
            await c.execute("UPDATE mailbox_credentials SET last_ok_at = now() WHERE id = $1 AND status = 'ok'", credential_id)
            await c.execute("UPDATE mailboxes SET last_error = NULL, last_error_at = NULL WHERE id = $1 AND last_error IS NOT NULL", mailbox_id)

    async def note_error(self, mailbox_id: int, text: str) -> None:
        await self.pool.execute("UPDATE mailboxes SET last_error = $2, last_error_at = now() WHERE id = $1", mailbox_id, text[:500])

    async def trip_breaker(self, mailbox_id: int, credential_id: int, address: str, detail: str, actor: int | None) -> int:
        """One authentication failure: the credential is failed, the mailbox's sync stops, every Mail admin is told. Answers how many
        were told. Idempotent: a credential already failed changes nothing and tells no one again."""
        async with self.pool.acquire() as c, c.transaction():
            tripped = await c.fetchval(
                "UPDATE mailbox_credentials SET status = 'failed', failed_at = now(), failure = $2 WHERE id = $1 AND status = 'ok' RETURNING id",
                credential_id, detail[:500])
            if tripped is None:
                return 0
            await c.execute("UPDATE mailboxes SET sync_state = 'failed', last_error = $2, last_error_at = now() WHERE id = $1",
                            mailbox_id, ("credential refused: " + detail)[:500])
            await c.execute(
                """INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, mailbox_id, after)
                   VALUES ($1, 'system', 'mailbox_credential.failed', 'mailbox_credential', $2, $3, $4::jsonb)""",
                actor, credential_id, mailbox_id, json.dumps({"by": "mail service", "circuit_breaker": True}))
            told = await c.fetchval(
                """SELECT count(ml_notify(m.id, 'credential_failed', $1, $2, $3, 'mailbox', $3))
                     FROM members m WHERE m.member_kind = 'human' AND m.status = 'active' AND ml_member_has_right(m.id, 'settings.manage')""",
                f"MaluMail stopped using {address}'s credential",
                "The mail server refused it once, so it is never tried again (a second refusal could lock this server out of every "
                "mailbox). Store a new credential for the mailbox to start again.", mailbox_id)
            return int(told or 0)

    async def log_admin_call(self, actor: int, tool: str, target: str | None, ok: bool, error: str | None) -> None:
        await self.pool.execute(
            """INSERT INTO activity_log (actor_member_id, source, action, entity_type, after)
               VALUES ($1, 'application', $2, 'malumail_admin', $3::jsonb)""",
            actor, f"mailer.admin.{tool}", json.dumps({"target": target, "ok": ok, "error": error}))

    async def health_counts(self) -> dict:
        row = await self.pool.fetchrow(
            """SELECT (SELECT count(*) FROM mailboxes m JOIN mailbox_credentials c ON c.id = m.credential_id
                        WHERE m.active AND c.status = 'ok') AS syncable,
                      (SELECT count(*) FROM mailbox_credentials WHERE status = 'failed') AS failed,
                      (SELECT count(*) FROM outbound_email WHERE status = 'queued' AND send_after <= now()) AS outbox_due""")
        return dict(row)
