<?php
/**
 * Proof: the shell (docs/build-specs/sso-shell.md "Proof" 1–7): the hand-off; home's redirect; the rail by the wall; the notice gate; tokens;
 * the trail; every manifest screen URL answering its placeholder or itself by the right and the level; the mail service's client; the record
 * picker; the bell; the command bar; action tokens.
 */
require __DIR__ . '/lib.php';
$json = ['headers' => ['Accept: application/json']];
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/mcp/action_registry.json'), true);
$priya = mb('priya'); $dana = mb('dana'); $sales = mb('sales'); $expert = mb('expert');

echo "1. The hand-off\n";
[$jp, $r] = sign_on(26);
ok($r['code'] === 302 && $r['location'] === '/', 'Priya\'s hand-off lands on / (302)');
$h = handoff(26);
req('GET', $h);
ok(req('GET', $h)['code'] === 403, 'the same hand-off replayed is refused');
ok(req('GET', handoff(999))['code'] === 403, 'a member the directory does not hold is refused');
ok(req('GET', handoff(26, ['ttl' => -5]))['code'] === 403, 'an expired hand-off is refused');
ok(req('GET', handoff(26, ['app' => 'helpdesk']))['code'] === 403, 'a hand-off for another application is refused');

echo "2. Home\n";
ok(page($jp, '/')['location'] === "/m/$priya/v/inbox", 'Priya\'s home is her own Inbox');
[$jd, ] = sign_on(30);
[$jo, ] = sign_on(1);
[$jb, ] = sign_on(28);
[$ja, ] = sign_on(29);
[$jm, ] = sign_on(27);
ok(page($jo, '/')['location'] === "/m/$expert/v/inbox", 'the Mail admin\'s home is the one mailbox the role opens: the agent\'s (never a person\'s)');
$b = page($jb, '/');
ok($b['code'] === 200 && str_contains($b['body'], 'No mailbox is open to you yet') && str_contains($b['body'], 'SMOKE Owner'), 'Bea, with no mailbox, sees why and the Mail admins\' names');
$a = page($ja, '/');
ok($a['code'] === 403 && str_contains($a['body'], 'role'), 'Ann, admitted with no MaluMail role, is told why (403)');
ok(page($jd, '/')['location'] === '/notice?next=%2F', 'Dana, who has not read the notice, is sent to it from home');

echo "3. The rail\n";
$rail = static function (string $jar, string $path): array { preg_match_all('/id="nav-mailbox-([0-9]+)"/', page($jar, $path)['body'], $m); return array_map('intval', $m[1]); };
$items = static function (string $jar, string $path): array { preg_match_all('/id="nav-(oversight|templates|admin|notifications)"/', page($jar, $path)['body'], $m); return $m[1]; };
ok($rail($jp, "/m/$priya/v/inbox") === [$priya, $expert], 'Priya\'s rail: her own mailbox, then the agent mailbox she oversees — not sales@ (no row), not Dana\'s');
ok($items($jp, "/m/$priya/v/inbox") === ['oversight', 'templates', 'notifications'], 'Priya sees Oversight (she decides for the Expert\'s mailbox), Templates, Notifications — no Admin');
ok($rail($jo, "/m/$expert/v/inbox") === [$expert], 'the Mail admin\'s rail holds the agent mailbox only: holding the role never opens a person\'s or a shared mailbox (design §3)');
ok(in_array('admin', $items($jo, "/m/$expert/v/inbox"), true), '…and the Admin item');
ok(!in_array('admin', $items($jm, '/my/trail'), true), 'Marco holds the admin role but is no super-admin: no Admin item (held_by)');
$pb = page($jp, "/m/$priya/v/inbox")['body'];
ok(str_contains($pb, 'id="rail-views-' . $priya . '"') && str_contains($pb, 'id="nav-view-sent"') && str_contains($pb, 'href="/m/' . $priya . '/v/trash"'), 'the current mailbox shows its views under it (Sent … Trash)');
ok(preg_match('/id="nav-mailbox-' . $priya . '".*?rail-count">—</s', $pb) === 1, 'unread shows — until slice 2\'s sync fills it');
ok(str_contains($pb, 'id="shell-tabbar"') && str_contains($pb, 'id="tab-inbox"') && str_contains($pb, 'id="tab-search"') && str_contains($pb, 'href="/m/' . $priya . '/compose"') && str_contains($pb, 'id="tab-me"'),
   'the phone\'s tab bar: Inbox · Search · Compose (her own mailbox) · Me');
