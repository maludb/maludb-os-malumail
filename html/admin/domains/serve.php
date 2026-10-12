<?php
declare(strict_types=1);
/** Action `mail_domains_serve_set` (log `domains.serve`; confirm): serve a hosted domain here or stop, internal or not. People only — a super-admin's. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
ml_handler_begin();
admin_gate('domains.manage');
$pdo = db();
$domain = strtolower((string) req_val('domain'));
$errors = [];
if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $domain)) {
    $errors['domain'] = 'Name a domain MaluMail hosts for this business.';
}
if (!req_has('served')) {
    $errors['served'] = 'Say whether the domain is served here.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$served = ml_yes('served');
$internal = req_has('internal') ? ml_yes('internal') : null;
$mine = admin_domain_rows($pdo)[$domain] ?? null;
if ($served && !($mine['served'] ?? false)) {           // serving it: MaluMail must host it for this customer
    $hosted = admin_mailer('/v1/admin/list_hosted_domains');
    $names = array_map(static fn ($d): string => strtolower((string) ($d['domain'] ?? '')), (array) ($hosted['domains'] ?? []));
    if (!in_array($domain, $names, true)) {
        ml_refuse_fields(['domain' => 'MaluMail hosts no domain ' . $domain . ' for this business.']);
    }
}
$before = $mine === null ? ['served' => false, 'internal' => false] : ['served' => (bool) $mine['served'], 'internal' => (bool) $mine['internal']];
$id = ml_guard($pdo, static function () use ($pdo, $domain, $served, $internal): int {
    $st = $pdo->prepare('SELECT ml_domain_serve(:by, :d, :s, :i)');
    $st->execute(['by' => current_member_id(), 'd' => $domain, 's' => $served ? 't' : 'f', 'i' => $internal === null ? null : ($internal ? 't' : 'f')]);
    return (int) $st->fetchColumn();
});
$after = admin_domain_rows($pdo)[$domain];
log_activity($pdo, 'domains.serve', 'domain', $id, ml_diff($before, ['served' => (bool) $after['served'], 'internal' => (bool) $after['internal']]));
ml_done(($served ? 'Serving ' : 'Stopped serving ') . $domain . ($served ? (($after['internal'] ? ' — internal' : ' — outside')) : ''), $id,
    ml_land('/admin/domains', $served ? 'served' : 'unserved', 'domain-' . str_replace('.', '-', $domain)), 'mailboxesChanged');
