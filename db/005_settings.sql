-- 005: the application's SETTINGS (one row) and the KEY rules that read them (design §6.5; D6, D7, D8, D9, D10).
--
-- THE ESTATE'S DATA MODEL (design §6.7): ml_settings is the siblings' one-row `app_settings` skeleton (Help Desk db/005: id smallint
-- = 1, updated_at) with the kit's generic columns (business name, time zone, the key rates) and THIS application's columns: the
-- undo-send default (D8), the images default (D7), the index's text cap (D4), the browser's poll (D6), a message's attachments (D8),
-- the held-mail expiry and THE AGENT POLICY DEFAULTS a new agent mailbox's agent_mail_policies row is made from (D10, D11), THE
-- AGENTS' BUSINESS-WIDE EXTERNAL BUDGET and its warm-up (design §5), THE MAIL ADMINS' TWO BUSINESS-WIDE SWITCHES, the disclosure
-- footer's words, THE MONITORING NOTICE (text and version; every person acknowledges a version once, ml_notice_acknowledgements,
-- db/006+), and the Clerk's hour. The key functions are Knowledge db/011's = Sales CRM db/005's = the Data Lake db/005's = Boards
-- db/005's (ml_resolve_key, ml_rate_ok, ml_key_calls_today, ml_key_rotate, ml_prune_key_usage) — here because they read the settings.
--
-- WHO CHANGES THEM: a Mail admin (settings.manage — a super-admin, D2) on the admin screen; never an agent (refused, design §5). An
-- overseer sets ONE agent's policy (agent_mail_policies, db/006+) within these and can never raise the business-wide budget.
-- FIXED RULES ARE NOT SETTINGS: break-glass at most 30 days, the same answer within seven days, RFC 3834's loop rules (design §3, §5).
BEGIN;

