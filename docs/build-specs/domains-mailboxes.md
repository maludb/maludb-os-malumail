# Slice 1 — Domains, mailboxes and forwarding (THE CRUD EXEMPLAR)

The Mail admin's half of design §1 "administration": which of the MaluMail customer's hosted domains this business **serves** and
which are **internal** (design §5's test), the **mailboxes** of those domains as cards (create, connect an existing one, suspend,
reactivate, quota, reset, intake owner, share with applications, overseers), and the **forwarding addresses** (MaluMail's aliases,
outside destinations flagged and typed back). It is the CRUD exemplar every later slice copies: list → card → full-page form → POST
handler → the database's own function → `log_activity()` → land on the record. Every MaluMail call goes through **the mail service's
administration plane** (`services/mailer/mailbox_mcp.py`, Phase 0's skeleton, extended here). Design §1, §3 ("Credentials are never
seen…"), §4, §6.2, §9 (Admin, Domains, Mailboxes, Forwarding), D2, D3, D13. Prerequisites: Phase 2 (`sso-shell.md`), the mail
service's skeleton (`tests/mailer/run.sh`), db/006 and db/010's administration functions.

## Divergences from the design

1. **A person's password is revealed by the mail service only for a mailbox of kind `person`, decided by the service itself.**
   Design §3/§4 say the password is shown once on the admin's screen for a person's mailbox and sealed for an agent's or a shared
   one. Phase 0's skeleton returns only sealed boxes. Slice 1 adds the rule **in the service**, not in PHP: `create_mailbox` and
   `reset_mailbox_password` take a service-level argument `for` (`person` · `agent` · `shared`); for `reset` the service reads the
   mailbox's `kind` from the database and ignores the caller's word; for `create` the row does not exist yet, so the service creates
   the mailbox row itself in the same request (below) with the kind it was told and the owner it was given — a person's kind
   needs a **person** owner, which the database checks. A person's password comes back once as `revealed` and **nothing is stored**
   (D3: no credential for a person's mailbox until MM1); an agent's or a shared mailbox's is sealed, stored and never shown. So a
   compromised web server can still reset a person's mailbox (logged, noticed by the person's devices failing) but can never carry
   an agent's or a shared mailbox's credential away.
2. **The mail service writes the mailbox row and its credential** for `create` and `connect` (through `ml_mailbox_create()` and
   `ml_credential_store()`, as `malumail_rw`) in the same request that talks to MaluMail, and answers the new mailbox's id. Design
   §6.2 has PHP seal the credential; with the sealed box made inside the service, PHP never even sees the ciphertext — one fewer hop.
3. **Connect, before MM1, is for agent and shared mailboxes only**, and it **resets** the mailbox's password at MaluMail (the
   application cannot learn an existing password). Any phone or desktop client using that mailbox stops until given the new one —
   hence the confirmation. A person's existing mailbox is registered without a credential and shows "waits on MaluMail's
   application passwords (MM1)".

## Who may do what

| Action | Who | Refused to |
|---|---|---|
| serve / unserve a domain, internal or not | `domains.manage` (super-admins) | everyone else; every agent |
| create, connect, suspend, reactivate, quota, reset, update a mailbox | `mailboxes.manage` | everyone else; every agent |
| forwarding addresses | `aliases.manage` | everyone else; every agent |
| see the admin screens | the Mail admins | — |

Holding these rights **never opens a person's mail** (D9): the admin's mailbox page shows a person's mailbox's administrative facts
(address, quota, state, credential state, intake owner) and no message, count of unread, or subject.

## Screens

