-- Slice 2's proof of db/017: the wall computed set-wise (ml_levels_as, ml_my_levels, ml_my_mailbox_ids) gives EXACTLY db/006/012's
-- per-mailbox answer (ml_level_as) for every member, every person an agent may act for and every mailbox, through each rule's cases; the
-- list's core gives db/013's rows (the lateral ml_thread_state() reference written out here) for a person and an agent; ml_unread_counts()
-- gives ml_unread_count() for every mailbox. Phase 0's style: ONE transaction, ROLLED BACK.
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d ml_proof -f db/proof/slice2_wall_speed.sql; echo "exit $?"
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

-- ================================================================ fixtures: every rule of the wall
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Ada (super-admin)', 'ada@s2.example', 'super_admin', false, 'active', 'admin', '{admin,user}'),
 (2,  'human', 'SMOKE Pat', 'pat@s2.example', 'user', false, 'active', 'write', '{user}'),
 (3,  'human', 'SMOKE Kim', 'kim@s2.example', 'user', false, 'active', 'write', '{user}'),
 (4,  'human', 'SMOKE Lou (no role)', 'lou@s2.example', 'user', false, 'active', 'read', '{}'),
 (5,  'human', 'SMOKE Old (inactive)', 'old@s2.example', 'user', false, 'inactive', 'write', '{user}'),
 (6,  'human', 'SMOKE Viv', 'viv@s2.example', 'user', false, 'active', 'write', '{user}'),
 (40, 'agent', 'SMOKE Expert', NULL, 'user', false, 'active', 'write', '{user}'),
 (41, 'agent', 'SMOKE Helper', NULL, 'user', false, 'active', 'write', '{user}'),
 (42, 'agent', 'SMOKE Admin agent', NULL, 'user', false, 'active', 'admin', '{admin,user}');
INSERT INTO departments (id, name) VALUES (7, 'Sales'), (9, 'Gone');
INSERT INTO department_members (member_id, department_id, left_at) VALUES (2, 7, NULL), (3, 7, now() - interval '1 day'), (41, 7, NULL), (6, 9, NULL);
UPDATE ml_settings SET expert_member_id = 40 WHERE id = 1;
INSERT INTO ml_domains (domain, served, internal) VALUES ('s2.example', true, true);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id, ai_read) VALUES
 ('pat@s2.example', 'Pat', 'person', 2, false), ('kim@s2.example', 'Kim', 'person', 3, true), ('viv@s2.example', 'Viv', 'person', 6, true),
 ('sales@s2.example', 'Sales', 'shared', NULL, true), ('quiet@s2.example', 'Quiet', 'shared', NULL, false),
 ('helper@s2.example', 'Helper', 'agent', 41, true), ('expert@s2.example', 'Expert', 'agent', 40, true), ('none@s2.example', 'Nobody', 'shared', NULL, true);
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by, expires_at, reason, break_glass)
SELECT b.id, v.k, v.p, v.l, CASE WHEN b.kind = 'person' THEN b.owner_member_id ELSE 1 END, v.x, v.r, v.bg FROM (VALUES
  ('sales', 'department', 7, 'view', NULL::timestamptz, NULL, false),        -- a department row: its active people, never its agent
  ('sales', 'member', 3, 'triage', NULL, NULL, false),                        -- Kim left the department; her own row stays
  ('sales', 'agent', 41, 'send', NULL, NULL, false),                          -- an agent row on a shared mailbox
  ('kim', 'member', 2, 'view', NULL, NULL, false),
  ('quiet', 'department', 9, 'full', NULL, NULL, false),                      -- an archived department: nothing
  ('quiet', 'member', 4, 'full', NULL, NULL, false),                          -- a row for someone without a role: nothing
  ('quiet', 'member', 5, 'full', NULL, NULL, false),                          -- an inactive member: nothing
  ('viv', 'member', 2, 'triage', NULL, NULL, false),
  ('helper', 'member', 2, 'full', NULL, NULL, false)) v(a, k, p, l, x, r, bg)
  JOIN mailboxes b ON b.address = v.a || '@s2.example';
-- an expired break-glass row: nothing (time has passed — written past the guard, which refuses an expiry already gone)
SET LOCAL session_replication_role = replica;
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by, expires_at, reason, break_glass)
SELECT id, 'member', 6, 'full', 1, now() - interval '1 hour', 'An investigation, now over', true FROM mailboxes WHERE address = 'pat@s2.example';
-- an agent row at full on a person's mailbox (the guard refuses one now; the level caps one at send whatever wrote it)
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT id, 'agent', 41, 'full', 1 FROM mailboxes WHERE address = 'pat@s2.example';
SET LOCAL session_replication_role = origin;
UPDATE departments SET archived_at = now() WHERE id = 9;              -- archived after its row was granted

