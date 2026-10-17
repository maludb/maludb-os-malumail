-- Slice 7's proof of db/020 and of the delegation rules the slice shows (docs/build-specs/delegation-access.md): the settings function now
-- takes page_size and refuses a non-boolean yes/no key; the access log names a K7 consumer; and — as a regression net under the new
-- screens — who may grant, revoke and switch what, in SQL, as the web application's role. Phase 0's style: ONE transaction, ROLLED BACK.
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d ml_proof -f db/proof/slice7_access.sql; echo "exit $?"
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1, 'human', 'SMOKE Admin', 'admin@s7.example', 'super_admin', false, 'active', 'admin', '{admin,user}'),
 (2, 'human', 'SMOKE Pat', 'pat@s7.example', 'user', false, 'active', 'write', '{user}'),
 (3, 'human', 'SMOKE Jo', 'jo@s7.example', 'user', false, 'active', 'write', '{user}'),
 (4, 'agent', 'SMOKE Scheduler', 'sched@s7.example', 'user', false, 'active', 'write', '{user}'),
 (5, 'human', 'SMOKE Viv', 'viv@s7.example', 'user', false, 'active', 'write', '{user}');
INSERT INTO departments (id, name) VALUES (7, 'Sales');
INSERT INTO department_members (member_id, department_id, is_primary) VALUES (3, 7, true), (4, 7, true);
INSERT INTO ml_domains (domain, served, internal) VALUES ('s7.example', true, true);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES
 ('pat@s7.example', 'Pat', 'person', 2), ('sales@s7.example', 'Sales', 'shared', NULL), ('sched@s7.example', 'Scheduler', 'agent', 4);
CREATE TEMP TABLE mb AS SELECT split_part(address, '@', 1) AS k, id FROM mailboxes;
GRANT ALL ON mb TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.m(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM mb WHERE mb.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.as_member(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.member_id', COALESCE(p::text, ''), true) $$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

SET ROLE malumail_rw;
-- ------------------------------------------------------------------------------------------------- settings (db/020)
SELECT pg_temp.as_member(2);
SELECT ml_mailbox_settings_save(pg_temp.m('pat'), 2, '{"page_size": 25, "density": "compact", "conversation_view": false, "shortcuts": true, "undo_seconds": 10}'::jsonb, NULL, NULL);
SELECT pg_temp.check((SELECT (settings->>'page_size')::int = 25 AND settings->>'density' = 'compact' AND (settings->'conversation_view') = 'false'::jsonb FROM mailboxes WHERE id = pg_temp.m('pat')),
                     'the owner saves page_size, density and a boolean conversation view');
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save(pg_temp.m('pat'), 2, '{"page_size": 40}'::jsonb, NULL, NULL)$$, '25, 50 or 100', 'a page of 40 is refused');
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save(pg_temp.m('pat'), 2, '{"page_size": "50"}'::jsonb, NULL, NULL)$$, '25, 50 or 100', 'a page size that is a string is refused');
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save(pg_temp.m('pat'), 2, '{"conversation_view": "yes"}'::jsonb, NULL, NULL)$$, 'on or off', 'a yes/no key must be a boolean');
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save(pg_temp.m('pat'), 2, '{"undo_seconds": 31}'::jsonb, NULL, NULL)$$, '0 to 30', 'undo past 30 seconds is refused');
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save(pg_temp.m('pat'), 2, '{"theme": "dark"}'::jsonb, NULL, NULL)$$, 'no setting', 'an unknown key is refused');
SELECT ml_mailbox_settings_save(pg_temp.m('pat'), 2, '{}'::jsonb, true, false);
SELECT pg_temp.check((SELECT ai_read AND NOT share_with_apps FROM mailboxes WHERE id = pg_temp.m('pat')), 'the owner switches ai_read on');
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save(pg_temp.m('pat'), 1, '{}'::jsonb, false, NULL)$$, 'No such mailbox', 'a Mail admin cannot switch a person''s ai_read (no level on it)');
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save(pg_temp.m('pat'), 4, '{}'::jsonb, true, NULL)$$, 'a person''s decision', 'an agent never saves settings');
SELECT ml_mailbox_settings_save(pg_temp.m('sched'), 1, '{}'::jsonb, NULL, true);
SELECT pg_temp.check((SELECT share_with_apps FROM mailboxes WHERE id = pg_temp.m('sched')), 'a Mail admin switches share_with_apps on an agent''s mailbox');
SELECT ml_mailbox_settings_save(pg_temp.m('sales'), 1, '{}'::jsonb, true, NULL);
SELECT pg_temp.check((SELECT ai_read FROM mailboxes WHERE id = pg_temp.m('sales')), '…and a shared mailbox''s switches');
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save(pg_temp.m('sales'), 1, '{"density": "compact"}'::jsonb, NULL, NULL)$$, 'No such mailbox', 'but a Mail admin without a level saves no other setting of a shared mailbox');

