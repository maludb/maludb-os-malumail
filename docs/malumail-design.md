# MaluMail — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-10 · the business's **webmail, Gmail's job for a MaluDB Business OS installation**: the mailboxes the business keeps
at MaluMail — its people's, its shared addresses' and **its AI agents'** — read, searched, organised and answered in one place,
signed in once at the launcher; the OS's agents reading and writing their own mail through the same doors; **people overseeing
what the agents receive and send**; and the super-admins creating mailboxes and forwarding addresses through MaluMail's API
without leaving the OS. The owner's brief (2026-10-10): *"a gmail type webmail for malumail inside the os … where both AI
agents and humans can access email. This also will allow humans to oversee ai agents emails. Super admins will be able to use
the malumail api to create mailbox and forwarding accounts."* Its own repository `/srv/apps/malumail` (origin
`github.com/maludb/maludb-os-malumail`, **public** — the owner, 2026-10-10), served as **`webmail.<domain>`** (the owner,
2026-10-10), fitted to `maludb-os-integration` 0.9.0 and built with `htmx-php-builder` 0.9.0 (record picker, application
switcher). The infrastructure side's handoff (*MaluMail Webmail Handoff*, 2026-10-10, the owner's Claude Docs page) is the
starting point; §0.1 says where this plan departs from it and why.

The nearest exemplars: Help Desk (a MaluMail mailbox as a door; `mailboxes`, `outbound_email`), Spaces (permission rows,
agents answering through the kernel's chat endpoint, `agent_dispatches`, notifications), Inventory (sealed credentials, a
worker with backoff and a circuit breaker, the loopback bridge), DocCloud (files served under a sandbox CSP), the Data Lake
(the newest kit; a Python service that is the only door to its store).

> **What this document is.** The plan, in the kernel's words, and the brief a worker model builds from. Phase 0's first half is
> this document and the owner's answers (§13, recorded in §15); **the mail spike** (§10.1), the schema in `db/`,
> `maludb-os.json`, `os/`, `skills/` and `deploy/` complete it. Phase 1 adds `docs/malumail-mcp-tool-surface.md`,
> `docs/malumail-action-manifest.md`, the build specs `docs/build-specs/mail-service.md`, `reading-and-rendering.md`,
> `sending-and-oversight.md` and the slice specs. **No feature PHP is written until the owner approves them together.** The
> research behind §0 is in `docs/research/`.

## 0. What the handoff, MaluMail, the estate and the field say

### 0.1 Where this plan departs from the handoff — the OS is not webapp01

The handoff recommends running the webmail **on MaluMail's own web host**, where it could reach the `mbox` database and MaluMail's
internal paths directly, and reuse MaluMail's PHP helpers. **This application is an
application of the Business OS**: it is installed beside the kernel on the business's own server (`/srv/apps/malumail`),
signed in by the kernel, reached by the kernel's agents, and installed by the kernel's installer on any tenant's server. It
therefore sits **outside** MaluMail's network and can use only what MaluMail offers to the internet:

- **IMAPS 993 and submission 587/465** on `mbox01.malumail.com`, with a credential per mailbox;
- **the Mailbox MCP** at `api.malumail.com/mcp`, with the business's customer API key (Mailbox MCP switched on).

Everything else in the handoff holds and is a rule here: user mail leaves through mbox01, never mail01 or `/v1/send`; the
sending address is cold, so agents' sending is throttled and drafts-first; admin changes are MaluMail's rows, made only through
its API; an address is a mailbox or an alias, never both; suspend rather than delete; resolve special folders by `SPECIAL-USE`;
include UIDVALIDITY in every cache key; never test against real customers' mailboxes; mail content goes to a model only under
the rules of §5. The handoff's five open decisions are answered in the OS's terms in §13 (who is an admin → D2; the host →
this section; keep-a-copy forwarding → §11 and MM4; AI features and data flow → D10–D12, D14; password change → §4).

### 0.2 What MaluMail offers (`docs/research/01-malumail-platform-survey.md`)

- **The Mailbox MCP is an admin API and a thin mail client.** Its admin tools cover everything the owner asked for —
  `list_hosted_domains`, `list_mailboxes`, `create_mailbox` (a random password returned once), `reset_mailbox_password`,
  `set_mailbox_quota`, `set_mailbox_active`, and the aliases `list/create/update/delete_alias` (external destinations
  allowed). Its content tools (`list_messages`, `read_message`, `search_messages`, `send_message`, `move_message`,
  `mark_message`) are what the kernel uses for an assistant's mailbox, and **they cannot carry a Gmail-style client**: no
  folders, no paging past the newest 50, no HTML, no attachments, no drafts, no change feed, plain-text sending from the
  mailbox's own address only, one message per call.
- **mbox01's Dovecot has every IMAP extension a modern client needs:** `CONDSTORE QRESYNC` (incremental sync), `THREAD=REFS`,
  `SORT`, `ESEARCH`, `MOVE`, `UIDPLUS`, `BINARY` (partial fetch), `SNIPPET=FUZZY`/`PREVIEW` (snippets without fetching bodies),
  `LIST-STATUS`, `STATUS=SIZE`, `IDLE` and `NOTIFY`, `QUOTA`, `SPECIAL-USE`. No full-text index, no METADATA, no ManageSieve,
  no ACLs. Maildir keeps at most 26 keywords a folder — **labels cannot be IMAP keywords**.
- **Submission** takes about 9.8 MiB a message, DKIM-signs, and caps each mailbox at **500 messages an hour** (`451 4.7.1`).
  It does not save to Sent; the client appends its own copy.
- **One login per mailbox today** — the mailbox's own bcrypt password. Dovecot can take a second credential per mailbox (an
  **application password**, bound to the OS server's address) without changing the first: the infrastructure side's change, MM1.
- **New-mail webhooks hold one target per mailbox** and fire only on delivery — no moves, flags or appends.

**So the plan reaches mail by two planes** (D3): the **admin plane** through the Mailbox MCP with the customer key, and the
**content plane** through **IMAP and SMTP directly**, from one service of the application's own, with a per-mailbox
application password (MM1). IMAP `NOTIFY`/`IDLE` replaces webhooks, so the webmail never competes for a mailbox's one
webhook slot.

### 0.3 What the estate gives (`docs/research/02-estate-survey.md`)

| The webmail needs | The estate already has | So MaluMail takes |
|---|---|---|
| a record of each mailbox it serves | **Help Desk `mailboxes`** (`db/012`), copied by Sales CRM with its queue link loosened | **reuse**, CRM's loosening, appended columns (§6.7) |
| an outbox | **Help Desk `outbound_email`**, Sales CRM's appended recipients and bodies | **reuse**, the status enumeration widened (§6.3) |
| canned replies | **Sales CRM `email_templates`** | reuse |
| labels | **Help Desk `tags`** + DocCloud's appended columns; **CRM `record_tags`** | reuse, `mailbox_id` appended |
| who may open a mailbox | **Spaces `page_permissions`** (the access-row family) | **`mailbox_permissions`**, Spaces' shape |
| sealed credentials | **Inventory `source_credentials`** + its one decrypting file | **`mailbox_credentials`**, Inventory's shape |
| contacts | **Sales CRM `contacts`** — owned data, read through K7, never copied | `mail_correspondents` is a different concept (addresses seen in mail), new |
| notifications, dispatches, attachments, worker passes, settings, the kit | Spaces `db/012`, `db/016`; GL `db/009`; Help Desk `db/005`; the Data Lake's kit | reuse |
| a mirror of a mailbox: folders, messages, conversations | nothing — Help Desk's `inbound_email` is a processing queue of captured mail | **new canonical** |

**Three collisions** the survey found, each answered by a rule (§3, D13): every mail reader in the estate (the kernel's
assistant intake, Help Desk, the CRM's logging mailbox) treats **`\Seen` as "handled"**; each MaluMail mailbox has **one
webhook target**, already held by those readers; and **the customer key reaches every mailbox**, so who sees whose mail is
this application's wall to keep.

### 0.4 What the field shows (`docs/research/03-webmail-and-agent-mail.md`)

The landscape research ranks 64 requirements (R1–R64, MUST / SHOULD / LATER). Its MUSTs are v1 except those §11 lists as
deferred, each with its reason; its LATERs are §11. What it changes here:

- **Index, don't mirror** (R29–R32). Every PHP webmail that served large mailboxes added a database cache; Nextcloud Mail's
  cut CPU spikes from 40–50 % to 4 % on a 9,000-message account, and its first sync had to leave the web request for a worker.
  Gmail's conversations span folders (your replies sit in Sent), so IMAP `THREAD`, which works per folder, cannot build them:
  **threading is the index's**, split at a subject change and at 100 messages (Gmail's rules). Bodies and attachments stay on
  the server.
- **Labels are the hard mismatch** (R19). A Dovecot message lives in one folder; Maildir holds 26 keywords a folder. System
  places are folders; user labels are the application's (D5).
- **HTML is the attack surface** (R34). Roundcube has had eleven vulnerabilities in CISA's exploited catalogue (a third-party
  count); SnappyMail's and Nextcloud's were contained by a **second layer** (a CSP; an image proxy). Defence in depth is the rule
  (§6.4).
- **An agent's mailbox is the "lethal trifecta"** (R38–R47): untrusted content in, private business data within reach (its
  tools), and a way out. "An attacker can literally email your LLM and tell it what to do." Hence: a new agent sends nothing
  unseen; only **authenticated** mail from admitted senders reaches it; a reply to the thread's own sender is still an
  outbound channel and is reviewed; RFC 3834's loop rules; per-thread caps; kill switches (§5).
- **Deliverability** (R44): Rspamd's 500 an hour is a backstop, not a budget; a cold address ramps over weeks (AgentMail's
  schedule: 10–20 a day in the first days); back off on `4.7.28`/`421`.
- **Law, in outline** (R51–R58): notice before monitoring (New York's §52-c in writing; *Bărbulescu* in Europe: advance notice,
  flow before content, the least intrusive means); a log of who opened whose mail; **no AI reading a person's mailbox by default**;
  mail that reaches a model also lands in the kernel's prompt ledger and at the provider; **AI disclosure** on agent mail (EU AI
  Act Art. 50, from 2 August 2026 per the research — whether later amended is unverified); retention is a per-tenant policy with a legal hold (§11).
- **Libraries** (R62): the mail service on **IMAPClient** (BSD; `IDLE`, `CHANGEDSINCE`, `MOVE`, `APPEND`) or the stdlib
  `imaplib` wrapped, the stdlib `email` (`policy.default`), **nh3** (MIT, Rust's ammonia; bleach is archived) with a CSS
  allow-list pass, `smtplib` with certificates verified. No PHP IMAP library, no composer: PHP never opens IMAP (R30).

## 1. What MaluMail is, and is not

**Is.** A Gmail-style webmail over the business's MaluMail mailboxes:

- **a mailbox list** on the left — the person's own mailbox, mailboxes shared with them, the agents' mailboxes they oversee —
  with Inbox, Starred, Snoozed, Sent, Drafts, Scheduled, All mail, Spam, Trash, the folders and the labels, unread counts;
- **conversations**: a message list grouped into Gmail's conversations across folders (by `References`/`In-Reply-To`, the
  subject as a fallback), unread in bold, stars, labels, attachment icons, snippets, density, bulk select;
- **reading**: the conversation with earlier messages collapsed, quoted text folded, HTML rendered safely with remote images
  held back until asked, attachments to open or download, "show original";
- **writing**: compose, reply, reply all, forward; rich text or plain; drafts saved to the mailbox's Drafts folder as you
  type; attachments; **undo send**; **schedule send**; signatures; sending as an alias of the mailbox;
- **organising**: archive, delete, spam / not spam, move, star, mark read or unread, labels, snooze, mute;
- **search** with Gmail's operators (`from:`, `to:`, `subject:`, `has:attachment`, `is:unread`, `is:starred`, `in:`,
  `label:`, `before:`, `after:`, `newer_than:`, `larger:`) over an index of every message's text;
