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
- **Agents' mail is governed, and agents never decide**: every agent `mail_send` (and reply, forward) passes `ml_outbound_hold_reasons()`; held mail
  waits for people (the agent mailbox's overseers, or the person whose mailbox an agent wrote from); review decisions, releases,
  pauses, policies, grants and all administration are refused to agents in SQL and the manifest; arriving mail reaches an agent
  only as fenced extracted text by id in one chat turn, only if DMARC-authenticated and admitted by its policy (internal-looking
  mail counts only if this application sent it, until MM3). Never loosen a default to make a test pass.
- **HTML mail is hostile**: sanitised by allow-list in the mail service (`services/mailer/sanitise.py`), served on its own
  sandboxed response, framed without scripts, remote images only on request through the image door. Never widen an allow-list
  (a tag, an attribute, a CSS property, a URL scheme) without re-running `tests/sanitiser/run.sh`, and never make it pass by
  editing the corpus.
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
- `bash tests/phase0/run.sh` — the kit without a kernel, the schema proof included: **101 checks** (2026-10-11), ports 9201–9203
  (`ML_MIGRATIONS_UPTO=005` for the kit alone: 100).
- `bash tests/phase0/schema.sh` — the schema proof alone on a fresh `ml_proof`: `db/proof/phase0_proof.sql`, **232 checks**
  (2026-10-11), and since Phase 1 the four `db/proof/phase1_*.sql` after it (514 in all). A check that ACTS and then READS splits into two statements: a stable function sees the statement's snapshot.

## The schema (Phase 0, 2026-10-11) — design §16 "Phase 0 continued" for the decisions
- `db/006` domains, `ml_aliases` (the internal test's cache), mailboxes, sealed credentials, permissions, identities, agent
  policies, notice acknowledgements — and THE WALL: `ml_mailbox_level(mailbox, member)`; `ml_level_as(…, on_behalf)` for the
  NAMED Expert in a person's run (`app.on_behalf_of`, set by the records MCP from K38); `ml_my_mailbox_ids(min)` once per statement.
- `db/007` the reused tables; `db/008` the index (message keys, conversations, states on message keys, `ml_search`); `db/009` drafts,
  the outbox's status machine, `ml_outbound_hold_reasons`, the refusals, `ml_review_decide`; `db/010` people's decisions and
  `ml_dispatch_decide`; `db/011` the `mcp_*` views, the access log, `ml_correspondence_index` (K7).
- **Action names begin with `mail_`** — the kernel's Actions MCP has one flat namespace (K55): `message_send`, `folder_delete`,
  `mailbox_create`, `settings_save` are other tools there.
- `maludb-os.json`, `os/`, `skills/`, `deploy/` (Phase 0's packaging): `php /var/www/bin/app_install.php plan /srv/apps/malumail`
  reads clean (35 steps, 7 notes).

## The sanitiser (2026-10-11) — `research/04` §A, design §16
- `services/mailer/sanitise.py` is the mail service's first module and design §6.4's first layer: nh3 0.3.7 (pinned in
  `mcp/requirements.txt`) with our attribute filter, our pass, then nh3 again until the output is a fixed point. It returns the
  HTML plus what it removed: hidden elements, remote images (their URLs in order), pixels and Content-IDs.
- `tests/sanitiser/run.sh` runs **50 checks** in about five minutes with no network and no database. It needs `mcp/venv` built
  from `mcp/requirements.txt`, plus the kernel's Playwright.
- The attack corpus is DOMPurify 3.4.16's, the owner's choice. It is copied unchanged with its licence and `SOURCE.md`, read as
  data and never executed. To update it, replace both files from a newer release at least two weeks old.

## The mail service (Phase 0's skeleton, 2026-10-11) — design §16
- `services/mailer/` is the ONLY holder of open mailbox credentials, the sealing secret key and MaluMail's customer key. Its parts:
  `server.py` (Starlette on uvicorn, loopback), `door.py` (the signed header, nonce once, 60 s), `mailbox_mcp.py` (the admin
  allow-list, the served-domain wall, passwords sealed on arrival and never returned, fail closed), `imap.py` (the TLS probe;
  what counts as `auth_failed`), `store.py` (rights read for the member a call names, never taken from the caller; the breaker in
  `mailbox_credentials`), `seal.py` and `config.py` (`ML_DEV_ENV` for the proofs; `ML_TLS_CA_FILE` refused in production).
- `bash tests/mailer/run.sh` runs **62 checks** in about 15 s on scratch `ml_mailer`, against `tests/fixtures/fake_mailbox_mcp.py`
  and `tests/fixtures/imap_server.py`; the service listens on 9206 and a second instance on 9207. **Never point a proof at
  MaluMail's real server with a wrong password**: the fixture exists so the breaker can be proven without that.

## Phase 1 (2026-10-11, APPROVED by the owner as written) — `docs/phase1-consistency.md`
- `docs/malumail-mcp-tool-surface.md` (36 + 7 tools), `docs/malumail-action-manifest.md` (45 screens, 67 actions) →
  `php bin/build_action_registry.php` (`--check` in every proof); three build specs and eleven slice specs in `docs/build-specs/`.
- `bash tests/phase1/check_specs.sh` — the documents agree (98 checks). `bash tests/phase0/schema.sh` — db/001–015 and every proof
  (514 checks).
- **No plain-value parameter may carry a resolver's name** (`mailbox`, `folder`, `to_folder`, `label`, `thread`, `correspondent`,
  `template`, `identity`, `member`, `person`, `agent`, `owner`, `overseer`, `primary_overseer`, `department`): the kernel's actions server
  resolves them registry-wide.

## Phase 2 (2026-10-12, the shell BUILT) — `docs/build-specs/sso-shell.md`
- `bash tests/phase2/run.sh` (php -S) and `ML_APP=apache bash tests/phase2/run.sh` (a user-level Apache rendering the deploy vhost):
  scratch `ml_dev2`, four seeded mailboxes (`priya@`, `dana@`, `sales@` shared at view with Dana, `expert@` overseen by Priya), the
  notice acknowledged by all but Dana; `shell.php` 79, `vhost.php` 43/47, `browser.mjs` 28 (Playwright from `/var/www/web/node_modules`).
- **A screen no slice has built is answered by `html/placeholder.php`** (the router's and the vhost's fallback): a slice that builds a
  screen adds its file and the placeholder stops answering it. Its gate is `screen_gate()` in `app/features/shell/nav.php` — keep the
  real screen's gate the same.
- **The notice gate** is `notice_gate()` (end of `app/bootstrap.php`); a new always-reachable page joins `notice_exempt()`.
- A directory's index is a rewrite in the vhost, never mod_dir's (its internal redirect meets the placeholder fallback).

## Slice 1 (2026-10-12, domains, mailboxes and forwarding BUILT — THE CRUD EXEMPLAR) — `docs/build-specs/domains-mailboxes.md`
- `bash tests/phase3/slice1/run.sh` (php -S) and `ML_APP=apache bash tests/phase3/slice1/run.sh`: scratch `ml_dev1`, Phase 2's servers with
  the IMAP fixture on (`ML_WITH_IMAP=1`); `admin.php` 66, `browser.mjs` 28. Copy its shape for every later slice: `app/features/<f>/` one
  file of helpers and queries, `app/views/<f>/`, `html/<f>/` controllers (`index.php`, `form.php` for /new, `view.php` for /{id}) and one
  handler per action (`ml_handler_begin()`, the right, `require_human()` on a people row, the database's function in `ml_guard()` or the
  mail service through `admin_mailer()`, `log_activity()`, `ml_done()`).
- **A password is the mail service's**: create/connect/reset go through `services/mailer/admin_flows.py`; PHP holds only a PERSON's new
  password, for the one redirect that shows it (`admin_reveal_once()`/`admin_take_revealed()`), never in JSON.
- The IMAP fixture accepts what the fake Mailbox MCP issued (`--passwords`): a reset changes what logs in, as at MaluMail.

## Slice 2 (2026-10-12, the mail service and the list BUILT — THE FIRST EXEMPLAR) — `docs/build-specs/mail-list.md`, `mail-service.md` §§2–3, 8–9
- `bash tests/phase3/slice2/run.sh` (php -S) and `ML_APP=apache …`: scratch `ml_dev2`, Phase 2's servers with the IMAP fixture seeded
  (`ML_IMAP_USERS`, one mailbox of 5,000 messages); `sync.py` 72, `list.php` 65, `browser.mjs` 45, `live.py` (MaluMail's test mailboxes
  ONLY with `ML_LIVE_CREDENTIALS` naming the owner's handoff file in your own environment — never in the repo; one submission per run).
  `ML_S2_HOLD=1` leaves the seeded servers up for development.
- **Every list reads ONE gatherer**: `ml_mail_list()` behind `mailbox-view`, `folder-view` and the `mail_list` tool
  (`app/features/mail/list.php`, `mcp/records_server.py`'s queries). The rail is `mail_rail()` over `ml_unread_counts()`; levels for many
  mailboxes come from `ml_my_levels()` (db/017) — never `mcp_mailboxes.my_level`, one `ml_level()` per row.
- **The poll** (`/m/{mailbox}/changes`) keys on `mail_mailbox_changes.seq`: 204 when unchanged; else out-of-band swaps. The mail service
  must move the counter only when a list would change (`store.folder_state()` writes counts only when they differ).
- **The mail service's sync** is `services/mailer/watch.py` (a watcher thread per live mailbox, a pool ≤ 3, every DB write through
  `Sync.call()` onto the loop), `index.py` (one message → a row), `textpass.py`. IMAPClient's `idle_check` swallows an EOF — the watcher
  treats two instant empty wakes as a dropped connection. Every fetch is a PEEK; the fixture counts any `\Seen` a fetch would set.
- Phase 2's and the mailer suite's proofs follow built screens (a built screen answers by its own slice's proof; the mailer suite pauses
  its seeded mailboxes so no watcher logs in beside the probe).

## Slice 3 (2026-10-15, reading BUILT — THE SECOND EXEMPLAR) — `docs/build-specs/reading.md`, `reading-and-rendering.md`, `mail-service.md` §4–§5
- `bash tests/phase3/slice3/run.sh` (php -S) and `ML_APP=apache …`: scratch `ml_dev3`, five seeded mailboxes, the IMAP fixture grown
  (`UID MOVE` with `COPYUID`, Content-IDs in `BODYSTRUCTURE`), the image door on 9207 under its socket stand-in (`--port`) admitting ONE loopback
  image fixture through `ML_IMAGES_TEST_ALLOW` (`tests/phase3/slice3/image_fixture.py` on 9208 — started AFTER the servers, whose start kills
  every pid in the state directory); `door.py` (the service), `reading.php` (the pages and the eleven actions), `browser.mjs` (Chromium),
  `live.py` (MaluMail's test mailboxes, only with `ML_LIVE_CREDENTIALS`); door 101, reading 117, browser 60 under both. `ML_S3_HOLD=1` leaves the servers up for development.
- **Every page that shows mail copies `html/m/thread.php` + `app/features/mail/reading.php`**: the conversation from `mcp_messages` (the wall in the
  view), each OPEN message's body asked of the mail service ONCE (`fetch`, form `html`, cached there so the frame's own request costs no second
  read), the banners from the index and the fetch, attachments through the signed part door, **the eleven actions through ONE runner**
  `mail_action_run()` (targets by `ml_thread_targets()`, `ml_mutation_refusal()` here first so the page says why, one `mutate` call, one activity
  row with the count, the landing with Undo). A view holder's and an intake mailbox's reads are per person (`mail_viewer_reads`), never a `\Seen`.
- **The three layers** (`reading-and-rendering.md`): the sanitiser gained the link-host rule (`title` = the host; a text naming another host gets
  ` (host)` and counts in `deceptive_links`); `html/m/body.php` answers the sandboxed body under the exact CSP (a 16-byte nonce; the height line
  the only script); `html/p.php` and `html/img.php` are the two public doors — the token (`ML_PART_KEY`, one hour, bound to the member and the
  message, `app/features/mail/links.php`) is the authority, the member's level re-checked on every use, inline ONLY for a raster image by its
  bytes, an HTML or SVG part `application/octet-stream` attachment; `services/images/door.py` fetches a remote image for a person who asked
  (a name resolved once and pinned, global addresses only, three redirects, 5 MB, four raster types by magic, 15 s).
- **The mail service** (`services/mailer/mime_read.py`, `extract.py`; `server.py` routes `fetch` · `part` · `raw` · `mutate`, **strict**: a Mail
  admin's role opens no mailbox's content): `fetch` forms `html` · `plain` · `text` (the agent's text — quotes and signatures cut, hidden content
  counted, links as `text [→ host]`) · `headers`; parts and the raw message streamed in 1 MB partials; every op one IMAP command per folder, the index
  updated in the same request; `409 moved` on a stale UIDVALIDITY (and the folder queued for a re-read); `409 not_live` for a mailbox with no watcher.
- `mcp/records_server.py` holds `find_threads`, `thread_read`, `message_read`, `message_headers`, `find_attachments`, `attachment_read` (text of
  plain/csv/calendar, PDF through `pdftotext`, `.docx`/`.xlsx` through the standard library — never another type's content); `mcp/server_common.py`
  holds `fence_untrusted()` (the ONE fence; Phase 4's tools wrap every string that came in a message).
- The kit's `ml_guard()` now answers the schema's `42501` → 403 and `22023` → 422 in their own sentences (`DB_SENTENCE_CODES`); the proofs' `ok()`
  takes a detail printed on failure.
- **A feature file the bootstrap loads for every request shares one function namespace with every feature a controller requires**: a helper
  named twice (reading's `pg_text_array` beside admin's) is a redeclaration fatal on the admin pages alone — prefix a global helper (`ml_…`)
  and run every earlier suite before committing (the regressions found it: Phase 2 and slice 1).

## Slice 4 (2026-10-16, writing BUILT — THE THIRD EXEMPLAR) — `docs/build-specs/writing.md`, `sending-and-oversight.md`, `mail-service.md` §6
- `bash tests/phase3/slice4/run.sh` (php -S) and `ML_APP=apache …`: scratch `ml_dev4`, `ML_WITH_SMTP=1` starts `tests/fixtures/smtp_server.py`
  beside the IMAP fixture (per-user `smtp_mode` in the users file; the spool the proofs read); `service.py` (the outbox, drafts, the crash
  rule through `servers.sh restart-mailer`, the breaker, an agent's hold), `checks.php` (the pages and the nine actions), `browser.mjs`
  (375 and 1280, axe, JavaScript off), `live.py` (only with `ML_LIVE_CREDENTIALS`; at most two submissions; never print the file or a
  password); service 71, checks 113, browser 48 under both. `ML_S4_HOLD=1` leaves the servers up — stop them by their ports, never with a
  `pkill -f` pattern that matches your own shell.
- **Every page that writes mail copies `html/m/compose.php` + `app/features/compose/`**: the database row is the save (the mail service files it
  in Drafts within seconds, one copy); Send = `outbound_send()` → `ml_outbound_submit()` under `ml_guard()` (the policy, the hold, the undo
  window and the schedule are the schema's), the draft's attachments moved to the message — and back on Undo; recipients read by
  `compose_posted()` (the chips and the typed box: a form without a script still sends).
- **The outbox** (`services/mailer/outbox.py`): ONE SMTP authentication attempt (PLAIN, else LOGIN) — smtplib's `login()` would try every
  mechanism and double the failed logins MaluMail counts against this machine; `ml_outbound_sent` before filing in Sent.
- **The composer's front end**: Squire 2.4.9 needs DOMPurify (3.4.16) loaded first; both vendored with their `LICENSE` and hashes in
  `html/assets/vendor/README.md`. The sticky bar is placed by the script above the tab bar and the command bar; never assume a phone
  keyboard sends a key event for a comma.

## Slice 5 (2026-10-16, agents' mail and oversight BUILT — THE FOURTH EXEMPLAR) — `docs/build-specs/oversight.md`, `sending-and-oversight.md` §7–§9
- `bash tests/phase3/slice5/run.sh` (php -S) and `ML_APP=apache …`: scratch `ml_dev5`; `checks.php` (every hold reason from a REAL agent send
  under a run token, the refusals, the five decisions, agents never decide, a person's mailbox, expiry, Unscreened, pause/resume, the
  policy), `tools.py` (the records and activity queries as the records role), `browser.mjs` (375 and 1280, the sandboxed held body),
  `live.py` (only with `ML_LIVE_CREDENTIALS`; ONE submission); checks 168, tools 31, browser 27 under both. `ML_S5_HOLD=1` leaves the
  servers up. The fake kernel's chat endpoint appends every turn (agent, acting member, utterance, context, conversation) to `<state>.chat`.
- **Every people-only decision copies `html/oversight/approve.php` + `app/features/oversight/`**: `ov_handler_begin()` (POST, CSRF,
  `require_human()`, the right) — the SQL function refuses an agent (`ml_require_person`) and a non-decider (`ml_require_decider`) AGAIN;
  log ids, digests and counts, never a body; an outside address by its domain.
- **A mail turn carries ids and a person's words, never mail text** (`bin/worker.php` `worker_dispatch_turn()`): no body, no subject; the
  conversation id `mail:<mailbox>:<anchor>`; a paused mailbox gets no turn.
- **The records queries read the `mcp_*` views only** (the records role has no base table, not even `members` — use `mcp_members`), answer
  plain text, and leave fencing to Phase 4's tools. `mcp_outbound_attachments` (db/018) is a held message's files.
- **pdo_pgsql with native prepares refuses a named placeholder used twice** — name each use (`:mb`, `:mb2`).
- **PHP here has no `intl`**: no `idn_to_utf8()`; a link's host is shown as parsed (an `xn--` name stays so — a look-alike shows as one).

## Slice 6 (2026-10-16, search, labels, snooze, folders and templates BUILT) — `docs/build-specs/search-labels-snooze.md`
- `bash tests/phase3/slice6/run.sh` (php -S) and `ML_APP=apache …`: scratch `ml_dev6`; the IMAP fixture grew `RENAME` and `DELETE` (a delete takes the
  folder's mail with it, as Dovecot does — the service counts first); five LIVE mailboxes on the fixtures (priya in Europe/Berlin, dana, sales, the
  Expert's, an intake) and five SEEDED straight into the index with no credential (big1/2/3 = 2,000 synthetic messages, hush, agentbox with one
  sensitive conversation); `checks.php` (every operator against an independent SQL truth, the cursor over the whole estate, the wall, labels,
  snooze and the worker's wake, folders, templates, the log vocabulary), `tools.py` (the five records queries), `browser.mjs` (375 and 1280, axe).
  `ML_S6_HOLD=1` leaves the servers up — and a second `checks.php` run on a held database fails (the labels and folders it makes exist).
- **A search groups messages into conversations at the NEWEST matching message** (`app/features/mail/search.php`, and `mail_search` in
  `mcp/records_server.py` the same way): the walk is `ml_search()`'s offset form 500 messages at a time (cap 5,000), a conversation is met first at its
  representative, the cursor `<µs>.<message id>` is a position in that order — so the pages repeat nothing and skip nothing. An unknown operator is
  searched as words and named (`ml_search_unknown()`); only a malformed date, period or size is `bad_query` (the page answers 422 in words). Pass
  `CAST(:x AS integer)` for ml_search's offset form — it has two overloads and a bare parameter is ambiguous.
- **Labels sit on MESSAGE KEYS, in the index only** (`ml_label_apply()` — every key of each conversation — so a reply keeps the label);
  `matching` (a search, ≤ 1,000 conversations) works on `mail_label` as on slice 3's actions; labels are fine on an intake mailbox.
- **Snooze is the mail service's move plus the schema's state**: `mutate snooze` moves the Inbox copies to the Snoozed folder (the first sync makes
  Archive and Snoozed on every live mailbox; special_use `snoozed` is recorded), `ml_thread_snooze()` keeps until / from / by on ONE anchor per
  conversation, and the handler moves the mail FIRST and the state second (putting it back if the state is refused). The worker's `snoozes` pass
  calls `ml_snoozes_due()` (db/019: stamps `woke_at`, the "Back from snooze" chip until the conversation is opened), `ml_snooze_mail_back()` (the
  service's `move` back, then `unseen` on the latest message), a `snooze_woke` notice, and on an agent's mailbox an `agent_dispatches` row of kind
  `snooze` whose turn carries ids only. A mail service that cannot be reached puts the snooze back for five minutes. Unsnooze is
  `ml_thread_unsnooze()` (db/019) and the same move.
- **Folders** are `mutate folder_create · folder_rename · folder_delete` (`ml_mutation_refusal()` first — the system folders keep their names —,
  one IMAP command, the index following in the same request); a folder with mail in it is refused with its count, never deleted. Two forms of
  one `<details>` choose labels and snooze times for the list's bulk bar AND the conversation (`label-form.php`, `snooze-form.php`).
- **"Select all N" is a search**: the bar's field is `matching`, filled only when pressed, from `mail_view_matching()` (list.php) — Inbox `in:inbox`, a folder `in:"name"`,
  a label `label:slug in:anywhere`; views with no exact search (starred, all mail, drafts, scheduled, outbox) show no button. Archive acts on Inbox copies only; from a folder use Delete or Move.
- **Search pages cost the result set** (cursor-form walk, ≤ 5,000 messages) — ~300–440 ms on 5,000 messages; the 50-row fetch sets `jit = off` (the planner prices `ml_thread_state()` at 1,000 rows
  and JIT-compiled it: 250 ms) and reads base tables behind the walled search. A canned reply's `usage_count` bumps in `send_answer()` from the composer's `template` field.
- **Never in a log payload**: a label, folder, template or subject name — ids, counts, the wake time. Canned replies are a person's alone
  (`require_human()` and the schema again); `compose?template=` fills the composer.

## Numbers
Ports `APP_INTERNAL_PORT=8199`, `MCP_RECORDS_PORT=8852`, `MCP_ACTIVITY_PORT=8853`, `ML_MAILER_PORT=8854`, `ML_IMAGES_PORT=8855`;
scratch 9201 (the app), 9202 (the fake kernel), 9203 (the fake MaluDB + MaluMail REST), 9204–9205 (the MCP servers), 9206 (the
mail service), 9207 (the image door); prefix `ml_`; scratch databases `ml_*`. Kernel items K43 (the catalog row, db/181 — **built and applied 2026-10-11**), K44 (the overseer link in the feed), K45 (the
kernel's intake independent of `\Seen`), K46 (the kernel's agent sends through this application's outbox), K55 (the Actions MCP's flat namespace — found here). MaluMail items take the prefix **MM** (MaluDB's and MaluMail's old M numbers collide).
