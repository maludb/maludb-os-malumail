-- 008: THE INDEX of the mail server — message keys, folders, messages, conversations — and the application's own state that rides on
-- it: conversation states anchored on a message, per-viewer read state, correspondents (design §6, §6.3; D4, D5, D13).
--
-- MaluMail's server is the RECORD of every message; this is an index of it, rebuildable at any time — EXCEPT what keys on a MESSAGE KEY
-- (mail_message_keys): labels (record_tags), viewer reads, conversation states, screening (agent_dispatches), drafts' replies, the
-- outbox's reply links. A UID changes when a message moves and when a folder's UIDVALIDITY resets; a message key survives both and
-- survives a rebuild. A message without a Message-ID is keyed by its envelope digest; a second message claiming a Message-ID already
-- keyed with a DIFFERENT envelope is keyed `Message-ID#digest` — a forged duplicate inherits nothing.
--
-- CONVERSATIONS (Gmail's, JWZ's rules across folders): References, then In-Reply-To, then the normalised subject within 30 days among
-- the same participants; a changed subject or the 100th message starts a new one; a parent arriving after its replies adopts them.
-- Threads are rebuildable; nothing the application owns keys on a thread id. The mail service may set app.ml_bulk = 'on' for an
-- initial sync (no threading per row) and call ml_threads_rebuild(mailbox) once after.
--
-- SEARCH (D5): the message index is itself the search structure — Gmail's operators filter the same rows the text matches
-- (ml_search, below); `tsv` is subject (A), people (B), snippet and text (C) under `ml_mail` (simple + unaccent: mail is any language).
--
-- NEW CANONICAL (design §6.7): mail_message_keys, mail_folders, mail_messages, mail_threads, mail_thread_states, mail_viewer_reads,
-- mail_correspondents.
BEGIN;

CREATE TEXT SEARCH CONFIGURATION ml_mail (COPY = simple);
ALTER TEXT SEARCH CONFIGURATION ml_mail ALTER MAPPING FOR asciiword, asciihword, hword_asciipart, word, hword, hword_part WITH unaccent, simple;

-- ------------------------------------------------------------------------------------------------- message keys
CREATE TABLE mail_message_keys (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_id         bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    message_key        text NOT NULL,                       -- the Message-ID; 'digest:<sha256>' without one; '<Message-ID>#<sha256>' for a second envelope
    message_id_header  text,
    envelope_digest    text NOT NULL CHECK (envelope_digest ~ '^[0-9a-f]{64}$'),
    created_at         timestamptz NOT NULL DEFAULT now(),
    UNIQUE (mailbox_id, message_key)
);
ALTER TABLE agent_dispatches ADD CONSTRAINT agent_dispatches_message_key_fk FOREIGN KEY (message_key_id) REFERENCES mail_message_keys(id) ON DELETE CASCADE;

