-- 012: PHASE 1's FIXES (2026-10-11) — what the tool surface, the action manifest and the specs found the schema lacking
-- (docs/phase1-consistency.md lists each with the spec that asked). Additive: functions replaced in place, views and functions added.
BEGIN;

-- ------------------------------------------------------------------------------------------------- the wall: an agent for a person
-- Design §8: an agent answering a person reads only what BOTH may open — except §3's one rule for the NAMED Mail Expert (the person's
-- level capped at triage where ai_read is on). db/006 gave any other agent its own level in a person's run; now it takes the lesser.
CREATE OR REPLACE FUNCTION ml_level_as(p_mailbox bigint, p_member bigint, p_on_behalf bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE base integer; via integer := 0; b mailboxes%ROWTYPE; is_agent boolean; is_expert boolean;
BEGIN
    base := ml_level_rank(ml_mailbox_level(p_mailbox, p_member));
    IF p_on_behalf IS NULL OR p_on_behalf IS NOT DISTINCT FROM p_member THEN
        RETURN ml_level_name(base);
    END IF;
    SELECT m.member_kind = 'agent' INTO is_agent FROM members m WHERE m.id = p_member;
    IF NOT COALESCE(is_agent, false)
       OR NOT EXISTS (SELECT 1 FROM members m WHERE m.id = p_on_behalf AND m.member_kind = 'human' AND m.status = 'active') THEN
        RETURN ml_level_name(base);
    END IF;
    is_expert := p_member = (SELECT s.expert_member_id FROM ml_settings s WHERE s.id = 1);
    IF is_expert THEN
        SELECT * INTO b FROM mailboxes WHERE id = p_mailbox;
        IF FOUND AND b.ai_read AND (b.kind <> 'person' OR b.owner_member_id = p_on_behalf) THEN
            via := LEAST(ml_level_rank(ml_mailbox_level(p_mailbox, p_on_behalf)), 2);
        END IF;
        RETURN ml_level_name(GREATEST(base, via));
    END IF;
    -- any other agent working for a person: what both may open
    RETURN ml_level_name(LEAST(base, ml_level_rank(ml_mailbox_level(p_mailbox, p_on_behalf))));
END$$;

-- ------------------------------------------------------------------------------------------------- the resolvers' views
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, CASE m.member_kind WHEN 'agent' THEN 'agent' ELSE 'person' END AS kind,
       CASE WHEN m.member_kind = 'human' THEN m.email END AS email, m.status
  FROM members m
 WHERE m.status = 'active' AND (SELECT ml_is_member_here());
CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name
  FROM departments d
 WHERE d.archived_at IS NULL AND (SELECT ml_is_member_here());
-- canned replies: the caller's own, and the shared ones (every mailbox's, or one the caller may open at send)
CREATE OR REPLACE VIEW mcp_templates WITH (security_barrier = true) AS
SELECT t.id AS template_id, t.name, t.subject, t.body, t.is_shared AS shared, t.mailbox_id, t.owner_member_id, t.usage_count, t.updated_at
  FROM email_templates t
 WHERE t.archived_at IS NULL
   AND (t.owner_member_id = app_current_member_id()
        OR (t.is_shared AND (t.mailbox_id IS NULL OR t.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('send'))::bigint[]))));

-- ------------------------------------------------------------------------------------------------- the records MCP's one write
-- A records-MCP read of a mailbox that is not the caller's own is in the access log (design §3) — once per tool call per mailbox.
CREATE OR REPLACE FUNCTION ml_log_mcp_read(p_mailbox bigint, p_tool text, p_run bigint DEFAULT NULL, p_token bigint DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE who bigint := app_current_member_id();
BEGIN
    IF who IS NULL OR p_mailbox IS NULL OR p_tool !~ '^[a-z_]{1,60}$' THEN RETURN; END IF;
    IF NOT ml_access_should_log(p_mailbox, who, 'mcp.read') THEN RETURN; END IF;
    INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, mailbox_id, agent_run_id, token_id, after)
    VALUES (who, 'mcp', 'mcp.read', 'mailbox', p_mailbox, p_mailbox, p_run, p_token,
            jsonb_build_object('application', 'malumail', 'tool', p_tool,
                               'on_behalf_of', NULLIF(current_setting('app.on_behalf_of', true), '')::bigint));
END$$;

