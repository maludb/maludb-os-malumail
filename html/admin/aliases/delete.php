<?php
declare(strict_types=1);
/** Action `mail_alias_delete` (log `alias.delete`; confirm): MaluMail's delete_alias, then the cache row dropped. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
ml_handler_begin();
admin_gate('aliases.manage');
$pdo = db();
$a = admin_alias($pdo, (string) req_val('alias'));
if ($a === null) {
    refuse(404, 'No such forwarding address.');
}
admin_mailer('/v1/admin/delete_alias', ['address' => $a['address']]);
ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_alias_cache_drop(:by, :a)')->execute(['by' => current_member_id(), 'a' => $a['address']]));
log_activity($pdo, 'alias.delete', 'alias', (int) $a['id'], ['before' => ['domain' => admin_domain_of($a['address']), 'destination_domains' => admin_domains_of($a['destinations'])]]);
ml_done('Deleted ' . $a['address'], (int) $a['id'], ml_land('/admin/aliases', 'deleted'), 'aliasesChanged');
