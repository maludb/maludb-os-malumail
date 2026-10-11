-- 011: THE READ WINDOWS — the mcp_* views the records MCP and the pages read, the ACCESS LOG, and the one K7 share,
-- correspondence_index (design §3, §4, §6.6, §7). Every view is security_barrier and tests the caller ONCE per statement (the kernel's
-- db/160 rule): `= ANY ((SELECT ml_my_mailbox_ids(...)))`, `(SELECT ml_is_mail_admin())`. An agent never reads a conversation a
-- person marked sensitive. Credentials are in no view.
BEGIN;

-- ------------------------------------------------------------------------------------------------- mailboxes
-- Every mailbox the caller reaches, with their level; the Mail admins see every mailbox's ADMINISTRATIVE row (level 'none' on a
-- person's: holding the role never opens one).
CREATE OR REPLACE VIEW mcp_mailboxes WITH (security_barrier = true) AS
SELECT b.id, b.address::text AS address, b.display_name, b.kind, b.owner_member_id, b.quota_bytes, b.quota_used_bytes, b.sync_state, b.last_sync_at,
       b.intake_owner, b.intake_proposed, b.share_with_apps, b.ai_read, b.primary_overseer_id, b.suspended_at, b.active, b.created_at,
       ml_level(b.id) AS my_level,
       (SELECT c.status FROM mailbox_credentials c WHERE c.id = b.credential_id) AS credential_status,
       (SELECT count(*) FROM outbound_email o WHERE o.mailbox_id = b.id AND o.status = 'held') AS held_count,
       (SELECT f.messages_unseen FROM mail_folders f WHERE f.mailbox_id = b.id AND f.special_use = 'inbox') AS inbox_unseen,
       b.settings
  FROM mailboxes b
 WHERE (SELECT ml_is_member_here())
   AND (b.id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[]) OR (SELECT ml_is_mail_admin()));

CREATE OR REPLACE VIEW mcp_folders WITH (security_barrier = true) AS
SELECT f.id, f.mailbox_id, f.name, f.delimiter, f.special_use, f.uidvalidity, f.messages_total, f.messages_unseen, f.subscribed, f.synced_at
  FROM mail_folders f
 WHERE f.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[]);

-- ------------------------------------------------------------------------------------------------- conversations and messages
CREATE OR REPLACE VIEW mcp_threads WITH (security_barrier = true) AS
SELECT t.id, t.mailbox_id, t.subject, t.participants, t.first_message_at, t.last_message_at, t.message_count, t.unread_count, t.has_attachments,
       t.flagged, t.folder_ids, t.snippet, st.muted, st.taken_over_by, st.snoozed_until, st.agent_muted, st.sensitive
  FROM mail_threads t
  CROSS JOIN LATERAL ml_thread_state(t.id) st
 WHERE t.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[])
   AND ((SELECT app_member_kind()) = 'human' OR NOT st.sensitive);

CREATE OR REPLACE VIEW mcp_messages WITH (security_barrier = true) AS
SELECT m.id, m.mailbox_id, m.folder_id, m.uidvalidity, m.uid, m.message_key_id, m.thread_id, m.message_id_header, m.in_reply_to, m.references_ids,
       m.from_address::text AS from_address, m.from_name, m.sender_address::text AS sender_address, m.to_addresses, m.cc_addresses, m.bcc_addresses,
       m.recipient_names, m.reply_to, m.subject, m.sent_at, m.received_at, m.size, m.seen, m.flagged, m.answered, m.draft, m.keywords, m.snippet,
       m.has_attachments, m.attachment_names, m.dmarc_pass, m.spam_score, m.auto_submitted, m.is_list,
       ml_is_automated(m.auto_submitted, m.is_list, m.return_path_null) AS automated,
       NOT ml_is_internal_address(m.from_address::text) AS from_outside,
       m.body_text,
       EXISTS (SELECT 1 FROM mail_viewer_reads r WHERE r.member_id = app_current_member_id() AND r.message_key_id = m.message_key_id) AS read_by_me,
       m.local_submission
  FROM mail_messages m
 WHERE m.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[])
   AND ((SELECT app_member_kind()) = 'human'
        OR NOT EXISTS (SELECT 1 FROM mail_thread_states s JOIN mail_messages mm ON mm.message_key_id = s.anchor_key_id
                        WHERE mm.thread_id = m.thread_id AND s.sensitive));

