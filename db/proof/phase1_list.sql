-- Phase 1 proof of db/013 (the list and the sync — docs/build-specs/mail-service.md, mail-list.md, home-admin.md). Phase 0's style: ONE
-- transaction, ROLLED BACK; fixtures written as malumail_rw; reads with app.member_id (and app.on_behalf_of) set as PHP and the MCP
-- servers set them. Run as postgres on a SCRATCH database after every migration through db/013:
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d ml_p1a -f db/proof/phase1_list.sql; echo "exit $?"
-- Prints one line per check and the count; exits non-zero when any check failed.
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
CREATE OR REPLACE FUNCTION pg_temp.key(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM ids WHERE ids.k = 'key:' || $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.folders(mb bigint) RETURNS void LANGUAGE sql AS $$
    INSERT INTO mail_folders (mailbox_id, name, special_use, uidvalidity, uidnext) VALUES
      (mb, 'INBOX', 'inbox', 1, 1), (mb, 'Sent', 'sent', 1, 1), (mb, 'Drafts', 'drafts', 1, 1), (mb, 'Trash', 'trash', 1, 1),
      (mb, 'Junk', 'junk', 1, 1), (mb, 'Archive', 'archive', 1, 1);
$$;
CREATE OR REPLACE FUNCTION pg_temp.f(mb bigint, su text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM mail_folders WHERE mailbox_id = mb AND special_use = su $$;
-- a message on the server, as the mail service writes it (x: irt, seen, flagged, body, uid, uidvalidity, name)
CREATE OR REPLACE FUNCTION pg_temp.msg(k text, mb bigint, su text, mid text, frm text, tos text[], subj text, at timestamptz, x jsonb DEFAULT '{}') RETURNS bigint
    LANGUAGE plpgsql AS $$
DECLARE fid bigint := pg_temp.f(mb, su); key bigint; u bigint; newid bigint;
BEGIN
    key := ml_message_key_for(mb, mid, ml_envelope_digest(at::text, frm, array_to_string(tos, ','), '', subj));
    u := COALESCE((x->>'uid')::bigint, (SELECT COALESCE(max(m.uid), 0) + 1 FROM mail_messages m WHERE m.folder_id = fid));
    INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, in_reply_to, from_address, from_name, to_addresses,
                               subject, sent_at, received_at, size, seen, flagged, snippet, body_text, text_indexed_at, dmarc_pass)
    VALUES (fid, COALESCE((x->>'uidvalidity')::bigint, 1), u, key, mid, x->>'irt', frm, x->>'name', tos, subj, at, at, 2000,
            COALESCE((x->>'seen')::boolean, false), COALESCE((x->>'flagged')::boolean, false), left(COALESCE(x->>'body', subj), 200), x->>'body',
            CASE WHEN x ? 'body' THEN at END, true)
    RETURNING mail_messages.id INTO newid;
    PERFORM pg_temp.keep(k, newid);
    PERFORM pg_temp.keep('key:' || k, key);
    RETURN newid;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.thr(k text) RETURNS bigint LANGUAGE sql STABLE SECURITY DEFINER AS $$ SELECT thread_id FROM mail_messages WHERE id = pg_temp.i($1) $$;
-- the list's thread ids for the current caller, newest first
CREATE OR REPLACE FUNCTION pg_temp.list(mb text, v text, folder bigint DEFAULT NULL, label bigint DEFAULT NULL, unread boolean DEFAULT false) RETURNS bigint[]
    LANGUAGE sql STABLE AS $$ SELECT COALESCE(array_agg(thread_id ORDER BY last_message_at DESC, thread_id DESC), '{}')
                               FROM ml_mail_list(pg_temp.i($1), $2, $3, $4, $5, NULL, NULL, 100) $$;
CREATE OR REPLACE FUNCTION pg_temp.seq(mb text) RETURNS bigint LANGUAGE sql STABLE SECURITY DEFINER AS $$
    SELECT COALESCE((SELECT seq FROM mail_mailbox_changes WHERE mailbox_id = pg_temp.i($1)), 0) $$;
CREATE OR REPLACE FUNCTION pg_temp.changed(mb text, since bigint) RETURNS bigint[] LANGUAGE sql STABLE AS $$
    SELECT COALESCE(array_agg(thread_id ORDER BY change_seq), '{}') FROM ml_mailbox_changed(pg_temp.i($1), $2, 1000) WHERE NOT gone $$;
CREATE OR REPLACE FUNCTION pg_temp.gone(mb text, since bigint) RETURNS bigint[] LANGUAGE sql STABLE AS $$
    SELECT COALESCE(array_agg(thread_id ORDER BY change_seq), '{}') FROM ml_mailbox_changed(pg_temp.i($1), $2, 1000) WHERE gone $$;
CREATE OR REPLACE FUNCTION pg_temp.send(k text, mb text, s bigint, tos text[], subj text, body text) RETURNS bigint LANGUAGE plpgsql AS $$
DECLARE o bigint;
BEGIN
    o := ml_outbound_submit(pg_temp.i(mb), s, NULL, tos, '{}', '{}', subj, body, NULL, NULL, NULL, CASE WHEN s = 44 THEN 'proof' END, false);
    RETURN pg_temp.keep(k, o);
END$$;
CREATE OR REPLACE FUNCTION pg_temp.backdate(p_sql text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER AS $$
BEGIN   -- test-only: change a row past the guards (session_replication_role = replica skips triggers)
    PERFORM set_config('session_replication_role', 'replica', true);
    EXECUTE p_sql;
    PERFORM set_config('session_replication_role', 'origin', true);
END$$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

-- ================================================================ fixtures (synthetic: subello.example; outsiders partner.example)
SET ROLE malumail_rw;
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner', 'owner@subello.example', 'super_admin', false, 'active', 'admin', '{admin}'),
 (26, 'human', 'SMOKE Priya', 'priya@subello.example', 'user',        false, 'active', 'write', '{user}'),
 (27, 'human', 'SMOKE Marco', 'marco@subello.example', 'user',        false, 'active', 'admin', '{admin,user}'),   -- the role, not a super-admin
 (30, 'human', 'SMOKE Dana',  'dana@subello.example',  'user',        false, 'active', 'write', '{user}'),
 (40, 'agent', 'SMOKE Mail Expert', NULL,             'user',        false, 'active', 'write', '{user}'),
 (42, 'agent', 'SMOKE Mail Clerk',  NULL,             'user',        false, 'active', 'write', '{user}'),
 (43, 'agent', 'SMOKE Ada',         NULL,             'user',        false, 'active', 'admin', '{admin}'),
 (44, 'agent', 'SMOKE Scheduler',   NULL,             'user',        false, 'active', 'write', '{user}');
UPDATE ml_settings SET expert_member_id = 40, clerk_member_id = 42, business_name = 'Subello' WHERE id = 1;
INSERT INTO ml_domains (domain, served, internal, created_by) VALUES ('subello.example', true, true, 1);
SELECT pg_temp.keep('mbP', ml_mailbox_create(1, 'priya@subello.example', 'Priya', 'person', 26));
SELECT pg_temp.keep('mbD', ml_mailbox_create(1, 'dana@subello.example', 'Dana', 'person', 30));
SELECT pg_temp.keep('mbS', ml_mailbox_create(1, 'sched@subello.example', 'Scheduler', 'agent', 44, 26));
SELECT pg_temp.keep('mbSales', ml_mailbox_create(1, 'sales@subello.example', 'Sales', 'shared', NULL));
SELECT pg_temp.keep('mbSupport', ml_mailbox_create(1, 'support@subello.example', 'Support', 'shared', NULL));
SELECT ml_mailbox_intake_set(pg_temp.i('mbSupport'), 1, 'helpdesk');
SELECT pg_temp.folders(pg_temp.i(x)) FROM unnest(ARRAY['mbP', 'mbD', 'mbS', 'mbSales', 'mbSupport']) x;
SELECT ml_permission_grant(pg_temp.i('mbSales'), 1, 'member', 26, 'triage'), ml_permission_grant(pg_temp.i('mbSales'), 1, 'member', 30, 'view'),
       ml_permission_grant(pg_temp.i('mbSupport'), 1, 'member', 26, 'triage'), ml_permission_grant(pg_temp.i('mbSupport'), 1, 'member', 30, 'view');

-- Priya's mailbox: a conversation of two, a starred one, sent, archived, trash, spam, a draft, a snoozed one
SELECT pg_temp.msg('p1', pg_temp.i('mbP'), 'inbox', '<p1@partner.example>', 'jon@partner.example', ARRAY['priya@subello.example'], 'Order 1',
                   '2026-10-01 09:00+00', '{"name": "Jon Park"}');
SELECT pg_temp.msg('p2', pg_temp.i('mbP'), 'inbox', '<p2@partner.example>', 'jon@partner.example', ARRAY['priya@subello.example'], 'Re: Order 1',
                   '2026-10-01 10:00+00', '{"irt": "<p1@partner.example>", "name": "Jon Park"}');
SELECT pg_temp.msg('p3', pg_temp.i('mbP'), 'inbox', '<p3@partner.example>', 'amy@partner.example', ARRAY['priya@subello.example'], 'Invoice 7',
                   '2026-10-02 09:00+00', '{"seen": true, "flagged": true, "body": "the invoice for October"}');
SELECT pg_temp.msg('p4', pg_temp.i('mbP'), 'sent', '<p4@subello.example>', 'priya@subello.example', ARRAY['amy@partner.example'], 'Hello Amy',
                   '2026-10-02 11:00+00', '{"seen": true}');
SELECT pg_temp.msg('p5', pg_temp.i('mbP'), 'archive', '<p5@partner.example>', 'old@partner.example', ARRAY['priya@subello.example'], 'Old news',
                   '2026-09-01 09:00+00', '{"seen": true}');
SELECT pg_temp.msg('p6', pg_temp.i('mbP'), 'trash', '<p6@partner.example>', 'bin@partner.example', ARRAY['priya@subello.example'], 'Bin me',
                   '2026-10-03 09:00+00', '{"seen": true}');
SELECT pg_temp.msg('p7', pg_temp.i('mbP'), 'junk', '<p7@partner.example>', 'spam@partner.example', ARRAY['priya@subello.example'], 'Spam!',
                   '2026-10-03 10:00+00');
SELECT pg_temp.msg('p8', pg_temp.i('mbP'), 'drafts', '<p8@subello.example>', 'priya@subello.example', ARRAY['jon@partner.example'], 'Draft x',
                   '2026-10-03 11:00+00', '{"seen": true}');
SELECT pg_temp.msg('p9', pg_temp.i('mbP'), 'inbox', '<p9@partner.example>', 'later@partner.example', ARRAY['priya@subello.example'], 'Snooze me',
                   '2026-10-04 09:00+00', '{"seen": true}');
SELECT pg_temp.as_member(26);
SELECT ml_thread_snooze(pg_temp.i('mbP'), pg_temp.key('p9'), 26, now() + interval '1 day', pg_temp.f(pg_temp.i('mbP'), 'inbox'));
INSERT INTO tags (slug, label, colour, mailbox_id, created_by) VALUES ('clients', 'Clients', '#336699', pg_temp.i('mbP'), 26);
SELECT pg_temp.keep('tagC', (SELECT id FROM tags WHERE slug = 'clients' AND mailbox_id = pg_temp.i('mbP')));
INSERT INTO record_tags (record_type, record_id, tag_id) VALUES ('mail_message', pg_temp.key('p3'), pg_temp.i('tagC'));
-- an agent's mailbox: a held reply, a conversation a person marks sensitive; Sales and Support: one unread message each
SELECT pg_temp.msg('s1', pg_temp.i('mbS'), 'inbox', '<s1@partner.example>', 'jon@partner.example', ARRAY['sched@subello.example'], 'Meeting',
                   '2026-10-05 09:00+00');
SELECT pg_temp.msg('s2', pg_temp.i('mbS'), 'inbox', '<s2@partner.example>', 'hr@partner.example', ARRAY['sched@subello.example'], 'Salary details',
                   '2026-10-05 10:00+00');
SELECT ml_thread_sensitive(pg_temp.i('mbS'), pg_temp.key('s2'), 26, true);
SELECT pg_temp.msg('sa1', pg_temp.i('mbSales'), 'inbox', '<sa1@partner.example>', 'buyer@partner.example', ARRAY['sales@subello.example'], 'Quote please',
                   '2026-10-05 11:00+00');
SELECT pg_temp.msg('su1', pg_temp.i('mbSupport'), 'inbox', '<su1@partner.example>', 'user@partner.example', ARRAY['support@subello.example'], 'Help',
                   '2026-10-05 12:00+00');

-- ================================================================ 1. the views (mail-list.md)
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(pg_temp.thr('p1') = pg_temp.thr('p2'), 'a reply joins its conversation (db/008, the fixture''s ground)');
SELECT pg_temp.check(pg_temp.list('mbP', 'inbox') = ARRAY[pg_temp.thr('p3'), pg_temp.thr('p1')],
                     'inbox: the conversations with a message in INBOX, newest first, the snoozed one left out');
SELECT pg_temp.check(pg_temp.list('mbP', 'starred') = ARRAY[pg_temp.thr('p3')], 'starred: the flagged conversation');
SELECT pg_temp.check(pg_temp.list('mbP', 'snoozed') = ARRAY[pg_temp.thr('p9')], 'snoozed: the snoozed conversation');
SELECT pg_temp.check(pg_temp.list('mbP', 'sent') = ARRAY[pg_temp.thr('p4')] AND pg_temp.list('mbP', 'drafts') = ARRAY[pg_temp.thr('p8')]
                     AND pg_temp.list('mbP', 'spam') = ARRAY[pg_temp.thr('p7')] AND pg_temp.list('mbP', 'trash') = ARRAY[pg_temp.thr('p6')],
                     'sent, drafts, spam and trash: their folders');
SELECT pg_temp.check(pg_temp.list('mbP', 'all') @> ARRAY[pg_temp.thr('p1'), pg_temp.thr('p3'), pg_temp.thr('p4'), pg_temp.thr('p5'), pg_temp.thr('p8'), pg_temp.thr('p9')]
                     AND cardinality(pg_temp.list('mbP', 'all')) = 6, 'all mail: everything but Trash and Spam (the archived and the snoozed included)');
SELECT pg_temp.check(pg_temp.list('mbP', NULL, pg_temp.f(pg_temp.i('mbP'), 'archive')) = ARRAY[pg_temp.thr('p5')], 'a folder: its conversations');
SELECT pg_temp.check(pg_temp.list('mbP', NULL, NULL, pg_temp.i('tagC')) = ARRAY[pg_temp.thr('p3')], 'a label: the conversations carrying it');
SELECT pg_temp.check(pg_temp.list('mbP', 'inbox', NULL, NULL, true) = ARRAY[pg_temp.thr('p1')], 'unread only: the conversation with an unseen message');
SELECT pg_temp.check((SELECT message_count = 2 AND unread AND names->>'jon@partner.example' = 'Jon Park' AND latest_message = pg_temp.key('p2')
                        FROM ml_mail_list(pg_temp.i('mbP'), 'inbox', NULL, NULL, false, NULL, NULL, 50) WHERE thread_id = pg_temp.thr('p1')),
                     'a row: its count, unread, the names its messages give, the latest message''s key');
SELECT pg_temp.check((SELECT labels->0->>'label' = 'Clients' AND flagged FROM ml_mail_list(pg_temp.i('mbP'), 'inbox', NULL, NULL, false, NULL, NULL, 50)
                       WHERE thread_id = pg_temp.thr('p3')), 'a row carries its labels and its star');
SELECT pg_temp.refused($$SELECT * FROM ml_mail_list(pg_temp.i('mbP'), 'everything', NULL, NULL, false, NULL, NULL, 50)$$, 'No such view', 'an unknown view is refused');
-- the cursor
SELECT pg_temp.check((SELECT array_agg(thread_id) FROM ml_mail_list(pg_temp.i('mbP'), 'inbox', NULL, NULL, false, NULL, NULL, 1)) = ARRAY[pg_temp.thr('p3')]
                     AND (SELECT array_agg(thread_id) FROM ml_mail_list(pg_temp.i('mbP'), 'inbox', NULL, NULL, false,
                            (SELECT last_message_at FROM mail_threads WHERE id = pg_temp.thr('p3')), pg_temp.thr('p3'), 1)) = ARRAY[pg_temp.thr('p1')],
                     'keyset paging: page one, then the page after its last row');
SELECT pg_temp.msg('p10', pg_temp.i('mbP'), 'inbox', '<p10@partner.example>', 'new@partner.example', ARRAY['priya@subello.example'], 'Brand new',
                   '2026-10-06 09:00+00');
SELECT pg_temp.check((SELECT array_agg(thread_id) FROM ml_mail_list(pg_temp.i('mbP'), 'inbox', NULL, NULL, false,
                        (SELECT last_message_at FROM mail_threads WHERE id = pg_temp.thr('p3')), pg_temp.thr('p3'), 5)) = ARRAY[pg_temp.thr('p1')],
                     'new mail never shifts a later page (it comes in through the "N new" bar)');
SELECT pg_temp.check(ml_mail_list_total(pg_temp.i('mbP'), 'inbox', NULL, NULL, false) = 3 AND ml_mail_list_total(pg_temp.i('mbP'), 'all', NULL, NULL, false) = 7,
                     'the total of a view');
-- outbound views
SELECT pg_temp.send('o1', 'mbP', 26, ARRAY['jon@partner.example'], 'Later', 'goes tomorrow');
SELECT pg_temp.backdate(format($$UPDATE outbound_email SET send_after = now() + interval '1 day' WHERE id = %s$$, pg_temp.i('o1')));
SELECT pg_temp.check((SELECT array_agg(outbound_id) FROM ml_mail_list(pg_temp.i('mbP'), 'scheduled', NULL, NULL, false, NULL, NULL, 50)) = ARRAY[pg_temp.i('o1')],
                     'scheduled: a person''s send due tomorrow');
SELECT pg_temp.as_member(44);
SELECT pg_temp.send('o2', 'mbS', 44, ARRAY['new@partner.example'], 'Hello', 'an agent''s first contact');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT outbound_status = 'held' AND agent_badge = 'awaiting review' AND 'first_contact' = ANY (hold_reasons)
                        FROM ml_mail_list(pg_temp.i('mbS'), 'outbox', NULL, NULL, false, NULL, NULL, 50) WHERE outbound_id = pg_temp.i('o2')),
                     'outbox: an agent''s held message, its reasons, "awaiting review" — read by its overseer');

