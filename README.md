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

Phase 1 was approved by the owner as written on 2026-10-11. Next: Phase 2, the shell.

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
