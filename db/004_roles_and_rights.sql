-- 004: MaluMail's roles and the rights they give (kernel db/145; roles-and-rights.md; the design §2, §3, D2).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the claims and
-- the feed carry them back into members.roles. What a role lets its holder do is the application's to enforce:
-- ml_has_right(right) answers from the catalogue and the mirror. The base role's KEY IS `user` on purpose (Mail user): the
-- installer's --grant-standing-departments gives a department the role keyed member/user/write.
--
-- TWO ROLES (D2, the owner approved 2026-10-11): Mail user (`user`, capability write) and Mail admin (`admin`, capability admin,
-- is_admin). MaluMail has NO read-only role: a grant the kernel has sent no roles for reads `admin` as Mail admin and `write` as
-- Mail user, and a `read` grant as NO ROLE AT ALL (fail closed — a read grant must never send mail; the super-admin gives the Mail
-- user role).
--
-- WHO MAY HOLD A RIGHT AT ALL (`held_by`, appended): `anyone` a role gives it to; `people` — a person's decision, never an agent's
-- whatever role it holds (design §3: overseeing an agent's mail — reviews, release, known senders, the policy, the pause, taking a
-- conversation over); `super_admins` — THE ADMIN RIGHTS ARE A SUPER-ADMIN'S (D2: "super admins … create mailbox and forwarding
-- accounts"): ml_member_has_right() refuses them to anyone who is not an active human super-admin of the kernel, whatever roles they
-- hold — an agent never, and a person the role was given to who is not a super-admin is refused the admin screens, told why
-- (ml_right_refusal()). The kernel gives every super-admin the is_admin role everywhere; ml_member_roles() adds it here too. The
-- schema's own functions (db/006+) refuse the same decisions to agents again, at the act — this is the first wall, not the only one.
--
-- RIGHTS ARE APPLICATION-WIDE CAPABILITIES. WHICH MAILBOX a caller opens, and at what level, is NOT a right: it is the mailbox's
-- level for the caller (view / triage / send / full — the owner, the mailbox's permission rows, the department rows for people,
-- the Mail admins on an AGENT'S mailbox only; design §3), answered by ml_mailbox_level() (db/006+). HOLDING A ROLE NEVER OPENS A
-- PERSON'S MAILBOX: not Mail user, not Mail admin, not a super-admin — only a row its owner made, or break-glass with a reason.
-- Oversight is a LEVEL (a person's `full` on an agent's mailbox), not a role.
--
-- THE ESTATE'S DATA MODEL (design §6.7): the shape is Consultant Tracking db/004 = DocCloud db/004 = Sales CRM db/004 = the Data Lake
-- db/004 = Boards db/004 (ct_/bd_ -> ml_), with ml_rights APPENDING `held_by` (an appended column, the shared-schema rule's
-- allowed change); the roles and rights are this application's; ml_member_roles(member) is Inventory db/004's convenience (the
-- roles a member holds, resolved); ml_member_has_right(member, right) the Data Lake's.
BEGIN;

CREATE TABLE ml_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0,
    -- appended (design §3, D2): who may hold the right at all, whatever roles they hold
    held_by     text NOT NULL DEFAULT 'anyone' CHECK (held_by IN ('anyone', 'people', 'super_admins'))
);
CREATE TABLE ml_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX ml_roles_one_admin ON ml_roles (is_admin) WHERE is_admin;
CREATE TABLE ml_role_rights (
    role_key  text NOT NULL REFERENCES ml_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES ml_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO ml_rights (right_key, description, sort_order, held_by) VALUES
 -- Mail user (design §2, §3)
 ('mail.use',         'Read, search and write their own mailbox and every mailbox shared with them, at the level given (view, triage, send or full); share a mailbox they own; their signatures, templates and settings', 1, 'anyone'),
 ('mail.oversee',     'Oversee the agent mailboxes they hold `full` on: review held mail, release unscreened mail, mark a sender known, set the agent''s policy, pause it, take a conversation over (people only — refused to every agent)', 2, 'people'),
 ('tokens.own',       'Mint and revoke their own tokens for the two read MCP servers (a client''s own AI tool reads what they may)', 3, 'anyone'),
 -- Mail admin — a super-admin's (D2)
 ('domains.manage',   'Choose which of the MaluMail customer''s domains this business serves and which count as internal', 4, 'super_admins'),
 ('mailboxes.manage', 'Create, suspend, reactivate, resize and reset mailboxes and connect existing ones; set a shared mailbox''s rows and an agent mailbox''s overseers — never opens a person''s mail', 5, 'super_admins'),
 ('aliases.manage',   'Create, change and delete forwarding addresses, outside destinations flagged', 6, 'super_admins'),
 ('access.audit',     'Read every mailbox''s access log and the whole review queue; grant break-glass access to a person''s mailbox with a reason, at most 30 days, the owner told', 7, 'super_admins'),
 ('settings.manage',  'Run MaluMail: the defaults, the agents'' business-wide budget and its two switches, the monitoring notice, sync health and credentials', 8, 'super_admins'),
 ('agents.manage',    'See every agent''s mail turns (Retry a failed one) and link to the kernel for hires and grants', 9, 'super_admins');

INSERT INTO ml_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('user',  'Mail user',  'Reads and writes their own mailbox and the mailboxes shared with them at the level given — view, triage, send or full — and oversees the agent mailboxes they hold full on.', 'write', false, 1),
 ('admin', 'Mail admin', 'Administers the business''s mail: domains, mailboxes, forwarding addresses, access, sync and settings. Works only for a super-admin of the OS, and never opens a person''s mailbox by holding it.', 'admin', true, 2);

INSERT INTO ml_role_rights (role_key, right_key)
SELECT 'user', r FROM unnest(ARRAY['mail.use','mail.oversee','tokens.own']) r
UNION ALL SELECT 'admin', right_key FROM ml_rights;

-- The roles a member holds, resolved: the kernel's list; a super-admin always holds admin; a member the kernel has not yet sent
-- roles for is read from the capability (admin = admin, write = user, read = NO ROLE — MaluMail has no read-only role). Empty for
-- nobody.
CREATE OR REPLACE FUNCTION ml_member_roles(p_member_id bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r text[];
BEGIN
    SELECT CASE
             WHEN m.member_kind = 'human' AND m.business_role = 'super_admin' THEN (SELECT array_agg(DISTINCT x) FROM unnest(m.roles || ARRAY['admin']) x)
             WHEN cardinality(m.roles) > 0 THEN m.roles
             WHEN m.capability = 'admin' THEN ARRAY['admin']
             WHEN m.capability = 'write' THEN ARRAY['user']
             ELSE '{}'::text[]
           END
      INTO r
      FROM members m
     WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN COALESCE(r, '{}');
END$$;

-- A right a given member's roles give (the member must be active and admitted); a `people` right never to an agent, a `super_admins`
-- right only to an active human super-admin of the kernel (D2). One query.
CREATE OR REPLACE FUNCTION ml_member_has_right(p_member_id bigint, p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM ml_role_rights rr
          JOIN ml_rights h ON h.right_key = rr.right_key
          JOIN members m ON m.id = p_member_id AND m.status = 'active'
         WHERE rr.right_key = p_right AND rr.role_key = ANY (ml_member_roles(p_member_id))
           AND (h.held_by = 'anyone'
                OR (h.held_by = 'people' AND m.member_kind = 'human')
                OR (h.held_by = 'super_admins' AND m.member_kind = 'human' AND m.business_role = 'super_admin')));
END$$;

-- The rule: a right the acting member's roles give.
CREATE OR REPLACE FUNCTION ml_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN ml_member_has_right(app_current_member_id(), p_right);
END$$;

-- Why the acting member lacks a right, in a sentence for a person — NULL when they hold it. The admin screens say this instead of a
-- bare 403 (D2: "saying why").
CREATE OR REPLACE FUNCTION ml_right_refusal(p_right text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE who text; holds_role boolean;
BEGIN
    IF ml_has_right(p_right) THEN
        RETURN NULL;
    END IF;
    SELECT h.held_by INTO who FROM ml_rights h WHERE h.right_key = p_right;
    IF who IS NULL THEN
        RETURN 'That is not something MaluMail does.';
    END IF;
    IF cardinality(ml_member_roles(app_current_member_id())) = 0 THEN
        RETURN 'Your access to MaluMail gives no role yet. A super-admin gives the Mail user role.';
    END IF;
    holds_role := EXISTS (SELECT 1 FROM ml_role_rights rr WHERE rr.right_key = p_right
                            AND rr.role_key = ANY (ml_member_roles(app_current_member_id())));
    IF app_member_kind() = 'agent' AND who = 'super_admins' THEN
        RETURN 'MaluMail''s administration is for people: an agent never administers mail.';
    END IF;
    IF app_member_kind() = 'agent' AND who = 'people' THEN
        RETURN 'That is a person''s decision: an agent never decides it.';
    END IF;
    IF who = 'super_admins' AND holds_role THEN
        RETURN 'MaluMail''s administration is for the OS''s super-admins. You hold the Mail admin role, but you are not a super-admin.';
    END IF;
    RETURN CASE WHEN who = 'super_admins' THEN 'Only a Mail admin — a super-admin of the OS — can do that.'
                ELSE 'Your MaluMail role does not let you do that.' END;
END$$;

-- Runs MaluMail: an active human super-admin of the kernel (who always holds Mail admin here). The one test the admin screens use.
CREATE OR REPLACE FUNCTION ml_is_mail_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin();
END$$;

-- Someone who belongs in the business here: admitted with a role (an agent granted one too). A read grant with no role is not.
CREATE OR REPLACE FUNCTION ml_is_member_here() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_active_member() AND cardinality(ml_member_roles(app_current_member_id())) > 0;
END$$;

-- What app_roles answers, in one read (the catalogue; the kernel reads it as itself).
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM ml_role_rights rr
                   JOIN ml_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM ml_roles r;

GRANT SELECT ON ml_rights, ml_roles, ml_role_rights, mcp_app_roles TO malumail_rw, malumail_records_ro;
GRANT EXECUTE ON FUNCTION ml_member_roles(bigint), ml_member_has_right(bigint, text), ml_has_right(text), ml_right_refusal(text),
    ml_is_mail_admin(), ml_is_member_here()
    TO malumail_rw, malumail_records_ro, malumail_activity_ro;

COMMIT;
