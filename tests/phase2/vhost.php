<?php
/** Proof: the URL rules (deploy/apache-malumail.conf). Meaningful under ML_APP=apache (a real Apache serves the rendered template; the internal vhost on :9205 answers the same); under php -S the same checks run against tests/dev_router.php, which mirrors the rewrites. */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(26);
$priya = mb('priya');
echo "URL rules\n";
ok(req('GET', '/sso')['code'] === 403, 'GET /sso without a token: 403');
ok(page($j, '/notice')['code'] === 200 && page($j, '/tokens')['code'] === 200 && page($j, '/tokens/new')['code'] === 200 && page($j, '/my/trail')['code'] === 200, '/notice, /tokens, /tokens/new and /my/trail reach their files');
ok(page($j, "/m/$priya")['code'] === 302 && page($j, "/m/$priya")['location'] === "/m/$priya/v/inbox", "/m/{mailbox} redirects to its Inbox");
$r = page($j, "/m/$priya/v/inbox");
ok($r['code'] === 200 && str_contains($r['body'], 'data-screen="mailbox-view"'), "/m/{mailbox}/v/{view} reaches its screen (slice 2 built it)");
$r = page($j, "/m/$priya/f/3");
ok($r['code'] === 404 && str_contains($r['body'], 'No such folder'), "/m/{mailbox}/f/{folder} reaches its screen (no such folder here: its own 404)");
// slice 3 built thread-view and message-original: their routes answer their own screens now (a missing conversation / message: the screen's 404)
$r = page($j, "/m/$priya/t/9");
ok($r['code'] === 404 && str_contains($r['body'], 'moved or was deleted'), "/m/{mailbox}/t/{thread} reaches its screen (slice 3 built it: no such conversation, its own 404)");
ok(page($j, "/m/$priya/msg/3.1.7/original")['code'] === 404, "/m/{mailbox}/msg/{message}/original reaches its screen (slice 3 built it)");
// slice 4 built compose, draft-edit and the identity form: their routes answer their own screens (a missing draft or identity: the screen's 404)
$r = page($j, "/m/$priya/compose");
ok($r['code'] === 200 && str_contains($r['body'], 'id="compose-form"'), "/m/{mailbox}/compose reaches its screen (slice 4 built it)");
$r = page($j, "/m/$priya/drafts/999999");
ok($r['code'] === 404 && str_contains($r['body'], 'No such draft'), "/m/{mailbox}/drafts/{draft} reaches its screen (slice 4 built it: no such draft, its own 404)");
$r = page($j, "/m/$priya/identities/new");
ok($r['code'] === 200 && str_contains($r['body'], 'id="identity-form"'), "/m/{mailbox}/identities/new reaches its screen (slice 4 built it)");
$r = page($j, "/m/$priya/identities/999999/edit");
ok($r['code'] === 404 && str_contains($r['body'], 'No such identity'), "/m/{mailbox}/identities/{identity}/edit reaches its screen (slice 4 built it: its own 404)");
// slice 5 built review-view and review-edit: their routes answer their own screens (a message no one holds: 404)
ok(page($j, '/oversight/review/999999')['code'] === 404 && page($j, '/oversight/review/999999/edit')['code'] === 404, '/oversight/review/{outbound} and /edit reach their screens (slice 5 built them: its own 404)');
// slice 6 built search, label-view, the templates: their routes answer their own screens
$r = page($j, "/m/$priya/label/2");
ok($r['code'] === 404 && str_contains($r['body'], 'No such label'), '/m/{mailbox}/label/{label} reaches its screen (slice 6 built it: no such label, its own 404)');
$r = page($j, "/m/$priya/search");
ok($r['code'] === 200 && str_contains($r['body'], 'id="search-form"'), '/m/{mailbox}/search reaches its screen (slice 6 built it)');
$r = page($j, '/search');
ok($r['code'] === 200 && str_contains($r['body'], 'id="search-form"'), '/search reaches its screen (slice 6 built it)');
$r = page($j, '/templates/new');
ok($r['code'] === 200 && str_contains($r['body'], 'id="template-form"'), '/templates/new reaches its screen (slice 6 built it)');
$r = page($j, '/templates/7/edit');
ok($r['code'] === 404 && str_contains($r['body'], 'No such canned reply'), '/templates/{template}/edit reaches its screen (slice 6 built it: its own 404)');
foreach (["/m/$priya/people/jon%40acme.example",
          "/m/$priya/access/new", "/m/$priya/access-log",
          '/notifications', '/settings/notifications'] as $p) {
    $r = page($j, $p);
    ok($r['code'] === 200 && str_contains($r['body'], 'builds this screen'), "$p reaches its screen's placeholder");
}
ok(page($j, '/admin/mailboxes/new')['code'] === 403 && page($j, '/admin/mailboxes/12')['code'] === 403 && page($j, '/admin/aliases/3')['code'] === 403, 'the admin\'s canonical URLs resolve (403 for Priya, by the right)');
ok(page($j, '/pick/member')['code'] === 200, '/pick/{kind} reaches the picker');
ok(page($j, '/nosuchscreen')['code'] === 404 && page($j, '/m/' . $priya . '/nosuch')['code'] === 404, 'an unknown path: 404');
ok(page($j, '/p/abc.def')['code'] === 404 && page($j, '/img/abc.def')['code'] === 404, 'the signed doors /p/ and /img/ answer 404 until slice 3 builds them');
$h = req('GET', '/api/v1/health');
ok($h['code'] === 200 && json_decode($h['body'], true)['application'] === 'malumail', '/api/v1/health answers');
ok(req('GET', '/api/v1/health.php')['code'] === 404, '/api/v1/health.php is not a name the vhost answers (404): only /api/v1/health');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404, 'anything else under /api is 404');
if (getenv('ML_APP') === 'apache') {
    ok(in_array(req('GET', '/mcp/records')['code'], [502, 503], true) && in_array(req('GET', '/mcp/activity')['code'], [502, 503], true), 'the MCP proxies are in the vhost and answer 502 until Phase 4 starts the servers');
    $base = 'http://127.0.0.1:' . (getenv('P_INTERNAL') ?: '9205');
    $i = req('GET', $base . '/api/v1/health');
    ok($i['code'] === 200 && json_decode($i['body'], true)['application'] === 'malumail', 'the internal vhost answers health the same');
    ok(req('GET', $base . "/m/$priya/v/inbox", ['jar' => $j])['code'] === 200 && req('GET', $base . '/templates/new', ['jar' => $j])['code'] === 200, '…and the mailbox routes and the placeholders');
    $hdr = req('GET', '/api/v1/health')['headers'];
    $mod = is_file('/etc/apache2/mods-enabled/headers.load');
    ok($mod === (stripos($hdr, 'X-Content-Type-Options') !== false), 'the Header lines are ' . ($mod ? 'present with' : 'absent without') . ' mod_headers (the guard holds)');
}
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1, '/manifest.webmanifest is served as JSON');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
ok(req('GET', '/assets/js/malumail.js')['code'] === 200 && req('GET', '/assets/js/record-picker.js')['code'] === 200, 'the shell\'s scripts are served');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/bin/directory_sync.php', '/mcp/db.py', '/CLAUDE.md', '/maludb-os.json', '/.git/config',
          '/tests/phase2/lib.php', '/deploy/ROOT_STEPS.sh', '/services/mailer/server.py'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ML_MAILER_KEY') && !str_contains($r['body'], 'CREATE TABLE') && !str_contains($r['body'], 'import '), "$p is not served ({$r['code']})");
}
finish();