| Screen | 375 px | 1280 px | States |
|---|---|---|---|
| `admin-domains` | cards per hosted domain: name, Served / Internal switches as small forms, SPF · DKIM · DMARC chips, mailbox and alias counts | a table with the same columns | MaluMail unreachable → the last read with its time and "MaluMail did not answer"; a domain no longer hosted by the customer → greyed with "no longer hosted" |
| `admin-mailboxes` | cards (the owner's preference): address, kind icon, owner or primary overseer, quota bar (use from the index's `GETQUOTAROOT`, slice 2), sync state chip, credential chip (ok · failed · none · waits on MM1), intake chip; filters as chips | a grid of cards, three across | empty → "No mailboxes on the served domains yet" and New mailbox |
| `admin-mailbox-new` | a full-page form: domain (served ones), local part, kind (person · agent · shared), display name, owner (the record picker: people for a person's, agents for an agent's), quota, overseers and the primary overseer (agent's, the picker) | two columns | the created page: for a person's mailbox **the password once**, in a box with Copy and "Give this to {name} for their phone and computer — it is not shown again", then the mailbox's page |
| `admin-mailbox-view` | sections: facts; Suspend/Reactivate; Quota; Reset password (✔; a person's shown once); Connect / Reconnect the webmail (agent or shared); the credential's state and its last error; intake owner (the proposed reader from MaluMail's webhook record, Confirm); Share with applications; overseers and the primary overseer (agent's) with links to its Access page | the same in two columns | a suspended mailbox's banner; a failed credential's banner with Reconnect |
| `admin-aliases` | cards: the alias, its destinations, an **Outside** chip when any destination is outside the served domains | a table | — |
| `admin-alias-new` · `admin-alias-view` | a full-page form: domain, local part, destinations (one per line, ≤ 20); an outside destination makes a "Type the outside addresses to confirm" field appear | the same | MaluMail's refusal sentence (an address that exists as a mailbox or an alias, platform-wide) shown on the form |

HTMX: the switches on `admin-domains` post and swap the card; every form is a full page that lands on the record with a flash. No
modal; `hx-confirm` on reset, suspend, connect, unserve and delete.

## Files (exactly these)

- `app/features/admin/{domains.php,mailboxes.php,aliases.php}` (queries and the mail-service calls below), `app/features/admin/views/`
  `{domains,mailboxes,mailbox-form,mailbox-view,mailbox-created,aliases,alias-form}.php`
- `html/admin/domains/{index.php,serve.php}`, `html/admin/mailboxes/{index.php,new.php,view.php,create.php,connect.php,update.php,`
  `suspend.php,reactivate.php,quota.php,reset.php}`, `html/admin/aliases/{index.php,new.php,view.php,create.php,update.php,delete.php}`
- `services/mailer/mailbox_mcp.py` (the `for` rule, `create`/`connect` writing the rows), `services/mailer/store.py`
  (`mailbox_create_rows()`, `credential_store()`), `services/mailer/server.py` (`POST /v1/admin/mailboxes/create`,
  `/v1/admin/mailboxes/connect` beside the passthrough)
- `tests/fixtures/fake_mailbox_mcp.py` (alias and quota behaviour already there; the webhook record for intake proposals)
- `tests/phase3/slice1/{run.sh,check.php,browser.mjs}`

## Query functions and mail-service calls (signatures fixed)

PHP (`app/features/admin/*.php`), every one through `ml_guard()`:
- `admin_domains(PDO): array` — `mcp_domains` joined to the last `list_hosted_domains` answer (cached in `ml_domains.flags_read_at`).
- `admin_domain_serve(PDO, int $by, string $domain, bool $served, ?bool $internal): void` — db/012's `ml_domain_serve()`.
- `admin_mailboxes(PDO, array $filters): array` · `admin_mailbox(PDO, int $id): array` — `mcp_mailboxes` (admin columns).
- `admin_mailbox_update(PDO, int $by, int $mailbox, array $fields): void` — `ml_mailbox_intake_set()`, `ml_primary_overseer_set()`,
  and db/012's `ml_mailbox_share_set()` / display name.
- `admin_mailbox_suspend(PDO, int $by, int $mailbox, bool $on, ?string $reason)` — `ml_mailbox_suspend()` **and** the service's
  `set_mailbox_active` (MaluMail rejects mail and logins while inactive); reactivate the reverse.
- `admin_aliases(PDO, ?string $domain): array` — the service's `list_aliases` per served domain, `ml_aliases` cache refreshed.

The mail service (`app/mailer.php`'s `mailer_call()`):
- `POST /v1/admin/mailboxes/create` `{domain, local_part, kind, display_name, owner, quota_gib, overseers[], primary_overseer}` →
  `{mailbox_id, address, revealed?}` — MaluMail's `create_mailbox`, then in one transaction `ml_mailbox_create()` (+ grants for further
  overseers), and for agent/shared `ml_credential_store()` with the sealed box. A failure after MaluMail created the mailbox leaves
  it at MaluMail and answers `created_at_malumail_only` with "Connect it from the list" — never a password.
- `POST /v1/admin/mailboxes/connect` `{address, kind, display_name, owner, overseers[], primary_overseer}` → `{mailbox_id}` — for
  agent/shared: `reset_mailbox_password` sealed and stored, then the probe (`test_login`); for a person's: the row only.
- `POST /v1/admin/reset_mailbox_password` `{address}` → `{revealed}` for a person's mailbox (kind read from the row), else
  `{credential: stored}` with a new `mailbox_credentials` row replacing the old (the old one `revoked`).
- `POST /v1/admin/set_mailbox_quota`, `set_mailbox_active`, `list_aliases`, `create_alias`, `update_alias`, `delete_alias`,
  `list_hosted_domains`, `list_mailboxes`, `get_mailbox_webhook` — the passthrough as built.

## MCP tools this slice makes real
`domains_list`, `find_aliases`, `mailbox_health`, `mailbox_get`, `find_mailboxes`

## Manifest rows claimed
Screens (7): `admin-domains`, `admin-mailboxes`, `admin-mailbox-new`, `admin-mailbox-view`, `admin-aliases`, `admin-alias-new`, `admin-alias-view`
Actions (11): `mail_domains_serve_set`, `mail_mailbox_create`, `mail_mailbox_connect`, `mail_mailbox_update`, `mail_mailbox_suspend`, `mail_mailbox_reactivate`, `mail_mailbox_quota_set`, `mail_mailbox_password_reset`, `mail_alias_create`, `mail_alias_update`, `mail_alias_delete`

## Activity log events

`domains.serve`, `mailbox.create`, `mailbox.connect`, `mailbox.update`, `mailbox.suspend`, `mailbox.reactivate`, `mailbox.quota`,
`mailbox.password_reset` (who, which mailbox — **never the password, never "revealed"'s value**), `alias.create`, `alias.update`,
`alias.delete` (the destinations' domains, and `outside: true` when any is outside); the mail service's own `mailer.admin.*` rows.

## Requirements claimed
MUSTs (1): `R38`

- R38 — one mailbox per agent, created by a super-admin through MaluMail's API; a named human overseer (the design's "sponsor") per
  agent mailbox; agents never administer.

## Out of scope for this slice

Who may open a mailbox beyond its owner and overseers (slice 7), the sync and its health screen (slices 2, 10), the policy of an
agent's mailbox (slice 5), deleting a mailbox (MaluMail has no `delete_mailbox`; Extended).

## Proof (`tests/phase3/slice1/run.sh`: scratch database `ml_dev1`; php -S on 9201, the fake kernel on 9202, the fake MaluDB + MaluMail REST on 9203, the mail service on 9206 against the fake Mailbox MCP and the IMAP fixture; then a user-level Apache; Playwright)

1. A Mail admin serves `business.example`, marks it internal; unserving it hides its mailboxes from the admin lists and refuses
   every administration call on it (the service's wall).
2. Create an **agent** mailbox with an owner agent, two overseers and a primary: the row, the identity, the default policy, the
   Clerk's `triage` row, both overseers' `full` rows; the credential sealed and stored; **no password in the page, the JSON, the
   log, or the activity log** (the fake's issued password searched for everywhere); the probe answers ok against the fixture.
3. Create a **person's** mailbox: the password shown once on the created page; reloading shows it no more; **no credential row**;
   the mailbox shows "waits on MM1"; JSON mode and an action token get no password (the service refuses `for: person` reveals to a
   call whose actor is an agent, and the handler never asks under an action token).
4. A `for: person` reset of an agent's mailbox reveals nothing: the service read the kind from the row.
5. Connect an existing shared mailbox: ✔ confirmation, reset sealed, probe ok, the old credential `revoked`.
6. Suspend: MaluMail's `set_mailbox_active false` called, `suspended_at` set, sync paused; reactivate restores both.
7. Aliases: create with an inside destination; with an outside one the form insists on typing it back; the card shows Outside;
   MaluMail's refusal sentence for a taken address shown on the form.
8. A Mail user, an agent's action token and a dept-admin are refused every action (403 / `people`); a Mail admin's mailbox page for
   a person's mailbox shows no mail fact.
9. **Live, once, on the test domain** (with the owner's customer key, open question 1): `list_hosted_domains`, `list_mailboxes`, and
   one `create_mailbox` of an agent mailbox on `mboxtest.example` — confirming that MaluMail names the field `password` — then
   suspended. Nothing else is created at MaluMail.
10. Playwright at 375 and 1280: the cards, the forms, the once-shown password box, no horizontal scroll.

## Decisions taken here

1. **The service decides what a reset reveals**, from the mailbox's kind in the database (Divergence 1) — a compromised web server
   cannot extract an agent's or a shared mailbox's credential.
2. **The service writes the mailbox and credential rows** on create and connect (Divergence 2) — the sealed box never reaches PHP.
3. **Connect resets** an agent's or shared mailbox's password, behind a confirmation that names the cost (Divergence 3).
4. **Suspend is both sides**: the row's `suspended_at` and MaluMail's `active false` — design §5 calls suspension the hard stop.
5. **Outside destinations are typed back**, not ticked — forwarding a business address outside is the one administrative act that
   sends mail out by itself.

## Open questions

1. **Which customer key may the live proof use?** The proofs never hold MaluMail's production customer key. Recommended: a key the
   infrastructure side scopes to `mboxtest.example` (MaluMail item MM7's domain-scoped key, asked early), kept in a mode-600
   file the owner's handoff names — never in this repository.

## Changes this spec asks of the shared documents

- db/012: `ml_domain_serve(p_by bigint, p_domain citext, p_served boolean, p_internal boolean)` (`domains.manage`, upsert into
  `ml_domains`, refusing to unserve a domain whose mailboxes still hold live credentials without `p_force`);
  `ml_mailbox_share_set(p_mailbox bigint, p_by bigint, p_on boolean)` (the Mail admin for agent/shared mailboxes; **the owner** for
  a person's — slice 7 calls it too); `ml_mailbox_display_name_set(p_mailbox, p_by, p_name)`.
- `services/mailer/`: the `for` rule and the two composite admin calls (this slice's Files).
- The design §16 records Divergences 1–3.
