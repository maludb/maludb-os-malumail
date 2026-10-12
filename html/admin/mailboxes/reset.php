<?php
declare(strict_types=1);
/**
 * Action `mail_mailbox_password_reset` (log `mailbox.password_reset`; confirm). People only. The mail service decides what a reset
 * reveals from the mailbox's KIND in the database: a person's new password once (shown on the mailbox's page, never stored, never in
 * JSON or under an action token); an agent's or a shared mailbox's is sealed and stored by the service, never answered.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
ml_handler_begin();
admin_gate('mailboxes.manage');
$pdo = db();
$b = admin_require_mailbox($pdo);
if ($b['kind'] === 'person' && (wants_json() || is_action_authed())) {
    refuse(422, 'A person\'s new password is shown once, on a Mail admin\'s screen: reset it there.');
}
$res = admin_mailer('/v1/admin/reset_mailbox_password', ['address' => $b['address']]);
log_activity($pdo, 'mailbox.password_reset', 'mailbox', (int) $b['id'], ['after' => ['kind' => $b['kind'], 'stored' => isset($res['credential'])]]);
if ($b['kind'] === 'person' && isset($res['revealed']) && is_string($res['revealed'])) {
    admin_reveal_once((int) $b['id'], $res['revealed'], $b['address'], $b['owner_name']);
}
ml_done('Reset ' . $b['address'] . '\'s password' . ($b['kind'] === 'person' ? ' — shown once' : ' — the webmail holds the new one'), (int) $b['id'],
    ml_land('/admin/mailboxes/' . (int) $b['id'], 'reset'), 'mailboxesChanged');
