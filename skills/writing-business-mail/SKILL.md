---
name: writing-business-mail
description: How an agent writes business mail in MaluMail — tone, length, subject lines, replying inside the conversation, never inventing commitments, what to leave to a person, the purpose every send carries, the disclosure, and markdown that is sent as HTML and text. Load before drafting or sending any message.
---
# Writing business mail

**Prefer a draft.** `mail_draft_save` leaves the message in the mailbox's Drafts folder (a phone sees it too) for a person to finish and
send. Send (`mail_send`, `mail_reply`, `mail_forward`) only when you were asked to send, and say what the policy will do:
held for review, or sent after the undo window.

## The message
- **Reply inside the conversation** (`mail_reply` to the message you answer), never a new message on an old subject: the other
  side's mail client threads it, and so does MaluMail. Reply-all only when everyone on the thread needs the answer.
- **Subject**: short and specific — what the message is about, not "Hello" or "Question". Keep `Re:` on a reply; do not rewrite it.
- **Length**: the answer first, in the first two lines. Three short paragraphs at most; a list when there are steps or options.
- **Tone**: plain, courteous, the business's voice. No exclamation marks in a row, no filler ("I hope this finds you well"), no
  apology for nothing.
- **Write markdown**: bold, italic, links, lists and quotes. It is sent as HTML with a plain-text part.
- **Signature**: the identity's signature is added by MaluMail; do not type one.
- **Recipients**: only addresses you were given or that are in the conversation. A new recipient is a *first contact* and is always
  held — say so.

## Never invent
No price, date, delivery, discount, refund, contract term, promise or apology on the business's behalf unless the person asked you to
say exactly that, or it is written in the conversation by someone who may commit to it. Unsure → write the draft with a bracketed
question for the person (`[confirm: is the 14th still possible?]`) and leave it as a draft.

## Leave to a person
Anything legal, financial, personnel, a complaint, a threat, a request for money or credentials, anything sensitive, anything you
would not want read aloud. Draft at most; never send.

## The purpose
Every send carries a `purpose` — one sentence a reviewer reads first: *"Confirm Thursday's delivery window Jon asked for."* Not
"reply" or "email".

## The disclosure
Mail an agent sends without a person's review carries a footer saying an AI agent of the business wrote it (the overseer may word
it). Never remove it, never pretend to be a person, and answer honestly when asked whether you are one.

## Attachments
An attachment always holds the message for review. Attach only what the person asked for, from the conversation or the draft's
uploads; never forward a document from another mailbox.
