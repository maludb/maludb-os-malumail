# MaluMail — Phase 1 consistency (2026-10-11 · APPROVED by the owner the same day)

What Phase 1 decided beyond the approved design, what it changed in the shared documents, and what it leaves to the owner.
`tests/phase1/check_specs.sh` proves the documents agree with each other: every screen and action of the registry claimed by exactly
one slice spec, every tool of the surface named by a slice, every agent's grant real and named in its job description, every MUST of
research 03 claimed or held here by name.

## The documents

| Document | What it holds |
|---|---|
| `docs/malumail-mcp-tool-surface.md` | 36 records tools and 7 activity tools, the resolvers, the agents' grants |
| `docs/malumail-action-manifest.md` → `mcp/action_registry.json` | 45 screens, 67 actions (41 a person's alone, 3 paused for agents) |
| `docs/build-specs/mail-service.md`, `reading-and-rendering.md`, `sending-and-oversight.md` | the three build specs |
| `docs/build-specs/sso-shell.md` + ten slice specs | Phase 2 and Phase 3's slices 1–10 |
| `db/012_phase1_fixes.sql` · `013_list_and_sync.sql` · `014_reading_search.sql` · `015_sending_oversight.sql` | the schema the specs found missing, each with its proof: `db/proof/phase1_core.sql` (37), `phase1_list.sql` (75), `phase1_reading.sql` (97), `phase1_sending.sql` (73); `bash tests/phase0/schema.sh` applies 001–015 and runs them with Phase 0's 232 — **514 green** |

## Requirements not claimed by a slice
MUSTs (2): `R56`, `R63`

- **R56** — retention and legal hold: v1 deletes nothing on its own (design §11); retention, the 30-day purge of Trash and Spam and
  a legal hold are Extended, each its own spec.
- **R63** — MaluMail's own items on mbox01 (a master user, push notification, a Sieve path, SPECIAL-USE folders): the infrastructure
  side's, tracked as MaluMail items MM1–MM10 in design §12.

## Decisions taken in Phase 1

Each spec's "Decisions taken here" holds the reasons; these are the ones that change or extend the approved design.

**Who sees what**
1. **An agent working for a person reads only what both may open** (design §8) — db/006 had given any agent but the Expert its own
   level in a person's run; db/012 takes the lesser. The Expert's §3 rule is unchanged.
2. **The mail service reads every level itself** from the member a signed call names (and the person behind an agent's run,
   `on_behalf`); §6.1's "naming the acting member and their level" no longer needs the level (`mail-service.md`).
3. **Overseers read an agent's or a shared mailbox's access log** — such a mailbox has no person as owner (`delegation-access.md`).
4. **No notice carries mail** — no subject, no sender's name, no words — in the bell, by mail or by text (`notifications.md`).

**Credentials and links**
5. **The mail service decides what a password reset reveals**, from the mailbox's kind in the database: a person's new password is
   shown once on the admin's screen and **never stored** (D3, until MM1); an agent's or a shared mailbox's is sealed, stored and never
   shown. A compromised web server can never carry an agent's credential away (`domains-mailboxes.md`).
6. **The service writes the mailbox and credential rows** on create and connect, so a sealed box never reaches PHP.
7. **Connecting an existing agent or shared mailbox resets its password** at MaluMail, behind a confirmation naming the cost.
8. **One link key, `ML_PART_KEY`**, signs the attachment and image links: minted by the mail service while it sanitises and by PHP
   for what it renders, verified by PHP's `/p/` and `/img/`, **one hour**, bound to the member and the message, the level re-checked
   on every use. The forks had proposed two different keys; this settles it.
9. **The body's CSP gains `form-action 'none'; base-uri 'none'`**; a plain-text message renders in the page, not the frame.

**Mail itself**
10. **IMAPClient 4.1.0**, not 3.0.1 (the TLS connect timeout, #664; unsolicited responses during `FETCH`, #647, which `NOTIFY` sends);
    the mail service's proof re-run on it: 62 green.
11. **The `STATUS` round runs every minute even with `NOTIFY`**; a first sync rebuilds threads twice (after INBOX, at the end).
12. **A folder is never deleted with mail in it**; a message stuck in `sending` after a crash becomes `failed`, never re-queued
    (its Sent copy proves nothing).
13. **Bulk actions take `matching`** (a search, up to 1,000 conversations) — R5's "all matching" and "archive everything from X".
14. **A reviewer edits subject and body, never recipients**; **the mail service appends an agent's signature**; **no inline images in
    composed mail in v1** (a pasted image becomes an attachment); **Squire 2.4.9** (MIT, 2026-09-15) vendored as the editor.
15. **Always-show-images only for a proven sender** (DMARC pass, or `local_submission` from a colleague).

**The agents**
16. **The Mail Clerk's morning note is the worker's deterministic pass** under the Clerk's name (`digest` notices: counts and links),
    not a scheduled model turn — no cost, no mail in a model's context, nothing to inject into. The Clerk keeps its on-request work.
    `maludb-os.json` drops its `duty`; `os/clerk.md` says so.
17. **The Expert's grants gain** `mail_send_cancel`, `mail_draft_discard`, `mail_not_spam`, `mail_unsnooze` — an agent can take back
    what it did.

## Changes to the shared documents

- **The manifest** (45 screens, 67 actions, unchanged in number): bulk actions' `thread[]` optional with `matching`;
  `mail_review_edit` without recipients; `mail_unscreened_dismiss` gains `as_spam`; `mail_notification_prefs_save` takes `text_enabled`
  and `text_kind[]`; `mail_mailbox_settings_save` gains `page_size`; `mail_settings_save` takes `ml_settings`' own column names (and
  `mm3_identity_enforced`, `clerk_note_at`); the canonical rewrites for compose, identities, the policy and the review pages.
- **The tool surface**: `mail_list`'s `outbox` view and `ml_mail_list()`; `mailbox_overview` from `ml_mailbox_counts()`;
  `mailbox_health` from `ml_sync_health()` for a Mail admin; `mail_search`'s added operators and `unknown_operators`; `thread_read`'s
  five latest messages and `deceptive_links`; `outbox_list`'s decision and note for the writer; the access log's readers; the share's
  `truncated` and one `k7.read` per mailbox.
- **`maludb-os.json`**: `ML_PART_KEY` required, `ML_MAILER_MAX_CONNECTIONS` optional; the Clerk's duty removed; the share's description.
- **`deploy/ROOT_STEPS.sh`, `config/.env.example`, `tests/setup_dev.sh`**: `ML_PART_KEY` made once and kept; the connection ceiling.
- **`mcp/requirements.txt`**: `imapclient==4.1.0`.
- **The design**: §12 gains **K56** (the run facts name the tools a run called, for the review page); §16 records Phase 1.

## For the owner

**Three questions** — **the owner took every recommendation (2026-10-11)**:
1. **Which MaluMail customer key may the live proofs use?** Recommended: a key the infrastructure side scopes to `mboxtest.example`
   (MaluMail item MM7's domain-scoped key, asked early), kept in a mode-600 file outside the repositories (`domains-mailboxes.md`).
2. **When an agent's message is partly refused** (some recipients `550`), does it count as sent? Recommended: yes for the
   per-conversation cap; only the accepted recipients become known correspondents (`sending-and-oversight.md`).
3. **"Reply to the agent" on a held message** — keep the draft held (recommended, design §5's wording), or also withdraw it?
   (`oversight.md`).

**What Phase 1 changes from the approved design** (decisions 2, 5, 10 and 16 above) — approving Phase 1 approves them.
