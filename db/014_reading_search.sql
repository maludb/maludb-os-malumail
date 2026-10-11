-- 014: READING AND SEARCH (Phase 1, 2026-10-11) — what docs/build-specs/reading-and-rendering.md, reading.md (slice 3),
-- search-labels-snooze.md (slice 6) and correspondents.md (slice 8) ask of the schema. Additive on db/001–012: columns appended, functions
-- added, ml_search kept under its old signature beside the cursor one (and a negated term fixed: `-from:x` dropped every message with no
-- display name), ml_correspondence_index re-created with `since`. Every function
-- that writes takes the acting member (`p_by`) and asks the wall itself; every read answers only what the caller may open.
BEGIN;

-- ------------------------------------------------------------------------------------------------- images always from a sender (slice 3)
-- "Always show images from {sender}" is a mailbox's choice about one address, kept on its correspondent row. It applies only to mail
-- that PROVES its sender (a DMARC pass, or a colleague's local submission): a forged From never turns tracking images on.
ALTER TABLE mail_correspondents
    ADD COLUMN images_always boolean NOT NULL DEFAULT false,
    ADD COLUMN images_set_by bigint REFERENCES members(id) ON DELETE SET NULL,
    ADD COLUMN images_set_at timestamptz;

CREATE OR REPLACE FUNCTION ml_images_allow(p_mailbox bigint, p_address text, p_by bigint, p_on boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lvl text;
BEGIN
    PERFORM ml_require_person(p_by);
    lvl := ml_mailbox_level(p_mailbox, p_by);
    IF lvl = 'none' THEN RAISE EXCEPTION 'No such mailbox.' USING ERRCODE = 'no_data_found'; END IF;
    IF lvl <> 'full' THEN
        RAISE EXCEPTION 'Showing a sender''s images always is set by someone with full on the mailbox' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF p_address IS NULL OR position('@' IN p_address) = 0 THEN
        RAISE EXCEPTION 'A sender is an address' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    INSERT INTO mail_correspondents AS c (mailbox_id, address, images_always, images_set_by, images_set_at, is_internal)
    VALUES (p_mailbox, lower(btrim(p_address)), p_on, p_by, now(), ml_is_internal_address(p_address))
    ON CONFLICT (mailbox_id, address) DO UPDATE SET images_always = p_on, images_set_by = p_by, images_set_at = now();
END$$;

-- Does this message show its remote images without being asked? Only for the caller's own reading, only from a proven sender.
CREATE OR REPLACE FUNCTION ml_images_always_for(p_message bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE m mail_messages%ROWTYPE;
BEGIN
    SELECT * INTO m FROM mail_messages WHERE id = p_message;
    IF NOT FOUND OR ml_level_rank(ml_level(m.mailbox_id)) < 1 THEN RETURN false; END IF;
    IF NOT (COALESCE(m.dmarc_pass, false) OR (m.local_submission AND ml_is_internal_address(m.from_address::text))) THEN RETURN false; END IF;
    RETURN EXISTS (SELECT 1 FROM mail_correspondents c WHERE c.mailbox_id = m.mailbox_id AND c.address = lower(m.from_address::text)::citext
                      AND c.images_always);
END$$;

-- the correspondents' window gains the flag (appended last)
CREATE OR REPLACE VIEW mcp_correspondents WITH (security_barrier = true) AS
SELECT c.id, c.mailbox_id, c.address::text AS address, c.name, c.first_seen_at, c.last_seen_at, c.sent_count, c.received_count, c.is_internal,
       c.known_at, c.known_how, c.images_always
  FROM mail_correspondents c
 WHERE c.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[]);

CREATE INDEX mail_correspondents_name_trgm ON mail_correspondents USING gin (name gin_trgm_ops);

-- ------------------------------------------------------------------------------------------------- a conversation's message keys
-- The anchors states and labels hang on. Nothing for a conversation the caller cannot open, or (an agent) one marked sensitive.
CREATE OR REPLACE FUNCTION ml_thread_keys(p_thread bigint) RETURNS bigint[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE mb bigint; keys bigint[];
BEGIN
    SELECT mailbox_id INTO mb FROM mail_threads WHERE id = p_thread;
    IF mb IS NULL OR ml_level_rank(ml_level(mb)) < 1 THEN RETURN '{}'; END IF;
    IF app_member_kind() = 'agent' AND COALESCE((SELECT s.sensitive FROM ml_thread_state(p_thread) s), false) THEN RETURN '{}'; END IF;
    SELECT COALESCE(array_agg(DISTINCT message_key_id ORDER BY message_key_id), '{}') INTO keys FROM mail_messages WHERE thread_id = p_thread;
    RETURN keys;
END$$;

-- ------------------------------------------------------------------------------------------------- search (slice 6)
-- The one body behind both signatures. New beside db/008's operators: is:snoozed, is:muted, has:label, has:nolabel; is:unread / is:read
-- PER PERSON where the caller reads in `viewer_read` mode (a view holder, every intake mailbox — design §3; mail the mailbox sent or
-- drafted is never unread to anyone); the cursor (p_before, and
-- p_before_id to break ties at the same instant) instead of an offset.
CREATE OR REPLACE FUNCTION ml_search_core(p_q text, p_mailbox bigint, p_limit integer, p_offset integer, p_before timestamptz, p_before_id bigint)
    RETURNS TABLE (message_id bigint, thread_id bigint, mailbox_id bigint, received_at timestamptz, rank real)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE terms jsonb := ml_search_parse(p_q); t jsonb; c text; conds text[] := '{}'; v text; op text; any_in boolean := false;
        ts text := NULL; ids bigint[]; vids bigint[]; is_agent boolean := app_member_kind() = 'agent'; d date;
        me bigint := app_current_member_id(); behalf bigint := NULLIF(current_setting('app.on_behalf_of', true), '')::bigint;
        unread_c text := '(CASE WHEN m.mailbox_id = ANY ($4) THEN COALESCE(f.special_use, '''') NOT IN (''sent'', ''drafts'') AND NOT EXISTS (SELECT 1 FROM mail_viewer_reads r WHERE r.member_id = $5 AND r.message_key_id = m.message_key_id) ELSE NOT m.seen END)';
BEGIN
    ids := ml_my_mailbox_ids('view');
    IF p_mailbox IS NOT NULL THEN ids := ARRAY(SELECT x FROM unnest(ids) x WHERE x = p_mailbox); END IF;
    IF cardinality(ids) = 0 THEN RETURN; END IF;
    vids := ARRAY(SELECT x FROM unnest(ids) x WHERE ml_read_mode(x, me, behalf) = 'viewer_read');
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
        ELSIF op = 'has' THEN
            c := CASE lower(v)
                 WHEN 'attachment' THEN 'm.has_attachments' WHEN 'attachments' THEN 'm.has_attachments'
                 WHEN 'label' THEN 'EXISTS (SELECT 1 FROM record_tags rt JOIN tags g ON g.id = rt.tag_id WHERE rt.record_type = ''mail_message'' AND rt.record_id = m.message_key_id AND g.mailbox_id = m.mailbox_id)'
                 WHEN 'nolabel' THEN 'NOT EXISTS (SELECT 1 FROM record_tags rt JOIN tags g ON g.id = rt.tag_id WHERE rt.record_type = ''mail_message'' AND rt.record_id = m.message_key_id AND g.mailbox_id = m.mailbox_id)'
                 END;
        ELSIF op = 'is' THEN
            c := CASE lower(v) WHEN 'unread' THEN unread_c WHEN 'read' THEN 'NOT ' || unread_c
                               WHEN 'starred' THEN 'm.flagged' WHEN 'flagged' THEN 'm.flagged' WHEN 'answered' THEN 'm.answered'
                               WHEN 'snoozed' THEN 'EXISTS (SELECT 1 FROM ml_thread_state(m.thread_id) s WHERE s.snoozed_until IS NOT NULL)'
                               WHEN 'muted' THEN 'COALESCE((SELECT s.muted FROM ml_thread_state(m.thread_id) s), false)' END;
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
            -- an unknown operator is searched for as words (Gmail does the same); ml_search_unknown() names it
            c := format('m.tsv @@ plainto_tsquery(''ml_mail'', %L)', op || ' ' || v);
        END IF;
        IF c IS NULL THEN CONTINUE; END IF;
        -- a negated term is NOT COALESCE(…, false): db/008's NOT (…) dropped a message whose from_name is NULL from `-from:x`
        conds := conds || CASE WHEN (t->>'neg')::boolean THEN 'NOT COALESCE((' || c || '), false)' ELSE c END;
    END LOOP;
    IF NOT any_in THEN conds := conds || 'COALESCE(f.special_use, '''') NOT IN (''trash'', ''junk'')'::text; END IF;
    IF is_agent THEN
        conds := conds || 'NOT COALESCE((SELECT s.sensitive FROM ml_thread_state(m.thread_id) s), false)'::text;
    END IF;
    IF p_before IS NOT NULL THEN
        conds := conds || CASE WHEN p_before_id IS NULL THEN 'm.received_at < $6' ELSE '(m.received_at, m.id) < ($6, $7)' END;
    END IF;
    RETURN QUERY EXECUTE format(
        'SELECT m.id, m.thread_id, m.mailbox_id, m.received_at, %s::real
           FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
          WHERE m.mailbox_id = ANY ($1) %s
          ORDER BY m.received_at DESC, m.id DESC LIMIT $2 OFFSET $3',
        CASE WHEN ts IS NULL THEN '0' ELSE format('ts_rank(m.tsv, plainto_tsquery(''ml_mail'', %L))', ts) END,
        CASE WHEN cardinality(conds) = 0 THEN '' ELSE 'AND ' || array_to_string(conds, ' AND ') END)
    USING ids, LEAST(GREATEST(p_limit, 1), 500), GREATEST(p_offset, 0), vids, me, p_before, p_before_id;
END$$;

-- db/008's signature, kept (the Phase 0 proof and any offset caller): the same search, paged by offset.
CREATE OR REPLACE FUNCTION ml_search(p_q text, p_mailbox bigint DEFAULT NULL, p_limit integer DEFAULT 50, p_offset integer DEFAULT 0)
    RETURNS TABLE (message_id bigint, thread_id bigint, mailbox_id bigint, received_at timestamptz, rank real)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT * FROM ml_search_core(p_q, p_mailbox, p_limit, p_offset, NULL, NULL);
$$;

-- The cursor: every argument but the tie-breaker is required, so a call is never ambiguous with the offset one
-- (pass NULL::timestamptz for the first page).
CREATE OR REPLACE FUNCTION ml_search(p_q text, p_mailbox bigint, p_limit integer, p_before timestamptz, p_before_id bigint DEFAULT NULL)
    RETURNS TABLE (message_id bigint, thread_id bigint, mailbox_id bigint, received_at timestamptz, rank real)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT * FROM ml_search_core(p_q, p_mailbox, p_limit, 0, p_before, p_before_id);
$$;

-- The operators (and is:/has: values) a query used that the search did not know — for the page and the tool to name.
CREATE OR REPLACE FUNCTION ml_search_unknown(p_q text) RETURNS text[]
    LANGUAGE sql IMMUTABLE AS $$
    SELECT COALESCE(array_agg(DISTINCT x ORDER BY x), '{}') FROM (
        SELECT CASE
                 WHEN t->>'op' NOT IN ('phrase', 'from', 'to', 'cc', 'bcc', 'subject', 'filename', 'label', 'has', 'is', 'in', 'after', 'before',
                                       'older_than', 'newer_than', 'larger', 'smaller') THEN (t->>'op') || ':'
                 WHEN t->>'op' = 'is' AND lower(t->>'val') NOT IN ('unread', 'read', 'starred', 'flagged', 'answered', 'snoozed', 'muted') THEN 'is:' || lower(t->>'val')
                 WHEN t->>'op' = 'has' AND lower(t->>'val') NOT IN ('attachment', 'attachments', 'label', 'nolabel') THEN 'has:' || lower(t->>'val')
               END AS x
          FROM jsonb_array_elements(ml_search_parse(p_q)) t
         WHERE t->>'op' IS NOT NULL) u
     WHERE x IS NOT NULL;
$$;

-- ------------------------------------------------------------------------------------------------- labels (slice 6)
-- Labels live in this application's index only, on MESSAGE KEYS (never thread ids — threads are rebuildable): a conversation shows any
-- label of any of its messages. Labelling needs triage (ml_mutation_refusal 'label'); an agent never labels a sensitive conversation.
CREATE OR REPLACE FUNCTION ml_label_apply(p_mailbox bigint, p_keys bigint[], p_tag bigint, p_by bigint, p_remove boolean DEFAULT false) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE why text; n integer;
BEGIN
    why := ml_mutation_refusal(p_mailbox, p_by, NULLIF(current_setting('app.on_behalf_of', true), '')::bigint, NULL, 'label');
    IF why IS NOT NULL THEN RAISE EXCEPTION '%', why USING ERRCODE = CASE WHEN why = 'No such mailbox.' THEN 'no_data_found' ELSE 'insufficient_privilege' END; END IF;
    IF NOT EXISTS (SELECT 1 FROM tags WHERE id = p_tag AND mailbox_id = p_mailbox AND archived_at IS NULL) THEN
        RAISE EXCEPTION 'No such label in this mailbox' USING ERRCODE = 'no_data_found';
    END IF;
    IF EXISTS (SELECT 1 FROM unnest(p_keys) k WHERE NOT EXISTS (SELECT 1 FROM mail_message_keys mk WHERE mk.id = k AND mk.mailbox_id = p_mailbox)) THEN
        RAISE EXCEPTION 'No such message in this mailbox' USING ERRCODE = 'no_data_found';
    END IF;
    IF EXISTS (SELECT 1 FROM members WHERE id = p_by AND member_kind = 'agent')
       AND EXISTS (SELECT 1 FROM unnest(p_keys) k CROSS JOIN LATERAL ml_thread_state(ml_key_thread(k)) s WHERE s.sensitive) THEN
        RAISE EXCEPTION 'No such message in this mailbox' USING ERRCODE = 'no_data_found';
    END IF;
    IF p_remove THEN
        DELETE FROM record_tags WHERE record_type = 'mail_message' AND tag_id = p_tag AND record_id = ANY (p_keys);
    ELSE
        INSERT INTO record_tags (record_type, record_id, tag_id) SELECT 'mail_message', k, p_tag FROM unnest(p_keys) k ON CONFLICT DO NOTHING;
    END IF;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- Create (p_tag NULL) or rename/recolour a label. Its slug comes from its name: lower case, accents off, spaces to '-', '/' kept (nesting).
CREATE OR REPLACE FUNCTION ml_label_save(p_mailbox bigint, p_tag bigint, p_name text, p_colour text, p_by bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE why text; nm text := btrim(COALESCE(p_name, '')); sl text; col text := NULLIF(lower(btrim(COALESCE(p_colour, ''))), ''); id bigint;
BEGIN
    why := ml_mutation_refusal(p_mailbox, p_by, NULLIF(current_setting('app.on_behalf_of', true), '')::bigint, NULL, 'label');
    IF why IS NOT NULL THEN RAISE EXCEPTION '%', why USING ERRCODE = CASE WHEN why = 'No such mailbox.' THEN 'no_data_found' ELSE 'insufficient_privilege' END; END IF;
    IF nm = '' OR length(nm) > 50 THEN RAISE EXCEPTION 'A label''s name is 1 to 50 characters' USING ERRCODE = 'invalid_parameter_value'; END IF;
    sl := regexp_replace(lower(unaccent(nm)), '\s+', '-', 'g');
    sl := btrim(left(regexp_replace(sl, '[^a-z0-9_/-]', '', 'g'), 50), '-/_');
    IF sl !~ '^[a-z0-9]' THEN RAISE EXCEPTION 'A label''s name needs a letter or a digit' USING ERRCODE = 'invalid_parameter_value'; END IF;
    IF col IS NOT NULL AND col !~ '^#[0-9a-f]{6}$' THEN RAISE EXCEPTION 'A colour is #rrggbb' USING ERRCODE = 'invalid_parameter_value'; END IF;
    BEGIN
        IF p_tag IS NULL THEN
            INSERT INTO tags (slug, label, colour, created_by, mailbox_id) VALUES (sl, nm, col, p_by, p_mailbox) RETURNING tags.id INTO id;
        ELSE
            UPDATE tags SET slug = sl, label = nm, colour = col WHERE tags.id = p_tag AND mailbox_id = p_mailbox AND archived_at IS NULL RETURNING tags.id INTO id;
            IF id IS NULL THEN RAISE EXCEPTION 'No such label in this mailbox' USING ERRCODE = 'no_data_found'; END IF;
        END IF;
    EXCEPTION WHEN unique_violation THEN
        RAISE EXCEPTION 'This mailbox already has a label called %', nm USING ERRCODE = 'unique_violation';
    END;
    RETURN id;
END$$;

-- Delete a label: it leaves every conversation; the mail stays. Answers how many conversations carried it.
CREATE OR REPLACE FUNCTION ml_label_delete(p_mailbox bigint, p_tag bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE why text; n integer;
BEGIN
    why := ml_mutation_refusal(p_mailbox, p_by, NULLIF(current_setting('app.on_behalf_of', true), '')::bigint, NULL, 'label');
    IF why IS NOT NULL THEN RAISE EXCEPTION '%', why USING ERRCODE = CASE WHEN why = 'No such mailbox.' THEN 'no_data_found' ELSE 'insufficient_privilege' END; END IF;
    IF NOT EXISTS (SELECT 1 FROM tags WHERE id = p_tag AND mailbox_id = p_mailbox) THEN
        RAISE EXCEPTION 'No such label in this mailbox' USING ERRCODE = 'no_data_found';
    END IF;
    SELECT count(DISTINCT COALESCE(m.thread_id, -rt.record_id)) INTO n
      FROM record_tags rt LEFT JOIN mail_messages m ON m.message_key_id = rt.record_id
     WHERE rt.record_type = 'mail_message' AND rt.tag_id = p_tag;
    DELETE FROM tags WHERE id = p_tag AND mailbox_id = p_mailbox;
    RETURN n;
END$$;

-- ------------------------------------------------------------------------------------------------- canned replies (slice 6)
-- A person's own, or shared with everyone using MaluMail. Its owner changes it; a shared one, also a Mail admin. Agents keep none.
CREATE OR REPLACE FUNCTION ml_template_save(p_template bigint, p_name text, p_subject text, p_body text, p_shared boolean, p_by bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t email_templates%ROWTYPE; id bigint;
BEGIN
    PERFORM ml_require_person(p_by);
    IF NOT ml_member_has_right(p_by, 'mail.use') THEN
        RAISE EXCEPTION 'Canned replies are for a Mail user' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF btrim(COALESCE(p_name, '')) = '' OR length(p_name) > 120 THEN RAISE EXCEPTION 'A canned reply has a name of 1 to 120 characters' USING ERRCODE = 'invalid_parameter_value'; END IF;
    IF btrim(COALESCE(p_body, '')) = '' THEN RAISE EXCEPTION 'A canned reply says something' USING ERRCODE = 'invalid_parameter_value'; END IF;
    IF p_template IS NULL THEN
        INSERT INTO email_templates (name, subject, body, is_shared, owner_member_id, created_by)
        VALUES (btrim(p_name), COALESCE(btrim(p_subject), ''), p_body, COALESCE(p_shared, false), p_by, p_by) RETURNING email_templates.id INTO id;
        RETURN id;
    END IF;
    SELECT * INTO t FROM email_templates WHERE email_templates.id = p_template AND archived_at IS NULL;
    IF NOT FOUND OR (t.owner_member_id IS DISTINCT FROM p_by AND NOT t.is_shared) THEN
        RAISE EXCEPTION 'No such canned reply' USING ERRCODE = 'no_data_found';
    END IF;
    IF t.owner_member_id IS DISTINCT FROM p_by AND NOT ml_member_has_right(p_by, 'settings.manage') THEN
        RAISE EXCEPTION 'Only its owner or a Mail admin changes a shared canned reply' USING ERRCODE = 'insufficient_privilege';
    END IF;
    UPDATE email_templates SET name = btrim(p_name), subject = COALESCE(btrim(p_subject), ''), body = p_body, is_shared = COALESCE(p_shared, t.is_shared)
     WHERE email_templates.id = p_template;
    RETURN p_template;
END$$;

CREATE OR REPLACE FUNCTION ml_template_delete(p_template bigint, p_by bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t email_templates%ROWTYPE;
BEGIN
    PERFORM ml_require_person(p_by);
    SELECT * INTO t FROM email_templates WHERE id = p_template AND archived_at IS NULL;
    IF NOT FOUND OR (t.owner_member_id IS DISTINCT FROM p_by AND NOT t.is_shared) THEN
        RAISE EXCEPTION 'No such canned reply' USING ERRCODE = 'no_data_found';
    END IF;
    IF t.owner_member_id IS DISTINCT FROM p_by AND NOT ml_member_has_right(p_by, 'settings.manage') THEN
        RAISE EXCEPTION 'Only its owner or a Mail admin deletes a shared canned reply' USING ERRCODE = 'insufficient_privilege';
    END IF;
    UPDATE email_templates SET archived_at = now() WHERE id = p_template;
END$$;

-- ------------------------------------------------------------------------------------------------- the K7 share (slice 8)
-- db/011's, with `since`, and a suspended mailbox left out. The old three-argument form goes: the new one's defaults answer every call
-- it answered, and two forms would make (NULL, NULL) ambiguous.
DROP FUNCTION ml_correspondence_index(text, text, integer);
CREATE FUNCTION ml_correspondence_index(p_address text DEFAULT NULL, p_domain text DEFAULT NULL, p_limit integer DEFAULT 25,
                                        p_since timestamptz DEFAULT NULL)
    RETURNS TABLE (thread_id bigint, mailbox_id bigint, mailbox_address text, subject text, participants text[], first_message_at timestamptz,
                   last_message_at timestamptz, message_count integer, snippet text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE a text := lower(btrim(p_address)); d text := lower(btrim(p_domain));
BEGIN
    IF COALESCE(a, '') = '' AND COALESCE(d, '') = '' THEN
        RAISE EXCEPTION 'correspondence_index asks about an address or a domain' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    RETURN QUERY
    SELECT t.id, t.mailbox_id, b.address::text, t.subject, t.participants, t.first_message_at, t.last_message_at, t.message_count, left(t.snippet, 200)
      FROM mail_threads t JOIN mailboxes b ON b.id = t.mailbox_id AND b.share_with_apps AND b.active AND b.suspended_at IS NULL
     WHERE (a IS NULL OR a = '' OR a = ANY (t.participants))
       AND (d IS NULL OR d = '' OR EXISTS (SELECT 1 FROM unnest(t.participants) x WHERE split_part(x, '@', 2) = d))
       AND (p_since IS NULL OR t.last_message_at >= p_since)
       AND NOT (SELECT st.sensitive FROM ml_thread_state(t.id) st)
     ORDER BY t.last_message_at DESC
     LIMIT LEAST(GREATEST(p_limit, 1), 100);
END$$;

-- One k7.read row per mailbox an answer drew on (the access log, §3): the consumer, its agent, the count, the domain asked about.
CREATE OR REPLACE FUNCTION ml_log_k7_read(p_mailboxes bigint[], p_consumer text, p_consumer_agent bigint, p_counts integer[], p_domain text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF cardinality(p_mailboxes) IS DISTINCT FROM cardinality(p_counts) THEN
        RAISE EXCEPTION 'one count per mailbox' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, mailbox_id, after)
    SELECT p_consumer_agent, 'application', 'k7.read', 'mailbox', u.m, u.m,
           jsonb_build_object('application', 'malumail', 'consumer', left(p_consumer, 60), 'consumer_agent', p_consumer_agent, 'count', u.c,
                              'domain', lower(p_domain))
      FROM unnest(p_mailboxes, p_counts) AS u(m, c)
     WHERE u.m IS NOT NULL;
END$$;

-- ------------------------------------------------------------------------------------------------- autocomplete (slice 8)
-- For a sender (send on the mailbox): its correspondents (sent-to first), the business's people, and the mailboxes the caller opens —
-- never another mailbox's address (§3: no address leaks).
CREATE OR REPLACE FUNCTION ml_complete(p_mailbox bigint, p_q text, p_limit integer DEFAULT 8)
    RETURNS TABLE (address text, name text, source text, outside boolean)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE q text := btrim(COALESCE(p_q, '')); pat text;
BEGIN
    IF q = '' OR ml_level_rank(ml_level(p_mailbox)) < 3 THEN RETURN; END IF;
    pat := ml_like(q);
    RETURN QUERY
    WITH cand AS (
        SELECT c.address::text AS a, c.name AS n, 'correspondent'::text AS s, 1 AS pri, c.sent_count > 0 AS sent,
               c.sent_count + c.received_count AS cnt, c.last_seen_at AS seen
          FROM mail_correspondents c
         WHERE c.mailbox_id = p_mailbox AND (c.address::text ILIKE pat OR c.name ILIKE pat)
        UNION ALL
        SELECT lower(m.email::text), m.display_name, 'member', 2, false, 0, NULL::timestamptz
          FROM members m
         WHERE m.status = 'active' AND m.email IS NOT NULL AND (m.email::text ILIKE pat OR m.display_name ILIKE pat)
        UNION ALL
        SELECT lower(b.address::text), b.display_name, 'mailbox', 3, false, 0, NULL::timestamptz
          FROM mailboxes b
         WHERE b.id = ANY (ml_my_mailbox_ids('view')) AND b.active AND (b.address::text ILIKE pat OR b.display_name ILIKE pat)
    ), best AS (
        SELECT DISTINCT ON (lower(cand.a)) cand.* FROM cand ORDER BY lower(cand.a), cand.pri
    )
    SELECT best.a, best.n, best.s, NOT ml_is_internal_address(best.a)
      FROM best
     ORDER BY best.pri, best.sent DESC, best.cnt DESC, best.seen DESC NULLS LAST, best.a
     LIMIT LEAST(GREATEST(p_limit, 1), 25);
END$$;

-- ------------------------------------------------------------------------------------------------- grants
REVOKE ALL ON FUNCTION ml_images_allow(bigint, text, bigint, boolean), ml_images_always_for(bigint), ml_thread_keys(bigint),
    ml_search_core(text, bigint, integer, integer, timestamptz, bigint), ml_search(text, bigint, integer, timestamptz, bigint),
    ml_label_apply(bigint, bigint[], bigint, bigint, boolean), ml_label_save(bigint, bigint, text, text, bigint), ml_label_delete(bigint, bigint, bigint),
    ml_template_save(bigint, text, text, text, boolean, bigint), ml_template_delete(bigint, bigint),
    ml_correspondence_index(text, text, integer, timestamptz), ml_log_k7_read(bigint[], text, bigint, integer[], text),
    ml_complete(bigint, text, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_images_always_for(bigint), ml_thread_keys(bigint), ml_search(text, bigint, integer, timestamptz, bigint),
    ml_search_unknown(text), ml_correspondence_index(text, text, integer, timestamptz), ml_complete(bigint, text, integer)
TO malumail_rw, malumail_records_ro;
GRANT EXECUTE ON FUNCTION ml_images_allow(bigint, text, bigint, boolean), ml_label_apply(bigint, bigint[], bigint, bigint, boolean),
    ml_label_save(bigint, bigint, text, text, bigint), ml_label_delete(bigint, bigint, bigint),
    ml_template_save(bigint, text, text, text, boolean, bigint), ml_template_delete(bigint, bigint)
TO malumail_rw;
GRANT EXECUTE ON FUNCTION ml_log_k7_read(bigint[], text, bigint, integer[], text) TO malumail_records_ro;

COMMIT;
