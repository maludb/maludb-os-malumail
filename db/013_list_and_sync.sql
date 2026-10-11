-- 013: THE LIST AND THE SYNC (Phase 1, 2026-10-11) — what docs/build-specs/mail-service.md, mail-list.md and home-admin.md ask of the
-- schema. Additive: new tables, a new column, new functions and triggers; nothing of db/001–012 replaced.
--
-- THE CHANGE COUNTER (mail-list.md §"The poll", design §8.1): every mailbox has a sequence number (mail_mailbox_changes.seq) that moves
-- whenever something a list shows changes — a conversation's figures, its state, its labels, a person's read mark, an outbound row, a
-- folder's counts. Each conversation carries the number it last changed at (mail_threads.change_seq); a conversation that goes leaves a
-- tombstone. The poll answers 204 when the mailbox's number is the one the page holds — one primary-key read, no IMAP.
-- During a bulk write (app.ml_bulk = 'on', a first sync or a rebuild) the rows take the NEXT number without moving the counter, and the
-- mail service calls ml_mailbox_touch(mailbox, '{}') once after — so a long sync moves the counter once, not once per message.
--
-- THE LIST (mail-list.md): ONE gatherer, ml_mail_list(), behind mailbox-view, folder-view, label-view and the records tool mail_list; the
-- wall inside (ml_level(), app.member_id / app.on_behalf_of as the caller set them); an agent never sees a conversation marked sensitive;
-- unread per person where ml_read_mode() says the server's flag cannot be used (a view holder, every intake mailbox — D13).
--
-- THE ADMIN (home-admin.md): the settings saved through a key allow-list, the notice, re-index, a mail turn retried, sync health — each a
-- person's and a Mail admin's alone, refused to an agent in SQL.
BEGIN;

-- ------------------------------------------------------------------------------------------------- the change counter
-- No foreign keys on these two: a mailbox's deletion cascades through its conversations, whose triggers write here in the same
-- statement; a row left behind by a deleted mailbox is harmless and the worker prunes tombstones.
CREATE TABLE mail_mailbox_changes (
    mailbox_id  bigint PRIMARY KEY,
    seq         bigint NOT NULL DEFAULT 0,
    changed_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE mail_thread_tombstones (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    mailbox_id  bigint NOT NULL,
    thread_id   bigint NOT NULL,
    change_seq  bigint NOT NULL,
    at          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX mail_thread_tombstones_seq_idx ON mail_thread_tombstones (mailbox_id, change_seq);

ALTER TABLE mail_threads ADD COLUMN change_seq bigint NOT NULL DEFAULT 0;
CREATE INDEX mail_threads_change_idx ON mail_threads (mailbox_id, change_seq);

-- The next number (moves the counter) and the pending one (does not).
CREATE OR REPLACE FUNCTION ml_mailbox_next_seq(p_mailbox bigint) RETURNS bigint
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    INSERT INTO mail_mailbox_changes AS c (mailbox_id, seq, changed_at) VALUES (p_mailbox, 1, now())
    ON CONFLICT (mailbox_id) DO UPDATE SET seq = c.seq + 1, changed_at = now()
    RETURNING seq;
$$;
CREATE OR REPLACE FUNCTION ml_mailbox_pending_seq(p_mailbox bigint) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT seq FROM mail_mailbox_changes WHERE mailbox_id = p_mailbox), 0) + 1;
$$;
CREATE OR REPLACE FUNCTION ml_is_bulk() RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT COALESCE(current_setting('app.ml_bulk', true) = 'on', false) $$;

CREATE OR REPLACE FUNCTION ml_threads_change_stamp() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    NEW.change_seq := CASE WHEN ml_is_bulk() THEN ml_mailbox_pending_seq(NEW.mailbox_id) ELSE ml_mailbox_next_seq(NEW.mailbox_id) END;
    RETURN NEW;
END$$;
CREATE TRIGGER mail_threads_change_stamp BEFORE INSERT OR UPDATE ON mail_threads FOR EACH ROW EXECUTE FUNCTION ml_threads_change_stamp();

CREATE OR REPLACE FUNCTION ml_threads_tombstone() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    INSERT INTO mail_thread_tombstones (mailbox_id, thread_id, change_seq)
    VALUES (OLD.mailbox_id, OLD.id, CASE WHEN ml_is_bulk() THEN ml_mailbox_pending_seq(OLD.mailbox_id) ELSE ml_mailbox_next_seq(OLD.mailbox_id) END);
    RETURN NULL;
