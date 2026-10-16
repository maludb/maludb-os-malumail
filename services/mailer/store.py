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

    async def member_kind(self, member_id: int) -> str | None:
        return await self.pool.fetchval("SELECT member_kind FROM members WHERE id = $1 AND status = 'active'", member_id)

    async def mailbox_by_address(self, address: str):
        return await self.pool.fetchrow("SELECT id, kind, owner_member_id FROM mailboxes WHERE address = $1", address.lower())

    async def create_mailbox_rows(self, actor: int, address: str, display: str, kind: str, owner: int | None, primary: int | None,
                                  others: list[int], quota_bytes: int | None, box: bytes | None, label: str, intake_proposed: str | None) -> int:
        """The mailbox, its overseers' full rows and (agent/shared) its sealed credential — ONE transaction, the database's own functions."""
        async with self.pool.acquire() as c, c.transaction():
            await c.execute("SELECT set_config('app.member_id', $1, true)", str(actor))
            mb = await c.fetchval("SELECT ml_mailbox_create($1, $2, $3, $4, $5, $6, $7)", actor, address, display, kind, owner,
                                  primary if kind == "agent" else None, quota_bytes)
            for o in others:
                await c.execute("SELECT ml_permission_grant($1, $2, 'member', $3, 'full')", mb, actor, o)
            if box is not None:
                await c.fetchval("SELECT ml_credential_store($1, $2, 'mailbox_password', $3, $4)", mb, actor, box, label)
            if intake_proposed is not None:
                await c.execute("UPDATE mailboxes SET intake_proposed = $2 WHERE id = $1", mb, intake_proposed)
            return int(mb)

    async def credential_store(self, mailbox_id: int, actor: int, box: bytes, label: str) -> int:
        async with self.pool.acquire() as c, c.transaction():
            await c.execute("SELECT set_config('app.member_id', $1, true)", str(actor))
            return int(await c.fetchval("SELECT ml_credential_store($1, $2, 'mailbox_password', $3, $4)", mailbox_id, actor, box, label))

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

    # ============================================================================================================================
    # SLICE 2 — the index writes (docs/build-specs/mail-service.md §3). The watcher threads call these through the event loop
    # (Sync.call). A first sync writes in batches with app.ml_bulk = 'on' (no threading per row; ml_threads_rebuild() after INBOX and
    # at the end); a live write threads by db/008's triggers and moves the change counter by db/013's.

    async def live_mailboxes(self) -> list:
        """The mailboxes the service keeps in step: active, an ok credential, not suspended, not paused or failed."""
        return await self.pool.fetch(
            """SELECT m.id, m.address::text AS address, m.kind, m.intake_owner, m.sync_state, c.id AS credential_id, c.ciphertext
                 FROM mailboxes m JOIN mailbox_credentials c ON c.id = m.credential_id AND c.status = 'ok'
                WHERE m.active AND m.suspended_at IS NULL AND m.sync_state NOT IN ('paused', 'failed')
                ORDER BY m.id""")

    async def credential_row(self, mailbox_id: int):
        return await self.pool.fetchrow(
            """SELECT m.id, m.address::text AS address, m.kind, m.sync_state, c.id AS credential_id, c.ciphertext, c.status
                 FROM mailboxes m LEFT JOIN mailbox_credentials c ON c.id = m.credential_id WHERE m.id = $1""", mailbox_id)

    async def folders(self, mailbox_id: int) -> list:
        return await self.pool.fetch(
            """SELECT id, name, delimiter, special_use, uidvalidity, uidnext, highestmodseq, messages_total, messages_unseen, synced_at
                 FROM mail_folders WHERE mailbox_id = $1 ORDER BY id""", mailbox_id)

    async def upsert_folders(self, mailbox_id: int, rows: list[dict]) -> None:
        """The server's folders: new ones made, a special-use flag kept up to date, folders the server no longer has deleted
        (their messages' rows with them; the keys stay — mail-service.md §11)."""
        names = [r["name"] for r in rows]
        async with self.pool.acquire() as c, c.transaction():
            for r in rows:
                await c.execute(
                    """INSERT INTO mail_folders (mailbox_id, name, delimiter, special_use, messages_total) VALUES ($1, $2, $3, $4, COALESCE($5, 0))
                       ON CONFLICT (mailbox_id, name) DO UPDATE SET delimiter = EXCLUDED.delimiter,
                           special_use = COALESCE(EXCLUDED.special_use, mail_folders.special_use),
                           messages_total = COALESCE($5, mail_folders.messages_total)
                        WHERE (mail_folders.delimiter, mail_folders.special_use, mail_folders.messages_total)
                              IS DISTINCT FROM (EXCLUDED.delimiter, COALESCE(EXCLUDED.special_use, mail_folders.special_use),
                                                COALESCE($5, mail_folders.messages_total))""",
                    mailbox_id, r["name"], r.get("delimiter"), r.get("special_use"), r.get("messages"))
            await c.execute("DELETE FROM mail_folders WHERE mailbox_id = $1 AND NOT (name = ANY ($2::text[]))", mailbox_id, names)
            # (a folder's messages go with it by the foreign key; their keys stay — mail-service.md §11)

    async def write_messages(self, mailbox_id: int, folder_id: int, uidvalidity: int, rows: list[dict], bulk: bool,
                             correspondents: bool) -> list[int]:
        """New messages (or a row the server shows again) in ONE transaction; answers the ids of rows that were new."""
        new_ids: list[int] = []
        async with self.pool.acquire() as c, c.transaction():
            if bulk:
                await c.execute("SELECT set_config('app.ml_bulk', 'on', true)")
            for r in rows:
                rid, inserted = await c.fetchrow(
                    """INSERT INTO mail_messages (folder_id, uidvalidity, uid, modseq, message_key_id, message_id_header, in_reply_to,
                           references_ids, from_address, from_name, sender_address, to_addresses, cc_addresses, bcc_addresses,
                           recipient_names, reply_to, subject, sent_at, received_at, size, seen, flagged, answered, draft, keywords, snippet,
                           has_attachments, attachment_names, dmarc_pass, spam_score, auto_submitted, is_list, return_path_null, local_submission)
                       VALUES ($1, $2, $3, $4, ml_message_key_for($5, $6, $7), $6, $8, $9, $10, $11, $12, $13, $14, $15, $16::jsonb, $17, $18, $19,
                               $20, $21, $22, $23, $24, $25, $26, $27, $28, $29, $30, $31, $32, $33, $34, $35)
                       ON CONFLICT (folder_id, uidvalidity, uid) DO UPDATE SET modseq = EXCLUDED.modseq, seen = EXCLUDED.seen,
                           flagged = EXCLUDED.flagged, answered = EXCLUDED.answered, draft = EXCLUDED.draft, keywords = EXCLUDED.keywords
                       RETURNING id, (xmax = 0)""",
                    folder_id, uidvalidity, r["uid"], r["modseq"], mailbox_id, r["message_id_header"], r["digest"], r["in_reply_to"],
                    r["references_ids"], r["from_address"], r["from_name"], r["sender_address"], r["to_addresses"], r["cc_addresses"],
                    r["bcc_addresses"], json.dumps(r["recipient_names"]), r["reply_to"], r["subject"], r["sent_at"], r["received_at"],
                    r["size"], r["seen"], r["flagged"], r["answered"], r["draft"], r["keywords"], r["snippet"], r["has_attachments"],
                    r["attachment_names"], r["dmarc_pass"], r["spam_score"], r["auto_submitted"], r["is_list"], r["return_path_null"],
                    r["local_submission"])
                if inserted:
                    new_ids.append(int(rid))
                    if correspondents and r["from_address"]:
                        await c.execute("SELECT ml_correspondent_received($1, $2, $3, $4)", mailbox_id, r["from_address"], r["from_name"],
                                        r["received_at"])
        return new_ids

    async def dispatch_decide(self, message_ids: list[int]) -> None:
        """A new INBOX message of an agent's mailbox, written live: the dispatch rule decides (db/010; slice 5's worker acts on it)."""
        for mid in message_ids:
            try:
                await self.pool.execute("SELECT ml_dispatch_decide($1)", mid)
            except asyncpg.PostgresError:
                pass                                       # the rule refuses some shapes in words; the index is already right

    async def update_flags(self, folder_id: int, uidvalidity: int, changed: dict[int, dict]) -> int:
        if not changed:
            return 0
        n = 0
        async with self.pool.acquire() as c, c.transaction():
            for uid, f in changed.items():
                res = await c.execute(
                    """UPDATE mail_messages SET seen = $4, flagged = $5, answered = $6, draft = $7, keywords = $8, modseq = $9
                        WHERE folder_id = $1 AND uidvalidity = $2 AND uid = $3
                          AND (seen, flagged, answered, draft, keywords, COALESCE(modseq, -1)) IS DISTINCT FROM ($4, $5, $6, $7, $8, COALESCE($9, -1))""",
                    folder_id, uidvalidity, uid, f["seen"], f["flagged"], f["answered"], f["draft"], f["keywords"], f.get("modseq"))
                n += int(res.split()[-1])
        return n

    async def vanish(self, folder_id: int, uidvalidity: int, uids: list[int]) -> int:
        if not uids:
            return 0
        res = await self.pool.execute("DELETE FROM mail_messages WHERE folder_id = $1 AND uidvalidity = $2 AND uid = ANY ($3::bigint[])",
                                      folder_id, uidvalidity, uids)
        return int(res.split()[-1])

    async def known_uids(self, folder_id: int, uidvalidity: int) -> set[int]:
        rows = await self.pool.fetch("SELECT uid FROM mail_messages WHERE folder_id = $1 AND uidvalidity = $2", folder_id, uidvalidity)
        return {int(r["uid"]) for r in rows}

    async def folder_reset(self, folder_id: int, uidvalidity: int) -> int:
        return int(await self.pool.fetchval("SELECT ml_folder_reset($1, $2)", folder_id, uidvalidity))

    async def folder_state(self, folder_id: int, uidvalidity: int, uidnext: int | None, highestmodseq: int | None,
                           total: int | None, unseen: int | None) -> None:
        # the counts only when they differ: db/013's trigger moves the change counter on any UPDATE OF them, and the counter must
        # move exactly when a list would change
        async with self.pool.acquire() as c, c.transaction():
            await c.execute("UPDATE mail_folders SET uidvalidity = $2, uidnext = $3, highestmodseq = $4, synced_at = now() WHERE id = $1",
                            folder_id, uidvalidity, uidnext, highestmodseq)
            await c.execute(
                """UPDATE mail_folders SET messages_total = COALESCE($2, messages_total), messages_unseen = COALESCE($3, messages_unseen)
                    WHERE id = $1 AND (messages_total, messages_unseen) IS DISTINCT FROM (COALESCE($2, messages_total), COALESCE($3, messages_unseen))""",
                folder_id, total, unseen)

    async def rebuild(self, mailbox_id: int) -> int:
        async with self.pool.acquire() as c, c.transaction():
            n = await c.fetchval("SELECT ml_threads_rebuild($1)", mailbox_id)
            await c.execute("SELECT set_config('app.ml_bulk', 'off', true)")
            await c.fetchval("SELECT ml_mailbox_touch($1, '{}'::bigint[])", mailbox_id)
        return int(n or 0)

    async def touch(self, mailbox_id: int) -> None:
        await self.pool.fetchval("SELECT ml_mailbox_touch($1, '{}'::bigint[])", mailbox_id)

    async def set_sync_state(self, mailbox_id: int, state: str, synced: bool = False) -> None:
        await self.pool.execute(
            """UPDATE mailboxes SET sync_state = $2, last_sync_at = CASE WHEN $3 THEN now() ELSE last_sync_at END
                WHERE id = $1 AND sync_state IS DISTINCT FROM 'failed'""", mailbox_id, state, synced)

    async def synced(self, mailbox_id: int) -> None:
        await self.pool.execute("UPDATE mailboxes SET last_sync_at = now() WHERE id = $1", mailbox_id)

    async def save_quota(self, mailbox_id: int, used: int | None, limit: int | None) -> None:
        if used is None and limit is None:
            return
        await self.pool.execute("UPDATE mailboxes SET quota_used_bytes = COALESCE($2, quota_used_bytes), quota_bytes = COALESCE(quota_bytes, $3) WHERE id = $1",
                                mailbox_id, used, limit)

    async def text_due(self, mailbox_id: int, limit: int) -> list:
        """Messages whose text is not yet indexed, newest first (db/008's partial index)."""
        return await self.pool.fetch(
            """SELECT m.id, m.uid, m.uidvalidity, f.id AS folder_id, f.name AS folder
                 FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
                WHERE m.mailbox_id = $1 AND m.text_indexed_at IS NULL AND m.uidvalidity = f.uidvalidity
                ORDER BY m.received_at DESC LIMIT $2""", mailbox_id, limit)

    async def text_due_mailboxes(self) -> list[int]:
        rows = await self.pool.fetch(
            "SELECT DISTINCT mailbox_id FROM mail_messages WHERE text_indexed_at IS NULL ORDER BY mailbox_id")
        return [int(r["mailbox_id"]) for r in rows]

    async def write_text(self, texts: dict[int, str | None]) -> None:
        async with self.pool.acquire() as c, c.transaction():
            for mid, text in texts.items():
                await c.execute("UPDATE mail_messages SET body_text = $2, text_indexed_at = now() WHERE id = $1", mid, text)

    async def level_as(self, mailbox_id: int, member: int, on_behalf: int | None) -> str:
        return str(await self.pool.fetchval("SELECT ml_level_as($1, $2, $3)", mailbox_id, member, on_behalf) or "none")

    async def status(self, mailbox_id: int) -> dict:
        b = await self.pool.fetchrow(
            """SELECT m.sync_state, m.last_sync_at, m.quota_bytes, m.quota_used_bytes, m.last_error, c.status AS credential
                 FROM mailboxes m LEFT JOIN mailbox_credentials c ON c.id = m.credential_id WHERE m.id = $1""", mailbox_id)
        if b is None:
            return {}
        folders = await self.pool.fetch(
            """SELECT f.id, f.name, f.special_use, f.messages_total, f.synced_at,
                      (SELECT count(*) FROM mail_messages m WHERE m.folder_id = f.id) AS indexed
                 FROM mail_folders f WHERE f.mailbox_id = $1 ORDER BY f.id""", mailbox_id)
        text = await self.pool.fetchrow(
            """SELECT count(*) FILTER (WHERE text_indexed_at IS NOT NULL) AS indexed, count(*) FILTER (WHERE text_indexed_at IS NULL) AS due
                 FROM mail_messages WHERE mailbox_id = $1""", mailbox_id)
        iso = lambda t: t.isoformat() if t is not None else None  # noqa: E731
        return {
            "sync_state": b["sync_state"], "last_sync_at": iso(b["last_sync_at"]), "credential": b["credential"] or "none",
            "folders": [{"folder_id": f["id"], "name": f["name"], "special_use": f["special_use"], "messages_total": f["messages_total"],
                         "indexed": int(f["indexed"]), "synced_at": iso(f["synced_at"])} for f in folders],
            "text": {"indexed": int(text["indexed"] or 0), "due": int(text["due"] or 0)},
            "quota": {"used_bytes": b["quota_used_bytes"], "limit_bytes": b["quota_bytes"]},
            "last_error": b["last_error"],
        }

    async def sync_counts(self) -> dict:
        row = await self.pool.fetchrow(
            """SELECT (SELECT count(*) FROM mail_messages WHERE text_indexed_at IS NULL) AS text_due,
                      (SELECT count(*) FROM mailboxes WHERE sync_state = 'failed') AS failing""")
        return dict(row)

    # ============================================================================================================================
    # SLICE 3 — reading and mutations (docs/build-specs/mail-service.md §4–§5). The door computes every level itself; the index is
    # updated in the same request as the IMAP command (decision 7).

    async def message_ref(self, mailbox_id: int, message_id: int):
        """A message row with its folder's name and the folder's CURRENT uidvalidity (a mismatch is `moved`)."""
        return await self.pool.fetchrow(
            """SELECT m.id, m.message_key_id, m.folder_id, m.uidvalidity, m.uid, m.thread_id, m.from_address::text AS from_address,
                      m.subject, f.name AS folder, f.special_use, f.uidvalidity AS folder_uidvalidity
                 FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
                WHERE m.mailbox_id = $1 AND m.id = $2""", mailbox_id, message_id)

    async def ref_by_parts(self, mailbox_id: int, folder_id: int, uidvalidity: int, uid: int):
        return await self.pool.fetchrow(
            """SELECT m.id, m.message_key_id, m.folder_id, m.uidvalidity, m.uid, m.thread_id, m.from_address::text AS from_address,
                      m.subject, f.name AS folder, f.special_use, f.uidvalidity AS folder_uidvalidity
                 FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
                WHERE m.mailbox_id = $1 AND m.folder_id = $2 AND m.uidvalidity = $3 AND m.uid = $4""", mailbox_id, folder_id, uidvalidity, uid)

    async def folder_rows_by_id(self, mailbox_id: int) -> dict:
        rows = await self.pool.fetch("SELECT id, name, special_use, uidvalidity FROM mail_folders WHERE mailbox_id = $1", mailbox_id)
        return {int(r["id"]): r for r in rows}

    async def mutation_refusal(self, mailbox_id: int, actor: int, on_behalf: int | None, folder_id: int | None, op: str) -> str | None:
        return await self.pool.fetchval("SELECT ml_mutation_refusal($1, $2, $3, $4, $5)", mailbox_id, actor, on_behalf, folder_id, op)

    async def read_mode(self, mailbox_id: int, actor: int, on_behalf: int | None) -> str:
        return str(await self.pool.fetchval("SELECT ml_read_mode($1, $2, $3)", mailbox_id, actor, on_behalf) or "none")

    async def rows_for_targets(self, mailbox_id: int, targets: list) -> list:
        """[[folder_id, uidvalidity, uid]] → the rows the index holds for them (a target the index no longer has is left out)."""
        out = []
        for t in targets:
            try:
                fid, v, uid = int(t[0]), int(t[1]), int(t[2])
            except (TypeError, ValueError, IndexError):
                continue
            r = await self.ref_by_parts(mailbox_id, fid, v, uid)
            if r is not None:
                out.append(r)
        return out

    async def rows_for_messages(self, mailbox_id: int, ids: list[int]) -> list:
        out = []
        for i in ids:
            r = await self.message_ref(mailbox_id, int(i))
            if r is not None:
                out.append(r)
        return out

    async def set_flag(self, ids: list[int], column: str, value: bool) -> int:
        if column not in ("seen", "flagged"):
            raise ValueError(column)
        res = await self.pool.execute(f"UPDATE mail_messages SET {column} = $2 WHERE id = ANY ($1::bigint[]) AND {column} IS DISTINCT FROM $2", ids, value)
        return int(res.split()[-1])

    async def messages_moved(self, moved: list[tuple[int, int, int, int]]) -> None:
        """[(message id, folder id, uidvalidity, new uid)] → ml_message_moved() each (ids and keys kept)."""
        async with self.pool.acquire() as c, c.transaction():
            for mid, fid, v, uid in moved:
                await c.execute("SELECT ml_message_moved($1, $2, $3, $4)", mid, fid, v, uid)

    async def delete_rows(self, ids: list[int]) -> int:
        res = await self.pool.execute("DELETE FROM mail_messages WHERE id = ANY ($1::bigint[])", ids)
        return int(res.split()[-1])

    async def folder_uids(self, folder_id: int) -> list:
        return await self.pool.fetch("SELECT id, uidvalidity, uid FROM mail_messages WHERE folder_id = $1 ORDER BY uid", folder_id)

    async def ensure_folder_row(self, mailbox_id: int, name: str, special_use: str | None, delimiter: str = ".") -> int:
        row = await self.pool.fetchrow(
            """INSERT INTO mail_folders (mailbox_id, name, delimiter, special_use) VALUES ($1, $2, $4, $3)
               ON CONFLICT (mailbox_id, name) DO UPDATE SET special_use = COALESCE(mail_folders.special_use, EXCLUDED.special_use)
               RETURNING id""", mailbox_id, name, special_use, delimiter)
        return int(row["id"])

    async def folder_removed(self, mailbox_id: int, folder_id: int) -> None:
        """The folder the server no longer has (it was empty): its row goes."""
        await self.pool.execute("DELETE FROM mail_folders WHERE id = $1 AND mailbox_id = $2", folder_id, mailbox_id)

    async def folder_renamed(self, mailbox_id: int, folder_id: int, old: str, new: str, delimiter: str) -> None:
        """A renamed folder and the folders under it: their rows follow the server's names (ids, messages and uidvalidity kept)."""
        async with self.pool.acquire() as c, c.transaction():
            await c.execute("UPDATE mail_folders SET name = $3 || substr(name, length($2) + 1) WHERE mailbox_id = $1 AND (name = $2 OR name LIKE $4)",
                            mailbox_id, old, new, old.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + delimiter + "%")

    async def set_folder_uidvalidity(self, folder_id: int, uidvalidity: int) -> None:
        await self.pool.execute("UPDATE mail_folders SET uidvalidity = $2 WHERE id = $1 AND uidvalidity IS NULL", folder_id, uidvalidity)

    async def mailbox_identities(self, mailbox_id: int) -> set[str]:
        rows = await self.pool.fetch(
            """SELECT lower(address::text) AS a FROM mailboxes WHERE id = $1
               UNION SELECT lower(address::text) FROM mail_identities WHERE mailbox_id = $1""", mailbox_id)
        return {r["a"] for r in rows}

    # ============================================================================================================================
    # SLICE 4 — the outbox loop and the drafts (docs/build-specs/sending-and-oversight.md §5, mail-service.md §6).

    async def claim(self, limit: int = 10) -> list[int]:
        rows = await self.pool.fetch("SELECT ml_outbound_claim($1) AS id", limit)
        return [int(r["id"]) for r in rows]

    async def outbound(self, outbound_id: int):
        return await self.pool.fetchrow(
            """SELECT o.*, b.address::text AS mailbox_address, b.display_name AS mailbox_display, b.kind AS mailbox_kind, b.owner_member_id,
                      i.address::text AS identity_address, i.display_name AS identity_display, i.reply_to::text AS identity_reply_to,
                      i.signature_html, i.signature_text,
                      sm.display_name AS sender_name, sm.member_kind AS sender_kind, sm.email::text AS sender_email,
                      (SELECT s.business_name FROM ml_settings s WHERE s.id = 1) AS business_name,
                      (SELECT COALESCE(b2.address::text, NULL) FROM mailboxes b2 WHERE b2.kind = 'person' AND b2.owner_member_id = o.on_behalf_member_id LIMIT 1) AS delegate_mailbox
                 FROM outbound_email o JOIN mailboxes b ON b.id = o.mailbox_id
                 LEFT JOIN mail_identities i ON i.id = o.identity_id
                 LEFT JOIN members sm ON sm.id = o.sender_member_id
                WHERE o.id = $1""", outbound_id)

    async def attachments_of(self, record_type: str, record_id: int) -> list:
        return [dict(r) for r in await self.pool.fetch(
            "SELECT id, filename, mime_type, byte_size, sha256, storage_path FROM attachments WHERE record_type = $1 AND record_id = $2 ORDER BY id",
            record_type, record_id)]

    async def outbound_sent(self, outbound_id: int, queue_id: str | None, reply: str | None) -> None:
        await self.pool.execute("SELECT ml_outbound_sent($1, $2, $3)", outbound_id, queue_id, reply)

    async def outbound_deferred(self, outbound_id: int, reply: str | None) -> None:
        await self.pool.execute("SELECT ml_outbound_deferred($1, $2)", outbound_id, reply)

    async def outbound_failed(self, outbound_id: int, error: str) -> None:
        await self.pool.execute("SELECT ml_outbound_failed($1, $2)", outbound_id, error)

    async def outbound_lost(self, outbound_id: int) -> None:
        await self.pool.execute("SELECT ml_outbound_lost($1)", outbound_id)

    async def outbound_filed(self, outbound_id: int) -> None:
        await self.pool.execute("UPDATE outbound_email SET filed_at = now() WHERE id = $1", outbound_id)

    async def outbound_note(self, outbound_id: int, text: str) -> None:
        await self.pool.execute("UPDATE outbound_email SET error = $2 WHERE id = $1", outbound_id, text[:1000])

    async def stalled(self, minutes: int = 10) -> list[int]:
        return [int(r["id"]) for r in await self.pool.fetch("SELECT ml_outbound_stalled($1) AS id", minutes)]

    async def unfiled(self, limit: int = 20) -> list[int]:
        return [int(r["id"]) for r in await self.pool.fetch(
            "SELECT id FROM outbound_email WHERE status = 'sent' AND filed_at IS NULL AND sent_at > now() - interval '1 day' AND COALESCE(error, '') <> 'not filed in Sent' ORDER BY sent_at LIMIT $1", limit)]

    async def due_count(self) -> int:
        return int(await self.pool.fetchval("SELECT count(*) FROM outbound_email WHERE status IN ('queued', 'deferred') AND send_after <= now() AND (retry_at IS NULL OR retry_at <= now())") or 0)

    async def next_due(self):
        return await self.pool.fetchval("SELECT min(GREATEST(send_after, COALESCE(retry_at, send_after))) FROM outbound_email WHERE status IN ('queued', 'deferred')")

    async def message_row_for_key(self, key_id: int):
        return await self.pool.fetchrow(
            """SELECT m.id, m.uidvalidity, m.uid, f.name AS folder FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
                WHERE m.message_key_id = $1 ORDER BY (f.special_use = 'inbox') DESC, m.id LIMIT 1""", key_id)

    async def notify_send_failed(self, outbound_id: int, who: int | None, title: str, body: str, mailbox_id: int) -> None:
        if who is None:
            return
        await self.pool.execute("SELECT ml_notify($1, 'send_failed', $2, $3, $4, 'outbound', $5)", who, title, body, mailbox_id, outbound_id)

    async def primary_overseer(self, mailbox_id: int) -> int | None:
        return await self.pool.fetchval("SELECT ml_mailbox_acting_person($1)", mailbox_id)

    # drafts
    async def draft(self, draft_id: int):
        return await self.pool.fetchrow(
            """SELECT d.*, b.address::text AS mailbox_address, b.display_name AS mailbox_display,
                      i.address::text AS identity_address, i.display_name AS identity_display, i.reply_to::text AS identity_reply_to
                 FROM mail_drafts d JOIN mailboxes b ON b.id = d.mailbox_id LEFT JOIN mail_identities i ON i.id = d.identity_id
                WHERE d.id = $1""", draft_id)

    async def draft_filed(self, draft_id: int, folder_id: int, uidvalidity: int, uid: int) -> None:
        await self.pool.execute("UPDATE mail_drafts SET folder_id = $2, uidvalidity = $3, uid = $4, filed_at = now() WHERE id = $1",
                                draft_id, folder_id, uidvalidity, uid)

    async def draft_unfiled(self, draft_id: int, discarded: bool) -> None:
        await self.pool.execute("UPDATE mail_drafts SET folder_id = NULL, uidvalidity = NULL, uid = NULL" + (", discarded_at = COALESCE(discarded_at, now())" if discarded else "") + " WHERE id = $1", draft_id)

    async def drafts_to_file(self, limit: int = 20) -> list[int]:
        """Drafts saved after they were last filed (or never), not discarded, not sent."""
        return [int(r["id"]) for r in await self.pool.fetch(
            """SELECT id FROM mail_drafts WHERE discarded_at IS NULL AND outbound_id IS NULL AND (filed_at IS NULL OR filed_at < saved_at)
                  AND saved_at < now() - interval '2 seconds' ORDER BY saved_at LIMIT $1""", limit)]

    async def drafts_to_discard(self, limit: int = 20) -> list[int]:
        return [int(r["id"]) for r in await self.pool.fetch(
            "SELECT id FROM mail_drafts WHERE discarded_at IS NOT NULL AND uid IS NOT NULL ORDER BY discarded_at LIMIT $1", limit)]

    async def folder_named(self, mailbox_id: int, special: str):
        return await self.pool.fetchrow("SELECT id, name, uidvalidity FROM mail_folders WHERE mailbox_id = $1 AND special_use = $2", mailbox_id, special)

    async def alias_delivers(self, address: str, mailbox_address: str, aliases: list[dict]) -> bool:
        for a in aliases:
            if str(a.get("address", "")).lower() == address.lower():
                return mailbox_address.lower() in [str(x).lower() for x in (a.get("forwards_to") or a.get("destinations") or [])]
        return False

    async def set_identity_checked(self, mailbox_id: int, address: str, destinations: list[str] | None = None) -> None:
        """An alias list_aliases showed delivering here: the identity's checked_at, and the alias cache row (ml_aliases — MaluMail is the
        record, the cache is what the screens and the identities' trigger read) refreshed from the same answer."""
        if destinations:
            await self.pool.execute(
                """INSERT INTO ml_aliases (domain_id, address, destinations, synced_at)
                   SELECT d.id, $1::text, $2::citext[], now() FROM ml_domains d WHERE d.domain = split_part($1::text, '@', 2) AND d.served
                   ON CONFLICT (address) DO UPDATE SET destinations = EXCLUDED.destinations, synced_at = now(), active = true""",
                address.lower(), [str(x).lower() for x in destinations])
        await self.pool.execute("UPDATE mail_identities SET checked_at = now() WHERE mailbox_id = $1 AND address = $2", mailbox_id, address)
