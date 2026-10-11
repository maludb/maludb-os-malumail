# Slice 5 — Agents' mail and oversight (THE FOURTH EXEMPLAR)

2026-10-11 · **Phase 3, slice 5 of 10 — the fourth exemplar** (design §3 "people decide; agents never do", §5, §9 Oversight, D10–D12).
The people who oversee an agent's mailbox see what it receives and sends, decide its held mail, answer the mail that did not wake it,
set its sending policy, pause it, and take a conversation over. The policy function, the review flow, the outbox's kill switch and
the mail turn are the build spec `sending-and-oversight.md`; this slice is their screens, handlers and proof. **Prerequisites:**
slice 1 (agent mailboxes with overseers and a primary overseer), slice 2 (the index and the dispatch rule called on arrival), slice 3
(the conversation screen), slice 4 (sending — held mail exists only once agents can send).

## Divergences from the design

- **The tools the agent's run called** (design §5's review page) are shown only as the run id: the kernel's run facts do not yet answer
  an application the run's tool events. The review page links the run id for a super-admin (the kernel's run page on `os.`) and lists
  the tools once the kernel answers them (asked below).
- **Recipients are not edited in a review** (sending-and-oversight decision 4); the manifest's `mail_review_edit` loses `to[]`,
  `cc[]`, `bcc[]`.
- **Spam on an Unscreened message** is `mail_unscreened_dismiss` with `as_spam`, so the dispatch and the move happen in one action
  (the manifest gains the param).
- **The Clerk's morning note** is the worker's deterministic pass, built in slice 9 (sending-and-oversight decision 2) — not here.

## Who may do what

| Who | Sees | Decides |
|---|---|---|
| **an overseer** — a person with `full` on an agent's mailbox (`ml_is_decider()`) | its cards, its queue, its Unscreened, its policy and history | every decision below |
| **a person whose mailbox an agent sent from** (D12) | that held mail in their queue | approve, edit, reject, reply, take over — for that mail only |
| **a shared mailbox's people with `full`** | its held mail | the same |
| **a Mail admin** | every agent mailbox's cards and queue (they hold `full` there) — **never a person's mailbox's held mail** (`mcp_review_queue` excludes `kind = 'person'`) | as an overseer, on agent mailboxes |
| **the Mail Clerk** (its `triage` row on agent mailboxes) | the queue, the cards and Unscreened of those mailboxes, read-only (`can_decide` false) | nothing; `mail_mute` and `mail_label` only |
| **any agent** | its own outbound rows and their decisions (`outbox_list`) | **nothing** — every action here but `mail_mute` is `people`: refused by `require_human()` and by `ml_require_person()` / `ml_require_decider()` in SQL |
| a `triage` holder who is not a decider | the conversation; Mute | nothing else here |

## Screens

### `oversight` — `/oversight`

