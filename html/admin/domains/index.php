<?php
declare(strict_types=1);
/** /admin/domains — MaluMail's hosted domains for the customer key, which this business serves and which are internal (screen `admin-domains`). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
admin_gate('domains.manage');
$pdo = db();
[$rows, $reached] = admin_domains($pdo);
log_screen_view($pdo, 'admin-domains');
if (wants_json()) {
    respond_screen(['maluMail_answered' => $reached, 'domains' => array_map(static fn (array $d): array => ['domain' => $d['domain'], 'hosted' => $d['hosted'],
        'served' => $d['served'], 'internal' => $d['internal'], 'spf' => $d['spf'], 'dkim' => $d['dkim'], 'dmarc' => $d['dmarc'],
        'mailboxes' => $d['mailboxes'], 'aliases' => $d['aliases']], $rows)]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['served' => ['success', 'The domain is served here.'], 'unserved' => ['success', 'The domain is no longer served here.']]);
render_screen('Domains', view('admin/domains.php', ['rows' => $rows, 'reached' => $reached, 'notice' => $notice]),
    ['screen' => 'admin-domains', 'entity' => 'domain', 'activeNav' => 'admin']);
