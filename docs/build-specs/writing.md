# Slice 4 — Writing (THE THIRD EXEMPLAR)

2026-10-11 · **Phase 3, slice 4 of 10 — the third exemplar** (design §1 "writing", §6.3, §9 Compose, D8, D12). Compose, reply, reply
all and forward; the editor; drafts kept in the mailbox's Drafts folder as one types; attachments; identities and signatures; Send
with **Undo**, **Schedule**, the Outbox's deferrals and failures. The MIME, the outbox loop, submission and filing are the build spec
`sending-and-oversight.md`; the mail service's door and the IMAP side are `mail-service.md`. **Prerequisites:** Phase 2 (the shell),
slice 1 (mailboxes with credentials), slice 2 (the mail service's sync and the index — a draft written on a phone is found by it),
slice 3 (the conversation screen this slice's Reply and Forward start from, and the sanitiser's reading path).

## Divergences from the design

- **No inline images in v1** (sending-and-oversight decision 6): a pasted or dropped image becomes an attachment, and the composer says
  so. The design's compose lists none explicitly; this makes it explicit.
- **Canned replies** are slice 6's (templates). This slice's compose accepts the `template` prefill param (filled when slice 6 exists);
  the "Insert canned reply" control in the toolbar is added by slice 6.
- **Recipient autocomplete** is slice 8's (`/m/{mailbox}/complete`). This slice's recipient fields are chips with address validation;
  slice 8 attaches the suggestions to the same inputs.
- **An agent's signature** is appended by the mail service at send, not shown in a composer (sending-and-oversight decision 3).

## Who may do what

| Who | May |
|---|---|
| `triage` on the mailbox (`mail.use`) | write, save and discard drafts (`ml_drafts_guard()` — the Mail Expert in its person's run included) |
| `send` | send, reply, forward, schedule, undo their own, retry a failed one |
| `full` (people only) | add, change and delete the mailbox's identities and signatures |
| an agent with `send` (its own mailbox, a row) | send, reply, forward through the actions — held or queued by its policy; no undo window; undo (withdraw) its own held message |
| the Mail Expert in a person's run | drafts only (`triage` is its cap) — the person sends |
| anyone below the level | 404 for a mailbox they cannot open; 403 with `ml_level_name()`'s sentence for one opened below the level |

An agent's `mail_send` / `mail_reply` / `mail_forward` without a `purpose` is refused (422, the SQL's sentence). Refusals and holds are
the policy's (sending-and-oversight §7) — this slice never decides them in PHP.

## Screens

### `compose` — `/m/{mailbox}/compose` (params `reply`, `reply_all`, `forward`, `to`, `subject`, `body`, `template`)

- **Full page, no modal.** 375 px: one column — From, To (with Cc/Bcc revealed by a link), Subject, the editor filling the rest, the
  attachment strip, then a sticky bottom bar: **Send** (with a caret: **Schedule…**), the paper-clip, the format toggle, Discard.
  1280 px: the same column at a 760 px measure, centred, the conversation it answers collapsed beside it on the right (reply and
  forward only).
- **From**: the mailbox's identities (`mail_identities`, the default first); a delegate sees *"Sales (sent by Maria)"* explained under it.
- **To / Cc / Bcc**: chip inputs; an address is validated as typed (`^[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+$`); an **outside** address chip is
  outlined amber (`ml_is_internal_address()` through the page's JSON of served domains); more than 100 recipients refused.
- **The editor** — **Squire 2.4.9** (Fastmail's webmail editor, MIT; the built `dist/squire.js` vendored under
  `html/assets/vendor/squire-2.4.9/` with its sha256 in `html/assets/vendor/README.md`; no Node on a server). Toolbar: bold, italic,
  underline, link, bulleted and numbered lists, quote, remove formatting, plain-text toggle. **Plain** is a `<textarea>`; the choice is
  remembered per person (`mailboxes.settings.compose_format` of the person's own mailbox; default rich). Without JavaScript the
  composer is the plain textarea and a normal form POST — it still sends.
- **Reply** (`?reply=<message key id>`): To = the original's `Reply-To`, else `From`; Subject `Re: ` once (`Re:`, `RE:`, `Aw:`, `Fwd:`
  normalised by `ml_subject_norm()`); the body empty above a folded quote — *"On Tue, 6 Oct 2026 at 16:12, Sam Okafor
  &lt;sam@okafor.example&gt; wrote:"* and the original's **sanitised** HTML (the mail service's `fetch`, compose profile) in a
  `<blockquote>`; **Reply all** adds the original's To and Cc minus the mailbox's own identities. **Forward** (`?forward=`): Subject
  `Fwd: `, a header block (From, Date, Subject, To) and the body; its attachments listed with a checkbox each (all ticked).
- **The signature** of the chosen identity is inserted above the quote (`-- ` line first) and changes when From changes, unless the
  person edited it.
- **Autosave**: `ml_settings.draft_save_seconds` (10 s) after the last change, and on leaving the page, the form posts
  `mail_draft_save` (`hx-trigger="input changed delay:10s, beforeunload"` with `navigator.sendBeacon` for the last). The status line
  says *Saving… · Saved · Saved to Drafts* — the last when the mail service has APPENDed it (§Query functions). A save that loses
  (another tab saved later) says *"A newer version was saved elsewhere — Open it"*.
- **Attachments**: the paper-clip, drag and drop, paste; each upload `POST /m/{mailbox}/drafts/{draft}/files` (one file ≤ 7 MB, the
  draft's total ≤ `max_attachments_mb`); the strip shows name, size and a remove button; the total and the limit under it.
- **Send**: the bar shows *"Sending… Undo"* for the undo window (a person's), then *"Sent — View message"*; **Undo** posts
  `mail_send_cancel` and reopens the draft exactly as it was. **Schedule…** opens an inline panel (not a modal): *Tomorrow morning ·
  Tomorrow afternoon · Monday morning · Pick a date and time* in the person's time zone; the bar then says *"Scheduled for Tue 09:00 —
  Cancel"*.
- **Held or refused** (a delegate's message is never held; an agent sees this through the action's answer): the answer's sentence is
  shown where *Sent* would be.
- Empty and error states: no identity yet → the mailbox's own address is used and a link offers *"Add a signature"*; a suspended
  mailbox → the composer is read-only with the reason; the mail service down → *"Saved here; it will reach your Drafts folder when
  the mail service is back"*.

### `draft-edit` — `/m/{mailbox}/drafts/{draft}`

The same composer, filled from `mail_drafts`. A draft written on a phone (found by the sync with no `member_id`) opens here and gets
its writer on the first save. A draft that became an outbound row which was sent says *"This draft was sent"* with a link to it.

### `identity-new` — `/m/{mailbox}/identities/new` (param `address`), and `identity-edit` — `/m/{mailbox}/identities/{identity}/edit`

Full page, 375 first. Address (the mailbox's own, or an alias — checked through the mail service's `identity_check`: MaluMail's
`list_aliases` must show the alias delivering to this mailbox; otherwise *"sales@ does not deliver to this mailbox; ask a Mail admin to
forward it here first"*), display name, reply-to (an address), signature (the same editor, sanitised by the mail service with the
signature profile before it is stored), Default. The edit page has Delete at its foot (`hx-confirm`), refused for the `own` identity
and for the default while another is not chosen. Both are reached from the mailbox's settings page (slice 7) and from From's menu.

## Files (exactly these)

| File | What |
|---|---|
| `html/m/compose.php` | the `compose` and `draft-edit` screen (rewrite `/m/{mailbox}/compose`, `/m/{mailbox}/drafts/{draft}`) |
| `html/m/draft-files.php` | `POST /m/{mailbox}/drafts/{draft}/files` and `?remove=` — the attachment door |
| `html/m/identities/form.php` | `identity-new` and `identity-edit` |
| `html/mail/draft-save.php`, `draft-discard.php`, `send.php`, `reply.php`, `forward.php`, `send-cancel.php`, `outbox-retry.php`, `identity-save.php`, `identity-delete.php` | the nine handlers |
| `app/features/compose/queries.php` | the query functions below |
| `app/features/compose/compose.php` | prefill for reply, reply all and forward; the subject and recipient rules; quoting |
| `app/features/compose/present.php` | the JSON presenters |
| `app/views/compose/{compose,_status,_attachments,_schedule,identity_form}.php` | templates |
| `app/mailer.php` | (Phase 2's door client) gains `mailer_draft_append()`, `mailer_fetch_for_quote()`, `mailer_sanitise()`, `mailer_identity_check()` |
| `html/assets/js/compose.js` | Squire wiring, autosave, chips, uploads, Undo |
| `html/assets/vendor/squire-2.4.9/squire.js` | vendored, sha256 recorded |
| `services/mailer/mime.py`, `outbox.py`, `drafts.py` | the build spec's §4, §5 and the draft loop (with `mail-service.md`) |
| `tests/fixtures/smtp_server.py` | the build spec's §11 |
| `tests/phase3/slice4/run.sh`, `checks.php`, `browser.mjs`, `live.py` | the proof |

## Query functions and mail-service calls (signatures fixed)

```php
compose_prefill(PDO $pdo, int $mailbox, string $mode, ?int $messageKey, int $member): array   // to, cc, subject, quote_html, quote_text, attachments
draft_save(PDO $pdo, int $mailbox, ?int $draft, array $fields, int $member): array             // ['draft' => id, 'version' => saved_at, 'conflict' => bool]
draft_discard(PDO $pdo, int $mailbox, int $draft, int $member): void
draft_attachments(PDO $pdo, int $draft): array; draft_attachment_total(PDO $pdo, int $draft): int
outbound_send(PDO $pdo, int $mailbox, int $member, array $fields): array    // wraps ml_outbound_submit(); ['outbound', 'status', 'hold_reasons', 'refusal', 'send_after']
outbound_cancel(PDO $pdo, int $outbound, int $member): ?int                 // ml_outbound_cancel() → the reopened draft
outbound_retry(PDO $pdo, int $outbound, int $member): void                  // failed → queued (`db/015_sending_oversight.sql`'s ml_outbound_retry())
identity_save(PDO $pdo, int $mailbox, ?int $identity, array $fields, int $member): int
identity_delete(PDO $pdo, int $mailbox, int $identity, int $member): void
```

Mail-service calls (signed door, `mail-service.md`): `append_draft` (mailbox, draft id — APPEND the new version with `\Draft \Seen`
and expunge the previous UID; answers the new `(folder, uidvalidity, uid)`; asynchronous after a `NOTIFY ml_drafts` from
`draft_save()`, synchronous when the person closes the composer), `fetch` (compose profile, for the quote), `sanitise`
(`profile`: compose · signature; answers the cleaned HTML and the derived text), `identity_check` (mailbox, address → `delivers:
bool`, from `list_aliases` under the customer key; never the alias list itself). The outbox loop's `submit_now`, `append_sent` and
`store_flags` are internal to the service.

## MCP tools this slice makes real

`find_identities`, `outbox_list`.

## Manifest rows claimed
Screens (4): `compose`, `draft-edit`, `identity-new`, `identity-edit`
Actions (9): `mail_draft_save`, `mail_draft_discard`, `mail_send`, `mail_reply`, `mail_forward`, `mail_send_cancel`, `mail_outbox_retry`, `mail_identity_save`, `mail_identity_delete`

## Activity log events

`draft.save` (**once per compose session** — when the draft row is created; later saves are not logged), `draft.discard`,
`message.send`, `message.reply`, `message.forward` (each with `mailbox_id`, recipients inside/outside as counts, `held` and its reasons,
`scheduled` when `send_at` was given), `message.schedule` (in place of `message.send` for a scheduled send), `outbound.refuse` (an agent's message refused — the
rule, never the text), `message.cancel`,
`outbound.retry`, `identity.save`, `identity.delete`. Never a subject of a person's mailbox, never a body, never an address of a
person's correspondent (domains only).

## Requirements claimed
MUSTs (6): `R7`, `R8`, `R9`, `R10`, `R12`, `R35`

## Out of scope for this slice

Canned replies (slice 6), autocomplete (slice 8), the review of held mail (slice 5), inline images, S/MIME and PGP, read receipts,
sending from outside MaluMail, the kernel's assistant channel (K46).

## Proof

`tests/phase3/slice4/run.sh`: scratch database `ml_dev4` (tests/setup_dev.sh), `php -S` on 9201, the fake kernel on 9202, the fake
MaluDB/MaluMail REST on 9203, the mail service on 9206 against **the IMAP fixture** (`mail-service.md` grows it with APPEND, UID
EXPUNGE, STORE, SEARCH HEADER) and **the SMTP fixture** (sending-and-oversight §11); then the same checks under a user-level Apache
rendering `deploy/apache-malumail.conf` on 9201. Playwright from `/var/www/web/node_modules` for the screens at 375 and 1280.

1. **Compose and send** as a person: the spool holds one message; its headers (From the identity, To/Cc, no Bcc, Date, the stored
   `Message-ID`, `MIME-Version`); `multipart/alternative` with both parts; the Sent copy in the IMAP fixture **with** Bcc and `\Seen`;
   the outbound row `sent`, `filed_at` set; the draft expunged from Drafts.
2. **Undo** inside the window → `cancelled`, the draft reopened with its text and attachments; after the window → refused with the
   guard's sentence. **Schedule** for +2 minutes (the clock moved by `send_after` in the database) → sent once; Cancel before → never.
3. **Reply and reply all**: `In-Reply-To` and `References` from the original; `Re:` once; recipients by the rules; `\Answered` on the
   original in the fixture, never `\Seen`. **Forward** with and without attachments; the parts byte-identical.
4. **Autosave**: three saves → one Drafts copy at a time (UID changes, `draft_key` the same); `draft.save` logged once; a stale save
   reports the conflict; a draft APPENDed to the fixture's Drafts by "a phone" is found by the sync and opens in `draft-edit`.
5. **Attachments**: 7 MB accepted, 7 MB + 1 byte refused, the total refused over the limit; the MIME over 9.8 MiB refused before
   submission.
6. **The server's answers**: `451 4.7.1` → `deferred` with the next try shown on the Outbox, then `sent`; five deferrals → `failed`;
   `550` for one recipient → `sent` with the refusal named and a `send_failed` notice; **`drop` after DATA (the crash)** → on restart
   `failed` with the sentence, **one** copy in the spool; `535` → the breaker, the fixture's one attempt.
7. **An agent's token**: `mail_send` without `purpose` refused; with it, held or queued by the policy (no undo window); its
   `X-OS-Agent`, `Auto-Submitted`, disclosure footer and the identity's signature in the spool; it withdraws its own held message;
   `mail_identity_save` refused (people).
8. **Identities**: own and alias (the fake Mailbox MCP's `list_aliases` delivering or not); the signature sanitised (a `<script>` in
   it gone); delete refused for `own` and the sole default.
9. **The wall**: a `view` holder's compose is 403; another person's mailbox is 404; the Expert in a person's run saves a draft there
   and is refused Send.
10. **375 px**: compose, the schedule panel and the identity form without horizontal scroll; no JavaScript → the plain form sends.
11. **Live** (design §10, at most two of the three submissions sending-and-oversight allows): spike01 sends to spike02; spike02's
    INBOX shows it by `Message-ID`, spike01's Sent shows the copy; spike02 replies and the reply threads; both expunged afterwards.
    Credentials from `ML_LIVE_CREDENTIALS`, outside the repository; never a wrong password.

`php bin/build_action_registry.php --check` passes with the nine actions built.

## Decisions taken here

1. **Squire 2.4.9, vendored, is the editor.** It was built for a webmail (Fastmail), works on `contenteditable` with clean HTML,
   has no build step at runtime and is MIT. Hand-rolled `execCommand` is deprecated; heavier editors (TipTap, Quill) need a bundler
   or carry a document model the sanitiser would fight.
2. **Drafts are filed asynchronously**: the database row is the immediate save and the mail service APPENDs within seconds. The
   person never waits on IMAP to type, and a phone sees the draft moments later.
3. **Compose prefill takes a message key id**, not the `(folder, uidvalidity, uid)` token: a reply started in one tab survives the
   original being archived in another.
4. **The quote is built from the sanitised body**, never the raw HTML: what a person sends on is what they could see.
5. **A delegate's Send is never held**; only agents' mail is (design §5). The composer shows a delegate who they send as.
6. **The rich/plain choice is remembered per person** on their own mailbox's settings; the default is rich.

## Open questions

None for the owner.

## Changes this spec asks of the shared documents

- **Done in `db/015_sending_oversight.sql`**: `ml_outbound_retry(p_outbound, p_member)` (the writer, or a member with `send`; a fresh deferral
  budget); the `ml_drafts` wake-up when a draft is saved or discarded; `mail_drafts.filed_at`. The `ml_outbox` wake-up is a trigger on
  every row that becomes `queued`.
- **`mail-service.md`** (fork A): the door routes `append_draft`, `fetch` with a `profile`, `sanitise`, `identity_check`, and the
  IMAP fixture's APPEND, UID EXPUNGE, STORE and SEARCH HEADER.
- **The manifest**: none — its nine rows stand as written.
- **`deploy/apache-malumail.conf`** and `tests/dev_router.php`: the rewrites `/m/{mailbox}/compose`, `/m/{mailbox}/drafts/{draft}`,
  `/m/{mailbox}/drafts/{draft}/files`, `/m/{mailbox}/identities/new`, `/m/{mailbox}/identities/{identity}/edit`; the vhost's request
  body limit stays 25 MB.