END$$;
CREATE TRIGGER mail_threads_tombstone AFTER DELETE ON mail_threads FOR EACH ROW EXECUTE FUNCTION ml_threads_tombstone();

-- Something a list shows changed outside mail_threads: stamp the conversation (or only move the counter when there is none).
CREATE OR REPLACE FUNCTION ml_touch_thread(p_mailbox bigint, p_thread bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_mailbox IS NULL THEN RETURN; END IF;
    IF p_thread IS NOT NULL THEN
        UPDATE mail_threads SET change_seq = change_seq WHERE id = p_thread AND mailbox_id = p_mailbox;   -- the trigger stamps it
        IF FOUND THEN RETURN; END IF;
    END IF;
    IF NOT ml_is_bulk() THEN PERFORM ml_mailbox_next_seq(p_mailbox); END IF;
END$$;

-- The mail service, after a batch: stamp these conversations; with none, move the counter once (the end of a bulk write).
CREATE OR REPLACE FUNCTION ml_mailbox_touch(p_mailbox bigint, p_threads bigint[]) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF COALESCE(cardinality(p_threads), 0) > 0 AND NOT ml_is_bulk() THEN
        UPDATE mail_threads SET change_seq = change_seq WHERE mailbox_id = p_mailbox AND id = ANY (p_threads);
    END IF;
    IF COALESCE(cardinality(p_threads), 0) = 0 OR ml_is_bulk() OR NOT FOUND THEN
        PERFORM ml_mailbox_next_seq(p_mailbox);
    END IF;
    RETURN (SELECT seq FROM mail_mailbox_changes WHERE mailbox_id = p_mailbox);
END$$;

-- The tables whose changes a list shows.
CREATE OR REPLACE FUNCTION ml_states_touch() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record;
BEGIN
    r := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    PERFORM ml_touch_thread(r.mailbox_id, ml_key_thread(r.anchor_key_id));
    RETURN NULL;
END$$;
CREATE TRIGGER mail_thread_states_changed AFTER INSERT OR UPDATE OR DELETE ON mail_thread_states FOR EACH ROW EXECUTE FUNCTION ml_states_touch();

CREATE OR REPLACE FUNCTION ml_record_tags_touch() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; mb bigint;
BEGIN
    r := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    IF r.record_type IS DISTINCT FROM 'mail_message' THEN RETURN NULL; END IF;
    SELECT mailbox_id INTO mb FROM mail_message_keys WHERE id = r.record_id;
    PERFORM ml_touch_thread(mb, ml_key_thread(r.record_id));
    RETURN NULL;
END$$;
CREATE TRIGGER record_tags_mail_changed AFTER INSERT OR DELETE ON record_tags FOR EACH ROW EXECUTE FUNCTION ml_record_tags_touch();

CREATE OR REPLACE FUNCTION ml_viewer_reads_touch() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; mb bigint;
BEGIN
    r := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    SELECT mailbox_id INTO mb FROM mail_message_keys WHERE id = r.message_key_id;
    PERFORM ml_touch_thread(mb, ml_key_thread(r.message_key_id));
    RETURN NULL;
END$$;
CREATE TRIGGER mail_viewer_reads_changed AFTER INSERT OR DELETE ON mail_viewer_reads FOR EACH ROW EXECUTE FUNCTION ml_viewer_reads_touch();

CREATE OR REPLACE FUNCTION ml_outbound_touch() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.mailbox_id IS NOT NULL THEN PERFORM ml_touch_thread(NEW.mailbox_id, ml_key_thread(NEW.reply_to_key_id)); END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER outbound_email_list_changed AFTER INSERT OR UPDATE OF status, send_after ON outbound_email FOR EACH ROW EXECUTE FUNCTION ml_outbound_touch();

CREATE OR REPLACE FUNCTION ml_folders_touch() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record;
BEGIN
    r := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    IF NOT ml_is_bulk() AND EXISTS (SELECT 1 FROM mailboxes WHERE id = r.mailbox_id) THEN PERFORM ml_mailbox_next_seq(r.mailbox_id); END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER mail_folders_list_changed AFTER INSERT OR DELETE OR UPDATE OF name, messages_total, messages_unseen ON mail_folders
    FOR EACH ROW EXECUTE FUNCTION ml_folders_touch();

-- The poll's two reads (the caller must open the mailbox; NULL / nothing otherwise).
CREATE OR REPLACE FUNCTION ml_mailbox_seq(p_mailbox bigint) RETURNS bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF ml_level_rank(ml_level(p_mailbox)) = 0 THEN RETURN NULL; END IF;
    RETURN COALESCE((SELECT seq FROM mail_mailbox_changes WHERE mailbox_id = p_mailbox), 0);
END$$;

CREATE OR REPLACE FUNCTION ml_mailbox_changed(p_mailbox bigint, p_since bigint, p_limit integer DEFAULT 200)
    RETURNS TABLE (thread_id bigint, change_seq bigint, gone boolean)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
#variable_conflict use_column
DECLARE human boolean := app_member_kind() = 'human';
BEGIN
    IF ml_level_rank(ml_level(p_mailbox)) = 0 THEN RETURN; END IF;
    RETURN QUERY
    SELECT x.thread_id, x.change_seq, x.gone FROM (
        SELECT t.id AS thread_id, t.change_seq, false AS gone
          FROM mail_threads t
         WHERE t.mailbox_id = p_mailbox AND t.change_seq > p_since AND (human OR NOT (SELECT st.sensitive FROM ml_thread_state(t.id) st))
        UNION ALL
        SELECT z.thread_id, z.change_seq, true
          FROM mail_thread_tombstones z
         WHERE z.mailbox_id = p_mailbox AND z.change_seq > p_since
           AND NOT EXISTS (SELECT 1 FROM mail_threads t2 WHERE t2.id = z.thread_id)) x
     ORDER BY x.change_seq LIMIT LEAST(GREATEST(COALESCE(p_limit, 200), 1), 1000);
END$$;

-- The worker's index_catch_up pass: tombstones older than a day go.
CREATE OR REPLACE FUNCTION ml_tombstones_prune() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM mail_thread_tombstones WHERE at < now() - interval '1 day';
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- ------------------------------------------------------------------------------------------------- the sync's two writes
-- A folder's UIDVALIDITY changed: its message rows go (their KEYS stay, and with them labels, viewer reads, states, dispatches,
-- drafts' replies); the folder waits for the mail service to re-read it.
CREATE OR REPLACE FUNCTION ml_folder_reset(p_folder bigint, p_uidvalidity bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    IF p_uidvalidity IS NULL OR p_uidvalidity <= 0 THEN RAISE EXCEPTION 'A folder''s UIDVALIDITY is a positive number' USING ERRCODE = 'invalid_parameter_value'; END IF;
    IF NOT EXISTS (SELECT 1 FROM mail_folders WHERE id = p_folder) THEN RAISE EXCEPTION 'No such folder' USING ERRCODE = 'no_data_found'; END IF;
    DELETE FROM mail_messages WHERE folder_id = p_folder;
    GET DIAGNOSTICS n = ROW_COUNT;
    UPDATE mail_folders SET uidvalidity = p_uidvalidity, uidnext = NULL, highestmodseq = NULL, messages_total = 0, messages_unseen = 0, synced_at = NULL
     WHERE id = p_folder;
    RETURN n;
END$$;

-- A message moved by this application (UID MOVE answered COPYUID): the same row, its id and key kept, at its new place. A row the
-- watcher already made for the new place (a race) gives way.
CREATE OR REPLACE FUNCTION ml_message_moved(p_message bigint, p_folder bigint, p_uidvalidity bigint, p_uid bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE mb bigint; key bigint;
BEGIN
    SELECT mailbox_id, message_key_id INTO mb, key FROM mail_messages WHERE id = p_message;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such message in the index' USING ERRCODE = 'no_data_found'; END IF;
    IF NOT EXISTS (SELECT 1 FROM mail_folders WHERE id = p_folder AND mailbox_id = mb) THEN
        RAISE EXCEPTION 'A message moves within its own mailbox' USING ERRCODE = 'check_violation';
    END IF;
    DELETE FROM mail_messages WHERE folder_id = p_folder AND uidvalidity = p_uidvalidity AND uid = p_uid AND id <> p_message AND message_key_id = key;
    UPDATE mail_messages SET folder_id = p_folder, uidvalidity = p_uidvalidity, uid = p_uid WHERE id = p_message;
END$$;

-- ------------------------------------------------------------------------------------------------- the list
-- The core: which rows a view holds, newest first, after the cursor. Conversations (kind 'thread') for every view but `scheduled`
-- and `outbox`, which hold outbound rows (kind 'outbound'). The caller must open the mailbox (nothing otherwise — the screens answer
-- 404 and the tool `not_found`).
CREATE OR REPLACE FUNCTION ml_mail_list_ids(p_mailbox bigint, p_view text, p_folder bigint, p_label bigint, p_unread boolean,
                                            p_before_at timestamptz, p_before_id bigint, p_limit integer)
    RETURNS TABLE (row_kind text, row_id bigint, row_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
#variable_conflict use_column
DECLARE me bigint := app_current_member_id(); behalf bigint := NULLIF(current_setting('app.on_behalf_of', true), '')::bigint;
        human boolean := app_member_kind() = 'human'; viewer boolean; v text := COALESCE(p_view, CASE WHEN p_folder IS NOT NULL THEN 'folder'
        WHEN p_label IS NOT NULL THEN 'label' END);
        f_inbox bigint; f_sent bigint; f_drafts bigint; f_junk bigint; f_trash bigint;
BEGIN
    IF ml_level_rank(ml_level(p_mailbox)) = 0 THEN RETURN; END IF;
    IF v IS NULL OR v NOT IN ('inbox', 'starred', 'snoozed', 'sent', 'drafts', 'scheduled', 'outbox', 'all', 'spam', 'trash', 'folder', 'label') THEN
        RAISE EXCEPTION 'No such view' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF v = 'folder' AND NOT EXISTS (SELECT 1 FROM mail_folders WHERE id = p_folder AND mailbox_id = p_mailbox) THEN RETURN; END IF;
    IF v = 'label' AND NOT EXISTS (SELECT 1 FROM tags WHERE id = p_label AND (mailbox_id = p_mailbox OR mailbox_id IS NULL)) THEN RETURN; END IF;
    p_limit := LEAST(GREATEST(COALESCE(p_limit, 50), 1), 10001);
    IF v IN ('scheduled', 'outbox') THEN
        RETURN QUERY
        SELECT 'outbound'::text, o.id, COALESCE(o.send_after, o.created_at)
          FROM outbound_email o
         WHERE o.mailbox_id = p_mailbox
           AND ((v = 'scheduled' AND o.status = 'queued' AND o.send_after > now() + interval '30 seconds')
             OR (v = 'outbox' AND o.status IN ('sending', 'deferred', 'failed', 'held', 'refused')))
           AND (p_before_at IS NULL OR (COALESCE(o.send_after, o.created_at), o.id) < (p_before_at, COALESCE(p_before_id, 9223372036854775807)))
         ORDER BY COALESCE(o.send_after, o.created_at) DESC, o.id DESC
         LIMIT p_limit;
        RETURN;
    END IF;
    viewer := ml_read_mode(p_mailbox, me, behalf) = 'viewer_read';
    SELECT max(f.id) FILTER (WHERE f.special_use = 'inbox'), max(f.id) FILTER (WHERE f.special_use = 'sent'),
           max(f.id) FILTER (WHERE f.special_use = 'drafts'), max(f.id) FILTER (WHERE f.special_use = 'junk'),
           max(f.id) FILTER (WHERE f.special_use = 'trash')
      INTO f_inbox, f_sent, f_drafts, f_junk, f_trash
      FROM mail_folders f WHERE f.mailbox_id = p_mailbox;
    RETURN QUERY
    SELECT 'thread'::text, t.id, t.last_message_at
      FROM mail_threads t
      CROSS JOIN LATERAL ml_thread_state(t.id) st
     WHERE t.mailbox_id = p_mailbox
       AND (human OR NOT st.sensitive)
       AND (p_before_at IS NULL OR (t.last_message_at, t.id) < (p_before_at, COALESCE(p_before_id, 9223372036854775807)))
       AND CASE v
             WHEN 'inbox'   THEN f_inbox = ANY (t.folder_ids) AND st.snoozed_until IS NULL
             WHEN 'starred' THEN t.flagged
             WHEN 'snoozed' THEN st.snoozed_until IS NOT NULL
             WHEN 'sent'    THEN f_sent = ANY (t.folder_ids)
             WHEN 'drafts'  THEN f_drafts = ANY (t.folder_ids)
             WHEN 'spam'    THEN f_junk = ANY (t.folder_ids)
             WHEN 'trash'   THEN f_trash = ANY (t.folder_ids)
             WHEN 'all'     THEN EXISTS (SELECT 1 FROM unnest(t.folder_ids) x WHERE x <> COALESCE(f_trash, -1) AND x <> COALESCE(f_junk, -1))
             WHEN 'folder'  THEN p_folder = ANY (t.folder_ids)
             WHEN 'label'   THEN EXISTS (SELECT 1 FROM mail_messages m JOIN record_tags rt ON rt.record_type = 'mail_message' AND rt.record_id = m.message_key_id
                                          WHERE m.thread_id = t.id AND rt.tag_id = p_label)
           END
       AND (NOT COALESCE(p_unread, false) OR
            CASE WHEN viewer THEN EXISTS (SELECT 1 FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
                                            WHERE m.thread_id = t.id AND NOT m.seen AND COALESCE(f.special_use, '') NOT IN ('trash', 'junk', 'sent', 'drafts')
                                              AND NOT EXISTS (SELECT 1 FROM mail_viewer_reads r WHERE r.member_id = me AND r.message_key_id = m.message_key_id))
                 ELSE t.unread_count > 0 END)
     ORDER BY t.last_message_at DESC, t.id DESC
     LIMIT p_limit;
END$$;

-- Is a conversation unread for the caller (the server's flag, or their own marks where ml_read_mode says so)?
CREATE OR REPLACE FUNCTION ml_thread_unread_for(p_thread bigint, p_viewer boolean, p_member bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE WHEN p_viewer THEN EXISTS (SELECT 1 FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
                                            WHERE m.thread_id = p_thread AND NOT m.seen AND COALESCE(f.special_use, '') NOT IN ('trash', 'junk', 'sent', 'drafts')
                                              AND NOT EXISTS (SELECT 1 FROM mail_viewer_reads r WHERE r.member_id = p_member AND r.message_key_id = m.message_key_id))
                ELSE (SELECT unread_count > 0 FROM mail_threads WHERE id = p_thread) END;
$$;

-- The rows of one page, in the list's shape (mail-list.md). p_limit 1–100.
CREATE OR REPLACE FUNCTION ml_mail_list(p_mailbox bigint, p_view text, p_folder bigint, p_label bigint, p_unread boolean,
                                        p_before_at timestamptz, p_before_id bigint, p_limit integer)
    RETURNS TABLE (thread_id bigint, subject text, participants text[], names jsonb, last_message_at timestamptz, message_count integer,
                   unread boolean, flagged boolean, has_attachments boolean, snippet text, labels jsonb, snoozed_until timestamptz,
                   sensitive boolean, agent_badge text, latest_message bigint, outbound_id bigint, outbound_status text, send_after timestamptz,
                   hold_reasons text[])
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
#variable_conflict use_column
DECLARE me bigint := app_current_member_id(); behalf bigint := NULLIF(current_setting('app.on_behalf_of', true), '')::bigint; viewer boolean;
BEGIN
    IF ml_level_rank(ml_level(p_mailbox)) = 0 THEN RETURN; END IF;
    viewer := ml_read_mode(p_mailbox, me, behalf) = 'viewer_read';
    RETURN QUERY
    WITH ids AS (SELECT * FROM ml_mail_list_ids(p_mailbox, p_view, p_folder, p_label, p_unread, p_before_at, p_before_id,
                                                  LEAST(GREATEST(COALESCE(p_limit, 50), 1), 100)))
    SELECT t.id, t.subject, t.participants,
           COALESCE((SELECT jsonb_object_agg(n.a, n.nm) FROM (SELECT DISTINCT ON (lower(m.from_address::text)) lower(m.from_address::text) AS a, m.from_name AS nm
                                                               FROM mail_messages m WHERE m.thread_id = t.id AND m.from_name IS NOT NULL
                                                              ORDER BY lower(m.from_address::text), m.received_at DESC) n), '{}'::jsonb),
           t.last_message_at, t.message_count, ml_thread_unread_for(t.id, viewer, me), t.flagged, t.has_attachments, t.snippet,
           COALESCE((SELECT jsonb_agg(DISTINCT jsonb_build_object('id', g.id, 'label', g.label, 'colour', g.colour))
                       FROM mail_messages m JOIN record_tags rt ON rt.record_type = 'mail_message' AND rt.record_id = m.message_key_id
                       JOIN tags g ON g.id = rt.tag_id AND g.archived_at IS NULL
                      WHERE m.thread_id = t.id), '[]'::jsonb),
           st.snoozed_until, st.sensitive,
           CASE WHEN EXISTS (SELECT 1 FROM outbound_email o JOIN mail_messages m ON m.message_key_id = o.reply_to_key_id
                              WHERE m.thread_id = t.id AND o.status = 'held') THEN 'awaiting review'
                ELSE (SELECT 'drafted by ' || mb.display_name FROM outbound_email o JOIN mail_messages m ON m.message_id_header = o.message_id AND m.thread_id = t.id
                        JOIN members mb ON mb.id = o.sender_member_id AND mb.member_kind = 'agent'
                       WHERE o.mailbox_id = p_mailbox AND o.status = 'sent' ORDER BY o.id DESC LIMIT 1) END,
           (SELECT m.message_key_id FROM mail_messages m WHERE m.thread_id = t.id ORDER BY m.received_at DESC, m.id DESC LIMIT 1),
           NULL::bigint, NULL::text, NULL::timestamptz, NULL::text[]
      FROM ids JOIN mail_threads t ON ids.row_kind = 'thread' AND t.id = ids.row_id
      CROSS JOIN LATERAL ml_thread_state(t.id) st
    UNION ALL
    SELECT ml_key_thread(o.reply_to_key_id), o.subject, o.to_addresses, '{}'::jsonb, COALESCE(o.send_after, o.created_at), 1, false, false,
           o.has_attachments, left(o.body_text, 200), '[]'::jsonb, NULL::timestamptz, false,
           CASE WHEN o.status = 'held' THEN 'awaiting review' END, o.reply_to_key_id, o.id, o.status, o.send_after, o.hold_reasons
      FROM ids JOIN outbound_email o ON ids.row_kind = 'outbound' AND o.id = ids.row_id
     ORDER BY 5 DESC, 1 DESC NULLS LAST;
END$$;

-- How many rows a view holds, exact up to 10,000 ("more than 10,000" beyond: 10,001).
CREATE OR REPLACE FUNCTION ml_mail_list_total(p_mailbox bigint, p_view text, p_folder bigint, p_label bigint, p_unread boolean) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT count(*)::integer FROM ml_mail_list_ids(p_mailbox, p_view, p_folder, p_label, p_unread, NULL, NULL, 10001);
$$;

-- Unread conversations in the Inbox for the caller (the rail's count): the server's flag, or the caller's own marks (ml_read_mode).
CREATE OR REPLACE FUNCTION ml_unread_count(p_mailbox bigint) RETURNS integer
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF ml_level_rank(ml_level(p_mailbox)) = 0 THEN RETURN NULL; END IF;
    RETURN (SELECT count(*)::integer FROM ml_mail_list_ids(p_mailbox, 'inbox', NULL, NULL, true, NULL, NULL, 10001));
END$$;

-- The figures a mailbox's rail and the mailbox_overview tool show.
CREATE OR REPLACE FUNCTION ml_mailbox_counts(p_mailbox bigint) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE c jsonb;
BEGIN
    IF ml_level_rank(ml_level(p_mailbox)) = 0 THEN RETURN NULL; END IF;
    SELECT jsonb_build_object(
        'inbox_unread', ml_unread_count(p_mailbox),
        'inbox_total', ml_mail_list_total(p_mailbox, 'inbox', NULL, NULL, false),
        'starred', ml_mail_list_total(p_mailbox, 'starred', NULL, NULL, false),
        'snoozed', ml_mail_list_total(p_mailbox, 'snoozed', NULL, NULL, false),
        'next_wake', (SELECT min(s.snoozed_until) FROM mail_thread_states s WHERE s.mailbox_id = p_mailbox AND s.snoozed_until IS NOT NULL),
        'drafts', ml_mail_list_total(p_mailbox, 'drafts', NULL, NULL, false),
        'scheduled', ml_mail_list_total(p_mailbox, 'scheduled', NULL, NULL, false),
        'deferred', (SELECT count(*) FROM outbound_email o WHERE o.mailbox_id = p_mailbox AND o.status = 'deferred'),
        'failed', (SELECT count(*) FROM outbound_email o WHERE o.mailbox_id = p_mailbox AND o.status = 'failed'),
        'held', (SELECT count(*) FROM outbound_email o WHERE o.mailbox_id = p_mailbox AND o.status = 'held'),
        'unscreened', (SELECT count(*) FROM agent_dispatches d WHERE d.mailbox_id = p_mailbox AND d.status = 'unscreened'),
        'spam', ml_mail_list_total(p_mailbox, 'spam', NULL, NULL, false),
        'seq', COALESCE((SELECT seq FROM mail_mailbox_changes WHERE mailbox_id = p_mailbox), 0))
      INTO c;
    RETURN c;
END$$;

-- ------------------------------------------------------------------------------------------------- the admin (home-admin.md)
CREATE OR REPLACE FUNCTION ml_settings_save(p_by bigint, p_values jsonb) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE allowed text[] := ARRAY['undo_send_seconds', 'images_default', 'index_text_max_kb', 'max_attachments_mb', 'draft_save_seconds',
            'poll_visible_seconds', 'poll_hidden_seconds', 'held_expiry_hours', 'default_send_mode', 'default_first_contact_review',
            'default_attachments_review', 'default_max_external_per_day', 'default_max_per_hour', 'default_max_per_thread_per_day',
            'default_wake_on_mail', 'default_wake_from', 'default_disclosure', 'disclosure_text', 'agent_external_budget', 'hold_all_agents',
            'hold_all_external', 'mm3_identity_enforced', 'clerk_note_at'];
        bad text; o ml_settings%ROWTYPE; n ml_settings%ROWTYPE; before jsonb; after jsonb; k text; diff jsonb := '{}';
BEGIN
    PERFORM ml_require_person(p_by);
    PERFORM ml_require_right(p_by, 'settings.manage');
    IF p_values IS NULL OR jsonb_typeof(p_values) <> 'object' THEN RAISE EXCEPTION 'The settings come as an object' USING ERRCODE = 'invalid_parameter_value'; END IF;
    SELECT string_agg(key, ', ') INTO bad FROM jsonb_object_keys(p_values) key WHERE key <> ALL (allowed);
    IF bad IS NOT NULL THEN RAISE EXCEPTION 'Not a setting saved here: %', bad USING ERRCODE = 'invalid_parameter_value'; END IF;
    SELECT * INTO o FROM ml_settings WHERE id = 1 FOR UPDATE;
    n := jsonb_populate_record(o, p_values);
    UPDATE ml_settings SET undo_send_seconds = n.undo_send_seconds, images_default = n.images_default, index_text_max_kb = n.index_text_max_kb,
           max_attachments_mb = n.max_attachments_mb, draft_save_seconds = n.draft_save_seconds, poll_visible_seconds = n.poll_visible_seconds,
           poll_hidden_seconds = n.poll_hidden_seconds, held_expiry_hours = n.held_expiry_hours, default_send_mode = n.default_send_mode,
           default_first_contact_review = n.default_first_contact_review, default_attachments_review = n.default_attachments_review,
           default_max_external_per_day = n.default_max_external_per_day, default_max_per_hour = n.default_max_per_hour,
           default_max_per_thread_per_day = n.default_max_per_thread_per_day, default_wake_on_mail = n.default_wake_on_mail,
           default_wake_from = n.default_wake_from, default_disclosure = n.default_disclosure, disclosure_text = n.disclosure_text,
           agent_external_budget = n.agent_external_budget, hold_all_agents = n.hold_all_agents, hold_all_external = n.hold_all_external,
           mm3_identity_enforced = n.mm3_identity_enforced, clerk_note_at = n.clerk_note_at
     WHERE id = 1;
    before := to_jsonb(o); after := to_jsonb(n);
    FOREACH k IN ARRAY allowed LOOP
        IF before->k IS DISTINCT FROM after->k THEN
            diff := diff || jsonb_build_object(k, jsonb_build_object('before', CASE WHEN k = 'disclosure_text' THEN to_jsonb(length(before->>k)) ELSE before->k END,
                                                                     'after', CASE WHEN k = 'disclosure_text' THEN to_jsonb(length(after->>k)) ELSE after->k END));
        END IF;
    END LOOP;
    RETURN diff;
END$$;

CREATE OR REPLACE FUNCTION ml_notice_save(p_by bigint, p_text text) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v integer;
BEGIN
    PERFORM ml_require_person(p_by);
    PERFORM ml_require_right(p_by, 'settings.manage');
    UPDATE ml_settings SET notice_text = btrim(p_text) WHERE id = 1 RETURNING notice_version INTO v;
    RETURN v;
END$$;

CREATE OR REPLACE FUNCTION ml_mailbox_reindex(p_mailbox bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    PERFORM ml_require_person(p_by);
    PERFORM ml_require_right(p_by, 'settings.manage');
    IF NOT EXISTS (SELECT 1 FROM mailboxes WHERE id = p_mailbox) THEN RAISE EXCEPTION 'No such mailbox' USING ERRCODE = 'no_data_found'; END IF;
    UPDATE mail_messages SET body_text = NULL, text_indexed_at = NULL WHERE mailbox_id = p_mailbox AND (body_text IS NOT NULL OR text_indexed_at IS NOT NULL);
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

CREATE OR REPLACE FUNCTION ml_dispatch_retry(p_dispatch bigint, p_by bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM ml_require_person(p_by);
    PERFORM ml_require_right(p_by, 'agents.manage');
    UPDATE agent_dispatches SET status = 'queued', next_attempt_at = now() WHERE id = p_dispatch AND status = 'failed';
    IF NOT FOUND THEN RAISE EXCEPTION 'Only a failed mail turn is tried again' USING ERRCODE = 'check_violation'; END IF;
END$$;

-- Per mailbox, counts only (no subject, no correspondent): a Mail admin's; nothing for anyone else.
CREATE OR REPLACE FUNCTION ml_sync_health()
    RETURNS TABLE (mailbox_id bigint, address text, kind text, sync_state text, last_sync_at timestamptz, messages integer, text_indexed integer,
                   quota_used_bytes bigint, quota_bytes bigint, credential_status text, last_error text, last_error_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
#variable_conflict use_column
BEGIN
    IF NOT ml_is_mail_admin() THEN RETURN; END IF;
    RETURN QUERY
    SELECT b.id, b.address::text, b.kind, b.sync_state, b.last_sync_at, COALESCE(c.n, 0)::integer, COALESCE(c.t, 0)::integer, b.quota_used_bytes,
           b.quota_bytes, (SELECT k.status FROM mailbox_credentials k WHERE k.id = b.credential_id), b.last_error, b.last_error_at
      FROM mailboxes b
      LEFT JOIN (SELECT m.mailbox_id, count(*) AS n, count(*) FILTER (WHERE m.text_indexed_at IS NOT NULL) AS t FROM mail_messages m GROUP BY m.mailbox_id) c
        ON c.mailbox_id = b.id
     ORDER BY b.address;
END$$;

-- ------------------------------------------------------------------------------------------------- grants
GRANT SELECT ON mail_mailbox_changes, mail_thread_tombstones TO malumail_rw;
REVOKE ALL ON FUNCTION ml_mailbox_next_seq(bigint), ml_mailbox_pending_seq(bigint), ml_touch_thread(bigint, bigint), ml_mailbox_touch(bigint, bigint[]),
    ml_tombstones_prune(), ml_folder_reset(bigint, bigint), ml_message_moved(bigint, bigint, bigint, bigint), ml_settings_save(bigint, jsonb),
    ml_notice_save(bigint, text), ml_mailbox_reindex(bigint, bigint), ml_dispatch_retry(bigint, bigint), ml_sync_health(),
    ml_mailbox_seq(bigint), ml_mailbox_changed(bigint, bigint, integer), ml_mail_list_ids(bigint, text, bigint, bigint, boolean, timestamptz, bigint, integer),
    ml_thread_unread_for(bigint, boolean, bigint),
    ml_mail_list(bigint, text, bigint, bigint, boolean, timestamptz, bigint, integer), ml_mail_list_total(bigint, text, bigint, bigint, boolean),
    ml_unread_count(bigint), ml_mailbox_counts(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_mailbox_touch(bigint, bigint[]), ml_tombstones_prune(), ml_folder_reset(bigint, bigint),
    ml_message_moved(bigint, bigint, bigint, bigint), ml_settings_save(bigint, jsonb), ml_notice_save(bigint, text), ml_mailbox_reindex(bigint, bigint),
    ml_dispatch_retry(bigint, bigint) TO malumail_rw;
GRANT EXECUTE ON FUNCTION ml_sync_health(), ml_mailbox_seq(bigint), ml_mailbox_changed(bigint, bigint, integer),
    ml_mail_list_ids(bigint, text, bigint, bigint, boolean, timestamptz, bigint, integer), ml_thread_unread_for(bigint, boolean, bigint),
    ml_mail_list(bigint, text, bigint, bigint, boolean, timestamptz, bigint, integer), ml_mail_list_total(bigint, text, bigint, bigint, boolean),
    ml_unread_count(bigint), ml_mailbox_counts(bigint) TO malumail_rw, malumail_records_ro;

COMMIT;