-- ------------------------------------------------------------------------------------------------- sharing a person's mailbox
SELECT ml_permission_grant(pg_temp.m('pat'), 2, 'member', 3, 'triage');
SELECT ml_permission_grant(pg_temp.m('pat'), 2, 'department', 7, 'view');
SELECT pg_temp.check(ml_mailbox_level(pg_temp.m('pat'), 3) = 'triage', 'Pat shares with Jo at triage (the best of her rows wins over the department''s view)');
SELECT pg_temp.check(ml_mailbox_level(pg_temp.m('pat'), 4) = 'none', 'a department row admits people only: its agent has nothing');
SELECT ml_permission_grant(pg_temp.m('pat'), 2, 'agent', 4, 'send');
SELECT pg_temp.check(ml_mailbox_level(pg_temp.m('pat'), 4) = 'send', 'Pat grants the agent send: allowed');
SELECT pg_temp.refused($$SELECT ml_permission_grant(pg_temp.m('pat'), 2, 'agent', 4, 'full')$$, 'at most at send', 'an agent at full is refused with the sentence');
SELECT pg_temp.refused($$SELECT ml_permission_grant(pg_temp.m('pat'), 4, 'member', 5, 'view')$$, 'a person''s decision', 'an agent grants nothing');
SELECT pg_temp.refused($$SELECT ml_permission_grant(pg_temp.m('pat'), 3, 'member', 5, 'view')$$, 'Only its owner', 'Jo (triage) cannot share Pat''s mailbox');
SELECT pg_temp.refused($$SELECT ml_permission_grant(pg_temp.m('pat'), 1, 'member', 1, 'view')$$, 'mailbox_permissions_check', 'a Mail admin''s row without a reason is refused');
SELECT pg_temp.refused($$SELECT ml_permission_grant(pg_temp.m('pat'), 1, 'member', 1, 'view', 'we need the contract', now() + interval '40 days')$$, 'mailbox_permissions_check', 'break-glass past 30 days is refused');
SELECT pg_temp.refused($$SELECT ml_permission_grant(pg_temp.m('pat'), 1, 'department', 7, 'view', 'we need the contract', now() + interval '3 days')$$, 'one person', 'an emergency grant names one person');
SELECT ml_permission_grant(pg_temp.m('pat'), 1, 'member', 1, 'view', 'we need the signed contract', now() + interval '7 days');
SELECT pg_temp.check((SELECT break_glass FROM mailbox_permissions WHERE mailbox_id = pg_temp.m('pat') AND principal_id = 1), 'a Mail admin''s row on a person''s mailbox is break-glass');
SELECT pg_temp.check(ml_mailbox_level(pg_temp.m('pat'), 1) = 'view', '…and gives the Mail admin view');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM notifications WHERE member_id = 2 AND kind = 'break_glass' AND mailbox_id = pg_temp.m('pat')), 'Pat is told at once');
SELECT pg_temp.refused($$SELECT ml_permission_revoke(pg_temp.m('pat'), 2, 'member', 1)$$, 'do not decide', 'Pat cannot revoke the Mail admin''s break-glass row');
SELECT pg_temp.refused($$SELECT ml_permission_revoke(pg_temp.m('pat'), 3, 'member', 3)$$, 'do not decide', 'Jo cannot revoke his own share');
SELECT pg_temp.refused($$SELECT ml_permission_revoke(pg_temp.m('pat'), 4, 'agent', 4)$$, 'a person''s decision', 'an agent revokes nothing');
SELECT pg_temp.check(ml_permission_revoke(pg_temp.m('pat'), 2, 'member', 3), 'Pat revokes Jo');
SELECT pg_temp.check(ml_mailbox_level(pg_temp.m('pat'), 3) = 'view', '…Jo keeps the department''s view');
SELECT pg_temp.check(ml_permission_revoke(pg_temp.m('pat'), 1, 'member', 1), 'the Mail admin revokes the break-glass row');
SELECT pg_temp.check(ml_mailbox_level(pg_temp.m('pat'), 1) = 'none', '…and holds nothing on a person''s mailbox again');

