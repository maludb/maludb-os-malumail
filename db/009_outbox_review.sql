-- 009: WRITING — drafts (kept in the mailbox's Drafts folder, so a phone sees them), THE OUTBOX made on Send, THE POLICY that holds an
-- agent's mail, the REFUSALS, the outbox's STATUS MACHINE, and THE REVIEW of held mail by PEOPLE (design §5, §6.1 (4), §6.3; D8, D10,
-- D12).
--
-- THE ESTATE'S DATA MODEL (design §6.7):
--   * `outbound_email` is Help Desk db/012 VERBATIM with the Sales CRM's appended columns (db/011, db/021, db/022 — sender_member_id,
--     contact_id, deal_id, cc_addresses, body_text, body_html, message_id, template_id, has_ics, is_private, in_reply_to,
--     references_ids, activity_id, campaign_id, attempts, retry_at), the CRM's references to its own tables dropped and the columns kept
--     (as the CRM did with Help Desk's ticket link); LOOSENED: to_address is the first recipient, subject may be empty; `status`
--     WIDENED with held, rejected, cancelled, deferred, expired, refused and `sending` (claimed by the mail service — a row found
--     `sending` after a crash is looked for in Sent by its Message-ID before it is ever submitted again); APPENDING §6.3's columns.
--     The design's `next_attempt_at` is the CRM's `retry_at` (one column, the CRM's name).
--   * NEW CANONICAL: `mail_drafts`, `mail_reviews`.
--
-- THE RULES (in SQL):
--   * a person's mail is never held; an agent's passes ml_outbound_hold_reasons() — every reason that applies, shown to the reviewer;
--   * an agent's mail from a PERSON's mailbox is always held for that person (D12, reason `on_behalf`); from a SHARED mailbox it is held
--     for the mailbox's people (reason `mode` — a shared mailbox has no policy to widen: a decision taken here, design §16);
--   * RFC 3834's loop rules and the same answer within seven days REFUSE an agent's message (status `refused`, the agent told why);
--   * the reviewer is a PERSON: the agent mailbox's overseers (a person with full; the Mail admins hold full there), a shared mailbox's
--     people with full, a person's mailbox's OWNER; an agent never decides (refused, not paused).
BEGIN;

-- ------------------------------------------------------------------------------------------------- drafts
CREATE TABLE mail_drafts (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_id      bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    member_id       bigint REFERENCES members(id) ON DELETE SET NULL,        -- the writer (a person or an agent); NULL = written elsewhere (a phone)
    draft_key       text NOT NULL,                                           -- a Message-ID minted for the draft, the same across replacements
    folder_id       bigint REFERENCES mail_folders(id) ON DELETE SET NULL,   -- where its current version is (the Drafts folder)
    uidvalidity     bigint,
    uid             bigint,
    identity_id     bigint REFERENCES mail_identities(id) ON DELETE SET NULL,
    to_addresses    text[] NOT NULL DEFAULT '{}',
    cc_addresses    text[] NOT NULL DEFAULT '{}',
    bcc_addresses   text[] NOT NULL DEFAULT '{}',
    subject         text NOT NULL DEFAULT '',
    body_html       text,
    body_text       text NOT NULL DEFAULT '',
    reply_to_key_id bigint REFERENCES mail_message_keys(id) ON DELETE SET NULL,
    mode            text NOT NULL DEFAULT 'new' CHECK (mode IN ('new', 'reply', 'reply_all', 'forward')),
    in_reply_to     text,
    references_ids  text[] NOT NULL DEFAULT '{}',
    saved_at        timestamptz NOT NULL DEFAULT now(),
    outbound_id     bigint,                                                  -- FK below: the send it became (undo reopens the draft)
    discarded_at    timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (mailbox_id, draft_key),
    CHECK ((uid IS NULL) = (uidvalidity IS NULL))
);
CREATE INDEX mail_drafts_writer_idx ON mail_drafts (member_id, saved_at DESC) WHERE discarded_at IS NULL;
CREATE TRIGGER mail_drafts_touch BEFORE UPDATE ON mail_drafts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Writing a draft is triage (§3); the writer is checked as the caller is (the Expert in a person's run included).
CREATE OR REPLACE FUNCTION ml_drafts_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.member_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.member_id IS DISTINCT FROM OLD.member_id) THEN
        IF ml_level_rank(ml_level_as(NEW.mailbox_id, NEW.member_id, NULLIF(current_setting('app.on_behalf_of', true), '')::bigint)) < 2 THEN
            RAISE EXCEPTION 'Writing a draft here needs triage on the mailbox' USING ERRCODE = 'insufficient_privilege';
        END IF;
    END IF;
    IF NEW.identity_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mail_identities WHERE id = NEW.identity_id AND mailbox_id = NEW.mailbox_id) THEN
        RAISE EXCEPTION 'An identity of another mailbox' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mail_drafts_guard BEFORE INSERT OR UPDATE OF member_id, mailbox_id, identity_id ON mail_drafts FOR EACH ROW EXECUTE FUNCTION ml_drafts_guard();

-- ------------------------------------------------------------------------------------------------- the outbox
CREATE TABLE outbound_email (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ticket_id       bigint,                                        -- Help Desk's columns, kept nullable; unused here
    message_ref_id  bigint,
    to_address      citext NOT NULL,                               -- the first recipient (To, else Cc, else Bcc)
    subject         text NOT NULL DEFAULT '',                      -- may be empty
    template        text,
    provider_message_id text,
    status          text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'failed', 'suppressed',
                                                                    'held', 'rejected', 'cancelled', 'deferred', 'expired', 'refused', 'sending')),
    error           text,
    sent_at         timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    -- appended by the Sales CRM (db/011, db/021, db/022), its references to its own tables dropped
    sender_member_id bigint REFERENCES members(id) ON DELETE SET NULL,   -- the person or agent who wrote it
    contact_id      bigint,
    deal_id         bigint,
    cc_addresses    text[] NOT NULL DEFAULT '{}',
    body_text       text NOT NULL DEFAULT '',
    body_html       text,
    message_id      text,                                          -- minted here and stored BEFORE submission, so a retry never sends twice
    template_id     bigint REFERENCES email_templates(id) ON DELETE SET NULL,
    has_ics         boolean NOT NULL DEFAULT false,
    is_private      boolean NOT NULL DEFAULT false,
    in_reply_to     text,
    references_ids  text[] NOT NULL DEFAULT '{}',
    activity_id     bigint,
    campaign_id     bigint,
    attempts        smallint NOT NULL DEFAULT 0,
    retry_at        timestamptz,                                   -- the design's next_attempt_at
    -- appended (design §6.3)
    mailbox_id      bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    identity_id     bigint REFERENCES mail_identities(id) ON DELETE SET NULL,
    on_behalf_member_id bigint REFERENCES members(id) ON DELETE SET NULL,   -- a delegate: the mailbox in From, the delegate in Sender
    to_addresses    text[] NOT NULL DEFAULT '{}',
    bcc_addresses   text[] NOT NULL DEFAULT '{}',
    reply_to_key_id bigint REFERENCES mail_message_keys(id) ON DELETE SET NULL,
    send_after      timestamptz NOT NULL DEFAULT now(),            -- the undo window and the schedule
    smtp_reply      text,
    draft_id        bigint REFERENCES mail_drafts(id) ON DELETE SET NULL,
    purpose         text,                                          -- required of an agent
    hold_reasons    text[] NOT NULL DEFAULT '{}',
    refusal         text CHECK (refusal IS NULL OR refusal IN ('loop_rule', 'same_answer')),
    review_id       bigint,                                        -- FK below: the latest decision
    body_digest     text,
    sender_is_agent boolean NOT NULL DEFAULT false,                -- as written (the mirror may change later)
    external        boolean NOT NULL DEFAULT false,                -- any recipient outside the business (§5)
    has_attachments boolean NOT NULL DEFAULT false,
    disclosure      text,                                          -- the footer line an agent's message carries (§5)
    auto_submitted  text CHECK (auto_submitted IS NULL OR auto_submitted IN ('auto-generated', 'auto-replied')),   -- dropped by a review
    agent_run_id    bigint,                                        -- the kernel's run that wrote it
    held_at         timestamptz,
    decided_at      timestamptz,
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (NOT sender_is_agent OR (purpose IS NOT NULL AND length(btrim(purpose)) >= 3)),
    CHECK ((status = 'refused') = (refusal IS NOT NULL)),
    CHECK (status <> 'held' OR cardinality(hold_reasons) > 0)
);
CREATE INDEX outbound_email_due_idx ON outbound_email (send_after) WHERE status IN ('queued', 'deferred');
CREATE INDEX outbound_email_held_idx ON outbound_email (mailbox_id, held_at) WHERE status = 'held';
CREATE INDEX outbound_email_mailbox_idx ON outbound_email (mailbox_id, created_at DESC);
CREATE INDEX outbound_email_message_id_idx ON outbound_email (message_id);
CREATE INDEX outbound_email_sender_idx ON outbound_email (sender_member_id, created_at DESC);
CREATE INDEX outbound_email_digest_idx ON outbound_email (sender_member_id, body_digest, created_at DESC) WHERE body_digest IS NOT NULL;
CREATE TRIGGER outbound_email_touch BEFORE UPDATE ON outbound_email FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE mail_drafts ADD CONSTRAINT mail_drafts_outbound_fk FOREIGN KEY (outbound_id) REFERENCES outbound_email(id) ON DELETE SET NULL;

