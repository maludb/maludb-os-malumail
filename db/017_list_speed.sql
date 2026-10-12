-- 017: THE WALL'S AND THE LIST'S SPEED (slice 2, 2026-10-12) — found by slice 2's timing proof (mail-list.md Proof 9: "a list page of a
-- 5,000-conversation mailbox under 100 ms; the rail with 30 mailboxes under 100 ms"). Two defects, the same answers:
--
-- 1. db/013's ml_mail_list_ids() joined ml_thread_state() LATERALLY to EVERY conversation of a mailbox before it filtered and ordered — a
--    function call per conversation (4,500: 250–290 ms for one page, for the total, and for each of ml_mailbox_counts()'s six totals —
--    0.9 s). Now the two figures the list filters by (a snooze, the sensitive mark) are read once per call for the conversations that HAVE
--    a state, and joined; the keyset gets an index in its own order; the list's body is ml_mail_list_ids_core() — the view's filter with
--    the caller's facts as parameters — behind the same wall as before, so ml_unread_counts() can count every mailbox of the rail in one
--    statement without computing a level three times per mailbox; its plans are generic (a custom plan re-planned the view's query on
--    each of a rail's thirty calls).
-- 2. db/006's ml_my_mailbox_ids() — the set every mcp_* view's wall tests — called ml_level() once per mailbox of the ESTATE (1.6 ms each:
--    60 ms for 37 mailboxes, every page, growing with the estate). ml_levels_as(member, on_behalf) computes the same levels for every
--    mailbox in ONE statement (db/006's ml_mailbox_level() rules and db/012's ml_level_as() rule for an agent in a person's run, written
--    set-wise); ml_my_mailbox_ids() and the new ml_my_levels() read it. db/proof/slice2_wall_speed.sql proves ml_levels_as() equal to
--    ml_level_as() for every member, every person an agent may act for and every mailbox, through each rule's cases.
-- Additive: functions replaced with the same signatures and answers, functions and an index added.
BEGIN;

CREATE INDEX IF NOT EXISTS mail_threads_keyset_idx ON mail_threads (mailbox_id, last_message_at DESC, id DESC);

-- ------------------------------------------------------------------------------------------------- the wall, set-wise
-- A member's own level on every mailbox (db/006 ml_mailbox_level(), for all mailboxes at once).
CREATE OR REPLACE FUNCTION ml_base_levels(p_member bigint) RETURNS TABLE (mailbox_id bigint, rk integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH me AS (SELECT m.id, m.member_kind, ml_member_has_right(m.id, 'mail.use') AS uses,
                       ml_member_has_right(m.id, 'mailboxes.manage') AS admin
                  FROM members m WHERE m.id = p_member),
         rows AS (SELECT p.mailbox_id,
                         max(CASE WHEN p.principal_kind = 'agent' AND b.kind = 'person' THEN LEAST(ml_level_rank(p.level), 3)
                                  ELSE ml_level_rank(p.level) END) AS rk
                    FROM mailbox_permissions p
                    JOIN mailboxes b ON b.id = p.mailbox_id
                    JOIN me ON true
                   WHERE (p.expires_at IS NULL OR p.expires_at > now())
                     AND ((p.principal_kind = 'member' AND p.principal_id = me.id AND me.member_kind = 'human')
                       OR (p.principal_kind = 'agent' AND p.principal_id = me.id AND me.member_kind = 'agent')
                       OR (p.principal_kind = 'department' AND me.member_kind = 'human'
                           AND EXISTS (SELECT 1 FROM department_members dm JOIN departments d ON d.id = dm.department_id AND d.archived_at IS NULL
                                        WHERE dm.department_id = p.principal_id AND dm.member_id = me.id AND dm.left_at IS NULL)))
                   GROUP BY p.mailbox_id)
    SELECT b.id,
           CASE WHEN NOT COALESCE((SELECT me.uses FROM me), false) THEN 0
                ELSE GREATEST(CASE WHEN b.owner_member_id = p_member THEN 4 ELSE 0 END,
                              CASE WHEN b.kind = 'agent' AND COALESCE((SELECT me.admin FROM me), false) THEN 4 ELSE 0 END,
                              COALESCE(r.rk, 0)) END
      FROM mailboxes b LEFT JOIN rows r ON r.mailbox_id = b.id;
$$;

-- ml_level_as(mailbox, member, on_behalf) for every mailbox (db/012's rule: the Mail Expert reads at the person's level capped at triage
-- where ai_read is on — on a person's mailbox only its owner's; any other agent working for a person, what both may open).
CREATE OR REPLACE FUNCTION ml_levels_as(p_member bigint, p_on_behalf bigint) RETURNS TABLE (mailbox_id bigint, level text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
#variable_conflict use_column
DECLARE is_agent boolean; is_expert boolean;
BEGIN
    IF p_on_behalf IS NULL OR p_on_behalf IS NOT DISTINCT FROM p_member THEN
        RETURN QUERY SELECT x.mailbox_id, ml_level_name(x.rk) FROM ml_base_levels(p_member) x;
        RETURN;
    END IF;
    SELECT m.member_kind = 'agent' INTO is_agent FROM members m WHERE m.id = p_member;
    IF NOT COALESCE(is_agent, false)
       OR NOT EXISTS (SELECT 1 FROM members m WHERE m.id = p_on_behalf AND m.member_kind = 'human' AND m.status = 'active') THEN
        RETURN QUERY SELECT x.mailbox_id, ml_level_name(x.rk) FROM ml_base_levels(p_member) x;
        RETURN;
    END IF;
    is_expert := p_member = (SELECT s.expert_member_id FROM ml_settings s WHERE s.id = 1);
    IF is_expert THEN
        RETURN QUERY
        SELECT a.mailbox_id,
               ml_level_name(GREATEST(a.rk, CASE WHEN b.ai_read AND (b.kind <> 'person' OR b.owner_member_id = p_on_behalf) THEN LEAST(o.rk, 2) ELSE 0 END))
          FROM ml_base_levels(p_member) a JOIN ml_base_levels(p_on_behalf) o ON o.mailbox_id = a.mailbox_id JOIN mailboxes b ON b.id = a.mailbox_id;
        RETURN;
    END IF;
    RETURN QUERY
    SELECT a.mailbox_id, ml_level_name(LEAST(a.rk, o.rk))
      FROM ml_base_levels(p_member) a JOIN ml_base_levels(p_on_behalf) o ON o.mailbox_id = a.mailbox_id;
END$$;

-- The caller's level on every mailbox (app.member_id, app.on_behalf_of) — ml_level() for all of them at once.
CREATE OR REPLACE FUNCTION ml_my_levels() RETURNS TABLE (mailbox_id bigint, level text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT * FROM ml_levels_as(app_current_member_id(), NULLIF(current_setting('app.on_behalf_of', true), '')::bigint);
$$;

-- The mailboxes the caller reaches at a level or above — ONE set per statement, now ONE query.
CREATE OR REPLACE FUNCTION ml_my_mailbox_ids(p_min text DEFAULT 'view') RETURNS bigint[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE ids bigint[];
BEGIN
    SELECT COALESCE(array_agg(l.mailbox_id ORDER BY l.mailbox_id), '{}') INTO ids FROM ml_my_levels() l
     WHERE ml_level_rank(l.level) >= ml_level_rank(p_min);
    RETURN ids;
END$$;

-- ------------------------------------------------------------------------------------------------- the list
CREATE OR REPLACE FUNCTION ml_mail_list_ids_core(p_mailbox bigint, p_view text, p_folder bigint, p_label bigint, p_unread boolean,
                                                 p_before_at timestamptz, p_before_id bigint, p_limit integer,
                                                 p_viewer boolean, p_member bigint, p_human boolean)
    RETURNS TABLE (row_kind text, row_id bigint, row_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public SET plan_cache_mode = force_generic_plan ROWS 50 AS $$
#variable_conflict use_column
DECLARE me bigint := p_member; human boolean := p_human; viewer boolean := p_viewer; v text := COALESCE(p_view, CASE WHEN p_folder IS NOT NULL THEN 'folder'
        WHEN p_label IS NOT NULL THEN 'label' END);
        f_inbox bigint; f_sent bigint; f_drafts bigint; f_junk bigint; f_trash bigint;
BEGIN
    IF v IS NULL OR v NOT IN ('inbox', 'starred', 'snoozed', 'sent', 'drafts', 'scheduled', 'outbox', 'all', 'spam', 'trash', 'folder', 'label') THEN
        RAISE EXCEPTION 'No such view' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF v = 'folder' AND NOT EXISTS (SELECT 1 FROM mail_folders WHERE id = p_folder AND mailbox_id = p_mailbox) THEN RETURN; END IF;
    IF v = 'label' AND NOT EXISTS (SELECT 1 FROM tags WHERE id = p_label AND (mailbox_id = p_mailbox OR mailbox_id IS NULL)) THEN RETURN; END IF;
    p_limit := LEAST(GREATEST(COALESCE(p_limit, 50), 1), 10001);
    IF v IN ('scheduled', 'outbox') THEN
        RETURN QUERY
        SELECT 'outbound'::text, o.id, COALESCE(o.send_after, o.created_at)
          FROM outbound_email o
         WHERE o.mailbox_id = p_mailbox
           AND ((v = 'scheduled' AND o.status = 'queued' AND o.send_after > now() + interval '30 seconds')
             OR (v = 'outbox' AND o.status IN ('sending', 'deferred', 'failed', 'held', 'refused')))
           AND (p_before_at IS NULL OR (COALESCE(o.send_after, o.created_at), o.id) < (p_before_at, COALESCE(p_before_id, 9223372036854775807)))
         ORDER BY COALESCE(o.send_after, o.created_at) DESC, o.id DESC
         LIMIT p_limit;
        RETURN;
    END IF;
    SELECT max(f.id) FILTER (WHERE f.special_use = 'inbox'), max(f.id) FILTER (WHERE f.special_use = 'sent'),
           max(f.id) FILTER (WHERE f.special_use = 'drafts'), max(f.id) FILTER (WHERE f.special_use = 'junk'),
           max(f.id) FILTER (WHERE f.special_use = 'trash')
      INTO f_inbox, f_sent, f_drafts, f_junk, f_trash
      FROM mail_folders f WHERE f.mailbox_id = p_mailbox;
    RETURN QUERY
    WITH st AS (                       -- ml_thread_state()'s two figures the list filters by, for the conversations that HAVE a state (few)
        SELECT m.thread_id, max(s.snoozed_until) AS snoozed_until, COALESCE(bool_or(s.sensitive), false) AS sensitive
          FROM mail_thread_states s JOIN mail_messages m ON m.message_key_id = s.anchor_key_id AND m.thread_id IS NOT NULL
         WHERE s.mailbox_id = p_mailbox
         GROUP BY m.thread_id)
    SELECT 'thread'::text, t.id, t.last_message_at
      FROM mail_threads t
      LEFT JOIN st ON st.thread_id = t.id
     WHERE t.mailbox_id = p_mailbox
       AND (human OR NOT COALESCE(st.sensitive, false))
       AND (p_before_at IS NULL OR (t.last_message_at, t.id) < (p_before_at, COALESCE(p_before_id, 9223372036854775807)))
       AND CASE v
             WHEN 'inbox'   THEN f_inbox = ANY (t.folder_ids) AND st.snoozed_until IS NULL
             WHEN 'starred' THEN t.flagged
             WHEN 'snoozed' THEN st.snoozed_until IS NOT NULL
             WHEN 'sent'    THEN f_sent = ANY (t.folder_ids)
             WHEN 'drafts'  THEN f_drafts = ANY (t.folder_ids)
             WHEN 'spam'    THEN f_junk = ANY (t.folder_ids)
             WHEN 'trash'   THEN f_trash = ANY (t.folder_ids)
             WHEN 'all'     THEN EXISTS (SELECT 1 FROM unnest(t.folder_ids) x WHERE x <> COALESCE(f_trash, -1) AND x <> COALESCE(f_junk, -1))
             WHEN 'folder'  THEN p_folder = ANY (t.folder_ids)
             WHEN 'label'   THEN EXISTS (SELECT 1 FROM mail_messages m JOIN record_tags rt ON rt.record_type = 'mail_message' AND rt.record_id = m.message_key_id
                                          WHERE m.thread_id = t.id AND rt.tag_id = p_label)
           END
       AND (NOT COALESCE(p_unread, false) OR
            CASE WHEN viewer THEN EXISTS (SELECT 1 FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
                                            WHERE m.thread_id = t.id AND NOT m.seen AND COALESCE(f.special_use, '') NOT IN ('trash', 'junk', 'sent', 'drafts')
                                              AND NOT EXISTS (SELECT 1 FROM mail_viewer_reads r WHERE r.member_id = me AND r.message_key_id = m.message_key_id))
                 ELSE t.unread_count > 0 END)
     ORDER BY t.last_message_at DESC, t.id DESC
     LIMIT p_limit;
END$$;

CREATE OR REPLACE FUNCTION ml_mail_list_ids(p_mailbox bigint, p_view text, p_folder bigint, p_label bigint, p_unread boolean,
                                            p_before_at timestamptz, p_before_id bigint, p_limit integer)
    RETURNS TABLE (row_kind text, row_id bigint, row_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public ROWS 50 AS $$
DECLARE me bigint := app_current_member_id(); behalf bigint := NULLIF(current_setting('app.on_behalf_of', true), '')::bigint;
BEGIN
    IF ml_level_rank(ml_level(p_mailbox)) = 0 THEN RETURN; END IF;
    RETURN QUERY SELECT * FROM ml_mail_list_ids_core(p_mailbox, p_view, p_folder, p_label, p_unread, p_before_at, p_before_id, p_limit,
                                                     ml_read_mode(p_mailbox, me, behalf) = 'viewer_read', me, app_member_kind() = 'human');
END$$;

-- The rail's figures for every mailbox the caller opens, in one statement: the caller's level and the Inbox's unread conversations for
-- them (ml_unread_count()'s answer — the server's flag, or their own marks where ml_read_mode() says so: a view holder, an intake
-- mailbox, a suspended one).
CREATE OR REPLACE FUNCTION ml_unread_counts() RETURNS TABLE (mailbox_id bigint, level text, unread integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.mailbox_id, l.level,
           (SELECT count(*)::integer FROM ml_mail_list_ids_core(l.mailbox_id, 'inbox', NULL, NULL, true, NULL, NULL, 10001,
                       ml_level_rank(l.level) = 1 OR b.intake_owner <> 'none' OR b.suspended_at IS NOT NULL,
                       app_current_member_id(), app_member_kind() = 'human'))
      FROM ml_my_levels() l JOIN mailboxes b ON b.id = l.mailbox_id
     WHERE ml_level_rank(l.level) > 0;
$$;

REVOKE ALL ON FUNCTION ml_base_levels(bigint), ml_levels_as(bigint, bigint), ml_my_levels(), ml_unread_counts(),
    ml_mail_list_ids_core(bigint, text, bigint, bigint, boolean, timestamptz, bigint, integer, boolean, bigint, boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_base_levels(bigint), ml_levels_as(bigint, bigint), ml_my_levels() TO malumail_rw, malumail_records_ro, malumail_activity_ro;
GRANT EXECUTE ON FUNCTION ml_unread_counts() TO malumail_rw, malumail_records_ro;

COMMIT;
