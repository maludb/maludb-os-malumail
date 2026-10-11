-- Phase 1 proof of db/014_reading_search.sql (docs/build-specs/reading-and-rendering.md, reading.md, search-labels-snooze.md,
-- correspondents.md). phase0_proof.sql's shape: ONE transaction ROLLED BACK; fixtures written as malumail_rw, reads as malumail_records_ro,
-- the acting member set through app.member_id exactly as PHP and the MCP servers set it. Synthetic fixtures only: the business is
-- subello.example, outsiders partner.example / list.example / else.example. Run as postgres on a SCRATCH database after db/014:
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d ml_proof -f db/proof/phase1_reading.sql; echo "exit $?"
-- Prints one line per check (ok / FAIL) and the count; exits non-zero when any check failed.
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
CREATE TEMP TABLE ids (k text PRIMARY KEY, id bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS bigint LANGUAGE sql AS $$ INSERT INTO ids (k, id) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET id = EXCLUDED.id RETURNING id $$;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.key(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM ids WHERE ids.k = 'key:' || $1 $$;
-- the message ids of fixture keys, sorted
CREATE OR REPLACE FUNCTION pg_temp.m(VARIADIC ks text[]) RETURNS bigint[] LANGUAGE sql STABLE AS $$
    SELECT COALESCE(array_agg(id ORDER BY id), '{}') FROM ids WHERE k = ANY ($1) $$;
CREATE OR REPLACE FUNCTION pg_temp.folders(mb bigint) RETURNS void LANGUAGE sql AS $$
    INSERT INTO mail_folders (mailbox_id, name, special_use, uidvalidity, uidnext) VALUES
      (mb, 'INBOX', 'inbox', 1, 1), (mb, 'Sent', 'sent', 1, 1), (mb, 'Drafts', 'drafts', 1, 1), (mb, 'Trash', 'trash', 1, 1),
      (mb, 'Junk', 'junk', 1, 1), (mb, 'Archive', 'archive', 1, 1);
$$;
CREATE OR REPLACE FUNCTION pg_temp.f(mb bigint, su text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM mail_folders WHERE mailbox_id = mb AND special_use = su $$;
-- a message on the server, as the mail service writes it (x: irt, refs[], cc[], bcc[], dmarc, local, list, seen, flagged, answered, att, names[], body, size)
CREATE OR REPLACE FUNCTION pg_temp.msg(k text, mb bigint, su text, mid text, frm text, tos text[], subj text, at timestamptz, x jsonb DEFAULT '{}') RETURNS bigint
    LANGUAGE plpgsql AS $$
DECLARE fid bigint := pg_temp.f(mb, su); key bigint; u bigint; newid bigint;
BEGIN
    key := ml_message_key_for(mb, mid, ml_envelope_digest(at::text, frm, array_to_string(tos, ','), '', subj));
    u := (SELECT COALESCE(max(m.uid), 0) + 1 FROM mail_messages m WHERE m.folder_id = fid);
    INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, in_reply_to, references_ids, from_address, from_name,
                               to_addresses, cc_addresses, bcc_addresses, subject, sent_at, received_at, size, seen, flagged, answered, has_attachments,
                               attachment_names, dmarc_pass, is_list, snippet, body_text, local_submission)
    VALUES (fid, 1, u, key, mid, x->>'irt', COALESCE(ARRAY(SELECT jsonb_array_elements_text(x->'refs')), '{}'), frm, x->>'name', tos,
            COALESCE(ARRAY(SELECT jsonb_array_elements_text(x->'cc')), '{}'), COALESCE(ARRAY(SELECT jsonb_array_elements_text(x->'bcc')), '{}'),
            subj, at, at, COALESCE((x->>'size')::integer, 2000), COALESCE((x->>'seen')::boolean, false), COALESCE((x->>'flagged')::boolean, false),
            COALESCE((x->>'answered')::boolean, false), COALESCE((x->>'att')::boolean, false), COALESCE(ARRAY(SELECT jsonb_array_elements_text(x->'names')), '{}'),
            CASE WHEN x ? 'dmarc' THEN (x->>'dmarc')::boolean ELSE true END, COALESCE((x->>'list')::boolean, false),
            left(COALESCE(x->>'body', subj), 200), x->>'body', COALESCE((x->>'local')::boolean, false))
    RETURNING mail_messages.id INTO newid;
    PERFORM pg_temp.keep(k, newid);
    PERFORM pg_temp.keep('key:' || k, key);
    RETURN newid;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.at(k text) RETURNS timestamptz LANGUAGE sql STABLE SECURITY DEFINER AS $$ SELECT received_at FROM mail_messages WHERE id = pg_temp.i($1) $$;
CREATE OR REPLACE FUNCTION pg_temp.thr(k text) RETURNS bigint LANGUAGE sql STABLE SECURITY DEFINER AS $$ SELECT thread_id FROM mail_messages WHERE id = pg_temp.i($1) $$;
-- search through db/008's signature (an offset) and through the cursor one, answering message ids sorted
CREATE OR REPLACE FUNCTION pg_temp.s(q text, mb text DEFAULT NULL) RETURNS bigint[] LANGUAGE sql STABLE AS $$
    SELECT COALESCE(array_agg(message_id ORDER BY message_id), '{}') FROM ml_search($1, pg_temp.i($2), 500, 0) $$;
CREATE OR REPLACE FUNCTION pg_temp.sc(q text, mb text DEFAULT NULL) RETURNS bigint[] LANGUAGE sql STABLE AS $$
    SELECT COALESCE(array_agg(message_id ORDER BY message_id), '{}') FROM ml_search($1, pg_temp.i($2), 500, NULL::timestamptz) $$;
-- every page of a search, `per` at a time, through the cursor: the ids in the order they came
CREATE OR REPLACE FUNCTION pg_temp.pages(q text, mb text, per integer) RETURNS bigint[] LANGUAGE plpgsql STABLE AS $$
DECLARE out bigint[] := '{}'; b timestamptz := NULL; bid bigint := NULL; r record; n integer;
BEGIN
    LOOP
        n := 0;
        FOR r IN SELECT * FROM ml_search(q, pg_temp.i(mb), per, b, bid) LOOP
            out := out || r.message_id; b := r.received_at; bid := r.message_id; n := n + 1;
        END LOOP;
        EXIT WHEN n < per;
    END LOOP;
    RETURN out;
END$$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

-- ================================================================ fixtures
SET ROLE malumail_rw;
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',  'owner@subello.example', 'super_admin', false, 'active', 'admin', '{admin}'),   -- a Mail admin
 (26, 'human', 'SMOKE Priya',  'priya@subello.example', 'user',        false, 'active', 'write', '{user}'),    -- owns mbP; overseer of the Scheduler
 (28, 'human', 'SMOKE Bea',    'bea@subello.example',   'user',        false, 'active', 'write', '{user}'),    -- view on mbP
 (30, 'human', 'SMOKE Dana',   'dana@subello.example',  'user',        false, 'active', 'write', '{user}'),    -- owns mbD; send on Sales
 (44, 'agent', 'SMOKE Scheduler', NULL,                 'user',        false, 'active', 'write', '{user}');    -- owns mbS
INSERT INTO ml_domains (domain, served, internal, created_by) VALUES ('subello.example', true, true, 1);
SELECT pg_temp.keep('mbP', ml_mailbox_create(1, 'priya@subello.example', 'Priya', 'person', 26));
SELECT pg_temp.keep('mbD', ml_mailbox_create(1, 'dana@subello.example', 'Dana', 'person', 30));
SELECT pg_temp.keep('mbS', ml_mailbox_create(1, 'sched@subello.example', 'Scheduler', 'agent', 44, 26));
SELECT pg_temp.keep('mbSales', ml_mailbox_create(1, 'sales@subello.example', 'Sales', 'shared', NULL));
SELECT pg_temp.keep('mbSupport', ml_mailbox_create(1, 'support@subello.example', 'Support', 'shared', NULL));
SELECT pg_temp.folders(pg_temp.i(x)) FROM unnest(ARRAY['mbP', 'mbD', 'mbS', 'mbSales', 'mbSupport']) x;
SELECT ml_permission_grant(pg_temp.i('mbP'), 26, 'member', 28, 'view');
SELECT ml_permission_grant(pg_temp.i('mbSales'), 1, 'member', 30, 'send');

-- Priya's mailbox
SELECT pg_temp.msg('p1', pg_temp.i('mbP'), 'inbox', '<p1@partner.example>', 'jon@partner.example', ARRAY['priya@subello.example'], 'Delivery date',
                   now() - interval '3 days', '{"att": true, "names": ["contract.pdf"], "size": 50000, "flagged": true, "answered": true,
                   "body": "the delivery date moves to friday", "name": "Jon Partner"}');
SELECT pg_temp.msg('p2', pg_temp.i('mbP'), 'sent', '<p2@subello.example>', 'priya@subello.example', ARRAY['jon@partner.example'], 'Re: Delivery date',
                   now() - interval '3 days' + interval '1 hour', '{"irt": "<p1@partner.example>", "refs": ["<p1@partner.example>"], "seen": true,
                   "body": "friday is fine"}');
SELECT pg_temp.msg('p3', pg_temp.i('mbP'), 'inbox', '<p3@list.example>', 'news@list.example', ARRAY['priya@subello.example'], 'Weekly news',
                   now() - interval '10 days', '{"list": true, "seen": true, "size": 1000, "body": "this week in widgets"}');
SELECT pg_temp.msg('p4', pg_temp.i('mbP'), 'inbox', '<p4@partner.example>', 'jon@partner.example', ARRAY['priya@subello.example'], 'Invoice attached',
                   now() - interval '26 hours', '{"dmarc": false, "body": "please pay the invoice"}');
SELECT pg_temp.msg('p5', pg_temp.i('mbP'), 'inbox', '<p5@subello.example>', 'dana@subello.example', ARRAY['priya@subello.example'], 'Lunch',
                   now() - interval '2 days', '{"dmarc": null, "local": true, "body": "lunch at noon"}');
SELECT pg_temp.msg('p6', pg_temp.i('mbP'), 'trash', '<p6@else.example>', 'old@else.example', ARRAY['priya@subello.example'], 'Old thing',
                   now() - interval '20 days', '{"seen": true, "body": "an old thing"}');
SELECT pg_temp.msg('p7', pg_temp.i('mbP'), 'sent', '<p7@subello.example>', 'priya@subello.example', ARRAY['ops@partner.example'], 'Quietly',
                   now() - interval '4 days', '{"seen": true, "bcc": ["secret@else.example"], "body": "a quiet note", "cc": ["copy@partner.example"]}');
SELECT pg_temp.msg('p8a', pg_temp.i('mbP'), 'inbox', '<p8a@partner.example>', 'twins@partner.example', ARRAY['priya@subello.example'], 'Twin A',
                   date_trunc('second', now()) - interval '12 hours', '{"body": "twin"}');
SELECT pg_temp.msg('p8b', pg_temp.i('mbP'), 'inbox', '<p8b@partner.example>', 'twins@partner.example', ARRAY['priya@subello.example'], 'Twin B',
                   date_trunc('second', now()) - interval '12 hours', '{"body": "twin"}');
SELECT pg_temp.msg('p9', pg_temp.i('mbP'), 'inbox', '<p9@subello.example>', 'dana@subello.example', ARRAY['priya@subello.example'], 'Spoofed lunch',
                   now() - interval '5 hours', '{"dmarc": null, "local": false, "body": "a forged colleague"}');
-- the Scheduler's mailbox (an agent's), Sales (shared), Support (shared, nobody's here)
SELECT pg_temp.msg('s1', pg_temp.i('mbS'), 'inbox', '<s1@partner.example>', 'jon@partner.example', ARRAY['sched@subello.example'], 'Agent thread private',
                   now() - interval '2 days', '{"body": "a private matter"}');
SELECT pg_temp.msg('s2', pg_temp.i('mbS'), 'inbox', '<s2@partner.example>', 'jon@partner.example', ARRAY['sched@subello.example'], 'Agent thread open',
                   now() - interval '6 hours', '{"body": "an open matter"}');
SELECT pg_temp.msg('q1', pg_temp.i('mbSales'), 'inbox', '<q1@partner.example>', 'jon@partner.example', ARRAY['sales@subello.example'], 'Quote request',
                   now() - interval '30 days', '{"body": "a quote please"}');
SELECT pg_temp.msg('q2', pg_temp.i('mbSales'), 'inbox', '<q2@partner.example>', 'jon@partner.example', ARRAY['sales@subello.example'], 'Quote follow-up',
                   now() - interval '1 hour', '{"body": "still waiting"}');
SELECT pg_temp.msg('h1', pg_temp.i('mbSupport'), 'inbox', '<h1@partner.example>', 'jon@partner.example', ARRAY['support@subello.example'], 'Help needed',
                   now() - interval '1 hour', '{"body": "help"}');
-- correspondents, as the sync and the outbox keep them
SELECT ml_correspondent_received(pg_temp.i('mbP'), 'jon@partner.example', 'Jon Partner'), ml_correspondent_sent(pg_temp.i('mbP'), 'jon@partner.example', 'Jon Partner', 26),
       ml_correspondent_received(pg_temp.i('mbP'), 'jonas@else.example', 'Jonas Else'),
       ml_correspondent_received(pg_temp.i('mbS'), 'jon@partner.example', 'Jon Partner');

-- ================================================================ 1. search: every operator (as Priya, through the records role)
RESET ROLE; SET ROLE malumail_records_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(pg_temp.s('from:jon', 'mbP') = pg_temp.m('p1', 'p4'), 'from: matches the address');
SELECT pg_temp.check(pg_temp.s('from:"Jon Partner"', 'mbP') = pg_temp.m('p1'), 'from: matches the display name');
SELECT pg_temp.check(pg_temp.s('to:jon', 'mbP') = pg_temp.m('p2'), 'to:');
SELECT pg_temp.check(pg_temp.s('cc:copy', 'mbP') = pg_temp.m('p7'), 'cc:');
SELECT pg_temp.check(pg_temp.s('bcc:secret', 'mbP') = pg_temp.m('p7'), 'bcc:');
SELECT pg_temp.check(pg_temp.s('subject:delivery', 'mbP') = pg_temp.m('p1', 'p2'), 'subject:');
SELECT pg_temp.check(pg_temp.s('filename:contract', 'mbP') = pg_temp.m('p1'), 'filename:');
SELECT pg_temp.check(pg_temp.s('has:attachment', 'mbP') = pg_temp.m('p1'), 'has:attachment');
SELECT pg_temp.check(pg_temp.s('is:starred', 'mbP') = pg_temp.m('p1') AND pg_temp.s('is:flagged', 'mbP') = pg_temp.m('p1'), 'is:starred and is:flagged');
SELECT pg_temp.check(pg_temp.s('is:answered', 'mbP') = pg_temp.m('p1'), 'is:answered');
SELECT pg_temp.check(pg_temp.s('is:unread', 'mbP') = pg_temp.m('p1', 'p4', 'p5', 'p8a', 'p8b', 'p9'), 'is:unread — the owner reads the server''s \Seen');
SELECT pg_temp.check(pg_temp.s('is:read', 'mbP') = pg_temp.m('p2', 'p3', 'p7'), 'is:read (Trash left out)');
SELECT pg_temp.check(pg_temp.s('in:sent', 'mbP') = pg_temp.m('p2', 'p7') AND pg_temp.s('in:inbox from:jon', 'mbP') = pg_temp.m('p1', 'p4'), 'in:sent, in:inbox');
SELECT pg_temp.check(NOT (pg_temp.i('p6') = ANY (pg_temp.s('old', 'mbP'))) AND pg_temp.s('in:trash', 'mbP') = pg_temp.m('p6')
                     AND pg_temp.i('p6') = ANY (pg_temp.s('old in:anywhere', 'mbP')), 'Trash left out unless in:trash or in:anywhere names it');
SELECT pg_temp.check(pg_temp.s(format('after:%s', to_char(now() - interval '36 hours', 'YYYY-MM-DD')), 'mbP')
                         @> pg_temp.m('p4', 'p9', 'p8a', 'p8b')
                     AND NOT (pg_temp.i('p3') = ANY (pg_temp.s(format('after:%s', to_char(now() - interval '36 hours', 'YYYY/MM/DD')), 'mbP'))),
                     'after: (YYYY-MM-DD and YYYY/MM/DD)');
SELECT pg_temp.check(pg_temp.s(format('before:%s', to_char(now() - interval '5 days', 'YYYY-MM-DD')), 'mbP') = pg_temp.m('p3'), 'before:');
SELECT pg_temp.check(pg_temp.s('older_than:7d', 'mbP') = pg_temp.m('p3') AND pg_temp.s('newer_than:1d', 'mbP') = pg_temp.m('p8a', 'p8b', 'p9'),
                     'older_than: and newer_than:');
SELECT pg_temp.check(pg_temp.s('larger:10K', 'mbP') = pg_temp.m('p1') AND pg_temp.s('smaller:1500', 'mbP') = pg_temp.m('p3'), 'larger: and smaller:');
SELECT pg_temp.check(pg_temp.s('"delivery date"', 'mbP') = pg_temp.m('p1', 'p2') AND pg_temp.s('friday', 'mbP') = pg_temp.m('p1', 'p2'), 'a phrase and a word (a reply''s subject carries the phrase too)');
SELECT pg_temp.check(pg_temp.s('-from:jon in:inbox', 'mbP') = pg_temp.m('p3', 'p5', 'p8a', 'p8b', 'p9'), '- before an operator');
SELECT pg_temp.refused($$SELECT pg_temp.s('larger:big', 'mbP')$$, 'A size is a number', 'a malformed size is refused');
SELECT pg_temp.refused($$SELECT pg_temp.s('older_than:soon', 'mbP')$$, 'A period is a number', 'a malformed period is refused');
SELECT pg_temp.check(pg_temp.sc('from:jon', 'mbP') = pg_temp.s('from:jon', 'mbP'), 'the cursor form and the offset form find the same');

-- ================================================================ 2. the cursor
SELECT pg_temp.check((SELECT array_agg(x ORDER BY x) FROM unnest(pg_temp.pages('in:anywhere', 'mbP', 2)) x) = pg_temp.s('in:anywhere', 'mbP')
                     AND cardinality(pg_temp.pages('in:anywhere', 'mbP', 2)) = cardinality(pg_temp.s('in:anywhere', 'mbP')),
                     'paging two at a time by (received_at, id) returns every message once — the twins at one instant included');
SELECT pg_temp.check((SELECT count(*) FROM ml_search('twin', pg_temp.i('mbP'), 50, pg_temp.at('p8a'))) = 0,
                     'p_before alone is strictly before: nothing at that instant comes back');
SELECT pg_temp.check((SELECT array_agg(message_id) FROM ml_search('twin', pg_temp.i('mbP'), 50, pg_temp.at('p8b'),
                                                                    pg_temp.i('p8b'))) = ARRAY[pg_temp.i('p8a')],
                     'with the tie-breaker, the twin with the smaller id follows');

-- ================================================================ 3. unknown operators are words, and named
SELECT pg_temp.check(ml_search_unknown('foo:bar is:weird has:x from:jon is:unread "a phrase" word') = ARRAY['foo:', 'has:x', 'is:weird'],
                     'ml_search_unknown names an unknown operator and unknown is:/has: values, nothing else');
SELECT pg_temp.check(pg_temp.s('weekly:widgets', 'mbP') = pg_temp.m('p3'), 'an unknown operator is searched for as words (Gmail): weekly:widgets finds the weekly news');

-- ================================================================ 4. snoozed and muted; per-person read state
RESET ROLE; SET ROLE malumail_rw; SELECT pg_temp.as_member(26);
SELECT ml_thread_snooze(pg_temp.i('mbP'), pg_temp.key('p3'), 26, now() + interval '1 day', pg_temp.f(pg_temp.i('mbP'), 'inbox'));
SELECT ml_thread_mute(pg_temp.i('mbP'), pg_temp.key('p4'), 26, true);
RESET ROLE; SET ROLE malumail_records_ro; SELECT pg_temp.as_member(26);
SELECT pg_temp.check(pg_temp.s('is:snoozed', 'mbP') = pg_temp.m('p3') AND NOT (pg_temp.i('p3') = ANY (pg_temp.s('-is:snoozed in:inbox', 'mbP'))), 'is:snoozed');
SELECT pg_temp.check(pg_temp.s('is:muted', 'mbP') = pg_temp.m('p4'), 'is:muted');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(pg_temp.s('is:unread', 'mbP') = pg_temp.m('p1', 'p3', 'p4', 'p5', 'p8a', 'p8b', 'p9'),
                     'a view holder''s is:unread is HER reads: mail Priya read is unread to Bea — but never the mailbox''s own sent mail');
RESET ROLE; SET ROLE malumail_rw; SELECT pg_temp.as_member(28);
SELECT ml_viewer_read(28, pg_temp.key('p1'));
RESET ROLE; SET ROLE malumail_records_ro; SELECT pg_temp.as_member(28);
SELECT pg_temp.check(NOT (pg_temp.i('p1') = ANY (pg_temp.s('is:unread', 'mbP'))) AND pg_temp.i('p1') = ANY (pg_temp.s('is:read', 'mbP')),
                     'after she reads it, it is read for Bea …');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(pg_temp.i('p1') = ANY (pg_temp.s('is:unread', 'mbP')), '… and still unread on the server for Priya (a view never marks)');

-- ================================================================ 5. labels on message keys
RESET ROLE; SET ROLE malumail_rw; SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('lWork', ml_label_save(pg_temp.i('mbP'), NULL, 'Work/Clients', '#336699', 26));
SELECT pg_temp.keep('lCafe', ml_label_save(pg_temp.i('mbP'), NULL, 'Café Notes', NULL, 26));
SELECT pg_temp.check((SELECT slug FROM tags WHERE id = pg_temp.i('lWork')) = 'work/clients' AND (SELECT slug FROM tags WHERE id = pg_temp.i('lCafe')) = 'cafe-notes',
                     'a label''s slug: lower case, accents off, spaces to -, / kept for nesting');
SELECT pg_temp.refused(format($$SELECT ml_label_save(%s, NULL, 'work/CLIENTS', NULL, 26)$$, pg_temp.i('mbP')), 'already has a label', 'one label of a name per mailbox');
SELECT pg_temp.refused(format($$SELECT ml_label_save(%s, NULL, 'Blue', 'blue', 26)$$, pg_temp.i('mbP')), '#rrggbb', 'a colour is #rrggbb');
SELECT pg_temp.refused(format($$SELECT ml_label_save(%s, NULL, '!!!', NULL, 26)$$, pg_temp.i('mbP')), 'letter or a digit', 'a name with no letter or digit is refused');
SELECT pg_temp.keep('lCafe2', ml_label_save(pg_temp.i('mbP'), pg_temp.i('lCafe'), 'Cafe', '#aa0000', 26));
SELECT pg_temp.check(pg_temp.i('lCafe2') = pg_temp.i('lCafe') AND (SELECT label || ' ' || colour FROM tags WHERE id = pg_temp.i('lCafe')) = 'Cafe #aa0000', 'rename and recolour');
SELECT pg_temp.check(ml_label_apply(pg_temp.i('mbP'), ml_thread_keys(pg_temp.thr('p1')), pg_temp.i('lWork'), 26) = 2,
                     'labelling a conversation labels its two message keys');
RESET ROLE; SET ROLE malumail_records_ro; SELECT pg_temp.as_member(26);
SELECT pg_temp.check(pg_temp.s('label:work/clients', 'mbP') = pg_temp.m('p1', 'p2') AND pg_temp.s('has:label', 'mbP') = pg_temp.m('p1', 'p2')
                     AND NOT (pg_temp.i('p1') = ANY (pg_temp.s('has:nolabel', 'mbP'))) AND pg_temp.i('p3') = ANY (pg_temp.s('has:nolabel', 'mbP')),
                     'label:, has:label, has:nolabel');
RESET ROLE;
SELECT ml_threads_rebuild(pg_temp.i('mbP'));
SET ROLE malumail_records_ro; SELECT pg_temp.as_member(26);
SELECT pg_temp.check(pg_temp.s('label:work/clients', 'mbP') = pg_temp.m('p1', 'p2') AND pg_temp.thr('p1') = pg_temp.thr('p2'),
                     'the label survives a rebuild of the conversations (it is on message keys)');
RESET ROLE; SET ROLE malumail_rw; SELECT pg_temp.as_member(28);
SELECT pg_temp.refused(format($$SELECT ml_label_apply(%s, ARRAY[%s]::bigint[], %s, 28)$$, pg_temp.i('mbP'), pg_temp.key('p3'), pg_temp.i('lWork')),
                       'never changes it', 'a view holder cannot label');
SELECT pg_temp.as_member(30);
SELECT pg_temp.refused(format($$SELECT ml_label_apply(%s, ARRAY[%s]::bigint[], %s, 30)$$, pg_temp.i('mbP'), pg_temp.key('p3'), pg_temp.i('lWork')),
                       'No such mailbox', 'someone with no row cannot label (the mailbox does not exist for them)');
SELECT pg_temp.refused(format($$SELECT ml_label_save(%s, NULL, 'Mine', NULL, 30)$$, pg_temp.i('mbP')), 'No such mailbox', '… nor make a label in it');
SELECT pg_temp.as_member(26);
SELECT pg_temp.refused(format($$SELECT ml_label_apply(%s, ARRAY[%s]::bigint[], %s, 26)$$, pg_temp.i('mbP'), pg_temp.key('q1'), pg_temp.i('lWork')),
                       'No such message', 'a message of another mailbox is never labelled with this mailbox''s label');
SELECT pg_temp.check(ml_label_apply(pg_temp.i('mbP'), ml_thread_keys(pg_temp.thr('p1')), pg_temp.i('lWork'), 26, true) = 2, 'removing takes it off both keys');
SELECT ml_label_apply(pg_temp.i('mbP'), ARRAY[pg_temp.key('p3'), pg_temp.key('p5')], pg_temp.i('lCafe'), 26);
SELECT pg_temp.keep('nCafe', ml_label_delete(pg_temp.i('mbP'), pg_temp.i('lCafe'), 26));
SELECT pg_temp.check(pg_temp.i('nCafe') = 2
                     AND NOT EXISTS (SELECT 1 FROM record_tags WHERE tag_id = pg_temp.i('lCafe'))
                     AND pg_temp.at('p3') IS NOT NULL AND pg_temp.at('p5') IS NOT NULL,
                     'deleting a label answers its conversations, removes it everywhere, and the mail stays');

-- ================================================================ 6. a conversation's keys; the agent and the sensitive conversation
SELECT ml_thread_sensitive(pg_temp.i('mbS'), pg_temp.key('s1'), 26, true);
SELECT pg_temp.keep('lAgent', ml_label_save(pg_temp.i('mbS'), NULL, 'Follow up', NULL, 26));
SELECT pg_temp.as_member(44);
SELECT pg_temp.check(ml_thread_keys(pg_temp.thr('s1')) = '{}' AND ml_thread_keys(pg_temp.thr('s2')) = ARRAY[pg_temp.key('s2')],
                     'ml_thread_keys: an agent gets no keys of a sensitive conversation, its others'' keys');
SELECT pg_temp.refused(format($$SELECT ml_label_apply(%s, ARRAY[%s]::bigint[], %s, 44)$$, pg_temp.i('mbS'), pg_temp.key('s1'), pg_temp.i('lAgent')),
                       'No such message', 'an agent never labels a sensitive conversation');
SELECT pg_temp.check(ml_label_apply(pg_temp.i('mbS'), ARRAY[pg_temp.key('s2')], pg_temp.i('lAgent'), 44) = 1, '… and labels its other mail');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(ml_thread_keys(pg_temp.thr('p1')) = '{}', 'ml_thread_keys: nothing of a mailbox the caller cannot open');
RESET ROLE; SET ROLE malumail_records_ro;
SELECT pg_temp.as_member(44);
SELECT pg_temp.check(pg_temp.s('agent thread', 'mbS') = pg_temp.m('s2') AND pg_temp.sc('private', 'mbS') = '{}', 'an agent''s search never finds a sensitive conversation');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(pg_temp.s('agent thread', 'mbS') = pg_temp.m('s1', 's2'), '… a person''s does');

-- ================================================================ 7. the wall across the searches
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(pg_temp.s('jon', 'mbP') = '{}' AND pg_temp.sc('jon', 'mbP') = '{}', 'Dana searching Priya''s mailbox finds nothing (no row, no mailbox)');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_search('jon', NULL, 500, 0) WHERE mailbox_id NOT IN (pg_temp.i('mbD'), pg_temp.i('mbSales'))),
                     'Dana''s search over every mailbox finds only her own and Sales');
