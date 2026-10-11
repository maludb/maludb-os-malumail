# Slice 3 — Reading (THE SECOND EXEMPLAR)

2026-10-11 · **Phase 1, for the checkpoint.** A conversation opened, read safely and acted on: the conversation page, the sandboxed
body, attachments, images on request, the Original and the `.eml`; archive, move, trash, spam, not spam, star, read and unread, delete
forever, empty Trash; per-viewer read state; intake mailboxes; Gmail's keyboard; the access log's reading rows. **The second
exemplar**: every later slice that shows mail (writing's quotes, oversight's review page, search's results opening a conversation)
copies its page, its frame and its action handlers' shape. Design §3, §6.3, §6.4, §8, §9; `docs/build-specs/reading-and-rendering.md`
(the three layers and the agent's text — read it first). Prerequisites: Phase 2 (the shell, `ml_require_mailbox()`, the rail, the
record picker), slice 2 (the mail service's sync and `fetch`, the list this page is opened from; `docs/build-specs/mail-service.md`).

## Divergences from the design

- **Bulk actions act on a search too.** R5 and design §5's example *"archive everything from the newsletter"* need "all matching", and
  the manifest's bulk actions take only `thread[]`. They gain an optional `matching` (a search query; at most 1,000 conversations,
  §Changes). `thread[]` stops being required: one of the two is.
- **Trash and archive are undone to where Gmail would put things back**, not by a recorded origin: a received message returns to the
  Inbox, a message the mailbox sent returns to Sent (decision 2).
- **Mark unread marks the latest message unread**, as Gmail does; mark read marks the whole conversation read.

## Who may do what

| Who | May |
|---|---|
| `view` holder | read; open the Original and the `.eml`; mark read and unread **for themselves** (`mail_viewer_reads`) — never a change to the mailbox |
| `triage` holder, owner | the above on the mailbox itself; archive, move, trash, spam, not spam, star, delete forever from Trash or Spam, empty Trash or Spam; mark a conversation sensitive (a person) |
| `full` holder (a person) | the above; always show images from a sender (`mail_images_allow`) |
| an agent | the actions marked `yes` at its level; `mail_delete_forever` and `mail_trash_empty` **pause** for a super-admin (the kernel's hook); never `mail_images_allow` or `mail_sensitive_set`; never reads a conversation marked sensitive |
| an intake mailbox (`intake_owner ≠ none`) | everyone's read state is per person; **nothing is marked `\Seen`**; its INBOX's messages are never moved, archived, trashed, marked spam or expunged (`ml_mutation_refusal()` answers why) |
| a suspended mailbox | read only |

Every handler asks `ml_mutation_refusal(mailbox, member, on_behalf, folder, op)` **before** calling the mail service, so the page can
say why; the mail service asks it again before the IMAP command.

## Screens

### `thread-view` — `/m/{mailbox}/t/{thread}` (375 first)

- **Header**: the subject (fenced in the page's escaping like every string from mail), the labels as chips, and the actions: Archive
  (`e`), Delete (`#`), Spam (`!`), Move (`v`), Label (`l`), Snooze (`b`), Mute, Mark unread (`Shift+U`), Print, and **Take over** on an
  agent's mailbox for an overseer. A button whose action is another slice's appears only once that action is built (`ml_action_built()`,
  the registry's `built` flag).
- **The messages**, oldest first. **Earlier ones are collapsed** to one line (from, date, snippet); the latest unread and the last are
  open. A collapsed one opens with `hx-get` of its partial. An open message shows: from (name, address), to/cc, date, the banners
  (reading-and-rendering §5), the body (the sandboxed frame, or the escaped text), quoted text folded behind "…", the attachments, and
  per-message Reply, Reply all, Forward (slice 4 — shown when built), Show original.
- **Show images** reloads one frame with `?images=1`; **Always show from {sender}** posts `mail_images_allow` (a `full` person; hidden
  otherwise; *"only for mail that proves its sender"* under it).
- **Opening marks read** by `ml_read_mode()`: `store_seen` → the mail service's `mutate` `seen` on the conversation's unread messages
  in the folders shown; `viewer_read` → `ml_viewer_read()` for each. An agent's view (none: agents have no screens) — `thread_read`
  never marks read.
- **States**: empty — none (a conversation has at least one message; one that emptied answers *"This conversation moved or was
  deleted"* with a link back to the list); the mail service down — the index's header lines and *"The message body is not available
  right now"*, no frame; `{thread}` stale after a rebuild — 410 *"This conversation moved — reload the list"*.
- **At 1280**: the same column, max-width 900 px, the actions as a toolbar; at 375 the actions collapse to Archive, Delete and a
  "More" menu (a `<details>`, not a modal).
- **The keyboard** (design §9; on unless the person switched shortcuts off in mailbox settings): `j`/`k` next/previous conversation
  (the list's order, kept in the page's `data-next`/`data-prev`), `u` back to the list, `e`, `#`, `!`, `s` star, `Shift+I`/`Shift+U`,
  `v` move, `l` label, `b` snooze, `r`/`a`/`f` (slice 4), `g i` Inbox, `?` the list of shortcuts (a page section toggled, not a modal).
  `html/assets/mail/keys.js`, ignoring keys while focus is in a field.

### `message-original` — `/m/{mailbox}/msg/{message}/original` (375 first)

The headers as a two-column list, then the source escaped in a `pre-wrap` block cut at 1 MB, and **Download .eml**
(`/m/{mailbox}/msg/{message}/raw`). Level `view`. A non-owner's open writes `message.raw` once (the raw door) or `message.open`
(this page).

## Files (exactly these)

| File | What |
|---|---|
| `html/m/thread.php` | the conversation page (GET), its message partial (`?message=` with `HX-Request`) |
| `html/m/body.php` | the sandboxed body (reading-and-rendering §2) |
| `html/m/original.php`, `html/m/raw.php` | the Original; the `.eml` |
| `html/p.php`, `html/img.php` | the part door and the image door's front (reading-and-rendering §3, §4) |
| `html/mail/archive.php`, `move.php`, `trash.php`, `spam.php`, `not-spam.php`, `flag.php`, `mark-read.php`, `delete-forever.php`, `trash-empty.php`, `images-allow.php`, `sensitive.php` | the eleven actions |
| `app/features/mail/reading.php` | the queries and helpers below |
| `app/features/mail/links.php` | `ml_link_sign()`, `ml_link_verify()` for `/p/` and `/img/` |
| `app/views/mail/thread.php`, `message.php`, `original.php`, `banners.php`, `attachments.php` | templates |
| `html/assets/mail/keys.js`, `frame.js`, `remote-image.svg` | the keyboard; the frame's height listener; the placeholder |
| `services/mailer/extract.py` | the agent's text (reading-and-rendering §6) |
| `services/mailer/sanitise.py` | the link-host addition (reading-and-rendering §1) |
| `services/images/door.py` | the image door |
| `db/012_phase1.sql` (shared, reconciled) | `ml_images_allow()`, the correspondents' columns, `ml_thread_keys()` |
| `tests/phase3/slice3/run.sh`, `browser.mjs`, `door.py` | the proof |

## Query functions and mail-service calls (signatures fixed)

PHP (`app/features/mail/reading.php`):

- `ml_thread_page(int $mailbox, int $thread): ?array` — the conversation's messages from `mcp_messages` (as the person), states from
  `ml_thread_state()`, labels from `mcp_message_labels`; `null` → 404.
- `ml_thread_targets(int $mailbox, array $threads, ?string $matching, string $op): array` — the message rows an action touches:
  `threads` resolved through `mcp_threads` (404 for one the person cannot open), or `matching` through `ml_search()` (at most 1,000
  conversations), filtered by the op's rule (archive: Inbox only; spam: received only; not_spam: Junk only; delete_forever: Trash
  and Junk only; move/trash: all but Drafts, and move leaves Sent). Answers `[[folder_id, uidvalidity, uid, message_key_id], …]`.
- `ml_mail_act(int $mailbox, string $op, array $targets, array $args = []): array` — asks `ml_mutation_refusal()` per folder, then
  the mail service's `mutate` (one call per op, the targets grouped by folder), and answers `{count, refused: [{reason, count}]}`.
- `ml_open_marks(int $mailbox, array $keys): void` — `ml_read_mode()` then `mutate seen` or `ml_viewer_read()`.

The mail service (`mail-service.md`, consumed by name):

- `fetch {mailbox, folder, uidvalidity, uid, form: html|text|headers, images: ask|show, member}` → the `Sanitised` fields, the
  header summary and the part list (reading-and-rendering §Changes).
- `part {mailbox, folder, uidvalidity, uid, part, member}` → a stream. `raw {…, member}` → a stream.
- `mutate {mailbox, op, targets: [[folder, uidvalidity, uid]], to_folder?, member, on_behalf?}` with ops `seen`, `unseen`, `flag`,
  `unflag`, `move`, `archive`, `trash`, `spam`, `expunge`, `delete_forever`; it creates **Archive** by name when absent (research 04
  §B) and records it as `special_use = 'archive'`; it updates the index in the same request; it answers `moved` for a stale
  UIDVALIDITY and the refusal sentence for a refused op.

SQL (db/012):

- `ml_images_allow(p_mailbox bigint, p_address text, p_by bigint, p_on boolean) RETURNS void` — `ml_require_person(p_by)`, level `full`.
- `ml_thread_keys(p_thread bigint) RETURNS bigint[]` — the conversation's message keys (the anchors for states and labels).

## MCP tools this slice makes real

`thread_read`, `message_read`, `message_headers`, `find_attachments`, `attachment_read`, `find_threads`.

## Manifest rows claimed

Screens (2): `thread-view`, `message-original`
Actions (11): `mail_archive`, `mail_move`, `mail_trash`, `mail_spam`, `mail_not_spam`, `mail_flag`, `mail_mark_read`, `mail_delete_forever`, `mail_trash_empty`, `mail_images_allow`, `mail_sensitive_set`

## Activity log events

| Event | When | `after` carries |
|---|---|---|
| `message.open` | a conversation opened by anyone but the owner (`ml_access_should_log`) | `thread_id`, message count |
| `message.part` | a part fetched by anyone but the owner (once per part per hour) | `message_key_id`, `part`, type, size |
| `message.raw` | the `.eml` or the Original by anyone but the owner | `message_key_id` |
| `message.archive`, `message.move`, `message.trash`, `message.spam`, `message.not_spam`, `message.flag`, `message.mark_read` | each action, **one row per request** with the count | count, `to_folder` (move), `matching: true` when by search |
| `message.delete_forever`, `trash.empty` | each | count, folder |
| `sender.images_allow` | each | the sender's **domain** only, on/off |
| `thread.sensitive` | each | count, on/off |
| `mcp.read` | a records-MCP read of a mailbox not the caller's own (`thread_read` and friends) | the tool's name |

Never a subject of a person's mailbox, never a body (design §6.6).

## Requirements claimed

MUSTs (4): `R5`, `R6`, `R15`, `R34`

## Out of scope for this slice

Reply, reply all and forward (slice 4 — the buttons appear when built); labels, snooze, folders (slice 6 — likewise); take over and
mute (slice 5); the list and the poll (slice 2); retention of Trash and Spam (Extended, R56); spam training (MM6); a link interstitial
(Extended).

## Proof (`tests/phase3/slice3/run.sh`: scratch `ml_dev3`; php -S on 9201, the fake kernel on 9202, the fake MaluDB on 9203, the mail service on 9206, the image door on 9207 under its socket stand-in; then the user-level Apache on 9201; the IMAP fixture grown with `FETCH BODY.PEEK[]`/`BINARY.PEEK[n]<…>`/`BINARY.SIZE`, `UID STORE`, `UID MOVE` with `COPYUID`, `UID EXPUNGE`, `CREATE`; Playwright from /var/www/web/node_modules)

1. The page: a person opens a conversation from the benign corpus (`tests/sanitiser/corpus/benign/`) at 375 and 1280; earlier messages
   collapse; the frame has exactly `allow-popups allow-popups-to-escape-sandbox allow-scripts`; the height line sizes it; no console
   error; the banners for an outside sender, a mismatch, a failed DMARC, a Reply-To elsewhere and a deceptive link appear on fixtures
   built for each.
2. The body's headers byte for byte; a raw DOMPurify payload pushed through `body.php`'s wrapper in a test build runs no script
   (the sanitiser proof's layer 2, here through the real door).
3. The part door: a `cid:` logo shows; an attachment downloads with its name; an HTML attachment downloads and never renders; 404 for
   an unsigned token, an expired one, a forged one, one for another mailbox, and one whose member lost the mailbox.
4. Images: the placeholder by default; Show images loads through `/img/` from the door's test-allowed fixture; the 1×1 pixel never
   loads; Always show applies to a DMARC-passed message from that sender and not to a forged one; the image door's refusals
   (reading-and-rendering §7).
5. Actions, each through the page and in JSON mode with an action token: archive moves only Inbox messages to a created Archive;
   undo puts them back; move leaves Sent copies; trash then undo returns received mail to the Inbox and sent mail to Sent; spam and
   not spam; star and unstar; mark read and unread; delete forever only from Trash; empty Trash with its count; `matching:` archives
   every conversation a search finds and no other; the fixture's state agrees with the index after each.
6. The walls: a `view` holder's mark read writes `mail_viewer_reads` and never a `\Seen` (the fixture's STORE log is empty); on an
   intake mailbox no `\Seen` is ever stored and archive, trash, spam, move and delete forever of an INBOX message are refused with
   the sentence; an agent's `mail_delete_forever` pauses (the kernel's hook, faked); an agent's `mail_images_allow` and
   `mail_sensitive_set` are refused; an agent's `thread_read` on a sensitive conversation answers `not_found`.
7. The access log: a delegate's open, part, raw and an agent's `thread_read` each write their row once; the owner's write none.
8. `thread_read`: Gmail, Outlook and Apple quotes cut; the signature cut; the newsletter's preheader flagged; links with hosts; the
   fence intact around a text holding `</untrusted-mail>`.
9. **The test mailbox at MaluMail** (design §10 Phase 3), when the owner's handoff credential file is present (else one line says it was skipped): spike02
   sends spike01 one HTML message with an inline image and an attachment; spike01's conversation opens, the `cid:` image and the
   attachment come through the part door, archive and undo move it on the real server, and the message is expunged at the end.
   Only the stored passwords are used — never a wrong one.
10. `php bin/build_action_registry.php --check` green; the eleven actions `built`.

## Decisions taken here

1. **`matching` on the bulk actions** (Divergences): R5's "all matching a search" and the Expert's "archive everything from X" need it.
   Capped at 1,000 conversations a request so one call stays one transaction-sized job.
2. **Undo puts mail back by kind, not by record**: received → Inbox, sent → Sent. A recorded origin would need a table for a
   ten-second toast; the kind rule is what Gmail's users expect.
3. **Move leaves Sent copies where they are**, so a conversation stays whole; trash takes every message but drafts.
4. **Archive is a folder named `Archive`**, created by the mail service when absent and recorded with `special_use = 'archive'`.
5. **Mark read reads the whole conversation; mark unread un-reads the latest message** (Gmail).
6. **A button for another slice's action appears only when that action is built** — the page is honest at every step of the build.

## Open questions

- None.

## Changes this spec asks of the shared documents

- **Manifest**: `mail_archive`, `mail_move`, `mail_trash`, `mail_spam`, `mail_not_spam`, `mail_flag`, `mail_mark_read` — `**thread[]**`
  becomes `thread[]`, and each gains `matching` (a search query — every conversation it finds up to 1000) as the last parameter.
  (Slice 6's `mail_label` gains the same.)
- **db/012**:
  ```sql
  ALTER TABLE mail_correspondents ADD COLUMN images_always boolean NOT NULL DEFAULT false,
      ADD COLUMN images_set_by bigint REFERENCES members(id) ON DELETE SET NULL, ADD COLUMN images_set_at timestamptz;
  CREATE FUNCTION ml_images_allow(p_mailbox bigint, p_address text, p_by bigint, p_on boolean) RETURNS void
      LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
  BEGIN
      PERFORM ml_require_person(p_by);
      IF ml_mailbox_level(p_mailbox, p_by) <> 'full' THEN RAISE EXCEPTION 'Showing images always is set by someone with full on the mailbox' USING ERRCODE = 'insufficient_privilege'; END IF;
      INSERT INTO mail_correspondents AS c (mailbox_id, address, images_always, images_set_by, images_set_at)
      VALUES (p_mailbox, lower(btrim(p_address)), p_on, p_by, now())
      ON CONFLICT (mailbox_id, address) DO UPDATE SET images_always = p_on, images_set_by = p_by, images_set_at = now();
  END$$;
  CREATE FUNCTION ml_thread_keys(p_thread bigint) RETURNS bigint[] LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS
      $$ SELECT COALESCE(array_agg(DISTINCT message_key_id), '{}') FROM mail_messages WHERE thread_id = p_thread $$;
  ```
  `mcp_correspondents` appends `images_always`.
- **maludb-os.json**: `ML_LINK_KEY` (reading-and-rendering §Changes).
- **The IMAP fixture** (`tests/fixtures/imap_server.py`) grows by the commands named in the proof's heading — mail-service.md's slice 2
  growth comes first; this slice adds `BINARY.PEEK`/`BINARY.SIZE`, `UID MOVE` with `COPYUID`, `UID EXPUNGE` and `CREATE`.
