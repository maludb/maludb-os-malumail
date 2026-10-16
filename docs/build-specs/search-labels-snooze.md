# Slice 6 — Search, labels, snooze, folders and templates

2026-10-11 · **Phase 1, for the checkpoint.** Finding mail with Gmail's operators (one mailbox, or every mailbox a person can open),
labels on conversations, snoozing a conversation out of the Inbox until a time, a mailbox's IMAP folders, and canned replies. Not an
exemplar: it copies slice 2's list for results and label views, slice 3's action handlers for labelling and snoozing, and slice 1's
CRUD screens for folders, labels and templates. Design §6.3 (`ml_search`, labels on message keys, `mail_thread_states`), §6.5 (the
worker's snooze pass), §7 (questions 3, 8, 18), §9. Prerequisites: slices 2 and 3.

## Divergences from the design

- **An unknown operator is searched as words, as Gmail does** (`ml_search` already does). The tool surface's `bad_query` answers only
  a malformed *value* — a date, a period, a size; the page and the tool name the operators they did not know (decision 1).
- **A woken conversation comes back unread** at the top of its folder's unread, not re-dated: the list orders by the last message's
  time, and a snooze's wake changes no message's date. The conversation carries a "back from snooze" chip until opened (decision 4).
- **A folder with mail in it is not deleted.** IMAP's `DELETE` takes a folder's messages with it, which Gmail's users would never
  expect from "delete a label". The page says *"Move or delete its N messages first"* (decision 5).

## Who may do what

| Who | May |
|---|---|
| any reader (`view`) | search the mailboxes they can open; open a label's view; see folders and labels |
| `triage` | label and unlabel; snooze and unsnooze; create, rename and delete labels and folders (system folders keep their names) |
| any Mail user | their own canned replies; a shared one (everyone using MaluMail reads it); edit or delete only their own — or any, as a Mail admin |
| an agent | `mail_label`, `mail_snooze`, `mail_unsnooze`, folders and labels at its level; `mail_folder_delete` pauses; never templates; a search never finds a sensitive conversation (`ml_search`) |
| an intake mailbox | its INBOX's messages are never snoozed (`ml_mutation_refusal` 'snooze'); labels are fine (they live in MaluMail's index only) |

## Screens

All at 375 first; the result lists are slice 2's list partial.

- **`search`** — `/m/{mailbox}/search?q=` — the search box filled; a line *"Searched {mailbox} — Trash and Spam left out; add
  `in:anywhere` to include them"*; results as conversations (one row per conversation, its best-matching message's snippet, the
  matched messages counted); cursor paging by `before`; *"Unknown operators searched as words: foo:"* when any; an operator help
  section under a `<details>`. A non-owner's search writes `mailbox.view` (the access log, once per half hour). Empty: *"No mail
  matches {q}"* with the help open.
- **`search-all`** — `/search?q=` — the same over every mailbox the person can open, each row naming its mailbox; the shell's search
  box lands here when no mailbox is current.
- **`label-view`** — `/m/{mailbox}/label/{label}` — conversations carrying the label (any of their messages); the label's colour in the
  header. Empty: *"Nothing has this label yet."*
- **`folders`** — `/m/{mailbox}/folders` — the IMAP folders with total and unread, system ones marked (Inbox, Sent, Drafts, Junk,
  Trash, Archive, Snoozed — not renameable or deletable); a form to create one (name, under an existing folder — the server's
  delimiter is `.`); rename inline (a form per row, `hx-post`); delete with `hx-confirm`, refused for a non-empty folder.
- **`labels`** — `/m/{mailbox}/labels` — the labels with colour and conversation count; create (name — a `/` nests: *Work/Clients* —
  and colour from eight swatches); rename, recolour; delete with `hx-confirm` *"Remove the label from N conversations?"* — the mail
  stays.
- **`templates`** — `/templates` — my canned replies and the shared ones as cards (name, subject, the first line, shared or mine,
  used N times); New; a card opens its edit page. Empty: *"No canned replies yet — write one for answers you give often."*
