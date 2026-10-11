-- Phase 1 proof of db/015_sending_oversight.sql (docs/build-specs/sending-and-oversight.md, writing.md, oversight.md, notifications.md), in
-- phase0_proof.sql's style: ONE transaction, ROLLED BACK; fixtures written as malumail_rw; acting members through app.member_id. Synthetic:
-- the business is subello.example, outsiders partner.example. Run as postgres on a SCRATCH database holding db/001–012 and db/015:
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -X -d ml_p1c -f db/proof/phase1_sending.sql; echo "exit $?"
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
CREATE OR REPLACE FUNCTION pg_temp.same(a anyarray, b anyarray) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$ SELECT a @> b AND a <@ b $$;
CREATE OR REPLACE FUNCTION pg_temp.folders(mb bigint) RETURNS void LANGUAGE sql AS $$
    INSERT INTO mail_folders (mailbox_id, name, special_use, uidvalidity, uidnext) VALUES
      (mb, 'INBOX', 'inbox', 1, 1), (mb, 'Sent', 'sent', 1, 1), (mb, 'Drafts', 'drafts', 1, 1), (mb, 'Trash', 'trash', 1, 1), (mb, 'Junk', 'junk', 1, 1);
$$;
CREATE OR REPLACE FUNCTION pg_temp.f(mb bigint, su text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM mail_folders WHERE mailbox_id = mb AND special_use = su $$;
-- an arriving message (x: irt, refs[])
CREATE OR REPLACE FUNCTION pg_temp.msg(k text, mb bigint, mid text, frm text, tos text[], subj text, at timestamptz, x jsonb DEFAULT '{}') RETURNS bigint
    LANGUAGE plpgsql AS $$
DECLARE fid bigint := pg_temp.f(mb, 'inbox'); key bigint; newid bigint;
BEGIN
    key := ml_message_key_for(mb, mid, ml_envelope_digest(at::text, frm, array_to_string(tos, ','), '', subj));
    INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, in_reply_to, references_ids, from_address,
                               to_addresses, cc_addresses, subject, sent_at, received_at, size, dmarc_pass, snippet)
    VALUES (fid, 1, (SELECT COALESCE(max(m.uid), 0) + 1 FROM mail_messages m WHERE m.folder_id = fid), key, mid, x->>'irt',
            COALESCE(ARRAY(SELECT jsonb_array_elements_text(x->'refs')), '{}'), frm, tos, '{}', subj, at, at, 2000, true, subj)
    RETURNING mail_messages.id INTO newid;
    PERFORM pg_temp.keep(k, newid);
    PERFORM pg_temp.keep('key:' || k, key);
    RETURN newid;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.key(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM ids WHERE ids.k = 'key:' || $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.send(k text, mb text, s bigint, tos text[], subj text, body text, x jsonb DEFAULT '{}') RETURNS bigint LANGUAGE plpgsql AS $$
DECLARE o bigint;
BEGIN
    o := ml_outbound_submit(pg_temp.i(mb), s, NULL, tos, '{}', '{}', subj, body, NULL, (x->>'reply_key')::bigint, NULL,
                            COALESCE(x->>'purpose', CASE WHEN s = 44 THEN 'proof' END), COALESCE((x->>'att')::boolean, false));
    RETURN pg_temp.keep(k, o);
END$$;
CREATE OR REPLACE FUNCTION pg_temp.ob(k text) RETURNS outbound_email LANGUAGE sql STABLE SECURITY DEFINER AS $$ SELECT * FROM outbound_email WHERE id = pg_temp.i($1) $$;
CREATE OR REPLACE FUNCTION pg_temp.set_status(k text, st text) RETURNS void LANGUAGE sql AS $$ UPDATE outbound_email SET status = st WHERE id = pg_temp.i(k) $$;
CREATE OR REPLACE FUNCTION pg_temp.noticed(k text, kind text) RETURNS bigint[] LANGUAGE sql STABLE SECURITY DEFINER AS $$
    SELECT COALESCE(array_agg(member_id ORDER BY member_id), '{}') FROM notifications WHERE record_type = 'outbound' AND record_id = pg_temp.i($1) AND notifications.kind = $2 $$;
CREATE OR REPLACE FUNCTION pg_temp.rdisp(k text) RETURNS agent_dispatches LANGUAGE sql STABLE SECURITY DEFINER AS $$
    SELECT * FROM agent_dispatches WHERE kind = 'review' AND record_type = 'outbound' AND record_id = pg_temp.i($1) ORDER BY id DESC LIMIT 1 $$;
CREATE OR REPLACE FUNCTION pg_temp.backdate(p_sql text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER AS $$
BEGIN   -- test-only: move a timestamp into the past past the guards (the transaction's now() never advances)
    PERFORM set_config('session_replication_role', 'replica', true);
    EXECUTE p_sql;
    PERFORM set_config('session_replication_role', 'origin', true);
END$$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

-- ================================================================ fixtures
SET ROLE malumail_rw;
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',  'owner@subello.example', 'super_admin', false, 'active', 'admin', '{admin}'),   -- a Mail admin: full on agent mailboxes
 (26, 'human', 'SMOKE Priya',  'priya@subello.example', 'user',        false, 'active', 'write', '{user}'),    -- the Scheduler's primary overseer
 (30, 'human', 'SMOKE Dana',   'dana@subello.example',  'user',        false, 'active', 'write', '{user}'),    -- a second overseer
 (31, 'human', 'SMOKE Lee',    'lee@subello.example',   'user',        false, 'active', 'write', '{user}'),    -- no row anywhere
 (40, 'agent', 'SMOKE Mail Expert', NULL,               'user',        false, 'active', 'write', '{user}'),
 (42, 'agent', 'SMOKE Mail Clerk',  NULL,               'user',        false, 'active', 'write', '{user}'),
 (44, 'agent', 'SMOKE Scheduler',   NULL,               'user',        false, 'active', 'write', '{user}');
UPDATE ml_settings SET expert_member_id = 40, clerk_member_id = 42, business_name = 'Subello' WHERE id = 1;
INSERT INTO ml_domains (domain, served, internal, created_by) VALUES ('subello.example', true, true, 1);
SELECT pg_temp.keep('mbS', ml_mailbox_create(1, 'sched@subello.example', 'Scheduler', 'agent', 44, 26));
SELECT pg_temp.keep('mbP', ml_mailbox_create(1, 'priya@subello.example', 'Priya', 'person', 26));
SELECT pg_temp.folders(pg_temp.i(x)) FROM unnest(ARRAY['mbS', 'mbP']) x;
SELECT ml_permission_grant(pg_temp.i('mbS'), 1, 'member', 30, 'full');
SELECT ml_permission_grant(pg_temp.i('mbP'), 26, 'agent', 44, 'send');
-- text notices: Dana opted in for held mail; the Owner opted in for other kinds only; Priya not at all
INSERT INTO notification_prefs (member_id, text_enabled, text_kinds) VALUES (30, true, '{held_mail}'), (1, true, '{credential_failed}'), (26, false, '{held_mail}');
-- a conversation in the Scheduler's inbox (s1 → s2), and another (t1)
SELECT pg_temp.msg('s1', pg_temp.i('mbS'), '<s1@subello.example>', 'priya@subello.example', '{sched@subello.example}', 'Next week', now() - interval '2 hours');
SELECT pg_temp.msg('s2', pg_temp.i('mbS'), '<s2@subello.example>', 'priya@subello.example', '{sched@subello.example}', 'Re: Next week', now() - interval '1 hour',
                   '{"irt": "<s1@subello.example>", "refs": ["<s1@subello.example>"]}');
SELECT pg_temp.msg('t1', pg_temp.i('mbS'), '<t1@subello.example>', 'dana@subello.example', '{sched@subello.example}', 'Room booking', now() - interval '50 minutes');
SELECT pg_temp.check((SELECT thread_id FROM mail_messages WHERE id = pg_temp.i('s1')) = (SELECT thread_id FROM mail_messages WHERE id = pg_temp.i('s2'))
                     AND (SELECT thread_id FROM mail_messages WHERE id = pg_temp.i('t1')) IS DISTINCT FROM (SELECT thread_id FROM mail_messages WHERE id = pg_temp.i('s1')),
                     '(fixtures: s1 and s2 are one conversation, t1 another)');

-- ================================================================ 1. the columns
SELECT pg_temp.check((SELECT count(*) FROM information_schema.columns WHERE (table_name, column_name) IN
                       (('outbound_email', 'filed_at'), ('outbound_email', 'thread_anchor_key'), ('mail_reviews', 'before_subject'),
                        ('mail_reviews', 'before_body_text'), ('mail_drafts', 'filed_at'), ('notifications', 'item_count'))) = 6,
                     'filed_at, thread_anchor_key, the edit''s before-text, the draft''s filed_at and the coalesced count exist');

-- ================================================================ 2. the status machine
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"send_mode": "auto", "first_contact_review": false, "max_per_thread_per_day": 1}');
SELECT pg_temp.send('q1', 'mbS', 44, '{priya@subello.example}', 'Re: Next week', 'Booked.', jsonb_build_object('reply_key', pg_temp.key('s1')));
SELECT pg_temp.check((pg_temp.ob('q1')).status = 'queued' AND (pg_temp.ob('q1')).thread_anchor_key = pg_temp.key('s1'),
                     'an agent''s reply under auto: queued, the conversation''s anchor stored (its lowest message key)');
SELECT pg_temp.send('q2', 'mbS', 44, '{priya@subello.example}', 'Re: Next week', 'Also booked the room.', jsonb_build_object('reply_key', pg_temp.key('s2')));
SELECT pg_temp.check((pg_temp.ob('q2')).thread_anchor_key = pg_temp.key('s1') AND 'over_thread' = ANY ((pg_temp.ob('q2')).hold_reasons),
                     'a second reply answering ANOTHER message of the same conversation counts toward its cap (over_thread by anchor)');
SELECT pg_temp.send('q3', 'mbS', 44, '{dana@subello.example}', 'Re: Room booking', 'Room 2 is free.', jsonb_build_object('reply_key', pg_temp.key('t1')));
SELECT pg_temp.check((pg_temp.ob('q3')).status = 'queued', 'another conversation is not capped by the first');
SELECT pg_temp.refused(format($$UPDATE outbound_email SET status = 'sent' WHERE id = %s$$, pg_temp.i('q1')), 'cannot become', 'queued → sent: refused (it is claimed first)');
UPDATE outbound_email SET send_after = now() - interval '1 second' WHERE id IN (pg_temp.i('q1'), pg_temp.i('q3'));
SELECT ml_outbound_claim(50);
SELECT pg_temp.check((pg_temp.ob('q1')).status = 'sending' AND (pg_temp.ob('q3')).status = 'sending', 'queued → sending: the claim');
SELECT pg_temp.refused(format($$UPDATE outbound_email SET status = 'queued' WHERE id = %s$$, pg_temp.i('q1')), 'never sent again by itself',
                       'sending → queued: REFUSED — a row lost mid-send is never re-queued (the crash rule)');
SELECT ml_outbound_deferred(pg_temp.i('q3'), '451 4.7.1 Rate limit exceeded');
SELECT pg_temp.check((pg_temp.ob('q3')).status = 'deferred', 'sending → deferred');
UPDATE outbound_email SET status = 'held', hold_reasons = '{paused}' WHERE id = pg_temp.i('q3');
SELECT pg_temp.check((pg_temp.ob('q3')).status = 'held' AND (pg_temp.ob('q3')).held_at IS NOT NULL, 'deferred → held: admitted now (the kill switch)');
SELECT ml_outbound_sent(pg_temp.i('q1'), '<prov1@malumail>', '250 Ok');
SELECT pg_temp.check((pg_temp.ob('q1')).status = 'sent' AND (pg_temp.ob('q1')).filed_at IS NULL, 'sending → sent; not yet filed in Sent (filed_at null)');
UPDATE outbound_email SET filed_at = now() WHERE id = pg_temp.i('q1');
SELECT pg_temp.check((pg_temp.ob('q1')).filed_at IS NOT NULL, 'the Sent copy recorded (filed_at)');
SELECT pg_temp.refused(format($$UPDATE outbound_email SET status = 'failed' WHERE id = %s$$, pg_temp.i('q1')), 'cannot become', 'sent is final');
-- deferred → sending and → cancelled; sending → failed; failed → queued
SELECT pg_temp.send('q4', 'mbS', 44, '{dana@subello.example}', 'Lunch', 'Lunch at noon?');
SELECT pg_temp.send('q5', 'mbS', 44, '{dana@subello.example}', 'Coffee', 'Coffee at three?');
UPDATE outbound_email SET send_after = now() - interval '1 second' WHERE id IN (pg_temp.i('q4'), pg_temp.i('q5'));
SELECT ml_outbound_claim(50);
SELECT ml_outbound_deferred(pg_temp.i('q4'), '451 4.7.1'), ml_outbound_deferred(pg_temp.i('q5'), '451 4.7.1');
UPDATE outbound_email SET retry_at = now() - interval '1 second' WHERE id = pg_temp.i('q4');
SELECT ml_outbound_claim(50);
SELECT pg_temp.check((pg_temp.ob('q4')).status = 'sending' AND (pg_temp.ob('q4')).attempts = 2, 'deferred → sending: claimed again when due');
SELECT pg_temp.set_status('q5', 'cancelled');
SELECT pg_temp.check((pg_temp.ob('q5')).status = 'cancelled', 'deferred → cancelled');
SELECT ml_outbound_failed(pg_temp.i('q4'), '550 5.1.1 no such user');
SELECT pg_temp.check((pg_temp.ob('q4')).status = 'failed', 'sending → failed');
SELECT pg_temp.refused(format($$UPDATE outbound_email SET status = 'sent' WHERE id = %s$$, pg_temp.i('q4')), 'cannot become', 'failed → sent: refused');

-- ================================================================ 3. the crash rule
SELECT pg_temp.send('c1', 'mbS', 44, '{dana@subello.example}', 'Agenda', 'The agenda is attached below.');
SELECT pg_temp.send('c2', 'mbS', 44, '{dana@subello.example}', 'Minutes', 'The minutes follow.');
UPDATE outbound_email SET send_after = now() - interval '1 second' WHERE id IN (pg_temp.i('c1'), pg_temp.i('c2'));
SELECT ml_outbound_claim(50);
SELECT pg_temp.backdate(format('UPDATE outbound_email SET updated_at = now() - interval ''20 minutes'' WHERE id = %s', pg_temp.i('c1')));
SELECT pg_temp.check(pg_temp.i('c1') IN (SELECT ml_outbound_stalled(10)) AND pg_temp.i('c2') NOT IN (SELECT ml_outbound_stalled(10)),
                     'a row claimed 20 minutes ago and never answered is listed stalled; one claimed now is not');
SELECT ml_outbound_lost(pg_temp.i('c1'));
SELECT pg_temp.check((pg_temp.ob('c1')).status = 'failed' AND (pg_temp.ob('c1')).error LIKE '%it may have gone%',
                     'lost: failed, saying it may have gone — never queued again by itself');
SELECT pg_temp.refused(format($$SELECT ml_outbound_lost(%s)$$, pg_temp.i('c1')), 'Only a message being sent', 'only a row being sent can be lost');

-- ================================================================ 4. retry
SELECT pg_temp.refused(format($$SELECT ml_outbound_retry(%s, 31)$$, pg_temp.i('c1')), 'No such message', 'a member who cannot open the mailbox: no such message');
SELECT pg_temp.refused(format($$SELECT ml_outbound_retry(%s, 42)$$, pg_temp.i('c1')), 'needs send', 'the Clerk (triage, not the writer) cannot retry');
SELECT pg_temp.refused(format($$SELECT ml_outbound_retry(%s, 44)$$, pg_temp.i('q1')), 'Only a failed message', 'only a failed message is retried');
SELECT ml_outbound_retry(pg_temp.i('c1'), 44);
SELECT pg_temp.check((pg_temp.ob('c1')).status = 'queued' AND (pg_temp.ob('c1')).attempts = 0 AND (pg_temp.ob('c1')).retry_at IS NULL
                     AND (pg_temp.ob('c1')).error IS NULL, 'its writer retries: failed → queued, a fresh deferral budget');
SELECT ml_outbound_retry(pg_temp.i('q4'), 30);
SELECT pg_temp.check((pg_temp.ob('q4')).status = 'queued', 'an overseer with send on the mailbox may retry it too');

-- ================================================================ 5. held notices: exactly the reviewers; texts only for those who opted in
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"send_mode": "hold_all", "first_contact_review": true, "max_per_thread_per_day": 5}');
SELECT pg_temp.send('h1', 'mbS', 44, '{carol@partner.example}', 'Hello', 'Introducing our scheduling service.');
SELECT pg_temp.check((pg_temp.ob('h1')).status = 'held', '(held: mode, first contact)');
SELECT pg_temp.check(pg_temp.noticed('h1', 'held_mail') = '{1,26,30}',
                     'held mail tells exactly its reviewers — the two overseers and the Mail admin; not the Clerk, not the agent');
SELECT pg_temp.check((SELECT title FROM notifications WHERE kind = 'held_mail' AND record_id = pg_temp.i('h1') AND member_id = 26)
                      = 'Mail from SMOKE Scheduler waits for your review (mode, first contact)'
                     AND NOT EXISTS (SELECT 1 FROM notifications WHERE kind = 'held_mail' AND (title LIKE '%Hello%' OR body LIKE '%Introducing%')),
                     'the notice names the agent and the reasons — never the subject or the body');
SELECT pg_temp.check((SELECT array_agg(member_id ORDER BY member_id) FROM notification_outbox WHERE channel = 'text' AND kind = 'held_mail'
                       AND record_id = pg_temp.i('h1')) = '{30}',
                     'a text row only for Dana (opted in for held mail) — not Priya (texts off), not the Owner (other kinds)');
SELECT pg_temp.check((SELECT dedupe_key FROM notification_outbox WHERE channel = 'text' AND record_id = pg_temp.i('h1')) LIKE 't%',
                     'the text row is deduplicated by its notice (t<id>)');
SELECT pg_temp.send('h2', 'mbP', 44, '{jon@partner.example}', 'Delivery', 'Confirmed for Tuesday.');
SELECT pg_temp.check(pg_temp.noticed('h2', 'held_mail') = '{26}', 'from a person''s mailbox (D12): only its owner is told — no Mail admin, no overseer');
SELECT ml_notify(1, 'credential_failed', 'A credential failed', NULL, pg_temp.i('mbS'));
SELECT pg_temp.check(EXISTS (SELECT 1 FROM notification_outbox WHERE channel = 'text' AND member_id = 1 AND kind = 'credential_failed')
                     AND NOT EXISTS (SELECT 1 FROM notification_outbox WHERE channel = 'text' AND member_id = 1 AND kind = 'held_mail'),
                     'ml_notify writes a text row for a kind the person chose, and only for it');

-- ================================================================ 6. the five decisions; agents refused every one
SELECT pg_temp.refused(format($$SELECT ml_review_decide(%s, 44, %L, 'note')$$, pg_temp.i('h1'), d), 'never decides',
                       'the agent cannot ' || d || ' its own held mail')
  FROM unnest(ARRAY['approve', 'edit', 'reject', 'reply', 'take_over']) d;
SELECT pg_temp.refused(format($$SELECT ml_review_decide(%s, 42, 'approve')$$, pg_temp.i('h1')), 'never decides', 'the Clerk cannot decide');
SELECT pg_temp.refused(format($$SELECT ml_review_decide(%s, 30, 'approve')$$, pg_temp.i('h2')), 'do not review', 'an overseer of the agent cannot decide mail from Priya''s mailbox');
SELECT ml_review_decide(pg_temp.i('h1'), 26, 'reply', 'Who asked for this introduction?');
SELECT pg_temp.check((pg_temp.ob('h1')).status = 'held' AND (pg_temp.rdisp('h1')).decision = 'reply' AND (pg_temp.rdisp('h1')).status = 'queued'
                     AND (pg_temp.rdisp('h1')).acting_member_id = 26 AND (pg_temp.rdisp('h1')).agent_member_id = 44
                     AND (pg_temp.rdisp('h1')).detail = 'Who asked for this introduction?',
                     'reply: still held; the note reaches the agent as a queued review dispatch, the reviewer acting');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM notifications WHERE kind = 'held_mail' AND record_id = pg_temp.i('h1') AND member_id = 26 AND id <> (
                       SELECT min(id) FROM notifications WHERE kind = 'held_mail' AND record_id = pg_temp.i('h1') AND member_id = 26)),
                     'a decision that keeps it held tells no one twice');
