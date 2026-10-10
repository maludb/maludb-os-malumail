> Web research for planning `maludb-os-malumail`, 2026-10-10 (a research agent's report, kept as written). Every page cited was opened; what could not be verified is marked and listed in §6. Some items in §6 were checked later against mbox01 itself — see `01-malumail-platform-survey.md` (e.g. the post-auth capabilities, `message_size_limit`, the sender-login maps).

# maludb-os-malumail: research report (2026-10-10)

Web research only. Nothing was written to disk. I cite each page I opened inline as [title](url). Anything I could not verify is marked **(unverified)** and collected in §6. The R-ids run through the whole report (R1…R78) so they stay unique.

## 0. The ten findings that shape the design

1. **Index the mail, don't mirror it.** Every mature PHP webmail that started out talking to IMAP on each request later added a database cache once mailboxes grew. Nextcloud Mail's cache cut server CPU spikes from 40–50% to 4% on a 9,000-message account. Recommendation: PostgreSQL holds envelopes, flags, labels, thread ids and a bounded text copy. Dovecot stays the store for bodies and attachments.
2. **Gmail-style conversations must be computed in PostgreSQL, across folders.** IMAP `THREAD` works per folder, so it can never pull your own replies from Sent into the conversation. Nextcloud Mail has the same problem and threads in its own code over its database.
3. **Labels are the hard mismatch.** Gmail labels are folders over one message store. In Dovecot a message lives in exactly one folder. Maildir stores only **26 keywords per folder** in filenames; extra keywords live only in Dovecot's index files. Recommendation: system places are real folders; user labels are kept in PostgreSQL and mirrored to IMAP keywords up to a cap.
4. **There is no full-text index on mbox01.** Dovecot then searches bodies "sequentially through all message data". The Gmail search operators therefore have to compile to SQL over our index.
5. **Webmail HTML is the attack surface.** A third-party tracker lists 11 Roundcube CVEs in CISA's Known Exploited Vulnerabilities catalogue. Defence needs layers: a server-side whitelist sanitizer, a sandboxed iframe with a strict CSP, remote content blocked by default, and an image proxy.
6. **Sync should be push-driven.** Dovecot 2.3 can POST on delivery through its `push_notification` plugin (OX or Lua driver). Use that, with IDLE/NOTIFY plus CONDSTORE/QRESYNC as the fallback. A Dovecot master user lets one worker reach every mailbox without holding users' passwords.
7. **Agent mail meets all three conditions of the "lethal trifecta".** An agent mailbox has untrusted inbound content, private business data through MCP, and an outbound channel. In v1, any external send by an agent goes to review; internal mail may auto-send. Even a reply to the sender of the thread is an exfiltration path.
8. **The IP is cold, so caps must sit well below Rspamd's 500 messages/hour/mailbox.** Ramp a business-wide external budget over about four weeks. Do no agent cold outreach in v1.
9. **Law and oversight.** Give advance written notice of monitoring. Log who opened whose mailbox, kept visible to admins. Distinguish metadata from content. Do not let AI read human mailboxes by default. Email content that reaches an agent lands in the kernel's prompt ledger, and at the model provider (30-day API retention by default), so retention rules must cover those copies too.
10. **Sieve with no ManageSieve.** Ask MaluMail to expose ManageSieve, or an API wrapping doveadm's `sievePut`/`sieveActivate`. The webmail should generate one script per mailbox from rules it keeps in PostgreSQL.

---

## 1. Gmail, screen by screen

### 1.1 What Gmail does (from the help pages)

| Area | Gmail behaviour | Source |
|---|---|---|
| **Conversation view** | Replies are grouped into one thread with the newest at the bottom. A conversation splits when the subject changes or when it exceeds 100 emails. Automated mail is grouped when it shares recipients, sender or subject, has a matching reference header, and arrives within a week. The view can be turned off. List density is Default, Comfortable or Compact. | [Gmail conversation view](https://support.google.com/mail/answer/5900) |
| **Categories** | Primary, Social, Promotions, Updates and Forums. You cannot create your own. Dragging a message to a tab moves it, with an undo. A starred message also shows in Primary. | [Inbox categories](https://support.google.com/mail/answer/3094499) |
| **Importance** | Learned from whom you email, what you open, reply to, star or archive, and keywords. Clicking the marker trains it. Search with `is:important`. Markers can be hidden, and learning can be turned off. | [Importance markers](https://support.google.com/mail/answer/186543) |
| **Labels** | Up to 5,000. Can be nested. Up to 100 custom colours. Private to you. Deleting a message removes it from all labels. "Move" means removing the current label and adding a new one. | [Labels](https://support.google.com/mail/answer/118708) |
| **Labels over IMAP** | Labels appear as folders, so CREATE, RENAME and DELETE work on them. System labels sit under `[Gmail]/`. SPECIAL-USE maps `\All` to All Mail, `\Flagged` to Starred, `\Junk` to Spam, plus `\Trash`, `\Drafts`, `\Sent` and `\Important`. Gmail extensions: `X-GM-LABELS` (read, store, search), `X-GM-THRID` (thread id), `X-GM-MSGID` (one id across folders), `X-GM-RAW` (Gmail search syntax over IMAP). | [Gmail IMAP extensions](https://developers.google.com/gmail/imap/imap-extensions) |
| **IMAP client advice** | A message deleted from an IMAP folder is still in "All Mail". Sent mail is copied automatically when Gmail's SMTP is used. Drafts should be saved on the server. Clients should not run their own junk filters. | [Gmail IMAP client settings](https://support.google.com/mail/answer/78892) |
| **Search operators** | `from: to: cc: bcc: subject: after: before: older: newer: older_than: newer_than: OR {} AND - AROUND label: category: has:attachment has:drive/document/spreadsheet/presentation/youtube has:userlabels has:nouserlabels list: filename: "…" ( ) in:anywhere in:archive in:snoozed is:muted is:important is:starred is:unread is:read has:<star types> deliveredto: size: larger: smaller: + rfc822msgid: label:encryptedmail header:` | [Search operators](https://support.google.com/mail/answer/7190) |
| **Images** | Either "Always display external images" or "Ask before displaying". Images from suspicious messages always ask first. Google scans images, which stops senders learning your location and setting or reading cookies. | [Images](https://support.google.com/mail/answer/145919) |
| **Undo send** | Cancellation window of 5, 10, 20 or 30 seconds. A "Message sent / Undo" toast appears. | [Undo send](https://support.google.com/mail/answer/2819488) |
| **Schedule send** | Up to 100 scheduled emails. Cancelling turns the email back into a draft. Kept in a "Scheduled" view. The page I opened covers the iPhone/iPad app. | [Schedule send](https://support.google.com/mail/answer/9214606) |
| **Snooze** | Removes the message from the inbox; it returns to the top at the chosen time. Shown in a Snoozed view; searchable with `in:snoozed`. | [Snooze](https://support.google.com/mail/answer/7622010) |
| **Send as / alias** | The address must be verified. For work accounts, an external SMTP server can be given. Replies go to the original address unless a reply-to is set. Recipients on other clients may see "on behalf of". | [Send from another address](https://support.google.com/mail/answer/22370) |
| **Spam** | Report spam and Not spam. Blocking a sender is persistent. Mail from a sender after you unsubscribe goes straight to Spam. Reports train the filter. | [Spam](https://support.google.com/mail/answer/1366858) |
| **Shortcuts** | `c r a f e # Shift+u s / j k o g-i x m b l v Ctrl+Enter ? z`. Most need to be switched on in settings first. | [Keyboard shortcuts](https://support.google.com/mail/answer/6594) |
| **Delegation (user side)** | A delegate can "read, send, and delete emails" for you. A delegate cannot chat, change your password, or reach account settings, Gemini or Smart Compose. Limits: 10 delegates for a personal account, 1,000 for Workspace, about 40 at the same time. The invitation must be accepted within a week; access can take 24 hours. The delegated inbox is marked "Delegated". You cannot delegate to an alias. The delegate's address appears on mail they send. | [Delegate your mail](https://support.google.com/mail/answer/138350) |
| **Delegation (admin side)** | The admin enables it per organisational unit and chooses the sender display: "Show the account owner and the delegate who sent the email" or "Show the account owner only". Delegating to a group counts as one delegate. Delegates must be in the same organisation. | [Workspace: let users delegate](https://knowledge.workspace.google.com/admin/gmail/let-users-delegate-access-to-a-gmail-account) |
| **Sending limits (a benchmark)** | Per user: 2,000 messages a day, 10,000 recipients a day, 3,000 unique recipients (2,000 external). Trial accounts: 500. Exceeding a limit blocks sending for up to 24 hours. | [Workspace sending limits](https://knowledge.workspace.google.com/admin/gmail/gmail-sending-limits-in-google-workspace) |

Taken from general product knowledge and **unverified** against a help page: desktop Gmail paginates ("1–50 of N", page size chosen in settings) rather than scrolling endlessly; quoted history folds behind "…" ("Show trimmed content"); an "always display images from this sender" link exists.

### 1.2 What this means on Dovecot

- **Labels vs folders.** In Dovecot a message is in exactly one folder; a copy in another folder is a separate message with its own UID. There are three ways to provide labels:
  - (a) Folders only, Outlook style.
  - (b) IMAP keywords. Other clients see them, and Sieve's `addflag` can set them at delivery. But Maildir keeps only 26 keywords per folder in filenames, and the rest live only in index files ([Dovecot Maildir](https://doc.dovecot.org/2.3/admin_manual/mailbox_formats/maildir/)). System keywords such as `$Junk`, `$Forwarded` and `$MDNSent` use up the same 26 slots.
  - (c) PostgreSQL only. Durable, but invisible to other clients.

  **Recommendation:** system places are folders; user labels are kept in PostgreSQL; a configurable cap (about 15) is mirrored as `$label-<slug>` keywords so that Sieve filters can apply labels. The keyword name limit is 50 characters ([Dovecot core settings](https://doc.dovecot.org/2.3/settings/core/)).
- **Archive.** Gmail archives by removing the `\Inbox` label. In Dovecot, archive is a MOVE to the folder flagged `\Archive` with SPECIAL-USE. The All mail view is a query over the index, not a folder.
- **Conversations.** `THREAD` works per folder ([RFC 5256](https://www.rfc-editor.org/rfc/rfc5256.html)), so Gmail's cross-folder conversation, including your own Sent replies, has to be built in our index from `Message-ID`, `References` and `In-Reply-To`, keeping Gmail's limits: split on subject change and at 100 messages.
- **Snooze.** Move the message to a `Snoozed` folder, which other IMAP clients also see. A worker moves it back to INBOX at the wake time and marks it unread.
- **Undo send and schedule send** come almost free from an application-side outbox with a `send_after` time (§2.4).
- **Delegation.** Dovecot ACL and shared namespaces exist ([Dovecot shared mailboxes](https://doc.dovecot.org/2.3/configuration_manual/shared_mailboxes/shared_mailboxes/)). For SSO users it is simpler to enforce delegation in the application and reach mailboxes through a master user (§5). For "on behalf of", put the author in `From:` and the transmitter in `Sender:` ([RFC 5322 §3.6.2](https://www.rfc-editor.org/rfc/rfc5322.html#section-3.6.2): the secretary example). Exchange's three-way model is a useful vocabulary ([Exchange mailbox permissions](https://learn.microsoft.com/en-us/exchange/recipients/mailbox-permissions)):
  - Full Access: read, but no send.
  - Send As: no indication of who sent it.
  - Send on Behalf: "Delegate on behalf of Mailbox".

### 1.3 Requirements, ranked for a business webmail v1

| R | Rank | Requirement |
|---|---|---|
| R1 | MUST | **Mailbox switcher.** Every mailbox the person may open: their own, agent mailboxes they supervise, shared/alias identities. Unread counts. No second login (kernel SSO). |
| R2 | MUST | **Conversation list.** One row per cross-folder conversation: participants with a count, subject + snippet (Dovecot PREVIEW), date, unread in bold, star, paper-clip, label chips, and agent badges ("drafted by …", "awaiting review"). A per-user toggle to message view. |
| R3 | MUST | **Paging.** "1–50 of N" with newer/older, keyset pagination; page size 25/50/100. No infinite scroll. |
| R4 | MUST | **Navigation.** Inbox, Starred, Sent, Drafts, Outbox/Awaiting review, All mail, Spam, Trash, and labels/folders. |
| R5 | MUST | **Bulk actions.** Select on the page or all matching a search; archive, delete, read/unread, star, move, label, spam/not spam; an Undo toast. |
| R6 | MUST | **Reading view.** Newest and unread messages expanded, earlier read ones collapsed to a line; quoted text folded; an attachment strip (type, size, download); inline `cid:` images; remote content blocked with "Show images" and "Always from this sender"; a DMARC/"via" indicator and an external-sender banner. |
| R7 | MUST | **Compose, reply, reply-all, forward.** Replies inline in the thread as an HTMX partial; new mail full-page, which keeps the design system's no-modal rule. |
| R8 | MUST | **Format.** Plain text plus a minimal rich-text editor (bold, italic, link, list, quote). Always send `multipart/alternative` with a text part. Our own composed HTML is sanitized too. |
| R9 | MUST | **Autosave** to the IMAP Drafts folder (APPEND the new version, delete the old by UID) every 15–30 s and on leaving the field. Roundcube's default is 300 s ([Roundcube defaults](https://raw.githubusercontent.com/roundcube/roundcubemail/master/config/defaults.inc.php)). |
| R10 | MUST | **Attachments.** Upload several files within Postfix's `message_size_limit` on mbox01 (value unverified); forward with attachments. |
| R11 | MUST | **Recipient autocomplete.** The kernel directory (people, agents, aliases) plus recent correspondents; external addresses shown in a different colour. |
| R12 | MUST | **Identities.** A From selector over the mailbox's address and the aliases a super-admin assigned; a signature per identity; replies default to the address the mail was sent to. |
| R13 | MUST | **Search.** Gmail operator subset `from: to: cc: subject: has:attachment filename: before: after: older_than: newer_than: in: is:unread/read/starred label: "…" - OR ( )`, plus an advanced form. Results shown as conversations. |
| R14 | MUST | **Delegation roles per mailbox.** `owner`; `delegate` (read and send on behalf, sets `Sender:`); `reviewer` (read and approve agent drafts); `auditor` (read only). Granted by super-admins. Every access by a non-owner is logged (§4). |
| R15 | MUST | **Mobile at 375 px.** List → conversation → back; a bottom action bar. |
| R16 | MUST | **New-mail refresh.** Poll a cheap changes endpoint every 30–60 s (Roundcube's default `refresh_interval` is 60) or use push. |
| R17 | SHOULD | Undo send: 5/10/20/30 s, default 10. |
| R18 | SHOULD | Schedule send: Scheduled view, at most 100 pending, cancel turns it back into a draft. |
| R19 | SHOULD | User labels: nested, coloured; the §1.2 model. |
| R20 | SHOULD | Snooze, using the Snoozed folder and a wake job. |
| R21 | SHOULD | Keyboard shortcuts, off by default, Gmail's key map. |
| R22 | SHOULD | Filters ("create filter from this search") compiled to Sieve (§5.2). |
| R23 | SHOULD | Vacation responder through Sieve `vacation`. |
| R24 | SHOULD | Density (default/compact); print; download `.eml`; show original. |
| R25 | SHOULD | Mute a conversation (application-side flag; new mail skips the inbox). |
| R26 | LATER | Categories/tabs, importance markers, priority inbox. An agent classifier could fill these later. |
| R27 | LATER | Unified inbox across mailboxes; multiple-inbox layouts. |
| R28 | LATER | One-click unsubscribe/block, calendar invite (iTIP) cards, confidential mode, offline. |

---

## 2. Open-source webmails: how they are built

### 2.1 Comparison

| | IMAP access and cache | Threading | Search | HTML safety | Notes |
|---|---|---|---|---|---|
| **Roundcube** (PHP) | Stateless by default. Optional `imap_cache` (db/apcu/redis/memcache) and `messages_cache` (db only), which "requires an IMAP server that supports QRESYNC and CONDSTORE". Default TTL 10 days, capped at 30. Tables `cache_index` (sorted UIDs plus UIDVALIDITY/UIDNEXT/HIGHESTMODSEQ), `cache_thread`, `cache_messages` (serialized headers, body dropped above `messages_cache_threshold` = 50 KB). Validation checks UIDVALIDITY, then a modseq shortcut, then UIDNEXT, then counts. Sync runs `UID FETCH 1:* (FLAGS) (CHANGEDSINCE m VANISHED)`. A UIDVALIDITY change wipes the folder. ([defaults](https://raw.githubusercontent.com/roundcube/roundcubemail/master/config/defaults.inc.php), [rcube_imap_cache](https://raw.githubusercontent.com/roundcube/roundcubemail/master/program/lib/Roundcube/rcube_imap_cache.php)) | Server `THREAD` (REFS/REFERENCES/ORDEREDSUBJECT). REFS threads are ordered by their latest message. `default_list_mode` is list or threads. No client-side fallback found ([rcube_imap](https://raw.githubusercontent.com/roundcube/roundcubemail/master/program/lib/Roundcube/rcube_imap.php)). | Server SEARCH with CHARSET, retried as US-ASCII on error; multi-folder search through `rcube_imap_search`; scope base/sub/all. | `rcube_washtml` rebuilds the DOM from a whitelist (PHP 8.4 `Dom\HTMLDocument`, then Masterminds HTML5, then DOMDocument). Remote URIs only when `allow_remote`; a `blocked_src` placeholder; CSS `url()` washed; `javascript:`, `vbscript:` and `data:` hrefs rejected; SVG re-sanitized ([rcube_washtml](https://raw.githubusercontent.com/roundcube/roundcubemail/master/program/lib/Roundcube/rcube_washtml.php)). `show_images` 0 never / 1 contacts / 2 always / 3 trusted senders. `x_frame_options` sameorigin; no CSP option in the defaults file. | Undo send (`undo_timeout`) exists, default off. 11 Roundcube CVEs on CISA KEV per [Senserva](https://senserva.com/exploited/roundcube.html) (third-party, not checked against CISA). |
| **SnappyMail** (PHP back end, JS front end; AGPL-3) | "No database required". Uses CONDSTORE/QRESYNC (HIGHESTMODSEQ) for cache handling, plus LIST-STATUS, PREVIEW, METADATA, MOVE and SPECIAL-USE ([IMAP capabilities wiki](https://github.com/the-djmaze/snappymail/wiki/IMAP-capabilities), [repo](https://github.com/the-djmaze/snappymail)). Admin can cap how many UIDs are fetched for sorting (e.g. 1,000) ([performance wiki](https://github.com/the-djmaze/snappymail/wiki/Improve-performance)). | Server THREAD; admins disable SORT/THREAD on slow servers. | Server SEARCH (ESEARCH). | Sanitizes in the browser (`cleanHtml`). CVE-2024-45800 was an mXSS in it before 2.38.0; "due to the default Content Security Policy the impact … is minimal"; the image proxy could leak data ([GCVE](https://db.gcve.eu/vuln/CVE-2024-45800)). | Squire editor; Sieve script editor; PHP 7.4+. |
| **Cypht** (PHP, LGPL-2.1) | Module sets; combined views across accounts; IMAP/SMTP/JMAP/EWS ([repo](https://github.com/cypht-org/cypht), [cypht.org](https://cypht.org/)). No database required (sessions PHP/DB/MEM/REDIS; settings in file or DB). Redis/Memcache cache, encrypted; using the session as a cache is "NOT RECOMMENDED" ([config/app.php](https://raw.githubusercontent.com/cypht-org/cypht/master/config/app.php)). | n/a | Per-source SEARCH with a time limit; 1–1,000 items per source, default 20 items over one week. | `allow_external_image_sources` off by default ("could leak information"). | A shallow, fast "combined" model: fine for aggregation, weak for deep mailboxes. |
| **Nextcloud Mail** (PHP + Vue) | A database message cache since 1.3, with background jobs fetching new mail; CPU spikes fell from 40–50% to 4% at about 9k messages ([blog](https://nextcloud.com/blog/mail-1-3-introduces-mail-cache-improved-signatures-link-shares-mark-all-read-and-more/)). The initial sync is split across many requests because of web timeouts; tested at about 100k messages ([forum](https://help.nextcloud.com/t/expected-performance-for-mail-app/116648)). Uses Horde (`bytestream/horde-imap-client`) plus horde-managesieve and a JMAP client ([composer.json](https://raw.githubusercontent.com/nextcloud/mail/main/composer.json)). | **Its own threading in PHP over the database** ([Threading dir](https://github.com/nextcloud/mail/tree/main/lib/IMAP/Threading)): JWZ/RFC 5256-style steps, References only (In-Reply-To unused), subject grouping only between root containers, cross-subject merging "deliberately omit[ted]" ([ThreadBuilder.php](https://raw.githubusercontent.com/nextcloud/mail/main/lib/IMAP/Threading/ThreadBuilder.php)). | Over its database (details not verified). | HTMLPurifier with `TargetBlank`, a no-referrer transform, image `src` transforms, HMAC-signed URLs (inferred from the code: points to a signed proxy); `<style>` blocks extracted and every CSS URL replaced by a blocked image ([Html.php](https://raw.githubusercontent.com/nextcloud/mail/main/lib/Service/Html.php)). CVE-2021-32707: `background-image` slipped past the privacy filter, but went through the proxy, so no IP leak ([CVE](https://cveawg.mitre.org/api/cve/CVE-2021-32707)). | The closest analogue to what we need. |
| **JMAP: Bulwark / Stalwart** | "JMAP moves threading, search and change tracking to the server and returns only what changed"; Stalwart pushes changes; AGPL-3; needs Stalwart ≥0.16.6 ([bulwarkmail.org](https://www.bulwarkmail.org/)). Stalwart's own webmail is planned after 1.0, "most likely sometime in 2026", as a Dioxus SPA ([Stalwart roadmap](https://stalw.art/blog/roadmap/)). | Server | Server | n/a | Not applicable to Dovecot 2.3. But JMAP's shape (threads, change states, query) is a good model for our index API. |

An agent-mail vendor renders HTML with DOMPurify inside `<iframe sandbox srcdoc>` and an injected CSP: `default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; frame-ancestors 'none'`. Note that `img-src https:` still lets tracking pixels load ([AgentMail engineering](https://engineering.agentmail.to/blog/rendering-email-safely-preventing-phishing-attacks)).

Per MDN, an empty `sandbox` applies every restriction; `allow-scripts` together with `allow-same-origin` is "strongly discouraged"; `srcdoc` documents get `about:srcdoc` as their location ([MDN iframe](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/iframe)).

### 2.2 Lessons

- A stateless model is acceptable for small mailboxes. Every product that serves large ones caches envelopes, and only incremental sync over CONDSTORE/QRESYNC makes the cache cheap.
- The initial sync does not fit inside a web request: Nextcloud had to chunk it. It belongs in a worker.
- Server-side THREAD cannot produce Gmail's cross-folder conversation. Application-side threading over a database can.
- Both CVEs above were contained by a **second layer** (CSP for SnappyMail, the proxy for Nextcloud). Defence in depth is not optional.

### 2.3 Recommended architecture

```
Browser (HTMX, Bootstrap) ── Apache/PHP 8.3: screens; reads PostgreSQL; writes "intents"
                                │  loopback HTTP + HMAC (JSON)
                                ▼
          mail-core (Python, systemd): IMAP pool (master user), sync worker,
          MIME parse/compose, sanitize, image proxy, outbox + SMTP submission,
          records/activity MCP servers for agents
                                │ IMAPS 993 · submission 587/465 · webhook from mbox01
                                ▼
          mbox01 (MaluMail): Dovecot 2.3.21 · Postfix 3.8.6 · Rspamd 3.8.1
PostgreSQL 17 (<tenant>_malumail): mailboxes, folders(uidvalidity, highestmodseq,
  uidnext), messages(envelope, flags, keywords, thread_id, snippet, text tsvector,
  auth results, spam score), labels, outbox, reviews, mail_events, access_log, policy
```

- **Index.** Per message: `(mailbox, folder, uidvalidity, uid)` as the key, plus modseq, flags and keywords, `Message-ID`, `In-Reply-To`, `References[]`, `thread_id`, base subject, from/to/cc as both jsonb and an address array, date and internal date, size, attachment names and types, `List-Id`, `Auto-Submitted`, `Authentication-Results`, the Rspamd score, the PREVIEW snippet, and **bounded body text** (e.g. the first 64 KB of the text part, or HTML converted to text) with a `tsvector`. Add `pg_trgm` on addresses and subjects.
- **Keep bodies on the server.** The full HTML and attachments are fetched on demand by `UID FETCH BODY.PEEK[section]` / `BINARY[section]`.
  - Trade-off: caching bodies roughly doubles storage, widens the damage of a breach, and makes deletion and retention harder. Not caching costs one IMAP round-trip each time a message is opened, which is acceptable.
- **Threading in the index, across folders.** JWZ/RFC 5256-style linking on References, then In-Reply-To. Split when the normalised subject changes or the thread passes 100 messages (Gmail's rules). Keep `thread_id` stable across MOVE by remapping UIDs from UIDPLUS `COPYUID`.
- **Sync worker.**
  - Initial sync newest first in batches (`ENVELOPE FLAGS BODYSTRUCTURE PREVIEW`, then text later).
  - Incremental: `SELECT … (QRESYNC (uidvalidity modseq))` or CONDSTORE `CHANGEDSINCE` plus a UID-set diff.
  - A UIDVALIDITY change drops the folder's rows and resyncs it. Labels survive because they are re-attached by Message-ID plus a hash.
  - Triggers: (1) Dovecot `push_notification` (Lua HTTP driver, or the OX driver, which fires only on deliveries) POSTing to mail-core; (2) IDLE on INBOX for active mailboxes, or one NOTIFY connection per mailbox (needs `mailbox_list_index=yes`); (3) a LIST-STATUS/HIGHESTMODSEQ sweep every few minutes as a safety net.
  - Watch `mail_max_userip_connections` (default **10 per user per source IP**): the worker and the web requests for the same mailbox share it.
- **Writes.** PHP records the intent and calls mail-core, which runs MOVE/STORE/APPEND at once and updates the index from the server's response. The next sync reconciles.
- **Rendering.**
  - Sanitize on the server with a whitelist.
  - Serve the message in an iframe that is sandboxed (no `allow-scripts`, no `allow-same-origin`; `allow-popups` plus `allow-popups-to-escape-sandbox` so links can open), with a CSP of `default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:`.
  - Images come only through `/mail/img?u=<hmac>`: an image proxy that fetches on the server, drops cookies and referrer, blocks private and loopback addresses (SSRF), limits size and type, and caches.
  - Rewrite links through an interstitial that shows the real host.
- **Attachments.** Stream from IMAP with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`. Never render HTML or SVG attachments inline.
- **Search.** Parse the operators into SQL. Body terms use the `tsvector`. "Search on server (slow)" stays an explicit fallback for text beyond the indexed length.

### 2.4 Requirements

| R | Rank | Requirement |
|---|---|---|
| R29 | MUST | PostgreSQL envelope index plus bounded text as described; bodies and attachments stay in Dovecot. |
| R30 | MUST | All IMAP/SMTP/MIME/sanitize work in one Python service; PHP never opens IMAP. This removes the need for any PHP IMAP library or composer. |
| R31 | MUST | Cross-folder conversation threading in the index (References, then In-Reply-To; subject split; 100-message cap). |
| R32 | MUST | Incremental sync with CONDSTORE/QRESYNC; UIDVALIDITY change triggers a folder resync; initial sync runs in the worker, never in a request. |
| R33 | MUST | Push-first sync triggers with IDLE/NOTIFY and a sweep as fallback. |
| R34 | MUST | Sanitizer, sandboxed iframe, CSP and image proxy; remote content blocked by default; per-sender "always show". |
| R35 | MUST | An outbox table: `send_after` for undo and schedule, a policy check, SMTP submission, then APPEND to Sent with `\Seen`; idempotent with a stored `Message-ID`. |
| R36 | SHOULD | Changes endpoint (an index sequence or ETag) for HTMX polling of the list partial; 204 when nothing changed. |
| R37 | LATER | A JMAP-shaped internal API (threads, query, changes) for agents and a future mobile client. |

---

## 3. Email for AI agents

### 3.1 Products and patterns

- **AgentMail** ([docs](https://docs.agentmail.to/), [index](https://docs.agentmail.to/llms.txt))
  - **Inboxes:** created by API (`username`, `domain`, `display_name`, `client_id` for idempotency); "tens, hundreds, or even thousands" of them; a thread "groups together all replies and forwards" ([Inboxes](https://docs.agentmail.to/inboxes.md)).
  - **Human in the loop:** the agent creates a **draft**; `drafts.list()` across the organisation is the review queue; a human calls `drafts.send()`; labels such as `needs-human-review` track state ([HITL](https://docs.agentmail.to/knowledge-base/human-in-the-loop.md)).
  - **Lists:** send / receive / reply × allow / block, by address or domain, at organisation, pod or inbox level, and inbox-level lists override the others. Replies are matched by `In-Reply-To`, and reply lists are empty, meaning allow, by default ([Lists](https://docs.agentmail.to/knowledge-base/allowlists-blocklists.md)).
  - **Reply extraction:** `extracted_text`/`extracted_html` strip quoted history and boilerplate but keep the signature, using `agentextract` (MIT, rule-based). Known gaps: forwarded mail and unusual layouts ([Reply extraction](https://docs.agentmail.to/reply-extraction.md)).
  - **API keys:** scoped to organisation, pod or inbox, with whitelisted permissions. `draft_create` without `draft_send`/`message_send` gives a draft-only agent. `label_unauthenticated_read=false` hides unauthenticated mail from the key ([Permissions](https://docs.agentmail.to/permissions.md)).
- **Inbound parsing services**
  - Mailgun posts `stripped-text`, `stripped-signature` and `stripped-html`, signs the request with an HMAC, and retries for 8 hours ([Mailgun receive](https://documentation.mailgun.com/docs/mailgun/user-manual/receive-forward-store/receive-http)).
  - Resend POSTs to your endpoint, gives attachments as temporary URLs, and has an MCP server so an agent can "list, read, and download inbound emails" ([Resend receiving](https://resend.com/docs/dashboard/receiving/introduction)).
  - Postmark's inbound page returned 403 (unverified).
- **Cloudflare Agents.** Mail arrives in `onEmail()` through `routeAgentEmail` and an address resolver; the agent answers with `replyToEmail`. Put "a stable identifier in the reply address, message metadata, or headers" so replies reach the right agent ([Cloudflare Agents email](https://developers.cloudflare.com/agents/api-reference/email/)).
- **Microsoft**
  - Agent 365 builds on Entra Agent ID: a blueprint, then an agent identity, then an optional **agent's user account**, which can hold a mailbox (Frontier preview). Agents have no passwords or MFA and are governed by Conditional Access. Every agent needs at least one human **sponsor**. Audit logs separate the blueprint, the agent and the agent's user account. Modes are S2S, OBO and Agentic-User ([Agent 365 identity](https://learn.microsoft.com/en-gb/microsoft-agent-365/developer/identity)).
  - An agent's user account cannot hold privileged admin roles, and its rights are "similar to guest users" ([Entra agent users](https://learn.microsoft.com/entra/agent-id/agent-users)).
  - Takeaways for us: give each agent its own identity and mailbox, a named human sponsor (our "principal"/supervisor), no admin roles, and an audit trail that tells the agent apart from the person it acts for.
- **Google.** I found no official guidance on agents with their own Gmail mailbox (unverified). Third-party guides use domain-wide delegation; I did not open them.
- **Human-in-the-loop UI pattern.** LangChain's Agent Inbox (now archived) defines an interrupt with `action_request`, `description` (markdown context) and `config` flags `allow_accept`, `allow_edit`, `allow_respond`, `allow_ignore` ([agent-inbox](https://github.com/langchain-ai/agent-inbox)). Accept / edit / respond / ignore is the right verb set for a review queue.

### 3.2 Prompt injection from inbound mail

- **The lethal trifecta.** Private data plus untrusted content plus external communication equals data theft; "an attacker can literally email your LLM and tell it what to do". The only reliable defence is to avoid the combination ([Willison](https://simonwillison.net/2025/Jun/16/the-lethal-trifecta/)).
- **Design patterns for constraining agents** (action-selector, plan-then-execute, map-reduce, dual LLM, code-then-execute, context minimisation): "once an LLM agent has ingested untrusted input, it must be constrained so that it is impossible for that input to trigger any consequential actions" ([Willison on the patterns paper](https://simonwillison.net/2025/Jun/13/prompt-injection-design-patterns/)).
- **OWASP LLM01:2025.** Constrain behaviour, validate output, filter input and output, least privilege, **human approval for high-risk actions**, **segregate external content**, adversarial testing; "it is unclear if there are fool-proof methods" ([OWASP LLM01](https://genai.owasp.org/llmrisk/llm01-prompt-injection/)).
- **EchoLeak (CVE-2025-32711, CVSS 9.3).** A zero-click attack on M365 Copilot: one crafted email put into the context leaked anything in Copilot's reach, combined with a CSP bypass. Suggested mitigations included blocking processing of external email ([SOC Prime](https://socprime.com/blog/cve-2025-32711-zero-click-ai-vulnerability/), secondary source).
- **Google's layers.** Injection classifiers, "security thought reinforcement", **markdown sanitization and suspicious URL redaction** to stop exfiltration through images and links, a **user confirmation framework** for risky actions, and notifications to the user ([Workspace: indirect prompt injection](https://knowledge.workspace.google.com/admin/security/indirect-prompt-injections-and-googles-layered-defense-strategy-for-gemini)).

### 3.3 Deliverability with a cold IP

- **Gmail, all senders:** SPF or DKIM, matching forward and reverse DNS (PTR), TLS, RFC 5322 format, and a spam rate below 0.3% (aim below 0.10%).
- **Gmail, 5,000+ messages a day:** add DMARC with alignment and one-click unsubscribe for marketing mail.
- **Gmail advice for new senders:** DKIM ≥1024-bit (2048 recommended). "Start with low volume to engaged users"; avoid bursts; doubling volume at once can bring rate limiting; on SMTP `4.7.28` stop for at least 10 minutes ([Gmail sender guidelines](https://support.google.com/a/answer/81126)).
- **AgentMail's warm-up schedule:** days 1–3 10–20/day → days 4–7 50–100 → days 8–14 200–500 → days 15–21 500–1,000 → days 22–30 1,000–5,000. Healthy means bounces below 2% and complaints below 0.1%; slow down above 5% bounces. Have agents reply before they reach out cold; put high-volume sending on separate subdomains ([Domain warming](https://docs.agentmail.to/knowledge-base/domain-warming.md)).
- **Outlook.com** has high-volume sender rules from 2025; the Microsoft post did not load for me (unverified).
- **On mbox01:** Rspamd's 500 messages/hour/mailbox cap is a backstop, not a budget. Greylisting delays first-time external senders by minutes, which adds latency to agent replies.
- **Loop safety ([RFC 3834](https://www.rfc-editor.org/rfc/rfc3834.html)):**
  - Mark mail sent without review `Auto-Submitted: auto-replied` (a reply) or `auto-generated` (anything else).
  - Never answer messages with `Auto-Submitted` other than `no`, a null return path, or (optionally) `List-*` or `Precedence: bulk/list/junk`.
  - Do not repeat the same response to a sender within 7 days.

### 3.4 Recommended defaults

**Which agent mail may leave without review (v1):**

| Case | Default |
|---|---|
| Agent → any mailbox on the tenant's own domains (people, agents, aliases) | **Auto-send**, logged, capped (e.g. 60/hour per agent) |
| Agent → external address, any thread | **Review** (accept / edit / reject / respond-to-agent) |
| Agent mail with an attachment, a link to a domain not on the allowlist, Bcc, more than 5 recipients, or a recipient never seen before | **Review**, with the reason shown |
| A super-admin's allowlist entry for agent × recipient domain or address (e.g. a supplier's ordering address) | Auto-send within a daily cap, with the evidence shown (e.g. the last 20 approvals unedited) |
| A newly hired agent | **Draft-only** until a super-admin raises its tier |
| Agent frozen, or the business's external budget used up | Held in the outbox; the reviewer sees why |

Why replies to an external sender are not exempt: the sender may be the attacker, and replying in the thread is itself the exfiltration channel.

**What a reviewer sees:**
1. The draft rendered exactly as it will go out: From/Sender ("Agent on behalf of Person"), recipients with external ones flagged, subject, HTML and text, attachments (name, type, size, hash).
2. Links listed with their real hosts.
3. A diff against the agent's previous version and against any edit.
4. The inbound messages that triggered it: authentication results (SPF/DKIM/DMARC), Rspamd score, first-time-sender flag, and hidden-content flags (invisible text, zero-size fonts) found during sanitizing.
5. The agent run: its id, the tools it called (especially record reads that put business data in the draft), and a link to the prompt-ledger entry.
6. The policy rule that held it.
7. Buttons: Approve & send, Edit & send, Reject (with a reason, fed back to the agent), Reply to agent.

**How the oversight trail is kept:**
- `mail_events` is append-only: received, read by agent, draft created or edited, held (and the rule), reviewed (who, decision, edit diff), submitted (Message-ID, queue id), delivered, bounced, recalled.
- Each event links to the kernel's `agent_runs`, the prompt ledger, `log_activity`, and MaluDB activity memory. Activity memory gets **metadata only**, never bodies.
- The kernel's approvals hook is the decision point: `mail_send` becomes an action that pauses for review.

**Kill switch:** freeze one agent (outbox holds its mail and its send tools are refused); a global "hold all agent mail" and "hold all external mail"; and a hard stop through MaluMail's API that disables the mailbox's submission.

| R | Rank | Requirement |
|---|---|---|
| R38 | MUST | One mailbox per agent, created by a super-admin through MaluMail's API; a named human sponsor per agent; agents never hold mailbox passwords (mail-core sends for them). |
| R39 | MUST | Agents read through MCP tools over the index: whole threads, extracted reply text, participants, authentication results, labels. Attachments only through an explicit tool limited to allowed file types. Never raw HTML. |
| R40 | MUST | Only DMARC-pass (or allowlisted) external mail wakes an agent or enters its context automatically. Unauthenticated or high-spam mail goes to a human queue. |
| R41 | MUST | Inbound text is handed to the model as clearly labelled untrusted data. Hidden HTML content is stripped and flagged. Remote images and URLs are never fetched on an agent's behalf. |
| R42 | MUST | The outbound policy matrix above; tiers set by super-admins; draft-only by default for new agents. |
| R43 | MUST | Review queue showing the reviewer content listed above; accept / edit / reject / respond; approvals go through the kernel's approval hook. |
| R44 | MUST | Rate caps: per-agent hourly and daily limits plus a business-wide external budget that ramps during warm-up (e.g. weeks 1–4 at 20/50/100/200 a day, then about +50% a week while bounces stay below 2% and complaints near zero). Back off on `4.7.28`/`421`. |
| R45 | MUST | RFC 3834 loop rules plus a cap of N agent sends per thread per day, to break agent-to-agent loops. |
| R46 | MUST | Kill switches (agent / all agents / all external / submission hard stop). |
| R47 | MUST | Append-only `mail_events` trail linked to runs, the ledger and the activity log. |
| R48 | SHOULD | AI disclosure in agent mail (§4) and a header such as `X-Agent: <name>; on-behalf-of=<person>`. |
| R49 | SHOULD | "Earned autonomy" statistics per agent × domain (approved unedited, edited, rejected) shown when a super-admin raises a tier. |
| R50 | LATER | Agent outreach or campaigns: a separate subdomain, its own warm-up, one-click unsubscribe. |

---

## 4. Safety and law (principles, not legal advice)

- **United States.**
  - The Stored Communications Act's provider exception generally lets an employer that runs the mail system access mail stored on it (*Fraser v. Nationwide*: "employees have no statutory privacy protection regarding emails stored on company-owned systems"). Interception in transit falls under the Wiretap Act, with consent and ordinary-course exceptions. Employees' personal accounts are off limits.
  - A clear acceptable-use and monitoring policy usually defeats a privacy-tort claim ([LexPlug ECPA/SCA outline](https://www.lexplug.com/outlines/electronic-monitoring-ecpa-stored-communications-act)).
  - Some states require notice. New York Civil Rights Law §52-c requires written notice at hiring, a written or electronic acknowledgment, and a posted notice for monitoring of "electronic mail or transmissions". Automated processes for system maintenance or protection that do not target one person are exempt. Penalties are $500, $1,000, then $3,000 ([NY Civ. Rights §52-c](https://newyork.public.law/laws/n.y._civil_rights_law_section_52-c*2)).
- **European Union.**
  - *Bărbulescu v. Romania* (ECtHR Grand Chamber, 2017) sets six tests: clear notice given in advance; the extent of monitoring, distinguishing **flow from content**; legitimate reasons, with weightier ones needed for content; whether a less intrusive method existed; the consequences for the employee; and safeguards ([Littler](https://www.littler.com/news-analysis/asap/european-court-limits-workplace-email-monitoring)).
  - WP29 Opinion 2/2017: consent is "highly unlikely" to be a lawful basis at work; processing must be proportionate; "prevention should be given much more weight than detection"; DLP scanning should warn the sender before sending; employees may make some private use; monitoring logs should not be kept permanently ([WP29 2/2017](https://gdpr-text.com/en/guidelines/dataprocessingatwork/)).
- **Retention.**
  - Germany, HGB §257: commercial letters received and copies of those sent must be kept **6 years**; booking vouchers 8 years ([HGB §257](https://www.gesetze-im-internet.de/hgb/__257.html)).
  - United States: the duty to preserve starts once litigation is "reasonably foreseeable". Under FRCP 37(e) the harshest sanctions require "intent to deprive" ([FRCP 37](https://www.law.cornell.edu/rules/frcp/rule_37)). That means a **legal hold** is needed.
  - The GDPR's storage-limitation principle cuts the other way. Retention is therefore a per-tenant policy, not a constant.
- **Sending mail content to an external model.**
  - The provider is a processor: you need an Art. 28 contract (documented instructions including third-country transfers, approval of sub-processors, deletion at the end) ([GDPR Art. 28](https://gdpr-info.eu/art-28-gdpr/)).
  - Anthropic deletes API inputs and outputs within 30 days unless zero data retention is agreed, longer is needed for Usage Policy enforcement, or the law requires it ([Anthropic retention](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-personal-data); page updated 2026-07-01). Whether commercial API data is excluded from training was not stated on that page (unverified there).
  - Third parties' emails, including special-category data, flow into the model **and** into the kernel's prompt ledger, which stores full context.
- **AI disclosure.** EU AI Act Art. 50(1) requires that systems interacting with people are designed so the people know it is an AI; it applies from 2 August 2026 ([AI Act Art. 50](https://artificialintelligenceact.eu/article/50/)). Whether later amendments moved that date is unverified.

| R | Rank | Requirement |
|---|---|---|
| R51 | MUST | A written mail monitoring and acceptable-use policy, shown on first use, with the acknowledgment recorded. It states that agent mailboxes are fully reviewable and that human mailboxes are opened only for listed reasons. |
| R52 | MUST | Access log for every open of a mailbox, conversation or attachment by anyone but the owner: who, what, when, under which grant, and a required reason for super-admin access. Visible to super-admins; the owner sees "who opened my mail" by default. |
| R53 | MUST | Oversight starts from metadata (counts, domains, recipients, held drafts). Opening content is a separate, logged step. |
| R54 | MUST | A per-mailbox "AI may read" switch: on for agent mailboxes, **off for human mailboxes** unless the person turns it on for their own assistant. A conversation marked sensitive is excluded from agent context. |
| R55 | MUST | Data minimisation to models: extracted reply text and only the turns needed; attachments only by explicit tool call. Ledger retention for mail-derived prompts follows the mailbox's retention. Model providers are listed as processors; prefer zero data retention where offered. |
| R56 | MUST | Retention: Trash and Spam purged after 30 days; per-mailbox retention policy; a legal hold that suspends every deletion. Expunging a message removes it from the index, the outbox and review bodies (metadata kept). |
| R57 | SHOULD | AI disclosure footer on agent-originated mail ("Sent by Ada, an AI agent of <Business>, for <Person>"). |
| R58 | SHOULD | Policy templates per jurisdiction (US with a state-notice variant; EU per Bărbulescu and WP29). |

---

## 5. Protocol facts for the build

### 5.1 Dovecot 2.3.21

| Item | Fact | Implication |
|---|---|---|
| CONDSTORE / QRESYNC (RFC 7162) | Present since 1.2, along with ESEARCH, ESORT, SEARCHRES, WITHIN, ID and CONTEXT=SEARCH ([Dovecot 1.2.0 notes](https://bbs.iredmail.org/post5348.html)). Listed on the [Dovecot RFC page](https://doc.dovecot.org/main/core/summaries/rfc.html) (2.4 docs). | Incremental sync. |
| THREAD / SORT (RFC 5256, 5957) | `THREAD=REFERENCES`, `THREAD=REFS` (ordered by latest message, no base-subject merge), `SORT`, `SORT=DISPLAY`. A 2.3.21 capability string with these was seen only in a search snippet (unverified). | Useful for single-folder views; not used for conversations. |
| SPECIAL-USE, METADATA, NOTIFY, COMPRESS, SEARCH=FUZZY, URLAUTH | "need to be explicitly enabled"; NOTIFY needs `mailbox_list_index = yes` ([Dovecot 2.3 IMAP server](https://doc.dovecot.org/2.3/configuration_manual/protocols/imap_server/)); METADATA needs `imap_metadata = yes` and `mail_attribute_dict` ([IMAP METADATA](https://doc.dovecot.org/2.3/configuration_manual/imap_metadata/)). | Check what mbox01 has enabled. |
| MOVE, IDLE, UIDPLUS, BINARY, LIST-STATUS, MULTIAPPEND, CATENATE, `$Important` (8457), STATUS=SIZE (8438), SAVEDATE, REPLACE | On the RFC list ([RFC page](https://doc.dovecot.org/main/core/summaries/rfc.html)). That page is the 2.4 edition; 8438, 8508 and 8514 may be 2.4-only (unverified). OBJECTID (8474) and UTF8=ACCEPT are **not** listed. | Do not rely on EMAILID: key on folder/UIDVALIDITY/UID plus Message-ID. |
| PREVIEW (RFC 8970) | Supported since 2.3.15; SNIPPET deprecated ([IMAP PREVIEW](https://doc.dovecot.org/2.3/admin_manual/imap_preview/)). | Snippets in the list. |
| Keywords | 26 per folder in Maildir filenames, the rest only in indexes ([Maildir](https://doc.dovecot.org/2.3/admin_manual/mailbox_formats/maildir/)); `mail_max_keyword_length` 50 ([settings](https://doc.dovecot.org/2.3/settings/core/)). | The §1.2 label model. |
| No FTS | "searches use a slow sequential search through all message data"; header searches use the index and cache; FTS options are Solr, flatcurve (planned in 2.3) or third-party xapian ([Dovecot FTS](https://doc.dovecot.org/2.3/configuration_manual/fts/)). | Search over our index. |
| Limits | `mail_max_userip_connections` 10; `imap_idle_notify_interval` 2 min; `imap_max_line_length` 64k ([settings](https://doc.dovecot.org/2.3/settings/core/)). | Connection budget per mailbox. |
| Master users | Log in as `user*master`; defined with `master=yes`; `result_success=continue` checks the target user exists; master users are subject to ACLs ([Master users](https://doc.dovecot.org/2.3/configuration_manual/authentication/master_users/)). | One worker credential for all mailboxes. This is MaluMail's change to make. |
| Push | The `push_notification` plugin. The OX driver POSTs JSON on **MessageNew** (deliveries only): event, folder, from, imap-uid, uidvalidity, snippet, subject, unseen, user. The Lua driver (2.3.4+) can POST on new and appended mail ([Push notifications](https://doc.dovecot.org/2.3/configuration_manual/push_notification/)). | A delivery webhook into mail-core. |
| doveadm HTTP API | `POST /doveadm/v1` with an API key; includes `sievePut`, `sieveActivate`, `sieveList`, `sieveGet`, `sieveDelete`. Don't batch several commands in one request ([doveadm HTTP API](https://doc.dovecot.org/2.3/admin_manual/doveadm_http_api/)). | One way to manage Sieve without ManageSieve. |

### 5.2 Sieve without ManageSieve

- **Storage.** Default `sieve = file:~/sieve;active=~/.dovecot.sieve`. `sieve_before`/`sieve_after` hold global scripts, for example to copy agent mail to an oversight mailbox. `sieve_max_redirects` defaults to 4 ([Sieve configuration](https://doc.dovecot.org/2.3/configuration_manual/sieve/configuration/)).
- **Vacation.** `sieve_vacation_min_period` 1d, `default_period` 7d, `max_period` 0 (no limit). `vacation-seconds` is off by default. The envelope sender defaults to the null sender. Recipients are checked so only mail explicitly addressed to the mailbox gets a reply ([Vacation](https://doc.dovecot.org/2.3/configuration_manual/sieve/extensions/vacation/)).
- **Options, best first:**
  1. MaluMail exposes **ManageSieve** (port 4190, part of Pigeonhole; Nextcloud uses horde-managesieve).
  2. A MaluMail API endpoint wrapping doveadm `sievePut`/`sieveActivate`.
  3. The doveadm HTTP API on a private interface.
  4. Writing files over ssh. Fragile; avoid.
- In every case the webmail owns **one generated script per mailbox**, built from PostgreSQL rules (filters, vacation, label `addflag`). Hand-written scripts are never parsed back.
- **R59 MUST:** script generation with a round-trip test. **R60 MUST:** vacation with a 1-day minimum. **R61 SHOULD:** filters.

### 5.3 PHP, if PHP must touch mail at all

- **ext/imap** was unbundled to PECL in PHP 8.4 (vote 27–0) because c-client, its underlying library, has not been released since 2007 ([PHP RFC](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)). We run PHP 8.3; treat ext/imap as a dead end anyway.
- **Pure-PHP IMAP libraries (all need composer):**
  - `webklex/php-imap`: MIT, needs no ext-imap, but pulls in carbon, symfony/http-foundation and illuminate/pagination ([repo](https://github.com/Webklex/php-imap), [composer.json](https://raw.githubusercontent.com/Webklex/php-imap/master/composer.json)).
  - `directorytree/imapengine`: MIT, PHP ^8.1, pulls in symfony/mime, carbon, illuminate/collections and zbateson ([repo](https://github.com/DirectoryTree/ImapEngine), [composer.json](https://raw.githubusercontent.com/DirectoryTree/ImapEngine/master/composer.json)).
  - `bytestream/horde-imap-client`: LGPL-2.1, v2.34.2 (2026-09-21), about 9 Horde dependencies, the one Nextcloud uses ([Packagist](https://packagist.org/packages/bytestream/horde-imap-client)).
  - `ddeboer/imap`: **requires ext-imap and PHP ≥8.4**. Ruled out ([repo](https://github.com/ddeboer/imap)).
- **MIME:** `zbateson/mail-mime-parser`, BSD-2, PHP ≥8.1, no mailparse extension needed ([Packagist](https://packagist.org/packages/zbateson/mail-mime-parser)).
- **Sanitizers:**
  - HTMLPurifier: LGPL-2.1+, v4.19.1, 2026-09-15 ([Packagist](https://packagist.org/packages/ezyang/htmlpurifier)). It knows only XHTML 1.0, which is why Nextcloud adds `figure` and similar elements itself.
  - `symfony/html-sanitizer`: MIT. 8.x needs **PHP ≥8.4.1** ([Packagist](https://packagist.org/packages/symfony/html-sanitizer)); the **7.4 line needs PHP ≥8.2** plus masterminds/html5 and league/uri ([7.4 composer.json](https://raw.githubusercontent.com/symfony/html-sanitizer/7.4/composer.json)).
- **SMTP without a library:** `stream_socket_client` to port 465 (implicit TLS), or to 587 then `STARTTLS` and `stream_socket_enable_crypto(..., STREAM_CRYPTO_METHOD_TLS_CLIENT)` with `verify_peer` set ([PHP manual](https://www.php.net/manual/en/function.stream-socket-enable-crypto.php)). RFC 8314 says the two ports have no significant security difference ([RFC 8314](https://www.rfc-editor.org/rfc/rfc8314.html)).
- **PHPMailer:** LGPL-2.1-only, v7.1.1 ([Packagist](https://packagist.org/packages/phpmailer/phpmailer)).
- **Recommendation:** none of the above. PHP stays a screen layer (R30).

### 5.4 Python, for mail-core

- **IMAP:**
  - `imaplib` (stdlib) gains `IMAP4.idle()` only in **3.14**; Ubuntu 24.04's default 3.12 lacks it (unverified for mbox01's host). It has no CONDSTORE/QRESYNC helpers, and `IMAP4_SSL` **does not verify certificates** unless you pass `ssl.create_default_context()` ([imaplib](https://docs.python.org/3/library/imaplib.html)).
  - **IMAPClient**: BSD, 4.1.0 (2026-09-18), Python 3.8–3.14 ([PyPI](https://pypi.org/project/IMAPClient/)). Has `idle()`/`idle_check()`/`idle_done()`, `fetch(..., modifiers=['CHANGEDSINCE n'])`, `enable()`, `move()`, `thread()`, `sort()`, `append()`/`multiappend()`. No QRESYNC helper ([API](https://imapclient.readthedocs.io/en/master/api.html)). **The recommended choice.**
  - `aioimaplib`: **GPL-3.0**; no SORT/THREAD; tested on 3.9–3.11 ([repo](https://github.com/iroco-co/aioimaplib)). Avoid.
- **MIME:** stdlib `email` with `policy.default`, using `get_body(('html','plain'))` and `iter_attachments()`; `compat32` "should not be used with EmailMessage" ([email.message](https://docs.python.org/3/library/email.message.html)).
- **Sanitizing:** `nh3`, MIT, 0.3.7 (2026-08-23), bindings to the Rust Ammonia library, about 20× faster than bleach ([PyPI](https://pypi.org/project/nh3/)). **bleach is archived** ("no future releases including for security issues", June 2026; [repo](https://github.com/mozilla/bleach)). How nh3 handles inline CSS is unverified; email depends on `style=`, so plan a CSS property-allowlist pass.
- **Reply-text extraction:** port the rules of AgentMail's MIT-licensed `agentextract`, or write our own.

| R | Rank | Requirement |
|---|---|---|
| R62 | MUST | mail-core on IMAPClient + `email` + nh3 (with a CSS allowlist) + `smtplib` (465, or 587 with STARTTLS, certificates verified). |
| R63 | MUST | On mbox01, MaluMail provides a master user, push notification (Lua or OX), a Sieve management path, SPECIAL-USE folders (`\Archive \Drafts \Junk \Sent \Trash`), and Postfix sender-login maps that cover aliases. |
| R64 | SHOULD | NOTIFY (`mailbox_list_index=yes`) for one connection per mailbox. |

---

## 6. Could not verify, and what to check on mbox01

- **Not verified:**
  - The exact Dovecot 2.3.21 capability string (third-party page blocked).
  - Whether RFC 8438, 8508 and 8514 are in 2.3.
  - PREVIEW length and the LAZY modifier.
  - Postmark's inbound documentation (403).
  - Outlook's sender requirements (page did not load).
  - Gmail's pagination, "Show trimmed content" and "always show images from sender" (no help page opened).
  - Schedule send on desktop (the page opened was the iOS one).
  - EchoLeak specifics (secondary source).
  - Anthropic's training stance on commercial API data.
  - Whether the AI Act Art. 50 date has since changed.
  - nh3's CSS handling.
  - Roundcube's behaviour with no server THREAD.
  - Senserva's KEV list against CISA's own catalogue.
- **Check on mbox01 before Phase 0:**
  - `a CAPABILITY` after login.
  - `special_use`, `mailbox_list_index`, `imap_metadata`.
  - Whether the `push_notification` plugin and Lua are available.
  - A master-user passdb.
  - Postfix `message_size_limit` and `smtpd_sender_login_maps`.
  - Whether mail between tenant mailboxes is delivered locally (if so, internal agent mail does not touch IP reputation).
  - Whether Rspamd learns from moves to and from Junk (imapsieve).
  - How Rspamd's 500/hour/mailbox limit is keyed (mailbox vs authenticated user vs alias).
  - Whether the MaluMail API can manage aliases, submission enable/disable and Sieve.