CREATE OR REPLACE VIEW mcp_correspondents WITH (security_barrier = true) AS
SELECT c.id, c.mailbox_id, c.address::text AS address, c.name, c.first_seen_at, c.last_seen_at, c.sent_count, c.received_count, c.is_internal,
       c.known_at, c.known_how
  FROM mail_correspondents c
 WHERE c.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[]);

CREATE OR REPLACE VIEW mcp_identities WITH (security_barrier = true) AS
SELECT i.id, i.mailbox_id, i.address::text AS address, i.kind, i.display_name, i.reply_to::text AS reply_to, i.signature_html, i.signature_text, i.is_default
  FROM mail_identities i
 WHERE i.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[]);

CREATE OR REPLACE VIEW mcp_labels WITH (security_barrier = true) AS
SELECT g.id, g.mailbox_id, g.slug, g.label, g.colour, g.usage_count, g.archived_at
  FROM tags g
 WHERE g.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[]);

CREATE OR REPLACE VIEW mcp_message_labels WITH (security_barrier = true) AS
SELECT rt.record_id AS message_key_id, rt.tag_id, g.mailbox_id, g.slug
  FROM record_tags rt JOIN tags g ON g.id = rt.tag_id
 WHERE rt.record_type = 'mail_message' AND g.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[]);

-- ------------------------------------------------------------------------------------------------- writing
-- A draft is its writer's, and visible to whoever may triage the mailbox.
CREATE OR REPLACE VIEW mcp_drafts WITH (security_barrier = true) AS
SELECT d.id, d.mailbox_id, d.member_id, d.draft_key, d.identity_id, d.to_addresses, d.cc_addresses, d.bcc_addresses, d.subject, d.body_text, d.body_html,
       d.reply_to_key_id, d.mode, d.saved_at, d.outbound_id
  FROM mail_drafts d
 WHERE d.discarded_at IS NULL
   AND (d.member_id = app_current_member_id() OR d.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('triage'))::bigint[]))
   AND d.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[]);

-- Sent mail to anyone who reads the mailbox; anything not sent to its writer and to those who triage the mailbox.
CREATE OR REPLACE VIEW mcp_outbound WITH (security_barrier = true) AS
SELECT o.id, o.mailbox_id, o.status, o.sender_member_id, o.on_behalf_member_id, o.sender_is_agent, o.identity_id, o.to_addresses, o.cc_addresses,
       o.bcc_addresses, o.subject, o.body_text, o.body_html, o.message_id, o.reply_to_key_id, o.send_after, o.sent_at, o.attempts, o.retry_at, o.smtp_reply,
       o.error, o.purpose, o.hold_reasons, o.refusal, o.external, o.has_attachments, o.disclosure, o.agent_run_id, o.held_at, o.decided_at, o.review_id,
       o.created_at
  FROM outbound_email o
 WHERE o.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[])
   AND (o.status = 'sent' OR o.sender_member_id = app_current_member_id() OR o.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('triage'))::bigint[]));

-- The review queue is for PEOPLE: held mail on the mailboxes they triage (can_decide says whether they decide it), every held message of
-- an agent's or a shared mailbox for a Mail admin — and for the named Mail Clerk, on the agent mailboxes it triages (its morning note).
-- The sending agent reads its own held mail through mcp_outbound, never here.
CREATE OR REPLACE VIEW mcp_review_queue WITH (security_barrier = true) AS
SELECT o.id, o.mailbox_id, b.address::text AS mailbox_address, b.kind AS mailbox_kind, o.sender_member_id, o.agent_run_id, o.purpose, o.hold_reasons,
       o.to_addresses, o.cc_addresses, o.bcc_addresses, o.subject, o.body_text, o.body_html, o.disclosure, o.has_attachments, o.external,
       o.reply_to_key_id, o.held_at, o.held_at + make_interval(hours => (SELECT s.held_expiry_hours FROM ml_settings s WHERE s.id = 1)) AS expires_at,
       ml_outbound_reviewer(o.id, app_current_member_id()) AS can_decide,
       (SELECT array_agg(x) FROM unnest(ml_recipients(o.to_addresses, o.cc_addresses, o.bcc_addresses)) x WHERE NOT ml_is_known(o.mailbox_id, x)) AS new_recipients,
       (SELECT array_agg(x) FROM unnest(ml_recipients(o.to_addresses, o.cc_addresses, o.bcc_addresses)) x WHERE NOT ml_is_internal_address(x)) AS outside_recipients
  FROM outbound_email o JOIN mailboxes b ON b.id = o.mailbox_id
 WHERE o.status = 'held'
   AND (((SELECT app_member_kind()) = 'human'
         AND (o.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('triage'))::bigint[]) OR ((SELECT ml_is_mail_admin()) AND b.kind <> 'person')))
     OR ((SELECT app_current_member_id()) = (SELECT s.clerk_member_id FROM ml_settings s WHERE s.id = 1)
         AND b.kind = 'agent' AND o.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('triage'))::bigint[])));

