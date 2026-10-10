# MaluMail, as a webmail sees it — survey of 2026-10-10

Read-only survey of MaluMail's mailbox platform for planning `maludb-os-malumail`. Its sources: the infrastructure side's
webmail handoff (2026-10-10, a Claude Docs page owned by the owner), the MaluMail web application's code and SQL, the
mailbox server's Dovecot and Postfix configuration (read-only), a pre-auth `CAPABILITY`/`EHLO` probe from the Business OS
server, and the kernel's own MaluMail client. Nothing was written anywhere; no login was attempted.

> This repository is public. Credentials, their locations, internal addressing, the privileged access path MaluMail's own
> web application uses, and abuse-protection thresholds are deliberately left out. They are in the owner's handoff.

## 1. The hosts, in one paragraph

Mailbox hosting is **mbox01** (`mbox01.malumail.com`): Postfix 3.8.6, Dovecot 2.3.21 with Pigeonhole 0.5.21, Rspamd 3.8.1.
The portal (`app.malumail.com`), the API (`api.malumail.com`, REST + MCP) and the PostgreSQL database that holds every
domain, mailbox and alias (schema `mbox`) live on **webapp01**. The transactional relay **mail01** is a separate machine with
its own reputation: **webmail mail never leaves through it** (nor through `POST /v1/send`, which uses it). Admin features are
row changes in `mbox`, read live by Postfix and Dovecot; nothing reloads.

## 2. The Mailbox MCP — `POST https://api.malumail.com/mcp`

- MCP over Streamable HTTP, stateless, JSON only (no SSE, no session; GET answers 405). Methods `initialize`, `ping`,
  `tools/list`, `tools/call`. **JSON-RPC batches are accepted.** Server `malumail-mailbox` 1.2.0.
- Auth: `Authorization: Bearer mm_<48 hex>`, a portal API key with **Mailbox MCP** switched on. **The key reaches every
  domain and mailbox of its customer** — it is not scoped to a domain or a mailbox. Every call is written to
  `mbox.mcp_audit_log` (tool, target, ok, error — never content).
- Errors: a tool refusal is `isError: true` with a sentence; an SMTP refusal, including the `451 4.7.1` rate limit, arrives
  as text only (no structured code). 401 for a bad key, 403 for a key without Mailbox MCP.
- Every content call opens its own IMAP session on MaluMail's side, within a web request's time and size limits.

### 2.1 Content tools

| Tool | Arguments | What it does and returns | Limits that matter |
|---|---|---|---|
| `list_messages` | `address`, `folder` (INBOX), `unread_only`, `since_uid`, `limit` 1–50 | newest by **UID**; `{uid, from, to, subject, date, message_id, in_reply_to, seen, flagged, size}` | **no paging older than the newest 50**; no cc, snippet, `\Answered`, keywords, UIDVALIDITY |
| `read_message` | `address`, `folder`, `uid`, `mark_seen` | headers (from, to, cc, reply_to, subject, date, message_id, in_reply_to, references), `flags[]`, `text` (first text/plain, else HTML stripped), `attachments[{name,type,size}]` | **no HTML, no raw .eml, no attachment content**, no Content-ID; text capped at 100,000 characters |
| `search_messages` | `address`, `folder`, `query`, `field` text/from/to/subject/body, `limit` 1–50 | `UID SEARCH` on one field | no dates, flags, combined criteria, Message-ID lookup or paging; body search scans the Maildir |
| `send_message` | `address`, `to[]` ≤20, `cc[]`, `subject`, `text`, `from_name`, `in_reply_to`, `references[]` | submits through mbox01, DKIM-signed by Rspamd, then `APPEND` to `Sent` | **plain text only; no HTML, attachments, Bcc, Reply-To; From is always the mailbox itself**; `\Answered` not set |
| `move_message` | `address`, `folder`, `uid`, `to_folder` | `UID MOVE`, one message | the target folder must exist |
| `mark_message` | `address`, `folder`, `uid`, `seen`, `flagged` | one message | no other flags |

**Absent altogether:** folders (list, create, rename, delete, counts), copy, delete/expunge, drafts, `\Answered`/`\Draft`/
keywords, quota usage, THREAD/SORT, any changes-since mechanism, IDLE, HTML, raw, attachments, bulk operations.

### 2.2 Admin tools

| Tool | Arguments | Notes |
|---|---|---|
| `list_hosted_domains` | — | domain, receiving, SPF/DKIM/DMARC verified, counts |
| `list_mailboxes` | `domain` | address, active, quota (GiB), created — **no usage figure** |
| `create_mailbox` | `domain`, `local_part`, `quota_gib` 1–50 (2) | refuses an address that exists as a mailbox **or** an alias (platform-wide); **the password is random, 24 characters, returned once**; the caller cannot choose it |
| `reset_mailbox_password` | `address` | a new random password, once |
| `set_mailbox_quota` | `address`, `quota_gib` | |
| `set_mailbox_active` | `address`, `active` | inactive: mail rejected, logins refused, Maildir kept |
| `list_aliases` / `create_alias` / `update_alias` / `delete_alias` | `domain` / `local_part`, `forwards_to[]` ≤20 / `address` | destinations internal or **external**, stored comma-separated; an alias's `active` is not settable here |
| `set_mailbox_webhook` / `get_mailbox_webhook` / `delete_mailbox_webhook` | `address` XOR `domain`, `url`, `active`, `rotate_secret` | **one webhook per mailbox and one per domain, one URL each; the mailbox's own wins over the domain's**; secret returned on create/rotate |

