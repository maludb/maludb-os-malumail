-- 010: PEOPLE'S DECISIONS and THE MAIL TURN (design §3, §5; D2, D9, D10, D11, D12, D13) — administering mailboxes (a Mail admin: a
-- super-admin), sharing them (only a person; on a person's mailbox only its owner, else break-glass), an agent's policy, pause and
-- resume, taking a conversation over, marking it sensitive, releasing Unscreened mail, marking a sender known — every one REFUSED to an
-- agent here, in SQL (refused, not paused) — and THE DISPATCH RULE that decides whether arriving mail wakes an agent.
--
-- Agents may (governed by the policy, never refused): mute and snooze a conversation, mark mail read where they hold triage.
-- The Mail Expert asked for a refused act opens the prefilled screen for a person (a navigation, not an action).
BEGIN;

-- The Mail admins (to tell): active human super-admins.
CREATE OR REPLACE FUNCTION ml_mail_admin_ids() RETURNS SETOF bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT id FROM members WHERE member_kind = 'human' AND status = 'active' AND business_role = 'super_admin' AND capability IS NOT NULL;
$$;

CREATE OR REPLACE FUNCTION ml_require_right(p_member bigint, p_right text) RETURNS void
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE words text;
BEGIN
    IF NOT ml_member_has_right(p_member, p_right) THEN
        words := CASE
            WHEN EXISTS (SELECT 1 FROM members WHERE id = p_member AND member_kind = 'agent') THEN
                CASE (SELECT held_by FROM ml_rights WHERE right_key = p_right)
                     WHEN 'super_admins' THEN 'MaluMail''s administration is for people: an agent never administers mail.'
                     ELSE 'That is a person''s decision: an agent never decides it.' END
            WHEN (SELECT held_by FROM ml_rights WHERE right_key = p_right) = 'super_admins' THEN 'Only a Mail admin — a super-admin of the OS — can do that.'
            ELSE 'Your MaluMail role does not let you do that.' END;
        RAISE EXCEPTION '%', words USING ERRCODE = 'insufficient_privilege';
    END IF;
END$$;

-- ================================================================================================ administration (a Mail admin)
-- Register a mailbox the mail service has just created at MaluMail (or connected). An agent's mailbox gets its policy from the
-- settings, the overseer's full row and the primary overseer, and the Mail Clerk's triage row; every mailbox its own identity.
CREATE OR REPLACE FUNCTION ml_mailbox_create(p_by bigint, p_address text, p_display_name text, p_kind text, p_owner bigint,
                                             p_overseer bigint DEFAULT NULL, p_quota_bytes bigint DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE mb bigint; s ml_settings%ROWTYPE;
BEGIN
    PERFORM ml_require_right(p_by, 'mailboxes.manage');
    SELECT * INTO s FROM ml_settings WHERE id = 1;
    INSERT INTO mailboxes (address, display_name, kind, owner_member_id, quota_bytes, created_by)
    VALUES (lower(btrim(p_address)), btrim(p_display_name), p_kind, p_owner, p_quota_bytes, p_by) RETURNING id INTO mb;
    INSERT INTO mail_identities (mailbox_id, address, kind, display_name, is_default, created_by)
    VALUES (mb, lower(btrim(p_address)), 'own', btrim(p_display_name), true, p_by);
    IF p_kind = 'agent' THEN
        INSERT INTO agent_mail_policies (mailbox_id, send_mode, first_contact_review, attachments_review, max_external_per_day, max_per_hour,
                                         max_per_thread_per_day, wake_on_mail, wake_from, disclosure, updated_by)
        VALUES (mb, s.default_send_mode, s.default_first_contact_review, s.default_attachments_review, s.default_max_external_per_day,
                s.default_max_per_hour, s.default_max_per_thread_per_day, s.default_wake_on_mail, s.default_wake_from, s.default_disclosure, p_by);
        IF p_overseer IS NOT NULL THEN
            INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) VALUES (mb, 'member', p_overseer, 'full', p_by);
            UPDATE mailboxes SET primary_overseer_id = p_overseer WHERE id = mb;
        END IF;
        IF s.clerk_member_id IS NOT NULL AND s.clerk_member_id IS DISTINCT FROM p_owner
           AND EXISTS (SELECT 1 FROM members WHERE id = s.clerk_member_id AND member_kind = 'agent') THEN
            INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) VALUES (mb, 'agent', s.clerk_member_id, 'triage', p_by);
        END IF;
    END IF;
    RETURN mb;
