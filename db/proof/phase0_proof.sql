-- Phase 0 proof of the schema (docs/malumail-design.md §10). Everything happens inside ONE transaction that is ROLLED BACK: fixtures written
-- as malumail_rw (the web application and the mail service), reads as malumail_records_ro, acting members set through app.member_id (and the
-- person behind an agent's run through app.on_behalf_of) exactly as PHP and the MCP servers set them. Fixtures are synthetic: the business is
-- subello.example, outsiders partner.example / else.example. Run as postgres on a SCRATCH database after every migration (tests/phase0/schema.sh):
--   sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d ml_proof -f db/proof/phase0_proof.sql; echo "exit $?"
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
CREATE OR REPLACE FUNCTION pg_temp.behalf(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.on_behalf_of', COALESCE(p::text, ''), true) $$;
CREATE TEMP TABLE ids (k text PRIMARY KEY, id bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS bigint LANGUAGE sql AS $$ INSERT INTO ids (k, id) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET id = EXCLUDED.id RETURNING id $$;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.same(a text[], b text[]) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$ SELECT COALESCE(a, '{}') @> COALESCE(b, '{}') AND COALESCE(a, '{}') <@ COALESCE(b, '{}') $$;
CREATE OR REPLACE FUNCTION pg_temp.folders(mb bigint) RETURNS void LANGUAGE sql AS $$
    INSERT INTO mail_folders (mailbox_id, name, special_use, uidvalidity, uidnext) VALUES
      (mb, 'INBOX', 'inbox', 1, 1), (mb, 'Sent', 'sent', 1, 1), (mb, 'Drafts', 'drafts', 1, 1), (mb, 'Trash', 'trash', 1, 1),
      (mb, 'Junk', 'junk', 1, 1), (mb, 'Archive', 'archive', 1, 1);
$$;
CREATE OR REPLACE FUNCTION pg_temp.f(mb bigint, su text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM mail_folders WHERE mailbox_id = mb AND special_use = su $$;
-- a message on the server, as the mail service writes it (x: irt, refs[], cc[], dmarc, auto, list, rpnull, seen, flagged, att, names[], body, size, uid, uidvalidity)
CREATE OR REPLACE FUNCTION pg_temp.msg(k text, mb bigint, su text, mid text, frm text, tos text[], subj text, at timestamptz, x jsonb DEFAULT '{}') RETURNS bigint
    LANGUAGE plpgsql AS $$
DECLARE fid bigint := pg_temp.f(mb, su); key bigint; u bigint; newid bigint; ccs text[] := COALESCE(ARRAY(SELECT jsonb_array_elements_text(x->'cc')), '{}');
BEGIN
    key := ml_message_key_for(mb, mid, ml_envelope_digest(at::text, frm, array_to_string(tos, ','), array_to_string(ccs, ','), subj));
    u := COALESCE((x->>'uid')::bigint, (SELECT COALESCE(max(m.uid), 0) + 1 FROM mail_messages m WHERE m.folder_id = fid));
    INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, in_reply_to, references_ids, from_address, from_name,
                               to_addresses, cc_addresses, subject, sent_at, received_at, size, seen, flagged, has_attachments, attachment_names,
                               dmarc_pass, auto_submitted, is_list, return_path_null, snippet, body_text)
    VALUES (fid, COALESCE((x->>'uidvalidity')::bigint, 1), u, key, mid, x->>'irt', COALESCE(ARRAY(SELECT jsonb_array_elements_text(x->'refs')), '{}'),
            frm, x->>'name', tos, ccs, subj, at, at, COALESCE((x->>'size')::integer, 2000), COALESCE((x->>'seen')::boolean, false),
            COALESCE((x->>'flagged')::boolean, false), COALESCE((x->>'att')::boolean, false), COALESCE(ARRAY(SELECT jsonb_array_elements_text(x->'names')), '{}'),
            CASE WHEN x ? 'dmarc' THEN (x->>'dmarc')::boolean ELSE true END, x->>'auto', COALESCE((x->>'list')::boolean, false),
            COALESCE((x->>'rpnull')::boolean, false), left(COALESCE(x->>'body', subj), 200), x->>'body')
    RETURNING mail_messages.id INTO newid;
    PERFORM pg_temp.keep(k, newid);
    PERFORM pg_temp.keep('key:' || k, key);
    RETURN newid;
END$$;
CREATE TEMP TABLE dec (k text PRIMARY KEY, d text);
GRANT ALL ON dec TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.decide(k text) RETURNS text LANGUAGE sql AS $$
    INSERT INTO dec (k, d) VALUES ($1, ml_dispatch_decide(pg_temp.i($1))) ON CONFLICT (k) DO UPDATE SET d = EXCLUDED.d RETURNING d $$;
CREATE OR REPLACE FUNCTION pg_temp.d(k text) RETURNS text LANGUAGE sql STABLE AS $$ SELECT d FROM dec WHERE dec.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.key(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT id FROM ids WHERE ids.k = 'key:' || $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.thr(k text) RETURNS bigint LANGUAGE sql STABLE SECURITY DEFINER AS $$ SELECT thread_id FROM mail_messages WHERE id = pg_temp.i($1) $$;
CREATE OR REPLACE FUNCTION pg_temp.lvl(mb text, m bigint) RETURNS text LANGUAGE sql STABLE AS $$ SELECT ml_mailbox_level(pg_temp.i($1), $2) $$;
CREATE OR REPLACE FUNCTION pg_temp.why(mb text, m bigint, su text, op text) RETURNS text LANGUAGE sql STABLE AS $$ SELECT ml_mutation_refusal(pg_temp.i($1), $2, NULL, pg_temp.f(pg_temp.i($1), $3), $4) $$;
CREATE OR REPLACE FUNCTION pg_temp.hold(mb text, s bigint, tos text[], bccs text[] DEFAULT '{}', att boolean DEFAULT false, rk bigint DEFAULT NULL) RETURNS text[]
    LANGUAGE sql STABLE AS $$ SELECT ml_outbound_hold_reasons(pg_temp.i($1), $2, $3, '{}', $4, $5, $6) $$;
CREATE OR REPLACE FUNCTION pg_temp.send(k text, mb text, s bigint, tos text[], subj text, body text, x jsonb DEFAULT '{}') RETURNS bigint LANGUAGE plpgsql AS $$
DECLARE o bigint;
BEGIN
    o := ml_outbound_submit(pg_temp.i(mb), s, NULL, tos, '{}', COALESCE(ARRAY(SELECT jsonb_array_elements_text(x->'bcc')), '{}'), subj, body, NULL,
                            (x->>'reply_key')::bigint, (x->>'draft')::bigint, COALESCE(x->>'purpose', CASE WHEN s IN (40, 42, 43, 44, 45) THEN 'proof' END),
                            COALESCE((x->>'att')::boolean, false));
    RETURN pg_temp.keep(k, o);
END$$;
CREATE OR REPLACE FUNCTION pg_temp.ob(k text) RETURNS outbound_email LANGUAGE sql STABLE SECURITY DEFINER AS $$ SELECT * FROM outbound_email WHERE id = pg_temp.i($1) $$;
CREATE OR REPLACE FUNCTION pg_temp.dispatch(k text) RETURNS agent_dispatches LANGUAGE sql STABLE SECURITY DEFINER AS $$
    SELECT * FROM agent_dispatches WHERE message_key_id = pg_temp.key($1) ORDER BY id DESC LIMIT 1 $$;
CREATE OR REPLACE FUNCTION pg_temp.search(q text, mb text DEFAULT NULL) RETURNS bigint[] LANGUAGE sql STABLE AS $$
    SELECT COALESCE(array_agg(message_id ORDER BY message_id), '{}') FROM ml_search($1, pg_temp.i($2), 500, 0) $$;
CREATE OR REPLACE FUNCTION pg_temp.cols(t text) RETURNS text[] LANGUAGE sql STABLE AS $$
    SELECT array_agg(attname::text ORDER BY attnum) FROM pg_attribute WHERE attrelid = t::regclass AND attnum > 0 AND NOT attisdropped $$;
CREATE OR REPLACE FUNCTION pg_temp.backdate(p_sql text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER AS $$
BEGIN   -- test-only: move a timestamp into the past past the guards (the transaction's now() never advances)
    PERFORM set_config('session_replication_role', 'replica', true);
    EXECUTE p_sql;
    PERFORM set_config('session_replication_role', 'origin', true);
END$$;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA pg_temp TO PUBLIC;

-- ================================================================ fixtures: every actor of §2 (bin/dev_directory.json's, and two more agents)
SET ROLE malumail_rw;
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',  'owner@subello.example', 'super_admin', false, 'active', 'admin', '{admin}'),   -- a super-admin = a Mail admin
 (26, 'human', 'SMOKE Priya',  'priya@subello.example', 'user',        false, 'active', 'write', '{user}'),    -- Sales; overseer of the Scheduler
 (27, 'human', 'SMOKE Marco',  'marco@subello.example', 'user',        false, 'active', 'admin', '{admin,user}'), -- Mail admin ROLE, not a super-admin
 (28, 'human', 'SMOKE Bea',    'bea@subello.example',   'user',        false, 'active', 'write', '{}'),        -- write, no roles yet → Mail user
 (29, 'human', 'SMOKE Ann',    'ann@else.example',      'user',        true,  'active', 'read',  '{viewer}'),  -- an external with a role MaluMail lacks
 (30, 'human', 'SMOKE Dana',   'dana@subello.example',  'user',        false, 'active', 'write', '{user}'),    -- Sales
 (31, 'human', 'SMOKE Lee',    'lee@subello.example',   'user',        false, 'active', 'read',  '{}'),        -- read, no roles → no role
 (40, 'agent', 'SMOKE Mail Expert', NULL,               'user',        false, 'active', 'write', '{user}'),
 (41, 'agent', 'SMOKE Watcher',     NULL,               'user',        false, 'active', NULL,    '{}'),        -- not admitted
 (42, 'agent', 'SMOKE Mail Clerk',  NULL,               'user',        false, 'active', 'write', '{user}'),
 (43, 'agent', 'SMOKE Ada',         NULL,               'user',        false, 'active', 'admin', '{admin}'),   -- an agent given Mail admin
 (44, 'agent', 'SMOKE Scheduler',   NULL,               'user',        false, 'active', 'write', '{user}'),    -- in Sales; has a mailbox
 (45, 'agent', 'SMOKE Seamus',      NULL,               'user',        false, 'active', 'write', '{user}');    -- an assistant: the kernel's intake
INSERT INTO departments (id, name) VALUES (7, 'Sales'), (8, 'Marketing');
INSERT INTO department_members (member_id, department_id, is_primary) VALUES (26, 7, true), (30, 7, true), (44, 7, true), (28, 8, true);
UPDATE ml_settings SET expert_member_id = 40, clerk_member_id = 42, business_name = 'Subello' WHERE id = 1;
INSERT INTO ml_domains (domain, served, internal, created_by) VALUES ('subello.example', true, true, 1);

-- ================================================================ 1. administration: only a Mail admin, a super-admin (D2)
SELECT pg_temp.keep('mbP', ml_mailbox_create(1, 'priya@subello.example', 'Priya', 'person', 26));
SELECT pg_temp.keep('mbD', ml_mailbox_create(1, 'dana@subello.example', 'Dana', 'person', 30));
SELECT pg_temp.keep('mbS', ml_mailbox_create(1, 'sched@subello.example', 'Scheduler', 'agent', 44, 26));
SELECT pg_temp.keep('mbA', ml_mailbox_create(1, 'ada@subello.example', 'Ada', 'agent', 43, 1));
SELECT pg_temp.keep('mbSales', ml_mailbox_create(1, 'sales@subello.example', 'Sales', 'shared', NULL));
SELECT pg_temp.keep('mbSeamus', ml_mailbox_create(1, 'seamus@subello.example', 'Seamus', 'agent', 45, 1));
SELECT pg_temp.keep('mbSupport', ml_mailbox_create(1, 'support@subello.example', 'Support', 'shared', NULL));
SELECT ml_mailbox_intake_set(pg_temp.i('mbSeamus'), 1, 'kernel'), ml_mailbox_intake_set(pg_temp.i('mbSupport'), 1, 'helpdesk');
SELECT pg_temp.folders(pg_temp.i(x)) FROM unnest(ARRAY['mbP', 'mbD', 'mbS', 'mbA', 'mbSales', 'mbSeamus', 'mbSupport']) x;
SELECT pg_temp.check((SELECT count(*) FROM mailboxes) = 7, 'the super-admin creates person, agent and shared mailboxes');
SELECT pg_temp.refused($$SELECT ml_mailbox_create(27, 'x1@subello.example', 'X', 'shared', NULL)$$, 'Only a Mail admin',
                       'Marco holds the Mail admin ROLE but is no super-admin: refused, told why');
SELECT pg_temp.refused($$SELECT ml_mailbox_create(43, 'x2@subello.example', 'X', 'shared', NULL)$$, 'an agent never administers mail',
                       'Ada, an agent given the Mail admin role: refused — agents never administer');
SELECT pg_temp.refused($$SELECT ml_mailbox_create(26, 'x3@subello.example', 'X', 'shared', NULL)$$, 'Only a Mail admin', 'a Mail user cannot create a mailbox');
SELECT pg_temp.refused($$SELECT ml_mailbox_create(1, 'x@partner.example', 'X', 'shared', NULL)$$, 'serves no domain', 'a mailbox only on a served domain');
SELECT pg_temp.refused($$SELECT ml_mailbox_create(1, 'x4@subello.example', 'X', 'person', 44)$$, 'belongs to a person', 'a person''s mailbox is a person''s');
SELECT pg_temp.refused($$SELECT ml_mailbox_create(1, 'x5@subello.example', 'X', 'agent', 26)$$, 'belongs to an agent', 'an agent''s mailbox is an agent''s');
SELECT pg_temp.check((SELECT ai_read AND share_with_apps FROM mailboxes WHERE id = pg_temp.i('mbS'))
                     AND (SELECT NOT ai_read AND NOT share_with_apps FROM mailboxes WHERE id = pg_temp.i('mbP'))
                     AND (SELECT ai_read AND share_with_apps FROM mailboxes WHERE id = pg_temp.i('mbSales')),
                     'ai_read and the K7 share: on for agent and shared mailboxes, OFF for a person''s (§3, §4)');
SELECT pg_temp.check((SELECT send_mode = 'hold_all' AND first_contact_review AND attachments_review AND max_external_per_day = 20 AND max_per_hour = 50
                             AND max_per_thread_per_day = 5 AND wake_from = 'internal_and_known' AND disclosure AND NOT paused
                        FROM agent_mail_policies WHERE mailbox_id = pg_temp.i('mbS')),
                     'an agent mailbox gets its policy from the settings: hold_all, first contact and attachments held, 20/50/5, internal_and_known (D10)');
SELECT pg_temp.check((SELECT primary_overseer_id FROM mailboxes WHERE id = pg_temp.i('mbS')) = 26 AND pg_temp.lvl('mbS', 26) = 'full',
                     'the overseer named at creation holds full and is the primary overseer');
SELECT pg_temp.check(pg_temp.lvl('mbS', 42) = 'triage' AND pg_temp.lvl('mbA', 42) = 'triage', 'the Mail Clerk holds triage on every agent''s mailbox (a row made with it)');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM agent_mail_policies WHERE mailbox_id IN (pg_temp.i('mbP'), pg_temp.i('mbSales'))),
                     'only an agent''s mailbox has a mail policy');
SELECT pg_temp.check((SELECT count(*) FROM mail_identities WHERE is_default AND kind = 'own') = 7, 'every mailbox sends as its own address by default');

-- ================================================================ 2. the wall (§3)
SELECT pg_temp.check(pg_temp.lvl('mbP', 26) = 'full' AND pg_temp.lvl('mbS', 44) = 'full', 'an owner holds full (a person; an agent on its own)');
SELECT pg_temp.check(pg_temp.lvl('mbP', 1) = 'none', 'a Mail admin reads no person''s mailbox by holding the role (D9)');
SELECT pg_temp.check(pg_temp.lvl('mbS', 1) = 'full' AND pg_temp.lvl('mbA', 1) = 'full', 'on an agent''s mailbox every Mail admin holds full');
SELECT pg_temp.check(pg_temp.lvl('mbS', 27) = 'none', 'Marco, Mail admin role but no super-admin: nothing on an agent''s mailbox');
SELECT pg_temp.check(pg_temp.lvl('mbP', 30) = 'none' AND pg_temp.lvl('mbP', 44) = 'none', 'no row, no mailbox: a colleague and an agent see nothing');
-- the owner shares
SELECT ml_permission_grant(pg_temp.i('mbP'), 26, 'member', 30, 'view');
SELECT pg_temp.check(pg_temp.lvl('mbP', 30) = 'view', 'the owner shares her mailbox with Dana at view');
SELECT pg_temp.refused(format($$SELECT ml_permission_grant(%s, 26, 'agent', 44, 'full')$$, pg_temp.i('mbP')), 'at most at send',
                       'an agent reaches a person''s mailbox at most at send (D12)');
SELECT ml_permission_grant(pg_temp.i('mbP'), 26, 'agent', 44, 'send');
SELECT pg_temp.check(pg_temp.lvl('mbP', 44) = 'send', 'the owner gives the Scheduler send on her mailbox');
SELECT pg_temp.refused(format($$SELECT ml_permission_grant(%s, 44, 'member', 28, 'view')$$, pg_temp.i('mbS')), 'an agent never decides',
                       'an agent never grants (refused, not paused)');
SELECT pg_temp.refused(format($$SELECT ml_permission_grant(%s, 30, 'member', 28, 'view')$$, pg_temp.i('mbP')), 'Only its owner',
                       'a view holder cannot share someone''s mailbox');
SELECT pg_temp.refused(format($$SELECT ml_permission_grant(%s, 27, 'member', 28, 'view')$$, pg_temp.i('mbP')), 'Only its owner',
                       'Marco (Mail admin role, no super-admin) cannot open a person''s mailbox even by break-glass');
-- break-glass
SELECT pg_temp.refused(format($$SELECT ml_permission_grant(%s, 1, 'member', 28, 'triage')$$, pg_temp.i('mbP')), 'check',
                       'break-glass without a reason or an expiry: refused');
SELECT pg_temp.refused(format($$SELECT ml_permission_grant(%s, 1, 'member', 28, 'triage', 'Investigating a reported payment fraud', now() + interval '40 days')$$, pg_temp.i('mbP')),
                       'check', 'break-glass past 30 days: refused');
SELECT pg_temp.refused(format($$SELECT ml_permission_grant(%s, 1, 'agent', 44, 'view', 'Investigating a reported payment fraud', now() + interval '7 days')$$, pg_temp.i('mbP')),
                       'names one person', 'break-glass never names an agent');
SELECT pg_temp.keep('bg', ml_permission_grant(pg_temp.i('mbP'), 1, 'member', 28, 'triage', 'Investigating a reported payment fraud', now() + interval '7 days'));
SELECT pg_temp.check((SELECT break_glass FROM mailbox_permissions WHERE id = pg_temp.i('bg')) AND pg_temp.lvl('mbP', 28) = 'triage',
                     'a super-admin''s row on a person''s mailbox IS break-glass: reason, expiry within 30 days');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM notifications WHERE member_id = 26 AND kind = 'break_glass' AND mailbox_id = pg_temp.i('mbP'))
                     AND EXISTS (SELECT 1 FROM notification_outbox WHERE member_id = 26 AND kind = 'break_glass'),
                     'the owner is told at once: in the bell and by mail');
-- departments: the people, never the agents
SELECT ml_permission_grant(pg_temp.i('mbSales'), 1, 'department', 7, 'triage');
SELECT pg_temp.check(pg_temp.lvl('mbSales', 26) = 'triage' AND pg_temp.lvl('mbSales', 30) = 'triage', 'a department row admits the department''s people');
SELECT pg_temp.check(pg_temp.lvl('mbSales', 44) = 'none', 'a department row admits NO agent of the department');
SELECT pg_temp.check(pg_temp.lvl('mbSales', 28) = 'none', 'nor anyone outside it');
SELECT ml_permission_grant(pg_temp.i('mbSales'), 1, 'member', 31, 'view');
SELECT ml_permission_grant(pg_temp.i('mbSales'), 1, 'member', 29, 'view');
SELECT ml_permission_grant(pg_temp.i('mbSales'), 1, 'agent', 41, 'view');
SELECT pg_temp.check(pg_temp.lvl('mbSales', 31) = 'none' AND pg_temp.lvl('mbSales', 29) = 'none' AND pg_temp.lvl('mbSales', 41) = 'none',
                     'a row is nothing without a Mail role: Lee (read, no role), Ann (a role MaluMail lacks), an agent not admitted');
SELECT ml_permission_grant(pg_temp.i('mbSupport'), 1, 'member', 26, 'triage');
-- revoking
SELECT pg_temp.refused(format($$SELECT ml_permission_revoke(%s, 30, 'member', 30)$$, pg_temp.i('mbP')), 'do not decide', 'a holder does not revoke on someone else''s mailbox');
SELECT pg_temp.refused(format($$SELECT ml_permission_revoke(%s, 26, 'member', 28)$$, pg_temp.i('mbP')), 'do not decide',
                       'the owner cannot revoke an emergency grant (a Mail admin can; it also expires)');
SELECT pg_temp.keep('rv', ml_permission_revoke(pg_temp.i('mbP'), 1, 'member', 28)::integer);
SELECT pg_temp.check(pg_temp.i('rv') = 1 AND pg_temp.lvl('mbP', 28) = 'none', 'a Mail admin ends the emergency grant');
SELECT pg_temp.keep('bg2', ml_permission_grant(pg_temp.i('mbP'), 1, 'member', 28, 'view', 'Second look at the payment fraud case', now() + interval '1 day'));
SELECT pg_temp.backdate(format('UPDATE mailbox_permissions SET expires_at = now() - interval ''1 minute'' WHERE id = %s', pg_temp.i('bg2')));
SELECT pg_temp.check(pg_temp.lvl('mbP', 28) = 'none', 'an expired row is nothing');
SELECT pg_temp.keep('expired', ml_permissions_expire());
SELECT pg_temp.check(pg_temp.i('expired') >= 1 AND NOT EXISTS (SELECT 1 FROM mailbox_permissions WHERE id = pg_temp.i('bg2')),
                     'the worker''s break_glass_expiry pass removes expired rows');

-- ================================================================ 3. the Mail Expert's one other way in (§3, R54)
SELECT pg_temp.check(ml_level_as(pg_temp.i('mbP'), 40, 26) = 'none', 'the Expert in Priya''s run: nothing while her "let the Expert read my mail" is off');
UPDATE mailboxes SET ai_read = true WHERE id = pg_temp.i('mbP');
SELECT pg_temp.check(ml_level_as(pg_temp.i('mbP'), 40, 26) = 'triage', 'switched on: the Expert reads her mailbox in HER run, capped at triage');
SELECT pg_temp.check(ml_level_as(pg_temp.i('mbP'), 40, 30) = 'none', 'in Dana''s run (Dana holds view on it): nothing — a person''s mailbox only in its owner''s run');
SELECT pg_temp.check(ml_level_as(pg_temp.i('mbP'), 40, NULL) = 'none', 'the Expert on its own: nothing');
SELECT pg_temp.check(ml_level_as(pg_temp.i('mbP'), 42, 26) = 'none', 'another agent (the Clerk) in her run: nothing — only the named Expert');
SELECT pg_temp.check(ml_level_as(pg_temp.i('mbSales'), 40, 26) = 'triage' AND ml_level_as(pg_temp.i('mbSales'), 40, 31) = 'none',
                     'a shared mailbox in a person''s run: the person''s level capped at triage; nothing for one without a Mail role');
SELECT pg_temp.as_member(40); SELECT pg_temp.behalf(26);
SELECT pg_temp.check(ml_level(pg_temp.i('mbP')) = 'triage', 'ml_level() reads the acting member and app.on_behalf_of as the records MCP sets them');
WITH d AS (INSERT INTO mail_drafts (mailbox_id, member_id, draft_key, to_addresses, subject, body_text)
           VALUES (pg_temp.i('mbP'), 40, '<draft1@subello.example>', '{jon@partner.example}', 'Re: Delivery dates', 'We accept.') RETURNING id)
SELECT pg_temp.keep('dr1', id) FROM d;
SELECT pg_temp.check(pg_temp.i('dr1') IS NOT NULL, '"draft a reply to Jon saying we accept": the Expert leaves a draft in Priya''s Drafts (triage)');
SELECT pg_temp.refused(format($$SELECT ml_outbound_submit(%s, 40, NULL, '{jon@partner.example}', '{}', '{}', 'Re: Delivery dates', 'We accept.', NULL, NULL, NULL, 'reply', false)$$,
                              pg_temp.i('mbP')), 'needs send', 'the Expert never sends from a person''s mailbox in her run (capped at triage)');
SELECT pg_temp.behalf(NULL);
SELECT pg_temp.refused(format($$INSERT INTO mail_drafts (mailbox_id, member_id, draft_key) VALUES (%s, 40, '<draft2@subello.example>')$$, pg_temp.i('mbP')),
                       'needs triage', 'without her run, the Expert writes no draft there');
SELECT pg_temp.as_member(NULL);

-- ================================================================ 4. the index: keys, conversations, threading (§6, §6.3)
-- Priya's mailbox: a conversation across INBOX and Sent
SELECT pg_temp.msg('m1', pg_temp.i('mbP'), 'inbox', '<m1@partner.example>', 'jon@partner.example', '{priya@subello.example}', 'Delivery dates', now() - interval '5 days',
                   '{"body": "The container ship arrives Tuesday at the port.", "name": "Jon Park"}');
SELECT pg_temp.msg('m2', pg_temp.i('mbP'), 'sent', '<m2@subello.example>', 'priya@subello.example', '{jon@partner.example}', 'Re: Delivery dates', now() - interval '4 days',
                   '{"irt": "<m1@partner.example>", "refs": ["<m1@partner.example>"], "seen": true}');
SELECT pg_temp.msg('m3', pg_temp.i('mbP'), 'inbox', '<m3@partner.example>', 'jon@partner.example', '{priya@subello.example}', 'RE: Re: Delivery dates', now() - interval '3 days',
                   '{"irt": "<m2@subello.example>", "refs": ["<m1@partner.example>", "<m2@subello.example>"], "att": true, "names": ["schedule.pdf"]}');
SELECT pg_temp.check(pg_temp.thr('m1') = pg_temp.thr('m2') AND pg_temp.thr('m2') = pg_temp.thr('m3'),
                     'References and In-Reply-To make ONE conversation across INBOX and Sent');
SELECT pg_temp.msg('m4', pg_temp.i('mbP'), 'inbox', '<m4@partner.example>', 'jon@partner.example', '{priya@subello.example}', 'Fwd: Delivery dates', now() - interval '2 days');
SELECT pg_temp.check(pg_temp.thr('m4') = pg_temp.thr('m1'), 'no references: the same normalised subject within 30 days among the same people joins');
SELECT pg_temp.msg('m5', pg_temp.i('mbP'), 'inbox', '<m5@else.example>', 'ann@else.example', '{priya@subello.example}', 'Delivery dates', now() - interval '2 days');
SELECT pg_temp.check(pg_temp.thr('m5') <> pg_temp.thr('m1'), 'the same subject from other people is another conversation');
SELECT pg_temp.msg('m7', pg_temp.i('mbP'), 'inbox', '<m7@partner.example>', 'jon@partner.example', '{priya@subello.example}', 'Invoice 12', now() - interval '90 days');
SELECT pg_temp.msg('m8', pg_temp.i('mbP'), 'inbox', '<m8@partner.example>', 'jon@partner.example', '{priya@subello.example}', 'Invoice 12', now() - interval '50 days');
SELECT pg_temp.check(pg_temp.thr('m8') <> pg_temp.thr('m7'), 'past 30 days the subject alone joins nothing');
SELECT pg_temp.msg('m9', pg_temp.i('mbP'), 'inbox', '<m9@partner.example>', 'jon@partner.example', '{priya@subello.example}', 'Something else entirely', now() - interval '1 day',
                   '{"refs": ["<m1@partner.example>"]}');
SELECT pg_temp.check(pg_temp.thr('m9') <> pg_temp.thr('m1'), 'a reply under a NEW subject starts a new conversation (Gmail''s rule)');
-- a reply that arrives before its parent is adopted when the parent comes
SELECT pg_temp.msg('c1', pg_temp.i('mbP'), 'inbox', '<c1@partner.example>', 'carol@partner.example', '{priya@subello.example}', 'Re: Quote', now() - interval '1 day',
                   '{"irt": "<p1@subello.example>", "refs": ["<p1@subello.example>"]}');
SELECT pg_temp.keep('c1thr', pg_temp.thr('c1'));
SELECT pg_temp.msg('p1', pg_temp.i('mbP'), 'sent', '<p1@subello.example>', 'priya@subello.example', '{dan@partner.example}', 'Quote', now() - interval '2 days', '{"seen": true}');
SELECT pg_temp.check(pg_temp.thr('c1') = pg_temp.thr('p1') AND NOT EXISTS (SELECT 1 FROM mail_threads WHERE id = pg_temp.i('c1thr')),
                     'a parent arriving after its reply adopts it (the reply''s conversation joins)');
-- the 100th message closes a conversation
SELECT pg_temp.msg('r0', pg_temp.i('mbD'), 'inbox', '<r0@partner.example>', 'jon@partner.example', '{dana@subello.example}', 'Long thread', now() - interval '6 days');
SELECT pg_temp.msg('r' || g, pg_temp.i('mbD'), 'inbox', '<r' || g || '@partner.example>', 'jon@partner.example', '{dana@subello.example}', 'Re: Long thread',
                   now() - interval '6 days' + make_interval(mins => g), jsonb_build_object('refs', jsonb_build_array('<r0@partner.example>')))
  FROM generate_series(1, 100) g;
SELECT pg_temp.check((SELECT message_count FROM mail_threads WHERE id = pg_temp.thr('r0')) = 100 AND pg_temp.thr('r100') <> pg_temp.thr('r0'),
                     'a conversation holds at most 100 messages; the 101st starts another');
SELECT pg_temp.check((SELECT message_count = 4 AND unread_count = 3 AND has_attachments FROM mail_threads WHERE id = pg_temp.thr('m1')),
                     'a conversation''s figures: messages, unread (Sent not counted), attachments');
-- keys
SELECT pg_temp.check(pg_temp.key('m1') <> pg_temp.key('m3') AND (SELECT message_key FROM mail_message_keys WHERE id = pg_temp.key('m1')) = '<m1@partner.example>',
                     'a message key is the Message-ID');
SELECT pg_temp.msg('nomid', pg_temp.i('mbP'), 'inbox', NULL, 'robot@partner.example', '{priya@subello.example}', 'No id', now() - interval '1 hour');
SELECT pg_temp.check((SELECT message_key LIKE 'digest:%' FROM mail_message_keys WHERE id = pg_temp.key('nomid')), 'no Message-ID: keyed by the envelope''s digest');

-- ================================================================ 5. the application's state survives a move, a UIDVALIDITY reset and a rebuild
INSERT INTO tags (mailbox_id, slug, label, created_by) VALUES (pg_temp.i('mbP'), 'clients', 'Clients', 26);
INSERT INTO record_tags (record_type, record_id, tag_id) SELECT 'mail_message', pg_temp.key('m1'), id FROM tags WHERE slug = 'clients';
SELECT ml_thread_mute(pg_temp.i('mbP'), pg_temp.key('m3'), 26, true);
SELECT ml_viewer_read(30, pg_temp.key('m1'));
-- m1 is archived: the server gives it a new UID in another folder
DELETE FROM mail_messages WHERE id = pg_temp.i('m1');
SELECT pg_temp.msg('m1', pg_temp.i('mbP'), 'archive', '<m1@partner.example>', 'jon@partner.example', '{priya@subello.example}', 'Delivery dates', now() - interval '5 days',
                   '{"body": "The container ship arrives Tuesday at the port.", "uid": 77}');
SELECT pg_temp.check(pg_temp.key('m1') = (SELECT message_key_id FROM mail_messages WHERE id = pg_temp.i('m1')), 'moved, the message keeps its key');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM record_tags WHERE record_id = pg_temp.key('m1')) AND EXISTS (SELECT 1 FROM mail_viewer_reads WHERE member_id = 30 AND message_key_id = pg_temp.key('m1')),
                     'its label and Dana''s read state survive the move (they key on the message key, never the UID)');
SELECT pg_temp.check(pg_temp.thr('m1') = pg_temp.thr('m3') AND (SELECT muted FROM ml_thread_state(pg_temp.thr('m1'))), 'it rejoins its conversation, still muted');
-- a UIDVALIDITY reset: the folder is re-read
UPDATE mail_folders SET uidvalidity = 2 WHERE id = pg_temp.f(pg_temp.i('mbP'), 'inbox');
DELETE FROM mail_messages WHERE id = pg_temp.i('m3');
SELECT pg_temp.msg('m3', pg_temp.i('mbP'), 'inbox', '<m3@partner.example>', 'jon@partner.example', '{priya@subello.example}', 'RE: Re: Delivery dates', now() - interval '3 days',
                   '{"irt": "<m2@subello.example>", "refs": ["<m1@partner.example>", "<m2@subello.example>"], "att": true, "names": ["schedule.pdf"], "uidvalidity": 2, "uid": 1}');
SELECT pg_temp.check((SELECT muted FROM ml_thread_state(pg_temp.thr('m3'))) AND pg_temp.thr('m3') = pg_temp.thr('m1'),
                     'after a UIDVALIDITY reset the conversation''s state is found again by its message key');
-- a full rebuild of the conversations
SELECT pg_temp.keep('oldthr', pg_temp.thr('m1'));
SELECT pg_temp.check(ml_threads_rebuild(pg_temp.i('mbP')) > 10, 'the conversations rebuild from the messages');
SELECT pg_temp.check(pg_temp.thr('m1') = pg_temp.thr('m2') AND pg_temp.thr('m2') = pg_temp.thr('m3') AND pg_temp.thr('c1') = pg_temp.thr('p1')
                     AND pg_temp.thr('m9') <> pg_temp.thr('m1') AND pg_temp.thr('m8') <> pg_temp.thr('m7'),
                     'rebuilt, the conversations are the same');
SELECT pg_temp.check(pg_temp.thr('m1') <> pg_temp.i('oldthr') AND (SELECT muted FROM ml_thread_state(pg_temp.thr('m1')))
                     AND EXISTS (SELECT 1 FROM record_tags WHERE record_id = pg_temp.key('m1')),
                     'new thread ids, nothing lost: the mute and the label are found by message key');
-- a forged duplicate Message-ID inherits nothing
SELECT pg_temp.msg('forged', pg_temp.i('mbP'), 'inbox', '<m1@partner.example>', 'billing@else.example', '{priya@subello.example}', 'Urgent: new bank details',
                   now() - interval '1 hour', '{"dmarc": false}');
SELECT pg_temp.check(pg_temp.key('forged') <> pg_temp.key('m1')
                     AND (SELECT message_key FROM mail_message_keys WHERE id = pg_temp.key('forged')) LIKE '<m1@partner.example>#%',
                     'a second envelope under a known Message-ID gets a key of its own');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM record_tags WHERE record_id = pg_temp.key('forged')) AND NOT (SELECT muted FROM ml_thread_state(pg_temp.thr('forged')))
                     AND pg_temp.thr('forged') <> pg_temp.thr('m1'),
                     'the forged duplicate inherits no label, no state and no conversation');

