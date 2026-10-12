<?php
declare(strict_types=1);
/**
 * Action `mail_mailbox_create` (log `mailbox.create`). People only — a Mail admin's. The mail service creates the mailbox at MaluMail and
 * records it here in one transaction (services/mailer/admin_flows.py); an agent's or a shared mailbox's password is sealed there and
 * never reaches PHP. A PERSON's is answered once and shown once on the mailbox's page — never in JSON, never under an action token.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
ml_handler_begin();
admin_gate('mailboxes.manage');
$pdo = db();
$errors = [];
$kind = ml_choice('kind', null, array_keys(ADMIN_KINDS), 'The kind', $errors, false);
$local = strtolower((string) req_val('local_part'));
if (!preg_match('/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/', $local)) {
    $errors['local_part'] = 'An address is letters, digits, dots, hyphens, plus signs or underscores before the @.';
}
$domain = strtolower((string) req_val('domain'));
if (!in_array($domain, array_column(admin_served_domains($pdo), 'domain'), true)) {
    $errors['domain'] = 'Choose a domain this business serves.';
}
$display = ml_text('display_name', null, 120, 'The display name', $errors) ?? $local;
$quota = ml_int('quota_gib', 2, 1, 50, 'The quota', $errors) ?? 2;
$owner = req_val('owner');
if ($owner === null || $owner === '') {
    $owner = $kind === 'person' ? req_val('owner_person') : ($kind === 'agent' ? req_val('owner_agent') : null);
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
if ($kind === 'person' && (wants_json() || is_action_authed())) {
    // the person's password is shown once on a Mail admin's screen — asked from JSON it could only be lost or leaked
    ml_refuse_fields(['kind' => 'A person\'s new mailbox shows its password once, on a Mail admin\'s screen: create it there.']);
}
$args = ['domain' => $domain, 'local_part' => $local, 'kind' => $kind, 'display_name' => $display, 'quota_gib' => $quota,
         'owner' => $kind === 'shared' ? null : ($owner === null || $owner === '' ? null : (int) $owner)];
if ($kind === 'agent') {
    $args['primary_overseer'] = ctype_digit((string) req_val('primary_overseer')) ? (int) req_val('primary_overseer') : null;
    $args['overseers'] = admin_overseer_ids();
}
$res = admin_mailer('/v1/admin/mailboxes/create', $args);
$id = (int) $res['mailbox_id'];
log_activity($pdo, 'mailbox.create', 'mailbox', $id, ['after' => ['kind' => $kind, 'domain' => $domain, 'quota_gib' => $quota,
    'owner_member_id' => $args['owner'], 'primary_overseer_id' => $args['primary_overseer'] ?? null, 'overseers' => count($args['overseers'] ?? [])]]);
if (isset($res['revealed']) && is_string($res['revealed'])) {
    admin_reveal_once($id, $res['revealed'], (string) $res['address'], null);
}
ml_done('Created ' . $res['address'] . ($kind === 'person' ? ' — its password is shown once' : ''), $id, '/admin/mailboxes/' . $id, 'mailboxesChanged');
