<?php
declare(strict_types=1);

/**
 * Delegation and access (docs/build-specs/delegation-access.md — slice 7; design §3, §4, §6.2, §6.6, §9): what the Access page, the
 * grant form and the access log read, and what the two handlers change. Every rule is the schema's — ml_permission_grant() and the
 * mailbox_permissions guard trigger decide who may grant what (the owner of a person's mailbox; anyone else is break-glass for a Mail admin
 * only, one person, a reason, at most 30 days, the owner told at once; a person with full or a Mail admin on an agent's or shared
 * mailbox; never an agent), ml_permission_revoke() who may take a row back; the views (mcp_mailbox_permissions, mcp_mailbox_access_log)
 * are the wall for the reads. This file only asks first, so the page says why in words, and shows the answer. Required by the access
 * screens, the settings screen and the two handlers.
 */

require_once dirname(__DIR__) . '/mail/wall.php';

const AC_LEVEL_WORDS = [
    'view' => 'Can read this mailbox. Never changes it: nothing is marked read, moved or sent.',
    'triage' => 'Can also file and organise: archive, label, move, mark read, snooze.',
    'send' => 'Can also send as this mailbox. The mail says it was sent on its behalf.',
    'full' => 'Can also change its settings and decide who else may open it.',
];
const AC_LEVELS = ['view', 'triage', 'send', 'full'];

const AC_WHAT = [
    'mailbox.view' => 'opened the list', 'message.open' => 'opened a conversation', 'message.part' => 'opened an attachment',
    'message.raw' => 'opened the original message', 'mcp.read' => 'read it through an assistant tool', 'k7.read' => 'read it for another application',
    'permission.break_glass' => 'was given emergency access',
];

/**
 * The mailbox for an access screen or handler, and what the caller is to it (the wall answers: a mailbox hidden from the caller is the
 * same 404 as a missing one). `row` = mcp_mailboxes' row, `level` the caller's, `owner` / `manager` / `audit` the three standings,
 * `sees_rows` whether the caller reads every row (else only their own), `grant` the kind of form: 'share' (an ordinary row), 'break_glass',
 * or null (no grant form for this person).
 */
function ac_context(PDO $pdo, int|string|null $id): array
{
    $id = is_string($id) && ctype_digit($id) ? (int) $id : $id;
    if (!is_int($id) || $id <= 0) {
        respond_not_found('No such mailbox.');
    }
    $st = $pdo->prepare('SELECT b.*, o.display_name AS owner_name FROM mcp_mailboxes b LEFT JOIN mcp_members o ON o.member_id = b.owner_member_id WHERE b.id = :id');
    $st->execute(['id' => $id]);
    $b = $st->fetch();
    if ($b === false) {
        respond_not_found('No such mailbox.');
    }
    $me = (int) current_member_id();
    $level = (string) $b['my_level'];
    $person = $b['kind'] === 'person';
    $owner = $person && (int) $b['owner_member_id'] === $me;
    $audit = has_right('access.audit');
    $manage = has_right('mailboxes.manage');
    $decider = !$person && db_bool($pdo, 'SELECT ml_is_decider(:mb, :me)', ['mb' => $id, 'me' => $me]);
    $sees = $person ? ($owner || $audit) : ($decider || $manage || $audit);
    $grant = $person ? ($owner ? 'share' : ($audit ? 'break_glass' : null)) : (($decider || $manage) ? 'share' : null);
    return ['row' => $b, 'id' => $id, 'level' => $level, 'kind' => (string) $b['kind'], 'owner' => $owner, 'audit' => $audit, 'manager' => $manage,
            'decider' => $decider, 'sees_rows' => $sees, 'grant' => $grant, 'me' => $me,
            'can_revoke' => $person ? ($owner || $audit) : ($decider || $manage || $audit),
            'reads_log' => $person ? ($owner || $audit) : ($decider || $audit)];
}

/** Why a person cannot open the grant form here (the schema's own sentence for the case). */
function ac_grant_refusal(array $ctx): string
{
    return $ctx['kind'] === 'person' ? 'Only its owner shares a person\'s mailbox.' : 'Only a person with full access to this mailbox, or a Mail admin, shares it.';
}