SELECT ml_review_decide(pg_temp.i('h1'), 30, 'edit', NULL, 'Hello from Subello', 'Introducing our scheduling service. — Dana');
SELECT pg_temp.check((pg_temp.ob('h1')).status = 'queued'
                     AND (SELECT before_subject = 'Hello' AND before_body_text = 'Introducing our scheduling service.'
                            FROM mail_reviews WHERE id = (pg_temp.ob('h1')).review_id),
                     'edit: queued, and the text before the edit kept for the diff');
SELECT pg_temp.refused(format($$INSERT INTO mail_reviews (outbound_id, decision, reviewer_member_id, before_digest, after_digest) VALUES (%s, 'edit', 26, 'a', 'b')$$,
                              pg_temp.i('h1')), 'mail_reviews_edit_before', 'an edit without its before-text is refused');
SELECT pg_temp.send('h3', 'mbS', 44, '{dave@partner.example}', 'Offer', 'A special offer.');
SELECT ml_review_decide(pg_temp.i('h3'), 1, 'reject', 'We do not send offers cold.');
SELECT pg_temp.check((pg_temp.ob('h3')).status = 'rejected' AND (pg_temp.rdisp('h3')).decision = 'reject' AND (pg_temp.rdisp('h3')).acting_member_id = 1
                     AND (pg_temp.rdisp('h3')).detail = 'We do not send offers cold.', 'reject: the note reaches the agent (a review dispatch)');