-- ================================================================ 2. counts and unread per viewer
SELECT pg_temp.check((SELECT (c->>'inbox_unread')::int = 2 AND (c->>'inbox_total')::int = 3 AND (c->>'starred')::int = 1 AND (c->>'snoozed')::int = 1
                             AND (c->>'drafts')::int = 1 AND (c->>'scheduled')::int = 1 AND (c->>'spam')::int = 1 AND c->>'next_wake' IS NOT NULL
                        FROM ml_mailbox_counts(pg_temp.i('mbP')) c), 'the counts of a mailbox (the rail and mailbox_overview)');
SELECT pg_temp.check((ml_mailbox_counts(pg_temp.i('mbS'))->>'held')::int = 1, 'held mail counted on the agent''s mailbox');
SELECT pg_temp.check(ml_unread_count(pg_temp.i('mbSales')) = 1, 'Sales, a triage holder: one unread (the server''s flag)');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(ml_unread_count(pg_temp.i('mbSales')) = 1, 'Sales, a view holder: one unread too');
SELECT ml_viewer_read(30, pg_temp.key('sa1'));
SELECT pg_temp.check(ml_unread_count(pg_temp.i('mbSales')) = 0 AND (SELECT NOT unread FROM ml_mail_list(pg_temp.i('mbSales'), 'inbox', NULL, NULL, false, NULL, NULL, 5)),
                     'the view holder reads it: unread is gone for them (their own mark)');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(ml_unread_count(pg_temp.i('mbSales')) = 1 AND (SELECT NOT seen FROM mail_messages WHERE id = pg_temp.i('sa1')),
                     '…and stays for the triage holder; nothing reached the server''s flag');
