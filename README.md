# MaluMail

A Gmail-style webmail for MaluMail mailboxes, as an application of the MaluDB Business OS: people and the OS's AI agents
read and write their mail in one place, people oversee what the agents receive and send, and the super-admins create
mailboxes and forwarding addresses through MaluMail's API without leaving the OS. MaluMail's mail server stays the record;
the application keeps an index for speed and search, who may open which mailbox, the outbox, and the agents' mail policies
and reviews.

## Status

**Phase 0.** The design was written 2026-10-10 and **approved by the owner 2026-10-11** — every decision (D1–D16) as
recommended. MaluMail's platform, the estate and the field are surveyed. The kernel's catalog row (K43) and **the kit** are built
(2026-10-11): sign-on, the directory mirror, the two roles, the settings, the activity funnel, tokens, the worker and the ingest
bridge, proven without a kernel by `tests/phase0/run.sh`. **The schema** (db/006–011: mailboxes and the wall, the message index
and search, the outbox, people's review of agents' mail, the dispatch rule) is proven by `db/proof/phase0_proof.sql` — **232
checks** — and the packaging (`maludb-os.json`, the two agents, eight skills, `deploy/`) reads clean in the installer's plan. No
screens yet.

The mail spike has been run. Its IMAP/SMTP half synced 5,000 messages in about 10 s against two test mailboxes. Its sanitiser half
is `services/mailer/sanitise.py`, proven by `tests/sanitiser/run.sh` against DOMPurify's 223 test cases in headless Chromium:
nothing runs after it, and nothing runs behind the body's CSP and sandbox either.

The mail service's skeleton, `services/mailer/`, has its signed door, the administration plane and the IMAP probe with the
circuit breaker. `tests/mailer/run.sh` proves it with 62 checks against a fake Mailbox MCP and an IMAP fixture.

**Phase 1 is written** (`docs/phase1-consistency.md`):
- the tool surface: 36 records and 7 activity tools;
- the action manifest: 45 screens and 67 actions;
- three build specs and eleven slice specs;
- the schema's Phase 1 fixes, db/012–015.

`tests/phase1/check_specs.sh` shows the documents agree, and `tests/phase0/schema.sh` passes 514 checks over db/001–015.

Phase 1 was approved by the owner as written on 2026-10-11.

**Phase 2, the shell, is built** (2026-10-12): the mailbox rail, the tab bar, the notice gate, tokens, the trail, the mail service's
client, and one placeholder for every screen a slice has not built yet. `tests/phase2/run.sh` proves it under php -S and under a real
Apache (`ML_APP=apache`), with headless Chromium at 375 and 1280 pixels.

**Slice 1, domains, mailboxes and forwarding, is built** (2026-10-12), the CRUD exemplar: a Mail admin serves domains, creates and
connects mailboxes (a person's password shown once and never stored; an agent's or a shared mailbox's sealed by the mail service and
never shown), suspends, resizes and resets them, and manages forwarding addresses. `tests/phase3/slice1/run.sh` proves it under php -S
and Apache.

**Slice 2, the mail service and the list, is built** (2026-10-12), the first exemplar: the mail service keeps every live mailbox in step
with MaluMail's server (the first sync, NOTIFY — else IDLE and a STATUS round every minute — QRESYNC catch-up, UIDVALIDITY resets that
keep everything keyed on a message, a gentle text pass for search; it never marks anything read), and every mailbox opens as Gmail's
conversation list: ten views and its folders, stars, snippets, agent badges, keyset paging, unread counts per person in the rail, and a
cheap poll that shows "N new" without moving the page. `tests/phase3/slice2/run.sh` proves it under php -S and Apache against an IMAP
fixture, and once against MaluMail's own test mailboxes.

**Slice 3, reading, is built** (2026-10-15), the second exemplar: a conversation opens with each message's body cleaned by the mail
service and shown in a sandboxed frame under a strict content policy, attachments and remote images through two signed doors, banners for
outside senders, DMARC, deceptive links and hidden content, and the eleven actions (archive, move, delete, spam, star, read/unread…) with
Undo. `tests/phase3/slice3/run.sh` proves it under php -S and Apache.

