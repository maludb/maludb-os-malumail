-- Phase 1 proof of db/012 (the fixes the tool surface, the manifest, the shell and slices 1 and 7 asked for — docs/phase1-consistency.md).
-- Phase 0's style: ONE transaction, ROLLED BACK; fixtures written as malumail_rw; calls with app.member_id (and app.on_behalf_of) set
-- as PHP and the MCP servers set them. Run as postgres on a SCRATCH database after every migration:
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d ml_proof -f db/proof/phase1_core.sql; echo "exit $?"
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
CREATE OR REPLACE FUNCTION pg_temp.as_member(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.member_id', COALESCE(p::text, ''), true) $$;
CREATE OR REPLACE FUNCTION pg_temp.behalf(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.on_behalf_of', COALESCE(p::text, ''), true) $$;
CREATE TEMP TABLE ids (k text PRIMARY KEY, id bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS bigint LANGUAGE sql AS $$ INSERT INTO ids (k, id) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET id = EXCLUDED.id RETURNING id $$;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.backdate(p_sql text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER AS $$
BEGIN   -- test-only: change a row past the guards
    PERFORM set_config('session_replication_role', 'replica', true);
    EXECUTE p_sql;
    PERFORM set_config('session_replication_role', 'origin', true);
END$$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

-- ================================================================ fixtures (synthetic: core.example)
SET ROLE malumail_rw;
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Ada (super-admin)', 'ada@core.example', 'super_admin', false, 'active', 'admin', '{admin,user}'),
 (2,  'human', 'SMOKE Pat', 'pat@core.example', 'user', false, 'active', 'write', '{user}'),
 (3,  'human', 'SMOKE Jo',  'jo@core.example',  'user', false, 'active', 'write', '{user}'),
 (40, 'agent', 'SMOKE Mail Expert', NULL, 'user', false, 'active', 'write', '{user}'),
 (41, 'agent', 'SMOKE Scheduler', NULL, 'user', false, 'active', 'write', '{user}');
INSERT INTO departments (id, name) VALUES (7, 'SMOKE Sales');
RESET ROLE;
UPDATE ml_settings SET expert_member_id = 40 WHERE id = 1;
SET ROLE malumail_rw;
INSERT INTO ml_domains (domain, served, internal) VALUES ('core.example', true, true);
WITH x AS (INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES ('pat@core.example', 'Pat', 'person', 2) RETURNING id) SELECT pg_temp.keep('pat', id) FROM x;
WITH x AS (INSERT INTO mailboxes (address, display_name, kind) VALUES ('sales@core.example', 'Sales', 'shared') RETURNING id) SELECT pg_temp.keep('sales', id) FROM x;
WITH x AS (INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES ('sched@core.example', 'Scheduler', 'agent', 41) RETURNING id) SELECT pg_temp.keep('sched', id) FROM x;
-- Pat shares her mailbox with the Scheduler at send; Ada shares sales@ with Jo at view and with the Scheduler at triage
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) VALUES
 (pg_temp.i('pat'), 'agent', 41, 'send', 2),
 (pg_temp.i('sales'), 'member', 3, 'view', 1),
 (pg_temp.i('sales'), 'agent', 41, 'triage', 1);
INSERT INTO email_templates (name, subject, body, is_shared, owner_member_id) VALUES
 ('SMOKE Pat own', 's', 'b', false, 2), ('SMOKE Jo own', 's', 'b', false, 3), ('SMOKE everyone', 's', 'b', true, NULL);
INSERT INTO email_templates (name, subject, body, is_shared, owner_member_id, mailbox_id) VALUES ('SMOKE sales only', 's', 'b', true, NULL, pg_temp.i('sales'));

-- ================================================================ 1. the wall for an agent working for a person
SELECT pg_temp.as_member(41); SELECT pg_temp.behalf(NULL);
SELECT pg_temp.check(ml_level(pg_temp.i('pat')) = 'send', 'the Scheduler alone reaches Pat''s mailbox at send (her row)');
SELECT pg_temp.behalf(3);
SELECT pg_temp.check(ml_level(pg_temp.i('pat')) = 'none', 'in Jo''s run the Scheduler reaches nothing Jo cannot open — Pat''s mailbox is closed (db/012: the lesser)');
SELECT pg_temp.check(ml_level(pg_temp.i('sales')) = 'view', '…and sales@ only at Jo''s view, not its own triage');
SELECT pg_temp.behalf(2);
SELECT pg_temp.check(ml_level(pg_temp.i('pat')) = 'send', 'in Pat''s run the Scheduler keeps send on her mailbox (the lesser of send and full)');
SELECT pg_temp.check(ml_level(pg_temp.i('sched')) = 'none', '…and its own mailbox is closed in Pat''s run (she holds no row on it)');
SELECT pg_temp.as_member(40); SELECT pg_temp.behalf(2);
SELECT pg_temp.check(ml_level(pg_temp.i('pat')) = 'none', 'the Mail Expert in Pat''s run reads nothing of her mailbox while ai_read is off');
RESET ROLE; UPDATE mailboxes SET ai_read = true WHERE id = (SELECT id FROM ids WHERE k = 'pat'); SET ROLE malumail_rw;
SELECT pg_temp.as_member(40); SELECT pg_temp.behalf(2);
SELECT pg_temp.check(ml_level(pg_temp.i('pat')) = 'triage', '…and at triage once she switches it on (design §3, unchanged)');
SELECT pg_temp.behalf(3);
SELECT pg_temp.check(ml_level(pg_temp.i('pat')) = 'none', '…never in Jo''s run, ai_read or not');
SELECT pg_temp.behalf(NULL);

-- ================================================================ 2. the resolvers' views
SELECT pg_temp.as_member(3);
SELECT pg_temp.check((SELECT count(*) FROM mcp_members) = 5 AND (SELECT email FROM mcp_members WHERE member_id = 40) IS NULL,
                     'mcp_members: every active member here; an agent has no address');
SELECT pg_temp.check((SELECT string_agg(name, ',') FROM mcp_departments) = 'SMOKE Sales', 'mcp_departments: the live departments');
SELECT pg_temp.check((SELECT string_agg(name, ',' ORDER BY name COLLATE "C") FROM mcp_templates) = 'SMOKE Jo own,SMOKE everyone',
                     'mcp_templates for Jo: hers and the shared-to-all — not Pat''s, not sales@''s (Jo holds view, not send)');
SELECT pg_temp.as_member(2);
SELECT pg_temp.check((SELECT string_agg(name, ',' ORDER BY name COLLATE "C") FROM mcp_templates) = 'SMOKE Pat own,SMOKE everyone', 'mcp_templates for Pat: hers and the shared-to-all');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT count(*) FROM mcp_templates WHERE name = 'SMOKE sales only') = 0, '…a template shared on sales@ does not reach Ada: the Mail admin role opens no shared mailbox');
SELECT pg_temp.as_member(1); RESET ROLE;
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) VALUES ((SELECT id FROM ids WHERE k = 'sales'), 'member', 2, 'send', 1);
SET ROLE malumail_rw; SELECT pg_temp.as_member(2);
SELECT pg_temp.check((SELECT count(*) FROM mcp_templates WHERE name = 'SMOKE sales only') = 1, '…it reaches Pat once she may send from sales@');
RESET ROLE; DELETE FROM mailbox_permissions WHERE principal_id = 2 AND principal_kind = 'member'; SET ROLE malumail_rw;
SELECT pg_temp.as_member(999);
SELECT pg_temp.check((SELECT count(*) FROM mcp_members) = 0 AND (SELECT count(*) FROM mcp_departments) = 0, 'someone not a member here reads no member and no department');