- **`template-new`** — `/templates/new` — name, subject (optional), body (the plain editor), Shared with everyone using MaluMail.
- **`template-edit`** — `/templates/{template}/edit` — the same, and Delete (`hx-confirm`). A shared template someone else owns opens
  read-only with *"Owned by {name}"* unless the person is a Mail admin.

**Labelling and snoozing from the list and the conversation**: the bulk bar's Label (`l`) and Snooze (`b`) open a small form below the
bar (not a modal): labels as checkboxes with a filter; snooze as *Later today (18:00), Tomorrow (08:00), This weekend (Sat 08:00),
Next week (Mon 08:00), Pick a date and time* in the person's time zone (`members.timezone`).

## Files (exactly these)

| File | What |
|---|---|
| `html/m/search.php`, `html/search.php` | the two searches |
| `html/m/label.php`, `html/m/folders.php`, `html/m/labels.php` | label view, folders, labels |
| `html/templates/index.php`, `form.php` | the templates' list; new and edit |
| `html/mail/label.php`, `snooze.php`, `unsnooze.php`, `folder-create.php`, `folder-rename.php`, `folder-delete.php`, `label-create.php`, `label-update.php`, `label-delete.php` | nine actions |
| `html/templates/save.php`, `delete.php` | two actions |
| `app/features/mail/search.php`, `labels.php`, `folders.php`, `templates.php` | queries |
| `app/views/mail/search.php`, `label.php`, `folders.php`, `labels.php`, `label-form.php`, `snooze-form.php`; `app/views/templates/list.php`, `form.php` | templates |
| `bin/worker.php` | the `snooze_wake` pass (added) |
| `tests/phase3/slice6/run.sh`, `browser.mjs` | the proof |

## Query functions and mail-service calls (signatures fixed)

SQL (db/008, extended by **`db/014_reading_search.sql`** — built, proven by `db/proof/phase1_reading.sql`):

- **`ml_search(p_q text, p_mailbox bigint, p_limit integer, p_before timestamptz, p_before_id bigint DEFAULT NULL)`** — the cursor:
  `(received_at, id) < (p_before, p_before_id)` (`received_at < p_before` without the tie-breaker); the first page passes
  `NULL::timestamptz`. Every argument but the tie-breaker is required, so it is never ambiguous with **db/008's offset form,
  `ml_search(p_q, p_mailbox DEFAULT NULL, p_limit DEFAULT 50, p_offset DEFAULT 0)`, kept** for its callers. Both run one body,
  `ml_search_core()` (executable only through them). New terms: `is:snoozed`, `is:muted`, `has:label`, `has:nolabel`, and a
  **per-person** `is:unread`/`is:read` for a `viewer_read` reader (their `mail_viewer_reads`; the mailbox's own Sent and Drafts are
  never unread to anyone). Fixed on the way: a negated term is `NOT COALESCE(…, false)` — db/008's `-from:x` dropped every message
  with no display name.
- `ml_search_unknown(p_q text) RETURNS text[]` — the unknown operators (`foo:`) and unknown `is:`/`has:` values (`is:weird`) a query used.
- `ml_label_apply(p_mailbox bigint, p_keys bigint[], p_tag bigint, p_by bigint, p_remove boolean) RETURNS integer` — level `triage`
  via `ml_mutation_refusal(…, 'label')`, the tag in the same mailbox; inserts or deletes `record_tags` for every key; answers the count.
- `ml_label_save(p_mailbox bigint, p_tag bigint, p_name text, p_colour text, p_by bigint) RETURNS bigint` — create (p_tag NULL) or
  update; the slug from the name (`lower`, accents off, spaces to `-`, `/` kept); unique per mailbox; colour `#rrggbb`. An agent never
  labels a conversation marked sensitive (`ml_label_apply` answers *no such message*).
