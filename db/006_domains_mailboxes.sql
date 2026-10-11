-- 006: DOMAINS, MAILBOXES, their CREDENTIALS, who may open them (PERMISSIONS), the addresses they send as (IDENTITIES), an agent
-- mailbox's POLICY, the notice's ACKNOWLEDGEMENTS — and THE WALL, ml_mailbox_level(), which every view and handler asks (design §3,
-- §5, §6.2; D2, D3, D9, D10, D12, D13).
--
-- THE ESTATE'S DATA MODEL (design §6.7):
--   * `mailboxes` is Help Desk db/012 VERBATIM (queue_id kept nullable without its reference — the Sales CRM's precedent) with the
--     CRM's appended columns (db/011: last_webhook_at, last_error, last_error_at), then MaluMail's (§6.2).
--   * `mailbox_credentials` is Inventory db/009 `source_credentials` (source_id kept nullable without its reference; `kind` ⊂ ours),
--     appending mailbox_id, status, last_ok_at, failed_at, failure. Here the ciphertext is a libsodium SEALED BOX under the mail
--     service's public key — PHP seals, only the mail service opens (§6.1) — and `last4` is the last four of the sealed box's SHA-256,
--     never of the password.
--   * `mailbox_permissions` is Spaces db/007 `page_permissions` (page_id → mailbox_id; principal kinds ⊂ member/department/agent;
--     levels ⊂ view/triage/send/full), appending reason, expires_at, break_glass and an id.
--   * NEW CANONICAL: `ml_domains`, `ml_aliases`, `mail_identities`, `agent_mail_policies`, `ml_notice_acknowledgements`.
--     `ml_aliases` is the one table §6.7 did not list: the internal test of §5 ("an alias whose every destination is internal")
--     needs the aliases' destinations where SQL can read them — a cache the mail service's admin door writes on every list,
--     create, update and delete at MaluMail, never the record.
--
-- THE RULES IN SQL (design §3 "In SQL, not in PHP or Python"):
--   * ml_mailbox_level(mailbox, member) — one PL/pgSQL query: the owner full; on an agent's mailbox every Mail admin full; a member
--     row for that PERSON, an agent row for that AGENT, a department row for the PEOPLE of the department only; an agent row on a
--     person's mailbox at most `send`; an expired row nothing; no right to use MaluMail, nothing.
--   * ml_level_as(mailbox, member, on_behalf) — the same, plus the Mail Expert's one other way in: in a run a PERSON started
--     (K38's requested_by, set by the records MCP as app.on_behalf_of), at that person's level capped at `triage`, only where
--     `ai_read` is on, and on a person's mailbox only in its OWNER's run.
--   * the permission guard: only a person grants; on a person's mailbox only its owner — anyone else's row is BREAK-GLASS (a Mail
--     admin's, for a person, with a reason and an expiry within 30 days, the owner told at once: db/010); an agent row on a person's
--     mailbox at most `send`; a department row admits no agent (in the level, above).
BEGIN;

-- The Mail Expert and the Mail Clerk are NAMED, never guessed (DocCloud db/022's lesson): the installer's hire step and the admin's
-- agents screen write them. NULL = not hired; every test below is NULL-safe.
ALTER TABLE ml_settings
    ADD COLUMN expert_member_id bigint,
    ADD COLUMN clerk_member_id bigint,
    -- MM3: once MaluMail refuses a submission whose From is not the login's own (the infrastructure side's item), an authenticated
    -- message from a served internal domain proves an internal sender. Until then only mail THIS application sent counts (§5).
    ADD COLUMN mm3_identity_enforced boolean NOT NULL DEFAULT false;

-- Levels as numbers, for GREATEST/LEAST.
CREATE OR REPLACE FUNCTION ml_level_rank(p text) RETURNS integer
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p WHEN 'view' THEN 1 WHEN 'triage' THEN 2 WHEN 'send' THEN 3 WHEN 'full' THEN 4 ELSE 0 END;
$$;
CREATE OR REPLACE FUNCTION ml_level_name(p integer) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN p >= 4 THEN 'full' WHEN p = 3 THEN 'send' WHEN p = 2 THEN 'triage' WHEN p = 1 THEN 'view' ELSE 'none' END;
$$;
CREATE OR REPLACE FUNCTION ml_address_domain(p text) RETURNS citext
    LANGUAGE sql IMMUTABLE AS $$
    SELECT NULLIF(lower(split_part(btrim(p), '@', 2)), '')::citext;
$$;

-- ---------------------------------------------------------------------------------------------------------------------------------
-- The domains this installation SERVES (the customer key reaches every domain of the MaluMail customer; the application shows and
-- administers only these — the wall of §6.1) and which of them are INTERNAL for §5.
-- ---------------------------------------------------------------------------------------------------------------------------------
CREATE TABLE ml_domains (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    domain        citext NOT NULL UNIQUE CHECK (domain ~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$'),
    served        boolean NOT NULL DEFAULT true,
    internal      boolean NOT NULL DEFAULT true,          -- its mailboxes (and aliases forwarding only inside) are colleagues
    spf_ok        boolean,                                -- MaluMail's flags as last read
    dkim_ok       boolean,
    dmarc_ok      boolean,
    flags_read_at timestamptz,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK (served OR NOT internal)
);
CREATE TRIGGER ml_domains_touch BEFORE UPDATE ON ml_domains FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ---------------------------------------------------------------------------------------------------------------------------------
-- mailboxes — Help Desk db/012 verbatim + the CRM's appended columns + MaluMail's (§6.2).
-- ---------------------------------------------------------------------------------------------------------------------------------
CREATE TABLE mailboxes (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    address            citext NOT NULL UNIQUE,
    display_name       text NOT NULL,
    queue_id           bigint,                                       -- Help Desk's column, kept for the verbatim rule; unused here
    provider           text NOT NULL DEFAULT 'malumail' CHECK (provider IN ('malumail', 'imap')),
    webhook_secret_ref text,                                         -- Help Desk's; unused (MaluMail takes no webhook here)
    signature          text,                                         -- Help Desk's; a mailbox's signatures are mail_identities' here
    poll_enabled       boolean NOT NULL DEFAULT true,
    last_polled_at     timestamptz,
    last_uid           bigint,                                       -- Help Desk's; unused (the index keeps folders' UIDs)
    active             boolean NOT NULL DEFAULT true,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    -- appended by the Sales CRM (db/011)
    last_webhook_at    timestamptz,
    last_error         text,
    last_error_at      timestamptz,
    -- appended (design §6.2)
    kind               text NOT NULL CHECK (kind IN ('person', 'agent', 'shared')),
    owner_member_id    bigint REFERENCES members(id) ON DELETE RESTRICT,
    credential_id      bigint,                                       -- FK below (the credential names its mailbox too)
    quota_bytes        bigint CHECK (quota_bytes IS NULL OR quota_bytes > 0),
    quota_used_bytes   bigint CHECK (quota_used_bytes IS NULL OR quota_used_bytes >= 0),
    sync_state         text NOT NULL DEFAULT 'new' CHECK (sync_state IN ('new', 'syncing', 'live', 'paused', 'failed')),
    last_sync_at       timestamptz,
    intake_owner       text NOT NULL DEFAULT 'none' CHECK (intake_owner IN ('none', 'kernel', 'helpdesk', 'sales_crm', 'other')),
    intake_proposed    text CHECK (intake_proposed IS NULL OR intake_proposed IN ('kernel', 'helpdesk', 'sales_crm', 'other')),  -- from MaluMail's webhook record; a Mail admin confirms
    share_with_apps    boolean NOT NULL,                             -- the K7 share (§4): agent and shared mailboxes by default; a person's only by its owner
    ai_read            boolean NOT NULL,                             -- §3: on for agent and shared, OFF for a person's (the owner switches it)
    primary_overseer_id bigint REFERENCES members(id) ON DELETE SET NULL,   -- an agent mailbox's acting member for its mail turns (§5)
    suspended_at       timestamptz,
    settings           jsonb NOT NULL DEFAULT '{}'::jsonb,           -- conversation view, images, undo seconds, density
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    CHECK ((kind = 'shared') = (owner_member_id IS NULL)),
    CHECK (primary_overseer_id IS NULL OR kind = 'agent'),
    CHECK (address ~ '^[^@\s]+@[^@\s]+$')
);
CREATE TRIGGER mailboxes_touch BEFORE UPDATE ON mailboxes FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE INDEX mailboxes_owner_idx ON mailboxes (owner_member_id) WHERE owner_member_id IS NOT NULL;

-- A mailbox is on a served domain; a person's is owned by a person and an agent's by an agent; the primary overseer is a person;
-- ai_read and the K7 share take their defaults by kind when not given.
CREATE OR REPLACE FUNCTION ml_mailboxes_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE k text;
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.ai_read := COALESCE(NEW.ai_read, NEW.kind <> 'person');
        NEW.share_with_apps := COALESCE(NEW.share_with_apps, NEW.kind <> 'person');
    END IF;
    IF TG_OP = 'INSERT' OR NEW.address IS DISTINCT FROM OLD.address THEN
        IF NOT EXISTS (SELECT 1 FROM ml_domains d WHERE d.domain = ml_address_domain(NEW.address) AND d.served) THEN
            RAISE EXCEPTION 'MaluMail serves no domain % here: a Mail admin adds it first', ml_address_domain(NEW.address) USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.kind IS DISTINCT FROM OLD.kind THEN
        RAISE EXCEPTION 'A mailbox keeps its kind' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.owner_member_id IS NOT NULL THEN
        SELECT member_kind INTO k FROM members WHERE id = NEW.owner_member_id;
        IF k IS DISTINCT FROM (CASE NEW.kind WHEN 'person' THEN 'human' ELSE 'agent' END) THEN
            RAISE EXCEPTION 'A % mailbox belongs to %', NEW.kind, CASE NEW.kind WHEN 'person' THEN 'a person' ELSE 'an agent' END USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF NEW.primary_overseer_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.primary_overseer_id IS DISTINCT FROM OLD.primary_overseer_id) THEN
        IF NOT EXISTS (SELECT 1 FROM members WHERE id = NEW.primary_overseer_id AND member_kind = 'human' AND status = 'active') THEN
            RAISE EXCEPTION 'The primary overseer is an active person' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mailboxes_guard BEFORE INSERT OR UPDATE ON mailboxes FOR EACH ROW EXECUTE FUNCTION ml_mailboxes_guard();

-- ---------------------------------------------------------------------------------------------------------------------------------
-- mailbox_credentials — Inventory's source_credentials, sealed boxes. Readable by the application role (the mail service connects as
-- malumail_rw, D1/§16 decision 4); the records role never sees it; PHP cannot open a box.
-- ---------------------------------------------------------------------------------------------------------------------------------
CREATE TABLE mailbox_credentials (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id   bigint,                                                        -- Inventory's column, kept for the verbatim rule; unused
    kind        text NOT NULL CHECK (kind IN ('app_password', 'mailbox_password')),
    label       text NOT NULL,
    last4       text NOT NULL CHECK (length(last4) BETWEEN 1 AND 4),          -- of the sealed box's SHA-256, never of the secret
    ciphertext  bytea NOT NULL CHECK (octet_length(ciphertext) > 48),         -- crypto_box_seal: 48 bytes of overhead + the secret
    rotated_at  timestamptz,
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.1, §6.2): the circuit breaker's state
    mailbox_id  bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    status      text NOT NULL DEFAULT 'ok' CHECK (status IN ('ok', 'failed', 'revoked')),
    last_ok_at  timestamptz,
    failed_at   timestamptz,
    failure     text,
    CHECK ((status = 'failed') = (failed_at IS NOT NULL))
);
CREATE INDEX mailbox_credentials_mailbox_idx ON mailbox_credentials (mailbox_id, created_at DESC);
CREATE UNIQUE INDEX mailbox_credentials_one_live ON mailbox_credentials (mailbox_id) WHERE status = 'ok';
CREATE TRIGGER mailbox_credentials_touch BEFORE UPDATE ON mailbox_credentials FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE mailboxes ADD CONSTRAINT mailboxes_credential_fk FOREIGN KEY (credential_id) REFERENCES mailbox_credentials(id) ON DELETE SET NULL;