**There is no `delete_mailbox`** (deleting the row would orphan the Maildir; the infrastructure side wants hard delete to be
an operator job) and **no display name, signature or per-mailbox setting** anywhere.

**Webhook payload:** `{type:"message.new", event_id, address, folder, uid, uid_validity, message_id, from, subject, date,
received_at}`, headers `X-MaluMail-Event`, `X-MaluMail-Delivery`, `X-MaluMail-Signature: t=<unix>,v1=HMAC-SHA256(secret,
"t.body")`; retried 30 s … 8 h, then failed. **Events come only from delivery** (Sieve filing into Junk included) — never for
an APPEND, a move, a flag change or an expunge. The URL must be https on a public address.

## 3. The REST API on api.malumail.com

`POST /v1/send` (through mail01, a verified sending domain, text and/or HTML, `reply_to`, `in_reply_to`, `references`, 429
on the customer's limit, no Sent copy), `GET|POST|DELETE /v1/suppressions`, `POST /mcp`. **No `/v1/mbox/...` route exists.**
The webmail must not send through `/v1/send`; the OS's own notification mail may (the installer's `mail` step, unchanged).

## 4. The `mbox` schema (from the repository's SQL and documents)

| Table | Key columns |
|---|---|
| `hosted_domain` | `id uuid`, `customer_id`, `domain` unique, `active`, `spf_verified`, `dkim_verified`, `dmarc_verified` |
| `mailbox` | `id uuid`, `domain_id`, `local_part`, `address` unique, `password_hash` (bcrypt), `quota_bytes` (2 GiB), `active` |
| `alias` | `id uuid`, `domain_id`, `source` unique, `destination` (comma-separated), `active` |
| `dkim_key` | `domain_id`, `selector`, keys, `active` (synced to Rspamd every 2 minutes) |
| `mailbox_webhook` | `customer_id`, `domain_id` XOR `mailbox_id`, `url`, `secret_enc`, `active`; unique per domain and per mailbox |
| `mailbox_event` | `address`, `folder`, `uid`, `uid_validity`, `message_id`, `from_address`, `subject`, `status`, retries; `NOTIFY mailbox_event` on insert |
| `mcp_audit_log` | `customer_id`, `api_key_id`, `tool`, `target`, `ok`, `error` |

