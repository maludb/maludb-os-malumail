<?php
/**
 * Router for `php -S 127.0.0.1:9201 -t html tests/dev_router.php` — the vhost's rewrites (deploy/apache-malumail.conf), so the proofs
 * run without Apache: /sso, /sso/logout, /api/v1/health and the token API (/api/v1/{name}[/{id}[/{do}]] → api/v1/{name}.php with
 * id/do); a page of a numbered record (/admin/mailboxes/12/edit) and the canonical URLs (/x/new, /x/{id}/edit, /x/{id}, /x → x.php or
 * x/index.php). Phase 2 adds the mailbox's own routes (/mail/{mailbox}/{folder}, a conversation, the signed /p/ and /img/ doors —
 * design §8) here and in the vhost together. A rewrite to a file its slice has not built yet answers 404, as Apache does. Anything
 * that is a real file is served as it is (never under /api/ — only the vhost's own names answer there).
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__) . '/html';
if ($path !== '/' && is_file($root . $path) && !str_starts_with($path, '/api/')) {      // under /api only the vhost's own names answer (health)
    if (str_ends_with($path, '.webmanifest')) { header('Content-Type: application/manifest+json'); readfile($root . $path); return true; }
    return false;                                             // a static file or a real .php file
}
$set = static function (array $kv): void { foreach ($kv as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; } };
$map = ['/sso' => '/sso.php', '/sso/logout' => '/sso/logout.php', '/api/v1/health' => '/api/v1/health.php'];
$rel = rtrim($path, '/') ?: '/';
if (isset($map[$rel])) { $target = $map[$rel]; }
elseif (preg_match('#^/api/v1/([a-z_]+)/([0-9]+)(?:/([a-z_]+))?$#', $path, $m)) { $set(['id' => $m[2], 'do' => $m[3] ?? '']); $target = '/api/v1/' . $m[1] . '.php'; }
elseif (preg_match('#^/api/v1/([a-z_]+)(?:/([a-z_]+))?$#', $path, $m)) { $target = '/api/v1/' . $m[1] . (isset($m[2]) ? '/' . $m[2] : '') . '.php'; }
elseif (str_starts_with($path, '/api/')) { http_response_code(404); echo 'Not found'; return true; }
elseif (preg_match('#^/((?:[a-z_-]+/)?[a-z_-]+)/new$#', $path, $m)) { $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/((?:[a-z_-]+/)?[a-z_-]+)/([0-9]+)/edit$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/((?:[a-z_-]+/)?[a-z_-]+)/([0-9]+)$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/view.php'; }
elseif (preg_match('#^/((?:[a-z_-]+/)?[a-z_-]+)/([0-9]+)/([a-z_-]+)$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/' . $m[3] . '.php'; }
elseif ($rel === '/') { $target = '/index.php'; }
elseif (is_file($root . $rel . '.php')) { $target = $rel . '.php'; }
elseif (is_file($root . $rel . '/index.php')) { $target = $rel . '/index.php'; }
else { http_response_code(404); echo 'Not found'; return true; }
if (!is_file($root . $target)) { http_response_code(404); echo 'Not found'; return true; }
$_SERVER['SCRIPT_NAME'] = $target;
$_SERVER['SCRIPT_FILENAME'] = $root . $target;
chdir(dirname($root . $target));
require $root . $target;
return true;
