<?php
declare(strict_types=1);
/** /admin — the Mail admin's overview: domains, mailboxes, forwarding, sync, held mail, the agents' budget and the switches (screen `admin`). Administrative facts only. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/admin/overview.php';
admin_gate('settings.manage');
$pdo = db();
$o = admin_overview($pdo);
log_screen_view($pdo, 'admin');
if (wants_json()) {
    $o['domains']['unverified'] = array_map(static fn (array $u): array => ['domain' => $u['domain'], 'spf' => $u['spf_ok'], 'dkim' => $u['dkim_ok'], 'dmarc' => $u['dmarc_ok']], $o['domains']['unverified']);
    $o['held']['oldest'] = json_ts($o['held']['oldest']);
    $o['budget']['started'] = json_ts($o['budget']['started']);
    respond_screen($o);
}
render_screen('Admin', view('admin/overview.php', ['o' => $o, 'tz' => member_timezone()]), ['screen' => 'admin', 'entity' => 'admin', 'activeNav' => 'admin']);
