# Slice 7 — Delegation and access

Who may open a mailbox and at what level, made visible and changeable: the **Access** page of every mailbox (who can open it and
why), the **grant** form with the record picker (a person, a department, an agent; `view` · `triage` · `send` · `full`), **revoke**,
**break-glass** on a person's mailbox (a Mail admin's emergency row: one person, a reason, at most 30 days, the owner told at once),
the **access log** (every view of a mailbox by anyone but its owner), and the mailbox's **settings** — among them a person's two
switches, *Let the Mail Expert read my mail when I ask* (`ai_read`) and *Share with applications* (the K7 share), both off by
default for a person's mailbox. Design §3 (the whole section), §4, §6.2 (`mailbox_permissions`), §6.6 (the access log), §9 (Access,
Mailbox settings), D9, D12. Not an exemplar: it copies slice 1's CRUD shape. Prerequisites: slices 1–3 (a mailbox, the list, the
conversation whose opening writes the access log).

**Sending on behalf** is a level, not a feature here: `send` lets a delegate send as the mailbox, and slice 4 writes `From` (the
mailbox's identity) and `Sender` (the delegate) — `sending-and-oversight.md`.

## Divergences from the design

1. **The access log is readable by the owner, the Mail admins (`access.audit`), and — on an agent's or a shared mailbox — the people
   holding `full` on it** (db/011's `mcp_mailbox_access_log`). Design §3 says "its owner and the Mail admins"; an agent's or shared
   mailbox has no owner who is a person, so its `full` holders (the overseers) stand in. The tool surface's `mailbox_access_log`
   row says the same after reconciliation.
2. **An expiring row is logged** (`permission.expire`, with `mailbox_id`) by the worker's pass — design §6.5 lists the pass but not
   its trail; break-glass that ends silently would leave the owner unsure it ended.

## Who may do what

| Act | A person's mailbox | An agent's or a shared mailbox | Never |
|---|---|---|---|
| see Access | the owner; a Mail admin | its `full` holders; a Mail admin | `view`/`triage`/`send` holders see their own row only |
| grant a row | **the owner** (any principal; an agent at most `send`, a CHECK) · **a Mail admin as break-glass** (one person, a reason, ≤ 30 days, the owner told) | a person with `full` (an overseer), a Mail admin | **every agent** (`ml_require_person()`); a department row never admits an agent |
| revoke | the owner (rows they made, not break-glass); a Mail admin (any) | a person with `full`; a Mail admin | every agent |
| read the access log | the owner; a Mail admin | `full` holders; a Mail admin | — |
| switch `ai_read`, *share with applications* | **the owner alone** | a person with `full`; a Mail admin | every agent; a Mail admin on a person's mailbox |
| the other settings (undo, view, images, density, shortcuts) | the owner — kept per mailbox | a person with `full` | agents |

## Screens

| Screen | 375 px | 1280 px | States |
|---|---|---|---|
| `mailbox-access` | sections: **Owner**; **Shared with** — cards per row: the principal (avatar, name, kind), the level chip, who granted and when, expiry, Revoke (✔); **Break-glass** rows in a red card with reason, expiry and who; **By role** (agent and shared mailboxes): the Mail admins at `full`, the Clerk at `triage` — each with "why" in a sentence; the button *Share this mailbox* | the same in a two-column grid | a `view`-level reader sees only "You can read this mailbox (view)" and who shared it |
| `access-grant-new` | a full-page form: Who (the record picker over people, departments and — on agent and shared mailboxes, or a person's at most `send` — agents), Level (four radio cards with one sentence each: *view never changes the mailbox* …); on a person's mailbox for a Mail admin who is not its owner, the **break-glass** form instead: one person, a reason (required), an expiry (default 7 days, at most 30), the line *"{owner} is told at once."* | two columns | the database's refusal sentence shown on the form (e.g. "An agent reaches a person's mailbox at most at send") |
| `mailbox-access-log` | a list: who, what (opened the list · a conversation · an attachment · the original · an MCP read · a K7 read · break-glass), when, through what (web · MCP · K7, the consumer named) — filter by person and since | a table | empty → "No one but you has looked." |
| `mailbox-settings` | sections: Reading (conversation view, images ask/always, density, shortcuts), Sending (undo seconds 0–30), and on a person's mailbox **Privacy**: the two switches, each with its sentence (*"When you ask the Mail Expert about your mail, it may read this mailbox — only in conversations you start."* / *"Other applications of the business (the CRM, Help Desk) may see subjects and short snippets of conversations with an address."*) | the same | the switches absent for anyone but the owner |

HTMX: revoke posts with `hx-confirm` and swaps the card out; the grant form is a full page landing on Access with a flash; the
settings form saves in place and returns the section.

## Files (exactly these)

- `app/features/access/{queries.php,views/access.php,views/grant-form.php,views/log.php}`, `app/features/settings/{queries.php,views/settings.php}`
- `html/m/access.php`, `html/m/access-new.php`, `html/m/access-log.php`, `html/m/settings.php`
- `html/access/grant.php`, `html/access/revoke.php`, `html/mail/settings-save.php`
- `bin/worker.php` (the `permissions_expire` step made real)
- `tests/phase3/slice7/{run.sh,check.php,browser.mjs}`

## Query functions and mail-service calls (signatures fixed)

- `access_rows(PDO, int $mailbox): array` — `mcp_mailbox_permissions` plus the implicit holders (owner, Mail admins on agent
  mailboxes, the Clerk) with their reason, each from `ml_mailbox_level()`'s own rules.
- `access_grant(PDO, int $by, int $mailbox, string $kind, int $principal, string $level, ?string $reason, ?string $expires): int` —
  `ml_permission_grant()` through `ml_guard()`; **break-glass is the database's decision** (the trigger), not the form's.
- `access_revoke(PDO, int $by, int $mailbox, int $permission): void` — reads the row's kind and principal, then `ml_permission_revoke()`.
- `access_log(PDO, int $mailbox, ?int $who, ?string $since, int $limit): array` — `mcp_mailbox_access_log`.
- `mailbox_settings_save(PDO, int $by, int $mailbox, array $settings, ?bool $aiRead, ?bool $share): void` — db/012's
  `ml_mailbox_settings_save()`.
- No mail-service call: access is the database's.

## MCP tools this slice makes real
`mailbox_access`, `mailbox_access_log`, `mailbox_trail`

## Manifest rows claimed
Screens (4): `mailbox-access`, `access-grant-new`, `mailbox-access-log`, `mailbox-settings`
Actions (3): `mail_permission_grant`, `mail_permission_revoke`, `mail_mailbox_settings_save`

## Activity log events

`permission.grant` (mailbox, principal kind and id, level, expiry — **break-glass rows as `permission.break_glass`**, which the
access log shows), `permission.revoke`, `permission.expire` (the worker), `mailbox.settings` (which keys changed; `ai_read` and
`share_with_apps` named when they change).

## Requirements claimed
MUSTs (3): `R14`, `R52`, `R54`

- R14 — delegation roles per mailbox: the four levels as Gmail's delegation, in the estate's permission rows.
- R52 — the access log of every open by anyone but the owner (lists, conversations, attachments, the original, MCP and K7 reads).
- R54 — the "AI may read" switch, on for agent mailboxes and **off for a person's** until they turn it on.

## Out of scope for this slice

The From/Sender of a delegate's send (slice 4); the oversight of agent mailboxes (slice 5); the K7 share's answers (slice 8 — this
slice only flips the switch); notifications other than break-glass's immediate notice, which db/010 already sends (slice 9 shows it).

## Proof (`tests/phase3/slice7/run.sh`: scratch database `ml_dev7`; php -S on 9201, the fake kernel on 9202, the mail service on 9206 with the fixtures; then a user-level Apache; Playwright)

1. Pat (owner) shares `pat@` with Jo at `triage` and with the Sales department at `view`; Jo sees it in the rail; a Sales agent does
   **not** (a department row admits people only); Jo can archive (triage) but not send.
2. Pat grants the Scheduler agent `send`: allowed; `full`: refused with the database's sentence.
3. Ada (Mail admin, not the owner) opens `pat@`'s Access: she sees only the break-glass form; granting herself `view` without a reason
   or with 40 days is refused; with a reason and 7 days it is made, **Pat is notified at once**, and the row is red.
4. Ada opens Pat's Inbox through the break-glass row: the access log shows `mailbox.view` once per half hour and every conversation
   opened; Pat sees it; Jo (triage) cannot open the log.
5. The worker's expiry: a row set to expire (backdated in the proof) is gone after a pass, logged `permission.expire`.
6. Revoke: Pat revokes Jo; Jo's next request answers 404 for the mailbox; Pat cannot revoke Ada's break-glass row (only a Mail admin).
7. Settings: Pat switches `ai_read` on; Ada cannot see or switch it; an agent's action token is refused `mail_mailbox_settings_save`;
   on an agent mailbox its overseer may switch `share_with_apps`.
8. Every action refused to an agent's token (`people`), and refused in SQL when called directly as the agent.
9. Playwright at 375 and 1280: Access, the picker, the break-glass form, the log.

## Decisions taken here

1. **Overseers read an agent's or shared mailbox's access log** (Divergence 1).
2. **Expiry is logged** (Divergence 2).
3. **The break-glass form replaces the grant form** for a Mail admin on a person's mailbox — one form per situation, never a checkbox
   that turns a share into an emergency.

## Open questions

None for the owner.

## Changes this spec asks of the shared documents

- db/012: `ml_mailbox_settings_save(p_mailbox bigint, p_by bigint, p_settings jsonb, p_ai_read boolean, p_share boolean)` —
  `ml_require_person(p_by)`; `ai_read`/`share_with_apps` on a person's mailbox only by its owner, on agent/shared by a decider or
  `mailboxes.manage`; the other keys by a `full` holder, merged into `mailboxes.settings` from an allow-list of keys
  (`undo_seconds` 0–30, `conversation_view`, `images`, `density`, `shortcuts`).
- db/012: `ml_permissions_expire()` writes one `activity_log` row (`permission.expire`, `source 'system'`, `mailbox_id`, the principal)
  per row it deletes.
- The tool surface: `mailbox_access_log` — "the owner, the Mail admins and, on an agent's or shared mailbox, its `full` holders".


## Built and proven (2026-10-17)

Built by a Sonnet 5.5 worker: the four screens (`mailbox-access`, `access-grant-new`, `mailbox-access-log`, `mailbox-settings`), the three actions
(`mail_permission_grant`, `mail_permission_revoke`, `mail_mailbox_settings_save`), the worker's `break_glass_expiry` pass made real, the tools
`mailbox_access`, `mailbox_access_log` (records) and `mailbox_trail` (activity). Registry: 41/45 screens, 63/67 actions built.

**Proven** (php -S and `ML_APP=apache`, both identical): `tests/phase3/slice7/run.sh` checks 138, tools 26, browser 29; `tests/phase0/schema.sh`
now 43 more checks in `db/proof/slice7_access.sql`.

**Found and fixed**
1. `ml_mailbox_settings_save()` refused `page_size` (declared by the manifest, read by the list) and did not type the yes/no keys: db/020 re-creates it
   with the same signature and rules (who may save and switch is unchanged) adding `page_size` 25/50/100 and boolean checks.
2. `mcp_mailbox_access_log` named no consumer for a K7 read: db/020 appends a `consumer` column (from the row's own `after`); its filter is untouched.
3. Neither implementation of the wall (`ml_mailbox_level()`, db/017's set form) was changed, so the 576-case equality proof stands unchanged.
4. A Mail admin's tool read of a person's mailbox (`mailbox_access`, `mailbox_trail`) needs sight without a level: `_mailbox_id_seen()` in
   `mcp/records_server.py` (mcp_mailboxes already admits a Mail admin); the views still decide the rows.
5. Phase 0's worker check and Phase 2's placeholder checks followed the built screens (the placeholder checks now use `people/{correspondent}`).

**Decisions taken here (within the spec)**
1. A Mail admin on a person's mailbox may open Access, the log and Give emergency access, never its settings (404, the schema's rule).
2. Break-glass expiry is a date (default 7 days, at most 30; a bare date means the end of that day); the reason must be at least ten characters.
3. `mailbox_trail` (activity) is the owner's and the Mail admins' only; `mailbox_access_log` (records) also admits an agent or shared mailbox's `full` holders.
4. The rail links Access to everyone and Settings to `full` holders; `page_size` is a setting of the form.

**Slice 3 Apache flake**: not reproduced in this session (two Apache runs green); the earlier review's fix (polling the log) stands.