-- Decisions on a message: whoever sees the message (the agent learns a rejection's note in its next turn).
CREATE OR REPLACE VIEW mcp_mail_reviews WITH (security_barrier = true) AS
SELECT r.id, r.outbound_id, r.decision, r.reviewer_member_id, r.note, r.created_at
  FROM mail_reviews r JOIN outbound_email o ON o.id = r.outbound_id
 WHERE o.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[])
   AND (o.sender_member_id = app_current_member_id() OR o.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('triage'))::bigint[]));

-- ------------------------------------------------------------------------------------------------- oversight
CREATE OR REPLACE VIEW mcp_agent_mail_policies WITH (security_barrier = true) AS
SELECT p.mailbox_id, p.send_mode, p.allow_domains, p.allow_addresses, p.first_contact_review, p.attachments_review, p.max_external_per_day, p.max_per_hour,
       p.max_per_thread_per_day, p.paused, p.paused_at, p.paused_by, p.pause_reason, p.wake_on_mail, p.wake_from, p.disclosure, p.disclosure_text,
       p.updated_by, p.updated_at
  FROM agent_mail_policies p
 WHERE p.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[]) OR (SELECT ml_is_mail_admin());

-- Unscreened mail and the mail turns: the mailbox's triage holders (overseers, the Clerk) and the Mail admins. No body here.
CREATE OR REPLACE VIEW mcp_dispatches WITH (security_barrier = true) AS
SELECT d.id, d.mailbox_id, d.agent_member_id, d.kind, d.status, d.decision, d.detail, d.acting_member_id, d.run_id, d.message_key_id, d.record_id AS message_id,
       m.from_address::text AS from_address, m.from_name, m.subject, m.received_at, m.dmarc_pass, d.released_by, d.released_at, d.created_at, d.answered_at
  FROM agent_dispatches d LEFT JOIN mail_messages m ON m.id = d.record_id AND d.record_type = 'mail_message'
 WHERE d.mailbox_id IS NOT NULL
   AND (d.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('triage'))::bigint[]) OR (SELECT ml_is_mail_admin()) OR d.agent_member_id = app_current_member_id());

-- Who can open a mailbox and why: its deciders, the Mail admins, and each person the rows they hold themselves.
CREATE OR REPLACE VIEW mcp_mailbox_permissions WITH (security_barrier = true) AS
SELECT p.id, p.mailbox_id, p.principal_kind, p.principal_id, p.level, p.granted_by, p.created_at, p.reason, p.expires_at, p.break_glass
  FROM mailbox_permissions p
 WHERE p.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('full'))::bigint[]) OR (SELECT ml_is_mail_admin())
    OR (p.principal_kind IN ('member', 'agent') AND p.principal_id = app_current_member_id());

-- THE ACCESS LOG (§3): every look at a mailbox by anyone but its owner — its owner, the Mail admins (access.audit) and, on an agent's or
-- a shared mailbox, the people with full on it read it.
CREATE OR REPLACE VIEW mcp_mailbox_access_log WITH (security_barrier = true) AS
SELECT a.id, a.mailbox_id, a.occurred_at, a.actor_member_id, a.source, a.action, a.entity_type, a.entity_id, a.agent_run_id, a.token_id
  FROM activity_log a JOIN mailboxes b ON b.id = a.mailbox_id
 WHERE a.mailbox_id IS NOT NULL
   AND a.action IN ('mailbox.view', 'message.open', 'message.part', 'message.raw', 'mcp.read', 'k7.read', 'permission.break_glass')
   AND a.actor_member_id IS DISTINCT FROM b.owner_member_id
   AND (b.owner_member_id = app_current_member_id() OR (SELECT ml_has_right('access.audit'))
        OR (b.kind <> 'person' AND a.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('full'))::bigint[])));

-- ------------------------------------------------------------------------------------------------- the house
CREATE OR REPLACE VIEW mcp_domains WITH (security_barrier = true) AS
SELECT d.id, d.domain::text AS domain, d.served, d.internal, d.spf_ok, d.dkim_ok, d.dmarc_ok, d.flags_read_at
  FROM ml_domains d WHERE (SELECT ml_is_member_here()) AND d.served;