-- A failed credential stays failed until a person stores a new one (the circuit breaker, §6.1): no update brings it back.
CREATE OR REPLACE FUNCTION ml_credentials_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status IN ('failed', 'revoked') AND NEW.status = 'ok' THEN
        RAISE EXCEPTION 'A % credential is never used again: a person stores a new one', OLD.status USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.ciphertext IS DISTINCT FROM OLD.ciphertext THEN
        RAISE EXCEPTION 'A sealed credential is never rewritten: a new one is stored' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mailbox_credentials_guard BEFORE UPDATE ON mailbox_credentials FOR EACH ROW EXECUTE FUNCTION ml_credentials_guard();

-- ---------------------------------------------------------------------------------------------------------------------------------
-- ml_aliases — the cache of MaluMail's forwarding addresses on served domains (the mail service's admin door writes it).
-- ---------------------------------------------------------------------------------------------------------------------------------
CREATE TABLE ml_aliases (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    domain_id     bigint NOT NULL REFERENCES ml_domains(id) ON DELETE CASCADE,
    address       citext NOT NULL UNIQUE CHECK (address ~ '^[^@\s]+@[^@\s]+$'),
    destinations  citext[] NOT NULL CHECK (cardinality(destinations) BETWEEN 1 AND 50),
    active        boolean NOT NULL DEFAULT true,
    synced_at     timestamptz NOT NULL DEFAULT now(),
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER ml_aliases_touch BEFORE UPDATE ON ml_aliases FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE INDEX ml_aliases_destinations_idx ON ml_aliases USING gin (destinations);

-- §5: an address is INTERNAL when it is a mailbox this application serves on an internal domain, or an alias on an internal domain
-- whose EVERY destination is internal (followed through aliases at most five deep; a loop, an unknown address or a dead end is
-- outside). An address on an internal domain that is neither proves nothing.
CREATE OR REPLACE FUNCTION ml_is_internal_address(p_address text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE outside integer; reached integer;
BEGIN
    IF p_address IS NULL OR position('@' IN p_address) = 0 THEN RETURN false; END IF;
    WITH RECURSIVE walk(addr, depth, path) AS (
        SELECT lower(btrim(p_address))::citext, 0, ARRAY[lower(btrim(p_address))]
        UNION ALL
        SELECT lower(d)::citext, w.depth + 1, w.path || lower(d)
          FROM walk w
          JOIN ml_aliases a ON a.address = w.addr AND a.active
          CROSS JOIN LATERAL unnest(a.destinations) d
         WHERE w.depth < 5 AND NOT (lower(d) = ANY (w.path))
    ),
    leaves AS (
        -- an address is settled when it is a served mailbox, or it is not an alias (then it is a dead end)
        SELECT w.addr, w.depth,
               EXISTS (SELECT 1 FROM mailboxes mb JOIN ml_domains dm ON dm.domain = ml_address_domain(mb.address) AND dm.served AND dm.internal
                        WHERE mb.address = w.addr AND mb.active) AS is_mailbox,
               EXISTS (SELECT 1 FROM ml_aliases a JOIN ml_domains dm ON dm.id = a.domain_id AND dm.served AND dm.internal
                        WHERE a.address = w.addr AND a.active) AS is_alias
          FROM walk w
    )
    SELECT count(*) FILTER (WHERE NOT l.is_mailbox AND (NOT l.is_alias OR l.depth >= 5)), count(*) FILTER (WHERE l.is_mailbox)
      INTO outside, reached FROM leaves l;
    -- the start is on an internal domain, at least one mailbox is reached (an alias loop reaches none), and nothing reached is outside
    RETURN outside = 0 AND reached > 0
       AND EXISTS (SELECT 1 FROM ml_domains dm WHERE dm.domain = ml_address_domain(p_address) AND dm.served AND dm.internal);
END$$;

-- ---------------------------------------------------------------------------------------------------------------------------------
-- mailbox_permissions — Spaces' page_permissions for mailboxes (D9).
-- ---------------------------------------------------------------------------------------------------------------------------------
CREATE TABLE mailbox_permissions (
    mailbox_id      bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    principal_kind  text NOT NULL CHECK (principal_kind IN ('member', 'department', 'agent')),
    principal_id    bigint NOT NULL,                                  -- Spaces' column is nullable for its two "everyone" kinds; none here
    level           text NOT NULL CHECK (level IN ('view', 'triage', 'send', 'full')),
    granted_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.2)
    reason          text,
    expires_at      timestamptz,
    break_glass     boolean NOT NULL DEFAULT false,
    id              bigint GENERATED ALWAYS AS IDENTITY UNIQUE,
    -- break-glass has a reason and an expiry within 30 days (design §3)
    CHECK (NOT break_glass OR (reason IS NOT NULL AND length(btrim(reason)) >= 10
                               AND expires_at IS NOT NULL AND expires_at <= created_at + interval '30 days'))
);
CREATE UNIQUE INDEX mailbox_permissions_principal ON mailbox_permissions (mailbox_id, principal_kind, principal_id);
CREATE INDEX mailbox_permissions_lookup ON mailbox_permissions (principal_kind, principal_id, mailbox_id);
CREATE INDEX mailbox_permissions_expiry ON mailbox_permissions (expires_at) WHERE expires_at IS NOT NULL;

-- ---------------------------------------------------------------------------------------------------------------------------------
-- THE WALL.
-- ---------------------------------------------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ml_mailbox_level(p_mailbox bigint, p_member bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r integer;
BEGIN
    SELECT max(x.rk) INTO r FROM (
        -- the owner
        SELECT 4 AS rk FROM mailboxes b WHERE b.id = p_mailbox AND b.owner_member_id = p_member
        UNION ALL
        -- on an agent's mailbox, every Mail admin (a super-admin, D2)
        SELECT 4 FROM mailboxes b WHERE b.id = p_mailbox AND b.kind = 'agent' AND ml_member_has_right(p_member, 'mailboxes.manage')
        UNION ALL
        -- the rows: a member row for that person, an agent row for that agent (on a person's mailbox at most send), a department row
        -- for the department's PEOPLE only; an expired row is nothing
        SELECT CASE WHEN p.principal_kind = 'agent' AND b.kind = 'person' THEN LEAST(ml_level_rank(p.level), 3) ELSE ml_level_rank(p.level) END
          FROM mailbox_permissions p
          JOIN mailboxes b ON b.id = p.mailbox_id
          JOIN members m ON m.id = p_member
         WHERE p.mailbox_id = p_mailbox
           AND (p.expires_at IS NULL OR p.expires_at > now())
           AND ((p.principal_kind = 'member' AND p.principal_id = p_member AND m.member_kind = 'human')
             OR (p.principal_kind = 'agent' AND p.principal_id = p_member AND m.member_kind = 'agent')
             OR (p.principal_kind = 'department' AND m.member_kind = 'human'
                 AND EXISTS (SELECT 1 FROM department_members dm JOIN departments d ON d.id = dm.department_id AND d.archived_at IS NULL
                              WHERE dm.department_id = p.principal_id AND dm.member_id = p_member AND dm.left_at IS NULL)))
    ) x
    WHERE ml_member_has_right(p_member, 'mail.use');                 -- active, admitted, with a role
    RETURN ml_level_name(COALESCE(r, 0));
END$$;

-- The level of a caller who may be the Mail Expert acting in a person's run (§3): the base level, or — for the NAMED Expert, in a run
-- an active person with MaluMail started, where ai_read is on — that person's own level capped at triage; on a person's mailbox only
-- when the person is its owner.
CREATE OR REPLACE FUNCTION ml_level_as(p_mailbox bigint, p_member bigint, p_on_behalf bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE base integer; via integer := 0; b mailboxes%ROWTYPE;
BEGIN
    base := ml_level_rank(ml_mailbox_level(p_mailbox, p_member));
    IF p_on_behalf IS NOT NULL AND p_on_behalf IS DISTINCT FROM p_member
       AND p_member = (SELECT s.expert_member_id FROM ml_settings s WHERE s.id = 1)
       AND EXISTS (SELECT 1 FROM members m WHERE m.id = p_member AND m.member_kind = 'agent')
       AND EXISTS (SELECT 1 FROM members m WHERE m.id = p_on_behalf AND m.member_kind = 'human' AND m.status = 'active') THEN
        SELECT * INTO b FROM mailboxes WHERE id = p_mailbox;
        IF FOUND AND b.ai_read AND (b.kind <> 'person' OR b.owner_member_id = p_on_behalf) THEN
            via := LEAST(ml_level_rank(ml_mailbox_level(p_mailbox, p_on_behalf)), 2);
        END IF;
    END IF;
    RETURN ml_level_name(GREATEST(base, via));
END$$;

-- The caller's level: the acting member, and the person behind an agent's run when the records MCP set it.
CREATE OR REPLACE FUNCTION ml_level(p_mailbox bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN ml_level_as(p_mailbox, app_current_member_id(), NULLIF(current_setting('app.on_behalf_of', true), '')::bigint);
END$$;

-- The mailboxes the caller reaches at a level or above — ONE set per statement (mailboxes are few; the views test membership in it).
CREATE OR REPLACE FUNCTION ml_my_mailbox_ids(p_min text DEFAULT 'view') RETURNS bigint[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE ids bigint[];
BEGIN
    SELECT COALESCE(array_agg(b.id), '{}') INTO ids FROM mailboxes b
     WHERE ml_level_rank(ml_level(b.id)) >= ml_level_rank(p_min);
    RETURN ids;
END$$;

-- Is this member an OVERSEER-like decider on the mailbox: a PERSON with full on it — and on a person's mailbox, only its owner.
CREATE OR REPLACE FUNCTION ml_is_decider(p_mailbox bigint, p_member bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM mailboxes b JOIN members m ON m.id = p_member AND m.member_kind = 'human' AND m.status = 'active'
                    WHERE b.id = p_mailbox
                      AND ml_mailbox_level(p_mailbox, p_member) = 'full'
                      AND (b.kind <> 'person' OR b.owner_member_id = p_member));
END$$;

-- Refuse an agent a decision people make (§3) — raised the same way everywhere, in the kit's sentence.
CREATE OR REPLACE FUNCTION ml_require_person(p_member bigint) RETURNS void
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_member IS NULL OR NOT EXISTS (SELECT 1 FROM members WHERE id = p_member AND member_kind = 'human' AND status = 'active') THEN
        RAISE EXCEPTION 'That is a person''s decision: an agent never decides it.' USING ERRCODE = 'insufficient_privilege';
    END IF;
END$$;

-- The permission guard (design §3, §6.2): who may grant, what a row may be, and break-glass.
CREATE OR REPLACE FUNCTION ml_permissions_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b mailboxes%ROWTYPE; pk text;
BEGIN
    SELECT * INTO b FROM mailboxes WHERE id = NEW.mailbox_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such mailbox' USING ERRCODE = 'no_data_found'; END IF;
    IF TG_OP = 'UPDATE' AND (NEW.mailbox_id, NEW.principal_kind, NEW.principal_id) IS DISTINCT FROM (OLD.mailbox_id, OLD.principal_kind, OLD.principal_id) THEN
        RAISE EXCEPTION 'A permission row keeps its mailbox and principal: revoke it and grant another' USING ERRCODE = 'check_violation';
    END IF;
    -- only a person grants
    PERFORM ml_require_person(NEW.granted_by);
    -- the principal is what its kind says
    IF NEW.principal_kind = 'department' THEN
        IF NOT EXISTS (SELECT 1 FROM departments WHERE id = NEW.principal_id AND archived_at IS NULL) THEN
            RAISE EXCEPTION 'No such department' USING ERRCODE = 'no_data_found';
        END IF;
    ELSE
        SELECT member_kind INTO pk FROM members WHERE id = NEW.principal_id;
        IF pk IS DISTINCT FROM (CASE NEW.principal_kind WHEN 'member' THEN 'human' ELSE 'agent' END) THEN
            RAISE EXCEPTION 'A % row names %', NEW.principal_kind, CASE NEW.principal_kind WHEN 'member' THEN 'a person' ELSE 'an agent' END USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    IF b.kind = 'person' THEN
        -- an agent reaches a person's mailbox at most at send (D12), and only through its owner
        IF NEW.principal_kind = 'agent' AND ml_level_rank(NEW.level) > 3 THEN
            RAISE EXCEPTION 'An agent reaches a person''s mailbox at most at send' USING ERRCODE = 'check_violation';
        END IF;
        IF NEW.granted_by = b.owner_member_id THEN
            NEW.break_glass := false;
        ELSE
            -- anyone else's row is break-glass: a Mail admin's, for a person, with a reason and an expiry (the CHECK), the owner told (db/010)
            IF NOT ml_member_has_right(NEW.granted_by, 'access.audit') THEN
                RAISE EXCEPTION 'Only its owner shares a person''s mailbox' USING ERRCODE = 'insufficient_privilege';
            END IF;
            IF NEW.principal_kind <> 'member' THEN
                RAISE EXCEPTION 'An emergency grant names one person' USING ERRCODE = 'check_violation';
            END IF;
            NEW.break_glass := true;
        END IF;
    ELSE
        -- an agent's or a shared mailbox: a person with full on it, or a Mail admin
        IF NOT (ml_is_decider(NEW.mailbox_id, NEW.granted_by) OR ml_member_has_right(NEW.granted_by, 'mailboxes.manage')) THEN
            RAISE EXCEPTION 'Only a person with full access to this mailbox, or a Mail admin, shares it' USING ERRCODE = 'insufficient_privilege';
        END IF;
        NEW.break_glass := false;
    END IF;
    IF NEW.expires_at IS NOT NULL AND NEW.expires_at <= now() THEN
        RAISE EXCEPTION 'An expiry is in the future' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mailbox_permissions_guard BEFORE INSERT OR UPDATE ON mailbox_permissions FOR EACH ROW EXECUTE FUNCTION ml_permissions_guard();

-- ---------------------------------------------------------------------------------------------------------------------------------
-- mail_identities — the addresses a mailbox sends as: its own, and the aliases MaluMail delivers to it (checked through list_aliases).
-- ---------------------------------------------------------------------------------------------------------------------------------
CREATE TABLE mail_identities (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_id     bigint NOT NULL REFERENCES mailboxes(id) ON DELETE CASCADE,
    address        citext NOT NULL CHECK (address ~ '^[^@\s]+@[^@\s]+$'),
    kind           text NOT NULL DEFAULT 'own' CHECK (kind IN ('own', 'alias')),
    display_name   text,
    reply_to       citext,
    signature_html text,                                  -- sanitised by the mail service before it is stored
    signature_text text,
    is_default     boolean NOT NULL DEFAULT false,
    checked_at     timestamptz,                           -- an alias: when list_aliases last showed it delivering here
    created_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (mailbox_id, address)
);
CREATE UNIQUE INDEX mail_identities_one_default ON mail_identities (mailbox_id) WHERE is_default;
CREATE TRIGGER mail_identities_touch BEFORE UPDATE ON mail_identities FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION ml_identities_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.kind = 'own' AND NEW.address <> (SELECT address FROM mailboxes WHERE id = NEW.mailbox_id) THEN
        RAISE EXCEPTION 'A mailbox''s own identity is its own address' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.kind = 'alias' AND NOT EXISTS (SELECT 1 FROM ml_aliases a JOIN mailboxes b ON b.id = NEW.mailbox_id
                                           WHERE a.address = NEW.address AND a.active AND b.address = ANY (a.destinations)) THEN
        RAISE EXCEPTION 'MaluMail does not deliver % to this mailbox', NEW.address USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mail_identities_guard BEFORE INSERT OR UPDATE OF address, kind, mailbox_id ON mail_identities FOR EACH ROW EXECUTE FUNCTION ml_identities_guard();

-- ---------------------------------------------------------------------------------------------------------------------------------
-- agent_mail_policies — one row per agent mailbox (§5, D10), made with the mailbox from the settings' defaults; changed by a person.
-- ---------------------------------------------------------------------------------------------------------------------------------
CREATE TABLE agent_mail_policies (
    mailbox_id              bigint PRIMARY KEY REFERENCES mailboxes(id) ON DELETE CASCADE,
    send_mode               text NOT NULL CHECK (send_mode IN ('hold_all', 'internal_auto', 'allowlist_auto', 'auto')),
    allow_domains           citext[] NOT NULL DEFAULT '{}',
    allow_addresses         citext[] NOT NULL DEFAULT '{}',
    first_contact_review    boolean NOT NULL,
    attachments_review      boolean NOT NULL,
    max_external_per_day    smallint NOT NULL CHECK (max_external_per_day BETWEEN 0 AND 10000),
    max_per_hour            smallint NOT NULL CHECK (max_per_hour BETWEEN 0 AND 10000),
    max_per_thread_per_day  smallint NOT NULL CHECK (max_per_thread_per_day BETWEEN 1 AND 1000),
    paused                  boolean NOT NULL DEFAULT false,
    paused_at               timestamptz,
    paused_by               bigint REFERENCES members(id) ON DELETE SET NULL,
    pause_reason            text,
    wake_on_mail            boolean NOT NULL,
    wake_from               text NOT NULL CHECK (wake_from IN ('none', 'internal', 'internal_and_known', 'all_authenticated')),
    disclosure              boolean NOT NULL,
    disclosure_text         text CHECK (disclosure_text IS NULL OR (length(disclosure_text) BETWEEN 10 AND 500 AND disclosure_text LIKE '%{agent}%')),  -- NULL = the settings'
    updated_by              bigint NOT NULL REFERENCES members(id) ON DELETE RESTRICT,   -- a person (the guard)
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    CHECK (paused = (paused_at IS NOT NULL)),
    CHECK (send_mode <> 'allowlist_auto' OR cardinality(allow_domains) + cardinality(allow_addresses) > 0)
);
CREATE TRIGGER agent_mail_policies_touch BEFORE UPDATE ON agent_mail_policies FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION ml_policies_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM mailboxes WHERE id = NEW.mailbox_id AND kind = 'agent') THEN
        RAISE EXCEPTION 'A mail policy is an agent mailbox''s' USING ERRCODE = 'check_violation';
    END IF;
    PERFORM ml_require_person(NEW.updated_by);
    RETURN NEW;
END$$;
CREATE TRIGGER agent_mail_policies_guard BEFORE INSERT OR UPDATE ON agent_mail_policies FOR EACH ROW EXECUTE FUNCTION ml_policies_guard();

-- ---------------------------------------------------------------------------------------------------------------------------------
-- ml_notice_acknowledgements — every person acknowledges each version of the monitoring notice once (§3).
-- ---------------------------------------------------------------------------------------------------------------------------------
CREATE TABLE ml_notice_acknowledgements (
    member_id        bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    notice_version   integer NOT NULL CHECK (notice_version >= 1),
    acknowledged_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, notice_version)
);

-- Has the caller acknowledged the notice's current version?
CREATE OR REPLACE FUNCTION ml_notice_acknowledged(p_member bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM ml_notice_acknowledgements a JOIN ml_settings s ON s.id = 1
                    WHERE a.member_id = p_member AND a.notice_version = s.notice_version);
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON ml_domains, ml_aliases, mailboxes, mailbox_credentials, mailbox_permissions, mail_identities,
    agent_mail_policies, ml_notice_acknowledgements TO malumail_rw;
REVOKE ALL ON FUNCTION ml_mailboxes_guard(), ml_credentials_guard(), ml_permissions_guard(), ml_identities_guard(), ml_policies_guard() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_level_rank(text), ml_level_name(integer), ml_address_domain(text), ml_is_internal_address(text),
    ml_mailbox_level(bigint, bigint), ml_level_as(bigint, bigint, bigint), ml_level(bigint), ml_my_mailbox_ids(text),
    ml_is_decider(bigint, bigint), ml_require_person(bigint), ml_notice_acknowledged(bigint)
TO malumail_rw, malumail_records_ro, malumail_activity_ro;

COMMIT;