SELECT ml_viewer_read(26, pg_temp.key('su1'));
SELECT pg_temp.check(ml_unread_count(pg_temp.i('mbSupport')) = 0, 'an intake mailbox: even a triage holder''s read is their own mark (D13)');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(ml_unread_count(pg_temp.i('mbSupport')) = 1 AND (SELECT NOT seen FROM mail_messages WHERE id = pg_temp.i('su1')),
                     '…so another person still sees it unread, and the server never saw \Seen');

-- ================================================================ 3. the poll's counter and tombstones
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('seq0', ml_mailbox_seq(pg_temp.i('mbP')));
SELECT pg_temp.check(pg_temp.i('seq0') > 0 AND ml_mailbox_seq(pg_temp.i('mbP')) = pg_temp.i('seq0') AND pg_temp.changed('mbP', pg_temp.i('seq0')) = '{}',
                     'nothing changed: the same number, no rows (the poll answers 204)');
SELECT pg_temp.msg('p11', pg_temp.i('mbP'), 'inbox', '<p11@partner.example>', 'news@partner.example', ARRAY['priya@subello.example'], 'Newest',
                   '2026-10-07 09:00+00');
SELECT pg_temp.check(ml_mailbox_seq(pg_temp.i('mbP')) > pg_temp.i('seq0') AND pg_temp.thr('p11') = ANY (pg_temp.changed('mbP', pg_temp.i('seq0'))),
                     'new mail moves the number and names its conversation');
