<?php
declare(strict_types=1);
/** /admin/aliases/{alias} — one forwarding address: change its destinations, delete it (screen `admin-alias-view`). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
admin_gate('aliases.manage');
$pdo = db();
$id = request_integer('id');
$a = $id === null ? null : admin_alias($pdo, (string) $id);
if ($a === null) {
    respond_not_found('No such forwarding address.');
}
log_screen_view($pdo, 'admin-alias-view');
if (wants_json()) {
    respond_screen(['alias' => ['id' => (int) $a['id'], 'address' => $a['address'], 'destinations' => $a['destinations'], 'forwards_outside' => (bool) $a['forwards_outside']]]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['created' => ['success', 'The forwarding address is made at MaluMail.'], 'saved' => ['success', 'Saved at MaluMail.']]);
render_screen($a['address'], view('admin/alias-form.php', ['a' => $a, 'domains' => admin_served_domains($pdo), 'pre' => [], 'notice' => $notice]),
    ['screen' => 'admin-alias-view', 'entity' => 'alias', 'recordId' => (int) $a['id'], 'activeNav' => 'admin']);
