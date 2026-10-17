-- 021: CORRESPONDENTS (slice 8, 2026-10-17). docs/build-specs/correspondents.md "Keeping mail_correspondents": a message is RECEIVED once per
-- message key, never again for the same message in another folder or after a re-sync, and never when the mailbox itself wrote it.
-- db/008's ml_correspondent_received() counted whatever the mail service handed it, and the service handed it every new ROW (a message
-- in Inbox and then in Archive is two rows, one key). This adds the once-per-key ledger and one function the service calls.
-- Neither implementation of the wall is touched; no view changes.
BEGIN;

ALTER TABLE mail_message_keys ADD COLUMN correspondent_counted_at timestamptz;
-- every key that exists was seen by the old code: they are counted already
UPDATE mail_message_keys SET correspondent_counted_at = created_at;

-- Count the sender of an indexed message as received, once per message key. Answers whether it counted. Mail the mailbox wrote itself
-- (its address or one of its identities) is not "heard from"; Cc'd addresses are never touched here.
CREATE OR REPLACE FUNCTION ml_correspondent_received_for(p_message bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE m mail_messages%ROWTYPE; kid bigint;
BEGIN
    SELECT * INTO m FROM mail_messages WHERE id = p_message;
    IF NOT FOUND OR m.from_address IS NULL OR btrim(m.from_address::text) = '' THEN RETURN false; END IF;
    UPDATE mail_message_keys SET correspondent_counted_at = now()
     WHERE id = m.message_key_id AND correspondent_counted_at IS NULL RETURNING id INTO kid;
    IF kid IS NULL THEN RETURN false; END IF;
    IF lower(m.from_address::text) IN (SELECT lower(b.address::text) FROM mailboxes b WHERE b.id = m.mailbox_id
                                       UNION SELECT lower(i.address::text) FROM mail_identities i WHERE i.mailbox_id = m.mailbox_id) THEN
        RETURN false;
    END IF;
    PERFORM ml_correspondent_received(m.mailbox_id, m.from_address::text, m.from_name, COALESCE(m.received_at, now()));
    RETURN true;
END$$;

-- The K7 access-log row (db/014), now refusing what the kernel's headers should never carry: a consumer that is not an application key, and an
-- agent that is not a member of this installation (the actor is then NULL and so is the agent named in the row: nothing unverified is recorded as
-- fact). Same signature, same grant (the records role alone). The row is the mailbox's own access-log line: consumer, agent, count, the domain
-- asked about — never a subject, an address or a word of mail.
CREATE OR REPLACE FUNCTION ml_log_k7_read(p_mailboxes bigint[], p_consumer text, p_consumer_agent bigint, p_counts integer[], p_domain text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE agent bigint;
BEGIN
    IF cardinality(p_mailboxes) IS DISTINCT FROM cardinality(p_counts) THEN
        RAISE EXCEPTION 'one count per mailbox' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    IF p_consumer IS NULL OR p_consumer !~ '^[a-z0-9_]{1,60}$' THEN
        RAISE EXCEPTION 'A K7 read names its consumer (an application key)' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    SELECT id INTO agent FROM members WHERE id = p_consumer_agent AND member_kind = 'agent';
    INSERT INTO activity_log (actor_member_id, source, action, entity_type, entity_id, mailbox_id, after)
    SELECT agent, 'application', 'k7.read', 'mailbox', u.m, u.m,
           jsonb_build_object('application', 'malumail', 'consumer', p_consumer, 'consumer_agent', agent, 'count', u.c,
                              'domain', NULLIF(lower(left(btrim(COALESCE(p_domain, '')), 255)), ''))
      FROM unnest(p_mailboxes, p_counts) AS u(m, c)
     WHERE u.m IS NOT NULL;
END$$;

REVOKE ALL ON FUNCTION ml_correspondent_received_for(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_correspondent_received_for(bigint) TO malumail_rw;

COMMIT;
