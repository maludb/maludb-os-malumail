-- Slice 1's proof of db/016 and of the administration functions the mail service calls in one transaction on create and connect
-- (docs/build-specs/domains-mailboxes.md). Phase 0's style: ONE transaction, ROLLED BACK; fixtures as malumail_rw.
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d ml_proof -f db/proof/slice1_admin.sql; echo "exit $?"
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
CREATE TEMP TABLE ids (k text PRIMARY KEY, id bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS bigint LANGUAGE sql AS $$ INSERT INTO ids (k, id) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET id = EXCLUDED.id RETURNING id $$;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM ids WHERE ids.k = $1 $$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

SET ROLE malumail_rw;
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Ada (super-admin)', 'ada@s1.example', 'super_admin', false, 'active', 'admin', '{admin,user}'),
 (2,  'human', 'SMOKE Pat', 'pat@s1.example', 'user', false, 'active', 'write', '{user}'),
 (3,  'human', 'SMOKE Kim (overseer)', 'kim@s1.example', 'user', false, 'active', 'write', '{user}'),
 (41, 'agent', 'SMOKE Scheduler', NULL, 'user', false, 'active', 'write', '{user}');
SELECT ml_domain_serve(1, 's1.example', true, true);

-- ================================================================ the transaction the mail service runs on create (an agent's mailbox)
WITH x AS (SELECT ml_mailbox_create(1, 'sched@s1.example', 'Scheduler', 'agent', 41, 2, 2::bigint * 1073741824) AS id) SELECT pg_temp.keep('sched', id) FROM x;
SELECT ml_permission_grant(pg_temp.i('sched'), 1, 'member', 3, 'full');
SELECT ml_primary_overseer_set(pg_temp.i('sched'), 1, 3);
SELECT ml_credential_store(pg_temp.i('sched'), 1, 'mailbox_password', decode(repeat('ab', 60), 'hex'), 'created');
SELECT pg_temp.check((SELECT kind = 'agent' AND owner_member_id = 41 AND primary_overseer_id = 3 FROM mailboxes WHERE id = pg_temp.i('sched')),
                     'an agent''s mailbox: owner the agent, the primary overseer Kim');
SELECT pg_temp.check((SELECT string_agg(principal_id::text || ':' || level, ',' ORDER BY principal_id) FROM mailbox_permissions WHERE mailbox_id = pg_temp.i('sched') AND principal_kind = 'member') = '2:full,3:full',
                     'both overseers hold full (Pat named at create, Kim added)');
SELECT pg_temp.check((SELECT c.status = 'ok' FROM mailboxes b JOIN mailbox_credentials c ON c.id = b.credential_id WHERE b.id = pg_temp.i('sched')), 'its sealed credential is stored and live');
SELECT pg_temp.check((SELECT count(*) FROM agent_mail_policies WHERE mailbox_id = pg_temp.i('sched')) = 1, 'and its policy is seeded with the defaults');
SELECT pg_temp.refused($$SELECT ml_mailbox_create(1, 'pat2@s1.example', 'Pat', 'person', 41)$$, 'belongs to a person', 'a person''s mailbox owned by an agent is refused');
SELECT pg_temp.refused($$SELECT ml_mailbox_create(1, 'x@unserved.example', 'X', 'shared', NULL)$$, 'serves no domain', 'a mailbox on an unserved domain is refused');
SELECT pg_temp.refused($$SELECT ml_mailbox_create(2, 'y@s1.example', 'Y', 'shared', NULL)$$, 'Mail admin', 'a Mail user cannot create a mailbox');
SELECT pg_temp.refused($$SELECT ml_mailbox_create(41, 'y@s1.example', 'Y', 'shared', NULL)$$, 'never administers', 'an agent cannot create a mailbox, and is told why');

-- a reset of an agent's mailbox replaces the credential: the old one revoked, the new one live
SELECT ml_credential_store(pg_temp.i('sched'), 1, 'mailbox_password', decode(repeat('cd', 60), 'hex'), 'reset');
SELECT pg_temp.check((SELECT string_agg(status, ',' ORDER BY id) FROM mailbox_credentials WHERE mailbox_id = pg_temp.i('sched')) = 'revoked,ok',
                     'a reset stores the new credential and revokes the old');

-- ================================================================ db/016: quota
SELECT ml_mailbox_quota_set(pg_temp.i('sched'), 1, 5);
SELECT pg_temp.check((SELECT quota_bytes FROM mailboxes WHERE id = pg_temp.i('sched')) = 5::bigint * 1073741824, 'the quota is recorded in bytes');
SELECT pg_temp.refused($$SELECT ml_mailbox_quota_set((SELECT id FROM ids WHERE k='sched'), 1, 51)$$, '1 to 50', 'a quota over 50 GiB is refused');
SELECT pg_temp.refused($$SELECT ml_mailbox_quota_set((SELECT id FROM ids WHERE k='sched'), 2, 3)$$, 'Mail admin', 'a Mail user cannot set a quota');

-- ================================================================ db/016: the alias cache
SELECT pg_temp.keep('sales', ml_alias_cache_put(1, 'Sales@S1.example', ARRAY['pat@s1.example', 'KIM@s1.example']));
SELECT pg_temp.check((SELECT address::text || '>' || array_to_string(destinations::text[], ',') FROM ml_aliases WHERE id = pg_temp.i('sales')) = 'sales@s1.example>pat@s1.example,kim@s1.example',
                     'an alias is cached lower-cased with its destinations');
SELECT ml_alias_cache_put(1, 'sales@s1.example', ARRAY['pat@s1.example', 'rep@outside.example']);
SELECT set_config('app.member_id', '1', true);
SELECT pg_temp.check((SELECT count(*) FROM ml_aliases WHERE address = 'sales@s1.example') = 1
                     AND (SELECT forwards_outside FROM mcp_aliases WHERE address = 'sales@s1.example'),
                     'putting it again replaces its destinations (one row), and the view flags the outside one');
SELECT pg_temp.refused($$SELECT ml_alias_cache_put(1, 'x@elsewhere.example', ARRAY['pat@s1.example'])$$, 'serves no domain', 'an alias on an unserved domain is refused');
SELECT pg_temp.refused($$SELECT ml_alias_cache_put(1, 'empty@s1.example', ARRAY[]::text[])$$, 'at least one', 'an alias with no destination is refused');
SELECT pg_temp.refused($$SELECT ml_alias_cache_put(2, 'p@s1.example', ARRAY['pat@s1.example'])$$, 'Mail admin', 'a Mail user cannot cache an alias');
CREATE TEMP TABLE dropped AS SELECT ml_alias_cache_drop(1, 'SALES@s1.example') AS ok;
SELECT pg_temp.check((SELECT ok FROM dropped) AND NOT EXISTS (SELECT 1 FROM ml_aliases WHERE address = 'sales@s1.example'), 'dropping an alias removes its cache row');
SELECT pg_temp.check(NOT ml_alias_cache_drop(1, 'never@s1.example'), 'dropping one never cached answers false');

-- ================================================================ db/016: domain flags
SELECT ml_domain_flags_save(1, 'S1.example', true, true, false);
SELECT pg_temp.check((SELECT spf_ok AND dkim_ok AND NOT dmarc_ok AND flags_read_at IS NOT NULL FROM ml_domains WHERE domain = 's1.example'), 'a served domain''s flags are saved with the time read');
SELECT pg_temp.refused($$SELECT ml_domain_flags_save(2, 's1.example', true, true, true)$$, 'Mail admin', 'a Mail user cannot save flags');

-- ================================================================ grants
RESET ROLE;
SELECT pg_temp.check(has_function_privilege('malumail_rw', 'ml_alias_cache_put(bigint, text, text[])', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_records_ro', 'ml_alias_cache_put(bigint, text, text[])', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_records_ro', 'ml_mailbox_quota_set(bigint, bigint, integer)', 'EXECUTE'),
                     'the application role writes; the records role does not');

\o
SELECT n, CASE WHEN ok THEN 'ok  ' ELSE 'FAIL' END AS r, label FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' AS result FROM proof;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the slice 1 proof failed'; END IF; END $$;
ROLLBACK;