-- ================================================================ 3. a mailbox's settings and its two switches
SELECT pg_temp.as_member(2);
SELECT ml_mailbox_settings_save(pg_temp.i('pat'), 2, '{"undo_seconds": 20, "density": "compact"}', NULL, true);
SELECT pg_temp.check((SELECT settings->>'undo_seconds' = '20' AND share_with_apps FROM mailboxes WHERE id = pg_temp.i('pat')),
                     'Pat saves her undo seconds and shares her own mailbox with applications');
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save((SELECT id FROM ids WHERE k='pat'), 2, '{"undo_seconds": 40}')$$, '0 to 30', 'undo send over 30 seconds is refused');
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save((SELECT id FROM ids WHERE k='pat'), 2, '{"forward_to": "x@y.example"}')$$, 'no setting', 'a setting not on the allow-list is refused');
SELECT pg_temp.as_member(1);
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save((SELECT id FROM ids WHERE k='pat'), 1, '{}', false)$$, 'No such mailbox', 'Ada (a Mail admin) cannot even reach Pat''s settings — the mailbox does not exist for her');
SELECT pg_temp.as_member(41);
SELECT pg_temp.refused($$SELECT ml_mailbox_settings_save((SELECT id FROM ids WHERE k='sched'), 41, '{"density": "compact"}')$$, 'person', 'an agent changes no setting, even on its own mailbox');
SELECT pg_temp.as_member(1);
SELECT ml_mailbox_settings_save(pg_temp.i('sales'), 1, '{}', false, true);
SELECT pg_temp.check((SELECT NOT ai_read AND share_with_apps FROM mailboxes WHERE id = pg_temp.i('sales')), 'a Mail admin switches a shared mailbox''s ai_read and share');

-- ================================================================ 4. share with applications, directly
SELECT pg_temp.as_member(3);
SELECT pg_temp.refused($$SELECT ml_mailbox_share_set((SELECT id FROM ids WHERE k='pat'), 3, false)$$, 'Only its owner', 'Jo cannot switch Pat''s share');
SELECT pg_temp.as_member(1);
SELECT pg_temp.refused($$SELECT ml_mailbox_share_set((SELECT id FROM ids WHERE k='pat'), 1, false)$$, 'Only its owner', 'nor can a Mail admin: a person''s mailbox is its owner''s to share');
SELECT ml_mailbox_share_set(pg_temp.i('sched'), 1, false);
SELECT pg_temp.check((SELECT NOT share_with_apps FROM mailboxes WHERE id = pg_temp.i('sched')), 'a Mail admin switches an agent mailbox''s share');