-- ================================================================ 6. search with Gmail's operators (D5)
SELECT pg_temp.msg('trash1', pg_temp.i('mbP'), 'trash', '<t1@partner.example>', 'jon@partner.example', '{priya@subello.example}', 'Old offer', now() - interval '8 days');
SELECT pg_temp.msg('big', pg_temp.i('mbP'), 'inbox', '<big@partner.example>', 'jon@partner.example', '{priya@subello.example}', 'Drawings', now() - interval '6 hours',
                   '{"size": 2500000, "att": true, "names": ["site-plan.dwg"], "seen": true}');
SELECT pg_temp.msg('bea1', pg_temp.i('mbP'), 'inbox', '<bea1@subello.example>', 'bea@subello.example', '{priya@subello.example}', 'Lunch', now() - interval '20 days',
                   '{"cc": ["dana@subello.example"], "seen": true}');
SET ROLE malumail_records_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(pg_temp.search('from:jon', 'mbP') @> ARRAY[pg_temp.i('m1'), pg_temp.i('m3'), pg_temp.i('big')] AND NOT pg_temp.search('from:jon', 'mbP') @> ARRAY[pg_temp.i('bea1')],
                     'from: matches the sender');
SELECT pg_temp.check(pg_temp.search('to:jon', 'mbP') = ARRAY[pg_temp.i('m2')], 'to: matches a recipient');
SELECT pg_temp.check(pg_temp.search('cc:dana', 'mbP') = ARRAY[pg_temp.i('bea1')], 'cc: matches a copy');
SELECT pg_temp.check(pg_temp.search('subject:"Delivery dates"', 'mbP') @> ARRAY[pg_temp.i('m1'), pg_temp.i('m2'), pg_temp.i('m3'), pg_temp.i('m4'), pg_temp.i('m5')],
                     'subject: with a quoted phrase');
