-- 020: DELEGATION AND ACCESS (slice 7, 2026-10-17) — delegation-access.md. Two defects the slice found; neither touches the wall
-- (ml_mailbox_level() and db/017's set-based form answer exactly what they did).
--  1. ml_mailbox_settings_save() (db/012) refused `page_size`, a key the manifest's mail_mailbox_settings_save declares and the list
--     already reads (mail_page_size(): 25, 50 or 100), and validated the yes/no keys not at all. The allow-list gains page_size and
--     the two yes/no keys must be booleans. Every other rule — who may save, who may switch ai_read / share_with_apps — is unchanged.
--  2. mcp_mailbox_access_log named no consumer: the access-log screen shows "through what (web, MCP, K7, the consumer named)". The
--     view gains ONE appended column, `consumer`, read from the log row's own `after` (the application name a K7 read logs; NULL
--     otherwise). The view's filter — the owner, access.audit, and an agent's or shared mailbox's full holders — is untouched.
-- Additive: a function re-created with its signature, a view with a column appended last (grants kept).
BEGIN;

CREATE OR REPLACE FUNCTION ml_mailbox_settings_save(p_mailbox bigint, p_by bigint, p_settings jsonb, p_ai_read boolean DEFAULT NULL,
                                                    p_share boolean DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b mailboxes%ROWTYPE; k text; may_switch boolean;
BEGIN
    PERFORM ml_require_person(p_by);
    SELECT * INTO b FROM mailboxes WHERE id = p_mailbox;
    -- a person holding full saves any of it; a Mail admin, who holds no level on a shared mailbox, only its two switches
    IF NOT FOUND OR (ml_mailbox_level(p_mailbox, p_by) IS DISTINCT FROM 'full'
                     AND NOT (b.kind <> 'person' AND ml_member_has_right(p_by, 'mailboxes.manage')
                              AND COALESCE(p_settings, '{}'::jsonb) = '{}'::jsonb)) THEN
        RAISE EXCEPTION 'No such mailbox' USING ERRCODE = 'no_data_found';
    END IF;
    FOR k IN SELECT jsonb_object_keys(COALESCE(p_settings, '{}'::jsonb)) LOOP
        IF k NOT IN ('undo_seconds', 'conversation_view', 'images', 'density', 'shortcuts', 'page_size') THEN
            RAISE EXCEPTION 'A mailbox has no setting %', k USING ERRCODE = 'check_violation';
        END IF;
    END LOOP;
    IF p_settings ? 'undo_seconds' AND NOT (jsonb_typeof(p_settings->'undo_seconds') = 'number' AND (p_settings->>'undo_seconds') ~ '^\d{1,2}$'
                                            AND (p_settings->>'undo_seconds')::int BETWEEN 0 AND 30) THEN
        RAISE EXCEPTION 'Undo send lasts 0 to 30 seconds' USING ERRCODE = 'check_violation';
    END IF;
    IF p_settings ? 'images' AND p_settings->>'images' NOT IN ('ask', 'always') THEN
        RAISE EXCEPTION 'Images are ask or always' USING ERRCODE = 'check_violation';
    END IF;
    IF p_settings ? 'density' AND p_settings->>'density' NOT IN ('comfortable', 'compact') THEN
        RAISE EXCEPTION 'Density is comfortable or compact' USING ERRCODE = 'check_violation';
    END IF;
    IF p_settings ? 'page_size' AND NOT (jsonb_typeof(p_settings->'page_size') = 'number' AND (p_settings->>'page_size') IN ('25', '50', '100')) THEN
        RAISE EXCEPTION 'A page shows 25, 50 or 100 conversations' USING ERRCODE = 'check_violation';
    END IF;
    IF p_settings ? 'conversation_view' AND jsonb_typeof(p_settings->'conversation_view') <> 'boolean' THEN
        RAISE EXCEPTION 'Conversation view is on or off' USING ERRCODE = 'check_violation';
    END IF;
    IF p_settings ? 'shortcuts' AND jsonb_typeof(p_settings->'shortcuts') <> 'boolean' THEN
        RAISE EXCEPTION 'Keyboard shortcuts are on or off' USING ERRCODE = 'check_violation';
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

CREATE OR REPLACE VIEW mcp_mailbox_access_log WITH (security_barrier = true) AS
SELECT a.id, a.mailbox_id, a.occurred_at, a.actor_member_id, a.source, a.action, a.entity_type, a.entity_id, a.agent_run_id, a.token_id,
       a.after->>'consumer' AS consumer
  FROM activity_log a JOIN mailboxes b ON b.id = a.mailbox_id
 WHERE a.mailbox_id IS NOT NULL
   AND a.action IN ('mailbox.view', 'message.open', 'message.part', 'message.raw', 'mcp.read', 'k7.read', 'permission.break_glass')
   AND a.actor_member_id IS DISTINCT FROM b.owner_member_id
   AND (b.owner_member_id = app_current_member_id() OR (SELECT ml_has_right('access.audit'))
        OR (b.kind <> 'person' AND a.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('full'))::bigint[])));

COMMIT;