SELECT pg_temp.send('h4', 'mbS', 44, '{erin@partner.example}', 'Hi', 'Introducing ourselves.');
SELECT ml_review_decide(pg_temp.i('h4'), 26, 'approve');
SELECT pg_temp.check((pg_temp.ob('h4')).status = 'queued' AND (pg_temp.rdisp('h4')).id IS NULL, 'approve: queued, nothing to tell the agent');
SELECT pg_temp.send('h5', 'mbS', 44, '{priya@subello.example}', 'Re: Next week', 'Shall I book the room too?', jsonb_build_object('reply_key', pg_temp.key('s2')));
SELECT ml_review_decide(pg_temp.i('h5'), 26, 'take_over');
SELECT pg_temp.check((pg_temp.ob('h5')).status = 'cancelled' AND (pg_temp.rdisp('h5')).decision = 'take_over', 'take over from the review: withdrawn, the agent told');

-- ================================================================ 7. take over from the conversation: the agent's held replies in it withdrawn
SELECT ml_thread_take_over(pg_temp.i('mbS'), pg_temp.key('s2'), 26, false);
SELECT pg_temp.send('k1', 'mbS', 44, '{priya@subello.example}', 'Re: Next week', 'Monday then.', jsonb_build_object('reply_key', pg_temp.key('s1')));
SELECT pg_temp.send('k2', 'mbS', 44, '{priya@subello.example}', 'Re: Next week', 'Or Tuesday.', jsonb_build_object('reply_key', pg_temp.key('s2')));
SELECT pg_temp.send('k3', 'mbS', 44, '{dana@subello.example}', 'Re: Room booking', 'Room 3 instead.', jsonb_build_object('reply_key', pg_temp.key('t1')));
SELECT pg_temp.check((pg_temp.ob('k1')).status = 'held' AND (pg_temp.ob('k2')).status = 'held' AND (pg_temp.ob('k3')).status = 'held', '(three held replies)');
SELECT pg_temp.refused(format($$SELECT ml_thread_take_over(%s, %s, 44)$$, pg_temp.i('mbS'), pg_temp.key('s1')), 'never decides', 'an agent cannot take a conversation over');
SELECT ml_thread_take_over(pg_temp.i('mbS'), pg_temp.key('s1'), 30);
SELECT pg_temp.check((pg_temp.ob('k1')).status = 'cancelled' AND (pg_temp.ob('k2')).status = 'cancelled' AND (pg_temp.ob('k3')).status = 'held',
                     'taking the conversation over withdraws the agent''s held replies in IT (both messages), not in another');