SELECT pg_temp.check(pg_temp.search('has:attachment', 'mbP') = (SELECT array_agg(x ORDER BY x) FROM unnest(ARRAY[pg_temp.i('m3'), pg_temp.i('big')]) x), 'has:attachment');
SELECT pg_temp.check(NOT pg_temp.search('is:unread', 'mbP') @> ARRAY[pg_temp.i('big')] AND pg_temp.search('is:unread', 'mbP') @> ARRAY[pg_temp.i('m4')], 'is:unread');
SELECT pg_temp.check(pg_temp.search('label:clients', 'mbP') = ARRAY[pg_temp.i('m1')], 'label: finds by the message key''s label');
SELECT pg_temp.check(pg_temp.search('"ship arrives"', 'mbP') = ARRAY[pg_temp.i('m1')] AND pg_temp.search('"arrives ship"', 'mbP') = '{}', 'a quoted phrase is a phrase');
SELECT pg_temp.check(pg_temp.search('container', 'mbP') = ARRAY[pg_temp.i('m1')], 'a word matches the body text');
SELECT pg_temp.check(pg_temp.search('from:jon -invoice', 'mbP') @> ARRAY[pg_temp.i('m1')] AND NOT pg_temp.search('from:jon -invoice', 'mbP') && ARRAY[pg_temp.i('m7'), pg_temp.i('m8')],
                     '-word leaves matches out');
