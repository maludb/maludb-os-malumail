<?php
declare(strict_types=1);
/** Action `mail_alias_update` (log `alias.update`; confirm): MaluMail's update_alias, then the cache. A new outside destination must be typed back. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
ml_handler_begin();
admin_gate('aliases.manage');
$pdo = db();
$a = admin_alias($pdo, (string) req_val('alias'));
if ($a === null) {
    refuse(404, 'No such forwarding address.');
}
$dest = admin_destinations();
$outside = admin_confirm_outside($pdo, $dest);
admin_mailer('/v1/admin/update_alias', ['address' => $a['address'], 'forwards_to' => $dest]);
ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_alias_cache_put(:by, :a, :d)')->execute(['by' => current_member_id(), 'a' => $a['address'], 'd' => pg_array_literal($dest)]));
log_activity($pdo, 'alias.update', 'alias', (int) $a['id'], ['before' => ['destination_domains' => admin_domains_of($a['destinations']), 'destinations' => count($a['destinations'])],
    'after' => ['destination_domains' => admin_domains_of($dest), 'destinations' => count($dest), 'outside' => $outside !== []]]);
ml_done('Saved ' . $a['address'], (int) $a['id'], ml_land('/admin/aliases/' . (int) $a['id'], 'saved'), 'aliasesChanged');
