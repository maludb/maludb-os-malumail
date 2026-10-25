-- Phase 4's proof of db/023 (docs/malumail-mcp-tool-surface.md): the kit's two functions the MCP servers call (an agent is admitted once on the kernel's word, a
-- member is "known" only active with a capability), the activity server's one window mcp_activity_log (a mailbox's rows for whoever may open it, its administration
-- for a Mail admin, a row about no mailbox for its actor, never a screen view), the activity role's grants, and ml_aliases_visible() (every alias for a Mail admin,
-- otherwise those delivering to a mailbox the caller holds full on). Phase 0's style: ONE transaction, ROLLED BACK.
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d ml_proof -f db/proof/slice11_mcp_servers.sql; echo "exit $?"
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN EXECUTE p_sql; INSERT INTO proof (ok, label) VALUES (false, l || ' (it was allowed)');
    EXCEPTION WHEN OTHERS THEN INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE p_like, l || ' [' || left(SQLERRM, 80) || ']');
    END;
END$$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1, 'human', 'SMOKE Ada (super-admin)', 'ada@s11.example', 'super_admin', false, 'active', 'admin', '{admin,user}'),
 (2, 'human', 'SMOKE Kim', 'kim@s11.example', 'user', false, 'active', 'write', '{user}'),
 (3, 'human', 'SMOKE Lou', 'lou@s11.example', 'user', false, 'active', 'write', '{user}'),
 (9, 'agent', 'SMOKE Agent', 'agent@s11.example', 'user', false, 'active', NULL, '{user}'),
 (10, 'agent', 'SMOKE Gone', 'gone@s11.example', 'user', false, 'inactive', NULL, '{user}');
INSERT INTO ml_domains (domain, served, internal) VALUES ('s11.example', true, true);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES
 ('kim@s11.example', 'Kim', 'person', 2), ('lou@s11.example', 'Lou', 'person', 3), ('team@s11.example', 'Team', 'shared', NULL);
INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by)
SELECT id, 'member', 2, 'full', 1 FROM mailboxes WHERE address = 'team@s11.example';
INSERT INTO ml_aliases (domain_id, address, destinations, created_by)
SELECT d.id, v.a, v.dest::citext[], 1 FROM ml_domains d, (VALUES ('kim-alias@s11.example', '{kim@s11.example}'), ('lou-alias@s11.example', '{lou@s11.example,x@outside.example}'),
       ('team-alias@s11.example', '{team@s11.example}')) v(a, dest) WHERE d.domain = 's11.example';

-- the kit's two functions
SELECT pg_temp.check(mcp_member_kind(2) = 'human' AND mcp_member_kind(9) IS NULL AND mcp_member_kind(10) IS NULL AND mcp_member_kind(999) IS NULL, 'mcp_member_kind: a member is known only active with a capability');
SELECT pg_temp.check(mcp_admit_agent(9), 'mcp_admit_agent: a vouched agent is admitted');
SELECT pg_temp.check((SELECT capability FROM members WHERE id = 9) = 'write' AND NOT mcp_admit_agent(9), '…once: the second time changes nothing');
SELECT pg_temp.check(NOT mcp_admit_agent(10) AND NOT mcp_admit_agent(3) AND mcp_member_kind(9) = 'agent', '…an inactive agent and a person are not admitted by it');

-- the activity log's window
INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, mailbox_id) SELECT 2, 'web', 'message.archive', 'mail_message', 1, id FROM mailboxes WHERE address = 'kim@s11.example';
INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, mailbox_id) SELECT 3, 'web', 'message.archive', 'mail_message', 2, id FROM mailboxes WHERE address = 'lou@s11.example';
INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, mailbox_id) SELECT 2, 'web', 'message.archive', 'mail_message', 3, id FROM mailboxes WHERE address = 'team@s11.example';
INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, mailbox_id) SELECT 1, 'web', 'permission.grant', 'mailbox_permission', 1, id FROM mailboxes WHERE address = 'lou@s11.example';
INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id) VALUES (1, 'web', 'alias.create', 'alias', 1), (2, 'web', 'token.mint', 'token', 1);
INSERT INTO activity_log (actor_member_id, source, action, screen) VALUES (2, 'web', 'screen.view', 'inbox');
SET ROLE malumail_activity_ro;
SELECT set_config('app.member_id', '2', false);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log WHERE action = 'message.archive') = 2, 'Kim sees the archive events of her own mailbox and of the one she holds full on — not Lou''s');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_activity_log WHERE action IN ('screen.view', 'alias.create', 'permission.grant')), '…no screen view, nobody else''s administration');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_activity_log WHERE action = 'token.mint'), '…and an event about no mailbox that is hers');
SELECT set_config('app.member_id', '1', false);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 3 AND NOT EXISTS (SELECT 1 FROM mcp_activity_log WHERE action = 'message.archive'),
                     'the Mail admin sees the administration (a permission, a forwarding address, the token event of no mailbox) and no person''s mail acts');
SELECT set_config('app.member_id', '3', false);
SELECT pg_temp.check((SELECT array_agg(action ORDER BY action) FROM mcp_activity_log) = ARRAY['message.archive', 'permission.grant'] AND NOT EXISTS (SELECT 1 FROM mcp_activity_log WHERE entity_id IN (1, 3) AND action = 'message.archive'),
                     'Lou sees her own mailbox''s events (an archive, the permission given on it) — not Kim''s, not the forwarding address');
SELECT pg_temp.refused($$SELECT count(*) FROM activity_log$$, '%permission denied%', 'the activity role cannot read the base table');
SELECT pg_temp.refused($$INSERT INTO activity_log (action) VALUES ('x')$$, '%permission denied%', '…nor write it');
SELECT pg_temp.refused($$SELECT count(*) FROM mail_messages$$, '%permission denied%', '…nor read a message');
SELECT pg_temp.check((SELECT count(*) FROM mcp_mailboxes WHERE my_level <> 'none') >= 0 AND (SELECT count(*) FROM mcp_members) >= 3, '…but reads the views its queries join');
RESET ROLE;

-- ml_aliases_visible
SET ROLE malumail_records_ro;
SELECT set_config('app.member_id', '1', false);
SELECT pg_temp.check((SELECT count(*) FROM ml_aliases_visible()) = 3 AND (SELECT forwards_outside FROM ml_aliases_visible() WHERE address = 'lou-alias@s11.example'), 'a Mail admin sees every alias, the outside forward flagged');
SELECT set_config('app.member_id', '2', false);
SELECT pg_temp.check((SELECT array_agg(address ORDER BY address) FROM ml_aliases_visible()) = ARRAY['kim-alias@s11.example', 'team-alias@s11.example'], 'Kim: the aliases delivering to her own mailbox and to the shared one she holds full on');
SELECT set_config('app.member_id', '3', false);
SELECT pg_temp.check((SELECT array_agg(address) FROM ml_aliases_visible()) = ARRAY['lou-alias@s11.example'], 'Lou: hers alone (and its outside destination is hers to know)');
SELECT set_config('app.member_id', '99', false);
SELECT pg_temp.check((SELECT count(*) FROM ml_aliases_visible()) = 0, 'a stranger: none');
RESET ROLE;

\o
SELECT n, CASE WHEN ok THEN 'ok  ' ELSE 'FAIL' END AS r, label FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' AS result FROM proof;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the Phase 4 proof failed'; END IF; END $$;
ROLLBACK;