SELECT pg_temp.check(NOT pg_temp.search('offer', 'mbP') @> ARRAY[pg_temp.i('trash1')] AND pg_temp.search('offer in:trash', 'mbP') = ARRAY[pg_temp.i('trash1')]
                     AND pg_temp.search('offer in:anywhere', 'mbP') = ARRAY[pg_temp.i('trash1')], 'Trash is left out unless in: names it');
SELECT pg_temp.check(pg_temp.search('larger:1M', 'mbP') = ARRAY[pg_temp.i('big')] AND NOT pg_temp.search('smaller:1M', 'mbP') @> ARRAY[pg_temp.i('big')], 'larger: and smaller:');
SELECT pg_temp.check(pg_temp.search('filename:site-plan', 'mbP') = ARRAY[pg_temp.i('big')], 'filename:');
SELECT pg_temp.check(pg_temp.search(format('from:jon after:%s', to_char(now() - interval '10 days', 'YYYY/MM/DD')), 'mbP') @> ARRAY[pg_temp.i('m1')]
                     AND NOT pg_temp.search(format('from:jon after:%s', to_char(now() - interval '10 days', 'YYYY/MM/DD')), 'mbP') @> ARRAY[pg_temp.i('m7')]
                     AND pg_temp.search(format('subject:invoice before:%s', to_char(now() - interval '60 days', 'YYYY-MM-DD')), 'mbP') = ARRAY[pg_temp.i('m7')],
                     'after: and before:');
SELECT pg_temp.check(pg_temp.search('subject:invoice older_than:2m', 'mbP') = ARRAY[pg_temp.i('m7')] AND pg_temp.search('subject:invoice newer_than:60d', 'mbP') = ARRAY[pg_temp.i('m8')],
                     'older_than: and newer_than:');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(pg_temp.search('container') = ARRAY[pg_temp.i('m1')], 'a view holder searches what she may read');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check(pg_temp.search('container') = '{}', 'no row, no results');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(pg_temp.search('container') = '{}' AND NOT EXISTS (SELECT 1 FROM mcp_messages WHERE mailbox_id = pg_temp.i('mbP')),
                     'a Mail admin finds and reads nothing of a person''s mailbox');
SELECT pg_temp.check((SELECT my_level FROM mcp_mailboxes WHERE id = pg_temp.i('mbP')) = 'none', '…but sees its administrative row, at level none');
SELECT pg_temp.as_member(NULL);
SET ROLE malumail_rw;

-- ================================================================ 7. what counts as internal and known (§5)
INSERT INTO ml_aliases (domain_id, address, destinations, created_by) SELECT d.id, a.addr, a.dest, 1 FROM ml_domains d,
  (VALUES ('team@subello.example', '{priya@subello.example,dana@subello.example}'::citext[]),
          ('fwd@subello.example', '{priya@subello.example,someone@partner.example}'::citext[]),
          ('loop1@subello.example', '{loop2@subello.example}'::citext[]),
          ('loop2@subello.example', '{loop1@subello.example}'::citext[]),
          ('deep@subello.example', '{team@subello.example}'::citext[])) a(addr, dest)
 WHERE d.domain = 'subello.example';
SELECT pg_temp.check(ml_is_internal_address('priya@subello.example'), 'a mailbox this application serves is internal');
SELECT pg_temp.check(ml_is_internal_address('team@subello.example') AND ml_is_internal_address('deep@subello.example'),
                     'an alias whose every destination is internal is internal (through aliases)');
SELECT pg_temp.check(NOT ml_is_internal_address('fwd@subello.example'), 'an alias forwarding outside is OUTSIDE');
SELECT pg_temp.check(NOT ml_is_internal_address('loop1@subello.example'), 'an alias loop proves nothing: outside');
SELECT pg_temp.check(NOT ml_is_internal_address('nobody@subello.example'), 'an unknown address on the business''s domain proves nothing: outside');
SELECT pg_temp.check(NOT ml_is_internal_address('jon@partner.example'), 'an outside address is outside');
SELECT ml_correspondent_received(pg_temp.i('mbS'), 'stranger@partner.example', 'A Stranger');
SELECT ml_correspondent_received(pg_temp.i('mbS'), 'stranger@partner.example', 'A Stranger');
SELECT pg_temp.check(NOT ml_is_known(pg_temp.i('mbS'), 'stranger@partner.example'), 'mail only RECEIVED never makes a sender known (twice is no better)');
SELECT pg_temp.refused($$INSERT INTO mail_correspondents (mailbox_id, address, known_at, known_by, known_how) VALUES ((SELECT id FROM mailboxes WHERE address = 'sched@subello.example'), 'x@partner.example', now(), 44, 'marked')$$,
                       'an agent never decides', 'an agent cannot make a sender known');
SELECT pg_temp.refused(format($$SELECT ml_sender_mark_known(%s, 'stranger@partner.example', 44)$$, pg_temp.i('mbS')), 'an agent never decides',
                       'marking a sender known is a person''s (refused to the agent)');
SELECT pg_temp.refused(format($$SELECT ml_sender_mark_known(%s, 'stranger@partner.example', 30)$$, pg_temp.i('mbS')), 'do not oversee',
                       '…and only an overseer''s');

-- ================================================================ 8. the policy: why an agent's message is held (§5)
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{priya@subello.example}'), '{mode,first_contact}'), 'hold_all: mode, and a first contact');
SELECT pg_temp.check(pg_temp.hold('mbP', 26, '{jon@partner.example}') = '{}', 'a person''s mail is never held');
SELECT pg_temp.refused(format($$SELECT ml_policy_set(%s, 44, '{"send_mode": "auto"}')$$, pg_temp.i('mbS')), 'an agent never decides', 'an agent never widens its own policy');
SELECT pg_temp.refused(format($$SELECT ml_policy_set(%s, 30, '{"send_mode": "auto"}')$$, pg_temp.i('mbS')), 'do not oversee', 'a person who does not oversee it cannot either');
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"send_mode": "internal_auto"}');
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{priya@subello.example}'), '{first_contact}'), 'internal_auto: to a colleague only the first contact holds');
SELECT ml_sender_mark_known(pg_temp.i('mbS'), 'priya@subello.example', 26);
SELECT pg_temp.check(pg_temp.hold('mbS', 44, '{priya@subello.example}') = '{}', 'to a known colleague: nothing holds');
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{priya@subello.example}', '{jon@partner.example}'), '{mode,first_contact}'),
                     'EVERY recipient is tested: one outside Bcc makes the message outside');
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{fwd@subello.example}'), '{mode,first_contact}'), 'an alias forwarding outside makes it outside');
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{team@subello.example}'), '{first_contact}'), 'an alias forwarding only inside stays inside');
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"send_mode": "allowlist_auto", "allow_domains": ["partner.example"]}');
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{jon@partner.example}'), '{first_contact}') AND pg_temp.same(pg_temp.hold('mbS', 44, '{x@else.example}'), '{mode,first_contact}'),
                     'allowlist_auto: a listed domain passes the mode, another does not');