SELECT pg_temp.keep('seq1', ml_mailbox_seq(pg_temp.i('mbP')));
UPDATE mail_messages SET seen = true WHERE id = pg_temp.i('p1');
SELECT pg_temp.check(pg_temp.changed('mbP', pg_temp.i('seq1')) = ARRAY[pg_temp.thr('p1')], 'a flag changed by another client: that conversation alone');
SELECT pg_temp.keep('seq2', ml_mailbox_seq(pg_temp.i('mbP')));
INSERT INTO record_tags (record_type, record_id, tag_id) VALUES ('mail_message', pg_temp.key('p10'), pg_temp.i('tagC'));
SELECT pg_temp.check(pg_temp.changed('mbP', pg_temp.i('seq2')) = ARRAY[pg_temp.thr('p10')], 'a label put on: its conversation changed');
SELECT pg_temp.keep('seq3', ml_mailbox_seq(pg_temp.i('mbP')));
SELECT ml_thread_mute(pg_temp.i('mbP'), pg_temp.key('p11'), 26, true);
SELECT pg_temp.check(pg_temp.changed('mbP', pg_temp.i('seq3')) = ARRAY[pg_temp.thr('p11')], 'a conversation''s state (mute): changed');
SELECT pg_temp.keep('seq4', ml_mailbox_seq(pg_temp.i('mbP')));
SELECT ml_viewer_read(26, pg_temp.key('p10'));
SELECT pg_temp.check(pg_temp.changed('mbP', pg_temp.i('seq4')) = ARRAY[pg_temp.thr('p10')], 'a person''s own read mark: changed');
SELECT pg_temp.keep('seq5', ml_mailbox_seq(pg_temp.i('mbP')));
SELECT pg_temp.keep('t6', pg_temp.thr('p6'));
DELETE FROM mail_messages WHERE id = pg_temp.i('p6');
SELECT pg_temp.check(pg_temp.gone('mbP', pg_temp.i('seq5')) = ARRAY[pg_temp.i('t6')] AND NOT EXISTS (SELECT 1 FROM mail_threads WHERE id = pg_temp.i('t6')),
                     'an expunged message empties its conversation: a tombstone names it');
