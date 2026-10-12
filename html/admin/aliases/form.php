<?php
declare(strict_types=1);
/** /admin/aliases/new — a new forwarding address (screen `admin-alias-new`; prefill: domain, local_part). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
admin_gate('aliases.manage');
$pdo = db();
$domains = admin_served_domains($pdo);
$pre = ['domain' => request_string('domain'), 'local_part' => request_string('local_part')];
log_screen_view($pdo, 'admin-alias-new');
if (wants_json()) {
    respond_screen(['served_domains' => array_column($domains, 'domain'), 'prefill' => $pre]);
}
render_screen('New forwarding address', view('admin/alias-form.php', ['a' => null, 'domains' => $domains, 'pre' => $pre]), ['screen' => 'admin-alias-new', 'entity' => 'alias', 'activeNav' => 'admin']);
