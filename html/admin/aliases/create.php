<?php
declare(strict_types=1);
/** Action `mail_alias_create` (log `alias.create`; confirm): MaluMail's create_alias, then the cache (db/016). An outside destination must be typed back. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
ml_handler_begin();
admin_gate('aliases.manage');
$pdo = db();
$errors = [];
$local = strtolower((string) req_val('local_part'));
if (!preg_match('/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/', $local)) {
    $errors['local_part'] = 'An address is letters, digits, dots, hyphens, plus signs or underscores before the @.';
}
$domain = strtolower((string) req_val('domain'));
if (!in_array($domain, array_column(admin_served_domains($pdo), 'domain'), true)) {
    $errors['domain'] = 'Choose a domain this business serves.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$dest = admin_destinations();
$outside = admin_confirm_outside($pdo, $dest);
admin_mailer('/v1/admin/create_alias', ['domain' => $domain, 'local_part' => $local, 'forwards_to' => $dest]);
$address = $local . '@' . $domain;
$id = ml_guard($pdo, static function () use ($pdo, $address, $dest): int {
    $st = $pdo->prepare('SELECT ml_alias_cache_put(:by, :a, :d)');
    $st->execute(['by' => current_member_id(), 'a' => $address, 'd' => pg_array_literal($dest)]);
    return (int) $st->fetchColumn();
});
log_activity($pdo, 'alias.create', 'alias', $id, ['after' => ['domain' => $domain, 'destination_domains' => admin_domains_of($dest), 'destinations' => count($dest), 'outside' => $outside !== []]]);
ml_done('Made ' . $address . ' → ' . count($dest) . ' address' . (count($dest) === 1 ? '' : 'es') . ($outside !== [] ? ' (outside the business)' : ''), $id, ml_land('/admin/aliases/' . $id, 'created'), 'aliasesChanged');