SELECT ml_sender_mark_known(pg_temp.i('mbS'), 'jon@partner.example', 26);
SELECT pg_temp.check(pg_temp.hold('mbS', 44, '{jon@partner.example}') = '{}', 'a listed, known recipient: nothing holds');
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"send_mode": "auto"}');
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{new@else.example}'), '{first_contact}'), 'auto: the first contact still holds');
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{jon@partner.example}', '{}', true), '{attachment}'), 'an attachment holds');
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"first_contact_review": false, "max_per_hour": 2, "max_external_per_day": 1, "max_per_thread_per_day": 1}');
SELECT pg_temp.check(pg_temp.hold('mbS', 44, '{new@else.example}') = '{}', 'first-contact review off: a stranger passes (auto)');
-- the caps count what was sent
SELECT pg_temp.msg('s1', pg_temp.i('mbS'), 'inbox', '<s1@subello.example>', 'priya@subello.example', '{sched@subello.example}', 'Next week', now() - interval '2 hours',
                   '{"dmarc": true}');
SELECT pg_temp.send('o1', 'mbS', 44, '{jon@partner.example}', 'Visit', 'Shall we meet on Thursday?');
SELECT pg_temp.check((pg_temp.ob('o1')).status = 'queued' AND (pg_temp.ob('o1')).send_after = now(), 'an agent''s unheld message is queued at once (no undo window)');
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{jon@partner.example}'), '{over_day}'), 'over the outside cap a day: held (over_day)');
SELECT pg_temp.send('o2', 'mbS', 44, '{priya@subello.example}', 'Re: Next week', 'Thursday works.', jsonb_build_object('reply_key', pg_temp.key('s1')));
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{priya@subello.example}'), '{over_hour}'), 'over the hourly cap: held (over_hour)');
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"max_per_hour": 50, "max_external_per_day": 20}');
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{priya@subello.example}', '{}', false, pg_temp.key('s1')), '{over_thread}'),
                     'over the per-conversation cap: held (over_thread — loops between agents break here)');
UPDATE ml_settings SET agent_external_budget = 1 WHERE id = 1;
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{jon@partner.example}'), '{over_business_budget}'), 'the business-wide budget holds every agent''s outside mail');
UPDATE ml_settings SET agent_external_budget = NULL WHERE id = 1;
SELECT pg_temp.check(ml_agent_external_budget() = 20, 'the warm-up: 20 a day in the first week');
UPDATE ml_settings SET hold_all_agents = true WHERE id = 1;
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{priya@subello.example}'), '{hold_all_agents}'), 'the Mail admins'' switch: every agent''s mail held');
UPDATE ml_settings SET hold_all_agents = false, hold_all_external = true WHERE id = 1;
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{jon@partner.example}'), '{hold_all_external}') AND pg_temp.hold('mbS', 44, '{priya@subello.example}') = '{}',
                     'the other switch: every agent''s OUTSIDE mail held');
UPDATE ml_settings SET hold_all_external = false WHERE id = 1;
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbP', 44, '{priya@subello.example}'), '{on_behalf}'), 'an agent''s mail from a PERSON''s mailbox is always held for her (D12)');
SELECT ml_permission_grant(pg_temp.i('mbSales'), 1, 'agent', 44, 'send');
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbSales', 44, '{priya@subello.example}'), '{mode}'), 'from a shared mailbox it is held for the mailbox''s people');
-- the kill switch
SELECT pg_temp.refused(format($$SELECT ml_mailbox_pause(%s, 44, true)$$, pg_temp.i('mbS')), 'an agent never decides', 'an agent cannot pause or resume itself');
SELECT ml_mailbox_pause(pg_temp.i('mbS'), 26, true, 'Reviewing its outreach');
SELECT pg_temp.check(pg_temp.same(pg_temp.hold('mbS', 44, '{priya@subello.example}'), '{paused}'), 'paused: everything held');
SELECT pg_temp.check((pg_temp.ob('o1')).status = 'held' AND 'paused' = ANY ((pg_temp.ob('o1')).hold_reasons) AND (pg_temp.ob('o2')).status = 'held',
                     'pausing holds what was already queued');
SELECT ml_mailbox_pause(pg_temp.i('mbS'), 26, false);

-- ================================================================ 9. refused outright: the loop rules and the same answer (§5)
SELECT pg_temp.msg('auto1', pg_temp.i('mbS'), 'inbox', '<auto1@partner.example>', 'jon@partner.example', '{sched@subello.example}', 'Out of office', now() - interval '1 hour',
                   '{"auto": "auto-replied"}');
SELECT pg_temp.msg('list1', pg_temp.i('mbS'), 'inbox', '<list1@partner.example>', 'news@partner.example', '{sched@subello.example}', 'Newsletter', now() - interval '1 hour', '{"list": true}');
SELECT pg_temp.msg('bounce1', pg_temp.i('mbS'), 'inbox', '<b1@partner.example>', 'mailer-daemon@partner.example', '{sched@subello.example}', 'Undelivered', now() - interval '1 hour', '{"rpnull": true}');
SELECT pg_temp.send('l1', 'mbS', 44, '{jon@partner.example}', 'Re: Out of office', 'Noted.', jsonb_build_object('reply_key', pg_temp.key('auto1')));
SELECT pg_temp.send('l2', 'mbS', 44, '{news@partner.example}', 'Re: Newsletter', 'Thanks.', jsonb_build_object('reply_key', pg_temp.key('list1')));
SELECT pg_temp.send('l3', 'mbS', 44, '{mailer-daemon@partner.example}', 'Re: Undelivered', 'Retry?', jsonb_build_object('reply_key', pg_temp.key('bounce1')));
SELECT pg_temp.check((pg_temp.ob('l1')).status = 'refused' AND (pg_temp.ob('l1')).refusal = 'loop_rule' AND (pg_temp.ob('l2')).refusal = 'loop_rule'
                     AND (pg_temp.ob('l3')).refusal = 'loop_rule' AND (pg_temp.ob('l1')).error LIKE '%written by a machine%',
                     'an agent never answers an auto-reply, list mail or a null return path — refused, and told why');
SELECT pg_temp.send('sa1', 'mbS', 44, '{jon@partner.example}', 'Thanks', 'Thanks, we received it.');
SELECT pg_temp.send('sa2', 'mbS', 44, '{JON@partner.example}', 'Thanks again', '  thanks,   we received IT. ');
SELECT pg_temp.send('sa3', 'mbS', 44, '{priya@subello.example}', 'Thanks', 'Thanks, we received it.');
SELECT pg_temp.check((pg_temp.ob('sa1')).status <> 'refused' AND (pg_temp.ob('sa2')).refusal = 'same_answer' AND (pg_temp.ob('sa3')).status <> 'refused',
                     'the same answer to the same recipient within seven days is refused; to another it is not');
SELECT pg_temp.refused(format($$SELECT ml_outbound_submit(%s, 44, NULL, '{jon@partner.example}', '{}', '{}', 'x', 'y', NULL, NULL, NULL, NULL, false)$$, pg_temp.i('mbS')),
                       'purpose', 'an agent''s message says what it is for');
SELECT pg_temp.check((pg_temp.ob('sa1')).disclosure = 'Sent by SMOKE Scheduler, an AI agent of Subello.' AND (pg_temp.ob('sa1')).auto_submitted = 'auto-generated'
                     AND (pg_temp.ob('o2')).auto_submitted = 'auto-replied',
                     'an agent''s message carries the disclosure and Auto-Submitted (auto-replied for a reply)');

-- ================================================================ 10. the outbox's status machine (§6.1 (4); D8)
WITH d AS (INSERT INTO mail_drafts (mailbox_id, member_id, draft_key, to_addresses, subject, body_text)
           VALUES (pg_temp.i('mbP'), 26, '<draft3@subello.example>', '{jon@partner.example}', 'Re: Delivery dates', 'Tuesday is fine.') RETURNING id)
SELECT pg_temp.keep('dr3', id) FROM d;
SELECT pg_temp.send('p1o', 'mbP', 26, '{jon@partner.example}', 'Re: Delivery dates', 'Tuesday is fine.', jsonb_build_object('draft', pg_temp.i('dr3'), 'reply_key', pg_temp.key('m3')));
SELECT pg_temp.check((pg_temp.ob('p1o')).status = 'queued' AND (pg_temp.ob('p1o')).send_after = now() + interval '10 seconds'
                     AND (pg_temp.ob('p1o')).in_reply_to = '<m3@partner.example>' AND (pg_temp.ob('p1o')).references_ids[3] = '<m3@partner.example>'
                     AND (pg_temp.ob('p1o')).message_id LIKE '<%.malumail@subello.example>',
                     'a person''s Send: queued for the undo window, threaded, its Message-ID minted before submission');
SELECT pg_temp.refused(format($$SELECT ml_outbound_cancel(%s, 30)$$, pg_temp.i('p1o')), 'Only its writer', 'only its writer undoes a message');
SELECT pg_temp.keep('undone', ml_outbound_cancel(pg_temp.i('p1o'), 26));
SELECT pg_temp.check(pg_temp.i('undone') = pg_temp.i('dr3') AND (pg_temp.ob('p1o')).status = 'cancelled'
                     AND (SELECT outbound_id IS NULL FROM mail_drafts WHERE id = pg_temp.i('dr3')),
                     'Undo: the row is cancelled and its draft reopened');
SELECT pg_temp.send('p2o', 'mbP', 26, '{jon@partner.example}', 'Re: Delivery dates', 'Tuesday it is.', jsonb_build_object('reply_key', pg_temp.key('m3')));
UPDATE outbound_email SET send_after = now() - interval '1 minute' WHERE id = pg_temp.i('p2o');
SELECT pg_temp.refused(format($$SELECT ml_outbound_cancel(%s, 26)$$, pg_temp.i('p2o')), 'Too late', 'past its undo window a message cannot be undone');
SELECT pg_temp.refused(format($$UPDATE outbound_email SET status = 'queued' WHERE id = %s$$, pg_temp.i('l1')), 'cannot become', 'a refused message is final');
SELECT pg_temp.keep('claimed:' || c, c) FROM ml_outbound_claim(50) c;
SELECT pg_temp.check(pg_temp.i('claimed:' || pg_temp.i('p2o')) IS NOT NULL AND (pg_temp.ob('p2o')).status = 'sending' AND (pg_temp.ob('p2o')).attempts = 1,
                     'the mail service claims due rows: sending, one attempt');
SELECT ml_outbound_sent(pg_temp.i('p2o'), '<prov@malumail>', '250 2.0.0 Ok');
SELECT pg_temp.check((pg_temp.ob('p2o')).status = 'sent' AND (pg_temp.ob('p2o')).sent_at IS NOT NULL, 'submitted and filed: sent');
SELECT pg_temp.check((SELECT known_how = 'sent' AND known_by = 26 FROM mail_correspondents WHERE mailbox_id = pg_temp.i('mbP') AND address = 'jon@partner.example'),
                     'a person''s send makes the recipient KNOWN (sent, by her)');
