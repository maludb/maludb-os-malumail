<?php
declare(strict_types=1);
/** Action `mail_mailbox_reindex` (log `mailbox.reindex`): empty a mailbox's indexed text in SQL (ml_mailbox_reindex) and let the gentle text pass refill it — a forced fast re-read would trip the mail server's rate rules. A Mail admin's alone. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/overview.php';
ml_handler_begin();
admin_gate('settings.manage');
$pdo = db();
$b = admin_require_mailbox($pdo);
$id = (int) $b['id'];
$n = (int) ml_guard($pdo, static function () use ($pdo, $id): int {
    $st = $pdo->prepare('SELECT ml_mailbox_reindex(:b, :by)');
    $st->execute(['b' => $id, 'by' => current_member_id()]);
    return (int) $st->fetchColumn();
});
admin_mailer_try('/v1/mailboxes/' . $id . '/sync', ['text' => true]);          // a mailbox that is not live is refilled when it is
log_activity($pdo, 'mailbox.reindex', 'mailbox', $id, ['after' => ['reset' => $n], 'mailbox_id' => $id]);
[$rows] = admin_sync_rows($pdo, '', $id);
admin_row_reply('Emptied the text of ' . $n . ' message' . ($n === 1 ? '' : 's') . ' of ' . $b['address'] . '; it refills gently', $id,
    $rows === [] ? '' : view('admin/partials/sync-row.php', ['r' => $rows[0], 'tz' => member_timezone()]), ml_land('/admin/sync', null, 'sync-' . $id), 'syncChanged');
