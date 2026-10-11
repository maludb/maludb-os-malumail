# MaluMail — CLAUDE.md

This repository is the **MaluMail** webmail application of the MaluDB Business OS (`github.com/maludb/maludb-os-malumail`,
**public** — the owner, 2026-10-10; local clone and install path `/srv/apps/malumail`; catalog key `malumail`; DNS label
**`webmail`** — the owner, 2026-10-10). The kernel is `/var/www` (`maludb-os-core`); read its CLAUDE.md first — the kernel owns
identity, the directory, the agents, approvals and the token ledger. This application owns the business's **mail as people and
agents work it**: the mailboxes it serves at MaluMail, an index of their folders, messages and conversations (MaluMail's mail
server stays the record), who may open which mailbox, the outbox, the agents' mail policies and the people's reviews of what
agents send, and the administration of MaluMail mailboxes and forwarding addresses through MaluMail's API. The owner's brief
(2026-10-10): *a Gmail-type webmail for MaluMail inside the OS, where both AI agents and humans access email; humans oversee the
agents' email; super-admins create mailboxes and forwarding accounts through the MaluMail API.*

## Read first
1. `docs/malumail-design.md` — the plan (Phase 0): where it departs from MaluMail's handoff and why (§0.1), what MaluMail, the
   estate and the field give (§0), what it is and is not (§1), the actors (§2), **who sees whose mail** (§3), the doors (§4),
   **the agents' mail and its oversight** (§5), the memory model (§6 — the mail service, mailboxes and credentials, the index and
   the outbox, reading safely, the tables against the estate), the questions with every tool named (§7), the kernel's part and the
   doors (§8), the screens (§9), the build order and **the mail spike** (§10), Extended (§11), what is owed — K43–K45 and MaluMail's
   MM1–MM9 (§12), the decisions D1–D16 (§13), ports and files (§14), the owner's answers (§15), the state (§16).
