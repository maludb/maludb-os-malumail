-- 019: THE "BACK FROM SNOOZE" CHIP (slice 6, 2026-10-16) — search-labels-snooze.md Divergence 2 and decision 4: a conversation that
-- woke from a snooze carries a chip until it is opened. db/010's ml_snoozes_due() cleared the snooze and kept nothing, so the page had
-- nothing to show the chip from. The state row gains `woke_at`; the worker's pass sets it as it clears the snooze; opening the
-- conversation clears it. Two small functions read and clear it behind the wall. Additive: one column, ml_snoozes_due() re-created with
-- the same signature, two functions.
BEGIN;

ALTER TABLE mail_thread_states ADD COLUMN woke_at timestamptz;

CREATE OR REPLACE FUNCTION ml_snoozes_due() RETURNS TABLE (mailbox_id bigint, anchor_key_id bigint, back_to text, snoozed_by bigint)
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    UPDATE mail_thread_states s SET snoozed_until = NULL, snoozed_from_folder = NULL, snoozed_by = NULL, woke_at = now()
      FROM (SELECT id, snoozed_from_folder AS f, snoozed_by AS b FROM mail_thread_states WHERE snoozed_until <= now() FOR UPDATE SKIP LOCKED) d
     WHERE s.id = d.id
    RETURNING s.mailbox_id, s.anchor_key_id, d.f, d.b;
$$;

-- Which of these conversations woke from a snooze and have not been opened since (only those the caller may open).
CREATE OR REPLACE FUNCTION ml_woke_threads(p_threads bigint[]) RETURNS SETOF bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT DISTINCT m.thread_id
      FROM mail_messages m JOIN mail_thread_states s ON s.anchor_key_id = m.message_key_id
     WHERE m.thread_id = ANY (p_threads) AND s.woke_at IS NOT NULL AND ml_level_rank(ml_level(m.mailbox_id)) >= 1;
$$;

-- Opening the conversation clears the chip (for everyone: it is the conversation's, not a person's).
CREATE OR REPLACE FUNCTION ml_woke_clear(p_thread bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE mb bigint; n integer;
BEGIN
    SELECT mailbox_id INTO mb FROM mail_threads WHERE id = p_thread;
    IF mb IS NULL OR ml_level_rank(ml_level(mb)) < 1 THEN RETURN 0; END IF;
    UPDATE mail_thread_states SET woke_at = NULL
     WHERE woke_at IS NOT NULL AND anchor_key_id IN (SELECT m.message_key_id FROM mail_messages m WHERE m.thread_id = p_thread);
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- Unsnooze now (search-labels-snooze.md: "unsnooze does the same at once"): the same state change the worker's due pass makes, for one
-- conversation the caller may triage in; answers the anchors and where each came from so the mail service can move the mail back.
CREATE OR REPLACE FUNCTION ml_thread_unsnooze(p_mailbox bigint, p_thread bigint, p_by bigint) RETURNS TABLE (anchor_key_id bigint, back_to text)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE why text;
BEGIN
    why := ml_mutation_refusal(p_mailbox, p_by, NULLIF(current_setting('app.on_behalf_of', true), '')::bigint, NULL, 'snooze');
    IF why IS NOT NULL THEN RAISE EXCEPTION '%', why USING ERRCODE = CASE WHEN why = 'No such mailbox.' THEN 'no_data_found' ELSE 'insufficient_privilege' END; END IF;
    RETURN QUERY
    WITH d AS (SELECT s.id, s.snoozed_from_folder AS f FROM mail_thread_states s
                WHERE s.mailbox_id = p_mailbox AND s.snoozed_until IS NOT NULL
                  AND s.anchor_key_id IN (SELECT m.message_key_id FROM mail_messages m WHERE m.thread_id = p_thread) FOR UPDATE)
    UPDATE mail_thread_states s SET snoozed_until = NULL, snoozed_from_folder = NULL, snoozed_by = NULL, woke_at = now()
      FROM d WHERE s.id = d.id RETURNING s.anchor_key_id, d.f;
END$$;

REVOKE ALL ON FUNCTION ml_woke_threads(bigint[]), ml_woke_clear(bigint), ml_thread_unsnooze(bigint, bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_woke_threads(bigint[]), ml_woke_clear(bigint) TO malumail_rw, malumail_records_ro;
GRANT EXECUTE ON FUNCTION ml_thread_unsnooze(bigint, bigint, bigint) TO malumail_rw;

COMMIT;