SELECT pg_temp.check(pg_temp.s('quote', NULL) = pg_temp.m('q1', 'q2'), '… Sales'' mail among them');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_search('jon', NULL, 500, 0) WHERE mailbox_id <> pg_temp.i('mbP')), 'Bea finds Priya''s mail, which she may view, and no other');

-- ================================================================ 8. canned replies
RESET ROLE; SET ROLE malumail_rw;
SELECT pg_temp.keep('tOwn', ml_template_save(NULL, 'Thanks', NULL, 'Thank you for your mail.', false, 26));
SELECT pg_temp.keep('tShared', ml_template_save(NULL, 'Office hours', 'Our hours', 'We are open 9 to 5.', true, 26));
RESET ROLE; SET ROLE malumail_records_ro;
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_templates WHERE template_id = pg_temp.i('tOwn'))
                     AND EXISTS (SELECT 1 FROM mcp_templates WHERE template_id = pg_temp.i('tShared')),
                     'a person''s own canned reply is hers; a shared one everyone using MaluMail reads');
RESET ROLE; SET ROLE malumail_rw;
SELECT pg_temp.refused(format($$SELECT ml_template_save(%s, 'Hours', NULL, 'Changed', true, 30)$$, pg_temp.i('tShared')), 'Only its owner or a Mail admin',
                       'Dana cannot change Priya''s shared canned reply');
