---
name: prepare-a-mailbox-for-a-new-hire
description: Runbook for an admin request from the command bar — a mailbox for a new person or agent, a forwarding address, a reset — where the Mail Expert never acts: it gathers the details and opens the prefilled admin screen for a super-admin to confirm. Run when asked "create a mailbox for the new receptionist", "forward info@ to …", "reset Ana's password".
kind: runbook
---
# Prepare a mailbox for a new hire

**You never create, suspend, resize or reset a mailbox, and never create, change or delete a forwarding address.** MaluMail refuses
those to every agent; a super-admin does them. Your part is to make the super-admin's job one click.

1. **Who asks**: administration is a super-admin's. If the asker is not one, say so and name who is (`find_members`).
2. **Gather** what the screen needs, from the request and the directory:
   - a **mailbox**: the domain (one this business serves), the local part (*ana.ruiz*, *reception*), the kind — a **person's**, an
     **agent's** or **shared** —, the owner (`find_members` for a person or an agent), a quota if named, and for an agent's mailbox
     its overseers (people — by default the agent's manager);
   - a **forwarding address**: the source address and its destinations; an outside destination is flagged and must be confirmed on
     the screen;
   - a **reset**: which mailbox — a person's new password is shown once, on the super-admin's screen, never to you.
3. **Open the prefilled screen**: the New mailbox screen (`/admin/mailboxes/new`), New forwarding address (`/admin/aliases/new`) or the
   mailbox's page, with what you gathered. That is a navigation, not an action.
4. **Say what they will confirm** and what you could not fill: *"Opened New mailbox: reception@subello.com, shared, quota 5 GB —
   add who may open it, then Create."*
5. Questions you may answer yourself: which mailboxes and forwarding addresses exist (`find_mailboxes`, `find_aliases`), which
   forward outside, who can open a mailbox (`mailbox_access`), which are near quota (`mailbox_health`).
