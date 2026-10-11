-- 015: SENDING AND OVERSIGHT, Phase 1's schema for docs/build-specs/sending-and-oversight.md, writing.md (slice 4), oversight.md (slice 5)
-- and notifications.md (slice 9). Additive; applies on top of db/001–012 (db/012 widened agent_dispatches.kind with 'review' — used
-- here, not repeated; the Clerk's note keeps the existing notice kind `digest`).
--
--   * outbound_email.filed_at (the Sent copy appended) and thread_anchor_key (the conversation a message answers, for the cap);
--     mail_reviews.before_subject / before_body_text (an edit's diff); mail_drafts.filed_at (its Drafts copy appended);
--     notifications.item_count (new mail coalesced, appended to the Spaces family's table).
--   * THE CRASH RULE: a row lost in `sending` is never re-queued — the status machine drops `sending → queued`; the mail service lists
--     stalled rows (ml_outbound_stalled), searches Sent for each by its Message-ID, and calls ml_outbound_sent() or ml_outbound_lost().
--   * THE KILL SWITCH reaches deferred rows: `deferred → held` admitted; the claim and the pause re-hold deferred rows too; RESUME
--     re-queues the rows a person had approved before the pause held them, removes `paused` from unreviewed rows held for more, and
--     leaves an unreviewed row held for `paused` alone waiting for a person.
--   * THE AGENT IS TOLD: a reject, a reply, a take-over and an expiry each make one `review` dispatch (the worker's one chat turn, the
--     reviewer — or for an expiry the mailbox's acting person — the acting member); the reviewers learn an expiry (`review_decided`).
--   * A TAKE-OVER from the conversation cancels the agent's held replies in it, each with its own `take_over` review and dispatch.
--   * NOTICES: held mail tells exactly its reviewers (a trigger), never for a pause's re-hold; ml_notify() also writes the TEXT row (K6) for
--     a person who opted in; ml_notify_coalesce() keeps one unread new-mail notice per mailbox per half hour.
--   * WAKE-UPS: pg_notify('ml_outbox') when a row becomes queued, pg_notify('ml_drafts') when a draft is saved or discarded.
--   * ml_outbound_retry(), ml_agent_mail_summary() (the cards' and the two summary tools' one gatherer).
BEGIN;

-- ------------------------------------------------------------------------------------------------- columns
ALTER TABLE outbound_email
    ADD COLUMN filed_at          timestamptz,                                                      -- the copy APPENDed to Sent
    ADD COLUMN thread_anchor_key bigint REFERENCES mail_message_keys(id) ON DELETE SET NULL;       -- the answered conversation's anchor
CREATE INDEX outbound_email_thread_idx ON outbound_email (mailbox_id, thread_anchor_key, created_at)
    WHERE sender_is_agent AND thread_anchor_key IS NOT NULL;
CREATE INDEX outbound_email_sending_idx ON outbound_email (updated_at) WHERE status = 'sending';
CREATE INDEX outbound_email_unfiled_idx ON outbound_email (sent_at) WHERE status = 'sent' AND filed_at IS NULL;

ALTER TABLE mail_reviews
    ADD COLUMN before_subject   text,
    ADD COLUMN before_body_text text,
    ADD CONSTRAINT mail_reviews_edit_before CHECK (decision <> 'edit' OR before_body_text IS NOT NULL);

ALTER TABLE mail_drafts ADD COLUMN filed_at timestamptz;                                           -- the version of saved_at in Drafts

ALTER TABLE notifications ADD COLUMN item_count integer CHECK (item_count IS NULL OR item_count > 0);   -- new mail coalesced
CREATE INDEX notifications_coalesce_idx ON notifications (member_id, mailbox_id, created_at DESC) WHERE kind = 'new_mail' AND read_at IS NULL;

-- ------------------------------------------------------------------------------------------------- helpers
-- A conversation's anchor: the lowest message key of the thread the key is in (the key itself when the thread is unknown). Stable for a
-- day's cap; never a key of the application's own state (that is mail_thread_states').
CREATE OR REPLACE FUNCTION ml_thread_anchor(p_key bigint) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE WHEN p_key IS NULL THEN NULL
                ELSE COALESCE((SELECT min(m.message_key_id) FROM mail_messages m WHERE m.thread_id = ml_key_thread(p_key)), p_key) END;
$$;

-- The people who decide a message's fate: active people with full on its mailbox — on a person's mailbox, its owner alone (ml_is_decider).
CREATE OR REPLACE FUNCTION ml_outbound_reviewers(p_outbound bigint) RETURNS SETOF bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT m.id FROM outbound_email o, members m
     WHERE o.id = p_outbound AND m.member_kind = 'human' AND m.status = 'active' AND ml_is_decider(o.mailbox_id, m.id)
     ORDER BY m.id;
$$;

-- One `review` dispatch: the worker turns it into ONE chat turn of the writing agent — a person's words and ids, never mail text.
CREATE OR REPLACE FUNCTION ml_review_dispatch(p_outbound bigint, p_decision text, p_acting bigint, p_note text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o outbound_email%ROWTYPE; did bigint;
BEGIN
    SELECT * INTO o FROM outbound_email WHERE id = p_outbound;
    IF NOT FOUND OR NOT o.sender_is_agent OR o.sender_member_id IS NULL THEN RETURN NULL; END IF;
    INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, status, decision, detail, mailbox_id,
                                  conversation_id, attempts, next_attempt_at)
    VALUES ('outbound', p_outbound, o.sender_member_id, 'review', 'chat', p_acting,
            CASE WHEN p_acting IS NULL THEN 'skipped' ELSE 'queued' END, p_decision,
            CASE WHEN p_acting IS NULL THEN 'no person to act for the turn' ELSE left(p_note, 1000) END, o.mailbox_id,
            'mail:' || o.mailbox_id || ':' || COALESCE(o.thread_anchor_key::text, 'out' || o.id), 0, now())
    RETURNING id INTO did;
    RETURN did;
END$$;

-- The acting person for a turn no reviewer started: an agent mailbox's primary overseer; a person's mailbox's owner.
CREATE OR REPLACE FUNCTION ml_mailbox_acting_person(p_mailbox bigint) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE b.kind WHEN 'agent' THEN b.primary_overseer_id WHEN 'person' THEN b.owner_member_id END FROM mailboxes b WHERE b.id = p_mailbox;
$$;

-- ------------------------------------------------------------------------------------------------- the status machine (crash rule, kill switch)
-- held → queued (a person's approve or edit) | rejected | expired | cancelled
-- queued → sending | cancelled (only while send_after is ahead) | held
-- sending → sent | deferred | failed          (NEVER back to queued: a row lost mid-send may have gone — ml_outbound_lost)
-- deferred → sending | failed | cancelled | held (the kill switch)
-- failed → queued (ml_outbound_retry) ; sent, suppressed, rejected, cancelled, expired, refused are final.
CREATE OR REPLACE FUNCTION ml_outbound_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d text;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status NOT IN ('held', 'queued', 'refused', 'suppressed') THEN
            RAISE EXCEPTION 'A new message is held, queued, refused or suppressed' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.status = OLD.status THEN
        IF OLD.status IN ('sent', 'suppressed', 'rejected', 'cancelled', 'expired', 'refused')
           AND (NEW.body_text, NEW.body_html, NEW.subject, NEW.to_addresses, NEW.cc_addresses, NEW.bcc_addresses)
               IS DISTINCT FROM (OLD.body_text, OLD.body_html, OLD.subject, OLD.to_addresses, OLD.cc_addresses, OLD.bcc_addresses) THEN
            RAISE EXCEPTION 'A % message is never changed', OLD.status USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status = 'sending' AND NEW.status = 'queued' THEN
        RAISE EXCEPTION 'A message lost while sending is never sent again by itself: it may have gone (ml_outbound_lost)' USING ERRCODE = 'check_violation';
    END IF;
    IF NOT (
        (OLD.status = 'held' AND NEW.status IN ('queued', 'rejected', 'expired', 'cancelled')) OR
        (OLD.status = 'queued' AND NEW.status IN ('sending', 'cancelled', 'held')) OR
        (OLD.status = 'sending' AND NEW.status IN ('sent', 'deferred', 'failed')) OR
        (OLD.status = 'deferred' AND NEW.status IN ('sending', 'failed', 'cancelled', 'held')) OR
        (OLD.status = 'failed' AND NEW.status = 'queued')
    ) THEN
        RAISE EXCEPTION 'A % message cannot become %', OLD.status, NEW.status USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.status = 'queued' AND NEW.status = 'cancelled' AND OLD.send_after <= now() THEN
        RAISE EXCEPTION 'Too late to undo: the message is on its way' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.status = 'held' AND NEW.status = 'queued' THEN
        SELECT decision INTO d FROM mail_reviews WHERE id = NEW.review_id AND outbound_id = NEW.id;
        IF d IS DISTINCT FROM 'approve' AND d IS DISTINCT FROM 'edit' THEN
            RAISE EXCEPTION 'Held mail leaves only by a person''s approval' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    IF NEW.status IN ('sent', 'rejected', 'expired', 'cancelled', 'failed') THEN NEW.decided_at := COALESCE(NEW.decided_at, now()); END IF;
    IF NEW.status = 'held' THEN NEW.held_at := now(); END IF;
    RETURN NEW;
END$$;

-- Rows claimed and never answered: the mail service's start lists them, searches Sent for each by its Message-ID, then sent or lost.
CREATE OR REPLACE FUNCTION ml_outbound_stalled(p_minutes integer DEFAULT 10) RETURNS SETOF bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT id FROM outbound_email WHERE status = 'sending' AND updated_at < now() - make_interval(mins => GREATEST(p_minutes, 1)) ORDER BY id;
$$;

CREATE OR REPLACE FUNCTION ml_outbound_lost(p_outbound bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE outbound_email SET status = 'failed',
           error = 'The mail service stopped while sending this message; it may have gone. Check with the recipient before you retry.'
     WHERE id = p_outbound AND status = 'sending';
    IF NOT FOUND THEN RAISE EXCEPTION 'Only a message being sent is lost' USING ERRCODE = 'check_violation'; END IF;
END$$;

-- ------------------------------------------------------------------------------------------------- the claim: the kill switch reaches deferred rows
CREATE OR REPLACE FUNCTION ml_outbound_claim(p_limit integer DEFAULT 10) RETURNS SETOF bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s ml_settings%ROWTYPE;
BEGIN
    SELECT * INTO s FROM ml_settings WHERE id = 1;
    UPDATE outbound_email o SET status = 'held', hold_reasons = (SELECT array_agg(DISTINCT x) FROM unnest(o.hold_reasons || 'paused'::text) x)
     WHERE o.status IN ('queued', 'deferred') AND o.sender_is_agent
       AND EXISTS (SELECT 1 FROM agent_mail_policies p WHERE p.mailbox_id = o.mailbox_id AND p.paused);
    UPDATE outbound_email o SET status = 'held',
           hold_reasons = (SELECT array_agg(DISTINCT x) FROM unnest(o.hold_reasons || CASE WHEN s.hold_all_agents THEN 'hold_all_agents' ELSE 'hold_all_external' END) x)
     WHERE o.status IN ('queued', 'deferred') AND o.sender_is_agent AND o.review_id IS NULL AND (s.hold_all_agents OR (s.hold_all_external AND o.external));
    RETURN QUERY
    UPDATE outbound_email SET status = 'sending', attempts = attempts + 1
     WHERE id IN (SELECT id FROM outbound_email
                   WHERE status IN ('queued', 'deferred') AND send_after <= now() AND (retry_at IS NULL OR retry_at <= now())
                   ORDER BY send_after, id FOR UPDATE SKIP LOCKED LIMIT GREATEST(p_limit, 1))
    RETURNING id;
END$$;

-- ------------------------------------------------------------------------------------------------- pause and resume
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
         WHERE mailbox_id = p_mailbox AND status IN ('queued', 'deferred') AND sender_is_agent;
    ELSE
        -- a person had approved it and the pause held it back: it goes (its earlier reasons were decided by that approval)
        UPDATE outbound_email o SET status = 'queued', hold_reasons = array_remove(o.hold_reasons, 'paused'), send_after = GREATEST(o.send_after, now())
         WHERE o.mailbox_id = p_mailbox AND o.status = 'held' AND o.sender_is_agent AND 'paused' = ANY (o.hold_reasons)
           AND EXISTS (SELECT 1 FROM mail_reviews r WHERE r.id = o.review_id AND r.decision IN ('approve', 'edit'));
        -- held for more than the pause: the pause is no longer a reason; a person still decides
        UPDATE outbound_email o SET hold_reasons = array_remove(o.hold_reasons, 'paused')
         WHERE o.mailbox_id = p_mailbox AND o.status = 'held' AND o.sender_is_agent AND 'paused' = ANY (o.hold_reasons)
           AND cardinality(o.hold_reasons) > 1;
        -- held for the pause alone and never reviewed: it waits for a person, still saying why it was held
    END IF;
END$$;

-- ------------------------------------------------------------------------------------------------- the policy's conversation cap by anchor
CREATE OR REPLACE FUNCTION ml_outbound_hold_reasons(p_mailbox bigint, p_sender bigint, p_to text[], p_cc text[], p_bcc text[],
                                                    p_has_attachments boolean, p_reply_to_key bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE b mailboxes%ROWTYPE; p agent_mail_policies%ROWTYPE; s ml_settings%ROWTYPE; r text[] := '{}'; recips text[]; ext boolean; anchor bigint; n integer;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM members WHERE id = p_sender AND member_kind = 'agent') THEN RETURN '{}'; END IF;
    SELECT * INTO b FROM mailboxes WHERE id = p_mailbox;
    SELECT * INTO s FROM ml_settings WHERE id = 1;
    recips := ml_recipients(p_to, p_cc, p_bcc);
    ext := EXISTS (SELECT 1 FROM unnest(recips) x WHERE NOT ml_is_internal_address(x));
    IF b.kind = 'person' THEN
        r := r || 'on_behalf'::text;
    ELSIF b.kind = 'shared' THEN
        r := r || 'mode'::text;
    ELSE
        SELECT * INTO p FROM agent_mail_policies WHERE mailbox_id = p_mailbox;
        IF p.paused THEN r := r || 'paused'::text; END IF;
        IF p.send_mode = 'hold_all'
           OR (p.send_mode = 'internal_auto' AND ext)
           OR (p.send_mode = 'allowlist_auto' AND EXISTS (SELECT 1 FROM unnest(recips) x
                                                           WHERE NOT ml_is_internal_address(x)
                                                             AND NOT (x::citext = ANY (p.allow_addresses))
                                                             AND NOT (ml_address_domain(x) = ANY (p.allow_domains)))) THEN
            r := r || 'mode'::text;
        END IF;
        IF p.first_contact_review AND EXISTS (SELECT 1 FROM unnest(recips) x WHERE NOT ml_is_known(p_mailbox, x)) THEN r := r || 'first_contact'::text; END IF;
        IF p.attachments_review AND COALESCE(p_has_attachments, false) THEN r := r || 'attachment'::text; END IF;
        SELECT count(*) INTO n FROM outbound_email o
         WHERE o.mailbox_id = p_mailbox AND o.sender_is_agent AND o.status IN ('queued', 'sending', 'sent', 'deferred') AND o.created_at > now() - interval '1 hour';
        IF n >= p.max_per_hour THEN r := r || 'over_hour'::text; END IF;
        IF ext THEN
            SELECT count(*) INTO n FROM outbound_email o
             WHERE o.mailbox_id = p_mailbox AND o.sender_is_agent AND o.external AND o.status IN ('queued', 'sending', 'sent', 'deferred')
               AND o.created_at > now() - interval '1 day';
            IF n >= p.max_external_per_day THEN r := r || 'over_day'::text; END IF;
        END IF;
        anchor := ml_thread_anchor(p_reply_to_key);
        IF anchor IS NOT NULL THEN
            SELECT count(*) INTO n FROM outbound_email o
             WHERE o.mailbox_id = p_mailbox AND o.sender_is_agent AND o.thread_anchor_key = anchor
               AND o.status IN ('queued', 'sending', 'sent', 'deferred') AND o.created_at > now() - interval '1 day';
            IF n >= p.max_per_thread_per_day THEN r := r || 'over_thread'::text; END IF;
        END IF;
    END IF;
    IF ext THEN
        SELECT count(*) INTO n FROM outbound_email o
         WHERE o.sender_is_agent AND o.external AND o.status IN ('queued', 'sending', 'sent', 'deferred') AND o.created_at > now() - interval '1 day';
        IF n >= ml_agent_external_budget() THEN r := r || 'over_business_budget'::text; END IF;
    END IF;
    IF s.hold_all_agents THEN r := r || 'hold_all_agents'::text; END IF;
    IF s.hold_all_external AND ext THEN r := r || 'hold_all_external'::text; END IF;
    RETURN r;
END$$;

-- Send: db/009's function, now storing the conversation's anchor (the cap and the dispatch's conversation key).
CREATE OR REPLACE FUNCTION ml_outbound_submit(p_mailbox bigint, p_sender bigint, p_identity bigint, p_to text[], p_cc text[], p_bcc text[],
                                              p_subject text, p_body_text text, p_body_html text, p_reply_to_key bigint, p_draft bigint,
                                              p_purpose text, p_has_attachments boolean, p_send_at timestamptz DEFAULT NULL,
                                              p_agent_run bigint DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b mailboxes%ROWTYPE; m members%ROWTYPE; s ml_settings%ROWTYPE; pol agent_mail_policies%ROWTYPE; recips text[]; reasons text[];
        refusal text; digest text; mid text; irt text; refs text[]; first text; oid bigint; ident bigint; undo integer; footer text; ext boolean;
BEGIN
    SELECT * INTO b FROM mailboxes WHERE id = p_mailbox;
    SELECT * INTO m FROM members WHERE id = p_sender;
    SELECT * INTO s FROM ml_settings WHERE id = 1;
    IF NOT FOUND OR b.id IS NULL THEN RAISE EXCEPTION 'No such mailbox' USING ERRCODE = 'no_data_found'; END IF;
    IF ml_level_rank(ml_level_as(p_mailbox, p_sender, NULLIF(current_setting('app.on_behalf_of', true), '')::bigint)) < 3 THEN
        RAISE EXCEPTION 'Sending from this mailbox needs send on it' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF b.suspended_at IS NOT NULL THEN RAISE EXCEPTION 'This mailbox is suspended' USING ERRCODE = 'check_violation'; END IF;
    recips := ml_recipients(p_to, p_cc, p_bcc);
    IF cardinality(recips) = 0 THEN RAISE EXCEPTION 'A message needs a recipient' USING ERRCODE = 'invalid_parameter_value'; END IF;
    IF cardinality(recips) > 100 THEN RAISE EXCEPTION 'At most 100 recipients a message' USING ERRCODE = 'invalid_parameter_value'; END IF;
    IF EXISTS (SELECT 1 FROM unnest(recips) x WHERE x !~ '^[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+$') THEN
        RAISE EXCEPTION 'Every recipient is an address' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF m.member_kind = 'agent' AND (p_purpose IS NULL OR length(btrim(p_purpose)) < 3) THEN
        RAISE EXCEPTION 'A message an agent sends says what it is for (purpose)' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    ident := COALESCE(p_identity, (SELECT id FROM mail_identities WHERE mailbox_id = p_mailbox AND is_default));
    IF ident IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mail_identities WHERE id = ident AND mailbox_id = p_mailbox) THEN
        RAISE EXCEPTION 'An identity of another mailbox' USING ERRCODE = 'check_violation';
    END IF;
    IF p_reply_to_key IS NOT NULL THEN
        IF NOT EXISTS (SELECT 1 FROM mail_message_keys WHERE id = p_reply_to_key AND mailbox_id = p_mailbox) THEN
            RAISE EXCEPTION 'A reply answers a message of the same mailbox' USING ERRCODE = 'check_violation';
        END IF;
        SELECT mm.message_id_header, (mm.references_ids || CASE WHEN mm.message_id_header IS NULL THEN '{}'::text[] ELSE ARRAY[mm.message_id_header] END)
          INTO irt, refs FROM mail_messages mm WHERE mm.message_key_id = p_reply_to_key ORDER BY mm.id LIMIT 1;
    END IF;
    first := COALESCE(p_to[1], p_cc[1], p_bcc[1]);
    digest := ml_body_digest(p_body_text);
    mid := '<' || replace(gen_random_uuid()::text, '-', '') || '.malumail@' || ml_address_domain(b.address) || '>';
    ext := EXISTS (SELECT 1 FROM unnest(recips) x WHERE NOT ml_is_internal_address(x));
    refusal := ml_outbound_refusal(p_sender, p_reply_to_key, recips, digest);
    reasons := CASE WHEN refusal IS NULL THEN ml_outbound_hold_reasons(p_mailbox, p_sender, p_to, p_cc, p_bcc, p_has_attachments, p_reply_to_key) ELSE '{}' END;
    IF m.member_kind = 'agent' THEN
        SELECT * INTO pol FROM agent_mail_policies WHERE mailbox_id = p_mailbox;
        IF COALESCE(pol.disclosure, s.default_disclosure) THEN
            footer := replace(replace(COALESCE(pol.disclosure_text, s.disclosure_text), '{agent}', m.display_name),
                              '{business}', COALESCE(s.business_name, 'the business'));
        END IF;
        undo := 0;
    ELSE
        undo := COALESCE(NULLIF(b.settings->>'undo_seconds', '')::integer, s.undo_send_seconds);
    END IF;
    INSERT INTO outbound_email (to_address, subject, status, sender_member_id, cc_addresses, body_text, body_html, message_id, in_reply_to,
                                references_ids, mailbox_id, identity_id, on_behalf_member_id, to_addresses, bcc_addresses, reply_to_key_id,
                                send_after, draft_id, purpose, hold_reasons, refusal, body_digest, sender_is_agent, external, has_attachments,
                                disclosure, auto_submitted, agent_run_id, error, thread_anchor_key)
    VALUES (first, COALESCE(p_subject, ''),
            CASE WHEN refusal IS NOT NULL THEN 'refused' WHEN cardinality(reasons) > 0 THEN 'held' ELSE 'queued' END,
            p_sender, COALESCE(p_cc, '{}'), COALESCE(p_body_text, ''), p_body_html, mid, irt, COALESCE(refs, '{}'), p_mailbox, ident,
            CASE WHEN b.owner_member_id IS DISTINCT FROM p_sender THEN p_sender END, COALESCE(p_to, '{}'), COALESCE(p_bcc, '{}'), p_reply_to_key,
            COALESCE(p_send_at, now() + make_interval(secs => undo)), p_draft, NULLIF(btrim(p_purpose), ''), reasons, refusal, digest,
            m.member_kind = 'agent', ext, COALESCE(p_has_attachments, false), footer,
            CASE WHEN m.member_kind = 'agent' THEN CASE WHEN p_reply_to_key IS NULL THEN 'auto-generated' ELSE 'auto-replied' END END,
            p_agent_run, ml_refusal_words(refusal), ml_thread_anchor(p_reply_to_key))
    RETURNING id INTO oid;
    IF cardinality(reasons) > 0 THEN UPDATE outbound_email SET held_at = now() WHERE id = oid; END IF;
    IF p_draft IS NOT NULL THEN UPDATE mail_drafts SET outbound_id = oid WHERE id = p_draft AND mailbox_id = p_mailbox; END IF;
    RETURN oid;
END$$;

-- ------------------------------------------------------------------------------------------------- review: the edit's before-text, the agent told
CREATE OR REPLACE FUNCTION ml_review_decide(p_outbound bigint, p_reviewer bigint, p_decision text, p_note text DEFAULT NULL,
                                            p_subject text DEFAULT NULL, p_body_text text DEFAULT NULL, p_body_html text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o outbound_email%ROWTYPE; rid bigint; before_d text; after_d text; undo integer;
BEGIN
    PERFORM ml_require_person(p_reviewer);
    IF p_decision NOT IN ('approve', 'edit', 'reject', 'reply', 'take_over') THEN
        RAISE EXCEPTION 'A review approves, edits, rejects, replies or takes over' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    SELECT * INTO o FROM outbound_email WHERE id = p_outbound FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such message' USING ERRCODE = 'no_data_found'; END IF;
    IF o.status <> 'held' THEN RAISE EXCEPTION 'Only held mail is reviewed: this message is %', o.status USING ERRCODE = 'check_violation'; END IF;
    IF NOT ml_outbound_reviewer(p_outbound, p_reviewer) THEN
        RAISE EXCEPTION 'You do not review this mailbox''s mail' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF p_decision = 'take_over' AND o.reply_to_key_id IS NULL THEN
        RAISE EXCEPTION 'Only a reply belongs to a conversation to take over: reject it instead' USING ERRCODE = 'check_violation';
    END IF;
    before_d := ml_body_digest(o.subject || chr(30) || o.body_text);
    IF p_decision = 'edit' THEN
        IF p_body_text IS NULL THEN RAISE EXCEPTION 'An edit gives the new text' USING ERRCODE = 'invalid_parameter_value'; END IF;
        after_d := ml_body_digest(COALESCE(p_subject, o.subject) || chr(30) || p_body_text);
    END IF;
    INSERT INTO mail_reviews (outbound_id, decision, reviewer_member_id, note, before_digest, after_digest, before_subject, before_body_text)
    VALUES (p_outbound, p_decision, p_reviewer, NULLIF(btrim(p_note), ''), CASE WHEN p_decision = 'edit' THEN before_d END, after_d,
            CASE WHEN p_decision = 'edit' THEN o.subject END, CASE WHEN p_decision = 'edit' THEN o.body_text END)
    RETURNING id INTO rid;
    undo := COALESCE((SELECT undo_send_seconds FROM ml_settings WHERE id = 1), 10);
    IF p_decision IN ('approve', 'edit') THEN
        UPDATE outbound_email SET status = 'queued', review_id = rid, auto_submitted = NULL, send_after = now() + make_interval(secs => undo),
               subject = CASE WHEN p_decision = 'edit' THEN COALESCE(p_subject, subject) ELSE subject END,
               body_text = CASE WHEN p_decision = 'edit' THEN p_body_text ELSE body_text END,
               body_html = CASE WHEN p_decision = 'edit' THEN p_body_html ELSE body_html END,
               body_digest = CASE WHEN p_decision = 'edit' THEN ml_body_digest(p_body_text) ELSE body_digest END
         WHERE id = p_outbound;
    ELSIF p_decision = 'reject' THEN
        UPDATE outbound_email SET status = 'rejected', review_id = rid WHERE id = p_outbound;
        PERFORM ml_review_dispatch(p_outbound, 'reject', p_reviewer, p_note);
    ELSIF p_decision = 'reply' THEN
        UPDATE outbound_email SET review_id = rid WHERE id = p_outbound;
        PERFORM ml_review_dispatch(p_outbound, 'reply', p_reviewer, p_note);
    ELSE
        UPDATE outbound_email SET status = 'cancelled', review_id = rid WHERE id = p_outbound;
        INSERT INTO mail_thread_states AS t (mailbox_id, anchor_key_id, taken_over_by, taken_over_at, agent_muted)
        VALUES (o.mailbox_id, o.reply_to_key_id, p_reviewer, now(), true)
        ON CONFLICT (mailbox_id, anchor_key_id) DO UPDATE SET taken_over_by = EXCLUDED.taken_over_by, taken_over_at = now(), agent_muted = true;
        PERFORM ml_review_dispatch(p_outbound, 'take_over', p_reviewer, NULL);
    END IF;
    RETURN rid;
END$$;

-- Expiry: the agent told (acting: the mailbox's person), its reviewers told it expired undecided.
CREATE OR REPLACE FUNCTION ml_held_expire() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; n integer := 0; rid bigint; who bigint; agent_name text;
BEGIN
    FOR r IN SELECT o.id, o.mailbox_id, o.sender_member_id FROM outbound_email o, ml_settings s
              WHERE s.id = 1 AND o.status = 'held' AND o.held_at < now() - make_interval(hours => s.held_expiry_hours)
              FOR UPDATE OF o SKIP LOCKED LOOP
        FOR who IN SELECT ml_outbound_reviewers(r.id) LOOP
            SELECT display_name INTO agent_name FROM members WHERE id = r.sender_member_id;
            PERFORM ml_notify(who, 'review_decided', COALESCE(agent_name, 'An agent') || '''s message expired with no decision', NULL,
                              r.mailbox_id, 'outbound', r.id);
        END LOOP;
        INSERT INTO mail_reviews (outbound_id, decision) VALUES (r.id, 'expire') RETURNING id INTO rid;
        UPDATE outbound_email SET status = 'expired', review_id = rid WHERE id = r.id;
        PERFORM ml_review_dispatch(r.id, 'expire', ml_mailbox_acting_person(r.mailbox_id), NULL);
        n := n + 1;
    END LOOP;
    RETURN n;
END$$;

-- Take over from the conversation: the agent's held replies in it are withdrawn, each recorded and the agent told.
CREATE OR REPLACE FUNCTION ml_thread_take_over(p_mailbox bigint, p_key bigint, p_by bigint, p_on boolean DEFAULT true) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE thr bigint; r record; rid bigint;
BEGIN
    PERFORM ml_require_decider(p_mailbox, p_by);
    INSERT INTO mail_thread_states AS t (mailbox_id, anchor_key_id, taken_over_by, taken_over_at, agent_muted)
    VALUES (p_mailbox, p_key, CASE WHEN p_on THEN p_by END, CASE WHEN p_on THEN now() END, p_on)
    ON CONFLICT (mailbox_id, anchor_key_id) DO UPDATE SET taken_over_by = EXCLUDED.taken_over_by, taken_over_at = EXCLUDED.taken_over_at,
                                                         agent_muted = EXCLUDED.agent_muted;
    thr := ml_key_thread(p_key);
    IF NOT p_on THEN
        UPDATE mail_thread_states SET taken_over_by = NULL, taken_over_at = NULL, agent_muted = false
         WHERE mailbox_id = p_mailbox AND anchor_key_id IN (SELECT m.message_key_id FROM mail_messages m WHERE m.thread_id = thr);
        RETURN;
    END IF;
    FOR r IN SELECT o.id FROM outbound_email o
              WHERE o.mailbox_id = p_mailbox AND o.status = 'held' AND o.sender_is_agent AND o.reply_to_key_id IS NOT NULL
                AND (o.reply_to_key_id = p_key OR (thr IS NOT NULL AND ml_key_thread(o.reply_to_key_id) = thr))
              FOR UPDATE OF o LOOP
        INSERT INTO mail_reviews (outbound_id, decision, reviewer_member_id) VALUES (r.id, 'take_over', p_by) RETURNING id INTO rid;
        UPDATE outbound_email SET status = 'cancelled', review_id = rid WHERE id = r.id;
        PERFORM ml_review_dispatch(r.id, 'take_over', p_by, NULL);
    END LOOP;
END$$;

-- Retry a failed message: its writer, or a member who may send from the mailbox. A fresh deferral budget; the claim re-holds it if paused.
CREATE OR REPLACE FUNCTION ml_outbound_retry(p_outbound bigint, p_member bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o outbound_email%ROWTYPE;
BEGIN
    SELECT * INTO o FROM outbound_email WHERE id = p_outbound FOR UPDATE;
    IF NOT FOUND OR ml_level_rank(ml_level_as(o.mailbox_id, p_member, NULLIF(current_setting('app.on_behalf_of', true), '')::bigint)) < 1 THEN
        RAISE EXCEPTION 'No such message' USING ERRCODE = 'no_data_found';
    END IF;
    IF o.sender_member_id IS DISTINCT FROM p_member
       AND ml_level_rank(ml_level_as(o.mailbox_id, p_member, NULLIF(current_setting('app.on_behalf_of', true), '')::bigint)) < 3 THEN
        RAISE EXCEPTION 'Retrying a message needs send on its mailbox' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF o.status <> 'failed' THEN RAISE EXCEPTION 'Only a failed message is retried: this one is %', o.status USING ERRCODE = 'check_violation'; END IF;
    UPDATE outbound_email SET status = 'queued', attempts = 0, retry_at = NULL, error = NULL, send_after = now() WHERE id = p_outbound;
END$$;

-- ------------------------------------------------------------------------------------------------- notices
-- ml_notify (db/007) also writes the TEXT row (K6) when the person opted in and the kind is one of their text kinds.
CREATE OR REPLACE FUNCTION ml_notify(p_member bigint, p_kind text, p_title text, p_body text, p_mailbox bigint,
                                     p_record_type text DEFAULT NULL, p_record_id bigint DEFAULT NULL, p_actor bigint DEFAULT NULL,
                                     p_force_email boolean DEFAULT false) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE nid bigint; m members%ROWTYPE; pr notification_prefs%ROWTYPE;
BEGIN
    SELECT * INTO m FROM members WHERE id = p_member;
    IF NOT FOUND OR m.status <> 'active' OR m.member_kind <> 'human' THEN RETURN NULL; END IF;
    INSERT INTO notifications (member_id, kind, title, body, mailbox_id, record_type, record_id, actor_member_id)
    VALUES (p_member, p_kind, p_title, p_body, p_mailbox, p_record_type, p_record_id, p_actor) RETURNING id INTO nid;
    SELECT * INTO pr FROM notification_prefs WHERE member_id = p_member;
    IF m.email IS NOT NULL AND (p_force_email OR (COALESCE(pr.email_enabled, true) AND p_kind = ANY (COALESCE(pr.kinds, '{held_mail,review_decided,unscreened,break_glass,permission_granted,credential_failed,sync_failed,send_failed}'::text[])))) THEN
        INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('email', p_member, m.email, p_kind, p_record_type, p_record_id, 'n' || nid, p_title, COALESCE(p_body, p_title));
    END IF;
    IF COALESCE(pr.text_enabled, false) AND p_kind = ANY (COALESCE(pr.text_kinds, '{}'::text[])) THEN
        INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, body)
        VALUES ('text', p_member, p_kind, p_record_type, p_record_id, 't' || nid, p_title);
    END IF;
    RETURN nid;
END$$;

-- New mail, coalesced: one unread notice per person per mailbox per half hour, its count raised; nothing for a mailbox they cannot open.
CREATE OR REPLACE FUNCTION ml_notify_coalesce(p_member bigint, p_mailbox bigint, p_count integer) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE nid bigint; total integer; addr text;
BEGIN
    IF COALESCE(p_count, 0) < 1 OR ml_level_rank(ml_mailbox_level(p_mailbox, p_member)) < 1 THEN RETURN NULL; END IF;
    SELECT address::text INTO addr FROM mailboxes WHERE id = p_mailbox;
    SELECT id, COALESCE(item_count, 1) INTO nid, total FROM notifications
     WHERE member_id = p_member AND mailbox_id = p_mailbox AND kind = 'new_mail' AND read_at IS NULL AND created_at > now() - interval '30 minutes'
     ORDER BY created_at DESC LIMIT 1 FOR UPDATE;
    IF nid IS NOT NULL THEN
        total := total + p_count;
        UPDATE notifications SET item_count = total, title = total || ' new messages in ' || addr WHERE id = nid;
        RETURN nid;
    END IF;
    nid := ml_notify(p_member, 'new_mail', p_count || CASE WHEN p_count = 1 THEN ' new message in ' ELSE ' new messages in ' END || addr,
                     NULL, p_mailbox, 'mailbox', p_mailbox);
    IF nid IS NOT NULL THEN UPDATE notifications SET item_count = p_count WHERE id = nid; END IF;
    RETURN nid;
END$$;

-- Held mail tells exactly its reviewers — never for a pause's re-hold (the overseer who paused it knows), never twice while unread.
CREATE OR REPLACE FUNCTION ml_outbound_held_notice() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE added text[]; who bigint; agent_name text;
BEGIN
    added := ARRAY(SELECT x FROM unnest(NEW.hold_reasons) x
                    WHERE TG_OP = 'INSERT' OR NOT (x = ANY (COALESCE(OLD.hold_reasons, '{}'::text[]))));
    IF NOT EXISTS (SELECT 1 FROM unnest(added) x WHERE x <> 'paused') THEN RETURN NULL; END IF;
    SELECT display_name INTO agent_name FROM members WHERE id = NEW.sender_member_id;
    FOR who IN SELECT ml_outbound_reviewers(NEW.id) LOOP
        IF NOT EXISTS (SELECT 1 FROM notifications n WHERE n.member_id = who AND n.kind = 'held_mail' AND n.record_type = 'outbound'
                          AND n.record_id = NEW.id AND n.read_at IS NULL) THEN
            PERFORM ml_notify(who, 'held_mail', 'Mail from ' || COALESCE(agent_name, 'an agent') || ' waits for your review ('
                              || array_to_string(ARRAY(SELECT replace(x, '_', ' ') FROM unnest(NEW.hold_reasons) x), ', ') || ')',
                              NULL, NEW.mailbox_id, 'outbound', NEW.id);
        END IF;
    END LOOP;
    RETURN NULL;
END$$;
CREATE TRIGGER outbound_email_held_notice AFTER INSERT OR UPDATE OF status ON outbound_email
    FOR EACH ROW WHEN (NEW.status = 'held') EXECUTE FUNCTION ml_outbound_held_notice();

-- ------------------------------------------------------------------------------------------------- wake-ups for the mail service
CREATE OR REPLACE FUNCTION ml_outbox_wake() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_notify('ml_outbox', NEW.id::text);
    RETURN NULL;
END$$;
CREATE TRIGGER outbound_email_wake AFTER INSERT OR UPDATE OF status ON outbound_email
    FOR EACH ROW WHEN (NEW.status = 'queued') EXECUTE FUNCTION ml_outbox_wake();

CREATE OR REPLACE FUNCTION ml_drafts_wake() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM pg_notify('ml_drafts', NEW.id::text);
    RETURN NULL;
END$$;
CREATE TRIGGER mail_drafts_wake_insert AFTER INSERT ON mail_drafts FOR EACH ROW EXECUTE FUNCTION ml_drafts_wake();
CREATE TRIGGER mail_drafts_wake_update AFTER UPDATE OF saved_at, discarded_at ON mail_drafts
    FOR EACH ROW WHEN (NEW.saved_at IS DISTINCT FROM OLD.saved_at OR NEW.discarded_at IS DISTINCT FROM OLD.discarded_at)
    EXECUTE FUNCTION ml_drafts_wake();

-- ------------------------------------------------------------------------------------------------- the oversight cards' one gatherer
-- An agent mailbox's mail for the last p_days (1–90) — the cards (slice 5) and the tools agent_mail_summary / oversight_summary read this
-- one function. The caller (app.member_id, and app.on_behalf_of in an agent's run) needs triage on the mailbox; otherwise it does not exist.
CREATE OR REPLACE FUNCTION ml_agent_mail_summary(p_mailbox bigint, p_days integer DEFAULT 7) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE since timestamptz; b mailboxes%ROWTYPE; p agent_mail_policies%ROWTYPE; out jsonb;
BEGIN
    IF ml_level_rank(ml_level(p_mailbox)) < 2 THEN RAISE EXCEPTION 'No such mailbox' USING ERRCODE = 'no_data_found'; END IF;
    since := now() - make_interval(days => LEAST(GREATEST(COALESCE(p_days, 7), 1), 90));
    SELECT * INTO b FROM mailboxes WHERE id = p_mailbox;
    SELECT * INTO p FROM agent_mail_policies WHERE mailbox_id = p_mailbox;
    SELECT jsonb_build_object(
        'mailbox_id', b.id, 'address', b.address::text, 'kind', b.kind, 'days', LEAST(GREATEST(COALESCE(p_days, 7), 1), 90),
        'received', (SELECT count(DISTINCT m.message_key_id) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
                      WHERE f.mailbox_id = p_mailbox AND f.special_use = 'inbox' AND m.received_at >= since),
        'woken', (SELECT count(*) FROM agent_dispatches d WHERE d.mailbox_id = p_mailbox AND d.kind = 'mail' AND d.decision = 'wake' AND d.created_at >= since),
        'unscreened', (SELECT count(*) FROM agent_dispatches d WHERE d.mailbox_id = p_mailbox AND d.kind = 'mail' AND d.status = 'unscreened'),
        'released', (SELECT count(*) FROM agent_dispatches d WHERE d.mailbox_id = p_mailbox AND d.released_at >= since),
        'sent', (SELECT count(*) FROM outbound_email o WHERE o.mailbox_id = p_mailbox AND o.sender_is_agent AND o.status = 'sent' AND o.sent_at >= since),
        'held_now', (SELECT count(*) FROM outbound_email o WHERE o.mailbox_id = p_mailbox AND o.sender_is_agent AND o.status = 'held'),
        'oldest_held_at', (SELECT min(o.held_at) FROM outbound_email o WHERE o.mailbox_id = p_mailbox AND o.sender_is_agent AND o.status = 'held'),
        'approved_unedited', (SELECT count(*) FROM mail_reviews r JOIN outbound_email o ON o.id = r.outbound_id
                               WHERE o.mailbox_id = p_mailbox AND r.decision = 'approve' AND r.created_at >= since),
        'edited', (SELECT count(*) FROM mail_reviews r JOIN outbound_email o ON o.id = r.outbound_id
                    WHERE o.mailbox_id = p_mailbox AND r.decision = 'edit' AND r.created_at >= since),
        'rejected', (SELECT count(*) FROM mail_reviews r JOIN outbound_email o ON o.id = r.outbound_id
                      WHERE o.mailbox_id = p_mailbox AND r.decision = 'reject' AND r.created_at >= since),
        'taken_over', (SELECT count(*) FROM mail_reviews r JOIN outbound_email o ON o.id = r.outbound_id
                        WHERE o.mailbox_id = p_mailbox AND r.decision = 'take_over' AND r.created_at >= since),
        'expired', (SELECT count(*) FROM mail_reviews r JOIN outbound_email o ON o.id = r.outbound_id
                     WHERE o.mailbox_id = p_mailbox AND r.decision = 'expire' AND r.created_at >= since),
        'refused', (SELECT COALESCE(jsonb_object_agg(x.refusal, x.n), '{}'::jsonb) FROM (SELECT o.refusal, count(*) AS n FROM outbound_email o
                     WHERE o.mailbox_id = p_mailbox AND o.status = 'refused' AND o.created_at >= since GROUP BY o.refusal) x),
        'first_contacts_held', (SELECT count(*) FROM outbound_email o WHERE o.mailbox_id = p_mailbox AND o.sender_is_agent
                                 AND 'first_contact' = ANY (o.hold_reasons) AND o.created_at >= since),
        'external_today', (SELECT count(*) FROM outbound_email o WHERE o.mailbox_id = p_mailbox AND o.sender_is_agent AND o.external
                            AND o.status IN ('queued', 'sending', 'sent', 'deferred') AND o.created_at > now() - interval '1 day'),
        'max_external_per_day', p.max_external_per_day,
        'paused', COALESCE(p.paused, false), 'paused_by', p.paused_by, 'pause_reason', p.pause_reason,
        'last_activity_at', GREATEST((SELECT max(m.received_at) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE f.mailbox_id = p_mailbox),
                                     (SELECT max(o.created_at) FROM outbound_email o WHERE o.mailbox_id = p_mailbox))
    ) INTO out;
    RETURN out;
END$$;

-- ------------------------------------------------------------------------------------------------- grants
REVOKE ALL ON FUNCTION ml_thread_anchor(bigint), ml_outbound_reviewers(bigint), ml_review_dispatch(bigint, text, bigint, text),
    ml_mailbox_acting_person(bigint), ml_outbound_stalled(integer), ml_outbound_lost(bigint), ml_outbound_retry(bigint, bigint),
    ml_notify_coalesce(bigint, bigint, integer), ml_outbound_held_notice(), ml_outbox_wake(), ml_drafts_wake(),
    ml_agent_mail_summary(bigint, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_thread_anchor(bigint), ml_outbound_reviewers(bigint), ml_mailbox_acting_person(bigint), ml_outbound_stalled(integer),
    ml_outbound_lost(bigint), ml_outbound_retry(bigint, bigint), ml_notify_coalesce(bigint, bigint, integer), ml_review_dispatch(bigint, text, bigint, text)
TO malumail_rw;
GRANT EXECUTE ON FUNCTION ml_agent_mail_summary(bigint, integer) TO malumail_rw, malumail_records_ro;

COMMIT;