SELECT pg_temp.refused(format($$SELECT ml_template_save(%s, 'Thanks', NULL, 'Mine now', false, 30)$$, pg_temp.i('tOwn')), 'No such canned reply',
                       'Priya''s own canned reply does not exist for Dana');
SELECT pg_temp.keep('tShared2', ml_template_save(pg_temp.i('tShared'), 'Office hours', 'Our hours', 'We are open 8 to 6.', true, 1));
SELECT pg_temp.check(pg_temp.i('tShared2') = pg_temp.i('tShared')
                     AND (SELECT body FROM email_templates WHERE id = pg_temp.i('tShared')) = 'We are open 8 to 6.', 'a Mail admin changes a shared one');
SELECT pg_temp.refused($$SELECT ml_template_save(NULL, 'Agentic', NULL, 'Hi', false, 44)$$, 'person''s decision', 'an agent keeps no canned replies');
SELECT pg_temp.refused($$SELECT ml_template_save(NULL, '', NULL, 'Hi', false, 26)$$, 'name of 1 to 120', 'a canned reply has a name');
SELECT pg_temp.refused(format($$SELECT ml_template_delete(%s, 30)$$, pg_temp.i('tShared')), 'Only its owner or a Mail admin', 'Dana cannot delete the shared one');
SELECT ml_template_delete(pg_temp.i('tOwn'), 26);
RESET ROLE; SET ROLE malumail_records_ro; SELECT pg_temp.as_member(26);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_templates WHERE template_id = pg_temp.i('tOwn')), 'its owner deletes her own (archived, gone from the list)');