SELECT pg_temp.check((pg_temp.ob('q2')).status = 'cancelled', 'the earlier held reply q2 (over_thread) in that conversation is withdrawn too');
SELECT pg_temp.check((SELECT decision = 'take_over' AND reviewer_member_id = 30 FROM mail_reviews WHERE id = (pg_temp.ob('k2')).review_id)
                     AND (pg_temp.rdisp('k1')).decision = 'take_over' AND (pg_temp.rdisp('k2')).acting_member_id = 30,
                     'each with its own take_over review and a review dispatch to the agent');

-- ================================================================ 8. expiry: the agent told, the reviewers told
UPDATE outbound_email SET held_at = now() - interval '73 hours' WHERE id IN (pg_temp.i('k3'), pg_temp.i('h2'));
SELECT pg_temp.keep('expired', ml_held_expire());
SELECT pg_temp.check(pg_temp.i('expired') = 2 AND (pg_temp.ob('k3')).status = 'expired' AND (pg_temp.ob('h2')).status = 'expired', 'held mail past 72 hours expires');
SELECT pg_temp.check(pg_temp.noticed('k3', 'review_decided') = '{1,26,30}' AND pg_temp.noticed('h2', 'review_decided') = '{26}',
                     'its reviewers are told it expired undecided — exactly them');