-- ------------------------------------------------------------------------------------------------- reviews
CREATE TABLE mail_reviews (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    outbound_id         bigint NOT NULL REFERENCES outbound_email(id) ON DELETE CASCADE,
    decision            text NOT NULL CHECK (decision IN ('approve', 'edit', 'reject', 'reply', 'take_over', 'expire')),
    reviewer_member_id  bigint REFERENCES members(id) ON DELETE RESTRICT,   -- a person (the guard); NULL only for the worker's expiry
    note                text,
    before_digest       text,
    after_digest        text,
    created_at          timestamptz NOT NULL DEFAULT now(),
    CHECK ((decision = 'expire') = (reviewer_member_id IS NULL)),
    CHECK (decision NOT IN ('reject', 'reply') OR (note IS NOT NULL AND length(btrim(note)) > 0)),
    CHECK (decision <> 'edit' OR (before_digest IS NOT NULL AND after_digest IS NOT NULL))
);
CREATE INDEX mail_reviews_outbound_idx ON mail_reviews (outbound_id, created_at);
ALTER TABLE outbound_email ADD CONSTRAINT outbound_email_review_fk FOREIGN KEY (review_id) REFERENCES mail_reviews(id) ON DELETE SET NULL;

CREATE OR REPLACE FUNCTION ml_reviews_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.decision <> 'expire' THEN PERFORM ml_require_person(NEW.reviewer_member_id); END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mail_reviews_guard BEFORE INSERT ON mail_reviews FOR EACH ROW EXECUTE FUNCTION ml_reviews_guard();
CREATE OR REPLACE FUNCTION ml_reviews_append_only() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'The oversight trail is append-only' USING ERRCODE = 'insufficient_privilege';
END$$;
CREATE TRIGGER mail_reviews_append_only BEFORE UPDATE OR DELETE ON mail_reviews FOR EACH ROW EXECUTE FUNCTION ml_reviews_append_only();

