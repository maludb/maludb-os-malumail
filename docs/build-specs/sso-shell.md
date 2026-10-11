# Build spec: Phase 2 — sign-on, the mirror, the shell, the mail service's client (before any slice)

What exists at the end: a person clicks MaluMail on the launcher — or types `webmail.<domain>` on a phone — and lands, signed in, in
the finished shell of an application whose mail screens are placeholders:

- the nxl layout with **the mailbox rail** on the left (the mailboxes the person can open, from `ml_my_mailbox_ids()`; each with
  its views and folders once slice 2 fills them; labels; a Compose button), the header's search box, the application switcher, the
  bell, the user menu, the command bar;
- at 375 px the rail becomes a drawer behind the menu button, and a bottom tab bar holds **Inbox · Search · Compose · Me**;
- the **monitoring notice** (design §3) stands between a person and their mail until they acknowledge its current version;
- a person admitted by the kernel with no MaluMail role is told why, in a sentence (`ml_right_refusal()`);
- the mirror refreshes every minute; every request is logged as the kit logs it; the command bar answers through the kernel;
- the record picker is in the shell with its `member`, `department`, `agent` and `mailbox` sources;
- **PHP reaches mail only through `app/mailer.php`** — the mail service's signed client (`docs/build-specs/mail-service.md` is the
  protocol) — which answers `mailer_down` in a sentence when the service is down;
- every later screen answers as a placeholder naming the slice that builds it;
- the worker runs a pass every minute whose steps are stubs but the kit's; the mail service runs its empty passes (Phase 0's
  skeleton).

**The kit is in the repo already (Phase 0)** and proven without a kernel (`tests/phase0/run.sh`, 100 checks); **the mail service's
skeleton too** (`tests/mailer/run.sh`, 62 checks). This phase wires the shell around them and proves it under php -S and a user-level
Apache at 375 and 1280. It follows Developer's Phase 2 (`/srv/apps/developer/docs/build-specs/sso-shell.md`), whose kit is MaluMail's
fork, and Boards' (the user-level Apache rendering the deploy vhost).

Schema it runs on (db/001–012, never modified here): `members` (+ `is_agent`), `departments`, `department_members`,
`directory_sync_state`, `sso_nonces`, `member_sessions` (db/001); `activity_log` (+ `token_id`, `mailbox_id`) (db/002);
`mcp_access_tokens`, `key_usage`, `mcp_resolve_token()` (db/003); `ml_rights` (+ `held_by`), `ml_roles`, `ml_role_rights`,
`ml_member_roles()`, `ml_member_has_right()`, `ml_has_right()`, `ml_right_refusal()`, `mcp_app_roles` (db/004); `ml_settings`,
`ml_settings_notice_version()`, `ml_notice_acknowledge()`, `ml_notice_acknowledged()` (db/005–006); `mailboxes`, `ml_level()`,
`ml_my_mailbox_ids()` (db/006); `notifications` and `worker_passes` (db/007).

## Divergences from the design

1. **The bottom tab bar is Inbox · Search · Compose · Me**, not the estate's Home · List · Search · Me: Inbox is the home of a mail
   application, and Compose is the one action a phone needs from anywhere. The rail's drawer holds the rest.
2. **The notice gates mail, not the application.** A person who has not acknowledged the current version reaches `/notice`,
   `/tokens`, `/my/trail`, `/notifications` and the sign-out — and nothing that shows mail — until they do. Design §3 says "on first
   use"; gating the mail screens is how "first use" is made unmissable. An agent never acknowledges (it reads no notice): the gate
   is a person's.
3. **The rail lists mailboxes, not the estate's menu of groups.** MaluMail's screens are reached from the mailbox (its views,
   folders, labels, settings, access, policy) and from four top items gated by rights: **Oversight** (`mail.oversee`, shown only to
   someone overseeing at least one agent mailbox), **Templates**, **Admin** (`mailboxes.manage`), **Notifications**.

## Who may do what

