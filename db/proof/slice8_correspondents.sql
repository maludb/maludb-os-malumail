-- Slice 8's proof of db/021 (docs/build-specs/correspondents.md): a message is received ONCE per message key (a second folder, a re-run,
-- the mailbox's own mail count nothing); the K7 access-log row refuses a nameless consumer and records no unverified agent; the K7 share's SQL
-- (ml_correspondence_index) answers only a mailbox its owner marked shared, active, unsuspended, never a sensitive conversation, with `since`
-- and `limit`; it reads no wall because it has no member. Phase 0's style: ONE transaction, ROLLED BACK.
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d ml_proof -f db/proof/slice8_correspondents.sql; echo "exit $?"
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
 (1, 'human', 'SMOKE Ada (super-admin)', 'ada@s8.example', 'super_admin', false, 'active', 'admin', '{admin,user}'),
 (2, 'human', 'SMOKE Kim', 'kim@s8.example', 'user', false, 'active', 'write', '{user}'),
 (9, 'agent', 'SMOKE Agent', 'agent@s8.example', 'user', false, 'active', 'write', '{user}');
INSERT INTO ml_domains (domain, served, internal) VALUES ('s8.example', true, true);
INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES
 ('kim@s8.example', 'Kim', 'person', 2), ('team@s8.example', 'Team', 'shared', NULL), ('bot@s8.example', 'Bot', 'agent', 9);
INSERT INTO mail_identities (mailbox_id, address, kind, display_name, is_default, created_by)
SELECT id, address, 'own', display_name, true, 1 FROM mailboxes;
INSERT INTO mail_folders (mailbox_id, name, special_use, uidvalidity, uidnext)
SELECT b.id, f.n, f.u, 1, 10 FROM mailboxes b, (VALUES ('INBOX', 'inbox'), ('Archive', 'archive')) f(n, u);

-- ---------------------------------------------------------------------------------------- received once per message key
CREATE OR REPLACE FUNCTION pg_temp.msg2(p_box text, p_folder text, p_uid int, p_mid text, p_from text, p_name text, p_subject text) RETURNS bigint LANGUAGE sql AS $$
    INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, from_address, from_name, subject, received_at)
    SELECT f.id, 1, p_uid, ml_message_key_for(f.mailbox_id, p_mid, encode(digest(p_mid, 'sha256'), 'hex')), p_mid, p_from, p_name, p_subject, now() - interval '1 hour'
      FROM mail_folders f JOIN mailboxes b ON b.id = f.mailbox_id WHERE b.address = p_box AND f.name = p_folder
    RETURNING id $$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;
CREATE TEMP TABLE ids (k text PRIMARY KEY, id bigint);
GRANT ALL ON ids TO PUBLIC;
INSERT INTO ids SELECT 'inbox1', pg_temp.msg2('kim@s8.example', 'INBOX', 1, '<s8-1@x>', 'Jon@Partner.example', 'Jon Partner', 'Hello');
INSERT INTO ids SELECT 'arch1', pg_temp.msg2('kim@s8.example', 'Archive', 1, '<s8-1@x>', 'Jon@Partner.example', 'Jon Partner', 'Hello');
INSERT INTO ids SELECT 'own1', pg_temp.msg2('kim@s8.example', 'Archive', 2, '<s8-2@x>', 'kim@s8.example', 'Kim', 'My own');
INSERT INTO ids SELECT 'inbox2', pg_temp.msg2('kim@s8.example', 'INBOX', 2, '<s8-3@x>', 'jon@partner.example', NULL, 'Again');
SET ROLE malumail_rw;
SELECT pg_temp.check(ml_correspondent_received_for((SELECT id FROM ids WHERE k = 'inbox1')) IS TRUE, 'the first copy of a message counts its sender');
SELECT pg_temp.check(ml_correspondent_received_for((SELECT id FROM ids WHERE k = 'arch1')) IS FALSE, 'the same message in Archive (one key) counts nothing');
SELECT pg_temp.check(ml_correspondent_received_for((SELECT id FROM ids WHERE k = 'inbox1')) IS FALSE, 'a re-run counts nothing');
SELECT pg_temp.check(ml_correspondent_received_for((SELECT id FROM ids WHERE k = 'own1')) IS FALSE, 'mail the mailbox wrote itself is not heard from');
SELECT pg_temp.check(ml_correspondent_received_for((SELECT id FROM ids WHERE k = 'inbox2')) IS TRUE, 'another message from the same address counts');
SELECT pg_temp.check(ml_correspondent_received_for(-1) IS FALSE, 'a message that does not exist counts nothing');
RESET ROLE;
SELECT pg_temp.check((SELECT received_count FROM mail_correspondents WHERE address = 'jon@partner.example') = 2, 'two messages, two received (never three)');
SELECT pg_temp.check((SELECT name FROM mail_correspondents WHERE address = 'jon@partner.example') = 'Jon Partner', 'the name is kept from the first mail');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mail_correspondents WHERE address = 'kim@s8.example'), 'the mailbox is not its own correspondent');
SELECT pg_temp.check((SELECT NOT is_internal AND known_at IS NULL FROM mail_correspondents WHERE address = 'jon@partner.example'), 'outside, and received mail makes nothing known');
SELECT pg_temp.check(NOT has_function_privilege('malumail_records_ro', 'ml_correspondent_received_for(bigint)', 'EXECUTE') AND NOT has_function_privilege('public', 'ml_correspondent_received_for(bigint)', 'EXECUTE'),
                     'only the web application''s role calls it (not the read servers)');