- `ml_label_delete(p_mailbox bigint, p_tag bigint, p_by bigint) RETURNS integer` — the conversations it was on.
- `ml_template_save(p_template bigint, p_name text, p_subject text, p_body text, p_shared boolean, p_by bigint) RETURNS bigint` and
  `ml_template_delete(p_template bigint, p_by bigint)` — the owner, or a Mail admin for a shared one; `ml_require_person(p_by)`;
  delete archives. They are read through db/012's `mcp_templates` (the caller's own and the shared ones).
- `ml_thread_snooze()` and `ml_snoozes_due()` (db/010) as they are; `ml_thread_keys()` (slice 3).

PHP:

- `ml_search_page(?int $mailbox, string $q, ?string $before, int $limit = 50): array` — `ml_search()` rows grouped into conversations
  (best rank, then latest), the unknown operators, the next cursor.
- `ml_snooze_choices(string $tz): array` — the five times above, as UTC instants.

The mail service (`mail-service.md`): `mutate` ops `snooze` (the conversation's Inbox messages to **Snoozed**, created by name when
absent and recorded `special_use = 'snoozed'`), `move` (unsnooze and the wake: Snoozed → the folder named in `snoozed_from_folder`,
then `unseen` on the latest message), `folder_create`, `folder_rename`, `folder_delete` (refused unless the folder is empty).

**The worker's `snooze_wake` pass** (every minute, `worker_passes`): `ml_snoozes_due()` → for each, the mail service's `mutate move`
back and `unseen` on the latest message; a notice of the existing kind `snooze_woke` to the person who snoozed it (slice 9's preferences decide whether it mails);
**on an agent's mailbox, an `agent_dispatches` row** so the agent gets its follow-up turn (design §5 (b), §Changes).

## MCP tools this slice makes real

`mail_search`, `find_folders`, `find_labels`, `find_templates`, `records_search`.

## Manifest rows claimed

Screens (8): `search`, `search-all`, `label-view`, `folders`, `labels`, `templates`, `template-new`, `template-edit`
Actions (11): `mail_label`, `mail_snooze`, `mail_unsnooze`, `mail_folder_create`, `mail_folder_rename`, `mail_folder_delete`, `mail_label_create`, `mail_label_update`, `mail_label_delete`, `mail_template_save`, `mail_template_delete`

## Activity log events

| Event | `after` carries |
|---|---|
| `message.label` | count, the label's id, removed or not |
| `thread.snooze`, `thread.unsnooze` | count, the wake time |
| `folder.create`, `folder.rename`, `folder.delete` | the folder's id (never its name on a person's mailbox) |
| `label.create`, `label.update`, `label.delete` | the label's id, count on delete |
| `template.save`, `template.delete` | the template's id, shared or not |
| `mailbox.view` | a non-owner's search (the access log, once per half hour) |

## Requirements claimed

MUSTs (3): `R4`, `R13`, `R15`

## Out of scope for this slice

Saved searches; labels mirrored to IMAP keywords (Extended, §11); filters that label arriving mail (Sieve, MM4); templates per mailbox
(the column exists, unused in v1); Gmail's category tabs.

## Proof (`tests/phase3/slice6/run.sh`: scratch `ml_dev6`; php -S on 9201, the fake kernel 9202, the fake MaluDB 9203, the mail service 9206; then the user-level Apache; the IMAP fixture with `CREATE`, `RENAME`, `DELETE`, `UID MOVE`, `UID STORE`; a seeded index of 2,000 synthetic messages in three mailboxes; Playwright)

1. Every operator of `ml_search` against the seeded index, each with and without `-`: `from:` `to:` `cc:` `bcc:` `subject:` `label:`
   `filename:` `has:attachment` `has:label` `has:nolabel` `is:unread|read|starred|answered|snoozed|muted` `in:inbox|sent|trash|spam|archive|snoozed|anywhere`
   `after:` `before:` `older_than:` `newer_than:` `larger:` `smaller:`, a phrase, words; Trash and Spam left out by default; the cursor
   pages without a repeat or a gap across 2,000 results; an unknown operator searched as words and named; `before:2026-13-40` →
   `bad_query`.