Invariants the platform enforces and the webmail must respect: **an address is a mailbox or an alias, never both** (so
"forward my mailbox and keep a copy" is Sieve's `redirect :copy`, not an alias); a mailbox's domain must be a hosted domain
of the acting customer; changes take effect at commit (DKIM up to 2 minutes later); **suspend (`active = false`) rather than
delete**; mail is irreplaceable (hourly off-site backups).

## 5. Dovecot on mbox01 — what an IMAP client gets

- Login is the **full address** with the mailbox's own bcrypt password; `active = false` refuses at once. One credential
  per mailbox today. A second SQL `passdb` over a new table (an **application password**, bound to the OS server's address
  with `allow_nets`) can be added beside it without changing how the existing one works — the shape the infrastructure side
  would review (§7 option 3).
- **Post-auth capabilities** (compiled into the server binary; the pre-auth banner shows only `IDLE`, `ENABLE`, `ID`,
  `LITERAL+`, `SASL-IR`): `SORT SORT=DISPLAY THREAD=REFERENCES THREAD=REFS THREAD=ORDEREDSUBJECT MULTIAPPEND URL-PARTIAL
  CATENATE UNSELECT CHILDREN NAMESPACE UIDPLUS LIST-EXTENDED I18NLEVEL=1 CONDSTORE QRESYNC ESEARCH ESORT SEARCHRES WITHIN
  CONTEXT=SEARCH LIST-STATUS BINARY MOVE SNIPPET=FUZZY PREVIEW=FUZZY PREVIEW STATUS=SIZE SAVEDATE`; QUOTA (`imap_quota`
  loaded), SPECIAL-USE (configured) and NOTIFY (`mailbox_list_index = yes`) inferred from the configuration.
- **Absent:** METADATA (`imap_metadata = no`), COMPRESS, full-text search (fts plugins installed, not loaded), ACLs and
  shared folders (installed, not loaded), IMAPSieve (installed, not loaded), **ManageSieve** (not a protocol; nothing on 4190).
- Maildir++ with `.` as the separator; special-use folders auto-created and subscribed: `INBOX`, `Drafts`, `Sent`, `Junk`,
  `Trash`, and `Sent Messages` (a second `\Sent` for Apple clients). Resolve by `LIST (SPECIAL-USE)`, never by name.
- Quota per mailbox from `quota_bytes` (default 2 GiB); `GETQUOTAROOT INBOX` gives the usage bar; a full mailbox rejects mail.
- Keywords are capped at 50 characters, and Maildir keeps **at most 26 keywords per folder** — so Gmail-style labels cannot
  be IMAP keywords.
- Spam: Rspamd scores; a global Sieve script files flagged mail into Junk. Headers available: `X-Spam-Status`,
  `X-Spam-Level`, `X-Spamd-Result`, `X-Spam`. No `Authentication-Results` header is added. Greylisting delays a first-time
  sender about 5 minutes.
- A mailbox accepts at most 10 concurrent IMAP connections from one address.

## 6. Submission (587 STARTTLS, 465 TLS)

`EHLO`: `SIZE 10240000` (about 9.8 MiB a message — roughly 7 MB of attachments after base64), `AUTH PLAIN LOGIN`,
`PIPELINING`, `8BITMIME`, `DSN`, `SMTPUTF8`, `CHUNKING`, `ENHANCEDSTATUSCODES`. Rspamd DKIM-signs with the From domain's key.
**Rate limit: 500 messages an hour per authenticated mailbox**; over it the server answers `451 4.7.1 Ratelimit exceeded`
(a soft error to show plainly). The sending address is cold: bulk and AI auto-send must be throttled.
Submission does not save to Sent — the client `APPEND`s its own copy.

## 7. What the kernel already does with MaluMail

- `malumail_mcp()` (`/var/www/app/features/channels/channels.php`): one `tools/call` per request, one account API key for
  every mailbox (a tenant secret). It uses `list_mailboxes`, `create_mailbox` (the password discarded),
  `set_mailbox_webhook` (mailbox-scoped, to `/channels/malumail/mail`, secret rotated on every run), `read_message`
  (`mark_seen: true`), `mark_message`, `list_messages` (unread, INBOX), `send_message`.
- Intake (`email_inbound()`): a webhook for each new INBOX message plus a poll every 60 s of unread INBOX mail. **It skips a
  message whose flags already contain `\Seen`** ("already read"), and marks everything it handles `\Seen`. It never moves
  or deletes.
- **Two consequences for a webmail on the same mailbox:** (1) if a person opens a message in an agent's mailbox before the
  kernel does, the agent never receives it; (2) the kernel holds the mailbox's one webhook slot, so the webmail cannot get
  its own webhook for that mailbox (a domain webhook is overridden by the mailbox's own).

## 8. Gaps — what a Gmail-style webmail needs that the MCP and REST cannot do

Folders and counts; paging and date sort; a change feed (MODSEQ/QRESYNC) and push for flags, moves and appends; more than
one webhook per mailbox; HTML bodies, raw .eml and full headers; attachments and `cid:` images; HTML/attachment/Bcc/Reply-To
sending and send-as-alias; drafts; `\Answered`, `\Deleted`, delete/expunge, bulk operations; search by date, flag, header,
combined, paged; threads; quota usage; display names, signatures, vacation and filters (no ManageSieve); keep-a-copy
forwarding; a key scoped to some domains; a structured rate-limit error; coordination of `\Seen` with the kernel's intake.

## 9. How an application on another server can reach mailbox content

| # | Route | What MaluMail would build | What the OS gets | Security |
|---|---|---|---|---|
| 1 | **Extend the MCP** | tools for every gap in §8, through its own privileged path | the same thin client the kernel has | the OS holds one customer key (revocable, audited per call); a leak reads and sends as every mailbox of the customer, from anywhere; JSON-wrapped binaries; a fresh IMAP login per call |
| 2 | **A REST webmail API** `/v1/mbox/...` | new routes over the same helpers, binary streams for attachments and .eml, ideally domain-scoped keys | a better fit for binaries and caching | as 1 |
| 3 | **Per-mailbox application passwords**, bound to the OS server's address | a table, a second Dovecot SQL passdb with `allow_nets`, MCP tools to issue (once) and revoke, the OS's address exempted from login-failure bans | **full IMAP** (CONDSTORE/QRESYNC, SORT/THREAD, IDLE/NOTIFY, BINARY, APPEND, MOVE) and **SMTP with any MIME** up to the size limit | one secret per mailbox, usable only from the OS server, revocable without touching the person's own password, still under the 500/h limit; the OS keeps N sealed secrets |
| 4 | **Keep each mailbox's own password** | nothing | full IMAP/SMTP | weakest: the person's primary credential, usable anywhere; a reset breaks their phone; no separate trail |
| 5 | **A customer-scoped privileged login** bound to the OS server's address | a scoped master passdb (a query that must be reviewed carefully) | full IMAP/SMTP for every mailbox of the customer with one credential | a compromise opens every mailbox of the customer; a wrong scoping query widens it to the platform |