- **keyboard shortcuts** (Gmail's set) and the 375 px layout;
- **delegation**: a mailbox shared with a person, a department or an agent at a level (view, triage, send, full) — Gmail's
  delegation, in the estate's permission rows;
- **the agents' mail**: each agent may have a mailbox; it reads and writes through MCP tools; a policy decides what it may
  send unseen; **held mail waits in a review queue** for the people who oversee that agent; inbound mail wakes the agent as
  one chat turn; a person can pause an agent's mail or take a conversation over;
- **administration** (the Mail admin, D2): the hosted domains this installation serves, mailboxes (create, suspend,
  reactivate, quota, password reset, connect to the webmail), **forwarding addresses** (MaluMail's aliases, outside
  destinations flagged), who may open which mailbox, and the sync's health.

**What waits on MaluMail.** Agents' and shared mailboxes created by this application work from the first install. **A person's
existing mailbox opens in the webmail once MaluMail issues application passwords (MM1)**: until then the application holds no
credential for it and will not ask the person for their own password (D3). **Oversight covers the mail agents send through this
application**; the kernel's assistant channel sends through MaluMail's API directly until K46 (§5).

**Is not (v1).**

- a mail server — MaluMail is the mail server; this application never edits mail-server configuration;
- the transactional sender — the OS's notifications keep `/v1/send` (the installer's `mail` step), and no person's or
  agent's mail goes that way;
- **vacation replies, filters and keep-a-copy forwarding** — they need Sieve, which MaluMail does not yet expose (MM4; §11);
- a calendar, a contacts manager (the CRM owns contacts) or a ticket system (Help Desk owns tickets);
- Gmail's category tabs, "Important", nudges and smart replies — Extended;
- a mail client for mailboxes outside MaluMail (Google, Microsoft) — Extended, if ever;
- a POP3 or IMAP server of its own, or a public API — clients' own AI tools reach the mail through the two MCP servers (SaaS
  Plus+), under a person's token.

## 2. Who uses it (the actors, in the OS's words)

