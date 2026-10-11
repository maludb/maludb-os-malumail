# Slice spec: the mail service and the list (slice 2 — THE FIRST EXEMPLAR)

What exists at the end: **the mail service keeps every live mailbox in step** — the first sync, `QRESYNC` catch-up, `NOTIFY` (else
`IDLE` and the `STATUS` round), the text pass, the change counter — built as `mail-service.md` §§2–3, 8–10 and 12–13 fix it; and **the
list**: the rail filled with the mailboxes a person can open and their unread counts, a mailbox's views (Inbox, Starred, Snoozed, Sent,
Drafts, Scheduled, Outbox, All mail, Spam, Trash) and its folders as Gmail's conversation list — select, the bulk bar, stars, labels,
the paper clip, snippets, dates, agent badges, keyset paging with "1–50 of N", the cheap poll answering 204 when nothing changed, the
"N new" bar, the unread count in the title and the favicon. Design §1, §3, §6.1, §6.3, §8, §8.1, §9.

**It is the first exemplar**: every later slice's page reads the index the way this one does — **one SQL gatherer behind the screen and
the MCP tool** (the rule My Work set in the kernel: `ml_mail_list()` serves `mailbox-view`, `folder-view` and `mail_list`), the wall
in SQL, the mail service through `app/mailer.php`, the poll by change counter. Slices 3 and 6 put their actions on the bulk bar this
slice draws; slice 6 adds `label-view` and search on the same gatherer.

**Prerequisites**: Phase 2's shell (`sso-shell.md`: the layout with the rail's frame, `app/mailer.php`, the record picker, the search
box, `/api/v1/health`); Phase 0's schema (db/008's index and threading, db/011's views) and the mail service's skeleton; db/013_list_and_sync.sql (built and proven: `db/proof/phase1_list.sql`).

## Divergences from the design

