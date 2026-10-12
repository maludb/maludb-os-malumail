<?php
declare(strict_types=1);
/** /admin/mailboxes/{mailbox} — one mailbox for a Mail admin, administrative facts only (screen `admin-mailbox-view`). A person's new password, once. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
admin_gate('mailboxes.manage');
$pdo = db();
$b = admin_mailbox_for_screen($pdo);
$overseers = $b['kind'] === 'agent' ? admin_overseers($pdo, (int) $b['id']) : [];
log_screen_view($pdo, 'admin-mailbox-view', null);
if (wants_json()) {
    respond_screen(['mailbox' => admin_mailbox_json($b) + ['overseers' => array_map(static fn (array $o): array => ['member_id' => (int) $o['member_id'], 'name' => $o['display_name']], $overseers)]]);
}
$revealed = admin_take_revealed((int) $b['id']);
header('Cache-Control: no-store');
$notice = ml_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'suspended' => ['success', 'Suspended: MaluMail refuses its mail and logins until it is reactivated.'],
    'reactivated' => ['success', 'Reactivated.'], 'quota' => ['success', 'The quota is changed at MaluMail.'], 'reset' => ['success', 'A new password is set at MaluMail.']]);
render_screen($b['address'], view('admin/mailbox-view.php', ['b' => $b, 'overseers' => $overseers, 'revealed' => $revealed, 'notice' => $notice, 'tz' => member_timezone()]),
    ['screen' => 'admin-mailbox-view', 'entity' => 'mailbox', 'recordId' => (int) $b['id'], 'activeNav' => 'admin']);