SELECT pg_temp.keep('seq6', ml_mailbox_seq(pg_temp.i('mbP')));
SELECT pg_temp.send('o3', 'mbP', 26, ARRAY['amy@partner.example'], 'Re: Hello Amy', 'quick note');
SELECT pg_temp.check(ml_mailbox_seq(pg_temp.i('mbP')) > pg_temp.i('seq6'), 'an outbound row moves the number (the Scheduled and Outbox views)');
-- a bulk write moves the counter once
SELECT pg_temp.keep('seq7', ml_mailbox_seq(pg_temp.i('mbP')));
SELECT set_config('app.ml_bulk', 'on', true);
SELECT pg_temp.msg('p12', pg_temp.i('mbP'), 'inbox', '<p12@partner.example>', 'bulk@partner.example', ARRAY['priya@subello.example'], 'Bulk one',
                   '2026-10-07 10:00+00');
SELECT ml_threads_rebuild(pg_temp.i('mbP'));
SELECT pg_temp.check(ml_mailbox_seq(pg_temp.i('mbP')) = pg_temp.i('seq7'), 'a bulk write and a rebuild leave the number alone while they run');
SELECT ml_mailbox_touch(pg_temp.i('mbP'), '{}');
SELECT pg_temp.check(ml_mailbox_seq(pg_temp.i('mbP')) = pg_temp.i('seq7') + 1
                     AND pg_temp.thr('p12') = ANY (pg_temp.changed('mbP', pg_temp.i('seq7')))
                     AND cardinality(pg_temp.gone('mbP', pg_temp.i('seq7'))) > 0,
                     '…then one touch moves it once: the rebuilt conversations changed, the old ones tombstoned');