-- ================================================================ ml_levels_as() = ml_level_as() for every member × person × mailbox
CREATE TEMP TABLE pairs AS
SELECT m.id AS member, ob.id AS behalf FROM members m CROSS JOIN (SELECT NULL::bigint AS id UNION ALL SELECT id FROM members WHERE member_kind = 'human'
                                                                   UNION ALL SELECT 999) ob;
CREATE TEMP TABLE cmp AS
SELECT p.member, p.behalf, b.id AS mailbox, ml_level_as(b.id, p.member, p.behalf) AS one_by_one,
       (SELECT l.level FROM ml_levels_as(p.member, p.behalf) l WHERE l.mailbox_id = b.id) AS set_wise
  FROM pairs p CROSS JOIN mailboxes b;
SELECT pg_temp.check((SELECT count(*) FROM cmp WHERE one_by_one IS DISTINCT FROM set_wise) = 0 AND (SELECT count(*) FROM cmp) > 500,
                     'ml_levels_as() equals ml_level_as() for every member, every person an agent may act for and every mailbox ('
                     || (SELECT count(*) FROM cmp) || ' cases)');
-- each rule reached at least once (the fixture covers them)
SELECT pg_temp.check((SELECT one_by_one FROM cmp WHERE member = 2 AND behalf IS NULL AND mailbox = (SELECT id FROM mailboxes WHERE address = 'pat@s2.example')) = 'full', 'the owner: full');
SELECT pg_temp.check((SELECT one_by_one FROM cmp WHERE member = 2 AND behalf IS NULL AND mailbox = (SELECT id FROM mailboxes WHERE address = 'sales@s2.example')) = 'view', 'a department row: its person');
SELECT pg_temp.check((SELECT one_by_one FROM cmp WHERE member = 41 AND behalf IS NULL AND mailbox = (SELECT id FROM mailboxes WHERE address = 'sales@s2.example')) = 'send', '…never its agent (the agent''s own row instead)');
SELECT pg_temp.check((SELECT one_by_one FROM cmp WHERE member = 3 AND behalf IS NULL AND mailbox = (SELECT id FROM mailboxes WHERE address = 'sales@s2.example')) = 'triage', 'a person who left the department keeps only her own row');
SELECT pg_temp.check((SELECT one_by_one FROM cmp WHERE member = 41 AND behalf IS NULL AND mailbox = (SELECT id FROM mailboxes WHERE address = 'pat@s2.example')) = 'send', 'an agent row on a person''s mailbox: at most send');
SELECT pg_temp.check((SELECT one_by_one FROM cmp WHERE member = 6 AND behalf IS NULL AND mailbox = (SELECT id FROM mailboxes WHERE address = 'pat@s2.example')) = 'none', 'an expired row: nothing');
SELECT pg_temp.check((SELECT count(*) FROM cmp WHERE behalf IS NULL AND member IN (4, 5, 6) AND mailbox = (SELECT id FROM mailboxes WHERE address = 'quiet@s2.example') AND one_by_one <> 'none') = 0,
                     'no role, inactive, an archived department: nothing');
SELECT pg_temp.check((SELECT one_by_one FROM cmp WHERE member = 1 AND behalf IS NULL AND mailbox = (SELECT id FROM mailboxes WHERE address = 'expert@s2.example')) = 'full'
                     AND (SELECT one_by_one FROM cmp WHERE member = 42 AND behalf IS NULL AND mailbox = (SELECT id FROM mailboxes WHERE address = 'expert@s2.example')) = 'none'
                     AND (SELECT one_by_one FROM cmp WHERE member = 1 AND behalf IS NULL AND mailbox = (SELECT id FROM mailboxes WHERE address = 'helper@s2.example')) = 'full'
                     AND (SELECT one_by_one FROM cmp WHERE member = 1 AND behalf IS NULL AND mailbox = (SELECT id FROM mailboxes WHERE address = 'pat@s2.example')) = 'none',
                     'a Mail admin: every agent''s mailbox at full, a person''s not at all; an agent holding the admin role administers nothing');