-- ================================================================ 9. always show images — only for a proven sender
RESET ROLE; SET ROLE malumail_rw; SELECT pg_temp.as_member(26);
SELECT ml_images_allow(pg_temp.i('mbP'), 'jon@partner.example', 26, true), ml_images_allow(pg_temp.i('mbP'), 'Dana@Subello.example', 26, true);
RESET ROLE; SET ROLE malumail_records_ro; SELECT pg_temp.as_member(26);
SELECT pg_temp.check(ml_images_always_for(pg_temp.i('p1')), 'Jon''s DMARC-passed message shows its images');
SELECT pg_temp.check(NOT ml_images_always_for(pg_temp.i('p4')), 'a message claiming Jon without a DMARC pass does not');
SELECT pg_temp.check(ml_images_always_for(pg_temp.i('p5')), 'Dana''s local submission (a colleague proven, no DMARC result) shows them');
SELECT pg_temp.check(NOT ml_images_always_for(pg_temp.i('p9')), 'a message claiming Dana with neither a DMARC pass nor a local submission does not');
SELECT pg_temp.check(NOT ml_images_always_for(pg_temp.i('p3')), 'a sender never allowed does not');
SELECT pg_temp.check((SELECT images_always FROM mcp_correspondents WHERE mailbox_id = pg_temp.i('mbP') AND address = 'jon@partner.example'),
                     'mcp_correspondents carries the flag');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(NOT ml_images_always_for(pg_temp.i('p1')), 'someone who cannot open the mailbox learns nothing from it');
