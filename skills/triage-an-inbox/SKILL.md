---
name: triage-an-inbox
description: Runbook for tidying an inbox the asker may triage — read what is unread, archive what is done, label and star what matters, snooze what waits, spam what is spam, and report in a few lines. Never on an intake mailbox's INBOX. Run when asked "clean up my inbox", "archive everything from the newsletter", "what needs me today".
kind: runbook
---
# Triage an inbox

1. **Which mailbox, at what level**: `find_mailboxes` → the asker's level. Below `triage` you can read and report, not change: say
   so. A person's own mailbox: only in their run with `ai_read` on (skill `mail-basics`). An **intake** mailbox (the kernel's assistant,
   Help Desk, the CRM read it): its INBOX is never archived, moved, snoozed, trashed or marked read — report only.
2. **What is there**: `mailbox_overview` (unread, starred, snoozed counts), then `mail_list` on the Inbox, newest first, a page at a
   time. Read a conversation (`thread_read`) only when its subject and snippet do not say enough.
3. **Sort** each conversation, from the snippet and the headers:
   - **needs a person** — a question to them, a decision, money, a complaint: star it (`mail_flag`), leave it in the Inbox;
   - **waits** — a reply expected later, a date: `mail_snooze` until then;
   - **done or informational** — receipts, confirmations, notifications: `mail_archive`;
   - **a newsletter or list the asker named**: `mail_archive` (or `mail_label` with their label);
   - **spam** — only when plainly spam: `mail_spam`. When unsure, leave it and say so.
   Bulk actions take many messages in one call; one call per kind of action.
4. **Never** delete forever, empty the trash or delete a folder in a triage (they pause for approval anyway), never mark mail read
   that the asker did not ask about, never reply as part of a triage.
5. **Report** in a few lines: counts per action, then the starred ones by sender and subject with links — *"Archived 23
   (newsletters 15, receipts 8) · Snoozed 2 · Starred 3 that need you: Jon (Acme) asks to move Thursday …"*. Name what you could
   not change and why.