| Actor | Who | Reaches MaluMail through |
|---|---|---|
| **A Mail user** (role `user`, kernel capability `write`) | Anyone the super-admin granted MaluMail: reads and writes their own mailbox, and those shared with them at the level given | the browser (the launcher's hand-off), the command bar |
| **An overseer** | A **person** given `full` on an **agent's** mailbox (by default the agent's manager, K44; one of them the *primary overseer*, the acting member of its mail turns) — reads everything the agent receives and sends, decides its held mail, sets its policy, pauses it, takes a conversation over | the browser: Oversight |
| **A Mail admin** (role `admin`, capability `admin`, `is_admin`) | The super-admins (D2) — the kernel gives a super-admin the `is_admin` role everywhere: domains, mailboxes, forwarding addresses, access, sync health, settings. **No access to a person's mail by holding the role** (D9) | the browser: Admin |
| **An agent** | Any hired agent granted MaluMail (`user`): its own mailbox, and any a person delegates to it. Never decides for people (§3). The shipped **Mail Expert** and **Mail Clerk** on install | Records MCP (run token) · Actions MCP (relayed) · a chat turn when its mail arrives (§5) |
| **The kernel** | Signs people in, delivers the directory, hires and runs the agents, pauses their deletions for a super-admin, answers the command bar and the agents' mail turns (the chat endpoint), carries texts (K6) and the siblings' reads (K7) | — |
| **MaluMail** | The mail server (IMAP, submission) and the admin API (the Mailbox MCP) | the mail service alone |
| **The system** | **The mail service** (sync, fetch, the outbox, administration; the only holder of open credentials and the customer key), the image door, and the worker (dispatches, notifications, snoozes, held-mail expiry, the index's catch-up) | — |

## 3. Who sees what — the rules, in one place

**Who a mailbox is open to.**

- its owner (`mailboxes.owner_member_id` — a person or an agent: `full`);
- a `member` row naming a person, an `agent` row naming an agent;
- a `department` row — **for the people of that department only**: an agent who belongs to the department is not admitted by
  it (the kernel mirrors agents into departments; a person sharing with their team never shares with its agents);
- on an **agent's** mailbox, every Mail admin at `full`;
- a **shared** mailbox (`info@`, `sales@`) has no owner, only rows, set by the Mail admins.

No row → the mailbox does not exist for them (404, never 403, so an address never leaks).

**Levels** (Gmail's delegation in the estate's permission rows, D9):

- **`view`** — read everything; **never changes the mailbox**. Read state for a `view` holder is kept per person in the index
  (`mail_viewer_reads`).
- **`triage`** — `view` + flags, move, archive, delete to Trash, spam, labels, snooze, drafts.
- **`send`** — `triage` + send as the mailbox ("Maria on behalf of Sales": the author in `From`, the delegate in `Sender`).
- **`full`** — `send` + the mailbox's identities, signatures, settings and its permission rows. On an agent's mailbox, also the
  agent's policy, its held mail and the pause switch.

**Reading never marks mail read by itself.** Every fetch the mail service makes is a `PEEK` (`BODY.PEEK`, `BINARY.PEEK`). When
the owner or a `triage` holder opens a message, the application marks it read with an explicit `STORE` — and never on an
intake mailbox (below).

**People decide; agents never do.** These need a person (`NOT is_agent`) and are **refused to every agent** in SQL and in the
manifest — refused, not paused:

- every review decision (approve, edit, reject, reply to the agent, take over);
- releasing Unscreened mail, and marking a sender known;
- pausing or resuming an agent's mail, and changing its policy;
- granting or revoking a permission row;
- every administrative action (§5).

An **overseer** is a *person* holding `full` on an agent's mailbox.

**A person's mailbox.**

- **Only its owner grants rows on it.** A row on a person's mailbox made by anyone else — a Mail admin, a super-admin — is
  **break-glass**, and the SQL insists on three things: a reason, an expiry of at most 30 days, and an immediate notice to the
  owner if they are still active. **Holding a role never opens a person's mailbox.**
- **An agent reaches a person's mailbox in two ways only.**
  - Through an `agent` row the owner made, at most `send` (a CHECK). Everything the agent sends from there is held for the
    owner (D12).
  - Through **the Mail Expert in a chat run the person started** (K38's `requested_by` is the owner). It reads the person's
    mailboxes at the person's level, capped at `triage`, and **only when the person has switched on "Let the Mail Expert read
    my mail when I ask"** (`mailboxes.ai_read`, **off by default** for a person's mailbox; research R54).

**An agent's mailbox is the business's.**

- Its overseers and the Mail admins hold `full`.
- The agent holds `full` on its own mailbox except for everything people decide (above). Its outbound mail passes its policy
  (§5).
- The **Mail Clerk** holds `triage` on every agent's mailbox (a row made when the mailbox is created).

**Intake mailboxes** (D13) are those another reader uses as its intake: the kernel's assistant channel, Help Desk's queue, the
CRM's logging mailbox (`mailboxes.intake_owner`). Until K45, HD8 and CRM7 make those readers independent of the flags:

- the application **never sets `\Seen`** on an intake mailbox, and read state there is kept per person;
- **messages in its INBOX cannot be moved, archived, snoozed, trashed or marked spam** — any of those would hide new mail from
  the reader exactly as `\Seen` would.

When a mailbox is connected, `intake_owner` is **proposed** from MaluMail's webhook record: a mailbox with a webhook of its own
belongs to some reader. A Mail admin confirms it.

**Notice before oversight.** The Mail admin keeps the business's mail-monitoring notice: a seeded text in plain words, versioned
in `ml_settings`. It says:

- agents' mailboxes are reviewed in full;
- people's mailboxes are opened by others only through a grant the owner made, or through break-glass for the reasons listed.

Every person sees the notice on first use, and their acknowledgment is recorded in `ml_notice_acknowledgements`. New York's §52-c
and *Bărbulescu* ask for exactly that.

**The access log** is the activity log's rows on a mailbox by anyone but its owner, read through the view
`mcp_mailbox_access_log`. Its owner and the Mail admins can read it. It records:

- a non-owner's list and search views (one row per mailbox per half hour);
- every conversation opened;
- every attachment, original or raw message fetched;
- every records-MCP read of a mailbox that is not the caller's own, including agents and the command bar;
- every K7 read.

**The K7 exception.** `correspondence_index` (§4) answers a sibling application about the mailboxes marked *shared with
applications*:

- by default, agents' and shared mailboxes; a person's mailbox only if its owner opts in;
- the answer carries subject, participants, dates and a 200-character snippet;
- it goes to that application's agent or person without a row, and is logged as a K7 read.

**Credentials are never seen by the Mail admins or by agents.**

- For an agent's or a shared mailbox created before MM1, the password MaluMail returns is **sealed at once and never displayed**.
- For a person's mailbox, the password (create or reset) is shown **once, on a person's screen**: never in JSON mode, never under
  an action token. It is for the person's phone and desktop.

**In SQL, not in PHP or Python.**

- `ml_mailbox_level(mailbox bigint, member bigint) → text` is PL/pgSQL, one query, and it holds every rule above. Every `mcp_*`
  view and every handler asks it. The views test the caller once per statement (the kernel's db/160 rule) and use
  `security_barrier` throughout.
- The mail service is told the acting member and their level by the handler that calls it. It refuses a mutation below
  `triage`, a mutation of an intake INBOX, and any request whose UIDVALIDITY no longer matches.

## 4. Doors for those who are not inside

- **People's own mail clients** (phone, desktop) keep working beside the webmail: the mailbox's own password is the person's,
  untouched by the webmail (MM1). When a Mail admin creates a person's mailbox, the password MaluMail returns is shown **once**,
  on the admin's screen, for the person's devices; a reset shows a new one once (never in JSON mode, never to an agent, §3). **Changing one's own mailbox password** is a page under
  My settings once MaluMail offers a verified change (MM9); until then it is a reset by a Mail admin.
- **The siblings, through K7** (D15). MaluMail shares one tool:
  - `correspondence_index` — conversations with an address or a domain over the mailboxes marked *shared with
    applications* (agents' and shared mailboxes by default; a person's only if they opt in): subject, participants, dates,
    mailbox, message count, a 200-character snippet, the URL. The CRM's account timeline and Help Desk's customer view read it.
    It is §3's one exception to "no row, no mailbox", and every answer is in the access log.

  **MaluMail reads nothing** in v1. Reading the CRM's `contacts_index` for names in autocomplete is Extended.
- **Clients' own AI tools** reach their mail through the two MCP servers with a person's token (SaaS Plus+, `/tokens`), under
  the same wall.

## 5. Agents and the application — the OS requirement, in detail

**An agent's mailbox is a colleague's desk with a supervisor.** Mail is the riskiest door an agent has. What comes in is written
by strangers, and what goes out leaves the business under its name from a cold address. An agent's mailbox meets all three
conditions of the "lethal trifecta" (§0.4):

- untrusted content comes in;
- business data is within reach of its tools;
- there is a way out.

So for every agent:

**Outbound — the policy decides, a person reviews** (`agent_mail_policies`, D10):

| Setting | Default for a new agent mailbox | Meaning |
|---|---|---|
| `send_mode` | **`hold_all`** | every message waits for review. Others: `internal_auto` (to internal recipients without review), `allowlist_auto` (to listed domains and addresses), `auto` (everything, still capped) |
| `allow_domains`, `allow_addresses` | empty | for `allowlist_auto` |
| `first_contact_review` | on | a recipient the mailbox has never **sent** to (below) is always held |
| `attachments_review` | on | a message with an attachment is always held |
| `max_external_per_day` / `max_per_hour` | 20 / 50 | the warm-up caps; over them a message is held, never dropped |
| `max_per_thread_per_day` | 5 | breaks agent-to-agent and auto-reply loops |
| `paused` | off | the kill switch: everything held, no mail turns |
| `wake_on_mail`, `wake_from` | on, `internal_and_known` | which arriving mail starts a turn: `none`, `internal`, `internal_and_known`, `all_authenticated` (below) |
| `disclosure` | on | agent mail says an AI agent of the business wrote it (below) |

**What counts as internal.** A recipient is internal when it is:

- a mailbox this application serves; or
- an alias on a served domain **whose every destination is internal**. An alias may forward outside, and then it is outside.

**Every recipient is tested** — To, Cc and Bcc. One outside recipient makes the message outside.

**What counts as known.** A correspondent is *known* to a mailbox when either:

- the mailbox has **sent** to the address — a person's send, or an agent's send that a person approved; or
- a person **marked** the sender known (`mail_correspondents.known_at`, `known_by`).

Mail that was only received never makes a sender known — for waking an agent or for first contact. Otherwise an outsider could
write twice and pass both gates.

**The business-wide budget.** The Mail admins' `ml_settings.agent_external_budget` caps every agent's outside mail together.
It follows a warm-up: weeks 1–4 at 20, 50, 100 and 200 a day from the first agent send, then up 50 % a week while bounces stay
under 2 %. Overseers cannot raise it; the Mail admins can. Rspamd's 500 an hour per mailbox is a backstop, not a budget.

**Why a message is held** (`outbound_email.hold_reasons`, every reason that applies, shown to the reviewer):

| Reason | When |
|---|---|
| `mode` | the policy's mode wants review |
| `first_contact` | a recipient the mailbox has never sent to |
| `attachment` | the message has an attachment |
| `over_hour`, `over_day`, `over_thread` | over the agent's caps |
| `over_business_budget` | over the business-wide budget |
| `paused` | the agent's mail is paused |
| `hold_all_agents`, `hold_all_external` | one of the Mail admins' two business-wide switches is on |
| `on_behalf` | the agent sent from a person's mailbox (D12) |

**What is refused outright** (status `refused`, and the agent is told why):

- **RFC 3834's loop rules.** An agent never answers a message whose `Auto-Submitted` is not `no`, whose return path is null, or
  that carries `List-*` or `Precedence: bulk/list/junk`.
- **The same answer twice.** The same normalised body to the same recipient within seven days.

**What every agent message carries.**

- Always the header `X-OS-Agent: <name>; business=<name>`.
- `Auto-Submitted: auto-generated`, or `auto-replied` for a reply, **when it leaves without a person's review**. A reviewed
  message keeps `X-OS-Agent` and drops `Auto-Submitted`.
- The **disclosure footer** ("Sent by Ada, an AI agent of Subello"), on by default. Research R57 and the EU AI Act Art. 50 (from
  2 August 2026 per the research; whether later amended is unverified). An overseer may word it.

**The review queue** (Oversight → For review).

*Who reviews.* Reviewers are **people**:

- the overseers of the sending agent's mailbox;
- for mail an agent sends from a person's mailbox (D12), **that person**.

The Mail admins see the whole queue.

*What the reviewer sees.*

- The message exactly as it will go out: From and Sender, recipients with outside and new ones marked, subject, the rendered
  body and its text, **every link with its real host**, attachments (name, type, size, digest).
- Beside it:
  - the conversation it answers, with the arriving message's DMARC result, spam score and any hidden content the sanitiser
    removed;
  - the **purpose** the agent gave (`message_send` requires one);
  - the tools its run called, and its run id;
  - every hold reason.

*What the reviewer can do.*

- **Approve.** The message enters the outbox with the undo window.
- **Edit, then send.** The edit is recorded with a diff.
- **Reject with a note.** The note reaches the agent in its next mail turn: "your draft to … was not sent: …".
- **Reply to the agent.** A question; the draft is kept.
- **Take the conversation over.** The agent is muted on it, and the person replies.

*Expiry.* Held mail expires after 72 hours, and the agent is told.

*Each agent's record.* The oversight cards show approved unedited, edited and rejected. A widened policy is earned, not guessed.

**Reviewers are people, not the kernel's approvals.** The kernel decides approvals only on `os.`, which only super-admins reach
(research 02 §3). Mail review belongs to the agent's people.

**Outside this application's reach.** The kernel's assistant channel sends with MaluMail's API directly, under the customer key —
an assistant replying to its principal. That mail never meets `agent_mail_policies`, and pausing an agent here does not stop it.
**Oversight covers what agents send through this application.** K46 makes the kernel's agent sends go through this application's
outbox once the application serves that mailbox. The hard stop for any mailbox is suspending it, which also stops its inbound
mail. A submission-only stop is MaluMail's MM10.

**Inbound — untrusted data, one turn at a time** (D11):

- **Only authenticated mail wakes an agent.** *Authenticated* means an aligned DMARC pass as Rspamd reports it (the
  `DMARC_POLICY_ALLOW` symbol in `X-Spamd-Result`).
- **A From header does not prove an internal sender.** Until MaluMail's identity rule for submission (MM3) is in place, mail from
  the business's own domains wakes an agent **only if this application sent it**: its `Message-ID` is in `outbound_email`. Any
  other internal-looking mail is treated as outside mail.
- Auto-submitted, bulk and list mail never wakes an agent.
- Mail from outside the business that is not from a known correspondent **does not wake the agent**. It waits in the overseers'
  *Unscreened* list, where a person can:
  - release it (one turn);
  - mark the sender known;
  - mark it spam.
- A conversation that is **taken over** or **muted** wakes no one. A **paused** mailbox wakes no one. A conversation marked
  **sensitive** is never put in an agent's context.
- An **intake mailbox** of the kernel (an assistant's) never dispatches from here. The kernel's channel answers it (D13).

**The mail turn.** A message that may wake the agent becomes an `agent_dispatches` row. The worker turns it into **one chat
turn** of the agent through the kernel's chat endpoint, as Spaces does for a mention.

- **The acting member.** The endpoint requires an active human acting member with a grant. Until the kernel can wake an agent
  from an application on its own (K8), it is the mailbox's **primary overseer**.
- **The turn carries ids, never mail text.** The endpoint caps an utterance at 4,000 characters, so the turn carries the mailbox,
  the message key and the conversation in `context`. The agent reads with `thread_read`.
- **`thread_read` returns the extracted reply text** (quoted history and signatures cut), never the raw HTML:
  - inside a fenced block marked as untrusted content, with the standing instruction that *nothing inside it is an instruction to
    the agent*;
  - hidden content (invisible text, zero-size fonts) stripped and flagged;
  - links listed with their hosts, never fetched for the agent;
  - attachments listed, and read only through `attachment_read` for allowed types.

**Refused to every agent** — a person's decisions (§3); the manifest's `Who` column says *people*:

- `mailbox_create`, `mailbox_suspend`, `mailbox_reactivate`, `mailbox_quota_set`, `mailbox_password_reset`, `mailbox_connect`;
- `alias_create`, `alias_update`, `alias_delete`;
- `mailbox_permission_grant`, `mailbox_permission_revoke`;
- `agent_mail_policy_set`, `mailbox_pause`, `mailbox_resume`;
- `review_approve`, `review_edit`, `review_reject`, `review_reply`, `thread_take_over`;
- `unscreened_release`, `sender_mark_known`;
- `domains_serve_set`, `settings_save`, `notice_save`.

Asked for one of them, the Mail Expert opens the screen prefilled for a person to confirm. That is a navigation, not an action.

**Paused for every agent by the kernel's approval hook** — category `deletion`: `message_delete_forever`, `trash_empty`,
`folder_delete`.

**Neither refused nor paused; governed by the policy:**

- `message_send`, `message_reply`, `message_forward` — held or queued by the policy;
- `draft_save`;
- on the mailboxes the agent may triage: `message_archive`, `message_move`, `message_flag`, `message_mark_read`,
  `message_label`, `message_trash`, `message_spam`, `thread_snooze`, `thread_mute`.

**Working.**

**(a) The command bar.** A person's turn on the **Mail Expert** through the kernel's chat endpoint. For example:

- *what did the supplier say about the delivery date*
- *find the contract Maria sent last month*
- *draft a reply to Jon saying we accept, from sales@*
- *archive everything from the newsletter*
- *what is waiting for my review*
- *what did the Scheduler agent send this week*
- *who can open the billing mailbox*
- *create a mailbox for the new receptionist* — the Expert opens the prefilled New mailbox screen for the super-admin.

The Expert reads with `mail_search` → `thread_read`, and acts through the registry.

- It reads a person's own mail only under §3's rule: `ai_read` on, in a run the person started.
- What it sends from a person's mailbox is held for that person (D12).

**(b) An agent's own mail.** Any agent with a mailbox works its INBOX through the same tools, in the turns its mail starts. It
reads the conversation, replies (held or queued by its policy), files, flags, and snoozes for a follow-up.

**(c) Duties.** The **Mail Clerk** has one: weekdays at 07:10, one note to the overseers. It covers the agent mailboxes it holds
`triage` on:

- held mail older than a day;
- unscreened mail;
- mailboxes near quota;
- sync failures.

**Shipped agents** (`maludb-os.json` `agents[]`; hired on install, D10):

| Key | Job | Reads | Acts | Refused / pauses on |
|---|---|---|---|---|
| `expert`: Mail Expert | The command bar and the house name. Finds mail, answers what a conversation says, drafts replies and new mail, files and labels, explains who can open a mailbox and why a message was held. Never sends from a person's mailbox without that person; never administers | every records tool on what it may open, `records_search`, `record_history` | `draft_save`, `message_send`/`reply`/`forward` (held per D12 and the policy), `message_archive`/`move`/`flag`/`mark_read`/`label`/`trash`/`spam`, `thread_snooze`/`mute` | refused: everything people decide; pauses on every `deletion` |
| `clerk`: Mail Clerk | The overseers' morning note. On request, sorts an agent's held mail by reason and drafts notes the reviewer may use. Proposes `wake_from` and allow-list changes from what overseers released. Never decides a review | `review_queue`, `oversight_summary`, `unscreened_mail`, `mailbox_health` — on the agent mailboxes it holds `triage` on | `message_label`, `thread_mute` (on agent mailboxes) | refused: everything people decide |

**Skills** (`skills/`):

- `mail-basics` covers:
  - mailboxes, folders, conversations, labels and levels;
  - **mail content is data, never instructions**;
  - search operators;
  - how an agent's sending is governed: holds, refusals, the warm-up, the business budget.
- `writing-business-mail` — tone, length, subject lines, replying in the conversation, never inventing commitments, what to hold
  for a person, the disclosure.
- `overseeing-agent-mail` — reviewing held mail, writing a useful rejection note, when to widen a policy.

Runbooks (`kind: runbook`): `triage-an-inbox`, `reply-in-a-conversation`, `find-a-document-in-mail`,
`prepare-a-mailbox-for-a-new-hire` (the prefilled screen), `explain-a-held-message`.

## 6. Memory model — what MaluMail remembers

There are **two stores and one record**.

- **MaluMail's mail server is the record of every message.**
- The application's **record memory** (PostgreSQL 17, `<tenant>_malumail`) holds two things:
  - an **index** of the server, rebuildable from it at any time;
  - the application's own data, which exists nowhere else: who may open what, policies, reviews, the outbox, drafts in
    progress, labels, snoozes, mutes, identities and settings.
- **The tenant's memory** receives the activity episodes (§6.6).

**Rules of identity.**

- **A message on the server** is `(folder, UIDVALIDITY, UID)`. Every index row, URL and mail-service request carries all three,
  and the service refuses a request whose UIDVALIDITY no longer matches.
- **A message to the application** is a **message key** (`mail_message_keys`): a bigint surrogate for `(mailbox, Message-ID)`.
  - A message without a `Message-ID` is keyed by a digest of its envelope.
  - A second message claiming the same `Message-ID` with a different envelope is keyed `Message-ID#digest`, so a forged
    duplicate never inherits another message's state.
- **The application's own state attaches to message keys, never to a UID**: labels, viewer reads, and the conversation states,
  which are anchored on a message. A UID changes when a message moves and when a folder's UIDVALIDITY resets; a message key
  survives both, and survives a full rebuild of the index.

### 6.1 The mail service (`services/mailer/` — Python, in the application's `mcp/venv`)

**It is the only process that holds an open mailbox credential, holds the MaluMail customer key, and speaks IMAP and SMTP.** It
listens on loopback, `127.0.0.1:{{ML_MAILER_PORT}}`. The PHP handlers and the records MCP call it with an HMAC-signed request
naming the acting member and their level (Inventory's bridge). It runs as its own system user, `malumail-mailer`, made by
`ROOT_STEPS.sh`, and does five things.

1. **Sync.** Each mailbox keeps one long-lived connection with `ENABLE QRESYNC` and `NOTIFY SET` over every folder. Where
   `NOTIFY` misbehaves, it uses `IDLE` on INBOX and a `STATUS` round every minute instead; the spike decides (§10.1).
   - **Bringing a folder up to date.** `SELECT … (QRESYNC (uidvalidity modseq))` does it:
     - new UIDs are fetched: `ENVELOPE`, `FLAGS`, `INTERNALDATE`, `RFC822.SIZE`, `BODYSTRUCTURE`, `PREVIEW`, and the
       `References`, `In-Reply-To`, `List-*`, `Auto-Submitted`, `Precedence` and `X-Spam*` headers;
     - changed flags are applied;
     - `VANISHED` UIDs are removed.
   - **A UIDVALIDITY change** re-reads the folder. The application's state finds its messages again by message key.
   - **The text pass.** Body text for search is fetched in a second pass: `BINARY.PEEK` of the text parts, capped at 100 kB a
     message, newest first, at a gentle rate. A new mailbox is usable at once and searchable within the hour.
2. **Fetch.** On demand:
   - a message's parts, with **its HTML sanitised** (§6.4);
   - an attachment, streamed (`BINARY.PEEK` partial);
   - the raw message;
   - the quota (`GETQUOTAROOT`).

   **Every fetch is a `PEEK`.** Each mailbox has a small pool of at most three connections; Dovecot allows ten per address.
3. **Mutate.** Flags, move, copy, expunge, folder create, rename and delete, and `APPEND` of a draft (replacing the previous
   one). Each is one IMAP command, and the index is updated in the same request so the screen is right at once. The service
   refuses any mutation below `triage`, and any mutation of an intake mailbox's INBOX or its `\Seen` flag (§3).
4. **The outbox.** Due rows in `outbound_email` are claimed `FOR UPDATE SKIP LOCKED`.
   - Each is built as MIME: multipart/alternative, attachments, threading headers, the agent headers (§5), and a `Message-ID`
     minted here and stored before submission, so a retry never sends twice.
   - It is submitted on 587 with STARTTLS, certificates verified.
   - It is `APPEND`ed to the mailbox's `\Sent` folder, and the original is marked `\Answered`.
   - A `451 4.7.1` defers the row with backoff, and the screen says so.
5. **Administration.** The admin handlers' calls to MaluMail's Mailbox MCP go through this door too, under the customer key:
   mailboxes, aliases, quotas, resets, application passwords, the served domains' flags. PHP holds no MaluMail secret.

**Credentials.**

- **How they are sealed.** `mailbox_credentials` holds each mailbox's credential **sealed with a libsodium sealed box**
  (`crypto_box_seal`) under the mail service's public key (`ML_SEAL_PUBLIC_KEY` in `config/.env`). PHP seals the credential at
  the moment MaluMail issues it — through the mail service's own administration call, so it is never in a page — and **cannot
  open it**.
- **Where the keys live.** The secret key **and the MaluMail customer key** live in `/etc/malumail/mailer.env`, readable only by
  `malumail-mailer`.
  - A compromise of the web server's user can still ask the loopback door to act: on this server, as some member, logged.
  - It **cannot carry a key away**.
- **What a credential is.**
  - An **application password** (MM1), bound by MaluMail to this server's address and revocable without touching the person's
    own password.
  - Until MM1 exists, the mailbox's own password, for a mailbox this application created for an agent or a shared address (D3).
- **The customer key is the real boundary.** It reaches every mailbox of the MaluMail customer, so the served-domain list (§6.2)
  is the application's wall. Where one MaluMail customer serves several businesses, a key scoped to named domains (MM7) is
  required, not optional.

**The circuit breaker.** One authentication failure stops all use of that credential. Its `status` becomes `failed`, the Mail
admins are told, and nothing more happens until a person looks. Repeated failures would trip the mail server's abuse
protection and lock this server's every mailbox out. Connection errors back off per mailbox, from 30 s to 30 min.

### 6.2 Mailboxes, credentials, permissions, identities (`app/features/mailboxes/`, `admin/`, `access/`)

- **`mailboxes`** is Help Desk's row (§6.7) with these columns appended:

  | Column | Meaning |
  |---|---|
  | `kind` | `person`, `agent` or `shared` |
  | `owner_member_id` | null for `shared` |
  | `credential_id` | the mailbox's sealed credential |
  | `quota_bytes`, `quota_used_bytes` | |
  | `sync_state` | `new`, `syncing`, `live`, `paused` or `failed` |
  | `last_sync_at` | |
  | `intake_owner` | `none`, `kernel`, `helpdesk`, `sales_crm` or `other` (§3) |
  | `share_with_apps` | the K7 share (§4) |
  | `ai_read` | §3's switch: on by default for `agent` and `shared`, **off for `person`** |
  | `primary_overseer_id` | an agent mailbox's acting member for mail turns (§5) |
  | `suspended_at` | |
  | `settings jsonb` | conversation view, images, undo seconds, density |

  Help Desk's columns that MaluMail does not use (`queue_id`, `webhook_secret_ref`, `last_uid`) stay, nullable and empty, with
  `queue_id`'s reference dropped. That is the CRM's precedent.
- **`mailbox_credentials`** is Inventory's `source_credentials` (`kind`, `label`, `last4`, `ciphertext`, `rotated_at`).
  - `source_id` stays, nullable and unused, with its reference dropped.
  - Appended: `mailbox_id`, `status` (`ok`, `failed`, `revoked`), `last_ok_at`, `failed_at`, `failure`.
  - `kind` ⊂ `app_password`, `mailbox_password`.
- **`mailbox_permissions`** is Spaces' `page_permissions` (`principal_kind`, `principal_id`, `level`, `granted_by`), with
  `page_id` → `mailbox_id`.
  - `principal_kind` ⊂ `member`, `department`, `agent`; `level` ⊂ `view`, `triage`, `send`, `full`.
  - Appended: `reason`, `expires_at`, `break_glass`.
  - Two CHECKs: an `agent` row on a person's mailbox is at most `send`; and a break-glass row has a reason and an expiry within
    30 days.
  - A trigger marks break-glass and notifies the owner.
- **`mail_identities`** is new: the addresses a mailbox sends as.
  - Its own address, and the aliases MaluMail delivers to it — checked through `list_aliases` when an alias is added.
  - Each with display name, reply-to, signature (rich text) and whether it is the default.
- **`ml_domains`** is new: the hosted domains this installation **serves**, and which of them are **internal** for §5. MaluMail's
  SPF, DKIM and DMARC flags are kept as last read. The customer key reaches every domain of the MaluMail customer; the
  application shows and administers only these.

### 6.3 The index, drafts and the outbox (`app/features/mail/`, `compose/`, `outbox/`; the mail service writes the index)

- **`mail_message_keys`** (new): `id`, `mailbox_id`, `message_key`, unique `(mailbox_id, message_key)` — §6's surrogate.
- **`mail_folders`**: per mailbox, `name`, `delimiter`, `special_use` (`inbox`, `sent`, `drafts`, `junk`, `trash`, `archive`,
  `snoozed`), `uidvalidity`, `uidnext`, `highestmodseq`, counts, `subscribed`, `synced_at`.
- **`mail_messages`**: one row per message per folder, unique `(folder_id, uidvalidity, uid)`.

  | Columns | |
  |---|---|
  | `folder_id`, `uidvalidity`, `uid`, `modseq` | where it is on the server |
  | `message_key_id`, `thread_id` | |
  | sender and recipients (address and name), `reply_to`, `subject` | |
  | `sent_at` (the Date header), `received_at` (INTERNALDATE), `size` | |
  | `seen`, `flagged`, `answered`, `draft`, keywords | the flags |
  | `snippet` | `PREVIEW` |
  | `has_attachments`, `attachment_names` | |
  | `dmarc_pass`, `spam_score` | from `X-Spamd-Result` |
  | `auto_submitted`, `is_list` | from `Auto-Submitted`, `List-*` and `Precedence` |
  | `body_text` (≤ 100 kB), `text_indexed_at` | the text pass |
  | **`tsv`** | subject, people, snippet and text; GIN-indexed |

- **`mail_threads`**: Gmail's conversation, per mailbox, with a surrogate `id`, the normalised subject, participants,
  `last_message_at`, counts (messages, unread), `has_attachments`, `flagged`, and the folders its messages are in.
  - **Threading** follows `References`, then `In-Reply-To`, then the normalised subject within 30 days among the same
    participants. These are JWZ's rules applied across folders, so Sent replies sit in the conversation.
  - A conversation splits when the subject changes, and at 100 messages (Gmail's rules).
  - Threads are rebuildable; nothing the application owns keys on a thread id.
- **`mail_thread_states`** (new): the application's own state for a conversation, **anchored on a message key**
  (`anchor_key_id`). A conversation shows the states of any of its messages, so a merge or a rebuild loses nothing. The states:
  `muted`, `taken_over_by`, `snoozed_until` with the folder it came from, `agent_muted`, `sensitive`.
- **`mail_viewer_reads`** (new): `(member_id, message_key_id)` — read state for a `view` holder, and on intake mailboxes.
- **Labels**: `tags` with `mailbox_id` appended, and `record_tags` with `record_type = 'mail_message'` and
  `record_id = mail_message_keys.id` (the CRM's bigint key, unchanged).
- **`mail_correspondents`** (new): per mailbox, each address seen.
  - Name, first and last seen, sent and received counts, internal or not.
  - **`known_at`, `known_by`**: when and how it became known — the first send to it, or a person's mark (§5).
  - Used for autocomplete, *first contact* and *known sender*.
- **`mail_drafts`** (new): a compose in progress — `id`, `mailbox_id`, `member_id` (the writer, a person or an agent),
  `draft_key` (a `Message-ID` minted for the draft, the same across replacements), the current `(folder, uidvalidity, uid)`
  in the Drafts folder, the compose state (identity, recipients, subject, body, `reply_to_key_id`, mode).
  - **A draft lives in the mailbox's Drafts folder**, so a phone sees it. Each save `APPEND`s the new version and expunges the
    old.
  - A draft written on a phone and opened here gets a row.
  - Its uploads are `attachments` rows with `record_type = 'mail_draft'`.
  - `{draft}` in a URL is `mail_drafts.id`.
- **`outbound_email`** is created **on Send**. It is Help Desk's row with the CRM's appended columns:
  - **Loosened.** `to_address` is the first recipient. `subject` may be empty. The CRM's `contact_id` and `deal_id` stay,
    nullable and unused, with their references dropped — as the CRM did with Help Desk's ticket link.
  - **Appended.**

    | Columns | |
    |---|---|
    | `mailbox_id`, `identity_id` | where it is sent from |
    | `sender_member_id` | the person or agent who wrote it |
    | `on_behalf_member_id` | a delegate |
    | `to_addresses`, `cc_addresses`, `bcc_addresses` | |
    | `in_reply_to`, `references_ids`, `reply_to_key_id` | threading |
    | `send_after` | the undo window and the schedule |
    | `attempts`, `next_attempt_at`, `smtp_reply` | delivery |
    | `draft_id` | the draft it came from |
    | `purpose` | required of an agent |
    | `hold_reasons text[]` | §5's enumeration |
    | `refusal` | `loop_rule` or `same_answer` |
    | `review_id`, `body_digest` | |

  - **`status` widened** with `held`, `rejected`, `cancelled`, `deferred`, `expired` and `refused`, beside Help Desk's
    `queued`, `sent`, `failed` and `suppressed`.
  - **Undo** returns the row to a draft: status `cancelled`, the draft reopened.
  - Its attachments move from the draft to `record_type = 'outbound_email'`.
- **`mail_reviews`** (new): one row per decision on held mail — `outbound_id`, `decision` (`approve`, `edit`, `reject`,
  `reply`, `take_over`, `expire`), `reviewer_member_id` (a person; a CHECK through the mirror's `is_agent`), `note`, and a
  digest of the text before and after an edit.
- **`agent_mail_policies`** (new): one row per agent mailbox (§5), with `updated_by` (a person); its history is in the activity
  log.
- **`ml_notice_acknowledgements`** (new): `member_id`, `notice_version`, `acknowledged_at`.
- **`email_templates`**: the CRM's canned replies, per person or shared.

### 6.4 Reading safely (`docs/build-specs/reading-and-rendering.md`)

HTML mail is the first untrusted HTML the estate renders to people. Three layers (D7):

1. **Sanitised by allow-list in the mail service** (nh3 with a CSS property allow-list pass):
   - tags, attributes and CSS properties are named one by one;
   - no script, no event attribute, no form, no `iframe`/`object`/`embed`, no `base`, no `meta refresh`, no
     `javascript:`/`data:` links, no CSS `url()`;
   - `cid:` images are rewritten to the part door;
   - remote images are replaced by a placeholder, and their tracking pixels dropped;
   - hidden content is removed and flagged;
   - links are rewritten to open in a new tab with `noopener noreferrer`.
2. **Served on its own response.** `/m/{mailbox}/msg/{message}/body` carries `Content-Security-Policy: sandbox allow-popups
   allow-popups-to-escape-sandbox allow-scripts; default-src 'none'; script-src 'nonce-…'; img-src 'self' data:; style-src
   'unsafe-inline'; font-src data:; frame-ancestors 'self'`.
   - It is shown in an `<iframe sandbox>` **without `allow-same-origin`**, so its origin is opaque. Even a script that slipped
     past the sanitiser would hold no session and reach no page. DocCloud's sandboxed responses are the precedent.
   - The only script the policy admits is one nonce'd line that reports the body's height to the page.
   - Parts and images inside it load by **signed, per-message URLs** with a short expiry, which need no cookie.
3. **Remote images on request.** "Show images" works for this message, or "always from this sender", through the **image door**.
   The door is its own small unit (`malumail-images`, loopback `{{ML_IMAGES_PORT}}`) with no credentials and its own user. Its
   systemd unit denies private address ranges. It:
   - resolves a name once and pins the address;
   - accepts only `ipaddress.is_global` addresses, http or https, on ports 80 and 443;
   - follows at most three redirects, each re-checked;
   - ignores proxy environment variables;
   - sends no cookies and no referrer;
   - limits size and type.

   The sender learns nothing until the person asks.

**Attachments** stream with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. An HTML or SVG attachment is
never rendered inline. A link shows its real host beside its text.

**Above the frame** the page shows:

- the text part;
- the headers (`From`, `Reply-To`, list headers);
- a **"from outside the business"** banner;
- a **sender-mismatch** warning (a colleague's display name on an outside address);
- the DMARC result and the spam score.

### 6.5 Settings, roles, the worker

- **`ml_settings`** (the `<prefix>_settings` skeleton):
  - the undo-send default (10 s);
  - the images default (ask);
  - the body index cap (100 kB);
  - the held-mail expiry (72 h);
  - the agent policy defaults;
  - **the agents' business-wide external budget** and its warm-up start;
  - **the two business-wide switches** (`hold_all_agents`, `hold_all_external`);
  - the monitoring notice (text and version);
  - the Clerk's hour.
- **Roles**: `ml_rights`, `ml_roles`, `ml_role_rights` and `mcp_app_roles`, in Consultant Tracking's shape.
- **The worker** (PHP, every minute) runs:
  - dispatches (mail turns);
  - notifications and the outbox of notifications;
  - snoozes waking;
  - held mail expiring;
  - break-glass rows expiring;
  - the index's catch-up checks;
  - the Clerk's inputs.

### 6.6 Activity memory

`log_activity()` writes `entity.verb` with `"application": "malumail"` first. Every row carries `mailbox_id` (an appended
column). **What is logged is what people and agents do, never what mail says** (D14).

| Kind | Events |
|---|---|
| **Reading by anyone but the owner** — the access log, §3 | `mailbox.view` (a list or search view, one per mailbox per half hour), `message.open` (per conversation), `message.part`, `message.raw`, `mcp.read` (a records-MCP read of a mailbox that is not the caller's own), `k7.read` |
| **Mail** | `message.send`, `message.schedule`, `message.cancel`, `message.archive`/`move`/`trash`/`spam`/`label`/`flag` (one row per bulk action, with a count), `thread.snooze`, `thread.mute`, `thread.take_over`, `draft.save` (one per compose session) |
| **Oversight** | `review.approve`, `review.edit`, `review.reject`, `review.reply`, `review.expire`, `outbound.refuse`, `policy.set`, `mailbox.pause`, `mailbox.resume`, `unscreened.release`, `sender.mark_known`, `agent.dispatch` |
| **Administration** | `mailbox.create`, `mailbox.suspend`, `mailbox.reactivate`, `mailbox.quota`, `mailbox.password_reset`, `mailbox.connect`, `alias.create`/`update`/`delete`, `permission.grant`/`revoke`/`break_glass`, `domains.serve`, `notice.save`, `notice.acknowledge`, `credential.failed` |

**What an episode may carry:** ids, counts and addresses' domains. A subject excerpt (≤ 200 characters) appears **only for agent
and shared mailboxes**. Never a body. **Nothing of a person's mailbox's content** ever reaches the tenant's memory.

The oversight trail — the activity log with `agent_run_id`, `mail_reviews` and the outbound rows — is append-only for agents'
mail. It covers research R47 without a separate `mail_events` table.

**Never logged:** polling, the owner's own list views, sync passes.

### 6.7 Tables against the estate — read, reuse, new (the shared-schema rule)

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | **reuse** | the Data Lake's kit `db/001` (with the mirror fix and the generated `is_agent`) |
| `activity_log`, `activity_ingest_state` | **reuse** | `memory.md`; **appends** `mailbox_id` |
| `mcp_access_tokens` | **reuse** | HR `db/003` |
| `ml_rights`, `ml_roles`, `ml_role_rights`, `mcp_app_roles` | **reuse** (shape) | Consultant Tracking `db/004` (`ct_` → `ml_`); `ml_rights` **appends** `held_by` (`anyone` / `people` / `super_admins` — oversight a person's, every admin right a super-admin's, D2) |
| `ml_settings` | **reuse** (skeleton) | Help Desk `db/005` |
| `mailboxes` | **reuse** | Help Desk `db/012`, the queue link loosened as the CRM did; **appends** §6.2's columns |
| `outbound_email` | **reuse** | Help Desk `db/012` + Sales CRM `db/011`'s appended columns; `to_address` = the first recipient, `subject` may be empty; the CRM's `contact_id`/`deal_id` kept without references; **appends** §6.3's; `status` widened |
| `email_templates` | **reuse** | Sales CRM `db/011` |
| `mailbox_credentials` | **reuse** (the family) | Inventory `db/009` `source_credentials`, `source_id` kept without its reference; **appends** `mailbox_id`, `status`, `last_ok_at`, `failed_at`, `failure`; sealed boxes instead of secretbox |
| `mailbox_permissions` | **reuse** (the family) | Spaces `db/007` `page_permissions` (`page_id` → `mailbox_id`); levels ⊂ view/triage/send/full; **appends** `reason`, `expires_at`, `break_glass` |
| `tags`, `record_tags` | **reuse** | Help Desk `db/009` + DocCloud's appended columns; Sales CRM `db/006` (bigint `record_id` → `mail_message_keys.id`); **appends** `tags.mailbox_id` |
| `attachments` | **reuse** | GL `db/009` canonical (bigint keys); `record_type` ⊂ `mail_draft`, `outbound_email` |
| `notifications`, `notification_prefs`, `notification_outbox` | **reuse** | Spaces `db/012` (member-only) |
| `agent_dispatches` | **reuse** | Spaces `db/012` (with `kind`); **appends** `mailbox_id`, `message_key_id`, `acting_member_id` |
| `worker_passes` | **reuse** | Spaces `db/016` |
| `search_index` | **not used** | the message index is itself the search structure: Gmail's operators filter the same rows the text matches, at mail's volume (§6.3) |
| `inbound_email` | **not used** | Help Desk's processing queue of captured mail; this application mirrors mailboxes instead |
| `contacts`, `accounts` | **read** (Extended) | Sales CRM's K7 `contacts_index`, for names in autocomplete |
| `ml_domains`, `mail_message_keys`, `mail_folders`, `mail_messages`, `mail_threads`, `mail_thread_states`, `mail_viewer_reads`, `mail_correspondents`, `mail_identities`, `mail_drafts`, `mail_reviews`, `agent_mail_policies`, `ml_notice_acknowledgements` | **new canonical** | see below |

**Why the new tables are new.** They were compared with:

- Help Desk's `inbound_email` — captured mail awaiting processing, not a mailbox's mirror;
- Spaces' `messages` — chat;
- the CRM's `activities` — dated touches with an e-mail direction;
- the CRM's `contacts` — the people the business sells to, not every address in a mailbox.

Nothing in the estate mirrors a mailbox or governs an agent's mail.

The new canonical tables go into the plugin's `shared-schema.md` catalogue (§12, a plugin item). The next application that reads
or sends mail — Help Desk replying from the mailbox, the CRM's logging — takes them verbatim.

## 7. The question inventory — what MaluMail exists to answer

**Record questions** (the records MCP; the Phase 1 surface names each tool's arguments):

| # | Question | Tool |
|---|---|---|
| 1 | Which mailboxes can I open, at what level, with how much unread? | `find_mailboxes` (resolver), `mailbox_get` |
| 2 | What is new in my inbox; what is unread, starred, snoozed, scheduled? | `mailbox_overview`, `mail_list` |
| 3 | Find mail — Gmail's operators | `mail_search` |
| 4 | What does this conversation say? | `find_threads` (resolver), `thread_read` (extracted text, untrusted-content fenced, attachments listed) |
| 5 | This message, its headers, its attachments | `message_read`, `message_headers`, `find_attachments`, `attachment_read` (allowed types only) |
| 6 | What have we exchanged with this address or this company? | `correspondence` (also the K7 share) |
| 7 | Who is this correspondent — first and last contact, counts? | `find_correspondents` (resolver) |
| 8 | Which folders and labels does this mailbox have? | `find_folders`, `find_labels` (resolvers) |
| 9 | What is in my outbox — scheduled, deferred, failed? | `outbox_list` |
| 10 | What is waiting for my review; why was it held? | `review_queue`, `review_get` |
| 11 | What did agent A send and receive this week; what was held, rejected, edited? | `agent_mail_summary`, `oversight_summary` |
| 12 | Which mail is waiting unscreened for an agent? | `unscreened_mail` |
| 13 | What is agent A allowed to send unseen? | `agent_mail_policy_get` |
| 14 | Who can open this mailbox, and who opened it lately? | `mailbox_access`, `mailbox_access_log` |
| 15 | Which forwarding addresses exist, and which forward outside the business? | `find_aliases` |
| 16 | Which mailboxes are near quota, failing to sync, suspended? | `mailbox_health` (the mailboxes the caller can open; every one for a Mail admin) |
| 17 | Which hosted domains do we serve, and are SPF/DKIM/DMARC verified? | `domains_list` (admin) |
| 18 | My canned replies; my identities and signatures | `find_templates`, `find_identities` |
| 19 | My notifications; have I acknowledged the monitoring notice? | `my_notifications`, `notice_status` |
| 20 | Who is this person or department? | `find_members`, `find_departments` (resolvers) |
| 21 | Search everything I can see | `records_search` (the guarded one) |
| — | The kernel's | `app_roles`; the share `correspondence_index` |

**Activity questions** (the activity MCP over the tenant's memory):

| # | Question | Tool |
|---|---|---|
| A1 | Who opened this mailbox (not its owner) this month? | `mailbox_trail` |
| A2 | What did the overseers decide this week, and how fast? | `review_trail` |
| A3 | What did agent A do in mail yesterday? | `member_activity` |
| A4 | When was info@ forwarded outside, and by whom? | `record_history` |
| A5 | Which agents sent most, and how much of it was held? | `agent_activity` |
| A6 | Anything else | `activity_search`, `recent_activity` |

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract, the Data Lake's kit. A member's `notification_prefs` row exists from sign-on.
  Departments are mirrored for `department` rows.
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it and to the share alone (reading
  `X-OS-Consumer` / `X-OS-Consumer-Agent`, K26).
- **Agents**: run tokens on the two read servers (the run-facts gate, fail closed; **K38's `requested_by`** tells the records
  server who asked: an agent answering a person reads only what both may open, except §3's one rule for the Mail Expert on the
  asker's own mailboxes with `ai_read` on); the Actions MCP from
  the registry (`deploy/kernel-registry-malumail.json`, resolvers `find_mailboxes`, `find_folders`, `find_labels`,
  `find_threads`, `find_correspondents`, `find_templates`, `find_identities`, `find_members`, `find_departments`); the
  approval hook before every `deletion`; the manifest's `Who` = *people* for everything §5 refuses to agents; the chat
  endpoint for the command bar and the mail turns (acting member: the asker, or the mailbox's primary overseer until K8).
- **The overseer link**: the directory feed does not say who manages an agent (K44). Until it does, a Mail admin names an
  agent mailbox's overseers when creating it.
- **Texts (K6)**: "mail held for your review", for an overseer who opted in.
- **Notification mail** stays the installer's `mail` step (`/v1/send`).

**The application's doors** (same-origin, under the session, or under an action token for agents):

| Door | What it does |
|---|---|
| `GET /m/{mailbox}/f/{folder}`, `/m/{mailbox}/v/{view}` | the conversation list (cursor-paged by `last_message_at`) |
| `GET /m/{mailbox}/changes?since={token}` | the cheap poll: 204 when nothing changed, else the counts and the rows to swap (§8.1) |
| `GET /m/{mailbox}/t/{thread}` | the conversation |
| `GET /m/{mailbox}/msg/{message}/body` | the sanitised HTML on its own sandboxed response (§6.4) |
| `GET /p/{signed}` | an attachment or a `cid:` image, streamed from the mail service by a signed, per-message, short-lived URL (no cookie needed) |
| `GET /m/{mailbox}/msg/{message}/original` · `/raw` | the Original screen · the `.eml` download |
| `GET /img/{signed}` | the remote-image door (§6.4), signed per message, on request only |
| `POST /m/{mailbox}/…` | every action of the manifest (§9), each a handler with `require_post()`, `verify_csrf()`, the level check and `log_activity()` |
| `POST /m/{mailbox}/drafts/{draft}/files` | an attachment for a draft (a single upload ≤ 7 MB; the message must stay under the server's ~9.8 MiB) |

`{message}` in a URL is `(folder, uidvalidity, uid)` in one token; a stale UIDVALIDITY answers "this message moved" and
reloads the list.

**The mail service's door** (`127.0.0.1:{{ML_MAILER_PORT}}`, HMAC-signed with `ML_MAILER_KEY`, never proxied): `sync`,
`fetch`, `part`, `raw`, `mutate`, `append_draft`, `submit_now`, `quota`, `test_login`, `status`, and `admin` (the Mailbox MCP
calls, §6.1).

### 8.1 Live updates without a socket

The mail service learns of new mail within a second (`NOTIFY`/`IDLE`) and writes the index. The browser polls
`/m/{mailbox}/changes?since=` every **15 s while the tab is visible and 60 s while hidden** (Spaces' visibility guard); the
door answers **204** from one indexed query when nothing changed, so a poll costs a few milliseconds and no IMAP. The
document title and the favicon carry the unread count. Boards' WebSocket relay could carry `mail:<mailbox>` rooms later
(Extended); `mpm_prefork` makes long-held connections expensive, so v1 does not hold any.

The vhost's public allow-list is `/sso`, `/sso/logout`, `/mcp/records`, `/mcp/activity`, `/api/v1/health`, and the signed
`/p/` and `/img/` doors; everything else is under the session's cookie on the same origin. **No webhook door**: MaluMail's webhooks are not used (§0.2).

## 9. Screens

The nxl look; no modals (the record picker excepted); full-page compose; 375 px first for reading and replying. The mail
list is a **list** (Gmail's), not cards; the admin's mailboxes and the oversight overview are **cards** (the owner's preference
for named things).

| Screen | URL | What it is |
|---|---|---|
| Home | `/` | redirects to the default mailbox's Inbox (the person's own, else the first they can open) |
| Mailbox rail | (layout) | the mailboxes the person can open (own, shared, overseen agents) with unread counts; the views and folders of the current one; labels; Compose |
| Folder / view | `/m/{mailbox}/f/{folder}`, `/m/{mailbox}/v/{inbox,starred,snoozed,sent,drafts,scheduled,outbox,all,spam,trash}`, `/m/{mailbox}/label/{label}` | the conversation list: select, bulk bar (archive, delete, spam, mark, move, label, snooze), star, labels, attachment icon, snippet, date; cursor paging; the "N new" bar from the poll |
| Conversation | `/m/{mailbox}/t/{thread}` | messages (earlier collapsed), quoted text folded, the sandboxed body, attachments, Show images, the outside/mismatch banners; Reply, Reply all, Forward, Archive, Delete, Spam, Move, Label, Snooze, Mute, Mark unread, Print, Show original; for an agent's mailbox: Take over |
| Original | `/m/{mailbox}/msg/{message}/original` | the headers and source; Download `.eml` (`/raw`) |
| Compose | `/m/{mailbox}/compose`, `?reply={message}`, `?reply_all=`, `?forward=`, `/m/{mailbox}/drafts/{draft}` | From (identities), To/Cc/Bcc with autocomplete, subject, the editor (rich or plain), attachments, signature, canned replies; Send, Schedule, Save draft, Discard; after Send, "Undo" for the undo window |
| Search | `/m/{mailbox}/search?q=`, `/search?q=` (every mailbox I can open) | operators; results as conversations |
| Mailbox settings | `/m/{mailbox}/settings` | display name, identities and signatures, undo seconds, conversation view, images, density, keyboard shortcuts, **Let the Mail Expert read my mail when I ask** (a person's mailbox, off by default) |
| Monitoring notice | `/notice` | the business's notice; Acknowledge (on first use, and when its version changes) |
| Access | `/m/{mailbox}/access`, `/m/{mailbox}/access-log` | who can open it, at what level (the record picker: people, departments, agents); the log of every view by anyone but the owner |
| Folders · Labels · Templates | `/m/{mailbox}/folders`, `/m/{mailbox}/labels`, `/templates` | create, rename, delete; colours; canned replies |
| **Oversight** | `/oversight` | cards per agent mailbox I oversee: received, sent, held, rejected (7 days), last activity, Pause/Resume |
| For review | `/oversight/review`, `/oversight/review/{outbound}` | the queue (overseers', and a person's own for an agent's mail from their mailbox); the review page of §5; Approve, Edit, Reject with a note, Reply to the agent, Take over |
| Unscreened | `/oversight/unscreened` | mail that did not wake an agent: Release (one turn), Mark sender known, Spam |
| Agent policy | `/m/{mailbox}/policy` | §5's settings with their meaning; the history |
| Admin | `/admin` | domains served, mailboxes, aliases, sync health at a glance |
| Domains | `/admin/domains` | MaluMail's hosted domains: serve or not, internal or not, SPF/DKIM/DMARC flags |
| Mailboxes | `/admin/mailboxes`, `/admin/mailboxes/new`, `/admin/mailboxes/{mailbox}` | cards; create (domain, local part, kind, owner — the record picker —, quota, overseers for an agent); the detail: suspend/reactivate, quota, reset password (shown once), connect / reconnect the webmail, the credential's state, intake owner, share with applications |
| Forwarding | `/admin/aliases`, `/admin/aliases/new`, `/admin/aliases/{alias}` | MaluMail's aliases: source, destinations (outside destinations flagged and confirmed), edit, delete |
| Sync health | `/admin/sync` | per mailbox: state, last sync, index coverage, errors; Re-sync, Re-index text |
| Admin settings · Agents | `/admin/settings`, `/admin/agents` | §6.5's settings (the notice, the business budget, the two switches); the agents, their dispatches (Retry) |
| Notifications · My trail · Tokens | `/notifications`, `/settings/notifications`, `/my/trail`, `/tokens` | the kit's |

**Keyboard** (Gmail's): `c` compose, `/` search, `j`/`k` next/previous, `o`/Enter open, `u` back to the list, `e` archive,
`#` delete, `!` spam, `r` reply, `a` reply all, `f` forward, `s` star, `x` select, `l` label, `v` move, `b` snooze,
`Shift+I`/`Shift+U` read/unread, `g i` go to Inbox, `?` the list of shortcuts. Off by default for agents' screens, on for people.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | This document and the owner's answers. **The mail spike** (§10.1). The schema `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`). The kit, proven without a kernel (`tests/phase0/run.sh`, a fake MaluMail MCP and a fake IMAP/SMTP fixture). `maludb-os.json`, `os/{expert,clerk}.md`, the skills, `deploy/` with `ROOT_STEPS.sh` (the five ports, the `malumail-mailer` user, the sealing key pair, `/etc/malumail/mailer.env`, `ML_MAILER_KEY`, `mod_headers`). The installer's `plan` clean | the owner's go |
| 1 | `docs/malumail-mcp-tool-surface.md`, `docs/malumail-action-manifest.md`, `mcp/action_registry.json`; the build specs `mail-service` (the service's loops, its door, the IMAP client, threading, the circuit breaker), `reading-and-rendering` (the sanitiser's allow-list, the sandbox, the image door), `sending-and-oversight` (MIME, the outbox, the policy function, the review flow, the mail turn's untrusted-content frame); the slice specs | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles`, `ml_has_right()`, the ingest bridge, the shell (the mailbox rail, the record picker, the application switcher, the search box), `/api/v1/health`, the vhost, the worker's and the mail service's empty passes | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; 375 and 1280 |
| 3 | The slices, in order (below) | each: screens + handlers + logging + manifest entries + tools, proven on a scratch database against the fixture **and** against the test mailbox at MaluMail |
| 4 | The two MCP servers with the run-facts gate (K38's `requested_by`) and `thread_read`'s untrusted-content frame; `app_roles` and the share admitted to the kernel's token; the registry wrapper; the two agents with their grants | from the command bar, "draft a reply to Jon saying we accept" leaves a draft in the person's Drafts; an agent's `message_send` to a first contact is held and an overseer approves it from the queue; an agent's review decision is refused; "create a forwarding address" from the command bar opens the prefilled admin screen |
| 5 | The owner's `app_install.php apply` (ports pinned first), DNS and TLS for `webmail.<domain>`, MM1 and MM2 in place, the hires | **a person reads, searches and answers their mail at 375 px and on a desktop, sends with undo, and their phone shows the same mailbox; a Mail admin creates an agent's mailbox and a forwarding address from the OS; mail to the agent wakes it, its reply to a new contact is held, the overseer edits and sends it, and the access log shows who looked** |

**The Phase 0 schema proof** covers: every actor of §2 and the wall (a `view` holder changes nothing; a Mail admin reads no
person's mailbox; a grant on a person's mailbox by anyone but its owner is break-glass with a reason, an expiry and a notice; a
department row admits no agent; an agent row on a person's mailbox capped at `send`; agents refused every decision of §3; the
Expert's `ai_read` rule), the policy function (`ml_outbound_hold_reasons()`: every reason of §5, the internal test over To, Cc,
Bcc and alias destinations, known = sent to or marked) for every mode, the refusals (loop rule, the same answer), the outbox's
status machine, labels and conversation states surviving a UID change and a rebuild (message keys), a forged duplicate
`Message-ID` inheriting nothing, the threading rules, the search operators against `tsv`, an intake mailbox never marked seen
nor its INBOX moved, the dispatch rule (`wake_from`, DMARC, internal only when sent from here, muted, taken over, paused,
kernel intake), and the reused tables verbatim against their siblings, with their loosenings.

**The Phase 3 slices, in order:**

1. **Domains, mailboxes and forwarding** — the CRUD exemplar: the mail service's `admin` door over the Mailbox MCP (Help Desk's
   `malumail_mcp_call()`, grown, in Python), served and internal domains, mailbox cards, create (a person's password shown once
   on the admin's screen; an agent's or shared mailbox's sealed, never shown), suspend, reactivate, quota, reset, connect,
   aliases with outside destinations flagged, the owner's `full` row, overseers and the primary overseer, the Clerk's row,
   `intake_owner` proposed from the webhook record.
2. **The mail service and the list — the first exemplar.** The service, its door, the IMAP client, credentials and the
   circuit breaker, `QRESYNC` sync, `NOTIFY`/`IDLE`, threading, the text pass; the mailbox rail, folders and views, the
   conversation list, unread counts, the poll.
3. **Reading — the second exemplar.** The conversation, the sanitiser, the sandboxed body, images on request, attachments,
   the original; flags, archive, move, trash, spam, mark, bulk; per-viewer read state; intake mailboxes; keyboard shortcuts.
4. **Writing — the third exemplar.** The editor, reply/forward quoting, identities and signatures, drafts to the Drafts
   folder, attachments, the outbox with undo and schedule, MIME and submission, Sent and `\Answered`, the rate-limit
   deferral.
5. **Agents' mail and oversight — the fourth exemplar.** Policies and `ml_outbound_hold_reasons()`, the review queue and page,
   take over and mute, pause, the mail turn (dispatch, `wake_from`, the untrusted-content frame), Unscreened, the oversight
   cards.
6. **Search, labels, snooze, folders and templates.**
7. **Delegation and access.** Permission rows with the picker, the four levels, sending on behalf, the access log,
   break-glass.
8. **Correspondents, autocomplete and the K7 share.**
9. **Notifications.** New mail in the bell for chosen mailboxes, held mail for overseers (in-app, mail, K6), failures for
   admins, the Clerk's note.
10. **Home, admin and settings.** Sync health, usage against quota, admin settings, agents and dispatches, tokens.

**Size.** Fourteen specs (three build specs, ten slices, the tool surface and manifest as one), about 47 screens, 80
actions, 32 records tools and 7 activity tools; one Python service of a new kind for the estate (the first IMAP/SMTP
client). The honest estimate:

- Phase 0 with the spike: a day (the spike needs MM2 and two test mailboxes first);
- Phases 1–2: a day;
- slices 1–5: four to five days — the mail service and safe rendering are not templated work;
- slices 6–10 with Phase 4: two to three days;
- Phase 5 with the owner and the infrastructure side (MM1).

**Who builds what** (D16): **the planning model** builds Phase 0 with the spike, Phases 1–2 and slices 1–5 (the service, the
sanitiser, sending and oversight decide the application's safety); **Sonnet 5.5** builds slices 6–10 and Phase 4.

### 10.1 The mail spike (Phase 0, before the schema is final)

The spike proves the estate's first IMAP/SMTP client against **the test mailbox** the handoff names (`mboxtest.example`, never
a real customer's), from this server, in a scratch directory outside the repository on ports 9201–9207. **Before it runs:
MM2 is agreed with the infrastructure side**, and the owner supplies **two** test mailboxes on `mboxtest.example` (the second
for the forged-sender case) with their credentials. The spike's client stops at its first authentication failure. It measures:

- login, the post-auth `CAPABILITY`, `ENABLE QRESYNC`; an initial sync of 5,000 synthetic messages (APPENDed by the spike):
  envelope pass time, text pass time, index size;
- new mail → the index: through `NOTIFY` and through `IDLE`, by a message the spike submits to itself;
- a flag changed by another client (a second connection) → the index, through `QRESYNC`;
- `MOVE`, `APPEND` of a draft and its replacement, `BINARY` partial fetch of a 5 MB attachment, `GETQUOTAROOT`;
- submission on 587 with an HTML + attachment message, the `\Sent` `APPEND`, `\Answered`; a burst to see the `451` shape;
- **the sanitiser** on this CPU (no AVX — the Data Lake's finding): `nh3` (Rust ammonia) with a CSS property allow-list pass
  of our own, against a corpus of hostile mail (script, event attributes, CSS `url()` and expressions, `data:` and
  `javascript:` links, SVG, nested forms, mXSS and encoded tricks, hidden text), compared with a stdlib allow-list over
  `html.parser` as the fallback;
- the Python IMAP client: **IMAPClient** (BSD, `IDLE`, `CHANGEDSINCE`) in a thread per mailbox, against the stdlib `imaplib`
  (3.12 has no `IDLE`) wrapped; whether `NOTIFY` needs raw commands either way (neither library speaks it);
- **how a message from a colleague proves itself**: what `X-Spamd-Result` and `Received` carry for mail between two mbox01
  mailboxes, for a forged From from outside, and for a forged From submitted by the second test login — so §5's wake rule
  is confirmed or tightened on evidence.

The results go into `docs/research/04-mail-spike.md` and the `mail-service` and `reading-and-rendering` specs; the spike's
messages are expunged from the test mailbox and its code deleted.

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order):

- **vacation replies, filters and keep-a-copy forwarding** — through Sieve once MaluMail exposes it (MM4);
- **spam / not-spam that trains Rspamd** (MM6) — v1's buttons move the message;
- **Help Desk and the CRM on the webmail's tables**: Help Desk's replies sent from the mailbox (so they appear in its Sent),
  the CRM's logging read from `correspondence_index` instead of a BCC mailbox;
- **the kernel's assistant mail through MaluMail**: one intake for an assistant's mailbox, the kernel's channel reading the
  webmail's dispatches (K45's long form);
- names from the CRM's `contacts_index` in autocomplete (a K7 read);
- category tabs, "Important", nudges; smart compose and summaries by the Mail Expert;
- a live socket on Boards' relay (`mail:<mailbox>` rooms);
- a mail graph (a MaluDB memory database of the agent and shared mailboxes — DocCloud's model);
- mailboxes outside MaluMail (generic IMAP);
- a hard delete of a mailbox through MaluMail's operator job;
- **retention and legal hold**: per-mailbox retention (Germany's HGB §257 asks six years for commercial letters; the GDPR asks
  for less), Trash and Spam emptied after 30 days, a legal hold that suspends every deletion — v1 deletes nothing on its own;
- labels mirrored to IMAP keywords up to a cap (about 15), so phone clients and Sieve filters see them;
- a link interstitial that shows the real host before leaving;
- evals of the agents' drafts against what reviewers changed.

**The research's MUSTs this plan defers, and why:** R56 retention and legal hold (above; v1 deletes nothing by itself);
R59–R60 Sieve, vacation and filters (MaluMail exposes no Sieve yet — MM4); R63 the MaluMail side's own items (§12); R46's
submission-only hard stop (MM10; suspending the mailbox is the hard stop meanwhile). R47's `mail_events` is met by the activity
log, `mail_reviews` and the outbound rows (§6.6); R54's "AI may read" switch is `ai_read` (§3).

**Later**: S/MIME and PGP; offline.

## 12. What the kernel, MaluMail, the plugin and the siblings owe this application

| # | Item | State |
|---|---|---|
| **K43** | **Catalog entry `malumail`** (kind `ours`, the unnamed top area beside Google Workspace and Microsoft 365, category `email` — already admitted —, `feather-mail`); on request, not a default (D15) | **built and applied** — kernel `db/181` (1c3bfd5), 2026-10-11 |
| **K44** | The directory feed and the claims carry an agent's `manager_member_id` and `principal_member_id`, so an agent mailbox's overseers default to its manager and an assistant's mailbox to its principal | owed; a Mail admin names overseers until then |
| **K45** | The kernel's email intake keeps its own record of handled mail (by `Message-ID`) and never reads `\Seen` as "handled" | owed; D13's rule keeps the webmail from setting `\Seen` on the kernel's intake mailboxes meanwhile |
| K38 | Run facts say who an agent's run acts for | **built** (8c9b6fa) |
| K26 | K7 names the consumer; `timeout_seconds` | **built** (db/173) |
| K6 | Texts to a member | **built** — held-mail alerts |
| K8 | Wake an agent from an application | owed to every sibling; the chat-turn dispatch with the primary overseer as acting member until then |
| **K46** | The kernel's agent and assistant sends go through this application's outbox (or its policy) once the application serves that mailbox | owed; until then oversight covers what agents send through this application (§5) |
| **MM1** | **Application passwords per mailbox, bound to the OS server's address**: a table, a second Dovecot SQL passdb with `allow_nets`, Mailbox MCP tools to issue (returned once), list and revoke | **required for people's mailboxes in production**; the development and the agents' mailboxes do without (D3) |
| **MM2** | **How this server's logins are protected from the mail server's abuse protection** — agreed with the infrastructure side, scoped to the application-password path (detail in the owner's handoff, not here) | **required before the spike** |
| MM3 | An identity rule at submission: which addresses a login may send as (itself and the aliases that deliver to it) | owed; the webmail restricts identities itself, and §5's wake rule treats internal-looking mail as outside unless this application sent it |
| MM4 | Sieve for a mailbox: ManageSieve reachable by the OS server with the application password, or Mailbox MCP tools over doveadm's `sievePut`/`sieveActivate` — the webmail owns one generated script per mailbox (vacation with a one-day minimum, filters, keep-a-copy forwarding) | Extended until it exists |
| MM5 | `\Archive` as an auto-created special-use folder | optional; the webmail creates `Archive` by name |
| MM6 | Rspamd learning on moves into and out of Junk | Extended |
| MM7 | An API key scoped to named hosted domains | **required where one MaluMail customer serves several businesses**; otherwise the served-domain list is the application's wall |
| MM9 | A verified password change for a mailbox (old password checked) through the API | optional; resets by a Mail admin meanwhile |
| MM10 | Stop a mailbox's sending without stopping its mail (a submission-only switch) | optional; suspending the mailbox is the hard stop meanwhile |
| plugin | `shared-schema.md` gains the new canonical mail tables and the three family reuses; the sanitiser and the sandboxed body as the estate's pattern for untrusted HTML | for the owner to raise upstream after slice 3 |
| HD8 / CRM7 | Help Desk and the CRM stop reading `\Seen` as "handled" (their own processed set) | owed by the siblings; D13 protects them meanwhile |
| owner | DNS and TLS for `webmail.<domain>`; the MaluMail API key with Mailbox MCP for the tenant; two test mailboxes for the spike; MM1/MM2 with the infrastructure side | Phase 0 (spike) and Phase 5 |

MaluMail's items take the prefix **MM**: the kernel's records numbered MaluMail's earlier items M1–M4 and MaluDB's M1–M12 in
one series, and the two collide. (MM8 is the infrastructure side's own housekeeping, kept in the owner's handoff.)

## 13. The decisions (the owner took every recommendation on 2026-10-11 — §15)

The repository's visibility (**public**) and the DNS label (**`webmail`**) were the owner's answers on 2026-10-10.

| # | Decision | Recommendation | Alternative |
|---|---|---|---|
| D1 | Name, key, prefix, catalog | **"MaluMail"**, key `malumail`, label `webmail` (decided), database `<tenant>_malumail`, roles `malumail_rw` / `_records_ro` / `_activity_ro`, prefix **`ml_`**; the unnamed top area beside Google Workspace and Microsoft 365, category `email`, icon `feather-mail` | name "Mail"; area Operations beside Spaces |
| D2 | Roles, and who administers | **Two roles: Mail user** (`user`, capability `write`) **and Mail admin** (`admin`, capability `admin`, `is_admin`) (domains, mailboxes, forwarding, access, sync, settings) — **held by super-admins only**: the application refuses its admin screens to a holder who is not a kernel super-admin, saying why (the owner's brief: *super admins … create mailbox and forwarding accounts*); **agents never administer** — the Mail Expert opens the prefilled screen. Oversight is a per-mailbox level held by people, not a role | any holder of Mail admin, super-admin or not; a third Compliance role for break-glass and every access log |
| D3 | How the application reaches mail | **Two planes: admin through the Mailbox MCP** with the tenant's customer key, limited to the served domains; **content through IMAP and SMTP from one mail service** of the application's own, with **per-mailbox application passwords** (MM1), sealed, opened only in the service. Until MM1: a mailbox this application creates for an agent or a shared address keeps its own password sealed (no person's client to break); a person's mailbox waits for MM1 | extend the Mailbox MCP into a full client API (MaluMail builds folders, paging, HTML, attachments, drafts, deltas — the thin client the kernel has); or one customer-scoped privileged login bound to this server (one credential opens every mailbox — the blast radius of the customer key, but IP-bound); or a person types their own mailbox password once to connect |
| D4 | The index | **Mirror envelopes, flags, conversations and the text of every message** (≤ 100 kB a message) for Gmail-grade search; HTML, attachments and the raw message fetched on demand, never stored; the server stays the record, the index rebuildable | envelopes only; body search through IMAP (slow on large mailboxes — no server index) |
| D5 | Folders and labels | **IMAP folders are where a message lives** (an `Archive` folder created; archive = move there); **conversations span folders**; stars are `\Flagged`; **labels are the application's** (many per message, coloured, keyed by the message key; not seen by phone clients) | folders only in v1 |
| D6 | Live updates | **The mail service holds `NOTIFY`/`IDLE`; the browser polls a 204-cheap door** every 15 s visible / 60 s hidden; no socket | Boards' relay in v1 |
| D7 | HTML mail | **Sanitised by allow-list in the mail service, served on its own response with a sandbox CSP, framed with an opaque origin (no `allow-same-origin`; one nonce'd height script), parts by signed URLs; remote images held until asked** (per message, or always from a sender) through the application's image door; outside-sender and mismatch banners | plain text only in v1 |
| D8 | Writing | **Rich text** (bold, italic, underline, link, lists, quote, clear — a small vanilla editor) sent as multipart/alternative, plain-text mode per person; **undo send 10 s** (0–30); **schedule send**; drafts saved to the Drafts folder every 10 s; attachments to 7 MB a message (the server's limit); agents write markdown, sent as HTML + text | plain text only; no undo |
| D9 | Who sees whose mail | **Owner `full`; rows at view/triage/send/full** (Spaces' family); **`view` never changes the mailbox**; **a role never opens a person's mailbox** — only a row its owner made; any other grant on it is **break-glass** (a reason, ≤ 30 days, the owner notified); department rows admit people, never agents; every view by another (lists, conversations, files, MCP and K7 reads) in the mailbox's access log; **a monitoring notice** every person acknowledges on first use; agent mailboxes open to their overseers and the Mail admins | Mail admins read every mailbox (the employer's legal right in many places, but not the default here) |
| D10 | An agent's outbound mail | **`hold_all` for a new agent mailbox**; modes `internal_auto`, `allowlist_auto`, `auto`; first contact and attachments always held; **20 external a day, 50 an hour, 5 a conversation a day** (the warm-up, loops) raised by an overseer within a **business-wide external budget** that ramps over four weeks; **AI disclosure** (a footer and `X-OS-Agent`) and `Auto-Submitted` on unreviewed mail; RFC 3834's loop rules; held mail expires in 72 h; **reviewers are people** — the overseers, or the person whose mailbox an agent wrote from — in the application's queue, not the kernel's approvals; agents never decide; the Mail Expert and the Mail Clerk hired on install | `internal_auto` as the default; the kernel's approvals for every agent send (super-admins only) |
| D11 | An agent's inbound mail | **Authenticated mail in the INBOX wakes the agent as one chat turn**, its extracted text fenced as untrusted content (never raw HTML, links never fetched); auto-submitted, bulk and list mail never wakes; **internal-looking mail counts as internal only if this application sent it** (until MM3); **outside senders who are not known (sent to, or marked by a person) wait in Unscreened**; taken-over, muted and paused conversations wake no one | every arriving message wakes the agent; or none — the agent reads on a duty |
| D12 | Agents on people's mailboxes | **A person may share their mailbox with an agent** (their assistant) through an `agent` row at `view`, `triage` or `send` (a CHECK caps it): everything an agent sends from a person's mailbox is held for that person; **the Mail Expert reads a person's mail in a run that person started only when they switch on `ai_read`** (off by default) | agents never on a person's mailbox in v1 |
| D13 | Shared intake mailboxes | **A mailbox another reader uses as intake** (the kernel's assistant channel, Help Desk, the CRM) **is never marked `\Seen` by the webmail, and its INBOX messages are not moved**; read state there is per person; `intake_owner` proposed from MaluMail's webhook record; K45, HD8 and CRM7 make those readers independent of `\Seen` | the webmail behaves normally and the readers are fixed first |
| D14 | Activity memory and models | **Actions with ids, never mail text**; a subject excerpt only for agent and shared mailboxes; **a person's mail reaches a model only when the person asks with `ai_read` on, or delegates to an agent**; agents get extracted text by id, never inline; an agent's own mailbox is read by that agent; attachments are listed, never sent to a model in v1; mail that reaches a model also sits in the kernel's prompt ledger — its retention is the kernel's to match | subjects of every mailbox in the tenant's memory |
| D15 | K7 and default | **One share `correspondence_index`** over mailboxes marked *shared with applications* (agents' and shared by default, a person's by opt-in); no reads in v1; **on request, not a default** install | no share in v1; a fifth default beside HR, Projects, Help Desk and Spaces |
| D16 | Ports and the division | **8199 / 8852 / 8853, the mail service 8854, the image door 8855**, scratch 9201–9207. **The planning model** builds K43, Phase 0 with the spike, Phases 1–2 and slices 1–5; **Sonnet 5.5** builds slices 6–10 and Phase 4 | the Spaces division (workers from slice 4) |

**Open questions beyond the table:**

- **(a) The label.** `malumail.subello.com` already resolves to the front proxy (someone made it); the owner chose `webmail`.
  Recommendation: keep `webmail` (the installer derives one label per installation; the old name can be removed or point
  here). If `malumail.subello.com` was made for this application, say so and D1's label changes.
- **(b) MaluMail's items.** MM1 and MM2 are the infrastructure side's work on production mail servers; this plan does not
  touch them. Recommendation: the owner hands §12's MM rows to the infrastructure session now — MM2 first (the spike waits on
  it), then MM1 (people's mailboxes wait on it) and MM3.
- **(c) The plugins.** As for every application since 2026-10-10: installing `htmx-php-builder` 0.9.0 and
  `maludb-os-integration` from the org marketplaces is the owner's call; this work reads them from the local clones.

## 14. Ports, names and files

- Repository `/srv/apps/malumail` (origin `github.com/maludb/maludb-os-malumail`, **public**); record database
  `<tenant>_malumail` (`subello_malumail`); roles `malumail_rw`, `malumail_records_ro`, `malumail_activity_ro`; the tenant's
  memory database receives the episodes tagged `malumail`.
- `webmail.<domain>` (`vhost.label = "webmail"`).
- Ports, all five in `env.required` and written to `config/.env` by `ROOT_STEPS.sh` before `apply`:
  `APP_INTERNAL_PORT=8199`, `MCP_RECORDS_PORT=8852`, `MCP_ACTIVITY_PORT=8853`, `ML_MAILER_PORT=8854`, `ML_IMAGES_PORT=8855`.
- Units: `malumail-records-mcp`, `malumail-activity-mcp`; **`malumail-mailer`** (the mail service, `services/mailer/` in
  `mcp/venv`, user `malumail-mailer`); **`malumail-images`** (the image door, its own user, private ranges denied);
  `malumail-activity-ingest` (+ timer), `malumail-directory-sync` (+ timer); `malumail-worker` (+ timer, every minute).
- `maludb-os.json`: `catalog_key malumail`, `name "MaluMail"`, `business_area ""`, `category email`, `icon feather-mail`,
  `criticality high`, `vhost.label webmail`; no `scopes`; `sso`; `directory {reads: true, writes: false}`;
  `assistant {command_bar: true, agent: expert}`; `agents [expert, clerk]`, `hired_on_install: true`, the Clerk's duty;
  `approvals` (§5); endpoints: the two MCP servers, the UI, health; services (the seven above); `shares` (one); no `reads`.
- `config/.env`: the contract's keys, plus `ML_MAILER_PORT`, `ML_IMAGES_PORT`, `ML_MAILER_KEY` and `ML_SEAL_PUBLIC_KEY` (both
  generated by `ROOT_STEPS.sh`), `MALUMAIL_MCP_URL`, `ML_IMAP_HOST` (`mbox01.malumail.com`), `ML_IMAP_PORT` (993),
  `ML_SMTP_HOST`, `ML_SMTP_PORT` (587), and the installer's `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` for notification
  mail. **Not in `config/.env`:** the sealing secret key and `MALUMAIL_MAILBOX_API_KEY` (the customer key with Mailbox MCP —
  the owner's) live in `/etc/malumail/mailer.env`, 0400, owned by `malumail-mailer`, written by `ROOT_STEPS.sh`.
- `deploy/` files are templates (`{{DOMAIN}}`, `{{APP_DIR}}`, `{{APP_KEY}}`, the five ports). The vhost keeps the server's PHP
  upload limit (25 MB; a message is capped at ~9.8 MiB anyway) and guards its `Header` lines (`mod_headers`, ROOT_STEPS).
- Kernel files: `mcp/registries/malumail.json` (made by the installer), db/181 (K43).
- Proof scratch ports 9201–9207; scratch databases `ml_dev`, `ml_proof`, `ml_spike`; fixtures `tests/fixtures/` (synthetic mail only: a
  hostile-HTML corpus, threads, a 5,000-message mailbox generator; nothing of a real person).

## 15. The owner's answers

**2026-10-11 — every recommendation, as §13 writes it.** The owner walked through the sixteen decisions and the three open
questions in one sitting and changed none:

| # | The owner's answer |
|---|---|
| D1 | **"MaluMail"**, key `malumail`, label `webmail`, prefix `ml_`, the unnamed top area, category `email`, `feather-mail` |
| D2 | **Two roles, Mail user and Mail admin; Mail admin works only for a kernel super-admin**; agents never administer |
| D3 | **Two planes**: admin through the Mailbox MCP, content over IMAP/SMTP from the mail service with **per-mailbox application passwords (MM1)**; people's existing mailboxes wait on MM1 |
| D4 | **Envelopes, flags, conversations and the text of every message** in the index; the rest on demand, never stored |
| D5 | **IMAP folders where a message lives, conversations across folders, the application's own labels** |
| D6 | **The mail service holds `NOTIFY`/`IDLE`; the browser polls** (15 s visible, 60 s hidden); no socket |
| D7 | **Sanitised HTML in an opaque-origin frame; remote images on request** through the image door |
| D8 | **Rich text, undo send 10 s, schedule send, drafts in the Drafts folder**; agents write markdown |
| D9 | **The owner shares by rows; a role never opens a person's mailbox; any other grant is break-glass**; the access log; the monitoring notice |
| D10 | **`hold_all` for a new agent mailbox, the caps and the business budget, disclosure; reviewers are people** in the application's queue |
| D11 | **Authenticated mail from internal or known senders wakes an agent; unknown outside senders wait in Unscreened** |
| D12 | **A person may share their mailbox with an agent up to `send`; its sends are held for that person; `ai_read` off by default** |
| D13 | **The webmail never sets `\Seen` on an intake mailbox and never moves its INBOX**; K45, HD8 and CRM7 owed |
| D14 | **Actions with ids, never mail text; a person's mail reaches a model only by their choice** |
| D15 | **One share, `correspondence_index`; on request, not a default** |
| D16 | **8199 / 8852 / 8853 / 8854 / 8855, scratch 9201–9207; the planning model through slice 5, Sonnet 5.5 slices 6–10 and Phase 4** |

- **(a)** Keep **`webmail`**. `malumail.subello.com` was not made for this application; it is left as it is.
- **(b)** **The owner hands §12's MM rows to MaluMail's infrastructure side** — MM2 and the two test mailboxes first (the
  spike waits on them), then MM1 (people's mailboxes wait on it), then MM3. This work writes requests and never changes
  MaluMail's production servers.
- **(c)** The plugins stay **read from the local clones** (`~/htmx-php-builder` 0.9.0, `~/maludb-os-integration`).

## 16. State

- **2026-10-10.**
  - The owner asked for the application; the repository `maludb/maludb-os-malumail` created **public** and cloned to
    `/srv/apps/malumail`; the label **`webmail`** (the owner's answers).
  - The infrastructure side's handoff read; MaluMail's platform surveyed read-only
    (`research/01-malumail-platform-survey.md`); the estate surveyed (`research/02-estate-survey.md`); the field researched
    (`research/03-webmail-and-agent-mail.md`).
  - This design written, then reviewed with fresh eyes (25 findings, all taken: agents never decide reviews or administer;
    *known* means sent to or marked; internal-looking mail proves nothing until MM3; department rows admit no agent; any grant
    on a person's mailbox by another is break-glass; the access log covers lists, files, MCP and K7 reads; the frame has an
    opaque origin; the customer key and the sealing key live only with the mail service; message keys and anchored
    conversation states; drafts in the Drafts folder; the image door its own unit; MaluMail's abuse-protection details kept
    out of this public repository).

- **2026-10-11.** **The design APPROVED** — the owner took every recommendation, D1–D16 and (a)–(c) (§15); MaluMail's MM
  rows go to the infrastructure side from the owner.

- **2026-10-11, later.** At the owner's word ("go ahead with K43 and the kit"):
  - **K43 built and applied** — kernel `db/181` (1c3bfd5): the `malumail` row, kind `ours`, the unnamed top area, `email`.
  - **The kit built and proven** — Boards' Phase 0 kit with its later fixes (the five refusal codes plus 42501/22023, `one_row()`,
    the flash's link, the message page's action), renamed and fitted: `db/001`–`005`, `app/`, `bin/`, `html/`, `mcp/`, `tests/`;
    `tests/phase0/run.sh` **100 checks** without a kernel. Decisions taken in building it (none changes the design):
    1. **Two roles, no read-only role** (D2): a grant the kernel has sent no roles for reads `write` as Mail user and `read` as
       *no role* — a read grant never sends mail; the person is admitted by the kernel and told why the home refuses them.
    2. **`ml_rights.held_by`** (appended): `people` for `mail.oversee` (§3: an agent never decides, whatever role it holds) and
       `super_admins` for the six admin rights (D2: a non-super-admin given the Mail admin role is refused them, told why by
       `ml_right_refusal()`). The schema's own functions (db/006+) refuse the same decisions again at the act.
    3. **PHP never speaks to the Mailbox MCP and takes no webhook**: the kit's `malumail_mcp_call()` and webhook check were
       removed; `app/mail.php` is notification mail through MaluMail's REST send only; the proof greps `app/`, `html/`, `bin/` for
       the customer key, the sealing secret and `tools/call`.
    4. **The mail service connects as `malumail_rw`** (D1's three roles; it writes the index).
    5. `ml_settings` carries §6.5 with the D4–D10 defaults; a new notice text bumps its version by trigger.
    6. The worker's eight passes are stubs naming their slice (`index_catch_up` 2, `dispatches`/`held_expiry` 5, `snoozes` 6,
       `break_glass_expiry` 7, `notify_outbox`/`clerk_inputs` 9); `key_usage_prune` is real.
    7. Health reports the mail service and the image door (`ok` / `down` / `unconfigured`) without failing on them.
    8. The brand: an envelope on teal (`bin/make_brand_images.php`).

  **NEXT:** MM2 and two test mailboxes (MaluMail's infrastructure side) → the mail spike (§10.1) → Phase 0's schema (db/006+ with
  `db/proof/phase0_proof.sql`), the mail service's skeleton with the fake Mailbox MCP and the IMAP/SMTP fixture, the manifest,
  agents, skills and deploy → Phase 1 for the owner's approval. **People's existing mailboxes wait on MM1.**
