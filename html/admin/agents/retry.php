<?php
declare(strict_types=1);
/** Action `mail_dispatch_retry` (log `dispatch.retry`): a failed mail turn goes back to queued, once; the worker's dispatch pass counts the try. People only; needs agents.manage (a super-admin's). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/overview.php';
ml_handler_begin();
admin_gate('agents.manage');
$pdo = db();
$id = request_integer('dispatch');
$rows = $id === null ? [] : admin_dispatches($pdo, null, null, 1, $id);
if ($rows === []) {
    respond_not_found('No such mail turn.');
}
$d = $rows[0];
ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_dispatch_retry(:d, :by)')->execute(['d' => $id, 'by' => current_member_id()]));
log_activity($pdo, 'dispatch.retry', 'agent_dispatch', $id, ['after' => ['dispatch' => $id, 'agent' => (int) $d['agent_member_id']], 'mailbox_id' => (int) $d['mailbox_id']]);
$fresh = admin_dispatches($pdo, null, null, 1, $id)[0];
admin_row_reply('Queued mail turn #' . $id . ' again', $id, view('admin/partials/dispatch-row.php', ['d' => $fresh, 'os' => admin_os_url(), 'tz' => member_timezone()]), ml_land('/admin/agents', 'retried', 'dispatch-' . $id), 'dispatchChanged');