**Slice 4, writing, is built** (2026-10-16), the third exemplar: compose, reply, reply all and forward in Fastmail's Squire editor with
recipient chips, attachments, autosave to the Drafts folder, Undo send and scheduled sending; identities and signatures; and the mail
service's outbox, which submits over SMTP, files the Sent copy, marks the original answered and holds an agent's message when its policy
says so. `tests/phase3/slice4/run.sh` proves it under php -S and Apache against IMAP and SMTP fixtures.

**Slice 5, agents' mail and oversight, is built** (2026-10-16), the fourth exemplar: the people who oversee an agent's mailbox see its week
on a card, review its held mail exactly as it would go out — every link with its real host, every reason in a sentence — and approve,
edit, reject, ask the agent, or take the conversation over; they answer the mail that did not wake it, set its sending policy and pause it.
Agents never decide (refused by the page and again by the database); the agent hears each decision in a chat turn that carries ids and a
person's words, never mail text. `tests/phase3/slice5/run.sh` proves it under php -S and Apache.

**Slice 6, search, labels, snooze, folders and templates, is built** (2026-10-16): Gmail's operators over one mailbox or every mailbox a
person can open, each conversation once with its best snippet and paged by cursor without a repeat or a gap; labels on conversations (a `/`
nests), from the list, the conversation or a whole search; snoozing a conversation out of the Inbox until a time in the person's own time
zone, brought back unread by the worker with a chip (an agent's mailbox wakes the agent); a mailbox's IMAP folders made, renamed and deleted
(never with mail in them); canned replies, shared or one's own. `tests/phase3/slice6/run.sh` proves it under php -S and Apache. Next: slice 7.

**Slice 7, delegation and access, is built** (2026-10-17): each mailbox's Access page (who can open it and why), sharing with a person, a department or an agent, a Mail admin's break-glass access with a reason and an expiry (the owner told at once), revoke, the access log and the mailbox settings with the owner's two privacy switches; the worker removes expired rows and logs it. `tests/phase3/slice7/run.sh` proves it under php -S and Apache. Next: slice 8.

**Slice 8, correspondents, autocomplete and the K7 share, is built** (2026-10-17): a mailbox's People (filters, Known and Outside chips), the correspondent page with the company tab, recipient suggestions in the composer, and the handler of the `correspondence_index` share (the owner's switch, never a sensitive conversation, every answer in the access log). `tests/phase3/slice8/run.sh` proves it under php -S and Apache. Next: slice 9.

**Slice 9, notifications, is built** (2026-10-17): the bell (unread count, a menu of the latest eight) and the Notifications page, each person's settings (what reaches them in the bell, by mail and by text, and the mailboxes whose new mail notifies them), and the worker's passes that make and deliver notices — new mail coalesced per mailbox, quota crossings, the Mail Clerk's weekday morning note for overseers, mail through MaluMail's send and texts through the kernel. No notice ever contains a word of any message. (`tests/phase3/slice9/run.sh`.)

## Documents

- [docs/malumail-design.md](docs/malumail-design.md) — the Phase 0 design fitted to the Business OS: why the webmail talks
  IMAP/SMTP itself and administers through MaluMail's Mailbox MCP; who sees whose mail; agents' mail with policies, a review
  queue and untrusted-content turns; the memory model and its provenance against the estate; the questions and tools;
  screens; slices; what the kernel and MaluMail owe; the decisions D1–D16.
- [docs/research/01-malumail-platform-survey.md](docs/research/01-malumail-platform-survey.md) — MaluMail's Mailbox MCP,
  REST API, schema, Dovecot and submission, read-only, and the ways an application on another server can reach mail.
- [docs/research/02-estate-survey.md](docs/research/02-estate-survey.md) — the sibling applications' mail tables and readers,
  the kernel's mail pieces, ports, names, the kit and the server.
- [docs/research/03-webmail-and-agent-mail.md](docs/research/03-webmail-and-agent-mail.md) — Gmail screen by screen, how the
  open-source webmails are built, email for AI agents and its oversight, safety and law in outline, protocol and library facts.

## Ports and names

`webmail.<domain>`; ports 8199 (application), 8852 / 8853 (records and activity MCP), 8854 (the mail service, loopback);
database `<tenant>_malumail`.