RESET ROLE; SET ROLE malumail_rw;
SELECT pg_temp.refused(format($$SELECT ml_images_allow(%s, 'x@else.example', 28, true)$$, pg_temp.i('mbP')), 'full on the mailbox', 'a view holder cannot set it');
SELECT pg_temp.refused(format($$SELECT ml_images_allow(%s, 'x@else.example', 30, true)$$, pg_temp.i('mbP')), 'No such mailbox', 'nor someone with no row');
SELECT pg_temp.refused(format($$SELECT ml_images_allow(%s, 'x@else.example', 44, true)$$, pg_temp.i('mbS')), 'person''s decision', 'nor an agent, even on its own mailbox');

-- ================================================================ 10. the K7 share
RESET ROLE; SET ROLE malumail_records_ro; SELECT pg_temp.as_member(NULL);
SELECT pg_temp.check((SELECT array_agg(DISTINCT mailbox_id ORDER BY mailbox_id) FROM ml_correspondence_index('jon@partner.example'))
                     = (SELECT array_agg(x ORDER BY x) FROM unnest(ARRAY[pg_temp.i('mbS'), pg_temp.i('mbSales'), pg_temp.i('mbSupport')]) x),
                     'the share answers from agents'' and shared mailboxes, never a person''s not shared');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_correspondence_index('jon@partner.example') WHERE thread_id = pg_temp.thr('s1')), '… never a sensitive conversation');
