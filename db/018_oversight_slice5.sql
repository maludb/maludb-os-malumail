-- 018: AGENTS' MAIL AND OVERSIGHT (slice 5, 2026-10-16) — what building the fourth exemplar found:
--
-- 1. A mail turn that keeps failing has no notice to send: sending-and-oversight.md §9.3 says a 5xx is backed off 1, 5, 30 minutes,
--    "then failed and a notice to the primary overseer", but db/007's notifications.kind admits no kind for it. `dispatch_failed` is
--    added (one notice per failed turn, record_type agent_dispatch; in-app — a person who wants it mailed adds it to their kinds).
-- 2. The records MCP's review_get lists a held message's attachments (name, type, size, sha256 — oversight.md, the tool surface), but the
--    records role reads only the mcp_* views and no view showed an outbound message's attachments. mcp_outbound_attachments: the
--    attachments of the outbound rows the caller sees (mcp_outbound's wall), never a storage path.
-- Additive: one CHECK widened, one view.
BEGIN;

ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_kind_check;
ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check
    CHECK (kind IN ('new_mail', 'held_mail', 'review_decided', 'unscreened', 'break_glass', 'permission_granted', 'credential_failed', 'sync_failed',
                    'quota', 'snooze_woke', 'send_failed', 'send_deferred', 'agent_replied', 'digest', 'dispatch_failed'));

CREATE OR REPLACE VIEW mcp_outbound_attachments WITH (security_barrier = true) AS
SELECT a.id, a.record_id AS outbound_id, a.filename, a.mime_type, a.byte_size, a.sha256, a.created_at
  FROM attachments a JOIN mcp_outbound o ON o.id = a.record_id
 WHERE a.record_type = 'outbound_email';
GRANT SELECT ON mcp_outbound_attachments TO malumail_records_ro, malumail_rw;

COMMIT;