ok(str_contains($pb, 'id="assistant-bar"') && str_contains($pb, 'id="record-picker"'), 'the command bar and the record picker are in the shell');

echo "4. The notice gate\n";
$r = page($jd, "/m/$dana/v/inbox");
ok($r['code'] === 302 && str_starts_with($r['location'], '/notice?next='), 'Dana has not read the notice: her Inbox sends her to /notice');
ok(page($jd, "/m/$sales/v/inbox")['code'] === 302 && page($jd, '/search')['code'] === 302, '…and so does every mail screen');
foreach (['/notice', '/tokens', '/my/trail', '/notifications', '/notifications/count'] as $p) { ok(page($jd, $p)['code'] === 200, "…but $p answers (200)"); }
$nb = page($jd, '/notice')['body'];
ok(str_contains($nb, 'How mail is looked after here') && str_contains($nb, 'notice-acknowledge-btn') && !str_contains($nb, 'id="nav-mailbox-'), 'the notice page shows the text, the button, and no mailbox in the rail yet');
$csrf = csrf_of($nb);
$r = req('POST', '/notice/acknowledge.php', ['jar' => $jd, 'form' => ['csrf_token' => $csrf, 'version' => '7', 'next' => "/m/$dana/v/inbox"]]);
ok($r['code'] === 409, 'acknowledging a version not the current one is refused (409)');
$r = req('POST', '/notice/acknowledge.php', ['jar' => $jd, 'form' => ['csrf_token' => $csrf, 'version' => '1', 'next' => "/m/$dana/v/inbox"]]);
ok($r['code'] === 302 && $r['location'] === "/m/$dana/v/inbox", 'acknowledging version 1 lands where she was going');
ok((int) one('SELECT count(*) FROM ml_notice_acknowledgements WHERE member_id = 30 AND notice_version = 1') === 1 && count(activity('notice.acknowledge')) === 1, '…recorded, and in the activity log');
ok(page($jd, "/m/$dana/v/inbox")['code'] === 200, '…and her Inbox opens');
ok($rail($jd, "/m/$dana/v/inbox") === [$dana, $sales], 'Dana\'s rail: her own mailbox and sales@ (a row at view) — not the agent\'s');
shell_exec('sudo -n -u postgres psql -X -q -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg("UPDATE ml_settings SET notice_text = notice_text || ' Revised.'"));
ok(page($jp, "/m/$priya/v/inbox")['code'] === 302, 'a new notice version asks everyone again (Priya is sent to /notice)');
$csrf = csrf_of(page($jp, '/notice')['body']);
req('POST', '/notice/acknowledge.php', ['jar' => $jp, 'form' => ['csrf_token' => $csrf, 'version' => '2']]);
ok(page($jp, "/m/$priya/v/inbox")['code'] === 200, '…and lets her through once she acknowledges version 2');
foreach ([$jd, $jo, $jb, $jm] as $jj) { $c = csrf_of(page($jj, '/notice')['body']); req('POST', '/notice/acknowledge.php', ['jar' => $jj, 'form' => ['csrf_token' => $c, 'version' => '2']]); }
$tok = run_token(40, 9001);
kernel_state(static function (array $s): array { $s['facts']['9001'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'endpoints' => [['application' => 'malumail']]]; return $s; });
$r = req('GET', "/m/$expert/v/inbox", ['headers' => as_agent($tok)]);
ok($r['code'] === 501 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_built', 'an agent\'s action token is never gated by the notice (the Expert\'s own Inbox: 501 not_built, not notice_unacknowledged)');

echo "5. Tokens\n";
$tb = page($jp, '/tokens');
ok($tb['code'] === 200 && str_contains($tb['body'], 'No tokens yet'), 'Priya has no token yet');
ok(page($jp, '/tokens/new')['code'] === 200, 'the mint form is a full page');
$r = req('POST', '/tokens/mint.php', ['jar' => $jp, 'form' => ['csrf_token' => csrf_of($tb['body']), 'name' => 'SMOKE laptop', 'expires_days' => '30']]);
ok($r['code'] === 302 && str_starts_with($r['location'], '/tokens'), 'minting lands on /tokens');
$once = page($jp, '/tokens')['body'];
preg_match('/id="token-secret">(mlm_[0-9a-f]{48})</', $once, $sm);
ok(isset($sm[1]), 'the secret is shown once');
ok(!str_contains(page($jp, '/tokens')['body'], 'token-secret'), '…and never again');
ok(isset($sm[1]) && (int) one('SELECT count(*) FROM mcp_access_tokens WHERE token_hash = :h AND member_id = 26', ['h' => hash('sha256', $sm[1])]) === 1
   && (int) one('SELECT count(*) FROM mcp_access_tokens WHERE token_hash = :s', ['s' => $sm[1] ?? '']) === 0, 'only its SHA-256 is stored');
$tid = (int) one('SELECT id FROM mcp_access_tokens WHERE member_id = 26 ORDER BY id DESC LIMIT 1');
$r = req('POST', '/tokens/revoke.php', ['jar' => $jd, 'form' => ['csrf_token' => csrf_of(page($jd, '/tokens')['body']), 'token' => (string) $tid]]);
ok($r['code'] === 404, 'Dana cannot revoke Priya\'s token (404)');
$r = req('POST', '/tokens/revoke.php', ['jar' => $jp, 'form' => ['csrf_token' => csrf_of(page($jp, '/tokens')['body']), 'token' => (string) $tid]]);
ok($r['code'] === 302 && one('SELECT revoked_at IS NOT NULL FROM mcp_access_tokens WHERE id = :i', ['i' => $tid]) === true, 'Priya revokes her own');
ok(count(activity('token.mint')) === 1 && count(activity('token.revoke')) === 1 && !str_contains(json_encode(activity('token.mint')), $sm[1] ?? 'x'), 'minting and revoking are logged — never the secret');
$r = req('POST', '/tokens/mint.php', ['headers' => array_merge(as_agent($tok), ['Content-Type: application/x-www-form-urlencoded']), 'raw' => 'name=x&expires_days=5']);
ok($r['code'] === 403, 'an agent cannot mint a token (people only)');

echo "6. The trail\n";
$tr = page($jp, '/my/trail')['body'];
ok(str_contains($tr, 'Acknowledged the monitoring notice') && str_contains($tr, 'Minted a token') && str_contains($tr, 'Revoked a token'), 'my trail says what I did, in words');
ok(!str_contains(page($jd, '/my/trail')['body'], 'Minted a token'), 'Dana\'s trail is hers alone');

echo "7. Every manifest screen: its placeholder or itself, by the right and the level\n";
$fill = static function (string $url, array $v): string { return preg_replace_callback('/\{([a-z_]+)\}/', static fn ($m) => (string) ($v[$m[1]] ?? 1), $url); };
$built = ['home', 'notice', 'my-trail', 'tokens-list'];
$wrong = [];
$n = 0;
foreach ($reg['screens'] as $id => $s) {
    if (in_array($id, $built, true)) { continue; }
    $url = $fill($s['url'], ['mailbox' => $priya, 'view' => 'inbox']);
    $isAdmin = str_starts_with($id, 'admin');
    $isOversight = in_array($id, ['oversight', 'review-queue', 'review-view', 'review-edit', 'unscreened'], true);
    $want = $isAdmin ? 403 : 200;
    if ($id === 'agent-policy') { $want = 404; }                                      // Priya's own mailbox is no agent's
    $r = page($jp, $url);
    $n++;
    if ($r['code'] !== $want) { $wrong[] = "$id $url wanted $want got {$r['code']}"; continue; }
    if ($want === 200 && (!str_contains($r['body'], 'builds this screen') || !str_contains($r['body'], 'data-screen="' . $id . '"'))) { $wrong[] = "$id: no placeholder text"; }
    if ($isOversight && $want === 200 && !str_contains($r['body'], 'slice 5')) { $wrong[] = "$id: not slice 5"; }
}
ok($wrong === [], "Priya: $n placeholder screens answer 200 (403 for the admin's, 404 for a policy of her person mailbox)" . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 6)) : ''));
$wrong = [];
foreach ($reg['screens'] as $id => $s) {
    if (!str_starts_with($id, 'admin') || !empty($s['built'])) { continue; }      // a built screen answers by its own rules (its slice's proof)
    $r = page($jo, $fill($s['url'], ['mailbox' => $expert, 'alias' => 3]));
    if ($r['code'] !== 200 || !str_contains($r['body'], 'builds this screen')) { $wrong[] = "$id {$r['code']}"; }
}
ok($wrong === [], 'the Mail admin: every admin screen not yet built answers its placeholder' . ($wrong ? ': ' . implode('; ', $wrong) : ''));
ok(page($jp, "/m/$dana/v/inbox")['code'] === 404 && page($jp, "/m/$dana/settings")['code'] === 404 && page($jp, '/m/999999/v/inbox')['code'] === 404,
   'a mailbox the caller cannot open answers 404 — Dana\'s and a missing one alike (never 403: an address never leaks)');