END$$;

CREATE OR REPLACE FUNCTION ml_mailbox_suspend(p_mailbox bigint, p_by bigint, p_on boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_right(p_by, 'mailboxes.manage');
    UPDATE mailboxes SET suspended_at = CASE WHEN p_on THEN COALESCE(suspended_at, now()) END,
                         sync_state = CASE WHEN p_on THEN 'paused' WHEN sync_state = 'paused' THEN 'new' ELSE sync_state END
     WHERE id = p_mailbox;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such mailbox' USING ERRCODE = 'no_data_found'; END IF;
END$$;

-- A Mail admin confirms (or clears) the reader a mailbox is an intake for (D13).
CREATE OR REPLACE FUNCTION ml_mailbox_intake_set(p_mailbox bigint, p_by bigint, p_owner text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_right(p_by, 'mailboxes.manage');
    UPDATE mailboxes SET intake_owner = p_owner, intake_proposed = NULL WHERE id = p_mailbox;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such mailbox' USING ERRCODE = 'no_data_found'; END IF;
END$$;

-- A sealed credential (the mail service's administration call seals it the moment MaluMail issues it): the previous one is revoked.
CREATE OR REPLACE FUNCTION ml_credential_store(p_mailbox bigint, p_by bigint, p_kind text, p_ciphertext bytea, p_label text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE cid bigint;
BEGIN
    PERFORM ml_require_right(p_by, 'mailboxes.manage');
    UPDATE mailbox_credentials SET status = 'revoked', rotated_at = now() WHERE mailbox_id = p_mailbox AND status = 'ok';
    INSERT INTO mailbox_credentials (kind, label, last4, ciphertext, created_by, mailbox_id)
    VALUES (p_kind, p_label, right(encode(digest(p_ciphertext, 'sha256'), 'hex'), 4), p_ciphertext, p_by, p_mailbox) RETURNING id INTO cid;
    UPDATE mailboxes SET credential_id = cid, sync_state = CASE WHEN sync_state = 'failed' THEN 'new' ELSE sync_state END WHERE id = p_mailbox;
    RETURN cid;
END$$;

-- THE CIRCUIT BREAKER (§6.1): one authentication failure stops all use of the credential, the mailbox shows failed, the Mail admins
-- are told — and nothing more happens until a person stores a new credential.
CREATE OR REPLACE FUNCTION ml_credential_failed(p_credential bigint, p_failure text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c mailbox_credentials%ROWTYPE; a bigint; addr text;
BEGIN
    UPDATE mailbox_credentials SET status = 'failed', failed_at = now(), failure = left(p_failure, 500)
     WHERE id = p_credential AND status = 'ok' RETURNING * INTO c;
    IF NOT FOUND THEN RETURN; END IF;
    UPDATE mailboxes SET sync_state = 'failed', last_error = 'The mail server refused this mailbox''s credential.', last_error_at = now()
     WHERE id = c.mailbox_id RETURNING address INTO addr;
    FOR a IN SELECT ml_mail_admin_ids() LOOP
        PERFORM ml_notify(a, 'credential_failed', 'MaluMail stopped using ' || addr || '''s credential',
                          'The mail server refused it once. Nothing more is tried until a Mail admin stores a new one.', c.mailbox_id, 'mailbox', c.mailbox_id);
    END LOOP;
END$$;

-- May the mail service use this credential now?
CREATE OR REPLACE FUNCTION ml_credential_usable(p_credential bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM mailbox_credentials c JOIN mailboxes b ON b.id = c.mailbox_id
                    WHERE c.id = p_credential AND c.status = 'ok' AND b.suspended_at IS NULL AND b.active);
$$;

-- ================================================================================================ sharing (a person)
CREATE OR REPLACE FUNCTION ml_permission_grant(p_mailbox bigint, p_by bigint, p_kind text, p_principal bigint, p_level text,
                                               p_reason text DEFAULT NULL, p_expires timestamptz DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pid bigint;
BEGIN
    PERFORM ml_require_person(p_by);
    INSERT INTO mailbox_permissions AS p (mailbox_id, principal_kind, principal_id, level, granted_by, reason, expires_at)
    VALUES (p_mailbox, p_kind, p_principal, p_level, p_by, NULLIF(btrim(p_reason), ''), p_expires)
    ON CONFLICT (mailbox_id, principal_kind, principal_id) DO UPDATE
        SET level = EXCLUDED.level, granted_by = EXCLUDED.granted_by, reason = EXCLUDED.reason, expires_at = EXCLUDED.expires_at, created_at = now()
    RETURNING id INTO pid;
    RETURN pid;
END$$;

-- Revoking: the owner of a person's mailbox revokes the rows they made, a Mail admin any (break-glass included); on an agent's or a
-- shared mailbox, a person with full or a Mail admin.
CREATE OR REPLACE FUNCTION ml_permission_revoke(p_mailbox bigint, p_by bigint, p_kind text, p_principal bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b mailboxes%ROWTYPE; p mailbox_permissions%ROWTYPE;
BEGIN
    PERFORM ml_require_person(p_by);
    SELECT * INTO b FROM mailboxes WHERE id = p_mailbox;
    SELECT * INTO p FROM mailbox_permissions WHERE mailbox_id = p_mailbox AND principal_kind = p_kind AND principal_id = p_principal;
    IF NOT FOUND THEN RETURN false; END IF;
    IF NOT (ml_member_has_right(p_by, 'access.audit')
            OR (b.kind = 'person' AND b.owner_member_id = p_by AND NOT p.break_glass)
            OR (b.kind <> 'person' AND ml_is_decider(p_mailbox, p_by))) THEN
        RAISE EXCEPTION 'You do not decide who opens this mailbox' USING ERRCODE = 'insufficient_privilege';
    END IF;
    DELETE FROM mailbox_permissions WHERE id = p.id;
    RETURN true;
END$$;

-- Break-glass: the owner is told AT ONCE (in the bell and by mail, whatever their preferences), while still active — on a new row and on any
-- change to one. A new ordinary member row tells its person.
CREATE OR REPLACE FUNCTION ml_permissions_notice() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b mailboxes%ROWTYPE; who text; whom text;
BEGIN
    SELECT * INTO b FROM mailboxes WHERE id = NEW.mailbox_id;
    IF NEW.break_glass AND (TG_OP = 'INSERT' OR (NEW.level, NEW.granted_by, NEW.expires_at, NEW.reason) IS DISTINCT FROM (OLD.level, OLD.granted_by, OLD.expires_at, OLD.reason)) THEN
        SELECT display_name INTO who FROM members WHERE id = NEW.granted_by;
        SELECT display_name INTO whom FROM members WHERE id = NEW.principal_id;
        PERFORM ml_notify(b.owner_member_id, 'break_glass', 'Your mailbox ' || b.address || ' was opened to ' || COALESCE(whom, 'someone') || ' in an emergency',
                          COALESCE(who, 'A super-admin') || ' gave the access until ' || to_char(NEW.expires_at, 'YYYY-MM-DD HH24:MI TZ') || '. The reason given: ' || NEW.reason,
                          NEW.mailbox_id, 'mailbox', NEW.mailbox_id, NEW.granted_by, true);
    ELSIF NEW.principal_kind = 'member' AND TG_OP = 'INSERT' THEN
        PERFORM ml_notify(NEW.principal_id, 'permission_granted', 'You can open ' || b.address || ' (' || NEW.level || ')', NULL,
                          NEW.mailbox_id, 'mailbox', NEW.mailbox_id, NEW.granted_by);
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER mailbox_permissions_notice AFTER INSERT OR UPDATE ON mailbox_permissions FOR EACH ROW EXECUTE FUNCTION ml_permissions_notice();

-- The worker's break_glass_expiry pass: expired rows go (ml_mailbox_level already ignores them). Answers how many.
CREATE OR REPLACE FUNCTION ml_permissions_expire() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM mailbox_permissions WHERE expires_at IS NOT NULL AND expires_at <= now();
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- ================================================================================================ an agent's mail (its overseers)
CREATE OR REPLACE FUNCTION ml_require_decider(p_mailbox bigint, p_by bigint) RETURNS void
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_person(p_by);
    IF NOT ml_is_decider(p_mailbox, p_by) THEN
        RAISE EXCEPTION 'You do not oversee this mailbox' USING ERRCODE = 'insufficient_privilege';
    END IF;
END$$;

-- An agent's policy, changed by its overseers (any subset of the keys; the history is the activity log's).
CREATE OR REPLACE FUNCTION ml_policy_set(p_mailbox bigint, p_by bigint, p jsonb) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE k text;
BEGIN
    PERFORM ml_require_decider(p_mailbox, p_by);
    FOR k IN SELECT jsonb_object_keys(p) LOOP
        IF k NOT IN ('send_mode', 'allow_domains', 'allow_addresses', 'first_contact_review', 'attachments_review', 'max_external_per_day',
                     'max_per_hour', 'max_per_thread_per_day', 'wake_on_mail', 'wake_from', 'disclosure', 'disclosure_text') THEN
            RAISE EXCEPTION 'A policy has no setting %', k USING ERRCODE = 'invalid_parameter_value';
        END IF;
    END LOOP;
    UPDATE agent_mail_policies SET
        send_mode = COALESCE(p->>'send_mode', send_mode),
        allow_domains = CASE WHEN p ? 'allow_domains' THEN ARRAY(SELECT lower(x)::citext FROM jsonb_array_elements_text(p->'allow_domains') x) ELSE allow_domains END,
        allow_addresses = CASE WHEN p ? 'allow_addresses' THEN ARRAY(SELECT lower(x)::citext FROM jsonb_array_elements_text(p->'allow_addresses') x) ELSE allow_addresses END,
        first_contact_review = COALESCE((p->>'first_contact_review')::boolean, first_contact_review),
        attachments_review = COALESCE((p->>'attachments_review')::boolean, attachments_review),
        max_external_per_day = COALESCE((p->>'max_external_per_day')::smallint, max_external_per_day),
        max_per_hour = COALESCE((p->>'max_per_hour')::smallint, max_per_hour),
        max_per_thread_per_day = COALESCE((p->>'max_per_thread_per_day')::smallint, max_per_thread_per_day),
        wake_on_mail = COALESCE((p->>'wake_on_mail')::boolean, wake_on_mail),
        wake_from = COALESCE(p->>'wake_from', wake_from),
        disclosure = COALESCE((p->>'disclosure')::boolean, disclosure),
        disclosure_text = CASE WHEN p ? 'disclosure_text' THEN NULLIF(btrim(p->>'disclosure_text'), '') ELSE disclosure_text END,
        updated_by = p_by
     WHERE mailbox_id = p_mailbox;
    IF NOT FOUND THEN RAISE EXCEPTION 'Only an agent''s mailbox has a mail policy' USING ERRCODE = 'check_violation'; END IF;
END$$;

-- THE KILL SWITCH: everything held, no mail turns (queued mail goes back to held at the outbox's next claim).
CREATE OR REPLACE FUNCTION ml_mailbox_pause(p_mailbox bigint, p_by bigint, p_on boolean, p_reason text DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_decider(p_mailbox, p_by);
    UPDATE agent_mail_policies SET paused = p_on, paused_at = CASE WHEN p_on THEN now() END, paused_by = CASE WHEN p_on THEN p_by END,
           pause_reason = CASE WHEN p_on THEN NULLIF(btrim(p_reason), '') END, updated_by = p_by
     WHERE mailbox_id = p_mailbox;
    IF NOT FOUND THEN RAISE EXCEPTION 'Only an agent''s mailbox is paused' USING ERRCODE = 'check_violation'; END IF;
    IF p_on THEN
        UPDATE outbound_email SET status = 'held', hold_reasons = (SELECT array_agg(DISTINCT x) FROM unnest(hold_reasons || 'paused'::text) x)
         WHERE mailbox_id = p_mailbox AND status = 'queued' AND sender_is_agent;
    END IF;
END$$;

CREATE OR REPLACE FUNCTION ml_primary_overseer_set(p_mailbox bigint, p_by bigint, p_member bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_decider(p_mailbox, p_by);
    IF NOT ml_is_decider(p_mailbox, p_member) THEN
        RAISE EXCEPTION 'The primary overseer is a person with full access to the agent''s mailbox' USING ERRCODE = 'check_violation';
    END IF;
    UPDATE mailboxes SET primary_overseer_id = p_member WHERE id = p_mailbox AND kind = 'agent';
END$$;

-- ================================================================================================ conversations
-- Take a conversation over: the person answers it, the agent is muted on it (and back).
CREATE OR REPLACE FUNCTION ml_thread_take_over(p_mailbox bigint, p_key bigint, p_by bigint, p_on boolean DEFAULT true) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_decider(p_mailbox, p_by);
    INSERT INTO mail_thread_states AS t (mailbox_id, anchor_key_id, taken_over_by, taken_over_at, agent_muted)
    VALUES (p_mailbox, p_key, CASE WHEN p_on THEN p_by END, CASE WHEN p_on THEN now() END, p_on)
    ON CONFLICT (mailbox_id, anchor_key_id) DO UPDATE SET taken_over_by = EXCLUDED.taken_over_by, taken_over_at = EXCLUDED.taken_over_at,
                                                         agent_muted = EXCLUDED.agent_muted;
    IF NOT p_on THEN
        -- giving it back clears every message's take-over in the conversation
        UPDATE mail_thread_states SET taken_over_by = NULL, taken_over_at = NULL, agent_muted = false
         WHERE mailbox_id = p_mailbox AND anchor_key_id IN (SELECT m.message_key_id FROM mail_messages m WHERE m.thread_id = ml_key_thread(p_key));
    END IF;
END$$;

-- Mute (Gmail's) — triage; an agent may.
CREATE OR REPLACE FUNCTION ml_thread_mute(p_mailbox bigint, p_key bigint, p_by bigint, p_on boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE why text;
BEGIN
    why := ml_mutation_refusal(p_mailbox, p_by, NULLIF(current_setting('app.on_behalf_of', true), '')::bigint, NULL, 'label');
    IF why IS NOT NULL THEN RAISE EXCEPTION '%', why USING ERRCODE = 'insufficient_privilege'; END IF;
    INSERT INTO mail_thread_states AS t (mailbox_id, anchor_key_id, muted, muted_by) VALUES (p_mailbox, p_key, p_on, p_by)
    ON CONFLICT (mailbox_id, anchor_key_id) DO UPDATE SET muted = EXCLUDED.muted, muted_by = EXCLUDED.muted_by;
    IF NOT p_on THEN
        UPDATE mail_thread_states SET muted = false
         WHERE mailbox_id = p_mailbox AND anchor_key_id IN (SELECT m.message_key_id FROM mail_messages m WHERE m.thread_id = ml_key_thread(p_key));
    END IF;
END$$;

-- Snooze — triage; never an intake mailbox's inbox (D13). The mail service moves the messages to the Snoozed folder and back.
CREATE OR REPLACE FUNCTION ml_thread_snooze(p_mailbox bigint, p_key bigint, p_by bigint, p_until timestamptz, p_from_folder bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE why text; fname text;
BEGIN
    why := ml_mutation_refusal(p_mailbox, p_by, NULLIF(current_setting('app.on_behalf_of', true), '')::bigint, p_from_folder, 'snooze');
    IF why IS NOT NULL THEN RAISE EXCEPTION '%', why USING ERRCODE = 'insufficient_privilege'; END IF;
    IF p_until IS NOT NULL AND p_until <= now() THEN RAISE EXCEPTION 'A snooze ends in the future' USING ERRCODE = 'invalid_parameter_value'; END IF;
    SELECT name INTO fname FROM mail_folders WHERE id = p_from_folder;
    INSERT INTO mail_thread_states AS t (mailbox_id, anchor_key_id, snoozed_until, snoozed_from_folder, snoozed_by)
    VALUES (p_mailbox, p_key, p_until, CASE WHEN p_until IS NOT NULL THEN COALESCE(fname, 'INBOX') END, CASE WHEN p_until IS NOT NULL THEN p_by END)
    ON CONFLICT (mailbox_id, anchor_key_id) DO UPDATE SET snoozed_until = EXCLUDED.snoozed_until, snoozed_from_folder = EXCLUDED.snoozed_from_folder,
                                                         snoozed_by = EXCLUDED.snoozed_by;
END$$;

-- The worker's snoozes pass: what wakes now (the mail service moves it back to where it came from); answers and clears them.
CREATE OR REPLACE FUNCTION ml_snoozes_due() RETURNS TABLE (mailbox_id bigint, anchor_key_id bigint, back_to text, snoozed_by bigint)
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    UPDATE mail_thread_states s SET snoozed_until = NULL, snoozed_from_folder = NULL, snoozed_by = NULL
      FROM (SELECT id, snoozed_from_folder AS f, snoozed_by AS b FROM mail_thread_states WHERE snoozed_until <= now() FOR UPDATE SKIP LOCKED) d
     WHERE s.id = d.id
    RETURNING s.mailbox_id, s.anchor_key_id, d.f, d.b;
$$;

-- Sensitive: never in an agent's context — a person with triage marks it (and unmarks it).
CREATE OR REPLACE FUNCTION ml_thread_sensitive(p_mailbox bigint, p_key bigint, p_by bigint, p_on boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_person(p_by);
    IF ml_level_rank(ml_mailbox_level(p_mailbox, p_by)) < 2 THEN
        RAISE EXCEPTION 'Marking a conversation needs triage on the mailbox' USING ERRCODE = 'insufficient_privilege';
    END IF;
    INSERT INTO mail_thread_states AS t (mailbox_id, anchor_key_id, sensitive, sensitive_by) VALUES (p_mailbox, p_key, p_on, p_by)
    ON CONFLICT (mailbox_id, anchor_key_id) DO UPDATE SET sensitive = EXCLUDED.sensitive, sensitive_by = EXCLUDED.sensitive_by;
    IF NOT p_on THEN
        UPDATE mail_thread_states SET sensitive = false, sensitive_by = p_by
         WHERE mailbox_id = p_mailbox AND anchor_key_id IN (SELECT m.message_key_id FROM mail_messages m WHERE m.thread_id = ml_key_thread(p_key));
    END IF;
END$$;

-- Per-viewer read state (a view holder; an intake mailbox).
CREATE OR REPLACE FUNCTION ml_viewer_read(p_member bigint, p_key bigint, p_on boolean DEFAULT true) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF ml_level_rank(ml_level_as((SELECT mailbox_id FROM mail_message_keys WHERE id = p_key), p_member,
                                 NULLIF(current_setting('app.on_behalf_of', true), '')::bigint)) < 1 THEN
        RAISE EXCEPTION 'No such message' USING ERRCODE = 'no_data_found';
    END IF;
    IF p_on THEN INSERT INTO mail_viewer_reads (member_id, message_key_id) VALUES (p_member, p_key) ON CONFLICT DO NOTHING;
    ELSE DELETE FROM mail_viewer_reads WHERE member_id = p_member AND message_key_id = p_key; END IF;
END$$;

-- The notice (§3): each person acknowledges each version once.
CREATE OR REPLACE FUNCTION ml_notice_acknowledge(p_member bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v integer;
BEGIN
    PERFORM ml_require_person(p_member);
    SELECT notice_version INTO v FROM ml_settings WHERE id = 1;
    INSERT INTO ml_notice_acknowledgements (member_id, notice_version) VALUES (p_member, v) ON CONFLICT DO NOTHING;
    RETURN v;
END$$;

-- ================================================================================================ the mail turn (D11)
-- What arriving mail on an agent's mailbox does (one row per agent per message key, whichever folders it is in). Answers the decision:
--   wake (queued: the worker turns it into ONE chat turn — acting member the primary overseer, the turn carrying ids, never text);
--   unscreened (a person releases it, marks the sender known, or marks it spam): an internal-looking sender this application did not
--     send (until MM3), outside mail without a DMARC pass, an unknown outside sender, or no primary overseer to act;
-- THE EVIDENCE (the spike, research 04 §B): mail between the business's mailboxes carries NO filter verdict — no DMARC result ever. So
-- a colleague is proven by this application having SENT the message (its Message-ID in outbound_email, sent), or — once MaluMail
-- enforces the sender's identity at submission (MM3, mm3_identity_enforced) — by its arriving through authenticated submission on
-- MaluMail's own server (local_submission). DMARC is the test for OUTSIDE mail only.
--   skipped: not an agent's inbox, the kernel's intake, suspended, paused, wake off, the mailbox's own mail, machine mail, a muted,
--     taken-over or sensitive conversation.
CREATE OR REPLACE FUNCTION ml_dispatch_decide(p_message bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE m mail_messages%ROWTYPE; b mailboxes%ROWTYPE; p agent_mail_policies%ROWTYPE; s ml_settings%ROWTYPE; su text;
        st record; dec text; status text; authed boolean; claims_internal boolean; internal boolean; known boolean; acting bigint;
BEGIN
    SELECT * INTO m FROM mail_messages WHERE id = p_message;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such message' USING ERRCODE = 'no_data_found'; END IF;
    SELECT * INTO b FROM mailboxes WHERE id = m.mailbox_id;
    SELECT special_use INTO su FROM mail_folders WHERE id = m.folder_id;
    IF b.kind <> 'agent' OR su IS DISTINCT FROM 'inbox' THEN RETURN 'not_an_agent_inbox'; END IF;
    SELECT * INTO p FROM agent_mail_policies WHERE mailbox_id = b.id;
    SELECT * INTO s FROM ml_settings WHERE id = 1;
    SELECT * INTO st FROM ml_thread_state(m.thread_id);
    acting := b.primary_overseer_id;
    claims_internal := ml_is_internal_address(m.from_address::text);
    internal := claims_internal
                AND (EXISTS (SELECT 1 FROM outbound_email o WHERE o.message_id = m.message_id_header AND o.status = 'sent')
                     OR (s.mm3_identity_enforced AND m.local_submission));
    authed := internal OR (NOT claims_internal AND COALESCE(m.dmarc_pass, false));
    known := ml_is_known(b.id, m.from_address::text);
    status := 'skipped';
    IF b.intake_owner = 'kernel' THEN dec := 'kernel_intake';
    ELSIF b.suspended_at IS NOT NULL THEN dec := 'suspended';
    ELSIF p.paused THEN dec := 'paused';
    ELSIF NOT p.wake_on_mail OR p.wake_from = 'none' THEN dec := 'wake_off';
    ELSIF m.from_address = b.address OR EXISTS (SELECT 1 FROM mail_identities i WHERE i.mailbox_id = b.id AND i.address = m.from_address) THEN dec := 'own_mail';
    ELSIF ml_is_automated(m.auto_submitted, m.is_list, m.return_path_null) THEN dec := 'automated';
    ELSIF st.sensitive THEN dec := 'sensitive';
    ELSIF st.taken_over_by IS NOT NULL OR st.agent_muted THEN dec := 'taken_over';
    ELSIF st.muted THEN dec := 'muted';
    ELSIF claims_internal AND NOT internal THEN dec := 'internal_unproven'; status := 'unscreened';
    ELSIF NOT authed THEN dec := 'unauthenticated'; status := 'unscreened';
    ELSIF (p.wake_from = 'internal' AND internal)
       OR (p.wake_from = 'internal_and_known' AND (internal OR known))
       OR p.wake_from = 'all_authenticated' THEN
        IF acting IS NULL THEN dec := 'no_overseer'; status := 'unscreened';
        ELSE dec := 'wake'; status := 'queued'; END IF;
    ELSE dec := 'unknown_sender'; status := 'unscreened';
    END IF;
    INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, status, decision, mailbox_id, message_key_id,
                                  conversation_id, attempts, next_attempt_at)
    VALUES ('mail_message', p_message, b.owner_member_id, 'mail', 'chat', CASE WHEN status = 'queued' THEN acting END, status, dec, b.id, m.message_key_id,
            'mail:' || b.id || ':' || m.message_key_id, 0, now())
    ON CONFLICT (agent_member_id, message_key_id, kind) WHERE message_key_id IS NOT NULL DO NOTHING;
    IF status = 'unscreened' AND acting IS NOT NULL THEN
        PERFORM ml_notify(acting, 'unscreened', 'Mail for ' || b.display_name || ' waits for a person', NULL, b.id, 'mail_message', m.message_key_id);
    END IF;
    RETURN dec;
END$$;

-- Release Unscreened mail: ONE turn, the releasing person the acting member.
CREATE OR REPLACE FUNCTION ml_unscreened_release(p_dispatch bigint, p_by bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d agent_dispatches%ROWTYPE;
BEGIN
    SELECT * INTO d FROM agent_dispatches WHERE id = p_dispatch FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such mail' USING ERRCODE = 'no_data_found'; END IF;
    PERFORM ml_require_decider(d.mailbox_id, p_by);
    IF d.status <> 'unscreened' THEN RAISE EXCEPTION 'This mail is not waiting for a person' USING ERRCODE = 'check_violation'; END IF;
    UPDATE agent_dispatches SET status = 'queued', acting_member_id = p_by, released_by = p_by, released_at = now(), next_attempt_at = now()
     WHERE id = p_dispatch;
END$$;

-- Dismiss Unscreened mail (the mail service moves it to Junk when `p_spam`).
CREATE OR REPLACE FUNCTION ml_unscreened_dismiss(p_dispatch bigint, p_by bigint, p_spam boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d agent_dispatches%ROWTYPE;
BEGIN
    SELECT * INTO d FROM agent_dispatches WHERE id = p_dispatch FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such mail' USING ERRCODE = 'no_data_found'; END IF;
    PERFORM ml_require_decider(d.mailbox_id, p_by);
    UPDATE agent_dispatches SET status = 'skipped', detail = CASE WHEN p_spam THEN 'spam' ELSE 'dismissed' END WHERE id = p_dispatch AND status = 'unscreened';
END$$;

-- Mark a sender known (a person's mark: known_how = 'marked').
CREATE OR REPLACE FUNCTION ml_sender_mark_known(p_mailbox bigint, p_address text, p_by bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_decider(p_mailbox, p_by);
    INSERT INTO mail_correspondents AS c (mailbox_id, address, is_internal, known_at, known_by, known_how)
    VALUES (p_mailbox, lower(btrim(p_address)), ml_is_internal_address(p_address), now(), p_by, 'marked')
    ON CONFLICT (mailbox_id, address) DO UPDATE SET known_at = COALESCE(c.known_at, now()), known_by = COALESCE(c.known_by, p_by),
                                                   known_how = COALESCE(c.known_how, 'marked');
END$$;

-- ================================================================================================ the access log (§3)
-- Should this look be logged? Never the owner's own; a list or search view once per mailbox per half hour; everything else every time.
CREATE OR REPLACE FUNCTION ml_access_should_log(p_mailbox bigint, p_member bigint, p_action text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM mailboxes WHERE id = p_mailbox AND owner_member_id = p_member) THEN RETURN false; END IF;
    IF p_action = 'mailbox.view' THEN
        RETURN NOT EXISTS (SELECT 1 FROM activity_log a WHERE a.mailbox_id = p_mailbox AND a.actor_member_id = p_member
                              AND a.action = 'mailbox.view' AND a.occurred_at > now() - interval '30 minutes');
    END IF;
    RETURN true;
END$$;

REVOKE ALL ON FUNCTION ml_permissions_notice() FROM PUBLIC;
REVOKE ALL ON FUNCTION ml_mailbox_create(bigint, text, text, text, bigint, bigint, bigint), ml_mailbox_suspend(bigint, bigint, boolean),
    ml_mailbox_intake_set(bigint, bigint, text), ml_credential_store(bigint, bigint, text, bytea, text), ml_credential_failed(bigint, text),
    ml_permission_grant(bigint, bigint, text, bigint, text, text, timestamptz), ml_permission_revoke(bigint, bigint, text, bigint),
    ml_permissions_expire(), ml_policy_set(bigint, bigint, jsonb), ml_mailbox_pause(bigint, bigint, boolean, text),
    ml_primary_overseer_set(bigint, bigint, bigint), ml_thread_take_over(bigint, bigint, bigint, boolean), ml_thread_mute(bigint, bigint, bigint, boolean),
    ml_thread_snooze(bigint, bigint, bigint, timestamptz, bigint), ml_snoozes_due(), ml_thread_sensitive(bigint, bigint, bigint, boolean),
    ml_viewer_read(bigint, bigint, boolean), ml_notice_acknowledge(bigint), ml_dispatch_decide(bigint), ml_unscreened_release(bigint, bigint),
    ml_unscreened_dismiss(bigint, bigint, boolean), ml_sender_mark_known(bigint, text, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_mailbox_create(bigint, text, text, text, bigint, bigint, bigint), ml_mailbox_suspend(bigint, bigint, boolean),
    ml_mailbox_intake_set(bigint, bigint, text), ml_credential_store(bigint, bigint, text, bytea, text), ml_credential_failed(bigint, text),
    ml_permission_grant(bigint, bigint, text, bigint, text, text, timestamptz), ml_permission_revoke(bigint, bigint, text, bigint),
    ml_permissions_expire(), ml_policy_set(bigint, bigint, jsonb), ml_mailbox_pause(bigint, bigint, boolean, text),
    ml_primary_overseer_set(bigint, bigint, bigint), ml_thread_take_over(bigint, bigint, bigint, boolean), ml_thread_mute(bigint, bigint, bigint, boolean),
    ml_thread_snooze(bigint, bigint, bigint, timestamptz, bigint), ml_snoozes_due(), ml_thread_sensitive(bigint, bigint, bigint, boolean),
    ml_viewer_read(bigint, bigint, boolean), ml_notice_acknowledge(bigint), ml_dispatch_decide(bigint), ml_unscreened_release(bigint, bigint),
    ml_unscreened_dismiss(bigint, bigint, boolean), ml_sender_mark_known(bigint, text, bigint)
TO malumail_rw;
GRANT EXECUTE ON FUNCTION ml_mail_admin_ids(), ml_require_right(bigint, text), ml_credential_usable(bigint), ml_require_decider(bigint, bigint),
    ml_access_should_log(bigint, bigint, text)
TO malumail_rw, malumail_records_ro;

COMMIT;