SELECT pg_temp.check((pg_temp.rdisp('k3')).decision = 'expire' AND (pg_temp.rdisp('k3')).acting_member_id = 26 AND (pg_temp.rdisp('k3')).status = 'queued'
                     AND (pg_temp.rdisp('h2')).acting_member_id = 26,
                     'the agent is told by a review dispatch: an agent mailbox''s primary overseer acting; a person''s mailbox''s owner');

-- ================================================================ 9. pause and resume
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"send_mode": "auto", "first_contact_review": false}');
SELECT pg_temp.send('p1', 'mbS', 44, '{carol@partner.example}', 'Follow-up', 'Following up.');          -- unreviewed, queued
SELECT pg_temp.send('p2', 'mbS', 44, '{dana@subello.example}', 'Status', 'All on track.');             -- will be deferred
UPDATE outbound_email SET send_after = now() + interval '1 hour' WHERE id = pg_temp.i('p1');      -- not due: it stays queued
UPDATE outbound_email SET send_after = now() - interval '1 second' WHERE id = pg_temp.i('p2');
SELECT ml_outbound_claim(50);
SELECT ml_outbound_deferred(pg_temp.i('p2'), '451 4.7.1');
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"send_mode": "hold_all"}');
SELECT pg_temp.send('p3', 'mbS', 44, '{dana@subello.example}', 'Plan', 'The plan for May.');            -- held {mode}
SELECT ml_review_decide(pg_temp.i('p3'), 26, 'approve');                                                -- approved → queued
SELECT pg_temp.keep('n_before', (SELECT count(*) FROM notifications WHERE kind = 'held_mail'));
SELECT ml_mailbox_pause(pg_temp.i('mbS'), 26, true, 'Reviewing its outreach');
SELECT pg_temp.check((pg_temp.ob('p1')).status = 'held' AND (pg_temp.ob('p2')).status = 'held' AND (pg_temp.ob('p3')).status = 'held'
                     AND 'paused' = ANY ((pg_temp.ob('p2')).hold_reasons), 'pause holds queued AND deferred rows, approved or not');