SELECT pg_temp.refused(format($$UPDATE outbound_email SET status = 'queued' WHERE id = %s$$, pg_temp.i('p2o')), 'cannot become', 'a sent message is final');
SELECT pg_temp.refused(format($$UPDATE outbound_email SET body_text = 'changed' WHERE id = %s$$, pg_temp.i('p2o')), 'never changed', 'a sent message is never changed');
SELECT pg_temp.refused(format($$INSERT INTO outbound_email (to_address, mailbox_id, status) VALUES ('x@else.example', %s, 'sent')$$, pg_temp.i('mbP')),
                       'held, queued, refused or suppressed', 'nothing is born sent');
SELECT pg_temp.send('p3o', 'mbP', 26, '{jon@partner.example}', 'Plans', 'See the attached plans.');
UPDATE outbound_email SET send_after = now() - interval '1 minute' WHERE id = pg_temp.i('p3o');
SELECT ml_outbound_claim(50);
SELECT ml_outbound_deferred(pg_temp.i('p3o'), '451 4.7.1 Try again later');
SELECT pg_temp.check((pg_temp.ob('p3o')).status = 'deferred' AND (pg_temp.ob('p3o')).retry_at > now(), 'a 451 defers the row with a backoff');
UPDATE outbound_email SET status = 'sending', attempts = 5 WHERE id = pg_temp.i('p3o');
SELECT ml_outbound_deferred(pg_temp.i('p3o'), '451 4.7.1 Try again later');
SELECT pg_temp.check((pg_temp.ob('p3o')).status = 'failed' AND (pg_temp.ob('p3o')).error LIKE 'MaluMail kept deferring%', 'the fifth deferral fails it, saying why');

-- ================================================================ 11. review: PEOPLE decide held mail (§5)
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"send_mode": "hold_all", "first_contact_review": true}');
SELECT pg_temp.send('h1', 'mbS', 44, '{carol@partner.example}', 'Hello', 'We would like to introduce our scheduling service.');
SELECT pg_temp.check((pg_temp.ob('h1')).status = 'held' AND pg_temp.same((pg_temp.ob('h1')).hold_reasons, '{mode,first_contact}') AND (pg_temp.ob('h1')).held_at IS NOT NULL,
                     'held, with every reason');
SELECT pg_temp.refused(format($$UPDATE outbound_email SET status = 'queued' WHERE id = %s$$, pg_temp.i('h1')), 'only by a person''s approval',
                       'held mail never leaves except by a person''s approval');
SELECT pg_temp.refused(format($$SELECT ml_review_decide(%s, 44, 'approve')$$, pg_temp.i('h1')), 'an agent never decides', 'the agent cannot approve its own mail');
SELECT pg_temp.refused(format($$SELECT ml_review_decide(%s, 42, 'approve')$$, pg_temp.i('h1')), 'an agent never decides', 'the Clerk (triage) cannot decide either');
SELECT pg_temp.refused(format($$SELECT ml_review_decide(%s, 30, 'approve')$$, pg_temp.i('h1')), 'do not review', 'a person who does not oversee it cannot');
SELECT pg_temp.refused(format($$SELECT ml_review_decide(%s, 26, 'reject')$$, pg_temp.i('h1')), 'check', 'a rejection carries a note');
SELECT pg_temp.check(ml_review_decide(pg_temp.i('h1'), 26, 'reply', 'Who is this for — did they ask?') IS NOT NULL AND (pg_temp.ob('h1')).status = 'held',
                     'replying to the agent keeps the message held');
SELECT ml_review_decide(pg_temp.i('h1'), 26, 'edit', NULL, 'Hello from Subello', 'We would like to introduce our scheduling service. — Priya');
SELECT pg_temp.check((pg_temp.ob('h1')).status = 'queued' AND (pg_temp.ob('h1')).auto_submitted IS NULL AND (pg_temp.ob('h1')).subject = 'Hello from Subello'
                     AND (SELECT before_digest <> after_digest FROM mail_reviews WHERE id = (pg_temp.ob('h1')).review_id),
                     'edit then send: queued, Auto-Submitted dropped, the edit recorded as digests before and after');
UPDATE outbound_email SET send_after = now() - interval '1 second' WHERE id = pg_temp.i('h1');
SELECT ml_outbound_claim(50);
SELECT ml_outbound_sent(pg_temp.i('h1'), NULL, '250 Ok');
SELECT pg_temp.check((SELECT known_how = 'sent' AND known_by = 26 FROM mail_correspondents WHERE mailbox_id = pg_temp.i('mbS') AND address = 'carol@partner.example'),
                     'an agent''s send a person approved makes the recipient known — by the approver');
SELECT pg_temp.check(NOT ml_is_known(pg_temp.i('mbS'), 'new@else.example'), '(an agent''s unreviewed send would not)');
SELECT pg_temp.check((SELECT agent_budget_started_at IS NOT NULL FROM ml_settings WHERE id = 1), 'the first agent send starts the warm-up');
SELECT pg_temp.send('h2', 'mbS', 44, '{dave@partner.example}', 'Offer', 'A special offer just for you.');
SELECT ml_review_decide(pg_temp.i('h2'), 1, 'reject', 'We do not send offers cold.');
SELECT pg_temp.check((pg_temp.ob('h2')).status = 'rejected', 'a Mail admin (full on an agent''s mailbox) may decide too: rejected with a note');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mail_reviews WHERE outbound_id = pg_temp.i('h2') AND decision = 'reject' AND note LIKE 'We do not%'),
                     'the note is kept for the agent''s next turn');
SELECT pg_temp.send('h3', 'mbS', 44, '{priya@subello.example}', 'Re: Next week', 'Shall I also book the room?',
                    jsonb_build_object('reply_key', pg_temp.key('s1'), 'purpose', 'follow-up'));
SELECT pg_temp.check((pg_temp.ob('h3')).status = 'held', '(another held reply)');
SELECT ml_review_decide(pg_temp.i('h3'), 26, 'take_over');
SELECT pg_temp.check((pg_temp.ob('h3')).status = 'cancelled' AND (SELECT taken_over_by = 26 AND agent_muted FROM ml_thread_state(pg_temp.thr('s1'))),
                     'take over: the message is withdrawn, the agent muted on the conversation, the person answers');
SELECT pg_temp.send('h4', 'mbP', 44, '{jon@partner.example}', 'Re: Delivery dates', 'Confirmed for Tuesday.',
                    jsonb_build_object('reply_key', pg_temp.key('m3'), 'purpose', 'confirm the date'));
SELECT pg_temp.check((pg_temp.ob('h4')).status = 'held' AND (pg_temp.ob('h4')).on_behalf_member_id = 44, 'from Priya''s mailbox the Scheduler''s mail waits for her');
SELECT pg_temp.refused(format($$SELECT ml_review_decide(%s, 1, 'approve')$$, pg_temp.i('h4')), 'do not review', 'a super-admin does not decide what goes out of a person''s mailbox');
SELECT ml_review_decide(pg_temp.i('h4'), 26, 'approve');
SELECT pg_temp.check((pg_temp.ob('h4')).status = 'queued', 'Priya approves it');
SELECT pg_temp.send('h5', 'mbS', 44, '{erin@partner.example}', 'Hi', 'Introducing ourselves.');
UPDATE outbound_email SET held_at = now() - interval '73 hours' WHERE id = pg_temp.i('h5');
SELECT pg_temp.keep('held_expired', ml_held_expire());
SELECT pg_temp.check(pg_temp.i('held_expired') >= 1 AND (pg_temp.ob('h5')).status = 'expired'
                     AND EXISTS (SELECT 1 FROM mail_reviews WHERE outbound_id = pg_temp.i('h5') AND decision = 'expire' AND reviewer_member_id IS NULL),
                     'held mail expires after 72 hours, recorded');
SELECT pg_temp.refused($$UPDATE mail_reviews SET note = 'rewritten'$$, 'permission denied', 'the application cannot rewrite the oversight trail');
RESET ROLE;
SELECT pg_temp.refused($$UPDATE mail_reviews SET note = 'rewritten'$$, 'append-only', '…nor can its owner: the trail is append-only');
SELECT pg_temp.refused($$DELETE FROM mail_reviews$$, 'append-only', '…nothing is deleted from it');
SET ROLE malumail_rw;

-- ================================================================ 12. the mail turn: who wakes an agent (§5, D11)
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"wake_from": "internal_and_known"}');
SELECT pg_temp.msg('d1', pg_temp.i('mbS'), 'inbox', '<d1@subello.example>', 'dana@subello.example', '{sched@subello.example}', 'Can you book Friday?', now() - interval '30 minutes');
SELECT pg_temp.decide('d1');
SELECT pg_temp.check(pg_temp.d('d1') = 'internal_unproven' AND (pg_temp.dispatch('d1')).status = 'unscreened',
                     'a colleague''s From the application did not send proves nothing until MM3: unscreened');
SELECT pg_temp.send('dsend', 'mbD', 30, '{sched@subello.example}', 'Room for Friday', 'Please book the large room.');
UPDATE outbound_email SET send_after = now() - interval '1 second' WHERE id = pg_temp.i('dsend');
SELECT ml_outbound_claim(50); SELECT ml_outbound_sent(pg_temp.i('dsend'), NULL, '250 Ok');
SELECT pg_temp.msg('d2', pg_temp.i('mbS'), 'inbox', (pg_temp.ob('dsend')).message_id, 'dana@subello.example', '{sched@subello.example}', 'Room for Friday', now() - interval '20 minutes');
SELECT pg_temp.decide('d2');
SELECT pg_temp.check(pg_temp.d('d2') = 'wake' AND (pg_temp.dispatch('d2')).status = 'queued' AND (pg_temp.dispatch('d2')).acting_member_id = 26
                     AND (pg_temp.dispatch('d2')).conversation_id = 'mail:' || pg_temp.i('mbS') || ':' || pg_temp.key('d2'),
                     'mail this application sent from a colleague wakes the agent: one turn, Priya the acting member, ids not text');
SELECT pg_temp.msg('d3', pg_temp.i('mbS'), 'inbox', '<d3@partner.example>', 'stranger@partner.example', '{sched@subello.example}', 'Partnership', now() - interval '20 minutes');
SELECT pg_temp.decide('d3');
SELECT pg_temp.check(pg_temp.d('d3') = 'unknown_sender' AND (pg_temp.dispatch('d3')).status = 'unscreened'
                     AND EXISTS (SELECT 1 FROM notifications WHERE member_id = 26 AND kind = 'unscreened'),
                     'an unknown outside sender waits Unscreened, and the overseer is told');
SELECT pg_temp.msg('d4', pg_temp.i('mbS'), 'inbox', '<d4@partner.example>', 'jon@partner.example', '{sched@subello.example}', 'Thursday?', now() - interval '20 minutes');
SELECT pg_temp.decide('d4');
SELECT pg_temp.check(pg_temp.d('d4') = 'wake', 'a known outside sender (marked by a person) wakes it');
SELECT pg_temp.msg('d5', pg_temp.i('mbS'), 'inbox', '<d5@partner.example>', 'jon@partner.example', '{sched@subello.example}', 'Thursday!', now() - interval '19 minutes', '{"dmarc": false}');
SELECT pg_temp.decide('d5');
SELECT pg_temp.check(pg_temp.d('d5') = 'unauthenticated' AND (pg_temp.dispatch('d5')).status = 'unscreened', 'only DMARC-authenticated mail wakes an agent');
SELECT pg_temp.decide('auto1');
SELECT pg_temp.decide('list1');
SELECT pg_temp.check(pg_temp.d('auto1') = 'automated' AND pg_temp.d('list1') = 'automated', 'auto-replies and list mail never wake it');
SELECT pg_temp.msg('d6', pg_temp.i('mbS'), 'inbox', '<d6@subello.example>', 'priya@subello.example', '{sched@subello.example}', 'Re: Next week', now() - interval '10 minutes',
                   '{"refs": ["<s1@subello.example>"]}');
