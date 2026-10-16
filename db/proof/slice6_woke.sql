-- Slice 6's proof of db/019 (docs/build-specs/search-labels-snooze.md): the worker's wake leaves the "back from snooze" chip; it is shown to
-- whoever opens the mailbox and to no one else; opening the conversation clears it. Phase 0's style: ONE transaction, ROLLED BACK.
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d ml_proof -f db/proof/slice6_woke.sql; echo "exit $?"
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1, 'human', 'SMOKE Ada (super-admin)', 'ada@s6.example', 'super_admin', false, 'active', 'admin', '{admin,user}'),
 (2, 'human', 'SMOKE Kim', 'kim@s6.example', 'user', false, 'active', 'write', '{user}'),
 (3, 'human', 'SMOKE Pat (no access)', 'pat@s6.example', 'user', false, 'active', 'write', '{user}');
INSERT INTO ml_domains (domain, served, internal) VALUES ('s6.example', true, true);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES ('kim@s6.example', 'Kim', 'person', 2);
INSERT INTO mail_folders (mailbox_id, name, special_use, uidvalidity, uidnext) SELECT id, 'INBOX', 'inbox', 1, 2 FROM mailboxes WHERE address = 'kim@s6.example';
INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, from_address, subject, received_at)
SELECT f.id, 1, 1, ml_message_key_for(f.mailbox_id, '<s6-1@x>', repeat('a', 64)), '<s6-1@x>', 'someone@outside.example', 'Wakes', now() - interval '1 day'
  FROM mail_folders f WHERE f.name = 'INBOX';
CREATE TEMP TABLE pick AS
SELECT m.thread_id, m.message_key_id AS k, m.mailbox_id AS mb FROM mail_messages m WHERE m.message_id_header = '<s6-1@x>';
GRANT ALL ON pick TO PUBLIC;
INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, snoozed_until, snoozed_from_folder, snoozed_by) SELECT mb, k, now() - interval '1 minute', 'INBOX', 2 FROM pick;

SELECT pg_temp.check((SELECT count(*) FROM ml_snoozes_due()) = 1, 'ml_snoozes_due() answers the due snooze');
SELECT pg_temp.check((SELECT woke_at IS NOT NULL AND snoozed_until IS NULL FROM mail_thread_states WHERE anchor_key_id = (SELECT k FROM pick)), 'it clears the snooze and stamps woke_at');
SELECT pg_temp.check((SELECT count(*) FROM ml_snoozes_due()) = 0, 'a second pass finds nothing');

CREATE OR REPLACE FUNCTION pg_temp.try_unsnooze(p_by bigint) RETURNS SETOF record LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        RETURN QUERY SELECT * FROM ml_thread_unsnooze((SELECT mb FROM pick), (SELECT thread_id FROM pick), p_by);
    EXCEPTION WHEN insufficient_privilege OR no_data_found THEN RETURN;
    END;
END$$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;
SET ROLE malumail_records_ro;
SELECT set_config('app.member_id', '2', false);
SELECT pg_temp.check((SELECT array_agg(x) FROM ml_woke_threads(ARRAY[(SELECT thread_id FROM pick)]) x) = ARRAY[(SELECT thread_id FROM pick)], 'the owner sees the chip');
SELECT set_config('app.member_id', '3', false);
SELECT pg_temp.check((SELECT count(*) FROM ml_woke_threads(ARRAY[(SELECT thread_id FROM pick)])) = 0, 'a person who does not open the mailbox sees nothing');
SELECT pg_temp.check(ml_woke_clear((SELECT thread_id FROM pick)) = 0, '…and clears nothing');
RESET ROLE;
SET ROLE malumail_rw;
SELECT set_config('app.member_id', '2', false);
SELECT pg_temp.check(ml_woke_clear((SELECT thread_id FROM pick)) = 1, 'opening clears the chip');
SELECT pg_temp.check((SELECT count(*) FROM ml_woke_threads(ARRAY[(SELECT thread_id FROM pick)])) = 0, 'it is gone');
SELECT set_config('app.member_id', '', false);
RESET ROLE;

-- unsnooze now: the same state change, behind triage, for the conversation's own mailbox
RESET ROLE;
INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, snoozed_until, snoozed_from_folder, snoozed_by, woke_at)
SELECT mb, k, now() + interval '1 day', 'INBOX', 2, NULL FROM pick
ON CONFLICT (mailbox_id, anchor_key_id) DO UPDATE SET snoozed_until = EXCLUDED.snoozed_until, snoozed_from_folder = 'INBOX', snoozed_by = 2;
SET ROLE malumail_rw;
SELECT set_config('app.member_id', '3', false);
SELECT pg_temp.check((SELECT count(*) FROM pg_temp.try_unsnooze(3) AS t(a bigint, b text)) = 0, 'a person with no access cannot unsnooze (refused)');
SELECT set_config('app.member_id', '2', false);
SELECT pg_temp.check((SELECT count(*) = 1 AND bool_and(back_to = 'INBOX') FROM ml_thread_unsnooze((SELECT mb FROM pick), (SELECT thread_id FROM pick), 2)), 'the owner unsnoozes: the anchor and where it came from');
SELECT pg_temp.check((SELECT count(*) FROM ml_woke_threads(ARRAY[(SELECT thread_id FROM pick)])) = 1, '…and the chip is there');
SELECT pg_temp.check((SELECT count(*) FROM ml_thread_unsnooze((SELECT mb FROM pick), (SELECT thread_id FROM pick), 2)) = 0, 'a second unsnooze finds nothing');
SELECT set_config('app.member_id', '', false);
RESET ROLE;

\o
SELECT n, CASE WHEN ok THEN 'ok  ' ELSE 'FAIL' END AS r, label FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' AS result FROM proof;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the slice 6 proof failed'; END IF; END $$;
ROLLBACK;