2. `docs/research/` — the reports behind §0.
3. The plugins, **read from the local clones** (Claude Code's registered builder plugin is the stale 0.4.1): `maludb-os-integration`
   (`~/maludb-os-integration`, skill `os-integration` — `shared-schema.md`, `agents.md`, `sms-and-reads.md` above all) and
   `htmx-php-builder` 0.9.0 (`~/htmx-php-builder`: `new-app`, `os-application`, `php-patterns`, `design-system` with the record
   picker and the switcher, `mcp-servers`, `chat-actions`).
4. The nearest exemplars: `/srv/apps/helpdesk` (a MaluMail mailbox as a door; `mailboxes`, `outbound_email`), `/srv/apps/spaces`
   (permission rows, agents answering through the chat endpoint, dispatches, notifications), `/srv/apps/inventory` (sealed
   credentials, the worker's backoff, the loopback bridge), `/srv/apps/doccloud` (sandboxed file responses), `/srv/apps/datalake`
   (the newest kit; a Python service as the only door to its store).

## Rules that bind here
- `htmx-php-builder` governs the PHP; `os-integration` governs the fit, and wins where they disagree: **no login form, no account
  of our own, no model key and no model call, no shared tables, no actions server of our own, no writes to the directory, no
  connection to another application's database or the kernel's.**
- **MaluMail's mail server is the record; this application keeps an index and its own state.** The index is rebuildable from the
  server at any time. The application's own state (labels, snoozes, mutes, take-overs, viewer reads) keys on `Message-ID` and the
  mailbox, **never on an IMAP UID**, and every cache key includes UIDVALIDITY.
- **Two planes.** Administration (domains, mailboxes, forwarding addresses, quotas, resets) goes through MaluMail's **Mailbox MCP**
  with the tenant's customer key, limited to the served domains. Mail content goes through **IMAP and SMTP in the mail service**
  (`services/mailer/`) alone. Never `/v1/send` or mail01 for a person's or an agent's mail — that is the OS's notification channel.
- **Only the mail service holds secrets.** Credentials are sealed boxes PHP can write but never open; the sealing secret key and
  MaluMail's customer key live in `/etc/malumail/mailer.env`, readable only by the `malumail-mailer` user; every Mailbox MCP
  call goes through the mail service's door. One authentication failure stops that credential until a person looks (repeated
  failures trip the mail server's abuse protection for the whole server). Never run login tests with a wrong password against
  MaluMail. Never return a password in JSON mode or under an action token.
- **Never test against a real customer's mailbox** — the test mailbox the handoff names, synthetic fixtures, nothing of a real person.
- **Who sees whose mail is SQL** (`ml_mailbox_level()`): a role never opens a person's mailbox; a grant on it by anyone but its
  owner is break-glass; department rows admit people, never agents; `view` never changes a mailbox and every fetch is a `PEEK`;
  every view by anyone but the owner (lists, conversations, files, MCP and K7 reads) is in the mailbox's access log; an intake
  mailbox (the kernel's, Help Desk's, the CRM's) is never marked `\Seen` and its INBOX is never moved by this application.
- **Agents' mail is governed, and agents never decide**: every agent `message_send` passes `ml_outbound_hold_reasons()`; held mail
  waits for people (the agent mailbox's overseers, or the person whose mailbox an agent wrote from); review decisions, releases,
  pauses, policies, grants and all administration are refused to agents in SQL and the manifest; arriving mail reaches an agent
  only as fenced extracted text by id in one chat turn, only if DMARC-authenticated and admitted by its policy (internal-looking
  mail counts only if this application sent it, until MM3). Never loosen a default to make a test pass.
- **HTML mail is hostile**: sanitised by allow-list in the mail service, served on its own sandboxed response, framed without
  scripts, remote images only on request through the image door.
- **The activity log records what people and agents do, never what mail says** (subject excerpts only for agent and shared
  mailboxes; nothing of a person's mailbox's content in the tenant's memory).
- **This repository is public.** No credential, credential location, internal address, privileged account name or detail of
  MaluMail's abuse protection is ever written here; those stay in the owner's handoff.
- Checkpoint gate: no feature PHP until the owner approves the schema, the MCP tool surface and the action manifest together.
- Commit each finished step on `main` in the repository's message style; never push unless the owner says so.
- The user runs every `sudo` command; ask for them — except `sudo -n -u postgres` for **scratch `ml_*` databases and the three
  `malumail_*` roles** (the proofs), as Boards has it; used 2026-10-11 for K43 and the kit's proof under the owner's "go ahead with
  K43 and the kit" — **the owner to confirm it as standing**. Changes on MaluMail's production hosts are the infrastructure side's
  — write the request (§12's MM rows), never make them.

## The kit (Phase 0, built 2026-10-11) and its proof
The kit is Boards' Phase 0 kit (itself the Data Lake's) with its later fixes, renamed `bd_` → `ml_` and fitted: `db/001`–`005`
(identity mirror, activity log with `mailbox_id`, tokens, **two roles with `ml_rights.held_by`** — `people` for oversight,
`super_admins` for every admin right (D2) —, `ml_settings` from design §6.5), `app/` (sign-on, the mirror, the gates —
`require_admin()` and `require_right()` say *why* through `ml_right_refusal()` —, handlers, the activity funnel, notification mail
only), `bin/` (the directory sync, the worker with MaluMail's eight passes as stubs naming their slice, the registry builder for
`docs/malumail-action-manifest.md`), `html/` (`/sso`, `/sso/logout`, `/logout.php`, health, the Phase 0 home), `mcp/` (the read
servers' base and the ingest bridge), `tests/`. **PHP never calls the Mailbox MCP, takes no webhook, and holds neither the
customer key nor the sealing secret** — the proof checks it.
- `bash tests/setup_dev.sh` — the scratch database `ml_dev0` (`ML_MIGRATIONS_UPTO=005` for the kit alone) and its environment in
  `/tmp/ml-dev0.env` (+ the mail service's stand-in `/tmp/ml-dev0-mailer.env` with a scratch sealing pair).
- `ML_MIGRATIONS_UPTO=005 bash tests/phase0/run.sh` — the kit without a kernel: **100 checks** (2026-10-11), ports 9201–9203.
- The schema proof (`db/proof/phase0_proof.sql`, `tests/phase0/schema.sh`) comes with db/006+ after the mail spike.

## Numbers
Ports `APP_INTERNAL_PORT=8199`, `MCP_RECORDS_PORT=8852`, `MCP_ACTIVITY_PORT=8853`, `ML_MAILER_PORT=8854`, `ML_IMAGES_PORT=8855`;
scratch 9201 (the app), 9202 (the fake kernel), 9203 (the fake MaluDB + MaluMail REST), 9204–9205 (the MCP servers), 9206 (the
mail service), 9207 (the image door); prefix `ml_`; scratch databases `ml_*`. Kernel items K43 (the catalog row, db/181 — **built and applied 2026-10-11**), K44 (the overseer link in the feed), K45 (the
kernel's intake independent of `\Seen`), K46 (the kernel's agent sends through this application's outbox). MaluMail items take the prefix **MM** (MaluDB's and MaluMail's old M numbers collide).
