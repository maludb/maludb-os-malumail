# The estate, as a webmail sees it — survey of 2026-10-10

Read-only survey of the Business OS server (the kernel at `/var/www`, the applications under `/srv/apps/*`) for planning
`maludb-os-malumail`. Every fact came from files, `ss`, `getent`, `php -m` and `pip list`; nothing was written.

## 1. The shared-schema rule, and the canonical tables a webmail touches

The rule (`maludb-os-integration` `shared-schema.md`): every planned table is **read** (another application owns the data —
through K7, never copied), **reuse** (the canonical definition verbatim; append columns, widen an enumeration, add indexes
only) or **new** (it becomes canonical). Never `\i` or reference another application's database.

| Concept | Canonical owner and file | For the webmail |
|---|---|---|
| members, department members, `sso_nonces`, `member_sessions`, `directory_sync_state` | the plugin's `php-sign-on-kit.md`; the Data Lake's `db/001` (newest, with the mirror fix, appends a generated `is_agent`) | reuse — the mirror carries no agent's manager or principal (§3) |
| `activity_log`, `activity_ingest_state` | `memory.md` | reuse |
| `mcp_access_tokens` | HR `db/003` | reuse |
| **`mailboxes`, `inbound_email`, `outbound_email`** | **Help Desk `db/012_doors_mail_kb.sql`**; Sales CRM `db/011_email.sql` copies them, loosens Help Desk's ticket and queue links (kept, nullable, unused) and appends columns | `mailboxes` and `outbound_email` are the same concepts — reuse; `inbound_email` is a processing queue of captured mail, not a mirror of a mailbox |
| `email_templates` | Sales CRM `db/011` (markdown body, merge fields, `kind`, `is_shared`, `owner_member_id`) | reuse for canned replies |
| `contacts`, `contact_emails`, `accounts` | Sales CRM `db/007` — **owned data**, read through its K7 shares `contacts_index` / `accounts_index` | read, never copied |
| `attachments` | GL `db/009`; UUID applications append `record_uuid`, `width`, `height`, `thumbnail_path` (Spaces `db/012`) | reuse for compose uploads |
| `blobs`, `upload_sessions`, `upload_parts` | DocCloud `db/007` (content-addressed, `ref_count`, 8 MB parts) | not needed below a 10 MB message |
| `notifications`, `notification_prefs`, `notification_outbox` | GL `db/014`; Spaces `db/012` is the member-only variant | reuse (Spaces') |
| `agent_dispatches` | GL `db/014` = Consultant Tracking `db/013`; Spaces `db/012` adds `kind` | reuse (Spaces') |
| `search_index` | Spaces `db/013`; DocCloud widens `entity_kind` | see the design §6.7 |
| `tags`, `record_tags` | Help Desk `db/009` + DocCloud's appended `kind`, `colour`, `created_by`, `archived_at`; Sales CRM `db/006` `record_tags` | reuse for labels |
| `<prefix>_settings` | Help Desk `db/005` skeleton | reuse |
| `worker_passes` | Spaces `db/016` | reuse |

**The Help Desk DDL** (`/srv/apps/helpdesk/db/012_doors_mail_kb.sql`):

- `mailboxes(id bigint identity, address citext unique, display_name text not null, queue_id → queues, provider text default
  'malumail' check in ('malumail','imap'), webhook_secret_ref text, signature text, poll_enabled bool default true,
  last_polled_at, last_uid bigint, active bool default true, created_at, updated_at)`
- `inbound_email(id, mailbox_id → mailboxes, provider_uid, message_id, in_reply_to, references_ids text[], from_address citext,
  from_name, to_addresses text[], cc_addresses text[], subject, body_text, body_html, headers jsonb, received_at, status check
  in ('received','processed','ignored','failed','bounce','auto_reply','suppressed'), error, ticket_id, message_ref_id,
  processed_at, created_at)`; unique `(mailbox_id, provider_uid)`
- `outbound_email(id, ticket_id, message_ref_id, to_address citext not null, subject text not null, template, provider_message_id,
  status check in ('queued','sent','failed','suppressed'), error, sent_at, created_at)`

Sales CRM's appends: `mailboxes` + `last_webhook_at`, `last_error`, `last_error_at`; `outbound_email` + `sender_member_id`,
`contact_id`, `deal_id`, `cc_addresses`, `body_text`, `body_html`, `message_id`, `template_id`, `has_ics`, `is_private`.

## 2. Who reads MaluMail today, and how

| Reader | Credential | Arrival | Read state |
|---|---|---|---|
| **The kernel** (`seamus@`, an assistant's mailbox; `agent_channel_endpoints`, db/156) | the customer API key with Mailbox MCP, a tenant secret | its own mailbox webhook + a 60 s poll of unread INBOX | `read_message mark_seen:true`; **skips anything already `\Seen`** |
| **Help Desk** (`support@`) | `MALUMAIL_API_KEY` in its `config/.env` | its own mailbox webhook + a 60 s poll of unread since `last_uid` | reads unseen, marks seen after commit |
| **Sales CRM** (a BCC logging mailbox) | as Help Desk | webhook; the poll is a stub until its slice 5 | as Help Desk |
| everything else | `/v1/send` only (notifications) | — | — |

No application and not the kernel uses IMAP or SMTP directly. Help Desk's replies leave through `/v1/send` (mail01), so
they never reach `support@`'s Sent folder. The kernel creates an assistant's mailbox with a CLI script
(`bin/channel_endpoint_set.php`), which discards the password and re-sets (and rotates) the mailbox's webhook on every run.

**Three collisions a webmail must design around:**

1. **`\Seen` means "handled" to every reader.** A person opening a new message in `seamus@` or `support@` before the kernel
   or Help Desk does hides it from them for good.
2. **One webhook per mailbox.** MaluMail keeps one target per mailbox (and one per domain, overridden by the mailbox's own).
   A webmail cannot take the push for a mailbox the kernel, Help Desk or the CRM already holds.
3. **One API key reaches every mailbox of the customer.** Visibility between people and agents must be the application's.

## 3. Kernel facts

- Highest migration **db/180**; next **db/181**. Kernel items used through **K42**; next **K43**.
- Upstream numbering: MaluMail's items were M1–M4 (`docs/build-specs/assistants-and-messaging.md`), MaluDB's M1–M12 — the
  two collide. **MaluMail items take their own prefix here: `MM`.**
- `APPLICATION_CATEGORIES` and both CHECK constraints already admit **`email`** and `communication`. The existing `email`
  entries (Google Workspace, Microsoft 365) sit in the unnamed top business area; Spaces and Boards (`communication`) sit in
  Operations.
- Endpoint kinds: `mcp, http_api, database, filesystem, smtp, imap, ssh, ui, webhook`; `auth_kind`: `none, bearer, oauth,
  basic, api_key, os_credential, mtls` (no `token`).
- **Approvals**: a manifest's `approvals[]` becomes an `approval_policies` row for agents; the kernel's actions server asks
  `html/approvals/hook.php`, which answers 202 `pending_approval`; approval replays the call. The approver resolves to the
  nearest human up the agent's chain, else the department manager, else a super-admin — **but approvals are decided only on
  `os.`, which only super-admins reach.** Human writes are never paused. A reviewer of an agent's mail who is not a
  super-admin needs the application's own review queue.
- **The directory feed does not say who manages an agent** (`agent_profiles.manager_member_id` and `principal_member_id` stay
  in the kernel). "Who oversees this agent's mailbox" has no kernel door today.

## 4. Ports and names

- Taken or designed: application ports 8100–8110 and 8180–8198; MCP 8811–8851 (Boards' live socket 8851); scratch ranges
  8291–8297, 8301–8307, 8391–8397, 8401–8407, 8501–8507, 8601–8607, 8701–8707, 8801–8807, 8901–8907, 9001–9007, 9101–9107.
- **Free and proposed:** application **8199**, records MCP **8852**, activity MCP **8853**, the mail service **8854**,
  scratch **9201–9207**.
- DNS labels in use: hr, projects, helpdesk, spaces, gl, consulting, knowledge, inventory, processcore, doccloud, crm,
  shareholders, datalake, boards, cidery, reservations, appointments, txtschedules. `webmail` and `mail` are free;
  **`malumail.subello.com` already has an A record** (to the front proxy). The tenant's MX is `mbox01.malumail.com`.

## 5. The kit, and the server

- **The newest kit is the Data Lake's** (`app/`: activity, auth, bootstrap, db, directory with the mirror fix, handler, http,
  mail, partial_update, picker, pickers, switcher; `tests/phase0/run.sh` with a fake kernel, a fake MaluDB and a fake MaluMail).
  Its guard reads five refusal codes (`P0001`, `P0002`, `23514`, `23505`, `23503`); Inventory's adds `42501` → 403.
- **Sealed credentials** (Inventory `app/sources/credentials.php`): libsodium `secretbox` under a 64-hex key in
  `config/.env` generated by `ROOT_STEPS.sh`; `seal()` → `v1.<base64url nonce+box>`; one file decrypts;
  `source_credentials(kind, label, last4, ciphertext bytea, rotated_at)`.
- PHP 8.3.6 with `curl`, `dom`, `fileinfo`, `gd`, `iconv`, `mbstring`, `openssl`, **`sodium`**, `zip`, `sockets`, `pgsql`;
  **no `imap`, `mailparse`, `intl`**. Every application of ours is vanilla PHP (no composer).
- **No HTML sanitizer anywhere in the estate.** Help Desk stores HTML after `strip_tags` (attributes kept) and never renders
  it; DocCloud serves inline files under `Content-Security-Policy: sandbox; default-src 'none'`. Rendering mail is a first.
- Python 3.12.3 with `imaplib`, `email`, `smtplib`; the MCP venvs carry `mcp` 1.30, `asyncpg`, `httpx`, `starlette`,
  `uvicorn`, `cryptography` — no `imapclient`, `aioimaplib`, `nh3`, `bleach`, `lxml`, `websockets`, `pynacl`.
- Apache 2.4.58, `mpm_prefork` (150 workers) — long-held connections (SSE, long-poll) are expensive; `mod_headers` off
  (`ROOT_STEPS.sh` enables it, Sales CRM's lesson). PHP upload limits 25 MB / post 27 MB on Apache. 12 CPUs, 15 GB RAM.
- **Live updates in the estate are HTMX polling with a visibility guard** (Spaces: 3 s visible, 20 s hidden; the bell 60 s).
  Boards' WebSocket relay is the only socket, built for Boards.
