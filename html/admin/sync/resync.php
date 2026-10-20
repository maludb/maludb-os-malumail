<?php
declare(strict_types=1);
/** Action `mail_mailbox_resync` (log `mailbox.resync`): ask the mail service to re-read every folder of a mailbox in full (QRESYNC from the start). A Mail admin's alone (settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/overview.php';
ml_handler_begin();
admin_gate('settings.manage');
$pdo = db();
$b = admin_require_mailbox($pdo);
$id = (int) $b['id'];
admin_mailer('/v1/mailboxes/' . $id . '/sync', ['full' => true]);
log_activity($pdo, 'mailbox.resync', 'mailbox', $id, ['mailbox_id' => $id]);
[$rows] = admin_sync_rows($pdo, '', $id);
admin_row_reply('Re-reading ' . $b['address'] . ' in full', $id, $rows === [] ? '' : view('admin/partials/sync-row.php', ['r' => $rows[0], 'tz' => member_timezone()]), ml_land('/admin/sync', null, 'sync-' . $id), 'syncChanged');