SELECT pg_temp.check((SELECT one_by_one FROM cmp WHERE member = 40 AND behalf = 3 AND mailbox = (SELECT id FROM mailboxes WHERE address = 'kim@s2.example')) = 'triage'
                     AND (SELECT one_by_one FROM cmp WHERE member = 40 AND behalf = 2 AND mailbox = (SELECT id FROM mailboxes WHERE address = 'pat@s2.example')) = 'none'
                     AND (SELECT one_by_one FROM cmp WHERE member = 40 AND behalf = 2 AND mailbox = (SELECT id FROM mailboxes WHERE address = 'kim@s2.example')) = 'none',
                     'the Expert: the person''s level capped at triage where ai_read is on, on a person''s mailbox only its owner''s');
SELECT pg_temp.check((SELECT one_by_one FROM cmp WHERE member = 41 AND behalf = 2 AND mailbox = (SELECT id FROM mailboxes WHERE address = 'sales@s2.example')) = 'view'
                     AND (SELECT one_by_one FROM cmp WHERE member = 41 AND behalf = 6 AND mailbox = (SELECT id FROM mailboxes WHERE address = 'pat@s2.example')) = 'none',
                     'any other agent for a person: what both may open');

-- ml_my_mailbox_ids() (now over ml_my_levels()) = db/006's definition for every caller
CREATE TEMP TABLE ids_cmp (member bigint, behalf bigint, lvl text, new_ids bigint[], old_ids bigint[]);
DO $$
DECLARE p record; lv text;
BEGIN
    FOR p IN SELECT * FROM pairs LOOP
        PERFORM set_config('app.member_id', COALESCE(p.member::text, ''), true);
        PERFORM set_config('app.on_behalf_of', COALESCE(p.behalf::text, ''), true);
        FOREACH lv IN ARRAY ARRAY['view', 'triage', 'send', 'full'] LOOP
            INSERT INTO ids_cmp VALUES (p.member, p.behalf, lv, ml_my_mailbox_ids(lv),
                (SELECT COALESCE(array_agg(b.id ORDER BY b.id), '{}') FROM mailboxes b WHERE ml_level_rank(ml_level(b.id)) >= ml_level_rank(lv)));
        END LOOP;
    END LOOP;
    PERFORM set_config('app.member_id', '', true);
    PERFORM set_config('app.on_behalf_of', '', true);
END$$;
SELECT pg_temp.check((SELECT count(*) FROM ids_cmp WHERE new_ids IS DISTINCT FROM old_ids) = 0,
                     'ml_my_mailbox_ids() equals db/006''s definition for every caller and level (' || (SELECT count(*) FROM ids_cmp) || ' cases)');

-- ================================================================ the list's core = db/013's rows (the lateral reference)
INSERT INTO mail_folders (mailbox_id, name, special_use) SELECT id, f.n, f.s FROM mailboxes, (VALUES ('INBOX', 'inbox'), ('Sent', 'sent'), ('Junk', 'junk'), ('Trash', 'trash')) f(n, s)
 WHERE address IN ('sales@s2.example', 'kim@s2.example');
CREATE TEMP TABLE msgs AS
SELECT b.id AS mb, g, f.id AS folder FROM mailboxes b CROSS JOIN generate_series(1, 24) g
  JOIN mail_folders f ON f.mailbox_id = b.id AND f.special_use = CASE WHEN g % 7 = 0 THEN 'trash' WHEN g % 5 = 0 THEN 'sent' ELSE 'inbox' END
 WHERE b.address IN ('sales@s2.example', 'kim@s2.example');
INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, from_address, subject, received_at, seen, flagged, in_reply_to, references_ids)
SELECT m.folder, 1, m.g, ml_message_key_for(m.mb, '<s2-' || m.g || '@x>', encode(digest('s2' || m.mb || '-' || m.g, 'sha256'), 'hex')), '<s2-' || m.g || '@x>',
       'out' || (m.g % 4) || '@outside.example', 'Subject ' || (m.g % 9), now() - m.g * interval '1 hour', m.g % 2 = 0, m.g % 6 = 0,
       CASE WHEN m.g % 9 = 1 AND m.g > 9 THEN '<s2-' || (m.g - 9) || '@x>' END, CASE WHEN m.g % 9 = 1 AND m.g > 9 THEN ARRAY['<s2-' || (m.g - 9) || '@x>'] ELSE '{}' END
  FROM msgs m;
-- states: a snooze and a sensitive mark on sales@'s conversations; a viewer's read mark
INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, snoozed_until, snoozed_from_folder, snoozed_by)
SELECT m.mailbox_id, m.message_key_id, now() + interval '1 day', 'INBOX', 2 FROM mail_messages m JOIN mailboxes b ON b.id = m.mailbox_id
 WHERE b.address = 'sales@s2.example' AND m.uid = 3;
INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, sensitive, sensitive_by)
SELECT m.mailbox_id, m.message_key_id, true, 2 FROM mail_messages m JOIN mailboxes b ON b.id = m.mailbox_id WHERE b.address = 'sales@s2.example' AND m.uid = 4;
INSERT INTO mail_viewer_reads (member_id, message_key_id)
SELECT 2, m.message_key_id FROM mail_messages m JOIN mailboxes b ON b.id = m.mailbox_id WHERE b.address = 'sales@s2.example' AND m.uid IN (1, 9);

CREATE OR REPLACE FUNCTION pg_temp.ref_ids(p_mailbox bigint, p_view text, p_unread boolean, p_viewer boolean, p_member bigint, p_human boolean)
    RETURNS bigint[] LANGUAGE sql STABLE AS $$
    -- db/013's thread branch, as it was written (ml_thread_state() laterally on every conversation)
    SELECT COALESCE(array_agg(t.id ORDER BY t.last_message_at DESC, t.id DESC), '{}')
      FROM mail_threads t CROSS JOIN LATERAL ml_thread_state(t.id) st
     WHERE t.mailbox_id = p_mailbox AND (p_human OR NOT st.sensitive)
       AND CASE p_view
             WHEN 'inbox' THEN (SELECT f.id FROM mail_folders f WHERE f.mailbox_id = p_mailbox AND f.special_use = 'inbox') = ANY (t.folder_ids) AND st.snoozed_until IS NULL
             WHEN 'starred' THEN t.flagged
             WHEN 'snoozed' THEN st.snoozed_until IS NOT NULL
             WHEN 'sent' THEN (SELECT f.id FROM mail_folders f WHERE f.mailbox_id = p_mailbox AND f.special_use = 'sent') = ANY (t.folder_ids)
             WHEN 'trash' THEN (SELECT f.id FROM mail_folders f WHERE f.mailbox_id = p_mailbox AND f.special_use = 'trash') = ANY (t.folder_ids)
             WHEN 'all' THEN EXISTS (SELECT 1 FROM unnest(t.folder_ids) x WHERE x NOT IN (SELECT f.id FROM mail_folders f WHERE f.mailbox_id = p_mailbox AND f.special_use IN ('trash', 'junk')))
           END
       AND (NOT p_unread OR CASE WHEN p_viewer THEN EXISTS (SELECT 1 FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
                                                              WHERE m.thread_id = t.id AND NOT m.seen AND COALESCE(f.special_use, '') NOT IN ('trash', 'junk', 'sent', 'drafts')
                                                                AND NOT EXISTS (SELECT 1 FROM mail_viewer_reads r WHERE r.member_id = p_member AND r.message_key_id = m.message_key_id))
                                 ELSE t.unread_count > 0 END);
$$;
CREATE TEMP TABLE list_cmp AS
SELECT b.address, v.view, u.unread, h.human, h.viewer,
       pg_temp.ref_ids(b.id, v.view, u.unread, h.viewer, 2, h.human) AS ref,
       (SELECT COALESCE(array_agg(x.row_id ORDER BY x.row_at DESC, x.row_id DESC), '{}')
          FROM ml_mail_list_ids_core(b.id, v.view, NULL, NULL, u.unread, NULL, NULL, 10001, h.viewer, 2, h.human) x) AS core
  FROM mailboxes b, (VALUES ('inbox'), ('starred'), ('snoozed'), ('sent'), ('trash'), ('all')) v(view), (VALUES (false), (true)) u(unread),
       (VALUES (true, false), (true, true), (false, false)) h(human, viewer)
 WHERE b.address IN ('sales@s2.example', 'kim@s2.example');
SELECT pg_temp.check((SELECT count(*) FROM list_cmp WHERE ref IS DISTINCT FROM core) = 0 AND (SELECT count(*) FROM list_cmp WHERE cardinality(ref) > 0) >= 30,
                     'the list''s core gives db/013''s rows and order for every view, unread or not, a person, a viewer and an agent ('
                     || (SELECT count(*) FROM list_cmp) || ' lists)');
SELECT pg_temp.check((SELECT cardinality(ref) FROM list_cmp WHERE address = 'sales@s2.example' AND view = 'snoozed' AND NOT unread AND human AND NOT viewer) = 1
                     AND (SELECT cardinality(a.ref) - cardinality(b.ref) FROM list_cmp a, list_cmp b WHERE a.address = 'sales@s2.example' AND b.address = a.address
                            AND a.view = 'inbox' AND b.view = 'inbox' AND NOT a.unread AND NOT b.unread AND a.human AND NOT b.human AND NOT a.viewer AND NOT b.viewer) = 1,
                     '…the fixture exercises a snooze and the sensitive mark');