-- The envelope digest both sides compute the same way: SHA-256 over Date, From, To, Cc and Subject as the headers carry them
-- (whitespace folded, lower-cased addresses), joined by a unit separator. The mail service calls this function or computes the same.
CREATE OR REPLACE FUNCTION ml_envelope_digest(p_date text, p_from text, p_to text, p_cc text, p_subject text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT encode(digest(concat_ws(chr(31), regexp_replace(btrim(COALESCE(p_date, '')), '\s+', ' ', 'g'), lower(btrim(COALESCE(p_from, ''))),
                                   lower(regexp_replace(btrim(COALESCE(p_to, '')), '\s+', ' ', 'g')), lower(regexp_replace(btrim(COALESCE(p_cc, '')), '\s+', ' ', 'g')),
                                   regexp_replace(btrim(COALESCE(p_subject, '')), '\s+', ' ', 'g')), 'sha256'), 'hex');
$$;

-- The key of a message in a mailbox (made once; the same message in two folders, or moved, keeps it).
CREATE OR REPLACE FUNCTION ml_message_key_for(p_mailbox bigint, p_message_id text, p_digest text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE mid text := NULLIF(btrim(p_message_id), ''); k mail_message_keys%ROWTYPE; key text; kid bigint;
BEGIN
    IF p_digest IS NULL OR p_digest !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'A message key needs the envelope''s digest' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF mid IS NULL THEN
        key := 'digest:' || p_digest;
    ELSE
        SELECT * INTO k FROM mail_message_keys WHERE mailbox_id = p_mailbox AND message_key = mid;
        IF FOUND AND k.envelope_digest = p_digest THEN RETURN k.id; END IF;
        key := CASE WHEN FOUND THEN mid || '#' || p_digest ELSE mid END;     -- a second envelope under a known Message-ID inherits nothing
    END IF;
    INSERT INTO mail_message_keys (mailbox_id, message_key, message_id_header, envelope_digest)
    VALUES (p_mailbox, key, mid, p_digest) ON CONFLICT (mailbox_id, message_key) DO NOTHING RETURNING id INTO kid;
    IF kid IS NULL THEN SELECT id INTO kid FROM mail_message_keys WHERE mailbox_id = p_mailbox AND message_key = key; END IF;
    RETURN kid;
END$$;

-- ------------------------------------------------------------------------------------------------- folders
CREATE TABLE mail_folders (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_id      bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    name            text NOT NULL,                          -- the server's name (modified UTF-7 decoded by the mail service)
    delimiter       text,
    special_use     text CHECK (special_use IS NULL OR special_use IN ('inbox', 'sent', 'drafts', 'junk', 'trash', 'archive', 'snoozed')),
    uidvalidity     bigint CHECK (uidvalidity IS NULL OR uidvalidity > 0),
    uidnext         bigint,
    highestmodseq   bigint,
    messages_total  integer NOT NULL DEFAULT 0,
    messages_unseen integer NOT NULL DEFAULT 0,
    subscribed      boolean NOT NULL DEFAULT true,
    synced_at       timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (mailbox_id, name)
);
CREATE UNIQUE INDEX mail_folders_special ON mail_folders (mailbox_id, special_use) WHERE special_use IS NOT NULL;
CREATE TRIGGER mail_folders_touch BEFORE UPDATE ON mail_folders FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ------------------------------------------------------------------------------------------------- conversations
CREATE TABLE mail_threads (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_id       bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    subject          text,                                   -- the latest message's
    subject_norm     text NOT NULL DEFAULT '',
    participants     text[] NOT NULL DEFAULT '{}',           -- lower-cased addresses, the mailbox's own left out
    first_message_at timestamptz,
    last_message_at  timestamptz,
    message_count    integer NOT NULL DEFAULT 0,
    unread_count     integer NOT NULL DEFAULT 0,
    has_attachments  boolean NOT NULL DEFAULT false,
    flagged          boolean NOT NULL DEFAULT false,
    folder_ids       bigint[] NOT NULL DEFAULT '{}',
    snippet          text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX mail_threads_list_idx ON mail_threads (mailbox_id, last_message_at DESC);
CREATE INDEX mail_threads_subject_idx ON mail_threads (mailbox_id, subject_norm, last_message_at DESC);
CREATE INDEX mail_threads_folders_idx ON mail_threads USING gin (folder_ids);

-- ------------------------------------------------------------------------------------------------- messages
CREATE TABLE mail_messages (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_id         bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,    -- the folder's (set by the trigger)
    folder_id          bigint NOT NULL REFERENCES mail_folders(id) ON DELETE CASCADE,
    uidvalidity        bigint NOT NULL,
    uid                bigint NOT NULL CHECK (uid > 0),
    modseq             bigint,
    message_key_id     bigint NOT NULL REFERENCES mail_message_keys(id) ON DELETE CASCADE,
    thread_id          bigint REFERENCES mail_threads(id) ON DELETE SET NULL,
    message_id_header  text,
    in_reply_to        text,
    references_ids     text[] NOT NULL DEFAULT '{}',
    from_address       citext,
    from_name          text,
    sender_address     citext,                                -- the Sender header ("on behalf of")
    to_addresses       text[] NOT NULL DEFAULT '{}',          -- lower-cased
    cc_addresses       text[] NOT NULL DEFAULT '{}',
    bcc_addresses      text[] NOT NULL DEFAULT '{}',          -- only on mail this mailbox sent
    recipient_names    jsonb NOT NULL DEFAULT '{}'::jsonb,    -- address → display name, as the headers gave them
    reply_to           text[] NOT NULL DEFAULT '{}',
    subject            text,
    sent_at            timestamptz,                           -- the Date header
    received_at        timestamptz NOT NULL,                  -- INTERNALDATE
    size               integer NOT NULL DEFAULT 0 CHECK (size >= 0),
    seen               boolean NOT NULL DEFAULT false,
    flagged            boolean NOT NULL DEFAULT false,
    answered           boolean NOT NULL DEFAULT false,
    draft              boolean NOT NULL DEFAULT false,
    keywords           text[] NOT NULL DEFAULT '{}',
    snippet            text,                                  -- PREVIEW
    has_attachments    boolean NOT NULL DEFAULT false,
    attachment_names   text[] NOT NULL DEFAULT '{}',
    dmarc_pass         boolean,                               -- DMARC_POLICY_ALLOW in X-Spamd-Result; NULL = not stated
    spam_score         numeric(6,2),
    auto_submitted     text,                                  -- the Auto-Submitted header's value (NULL = absent)
    is_list            boolean NOT NULL DEFAULT false,        -- List-* present, or Precedence bulk/list/junk
    return_path_null   boolean NOT NULL DEFAULT false,        -- Return-Path: <>
    local_submission   boolean NOT NULL DEFAULT false,        -- arrived by AUTHENTICATED SUBMISSION on MaluMail's own server (its `with ESMTPSA`
                                                              -- Received line, no inbound filter verdict) — the spike, research 04 §B: internal
                                                              -- mail carries no DMARC result; with MM3 this proves an internal sender (db/010)
    body_text          text,                                  -- the text pass, capped (ml_settings.index_text_max_kb)
    text_indexed_at    timestamptz,
    tsv                tsvector,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    UNIQUE (folder_id, uidvalidity, uid)
);
CREATE INDEX mail_messages_list_idx ON mail_messages (mailbox_id, received_at DESC);
CREATE INDEX mail_messages_folder_idx ON mail_messages (folder_id, received_at DESC);
CREATE INDEX mail_messages_thread_idx ON mail_messages (thread_id, received_at);
CREATE INDEX mail_messages_key_idx ON mail_messages (message_key_id);
CREATE INDEX mail_messages_mid_idx ON mail_messages (mailbox_id, message_id_header) WHERE message_id_header IS NOT NULL;
CREATE INDEX mail_messages_reply_idx ON mail_messages (mailbox_id, in_reply_to) WHERE in_reply_to IS NOT NULL;
CREATE INDEX mail_messages_refs_idx ON mail_messages USING gin (references_ids);
CREATE INDEX mail_messages_tsv_idx ON mail_messages USING gin (tsv);
CREATE INDEX mail_messages_from_idx ON mail_messages (mailbox_id, from_address);
CREATE INDEX mail_messages_text_due_idx ON mail_messages (mailbox_id, received_at DESC) WHERE text_indexed_at IS NULL;

-- Is a message mail written by a machine (RFC 3834's loop rules, §5)?
CREATE OR REPLACE FUNCTION ml_is_automated(p_auto_submitted text, p_is_list boolean, p_return_path_null boolean) RETURNS boolean
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(lower(btrim(p_auto_submitted)), 'no') <> 'no' OR COALESCE(p_is_list, false) OR COALESCE(p_return_path_null, false);
$$;

-- A subject with its reply and forward prefixes (in the languages mail clients write them), counters and list tags taken off.
CREATE OR REPLACE FUNCTION ml_subject_norm(p text) RETURNS text
    LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE s text := lower(btrim(COALESCE(p, ''))); prev text;
BEGIN
    LOOP
        prev := s;
        s := regexp_replace(s, '^\s*\[[^\]]{1,40}\]\s*', '');                                                  -- [list-tag]
        s := regexp_replace(s, '^\s*(re|fw|fwd|aw|wg|sv|vs|antw|tr|rif|réf|ref|r|odp|enc|res|vá|vl)\s*(\[\d+\]|\(\d+\))?\s*[:：]\s*', '');
        EXIT WHEN s = prev;
    END LOOP;
    RETURN regexp_replace(btrim(s), '\s+', ' ', 'g');
END$$;

-- The participants of a message, the mailbox's own addresses (its address and its identities) left out.
CREATE OR REPLACE FUNCTION ml_participants(p_mailbox bigint, p_from text, p_to text[], p_cc text[]) RETURNS text[]
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(array_agg(DISTINCT a ORDER BY a), '{}') FROM (
        SELECT lower(btrim(x)) AS a FROM unnest(ARRAY[p_from] || COALESCE(p_to, '{}') || COALESCE(p_cc, '{}')) x WHERE x IS NOT NULL AND btrim(x) <> ''
    ) y
    WHERE a NOT IN (SELECT lower(address::text) FROM mailboxes WHERE id = p_mailbox
                    UNION SELECT lower(address::text) FROM mail_identities WHERE mailbox_id = p_mailbox);
$$;

-- Which conversation a new message joins (NULL = a new one). JWZ's order; a changed subject or a full conversation starts anew.
CREATE OR REPLACE FUNCTION ml_thread_find(p_mailbox bigint, p_key bigint, p_in_reply_to text, p_refs text[], p_subject text,
                                          p_at timestamptz, p_participants text[]) RETURNS bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE t bigint; refs text[]; sn text := ml_subject_norm(p_subject); tt mail_threads%ROWTYPE;
BEGIN
    -- the same message in another folder
    SELECT thread_id INTO t FROM mail_messages WHERE message_key_id = p_key AND thread_id IS NOT NULL LIMIT 1;
    IF t IS NOT NULL THEN RETURN t; END IF;
    -- References, then In-Reply-To: the nearest ancestor this mailbox has
    refs := COALESCE(p_refs, '{}') || CASE WHEN NULLIF(btrim(p_in_reply_to), '') IS NULL THEN '{}'::text[] ELSE ARRAY[btrim(p_in_reply_to)] END;
    IF cardinality(refs) > 0 THEN
        SELECT m.thread_id INTO t FROM mail_messages m
         WHERE m.mailbox_id = p_mailbox AND m.message_id_header = ANY (refs) AND m.thread_id IS NOT NULL
         ORDER BY array_position(refs, m.message_id_header) DESC NULLS LAST, m.received_at DESC LIMIT 1;
        IF t IS NOT NULL THEN
            SELECT * INTO tt FROM mail_threads WHERE id = t;
            IF (sn = '' OR tt.subject_norm = '' OR tt.subject_norm = sn) AND tt.message_count < 100 THEN RETURN t; END IF;
            RETURN NULL;                                                   -- the subject changed, or the conversation is full
        END IF;
    END IF;
    -- the subject within 30 days among the same participants
    IF sn <> '' AND cardinality(COALESCE(p_participants, '{}')) > 0 THEN
        SELECT th.id INTO t FROM mail_threads th
         WHERE th.mailbox_id = p_mailbox AND th.subject_norm = sn AND th.message_count < 100
           AND th.last_message_at >= COALESCE(p_at, now()) - interval '30 days'
           AND th.participants && p_participants
         ORDER BY th.last_message_at DESC LIMIT 1;
        RETURN t;
    END IF;
    RETURN NULL;
END$$;

-- A conversation's figures, from its messages (≤ 100). An emptied conversation goes.
CREATE OR REPLACE FUNCTION ml_thread_refresh(p_thread bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    IF p_thread IS NULL THEN RETURN; END IF;
    SELECT count(*) INTO n FROM mail_messages WHERE thread_id = p_thread;
    IF n = 0 THEN DELETE FROM mail_threads WHERE id = p_thread; RETURN; END IF;
    UPDATE mail_threads th SET
        message_count = n,
        unread_count = (SELECT count(*) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
                         WHERE m.thread_id = p_thread AND NOT m.seen AND COALESCE(f.special_use, '') NOT IN ('trash', 'junk', 'sent', 'drafts')),
        first_message_at = (SELECT min(COALESCE(m.sent_at, m.received_at)) FROM mail_messages m WHERE m.thread_id = p_thread),
        last_message_at = (SELECT max(m.received_at) FROM mail_messages m WHERE m.thread_id = p_thread),
        has_attachments = EXISTS (SELECT 1 FROM mail_messages m WHERE m.thread_id = p_thread AND m.has_attachments),
        flagged = EXISTS (SELECT 1 FROM mail_messages m WHERE m.thread_id = p_thread AND m.flagged),
        folder_ids = (SELECT array_agg(DISTINCT m.folder_id) FROM mail_messages m WHERE m.thread_id = p_thread),
        participants = (SELECT COALESCE(array_agg(DISTINCT p), '{}') FROM mail_messages m
                          CROSS JOIN LATERAL unnest(ml_participants(m.mailbox_id, m.from_address::text, m.to_addresses, m.cc_addresses)) p
                         WHERE m.thread_id = p_thread),
        subject = (SELECT m.subject FROM mail_messages m WHERE m.thread_id = p_thread ORDER BY m.received_at DESC, m.id DESC LIMIT 1),
        snippet = (SELECT m.snippet FROM mail_messages m WHERE m.thread_id = p_thread ORDER BY m.received_at DESC, m.id DESC LIMIT 1),
        updated_at = now()
     WHERE th.id = p_thread;
END$$;

-- Before a message is written: its mailbox from its folder, its text capped and indexed, and — unless an initial sync asked for bulk —
-- its conversation (a new one when none), adopting replies that arrived before it.
CREATE OR REPLACE FUNCTION ml_messages_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE cap integer; parts text[]; t bigint; orphan bigint;
BEGIN
    SELECT mailbox_id INTO NEW.mailbox_id FROM mail_folders WHERE id = NEW.folder_id;
    IF NOT EXISTS (SELECT 1 FROM mail_message_keys WHERE id = NEW.message_key_id AND mailbox_id = NEW.mailbox_id) THEN
        RAISE EXCEPTION 'A message''s key belongs to its mailbox' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.body_text IS NOT NULL THEN
        cap := COALESCE((SELECT index_text_max_kb FROM ml_settings WHERE id = 1), 100) * 1024;
        IF octet_length(NEW.body_text) > cap THEN NEW.body_text := left(NEW.body_text, cap); END IF;
    END IF;
    NEW.to_addresses := COALESCE((SELECT array_agg(lower(btrim(x))) FROM unnest(NEW.to_addresses) x), '{}');
    NEW.cc_addresses := COALESCE((SELECT array_agg(lower(btrim(x))) FROM unnest(NEW.cc_addresses) x), '{}');
    NEW.bcc_addresses := COALESCE((SELECT array_agg(lower(btrim(x))) FROM unnest(NEW.bcc_addresses) x), '{}');
    NEW.tsv := setweight(to_tsvector('ml_mail', COALESCE(NEW.subject, '')), 'A')
            || setweight(to_tsvector('ml_mail', concat_ws(' ', NEW.from_name, NEW.from_address::text, array_to_string(NEW.to_addresses, ' '),
                                                           array_to_string(NEW.cc_addresses, ' '), array_to_string(NEW.attachment_names, ' '))), 'B')
            || setweight(to_tsvector('ml_mail', concat_ws(' ', NEW.snippet, NEW.body_text)), 'C');
    IF TG_OP = 'INSERT' AND NEW.thread_id IS NULL AND current_setting('app.ml_bulk', true) IS DISTINCT FROM 'on' THEN
        parts := ml_participants(NEW.mailbox_id, NEW.from_address::text, NEW.to_addresses, NEW.cc_addresses);
        t := ml_thread_find(NEW.mailbox_id, NEW.message_key_id, NEW.in_reply_to, NEW.references_ids, NEW.subject, COALESCE(NEW.sent_at, NEW.received_at), parts);
        IF t IS NULL THEN
            INSERT INTO mail_threads (mailbox_id, subject, subject_norm, participants, first_message_at, last_message_at)
            VALUES (NEW.mailbox_id, NEW.subject, ml_subject_norm(NEW.subject), parts, COALESCE(NEW.sent_at, NEW.received_at), NEW.received_at)
            RETURNING id INTO t;
        END IF;
        NEW.thread_id := t;
        -- replies that arrived first: their conversations (same subject) join this one
        IF NEW.message_id_header IS NOT NULL THEN
            FOR orphan IN SELECT DISTINCT m.thread_id FROM mail_messages m JOIN mail_threads th ON th.id = m.thread_id
                           WHERE m.mailbox_id = NEW.mailbox_id AND m.thread_id <> t
                             AND (m.in_reply_to = NEW.message_id_header OR NEW.message_id_header = ANY (m.references_ids))
                             AND (th.subject_norm = '' OR ml_subject_norm(NEW.subject) = '' OR th.subject_norm = ml_subject_norm(NEW.subject)) LOOP
                UPDATE mail_messages SET thread_id = t WHERE thread_id = orphan;
                DELETE FROM mail_threads WHERE id = orphan;
            END LOOP;
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mail_messages_before BEFORE INSERT OR UPDATE OF folder_id, message_key_id, subject, from_name, from_address, to_addresses,
    cc_addresses, bcc_addresses, attachment_names, snippet, body_text ON mail_messages FOR EACH ROW EXECUTE FUNCTION ml_messages_before();
CREATE TRIGGER mail_messages_touch BEFORE UPDATE ON mail_messages FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION ml_messages_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF current_setting('app.ml_bulk', true) = 'on' THEN RETURN NULL; END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') THEN PERFORM ml_thread_refresh(NEW.thread_id); END IF;
    IF TG_OP = 'DELETE' OR (TG_OP = 'UPDATE' AND OLD.thread_id IS DISTINCT FROM NEW.thread_id) THEN PERFORM ml_thread_refresh(OLD.thread_id); END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER mail_messages_after AFTER INSERT OR DELETE OR UPDATE OF thread_id, seen, flagged, folder_id, has_attachments, received_at
    ON mail_messages FOR EACH ROW EXECUTE FUNCTION ml_messages_after();

-- Rebuild a mailbox's conversations from its messages, oldest first (after a bulk sync, or to prove a rebuild loses nothing). While it
-- runs, each conversation keeps only what the next message's match reads (count, last date, participants); every figure is computed
-- once at the end, set-based (the spike, research 04 §B: refreshing after every message cost 3.3 ms a message).
CREATE OR REPLACE FUNCTION ml_threads_rebuild(p_mailbox bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; t bigint; parts text[]; n integer := 0; own text[];
BEGIN
    PERFORM set_config('app.ml_bulk', 'on', true);
    own := ARRAY(SELECT lower(address::text) FROM mailboxes WHERE id = p_mailbox UNION SELECT lower(address::text) FROM mail_identities WHERE mailbox_id = p_mailbox);
    UPDATE mail_messages SET thread_id = NULL WHERE mailbox_id = p_mailbox;
    DELETE FROM mail_threads WHERE mailbox_id = p_mailbox;
    FOR r IN SELECT * FROM mail_messages WHERE mailbox_id = p_mailbox ORDER BY COALESCE(sent_at, received_at), id LOOP
        parts := COALESCE(ARRAY(SELECT DISTINCT a FROM (SELECT lower(btrim(x)) a FROM unnest(ARRAY[r.from_address::text] || r.to_addresses || r.cc_addresses) x
                                                         WHERE x IS NOT NULL AND btrim(x) <> '') y WHERE NOT (a = ANY (own)) ORDER BY a), '{}');
        t := ml_thread_find(p_mailbox, r.message_key_id, r.in_reply_to, r.references_ids, r.subject, COALESCE(r.sent_at, r.received_at), parts);
        IF t IS NULL THEN
            INSERT INTO mail_threads (mailbox_id, subject, subject_norm, participants, first_message_at, last_message_at, message_count)
            VALUES (p_mailbox, r.subject, ml_subject_norm(r.subject), parts, COALESCE(r.sent_at, r.received_at), r.received_at, 1) RETURNING id INTO t;
        ELSE
            UPDATE mail_threads SET message_count = message_count + 1, last_message_at = GREATEST(last_message_at, r.received_at),
                   participants = ARRAY(SELECT DISTINCT x FROM unnest(participants || parts) x ORDER BY x)
             WHERE id = t;
        END IF;
        UPDATE mail_messages SET thread_id = t WHERE id = r.id;
        n := n + 1;
    END LOOP;
    -- every conversation's figures, once
    WITH agg AS (
        SELECT m.thread_id, count(*) AS n,
               count(*) FILTER (WHERE NOT m.seen AND COALESCE(f.special_use, '') NOT IN ('trash', 'junk', 'sent', 'drafts')) AS unread,
               min(COALESCE(m.sent_at, m.received_at)) AS first_at, max(m.received_at) AS last_at, bool_or(m.has_attachments) AS att,
               bool_or(m.flagged) AS fl, array_agg(DISTINCT m.folder_id) AS folders,
               (array_agg(m.subject ORDER BY m.received_at DESC, m.id DESC))[1] AS subj, (array_agg(m.snippet ORDER BY m.received_at DESC, m.id DESC))[1] AS snip
          FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
         WHERE m.mailbox_id = p_mailbox GROUP BY m.thread_id)
    UPDATE mail_threads th SET message_count = agg.n, unread_count = agg.unread, first_message_at = agg.first_at, last_message_at = agg.last_at,
           has_attachments = agg.att, flagged = agg.fl, folder_ids = agg.folders, subject = agg.subj, snippet = agg.snip, updated_at = now()
      FROM agg WHERE th.id = agg.thread_id;
    PERFORM set_config('app.ml_bulk', 'off', true);
    RETURN n;
END$$;

-- ------------------------------------------------------------------------------------------------- the application's own state
-- A conversation's state, anchored on a MESSAGE (key): a conversation shows the states of any of its messages, so a merge, a split or
-- a rebuild loses nothing.
CREATE TABLE mail_thread_states (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_id          bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    anchor_key_id       bigint NOT NULL REFERENCES mail_message_keys(id) ON DELETE CASCADE,
    muted               boolean NOT NULL DEFAULT false,                -- Gmail's mute: new messages skip the inbox
    muted_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    taken_over_by       bigint REFERENCES members(id) ON DELETE SET NULL,   -- a person answers it; the agent is muted on it
    taken_over_at       timestamptz,
    snoozed_until       timestamptz,
    snoozed_from_folder text,
    snoozed_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    agent_muted         boolean NOT NULL DEFAULT false,                -- no mail turn for this conversation
    sensitive           boolean NOT NULL DEFAULT false,                -- never in an agent's context
    sensitive_by        bigint REFERENCES members(id) ON DELETE SET NULL,   -- the person who last marked or unmarked it
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (mailbox_id, anchor_key_id),
    CHECK ((taken_over_by IS NULL) = (taken_over_at IS NULL)),
    CHECK ((snoozed_until IS NULL) OR snoozed_from_folder IS NOT NULL)
);
CREATE INDEX mail_thread_states_snoozed_idx ON mail_thread_states (snoozed_until) WHERE snoozed_until IS NOT NULL;
CREATE TRIGGER mail_thread_states_touch BEFORE UPDATE ON mail_thread_states FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Taking over and marking sensitive are a person's (§3); the trigger refuses an agent in those columns.
CREATE OR REPLACE FUNCTION ml_thread_states_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.taken_over_by IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.taken_over_by IS DISTINCT FROM OLD.taken_over_by) THEN
        PERFORM ml_require_person(NEW.taken_over_by);
    END IF;
    -- marking and unmarking: sensitive_by names the person who last changed it
    IF (TG_OP = 'INSERT' AND NEW.sensitive) OR (TG_OP = 'UPDATE' AND NEW.sensitive IS DISTINCT FROM OLD.sensitive) THEN
        PERFORM ml_require_person(NEW.sensitive_by);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM mail_message_keys WHERE id = NEW.anchor_key_id AND mailbox_id = NEW.mailbox_id) THEN
        RAISE EXCEPTION 'A conversation''s state is anchored on one of its mailbox''s messages' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mail_thread_states_guard BEFORE INSERT OR UPDATE ON mail_thread_states FOR EACH ROW EXECUTE FUNCTION ml_thread_states_guard();

-- A conversation's state: any of its messages' states (the latest take-over, the latest snooze).
CREATE OR REPLACE FUNCTION ml_thread_state(p_thread bigint)
    RETURNS TABLE (muted boolean, taken_over_by bigint, snoozed_until timestamptz, agent_muted boolean, sensitive boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(bool_or(s.muted), false),
           (array_agg(s.taken_over_by ORDER BY s.taken_over_at DESC NULLS LAST))[1],
           max(s.snoozed_until),
           COALESCE(bool_or(s.agent_muted OR s.taken_over_by IS NOT NULL), false),
           COALESCE(bool_or(s.sensitive), false)
      FROM mail_thread_states s
     WHERE s.anchor_key_id IN (SELECT m.message_key_id FROM mail_messages m WHERE m.thread_id = p_thread);
$$;

-- The thread a message key is in now (NULL when it is not in the index).
CREATE OR REPLACE FUNCTION ml_key_thread(p_key bigint) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT thread_id FROM mail_messages WHERE message_key_id = p_key AND thread_id IS NOT NULL ORDER BY id LIMIT 1;
$$;

-- Read state for a `view` holder, and for everyone on an intake mailbox (D13): never a \Seen on the server.
CREATE TABLE mail_viewer_reads (
    member_id       bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    message_key_id  bigint NOT NULL REFERENCES mail_message_keys(id) ON DELETE CASCADE,
    read_at         timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, message_key_id)
);

-- Correspondents: each address a mailbox has seen. KNOWN means the mailbox SENT to it (a person's send, or an agent's a person
-- approved) or a person MARKED it; mail that was only received never makes a sender known (§5).
CREATE TABLE mail_correspondents (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_id      bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    address         citext NOT NULL,
    name            text,
    first_seen_at   timestamptz NOT NULL DEFAULT now(),
    last_seen_at    timestamptz NOT NULL DEFAULT now(),
    sent_count      integer NOT NULL DEFAULT 0,
    received_count  integer NOT NULL DEFAULT 0,
    is_internal     boolean NOT NULL DEFAULT false,
    known_at        timestamptz,
    known_by        bigint REFERENCES members(id) ON DELETE SET NULL,   -- the person who sent, approved or marked
    known_how       text CHECK (known_how IS NULL OR known_how IN ('sent', 'marked')),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (mailbox_id, address),
    CHECK ((known_at IS NULL) = (known_how IS NULL))
);
CREATE INDEX mail_correspondents_auto_idx ON mail_correspondents USING gin (address gin_trgm_ops);
CREATE TRIGGER mail_correspondents_touch BEFORE UPDATE ON mail_correspondents FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Becoming known needs a person (an agent's send makes nothing known unless a person approved it — the outbox passes the approver).
CREATE OR REPLACE FUNCTION ml_correspondents_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.known_at IS NOT NULL AND (TG_OP = 'INSERT' OR OLD.known_at IS NULL) THEN
        PERFORM ml_require_person(NEW.known_by);
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mail_correspondents_guard BEFORE INSERT OR UPDATE ON mail_correspondents FOR EACH ROW EXECUTE FUNCTION ml_correspondents_guard();

CREATE OR REPLACE FUNCTION ml_correspondent_received(p_mailbox bigint, p_address text, p_name text, p_at timestamptz DEFAULT now()) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_address IS NULL OR position('@' IN p_address) = 0 THEN RETURN; END IF;
    INSERT INTO mail_correspondents AS c (mailbox_id, address, name, first_seen_at, last_seen_at, received_count, is_internal)
    VALUES (p_mailbox, lower(btrim(p_address)), NULLIF(btrim(p_name), ''), p_at, p_at, 1, ml_is_internal_address(p_address))
    ON CONFLICT (mailbox_id, address) DO UPDATE SET received_count = c.received_count + 1, last_seen_at = GREATEST(c.last_seen_at, EXCLUDED.last_seen_at),
        name = COALESCE(c.name, EXCLUDED.name), is_internal = EXCLUDED.is_internal;
END$$;

-- p_known_by: the person who sent, or who approved an agent's send; NULL for an agent's unreviewed send (nothing becomes known).
CREATE OR REPLACE FUNCTION ml_correspondent_sent(p_mailbox bigint, p_address text, p_name text, p_known_by bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_address IS NULL OR position('@' IN p_address) = 0 THEN RETURN; END IF;
    INSERT INTO mail_correspondents AS c (mailbox_id, address, name, sent_count, is_internal, known_at, known_by, known_how)
    VALUES (p_mailbox, lower(btrim(p_address)), NULLIF(btrim(p_name), ''), 1, ml_is_internal_address(p_address),
            CASE WHEN p_known_by IS NOT NULL THEN now() END, p_known_by, CASE WHEN p_known_by IS NOT NULL THEN 'sent' END)
    ON CONFLICT (mailbox_id, address) DO UPDATE SET sent_count = c.sent_count + 1, last_seen_at = now(), name = COALESCE(c.name, EXCLUDED.name),
        is_internal = EXCLUDED.is_internal,
        known_at = COALESCE(c.known_at, EXCLUDED.known_at), known_by = COALESCE(c.known_by, EXCLUDED.known_by), known_how = COALESCE(c.known_how, EXCLUDED.known_how);
END$$;

CREATE OR REPLACE FUNCTION ml_is_known(p_mailbox bigint, p_address text) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM mail_correspondents WHERE mailbox_id = p_mailbox AND address = lower(btrim(p_address))::citext AND known_at IS NOT NULL);
$$;

-- ------------------------------------------------------------------------------------------------- what a change may do (§3, D13)
-- NULL when the acting member may do `p_op` to the folder, else the reason. The mail service asks before every mutation; the handler
-- asks first so the page can say why. Ops: seen, unseen, flag, unflag, label, move, copy, archive, trash, spam, snooze, draft,
-- expunge, delete_forever, folder_create, folder_rename, folder_delete.
CREATE OR REPLACE FUNCTION ml_mutation_refusal(p_mailbox bigint, p_member bigint, p_on_behalf bigint, p_folder bigint, p_op text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE lvl integer; b mailboxes%ROWTYPE; su text;
BEGIN
    IF p_op NOT IN ('seen', 'unseen', 'flag', 'unflag', 'label', 'move', 'copy', 'archive', 'trash', 'spam', 'snooze', 'draft', 'expunge',
                    'delete_forever', 'folder_create', 'folder_rename', 'folder_delete') THEN
        RETURN 'That is not something MaluMail does to a mailbox.';
    END IF;
    lvl := ml_level_rank(ml_level_as(p_mailbox, p_member, p_on_behalf));
    IF lvl = 0 THEN RETURN 'No such mailbox.'; END IF;
    IF lvl < 2 THEN RETURN 'Viewing a mailbox never changes it.'; END IF;
    SELECT * INTO b FROM mailboxes WHERE id = p_mailbox;
    IF b.suspended_at IS NOT NULL THEN RETURN 'This mailbox is suspended.'; END IF;
    IF p_folder IS NOT NULL THEN
        SELECT special_use INTO su FROM mail_folders WHERE id = p_folder AND mailbox_id = p_mailbox;
        IF NOT FOUND THEN RETURN 'No such folder in this mailbox.'; END IF;
    END IF;
    IF b.intake_owner <> 'none' THEN
        IF p_op = 'seen' THEN RETURN 'This mailbox is another reader''s intake: MaluMail never marks its mail read.'; END IF;
        IF su = 'inbox' AND p_op IN ('move', 'archive', 'trash', 'spam', 'snooze', 'expunge', 'delete_forever') THEN
            RETURN 'This mailbox is another reader''s intake: its inbox stays as it is.';
        END IF;
    END IF;
    IF p_op IN ('folder_rename', 'folder_delete') AND su IS NOT NULL THEN
        RETURN 'A mailbox''s system folders keep their names.';
    END IF;
    RETURN NULL;
END$$;

-- Opening a message: what marks it read. 'store_seen' — the mail service STOREs \Seen (the owner, a triage holder, never an intake
-- mailbox); 'viewer_read' — mail_viewer_reads for this person only (a view holder, every intake mailbox); 'none' — nothing.
CREATE OR REPLACE FUNCTION ml_read_mode(p_mailbox bigint, p_member bigint, p_on_behalf bigint DEFAULT NULL) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE lvl integer;
BEGIN
    lvl := ml_level_rank(ml_level_as(p_mailbox, p_member, p_on_behalf));
    IF lvl = 0 THEN RETURN 'none'; END IF;
    IF lvl = 1 OR EXISTS (SELECT 1 FROM mailboxes WHERE id = p_mailbox AND (intake_owner <> 'none' OR suspended_at IS NOT NULL)) THEN RETURN 'viewer_read'; END IF;
    RETURN 'store_seen';
END$$;

-- ------------------------------------------------------------------------------------------------- search (Gmail's operators, D5)
-- The query as terms: [{neg, op, val}] — op NULL for a word, 'phrase' for a quoted phrase.
CREATE OR REPLACE FUNCTION ml_search_parse(p_q text) RETURNS jsonb
    LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE r text[]; out jsonb := '[]'::jsonb; v text; op text;
BEGIN
    FOR r IN SELECT regexp_matches(COALESCE(p_q, ''), '(-?)(?:([a-z_]+):)?("[^"]*"|\S+)', 'g') LOOP
        op := NULLIF(r[2], '');
        v := r[3];
        IF left(v, 1) = '"' AND right(v, 1) = '"' AND length(v) >= 2 THEN
            v := substr(v, 2, length(v) - 2);
            IF op IS NULL THEN op := 'phrase'; END IF;
        END IF;
        CONTINUE WHEN btrim(v) = '' OR upper(v) = 'OR' AND op IS NULL;
        out := out || jsonb_build_object('neg', r[1] = '-', 'op', op, 'val', v);
    END LOOP;
    RETURN out;
END$$;

CREATE OR REPLACE FUNCTION ml_like(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$ SELECT '%' || replace(replace(replace(p, '\', '\\'), '%', '\%'), '_', '\_') || '%'; $$;

CREATE OR REPLACE FUNCTION ml_search_interval(p text) RETURNS interval
    LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE n integer := NULLIF(substring(p FROM '^(\d{1,5})'), '')::integer; u text := lower(substring(p FROM '([dmy])$'));
BEGIN
    IF n IS NULL OR u IS NULL THEN RAISE EXCEPTION 'A period is a number and d, m or y (7d, 3m, 1y)' USING ERRCODE = 'invalid_parameter_value'; END IF;
    RETURN CASE u WHEN 'd' THEN make_interval(days => n) WHEN 'm' THEN make_interval(months => n) ELSE make_interval(years => n) END;
END$$;

CREATE OR REPLACE FUNCTION ml_search_bytes(p text) RETURNS bigint
    LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE n numeric := NULLIF(substring(p FROM '^(\d+(?:\.\d+)?)'), '')::numeric; u text := upper(substring(p FROM '([KkMm])[Bb]?$'));
BEGIN
    IF n IS NULL THEN RAISE EXCEPTION 'A size is a number with K or M (500K, 2M)' USING ERRCODE = 'invalid_parameter_value'; END IF;
    RETURN (n * CASE u WHEN 'K' THEN 1024 WHEN 'M' THEN 1048576 ELSE 1 END)::bigint;
END$$;

-- Search the caller's mailboxes (or one of them). Operators: from: to: cc: bcc: subject: label: filename: has:attachment
-- is:unread|read|starred|flagged|answered in:inbox|sent|drafts|trash|spam|archive|snoozed|anywhere after: before: (YYYY-MM-DD or
-- YYYY/MM/DD) older_than: newer_than: (7d 3m 1y) larger: smaller: (500K 2M); a quoted phrase; a word; '-' before any of them.
-- Trash and spam are left out unless in: names them or anywhere. An agent never finds a conversation marked sensitive.
CREATE OR REPLACE FUNCTION ml_search(p_q text, p_mailbox bigint DEFAULT NULL, p_limit integer DEFAULT 50, p_offset integer DEFAULT 0)
    RETURNS TABLE (message_id bigint, thread_id bigint, mailbox_id bigint, received_at timestamptz, rank real)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE terms jsonb := ml_search_parse(p_q); t jsonb; c text; conds text[] := '{}'; v text; op text; any_in boolean := false;
        ts text := NULL; ids bigint[]; is_agent boolean := app_member_kind() = 'agent'; d date;
BEGIN
    ids := ml_my_mailbox_ids('view');
    IF p_mailbox IS NOT NULL THEN ids := ARRAY(SELECT x FROM unnest(ids) x WHERE x = p_mailbox); END IF;
    IF cardinality(ids) = 0 THEN RETURN; END IF;
    FOR t IN SELECT * FROM jsonb_array_elements(terms) LOOP
        op := t->>'op'; v := t->>'val'; c := NULL;
        IF op IS NULL THEN
            c := format('m.tsv @@ plainto_tsquery(''ml_mail'', %L)', v);
            IF NOT (t->>'neg')::boolean THEN ts := concat_ws(' ', ts, v); END IF;
        ELSIF op = 'phrase' THEN
            c := format('m.tsv @@ phraseto_tsquery(''ml_mail'', %L)', v);
            IF NOT (t->>'neg')::boolean THEN ts := concat_ws(' ', ts, v); END IF;
        ELSIF op = 'from' THEN c := format('(m.from_address::text ILIKE %1$L OR m.from_name ILIKE %1$L)', ml_like(v));
        ELSIF op IN ('to', 'cc', 'bcc') THEN
            c := format('EXISTS (SELECT 1 FROM unnest(m.%I) a WHERE a ILIKE %L OR (m.recipient_names->>a) ILIKE %L)', op || '_addresses', ml_like(v), ml_like(v));
        ELSIF op = 'subject' THEN c := format('m.subject ILIKE %L', ml_like(v));
        ELSIF op = 'filename' THEN c := format('EXISTS (SELECT 1 FROM unnest(m.attachment_names) a WHERE a ILIKE %L)', ml_like(v));
        ELSIF op = 'label' THEN
            c := format('EXISTS (SELECT 1 FROM record_tags rt JOIN tags g ON g.id = rt.tag_id WHERE rt.record_type = ''mail_message'' AND rt.record_id = m.message_key_id AND g.mailbox_id = m.mailbox_id AND g.slug = %L)', lower(v));
        ELSIF op = 'has' AND lower(v) IN ('attachment', 'attachments') THEN c := 'm.has_attachments';
        ELSIF op = 'is' THEN
            c := CASE lower(v) WHEN 'unread' THEN 'NOT m.seen' WHEN 'read' THEN 'm.seen' WHEN 'starred' THEN 'm.flagged' WHEN 'flagged' THEN 'm.flagged'
                               WHEN 'answered' THEN 'm.answered' END;
        ELSIF op = 'in' THEN
            any_in := true;
            c := CASE lower(v) WHEN 'anywhere' THEN 'true' WHEN 'spam' THEN 'f.special_use = ''junk'''
                 ELSE format('(f.special_use = %1$L OR lower(f.name) = %1$L)', lower(v)) END;
        ELSIF op IN ('after', 'before') THEN
            d := to_date(replace(v, '/', '-'), 'YYYY-MM-DD');
            c := format('COALESCE(m.sent_at, m.received_at) %s %L::date', CASE op WHEN 'after' THEN '>=' ELSE '<' END, d);
        ELSIF op = 'older_than' THEN c := format('m.received_at < now() - %L::interval', ml_search_interval(v));
        ELSIF op = 'newer_than' THEN c := format('m.received_at >= now() - %L::interval', ml_search_interval(v));
        ELSIF op = 'larger' THEN c := format('m.size > %s', ml_search_bytes(v));
        ELSIF op = 'smaller' THEN c := format('m.size < %s', ml_search_bytes(v));
        ELSE
            -- an unknown operator is searched for as words (Gmail does the same)
            c := format('m.tsv @@ plainto_tsquery(''ml_mail'', %L)', op || ' ' || v);
        END IF;
        IF c IS NULL THEN CONTINUE; END IF;
        conds := conds || CASE WHEN (t->>'neg')::boolean THEN 'NOT (' || c || ')' ELSE c END;
    END LOOP;
    IF NOT any_in THEN conds := conds || 'COALESCE(f.special_use, '''') NOT IN (''trash'', ''junk'')'::text; END IF;
    IF is_agent THEN
        conds := conds || 'NOT COALESCE((SELECT s.sensitive FROM ml_thread_state(m.thread_id) s), false)'::text;
    END IF;
    RETURN QUERY EXECUTE format(
        'SELECT m.id, m.thread_id, m.mailbox_id, m.received_at, %s::real
           FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
          WHERE m.mailbox_id = ANY ($1) %s
          ORDER BY m.received_at DESC, m.id DESC LIMIT $2 OFFSET $3',
        CASE WHEN ts IS NULL THEN '0' ELSE format('ts_rank(m.tsv, plainto_tsquery(''ml_mail'', %L))', ts) END,
        CASE WHEN cardinality(conds) = 0 THEN '' ELSE 'AND ' || array_to_string(conds, ' AND ') END)
    USING ids, LEAST(GREATEST(p_limit, 1), 500), GREATEST(p_offset, 0);
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON mail_message_keys, mail_folders, mail_threads, mail_messages, mail_thread_states, mail_viewer_reads,
    mail_correspondents TO malumail_rw;
REVOKE ALL ON FUNCTION ml_messages_before(), ml_messages_after(), ml_thread_states_guard(), ml_correspondents_guard(),
    ml_threads_rebuild(bigint), ml_thread_refresh(bigint), ml_message_key_for(bigint, text, text),
    ml_correspondent_received(bigint, text, text, timestamptz), ml_correspondent_sent(bigint, text, text, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_threads_rebuild(bigint), ml_thread_refresh(bigint), ml_message_key_for(bigint, text, text),
    ml_correspondent_received(bigint, text, text, timestamptz), ml_correspondent_sent(bigint, text, text, bigint) TO malumail_rw;
GRANT EXECUTE ON FUNCTION ml_envelope_digest(text, text, text, text, text), ml_is_automated(text, boolean, boolean), ml_subject_norm(text),
    ml_participants(bigint, text, text[], text[]), ml_thread_find(bigint, bigint, text, text[], text, timestamptz, text[]),
    ml_thread_state(bigint), ml_key_thread(bigint), ml_is_known(bigint, text), ml_mutation_refusal(bigint, bigint, bigint, bigint, text),
    ml_read_mode(bigint, bigint, bigint), ml_search_parse(text), ml_like(text), ml_search_interval(text), ml_search_bytes(text),
    ml_search(text, bigint, integer, integer)
TO malumail_rw, malumail_records_ro;

COMMIT;