2. The wall: a delegate's search finds only their mailboxes; `search-all` names each result's mailbox; an agent never finds a
   sensitive conversation; `is:unread` for a `view` holder follows their own reads.
3. Labels: create *Work/Clients*; label three conversations from the list and one by `matching`; the label view shows four; a new
   message arriving in a labelled conversation keeps it in the view; rename, recolour; delete removes it from every conversation and
   the mail stays.
4. Snooze: *Tomorrow 08:00* in Europe/Berlin is stored as the UTC instant; the conversation leaves the Inbox for a created **Snoozed**
   folder on the fixture; the worker (clock moved) brings it back, unread, with the chip; unsnooze does the same at once; an intake
   mailbox's INBOX refuses; an agent mailbox's wake writes a dispatch.
5. Folders: create *Projects.Acme*, rename, delete when empty; a non-empty one refused with its count; Inbox and Sent refuse rename
   and delete; an agent's `mail_folder_delete` pauses.
6. Templates: a person writes one and a shared one; another person reads the shared one read-only; a Mail admin edits it; an agent's
   `mail_template_save` is refused.
7. 375 and 1280 for every screen; `--check` green; eleven actions built.

## Decisions taken here

1. **Unknown operators are words** (Gmail), and are named to the person and the tool; `bad_query` is for malformed values only.
2. **Labels apply to a conversation's existing message keys**, and a conversation shows any label of any of its messages — so it stays
   labelled when a reply arrives, without a thread-level table (threads are rebuildable; nothing keys on them).
3. **Labels live in MaluMail's index only** in v1; phones see folders, not labels (keywords are Extended).
4. **A wake un-reads the latest message and shows a chip**; the conversation is not re-dated (Divergences).
5. **A non-empty folder is not deleted** (Divergences); emptying it is a move or a delete forever the person chooses.
6. **Snooze times are offered in the person's time zone** and stored as instants; "This weekend" on a weekend means next Saturday.
7. **A shared template is everyone's to read** (canned text carries no mail) and its owner's or a Mail admin's to change.
8. **A snooze on an agent's mailbox wakes the agent** with one turn when it ends — that is what an agent snoozes for.

## Open questions

- None.

## Changes this spec asks of the shared documents

