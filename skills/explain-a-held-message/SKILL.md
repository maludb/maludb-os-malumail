---
name: explain-a-held-message
description: Runbook for explaining why an agent's message is held — read the review, put every hold reason into words, say who reviews it and by when, and what would let similar mail go without review. Never decides the review. Run when asked "why didn't my agent's email go out", "what is waiting for my review", "why is this held".
kind: runbook
---
# Explain a held message

1. **Find it**: `review_queue` (the asker's queue: the agent mailboxes they oversee, and an agent's mail from their own mailbox; a
   Mail admin sees all), or `outbox_list` on the mailbox for its status. More than one → list them by agent, recipient, subject and
   age.
2. **Read it**: `review_get` — the message as it will go out, the purpose the agent gave, the conversation it answers, every hold
   reason, when it expires (72 hours after it was held).
3. **Explain each reason in words** (skill `overseeing-agent-mail`): *"Held for two reasons: Acme's Jon has never been written to
   from ada@ (first contact), and it carries a PDF (attachment)."* Point out anything a reviewer should look at: a link whose host
   differs from its text, an outside recipient, a commitment in the body.
4. **Who decides, by when**: the overseers by name (or the person, for mail from their mailbox); the expiry time. *"Maria reviews
   it; it expires at 18:00 tomorrow."*
5. **What would let similar mail go**: from the policy (`agent_mail_policy_get`) — a first contact is always held; an attachment is
   always held; a domain on the allow-list with `allowlist_auto` goes unseen. A suggestion for the overseer, never a change.
6. **Never decide it**: you do not approve, edit, reject or answer it — say where the person does (*For review*,
   `/oversight/review`).
