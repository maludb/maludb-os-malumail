<?php
declare(strict_types=1);
/** /admin/sync — per mailbox the sync state, the watcher, index and text coverage, the credential and the last error; Re-sync and Re-index text (screen `admin-sync`). Counts only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/overview.php';
admin_gate('settings.manage');
$pdo = db();
$state = request_string('state');
$state = in_array($state, ['failing', 'syncing', 'failed'], true) ? $state : '';
[$rows, $reached] = admin_sync_rows($pdo, $state);
if (request_string('rows') === '1' && is_htmx_request() && !wants_json()) {          // the 30-second refresh: the rows alone
    foreach ($rows as $r) {
        echo view('admin/partials/sync-row.php', ['r' => $r, 'tz' => member_timezone()]);
    }
    exit;
}
log_screen_view($pdo, 'admin-sync');
if (wants_json()) {
    respond_screen(['mail_service_answered' => $reached, 'state' => $state, 'mailboxes' => array_map('admin_sync_json', $rows)]);
}
render_screen('Sync health', view('admin/sync.php', ['rows' => $rows, 'reached' => $reached, 'state' => $state, 'tz' => member_timezone(), 'refresh' => 30]),
    ['screen' => 'admin-sync', 'entity' => 'mailbox', 'activeNav' => 'admin']);