SELECT pg_temp.check((SELECT array_agg(thread_id ORDER BY thread_id) FROM ml_correspondence_index('jon@partner.example', NULL, 25, now() - interval '3 hours'))
                     = (SELECT array_agg(x ORDER BY x) FROM unnest(ARRAY[pg_temp.thr('q2'), pg_temp.thr('h1')]) x), 'since: only conversations active since');
SELECT pg_temp.check((SELECT count(*) FROM ml_correspondence_index(NULL, 'partner.example', 1)) = 1, 'limit holds');
SELECT pg_temp.refused($$SELECT * FROM ml_correspondence_index(NULL, NULL)$$, 'address or a domain', 'it asks about an address or a domain');
RESET ROLE; SET ROLE malumail_rw;
SELECT ml_mailbox_suspend(pg_temp.i('mbSales'), 1, true);
RESET ROLE; SET ROLE malumail_records_ro;
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_correspondence_index('jon@partner.example') WHERE mailbox_id = pg_temp.i('mbSales')),
                     'a suspended mailbox is left out of the share');
SELECT ml_log_k7_read(ARRAY[pg_temp.i('mbS'), pg_temp.i('mbSupport')], 'sales_crm', 44, ARRAY[2, 1], 'Partner.example');
RESET ROLE;
SELECT pg_temp.check((SELECT count(*) FROM activity_log WHERE action = 'k7.read') = 2
                     AND (SELECT count(DISTINCT mailbox_id) FROM activity_log WHERE action = 'k7.read') = 2
                     AND (SELECT bool_and(after->>'consumer' = 'sales_crm' AND after->>'domain' = 'partner.example' AND entity_id = mailbox_id AND source = 'application')
                            FROM activity_log WHERE action = 'k7.read')
                     AND (SELECT (after->>'count')::int FROM activity_log WHERE action = 'k7.read' AND mailbox_id = pg_temp.i('mbS')) = 2,
                     'one k7.read row per mailbox drawn on, with the consumer, its count and the domain');
