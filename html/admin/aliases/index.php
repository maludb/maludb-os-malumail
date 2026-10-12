<?php
declare(strict_types=1);
/** /admin/aliases — MaluMail's forwarding addresses on the served domains, outside destinations flagged (screen `admin-aliases`). Read from MaluMail; the cache refreshed. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
admin_gate('aliases.manage');
$pdo = db();
$reached = admin_aliases_refresh($pdo);
$f = ['domain' => request_string('domain'), 'outside' => request_string('outside'), 'q' => request_string('q')];
$rows = admin_aliases($pdo, $f);
log_screen_view($pdo, 'admin-aliases');
if (wants_json()) {
    respond_screen(['maluMail_answered' => $reached, 'aliases' => array_map(static fn (array $a): array => ['id' => (int) $a['id'], 'address' => $a['address'],
        'destinations' => $a['destinations'], 'forwards_outside' => (bool) $a['forwards_outside']], $rows)]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'The forwarding address is deleted at MaluMail.']]);
render_screen('Forwarding', view('admin/aliases.php', ['rows' => $rows, 'f' => $f, 'reached' => $reached, 'notice' => $notice, 'domains' => admin_served_domains($pdo)]),
    ['screen' => 'admin-aliases', 'entity' => 'alias', 'activeNav' => 'admin']);
