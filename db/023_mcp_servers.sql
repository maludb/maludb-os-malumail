-- 023: PHASE 4 — the two MCP servers' last database pieces (docs/malumail-mcp-tool-surface.md; docs/build-specs: none, the surface is the contract).
--  * mcp_admit_agent() and mcp_member_kind(): the kit's two functions mcp/db.py and mcp/server_common.py call (every sibling carries them; MaluMail's
--    db/011 did not). An agent the kernel's run facts vouch for and the mirror knows but has not admitted yet is admitted on its first contact; a
--    member is "known" to the servers only when active with a capability. The grant lives in the kernel — this only lets the mirror follow its word.
--  * mcp_activity_log: the activity server's one window on activity_log (the wall in SQL). A mailbox-bound row is visible to a caller who may open
--    that mailbox (ml_my_mailbox_ids('view')); a Mail admin also reads the ADMINISTRATION of any mailbox (alias, domain, policy, permission,
--    mailbox.create/connect/…) — never a person's list views or mail acts; a row about no mailbox is its actor's own, or a Mail admin's.
--    Payloads already carry ids, counts and domains, never mail (db/002's rule).
--  * the activity role reads the views its queries join (mcp_mailboxes, mcp_members, mcp_outbound, mcp_mail_reviews, mcp_dispatches,
--    mcp_mailbox_access_log) and may write the one access-log row (ml_log_mcp_read) — a read of a mailbox's trail is a look at the mailbox too.
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO malumail_records_ro, malumail_activity_ro, malumail_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO malumail_records_ro, malumail_activity_ro, malumail_rw;

CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT l.id AS log_id, l.occurred_at, l.actor_member_id, l.source, l.action, l.screen, l.entity_type, l.entity_id, l.mailbox_id, l.agent_run_id,
       l.token_id, l.before, l.after
  FROM activity_log l
 WHERE (SELECT ml_is_member_here())
   AND l.action <> 'screen.view'
   AND ((l.mailbox_id IS NOT NULL AND (l.mailbox_id = ANY ((SELECT ml_my_mailbox_ids('view'))::bigint[])
                                        OR ((SELECT ml_is_mail_admin())
                                            AND (l.action ~ '^(alias|domain|policy|permission)\.'
                                                 OR l.action IN ('mailbox.create', 'mailbox.connect', 'mailbox.password_reset', 'mailbox.pause', 'mailbox.quota',
                                                                 'mailbox.reactivate', 'mailbox.reindex', 'mailbox.resume', 'mailbox.resync', 'mailbox.settings',
                                                                 'mailbox.suspend', 'mailbox.update')))))
     OR (l.mailbox_id IS NULL AND (l.actor_member_id = app_current_member_id() OR (SELECT ml_is_mail_admin()))));

GRANT SELECT ON mcp_activity_log TO malumail_activity_ro;
GRANT SELECT ON mcp_mailboxes, mcp_members, mcp_outbound, mcp_mail_reviews, mcp_dispatches, mcp_mailbox_access_log, mcp_settings TO malumail_activity_ro;
GRANT EXECUTE ON FUNCTION ml_log_mcp_read(bigint, text, bigint, bigint) TO malumail_activity_ro;

-- find_aliases (tool surface, question 15): every forwarding alias for a Mail admin; for anyone else only those delivering to a mailbox they hold `full`
-- on (their own mail's forwarding is theirs to know). mcp_aliases stays the Mail admins' view — the admin pages read it.
CREATE OR REPLACE FUNCTION ml_aliases_visible()
    RETURNS TABLE (id bigint, address text, destinations text[], active boolean, synced_at timestamptz, forwards_outside boolean)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT a.id, a.address::text, a.destinations::text[], a.active, a.synced_at,
           EXISTS (SELECT 1 FROM unnest(a.destinations) x WHERE NOT ml_is_internal_address(x::text))
      FROM ml_aliases a
     WHERE ml_is_member_here()
       AND (ml_is_mail_admin()
            OR EXISTS (SELECT 1 FROM unnest(a.destinations) x JOIN mailboxes b ON lower(b.address::text) = lower(x::text)
                        WHERE b.id = ANY (ml_my_mailbox_ids('full'))));
$$;
REVOKE ALL ON FUNCTION ml_aliases_visible() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ml_aliases_visible() TO malumail_records_ro, malumail_rw;

COMMIT;