CREATE TABLE ml_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    business_name               text,
    timezone                    text NOT NULL DEFAULT 'UTC',
    -- reading and writing (D4, D6, D7, D8)
    undo_send_seconds           smallint NOT NULL DEFAULT 10  CHECK (undo_send_seconds BETWEEN 0 AND 30),       -- a person's default; each may choose 0–30
    images_default              text NOT NULL DEFAULT 'ask' CHECK (images_default IN ('ask', 'always')),        -- remote images: held until asked (D7)
    index_text_max_kb           integer NOT NULL DEFAULT 100 CHECK (index_text_max_kb BETWEEN 10 AND 1024),     -- the text of a message kept in the index (D4)
    max_attachments_mb          smallint NOT NULL DEFAULT 7 CHECK (max_attachments_mb BETWEEN 1 AND 9),        -- a message's attachments; MaluMail's limit is ~9.8 MiB encoded (D8)
    draft_save_seconds          smallint NOT NULL DEFAULT 10 CHECK (draft_save_seconds BETWEEN 5 AND 120),      -- the composer saves to the Drafts folder (D8)
    poll_visible_seconds        smallint NOT NULL DEFAULT 15 CHECK (poll_visible_seconds BETWEEN 5 AND 300),    -- the browser's "anything new?" (D6)
    poll_hidden_seconds         smallint NOT NULL DEFAULT 60 CHECK (poll_hidden_seconds BETWEEN 15 AND 900),
    -- held mail (D10)
    held_expiry_hours           smallint NOT NULL DEFAULT 72 CHECK (held_expiry_hours BETWEEN 1 AND 720),       -- then `expired`, and the agent is told
    -- a new agent mailbox's policy (D10, D11; design §5 — an overseer changes one agent's, within the budget below)
    default_send_mode           text NOT NULL DEFAULT 'hold_all'
                                CHECK (default_send_mode IN ('hold_all', 'internal_auto', 'allowlist_auto', 'auto')),
    default_first_contact_review boolean NOT NULL DEFAULT true,
    default_attachments_review  boolean NOT NULL DEFAULT true,
    default_max_external_per_day smallint NOT NULL DEFAULT 20 CHECK (default_max_external_per_day BETWEEN 0 AND 10000),
    default_max_per_hour        smallint NOT NULL DEFAULT 50 CHECK (default_max_per_hour BETWEEN 0 AND 10000),
    default_max_per_thread_per_day smallint NOT NULL DEFAULT 5 CHECK (default_max_per_thread_per_day BETWEEN 1 AND 1000),
    default_wake_on_mail        boolean NOT NULL DEFAULT true,
    default_wake_from           text NOT NULL DEFAULT 'internal_and_known'
                                CHECK (default_wake_from IN ('none', 'internal', 'internal_and_known', 'all_authenticated')),
    default_disclosure          boolean NOT NULL DEFAULT true,
    disclosure_text             text NOT NULL DEFAULT 'Sent by {agent}, an AI agent of {business}.'
                                CHECK (length(disclosure_text) BETWEEN 10 AND 500 AND disclosure_text LIKE '%{agent}%'),
    -- the agents' business-wide external budget (design §5): NULL = the warm-up computes it — weeks 1–4 at 20, 50, 100, 200 a day
    -- from the first agent send, then up half a week while bounces stay under 2 %; a number = the Mail admins' own cap, every agent's
    -- outside mail together. Overseers cannot raise it.
    agent_external_budget       integer CHECK (agent_external_budget IS NULL OR agent_external_budget BETWEEN 0 AND 100000),
    agent_budget_started_at     timestamptz,                                                                   -- the first agent send; set once by the outbox
    -- the Mail admins' two business-wide switches (design §5): every agent's mail held / every agent's outside mail held
    hold_all_agents             boolean NOT NULL DEFAULT false,
    hold_all_external           boolean NOT NULL DEFAULT false,
    -- the monitoring notice (design §3): plain words, versioned; a new version is acknowledged again by everyone
    notice_text                 text NOT NULL DEFAULT 'How mail is looked after here. Agents'' mailboxes are the business''s: the people who oversee an agent read everything it receives and sends, and its outgoing mail can wait for their review. Your own mailbox is yours: someone else opens it only if you share it with them, or — for a stated reason, for at most 30 days, and with you told at once — through an emergency grant by a super-admin. Every look at a mailbox by someone other than its owner is logged, and the owner can read that log.'
                                CHECK (length(notice_text) BETWEEN 50 AND 10000),
    notice_version              integer NOT NULL DEFAULT 1 CHECK (notice_version >= 1),
    notice_updated_at           timestamptz NOT NULL DEFAULT now(),
    -- the Mail Clerk's morning note (design §5 (c); the duty's schedule is the kernel's, this is the hour its inputs are cut at)
    clerk_note_at               time NOT NULL DEFAULT '07:10',
    -- keys (the kit's; db/003)
    key_rate_per_minute         integer NOT NULL DEFAULT 60 CHECK (key_rate_per_minute BETWEEN 1 AND 100000),
    key_rate_per_day            integer NOT NULL DEFAULT 5000 CHECK (key_rate_per_day BETWEEN 1 AND 10000000),
    key_rotation_overlap_hours  smallint NOT NULL DEFAULT 24 CHECK (key_rotation_overlap_hours BETWEEN 0 AND 168),
    storage_dir                 text,                                                                          -- NULL = ML_STORAGE_DIR from the environment (a draft's attachments)
    updated_at                  timestamptz NOT NULL DEFAULT now(),
    CHECK (poll_visible_seconds <= poll_hidden_seconds),
    CHECK (default_max_external_per_day <= default_max_per_hour * 24)
);
INSERT INTO ml_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER ml_settings_touch BEFORE UPDATE ON ml_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A new notice text is a new version (design §3): every person acknowledges it again. The version never goes back.
CREATE OR REPLACE FUNCTION ml_settings_notice_version() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.notice_text IS DISTINCT FROM OLD.notice_text THEN
        NEW.notice_version := OLD.notice_version + 1;
        NEW.notice_updated_at := now();
    ELSIF NEW.notice_version IS DISTINCT FROM OLD.notice_version THEN
        RAISE EXCEPTION 'The notice''s version follows its text' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER ml_settings_notice BEFORE UPDATE ON ml_settings FOR EACH ROW EXECUTE FUNCTION ml_settings_notice_version();

-- One setting by name, for the rules that read them (never for a column that is not a number / not text).
CREATE OR REPLACE FUNCTION ml_setting_int(p_name text) RETURNS bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v bigint;
BEGIN
    EXECUTE format('SELECT %I::bigint FROM ml_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION ml_setting_text(p_name text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v text;
BEGIN
    EXECUTE format('SELECT %I::text FROM ml_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION ml_setting_bool(p_name text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v boolean;
BEGIN
    EXECUTE format('SELECT %I::boolean FROM ml_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;

-- ---------------------------------------------------------------------------------------------
-- KEYS (db/003): a key's defaults, its resolution, the rate rule, rotation and pruning — Knowledge db/011 minus the base
-- (Sales CRM's and the Data Lake's copies, without the Data Lake's per-key SQL switch).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ml_access_tokens_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.scope = 'api' THEN
        NEW.rate_per_minute := COALESCE(NEW.rate_per_minute, ml_setting_int('key_rate_per_minute')::integer);
        NEW.rate_per_day := COALESCE(NEW.rate_per_day, ml_setting_int('key_rate_per_day')::integer);
        IF NEW.rotated_from IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mcp_access_tokens o WHERE o.id = NEW.rotated_from AND o.member_id = NEW.member_id AND o.scope = 'api') THEN
            RAISE EXCEPTION 'A key is rotated from a key of the same person' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mcp_access_tokens_before BEFORE INSERT OR UPDATE ON mcp_access_tokens FOR EACH ROW EXECUTE FUNCTION ml_access_tokens_before();

-- Resolve a key by its hash: the token, its person, its label and its limits, with last_used_at touched. Nothing for a
-- revoked, expired or mcp-scope token, or one whose person is no longer admitted (a revoked grant shuts this door too).
CREATE OR REPLACE FUNCTION ml_resolve_key(p_hash text)
    RETURNS TABLE (token_id bigint, member_id bigint, label text, rate_per_minute integer, rate_per_day integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE mcp_access_tokens t SET last_used_at = now()
      FROM members m
     WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
       AND m.id = t.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT t.id, t.member_id, t.label, t.rate_per_minute, t.rate_per_day
          FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id
         WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND m.status = 'active' AND m.capability IS NOT NULL;
END$$;

-- Count one call. ok = the call may proceed; when not, limit_hit says which bucket and retry_after how many seconds until it
-- opens. The minute bucket is charged first; a call the minute refuses is not charged to the day (so a burst never eats the
-- day); a call the day refuses stays counted in the minute (it was made). `refused` is kept per bucket for the keys screen.
CREATE OR REPLACE FUNCTION ml_rate_ok(p_token_id bigint, p_at timestamptz DEFAULT now())
    RETURNS TABLE (ok boolean, limit_hit text, retry_after integer, minute_calls integer, day_calls integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t mcp_access_tokens%ROWTYPE; m_start timestamptz; d_start timestamptz; m_calls integer; d_calls integer;
BEGIN
    SELECT * INTO t FROM mcp_access_tokens WHERE id = p_token_id AND scope = 'api' FOR UPDATE;   -- serialises the two upserts per key
    IF NOT FOUND THEN
        RETURN QUERY SELECT false, 'no_key'::text, 0, 0, 0; RETURN;
    END IF;
    IF t.revoked_at IS NOT NULL OR (t.expires_at IS NOT NULL AND t.expires_at <= p_at) THEN
        RETURN QUERY SELECT false, 'revoked'::text, 0, 0, 0; RETURN;
    END IF;
    m_start := date_trunc('minute', p_at);
    d_start := date_trunc('day', p_at);
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'minute', m_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO m_calls;
    IF m_calls > t.rate_per_minute THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'minute' AND bucket_start = m_start;
        SELECT COALESCE(calls, 0) INTO d_calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'minute'::text, GREATEST(1, ceil(extract(epoch FROM (m_start + interval '1 minute' - p_at)))::integer), m_calls - 1, COALESCE(d_calls, 0);
        RETURN;
    END IF;
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'day', d_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO d_calls;
    IF d_calls > t.rate_per_day THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'day'::text, GREATEST(1, ceil(extract(epoch FROM (d_start + interval '1 day' - p_at)))::integer), m_calls, d_calls - 1;
        RETURN;
    END IF;
    RETURN QUERY SELECT true, NULL::text, 0, m_calls, d_calls;
END$$;

-- Today's count for a key (the keys screen, find_keys).
CREATE OR REPLACE FUNCTION ml_key_calls_today(p_token_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = date_trunc('day', now())), 0);
$$;

-- Rotate: a new key for the same person, label and limits; the old one expires after the overlap (24 h) and points nowhere —
-- the new one says `rotated_from`. Returns the new row's id; the raw key is the caller's (PHP mints the 48 hex, hashes them, and
-- passes the hash in).
CREATE OR REPLACE FUNCTION ml_key_rotate(p_old_token_id bigint, p_new_hash text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o mcp_access_tokens%ROWTYPE; nid bigint; overlap integer;
BEGIN
    SELECT * INTO o FROM mcp_access_tokens WHERE id = p_old_token_id AND scope = 'api' FOR UPDATE;
    IF NOT FOUND OR o.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'Only a live key is rotated' USING ERRCODE = 'check_violation'; END IF;
    overlap := ml_setting_int('key_rotation_overlap_hours')::integer;
    INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day, rotated_from)
    VALUES (o.member_id, o.label, p_new_hash, 'api', o.rate_per_minute, o.rate_per_day, o.id)
    RETURNING id INTO nid;
    UPDATE mcp_access_tokens SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(hours => overlap)) WHERE id = o.id;
    RETURN nid;
END$$;

-- The worker prunes buckets once a minute: minute buckets older than a day, day buckets older than 35 days.
CREATE OR REPLACE FUNCTION ml_prune_key_usage() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM key_usage WHERE (bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days');
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

GRANT SELECT, UPDATE ON ml_settings TO malumail_rw;
GRANT EXECUTE ON FUNCTION ml_setting_int(text), ml_setting_text(text), ml_setting_bool(text), ml_key_calls_today(bigint) TO malumail_rw, malumail_records_ro, malumail_activity_ro;
REVOKE ALL ON FUNCTION ml_resolve_key(text), ml_rate_ok(bigint, timestamptz), ml_key_rotate(bigint, text), ml_prune_key_usage() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_resolve_key(text), ml_rate_ok(bigint, timestamptz), ml_key_rotate(bigint, text), ml_prune_key_usage() TO malumail_rw;

COMMIT;
