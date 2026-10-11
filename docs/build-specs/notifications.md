# Slice 9 — Notifications

2026-10-11 · **Phase 3, slice 9 of 10** (design §6.5's worker, §8 K6, §9 Notifications; the kit's notification tables, Spaces' family:
`notifications`, `notification_prefs`, `notification_outbox`, `ml_notify()` in db/007). The bell and its page; each person's choices;
the worker's passes that make and deliver notices — **new mail** in the mailboxes a person chose, **held mail** for its reviewers,
**decisions and expiries**, **Unscreened**, **break-glass** on one's own mailbox, **failures** for the Mail admins, and **the Clerk's
morning note** for overseers. **Prerequisites:** slices 1–5 (the events that notify exist), slice 7 (break-glass rows). Delivery by mail
goes through MaluMail's transactional `/v1/send` (the installer's `mail` step, `app/mail.php`); by text through the kernel (K6).

## Divergences from the design

- **No notice carries a subject, a sender's name or any words of a message** — in the bell, by mail or by text (sending-and-oversight
  decision 5). The design's §9 says "new mail in the bell"; the bell says *how many* and *where*, and the list is one click away.
- **The Clerk's note is the worker's deterministic pass** (sending-and-oversight decision 2, §10), attributed to the named Clerk.
- **The manifest's `sms_enabled`** maps to `notification_prefs.text_enabled`; the action gains `text_kind[]` (which notices may text).

## Who may do what

Every action here is the caller's own and a **person's** (`people`): an agent has no notifications (`ml_notify()` writes none for an
agent). Anyone with `mail.use` reads and sets their own; nobody reads another's. The Mail admins' failure notices go to the people who
hold `settings.manage`.

## The notices

| Kind | To | Made by | Says (title; body) |
|---|---|---|---|
| `new_mail` | a person who chose the mailbox (`new_mail_mailbox_ids`) and may open it | the worker's `new_mail` pass | *"3 new messages in sales@"* — coalesced by `ml_notify_coalesce()`: an unread `new_mail` notice for that mailbox under 30 minutes old has its count (`item_count`) raised, not repeated |
| `held_mail` | each reviewer of the held message | `db/015_sending_oversight.sql`'s trigger `ml_outbound_held_notice()` | *"Mail from Ada waits for your review (first contact, attachment)"* |
| `review_decided` | the other reviewers when a held message expires | `ml_held_expire()` (`db/015_sending_oversight.sql`) | *"Ada's message expired with no decision"* |
| `unscreened` | the agent mailbox's primary overseer | `ml_dispatch_decide()` (db/010) | *"Mail for Ada waits for a person"* |
| `break_glass` | the owner of a person's mailbox | db/010's permissions trigger (slice 7) | *"Marco was given access to your mailbox until 30 Oct: <reason>"* — the reason is the granter's words |
| `permission_granted` | a person given a row | the same trigger | *"You can now open sales@ (send)"* |
| `credential_failed`, `sync_failed` | the Mail admins | the mail service (`store.trip_breaker()`; the sync, `mail-service.md`) | *"MaluMail stopped using ada@'s credential"* |
| `quota` | a mailbox's owner (or an agent mailbox's overseers) and the Mail admins | the worker's `quota` pass at 90 % and 100 %, once per crossing | *"sales@ is 92 % full"* |
| `snooze_woke` | the person who snoozed | the worker's `snoozes` pass (slice 6) | *"A conversation you snoozed is back in sales@"* |
| `send_failed`, `send_deferred` | the writer (a person) or the primary overseer (an agent's message) | the outbox loop (`sending-and-oversight.md` §5.3) | *"Your message to supplier.example could not be sent"* — the domain, never the address of a person's correspondent |
| `agent_replied` | the person whose turn an agent answered by mail on their behalf | the dispatch pass | *"Ada replied in a conversation you released"* |
| `digest` | each overseer, weekdays at `clerk_note_at` | the worker's `clerk_note` pass, actor the Clerk | *"Your agents' mail this morning"*; body: per mailbox, held over a day, Unscreened, quota, failures — counts and links |

**Delivery.** `ml_notify()` writes the bell row and, when the person's choices say so, an `email` row of `notification_outbox`
(deduplicated by `n<notification id>`). `db/015_sending_oversight.sql`'s `ml_notify()` adds the **text** row: when `text_enabled` and the kind is in `text_kinds` (default
`{held_mail}`), a `channel = 'text'` row. The worker's `notify_outbox` pass sends:

- `email` through `app/mail.php` (MaluMail's `/v1/send`, the installer's key): plain text, the title, a link to the record, and the line
  *"You get this because of your MaluMail notification settings"* with the settings link. Never through a mailbox;
- `text` through the kernel's `POST /api/v1/notify/sms.php` (K6: the member's verified phone, a grant, not opted out, 30 a day) —
  the title and a short link; `503 no_sender` → `skipped` with the reason, never retried;
- three attempts with backoff, then `failed` (shown on the settings page as *"A notice could not be mailed"*).

## Screens

### `notifications` — `/notifications` (param `unread`)

A list, newest first, unread in bold: an icon by kind, the title, the mailbox, the time. A row opens its record — held mail
`/oversight/review/{outbound}`, Unscreened `/oversight/unscreened?mailbox=`, new mail `/m/{mailbox}/v/inbox`, a failure
`/admin/mailboxes/{mailbox}`, a digest `/oversight` — **marking it read by `hx-post` before following the link** (`mail_notification_read`).
**Mark all read**. Filter *Unread only*. 375 first. Empty: *"No notices. You choose what reaches you in Notification settings."*
**The bell** in the shell's header shows the unread count from the same query (`notifications_unread_count()`), polled with the mailbox
poll; its menu is the latest eight rows and *See all*.

### `notification-settings` — `/settings/notifications`

Per kind, three switches: **In MaluMail** (always on — the bell), **By mail**, **By text** (shown only when the kernel answers that the
person has a verified phone and the business a notification number; otherwise *"Texts need a verified phone in your profile"*).
**New mail**: the mailboxes the person may open, each with a switch (none by default). The page explains that notices never contain
mail. Save (`mail_notification_prefs_save`). 375 first.

## Files (exactly these)

| File | What |
|---|---|
| `html/notifications/index.php`, `read.php` | the list and `mail_notification_read` |
| `html/settings/notifications/index.php`, `save.php` | the settings and `mail_notification_prefs_save` |
| `app/features/notifications/queries.php`, `present.php`, `passes.php` | the queries; the worker passes `new_mail`, `quota`, `clerk_note`, `notify_outbox` |
| `app/views/notifications/{list,_row,_bell,settings}.php` | |
| `bin/worker.php` | gains the four passes (each recorded in `worker_passes.steps`) |
| `tests/phase3/slice9/run.sh`, `checks.php`, `browser.mjs` | the proof |

## Query functions and mail-service calls (signatures fixed)

```php
notifications_list(PDO $pdo, int $member, bool $unreadOnly, int $limit = 50, ?int $before = null): array
notifications_unread_count(PDO $pdo, int $member): int
notification_read(PDO $pdo, int $member, ?int $notification, bool $everyOne): int     // answers how many changed
prefs_get(PDO $pdo, int $member): array; prefs_save(PDO $pdo, int $member, array $prefs): void
pass_new_mail(PDO $pdo, DateTimeImmutable $since): int        // INBOX arrivals since the last pass in the chosen mailboxes; not one's own; not muted
pass_quota(PDO $pdo): int
pass_clerk_note(PDO $pdo, DateTimeImmutable $now): int        // once a weekday at clerk_note_at in ml_settings.timezone
pass_notify_outbox(PDO $pdo, int $limit = 50): int
```