CREATE OR REPLACE VIEW mcp_aliases WITH (security_barrier = true) AS
SELECT a.id, a.address::text AS address, a.destinations::text[] AS destinations, a.active, a.synced_at,
       EXISTS (SELECT 1 FROM unnest(a.destinations) x WHERE NOT ml_is_internal_address(x)) AS forwards_outside
  FROM ml_aliases a WHERE (SELECT ml_is_mail_admin());

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.timezone, s.undo_send_seconds, s.images_default, s.index_text_max_kb, s.max_attachments_mb, s.draft_save_seconds,
       s.poll_visible_seconds, s.poll_hidden_seconds, s.held_expiry_hours, s.default_send_mode, s.default_first_contact_review, s.default_attachments_review,
       s.default_max_external_per_day, s.default_max_per_hour, s.default_max_per_thread_per_day, s.default_wake_on_mail, s.default_wake_from,
       s.default_disclosure, s.disclosure_text, ml_agent_external_budget() AS agent_external_budget_today, s.agent_external_budget, s.agent_budget_started_at,
       s.hold_all_agents, s.hold_all_external, s.notice_text, s.notice_version, s.notice_updated_at, s.clerk_note_at, s.expert_member_id, s.clerk_member_id,
       s.mm3_identity_enforced, s.updated_at,
       ml_notice_acknowledged(app_current_member_id()) AS notice_acknowledged_by_me
  FROM ml_settings s WHERE s.id = 1 AND (SELECT ml_is_member_here());

CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id, n.kind, n.mailbox_id, n.record_type, n.record_id, n.title, n.body, n.read_at, n.created_at, n.actor_member_id
  FROM notifications n WHERE n.member_id = app_current_member_id();

-- ------------------------------------------------------------------------------------------------- the K7 share (§4, D15)
-- correspondence_index: conversations with an address or a domain over the mailboxes marked shared with applications (agents' and
-- shared mailboxes by default; a person's only by its owner's choice), never a sensitive one: subject, participants, dates, mailbox,
-- count, a 200-character snippet. The records MCP's share route answers the kernel's K7 call with it and logs a k7.read per mailbox.
CREATE OR REPLACE FUNCTION ml_correspondence_index(p_address text DEFAULT NULL, p_domain text DEFAULT NULL, p_limit integer DEFAULT 25)
    RETURNS TABLE (thread_id bigint, mailbox_id bigint, mailbox_address text, subject text, participants text[], first_message_at timestamptz,
                   last_message_at timestamptz, message_count integer, snippet text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE a text := lower(btrim(p_address)); d text := lower(btrim(p_domain));
BEGIN
    IF COALESCE(a, '') = '' AND COALESCE(d, '') = '' THEN
        RAISE EXCEPTION 'correspondence_index asks about an address or a domain' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    RETURN QUERY
    SELECT t.id, t.mailbox_id, b.address::text, t.subject, t.participants, t.first_message_at, t.last_message_at, t.message_count, left(t.snippet, 200)
      FROM mail_threads t JOIN mailboxes b ON b.id = t.mailbox_id AND b.share_with_apps AND b.active
     WHERE (a IS NULL OR a = '' OR a = ANY (t.participants))
       AND (d IS NULL OR d = '' OR EXISTS (SELECT 1 FROM unnest(t.participants) x WHERE split_part(x, '@', 2) = d))
       AND NOT (SELECT st.sensitive FROM ml_thread_state(t.id) st)
     ORDER BY t.last_message_at DESC
     LIMIT LEAST(GREATEST(p_limit, 1), 100);
END$$;

GRANT SELECT ON mcp_mailboxes, mcp_folders, mcp_threads, mcp_messages, mcp_correspondents, mcp_identities, mcp_labels, mcp_message_labels, mcp_drafts,
    mcp_outbound, mcp_review_queue, mcp_mail_reviews, mcp_agent_mail_policies, mcp_dispatches, mcp_mailbox_permissions, mcp_mailbox_access_log,
    mcp_domains, mcp_aliases, mcp_settings, mcp_notifications
TO malumail_records_ro, malumail_rw;
-- the activity reader sees the trail only through the access log's view
GRANT SELECT ON mcp_mailbox_access_log TO malumail_activity_ro;
REVOKE ALL ON FUNCTION ml_correspondence_index(text, text, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_correspondence_index(text, text, integer) TO malumail_records_ro, malumail_rw;

COMMIT;
