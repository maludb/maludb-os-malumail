<?php
declare(strict_types=1);
/** GET /api/v1/health — unauthenticated; the kernel's health check and the installer's monitor read it. */
require_once dirname(__DIR__, 3) . '/app/api/bootstrap.php';
api_cors();
api_require_get();
$out = ['ok' => true, 'application' => app_key(), 'version' => (string) (json_decode((string) @file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true)['version'] ?? ''), 'database' => 'ok', 'maludb' => 'unconfigured', 'ingest_lag' => null, 'directory' => null];
try {
    $pdo = db();
    $lag = $pdo->query('SELECT (SELECT COALESCE(max(id), 0) FROM activity_log) - (SELECT last_id FROM activity_ingest_state WHERE id = 1)')->fetchColumn();
    $out['ingest_lag'] = (int) $lag;
    $sync = $pdo->query('SELECT last_run_at, last_error, next_cursor FROM directory_sync_state WHERE id = 1')->fetch();
    $out['directory'] = ['last_run_at' => json_ts($sync['last_run_at'] ?? null), 'synced' => ($sync['next_cursor'] ?? null) !== null, 'error' => $sync['last_error'] ?? null];
} catch (Throwable $e) {
    error_log('health: ' . $e->getMessage());
    $out['ok'] = false;
    $out['database'] = 'error';
}
if ((string) env('MALUDB_API_URL', '') !== '' && (string) env('MALUDB_API_TOKEN', '') !== '') {
    $out['maludb'] = 'ok';
}
// the attachment store (design §14: ML_STORAGE_DIR, default storage/ under the application): a draft's attachments until sent, written by PHP.
$dir = (string) (env('ML_STORAGE_DIR', '') ?: 'storage');
$dir = rtrim($dir[0] === '/' ? $dir : APP_ROOT . '/' . $dir, '/');
$out['storage'] = is_dir($dir) && is_writable($dir) ? 'ok' : 'missing';
// the mail service and the image door (design §6.1, §6.4; their own units): whether their loopback ports answer — informational here,
// never a failure of this health (the screens say "mail is not reachable right now" on their own; the mail service's own health,
// with each mailbox's sync and credential state, is its door's).
foreach (['mailer' => 'ML_MAILER_PORT', 'images' => 'ML_IMAGES_PORT'] as $name => $key) {
    $port = (int) env($key, '0');
    if ($port <= 0) {
        $out[$name] = 'unconfigured';
        continue;
    }
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
    $out[$name] = $sock === false ? 'down' : 'ok';
    if ($sock !== false) {
        fclose($sock);
    }
}
api_json($out, $out['ok'] ? 200 : 503);
