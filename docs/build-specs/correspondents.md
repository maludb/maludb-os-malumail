# Slice 8 — Correspondents, autocomplete and the K7 share

2026-10-11 · **Phase 1, for the checkpoint.** The people a mailbox writes to and hears from: who they are, what was exchanged with an
address or its company, recipient autocomplete in compose, and the one tool MaluMail shares with its sibling applications,
`correspondence_index` (design §4, D15). Not an exemplar: it copies slice 2's list for conversations and slice 1's cards for the
list. Design §3 (the K7 exception), §4, §5 (*known*), §6.3 (`mail_correspondents`), §7 (questions 6, 7). Prerequisites: slice 2 (the
index writes `mail_correspondents` as it syncs), slice 4 (sending makes a correspondent known), the kernel's K7 and K26.

## Divergences from the design

- **The share takes a `since`** (the tool surface names it; db/011's `ml_correspondence_index()` had none — `db/014_reading_search.sql` adds it).
- **A company's view leaves out public mail providers.** "What have we exchanged with this company" groups by the address's domain,
  which is meaningless for `gmail.com`; a fixed list of about twenty public providers offers the address only (decision 2).

## Who may do what

| Who | May |
|---|---|
| a reader (`view`) | the mailbox's correspondents and what was exchanged with each |
| a sender (`send`) | autocomplete from that mailbox in compose |
| an overseer (on an agent's mailbox) | mark a correspondent known (slice 5's `mail_sender_mark_known`, shown here when built) |
| an agent | `find_correspondents` and `correspondence` on what it may open |
| a sibling application (the kernel's token, K7) | `correspondence_index` over mailboxes **shared with applications** — agents' and shared mailboxes by default, a person's only by its owner's switch (slice 7's mailbox settings) — never a sensitive conversation |

## Screens

### `correspondents` — `/m/{mailbox}/people` (375 first)

Everyone this mailbox wrote to or heard from, as rows (a list, not cards — they are many): name and address, first and last contact,
sent and received counts, a **Known** chip with how (*sent* · *marked*) on an agent's mailbox, an **Outside** chip, and — on an agent's
mailbox for an overseer — Mark known (slice 5, when built). Filters: `q` (name or address, trigram), `known` (yes · no), `outside`
(yes · no); ordered by the last contact; cursor paging by `last_seen_at`. A non-owner's view writes `mailbox.view` (once per half
hour). Empty: *"No correspondents yet — they appear as mail comes and goes."*

### `correspondent-view` — `/m/{mailbox}/people/{correspondent}` (375 first)

`{correspondent}` is the address (the resolver's `id_field`), URL-encoded. The header: name, address, internal or outside, first and
last contact, counts, known; then **the conversations with this address** (slice 2's list partial, newest first) and, for an outside
domain that is not a public provider, a tab **Everyone at {domain}** — the conversations with any address of that domain, and the
domain's addresses with their counts. An address the mailbox never exchanged with answers 404.

## The autocomplete door — `GET /m/{mailbox}/complete?q=` (not a screen)

Compose's To/Cc/Bcc fields (slice 4) ask it with `hx-get` after two characters, 150 ms after the last key, and render its partial as
a list under the field (keyboard: arrows, Enter, Escape). Level `send` on the mailbox; 404 otherwise.

At most eight suggestions, from three sources in this order, each de-duplicated by address:

1. **This mailbox's correspondents** — those it **sent** to first, then by `sent_count + received_count`, then by `last_seen_at`;
   name or address matching `q` (the trigram index on `address`, `ILIKE` on `name`).
2. **The business's people** — the directory mirror's members with an email (people and agents), by name or address.
3. **The mailboxes the person can open** — by address or display name (never any other mailbox: §3, no address leaks).

Each suggestion shows the name, the address, and **Outside** when the address is not internal. The door logs nothing (it is typing,
not reading a mailbox), and it answers in under 50 ms on a mailbox of 5,000 correspondents (the proof times it).

## Keeping `mail_correspondents` (the contract other slices write by)

- **Received** — the sync (slice 2) calls `ml_correspondent_received(mailbox, from, name, received_at)` **once per message key**, when
  the key is first indexed — never again for the same message in another folder or after a re-sync — and only for mail that is not
  the mailbox's own. Cc'd addresses are not counted as received.
- **Sent** — the outbox (slice 4) calls `ml_correspondent_sent(mailbox, address, name, known_by)` for every To, Cc and Bcc of a sent
  message, with `known_by` = the person who sent it or approved the agent's draft; **NULL for an agent's unreviewed send**, which makes
  nothing known (§5).
- **Marked** — `ml_sender_mark_known()` (slice 5).
- **Internal** — `is_internal` follows `ml_is_internal_address()` at each touch, so an alias that starts forwarding outside makes its
  address outside at the next mail.

## The K7 share — `correspondence_index`

The records server's share route (Phase 4 builds the server; this slice builds the function, the log and the proof against a
stand-in of the kernel's K7 call):

- **Caller**: the kernel's token only, with K26's `X-OS-Consumer` (the consuming application's key) and `X-OS-Consumer-Agent` (its
  agent's member id, when an agent asked). A consumer-supplied `as_agent` is ignored (the kernel strips it; the route ignores it too).
- **Arguments**: `with` (an address or a domain — one of them), `since`, `limit` (1–100, default 25). An answer is at most 256 kB
  (K7's cap); rows past it are cut and `truncated: true` says so.
- **Answer** (`os.malumail-correspondence/1`): `[{thread_id, mailbox, mailbox_address, subject, participants, first_message_at,
  last_message_at, message_count, snippet ≤ 200, url}]`, `url` = `APP_URL/m/{mailbox}/t/{thread}` — the consumer's person opens it
  only if MaluMail's wall lets them (otherwise 404, the wall unchanged).
- **The access log**: one `k7.read` row **per mailbox the answer drew on**, with the consumer, the consumer's agent, the count and
  the domain asked about (never the subject) — through `ml_log_k7_read()` (`db/014_reading_search.sql`), the records role's only other write. The
  mailbox's owner and the Mail admins see it in the access log (slice 7).
- **Never**: a sensitive conversation, a mailbox not shared with applications, an inactive or suspended mailbox, more than the
  snippet.

## Files (exactly these)

| File | What |
|---|---|
| `html/m/people.php`, `html/m/person.php` | the two screens |
| `html/m/complete.php` | the autocomplete door |
| `app/features/mail/correspondents.php` | queries |
| `app/views/mail/people.php`, `person.php`, `complete.php` | templates |
| `mcp/share_correspondence.py` | the share's handler the records server mounts (Phase 4 wires it into the server) |
| `db/014_reading_search.sql` (BUILT; proof `db/proof/phase1_reading.sql`) | `ml_correspondence_index()` with `since`, `ml_log_k7_read()`, `ml_complete()`, the name index |
| `tests/phase3/slice8/run.sh`, `browser.mjs`, `k7.py` | the proof (`k7.py` stands in for the kernel's K7 call) |

## Query functions and mail-service calls (signatures fixed)

SQL (`db/014_reading_search.sql`, built and proven):

- `ml_correspondence_index(p_address text DEFAULT NULL, p_domain text DEFAULT NULL, p_limit integer DEFAULT 25, p_since timestamptz
  DEFAULT NULL)` — db/011's, with `since` (`t.last_message_at >= p_since`) and the suspended mailboxes left out; the three-argument
  form is dropped (its calls resolve to this one).
- `ml_log_k7_read(p_mailboxes bigint[], p_consumer text, p_consumer_agent bigint, p_counts integer[], p_domain text) RETURNS void` —
  SECURITY DEFINER, executable by `malumail_records_ro` only; one `activity_log` row per mailbox, action `k7.read`, source `application`.
- `ml_complete(p_mailbox bigint, p_q text, p_limit integer DEFAULT 8) RETURNS TABLE (address text, name text, source text, outside
  boolean)` — the three sources above under the caller's wall (`send` on the mailbox, else no rows).

PHP: `ml_correspondents_page(int $mailbox, array $filters, ?string $before): array`; `ml_correspondent(int $mailbox, string $address):
?array`; `ml_correspondence_threads(int $mailbox, string $address, bool $domain, ?string $before): array`;
`ml_public_provider(string $domain): bool` (the fixed list: gmail.com, googlemail.com, outlook.com, hotmail.com, live.com, msn.com,
yahoo.com, ymail.com, icloud.com, me.com, mac.com, aol.com, proton.me, protonmail.com, gmx.com, gmx.de, web.de, mail.com, zoho.com,
yandex.com, fastmail.com).

No mail-service call: this slice reads the index only.

## MCP tools this slice makes real

`correspondence`, `find_correspondents`, `correspondence_index`, `find_members`.

## Manifest rows claimed

Screens (2): `correspondents`, `correspondent-view`
Actions (0):

## Activity log events

| Event | When | `after` carries |
|---|---|---|
| `mailbox.view` | a non-owner opens either screen (once per half hour) | the screen |
| `k7.read` | each share answer, per mailbox drawn on | consumer, consumer agent, count, the domain asked about |
| `mcp.read` | `correspondence` or `find_correspondents` on a mailbox not the caller's own | the tool |

## Requirements claimed

MUSTs (2): `R11`, `R15`

## Out of scope for this slice

Names from the CRM's `contacts_index` (a K7 read — Extended, §4); merging correspondents; editing a correspondent's name; Mark known
itself (slice 5); contact photos.

## Proof (`tests/phase3/slice8/run.sh`: scratch `ml_dev8`; php -S on 9201, the fake kernel 9202, the fake MaluDB 9203; then the user-level Apache; an index seeded with 5,000 correspondents across three mailboxes — one a person's, one an agent's, one shared; Playwright)

1. The list: filters, cursor paging, the Known and Outside chips; a delegate's view logs `mailbox.view` once in half an hour; the
   correspondent page shows the conversations with the address and the domain tab for `supplier.example`, not for `gmail.com`.
2. Upkeep: a message indexed twice (Inbox, then Archive) counts once; a person's send makes the recipient known (`sent`); an agent's
   unreviewed send counts but makes nothing known; an approved one makes it known with the approver.
3. Autocomplete: correspondents sent-to first; directory members; the mailboxes the person opens and no other (an address of a mailbox
   they cannot open never appears); outside marked; a `view`-only holder gets 404; 50 ms or less at 5,000 correspondents.
4. The share through `k7.py`: an agent's and a shared mailbox answer, a person's mailbox only after its owner switches sharing on, a
   sensitive conversation never, a suspended mailbox never; `since` and `limit` hold; the 256 kB cap truncates and says so; a call
   without the kernel's token is refused; every answer writes one `k7.read` per mailbox with the consumer and its agent, and the
   owner reads those rows in the access log.
5. 375 and 1280; `--check` green.

## Decisions taken here

1. **Autocomplete suggests only addresses the person may know of**: their mailbox's correspondents, the directory, and the mailboxes
   they open — never another mailbox's address (§3).
2. **The company view skips public providers** (Divergences) — a fixed list, edited in code, not a setting.
3. **Received counts once per message key**, so a message moved between folders or re-synced is not counted again.
4. **The share logs one row per mailbox it drew on**, so each owner sees exactly the reads of their own mailbox.
5. **The share's `url` is the conversation's own page** — the consumer's person reaches it only through MaluMail's wall.

## Open questions

- None.

## Changes this spec asks of the shared documents

- **`db/014_reading_search.sql`** — BUILT and proven (`db/proof/phase1_reading.sql`): `ml_correspondence_index()` with `p_since` and
  suspended mailboxes left out (the old three-argument form dropped), `ml_log_k7_read()` (the records role's alone), `ml_complete()`,
  the trigram index on correspondents' names.
- **maludb-os.json `shares[]`**: `correspondence_index`'s description gains "since" and "at most 256 kB, truncated beyond".
- **The tool surface**: `correspondence_index`'s arguments already name `since`; its answer gains `truncated`.
