# 04 — The mail spike (design §10.1)

The spike proves the estate's first IMAP/SMTP client and the first sanitiser of untrusted HTML before the mail service and the reading
path are specified. It runs from this server, in a scratch directory outside the repository, on MaluMail's scratch ports 9201–9207;
its code is deleted afterwards and its results land here and in the `mail-service` and `reading-and-rendering` specs.

## A. The sanitiser, on this server (2026-10-11) — partly run

**Confirmed:**

- **`nh3` 0.3.7** (Rust's ammonia; released 2026-08-23) installs from a wheel into a scratch virtual environment and runs on this
  VM's CPU, which reports no AVX (the Data Lake's finding: default Polars 2.0 crashes here; DuckDB and PyArrow do not). A test string
  was cleaned correctly.
- `nh3` 0.3.7 exposes the two hooks the design's first layer needs: **`attribute_filter`** (a Python callable deciding each attribute
  — where links are rewritten, `cid:` sources pointed at the part door and remote images replaced by a placeholder) and
  **`filter_style_properties`** (a CSS property allow-list applied inside `style` attributes). So the plan of §6.4 — nh3 with our own
  CSS pass — is buildable as written; the fallback (an allow-list over the standard library's `html.parser`) stays a fallback.
- **Playwright with headless Chromium** is available from the kernel's `web/node_modules` (Boards' canvas proofs use it), so the
  second and third layers — the sandboxed, opaque-origin frame and the image door — can be proven in a real browser: a rendered body
  that makes any network request, opens a dialog, writes to the console or navigates fails.

**Not run:** the hostile-mail corpus and its browser proof, and the timings at 20 kB / 200 kB / 2 MB. Writing the corpus's attack
examples was stopped by a safety check and was not worked around. **The owner decides how the attack testing is sourced.** The
recommendation: slice 3's proof (reading — the second exemplar) uses **a published sanitiser test suite the owner approves**, taken
as data under its own licence, alongside a benign corpus of ordinary business mail (tables, inline styles, a newsletter, an
Outlook-style reply with quoted history, a long message generated at test time). The `reading-and-rendering` spec names the attack
families the suite must cover — script and event attributes, dangerous URL schemes, CSS that loads or executes, embedded documents
and SVG, form and base elements, parser-differential (mXSS) cases, hidden text and tracking pixels — without carrying payloads of
its own.

## B. The IMAP/SMTP half, run 2026-10-11 against two test mailboxes