SELECT pg_temp.decide('d6');
SELECT pg_temp.check(pg_temp.d('d6') = 'taken_over', 'a conversation a person took over wakes no one');
SELECT ml_thread_mute(pg_temp.i('mbS'), pg_temp.key('d4'), 44, true);
SELECT pg_temp.msg('d7', pg_temp.i('mbS'), 'inbox', '<d7@partner.example>', 'jon@partner.example', '{sched@subello.example}', 'Re: Thursday?', now() - interval '9 minutes',
                   '{"refs": ["<d4@partner.example>"]}');
SELECT pg_temp.decide('d7');
SELECT pg_temp.check(pg_temp.d('d7') = 'muted', 'the agent may mute a conversation (triage): a muted one wakes no one');
SELECT pg_temp.refused(format($$SELECT ml_thread_sensitive(%s, %s, 44, true)$$, pg_temp.i('mbS'), pg_temp.key('d3')), 'an agent never decides', 'marking sensitive is a person''s');
SELECT ml_thread_sensitive(pg_temp.i('mbS'), pg_temp.key('d3'), 26, true);
SELECT pg_temp.msg('d8', pg_temp.i('mbS'), 'inbox', '<d8@partner.example>', 'stranger@partner.example', '{sched@subello.example}', 'Re: Partnership', now() - interval '8 minutes',
                   '{"refs": ["<d3@partner.example>"]}');
SELECT pg_temp.decide('d8');
SELECT pg_temp.check(pg_temp.d('d8') = 'sensitive', 'a sensitive conversation is never put in an agent''s context');
SET ROLE malumail_records_ro; SELECT pg_temp.as_member(44);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_messages WHERE id IN (pg_temp.i('d3'), pg_temp.i('d8'))) AND NOT EXISTS (SELECT 1 FROM mcp_threads WHERE id = pg_temp.thr('d3'))
                     AND NOT (pg_temp.search('partnership', 'mbS') && ARRAY[pg_temp.i('d3'), pg_temp.i('d8')]),
                     'the agent reads, lists and finds none of it');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_messages WHERE id = pg_temp.i('d3')), 'its overseer still does');
SELECT pg_temp.as_member(NULL); SET ROLE malumail_rw;
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"wake_from": "internal"}');
SELECT pg_temp.msg('d9', pg_temp.i('mbS'), 'inbox', '<d9@partner.example>', 'jon@partner.example', '{sched@subello.example}', 'Quick one', now() - interval '7 minutes');
SELECT pg_temp.decide('d9');
SELECT pg_temp.check(pg_temp.d('d9') = 'unknown_sender', 'wake_from internal: even a known outsider waits');
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"wake_from": "all_authenticated"}');
SELECT pg_temp.msg('d10', pg_temp.i('mbS'), 'inbox', '<d10@partner.example>', 'fresh@else.example', '{sched@subello.example}', 'Hello', now() - interval '6 minutes');
SELECT pg_temp.decide('d10');
SELECT pg_temp.check(pg_temp.d('d10') = 'wake', 'wake_from all_authenticated: any authenticated sender');
SELECT ml_mailbox_pause(pg_temp.i('mbS'), 26, true);
SELECT pg_temp.msg('d11', pg_temp.i('mbS'), 'inbox', '<d11@partner.example>', 'jon@partner.example', '{sched@subello.example}', 'Hello again', now() - interval '5 minutes');
SELECT pg_temp.decide('d11');
SELECT pg_temp.check(pg_temp.d('d11') = 'paused', 'a paused mailbox wakes no one');
SELECT ml_mailbox_pause(pg_temp.i('mbS'), 26, false);
SELECT pg_temp.msg('k1', pg_temp.i('mbSeamus'), 'inbox', '<k1@subello.example>', 'owner@subello.example', '{seamus@subello.example}', 'Book my flight', now() - interval '5 minutes');
SELECT pg_temp.decide('k1');
SELECT pg_temp.check(pg_temp.d('k1') = 'kernel_intake', 'the kernel''s intake mailbox never dispatches from here (its channel answers it, D13)');
SELECT ml_dispatch_decide(pg_temp.i('d4'));
SELECT pg_temp.check((SELECT count(*) FROM agent_dispatches WHERE message_key_id = pg_temp.key('d4')) = 1 AND (pg_temp.dispatch('d4')).decision = 'wake',
                     'a message dispatches once, however often it is looked at');
SELECT pg_temp.decide('m1');
SELECT pg_temp.check(pg_temp.d('m1') = 'not_an_agent_inbox', 'a person''s mail never dispatches');
UPDATE ml_settings SET mm3_identity_enforced = true WHERE id = 1;
SELECT ml_policy_set(pg_temp.i('mbS'), 26, '{"wake_from": "internal"}');
SELECT pg_temp.msg('d12', pg_temp.i('mbS'), 'inbox', '<d12@subello.example>', 'dana@subello.example', '{sched@subello.example}', 'Lunch?', now() - interval '4 minutes');
SELECT pg_temp.decide('d12');
SELECT pg_temp.check(pg_temp.d('d12') = 'wake', 'once MaluMail enforces the sender''s identity (MM3), an authenticated colleague counts');
UPDATE ml_settings SET mm3_identity_enforced = false WHERE id = 1;
-- release
SELECT pg_temp.refused(format($$SELECT ml_unscreened_release(%s, 44)$$, (pg_temp.dispatch('d1')).id), 'an agent never decides', 'the agent cannot release its own Unscreened mail');
SELECT pg_temp.refused(format($$SELECT ml_unscreened_release(%s, 42)$$, (pg_temp.dispatch('d1')).id), 'an agent never decides', 'nor the Clerk');
SELECT ml_unscreened_release((pg_temp.dispatch('d1')).id, 26);
SELECT pg_temp.check((pg_temp.dispatch('d1')).status = 'queued' AND (pg_temp.dispatch('d1')).acting_member_id = 26 AND (pg_temp.dispatch('d1')).released_by = 26,
                     'Priya releases it: one turn, she the acting member');
SELECT ml_unscreened_dismiss((pg_temp.dispatch('d5')).id, 26, true);
SELECT pg_temp.check((pg_temp.dispatch('d5')).status = 'skipped' AND (pg_temp.dispatch('d5')).detail = 'spam', 'or marks it spam');

-- ================================================================ 13. intake mailboxes and view holders change nothing (§3, D13)
SELECT pg_temp.check(pg_temp.why('mbP', 30, 'inbox', 'flag') = 'Viewing a mailbox never changes it.'
                     AND pg_temp.why('mbP', 30, 'inbox', 'move') = 'Viewing a mailbox never changes it.'
                     AND pg_temp.why('mbP', 30, 'inbox', 'seen') = 'Viewing a mailbox never changes it.',
                     'a view holder changes nothing — not a flag, not a move, not \Seen');
SELECT pg_temp.check(ml_read_mode(pg_temp.i('mbP'), 30) = 'viewer_read' AND ml_read_mode(pg_temp.i('mbP'), 26) = 'store_seen',
                     'opening mail: the owner marks it read on the server, a view holder only for herself');
SELECT pg_temp.check(pg_temp.why('mbSupport', 26, 'inbox', 'seen') LIKE '%never marks its mail read%'
                     AND pg_temp.why('mbSupport', 26, 'archive', 'seen') LIKE '%never marks its mail read%',
                     'an intake mailbox is never marked \Seen (another reader treats \Seen as handled)');
SELECT pg_temp.check(pg_temp.why('mbSupport', 26, 'inbox', 'move') LIKE '%inbox stays%' AND pg_temp.why('mbSupport', 26, 'inbox', 'archive') LIKE '%inbox stays%'
                     AND pg_temp.why('mbSupport', 26, 'inbox', 'snooze') LIKE '%inbox stays%' AND pg_temp.why('mbSupport', 26, 'inbox', 'trash') LIKE '%inbox stays%'
                     AND pg_temp.why('mbSupport', 26, 'inbox', 'spam') LIKE '%inbox stays%',
                     'its INBOX cannot be moved, archived, snoozed, trashed or marked spam');
SELECT pg_temp.check(pg_temp.why('mbSupport', 26, 'inbox', 'flag') IS NULL AND pg_temp.why('mbSupport', 26, 'archive', 'move') IS NULL
                     AND ml_read_mode(pg_temp.i('mbSupport'), 26) = 'viewer_read',
                     'a flag, or a move outside its INBOX, is allowed; read state there is per person');
SELECT pg_temp.check(pg_temp.why('mbP', 26, 'inbox', 'folder_rename') LIKE '%system folders%' AND pg_temp.why('mbP', 44, 'inbox', 'flag') IS NULL
                     AND pg_temp.why('mbP', 28, 'inbox', 'flag') = 'No such mailbox.',
                     'system folders keep their names; the Scheduler (send) may flag; no row: no such mailbox');
SELECT pg_temp.refused(format($$SELECT ml_thread_snooze(%s, %s, 26, now() + interval '1 day', %s)$$, pg_temp.i('mbSupport'), pg_temp.key('m1'), pg_temp.f(pg_temp.i('mbSupport'), 'inbox')),
                       'inbox stays', 'snoozing in an intake inbox is refused');

-- ================================================================ 14. credentials and the circuit breaker (§6.1)
SELECT pg_temp.refused(format($$SELECT ml_credential_store(%s, 27, 'mailbox_password', '\x%s', 'x')$$, pg_temp.i('mbS'), repeat('ab', 60)), 'Only a Mail admin',
                       'only a Mail admin stores a credential');
SELECT pg_temp.keep('cr1', ml_credential_store(pg_temp.i('mbS'), 1, 'mailbox_password', decode(repeat('ab', 60), 'hex'), 'Created with the mailbox'));
SELECT pg_temp.check((SELECT credential_id FROM mailboxes WHERE id = pg_temp.i('mbS')) = pg_temp.i('cr1') AND ml_credential_usable(pg_temp.i('cr1'))
                     AND (SELECT last4 = right(encode(digest(ciphertext, 'sha256'), 'hex'), 4) FROM mailbox_credentials WHERE id = pg_temp.i('cr1')),
                     'a sealed credential: last4 is of the sealed box''s digest, never of the secret');
SELECT ml_credential_failed(pg_temp.i('cr1'), 'AUTHENTICATIONFAILED');
SELECT pg_temp.check(NOT ml_credential_usable(pg_temp.i('cr1')) AND (SELECT sync_state = 'failed' FROM mailboxes WHERE id = pg_temp.i('mbS'))
                     AND EXISTS (SELECT 1 FROM notifications WHERE member_id = 1 AND kind = 'credential_failed'),
                     'ONE authentication failure stops the credential, the mailbox shows failed, the Mail admins are told');
SELECT pg_temp.refused(format($$UPDATE mailbox_credentials SET status = 'ok', failed_at = NULL WHERE id = %s$$, pg_temp.i('cr1')), 'never used again',
                       'a failed credential is never used again');