SELECT pg_temp.check(pg_temp.list('mbP', NULL, NULL, pg_temp.i('tagC')) @> ARRAY[pg_temp.thr('p3')] AND pg_temp.list('mbP', 'snoozed') = ARRAY[pg_temp.thr('p9')],
                     'after the rebuild the labels and the snooze are found again (they key on messages)');
SELECT pg_temp.keep('seq8', ml_mailbox_seq(pg_temp.i('mbP')));
SELECT pg_temp.keep('seq9', ml_mailbox_touch(pg_temp.i('mbP'), ARRAY[pg_temp.thr('p3')]));
SELECT pg_temp.check(pg_temp.i('seq9') > pg_temp.i('seq8') AND pg_temp.changed('mbP', pg_temp.i('seq8')) = ARRAY[pg_temp.thr('p3')],
                     'ml_mailbox_touch stamps the conversations it is given');

-- ================================================================ 4. folder reset and a move keep keys and state
SELECT pg_temp.keep('k3', pg_temp.key('p3'));
SELECT ml_thread_mute(pg_temp.i('mbP'), pg_temp.key('p3'), 26, true);
SELECT pg_temp.keep('reset', ml_folder_reset(pg_temp.f(pg_temp.i('mbP'), 'inbox'), 2));
SELECT pg_temp.check(pg_temp.i('reset') = 7
                     AND (SELECT uidvalidity = 2 AND uidnext IS NULL AND highestmodseq IS NULL AND messages_total = 0 FROM mail_folders WHERE id = pg_temp.f(pg_temp.i('mbP'), 'inbox'))
                     AND NOT EXISTS (SELECT 1 FROM mail_messages WHERE folder_id = pg_temp.f(pg_temp.i('mbP'), 'inbox')),
                     'a UIDVALIDITY change: the folder''s rows go, the folder awaits its re-read');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mail_message_keys WHERE id = pg_temp.i('k3'))
                     AND EXISTS (SELECT 1 FROM record_tags WHERE record_id = pg_temp.i('k3'))
                     AND EXISTS (SELECT 1 FROM mail_thread_states WHERE anchor_key_id = pg_temp.i('k3') AND muted),
                     '…the message keys, the labels and the conversation states stay');
SELECT pg_temp.msg('p3b', pg_temp.i('mbP'), 'inbox', '<p3@partner.example>', 'amy@partner.example', ARRAY['priya@subello.example'], 'Invoice 7',
                   '2026-10-02 09:00+00', '{"seen": true, "flagged": true, "uid": 41, "uidvalidity": 2, "body": "the invoice for October"}');
SELECT pg_temp.check(pg_temp.key('p3b') = pg_temp.i('k3')
                     AND (SELECT labels->0->>'label' = 'Clients' FROM ml_mail_list(pg_temp.i('mbP'), 'inbox', NULL, NULL, false, NULL, NULL, 50) WHERE thread_id = pg_temp.thr('p3b'))
                     AND (SELECT muted FROM ml_thread_state(pg_temp.thr('p3b'))),
                     'the re-read message finds its key again, and with it its label and its mute');
SELECT ml_message_moved(pg_temp.i('p5'), pg_temp.f(pg_temp.i('mbP'), 'inbox'), 2, 900);
SELECT pg_temp.check((SELECT folder_id = pg_temp.f(pg_temp.i('mbP'), 'inbox') AND uid = 900 AND uidvalidity = 2 FROM mail_messages WHERE id = pg_temp.i('p5'))
                     AND pg_temp.key('p5') = (SELECT message_key_id FROM mail_messages WHERE id = pg_temp.i('p5')),
                     'a move by COPYUID: the same row and key at the new place');
SELECT pg_temp.refused(format($$SELECT ml_message_moved(%s, %s, 1, 5)$$, pg_temp.i('p5'), pg_temp.f(pg_temp.i('mbD'), 'inbox')), 'within its own mailbox',
                       'a message never moves into another mailbox');