- **Manifest**: `mail_label` — `**thread[]**` becomes `thread[]` and it gains `matching` (as slice 3's bulk actions).
- **The tool surface**: `mail_search` — `bad_query` only for a malformed date, period or size; the answer carries `unknown_operators`;
  the operator list gains `has:label`, `has:nolabel`, `is:muted`, `is:read`, `is:answered`, `bcc:`, `filename:`.
- **`db/014_reading_search.sql`** — BUILT and proven (`db/proof/phase1_reading.sql`): the cursor form of `ml_search` beside the
  offset form, the new terms, the negation fix, `ml_search_unknown()`, `ml_label_apply/_save/_delete()`,
  `ml_template_save/_delete()`. db/012 already holds `mcp_templates` and the `snooze` dispatch kind; a wake's notice is the existing
  `snooze_woke`.
- **The IMAP fixture**: `CREATE`, `RENAME`, `DELETE` (deleting the folder's mail with it, as Dovecot does — the mail service refuses a
  non-empty folder before it asks).

## Built and proven (2026-10-16)

**Built:** the eight screens (`search`, `search-all`, `label-view`, `folders`, `labels`, `templates`, `template-new`, `template-edit`) and the eleven actions
(`mail_label`, `mail_snooze`, `mail_unsnooze`, `mail_folder_create`, `mail_folder_rename`, `mail_folder_delete`, `mail_label_create`, `mail_label_update`,
`mail_label_delete`, `mail_template_save`, `mail_template_delete`) — the registry: 33 of 45 screens, 57 of 67 actions built, `--check` green. Files beyond the
spec's list: `app/views/mail/search-results.php` (the part Older swaps), `app/views/mail/partials/bar-panel.php` (the bulk bar's two panels, which hold
`label-form.php` and `snooze-form.php` — the spec's two templates, used by the conversation page too), `db/019_snooze_chip.sql`, `db/proof/slice6_woke.sql`.
The mail service gained `mutate` ops `snooze`, `folder_create`, `folder_rename`, `folder_delete` (`services/mailer/server.py`, `imap.py`, `store.py`); the IMAP
fixture `RENAME` and `DELETE`; the records queries `mail_search`, `find_labels`, `find_templates`, `records_search` (and `find_folders`, slice 2's, proven here);
the worker's `snoozes` pass and the `snooze` mail turn. The list's rows carry the "Back from snooze" chip, the rail lists the labels with links to Folders and
Labels, the conversation page has Label and Snooze beside its actions, `compose?template=` fills the composer.

**Proven** (`tests/phase3/slice6/run.sh`, php -S / Apache): checks 192 / 192, tools 31 / 31, browser 65 / 65; schema `db/proof/slice6_woke.sql` 12
(`tests/phase0/schema.sh`). The checks run every operator of `ml_search` with and without `-` against an independent SQL truth over 700 synthetic messages
(each result set equal, none empty), page the whole 1,614-conversation estate by cursor (each once, in the order its newest message came), prove the wall
(Dana's and Priya's search-all, an agent never finding a sensitive conversation, `is:unread` for a view holder following her own reads, `mailbox.view` once per half
hour), labels (create, three from the list and one by `matching`, the view's four, a reply keeping the label, rename, recolour, delete), snooze (Tomorrow 08:00 in
Europe/Berlin stored as its UTC instant, the Snoozed folder on the fixture, the worker's wake — unread, the chip, a `snooze_woke` notice —, unsnooze, an intake
mailbox refusing, an agent mailbox's wake as a dispatch and one turn carrying ids), folders (create under a parent, rename, delete when empty, refused with mail in it,
Inbox and Sent refusing, a view holder refused, `mail_folder_delete` paused in the registry), canned replies (own and shared, read-only for another person, a Mail
admin editing, an agent refused) and the log vocabulary (no label, folder, template or subject name in any payload). The browser proof walks every screen at 375 and
1280 (no sideways scroll, axe clean), the bulk bar's Label (the filter) and Snooze (a typed date clearing the choices) with no modal, and the conversation's own two.

**Found and fixed:**
- **No place to keep the "back from snooze" chip.** `ml_snoozes_due()` (db/010) cleared the snooze and kept nothing; `db/019` adds `mail_thread_states.woke_at`, re-creates
  `ml_snoozes_due()` to stamp it, and adds `ml_woke_threads()`, `ml_woke_clear()` (opening the conversation clears it) and `ml_thread_unsnooze()` (the same state change for
  one conversation, behind triage). Additive; proven by `db/proof/slice6_woke.sql`.
- **Two overloads of `ml_search`** (the offset form and the cursor form) made a bare parameter ambiguous (`function ml_search(unknown, bigint, integer, unknown) is not unique`):
  the PHP and the proofs cast the offset to `integer`.
- **A thread's subject is fixed when it is made**: a seeded message whose subject was changed afterwards did not take a reply into its conversation (`ml_thread_find()`
  starts a new one when the subject changed) — the proof's reply carries the thread's own subject.
- The first sync already makes Archive and Snoozed on every live mailbox (`watch.py`), so the service's create-by-name path for Snoozed is the fallback, not the first
  snooze (the proof asserts the folder and its `special_use`, not that this snooze made it).

**Decisions taken here (within the spec):**
1. **A search groups at the newest matching message** and walks from the newest each page with `ml_search`'s CURSOR form, 500 messages a statement (cap 5,000 messages, then a line saying to narrow it) — a conversation is never
   split across two pages, so the cursor is a position in the conversation order (`<µs>.<message id>`, µs because two messages of different mailboxes can share a millisecond).
2. **Snooze moves the mail first and keeps the state second**, putting the mail back if the state is refused; the state's anchor is the conversation's latest Inbox message.
3. **The wake acts as the person who snoozed** (the mailbox's owner when that person is gone); a service that cannot be reached puts the snooze back for five minutes.
4. **Label accepts one id or a list** (`label[]`) so the bar's checkboxes work; the registry's `label` stays one parameter for the tools. The bar's "Take off" is the
   same action with `removed`.
5. **Folder names**: `parent` is an existing folder's full name; a name may not hold the server's delimiter; a folder with subfolders is deleted only when empty on the
   server (IMAP leaves the subfolders), and the sentence is "Move or delete its N message(s) first".
6. **The search page's "Label every conversation this search finds"** is the `matching` path as a form (up to 1,000 conversations); the bulk bar's own "Select all N" posts
   slice 3's `all_matching`, which no handler reads yet — the proof uses `matching` directly, as slice 3's did.
7. **Canned replies' "Used N times"** shows the column; nothing increments it yet (a GET never writes; Send would).
8. **A shared canned reply's owner who left** reads "Owned by someone who left" and is changed by a Mail admin.

### Review fixes (2026-10-16, after the first report)
1. **"Select all N" acts on everything the view holds.** The bar's hidden field is `matching` (it was `all_matching`, which no handler read), filled by the script only when
   Select all is pressed, with the view's own search from `mail_view_matching()` (`app/features/mail/list.php`): Inbox `in:inbox`, Sent `in:sent`, Spam `in:spam`, Trash
   `in:trash`, Snoozed `is:snoozed`, a folder `in:"<name>"`, a label `label:<slug> in:anywhere` (a label view holds Trash and Spam too), each followed by ` is:unread` when the Unread-only filter is on. Starred, All mail, Drafts,
   Scheduled and Outbox have no exact equivalent: no "Select all N" button there. The 1,000-conversation cap and the `matching: true` log flag stay. `mail_snooze` and
   `mail_unsnooze` take `matching` too (manifest rows changed like `mail_label`'s). Proven in slice 6's checks section 9 on a live 60-message mailbox with 25 rows a page: Select all +
   Move empties the Inbox, Select all + Archive from the folder view empties the folder, Select all + Delete from a label view moves the whole label to Trash; the browser proof
   shows matching empty until Select all is pressed, `in:inbox` after, empty after Clear.
2. **The search walk, measured** (5,000-message mailbox `big4`, php -S, section 10 of the checks, a whole search paged to the end): with the offset walk, page 1 took 723 ms and a
   page past 1,000 conversations 515 ms for a common word (`plain`, 4,410 messages), 519 / 372 ms for `is:read`. The offset form re-sorted everything it skipped (339 ms of walk against
   143 ms for the cursor form) — and a JIT compile of 250 ms was hiding in the 50-row fetch (the planner prices `ml_thread_state()` at 1,000 rows a call). Now: the walk uses the cursor
   form and the page's fetch sets `jit = off` for its connection and reads the base tables the walled search already answered for: **`plain` page 1 391 ms, page 22 (past 1,050 conversations)
   356 ms, slowest 420; `is:read` 278 / 262 ms, slowest 376** — every page under 500 ms, none repeated. The walk is still the whole result set (up to 5,000 messages) because a
   conversation's matched count and its newest match need it; a page costs the result's size, not the page's.
3. **"Used N times" counts.** The composer carries the template's id (`template` hidden field, set by `compose?template=`); `send_answer()` bumps `email_templates.usage_count` once when the
   message is sent or scheduled or held (not refused), only for a template the sender sees (`ml_template_used()`); a plain UPDATE the application role already may make, no migration, and
   nothing an agent may newly do (an agent keeps no templates; one that names a visible shared id would count it, which is a counter and no content).
4. **The flaky slice 3 check.** `door.py` judged `mcp.read` with `o["log"] != "0"`, which a failed or empty `psql` read passes vacuously, and `reading.php` read the log once; both now
   require an integer of at least 1 and `reading.php` polls the log (bounded, 10 s) for the row. The write itself is in the records query's own transaction, committed before it returns: no code
   race was found; the flake was not reproduced in two further runs.