/** A mailbox's rows (every one the caller reads — the view decides), the holders no row creates, and the caller's own. */
function access_rows(PDO $pdo, int $mailbox): array
{
    $st = $pdo->prepare("SELECT p.id, p.principal_kind, p.principal_id, p.level, p.granted_by, p.created_at, p.reason, p.expires_at, p.break_glass,
                                CASE p.principal_kind WHEN 'department' THEN d.name ELSE m.display_name END AS principal_name,
                                g.display_name AS granted_by_name,
                                (p.principal_kind = 'agent' AND p.principal_id = (SELECT s.clerk_member_id FROM mcp_settings s)) AS is_clerk,
                                (p.principal_kind = 'agent' AND p.principal_id = (SELECT s.expert_member_id FROM mcp_settings s)) AS is_expert
                           FROM mcp_mailbox_permissions p
                           LEFT JOIN mcp_members m ON p.principal_kind IN ('member', 'agent') AND m.member_id = p.principal_id
                           LEFT JOIN mcp_departments d ON p.principal_kind = 'department' AND d.department_id = p.principal_id
                           LEFT JOIN mcp_members g ON g.member_id = p.granted_by
                          WHERE p.mailbox_id = :mb AND (p.expires_at IS NULL OR p.expires_at > now())
                          ORDER BY p.break_glass DESC, ml_level_rank(p.level) DESC, p.created_at");
    $st->execute(['mb' => $mailbox]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        foreach (['break_glass', 'is_clerk', 'is_expert'] as $k) {
            $r[$k] = $r[$k] === true || $r[$k] === 't';
        }
        $r['id'] = (int) $r['id'];
        $rows[] = $r;
    }
    return $rows;
}

/** The sentence that says why a row lets its principal in. */
function ac_row_why(array $r, array $box, string $tz): string
{
    $by = $r['granted_by_name'] !== null ? ' by ' . $r['granted_by_name'] : '';
    $on = ' on ' . format_ts((string) $r['created_at'], $tz, 'M j, Y');
    if ($r['break_glass']) {
        return 'Emergency access' . $by . $on . ', until ' . format_ts((string) $r['expires_at'], $tz, 'M j, Y g:i A') . '. The reason given: ' . $r['reason'];
    }
    if ($r['principal_kind'] === 'department') {
        return 'Everyone in the department who is a person, shared' . $by . $on . '. A department never admits an agent.';
    }
    if ($r['is_clerk'] && $r['principal_kind'] === 'agent') {
        return 'The Filing Clerk reads and files mail here, so the business can see what waits for a person.';
    }
    if ($r['principal_kind'] === 'agent' && $box['kind'] === 'person') {
        return 'An agent the owner lets send as this mailbox (at most send, only through its owner).';
    }
    return 'Shared' . $by . $on . ($r['expires_at'] !== null ? ', until ' . format_ts((string) $r['expires_at'], $tz, 'M j, Y g:i A') : '') . '.';
}

/** Grant: the schema's function under ml_guard — the trigger decides break-glass, the owner's notice and every refusal. Answers the row's id. */
function access_grant(PDO $pdo, int $by, int $mailbox, string $kind, int $principal, string $level, ?string $reason, ?string $expires): int
{
    return (int) ml_guard($pdo, static function () use ($pdo, $by, $mailbox, $kind, $principal, $level, $reason, $expires): int {
        $st = $pdo->prepare('SELECT ml_permission_grant(:mb, :by, :k, :p, :l, :r, CAST(:e AS timestamptz))');
        $st->execute(['mb' => $mailbox, 'by' => $by, 'k' => $kind, 'p' => $principal, 'l' => $level, 'r' => $reason, 'e' => $expires]);
        return (int) $st->fetchColumn();
    });
}

/** Revoke a row the caller reads: its kind and principal, then ml_permission_revoke(). Answers the row as it was (for the log). */
function access_revoke(PDO $pdo, int $by, int $mailbox, int $permission): array
{
    $st = $pdo->prepare('SELECT id, principal_kind, principal_id, level, break_glass FROM mcp_mailbox_permissions WHERE id = :id AND mailbox_id = :mb');
    $st->execute(['id' => $permission, 'mb' => $mailbox]);
    $row = $st->fetch();
    if ($row === false) {
        respond_not_found('No such permission.');
    }
    ml_guard($pdo, static function () use ($pdo, $by, $mailbox, $row): void {
        $r = $pdo->prepare('SELECT ml_permission_revoke(:mb, :by, :k, :p)');
        $r->execute(['mb' => $mailbox, 'by' => $by, 'k' => $row['principal_kind'], 'p' => (int) $row['principal_id']]);
        if ($r->fetchColumn() === false) {
            throw new DomainException('Not found.');
        }
    });
    $row['break_glass'] = $row['break_glass'] === true || $row['break_glass'] === 't';
    return $row;
}

/** The access log: every look by anyone but the owner (mcp_mailbox_access_log — the owner, the Mail admins, an agent's or shared mailbox's full holders), newest first. */
function access_log(PDO $pdo, int $mailbox, ?int $who, ?string $since, int $limit, ?int $before = null): array
{
    $st = $pdo->prepare("SELECT a.id, a.occurred_at, a.actor_member_id, m.display_name AS actor_name, a.source, a.action, a.consumer, a.agent_run_id, a.token_id
                           FROM mcp_mailbox_access_log a LEFT JOIN mcp_members m ON m.member_id = a.actor_member_id
                          WHERE a.mailbox_id = :mb AND (CAST(:who AS bigint) IS NULL OR a.actor_member_id = :who2)
                            AND (CAST(:since AS timestamptz) IS NULL OR a.occurred_at >= CAST(:since2 AS timestamptz))
                            AND (CAST(:before AS bigint) IS NULL OR a.id < :before2)
                          ORDER BY a.id DESC LIMIT :lim");
    $st->bindValue('mb', $mailbox, PDO::PARAM_INT);
    $st->bindValue('who', $who, $who === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('who2', $who, $who === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('since', $since);
    $st->bindValue('since2', $since);
    $st->bindValue('before', $before, $before === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('before2', $before, $before === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** The door a look came through, in words (web, an assistant's tool, another application — the consumer named when the log row names it). */
function ac_through(array $r): string
{
    if ($r['action'] === 'k7.read') {
        return 'Another application' . ($r['consumer'] !== null && $r['consumer'] !== '' ? ': ' . $r['consumer'] : '');
    }
    if ($r['action'] === 'mcp.read' || in_array($r['source'], ['mcp', 'agent', 'assistant'], true)) {
        return 'An assistant\'s tool';
    }
    return 'The webmail';
}

/** The JSON presenters (whitelists, never a raw row). */
function present_access_row(array $r, array $box, string $tz): array
{
    return ['permission' => (int) $r['id'], 'principal_kind' => $r['principal_kind'], 'principal_id' => (int) $r['principal_id'], 'principal' => $r['principal_name'],
            'level' => $r['level'], 'granted_by' => $r['granted_by_name'], 'granted_at' => json_ts((string) $r['created_at']),
            'expires_at' => $r['expires_at'] === null ? null : json_ts((string) $r['expires_at']), 'break_glass' => (bool) $r['break_glass'],
            'reason' => $r['break_glass'] ? $r['reason'] : null, 'why' => ac_row_why($r, $box, $tz)];
}

function present_access_log_row(array $r, string $tz): array
{
    return ['log' => (int) $r['id'], 'at' => json_ts((string) $r['occurred_at']), 'who' => $r['actor_name'], 'who_id' => $r['actor_member_id'] === null ? null : (int) $r['actor_member_id'],
            'did' => $r['action'], 'what' => AC_WHAT[$r['action']] ?? $r['action'], 'through' => ac_through($r)];
}

/** An ISO date or date-time as the member's local time → a UTC timestamp string; null when unreadable. A bare date means the end of that day. */
function ac_parse_expiry(string $text, string $tz): ?DateTimeImmutable
{
    $text = trim($text);
    try {
        $zone = new DateTimeZone($tz !== '' ? $tz : 'UTC');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            return (new DateTimeImmutable($text . ' 23:59:00', $zone))->setTimezone(new DateTimeZone('UTC'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $text)) {
            return null;
        }
        return (new DateTimeImmutable($text, $zone))->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable) {
        return null;
    }
}
