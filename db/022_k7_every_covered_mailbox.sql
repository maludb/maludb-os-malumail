-- 022: EVERY K7 ANSWER IS LOGGED IN EVERY MAILBOX THE SHARE COVERS (the owner, 2026-10-17; docs/build-specs/correspondents.md decision 2).
-- db/014/021 wrote one k7.read row per mailbox an answer DREW ON, so a question that found nothing left no line anywhere — and an empty
-- answer still tells a consumer "nothing with X in the shared mailboxes". Now one row per mailbox the share covers (shared with applications,
-- active, not suspended — exactly ml_correspondence_index()'s rule), with the count sent from it: 0 when nothing came from it. A mailbox an
-- answer drew on is always logged even if its switch changed meanwhile. Same signature, same grant (the records role alone); the row still
-- carries the consumer, its agent, the count and the domain asked about — never a subject, an address or a word of mail.
BEGIN;

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
      FROM (SELECT a.m, sum(a.c)::integer AS c
              FROM (SELECT x.m, x.c FROM unnest(COALESCE(p_mailboxes, '{}'), COALESCE(p_counts, '{}')) AS x(m, c) WHERE x.m IS NOT NULL
                    UNION ALL
                    SELECT b.id, 0 FROM mailboxes b WHERE b.share_with_apps AND b.active AND b.suspended_at IS NULL) a
             GROUP BY a.m) u
     ORDER BY u.m;
END$$;

COMMIT;
