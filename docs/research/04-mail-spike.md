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

## B. The IMAP/SMTP half — awaits MM2 and the two test mailboxes

Before it runs: **MM2** agreed with MaluMail's infrastructure side, and **two test mailboxes** on `mboxtest.example` (the second for
the forged-sender case). The client stops at its first authentication failure. To measure (§10.1):

- login, the post-authentication `CAPABILITY`, `ENABLE QRESYNC`; an initial sync of 5,000 synthetic messages (APPENDed by the spike):
  the envelope pass, the text pass, the index's size — against `db/008`'s schema (`app.ml_bulk` then `ml_threads_rebuild`);
- new mail → the index through `NOTIFY` and through `IDLE`, by a message the spike submits to itself;
- a flag changed by a second connection → the index through `QRESYNC`;
- `MOVE`; `APPEND` of a draft and its replacement; `BINARY` partial fetch of a 5 MB attachment; `GETQUOTAROOT`;
- submission on 587 with an HTML message and an attachment, the `\Sent` `APPEND`, `\Answered`; a burst, to see the deferral's shape;
- IMAPClient (BSD; `IDLE`, `CHANGEDSINCE`) in a thread per mailbox against the standard library's `imaplib` wrapped; whether
  `NOTIFY` needs raw commands either way;
- what `X-Spamd-Result` and `Received` carry for mail between two mailboxes of the business, for a forged From from outside, and for
  a forged From submitted by the second test login — confirming or tightening §5's wake rule (`ml_dispatch_decide`, `db/010`).

The spike's messages are expunged from the test mailboxes afterwards.
