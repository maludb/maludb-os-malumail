<?php
declare(strict_types=1);
/**
 * Action `mail_permission_grant` (log `permission.grant`; a break-glass row `permission.break_glass`): give a person, a department or an
 * agent a level on a mailbox. People only (the schema refuses an agent again). The owner shares a person's mailbox; a Mail admin's row on
 * a person's mailbox is break-glass — one person, a reason, at most 30 days — and the owner is told at once (the schema decides, db/010).
 * An agent's or shared mailbox: a person with full on it, or a Mail admin. Never logs the reason: ids, kind, level, expiry.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';
ml_handler_begin();
require_human();
if (!has_right('mail.use') && !has_right('access.audit')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$ctx = ac_context($pdo, req_val('mailbox') ?? request_integer('mailbox'));
$mb = $ctx['id'];
if ($ctx['grant'] === null) {
    refuse(403, ac_grant_refusal($ctx));
}
$errors = [];
// who: exactly one of person · department · agent
$who = [];
foreach (['person' => 'member', 'department' => 'department', 'agent' => 'agent'] as $field => $kind) {
    if (req_has($field) && req_val($field) !== '' && req_val($field) !== '0') {
        $who[$field] = $kind;
    }
}
$kind = null;
$principal = null;
if (count($who) !== 1) {
    $errors['person'] = 'Choose one person, department or agent.';
} else {
    $field = array_key_first($who);
    $kind = $who[$field];
    $sql = match ($kind) {
        'member' => "SELECT 1 FROM mcp_members WHERE member_id = :id AND kind = 'person'",
        'agent' => "SELECT 1 FROM mcp_members WHERE member_id = :id AND kind = 'agent'",
        default => 'SELECT 1 FROM mcp_departments WHERE department_id = :id',
    };
    $principal = ml_ref($pdo, $field, null, $sql, $kind === 'member' ? 'person' : $kind, $errors, false);
}
$level = ml_choice('level', null, AC_LEVELS, 'The level', $errors, false);
$bg = $ctx['grant'] === 'break_glass';
$reason = ml_text('reason', null, 300, 'The reason', $errors, $bg);
$expires = null;
$raw = req_val('expires_at');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
if ($bg) {
    if ($kind !== null && $kind !== 'member') {
        $errors['person'] = 'An emergency grant names one person.';
    }
    if ($reason !== null && mb_strlen(trim($reason)) < 10) {
        $errors['reason'] = 'Say why, in at least ten characters: the owner is told what you write.';
    }
    $limit = $now->modify('+30 days');
    $when = $raw === null || $raw === '' ? $now->modify('+7 days') : ac_parse_expiry($raw, member_timezone());
    if ($when === null) {
        $errors['expires_at'] = 'Give the expiry as a date (2026-10-24).';
    } elseif ($when <= $now) {
        $errors['expires_at'] = 'The expiry is in the future.';
    } elseif ($when > $limit && $when->format('Y-m-d') > $limit->format('Y-m-d')) {
        $errors['expires_at'] = 'Emergency access lasts at most 30 days.';
    } else {
        $expires = ($when > $limit ? $limit->modify('-1 minute') : $when)->format('Y-m-d H:i:sP');
    }
} elseif ($raw !== null && $raw !== '') {
    $when = ac_parse_expiry($raw, member_timezone());
    if ($when === null) {
        $errors['expires_at'] = 'Give the expiry as a date (2026-10-24).';
    } elseif ($when <= $now) {
        $errors['expires_at'] = 'The expiry is in the future.';
    } else {
        $expires = $when->format('Y-m-d H:i:sP');
    }
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$me = (int) current_member_id();
$id = access_grant($pdo, $me, $mb, (string) $kind, (int) $principal, (string) $level, $reason, $expires);
$isBg = db_bool($pdo, 'SELECT break_glass FROM mcp_mailbox_permissions WHERE id = :id', ['id' => $id]);
log_activity($pdo, $isBg ? 'permission.break_glass' : 'permission.grant', 'mailbox_permission', $id,
    ['after' => ['principal_kind' => $kind, 'principal_id' => $principal, 'level' => $level, 'expires_at' => $expires], 'mailbox_id' => $mb]);
$name = (string) (db_value($pdo, $kind === 'department' ? 'SELECT name FROM mcp_departments WHERE department_id = :id' : 'SELECT display_name FROM mcp_members WHERE member_id = :id', ['id' => $principal]) ?? '');
ml_done($isBg ? 'Emergency access to ' . $ctx['row']['address'] . ' given to ' . $name . ' (' . $level . '); its owner is told.' : 'Shared ' . $ctx['row']['address'] . ' with ' . $name . ' (' . $level . ')', $id,
        ml_land(return_path('/m/' . $mb . '/access'), $isBg ? 'break_glass' : 'granted'), 'accessChanged',
        ['undo' => ['action' => 'mail_permission_revoke', 'mailbox' => $mb, 'permission' => $id]]);