SET ROLE malumail_records_ro;
SELECT pg_temp.refused($$SELECT ml_log_k7_read(ARRAY[1, 2]::bigint[], 'x', NULL, ARRAY[1], NULL)$$, 'one count per mailbox', 'one count per mailbox');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_mailbox_access_log WHERE action = 'k7.read' AND mailbox_id = pg_temp.i('mbS')) IS NOT NULL,
                     'the rows are access-log rows (mcp_mailbox_access_log names k7.read)');
RESET ROLE; SET ROLE malumail_rw;
SELECT pg_temp.refused($$SELECT ml_log_k7_read(ARRAY[1]::bigint[], 'x', NULL, ARRAY[1], NULL)$$, 'permission denied', 'only the records role writes a k7.read');
RESET ROLE; SET ROLE malumail_rw; SELECT pg_temp.as_member(26);
SELECT ml_mailbox_suspend(pg_temp.i('mbSales'), 1, false);

-- ================================================================ 11. autocomplete — never another mailbox's address
RESET ROLE; SET ROLE malumail_records_ro; SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT address || '|' || source || '|' || outside FROM ml_complete(pg_temp.i('mbP'), 'jon') LIMIT 1) = 'jon@partner.example|correspondent|true',
                     'Priya: the correspondent she wrote to comes first, marked outside');