-- keyset paging through the wrapper equals the core's order, page by page
SELECT set_config('app.member_id', '2', true);
CREATE TEMP TABLE pages AS
WITH RECURSIVE p(n, ids, last_at, last_id) AS (
    SELECT 1, (SELECT array_agg(row_id ORDER BY row_at DESC, row_id DESC) FROM ml_mail_list_ids((SELECT id FROM mailboxes WHERE address = 'sales@s2.example'), 'all', NULL, NULL, false, NULL, NULL, 4)),
           (SELECT min(row_at) FROM ml_mail_list_ids((SELECT id FROM mailboxes WHERE address = 'sales@s2.example'), 'all', NULL, NULL, false, NULL, NULL, 4)), NULL::bigint
    UNION ALL
    SELECT p.n + 1, x.ids, x.at, x.id FROM p, LATERAL (
        SELECT array_agg(row_id ORDER BY row_at DESC, row_id DESC) AS ids, min(row_at) AS at, min(row_id) AS id
          FROM ml_mail_list_ids((SELECT id FROM mailboxes WHERE address = 'sales@s2.example'), 'all', NULL, NULL, false,
                                (SELECT t.last_message_at FROM mail_threads t WHERE t.id = p.ids[cardinality(p.ids)]), p.ids[cardinality(p.ids)], 4)) x
     WHERE p.n < 20 AND p.ids IS NOT NULL AND x.ids IS NOT NULL)
SELECT * FROM p;
SELECT pg_temp.check((SELECT array_agg(i ORDER BY n, o) FROM pages, unnest(ids) WITH ORDINALITY u(i, o))
                     = (SELECT ref FROM list_cmp WHERE address = 'sales@s2.example' AND view = 'all' AND NOT unread AND human AND NOT viewer),
                     'keyset pages of four, through the wall, walk the whole list once in order');

-- ml_unread_counts() = ml_unread_count() for every caller and mailbox
CREATE TEMP TABLE unread_cmp (member bigint, mailbox bigint, one int, setwise int);
DO $$
DECLARE p record;
BEGIN
    FOR p IN SELECT id FROM members LOOP
        PERFORM set_config('app.member_id', p.id::text, true);
        INSERT INTO unread_cmp SELECT p.id, b.id, ml_unread_count(b.id), (SELECT u.unread FROM ml_unread_counts() u WHERE u.mailbox_id = b.id) FROM mailboxes b;
    END LOOP;
    PERFORM set_config('app.member_id', '', true);
END$$;
SELECT pg_temp.check((SELECT count(*) FROM unread_cmp WHERE one IS DISTINCT FROM setwise) = 0 AND (SELECT count(*) FROM unread_cmp WHERE one > 0) >= 3,
                     'ml_unread_counts() equals ml_unread_count() for every caller and mailbox (a viewer''s own marks included)');
SELECT pg_temp.check((SELECT one FROM unread_cmp WHERE member = 2 AND mailbox = (SELECT id FROM mailboxes WHERE address = 'kim@s2.example'))
                     < (SELECT one FROM unread_cmp WHERE member = 3 AND mailbox = (SELECT id FROM mailboxes WHERE address = 'kim@s2.example'))
                     OR NOT EXISTS (SELECT 1 FROM mail_viewer_reads r JOIN mail_messages m ON m.message_key_id = r.message_key_id JOIN mailboxes b ON b.id = m.mailbox_id
                                     WHERE r.member_id = 2 AND b.address = 'kim@s2.example'),
                     '…a view holder counts by her own marks');

-- ================================================================ grants
SELECT pg_temp.check(has_function_privilege('malumail_rw', 'ml_my_levels()', 'EXECUTE') AND has_function_privilege('malumail_records_ro', 'ml_unread_counts()', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_rw', 'ml_mail_list_ids_core(bigint, text, bigint, bigint, boolean, timestamptz, bigint, integer, boolean, bigint, boolean)', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_activity_ro', 'ml_unread_counts()', 'EXECUTE'),
                     'the core (which takes the caller as a parameter) is no role''s to call; the walled readers are');

\o
SELECT n, CASE WHEN ok THEN 'ok  ' ELSE 'FAIL' END AS r, label FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' AS result FROM proof;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the slice 2 proof failed'; END IF; END $$;
ROLLBACK;
