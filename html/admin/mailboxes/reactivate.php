<?php
declare(strict_types=1);
/** Action `mail_mailbox_reactivate` (log `mailbox.reactivate`): MaluMail's set_mailbox_active true and the row's suspension lifted. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
ml_handler_begin();
admin_gate('mailboxes.manage');
$pdo = db();
$b = admin_require_mailbox($pdo);
if ($b['suspended_at'] === null) {
    refuse(422, $b['address'] . ' is not suspended.');
}
admin_mailer('/v1/admin/set_mailbox_active', ['address' => $b['address'], 'active' => true]);
ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_mailbox_suspend(:b, :by, false)')->execute(['b' => (int) $b['id'], 'by' => current_member_id()]));
log_activity($pdo, 'mailbox.reactivate', 'mailbox', (int) $b['id']);
ml_done('Reactivated ' . $b['address'], (int) $b['id'], ml_land('/admin/mailboxes/' . (int) $b['id'], 'reactivated'), 'mailboxesChanged');