SELECT pg_temp.refused(format($$UPDATE mailbox_credentials SET ciphertext = '\x%s' WHERE id = %s$$, repeat('cd', 60), pg_temp.i('cr1')), 'never rewritten',
                       'a sealed credential is never rewritten');
SELECT pg_temp.keep('cr2', ml_credential_store(pg_temp.i('mbS'), 1, 'mailbox_password', decode(repeat('ef', 60), 'hex'), 'Reset'));
SELECT pg_temp.check(ml_credential_usable(pg_temp.i('cr2')) AND (SELECT sync_state = 'new' FROM mailboxes WHERE id = pg_temp.i('mbS')), 'a person stores a new one: the mailbox syncs again');
SET ROLE malumail_records_ro;
SELECT pg_temp.refused($$SELECT count(*) FROM mailbox_credentials$$, 'permission denied', 'the records role never reads a credential');
SELECT pg_temp.refused($$SELECT count(*) FROM mail_messages$$, 'permission denied', 'nor a base table: only the views');
SELECT pg_temp.refused($$INSERT INTO mailboxes (address, display_name, kind, owner_member_id) VALUES ('x@subello.example', 'x', 'person', 26)$$, 'permission denied',
                       'the records role writes nothing');
SET ROLE malumail_rw;
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid WHERE c.relname LIKE 'mcp\_%' AND c.relkind = 'v'
                                  AND a.attname IN ('ciphertext', 'last4', 'credential_id')), 'no view carries a credential');

-- ================================================================ 15. the notice, the access log, the K7 share
SELECT pg_temp.keep('ack', ml_notice_acknowledge(26));
SELECT pg_temp.check(pg_temp.i('ack') = 1 AND ml_notice_acknowledged(26), 'a person acknowledges the monitoring notice');
SELECT pg_temp.refused($$SELECT ml_notice_acknowledge(44)$$, 'an agent never decides', 'an agent acknowledges nothing');
UPDATE ml_settings SET notice_text = notice_text || ' Agents'' replies to outside senders may wait for review.' WHERE id = 1;
SELECT pg_temp.check(NOT ml_notice_acknowledged(26) AND (SELECT notice_version FROM ml_settings) = 2, 'a new notice is a new version, acknowledged again');
INSERT INTO activity_log (actor_member_id, action, mailbox_id, entity_type, entity_id) VALUES
  (30, 'message.open', pg_temp.i('mbP'), 'mail_message', pg_temp.key('m1')),
  (26, 'message.open', pg_temp.i('mbP'), 'mail_message', pg_temp.key('m1')),
  (30, 'mailbox.view', pg_temp.i('mbP'), 'mailbox', pg_temp.i('mbP')),
  (26, 'message.open', pg_temp.i('mbS'), 'mail_message', pg_temp.key('d2'));
SELECT pg_temp.check(NOT ml_access_should_log(pg_temp.i('mbP'), 26, 'message.open'), 'the owner''s own looks are not the access log''s');
SELECT pg_temp.check(NOT ml_access_should_log(pg_temp.i('mbP'), 30, 'mailbox.view') AND ml_access_should_log(pg_temp.i('mbP'), 30, 'message.open')
                     AND ml_access_should_log(pg_temp.i('mbD'), 30, 'mailbox.view') = false AND ml_access_should_log(pg_temp.i('mbSales'), 30, 'mailbox.view'),
                     'a non-owner''s list view once per mailbox per half hour; every open every time');
SET ROLE malumail_records_ro;
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT count(*) FROM mcp_mailbox_access_log WHERE mailbox_id = pg_temp.i('mbP')) = 2, 'Priya reads who looked at her mailbox (not herself)');
SELECT pg_temp.check((SELECT count(*) FROM mcp_mailbox_access_log WHERE mailbox_id = pg_temp.i('mbS')) = 1, '…and, as its overseer, at the agent''s');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_mailbox_access_log WHERE mailbox_id = pg_temp.i('mbP')), 'Dana cannot read Priya''s access log');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check((SELECT count(*) FROM mcp_mailbox_access_log WHERE mailbox_id = pg_temp.i('mbP')) = 2, 'the Mail admins can (access.audit)');
SELECT pg_temp.as_member(44);
SELECT pg_temp.check((SELECT count(*) FROM mcp_review_queue) = 0 AND (SELECT count(*) FROM mcp_outbound WHERE id = pg_temp.i('h2')) = 1
                     AND EXISTS (SELECT 1 FROM mcp_mail_reviews WHERE outbound_id = pg_temp.i('h2') AND decision = 'reject'),
                     'the agent reads its own rejected message and the note');
SELECT pg_temp.as_member(42);
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_dispatches WHERE status = 'unscreened' AND mailbox_id = pg_temp.i('mbS'))
                     AND NOT EXISTS (SELECT 1 FROM mcp_messages WHERE mailbox_id = pg_temp.i('mbP')),
                     'the Clerk sees the agent mailbox''s Unscreened list for its note — and nothing of a person''s mailbox');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check((SELECT bool_and(can_decide) FROM mcp_review_queue WHERE mailbox_id = pg_temp.i('mbS')) IS NOT FALSE
                     AND EXISTS (SELECT 1 FROM mcp_agent_mail_policies WHERE mailbox_id = pg_temp.i('mbS')), 'the overseer''s queue and the agent''s policy');
SELECT pg_temp.as_member(NULL);
SET ROLE malumail_rw;
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ml_correspondence_index('jon@partner.example') WHERE mailbox_id = pg_temp.i('mbS'))
                     AND NOT EXISTS (SELECT 1 FROM ml_correspondence_index('jon@partner.example') WHERE mailbox_id = pg_temp.i('mbP')),
                     'K7 correspondence_index: an agent''s mailbox answers, a person''s does not until she opts in');
UPDATE mailboxes SET share_with_apps = true WHERE id = pg_temp.i('mbP');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ml_correspondence_index('jon@partner.example') WHERE mailbox_id = pg_temp.i('mbP'))
                     AND (SELECT max(length(snippet)) FROM ml_correspondence_index(NULL, 'partner.example', 100)) <= 200,
                     'opted in, it answers — subject, people, dates and a 200-character snippet');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ml_correspondence_index('stranger@partner.example') WHERE thread_id = pg_temp.thr('d3')), 'never a sensitive conversation');
SELECT pg_temp.refused($$SELECT * FROM ml_correspondence_index(NULL, NULL)$$, 'address or a domain', 'it asks about an address or a domain');

-- ================================================================ 16. the reused tables, verbatim against their siblings (§6.7)
SELECT pg_temp.check((pg_temp.cols('mailboxes'))[1:16] = ARRAY['id','address','display_name','queue_id','provider','webhook_secret_ref','signature','poll_enabled',
                     'last_polled_at','last_uid','active','created_at','updated_at','last_webhook_at','last_error','last_error_at'],
                     'mailboxes = Help Desk db/012 + the CRM''s appended columns, then ours');
SELECT pg_temp.check((pg_temp.cols('outbound_email'))[1:27] = ARRAY['id','ticket_id','message_ref_id','to_address','subject','template','provider_message_id','status',
                     'error','sent_at','created_at','sender_member_id','contact_id','deal_id','cc_addresses','body_text','body_html','message_id','template_id','has_ics',
                     'is_private','in_reply_to','references_ids','activity_id','campaign_id','attempts','retry_at'],
                     'outbound_email = Help Desk db/012 + the CRM''s db/011, 021, 022, then ours');
SELECT pg_temp.check((pg_temp.cols('tags'))[1:9] = ARRAY['id','slug','label','usage_count','created_at','kind','colour','created_by','archived_at'],
                     'tags = Help Desk db/009 + DocCloud''s appended columns, then mailbox_id');
SELECT pg_temp.check(pg_temp.cols('record_tags') = ARRAY['record_type','record_id','tag_id'], 'record_tags = the CRM''s shape');
SELECT pg_temp.check((pg_temp.cols('mailbox_credentials'))[1:10] = ARRAY['id','source_id','kind','label','last4','ciphertext','rotated_at','created_by','created_at','updated_at'],
                     'mailbox_credentials = Inventory''s source_credentials, then ours');
SELECT pg_temp.check((pg_temp.cols('mailbox_permissions'))[1:6] = ARRAY['mailbox_id','principal_kind','principal_id','level','granted_by','created_at'],
                     'mailbox_permissions = Spaces'' page_permissions (page_id → mailbox_id), then ours');
SELECT pg_temp.check((pg_temp.cols('agent_dispatches'))[1:21] = ARRAY['id','record_type','record_id','agent_member_id','kind','via','acting_member_id','run_id','request_id',
                     'status','reply_excerpt','detail','attempts','created_at','answered_at','record_uuid','channel_id','conversation_id','reply_message_id',
                     'pending_message_id','next_attempt_at'], 'agent_dispatches = Spaces db/012, then ours');
SELECT pg_temp.check((pg_temp.cols('notifications'))[1:13] = ARRAY['id','member_id','kind','record_type','record_id','title','body','read_at','created_at','record_uuid',
                     'channel_id','message_id','actor_member_id'] AND (pg_temp.cols('notification_prefs'))[1:8] = ARRAY['member_id','email_enabled','text_enabled','kinds',
                     'text_kinds','updated_at','digest','away_minutes'], 'notifications and their preferences = Spaces db/012, then ours');
SELECT pg_temp.check(pg_temp.cols('notification_outbox') = ARRAY['id','channel','member_id','to_email','kind','record_type','record_id','dedupe_key','subject','body',
                     'body_html','status','attempts','detail','provider_ref','send_after','sent_at','created_at'] AND pg_temp.cols('worker_passes') = ARRAY['id',
                     'started_at','finished_at','steps','error'], 'notification_outbox and worker_passes = Spaces verbatim');
SELECT pg_temp.check((pg_temp.cols('email_templates'))[1:12] = ARRAY['id','name','subject','body','kind','is_shared','owner_member_id','usage_count','archived_at',
                     'created_by','created_at','updated_at'] AND pg_temp.cols('attachments') = ARRAY['id','record_type','record_id','filename','mime_type','byte_size',
                     'sha256','storage_path','uploaded_by','created_at'], 'email_templates = the CRM''s; attachments = GL''s canonical');
SELECT pg_temp.check((SELECT is_nullable = 'YES' FROM information_schema.columns WHERE table_name = 'mailboxes' AND column_name = 'queue_id')
                     AND NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'outbound_email'::regclass AND contype = 'f'
                                       AND conkey @> ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid = 'outbound_email'::regclass AND attname = 'contact_id')]),
                     'the loosenings: Help Desk''s queue link and the CRM''s contact link kept as plain columns');

-- ================================================================ 17. every view a barrier
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                                  WHERE n.nspname = 'public' AND c.relkind = 'v' AND c.relname LIKE 'mcp\_%' AND c.relname <> 'mcp_app_roles'
                                    AND NOT COALESCE(c.reloptions @> ARRAY['security_barrier=true'], false)),
                     'every mcp_* view is a security barrier');

RESET ROLE;
\o
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || n || '  ' || label FROM proof ORDER BY n;
SELECT (SELECT count(*) FROM proof WHERE ok) || ' ok, ' || (SELECT count(*) FROM proof WHERE NOT ok) || ' failed' AS result;
DO $$ BEGIN IF EXISTS (SELECT 1 FROM proof WHERE NOT ok) THEN RAISE EXCEPTION 'the schema proof failed'; END IF; END $$;
ROLLBACK;
