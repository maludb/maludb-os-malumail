<?php
declare(strict_types=1);
/** /admin/mailboxes/new — create a mailbox at MaluMail, or connect an existing one (screen `admin-mailbox-new`; prefill: domain, local_part, kind, owner). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
admin_gate('mailboxes.manage');
$pdo = db();
$domains = admin_served_domains($pdo);
$pre = ['domain' => request_string('domain'), 'local_part' => request_string('local_part'), 'kind' => request_string('kind', 'agent'),
        'owner' => request_integer('owner')];
if (!isset(ADMIN_KINDS[$pre['kind']])) {
    $pre['kind'] = 'agent';
}
log_screen_view($pdo, 'admin-mailbox-new');
if (wants_json()) {
    respond_screen(['served_domains' => array_column($domains, 'domain'), 'kinds' => array_keys(ADMIN_KINDS), 'prefill' => $pre]);
}
render_screen('New mailbox', view('admin/mailbox-form.php', ['domains' => $domains, 'pre' => $pre]), ['screen' => 'admin-mailbox-new', 'entity' => 'mailbox', 'activeNav' => 'admin']);