| Who | Reaches |
|---|---|
| A person with no MaluMail role | the refusal page in a sentence, sign-out |
| A Mail user (`mail.use`) | the shell; the notice; their tokens (`tokens.own`), trail and notifications; every mailbox `ml_level()` admits |
| An overseer | the Oversight item (slice 5) |
| A Mail admin (`mailboxes.manage` …, super-admins only) | the Admin item (slices 1, 10); **no person's mailbox by holding the role** |
| An agent (an action token) | no screen; the actions its run is granted (the manifest's Agent column) |

## Screens

| Screen | 375 px | 1280 px | States |
|---|---|---|---|
| `home` (`/`) | redirects to the default mailbox's Inbox (`/m/{mailbox}/v/inbox`) | the same | no mailbox → a card: "No mailbox is open to you yet. A Mail admin connects one; until MaluMail issues application passwords (MM1), a person's own mailbox waits." with the Mail admins' names |
| `notice` (`/notice`) | the notice's text in a readable column; **Acknowledge** at the foot | the same, centred, 720 px | already acknowledged → the text and "Acknowledged on {date}" |
| `my-trail` (`/my/trail`) | a list of my events, newest first (what, mailbox, when) | a table | empty → "Nothing yet." |
| `tokens-list` (`/tokens`) | cards: name, last used, expires, Revoke; Mint a token (full-page form) | a table | a new token shown **once** on the page that minted it, with Copy |

**The layout** (`app/views/layout.php`): the nxl theme's fixed header and sidebar. The sidebar is **the rail**: per mailbox the
address and display name, the unread count (`mcp_mailboxes` → slice 2's counts; Phase 2 shows `—`), the kind's icon (person ·
agent · shared), and under the current mailbox its views (Inbox, Starred, Snoozed, Sent, Drafts, Scheduled, Outbox, All mail, Spam,
Trash), its folders and its labels (placeholders until slice 2/6). Below: Oversight, Templates, Admin, Notifications. The header:
the search box (`/search?q=`, the mailbox's own search when inside one), the application switcher (K31), the bell, the user menu
(My settings, My trail, Tokens, Sign out). **At 375** the sidebar is an off-canvas drawer; the tab bar is fixed at the foot.

**Placeholders** (`app/views/shared/placeholder.php`): every manifest screen not yet built answers 200 with the screen's name, what
it will do (the manifest's "When the user wants…") and the slice that builds it — so the rail's links never 404 during Phase 3.

## The mail service's client (`app/mailer.php`) — `docs/build-specs/mail-service.md` is the protocol

- `mailer_call(string $path, int $actorMemberId, array $args = [], int $timeout = 30): array` — POSTs to
  `http://127.0.0.1:ML_MAILER_PORT{path}` with the `X-ML-Mailer` header built exactly as `services/mailer/door.py` builds it
  (`v1.{ts}.{nonce}.{hex HMAC-SHA256(ML_MAILER_KEY, "v1.{ts}.{nonce}.POST.{path}.{hex sha256(body)}")}`), the body
  `{"actor": …, "args": {…}}`. Answers the decoded `result`; throws `MailerRefused` (the service's `code` and `message`, which the
  handler shows) or `MailerDown` (no answer → "The mail service is not answering; nothing was changed.").
- `mailer_health(): ?array` — `GET /health`, for `/api/v1/health` and the admin's sync screen.
- **PHP never holds a mailbox credential, the customer key or the sealing secret key.** It holds `ML_MAILER_KEY` (signing) and
  `ML_SEAL_PUBLIC_KEY` (sealing a credential a person types — none in v1 until MM1).

## Files (exactly these)

- `app/views/layout.php`, `app/views/shared/{rail,tabbar,placeholder,refused}.php`, `app/views/home/no-mailbox.php`
- `app/mailer.php` (the client above), `app/features/mail/wall.php` (`ml_require_mailbox(int $mailbox, string $level): array` — the
  mailbox row and the caller's level, 404 below `view`, 403 with the level's sentence below `$level`; `ml_rail(PDO): array`)
- `app/features/notice/{queries.php,views/notice.php}`, `html/notice.php`, `html/notice/acknowledge.php`
- `app/features/tokens/{queries.php,views/list.php,views/form.php}`, `html/tokens/index.php`, `html/tokens/new.php`,
  `html/tokens/mint.php`, `html/tokens/revoke.php`
- `app/features/trail/{queries.php,views/list.php}`, `html/my/trail.php`
- `html/index.php` (replaced: the redirect), `html/pick/index.php` (the record picker's sources)
- `html/assets/js/malumail.js` (the rail drawer, the tab bar, the placeholder links; nothing else yet)
- `tests/dev_router.php` (the canonical-URL rewrites of the manifest's Conventions), `deploy/apache-malumail.conf` (the same rewrites)
- `tests/phase2/run.sh`, `tests/phase2/browser.mjs`

## MCP tools this slice names

`notice_status`, `find_members`, `find_departments`, `find_mailboxes` (Phase 4 serves them; the shell's screens read the same SQL).

## Query functions and mail-service calls (signatures fixed)

- `ml_rail(PDO $pdo): array` — `SELECT … FROM mcp_mailboxes ORDER BY kind_order, address` (the caller's mailboxes, the wall in the view).
- `notice_current(PDO $pdo): array` (`ml_settings` text and version) · `notice_needed(PDO $pdo): bool` (`NOT ml_notice_acknowledged()`).
- `tokens_for(PDO $pdo, int $member): array` · `token_mint(PDO, int $member, string $name, int $days): array{id, secret}` (the kit's
  `mcp_access_tokens`, hash stored, secret shown once) · `token_revoke(PDO, int $member, int $id): void`.
- `trail_for(PDO $pdo, int $member, ?string $since, ?int $mailbox, int $limit): array`.
- `mailer_health()` only; no other mail-service call in Phase 2.

## Manifest rows claimed
Screens (4): `home`, `notice`, `my-trail`, `tokens-list`
Actions (3): `mail_notice_acknowledge`, `mail_token_mint`, `mail_token_revoke`

## Activity log events

`notice.acknowledge` (the version), `token.mint` (the token's id and name — never the secret), `token.revoke`; the kit's `sso.*`
and `screen.view` rows; **no row for the owner's own list views** (design §6.6).

## Requirements claimed
MUSTs (3): `R1`, `R15`, `R51`

- R1 — the rail is the mailbox switcher: every mailbox the person may open, unread counts (slice 2 fills them), no second login.
- R15 — every shell screen at 375 px; the drawer and the tab bar.
- R51 — the notice shown on first use and when its version changes, acknowledged and recorded.

## Out of scope for this phase

The lists and counts (slice 2), reading (slice 3), compose (slice 4), oversight (slice 5), the notice's editing (slice 10), the
notifications screens (slice 9), the MCP servers (Phase 4).

## Proof (`tests/phase2/run.sh`: scratch database `ml_dev2` via `tests/setup_dev.sh`; php -S on 9201, the fake kernel on 9202, the fake MaluDB + MaluMail REST on 9203, the mail service's skeleton on 9206 with the fake Mailbox MCP; then the same under a user-level Apache rendering `deploy/apache-malumail.conf` with the scratch ports; Playwright from `/var/www/web/node_modules`)

1. The kit's hand-off: a good hand-off lands on `/`; a replay, an expired token, another audience, an unknown member and a member
   with no grant are refused and logged (the kit's checks re-run).
2. `/` redirects a person with an own mailbox to its Inbox; a person with only a shared mailbox to that; a person with none sees the
   no-mailbox card naming the Mail admins.
3. The rail lists exactly `ml_my_mailbox_ids('view')`: Pat sees `pat@` and `sales@` (a row), not `clerk@` (an agent's) nor Ann's;
   a Mail admin sees the agent mailboxes, **never a person's** by the role.
4. The notice: an unacknowledged person reaching `/m/{mailbox}/v/inbox` lands on `/notice`; Acknowledge records the version; a new
   version (`ml_settings` text changed) asks again; an agent's action token is never gated.
5. Tokens: mint shows the secret once; the list never shows it again; revoke stops it at the records server's resolver; another
   person's token cannot be revoked (404).
6. `app/mailer.php` signs a call the skeleton accepts; with the service stopped every mail screen says "The mail service is not
   answering" and nothing else breaks; `/api/v1/health` reports `mailer: down`.
7. Every manifest screen URL answers 200 (its placeholder or itself) for its right holder and 404/403 as the manifest says; the
   canonical rewrites resolve under php -S and Apache alike.
8. Playwright at 375 and 1280: the drawer opens and closes, the tab bar navigates, no horizontal scroll on any shell screen.
9. `php bin/build_action_registry.php --check` and `tests/phase1/check_specs.sh` green.

## Decisions taken here

1. **The tab bar is Inbox · Search · Compose · Me** — mail's four verbs on a phone (Divergence 1).
2. **The notice gates the mail screens** for people until acknowledged — the only way "shown on first use" cannot be skipped by a
   deep link (Divergence 2).
3. **Placeholders answer 200**, naming the slice — Phase 3's rail never dead-ends, and the proof can walk every URL from Phase 2.
4. **`app/mailer.php` is written here**, not in slice 1, so every slice uses one client and one error vocabulary.

## Open questions

None for the owner.

## Changes this spec asks of the shared documents

- db/012: `mcp_members`, `mcp_departments` (the resolvers' views, the kit's columns only: id, display name, kind, email for people) —
  the tool surface names them.