The quota figure comes from the mail service (`quota`, `mail-service.md`) into `mailboxes.quota_used_bytes`; this slice only reads it.

## MCP tools this slice makes real

`my_notifications`.

## Manifest rows claimed
Screens (2): `notifications`, `notification-settings`
Actions (2): `mail_notification_read`, `mail_notification_prefs_save`

## Activity log events

`notification.read` (once per action, with the count), `notification.prefs` (the kinds and switches changed — never a phone number).
The passes log nothing (design §6.6: polling and passes are never logged); `worker_passes` records their counts.

## Requirements claimed
MUSTs (0): none — notices carry no mail; the oversight MUSTs are oversight.md's

## Out of scope for this slice

Push notifications to a phone app; a daily digest for people (the kit's `digest` and `away_minutes` columns stay unused for people);
the Mail admins' settings page (slice 10).

## Proof

`tests/phase3/slice9/run.sh`: scratch `ml_dev9`; `php -S` on 9201, the fake kernel on 9202 (its `notify/sms.php` records texts and can
answer `503 no_sender`), the fake MaluMail REST on 9203 (`/v1/send` records each mail); then a user-level Apache on 9201; Playwright at
375 and 1280.

1. New mail in a chosen mailbox → one notice; two more within 30 minutes → the same notice says 3; a muted conversation, one's own
   sent mail and a mailbox not chosen → none; a mailbox the person can no longer open → none.
2. A held message → `held_mail` to each reviewer (the overseers; the person for D12), **not** to the Clerk, the agent or a Mail admin
   for a person's mailbox; a pause's re-hold by the mailbox's own overseer → none.
3. Expiry → `review_decided`; Unscreened → `unscreened` to the primary overseer; a credential trip → `credential_failed` to the Mail
   admins only; quota 92 % → one notice, 95 % → none more, 100 % → one more.
4. **No notice contains mail**: every title, body and outbox row of the run is scanned for the fixtures' subjects, senders' names and
   body words — none found.
5. Delivery: email rows sent through the fake `/v1/send` once (dedupe), a `502` retried then `failed`; text rows sent to the fake K6,
   a `503 no_sender` → `skipped`; texts only for `text_kinds`.
6. The Clerk's note: at `clerk_note_at` on a weekday, one `digest` per overseer covering only their mailboxes, counts and links only;
   a Saturday → none; a second pass the same day → none; nothing to say → none.
7. The screens: mark one read by opening it, mark all read, the unread filter, the bell's count and menu; settings saved and read
   back; the text column hidden without a verified phone; 375 px with no horizontal scroll.
8. An agent's token on both actions → refused (`people`).

## Decisions taken here

1. **Notices carry no mail content**, anywhere (sending-and-oversight decision 5).
2. **New mail is coalesced per mailbox per 30 minutes** in one unread notice: a busy shared mailbox must not bury every other notice.
3. **A held notice is not sent for a pause's re-hold** when the mailbox's own overseer paused it: they know.
4. **Opening a notice marks it read through `hx-post`, never a GET with a side effect.**
5. **Texts default to held mail only** (`text_kinds = {held_mail}`, db/007): a text is for what waits on a person.

## Open questions

None for the owner.

## Changes this spec asks of the shared documents

- **The manifest**: `mail_notification_prefs_save` → params `email_enabled (yes or no), text_enabled (yes or no), kind[] (the notices that
  may mail), text_kind[] (the notices that may text), new_mail_mailbox[] (mailbox ids)` (the column's own name, `text_enabled`, replaces
  `sms_enabled`).
- **Done in `db/015_sending_oversight.sql`**: `ml_notify()`'s text row (deduplicated by `t<notification id>`), `ml_notify_coalesce()` with
  `notifications.item_count`, the held-mail trigger and the expiry notice (proven by `db/proof/phase1_sending.sql`).
- **The tool surface**: none (`my_notifications` stands; `notice_status` is the shell's).