SELECT pg_temp.check((SELECT count(*) FROM notifications WHERE kind = 'held_mail') = pg_temp.i('n_before'),
                     'a pause''s re-hold tells no one (the overseer who paused knows)');
SELECT pg_temp.send('p4', 'mbS', 44, '{zed@partner.example}', 'Intro', 'Hello there.');                -- held while paused: {paused, mode}
SELECT pg_temp.check(pg_temp.same((pg_temp.ob('p4')).hold_reasons, '{paused,mode}') AND pg_temp.noticed('p4', 'held_mail') = '{1,26,30}',
                     'a NEW message held while paused for other reasons too still tells its reviewers');
SELECT ml_mailbox_pause(pg_temp.i('mbS'), 26, false);
SELECT pg_temp.check((pg_temp.ob('p3')).status = 'queued', 'resume: the message a person approved, held for the pause alone, goes');
SELECT pg_temp.check((pg_temp.ob('p4')).status = 'held' AND (pg_temp.ob('p4')).hold_reasons = '{mode}',
                     'resume: held for more than the pause → the pause is no longer a reason, a person still decides');
SELECT pg_temp.check((pg_temp.ob('p1')).status = 'held' AND (pg_temp.ob('p1')).hold_reasons = '{paused}'
                     AND (pg_temp.ob('p2')).status = 'held' AND (pg_temp.ob('p2')).hold_reasons = '{paused}',
                     'resume: never reviewed and held for the pause alone → waits for a person, still saying why');
