---
name: overseeing-agent-mail
description: How agent mail is overseen in MaluMail — what a reviewer sees, the hold reasons in words, what makes a useful rejection note, when a policy is worth widening (earned from the record, not guessed), Unscreened mail, pause and take-over. Load to explain, sort or summarise held mail for the people who decide it; agents never decide it.
---
# Overseeing agent mail

**People decide; agents never do.** An agent's mailbox is overseen by the **people** holding `full` on it (one of them the primary
overseer). Mail an agent writes from a **person's** mailbox is reviewed by that person. The Mail admins see the whole queue. Every
decision — approve, edit, reject, reply to the agent, take over, release, mark known, pause, change a policy — is a person's;
MaluMail refuses it to every agent.

## What a reviewer sees (`review_get`)
The message exactly as it will go out (From and Sender, recipients with outside and new ones marked, subject, body, every link with
its real host, attachments with name, type, size and digest); the conversation it answers with the arriving message's DMARC result,
spam score and any hidden content removed; the **purpose** the agent gave; the tools its run called and its run id; every hold reason.

## The hold reasons, in words
| Reason | Say |
|---|---|
| `mode` | the policy holds everything of this kind for review |
| `first_contact` | the mailbox has never sent to this address |
| `attachment` | it carries a file |
| `over_hour`, `over_day`, `over_thread` | over the agent's own caps (50 an hour, 20 outside a day, 5 a conversation a day to start) |
| `over_business_budget` | over the business-wide budget for all agents' outside mail (the warm-up) |
| `paused` | the agent's mail is paused |
| `hold_all_agents`, `hold_all_external` | a Mail admin switched on a business-wide hold |
| `on_behalf` | written from a person's mailbox — that person reviews it |

Held mail expires 72 hours after it was held; the agent is told.

## A useful rejection note
The agent reads it in its next mail turn. Say what was wrong and what to do instead, in one or two sentences: *"Don't quote prices —
say Maria will send the quote by Friday."* Not "no" or "bad".

## When to widen a policy
Earned, not guessed: the oversight cards count each agent's messages approved unedited, edited and rejected. For example: many to one
domain approved unedited → that domain on the allow-list (`allowlist_auto`). Mostly internal and never edited → `internal_auto`.
Rejections or edits in the last two weeks → keep `hold_all`. `auto` is for an agent with a long clean record, and the caps still apply.
A proposal is a sentence for the overseer; the change is theirs, on the policy screen.

## Unscreened mail
Outside mail from senders who are not known (never sent to, never marked by a person) does not wake the agent. It waits until a
person releases it (one turn), marks the sender known, or marks it spam. Mail that was only received never makes a sender known.

## Pause and take over
**Pause** holds everything the agent sends and starts no mail turns. **Take over** mutes the agent on one conversation and leaves
the reply to a person. The kernel's own assistant channel is outside both until K46; suspending the mailbox stops everything.
