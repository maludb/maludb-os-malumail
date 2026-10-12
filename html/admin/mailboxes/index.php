<?php
declare(strict_types=1);
/** /admin/mailboxes — every mailbox of the served domains as cards, administrative facts only (screen `admin-mailboxes`). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
admin_gate('mailboxes.manage');
$pdo = db();
$f = ['kind' => request_string('kind'), 'domain' => request_string('domain'), 'state' => request_string('state'), 'q' => request_string('q')];
$rows = admin_mailboxes($pdo, $f);
log_screen_view($pdo, 'admin-mailboxes');
if (wants_json()) {
    respond_screen(['mailboxes' => array_map('admin_mailbox_json', $rows), 'filters' => $f]);
}
render_screen('Mailboxes', view('admin/mailboxes.php', ['rows' => $rows, 'f' => $f, 'domains' => admin_served_domains($pdo)]),
    ['screen' => 'admin-mailboxes', 'entity' => 'mailbox', 'activeNav' => 'admin']);
