<?php
declare(strict_types=1);
/** Action `mail_mailbox_suspend` (log `mailbox.suspend`; confirm): MaluMail's set_mailbox_active false (mail and logins refused) AND the row's suspended_at — design §5's hard stop. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
ml_handler_begin();
admin_gate('mailboxes.manage');
$pdo = db();
$b = admin_require_mailbox($pdo);
$errors = [];
$reason = ml_text('reason', null, 200, 'The reason', $errors, true);
if ($errors !== []) {
    ml_refuse_fields($errors);
}
if ($b['suspended_at'] !== null) {
    refuse(422, $b['address'] . ' is already suspended.');
}
admin_mailer('/v1/admin/set_mailbox_active', ['address' => $b['address'], 'active' => false]);
ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_mailbox_suspend(:b, :by, true)')->execute(['b' => (int) $b['id'], 'by' => current_member_id()]));
log_activity($pdo, 'mailbox.suspend', 'mailbox', (int) $b['id'], ['after' => ['reason' => $reason]]);
ml_done('Suspended ' . $b['address'], (int) $b['id'], ml_land('/admin/mailboxes/' . (int) $b['id'], 'suspended'), 'mailboxesChanged');