ok(page($jo, "/m/$priya/v/inbox")['code'] === 404, 'the Mail admin: a person\'s mailbox is 404 too');
$r = page($jd, "/m/$sales/settings");
ok($r['code'] === 403 && str_contains($r['body'], 'read this mailbox (view)'), 'Dana at view on sales@: its settings refused in words (403)');
ok(page($jd, "/m/$sales/v/inbox")['code'] === 200 && page($jd, "/m/$sales/compose")['code'] === 403, '…its Inbox opens; compose needs triage');
ok(page($jp, "/m/$expert/policy")['code'] === 200, 'Priya, the Expert\'s overseer, reaches its policy\'s placeholder');
ok(page($jd, '/oversight')['code'] === 200, 'Oversight\'s placeholder opens to a Mail user (the rail hides it from one who oversees nothing)');
$r = page($jp, '/admin/mailboxes');
ok($r['code'] === 403 && str_contains($r['body'], 'super-admin'), 'a Mail user is refused the admin in the schema\'s sentence');
ok(page($jm, '/admin/mailboxes')['code'] === 403, 'Marco (the admin role, no super-admin) is refused too');
$r = req('POST', "/m/$priya/folders", ['jar' => $jp, 'form' => ['csrf_token' => csrf_of(page($jp, '/my/trail')['body'])]]);
ok($r['code'] === 501, 'a POST to a placeholder: 501');
$r = req('GET', "/m/$priya/folders", ['jar' => $jp] + $json);
ok($r['code'] === 501 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_built', 'JSON to a placeholder: 501 not_built');
ok(page($jp, '/no/such/page')['code'] === 404 && page($jp, "/m/$priya/nonsense")['code'] === 404, 'a path that is no screen: 404');
ok(req('GET', "/m/$priya/v/inbox")['code'] === 302, 'anonymous: sent to the launcher (302)');
ok(page($jp, "/m/$priya")['location'] === "/m/$priya/v/inbox", '/m/{mailbox} opens its Inbox');
ok(str_contains(page($jp, "/m/$priya/v/sent")['body'], 'data-mailbox="' . $priya . '"'), 'a mailbox screen stamps data-mailbox for the command bar');

echo "8. The mail service's client\n";
$php = static fn (string $code): string => trim((string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && php -r ' . escapeshellarg('require "app/bootstrap.php"; ' . $code) . ' 2>&1'));
$out = $php('try { $r = mailer_call("/v1/admin/list_hosted_domains", 1); echo json_encode(array_column($r["domains"], "domain")); } catch (Throwable $e) { echo get_class($e), ":", $e->getMessage(); }');
ok($out === '["business.example","elsewhere.example"]', 'app/mailer.php signs a call the service accepts (the Mail admin lists the hosted domains): ' . $out);
$out = $php('try { mailer_call("/v1/admin/list_hosted_domains", 26); echo "no"; } catch (MailerRefused $e) { echo $e->status, ":", $e->codeName; }');
ok($out === '403:forbidden', 'a Mail user\'s call is refused by the service itself (MailerRefused 403 forbidden): ' . $out);
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok(($h['mailer'] ?? '') === 'ok', '/api/v1/health: mailer ok');
$pid = trim((string) @file_get_contents(need('ML_DEV_STATE') . '/mailer.pid'));
if ($pid !== '') { posix_kill((int) $pid, SIGTERM); usleep(800000); }
$h = json_decode(req('GET', '/api/v1/health')['body'], true);
ok(($h['mailer'] ?? '') === 'down', '…and mailer down when the service stops');
$out = $php('try { mailer_call("/v1/admin/list_hosted_domains", 1); } catch (MailerDown $e) { echo $e->getMessage(); }');
ok($out === 'The mail service is not answering; nothing was changed.', 'MailerDown says so in a sentence');
ok(page($jp, "/m/$priya/v/inbox")['code'] === 200, 'the shell still renders with the service down');

echo "9. The record picker, the bell, the command bar\n";
$r = page($jp, '/pick/?source=member&q=SMOKE');
ok($r['code'] === 200 && str_contains($r['body'], 'SMOKE Dana') && !str_contains($r['body'], 'SMOKE Expert'), 'the member source lists people (not agents)');
ok(str_contains(page($jp, '/pick/agent')['body'], 'SMOKE Expert'), '/pick/agent lists agents');
$r = page($jp, '/pick/?source=mailbox');
ok(str_contains($r['body'], 'priya@business.example') && !str_contains($r['body'], 'dana@business.example'), 'the mailbox source lists only what the caller opens');
ok(page($jp, '/pick/department')['code'] === 200, 'the department source answers');
ok(req('GET', '/pick/?source=member')['code'] === 401, 'anonymous: 401 (a fragment)');
ok(req('GET', '/pick/?source=member', ['headers' => as_agent($tok)])['code'] === 403, 'an agent: 403 (it uses the tools)');
ok(page($jp, '/pick/?source=nonesuch')['code'] === 404, 'an unknown source: 404');
$bell = static fn (): int => preg_match('/id="bell-count">([0-9]+)</', page($jp, '/notifications/count')['body'], $bm) ? (int) $bm[1] : 0;
$before = $bell();
shell_exec('sudo -n -u postgres psql -X -q -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg("SELECT ml_notify(26, 'held_mail', 'SMOKE held', NULL, NULL)"));
ok($bell() === $before + 1 && $before === (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL') - 1, "the bell counts my unread notices ($before, then one more)");
$r = req('POST', '/assistant/ask.php', ['jar' => $jp, 'form' => ['csrf_token' => csrf_of(page($jp, '/my/trail')['body']), 'utterance' => 'what is waiting for my review', 'screen' => 'mailbox-view', 'mailbox' => (string) $priya]]);
ok($r['code'] === 200 && str_contains($r['body'], 'Hello from the fake expert'), 'the command bar answers through the kernel\'s chat endpoint');
$ask = activity('assistant.ask');
ok(count($ask) === 1 && (int) $ask[0]['mailbox_id'] === $priya && !str_contains((string) $ask[0]['after'], 'review'), 'logged with the mailbox — never the words');

finish();
