<?php
/**
 * Router for `php -S 127.0.0.1:9201 -t html tests/dev_router.php` — the vhost's rewrites (deploy/apache-malumail.conf), so the proofs run
 * without Apache: /sso, /sso/logout, /api/v1/health; the mailbox's own routes (the manifest's Conventions — /m/{mailbox}/v/{view},
 * /f/{folder}, /label/{label}, /t/{thread}, /msg/{message}/{original|body|raw}, /drafts/{draft}, /people/{correspondent},
 * /identities/new, /identities/{identity}/edit, /access/new, /{page}); the signed /p/ and /img/ doors (slice 3); /pick/{kind}; the
 * estate's canonical URLs (/x/new, /x/{id}/edit, /x/{id}, /x/{id}/{page}, /x → x.php or x/index.php). A rewrite whose file a slice has
 * not built yet falls to html/placeholder.php, which answers the manifest's screen (or 404), as the vhost does. A real file is served as
 * it is (never under /api/ — only the vhost's own names answer there).
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
$target = null;
if (isset($map[$rel])) { $target = $map[$rel]; }
elseif (str_starts_with($path, '/api/')) { http_response_code(404); echo 'Not found'; return true; }
// the mailbox's own routes (design §8, §9)
elseif (preg_match('#^/m/([0-9]+)/?$#', $path, $m)) { header('Location: /m/' . $m[1] . '/v/inbox', true, 302); return true; }
elseif (preg_match('#^/m/([0-9]+)/v/([a-z]+)/?$#', $path, $m)) { $set(['mailbox' => $m[1], 'view' => $m[2]]); $target = '/m/view.php'; }
elseif (preg_match('#^/m/([0-9]+)/f/([0-9]+)/?$#', $path, $m)) { $set(['mailbox' => $m[1], 'folder' => $m[2]]); $target = '/m/folder.php'; }
elseif (preg_match('#^/m/([0-9]+)/label/([0-9]+)/?$#', $path, $m)) { $set(['mailbox' => $m[1], 'label' => $m[2]]); $target = '/m/label.php'; }
elseif (preg_match('#^/m/([0-9]+)/t/([0-9]+)/?$#', $path, $m)) { $set(['mailbox' => $m[1], 'thread' => $m[2]]); $target = '/m/thread.php'; }
elseif (preg_match('#^/m/([0-9]+)/msg/([A-Za-z0-9_.-]+)/(original|body|raw)$#', $path, $m)) { $set(['mailbox' => $m[1], 'message' => $m[2]]); $target = '/m/' . $m[3] . '.php'; }
elseif (preg_match('#^/m/([0-9]+)/drafts/([0-9]+)/files$#', $path, $m)) { $set(['mailbox' => $m[1], 'draft' => $m[2]]); $target = '/m/draft-files.php'; }
elseif (preg_match('#^/m/([0-9]+)/drafts/([0-9]+)/?$#', $path, $m)) { $set(['mailbox' => $m[1], 'draft' => $m[2]]); $target = '/m/compose.php'; }
elseif (preg_match('#^/m/([0-9]+)/people/([^/]+)$#', $path, $m)) { $set(['mailbox' => $m[1], 'correspondent' => rawurldecode($m[2])]); $target = '/m/person.php'; }
elseif (preg_match('#^/m/([0-9]+)/identities/new$#', $path, $m)) { $set(['mailbox' => $m[1]]); $target = '/m/identity-form.php'; }
elseif (preg_match('#^/m/([0-9]+)/identities/([0-9]+)/edit$#', $path, $m)) { $set(['mailbox' => $m[1], 'identity' => $m[2]]); $target = '/m/identity-form.php'; }
elseif (preg_match('#^/m/([0-9]+)/access/new$#', $path, $m)) { $set(['mailbox' => $m[1]]); $target = '/m/access-new.php'; }
elseif (preg_match('#^/m/([0-9]+)/([a-z-]+)/?$#', $path, $m)) { $set(['mailbox' => $m[1]]); $target = '/m/' . $m[2] . '.php'; }
// the signed doors (slice 3) and the record picker
elseif (preg_match('#^/p/([A-Za-z0-9_.-]+)$#', $path, $m)) { $set(['t' => $m[1]]); $target = '/p.php'; }
elseif (preg_match('#^/img/([A-Za-z0-9_.-]+)$#', $path, $m)) { $set(['t' => $m[1]]); $target = '/img.php'; }
elseif (preg_match('#^/pick/([a-z_]+)$#', $path, $m)) { $set(['source' => $m[1]]); $target = '/pick/index.php'; }
// the estate's canonical URLs
elseif (preg_match('#^/((?:[a-z_-]+/)*[a-z_-]+)/new$#', $path, $m)) { $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/((?:[a-z_-]+/)*[a-z_-]+)/([0-9]+)/edit$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/form.php'; }
elseif (preg_match('#^/((?:[a-z_-]+/)*[a-z_-]+)/([0-9]+)$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/view.php'; }
elseif (preg_match('#^/((?:[a-z_-]+/)*[a-z_-]+)/([0-9]+)/([a-z_-]+)$#', $path, $m)) { $set(['id' => $m[2]]); $target = '/' . $m[1] . '/' . $m[3] . '.php'; }
elseif ($rel === '/') { $target = '/index.php'; }
elseif (is_file($root . $rel . '.php')) { $target = $rel . '.php'; }
elseif (is_file($root . $rel . '/index.php')) { $target = $rel . '/index.php'; }
if ($target === null || !is_file($root . $target)) { $target = '/placeholder.php'; }     // a screen no slice has built yet (or a 404 by the registry)
$_SERVER['SCRIPT_NAME'] = $target;
$_SERVER['SCRIPT_FILENAME'] = $root . $target;
chdir(dirname($root . $target));
require $root . $target;
return true;