SELECT pg_temp.check((SELECT array_agg(address ORDER BY address COLLATE "C") FROM ml_complete(pg_temp.i('mbP'), 'jon')) = ARRAY['jon@partner.example', 'jonas@else.example'],
                     '… then the one she only heard from');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ml_complete(pg_temp.i('mbP'), 'dana') WHERE address = 'dana@subello.example' AND NOT outside),
                     'the business''s people come from the directory, inside');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ml_complete(pg_temp.i('mbP'), 'sched') WHERE address = 'sched@subello.example' AND source = 'mailbox'),
                     'a mailbox she opens (the agent she oversees) is offered');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_complete(pg_temp.i('mbP'), 'support')), 'a mailbox she cannot open is never offered (support@)');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_complete(pg_temp.i('mbSales'), 'sched')) AND NOT EXISTS (SELECT 1 FROM ml_complete(pg_temp.i('mbSales'), 'support')),
                     'Dana, writing from Sales, is never offered sched@ or support@');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_complete(pg_temp.i('mbP'), 'jon')), 'Dana gets nothing for Priya''s mailbox (no row)');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_complete(pg_temp.i('mbP'), 'jon')), 'Bea, who may only view Priya''s mailbox, gets nothing (autocomplete is a sender''s)');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM ml_complete(pg_temp.i('mbP'), 'e', 3)) <= 3 AND NOT EXISTS (SELECT 1 FROM ml_complete(pg_temp.i('mbP'), '   ')),
                     'the limit holds; an empty query answers nothing');

-- ================================================================ 12. grants
RESET ROLE;
SELECT pg_temp.check(NOT has_function_privilege('public', 'ml_search_core(text, bigint, integer, integer, timestamptz, bigint)', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_rw', 'ml_search_core(text, bigint, integer, integer, timestamptz, bigint)', 'EXECUTE'),
                     'the search''s body is reached only through ml_search');
SELECT pg_temp.check(has_function_privilege('malumail_records_ro', 'ml_search(text, bigint, integer, timestamptz, bigint)', 'EXECUTE')
                     AND has_function_privilege('malumail_records_ro', 'ml_complete(bigint, text, integer)', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_records_ro', 'ml_label_apply(bigint, bigint[], bigint, bigint, boolean)', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_records_ro', 'ml_template_save(bigint, text, text, text, boolean, bigint)', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_records_ro', 'ml_images_allow(bigint, text, bigint, boolean)', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_activity_ro', 'ml_complete(bigint, text, integer)', 'EXECUTE'),
                     'the records role reads; only the web application writes labels, templates and the images choice');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                                  WHERE n.nspname = 'public' AND c.relkind = 'v' AND c.relname = 'mcp_correspondents'
                                    AND NOT COALESCE(c.reloptions @> ARRAY['security_barrier=true'], false)),
                     'mcp_correspondents is still a security barrier');

\o
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || n || '  ' || label FROM proof ORDER BY n;
SELECT (SELECT count(*) FROM proof WHERE ok) || ' ok, ' || (SELECT count(*) FROM proof WHERE NOT ok) || ' failed' AS result;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the reading and search proof failed'; END IF; END $$;
ROLLBACK;
