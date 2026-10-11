# MaluMail

A Gmail-style webmail for MaluMail mailboxes, as an application of the MaluDB Business OS: people and the OS's AI agents
read and write their mail in one place, people oversee what the agents receive and send, and the super-admins create
mailboxes and forwarding addresses through MaluMail's API without leaving the OS. MaluMail's mail server stays the record;
the application keeps an index for speed and search, who may open which mailbox, the outbox, and the agents' mail policies
and reviews.

## Status

**Planning (Phase 0).** The design was written 2026-10-10 and **approved by the owner 2026-10-11** — every decision
(D1–D16) as recommended. MaluMail's platform, the estate and the field are surveyed. No schema and no screens yet.

Next: MaluMail's MM2 (agreed with MaluMail's infrastructure side, design §12) and two test mailboxes → the mail spike →
the kernel's catalog row (K43) → the rest of Phase 0.

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