Cards (the owner's preference), one per agent mailbox the person decides, sorted by held mail then name. Each card: the agent's name
and address; **received · woken · unscreened · sent · held now · rejected · expired** for 7 days; **its record** — approved unedited,
edited, rejected (the counts that earn a wider policy); the oldest held message's age; *Paused* in red with who paused it and why;
**Pause / Resume** (`hx-post`, Pause asks for a reason inline); links to its queue, Unscreened, policy and mailbox. 375 px: one card a
row; 1280: three a row. Empty: *"You oversee no agent's mailbox. A Mail admin names an agent mailbox's overseers."* The data is one
gatherer, `ml_agent_mail_summary(mailbox, days)` (`db/015_sending_oversight.sql`), shared with the tools `agent_mail_summary` and `oversight_summary`.

### `review-queue` — `/oversight/review` (params `mailbox`, `reason`)

A list, oldest first (it expires): mailbox, the agent, recipients (outside ones amber, new ones marked *new*), subject, every hold
reason as a chip, *expires in 21 h*. Filters by mailbox and reason. A row the person cannot decide (the Clerk, a `triage` holder) shows
*"For its overseers"* and no buttons. Polled every 30 s while visible (`hx-trigger="every 30s [document.visibilityState=='visible']"`).
Empty: *"Nothing waits for your review."*

### `review-view` — `/oversight/review/{outbound}`

**The message exactly as it will go out** (design §5): From and Sender, recipients with *outside* and *new* marked, subject, the body
rendered through the reading path's sandboxed response (`/oversight/review/{outbound}/body`, same CSP as a message), **every link
listed with its real host** (parsed from the sanitised HTML), attachments with name, type, size and sha256, the disclosure footer.
**Beside it** (below at 375): the conversation it answers (link to `thread-view`), the arriving message's DMARC result, spam score and
the hidden content the sanitiser removed (counted, from the index); the agent's **purpose**; its run id; every hold reason with its
sentence (*first_contact — "This mailbox has never written to jon@supplier.example"*); earlier decisions on it (a `reply` note). The
buttons: **Approve**, **Edit**, **Reject** (a note, required, inline), **Reply to the agent** (a note, required), **Take over** (only for
a reply). After a decision the page answers with the next message in the queue.

### `review-edit` — `/oversight/review/{outbound}/edit`

Full page: subject and body in slice 4's editor (Squire), the recipients shown read-only. **Save and send** posts `mail_review_edit`;
the review history then shows the diff of the text before and after (`before_subject`, `before_body_text`, `db/015_sending_oversight.sql`).

### `unscreened` — `/oversight/unscreened` (param `mailbox`)

The mail that did not wake its agent, newest first: mailbox, from (address and the domain's DMARC), subject (an agent mailbox's —
HTML-escaped, never rendered), received, **why** in words (*"An outside sender this mailbox does not know"*, *"Looks internal, but this
application did not send it"*, *"No DMARC pass"*, *"No primary overseer to act"*). Per row: **Release** (one turn), **Mark sender known**,
**Spam**, **Dismiss**, and Open (the conversation, slice 3). Empty: *"Nothing waits — every message that arrived either woke its agent or
needed no one."*

### `agent-policy` — `/m/{mailbox}/policy`

Each setting of design §5's table with its meaning beside it and its current value; the business-wide budget and the two switches that
also apply (read-only here — the Mail admins'); **Save** (`hx-confirm` when the change widens what leaves unseen: a mode other than
`hold_all`, a higher cap, first contact or attachments review off); the pause switch with its reason; the **history** — every
`policy.set`, `mailbox.pause`, `mailbox.resume` with who and when (the activity log). 375 first. An agent's own mailbox only; anything
else 404.

## Files (exactly these)

| File | What |
|---|---|
| `html/oversight/index.php` | `oversight` |
| `html/oversight/review/index.php`, `view.php`, `form.php`, `body.php` | `review-queue`, `review-view`, `review-edit`, the sandboxed body of a held message |
| `html/oversight/unscreened.php` | `unscreened` |
| `html/m/policy.php` | `agent-policy` |
| `html/oversight/approve.php`, `edit.php`, `reject.php`, `reply.php`, `take-over.php`, `release.php`, `dismiss.php`, `mark-known.php`, `policy.php`, `pause.php`, `resume.php`; `html/mail/mute.php` | the twelve handlers |
| `app/features/oversight/queries.php`, `present.php`, `links.php` (links with their hosts) | |
| `app/views/oversight/{cards,queue,_row,review,review_edit,unscreened,policy,_history}.php` | |
| `tests/phase3/slice5/run.sh`, `checks.php`, `browser.mjs`, `live.py` | the proof |

## Query functions and mail-service calls (signatures fixed)

```php
oversight_cards(PDO $pdo, int $member, int $days = 7): array             // ml_agent_mail_summary() for each mailbox the member decides
review_queue(PDO $pdo, int $member, ?int $mailbox, ?string $reason): array // mcp_review_queue
review_get(PDO $pdo, int $member, int $outbound): ?array                 // the row, can_decide, earlier reviews, the answered message's flags
review_decide(PDO $pdo, int $outbound, int $member, string $decision, ?string $note, ?array $edit): int   // ml_review_decide()
thread_take_over(PDO $pdo, int $mailbox, int $thread, int $member, bool $on): void   // ml_thread_take_over() on the thread's anchor key
thread_mute(PDO $pdo, int $mailbox, array $threads, int $member, bool $on): int      // ml_thread_mute() per thread
unscreened_list(PDO $pdo, int $member, ?int $mailbox): array             // mcp_dispatches status = 'unscreened'
unscreened_answer(PDO $pdo, int $mailbox, int $messageKey, int $member, string $how): void   // release | dismiss | spam
sender_mark_known(PDO $pdo, int $mailbox, string $address, int $member): void
policy_get(PDO $pdo, int $mailbox, int $member): array; policy_set(PDO $pdo, int $mailbox, int $member, array $changes): void   // ml_policy_set()
mailbox_pause(PDO $pdo, int $mailbox, int $member, bool $on, ?string $reason): void  // ml_mailbox_pause()
policy_history(PDO $pdo, int $mailbox, int $limit = 50): array
```

Mail-service calls: `fetch` (the answered message's flags and the held message's rendering path), `mutate` `move_to_junk` (Spam on an
Unscreened message — `mail-service.md`). The dispatch turn after a release or a note is the worker's (sending-and-oversight §8.3, §9).

## MCP tools this slice makes real

`review_queue`, `review_get`, `agent_mail_summary`, `oversight_summary`, `unscreened_mail`, `agent_mail_policy_get`, `review_trail`,
`agent_activity`.

## Manifest rows claimed
Screens (6): `oversight`, `review-queue`, `review-view`, `review-edit`, `unscreened`, `agent-policy`
Actions (12): `mail_review_approve`, `mail_review_edit`, `mail_review_reject`, `mail_review_reply`, `mail_thread_take_over`, `mail_mute`, `mail_unscreened_release`, `mail_unscreened_dismiss`, `mail_sender_mark_known`, `mail_policy_set`, `mail_mailbox_pause`, `mail_mailbox_resume`

## Activity log events

`review.approve`, `review.edit` (with the digests, never the text), `review.reject`, `review.reply`, `review.expire` (the worker),
`thread.take_over`, `thread.mute`, `unscreened.release`, `unscreened.dismiss` (`spam` true or false), `sender.mark_known` (the address's
domain only), `policy.set` (the keys changed, old and new values — none is content), `mailbox.pause` (the reason), `mailbox.resume`,
`agent.dispatch` (the worker, per turn), `outbound.refuse` (at Send, slice 4's handler). Every row with `mailbox_id`; the subject of an
agent mailbox's message at most 200 characters; never a body.

## Requirements claimed
MUSTs (10): `R39`, `R40`, `R41`, `R42`, `R43`, `R44`, `R45`, `R47`, `R53`, `R55`

## Out of scope for this slice

The Clerk's note and every notice's delivery (slice 9); the Mail admins' business budget and switches (slice 10's settings); the
agents' dispatch list and Retry (slice 10); evals of what reviewers changed (Extended); the kernel's assistant channel (K46).

## Proof

`tests/phase3/slice5/run.sh`: scratch `ml_dev5`; `php -S` on 9201, the fake kernel on 9202 (**its chat endpoint records every turn's
utterance and context**), the fakes on 9203, the mail service on 9206 with the IMAP and SMTP fixtures; then a user-level Apache on
9201; Playwright at 375 and 1280.

1. **Every hold reason from a real agent Send** (an action token for an agent member): `mode` under `hold_all`, `first_contact`,
   `attachment`, `over_hour`, `over_day`, `over_thread`, `over_business_budget`, `paused`, `hold_all_agents`, `hold_all_external`,
   `on_behalf` (an agent row on a person's mailbox), `mode` on a shared mailbox — each shown on `review-view` with its sentence.
2. **Refusals**: a reply to `Auto-Submitted: auto-replied`, to a list message, to a null return path → `refused` with `loop_rule`; the
   same body to the same recipient twice in seven days → `same_answer`; both said to the agent in the action's answer.
3. **The five decisions as a person**: approve → queued → in the SMTP spool **without** `Auto-Submitted`, **with** `X-OS-Agent`, the
   recipient now known; edit → the spool has the new text, the history shows the diff; reject and reply → the fake kernel received one
   turn each whose utterance carries the note and ids, **no mail text**; take over → cancelled, the conversation muted for the agent, a
   later arrival in it wakes no one.
4. **Agents never decide**: each of the eleven `people` actions with an agent's token → 403 from the handler; the same SQL function
   called as the agent member → refused (`ml_require_person`); a held message the agent wrote is untouched. The Clerk sees the queue
   with no buttons and is refused a decision.
5. **A person's mailbox**: a Mail admin's queue does not show the held mail an agent wrote from a person's mailbox; that person decides it.
6. **Expiry**: a held row aged past 72 h (the clock set in the database) → `expired` by the worker's pass; the agent's turn says so; the
   reviewers' `review_decided` notice.
7. **Unscreened**: an outside unknown sender, an outside sender without DMARC, an internal-looking sender this application did not send,
   no primary overseer → four rows with their reasons; Release → one turn with the releasing person as acting member; Mark known → the
   next mail from that sender wakes; Spam → the message in Junk in the IMAP fixture; Dismiss → gone from the list.
8. **Pause and resume**: queued and deferred agent rows → held with `paused`; no turn while paused; resume → a previously approved row
   goes, an unreviewed one waits without `paused`.
9. **The policy**: every key saved and read back; an agent's token refused; `allowlist_auto` with an empty list refused (the CHECK's
   sentence); the history lists the change.
10. **375 px** for every screen; the review page's body inside the sandboxed frame (no script runs — the sanitiser proof's harness).
11. **Live** (design §10's Phase 5 shape, here once): an agent member writes from spike01 to spike02 (a first contact) → held → a person
    approves → it arrives at spike02 with `X-OS-Agent` and without `Auto-Submitted`; expunged afterwards. One submission.

## Decisions taken here

1. **One gatherer for the cards and the two summary tools** (`ml_agent_mail_summary()`), as My Work's rule in the kernel: the screen
   and the agent can never disagree.
2. **The review page renders the held body through the same sandboxed response as a message**: an agent's HTML is untrusted too.
3. **Links are listed with their real host on the review page**, parsed from the sanitised HTML server-side: the reviewer sees where a
   link goes without hovering.
4. **A decision answers with the next message in the queue**: reviewing is a run of decisions; returning to the list each time costs a
   click per message.
5. **Widening the policy asks first** (`hx-confirm` naming what widens); narrowing never does.
6. **Mute stays an agent's action** (design §5): an agent may stop following a conversation; taking it over is a person's.

## Open questions

1. Should an overseer be able to **reply to the agent without keeping the draft** (a reply that also withdraws)? This spec keeps the
   draft held (design §5's words) and lets the agent withdraw it.

## Changes this spec asks of the shared documents

- **The manifest**: `mail_review_edit` → params `**outbound**, subject, **body**, body_format` (no recipients); `mail_unscreened_dismiss`
  → add `as_spam (yes to move it to Junk)`.
- **Done in `db/015_sending_oversight.sql`**: `ml_agent_mail_summary(p_mailbox, p_days)` (`ml_level() >= triage`, granted to `malumail_rw` and
  `malumail_records_ro`), and the take-over that withdraws the agent's held replies in the conversation.
- **The kernel** (K56, design §12): the run facts answer an application the names of the tools a run called (`html/api/v1/runs/facts.php`,
  `tools[]`), so the review page can list them (design §5).
- **`deploy/apache-malumail.conf`**: `/oversight/review/{outbound}` → `view.php?id=`, `/edit` → `form.php?id=`, `/body` → `body.php?id=`
  (the body's CSP as `/m/{mailbox}/msg/{message}/body`); `/m/{mailbox}/policy` → `m/policy.php`.