SELECT pg_temp.refused(format($$SELECT ml_folder_reset(%s, 0)$$, pg_temp.f(pg_temp.i('mbP'), 'sent')), 'positive', 'a UIDVALIDITY is a positive number');

-- ================================================================ 5. the admin's functions
SELECT pg_temp.as_member(1);
CREATE TEMP TABLE saved (d jsonb); GRANT ALL ON saved TO PUBLIC;
INSERT INTO saved SELECT ml_settings_save(1, '{"hold_all_agents": true, "undo_send_seconds": 5, "images_default": "ask"}');
SELECT pg_temp.check((SELECT d ? 'hold_all_agents' AND d ? 'undo_send_seconds' AND NOT d ? 'images_default' FROM saved)
                     AND (SELECT hold_all_agents AND undo_send_seconds = 5 FROM ml_settings WHERE id = 1),
                     'a Mail admin saves settings; the answer names only what changed');
SELECT pg_temp.check('mode' = ANY (ml_outbound_hold_reasons(pg_temp.i('mbS'), 44, ARRAY['x@subello.example'], '{}', '{}', false, NULL))
                     AND 'hold_all_agents' = ANY (ml_outbound_hold_reasons(pg_temp.i('mbS'), 44, ARRAY['x@subello.example'], '{}', '{}', false, NULL)),
                     'the switch holds every agent''s message at once');
SELECT pg_temp.check((SELECT (d->'disclosure_text'->>'after')::int = length('Written by {agent}, the AI of {business}.')
                                AND NOT d::text LIKE '%Written by%'
                           FROM ml_settings_save(1, '{"disclosure_text": "Written by {agent}, the AI of {business}."}') d),
                     'the disclosure text is logged by its length, never its words');
SELECT pg_temp.refused($$SELECT ml_settings_save(1, '{"notice_text": "x"}')$$, 'Not a setting saved here', 'a key outside the allow-list is refused by name');
SELECT pg_temp.refused($$SELECT ml_settings_save(1, '{"undo_send_seconds": 99}')$$, 'check', 'a value out of range is refused by the table''s check');
SELECT pg_temp.refused($$SELECT ml_settings_save(26, '{"hold_all_agents": false}')$$, 'Only a Mail admin', 'a Mail user cannot save settings');
SELECT pg_temp.refused($$SELECT ml_settings_save(27, '{"hold_all_agents": false}')$$, 'Only a Mail admin', 'the Mail admin ROLE without being a super-admin cannot');
SELECT pg_temp.refused($$SELECT ml_settings_save(43, '{"hold_all_agents": false}')$$, 'agent never', 'an agent given the role cannot');
SELECT pg_temp.check(ml_notice_save(1, 'How mail is looked after here, in plain words: the second version of the notice.') = 2, 'a new notice is a new version');
SELECT pg_temp.refused($$SELECT ml_notice_save(26, 'How mail is looked after here, in plain words, by someone who may not.')$$, 'Only a Mail admin', 'a Mail user cannot write the notice');
SELECT pg_temp.refused($$SELECT ml_notice_save(40, 'How mail is looked after here, in plain words, by an agent who may not.')$$, 'agent never', 'nor can an agent');
SELECT pg_temp.keep('reindexed', ml_mailbox_reindex(pg_temp.i('mbP'), 1));
SELECT pg_temp.check(pg_temp.i('reindexed') = 1 AND NOT EXISTS (SELECT 1 FROM mail_messages WHERE mailbox_id = pg_temp.i('mbP') AND text_indexed_at IS NOT NULL),
                     're-index: the mailbox''s text emptied for the text pass to refill');
SELECT pg_temp.refused(format($$SELECT ml_mailbox_reindex(%s, 26)$$, pg_temp.i('mbP')), 'Only a Mail admin', 'a Mail user cannot re-index');
INSERT INTO agent_dispatches (agent_member_id, kind, via, status, mailbox_id) VALUES (44, 'mail', 'wake', 'failed', pg_temp.i('mbS'));
SELECT pg_temp.keep('disp', (SELECT max(id) FROM agent_dispatches));
SELECT ml_dispatch_retry(pg_temp.i('disp'), 1);
SELECT pg_temp.check((SELECT status = 'queued' AND attempts = 1 FROM agent_dispatches WHERE id = pg_temp.i('disp')), 'a failed mail turn retried: queued, no attempt counted');
SELECT pg_temp.refused(format($$SELECT ml_dispatch_retry(%s, 1)$$, pg_temp.i('disp')), 'Only a failed', 'only a failed one is retried');
SELECT pg_temp.refused(format($$SELECT ml_dispatch_retry(%s, 26)$$, pg_temp.i('disp')), 'Only a Mail admin', 'a Mail user cannot retry');
SELECT pg_temp.refused(format($$SELECT ml_dispatch_retry(%s, 44)$$, pg_temp.i('disp')), 'agent never', 'an agent cannot retry its own turn');
SELECT pg_temp.check((SELECT count(*) FROM ml_sync_health()) = 5 AND (SELECT messages > 0 FROM ml_sync_health() WHERE mailbox_id = pg_temp.i('mbP'))
                     AND NOT EXISTS (SELECT 1 FROM information_schema.routines r JOIN information_schema.parameters p ON p.specific_name = r.specific_name
                                      WHERE r.routine_name = 'ml_sync_health' AND p.parameter_mode = 'OUT' AND p.parameter_name IN ('subject', 'from_address', 'body_text')),
                     'sync health: every mailbox for a Mail admin, counts only (no subject, no correspondent)');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_sync_health()), '…and nothing for anyone else');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_sync_health()), '…not for the Mail admin role without a super-admin either');

