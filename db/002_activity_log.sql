-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "malumail" first). WHAT IS LOGGED IS WHAT PEOPLE AND AGENTS DO, NEVER WHAT MAIL SAYS (design §6.6,
-- D14): `before`/`after` carry ids, counts, levels, reasons and addresses' DOMAINS -- never a body, never a whole address of an
-- outsider, never a password, a token or a file path; a subject excerpt (<= 200 characters) only for an AGENT'S or a SHARED
-- mailbox, NOTHING of a person's mailbox's content. A key is named by its label. The ACCESS LOG of a mailbox (design §3) is this
-- table's rows on the mailbox by anyone but its owner -- every non-owner's list view (one a mailbox a half hour), conversation,
-- part, original, records-MCP read and K7 read -- read through mcp_mailbox_access_log (db/006+). Never logged: polling, the
-- owner's own list views, the mail service's sync passes.
--
-- THE ESTATE'S DATA MODEL (design §6.7): the contract table (memory.md / Consultant Tracking db/002) with token_id as Knowledge
-- db/002 appended it (Sales CRM, the Data Lake and Boards carry it too), and MaluMail's audit key APPENDED: mailbox_id bigint (every
-- event about a mailbox -- its mail, its oversight, its administration, a look at it by another -- a mailbox's TRAIL and its access
-- log are one index). No FK: history outlives a record, and mailboxes come in db/006+. `source` widens the contract's list with
-- 'system' (the worker's expiries and the mail service's own acts are no one's click) -- an enumeration widened, the shared-schema
-- rule's allowed change.
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application', 'system')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,                        -- the department the event concerns (a permission given to it)
    location_id     bigint,                        -- the contract's (unused: scope none)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now(),
    token_id        bigint,                        -- appended (Knowledge db/002): the token (mcp_access_tokens.id) an event ran under; its LABEL is in `after`, never the token
    -- appended (design §6.6): MaluMail's audit key (no FK — history outlives a record; mailboxes come in db/006+)
    mailbox_id      bigint                         -- the mailbox the event concerns (its mail, its oversight, its administration, a look at it by another)
);
CREATE INDEX activity_log_actor_idx    ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx   ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx   ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx     ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx  ON activity_log (request_id);
CREATE INDEX activity_log_token_idx    ON activity_log (token_id, occurred_at DESC) WHERE token_id IS NOT NULL;
CREATE INDEX activity_log_mailbox_idx  ON activity_log (mailbox_id, occurred_at DESC) WHERE mailbox_id IS NOT NULL;

GRANT INSERT, SELECT ON activity_log TO malumail_rw;
REVOKE ALL ON activity_log FROM malumail_records_ro, malumail_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO malumail_rw;

COMMIT;