-- the claim re-holds a deferred row when the agent is paused
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"send_mode": "auto"}');
SELECT pg_temp.send('p5', 'mbS', 44, '{dana@subello.example}', 'Later', 'Later today.');
UPDATE outbound_email SET send_after = now() - interval '1 second' WHERE id = pg_temp.i('p5');
SELECT ml_outbound_claim(50);
SELECT ml_outbound_deferred(pg_temp.i('p5'), '451 4.7.1');
UPDATE agent_mail_policies SET paused = true, paused_at = now(), paused_by = 26, updated_by = 26 WHERE mailbox_id = pg_temp.i('mbS');
SELECT ml_outbound_claim(50);
SELECT pg_temp.check((pg_temp.ob('p5')).status = 'held' AND 'paused' = ANY ((pg_temp.ob('p5')).hold_reasons),
                     'the claim re-holds a DEFERRED row of a paused agent (the kill switch reaches it)');
SELECT ml_mailbox_pause(pg_temp.i('mbS'), 26, false);

-- ================================================================ 10. the summary: one gatherer for the cards and the tools
SELECT pg_temp.as_member(26);
SELECT pg_temp.keep('sum', 1);
CREATE TEMP TABLE summ AS SELECT ml_agent_mail_summary(pg_temp.i('mbS'), 7) AS s;
SELECT pg_temp.check((SELECT (s->>'sent')::int = (SELECT count(*) FROM outbound_email WHERE mailbox_id = pg_temp.i('mbS') AND sender_is_agent AND status = 'sent')
                         AND (s->>'held_now')::int = (SELECT count(*) FROM outbound_email WHERE mailbox_id = pg_temp.i('mbS') AND sender_is_agent AND status = 'held')
                         AND (s->>'approved_unedited')::int = 2 AND (s->>'edited')::int = 1 AND (s->>'rejected')::int = 1
                         AND (s->>'taken_over')::int = 4 AND (s->>'expired')::int = 1 AND (s->>'received')::int = 3
                         AND (s->>'paused')::boolean = false AND s ? 'oldest_held_at' AND s ? 'last_activity_at' FROM summ),
                     'the summary counts sent, held now, approved unedited, edited, rejected, taken over (4: the review''s, and q2, k1, k2 withdrawn by the conversation''s take-over), expired and received');