1. **Drafts, Scheduled and Outbox are not conversation lists of the index alone.** Drafts lists the Drafts folder's conversations
   (so a phone's draft shows) and opens each in the composer (slice 4); Scheduled lists `outbound_email` rows `queued` with
   `send_after` past the undo window; Outbox lists rows `deferred`, `failed`, `held` and `refused` — each as a list row in the same
   shape, from `ml_mail_list()`.
2. **Unread is per person where the server's flag cannot be.** For a `view` holder and on every intake mailbox (D13), unread is
   "no `\Seen` and no `mail_viewer_reads` row of mine" (`ml_read_mode()` = `viewer_read`); for the owner and a `triage` holder it is the
   server's `\Seen`. The rail's count follows the same rule (`ml_unread_count()`).
3. **"1–50 of N" uses a bounded count.** N is exact up to 10,000 conversations in the view and "more than 10,000" beyond, so a
   page never counts a whole mailbox.

## Who may do what

| Who | Reads | Note |
|---|---|---|
| A Mail user | the mailboxes `ml_my_mailbox_ids('view')` names: their own, those shared with them, the agent mailboxes they oversee | anything else is **404** (design §3) |
| A `view` holder | the list as the owner sees it, with their own unread | a list view is logged once per mailbox per half hour (`mailbox.view`, the access log) |
| A Mail admin | **an agent's mailbox** at `full`; a person's only by a row | the rail shows no person's mailbox to them by holding the role |
| An agent (records MCP) | `mail_list`, `mailbox_overview`, `find_mailboxes`, `find_folders`, `mailbox_get` under `ml_level_as()` | never a conversation marked sensitive (db/011) |
| The mail service | every live mailbox | writes the index; never `\Seen` in a sync |

No action in this slice changes anything; the bulk bar posts slices 3's and 6's actions.

## Screens

### The rail (the shell draws its frame; this slice fills it)

- **Groups**: *My mailbox* (owned by me), *Shared with me* (rows and departments), *Agents I oversee* (agent mailboxes at `full`),
  *Agents* (for a Mail admin: every agent mailbox not already listed). Each row: display name, address on hover and at 1280, the
  unread count (Inbox), a dot when the mailbox is failing to sync, a pause icon when an agent's mail is paused, the held count on an
  agent mailbox ("3 for review").
- **The current mailbox** expands to its views — Inbox, Starred, Snoozed, Sent, Drafts, Scheduled, Outbox, All mail, Spam, Trash — with
  counts where they matter (Inbox unread, Drafts total, Scheduled total, Outbox failed + held), then its folders (other than the special
  ones) as a tree by delimiter, then its labels (slice 6 fills them; until then the group is absent), then Compose (slice 4).
- **375 px**: the rail is the page's off-canvas menu, opened by the ☰ button; the current mailbox and view name the header.
  **1280**: a fixed 240 px column.

### `mailbox-view` — `/m/{mailbox}/v/{view}` · `folder-view` — `/m/{mailbox}/f/{folder}`

One list, two ways to choose its rows. `{view}` ∈ `inbox` · `starred` · `snoozed` · `sent` · `drafts` · `scheduled` · `outbox` · `all`
· `spam` · `trash`; an unknown view answers 404.

| View | Rows (conversations unless said) |
|---|---|
| inbox | a message in the `inbox` folder; not snoozed |
| starred | `mail_threads.flagged` |
| snoozed | a thread state with `snoozed_until`; the wake time shown instead of the date |
| sent | a message in `sent` |
| drafts | a message in `drafts` (opens the composer — slice 4) |
| scheduled | `outbound_email` rows `queued` with `send_after` > now + 30 s: To, subject, the time it goes |
| outbox | rows `deferred` (with the next attempt), `failed` (with the server's answer), `held` (with the reasons), `refused` (the rule) |
| all | every conversation outside `trash` and `junk` |
| spam / trash | a message in `junk` / `trash` |
| a folder | a message in that folder |

**A row** (conversation view): a check box; the star; the participants (names, the mailbox's own left out, "me" for its own sends,
a count when more than one message, unread names in bold); the subject and the snippet in grey; label chips (slice 6); the paper clip;
the date (today: the time; this year: day and month; else the year); and **agent badges** — "drafted by {agent}" on a conversation an
agent sent into, "awaiting review" when an outbound row of it is `held`. Unread rows are bold. Conversations marked sensitive show a
lock to people. **Message view** (the mailbox's `conversation_view` setting off, R2's toggle): the same row per message.

**The bulk bar** appears when one row is checked: Select all on this page / all N matching; then the actions **whose handler file
exists** (the registry's `built` flag): Archive, Delete, Spam / Not spam, Mark read / unread, Star, Move to, Label, Snooze (slices 3
and 6). The bar posts `thread[]` with the view's `from_folder`. Until those slices it shows only the selection count.

**Paging (R3)**: keyset on `(last_message_at, id)` descending — `?before=<unix ms>.<id>` — with "Newer" and "Older" and "1–50 of N";
the page size is the mailbox setting `page_size` (25, 50 or 100; default 50). No infinite scroll. A page stays stable when new mail
arrives (new rows only come in through the "N new" bar).

**Filters**: `?unread=1` ("Unread only" chip). Search is the shell's box (slice 6 answers it).

**Empty states**: Inbox — "Nothing new. Mail that arrives shows here within a few seconds."; a mailbox still syncing — "Bringing this
mailbox in: 1,240 of about 5,000 messages so far" from `status`; a mailbox not connected — "This mailbox is not connected yet" and,
for a Mail admin, a link to its admin page; a failed credential — "Not connected: a Mail admin stores a new credential" (no retry
button: the breaker). Spam and Trash: "Nothing here." Scheduled: "Nothing scheduled."

**HTMX**: the page is `#page-content` with the list in `#mail-list` (`data-screen`, `data-mailbox`, `data-view`, `data-folder`,
`data-change-seq`). "Older"/"Newer" swap `#mail-list` and push the URL. Checking rows is plain JS (`mail-list.js`), no request.

### The poll (`GET /m/{mailbox}/changes?since={seq}&view=&folder=`) — design §8.1, R16

- `mail-list.js` asks every `poll_visible_seconds` (15) while `document.visibilityState === 'visible'` and every
  `poll_hidden_seconds` (60) while hidden (Spaces' guard); it stops after three answers of 404 or 401.
- **204** when `mail_mailbox_changes.seq` = `since` (one indexed read, no IMAP).
- Otherwise **200**, a fragment of out-of-band swaps: the rail's counts (`#rail-counts`, `hx-swap-oob`), the current list's changed
  rows that are on the page (`#t-{id}` swapped in place — flags, unread, snippet), a removed row's tombstone (`#t-{id}` removed), and
  **the "N new" bar** above the list when conversations newer than the page's first row arrived ("3 new conversations — show"), which
  reloads the first page. The answer's `X-ML-Seq` header is the next `since`; `X-ML-Unread` the Inbox's unread count.
- **The title and the favicon**: `(3) Inbox — sales@subello.com · MaluMail`; the favicon gets a red count badge drawn on a canvas
  (none at 0; "9+" past nine).

## Files (exactly these)

| File | What |
|---|---|
| `html/m/view.php`, `html/m/folder.php` | the two screens (GET; `ml_require_mailbox($mailbox, 'view')`) |
| `html/m/changes.php` | the poll door (GET; 204 / 200 fragment) |
| `app/features/mail/list.php` | `mail_list_page()`, `mail_rail()`, `mail_changes()`, `mail_view_label()` |
| `app/views/mail/list.php`, `app/views/mail/partials/{rows,row,bulk-bar,new-bar,rail,rail-counts,empty}.php` | the templates |
| `html/assets/js/mail-list.js` | selection, the bulk bar's state, the poll with the visibility guard, the title and the favicon |
| `services/mailer/{index.py,watch.py,textpass.py}`, changes to `server.py`, `store.py`, `imap.py`, `config.py` | `mail-service.md` §§2–3, 8–9 |
| `bin/worker.php` | the `index_catch_up` pass made real: mailboxes live but not synced for 10 minutes → `sync`; tombstones pruned after a day |
| `db/013_list_and_sync.sql` | `ml_mail_list()`, `ml_mailbox_counts()`, `ml_unread_count()`, the change counter (mail-service.md) |
| `mcp/records_server.py` (Phase 4 wires it; this slice writes the tools' queries) | `find_mailboxes`, `mailbox_get`, `mailbox_overview`, `mail_list`, `find_folders` |
| `tests/fixtures/imap_server.py` | slice 2's growth (`mail-service.md` §12) |
| `tests/phase3/slice2/{run.sh,list.php,sync.py,browser.mjs}` | the proof |

## Query functions and mail-service calls (signatures fixed)

**SQL (`db/013_list_and_sync.sql`; SECURITY DEFINER, the wall inside, `STABLE`, read once per statement):**

- `ml_mail_list(p_mailbox bigint, p_view text, p_folder bigint, p_label bigint, p_unread boolean, p_before_at timestamptz,
  p_before_id bigint, p_limit integer) RETURNS TABLE (thread_id bigint, subject text, participants text[], names jsonb,
  last_message_at timestamptz, message_count integer, unread boolean, flagged boolean, has_attachments boolean, snippet text,
  labels jsonb, snoozed_until timestamptz, sensitive boolean, agent_badge text, latest_message bigint, outbound_id bigint,
  outbound_status text, send_after timestamptz, hold_reasons text[])` — 404-equivalent (no rows and `not_found`) when
  `ml_level(p_mailbox)` is none; `p_limit` 1–100; the scheduled and outbox views fill the outbound columns.
- `ml_mail_list_total(p_mailbox bigint, p_view text, p_folder bigint, p_label bigint, p_unread boolean) RETURNS integer` — capped at
  10,001.
- `ml_mailbox_counts(p_mailbox bigint) RETURNS jsonb` — `{inbox_unread, inbox_total, starred, snoozed, next_wake, drafts, scheduled,
  deferred, failed, held, unscreened, spam}` (the `mailbox_overview` tool's answer and the rail's).
- `ml_unread_count(p_mailbox bigint) RETURNS integer` — Inbox, by `ml_read_mode()`'s rule for the caller.
- The poll: `ml_mailbox_seq(p_mailbox bigint) RETURNS bigint` (NULL when the caller may not open it) and
  `ml_mailbox_changed(p_mailbox bigint, p_since bigint, p_limit integer) RETURNS TABLE (thread_id bigint, change_seq bigint, gone
  boolean)` — the conversations changed since `p_since` and the tombstones of those that went (an agent never gets a sensitive one).
- `ml_mailbox_touch(p_mailbox bigint, p_threads bigint[]) RETURNS bigint`, `mail_mailbox_changes`, `ml_tombstones_prune()` (the
  worker's `index_catch_up` pass) — `mail-service.md`.
- Inside: `ml_mail_list_ids()` (the view's filter and cursor, shared by the list and its total) and `ml_thread_unread_for()`.

**PHP (`app/features/mail/list.php`):**

- `mail_list_page(PDO $db, int $mailbox, string $view, ?int $folder, ?string $before, bool $unread, int $limit): array` →
  `['rows' => [...], 'older' => ?string, 'newer' => ?string, 'total' => int, 'seq' => int]`.
- `mail_rail(PDO $db, int $currentMailbox): array` → the groups of §Screens with counts (one query over `mcp_mailboxes` +
  `ml_unread_count()` per listed mailbox, at most 50).
- `mail_changes(PDO $db, int $mailbox, int $since, string $view, ?int $folder, array $pageThreadIds): ?array` → null for 204.

**The mail service** (through `app/mailer.php`): `status` for the syncing empty state; no other call — the list is the index's.

## MCP tools this slice makes real

`find_mailboxes`, `mailbox_get`, `mailbox_overview`, `mail_list`, `find_folders`.

## Manifest rows claimed
Screens (2): `mailbox-view`, `folder-view`
Actions (0):

## Activity log events

- `mailbox.view` — a list view of a mailbox by anyone but its owner, at most once per mailbox per person per half hour
  (`ml_access_should_log()`), with `mailbox_id`, the view, and the door (`web`). The owner's own list views are never logged.
- `mcp.read` — a records-MCP read of a mailbox that is not the caller's own (`ml_log_mcp_read()`, tool surface).
- Never logged: the poll, the sync, the text pass (design §6.6).

## Requirements claimed
MUSTs (4): `R2`, `R3`, `R4`, `R16`

## Out of scope for this slice

Opening a conversation and every action of the bulk bar (slices 3 and 6); labels' screens and chips' data (6); search answers (6);
compose and drafts (4); keyboard shortcuts (3); Oversight's counts beyond the rail's held badge (5); the admin's sync page (10).

## Proof

`tests/phase3/slice2/run.sh`: scratch database `ml_dev2` (`tests/setup_dev.sh`), php -S on 9201, the fake kernel on 9202, the fake
MaluDB on 9203, the mail service on 9206, the IMAP fixture and the fake Mailbox MCP on ports the system gives them; then the same suite
under a user-level Apache on 9201 rendering `deploy/apache-malumail.conf`. Playwright from `/var/www/web/node_modules`.

1. **Sync** (`sync.py`, `mail-service.md` §13's list): 5,000 fixture messages; keys; `NOTIFY` ≤ 2 s; `IDLE` + `STATUS` fallback;
   another client's changes through `QRESYNC`; a `UIDVALIDITY` reset keeping labels, viewer reads and thread states by key; the text
   pass and `ml_search`; no `\Seen` in the fixture's `STORE` log.
2. **The wall** (`list.php`): the owner, a `triage` row, a `view` row, a department row (its people only — an agent of the department
   gets 404), a Mail admin (an agent's mailbox yes, a person's 404), an agent through `mail_list` under `ml_level_as()` (the Expert on
   a person's mailbox with `ai_read` off: `not_found`; on: its rows), a sensitive conversation hidden from an agent.
3. **The views**: each of the ten against fixtures built for it; scheduled and outbox rows from `outbound_email` fixtures; a snoozed
   conversation in Snoozed and not in the Inbox; the drafts view lists a phone's draft.
4. **Unread**: the owner's count is the server's; a `view` holder's and an intake mailbox's are their own (marking one read for one
   person leaves another's count alone, and nothing reaches the server).
5. **Paging**: keyset stable when 10 new conversations arrive mid-way (no row twice, none skipped); "1–50 of N"; page sizes 25/50/100;
   "more than 10,000" on a big fixture.
6. **The poll**: 204 with nothing changed (and the query under 10 ms); a delivered message → 200 with the "1 new" bar and the rail's
   count; a flag change by another client swaps the row in place; an expunge removes it; hidden-tab cadence 60 s, visible 15 s
   (the visibility state set by the test hook); the title "(n)" and the favicon badge.
7. **The access log**: a `view` holder's two list views within 30 minutes write one `mailbox.view`; the owner's write none.
8. **Screens at 375 and 1280** (screenshots): the rail off-canvas and fixed; rows, chips, badges, the bulk bar; empty states;
   no horizontal scroll; the axe check clean.
9. **Timings**: a list page of a 5,000-conversation mailbox under 100 ms; the rail with 30 mailboxes under 100 ms.
10. **MaluMail's test mailbox** (the owner's handoff file; a real credential, never a wrong one): the first sync of spike01; a message
    submitted from spike02 shows in spike01's Inbox list through the poll within 20 s; both mailboxes left as found.
11. The registry `--check`; Phase 0's suites (`tests/phase0/run.sh`, `tests/mailer/run.sh`, `tests/sanitiser/run.sh`) still green.

## Decisions taken here

1. **One gatherer, `ml_mail_list()`, behind the screens and the `mail_list` tool** — a list a person sees and the list their agent
   reads cannot disagree (the kernel's My Work rule).
2. **The poll keys on a per-mailbox counter, not on timestamps** — a counter cannot miss a change made in the same millisecond, and
   204 is one primary-key read.
3. **The bulk bar shows only actions whose handler exists** — the exemplar's way of shipping a list before its actions; no dead
   buttons.
4. **New mail never shifts the page under a person**: it waits behind the "N new" bar (Gmail's behaviour; R3's stable paging).
5. **Page size is a mailbox setting** (`page_size`, 25/50/100), beside density and the conversation view, so one person's choice on a
   shared mailbox does not need a new table.
6. **Agent badges come from `outbound_email`**, not from headers, so a forged `X-OS-Agent` from outside never earns a badge.

## Open questions

- None.

## Changes this spec asks of the shared documents

- **db/013_list_and_sync.sql** — BUILT and proven (`db/proof/phase1_list.sql`, 75 checks): `ml_mail_list()`, `ml_mail_list_total()`, `ml_mailbox_counts()`, `ml_unread_count()` as above (GRANT EXECUTE to
  `malumail_rw` and `malumail_records_ro`); the change counter of `mail-service.md` (`mail_mailbox_changes`, `mail_threads.change_seq`,
  `ml_mailbox_touch()`, `mail_thread_tombstones`). Sketch of the Inbox branch:
  `SELECT … FROM mail_threads t CROSS JOIN LATERAL ml_thread_state(t.id) st WHERE t.mailbox_id = p_mailbox AND
  (SELECT f.id FROM mail_folders f WHERE f.mailbox_id = p_mailbox AND f.special_use = 'inbox') = ANY (t.folder_ids) AND
  st.snoozed_until IS NULL AND (t.last_message_at, t.id) < (p_before_at, p_before_id) ORDER BY t.last_message_at DESC, t.id DESC
  LIMIT p_limit` — after `IF ml_level(p_mailbox) = 'none' THEN RETURN; END IF;` and the sensitive filter for an agent.
- **Manifest**: `mail_mailbox_settings_save` gains `page_size (25 50 or 100)` (slice 7's action; the setting this slice reads).
- **Tool surface**: `mail_list`'s `view` gains `outbox` (it already serves `scheduled`); `mailbox_overview` answers
  `ml_mailbox_counts()`'s fields.
- **mcp/requirements.txt**: `imapclient==4.1.0` (accepted; the coordinator bumps it).
