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
