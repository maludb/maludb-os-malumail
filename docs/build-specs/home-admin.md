# Slice spec: home, admin and settings (slice 10)

What exists at the end: **the Mail admin's overview** (`/admin`) — the served domains and any whose SPF, DKIM or DMARC is not
verified, the mailboxes by kind and state, the forwarding addresses that go outside, sync health, failed credentials, held mail, the
agents' business-wide budget today and the two switches; **sync health** (`/admin/sync`) — per mailbox the sync state, the watcher,
index and text coverage, the credential and the last error, with **Re-sync** and **Re-index text**; **MaluMail's settings**
(`/admin/settings`) — the reading and writing defaults, held-mail expiry, the agent policy defaults, **the business-wide budget and its
two switches** (`hold_all_agents`, `hold_all_external`, R46), the MM3 switch, the Clerk's hour and **the monitoring notice** (a new
version everyone acknowledges again); and **the agents** (`/admin/agents`) — every agent with a mailbox, its mail turns (dispatches)
and their states, **Retry** for a failed one, and the way to the kernel's agent page for hires and grants. Design §3 ("Notice before
oversight"), §5 (the budget and the switches), §6.5, §9 ("Admin", "Sync health", "Admin settings · Agents").

Not an exemplar: it replicates slice 1's admin screens (cards, full-page forms, `require_human()`, the super-admin's rights) and slice
2's reads. The person's home (`/` → their default mailbox's Inbox) is the shell's; "home" in this slice's name is the admin's.

**Prerequisites**: slices 1 (domains, mailboxes, aliases — the overview's cards link there), 2 (the mail service's `status`, the
index's counts), 5 (dispatches, held mail, policies — the agents' page reads them); db/013_list_and_sync.sql (built and proven: `db/proof/phase1_list.sql`).

## Divergences from the design

1. **The settings' parameters are the columns' names** (`undo_send_seconds`, `index_text_max_kb`, `clerk_note_at` …), not the
   manifest's first draft (`undo_seconds_default`, `body_index_kb`, `clerk_hour`); the manifest row is corrected (below).
2. **The MM3 switch (`mm3_identity_enforced`) is on this page**, confirmed by name: design §5 leaves "who turns it on" open; a Mail
   admin does, once MaluMail's infrastructure side confirms MM3 — it changes which internal-looking mail may wake an agent.
3. **Re-sync and Re-index are a Mail admin's alone** (`settings.manage`): design §9 puts them on the sync page; a mailbox's `full`
   holder sees its sync state on the mailbox (slice 2's empty state) but cannot force a re-read of a shared server.

## Who may do what

| Who | Screens | Actions |
|---|---|---|
| A Mail admin (a super-admin; every admin right is `held_by super_admins`) | all four | all five |
| A Mail user, an overseer | none (403 with `ml_right_refusal()`'s sentence) | none |
| An agent | none | none — every action of this slice is `people` (`require_human()`) and its right is a super-admin's (refused twice) |

A Mail admin sees **every mailbox's administrative row** (state, quota, credential, errors) and **no content of a person's mailbox**:
nothing on these pages is a subject, a name of a correspondent or a body; the agents' page shows a dispatch's sender **domain** and its
decision for an agent's mailbox, never a subject of a person's.

## Screens

### `admin` — `/admin`

Cards, each linking to its page (slice 1's or this slice's): **Domains** (served / hosted; a red line per served domain with SPF, DKIM
or DMARC not verified as last read), **Mailboxes** (by kind; suspended; not connected), **Forwarding** (count; "N go outside" in amber),
**Sync** (live / syncing / failing / failed credentials — red when any credential failed), **Held mail** (total, the oldest's age; the
review queue is the overseers' — the card links to `/oversight/review` with every mailbox for a Mail admin), **Agents' budget today**
(`n of ml_agent_external_budget()` with the warm-up week, or "the Mail admins' cap"), **Switches** (a red banner across the page while
`hold_all_agents` or `hold_all_external` is on: "Every agent's mail is being held — Settings"). 375 px: one column of cards; 1280: three.

### `admin-sync` — `/admin/sync`

A table (a ledger, not cards): mailbox, kind, sync state (`new` · `syncing` · `live` · `paused` · `failed`), the watcher (`notify` ·
`idle` · `down`, from the mail service's `/v1/status`), last sync, messages indexed / text indexed (a bar), quota used, the credential
(`ok` · `failed` · `revoked` · none), the last error and when, and **Re-sync** / **Re-index text** buttons. Filter `?state=` (failing ·
syncing · failed · all). At 375 each row is a card with the same fields. The mail service down → a banner "The mail service is not
answering" and the database's columns alone. Empty: "No mailbox is connected yet — Mailboxes".

### `admin-settings` — `/admin/settings`

One form in fieldsets, each with a sentence of what it does; Save asks first (✔: "These change every mailbox and every agent's
mail"):

- **Reading and writing**: `undo_send_seconds` (0–30), `images_default` (ask · always), `index_text_max_kb` (10–1024),
  `max_attachments_mb` (1–9), `draft_save_seconds` (5–120), `poll_visible_seconds` (5–300), `poll_hidden_seconds` (15–900).
- **Held mail**: `held_expiry_hours` (1–720).
- **A new agent mailbox's policy** (an overseer changes one agent's within these): `default_send_mode`, `default_first_contact_review`,
  `default_attachments_review`, `default_max_external_per_day`, `default_max_per_hour`, `default_max_per_thread_per_day`,
  `default_wake_on_mail`, `default_wake_from`, `default_disclosure`, `disclosure_text` (must contain `{agent}`).
- **The agents' business-wide budget**: `agent_external_budget` (empty = the warm-up, shown with today's figure) — and **the two
  switches**, each with its consequence in words: `hold_all_agents` ("every agent's message waits for a person"), `hold_all_external`
  ("every agent's message to anyone outside the business waits"). R46's per-agent switch is the overseer's Pause (slice 5); the
  submission hard stop is MaluMail's MM10 — until then suspending the mailbox (slice 1) is the hard stop, and the page says so.
- **MM3**: `mm3_identity_enforced`, with the sentence "Turn on only when MaluMail confirms that a mailbox can no longer send as
  another: internal mail then wakes agents without this application having sent it" (✔ typed back: "MM3").
- **The Mail Clerk's hour**: `clerk_note_at`. The named agents (`expert_member_id`, `clerk_member_id`) are shown, read-only, with a
  link to the kernel's agent page.

**The notice** is its own form below (`mail_notice_save`): the text, its version, when it changed, how many people have acknowledged
the current version of how many with MaluMail; Save asks first ("Everyone acknowledges the new version on their next visit").

### `admin-agents` — `/admin/agents`

Cards per agent with a mailbox: the agent, its mailbox, paused or not, its send mode, the last 7 days' turns by status (sent, answered,
failed, unscreened, refused), the primary overseer, and a link "Hire, grants and duties — in the OS" (the kernel's agent page,
`os_url`). Below, the dispatches as a table (time, agent, mailbox, kind, status, decision, the sender's domain, attempts, the run with
a link to the kernel's run page) filtered by `?status=` and `?agent=`; a `failed` one has **Retry**. 375: rows become cards.

**HTMX** for all four: forms post and answer with `ml_done()`'s redirect back and a toast; Re-sync, Re-index and Retry swap their row
(`#sync-{mailbox}`, `#dispatch-{id}`) with its new state; the sync page refreshes itself every 30 s while visible.

## Files (exactly these)

| File | What |
|---|---|
| `html/admin/index.php` | `admin` |
| `html/admin/sync/index.php`, `resync.php`, `reindex.php` | `admin-sync` and its two actions |
| `html/admin/settings/index.php`, `save.php`, `notice.php` | `admin-settings` and its two actions |
| `html/admin/agents/index.php`, `retry.php` | `admin-agents` and its action |
| `app/features/admin/overview.php` | `admin_overview()`, `admin_sync_rows()`, `admin_agent_cards()`, `admin_dispatches()` |
| `app/views/admin/{overview,sync,settings,agents}.php`, `app/views/admin/partials/{sync-row,dispatch-row,switch-banner}.php` | templates |
| `services/mailer/server.py` | `POST /v1/status` (all) — `mail-service.md` §1 |
| `db/013_list_and_sync.sql` | `ml_settings_save()`, `ml_notice_save()`, `ml_mailbox_reindex()`, `ml_dispatch_retry()`, `ml_sync_health()` |
| `tests/phase3/slice10/{run.sh,admin.php,browser.mjs}` | the proof |

## Query functions and mail-service calls (signatures fixed)

**SQL (`db/013_list_and_sync.sql`; each refuses an agent and a member without the right, in SQL):**

- `ml_settings_save(p_by bigint, p_values jsonb) RETURNS jsonb` — `ml_require_person(p_by)`, `ml_require_right(p_by,
  'settings.manage')`; only the keys of §Screens are accepted (an unknown key refused by name); the table's CHECKs stand; answers
  `{before, after}` of the changed keys for the log.
- `ml_notice_save(p_by bigint, p_text text) RETURNS integer` — the same guards; the new version (db/005's trigger bumps it).
- `ml_mailbox_reindex(p_mailbox bigint, p_by bigint) RETURNS integer` — guards; `UPDATE mail_messages SET body_text = NULL,
  text_indexed_at = NULL WHERE mailbox_id = p_mailbox`; answers the count.
- `ml_dispatch_retry(p_dispatch bigint, p_by bigint) RETURNS void` — guards (`agents.manage`); only `failed` → `queued`,
  `next_attempt_at = now()`, `attempts` unchanged (the worker's dispatch pass counts the try).
- `ml_sync_health() RETURNS TABLE (mailbox_id bigint, address text, kind text, sync_state text, last_sync_at timestamptz,
  messages integer, text_indexed integer, quota_used_bytes bigint, quota_bytes bigint, credential_status text, last_error text,
  last_error_at timestamptz)` — `ml_is_mail_admin()` or nothing; counts only.

**PHP (`app/features/admin/overview.php`):** `admin_overview(PDO $db): array`, `admin_sync_rows(PDO $db, string $state): array`
(`ml_sync_health()` joined in PHP with `mailer_call('/v1/status', …)`'s watchers), `admin_agent_cards(PDO $db): array`,
`admin_dispatches(PDO $db, ?string $status, ?int $agent, int $limit): array` (over `mcp_dispatches`, sender domain only).

**The mail service**: `/v1/status` (all) for the page; `sync` with `{"full": true}` for Re-sync; `sync` with `{"text": true}` after
`ml_mailbox_reindex()` for Re-index.

## MCP tools this slice makes real

`mailbox_health`.

## Manifest rows claimed
Screens (4): `admin`, `admin-sync`, `admin-settings`, `admin-agents`
Actions (5): `mail_settings_save`, `mail_notice_save`, `mail_mailbox_resync`, `mail_mailbox_reindex`, `mail_dispatch_retry`

## Activity log events

| Action | Event | Payload |
|---|---|---|
| `mail_settings_save` | `settings.save` | the changed keys with before and after (no text over 200 characters; the disclosure text by length) |
| `mail_notice_save` | `notice.save` | the new version, the text's length |
| `mail_mailbox_resync` | `mailbox.resync` | `mailbox_id` |
| `mail_mailbox_reindex` | `mailbox.reindex` | `mailbox_id`, the count reset |
| `mail_dispatch_retry` | `dispatch.retry` | the dispatch id, its agent, `mailbox_id` |

## Requirements claimed
MUSTs (1): `R46`

## Out of scope for this slice

Domains, mailboxes and forwarding themselves (slice 1); the review queue and the per-agent pause (slice 5); the notice's
acknowledgement page (the shell); notifications to the Mail admins (slice 9 — this slice's failures show on the pages only); the
kernel's hires, grants and duties (the OS).

## Proof

`tests/phase3/slice10/run.sh`: scratch `ml_dev10`, php -S on 9201, the fake kernel on 9202, the fakes on 9203, the mail service on
9206 with the IMAP fixture; then a user-level Apache on 9201; Playwright.

1. **Who**: a Mail user and an overseer get 403 with the right's sentence on each page and each POST; an agent's action token is
   refused by `require_human()` and, called directly, by the SQL; a super-admin passes.
2. **Settings**: each key saved and read back; a value out of range refused with the CHECK's sentence; an unknown key refused; the two
   switches change `ml_outbound_hold_reasons()`'s answer for a fixture agent's message at once (`hold_all_agents`,
   `hold_all_external`); `mm3_identity_enforced` changes `ml_dispatch_decide()` for a `local_submission` message; the log carries the
   changed keys only.
3. **The notice**: a save bumps the version; a person who acknowledged version 1 is sent to `/notice` on their next page; the count
   of acknowledgements shown is right.
4. **Sync**: a fixture mailbox `failing` (the fixture refusing connections) shows its error; Re-sync re-reads it (the fixture's log
   shows the `SELECT … QRESYNC`); Re-index empties `body_text` and the text pass refills it; the mail service down → the banner and
   the database's columns; a person's mailbox row shows no subject, no correspondent.
5. **Agents**: a failed dispatch retried goes `queued` once (a second Retry refused: not failed); the page shows sender domains only.
6. **Overview**: each card's figure equals the SQL's; the switch banner on every admin page while a switch is on.
7. **Screens at 375 and 1280**; no horizontal scroll; the registry `--check`; the earlier slices' suites still green.

## Decisions taken here

1. **The settings save is one SQL function with a key allow-list** — PHP never builds an `UPDATE` from a form, and a key added to
   `ml_settings` later is not writable until it is named.
2. **MM3's switch lives with the Mail admins and asks for the name typed back** — it widens what wakes an agent, which is a decision as
   heavy as the two switches.
3. **Re-index resets the text in SQL and lets the gentle text pass refill it** — a forced fast re-read would trip MaluMail's rate rules
   for a large mailbox.
4. **The agents' page links to the kernel for hires, grants and duties** and repeats none of them — the kernel owns agents (the 2026-09-22
   decision).
5. **Retry does not count an attempt itself** — the worker's dispatch pass is the one place a try is counted.

## Open questions

- None.

## Changes this spec asks of the shared documents

- **Manifest**, `mail_settings_save`: replace the params with the columns' names — `undo_send_seconds` (0 to 30), `images_default`
  (ask or always), `index_text_max_kb`, `max_attachments_mb`, `draft_save_seconds`, `poll_visible_seconds`, `poll_hidden_seconds`,
  `held_expiry_hours`, `default_send_mode` (hold_all internal_auto allowlist_auto or auto), `default_first_contact_review` (yes or
  no), `default_attachments_review` (yes or no), `default_max_external_per_day`, `default_max_per_hour`,
  `default_max_per_thread_per_day`, `default_wake_on_mail` (yes or no), `default_wake_from` (none internal internal_and_known or
  all_authenticated), `default_disclosure` (yes or no), `disclosure_text`, `agent_external_budget` (empty for the warm-up),
  `hold_all_agents` (yes or no), `hold_all_external` (yes or no), `mm3_identity_enforced` (yes or no), `clerk_note_at` (HH:MM).
  `mail_mailbox_resync` and `mail_mailbox_reindex`: `Who` stays `settings.manage`.
- **db/013_list_and_sync.sql** — BUILT and proven (`db/proof/phase1_list.sql`, 75 checks): `ml_settings_save()`, `ml_notice_save()`, `ml_mailbox_reindex()`, `ml_dispatch_retry()`, `ml_sync_health()` as above;
  GRANT EXECUTE to `malumail_rw`; `ml_sync_health()` also to `malumail_records_ro` for `mailbox_health`.
- **Tool surface**: `mailbox_health` reads `ml_sync_health()` for a Mail admin and `mcp_mailboxes` for everyone else.
- **mail-service.md** (this fork's own): `POST /v1/status` (all) added.


## Built and proven (2026-10-20)

Built: `/admin` (seven cards: domains with any SPF/DKIM/DMARC not verified in red, mailboxes by kind with suspended and not connected, forwarding with "N go outside", sync, held mail with the
oldest's age, the agents' budget today, the switches), `/admin/sync` (a ledger row per mailbox that stacks into a card below 768 px; filters failing, syncing, credential failed; Re-sync and
Re-index text swap their row; the rows refresh every 30 s through `?rows=1`), `/admin/settings` (the form in fieldsets that asks first, the budget with today's figure, the two switches, MM3 with
its name typed back, the Clerk's hour, the notice form with its version and "N of M" acknowledgements), `/admin/agents` (a card per agent mailbox, the turns as a ledger, Retry), the five actions,
the red switch banner on EVERY admin page (the six slice 1 views carry it too), `POST /v1/status` on the mail service, and `mailbox_health` in `mcp/records_server.py`. No migration:
db/013's five functions were enough. Registry: 45/45 screens, 67/67 actions built.

**Proven** (`tests/phase3/slice10/run.sh`, php -S and `ML_APP=apache`, identical): checks 81, tools 12, browser 29.

**Found and fixed**
1. An `hx-select` on the rows' container is inherited by every button and link inside it and emptied Re-sync's answer; the refresh is now `?rows=1` returning the rows alone.
2. Phase 2's last two placeholder checks (a POST and JSON to `/admin/settings`) follow whether any screen is still unbuilt; with none left the JSON one asserts the built screen's data.
3. At review (the planning model): the agents' page printed a mail turn's decision code ("Why: internal_unproven"); `ADMIN_DISPATCH_WHY` words all fourteen of `ml_dispatch_decide()`'s decisions (the unscreened four as slice 5 words them), with a check.

**Decisions taken here (within the spec)**
1. Filters: `failing` = sync state failed, or an error left standing on a mailbox that is not live; `syncing` = new or syncing; `failed` = the credential failed or was revoked.
2. `POST /v1/status` answers every mailbox's watcher and back-off to a Mail admin (settings.manage) and nothing else; PHP joins it onto `ml_sync_health()`.
3. Saving the notice does not acknowledge it for the saver: the Mail admin is sent to /notice like everyone ("everyone acknowledges again").
4. The kernel's agent and run pages are linked as `<OS_URL>/agents/<id>` and `<OS_URL>/ai/runs/<run>` (OS_URL, else the launcher's host with `os.` for `app.`); the kernel's route names are assumed, not read.
5. `mailbox_health` writes no `mcp.read`: it answers administrative columns only (like the screen), unlike `mailbox_get`.
6. A form's unchecked box is sent as `no` by a hidden input, so "left out stays as it was" never blurs with "switched off".