-- ================================================================ 6. the wall
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(pg_temp.list('mbP', 'inbox') = '{}' AND ml_mail_list_total(pg_temp.i('mbP'), 'all', NULL, NULL, false) = 0
                     AND ml_mailbox_counts(pg_temp.i('mbP')) IS NULL AND ml_unread_count(pg_temp.i('mbP')) IS NULL
                     AND ml_mailbox_seq(pg_temp.i('mbP')) IS NULL AND NOT EXISTS (SELECT 1 FROM ml_mailbox_changed(pg_temp.i('mbP'), 0, 100)),
                     'a colleague sees nothing of a person''s mailbox through any of them');
SELECT pg_temp.check(pg_temp.list('mbP', NULL, NULL, pg_temp.i('tagC')) = '{}' AND pg_temp.list('mbP', NULL, pg_temp.f(pg_temp.i('mbP'), 'inbox')) = '{}',
                     '…nor by its folder or its label');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(pg_temp.list('mbP', 'inbox') = '{}' AND ml_mailbox_counts(pg_temp.i('mbP')) IS NULL,
                     'a Mail admin sees nothing of a person''s mailbox by holding the role');
SELECT pg_temp.check(cardinality(pg_temp.list('mbS', 'inbox')) = 2, '…and the whole of an agent''s, sensitive included (a person)');
SELECT pg_temp.as_member(44);
SELECT pg_temp.check(pg_temp.list('mbS', 'inbox') = ARRAY[pg_temp.thr('s1')]
                     AND NOT pg_temp.thr('s2') = ANY (COALESCE((SELECT array_agg(thread_id) FROM ml_mailbox_changed(pg_temp.i('mbS'), 0, 100)), '{}'))
                     AND ml_mail_list_total(pg_temp.i('mbS'), 'inbox', NULL, NULL, false) = 1,
                     'the agent never sees the conversation a person marked sensitive — not in its list, its total or its poll');
SELECT pg_temp.as_member(40); SELECT pg_temp.behalf(26);
SELECT pg_temp.check(pg_temp.list('mbP', 'inbox') = '{}', 'the Mail Expert for Priya: nothing of her mailbox while ai_read is off');
RESET ROLE;
UPDATE mailboxes SET ai_read = true WHERE id = pg_temp.i('mbP');
SET ROLE malumail_rw;
SELECT pg_temp.as_member(40); SELECT pg_temp.behalf(26);
SELECT pg_temp.check(cardinality(pg_temp.list('mbP', 'inbox')) >= 2, '…and her Inbox once she switches it on (§3)');
SELECT pg_temp.behalf(30);
SELECT pg_temp.check(pg_temp.list('mbP', 'inbox') = '{}', 'the Mail Expert for Dana never reads Priya''s mailbox');
SELECT pg_temp.behalf(NULL);
-- the roles
RESET ROLE;
SET ROLE malumail_records_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(cardinality(pg_temp.list('mbP', 'inbox')) >= 2 AND (ml_mailbox_counts(pg_temp.i('mbP'))->>'starred')::int = 1,
                     'the records role reads the list and the counts (the mail_list and mailbox_overview tools)');
SELECT pg_temp.refused(format($$SELECT ml_mailbox_touch(%s, '{}')$$, pg_temp.i('mbP')), 'permission denied', 'the records role cannot move the counter');
SELECT pg_temp.refused($$SELECT ml_settings_save(1, '{"hold_all_agents": false}')$$, 'permission denied', 'the records role cannot save settings');
RESET ROLE;
SELECT pg_temp.check(NOT has_function_privilege('malumail_activity_ro', 'ml_mail_list(bigint, text, bigint, bigint, boolean, timestamptz, bigint, integer)', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_activity_ro', 'ml_folder_reset(bigint, bigint)', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_records_ro', 'ml_folder_reset(bigint, bigint)', 'EXECUTE'),
                     'nobody else executes them (revoked from PUBLIC)');

\o
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || n || '  ' || label FROM proof ORDER BY n;
SELECT (SELECT count(*) FROM proof WHERE ok) || ' ok, ' || (SELECT count(*) FROM proof WHERE NOT ok) || ' failed' AS result;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the list and sync proof failed'; END IF; END $$;
ROLLBACK;