-- ------------------------------------------------------------------------------------------------- the status machine
-- held → queued (a person's approve or edit) | rejected | expired | cancelled (taken over, or the writer withdraws)
-- queued → sending | cancelled (only while send_after is ahead: undo, or a scheduled send) | held (paused or a switch thrown)
-- sending → sent | deferred | failed | queued (a crash, after Sent was searched) ; deferred → sending | failed | cancelled
-- failed → queued (a person retries) ; sent, suppressed, rejected, cancelled, expired, refused are final.
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
    IF NOT (
        (OLD.status = 'held' AND NEW.status IN ('queued', 'rejected', 'expired', 'cancelled')) OR
        (OLD.status = 'queued' AND NEW.status IN ('sending', 'cancelled', 'held')) OR
        (OLD.status = 'sending' AND NEW.status IN ('sent', 'deferred', 'failed', 'queued')) OR
        (OLD.status = 'deferred' AND NEW.status IN ('sending', 'failed', 'cancelled')) OR
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
CREATE TRIGGER outbound_email_guard BEFORE INSERT OR UPDATE ON outbound_email FOR EACH ROW EXECUTE FUNCTION ml_outbound_guard();

-- ------------------------------------------------------------------------------------------------- helpers
CREATE OR REPLACE FUNCTION ml_body_digest(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT encode(digest(regexp_replace(lower(btrim(COALESCE(p, ''))), '\s+', ' ', 'g'), 'sha256'), 'hex');
$$;

CREATE OR REPLACE FUNCTION ml_recipients(p_to text[], p_cc text[], p_bcc text[]) RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(array_agg(DISTINCT lower(btrim(x))), '{}') FROM unnest(COALESCE(p_to, '{}') || COALESCE(p_cc, '{}') || COALESCE(p_bcc, '{}')) x
     WHERE x IS NOT NULL AND btrim(x) <> '';
$$;

-- The agents' business-wide external budget today (§5): the Mail admins' number, else the warm-up — weeks 1–4 at 20, 50, 100, 200 a day
-- from the first agent send. "Then up half a week while bounces stay under 2 %" needs bounces counted (slice 5); until then it stays at 200.
CREATE OR REPLACE FUNCTION ml_agent_external_budget() RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(s.agent_external_budget,
                    CASE WHEN s.agent_budget_started_at IS NULL THEN 20
                         ELSE (ARRAY[20, 50, 100, 200])[LEAST(4, 1 + floor(extract(epoch FROM now() - s.agent_budget_started_at) / 604800)::integer)] END)
      FROM ml_settings s WHERE s.id = 1;
$$;

-- Every reason an agent's message waits for a person (§5's table). A person's message: none.
CREATE OR REPLACE FUNCTION ml_outbound_hold_reasons(p_mailbox bigint, p_sender bigint, p_to text[], p_cc text[], p_bcc text[],
                                                    p_has_attachments boolean, p_reply_to_key bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE b mailboxes%ROWTYPE; p agent_mail_policies%ROWTYPE; s ml_settings%ROWTYPE; r text[] := '{}'; recips text[]; ext boolean; thr bigint; n integer;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM members WHERE id = p_sender AND member_kind = 'agent') THEN RETURN '{}'; END IF;
    SELECT * INTO b FROM mailboxes WHERE id = p_mailbox;
    SELECT * INTO s FROM ml_settings WHERE id = 1;
    recips := ml_recipients(p_to, p_cc, p_bcc);
    ext := EXISTS (SELECT 1 FROM unnest(recips) x WHERE NOT ml_is_internal_address(x));
    IF b.kind = 'person' THEN
        r := r || 'on_behalf'::text;                                       -- D12: held for the person whose mailbox it is
    ELSIF b.kind = 'shared' THEN
        r := r || 'mode'::text;                                            -- no policy to widen: the mailbox's people review
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
        thr := ml_key_thread(p_reply_to_key);
        IF thr IS NOT NULL THEN
            SELECT count(*) INTO n FROM outbound_email o
             WHERE o.mailbox_id = p_mailbox AND o.sender_is_agent AND o.status IN ('queued', 'sending', 'sent', 'deferred')
               AND o.created_at > now() - interval '1 day' AND o.reply_to_key_id IS NOT NULL AND ml_key_thread(o.reply_to_key_id) = thr;
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

-- Why an agent's message is refused outright, or NULL (§5): RFC 3834's loop rules; the same answer to the same recipient in 7 days.
CREATE OR REPLACE FUNCTION ml_outbound_refusal(p_sender bigint, p_reply_to_key bigint, p_recipients text[], p_body_digest text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM members WHERE id = p_sender AND member_kind = 'agent') THEN RETURN NULL; END IF;
    IF p_reply_to_key IS NOT NULL AND EXISTS (SELECT 1 FROM mail_messages m WHERE m.message_key_id = p_reply_to_key
                                                 AND ml_is_automated(m.auto_submitted, m.is_list, m.return_path_null)) THEN
        RETURN 'loop_rule';
    END IF;
    IF EXISTS (SELECT 1 FROM outbound_email o
                WHERE o.sender_member_id = p_sender AND o.body_digest = p_body_digest AND o.created_at > now() - interval '7 days'
                  AND o.status IN ('held', 'queued', 'sending', 'sent', 'deferred')
                  AND ml_recipients(o.to_addresses, o.cc_addresses, o.bcc_addresses) && p_recipients) THEN
        RETURN 'same_answer';
    END IF;
    RETURN NULL;
END$$;

-- The words for a refusal, said to the agent.
CREATE OR REPLACE FUNCTION ml_refusal_words(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p WHEN 'loop_rule' THEN 'Not sent: the message it answers was written by a machine (an auto-reply, a list or bulk mail), and an agent never answers those.'
                  WHEN 'same_answer' THEN 'Not sent: the same message went to the same recipient within the last seven days.' END;
$$;

-- ------------------------------------------------------------------------------------------------- Send
-- A message from a mailbox, made on Send (by the handler for a person; by the actions server for an agent). Answers the outbound id;
-- the row's status says what happened: queued (with its undo window or schedule), held (its reasons), refused (its refusal).
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
    -- threading: the answered message's Message-ID and References
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
                                disclosure, auto_submitted, agent_run_id, error)
    VALUES (first, COALESCE(p_subject, ''),
            CASE WHEN refusal IS NOT NULL THEN 'refused' WHEN cardinality(reasons) > 0 THEN 'held' ELSE 'queued' END,
            p_sender, COALESCE(p_cc, '{}'), COALESCE(p_body_text, ''), p_body_html, mid, irt, COALESCE(refs, '{}'), p_mailbox, ident,
            CASE WHEN b.owner_member_id IS DISTINCT FROM p_sender THEN p_sender END, COALESCE(p_to, '{}'), COALESCE(p_bcc, '{}'), p_reply_to_key,
            COALESCE(p_send_at, now() + make_interval(secs => undo)), p_draft, NULLIF(btrim(p_purpose), ''), reasons, refusal, digest,
            m.member_kind = 'agent', ext, COALESCE(p_has_attachments, false), footer,
            CASE WHEN m.member_kind = 'agent' THEN CASE WHEN p_reply_to_key IS NULL THEN 'auto-generated' ELSE 'auto-replied' END END,
            p_agent_run, ml_refusal_words(refusal))
    RETURNING id INTO oid;
    IF cardinality(reasons) > 0 THEN UPDATE outbound_email SET held_at = now() WHERE id = oid; END IF;
    IF p_draft IS NOT NULL THEN UPDATE mail_drafts SET outbound_id = oid WHERE id = p_draft AND mailbox_id = p_mailbox; END IF;
    RETURN oid;
END$$;

-- Undo (or withdrawing a held message by its writer): the row is cancelled and the draft it came from reopened.
CREATE OR REPLACE FUNCTION ml_outbound_cancel(p_outbound bigint, p_member bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o outbound_email%ROWTYPE;
BEGIN
    SELECT * INTO o FROM outbound_email WHERE id = p_outbound FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such message' USING ERRCODE = 'no_data_found'; END IF;
    IF o.sender_member_id IS DISTINCT FROM p_member THEN
        RAISE EXCEPTION 'Only its writer undoes a message' USING ERRCODE = 'insufficient_privilege';
    END IF;
    UPDATE outbound_email SET status = 'cancelled' WHERE id = p_outbound;
    UPDATE mail_drafts SET outbound_id = NULL WHERE id = o.draft_id;
    RETURN o.draft_id;
END$$;

-- ------------------------------------------------------------------------------------------------- review
-- May this member decide this held message? A person; a person's mailbox: its owner; an agent's or shared mailbox: a person with full.
CREATE OR REPLACE FUNCTION ml_outbound_reviewer(p_outbound bigint, p_member bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM outbound_email o WHERE o.id = p_outbound AND ml_is_decider(o.mailbox_id, p_member));
$$;

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
    INSERT INTO mail_reviews (outbound_id, decision, reviewer_member_id, note, before_digest, after_digest)
    VALUES (p_outbound, p_decision, p_reviewer, NULLIF(btrim(p_note), ''), CASE WHEN p_decision = 'edit' THEN before_d END, after_d)
    RETURNING id INTO rid;
    undo := COALESCE((SELECT undo_send_seconds FROM ml_settings WHERE id = 1), 10);
    IF p_decision IN ('approve', 'edit') THEN
        -- a reviewed message keeps X-OS-Agent and drops Auto-Submitted (§5)
        UPDATE outbound_email SET status = 'queued', review_id = rid, auto_submitted = NULL, send_after = now() + make_interval(secs => undo),
               subject = CASE WHEN p_decision = 'edit' THEN COALESCE(p_subject, subject) ELSE subject END,
               body_text = CASE WHEN p_decision = 'edit' THEN p_body_text ELSE body_text END,
               body_html = CASE WHEN p_decision = 'edit' THEN p_body_html ELSE body_html END,
               body_digest = CASE WHEN p_decision = 'edit' THEN ml_body_digest(p_body_text) ELSE body_digest END
         WHERE id = p_outbound;
    ELSIF p_decision = 'reject' THEN
        UPDATE outbound_email SET status = 'rejected', review_id = rid WHERE id = p_outbound;
    ELSIF p_decision = 'reply' THEN
        UPDATE outbound_email SET review_id = rid WHERE id = p_outbound;                     -- still held; the note reaches the agent
    ELSE
        UPDATE outbound_email SET status = 'cancelled', review_id = rid WHERE id = p_outbound;
        INSERT INTO mail_thread_states AS t (mailbox_id, anchor_key_id, taken_over_by, taken_over_at, agent_muted)
        VALUES (o.mailbox_id, o.reply_to_key_id, p_reviewer, now(), true)
        ON CONFLICT (mailbox_id, anchor_key_id) DO UPDATE SET taken_over_by = EXCLUDED.taken_over_by, taken_over_at = now(), agent_muted = true;
    END IF;
    RETURN rid;
END$$;

-- The worker's held_expiry pass: held mail older than the settings' hours expires, and the agent learns it in its next turn.
CREATE OR REPLACE FUNCTION ml_held_expire() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; n integer := 0; rid bigint;
BEGIN
    FOR r IN SELECT o.id FROM outbound_email o, ml_settings s
              WHERE s.id = 1 AND o.status = 'held' AND o.held_at < now() - make_interval(hours => s.held_expiry_hours)
              FOR UPDATE OF o SKIP LOCKED LOOP
        INSERT INTO mail_reviews (outbound_id, decision) VALUES (r.id, 'expire') RETURNING id INTO rid;
        UPDATE outbound_email SET status = 'expired', review_id = rid WHERE id = r.id;
        n := n + 1;
    END LOOP;
    RETURN n;
END$$;

-- ------------------------------------------------------------------------------------------------- the mail service's outbox loop
-- Claim due rows (paused agents' and thrown switches' rows go back to held first — the kill switch holds even an approved message).
CREATE OR REPLACE FUNCTION ml_outbound_claim(p_limit integer DEFAULT 10) RETURNS SETOF bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s ml_settings%ROWTYPE;
BEGIN
    SELECT * INTO s FROM ml_settings WHERE id = 1;
    UPDATE outbound_email o SET status = 'held', hold_reasons = (SELECT array_agg(DISTINCT x) FROM unnest(o.hold_reasons || 'paused'::text) x)
     WHERE o.status = 'queued' AND o.sender_is_agent
       AND EXISTS (SELECT 1 FROM agent_mail_policies p WHERE p.mailbox_id = o.mailbox_id AND p.paused);
    UPDATE outbound_email o SET status = 'held',
           hold_reasons = (SELECT array_agg(DISTINCT x) FROM unnest(o.hold_reasons || CASE WHEN s.hold_all_agents THEN 'hold_all_agents' ELSE 'hold_all_external' END) x)
     WHERE o.status = 'queued' AND o.sender_is_agent AND o.review_id IS NULL AND (s.hold_all_agents OR (s.hold_all_external AND o.external));
    RETURN QUERY
    UPDATE outbound_email SET status = 'sending', attempts = attempts + 1
     WHERE id IN (SELECT id FROM outbound_email
                   WHERE status IN ('queued', 'deferred') AND send_after <= now() AND (retry_at IS NULL OR retry_at <= now())
                   ORDER BY send_after, id FOR UPDATE SKIP LOCKED LIMIT GREATEST(p_limit, 1))
    RETURNING id;
END$$;

-- Submitted and filed in Sent: correspondents learn the send — KNOWN only through a person (the sender, or the approver).
CREATE OR REPLACE FUNCTION ml_outbound_sent(p_outbound bigint, p_provider_message_id text, p_smtp_reply text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o outbound_email%ROWTYPE; by_person bigint; a text;
BEGIN
    SELECT * INTO o FROM outbound_email WHERE id = p_outbound FOR UPDATE;
    UPDATE outbound_email SET status = 'sent', sent_at = now(), provider_message_id = p_provider_message_id, smtp_reply = p_smtp_reply, error = NULL
     WHERE id = p_outbound;
    IF NOT o.sender_is_agent THEN
        by_person := o.sender_member_id;
    ELSE
        SELECT r.reviewer_member_id INTO by_person FROM mail_reviews r
         WHERE r.outbound_id = p_outbound AND r.decision IN ('approve', 'edit') ORDER BY r.id DESC LIMIT 1;
        UPDATE ml_settings SET agent_budget_started_at = now() WHERE id = 1 AND agent_budget_started_at IS NULL;
    END IF;
    FOREACH a IN ARRAY ml_recipients(o.to_addresses, o.cc_addresses, o.bcc_addresses) LOOP
        PERFORM ml_correspondent_sent(o.mailbox_id, a, NULL, by_person);
    END LOOP;
END$$;

CREATE OR REPLACE FUNCTION ml_outbound_deferred(p_outbound bigint, p_smtp_reply text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    -- 30 s, then doubling to at most 30 min; the fifth deferral fails the row
    UPDATE outbound_email SET status = CASE WHEN attempts >= 5 THEN 'failed' ELSE 'deferred' END, smtp_reply = p_smtp_reply,
           error = CASE WHEN attempts >= 5 THEN 'MaluMail kept deferring this message: ' || left(COALESCE(p_smtp_reply, ''), 200) END,
           retry_at = now() + LEAST(interval '30 minutes', interval '30 seconds' * power(2, GREATEST(attempts - 1, 0)))
     WHERE id = p_outbound AND status = 'sending';
END$$;

CREATE OR REPLACE FUNCTION ml_outbound_failed(p_outbound bigint, p_error text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE outbound_email SET status = 'failed', error = left(COALESCE(p_error, 'failed'), 1000) WHERE id = p_outbound AND status = 'sending';
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON mail_drafts, outbound_email TO malumail_rw;
GRANT SELECT, INSERT ON mail_reviews TO malumail_rw;
REVOKE ALL ON FUNCTION ml_drafts_guard(), ml_outbound_guard(), ml_reviews_guard(), ml_reviews_append_only(),
    ml_outbound_submit(bigint, bigint, bigint, text[], text[], text[], text, text, text, bigint, bigint, text, boolean, timestamptz, bigint),
    ml_outbound_cancel(bigint, bigint), ml_review_decide(bigint, bigint, text, text, text, text, text), ml_held_expire(),
    ml_outbound_claim(integer), ml_outbound_sent(bigint, text, text), ml_outbound_deferred(bigint, text), ml_outbound_failed(bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION
    ml_outbound_submit(bigint, bigint, bigint, text[], text[], text[], text, text, text, bigint, bigint, text, boolean, timestamptz, bigint),
    ml_outbound_cancel(bigint, bigint), ml_review_decide(bigint, bigint, text, text, text, text, text), ml_held_expire(),
    ml_outbound_claim(integer), ml_outbound_sent(bigint, text, text), ml_outbound_deferred(bigint, text), ml_outbound_failed(bigint, text)
TO malumail_rw;
GRANT EXECUTE ON FUNCTION ml_body_digest(text), ml_recipients(text[], text[], text[]), ml_agent_external_budget(),
    ml_outbound_hold_reasons(bigint, bigint, text[], text[], text[], boolean, bigint), ml_outbound_refusal(bigint, bigint, text[], text),
    ml_refusal_words(text), ml_outbound_reviewer(bigint, bigint)
TO malumail_rw, malumail_records_ro;

COMMIT;
