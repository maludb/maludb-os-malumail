# MaluMail Action Manifest

2026-10-11 · **Phase 1, for the checkpoint**

> The registry the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve against. A
> screen or action missing here cannot be reached by voice or by an agent — unfinished design, like a question with no tool. Pairs
> with `db/` (tables and functions), `docs/malumail-mcp-tool-surface.md` (the read tools) and the specs in `docs/build-specs/`.
> `bin/build_action_registry.php` generates `mcp/action_registry.json` from this file; an action whose file does not exist yet is
> registered but not exposed. **The approval categories here ARE `maludb-os.json` `approvals[]`**: three actions pause for every
> agent (`deletion`, design §5); every decision of a person is **refused** to agents (the Agent column says `people`, the handler
> calls `require_human()`, and the right's `held_by` or `ml_require_person()` says the same in SQL), never paused.
> `php bin/build_action_registry.php --check` (in every proof) fails when the registry or the approvals are stale. The installer
> reads the resolve block from `deploy/kernel-registry-malumail.json`.

## Conventions

### Screens
- **Ids** are kebab-case. **A mailbox is named by its id** in a URL (`/m/{mailbox}/…`); a folder by its id (`/m/{mailbox}/f/{folder}`);
  a conversation by `mail_threads.id` (`/m/{mailbox}/t/{thread}` — rebuildable, so a stale id answers "this conversation moved" and
  reloads the list); **a message by one token `{message}` = `(folder id, uidvalidity, uid)`** (design §8; a stale UIDVALIDITY answers
  "this message moved"). Outbound rows, drafts, reviews, labels, templates, identities, aliases and dispatches are integer ids; a
  domain is its name.
- **No row → no mailbox**: every `/m/{mailbox}/…` answers 404 for a mailbox the person may not open (design §3) — never 403, so an
  address never leaks.
- **Canonical URLs** need the rewrites in `deploy/apache-malumail.conf` (and `tests/dev_router.php`), Phase 2: `/m/{mailbox}/{rest}`
  → `m/{rest-as-file}.php?mailbox=` (`v/{view}` → `m/view.php?view=`, `f/{folder}` → `m/folder.php?folder=`, `label/{label}` →
  `m/label.php`, `t/{thread}` → `m/thread.php`, `msg/{message}/original` → `m/original.php`, `msg/{message}/body` → `m/body.php`,
  `msg/{message}/raw` → `m/raw.php`, `drafts/{draft}` → `m/compose.php?draft=`, `people/{correspondent}` → `m/person.php`, `compose` → `m/compose.php`, `identities/{identity}/edit` → `m/identity-form.php`,
  `policy` → `m/policy.php`), `/oversight/review/{outbound}` → `oversight/view.php?id=` (`/edit` → `form.php?id=`, `/body` →
  `body.php?id=`), and the estate's `/{x}/new` → `form.php`, `/{x}/{id}` → `view.php?id=`, `/{x}/{id}/edit` → `form.php?id=`.
- **Reading and replying are designed at 375 px first**: the lists, the conversation, compose, the review page. The admin and the
  settings work at 375 and are laid out for 1280. No screen is a modal: the record picker and `hx-confirm` are the two exemptions.
- **Prefill params** become query-string values the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity`, `data-record-id`, and **`data-mailbox`** (and `data-thread`,
  `data-message` where it shows one) on `#page-content`, so "reply to this", "archive this", "who can open this" resolve against the
  page the person is on.
- **Doors that are not screens** have no row here (design §8):
  - `GET /m/{mailbox}/changes?since=` — the cheap poll (slice 2);
  - `GET /m/{mailbox}/msg/{message}/body` — the sanitised body on its own sandboxed response (slice 3);
  - `GET /m/{mailbox}/msg/{message}/raw` — the `.eml` (slice 3); `GET /p/{signed}` — an attachment or a `cid:` image (slice 3);
    `GET /img/{signed}` — the remote-image door (slice 3);
  - `POST /m/{mailbox}/drafts/{draft}/files` — an attachment for a draft (slice 4); `GET /m/{mailbox}/complete?q=` — recipient
    autocomplete (slice 8);
  - `GET /pick/{kind}` — the record picker over members, departments, agents and mailboxes (Phase 2); `/sso`, `/sso/logout`,
    `/api/v1/health`.

### Actions
- **Name** `mail_{entity}_{verb}` — **every MaluMail action begins with `mail_`** because the kernel's Actions MCP has one namespace
  for every application (K55); a bare verb names the mail itself (`mail_send`, `mail_archive`). The **log event** `{entity}.{verb}` is
  what the handler writes with `log_activity()` (design §6.6) — with `mailbox_id` on every row, **never a body, never a subject of a
  person's mailbox**. The three `deletion` actions keep log events of their own: the kernel's policy matches the log event.
- **Endpoints** are POST to the file given, with `ml_handler_begin()` (`require_post()`, `require_login()`, `verify_csrf()` or the
  relayed action token), the right (`require_right()`), **`require_human()` on every `people` row**, and — where the action names a
  mailbox — `ml_require_mailbox($mailbox, $level)`, which answers 404 for a mailbox the caller may not open and 403 with
  `ml_level_name()`'s sentence for one opened below the level. Every handler wraps the database's own function in `ml_guard()`
  (`ml_mailbox_create()`, `ml_review_decide()`, `ml_permission_grant()`, `ml_thread_snooze()` …) **or the mail service's client**
  (`app/mailer.php` — the signed door; PHP never speaks IMAP, SMTP or the Mailbox MCP), then calls `log_activity()`,
  `emit_action_status()` and `ml_done()`.
  **PHP never re-implements a rule the database keeps:** the wall and the levels, the intake rule, the hold reasons, the refusals,
  the outbox's status machine, the break-glass rule, who decides.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the last
  parameter). **A param named for a record accepts an id or a name**, resolved through the tool surface's resolvers (the resolve
  block): `mailbox`; `folder`, `to_folder`; `label`; `thread`; `correspondent`; `template`; `identity`; `member`, `person`, `agent`,
  `owner`, `overseer`, `primary_overseer` (members); `department`. **No plain-value parameter carries one of those names** (the
  kernel's actions server resolves a resolve entity's params registry-wide — Shareholder Intelligence's Phase 4 finding). These are
  ids or literal values only: `message` (a message key id, `mail_message_keys.id`, from `mail_search` or `thread_read`), `draft`,
  `outbound`, `permission`, `alias` (its address), `domain` (its name), `dispatch`, `notification`, `token`. **A repeated value never
  holds a comma** (the actions server splits a repeated parameter on commas — Boards' finding): addresses and ids only.
- **Who** is enforced in the endpoint: **a right** through `ml_has_right()` (design §3, db/004 — `mail.use`, `mail.oversee`,
  `tokens.own`, `domains.manage`, `mailboxes.manage`, `aliases.manage`, `access.audit`, `settings.manage`, `agents.manage`) and **a
  level** on the mailbox (`view` · `triage` · `send` · `full`, `ml_mailbox_level()`). Rights held `super_admins` are a super-admin's
  whatever roles they hold; rights held `people` are never an agent's.
- **Agent**: `yes` admits an agent's run token; `people` means the handler calls `require_human()` and the SQL refuses an agent too
  (`ml_require_person()` / `ml_is_decider()`), so an agent is refused twice. The 41 `people` rows are design §5's list plus the
  kit's personal rows and a person's own settings, identities, templates and sensitivity marks (tokens, notifications, the notice, a person's settings).
- **Undo** is the inverse; `restore prior` means the same action with the earlier values; `—` means it cannot be undone and the
  reply says so.
- **Confirm (✔)** = the command bar and the screen ask first (`hx-confirm`): it destroys, it widens or narrows who reaches a
  mailbox, it sends mail outside the business without a review, it changes a credential, or it suspends a mailbox.
- **record_id** is what the JSON answer's `record_id` names: the record made (a create) or acted on.
- **Refresh:** a mail action returns `HX-Trigger: mailChanged` (the list, the counts and the rail listen), an outbox action
  `outboxChanged`, a review action `reviewChanged`, an admin action `mailboxesChanged`.

### Result contract
```json
{"status": "success", "did": "Archived 3 conversations in sales@", "record_id": 912, "count": 3, "undo_id": "mail_move:912", "refresh": "mailChanged"}
{"status": "success", "did": "Held for review: first contact (jon@supplier.example), attachment", "record_id": 4410, "held": ["first_contact", "attachment"]}
{"status": "refused", "did": "A person decides held mail: an agent never does", "errors": {"agent": "this action is a person's"}, "rule": "agent"}
{"status": "refused", "did": "This is an intake mailbox: its Inbox stays as the help desk reads it", "errors": {"mailbox": "intake"}, "rule": "intake"}
{"status": "refused", "did": "Not sent: the same answer went to this address in the last seven days", "record_id": 4411, "rule": "same_answer"}
```

## The shell (Phase 2 — sso-shell)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `home` | `/` | my default mailbox's Inbox — the person's own, else the first they can open; a person with no mailbox yet sees why and who can connect one |
| `notice` | `/notice` | the business's mail-monitoring notice, and to acknowledge it (on first use, and when its version changes) |
| `my-trail` | `/my/trail` | my own trail here: what I sent, decided, filed and opened, by mailbox (params: `since`, `mailbox`) |
| `tokens-list` | `/tokens` | my tokens for the two read MCP servers: label, last used, revoke; mint a new one (shown once) |

Actions:

| Action | Endpoint | Params | Undo | Confirm | Agent approval | Log event | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `mail_notice_acknowledge` | `/notice/acknowledge.php` | **version** (the notice version shown) | — |  |  | `notice.acknowledge` | `mail.use` | people | the acknowledgement |
| `mail_token_mint` | `/tokens/mint.php` | **name** (what the token is for), expires_days (1 to 365) | mail_token_revoke |  |  | `token.mint` | `tokens.own` | people | the token |
| `mail_token_revoke` | `/tokens/revoke.php` | **token** (its id) | — | ✔ |  | `token.revoke` | `tokens.own` · own | people | the token |

## Domains, mailboxes and forwarding (slice 1 — the CRUD exemplar)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `admin-domains` | `/admin/domains` | MaluMail's hosted domains for the customer key: which this business serves, which are internal, SPF/DKIM/DMARC as last read |
| `admin-mailboxes` | `/admin/mailboxes` | every mailbox of the served domains as cards: kind, owner or overseers, quota and use, sync state, the credential's state, intake owner (params: `kind`, `domain`, `state`, `q`) |
| `admin-mailbox-new` | `/admin/mailboxes/new` | create a mailbox — domain, local part, kind, owner (the record picker), quota, overseers and the primary overseer for an agent's (params: `domain`, `local_part`, `kind`, `owner`) |
| `admin-mailbox-view` | `/admin/mailboxes/{mailbox}` | one mailbox for a Mail admin: suspend or reactivate, quota, reset the password (a person's shown once), connect or reconnect the webmail, the credential's state, intake owner, share with applications, overseers |
| `admin-aliases` | `/admin/aliases` | the forwarding addresses of the served domains, outside destinations flagged (params: `domain`, `outside`, `q`) |
| `admin-alias-new` | `/admin/aliases/new` | create a forwarding address — source and destinations, an outside destination confirmed by name (params: `domain`, `local_part`) |
| `admin-alias-view` | `/admin/aliases/{alias}` | one forwarding address: edit its destinations, delete it |

Actions:

| Action | Endpoint | Params | Undo | Confirm | Agent approval | Log event | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `mail_domains_serve_set` | `/admin/domains/serve.php` | **domain** (a hosted domain's name), **served** (yes or no), internal (yes or no) | restore prior | ✔ |  | `domains.serve` | `domains.manage` | people | the domain |
| `mail_mailbox_create` | `/admin/mailboxes/create.php` | **domain**, **local_part**, **kind** (person agent or shared), display_name, owner (a person for a person's mailbox or an agent for an agent's), quota_gib (1 to 50), overseer[] (people for an agent's mailbox), primary_overseer | mail_mailbox_suspend |  |  | `mailbox.create` | `mailboxes.manage` | people | the mailbox |
| `mail_mailbox_connect` | `/admin/mailboxes/connect.php` | **address** (an existing MaluMail mailbox on a served domain), **kind** (agent or shared until MM1), display_name, owner, overseer[], primary_overseer | mail_mailbox_suspend | ✔ |  | `mailbox.connect` | `mailboxes.manage` | people | the mailbox |
| `mail_mailbox_update` | `/admin/mailboxes/update.php` | **mailbox**, display_name, intake_owner (none kernel helpdesk sales_crm or other), share_with_apps (yes or no), primary_overseer | restore prior |  |  | `mailbox.update` | `mailboxes.manage` | people | the mailbox |
| `mail_mailbox_suspend` | `/admin/mailboxes/suspend.php` | **mailbox**, **reason** | mail_mailbox_reactivate | ✔ |  | `mailbox.suspend` | `mailboxes.manage` | people | the mailbox |
| `mail_mailbox_reactivate` | `/admin/mailboxes/reactivate.php` | **mailbox** | mail_mailbox_suspend |  |  | `mailbox.reactivate` | `mailboxes.manage` | people | the mailbox |
| `mail_mailbox_quota_set` | `/admin/mailboxes/quota.php` | **mailbox**, **quota_gib** (1 to 50) | restore prior |  |  | `mailbox.quota` | `mailboxes.manage` | people | the mailbox |
| `mail_mailbox_password_reset` | `/admin/mailboxes/reset.php` | **mailbox** | — | ✔ |  | `mailbox.password_reset` | `mailboxes.manage` | people | the mailbox |
| `mail_alias_create` | `/admin/aliases/create.php` | **domain**, **local_part**, **destination[]** (addresses — at most 20), confirm_outside (the outside addresses typed back) | mail_alias_delete | ✔ |  | `alias.create` | `aliases.manage` | people | the alias |
| `mail_alias_update` | `/admin/aliases/update.php` | **alias** (its address), **destination[]**, confirm_outside | restore prior | ✔ |  | `alias.update` | `aliases.manage` | people | the alias |
| `mail_alias_delete` | `/admin/aliases/delete.php` | **alias** | — | ✔ |  | `alias.delete` | `aliases.manage` | people | the alias |

## The mail service and the list (slice 2 — the first exemplar)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `mailbox-view` | `/m/{mailbox}/v/{view}` | a mailbox's view as Gmail's conversation list — `inbox`, `starred`, `snoozed`, `sent`, `drafts`, `scheduled`, `outbox`, `all`, `spam`, `trash` — with select, the bulk bar, stars, labels, the attachment icon, snippets, dates, cursor paging and the "N new" bar (params: `view`, `before`, `unread`) |
| `folder-view` | `/m/{mailbox}/f/{folder}` | one IMAP folder's conversations, the same list (params: `before`, `unread`) |

Actions: none — the list reads; its bulk bar posts slice 3's and slice 6's actions.

## Reading (slice 3 — the second exemplar)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `thread-view` | `/m/{mailbox}/t/{thread}` | one conversation: earlier messages collapsed, quoted text folded, the sandboxed body, attachments, Show images, the outside and sender-mismatch banners, the DMARC result; Reply, Reply all, Forward, Archive, Delete, Spam, Move, Label, Snooze, Mute, Mark unread, Print, Show original; Take over on an agent's mailbox (params: `message`) |
| `message-original` | `/m/{mailbox}/msg/{message}/original` | one message's headers and source, and the `.eml` download |

Actions:

| Action | Endpoint | Params | Undo | Confirm | Agent approval | Log event | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `mail_archive` | `/mail/archive.php` | **mailbox**, thread[], matching (a search query — every conversation it finds up to 1000 instead of thread) | mail_move |  |  | `message.archive` | `mail.use` · triage | yes | the first conversation |
| `mail_move` | `/mail/move.php` | **mailbox**, thread[], **to_folder**, matching (a search query — every conversation it finds up to 1000 instead of thread) | mail_move |  |  | `message.move` | `mail.use` · triage | yes | the first conversation |
| `mail_trash` | `/mail/trash.php` | **mailbox**, thread[], matching (a search query — every conversation it finds up to 1000 instead of thread) | mail_move |  |  | `message.trash` | `mail.use` · triage | yes | the first conversation |
| `mail_spam` | `/mail/spam.php` | **mailbox**, thread[], matching (a search query — every conversation it finds up to 1000 instead of thread) | mail_not_spam |  |  | `message.spam` | `mail.use` · triage | yes | the first conversation |
| `mail_not_spam` | `/mail/not-spam.php` | **mailbox**, thread[], matching (a search query — every conversation it finds up to 1000 instead of thread) | mail_spam |  |  | `message.not_spam` | `mail.use` · triage | yes | the first conversation |
| `mail_flag` | `/mail/flag.php` | **mailbox**, thread[], message (one message of it), flagged (yes or no), matching (a search query — every conversation it finds up to 1000 instead of thread) | restore prior |  |  | `message.flag` | `mail.use` · triage | yes | the first conversation |
| `mail_mark_read` | `/mail/mark-read.php` | **mailbox**, thread[], read (yes or no), matching (a search query — every conversation it finds up to 1000 instead of thread) | restore prior |  |  | `message.mark_read` | `mail.use` · view | yes | the first conversation |
| `mail_delete_forever` | `/mail/delete-forever.php` | **mailbox**, **thread[]** (from Trash or Spam only) | — | ✔ | deletion | `message.delete_forever` | `mail.use` · triage | yes | the first conversation |
| `mail_trash_empty` | `/mail/trash-empty.php` | **mailbox**, which (trash or spam) | — | ✔ | deletion | `trash.empty` | `mail.use` · triage | yes | the mailbox |
| `mail_images_allow` | `/mail/images-allow.php` | **mailbox**, **sender** (an address whose remote images always show) | restore prior |  |  | `sender.images_allow` | `mail.use` · full | people | the mailbox |
| `mail_sensitive_set` | `/mail/sensitive.php` | **mailbox**, **thread[]**, sensitive (yes or no) | restore prior |  |  | `thread.sensitive` | `mail.use` · triage | people | the first conversation |

## Writing (slice 4 — the third exemplar)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `compose` | `/m/{mailbox}/compose` | write new mail, or a reply or forward — From (identities), To/Cc/Bcc with autocomplete, subject, the editor (rich or plain), attachments, the signature, canned replies; Send, Schedule, Save draft, Discard; Undo after Send (params: `reply`, `reply_all`, `forward`, `to`, `subject`, `body`, `template`) |
| `draft-edit` | `/m/{mailbox}/drafts/{draft}` | go on with a draft — one written here or on a phone |
| `identity-new` | `/m/{mailbox}/identities/new` | add an address this mailbox sends as — its own, or an alias MaluMail delivers to it — with display name, reply-to and signature (params: `address`) |
| `identity-edit` | `/m/{mailbox}/identities/{identity}/edit` | change an identity's display name, reply-to, signature or default |

Actions:

| Action | Endpoint | Params | Undo | Confirm | Agent approval | Log event | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `mail_draft_save` | `/mail/draft-save.php` | **mailbox**, draft (its id to replace), identity, to[], cc[], bcc[], subject, body, body_format (text or html), reply_to (a message key id), mode (new reply reply_all or forward) | mail_draft_discard |  |  | `draft.save` | `mail.use` · triage | yes | the draft |
| `mail_draft_discard` | `/mail/draft-discard.php` | **mailbox**, **draft** | — |  |  | `draft.discard` | `mail.use` · triage | yes | the draft |
| `mail_send` | `/mail/send.php` | **mailbox**, identity, **to[]** (addresses), cc[], bcc[], subject, **body**, body_format (text or html), draft (the draft it sends), send_at (an ISO time for a scheduled send), purpose (why — required of an agent) | mail_send_cancel |  |  | `message.send` | `mail.use` · send | yes | the outbound message |
| `mail_reply` | `/mail/reply.php` | **mailbox**, **message** (the message key id answered), reply_all (yes or no), identity, **body**, body_format, cc[], bcc[], draft, send_at, purpose | mail_send_cancel |  |  | `message.reply` | `mail.use` · send | yes | the outbound message |
| `mail_forward` | `/mail/forward.php` | **mailbox**, **message**, **to[]**, cc[], bcc[], body, body_format, identity, attachments_included (yes or no), draft, send_at, purpose | mail_send_cancel |  |  | `message.forward` | `mail.use` · send | yes | the outbound message |
| `mail_send_cancel` | `/mail/send-cancel.php` | **mailbox**, **outbound** (an outbound id in its undo window or scheduled) | — |  |  | `message.cancel` | `mail.use` · send | yes | the draft reopened |
| `mail_outbox_retry` | `/mail/outbox-retry.php` | **mailbox**, **outbound** (a failed one) | — |  |  | `outbound.retry` | `mail.use` · send | yes | the outbound message |
| `mail_identity_save` | `/mail/identity-save.php` | **mailbox**, identity (its id to change), address, display_name, reply_to_address, signature, is_default (yes or no) | restore prior |  |  | `identity.save` | `mail.use` · full | people | the identity |
| `mail_identity_delete` | `/mail/identity-delete.php` | **mailbox**, **identity** | — | ✔ |  | `identity.delete` | `mail.use` · full | people | the identity |

## Agents' mail and oversight (slice 5 — the fourth exemplar)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `oversight` | `/oversight` | a card per agent mailbox I oversee: received, sent, held, rejected (7 days), last activity, Pause/Resume |
| `review-queue` | `/oversight/review` | the held mail waiting for me — the overseers', and a person's own for an agent's mail from their mailbox — oldest first, with its reasons (params: `mailbox`, `reason`) |
| `review-view` | `/oversight/review/{outbound}` | one held message as it will go out, beside the conversation it answers, the agent's purpose, its run and every hold reason; Approve, Edit, Reject with a note, Reply to the agent, Take over |
| `review-edit` | `/oversight/review/{outbound}/edit` | edit a held message before sending it — the edit kept as a diff |
| `unscreened` | `/oversight/unscreened` | mail that did not wake an agent: Release (one turn), Mark sender known, Spam, Dismiss (params: `mailbox`) |
| `agent-policy` | `/m/{mailbox}/policy` | an agent mailbox's sending policy with each setting's meaning, its pause switch and its history |

Actions:

| Action | Endpoint | Params | Undo | Confirm | Agent approval | Log event | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `mail_review_approve` | `/oversight/approve.php` | **outbound** | — |  |  | `review.approve` | `mail.oversee` · decider | people | the outbound message |
| `mail_review_edit` | `/oversight/edit.php` | **outbound**, subject, **body**, body_format (text or html) | — |  |  | `review.edit` | `mail.oversee` · decider | people | the outbound message |
| `mail_review_reject` | `/oversight/reject.php` | **outbound**, **note** (reaches the agent in its next turn) | — |  |  | `review.reject` | `mail.oversee` · decider | people | the outbound message |
| `mail_review_reply` | `/oversight/reply.php` | **outbound**, **note** (a question to the agent — the draft is kept) | — |  |  | `review.reply` | `mail.oversee` · decider | people | the outbound message |
| `mail_thread_take_over` | `/oversight/take-over.php` | **mailbox**, **thread** | restore prior |  |  | `thread.take_over` | `mail.oversee` · full | people | the conversation |
| `mail_mute` | `/mail/mute.php` | **mailbox**, **thread[]**, muted (yes or no) | restore prior |  |  | `thread.mute` | `mail.use` · triage | yes | the first conversation |
| `mail_unscreened_release` | `/oversight/release.php` | **mailbox**, **message** (a message key id) | — |  |  | `unscreened.release` | `mail.oversee` · full | people | the dispatch |
| `mail_unscreened_dismiss` | `/oversight/dismiss.php` | **mailbox**, **message**, as_spam (yes to move it to Spam) | — |  |  | `unscreened.dismiss` | `mail.oversee` · full | people | the dispatch |
| `mail_sender_mark_known` | `/oversight/mark-known.php` | **mailbox**, **address** (the sender) | restore prior |  |  | `sender.mark_known` | `mail.oversee` · full | people | the correspondent |
| `mail_policy_set` | `/oversight/policy.php` | **mailbox**, send_mode (hold_all internal_auto allowlist_auto or auto), allow_domain[], allow_address[], first_contact_review (yes or no), attachments_review (yes or no), max_external_per_day, max_per_hour, max_per_thread_per_day, wake_on_mail (yes or no), wake_from (none internal internal_and_known or all_authenticated), disclosure (yes or no), disclosure_text | restore prior | ✔ |  | `policy.set` | `mail.oversee` · full | people | the policy |
| `mail_mailbox_pause` | `/oversight/pause.php` | **mailbox**, reason | mail_mailbox_resume |  |  | `mailbox.pause` | `mail.oversee` · full | people | the mailbox |
| `mail_mailbox_resume` | `/oversight/resume.php` | **mailbox** | mail_mailbox_pause |  |  | `mailbox.resume` | `mail.oversee` · full | people | the mailbox |

## Search, labels, snooze, folders and templates (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `search` | `/m/{mailbox}/search` | conversations of one mailbox matching Gmail's operators (params: `q`, `before`) |
| `search-all` | `/search` | the same over every mailbox I can open, each result naming its mailbox (params: `q`, `before`) |
| `label-view` | `/m/{mailbox}/label/{label}` | the conversations carrying one label (params: `before`) |
| `folders` | `/m/{mailbox}/folders` | a mailbox's IMAP folders: create, rename, delete, counts |
| `labels` | `/m/{mailbox}/labels` | a mailbox's labels: create, rename, colour, delete, counts |
| `templates` | `/templates` | my canned replies and the shared ones (params: `q`) |
| `template-new` | `/templates/new` | write a canned reply (params: `name`, `body`) |
| `template-edit` | `/templates/{template}/edit` | change or delete a canned reply |

Actions:

| Action | Endpoint | Params | Undo | Confirm | Agent approval | Log event | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `mail_label` | `/mail/label.php` | **mailbox**, thread[], **label**, removed (yes to take the label off), matching (a search query — every conversation it finds up to 1000 instead of thread) | restore prior |  |  | `message.label` | `mail.use` · triage | yes | the first conversation |
| `mail_snooze` | `/mail/snooze.php` | **mailbox**, **thread[]**, **until** (an ISO time) | mail_unsnooze |  |  | `thread.snooze` | `mail.use` · triage | yes | the first conversation |
| `mail_unsnooze` | `/mail/unsnooze.php` | **mailbox**, **thread[]** | mail_snooze |  |  | `thread.unsnooze` | `mail.use` · triage | yes | the first conversation |
| `mail_folder_create` | `/mail/folder-create.php` | **mailbox**, **name**, parent (an existing folder's name) | mail_folder_delete |  |  | `folder.create` | `mail.use` · triage | yes | the folder |
| `mail_folder_rename` | `/mail/folder-rename.php` | **mailbox**, **folder**, **name** | restore prior |  |  | `folder.rename` | `mail.use` · triage | yes | the folder |
| `mail_folder_delete` | `/mail/folder-delete.php` | **mailbox**, **folder** | — | ✔ | deletion | `folder.delete` | `mail.use` · triage | yes | the folder |
| `mail_label_create` | `/mail/label-create.php` | **mailbox**, **name**, colour | mail_label_delete |  |  | `label.create` | `mail.use` · triage | yes | the label |
| `mail_label_update` | `/mail/label-update.php` | **mailbox**, **label**, name, colour | restore prior |  |  | `label.update` | `mail.use` · triage | yes | the label |
| `mail_label_delete` | `/mail/label-delete.php` | **mailbox**, **label** | — | ✔ |  | `label.delete` | `mail.use` · triage | yes | the label |
| `mail_template_save` | `/templates/save.php` | template (its id to change), **name**, subject, **body**, shared (yes or no) | restore prior |  |  | `template.save` | `mail.use` | people | the template |
| `mail_template_delete` | `/templates/delete.php` | **template** | — | ✔ |  | `template.delete` | `mail.use` · own | people | the template |

## Delegation and access (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `mailbox-access` | `/m/{mailbox}/access` | who can open this mailbox and at what level, and why (owner, a row, a department, Mail admin, the Clerk); revoke |
| `access-grant-new` | `/m/{mailbox}/access/new` | give a person, a department or an agent a level on this mailbox (the record picker); on a person's mailbox by anyone but its owner, the break-glass form with a reason and an expiry (params: `person`, `department`, `agent`, `level`) |
| `mailbox-access-log` | `/m/{mailbox}/access-log` | every view of this mailbox by anyone but its owner — lists, conversations, parts, raw, MCP and K7 reads (params: `since`, `who`) |
| `mailbox-settings` | `/m/{mailbox}/settings` | this mailbox's settings: undo seconds, conversation view, images, density, shortcuts; on a person's mailbox, **Let the Mail Expert read my mail when I ask** and **Share with applications** (both off by default) |

Actions:

| Action | Endpoint | Params | Undo | Confirm | Agent approval | Log event | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `mail_permission_grant` | `/access/grant.php` | **mailbox**, person, agent, department, **level** (view triage send or full), reason (required for break-glass), expires_at (an ISO date within 30 days for break-glass) | mail_permission_revoke | ✔ |  | `permission.grant` | `mail.use` · full · or `access.audit` for break-glass | people | the permission row |
| `mail_permission_revoke` | `/access/revoke.php` | **mailbox**, **permission** (the row's id) | — | ✔ |  | `permission.revoke` | `mail.use` · full · or `access.audit` | people | the permission row |
| `mail_mailbox_settings_save` | `/mail/settings-save.php` | **mailbox**, undo_seconds (0 to 30), conversation_view (yes or no), images (ask or always), density (comfortable or compact), shortcuts (yes or no), page_size (25 50 or 100), ai_read (yes or no), share_with_apps (yes or no) | restore prior |  |  | `mailbox.settings` | `mail.use` · full | people | the mailbox |

## Correspondents and the K7 share (slice 8)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `correspondents` | `/m/{mailbox}/people` | everyone this mailbox has written to or heard from: first and last contact, counts, known or not, internal or outside (params: `q`, `known`, `outside`) |
| `correspondent-view` | `/m/{mailbox}/people/{correspondent}` | what this mailbox has exchanged with one address or its company's domain, as conversations |

Actions: none — the correspondents are kept by the index and by sending; a person marks one known in slice 5.

## Notifications (slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `notifications` | `/notifications` | my notices: new mail in the mailboxes I chose, held mail for my review, decisions, unscreened mail, break-glass on my mailbox, failures (params: `unread`) |
| `notification-settings` | `/settings/notifications` | which notices reach me in-app, by mail and by text (K6), and for which mailboxes new mail notifies me |

Actions:

| Action | Endpoint | Params | Undo | Confirm | Agent approval | Log event | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `mail_notification_read` | `/notifications/read.php` | notification (one id), every_one (yes to mark all read) | — |  |  | `notification.read` | own | people | the notification |
| `mail_notification_prefs_save` | `/settings/notifications/save.php` | email_enabled (yes or no), text_enabled (yes or no), kind[] (the notices that may mail), text_kind[] (the notices that may text), new_mail_mailbox[] (mailbox ids) | restore prior |  |  | `notification.prefs` | own | people | the member |

## Home, admin and settings (slice 10)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `admin` | `/admin` | the Mail admin's overview: domains served, mailboxes by kind and state, forwarding addresses going outside, sync health, failed credentials, the agents' budget today |
| `admin-sync` | `/admin/sync` | per mailbox: sync state, last sync, index and text coverage, errors; Re-sync, Re-index text (params: `state`) |
| `admin-settings` | `/admin/settings` | MaluMail's settings: the undo and images defaults, the body index cap, held-mail expiry, the agent policy defaults, the agents' business-wide budget and its two switches, the monitoring notice, the Clerk's hour |
| `admin-agents` | `/admin/agents` | the agents with mailboxes, their mail turns (dispatches) and their states; Retry a failed one (params: `status`, `agent`) |

Actions:

| Action | Endpoint | Params | Undo | Confirm | Agent approval | Log event | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `mail_settings_save` | `/admin/settings/save.php` | undo_send_seconds (0 to 30), images_default (ask or always), index_text_max_kb, max_attachments_mb, draft_save_seconds, poll_visible_seconds, poll_hidden_seconds, held_expiry_hours, default_send_mode (hold_all internal_auto allowlist_auto or auto), default_first_contact_review (yes or no), default_attachments_review (yes or no), default_max_external_per_day, default_max_per_hour, default_max_per_thread_per_day, default_wake_on_mail (yes or no), default_wake_from (none internal internal_and_known or all_authenticated), default_disclosure (yes or no), disclosure_text, agent_external_budget (empty for the warm-up), hold_all_agents (yes or no), hold_all_external (yes or no), mm3_identity_enforced (yes or no), clerk_note_at (HH:MM) | restore prior | ✔ |  | `settings.save` | `settings.manage` | people | the settings |
| `mail_notice_save` | `/admin/settings/notice.php` | **text** (the notice in plain words — a new version) | — | ✔ |  | `notice.save` | `settings.manage` | people | the notice version |
| `mail_mailbox_resync` | `/admin/sync/resync.php` | **mailbox** | — |  |  | `mailbox.resync` | `settings.manage` | people | the mailbox |
| `mail_mailbox_reindex` | `/admin/sync/reindex.php` | **mailbox** | — |  |  | `mailbox.reindex` | `settings.manage` | people | the mailbox |
| `mail_dispatch_retry` | `/admin/agents/retry.php` | **dispatch** | — |  |  | `dispatch.retry` | `agents.manage` | people | the dispatch |