-- ------------------------------------------------------------------------------------------------- administration (slice 1)
CREATE OR REPLACE FUNCTION ml_domain_serve(p_by bigint, p_domain citext, p_served boolean, p_internal boolean DEFAULT NULL,
                                           p_force boolean DEFAULT false) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE did bigint;
BEGIN
    PERFORM ml_require_right(p_by, 'domains.manage');
    IF NOT p_served AND NOT p_force AND EXISTS (
        SELECT 1 FROM mailboxes b JOIN mailbox_credentials c ON c.id = b.credential_id
         WHERE ml_address_domain(b.address) = lower(p_domain) AND c.status = 'ok') THEN
        RAISE EXCEPTION 'Mailboxes on % still hold working credentials: suspend them first, or confirm to stop serving it anyway', p_domain
            USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO ml_domains (domain, served, internal, created_by)
    VALUES (lower(p_domain), p_served, COALESCE(p_internal, p_served) AND p_served, p_by)
    ON CONFLICT (domain) DO UPDATE SET served = EXCLUDED.served,
        internal = CASE WHEN NOT EXCLUDED.served THEN false ELSE COALESCE(p_internal, ml_domains.internal) END
    RETURNING id INTO did;
    RETURN did;
END$$;

-- Share with applications (the K7 share): a person's mailbox only by its OWNER; an agent's or shared one by a decider or a Mail admin.
CREATE OR REPLACE FUNCTION ml_mailbox_share_set(p_mailbox bigint, p_by bigint, p_on boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b mailboxes%ROWTYPE;
BEGIN
    PERFORM ml_require_person(p_by);
    SELECT * INTO b FROM mailboxes WHERE id = p_mailbox;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such mailbox' USING ERRCODE = 'no_data_found'; END IF;
    IF NOT ((b.kind = 'person' AND b.owner_member_id = p_by)
            OR (b.kind <> 'person' AND (ml_is_decider(p_mailbox, p_by) OR ml_member_has_right(p_by, 'mailboxes.manage')))) THEN
        RAISE EXCEPTION 'Only its owner shares a person''s mailbox with applications' USING ERRCODE = 'insufficient_privilege';
    END IF;
    UPDATE mailboxes SET share_with_apps = p_on WHERE id = p_mailbox;
END$$;

CREATE OR REPLACE FUNCTION ml_mailbox_display_name_set(p_mailbox bigint, p_by bigint, p_name text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_right(p_by, 'mailboxes.manage');
    IF btrim(COALESCE(p_name, '')) = '' THEN RAISE EXCEPTION 'A mailbox has a display name' USING ERRCODE = 'check_violation'; END IF;
    UPDATE mailboxes SET display_name = btrim(p_name) WHERE id = p_mailbox;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such mailbox' USING ERRCODE = 'no_data_found'; END IF;
END$$;

-- ------------------------------------------------------------------------------------------------- a mailbox's settings (slice 7)
-- ai_read and share_with_apps on a person's mailbox: its OWNER alone; on an agent's or shared one: a decider or a Mail admin. The
-- other keys (an allow-list) by a person holding full.
CREATE OR REPLACE FUNCTION ml_mailbox_settings_save(p_mailbox bigint, p_by bigint, p_settings jsonb, p_ai_read boolean DEFAULT NULL,
                                                    p_share boolean DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b mailboxes%ROWTYPE; k text; clean jsonb := '{}'::jsonb; may_switch boolean;
BEGIN
    PERFORM ml_require_person(p_by);
    SELECT * INTO b FROM mailboxes WHERE id = p_mailbox;
    IF NOT FOUND OR ml_mailbox_level(p_mailbox, p_by) IS DISTINCT FROM 'full' THEN
        RAISE EXCEPTION 'No such mailbox' USING ERRCODE = 'no_data_found';
    END IF;
    FOR k IN SELECT jsonb_object_keys(COALESCE(p_settings, '{}'::jsonb)) LOOP
        IF k NOT IN ('undo_seconds', 'conversation_view', 'images', 'density', 'shortcuts') THEN
            RAISE EXCEPTION 'A mailbox has no setting %', k USING ERRCODE = 'check_violation';
        END IF;
    END LOOP;
    IF p_settings ? 'undo_seconds' AND NOT ((p_settings->>'undo_seconds') ~ '^\d{1,2}$' AND (p_settings->>'undo_seconds')::int BETWEEN 0 AND 30) THEN
        RAISE EXCEPTION 'Undo send lasts 0 to 30 seconds' USING ERRCODE = 'check_violation';
    END IF;
    IF p_settings ? 'images' AND p_settings->>'images' NOT IN ('ask', 'always') THEN
        RAISE EXCEPTION 'Images are ask or always' USING ERRCODE = 'check_violation';
    END IF;
    IF p_settings ? 'density' AND p_settings->>'density' NOT IN ('comfortable', 'compact') THEN
        RAISE EXCEPTION 'Density is comfortable or compact' USING ERRCODE = 'check_violation';
    END IF;
    may_switch := (b.kind = 'person' AND b.owner_member_id = p_by)
               OR (b.kind <> 'person' AND (ml_is_decider(p_mailbox, p_by) OR ml_member_has_right(p_by, 'mailboxes.manage')));
    IF (p_ai_read IS NOT NULL OR p_share IS NOT NULL) AND NOT may_switch THEN
        RAISE EXCEPTION 'Only its owner decides whether the Mail Expert or other applications may read a person''s mailbox'
            USING ERRCODE = 'insufficient_privilege';
    END IF;
    UPDATE mailboxes SET settings = settings || COALESCE(p_settings, '{}'::jsonb),
                         ai_read = COALESCE(p_ai_read, ai_read),
                         share_with_apps = COALESCE(p_share, share_with_apps)
     WHERE id = p_mailbox;
END$$;

-- ------------------------------------------------------------------------------------------------- expiring rows are logged
CREATE OR REPLACE FUNCTION ml_permissions_expire() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    WITH gone AS (
        DELETE FROM mailbox_permissions WHERE expires_at IS NOT NULL AND expires_at <= now()
        RETURNING id, mailbox_id, principal_kind, principal_id, level, break_glass
    )
    INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, mailbox_id, after)
    SELECT NULL, 'system', 'permission.expire', 'mailbox_permission', g.id, g.mailbox_id,
           jsonb_build_object('application', 'malumail', 'principal_kind', g.principal_kind, 'principal_id', g.principal_id,
                              'level', g.level, 'break_glass', g.break_glass)
      FROM gone g;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- ------------------------------------------------------------------------------------------------- shared widenings (slices 5, 6, 9)
-- Done here once so db/013–015 never replace each other's CHECKs: a mail turn may now carry a reviewer's note back to the agent
-- (`review`, sending-and-oversight.md) or a snoozed conversation waking on an agent's mailbox (`snooze`, search-labels-snooze.md);
-- the Clerk's morning note is a notice of its own kind (`clerk_note`, notifications.md). A snooze's return reuses `snooze_woke`.
ALTER TABLE agent_dispatches DROP CONSTRAINT agent_dispatches_kind_check;
ALTER TABLE agent_dispatches ADD CONSTRAINT agent_dispatches_kind_check
    CHECK (kind IN ('mention', 'dm', 'duty_proposal', 'ask', 'mail', 'release', 'review', 'snooze'));
ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check;
ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check
    CHECK (kind IN ('new_mail', 'held_mail', 'review_decided', 'unscreened', 'break_glass', 'permission_granted', 'credential_failed',
                    'sync_failed', 'quota', 'snooze_woke', 'send_failed', 'send_deferred', 'agent_replied', 'digest', 'clerk_note'));

-- ------------------------------------------------------------------------------------------------- grants
GRANT SELECT ON mcp_members, mcp_departments, mcp_templates TO malumail_records_ro, malumail_rw;
REVOKE ALL ON FUNCTION ml_log_mcp_read(bigint, text, bigint, bigint), ml_domain_serve(bigint, citext, boolean, boolean, boolean),
    ml_mailbox_share_set(bigint, bigint, boolean), ml_mailbox_display_name_set(bigint, bigint, text),
    ml_mailbox_settings_save(bigint, bigint, jsonb, boolean, boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_log_mcp_read(bigint, text, bigint, bigint) TO malumail_records_ro, malumail_rw;
GRANT EXECUTE ON FUNCTION ml_domain_serve(bigint, citext, boolean, boolean, boolean), ml_mailbox_share_set(bigint, bigint, boolean),
    ml_mailbox_display_name_set(bigint, bigint, text), ml_mailbox_settings_save(bigint, bigint, jsonb, boolean, boolean)
TO malumail_rw;

COMMIT;
