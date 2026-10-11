# Build spec: the mail service

2026-10-11 · **Phase 1, for the checkpoint.** The one process that speaks IMAP, SMTP and MaluMail's Mailbox MCP (design §6.1, §8's
door, D1). It holds every open mailbox credential, the sealing secret key and the customer key. It keeps the index (db/008) in step
with the server, fetches what a reader opens, makes every change to mail as one IMAP command, appends drafts, submits the outbox and
does the Mail admins' administration. Every slice that touches mail calls it; **PHP never speaks IMAP, SMTP or the Mailbox MCP** (R30).

This spec fixes the door's calls, the process and connection model, the sync, the text pass, fetches and parts, mutations, drafts,
quota, the status answer, the PHP client, the failure modes, the IMAP fixture's growth and the proof of each slice. **Submission and
the outbox** (MIME, the outbox pass, deferral, `\Sent` and `\Answered`) are `sending-and-oversight.md`'s; this spec names only the
door call. **The reading pages and the signed doors** (`/p/`, `/img/`, the body's response) are `reading-and-rendering.md`'s; this
spec fixes what the mail service answers them.

**Where things live.** `services/mailer/` (Python 3.12, the application's `mcp/venv`), run by `malumail-mailer.service` as the user
`malumail-mailer` on `127.0.0.1:ML_MAILER_PORT` (8854; proofs 9206). Libraries: IMAPClient (pinned below), the standard library's
`imaplib` underneath, `email` (`policy.default`), `smtplib`, nh3 (through `sanitise.py`), asyncpg, httpx, Starlette and uvicorn,
PyNaCl (R62). It writes the index as `malumail_rw` and writes nothing on disk.

## What Phase 0 built, and what each slice adds

| Module | Phase 0 (built, `tests/mailer/run.sh` 62 checks; `tests/sanitiser/run.sh` 50) | Added by |
|---|---|---|
| `server.py` | `/health`; `POST /v1/admin/{tool}`; `POST /v1/mailboxes/{id}/probe`; the sync and outbox passes, empty | each call below in its slice |
| `door.py` | `X-ML-Mailer: v1.<ts>.<nonce>.<hmac>`, 60 s, once | `on_behalf` read from the body (slice 2) |
| `config.py` | the environment; `ML_DEV_ENV`; `ML_TLS_CA_FILE` refused in production | `ML_PART_KEY` (3), `ML_MAILER_MAX_CONNECTIONS` (2) |
| `store.py` | rights read for the named member; the breaker's writes; health counts | the index writes (2), mutations (3, 6), drafts (4) |
| `seal.py` | sealed boxes (PyNaCl = PHP's `sodium_crypto_box_seal`) | — |
| `mailbox_mcp.py` | the admin allow-list, the served-domain wall, passwords sealed on arrival, fail closed | — (slice 1 confirms MaluMail's `password` field on the test domain) |
| `imap.py` | the TLS probe and what counts as `auth_failed` | the connection pool, the watcher, sync, fetch, mutate (2, 3, 4, 6) |
| `sanitise.py` | the sanitiser (three passes, a fixed point) | called by `fetch` (3) |
| new `index.py` | — | envelope parsing, message keys, the batch writes, the change counter (2) |
| new `watch.py` | — | one watcher thread per live mailbox: `NOTIFY`, else `IDLE` + the `STATUS` round (2) |
| new `textpass.py` | — | the text pass (2) |
| new `mime_read.py` | — | `BODYSTRUCTURE` walking, the parts, the text extraction (3) |
| new `drafts.py` | — | `append_draft` (4) |
| outbox | the pass, empty | `sending-and-oversight.md` (4) |

## 1. The door

Every call but `/health` is a signed `POST` (Phase 0's `door.py`, unchanged). The body is JSON:
`{"actor": <member id>, "on_behalf": <member id or null>, "args": {…}}`. `on_behalf` is the person behind an agent's run (K38's
`requested_by_member_id`), which the records MCP and the relayed action handlers pass; the signature covers it, so it cannot be added
by anyone without the key. **The service computes the caller's level itself**: `ml_level_as(mailbox, actor, on_behalf)` for reads,
`ml_mutation_refusal(mailbox, actor, on_behalf, folder, op)` before every change — never a level a caller states.

| Call (design §8's name) | Path | args | Answers | Needs | Slice |
|---|---|---|---|---|---|
| `admin` | `/v1/admin/{tool}` | the tool's | `mailbox_mcp.py`'s reply | the tool's right | 0 (built) |
| `test_login` | `/v1/mailboxes/{id}/probe` | — | capabilities, folders, INBOX's counts | `mailboxes.manage` | 0 (built) |
| `sync` | `/v1/mailboxes/{id}/sync` | `folder` (an id, optional), `full` (bool), `text` (bool: re-run the text pass) | `{queued: true}` — the watcher runs it | `settings.manage`, or `full` on the mailbox | 2 (Re-sync, Re-index: 10) |
| `status` | `/v1/mailboxes/{id}/status` | — | §9's answer | `view` on it, or `settings.manage` | 2 (shown in 10) |
| `status` (all) | `/v1/status` | — | `{mailboxes: [{mailbox_id, watcher, backoff_seconds, pool_connections, text_due}]}` — every mailbox the service knows, counts only | `settings.manage` | 10 |
| `fetch` | `/v1/mailboxes/{id}/fetch` | `message` (a key id) or `ref` (`folder.uidvalidity.uid`), `form` (`html` · `text` · `headers`), `images` (`ask` · `show`), `profile` (`reading` · `compose`; default `reading`) | §4: the sanitiser's fields, the header summary, the part list | `view` | 3 (`profile=compose`: 4) |
| `part` | `/v1/mailboxes/{id}/part` | `ref`, `part` (a section like `2` or `1.2`), `range` (`start-end`, optional) | the bytes, streamed, with `Content-Type`, `Content-Length` and the decoded size | `view` | 3 |
| `raw` | `/v1/mailboxes/{id}/raw` | `ref` | the message's bytes, streamed | `view` | 3 |
| `sanitise` | `/v1/sanitise` | `html`, `profile` (`compose` · `signature`) | `{html, text}` — the cleaned HTML and the text derived from it | `mail.use` | 4 |
| `identity_check` | `/v1/mailboxes/{id}/identity_check` | `address` | `{delivers: bool}` — whether that alias delivers to this mailbox (`list_aliases` under the customer key; the alias list itself never returned) | `full` | 4 |
| `mutate` | `/v1/mailboxes/{id}/mutate` | `op`, `messages` (key ids) or `threads` (ids), `to_folder` (an id), `name`, `parent` | `{done, count, moved: [{key, ref}], refused: [{key, why}]}` | `ml_mutation_refusal()` | 3, 6 |
| `append_draft` | `/v1/mailboxes/{id}/append_draft` | `draft` (a `mail_drafts` id), `discard` (bool: the delete half alone — expunge the draft's current copy) | `{ref}` of the new version (none after a discard) | `ml_mutation_refusal(…, 'draft')` | 4 |
| `quota` | `/v1/mailboxes/{id}/quota` | — | `{used_bytes, limit_bytes, read_at}` | `view` | 2 (shown in 10) |

**Internal to the service, never door routes** (the outbox loop calls them; `sending-and-oversight.md` §5.2): `submit_now` (SMTP
submission of one claimed row), `append_sent` (the Sent copy, with `Bcc`, `\Seen` and the submission time; then `filed_at`),
`store_flags` (`+\Answered` on the message answered — never `\Seen`), and the startup's **crash search** (§7).

A refusal answers as Phase 0's do, plus `409 moved` (a stale UIDVALIDITY or a message no longer where the request said: "this message
moved"; the folder is queued for a re-read) and `423 intake` (`ml_mutation_refusal`'s intake sentence). A mailbox the caller may not
open answers **404**, never 403 (design §3). Every streamed answer ends early with a truncated body and a logged warning if the IMAP
connection fails mid-stream; the PHP door turns it into a 502 for the browser.

## 2. Processes and connections

- **One asyncio loop** serves the door and runs the passes. IMAP work runs in threads (IMAPClient blocks): **one watcher thread per
  live mailbox** holding the long-lived connection, and **a pool of at most three connections per mailbox** for fetches and
  mutations, opened on demand and closed after five idle minutes. Dovecot allows ten per address; the service never exceeds four.
  A login costs 0.42–0.52 s (research 04 §B), so warm connections matter on the reading path.
- **A global cap** `ML_MAILER_MAX_CONNECTIONS` (default 400) bounds the threads; past it a fetch waits up to 10 s, then answers 503
  `busy`. Each connection holds about 1 MB; `MemoryMax=1024M` in the unit covers a few hundred live mailboxes.
- **Every login goes through one function** (`imap.login(mailbox)`) that opens the credential, logs in and applies Phase 0's rule:
  `auth_failed` trips the breaker (`store.trip_breaker`) and stops every connection of that mailbox; `busy`, `connect_failed` and
  `tls_failed` back off (30 s doubling to 30 min). **No login is ever attempted with a credential whose status is not `ok`.**
- **A per-mailbox lock** orders mutations and the watcher's folder re-reads, so a move and a resync never race; reads do not take it.
- **Which mailboxes are live**: those with `active`, an `ok` credential, no `suspended_at`, and `sync_state` not `paused` or
  `failed`. The sync pass (every `ML_MAILER_PASS_SECONDS`, 60) starts a watcher for each newly live mailbox and stops one whose
  mailbox left that state. On start the service starts every live mailbox's watcher, a few a second (MaluMail's abuse protection
  counts logins).
- **IMAPClient 4.1.0** (pinned in `mcp/requirements.txt` at slice 2, replacing Phase 0's 3.0.1), with `normalise_times=False`.
  The raw `imaplib` connection underneath carries what IMAPClient has no method for: `NOTIFY`, `SELECT … (QRESYNC …)`, `VANISHED`,
  `MOVE`'s `COPYUID` (research 04 §B).

## 3. Sync

**3.1 A new mailbox** (`sync_state` `new` → `syncing` → `live`):

1. `ENABLE QRESYNC`; `LIST "" "*" RETURN (SPECIAL-USE)` → `mail_folders` (name decoded from modified UTF-7, delimiter `.`,
   `special_use` from the flags; INBOX is `inbox`). **`Archive` and `Snoozed` are created by name when missing** (no special-use flag
   exists for them on MaluMail's server) and recorded as `archive` and `snoozed`.
2. `GETQUOTAROOT INBOX` → `quota_bytes`, `quota_used_bytes`.
3. **The envelope pass**, folder by folder — INBOX, Sent, Drafts, then the rest — newest first in batches of 500 UIDs:
   `UID FETCH (UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE MODSEQ PREVIEW BODY.PEEK[HEADER.FIELDS (Date From Sender To Cc Bcc
   Reply-To Subject Message-ID References In-Reply-To List-Id List-Unsubscribe Auto-Submitted Precedence X-Spamd-Result
   X-Spam-Status Return-Path Received)])`. **Every fetch is a PEEK.** 837 messages a second on MaluMail's server.
4. Each batch is written in one transaction with `app.ml_bulk = 'on'` (no threading per row), then **`ml_threads_rebuild(mailbox)`
   runs once after INBOX** (so the Inbox is usable within a minute of a 5,000-message mailbox) **and once at the end**. 5,000
   messages rebuild in 3.5 s.
5. `sync_state` → `live`, `last_sync_at`; the folder's `uidvalidity`, `uidnext`, `highestmodseq`, counts, `synced_at`.

**3.2 One message into the index** (`index.py`):

- Headers parsed with `email.parser.BytesHeaderParser(policy=default)`; addresses lower-cased into the arrays, names into
  `recipient_names`. `received_at` = `INTERNALDATE` in UTC; `sent_at` = `Date` when it parses.
- **The message key**: `ml_envelope_digest(date, from, to, cc, subject)` computed in Python the same way (a proof compares the two),
  then `ml_message_key_for(mailbox, message_id, digest)` — so a forged duplicate `Message-ID` inherits nothing (db/008).
- `dmarc_pass` from `DMARC_POLICY_ALLOW` in `X-Spamd-Result` (NULL when the header is absent); `spam_score` from its first figure;
  `auto_submitted`, `is_list` (`List-*`, or `Precedence` bulk, list or junk), `return_path_null`.
- **`local_submission`** is true only when the message has **no** `X-Spamd-Result`, **no** `Authentication-Results`, and its
  topmost `Received` line was added by MaluMail's own server `with ESMTPSA` (the host named in `ML_IMAP_HOST`'s domain). It proves
  an internal sender only once `ml_settings.mm3_identity_enforced` is on (db/010; research 04 §B).
- `has_attachments` and `attachment_names` from `BODYSTRUCTURE`: a part with `Content-Disposition: attachment`, or a non-text leaf
  with a file name. `snippet` = `PREVIEW` (≤ 200 characters).
- Correspondents: `ml_correspondent_received(mailbox, from, name, received_at)` for mail in, from the INBOX and other non-Sent
  folders; Sent's recipients are counted by the outbox, not by sync (only a person's send makes an address known — db/008).
- After a non-bulk write, **`ml_dispatch_decide(message)`** for a new INBOX message of an agent's mailbox (slice 5 fills the
  worker's dispatch pass; slice 2 makes the call and leaves the decision in `agent_dispatches`).

**3.3 Staying in step** (`watch.py`, R32, R33):

- The watcher connection: `SELECT INBOX (QRESYNC (uidvalidity highestmodseq))`, then
  `NOTIFY SET (selected (MessageNew (UID FLAGS) MessageExpunge FlagChange)) (personal (MessageNew MessageExpunge))`.
  New INBOX mail is seen in about half a second; another folder's change arrives as `* STATUS name (…)`.
- **Where `NOTIFY` is missing or refused**: `IDLE` on INBOX (re-issued every 25 minutes) and a `STATUS` round over every folder every
  60 s (`LIST "" "*" RETURN (STATUS (MESSAGES UIDNEXT UNSEEN HIGHESTMODSEQ))`, one command). **The `STATUS` round runs every 60 s with
  `NOTIFY` too**, as the safety net: the first `NOTIFY` on a new mailbox took 28 s to say anything (research 04 §B).
- **A folder whose `HIGHESTMODSEQ` or `UIDNEXT` moved** is brought up to date on a pool connection: `SELECT folder (QRESYNC
  (uidvalidity modseq))` → `VANISHED (EARLIER)` rows deleted (their keys and the application's state stay), changed flags applied
  (one `UPDATE … FROM (VALUES …)`), new UIDs above the known `uidnext` fetched as §3.1's envelope pass. The selected INBOX takes the
  same path from the watcher's own untagged responses.
- **A `UIDVALIDITY` change** calls **`ml_folder_reset(folder, new_uidvalidity)`** (db/013: the folder's rows go, their keys stay) and
  re-reads the folder as §3.1 does; labels, viewer reads,
  thread states, drafts' replies and dispatches find their messages again by message key (R31's rebuild rule; Phase 0's proof).
- Each write that changes what a list shows **moves the mailbox's change counter** (db/013 — its triggers on conversations, their
  states, labels, read marks, outbound rows and folders), which the browser's poll reads. After a bulk write (`app.ml_bulk = 'on'`) and
  after `ml_threads_rebuild()`, the service calls **`ml_mailbox_touch(mailbox, '{}')`** once; after a live batch,
  `ml_mailbox_touch(mailbox, <the conversations it touched>)` is not needed (the triggers did it).
- Our own mutations are applied to the index in the same request (§5); when the watcher then sees their `MODSEQ` it finds nothing to
  change.

**3.4 The text pass** (`textpass.py`): for rows with `text_indexed_at IS NULL`, newest first (the partial index
`mail_messages_text_due_idx`), 200 messages a command: `UID FETCH (BINARY.PEEK[<part>]<0.N>)` of the first `text/plain` part (else the
first `text/html`, turned to text by nh3 with no tags allowed), `N` = `index_text_max_kb` × 1024. Grouped by part number (`1`, `1.1`).
**A gentle rate**: at most four commands a minute per mailbox and four mailboxes at a time across the service — 5,000 messages in
about seven minutes, a new mailbox searchable within the hour. `body_text`, `text_indexed_at`; `tsv` is rebuilt by db/008's trigger.

**3.5 Threading** (R31) is db/008's: `ml_thread_find` in the before-trigger for a live write (`References`, then `In-Reply-To`, then
the normalised subject within 30 days among the same participants; a changed subject or the 100th message starts anew; a parent
arriving late adopts its replies), and `ml_threads_rebuild` after a bulk write. The service never threads in Python.

## 4. Fetch, parts and the raw message

- **`fetch`** answers, for `form`:
  - **`headers`**: the header summary — From, Sender, Reply-To, To, Cc, Date, Message-ID, In-Reply-To, References, the DMARC result
    and spam score as Rspamd reported them, List-*, Auto-Submitted, Precedence, `X-OS-Agent` — as parsed values (`BODY.PEEK[HEADER]`),
    and **the part list**: `BODYSTRUCTURE` and `BINARY.SIZE` of each leaf → `[{part, type, name, disposition, size (decoded),
    content_id}]`.
  - **`text`**: the summary and the part list, plus the first `text/plain` part (else the HTML part turned to text), whole up to
    100,000 characters — what `thread_read` and `message_read` fence for an agent.
  - **`html`**: the summary and the part list, plus the HTML part through **`sanitise.sanitise()`**, answering its fields: `html`,
    `hidden_removed`, `remote_images`, `pixels_dropped`, `cid_refs`, `unstable`. With **`profile=reading`** the two callbacks are
    `cid_url(content_id)` → a **part link** for the leaf whose `Content-ID` matches, and `remote_image_url(url)` → the placeholder when
    `images=ask` or an **image link** when `images=show` (the placeholder and the image door are `reading-and-rendering.md`'s). With
    **`profile=compose`** (the quote of a reply or a forward, `writing.md`) `cid:` and data images are dropped and remote images too —
    links are kept. A message whose HTML is over 2 MB answers its text alone with `html_too_large: true`.
  - Sanitised bodies are kept in an in-memory LRU of 64 MB keyed by `(folder, uidvalidity, uid, images, profile)`; nothing on disk.
  - Every form refuses a stale UIDVALIDITY with **`409 moved`**.
- **`sanitise`**: HTML a person or a reviewer wrote (`writing.md`, `sending-and-oversight.md` §4.1), cleaned with the **compose**
  profile (the reading allow-lists; `cid:`, data and remote images refused; links kept) or the **signature** profile (the same, and no
  heading or table larger than a signature needs); answers `{html, text}` — the text derived from the cleaned HTML (block elements to
  lines, links as `text <url>`, quotes as `> `).
- **`part`**: `BINARY.PEEK[<part>]<start.len>` in 1 MB chunks, streamed; `Content-Type` from the structure. The mail service never
  decides inline or attachment — the door does.
- **`raw`**: `BODY.PEEK[]`, streamed in 1 MB chunks.
- **Part and image links — ONE key, `ML_PART_KEY`** (decided by the coordinator, 2026-10-11; `reading-and-rendering.md` owns the
  doors). A link is `base64url(JSON) "." hex HMAC-SHA256(ML_PART_KEY, the base64url text)`, the JSON `{"k": "part" | "img", "m":
  mailbox, "r": "folder.uidvalidity.uid", "p": part or the image's index, "a": member, "e": expiry unix}`, **valid one hour**. **The mail
  service mints them** when it sanitises (`cid:` images, part links); **PHP mints them** for the links it renders itself (attachment
  rows, Show original); **PHP's `/p/` and `/img/` doors verify them**. A link is **bound to the member and the message**: the door
  checks the session's member equals `a`, and **the member's level on the mailbox is re-checked on every use** (the door asks the mail
  service, which runs `ml_level_as()` for `a`) — a link copied out of a page opens nothing for anyone else, and a revoked row shuts it at
  once. `ML_PART_KEY` (32 random bytes, hex) is in `config/.env`, which both read; it reaches only what the bound member may open
  anyway. (`reading-and-rendering.md`'s `ML_LINK_KEY` is dropped.)
- **Every read by anyone but the owner** is logged by the PHP door that asked (`message.open`, `message.part`, `message.raw`) through
  `ml_access_should_log()`; the mail service logs nothing about content.

## 5. Mutations (`mutate`)

Each op is **one IMAP command per folder touched** (the messages grouped by folder into one UID set), the index updated in the same
request after the tagged `OK`, the change counter bumped. Before anything, `ml_mutation_refusal()` for every folder involved; a refusal
names the messages it held back and the rest proceed.

| op | IMAP | Index |
|---|---|---|
| `seen` / `unseen` | `UID STORE +FLAGS.SILENT (\Seen)` / `-FLAGS` — **only when `ml_read_mode()` is `store_seen`**; otherwise nothing on the server | `seen`; or `ml_viewer_read(member, key, on)` for a `view` holder and every intake mailbox (D13) |
| `flag` / `unflag` | `UID STORE ±FLAGS.SILENT (\Flagged)` | `flagged` |
| `move` | `UID MOVE set "folder"` → `COPYUID` | **`ml_message_moved(message, folder, uidvalidity, uid)`** per row (db/013: ids and keys kept) |
| `archive` | `move` to `archive` (**`CREATE "Archive"`** first when missing) | as `move` |
| `trash` / `spam` / `not_spam` | `move` to `trash` / `junk` / `inbox` | as `move` |
| `snooze` | `move` to `snoozed` (**`CREATE "Snoozed"`** first when missing), after `ml_thread_snooze()` per conversation | as `move` |
| `unsnooze` | `move` back to the folder `ml_snoozes_due()` names (the worker calls it for due snoozes; a person's Unsnooze calls it at once) | as `move` |
| `delete_forever` | `UID STORE +FLAGS.SILENT (\Deleted)` then `UID EXPUNGE set` — only from `trash` or `junk` | rows deleted |
| `trash_empty` | the same over every UID of `trash` (or `junk`) | rows deleted |
| `folder_create` | `CREATE "parent.name"` | a `mail_folders` row |
| `folder_rename` | `RENAME` (never a special-use folder — `ml_mutation_refusal`) | the row's name; its messages keep their rows |
| `folder_delete` | the folder's messages `UID MOVE`d to Trash first, then `DELETE` | rows moved, the folder row deleted |

`threads` are expanded to the conversation's messages **in the folders the view showed** (the request names `from_folder` for that:
archiving from the Inbox moves the Inbox's copies, never Sent's). A request naming a `ref` whose `uidvalidity` no longer matches the
server's answers `409 moved`. Labels are the application's own (`record_tags`) and never touch IMAP in v1 (design §11 mirrors them to
keywords later), so `mail_label` does not call the service.

## 6. Drafts (`append_draft`)

From the `mail_drafts` row: the MIME is built by the outbox's builder (`sending-and-oversight.md`) with `Message-ID: <draft_key>`;
`APPEND "Drafts" (\Draft \Seen)` → `APPENDUID`; the row's `folder_id`, `uidvalidity`, `uid` set; then the previous version
`UID STORE \Deleted` + `UID EXPUNGE` (UIDPLUS) — the Drafts folder holds one copy, so a phone sees the latest (research 04 §B: 0.14 s
the replacement). A failed APPEND leaves the row and the previous version untouched and answers 502; the composer keeps its text.
**The delete half** (`discard: true`): `UID STORE \Deleted` + `UID EXPUNGE` of the draft's current copy alone, used when the draft is
sent (`sending-and-oversight.md` §5.2 step 7) or discarded; the row's `folder_id`, `uidvalidity`, `uid` cleared. **A draft written on a
phone** is found by the sync in Drafts; the composer makes its row when it is opened (`writing.md`). The `\Draft \Seen` flags are the
draft's only flags.

## 7. Submission

`submit_now` and the outbox loop (claiming due rows `FOR UPDATE SKIP LOCKED`, the MIME, 587 with STARTTLS and certificates checked,
the `451 4.7.1` deferral) are **`sending-and-oversight.md`'s**. This service gives them the pool, `imap.login()` and its breaker (a
refused SMTP `AUTH`, `535`, trips the same breaker as a refused IMAP login) and three internal calls:

- **`append_sent`**: `APPEND "Sent" (\Seen) "<submission time>"` of the message as submitted, **with its `Bcc`** (the mailbox's own
  copy), then `outbound_email.filed_at` (`sending-and-oversight.md`). MaluMail files no copy itself (research 04 §B).
- **`store_flags`**: `UID STORE +FLAGS.SILENT (\Answered)` on the answered message's current location (`reply_to_key_id` → its row);
  **never `\Seen`**, so an intake mailbox stays as its reader expects.
- **The crash search at startup**: a row found `sending` for more than ten minutes was claimed before a crash and may have gone. It is
  **never submitted again automatically**: `SELECT "Sent"`, `UID SEARCH HEADER Message-ID "<its id>"` — found → `ml_outbound_sent()`;
  not found → `ml_outbound_failed()` with `sending-and-oversight.md`'s sentence.

## 8. Quota

`GETQUOTAROOT INBOX` (`STORAGE` in KiB) at the first sync, every 15 minutes of a live mailbox, after a large `APPEND`, and on the
`quota` call → `mailboxes.quota_bytes`, `quota_used_bytes`.

## 9. Status and health

`status` answers, for one mailbox: `{sync_state, last_sync_at, watcher: "notify" | "idle" | "down", backoff_seconds, credential:
"ok" | "failed" | "revoked" | "none", folders: [{folder_id, name, special_use, messages_total, synced_at}], text: {indexed, due},
quota: {used_bytes, limit_bytes}, last_error}`. `/health` gains `{watchers, pool_connections, text_due, mailboxes_live,
mailboxes_failing}` — counts only.

## 10. The PHP client (`app/mailer.php`, Phase 2)

- `mailer_call(string $path, int $actor, array $args = [], ?int $onBehalf = null, int $timeout = 30): array` — the body
  `json_encode(['actor' => $actor, 'on_behalf' => $onBehalf, 'args' => (object) $args])`; the header exactly as `door.py`'s `sign()`:
  `v1.<time()>.<bin2hex(random_bytes(16))>.<hash_hmac('sha256', "v1.$ts.$nonce.POST.$path." . hash('sha256', $body), ML_MAILER_KEY)>`.
  Answers the decoded JSON; throws `MailerRefused` (with code and message) on a refusal and `MailerDown` when nothing answers.
- `mailer_stream(string $path, int $actor, array $args, callable $onHeaders): void` — for `part` and `raw`: forwards the status and
  headers, then copies the body to the output in 64 kB writes, with the session released first (DocCloud's rule).
- `ml_part_token(string $kind, int $mailbox, string $ref, string $part, int $actor): string` and `ml_part_token_verify(string $token):
  ?array` — §4's shape; used by the reading doors.
- A proof checks that a PHP-signed call is accepted by the Python door and a Python-minted token verifies in PHP.

## 11. Failure modes

| What happens | What the service does | What a person sees |
|---|---|---|
| The server refuses the credential | the breaker: the credential `failed`, the watcher and pool closed, the Mail admins told | "Not connected — a Mail admin stores a new credential" on the rail and the admin |
| `[UNAVAILABLE]`, no answer, a bad certificate | backoff 30 s → 30 min per mailbox; `last_error` | "Reconnecting…" with the time; the index's mail stays readable |
| The watcher's connection drops | reconnect after the backoff, `SELECT … QRESYNC` catches up | nothing, or the "N new" bar a little later |
| A folder vanished on the server | its row deleted; its messages' rows with it; keys stay | the folder leaves the rail |
| `UIDVALIDITY` changed | the folder re-read (§3.3) | "Updating this folder…" for a moment |
| A message moved by another client | `VANISHED` in one folder, a new UID in another: the same key, the row re-made | nothing |
| The database is down | the door answers 503; the watchers keep their connections but stop writing; resync on return | "Mail is catching up" |
| A fetch's connection fails mid-stream | the stream ends short; the door answers 502 | "Could not load this part — try again" |

## 12. The IMAP fixture's growth (`tests/fixtures/imap_server.py`)

Phase 0's fixture answers `LOGIN`, `CAPABILITY`, `ENABLE`, `LIST`, `STATUS`, `SELECT`. It grows only by what each slice's proof needs,
and advertises only what it answers:

| Slice | Adds |
|---|---|
| 2 | `ENABLE QRESYNC`; `SELECT … (QRESYNC (v m))` with `VANISHED (EARLIER)`; `UID FETCH` of the envelope items (`FLAGS`, `INTERNALDATE`, `RFC822.SIZE`, `BODYSTRUCTURE` for single-part and `multipart/mixed` / `alternative` messages, `MODSEQ`, `PREVIEW`, `BODY.PEEK[HEADER.FIELDS (…)]`), `CHANGEDSINCE`; `BINARY.PEEK[n]<o.l>`; `IDLE`; `NOTIFY SET` with `MessageNew`/`MessageExpunge`/`FlagChange` and `STATUS` events; `LIST … RETURN (SPECIAL-USE STATUS (…))`; `GETQUOTAROOT`; `CREATE`; a control file to deliver a message, expunge one, change a flag from "another client", reset a folder's `UIDVALIDITY`, refuse `NOTIFY`, and drop a connection |
| 3 | `BINARY.SIZE`, `BODY.PEEK[HEADER]`, `BODY.PEEK[]`, `BINARY.PEEK` of any part; `UID STORE`; `UID MOVE` with `COPYUID`; `UID EXPUNGE`; `CREATE` of `Archive` and `Snoozed` on first use; parts of a corpus message with `cid:` images and attachments (the sanitiser's benign corpus) |
| 4 | `APPEND` with `APPENDUID` (Drafts with `\Draft \Seen`, Sent with `\Seen` and a date); `UID STORE` (`\Deleted`, `\Answered`); `UID EXPUNGE`; `UID SEARCH HEADER Message-ID`; the SMTP side (aiosmtpd, `sending-and-oversight.md` §11) |
| 6 | `RENAME`, `DELETE` |

## 13. The proof

Each slice's `tests/phase3/sliceN/run.sh` runs this spec's part against the fixture on scratch `ml_devN`, then — **where design §10
asks — against the test mailbox at MaluMail** with its real credential from the owner's handoff file (never a wrong password; the
breaker's proof stays on the fixture). Slice 2 proves at least:

- a first sync of a 5,000-message fixture mailbox: every message indexed once, every key equal to what `ml_message_key_for` computes,
  `ml_envelope_digest` equal in Python and SQL, the Inbox's conversations present before the other folders finish;
- `NOTIFY` delivers a new message into the index within 2 s; with `NOTIFY` refused, `IDLE` within 2 s and another folder within 70 s;
- another client's flag change, expunge and move reach the index through `QRESYNC`; a `UIDVALIDITY` reset re-reads the folder and
  the labels, viewer reads and thread states of its messages survive (by key);
- the text pass fills `body_text` capped at `index_text_max_kb` and `ml_search` finds a word of it;
- a dropped watcher reconnects and catches up; the change counter moves exactly when a list would change;
- `local_submission` set only for the `ESMTPSA`-without-verdict shape; `dmarc_pass` from `X-Spamd-Result`;
- no `\Seen` ever reaches the fixture from sync (its log of `STORE`s is empty after a sync);
- PHP's `mailer_call()` signature accepted by the Python door; the 62 Phase 0 checks still green;
- against MaluMail's test mailbox: a first sync of what the spike left, a submission seen through `NOTIFY` in under 2 s.

**Slices 3, 4 and 6 prove their calls the same way** (their specs list the checks): `fetch` in each form and profile, its links (one
hour, bound to the member and the message, the level re-checked on every use, refused to another member), `part` and `raw` streaming
and `409 moved` on a stale UIDVALIDITY (3); every `mutate` op against the fixture's command log, `Archive` and `Snoozed` created on
first use, nothing but the named ops reaching the server, no `\Seen` on an intake mailbox (3, 6); `sanitise` with both profiles and
`identity_check` against the fake Mailbox MCP (4); `append_draft` and its delete half leaving one copy in Drafts, `append_sent` with its
`Bcc`, `store_flags` never `\Seen`, and the crash search marking a found message `sent` and a missing one `failed`, never submitted
twice (4).

## Requirements claimed
MUSTs (6): `R29`, `R30`, `R31`, `R32`, `R33`, `R62`

## Decisions taken here

1. **IMAPClient moves to 4.1.0 at slice 2** (accepted; the coordinator bumps `mcp/requirements.txt` and re-runs the mailer proof). It fixes the TLS timeout (#664 — Phase 0's probe passes a timeout that 3.x did not honour
   for TLS) and unsolicited responses during `FETCH` (#647 — what `NOTIFY` sends); 4.0's only breaking change is the project's build
   tooling. Slice 2's proof re-runs Phase 0's 62 checks on it.
2. **The door carries `on_behalf`.** The records MCP must read as "the Expert for this person" (`ml_level_as`), and the service never
   takes a level from a caller; the person, signed into the body, lets the service compute the same answer the views give.
3. **One link key, `ML_PART_KEY`** (the coordinator's decision): the mail service mints links while it sanitises, PHP mints the
   links it renders, PHP's doors verify; one hour; bound to the member and the message; the level re-checked on every use.
4. **The `STATUS` round runs every minute even with `NOTIFY`.** A first `NOTIFY` took 28 s; a missed event must cost at most a minute.
5. **Two thread rebuilds in a first sync** — after INBOX and at the end — so a new mailbox's Inbox is usable at once without threading
   per row.
6. **A folder is deleted by moving its messages to Trash first.** Dovecot's `DELETE` of a non-empty folder destroys its mail; Trash
   keeps the agent-paused deletion recoverable.
7. **Mutations update the index in the same request** and the watcher reconciles by `MODSEQ`, so a screen is right at once and never
   flickers back.
8. **Correspondents are counted from received mail by sync and from sent mail by the outbox** — never from the Sent folder by sync —
   so "known" keeps its meaning (only a person's send, or an approved one, makes an address known).

## Open questions

- None for the owner. MaluMail's `create_mailbox` reply field (`password`) is confirmed by slice 1 on the test domain.

## Changes this spec asks of the shared documents

- **db/013_list_and_sync.sql** — BUILT and proven (`db/proof/phase1_list.sql`, 75 checks; Phase 0's 232 still green):
  `mail_mailbox_changes`, `mail_threads.change_seq` and its index, `mail_thread_tombstones`, the change triggers (conversations,
  their states, `record_tags`, `mail_viewer_reads`, `outbound_email`, `mail_folders`), `ml_mailbox_touch()`, `ml_mailbox_seq()`,
  `ml_mailbox_changed()`, `ml_tombstones_prune()`, `ml_folder_reset()`, `ml_message_moved()`; the list's and the admin's functions
  (`mail-list.md`, `home-admin.md`). Nothing of db/001–012 replaced.
- **mcp/requirements.txt**: `imapclient==4.1.0` (accepted; the coordinator bumps it).
- **config/.env.example, tests/setup_dev.sh, deploy/ROOT_STEPS.sh**: `ML_PART_KEY` (32 random bytes, hex; written once, never printed)
  and `ML_MAILER_MAX_CONNECTIONS=400`.
- **deploy/malumail-mailer.service**: `TasksMax` stays 1024 (watchers + pools + the loop fit under it at 400 connections).