Two test mailboxes on `mboxtest.example` (created by the owner for the spike; MM2 in place). Client: Python 3.12.3, **IMAPClient
3.0.1** with the standard library's `imaplib` underneath, `smtplib` for submission; TLS certificates verified on 993 and 587.
Four submissions in all (the spike's limit was fifteen). Every message the spike made was expunged afterwards and its one folder
deleted: both mailboxes are back to five empty folders and zero quota used.

### What the server is

| | |
|---|---|
| Capabilities before login | `IMAP4rev1 SASL-IR LOGIN-REFERRALS ID ENABLE IDLE LITERAL+ AUTH=PLAIN AUTH=LOGIN` |
| After login (adds) | `BINARY CATENATE CHILDREN CONDSTORE CONTEXT=SEARCH ESEARCH ESORT I18NLEVEL=1 LIST-EXTENDED LIST-STATUS MOVE MULTIAPPEND NAMESPACE NOTIFY PREVIEW PREVIEW=FUZZY QRESYNC QUOTA SAVEDATE SEARCHRES SNIPPET=FUZZY SORT SORT=DISPLAY SPECIAL-USE STATUS=SIZE THREAD=ORDEREDSUBJECT THREAD=REFERENCES THREAD=REFS UIDPLUS UNSELECT URL-PARTIAL WITHIN` |
| `ENABLE QRESYNC` | `ENABLED QRESYNC` |
| Namespace | personal `""`, delimiter `.`; no shared or other-users namespaces |
| Folders of a new mailbox | `INBOX`, `Sent` (`\Sent`), `Drafts` (`\Drafts`), `Junk` (`\Junk`), `Trash` (`\Trash`). **No `\Archive` and no Snoozed folder**; a plain `CREATE Archive` works (no CREATE-SPECIAL-USE) |
| Quota | one root, `User quota`, `STORAGE` 2,097,152 KiB (2 GiB) |
| Login (TLS + LOGIN) | 0.42–0.52 s |
| Submission on 587 (after STARTTLS) | `SIZE 10240000`, `PIPELINING`, `CHUNKING`, `8BITMIME`, `SMTPUTF8`, `DSN`, `ENHANCEDSTATUSCODES`, `AUTH PLAIN LOGIN` |

### Loading and the initial sync — 5,000 synthetic messages (9.1 MB) in spike01's INBOX

| Step | Time | Rate |
|---|---|---|
| `APPEND`, one message a command (100 messages) | 21.6 s | 215 ms a message — a round trip each |
| `MULTIAPPEND`, 100 a command (4,900 messages) | 31.8 s | 6.5 ms a message |
| **Envelope pass**: `UID FETCH` in batches of 500 — `UID FLAGS INTERNALDATE RFC822.SIZE ENVELOPE BODYSTRUCTURE MODSEQ PREVIEW BODY.PEEK[HEADER.FIELDS (Date From To Cc Subject Message-ID References In-Reply-To List-Id List-Unsubscribe Auto-Submitted Precedence X-Spamd-Result X-Spam-Status Return-Path)]` | 5.97 s | 837 messages/s (0.44–0.75 s a batch) |
| **Text pass**: `BINARY.PEEK[n]<0.102400>` of the text part (grouped by part number — `1` or `1.1`), 200 a command | 3.82 s | 1,309 messages/s |
| Envelopes into the index (`db/008`, `app.ml_bulk = 'on'`, `ml_message_key_for` + `ml_envelope_digest`, the search vector) | 4.0 s | 1,250 rows/s |
| The text pass into the index | 1.3 s | |
| `ml_threads_rebuild` (5,000 messages → 3,167 conversations) | **16.7 s** | 3.3 ms a message |
| `ml_search` (`from:park invoice`, a phrase, `has:attachment after:`, a negation) | 11–32 ms warm (70 ms cold) | |
| `mcp_threads`, all 3,167 conversations of the mailbox | 217 ms | |

`PREVIEW` came back for every message. The index for 5,000 messages: `mail_messages` 37 MB (heap 25 MB, indexes 12 MB of which
the text GIN 5.9 MB), `mail_threads` 4.2 MB, `mail_message_keys` 1.6 MB. Messages APPENDed (never passed through the mail
filter) carry no `X-Spamd-Result`.

### New mail, changes by another client, moves, drafts, a large attachment

| Measure | Result |
|---|---|
| New mail → `IDLE` on the selected INBOX | 0.53 s and 0.52 s after submission's `250` |
| New mail → `NOTIFY SET (selected (MessageNew (UID FLAGS) MessageExpunge FlagChange)) (personal (MessageNew MessageExpunge))` with INBOX selected | 0.51 s: `* n EXISTS` then `* n FETCH (UID … FLAGS (\Recent))` |
| New mail → `NOTIFY SET (personal …)` with INBOX **not** selected (`* STATUS INBOX (MESSAGES … UIDNEXT … UNSEEN …)`) | **28 s the first time** on a new mailbox; 0.52 s and 0.53 s afterwards |
| Another connection flags two messages and expunges one → the first connection, still selected, on `NOOP` | `* FETCH (UID … MODSEQ (…) FLAGS (…))` for each, `* VANISHED 12` |
| `UID FETCH 1:* (UID FLAGS) (CHANGEDSINCE m VANISHED)` | 65 ms: the two `FETCH`es and `* VANISHED (EARLIER) 12` |
| A reconnect: `SELECT INBOX (QRESYNC (uidvalidity m))` | 79 ms: `VANISHED (EARLIER) 12`, the two `FETCH`es with `MODSEQ`, `HIGHESTMODSEQ` |
| `UID MOVE` to a folder | `* OK [COPYUID uidvalidity src dst]` and `* VANISHED src` — the new UID is known at once |
| A draft: `APPEND Drafts (\Draft \Seen)`, then its replacement and `UID STORE \Deleted` + `UID EXPUNGE` of the old | `[APPENDUID uidvalidity uid]` each (0.22–0.32 s); the replacement 0.14 s; Drafts holds one message |
| A 5 MB attachment (7.1 MB as sent): `APPEND` | 1.49 s |
| `BINARY.SIZE[2]` | 5,242,880 — the **decoded** size (`BODYSTRUCTURE` gives the encoded 7,174,470) |
| `BINARY.PEEK[2]<0.65536>` / the whole part | 0.27 s / 0.58 s; the digest equals the original's |
| Three connections at once to one mailbox (the planned pool) | fine (1.5 s to open all three) |

### Submission

- `MAIL FROM`, `RCPT TO`, `DATA` answer `250 2.1.0 Ok`, `250 2.1.5 Ok`, **`250 2.0.0 Ok: queued as <queue id>`** — a queue id, not
  the `Message-ID`. **The `Message-ID` the client sets is delivered unchanged**, so the outbox's minted id (db/009) is the key that
  threads and that `ml_dispatch_decide` matches.
- **Submission files no copy in Sent**: the mail service `APPEND`s every sent message to `\Sent` (`\Seen`) itself, as planned.
- `\Answered` on the answered original: a plain `UID STORE +FLAGS`.
- The burst that would show the deferral's (`451 4.7.1`) shape was **not** measured: the spike stayed far under the sending limit
  on purpose. The deferral path (`ml_outbound_deferred`) is proven with a fixture until a real deferral is seen.

### How a message proves itself (§5's wake rule)

A message from one of the business's mailboxes to another, submitted on 587 and delivered on mbox01, arrives with `Return-Path`
(the envelope sender), `Delivered-To`, two `Received` lines (the submission, `with ESMTPSA`, and the local delivery, `with LMTP`)
and a `DKIM-Signature` — and **no `X-Spamd-Result`, no `X-Spam-Status`, no `Authentication-Results`**. So **`DMARC_POLICY_ALLOW`
never appears on internal mail**: the filter's verdict is added, if at all, only to mail arriving from outside (not tested here). The `ESMTPSA` line shows that the
submission was authenticated, but not by which login.

A message one test login submitted with the other mailbox's address in `From`: the results went to the owner privately, for MM3.
The other case — a forged `From` arriving from **outside** — could not be made from here (no outside mailbox) and stays for a test
from an outside address.

### IMAPClient and `imaplib`

IMAPClient handled login, `ENABLE`, `SELECT`, `IDLE`, `MULTIAPPEND`, flags and every `FETCH` item (`ENVELOPE`, `BODYSTRUCTURE`,
`PREVIEW`, `BINARY.PEEK` partials, `MODSEQ`; `fetch(…, modifiers=['CHANGEDSINCE m'])`). It needed the raw `imaplib` connection
underneath for: **`NOTIFY`** (no command), **`SELECT … (QRESYNC …)`** (no parameter), **`VANISHED`** (its fetch drops it), and
**`MOVE`'s `COPYUID`** (`move()` returns nothing). Its `normalise_times` default turns `INTERNALDATE` into a naive local time — the
service turns it off. A thread per mailbox holding one `IDLE`/`NOTIFY` connection, with a small pool for fetches, is workable.

### What this changes

1. **The dispatch rule (`db/010` `ml_dispatch_decide`) must not ask internal mail for DMARC.** As written it requires
   `dmarc_pass` before anything counts as internal, and internal mail never carries the verdict — so even mail this application
   sent from a colleague's mailbox would wait Unscreened. The evidence for internal mail is **the application's own record** (a
   `sent` outbound row with that `Message-ID`, from a served mailbox); DMARC remains the test for mail from outside. Until MM3
   (which should also add a trace naming the authenticated login, so a login proves itself), other internal-looking mail proves
   nothing — as §5 says. The Phase 0 proof's internal-mail fixture assumed a DMARC pass and changes with it.
2. **The sync connection**: `SELECT INBOX` with `NOTIFY SET (selected …) (personal …)` per mailbox — new INBOX mail in about half a
   second, other folders through `STATUS` events — and the once-a-minute `STATUS` round kept as the safety net (a first `NOTIFY`
   on a new mailbox took 28 s).
3. **Threading during an initial sync** is the slow step (3.3 ms a message; a 100,000-message mailbox ≈ 5.5 minutes):
   `ml_threads_rebuild` refreshes a conversation's figures after every message — refresh each conversation once at the end, or
   thread as the envelope pass goes.
4. The service creates **`Archive`** and **`Snoozed`** by name (no special-use flags exist for them on this server).
5. **Attachment sizes**: `BINARY.SIZE` gives decoded sizes. The index keeps names only (`attachment_names`); the reading page asks
   for sizes when it opens a message.
6. **List pages** read `mcp_threads` with a limit (the lateral conversation state is per row: 217 ms for all 3,167 at once).
7. The **attachment cap** (7 MiB decoded, ~9.8 MB encoded) sits just under submission's `SIZE 10240000`; the composer counts the
   encoded size against the server's.

**Done the same day** (the schema, `db/008` and `db/010`, proof 232 checks): (1) `ml_dispatch_decide` proves a colleague by this
application's own `sent` record, or — with MM3 enforced — by the new `mail_messages.local_submission` (the mail service sets it for a
message that arrived through authenticated submission on MaluMail's own server); DMARC is asked of outside mail only; outside mail
claiming a mailbox of the business stays Unscreened (`internal_unproven`). (3) `ml_threads_rebuild` keeps only what the next match
reads while it runs and computes every conversation's figures once at the end: 5,000 messages in 3.5 s (0.7 ms a message) on this
server, from 16.7 s.