-- ------------------------------------------------------------------------------------------------- sharing an agent's and a shared mailbox
SELECT pg_temp.check(ml_mailbox_level(pg_temp.m('sched'), 1) = 'full', 'a Mail admin holds full on every agent''s mailbox');
SELECT pg_temp.check(ml_mailbox_level(pg_temp.m('sales'), 1) = 'none', '…and nothing on a shared one');
SELECT ml_permission_grant(pg_temp.m('sales'), 1, 'member', 2, 'full');
SELECT ml_permission_grant(pg_temp.m('sales'), 2, 'member', 3, 'view');
SELECT pg_temp.check(ml_mailbox_level(pg_temp.m('sales'), 3) = 'view', 'a Mail admin shares a shared mailbox, and a full holder shares it again');
SELECT pg_temp.refused($$SELECT ml_permission_grant(pg_temp.m('sales'), 3, 'member', 5, 'view')$$, 'full access', 'a view holder cannot share it');
SELECT pg_temp.refused($$SELECT ml_permission_grant(pg_temp.m('sales'), 5, 'member', 5, 'view')$$, 'full access', 'a person with no standing cannot share it');

-- ------------------------------------------------------------------------------------------------- expiry leaves a trail
RESET ROLE;
SET session_replication_role = replica;
UPDATE mailbox_permissions SET expires_at = now() - interval '1 minute' WHERE mailbox_id = pg_temp.m('sales') AND principal_id = 3;
SET session_replication_role = origin;
SET ROLE malumail_rw;
SELECT pg_temp.check(ml_mailbox_level(pg_temp.m('sales'), 3) = 'none', 'an expired row is nothing even before the pass');
SELECT pg_temp.check(ml_permissions_expire() = 1, 'the pass removes it');
SELECT pg_temp.check((SELECT count(*) FROM activity_log WHERE action = 'permission.expire' AND mailbox_id = pg_temp.m('sales') AND source = 'system') = 1, '…and writes permission.expire for the mailbox');

-- ------------------------------------------------------------------------------------------------- the access log names its consumer (db/020)
RESET ROLE;
INSERT INTO activity_log (actor_member_id, source, action, mailbox_id, after) VALUES
 (1, 'application', 'k7.read', pg_temp.m('pat'), '{"consumer": "crm"}'), (1, 'web', 'mailbox.view', pg_temp.m('pat'), '{"view": "inbox"}');
SET ROLE malumail_records_ro;
SELECT pg_temp.as_member(2);
SELECT pg_temp.check((SELECT consumer FROM mcp_mailbox_access_log WHERE action = 'k7.read' AND mailbox_id = pg_temp.m('pat')) = 'crm', 'a K7 read names its consumer');
SELECT pg_temp.check((SELECT consumer FROM mcp_mailbox_access_log WHERE action = 'mailbox.view' AND mailbox_id = pg_temp.m('pat')) IS NULL, '…and a web look names none');
SELECT pg_temp.as_member(3);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_mailbox_access_log WHERE mailbox_id = pg_temp.m('pat')), 'Jo reads no access log of Pat''s mailbox');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT count(*) FROM mcp_mailbox_access_log WHERE mailbox_id = pg_temp.m('pat')) >= 2, 'a Mail admin reads it (access.audit)');
RESET ROLE;

\o
SELECT n, CASE WHEN ok THEN 'ok  ' ELSE 'FAIL' END AS r, label FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' AS result FROM proof;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the slice 7 proof failed'; END IF; END $$;
ROLLBACK;