SELECT pg_temp.as_member(42);
SELECT pg_temp.check(ml_agent_mail_summary(pg_temp.i('mbS'), 7) IS NOT NULL, 'the Clerk (triage) reads it');
SELECT pg_temp.as_member(31);
SELECT pg_temp.refused(format($$SELECT ml_agent_mail_summary(%s, 7)$$, pg_temp.i('mbS')), 'No such mailbox', 'someone who cannot open the mailbox: it does not exist');
SELECT pg_temp.as_member(NULL);

-- ================================================================ 11. new mail, coalesced
SELECT pg_temp.keep('nm1', ml_notify_coalesce(26, pg_temp.i('mbS'), 2));
SELECT pg_temp.check((SELECT title = '2 new messages in sched@subello.example' AND item_count = 2 FROM notifications WHERE id = pg_temp.i('nm1')),
                     'new mail: one notice with its count, the mailbox named, no sender or subject');
SELECT pg_temp.keep('nm2', ml_notify_coalesce(26, pg_temp.i('mbS'), 3));
SELECT pg_temp.check(pg_temp.i('nm2') = pg_temp.i('nm1') AND (SELECT title = '5 new messages in sched@subello.example' AND item_count = 5
                       FROM notifications WHERE id = pg_temp.i('nm1')), 'more within half an hour raises the same unread notice');
UPDATE notifications SET read_at = now() WHERE id = pg_temp.i('nm1');
SELECT pg_temp.keep('nm3', ml_notify_coalesce(26, pg_temp.i('mbS'), 1));
SELECT pg_temp.check(pg_temp.i('nm3') <> pg_temp.i('nm1') AND (SELECT title = '1 new message in sched@subello.example' FROM notifications WHERE id = pg_temp.i('nm3')),
                     'once read, the next new mail is a new notice (singular)');
SELECT pg_temp.backdate(format('UPDATE notifications SET created_at = now() - interval ''31 minutes'' WHERE id = %s', pg_temp.i('nm3')));
SELECT pg_temp.keep('nm4', ml_notify_coalesce(26, pg_temp.i('mbS'), 1));
SELECT pg_temp.check(pg_temp.i('nm4') <> pg_temp.i('nm3'), 'after half an hour, a new notice even if the old one is unread');
SELECT pg_temp.check(ml_notify_coalesce(31, pg_temp.i('mbS'), 4) IS NULL AND ml_notify_coalesce(26, pg_temp.i('mbS'), 0) IS NULL
                     AND ml_notify_coalesce(44, pg_temp.i('mbS'), 1) IS NULL,
                     'none for a mailbox one cannot open, for nothing new, or for an agent');

-- ================================================================ 12. the wake-ups and the grants
SELECT pg_temp.check((SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname IN
                       ('outbound_email_wake', 'outbound_email_held_notice', 'mail_drafts_wake_insert', 'mail_drafts_wake_update')) = 4,
                     'the triggers: ml_outbox when a row becomes queued, ml_drafts when a draft is saved or discarded, the held notice');
SELECT pg_temp.check(pg_get_functiondef('ml_outbox_wake'::regproc) LIKE '%pg_notify(''ml_outbox''%'
                     AND pg_get_functiondef('ml_drafts_wake'::regproc) LIKE '%pg_notify(''ml_drafts''%', 'they notify the mail service''s two channels');
WITH d AS (INSERT INTO mail_drafts (mailbox_id, member_id, draft_key, to_addresses, subject, body_text)
           VALUES (pg_temp.i('mbP'), 26, '<dr1@subello.example>', '{jon@partner.example}', 'Draft', 'Draft text.') RETURNING id)
SELECT pg_temp.keep('dr1', id) FROM d;
UPDATE mail_drafts SET filed_at = now() WHERE id = pg_temp.i('dr1');
SELECT pg_temp.check((SELECT filed_at IS NOT NULL FROM mail_drafts WHERE id = pg_temp.i('dr1')), 'a draft''s filed_at is the mail service''s to write (no wake-up loop)');
SELECT pg_temp.check(has_function_privilege('malumail_records_ro', 'ml_agent_mail_summary(bigint, integer)', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_records_ro', 'ml_outbound_retry(bigint, bigint)', 'EXECUTE')
                     AND NOT has_function_privilege('malumail_records_ro', 'ml_outbound_lost(bigint)', 'EXECUTE')
                     AND has_function_privilege('malumail_rw', 'ml_notify_coalesce(bigint, bigint, integer)', 'EXECUTE'),
                     'the read role reaches the summary alone; the writes are the application''s');

RESET ROLE;
\o
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || n || '  ' || label FROM proof ORDER BY n;
SELECT (SELECT count(*) FROM proof WHERE ok) || ' ok, ' || (SELECT count(*) FROM proof WHERE NOT ok) || ' failed' AS result;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the sending and oversight proof failed'; END IF; END $$;
ROLLBACK;
