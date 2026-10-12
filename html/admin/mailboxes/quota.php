<?php
declare(strict_types=1);
/** Action `mail_mailbox_quota_set` (log `mailbox.quota`): MaluMail's set_mailbox_quota, then the quota recorded here (db/016). People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
ml_handler_begin();
admin_gate('mailboxes.manage');
$pdo = db();
$b = admin_require_mailbox($pdo);
$errors = [];
$gib = ml_int('quota_gib', null, 1, 50, 'The quota', $errors);
if ($gib === null && $errors === []) {
    $errors['quota_gib'] = 'The quota is required.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
admin_mailer('/v1/admin/set_mailbox_quota', ['address' => $b['address'], 'quota_gib' => $gib]);
ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_mailbox_quota_set(:b, :by, :g)')->execute(['b' => (int) $b['id'], 'by' => current_member_id(), 'g' => $gib]));
log_activity($pdo, 'mailbox.quota', 'mailbox', (int) $b['id'], ['before' => ['quota_bytes' => $b['quota_bytes'] === null ? null : (int) $b['quota_bytes']], 'after' => ['quota_gib' => $gib]]);
ml_done('Set ' . $b['address'] . '\'s quota to ' . $gib . ' GiB', (int) $b['id'], ml_land('/admin/mailboxes/' . (int) $b['id'], 'quota'), 'mailboxesChanged');