-- ---------------------------------------------------------------------------------------- sent: known only through a person
SELECT ml_correspondent_sent((SELECT id FROM mailboxes WHERE address = 'bot@s8.example'), 'Buyer@Outside.example', NULL, NULL);
SELECT pg_temp.check((SELECT sent_count = 1 AND known_at IS NULL FROM mail_correspondents WHERE address = 'buyer@outside.example'), 'an agent''s unreviewed send counts but makes nothing known');
SELECT ml_correspondent_sent((SELECT id FROM mailboxes WHERE address = 'bot@s8.example'), 'buyer@outside.example', NULL, 2);
SELECT pg_temp.check((SELECT sent_count = 2 AND known_how = 'sent' AND known_by = 2 FROM mail_correspondents WHERE address = 'buyer@outside.example'), 'an approved send makes it known by the approver');
SELECT pg_temp.refused($$SELECT ml_correspondent_sent((SELECT id FROM mailboxes WHERE address = 'bot@s8.example'), 'x@outside.example', NULL, 9)$$, '%', 'an agent cannot be the one who makes an address known');

-- ---------------------------------------------------------------------------------------- the K7 log row
SET ROLE malumail_records_ro;
SELECT pg_temp.refused($$SELECT ml_log_k7_read(ARRAY[1]::bigint[], NULL, NULL, ARRAY[1], 'x.example')$$, '%consumer%', 'a K7 read with no consumer is refused');
SELECT pg_temp.refused($$SELECT ml_log_k7_read(ARRAY[1]::bigint[], 'Not An App!', NULL, ARRAY[1], 'x.example')$$, '%consumer%', 'a consumer that is not an application key is refused');
SELECT pg_temp.refused($$SELECT ml_log_k7_read(ARRAY[1, 2]::bigint[], 'sales_crm', NULL, ARRAY[1], 'x.example')$$, '%count%', 'a count for every mailbox');
RESET ROLE;
INSERT INTO ids SELECT 'mb_' || split_part(address, '@', 1), id FROM mailboxes;
SET ROLE malumail_records_ro;
SELECT ml_log_k7_read(ARRAY[(SELECT id FROM ids WHERE k = 'mb_team')], 'sales_crm', 9, ARRAY[3], 'Partner.example');
SELECT ml_log_k7_read(ARRAY[(SELECT id FROM ids WHERE k = 'mb_bot')], 'sales_crm', 4242, ARRAY[0], NULL);
RESET ROLE;
SELECT pg_temp.check((SELECT count(*) FROM activity_log WHERE action = 'k7.read') = 2, 'one k7.read row per mailbox named');
SELECT pg_temp.check((SELECT actor_member_id = 9 AND after->>'consumer' = 'sales_crm' AND (after->>'consumer_agent')::bigint = 9 AND (after->>'count')::int = 3 AND after->>'domain' = 'partner.example'
                        FROM activity_log WHERE action = 'k7.read' AND mailbox_id = (SELECT id FROM mailboxes WHERE address = 'team@s8.example')), 'the row names the consumer, its agent (a real one), the count and the domain asked about');
SELECT pg_temp.check((SELECT actor_member_id IS NULL AND after->>'consumer_agent' IS NULL AND after->>'domain' IS NULL
                        FROM activity_log WHERE action = 'k7.read' AND mailbox_id = (SELECT id FROM mailboxes WHERE address = 'bot@s8.example')), 'an agent that is no member is recorded as none');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM activity_log WHERE action = 'k7.read' AND after::text ~* '(subject|snippet|hello|again)'), 'a k7.read row carries no subject, snippet or word of mail');
SELECT pg_temp.check(NOT has_function_privilege('malumail_rw', 'ml_log_k7_read(bigint[], text, bigint, integer[], text)', 'EXECUTE'), 'the web application cannot write a K7 row; the read servers alone do');