-- ================================================================ 5. serving a domain
SELECT pg_temp.as_member(1);
SELECT ml_domain_serve(1, 'branch.example', true, false);
SELECT pg_temp.check((SELECT served AND NOT internal FROM ml_domains WHERE domain = 'branch.example'), 'Ada serves branch.example as outside (not internal)');
SELECT pg_temp.as_member(2);
SELECT pg_temp.refused($$SELECT ml_domain_serve(2, 'branch.example', false)$$, '', 'a Mail user cannot change the served domains');
RESET ROLE;
INSERT INTO mailbox_credentials (kind, label, last4, ciphertext, mailbox_id) VALUES ('mailbox_password', 'proof', 'abcd', decode(repeat('ab', 60), 'hex'), (SELECT id FROM ids WHERE k = 'sched'));
UPDATE mailboxes SET credential_id = (SELECT max(id) FROM mailbox_credentials) WHERE id = (SELECT id FROM ids WHERE k = 'sched');
SET ROLE malumail_rw;
SELECT pg_temp.as_member(1);
SELECT pg_temp.refused($$SELECT ml_domain_serve(1, 'core.example', false)$$, 'working credentials', 'unserving a domain whose mailbox holds a working credential is refused…');
SELECT ml_domain_serve(1, 'core.example', false, NULL, true);
SELECT pg_temp.check((SELECT NOT served AND NOT internal FROM ml_domains WHERE domain = 'core.example'), '…and done when confirmed; an unserved domain is never internal');
SELECT ml_domain_serve(1, 'core.example', true, true);
SELECT ml_mailbox_display_name_set(pg_temp.i('sales'), 1, 'Sales team');
SELECT pg_temp.check((SELECT display_name FROM mailboxes WHERE id = pg_temp.i('sales')) = 'Sales team', 'a Mail admin renames a mailbox');
SELECT pg_temp.as_member(3);
SELECT pg_temp.refused($$SELECT ml_mailbox_display_name_set((SELECT id FROM ids WHERE k='sales'), 3, 'Mine')$$, '', 'a Mail user cannot rename one');

-- ================================================================ 6. expiring rows are logged
SELECT pg_temp.as_member(1);
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by, expires_at)
VALUES (pg_temp.i('sales'), 'member', 2, 'view', 1, now() + interval '1 day');
SELECT pg_temp.backdate($$UPDATE mailbox_permissions SET expires_at = now() - interval '1 minute' WHERE principal_id = 2 AND principal_kind = 'member'$$);
SELECT pg_temp.check(ml_permissions_expire() = 1, 'the worker''s expiry removes the one expired row');
SELECT pg_temp.check((SELECT count(*) FROM activity_log WHERE action = 'permission.expire' AND mailbox_id = pg_temp.i('sales') AND after->>'principal_id' = '2') = 1,
                     '…and logs it (permission.expire, the mailbox and the principal)');

-- ================================================================ 7. the records MCP's access-log write
SELECT pg_temp.as_member(3);
SELECT ml_log_mcp_read(pg_temp.i('sales'), 'thread_read');
SELECT pg_temp.check((SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND actor_member_id = 3 AND mailbox_id = pg_temp.i('sales')) = 1,
                     'an MCP read of a mailbox not the caller''s own is in the access log');
SELECT pg_temp.as_member(2);
SELECT ml_log_mcp_read(pg_temp.i('pat'), 'thread_read');
SELECT pg_temp.check((SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND actor_member_id = 2) = 0, 'the owner''s own reads are not logged');
SELECT ml_log_mcp_read(pg_temp.i('sales'), 'DROP TABLE x');
SELECT pg_temp.check((SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND actor_member_id = 2) = 0, 'a tool name that is not a tool name writes nothing');
RESET ROLE;
SELECT pg_temp.check(has_function_privilege('malumail_records_ro', 'ml_log_mcp_read(bigint, text, bigint, bigint)', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_records_ro', 'ml_mailbox_settings_save(bigint, bigint, jsonb, boolean, boolean)', 'EXECUTE'),
                     'the records role may write the access log and nothing else of db/012');
SET ROLE malumail_rw;

-- ================================================================ 8. the shared widenings
SELECT pg_temp.check((SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'agent_dispatches_kind_check') ~ 'review' AND
                     (SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'agent_dispatches_kind_check') ~ 'snooze',
                     'a mail turn may carry a reviewer''s note (review) or a snoozed conversation waking (snooze)');

-- ================================================================ the count
RESET ROLE;
\o
SELECT n, CASE WHEN ok THEN 'ok  ' ELSE 'FAIL' END AS r, label FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' AS result FROM proof;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the db/012 proof failed'; END IF; END $$;
ROLLBACK;
