-- Slice 5's proof of db/018 (docs/build-specs/oversight.md): a failed mail turn has its notice kind, and the outbound attachments view
-- shows a held message's files to whoever sees the message and to no one else. Phase 0's style: ONE transaction, ROLLED BACK; fixtures as malumail_rw.
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d ml_proof -f db/proof/slice5_oversight.sql; echo "exit $?"
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
 (1,  'human', 'SMOKE Ada (super-admin)', 'ada@s5.example', 'super_admin', false, 'active', 'admin', '{admin,user}'),
 (2,  'human', 'SMOKE Kim (overseer)', 'kim@s5.example', 'user', false, 'active', 'write', '{user}'),
 (3,  'human', 'SMOKE Pat (nobody here)', 'pat@s5.example', 'user', false, 'active', 'write', '{user}'),
 (41, 'agent', 'SMOKE Scheduler', NULL, 'user', false, 'active', 'write', '{user}');
SELECT ml_domain_serve(1, 's5.example', true, true);
WITH x AS (SELECT ml_mailbox_create(1, 'sched@s5.example', 'Scheduler', 'agent', 41, 2, 2::bigint * 1073741824) AS id) SELECT pg_temp.keep('sched', id) FROM x;
SELECT ml_permission_grant(pg_temp.i('sched'), 1, 'member', 2, 'full');
UPDATE agent_mail_policies SET send_mode = 'hold_all', attachments_review = true WHERE mailbox_id = pg_temp.i('sched');   -- ml_mailbox_create made it
WITH x AS (SELECT ml_outbound_submit(pg_temp.i('sched'), 41, NULL, ARRAY['jon@supplier.example'], ARRAY[]::text[], ARRAY[]::text[], 'Figures', 'See the file.',
                                     NULL, NULL, NULL, 'send the figures', true, NULL, NULL) AS id) SELECT pg_temp.keep('o', id) FROM x;
INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by)
VALUES ('outbound_email', pg_temp.i('o'), 'figures.csv', 'text/csv', 8, repeat('a', 64), 'files/secret/path', 41);
SELECT pg_temp.check((SELECT status = 'held' AND 'attachment' = ANY (hold_reasons) FROM outbound_email WHERE id = pg_temp.i('o')), 'the agent''s message with a file is held');

-- ================================================================ 1. the failed turn's notice kind
SELECT pg_temp.check(ml_notify(2, 'dispatch_failed', 'A mail turn failed', NULL, pg_temp.i('sched'), 'agent_dispatch', 1) IS NOT NULL, 'dispatch_failed is a notice kind');
SELECT pg_temp.refused($$INSERT INTO notifications (member_id, kind, title) VALUES (2, 'failure', 'x')$$, 'notifications_kind_check', 'an unknown kind is still refused');

-- ================================================================ 2. mcp_outbound_attachments behind mcp_outbound's wall
RESET ROLE;
SET ROLE malumail_records_ro;
SELECT set_config('app.member_id', '2', false);
SELECT pg_temp.check((SELECT count(*) = 1 AND bool_and(filename = 'figures.csv' AND sha256 = repeat('a', 64)) FROM mcp_outbound_attachments WHERE outbound_id = pg_temp.i('o')),
                     'the overseer sees the held message''s file with its digest');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'mcp_outbound_attachments' AND column_name = 'storage_path'), 'never a storage path');
SELECT set_config('app.member_id', '3', false);
SELECT pg_temp.check((SELECT count(*) FROM mcp_outbound_attachments WHERE outbound_id = pg_temp.i('o')) = 0, 'a person who does not open the mailbox sees none of it');
SELECT set_config('app.member_id', '41', false);
SELECT pg_temp.check((SELECT count(*) FROM mcp_outbound_attachments WHERE outbound_id = pg_temp.i('o')) = 1, 'the agent sees its own message''s file');
SELECT set_config('app.member_id', '', false);
RESET ROLE;

\o
SELECT n, CASE WHEN ok THEN 'ok  ' ELSE 'FAIL' END AS r, label FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' AS result FROM proof;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the slice 5 proof failed'; END IF; END $$;
ROLLBACK;
