-- 007: the REUSED tables (design §6.7): labels (tags, record_tags), files (attachments), the bell and the mail of notifications
-- (notifications, notification_prefs, notification_outbox), mail turns (agent_dispatches), the worker's record (worker_passes) and canned
-- replies (email_templates).
--
-- THE ESTATE'S DATA MODEL:
--   * `tags` — Help Desk db/009 verbatim + DocCloud db/009's appended columns (kind, colour, created_by, archived_at), APPENDING
--     mailbox_id: a label belongs to one mailbox (Gmail's labels are a mailbox's). The slug is unique per mailbox, so the verbatim
--     column-level UNIQUE (slug) becomes UNIQUE (mailbox_id, slug) — the one loosening, recorded here.
--   * `record_tags` — the Sales CRM db/006 shape (record_type, bigint record_id, tag_id); record_type ⊂ 'mail_message' and record_id =
--     mail_message_keys.id (a label is the application's state: it keys on the message key, never a UID — §6).
--   * `attachments` — GL db/009 canonical (bigint keys); record_type ⊂ mail_draft, outbound_email.
--   * `notifications`, `notification_prefs`, `notification_outbox` — Spaces db/012 (member-only), Spaces' appended references to its own
--     channels and messages kept as plain nullable columns without them (the CRM's precedent), the kinds ours.
--   * `agent_dispatches` — Spaces db/012 with its `kind`, the status and kind lists widened with mail's, APPENDING mailbox_id,
--     message_key_id (db/008 adds its reference), released_by / released_at (Unscreened) and decision (why it woke or did not).
--   * `worker_passes` — Spaces db/016 verbatim.
--   * `email_templates` — the Sales CRM db/011 verbatim (no seed rows: the CRM's merge fields are a CRM's), APPENDING mailbox_id (a
--     template shared on one mailbox; NULL = the owner's own or every mailbox's when shared).
BEGIN;

-- ------------------------------------------------------------------------------------------------- labels
CREATE TABLE tags (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug        text NOT NULL CHECK (slug ~ '^[a-z0-9][a-z0-9_/-]{0,49}$'),     -- Help Desk's pattern with '/', for nested labels (Work/Clients)
    label       text NOT NULL,
    usage_count integer NOT NULL DEFAULT 0,
    created_at  timestamptz NOT NULL DEFAULT now(),
    -- appended by DocCloud (db/009)
    kind        text NOT NULL DEFAULT 'free' CHECK (kind IN ('managed', 'free')),
    colour      text CHECK (colour IS NULL OR colour ~ '^#[0-9a-f]{6}$'),
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    archived_at timestamptz,
    -- appended (design §6.3)
    mailbox_id  bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    UNIQUE (mailbox_id, slug)
);

CREATE TABLE record_tags (
    record_type text NOT NULL CHECK (record_type IN ('mail_message')),
    record_id   bigint NOT NULL,                                   -- mail_message_keys.id
    tag_id      bigint NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (record_type, record_id, tag_id)
);
CREATE INDEX record_tags_tag_idx ON record_tags (tag_id);
CREATE OR REPLACE FUNCTION ml_record_tags_count() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN UPDATE tags SET usage_count = usage_count + 1 WHERE id = NEW.tag_id;
    ELSIF TG_OP = 'DELETE' THEN UPDATE tags SET usage_count = GREATEST(0, usage_count - 1) WHERE id = OLD.tag_id; END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER record_tags_count AFTER INSERT OR DELETE ON record_tags FOR EACH ROW EXECUTE FUNCTION ml_record_tags_count();

-- ------------------------------------------------------------------------------------------------- files
CREATE TABLE attachments (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL CHECK (record_type IN ('mail_draft', 'outbound_email')),
    record_id     bigint NOT NULL,
    filename      text NOT NULL,
    mime_type     text NOT NULL,
    byte_size     bigint NOT NULL CHECK (byte_size >= 0),
    sha256        text NOT NULL,
    storage_path  text NOT NULL,
    uploaded_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX attachments_record_idx ON attachments (record_type, record_id);

-- ------------------------------------------------------------------------------------------------- notifications
CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('new_mail', 'held_mail', 'review_decided', 'unscreened', 'break_glass', 'permission_granted',
                                               'credential_failed', 'sync_failed', 'quota', 'snooze_woke', 'send_failed', 'send_deferred',
                                               'agent_replied', 'digest')),
    record_type  text,
    record_id    bigint,
    title        text NOT NULL,
    body         text,
    read_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    -- appended by Spaces (db/012), kept without its references to Spaces' channels and messages
    record_uuid  uuid,
    channel_id   bigint,
    message_id   bigint,
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    -- appended (design §6.6)
    mailbox_id   bigint REFERENCES mailboxes(id) ON DELETE CASCADE
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC);
CREATE INDEX notifications_unread_idx ON notifications (member_id) WHERE read_at IS NULL;

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,                -- K6: the kernel keeps the opt-out; this is the person's choice here
    kinds          text[] NOT NULL DEFAULT '{held_mail,review_decided,unscreened,break_glass,permission_granted,credential_failed,sync_failed,send_failed}',
    text_kinds     text[] NOT NULL DEFAULT '{held_mail}',
    updated_at     timestamptz NOT NULL DEFAULT now(),
    -- appended by Spaces (db/012)
    digest         boolean NOT NULL DEFAULT false,
    away_minutes   integer CHECK (away_minutes IS NULL OR away_minutes BETWEEN 1 AND 1440),
    -- appended (design §9 slice 9): new mail in the bell for the mailboxes a person chose
    new_mail_mailbox_ids bigint[] NOT NULL DEFAULT '{}'
);

CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    to_email       citext,
    kind           text NOT NULL,
    record_type    text,
    record_id      bigint,
    dedupe_key     text,
    subject        text,
    body           text NOT NULL,
    body_html      text,
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    provider_ref   text,
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_due_idx ON notification_outbox (send_after) WHERE status = 'queued';

-- One notice to a person: the bell, and a mail when their preferences ask for it (the worker's notify_outbox pass sends it through
-- MaluMail's REST send — app/mail.php — never through a mailbox). Says nothing of any message's content.
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
    RETURN nid;
END$$;

-- ------------------------------------------------------------------------------------------------- mail turns
CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type      text,                                                 -- 'mail_message' here
    record_id        bigint,                                               -- the mail_messages row it arrived as
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('mention', 'dm', 'duty_proposal', 'ask', 'mail', 'release')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval',
                                                                   'queued', 'skipped', 'unscreened')),
    reply_excerpt    text,
    detail           text,
    attempts         integer NOT NULL DEFAULT 1,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz,
    -- appended by Spaces (db/012), kept without its references
    record_uuid      uuid,
    channel_id       bigint,
    conversation_id  text,                                                 -- the thread as the kernel's conversation key ('mail:<mailbox>:<thread anchor key>')
    reply_message_id bigint,
    pending_message_id bigint,
    next_attempt_at  timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.7, §5)
    mailbox_id       bigint REFERENCES mailboxes(id) ON DELETE CASCADE,
    message_key_id   bigint,                                               -- FK in db/008
    decision         text,                                                 -- why it woke, or why not (ml_dispatch_decide, db/010)
    released_by      bigint REFERENCES members(id) ON DELETE SET NULL,     -- a person released it from Unscreened
    released_at      timestamptz,
    CHECK ((released_by IS NULL) = (released_at IS NULL))
);
CREATE INDEX agent_dispatches_due_idx ON agent_dispatches (next_attempt_at) WHERE status = 'queued';
CREATE INDEX agent_dispatches_mailbox_idx ON agent_dispatches (mailbox_id, status, created_at DESC);
CREATE UNIQUE INDEX agent_dispatches_once ON agent_dispatches (agent_member_id, message_key_id, kind) WHERE message_key_id IS NOT NULL;

-- ------------------------------------------------------------------------------------------------- the worker's record
CREATE TABLE worker_passes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    started_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    steps         jsonb NOT NULL DEFAULT '{}'::jsonb,
    error         text
);

-- ------------------------------------------------------------------------------------------------- canned replies
CREATE TABLE email_templates (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            text NOT NULL,
    subject         text NOT NULL,
    body            text NOT NULL,
    kind            text NOT NULL DEFAULT 'general' CHECK (kind IN ('general', 'intro', 'follow_up', 'proposal', 'meeting', 'thank_you', 'renewal', 'win_back')),
    is_shared       boolean NOT NULL DEFAULT false,
    owner_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    usage_count     integer NOT NULL DEFAULT 0,
    archived_at     timestamptz,
    created_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.3): a template shared on one mailbox (NULL = the owner's own, or — shared — every mailbox's)
    mailbox_id      bigint REFERENCES mailboxes(id) ON DELETE CASCADE,
    CHECK (is_shared OR owner_member_id IS NOT NULL)
);
CREATE TRIGGER email_templates_touch BEFORE UPDATE ON email_templates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

GRANT SELECT, INSERT, UPDATE, DELETE ON tags, record_tags, attachments, notifications, notification_prefs, notification_outbox,
    agent_dispatches, worker_passes, email_templates TO malumail_rw;
REVOKE ALL ON FUNCTION ml_record_tags_count(), ml_notify(bigint, text, text, text, bigint, text, bigint, bigint, boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_notify(bigint, text, text, text, bigint, text, bigint, bigint, boolean) TO malumail_rw;

COMMIT;