-- ---------------------------------------------------------------------------------------- the share's SQL
INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, from_address, to_addresses, subject, snippet, received_at)
SELECT f.id, 1, 50 + g, ml_message_key_for(f.mailbox_id, '<s8-k7-' || b.id || '-' || g || '@x>', encode(digest(b.id || '-' || g, 'sha256'), 'hex')), '<s8-k7-' || b.id || '-' || g || '@x>',
       'jon@partner.example', ARRAY[b.address::text], 'K7 subject ' || g, repeat('s', 300), now() - g * interval '1 day'
  FROM mailboxes b JOIN mail_folders f ON f.mailbox_id = b.id AND f.name = 'INBOX' CROSS JOIN generate_series(1, 3) g;
SELECT pg_temp.check((SELECT count(*) FROM mail_threads) >= 9, 'conversations exist in the three mailboxes');
SET ROLE malumail_records_ro;
SELECT pg_temp.check((SELECT array_agg(DISTINCT mailbox_address ORDER BY mailbox_address) FROM ml_correspondence_index('jon@partner.example', NULL, 100)) = ARRAY['bot@s8.example', 'team@s8.example'],
                     'an agent''s and a shared mailbox answer; a person''s does not until its owner switches sharing on');
RESET ROLE;
UPDATE mailboxes SET share_with_apps = true WHERE address = 'kim@s8.example';
SET ROLE malumail_records_ro;
SELECT pg_temp.check((SELECT count(DISTINCT mailbox_address) FROM ml_correspondence_index('jon@partner.example', NULL, 100)) = 3, 'after the owner''s switch, the person''s mailbox answers');
SELECT pg_temp.check((SELECT count(*) FROM ml_correspondence_index(NULL, 'partner.example', 100)) >= 9 AND (SELECT count(*) FROM ml_correspondence_index(NULL, 'other.example', 100)) = 0, 'a domain asks the same question');
SELECT pg_temp.check((SELECT max(length(snippet)) FROM ml_correspondence_index('jon@partner.example', NULL, 100)) = 200, 'a snippet is cut at 200 characters');
SELECT pg_temp.check((SELECT count(*) FROM ml_correspondence_index('jon@partner.example', NULL, 4)) = 4 AND (SELECT count(*) FROM ml_correspondence_index('jon@partner.example', NULL, 1000)) <= 100, 'limit holds, and is capped at 100');
SELECT pg_temp.check((SELECT count(*) FROM ml_correspondence_index('jon@partner.example', NULL, 100, now() - interval '36 hours')) = 5 AND (SELECT count(*) FROM ml_correspondence_index('jon@partner.example', NULL, 100, now() + interval '1 day')) = 0, '`since` holds');
RESET ROLE;
-- a sensitive conversation never leaves
INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, sensitive, sensitive_by)
SELECT m.mailbox_id, m.message_key_id, true, 2 FROM mail_messages m JOIN mailboxes b ON b.id = m.mailbox_id WHERE b.address = 'team@s8.example' AND m.uid = 51;
SET ROLE malumail_records_ro;
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_correspondence_index('jon@partner.example', NULL, 100) i WHERE i.mailbox_address = 'team@s8.example' AND i.subject = 'K7 subject 1'), 'a conversation marked sensitive is never in the answer');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ml_correspondence_index('jon@partner.example', NULL, 100) i WHERE i.mailbox_address = 'team@s8.example' AND i.subject = 'K7 subject 2'), '…and the others of that mailbox are');
RESET ROLE;
UPDATE mailboxes SET suspended_at = now() WHERE address = 'bot@s8.example';
UPDATE mailboxes SET active = false WHERE address = 'team@s8.example';
SET ROLE malumail_records_ro;
SELECT pg_temp.check((SELECT array_agg(DISTINCT mailbox_address) FROM ml_correspondence_index('jon@partner.example', NULL, 100)) = ARRAY['kim@s8.example'], 'a suspended mailbox and an inactive one never answer');
SELECT pg_temp.refused($$SELECT * FROM ml_correspondence_index(NULL, NULL, 10)$$, '%address or a domain%', 'the share asks about an address or a domain, never everything');
RESET ROLE;
UPDATE mailboxes SET share_with_apps = false WHERE address = 'kim@s8.example';
SET ROLE malumail_records_ro;
SELECT pg_temp.check((SELECT count(*) FROM ml_correspondence_index('jon@partner.example', NULL, 100)) = 0, 'switching sharing off closes the door at once');
RESET ROLE;

\o
SELECT n, CASE WHEN ok THEN 'ok  ' ELSE 'FAIL' END AS r, label FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' AS result FROM proof;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the slice 8 proof failed'; END IF; END $$;
ROLLBACK;
