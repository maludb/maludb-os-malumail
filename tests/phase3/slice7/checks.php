<?php
/**
 * Slice 7's page and action proof (delegation-access.md Proof 1–9): sharing a person's mailbox with a person, a department and an agent;
 * the break-glass form and its refusals; the access log; the worker's expiry; revoke (the owner cannot revoke emergency access); the
 * settings and the owner's two switches; every action refused to an agent's token and in SQL. "Pat" is Priya (26), "Jo" Marco (27, not in
 * Sales), "Dana" (30, in Sales), the Mail admin is member 1. Run by tests/phase3/slice7/run.sh.
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

$CTL = need('ML_IMAP_CONTROL');
function ctl(array $cmd): array
{
    global $CTL;
    $n = bin2hex(random_bytes(8));
    file_put_contents("$CTL/$n.part", json_encode($cmd));
    rename("$CTL/$n.part", "$CTL/$n.json");                      // whole or not at all: the fixture polls *.json
    for ($i = 0; $i < 200; $i++) {
        if (is_file("$CTL/$n.done")) { $o = json_decode((string) file_get_contents("$CTL/$n.done"), true); unlink("$CTL/$n.done"); return $o; }
        usleep(50000);
    }
    throw new RuntimeException('the fixture did not answer');
}
function su(string $sql): string { return trim((string) shell_exec('sudo -n -u postgres psql -X -At -v ON_ERROR_STOP=1 -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg($sql) . ' 2>&1')); }
function wait_until(callable $f, float $timeout = 20.0): bool { $t = microtime(true); while (microtime(true) - $t < $timeout) { if ($f()) { return true; } usleep(200000); } return false; }
const DMARC = 'default: False [0.40 / 15.00]; DMARC_POLICY_ALLOW(-0.50)[outside.example,none]; R_SPF_ALLOW(-0.20)';
function deliver(string $user, string $subject, string $text): array
{
    global $RUN;
    $subject .= $RUN;
    $mid = '<' . bin2hex(random_bytes(8)) . '@outside.example>';
    $h = "From: Outside Person <someone@outside.example>\r\nTo: $user\r\nSubject: $subject\r\nDate: " . date(DATE_RFC2822) . "\r\nMessage-ID: $mid\r\nMIME-Version: 1.0\r\nX-Spamd-Result: " . DMARC . "\r\n";
    ctl(['op' => 'deliver', 'user' => $user, 'raw' => $h . "Content-Type: text/plain; charset=utf-8\r\n\r\n" . $text]);
    $mb = mb(explode('@', $user)[0]);
    if (!wait_until(static fn (): bool => one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :mb AND message_id_header = :m AND thread_id IS NOT NULL', ['mb' => $mb, 'm' => $mid]) > 0, 90.0)) { throw new RuntimeException("not indexed: $subject"); }
    // the conversation is assigned, then settled by the mail service's next pass (the first id can be replaced): read it until it holds still
    $last = null;
    $same = 0;
    for ($i = 0; $i < 40 && $same < 3; $i++) {
        $r = q('SELECT id, thread_id FROM mail_messages WHERE mailbox_id = :mb AND message_id_header = :m', ['mb' => $mb, 'm' => $mid])[0];
        $now = (int) $r['thread_id'];
        $same = $now === $last ? $same + 1 : 0;
        $last = $now;
        usleep(500000);
    }
    return [(int) $r['id'], (int) $r['thread_id']];
}
/** A person's form POST (CSRF from a page), plain browser, HTMX or JSON. */
function post(string $jar, string $path, array $form, string $mode = 'form'): array
{
    $form += ['csrf_token' => page_csrf($jar)];
    $h = $mode === 'json' ? ['Accept: application/json'] : ($mode === 'htmx' ? ['HX-Request: true'] : []);
    return req('POST', $path, ['jar' => $jar, 'form' => $form, 'headers' => $h]);
}
function tpost(int $member, string $path, array $form, int $run = 9701): array { return req('POST', $path, ['headers' => as_agent(run_token($member, $run)), 'form' => $form]); }
function jbody(array $r): array { $j = json_decode($r['body'], true) ?: []; return isset($j['data']) && is_array($j['data']) ? $j['data'] : $j; }
function lvl(int $mb, int $member): string { return su("SELECT ml_mailbox_level($mb, $member)"); }
function perm(int $mb, string $kind, int $principal): ?array { return q('SELECT * FROM mailbox_permissions WHERE mailbox_id = :m AND principal_kind = :k AND principal_id = :p', ['m' => $mb, 'k' => $kind, 'p' => $principal])[0] ?? null; }
function worker(string $passes): array
{
    $out = (string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=' . escapeshellarg($passes) . ' 2>&1');
    return json_decode(trim((string) strrchr("\n" . trim($out), "\n")), true) ?: ['raw' => $out];
}

$RUN = ' #' . substr(bin2hex(random_bytes(3)), 0, 6);
$P = mb('priya'); $D = mb('dana'); $S = mb('sales'); $E = mb('expert');
$PAT = 26; $JO = 27; $DANA = 30; $ADMIN = 1; $EXPERT = 40;
[$jp] = sign_on($PAT); [$jd] = sign_on($DANA); [$ja] = sign_on($ADMIN); [$jj] = sign_on($JO);
kernel_state(static function (array $s): array {
    $s['facts']['9701'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 9701, 'endpoints' => [['application' => 'malumail']]];
    return $s;
});
$SALES_DEPT = (int) one("SELECT id FROM departments WHERE name = 'Sales'");
[$m1, $T] = deliver('priya@business.example', 'Pat\'s private note', 'Please keep this between us.');
[$m2, $T2] = deliver('priya@business.example', 'A second note', 'And this one stays in the Inbox.');

echo "1. Pat shares with Jo at triage and with Sales at view; the Sales agent gets nothing\n";
ok(lvl($P, $JO) === 'none' && page($jj, "/m/$P/v/inbox")['code'] === 404, 'before: Jo cannot open Pat\'s mailbox (404, as if it did not exist)');
$r = post($jp, '/access/grant', ['mailbox' => $P, 'person' => $JO, 'level' => 'triage', 'return_to' => "/m/$P/access"]);
ok($r['code'] === 302 && str_contains($r['location'], "/m/$P/access") && str_contains($r['location'], 'notice=granted'), 'Pat shares with Jo at triage → lands on Access with a notice', $r['code'] . ' ' . $r['location']);
$jorow = perm($P, 'member', $JO);
ok($jorow !== null && $jorow['level'] === 'triage' && !$jorow['break_glass'] && (int) $jorow['granted_by'] === $PAT, '…the row: triage, not break-glass, granted by Pat');
ok(count(activity('permission.grant')) === 1 && (int) q("SELECT mailbox_id FROM activity_log WHERE action = 'permission.grant'")[0]['mailbox_id'] === $P, '…logged permission.grant with the mailbox');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = $JO AND kind = 'permission_granted' AND mailbox_id = $P") === 1, '…and Jo is told he can open it');
$r = post($jp, '/access/grant', ['mailbox' => $P, 'department' => $SALES_DEPT, 'level' => 'view']);
ok($r['code'] === 302 && perm($P, 'department', $SALES_DEPT)['level'] === 'view', 'Pat shares with the Sales department at view');
ok(lvl($P, $JO) === 'triage' && lvl($P, $DANA) === 'view', 'Jo holds triage; Dana (in Sales) holds the department\'s view');
ok(lvl($P, $EXPERT) === 'none', 'the Expert is in Sales: a department row admits people only → nothing');
ok(req('GET', "/m/$P/v/inbox", ['headers' => as_agent(run_token(40, 9701))])['code'] === 404, '…and its read of Pat\'s Inbox answers 404');
ok(str_contains(page($jj, "/m/$P/v/inbox")['body'], 'id="nav-mailbox-' . $P . '"'), 'Jo sees Pat\'s mailbox in his rail');
$a = post($jj, '/mail/archive', ['mailbox' => $P, 'thread' => [$T]]);
ok(in_array($a['code'], [200, 302], true), 'Jo (triage) archives a conversation of Pat\'s', $a['code'] . ' T=' . $T . ' ' . trim(preg_replace('/\s+/', ' ', strip_tags($a['body']))));
ok((string) one("SELECT COALESCE(f.special_use, f.name) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.id = $m1") === 'archive', '…and it is in Archive');
$a = post($jj, '/mail/send', ['mailbox' => $P, 'to' => ['x@outside.example'], 'subject' => 'No', 'body' => 'No', 'body_format' => 'text']);
ok($a['code'] === 403, 'Jo cannot send as Pat (triage is below send)', (string) $a['code']);
$a = post($jd, '/mail/archive', ['mailbox' => $P, 'thread' => [$T2]]);
ok($a['code'] === 422 && str_contains($a['body'], 'Viewing a mailbox never changes it'), 'Dana (view) cannot archive: "Viewing a mailbox never changes it."', $a['code'] . ' ' . trim(preg_replace('/\s+/', ' ', strip_tags($a['body']))));
$acc = page($jd, "/m/$P/access");
ok($acc['code'] === 200 && str_contains($acc['body'], 'id="access-yours-level"') && str_contains($acc['body'], 'You can read this mailbox (view).') && !str_contains($acc['body'], 'SMOKE Marco') && !str_contains($acc['body'], 'id="access-shared"'), 'a view reader\'s Access says only "You can read this mailbox (view)" and who shared it');
ok(page($jd, "/m/$P/access/new")['code'] === 403 && page($jd, "/m/$P/access-log")['code'] === 403, '…and has no grant form and no access log (403, in words)');
$a = post($jd, '/access/grant', ['mailbox' => $P, 'person' => 28, 'level' => 'view']);
ok($a['code'] === 403, 'Dana\'s POST to share Pat\'s mailbox → 403', (string) $a['code']);
$jacc = page($jj, "/m/$P/access");
ok($jacc['code'] === 200 && str_contains($jacc['body'], 'id="access-yours-level"') && str_contains($jacc['body'], 'triage'), 'Jo (triage) sees only his own standing too');
$pacc = page($jp, "/m/$P/access");
ok($pacc['code'] === 200 && str_contains($pacc['body'], 'id="access-row-' . $jorow['id'] . '"') && str_contains($pacc['body'], 'SMOKE Marco') && str_contains($pacc['body'], 'Sales') && str_contains($pacc['body'], 'id="access-share-btn"'),
   'Pat\'s Access: Jo\'s row, the department\'s, the Share button');
$j = jbody(page($jp, "/m/$P/access", ['headers' => ['Accept: application/json']]));
ok(count($j['rows'] ?? []) === 2 && ($j['sees_every_row'] ?? false) === true && ($j['can_grant'] ?? '') === 'share', 'Pat\'s Access in JSON: two rows, every row seen, may share', json_encode($j));

echo "2. The Expert: send allowed, full refused with the database's sentence\n";
$r = post($jp, '/access/grant', ['mailbox' => $P, 'agent' => $EXPERT, 'level' => 'send']);
ok($r['code'] === 302 && lvl($P, $EXPERT) === 'send', 'Pat grants the Expert send');
$r = post($jp, '/access/grant', ['mailbox' => $P, 'agent' => $EXPERT, 'level' => 'full']);
ok($r['code'] === 422 && str_contains($r['body'], 'An agent reaches a person') && str_contains($r['body'], 'at most at send'), 'full is refused: "An agent reaches a person\'s mailbox at most at send"', $r['code'] . ' ' . substr(strip_tags($r['body']), 0, 160));
ok(lvl($P, $EXPERT) === 'send', '…the row still says send');
$r = post($jp, '/access/grant', ['mailbox' => $P, 'person' => $JO, 'agent' => $EXPERT, 'level' => 'view']);
ok($r['code'] === 422, 'two principals at once are refused');
$r = post($jp, '/access/grant', ['mailbox' => $P, 'level' => 'view']);
ok($r['code'] === 422, 'no principal is refused');
$r = post($jp, '/access/grant', ['mailbox' => $P, 'person' => $JO, 'level' => 'admin']);
ok($r['code'] === 422, 'a level that is not one of the four is refused');
$r = post($jp, '/access/grant', ['mailbox' => $P, 'department' => 99999, 'level' => 'view'], 'json');
ok($r['code'] === 422 && isset((json_decode($r['body'], true)['error']['fields'] ?? [])['department']), 'a department that does not exist is refused on its field (JSON)', $r['body']);
$r = post($jp, '/access/grant', ['mailbox' => $P, 'person' => $EXPERT, 'level' => 'view']);
ok($r['code'] === 422, 'an agent named as a person is refused');

echo "3. The Mail admin: only the break-glass form on a person's mailbox\n";
$acc = page($ja, "/m/$P/access");
ok($acc['code'] === 200 && str_contains($acc['body'], 'Give emergency access') && !str_contains($acc['body'], 'id="access-yours-level"') && str_contains($acc['body'], 'id="access-break-glass"'), 'the Mail admin\'s Access: the rows and "Give emergency access" (no ordinary share button)');
$form = page($ja, "/m/$P/access/new");
ok($form['code'] === 200 && str_contains($form['body'], 'id="grant-break-glass-note"') && str_contains($form['body'], 'SMOKE Priya is told at once') && str_contains($form['body'], 'id="grant-reason"') && !str_contains($form['body'], 'grant-field-department'),
   'access-grant-new is the break-glass form: one person, a reason, an expiry, "{owner} is told at once"');
$exp7 = gmdate('Y-m-d', time() + 7 * 86400);
$base = ['mailbox' => $P, 'person' => $ADMIN, 'level' => 'view', 'reason' => 'We need the signed contract', 'expires_at' => $exp7];
$r = post($ja, '/access/grant', ['reason' => ''] + $base);
ok($r['code'] === 422 && perm($P, 'member', $ADMIN) === null, 'no reason → refused');
$r = post($ja, '/access/grant', ['reason' => 'urgent'] + $base);
ok($r['code'] === 422 && str_contains($r['body'], 'at least ten characters'), 'a one-word reason → refused in words');
$r = post($ja, '/access/grant', ['expires_at' => gmdate('Y-m-d', time() + 40 * 86400)] + $base);
ok($r['code'] === 422 && str_contains($r['body'], 'at most 30 days') && perm($P, 'member', $ADMIN) === null, '40 days → refused: "at most 30 days"');
$r = post($ja, '/access/grant', ['expires_at' => gmdate('Y-m-d', time() - 86400)] + $base);
ok($r['code'] === 422, 'an expiry in the past → refused');
$r = post($ja, '/access/grant', ['person' => '', 'department' => $SALES_DEPT] + $base);
ok($r['code'] === 422 && str_contains($r['body'], 'one person'), 'a department row as break-glass → refused: "names one person"', $r['code'] . ' ' . substr(strip_tags($r['body']), 0, 120));
$r = post($ja, '/access/grant', ['person' => '', 'agent' => $EXPERT] + $base);
ok($r['code'] === 422, 'an agent as break-glass → refused');
$mark = last_activity_id();
$r = post($ja, '/access/grant', $base);
$bg = perm($P, 'member', $ADMIN);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=break_glass') && $bg !== null && $bg['break_glass'] === true && (int) $bg['granted_by'] === $ADMIN, 'with a reason and 7 days it is made, flagged break-glass', $r['code'] . ' ' . $r['location']);
$days = (float) one("SELECT extract(epoch FROM expires_at - now()) / 86400 FROM mailbox_permissions WHERE id = " . (int) $bg['id']);
ok($days > 6 && $days < 8.1, '…expiring in about 7 days', (string) $days);
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = $PAT AND kind = 'break_glass' AND mailbox_id = $P") === 1, 'Pat is notified at once (a break_glass notice)');
$n = q("SELECT body, title FROM notifications WHERE member_id = $PAT AND kind = 'break_glass'")[0];
ok(str_contains($n['body'], 'We need the signed contract') && str_contains($n['title'], 'emergency'), '…saying why and that it is an emergency');
$bgLogs = activity('permission.break_glass', $mark);
ok(count($bgLogs) === 1 && (int) $bgLogs[0]['mailbox_id'] === $P && (int) $bgLogs[0]['actor_member_id'] === $ADMIN && count(activity('permission.grant', $mark)) === 0, 'logged permission.break_glass (not permission.grant), with the mailbox');
$pacc = page($jp, "/m/$P/access");
ok(str_contains($pacc['body'], 'id="access-break-glass"') && preg_match('/id="access-row-' . $bg['id'] . '"><div class="card h-100 border-danger bg-soft-danger"/', $pacc['body']) === 1 && str_contains($pacc['body'], 'We need the signed contract'), 'Pat\'s Access shows the row red, with the reason');
ok(!str_contains($pacc['body'], 'access-row-' . $bg['id'] . '-revoke"'), '…and no Revoke button for Pat on it (only a Mail admin takes emergency access back)');
ok(str_contains(page($ja, "/m/$P/access")['body'], 'access-row-' . $bg['id'] . '-revoke"'), '…the Mail admin has one');

echo "4. The access log\n";
$mv = last_activity_id();
page($ja, "/m/$P/v/inbox");
page($ja, "/m/$P/v/inbox");
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'mailbox.view' AND actor_member_id = $ADMIN AND mailbox_id = $P AND id > $mv")) === 1, 'the Mail admin opens Pat\'s Inbox twice: mailbox.view once per half hour');
$t = page($ja, "/m/$P/t/$T");
page($ja, "/m/$P/t/$T");
ok($t['code'] === 200 && count(q("SELECT 1 FROM activity_log WHERE action = 'message.open' AND actor_member_id = $ADMIN AND mailbox_id = $P AND id > $mv")) === 2, '…and every conversation open (two) is logged message.open');
page($jp, "/m/$P/v/inbox");
page($jp, "/m/$P/t/$T");
$log = page($jp, "/m/$P/access-log");
ok($log['code'] === 200 && str_contains($log['body'], 'SMOKE Owner') && str_contains($log['body'], 'opened a conversation') && str_contains($log['body'], 'opened the list') && str_contains($log['body'], 'was given emergency access'),
   'Pat reads the log: who, what (opened the list · a conversation · was given emergency access)');
ok(!str_contains($log['body'], 'SMOKE Priya</span>'), '…her own looks are not in it');
$lj = jbody(page($jp, "/m/$P/access-log", ['headers' => ['Accept: application/json']]));
$dids = array_column($lj['looks'] ?? [], 'did');
ok(in_array('mailbox.view', $dids, true) && in_array('message.open', $dids, true) && in_array('permission.break_glass', $dids, true) && !in_array(26, array_column($lj['looks'] ?? [], 'who_id'), true), 'the log in JSON: the same looks, never Pat herself');
ok(count(array_filter($lj['looks'] ?? [], static fn ($l) => $l['through'] === 'The webmail')) === count($lj['looks'] ?? [1]) && ($lj['looks'][0]['through'] ?? '') === 'The webmail', '…each through the webmail');
$f = page($jp, "/m/$P/access-log?who=$ADMIN");
ok($f['code'] === 200 && str_contains($f['body'], 'opened a conversation'), 'filtered by person: the Mail admin\'s looks');
$f = page($jp, "/m/$P/access-log?who=$DANA");
ok($f['code'] === 200 && str_contains($f['body'], 'id="access-log-empty"') && str_contains($f['body'], 'No look matches.'), 'filtered to a person who did not look at it: "No look matches."');
$f = page($jp, "/m/$P/access-log?since=" . gmdate('Y-m-d', time() + 86400));
ok(str_contains($f['body'], 'id="access-log-empty"'), 'since tomorrow: nothing');
ok(page($jp, "/m/$P/access-log?since=yesterday")['code'] === 422, 'a bad date is refused in words');
ok(page($ja, "/m/$P/access-log")['code'] === 200, 'the Mail admin reads it too (access.audit)');
ok(page($jj, "/m/$P/access-log")['code'] === 403, 'Jo (triage) cannot open the log');
ok(page($jd, "/m/$P/access-log")['code'] === 403, 'Dana (view) cannot open the log');
ok(page($jp, "/m/$D/access-log")['code'] === 404, 'a mailbox Pat cannot open is the same 404');
$empty = page($jd, "/m/$D/access-log");
ok($empty['code'] === 200 && str_contains($empty['body'], 'No one but you has looked.'), 'Dana\'s own log is empty: "No one but you has looked."');
$ee = page($ja, "/m/$E/access-log");
ok($ee['code'] === 200, 'the Mail admin reads an agent mailbox\'s log');
ok(page($jp, "/m/$E/access-log")['code'] === 200, '…and so does Priya (full on it: an overseer)');
ok(page($jd, "/m/$E/access-log")['code'] === 403, '…but Dana (triage) does not');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'mailbox.view' AND mailbox_id = $P AND actor_member_id = $PAT") === 0, 'Pat\'s own looks wrote nothing');

echo "5. The worker's expiry\n";
su("SET session_replication_role = replica; UPDATE mailbox_permissions SET expires_at = now() - interval '2 minutes' WHERE id = " . (int) $bg['id']);
ok(lvl($P, $ADMIN) === 'none', 'an expired row is nothing even before the worker');
ok(page($ja, "/m/$P/v/inbox")['code'] === 404, '…the Mail admin\'s next request answers 404');
$w = worker('break_glass_expiry');
ok(($w['ran']['break_glass_expiry']['revoked'] ?? 0) === 1 && !isset($w['ran']['break_glass_expiry']['stub']), 'the pass removes the row (revoked: 1)', json_encode($w));
ok(perm($P, 'member', $ADMIN) === null, '…it is gone');
$ex = q("SELECT * FROM activity_log WHERE action = 'permission.expire' ORDER BY id DESC LIMIT 1")[0] ?? null;
ok($ex !== null && (int) $ex['mailbox_id'] === $P && $ex['source'] === 'system', '…logged permission.expire with the mailbox, source system');
$exa = json_decode((string) $ex['after'], true);
ok(($exa['principal_id'] ?? null) === $ADMIN && ($exa['break_glass'] ?? null) === true, '…naming the principal and that it was break-glass', (string) $ex['after']);
ok(page($jp, "/m/$P/access-log")['code'] === 200 && !str_contains(page($jp, "/m/$P/access")['body'], 'access-row-' . $bg['id']), 'Pat\'s Access no longer lists it');

echo "6. Revoke\n";
$r = post($jp, '/access/revoke', ['mailbox' => $P, 'permission' => $jorow['id']]);
ok($r['code'] === 302 && perm($P, 'member', $JO) === null && lvl($P, $JO) === 'none', 'Pat revokes Jo', (string) $r['code']);
ok(page($jj, "/m/$P/v/inbox")['code'] === 404, '…Jo\'s next request answers 404 for the mailbox');
ok(count(activity('permission.revoke')) >= 1 && (int) q("SELECT mailbox_id FROM activity_log WHERE action = 'permission.revoke' ORDER BY id DESC LIMIT 1")[0]['mailbox_id'] === $P, '…logged permission.revoke with the mailbox');
$r = post($jd, '/access/revoke', ['mailbox' => $P, 'permission' => perm($P, 'department', $SALES_DEPT)['id']]);
ok($r['code'] === 404 && perm($P, 'department', $SALES_DEPT) !== null, 'Dana (view, in the department) cannot revoke the department\'s row: she cannot even see it (404, the row stays)', (string) $r['code']);
$r = post($ja, '/access/grant', $base);
$bg = perm($P, 'member', $ADMIN);
ok($r['code'] === 302 && $bg !== null, 'the Mail admin makes another emergency row');
$r = post($jp, '/access/revoke', ['mailbox' => $P, 'permission' => $bg['id']]);
ok($r['code'] === 403 && perm($P, 'member', $ADMIN) !== null, 'Pat cannot revoke the Mail admin\'s break-glass row (403, the row stays)', (string) $r['code']);
$r = post($ja, '/access/revoke', ['mailbox' => $P, 'permission' => $bg['id']]);
ok($r['code'] === 302 && perm($P, 'member', $ADMIN) === null && lvl($P, $ADMIN) === 'none', 'a Mail admin revokes it');
ok(post($ja, '/access/revoke', ['mailbox' => $P, 'permission' => 99999999])['code'] === 404, 'a row that does not exist is a 404');
ok(post($jp, '/access/revoke', ['mailbox' => $D, 'permission' => $jorow['id']])['code'] === 404, 'Pat cannot revoke through a mailbox she cannot open (404)');
$r = post($jp, '/access/revoke', ['mailbox' => $P], 'json');
ok($r['code'] === 422, 'a revoke naming no row is refused');

echo "7. Settings and the owner's two switches\n";
ok(su("SELECT ai_read::text || share_with_apps::text FROM mailboxes WHERE id = $P") === 'falsefalse', 'both switches are off for a person\'s mailbox by default (R54)');
$pg = page($jp, "/m/$P/settings");
ok($pg['code'] === 200 && str_contains($pg['body'], 'Let the Mail Expert read my mail when I ask') && str_contains($pg['body'], 'Share with applications') && str_contains($pg['body'], 'only in conversations you start')
   && str_contains($pg['body'], 'id="settings-identities"') && str_contains($pg['body'], 'id="settings-undo_seconds"') && !preg_match('/id="settings-ai_read"[^>]*checked/', $pg['body']), 'Pat\'s settings: Reading, Sending, Privacy (both off), the addresses');
$r = post($ja, '/mail/settings-save', ['mailbox' => $P, 'ai_read' => 'no']);
ok(page($ja, "/m/$P/settings")['code'] === 404 && $r['code'] === 404, 'the Mail admin neither sees nor switches Pat\'s settings (404)', (string) $r['code']);
$r = post($ja, '/mail/settings-save', ['mailbox' => $P, 'ai_read' => 'yes']);
ok($r['code'] === 404 && su("SELECT ai_read FROM mailboxes WHERE id = $P") === 'f', '…not even to turn it on');
$mark = last_activity_id();
$r = post($jp, '/mail/settings-save', ['mailbox' => $P, 'ai_read' => 'yes', 'share_with_apps' => 'no', 'undo_seconds' => '9', 'density' => 'compact', 'page_size' => '25', 'conversation_view' => 'no', 'images' => 'always', 'shortcuts' => 'yes']);
ok($r['code'] === 302 && su("SELECT ai_read FROM mailboxes WHERE id = $P") === 't' && su("SELECT share_with_apps FROM mailboxes WHERE id = $P") === 'f', 'Pat switches ai_read on', (string) $r['code']);
$st = json_decode(su("SELECT settings FROM mailboxes WHERE id = $P"), true);
ok(($st['undo_seconds'] ?? null) === 9 && ($st['density'] ?? null) === 'compact' && ($st['page_size'] ?? null) === 25 && ($st['conversation_view'] ?? null) === false && ($st['images'] ?? null) === 'always', '…and the other keys saved with their types', json_encode($st));
$lg = activity('mailbox.settings', $mark);
$la = json_decode((string) ($lg[0]['after'] ?? '{}'), true);
ok(count($lg) === 1 && in_array('ai_read', $la['keys'] ?? [], true) && !in_array('share_with_apps', $la['keys'] ?? [], true) && in_array('undo_seconds', $la['keys'], true) && (int) $lg[0]['mailbox_id'] === $P, '…logged mailbox.settings naming ai_read and the keys that changed (share_with_apps did not)', json_encode($la));
$r = post($jp, '/mail/settings-save', ['mailbox' => $P, 'ai_read' => 'yes', 'undo_seconds' => '9']);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=unchanged'), 'saving what is already so: "Nothing changed"');
$mj = jbody(page($jp, "/m/$P/settings", ['headers' => ['Accept: application/json']]));
ok(($mj['ai_read'] ?? null) === true && ($mj['settings']['page_size'] ?? null) === 25 && ($mj['settings']['conversation_view'] ?? null) === false, 'the settings in JSON, the switches for the owner');
$pg = page($jp, "/m/$P/settings");
ok(preg_match('/id="settings-ai_read"[^>]*checked/', $pg['body']) === 1 && preg_match('/<option value="25" selected>/', $pg['body']) === 1, '…and the page shows what was saved');
foreach ([['undo_seconds', '31'], ['page_size', '40'], ['density', 'huge'], ['images', 'sometimes']] as [$k, $v]) {
    ok(post($jp, '/mail/settings-save', ['mailbox' => $P, $k => $v])['code'] === 422, "$k=$v is refused (422)");
}
ok(post($jp, '/mail/settings-save', ['mailbox' => $P, 'ai_read' => 'maybe'])['code'] === 422, 'ai_read=maybe is refused');
// Jo holds send on Pat's mailbox through a row Pat makes now: he may use it, not change the owner's switches
post($jp, '/access/grant', ['mailbox' => $P, 'person' => $JO, 'level' => 'send']);
$r = post($jj, '/mail/settings-save', ['mailbox' => $P, 'ai_read' => 'no']);
ok($r['code'] === 403 && su("SELECT ai_read FROM mailboxes WHERE id = $P") === 't', 'Jo (send) cannot change the settings (403, he can use the mailbox, not set it)', (string) $r['code']);
post($jp, '/access/grant', ['mailbox' => $P, 'person' => $JO, 'level' => 'full']);
$r = post($jj, '/mail/settings-save', ['mailbox' => $P, 'ai_read' => 'no']);
ok($r['code'] === 403 && su("SELECT ai_read FROM mailboxes WHERE id = $P") === 't', 'even Jo at full cannot flip the owner\'s switch of a person\'s mailbox (the schema: its owner alone)', (string) $r['code']);
$r = post($jj, '/mail/settings-save', ['mailbox' => $P, 'density' => 'comfortable']);
ok($r['code'] === 302 && ((json_decode(su("SELECT settings FROM mailboxes WHERE id = $P"), true)['density'] ?? '') === 'comfortable'), '…but may change the other settings (a full holder)');
post($jp, '/access/revoke', ['mailbox' => $P, 'permission' => perm($P, 'member', $JO)['id']]);
// the agent's mailbox: its overseer switches share_with_apps
$r = post($jp, '/mail/settings-save', ['mailbox' => $E, 'share_with_apps' => 'yes']);
ok($r['code'] === 302 && su("SELECT share_with_apps FROM mailboxes WHERE id = $E") === 't', 'on an agent mailbox its overseer (Priya, full) switches share_with_apps');
$r = post($jd, '/mail/settings-save', ['mailbox' => $E, 'share_with_apps' => 'no']);
ok($r['code'] === 403 && su("SELECT share_with_apps FROM mailboxes WHERE id = $E") === 't', 'Dana (triage on it) cannot (403)', (string) $r['code']);
$r = post($ja, '/mail/settings-save', ['mailbox' => $E, 'share_with_apps' => 'no']);
ok($r['code'] === 302 && su("SELECT share_with_apps FROM mailboxes WHERE id = $E") === 'f', 'a Mail admin can (full on every agent mailbox)');
$sp = page($ja, "/m/$S/settings");
ok($sp['code'] === 200 && str_contains($sp['body'], 'id="settings-privacy"') && !str_contains($sp['body'], 'id="settings-reading"'), 'a Mail admin on a SHARED mailbox sees its two switches only');
$r = post($ja, '/mail/settings-save', ['mailbox' => $S, 'share_with_apps' => 'yes']);
ok($r['code'] === 302 && su("SELECT share_with_apps FROM mailboxes WHERE id = $S") === 't', '…and flips one');
$r = post($ja, '/mail/settings-save', ['mailbox' => $S, 'density' => 'compact']);
ok($r['code'] === 403 || $r['code'] === 404, '…but changes no other setting there', (string) $r['code']);
$sp = page($jp, "/m/$S/settings");
ok($sp['code'] === 200 && str_contains($sp['body'], 'id="settings-reading"') && str_contains($sp['body'], 'id="settings-privacy"'), 'Priya (full on the shared mailbox) sees all of it');
ok(page($jd, "/m/$S/settings")['code'] === 403, 'Dana (view) does not (403)');

echo "8. Sharing an agent's and a shared mailbox\n";
$ea = page($ja, "/m/$E/access");
ok($ea['code'] === 200 && str_contains($ea['body'], 'id="access-by-role"') && str_contains($ea['body'], 'Every Mail admin') && str_contains($ea['body'], 'id="access-role-clerk"') && str_contains($ea['body'], 'id="access-share-btn"') && str_contains($ea['body'], 'Share this mailbox'),
   'the agent mailbox\'s Access: the Mail admins at full and the Clerk at triage "by role", and the ordinary Share button for a Mail admin');
$form = page($ja, "/m/$E/access/new");
ok($form['code'] === 200 && str_contains($form['body'], 'grant-field-person') && str_contains($form['body'], 'grant-field-department') && str_contains($form['body'], 'grant-field-agent') && !str_contains($form['body'], 'grant-break-glass-note'), '…the ordinary form with all three pickers');
$r = post($ja, '/access/grant', ['mailbox' => $E, 'person' => 31, 'level' => 'view']);
ok($r['code'] === 302 && perm($E, 'member', 31)['level'] === 'view' && !perm($E, 'member', 31)['break_glass'], 'a Mail admin shares an agent\'s mailbox (an ordinary row)');
$r = post($jp, '/access/grant', ['mailbox' => $E, 'person' => 31, 'level' => 'triage']);
ok($r['code'] === 302 && perm($E, 'member', 31)['level'] === 'triage', 'its overseer shares it again (changes Lee\'s level)');
$r = post($jd, '/access/grant', ['mailbox' => $E, 'person' => 31, 'level' => 'full']);
ok($r['code'] === 403, 'Dana (triage) cannot (403)');
$r = post($jp, '/access/revoke', ['mailbox' => $E, 'permission' => perm($E, 'member', 31)['id']]);
ok($r['code'] === 302 && perm($E, 'member', 31) === null, 'its overseer revokes');
$sa = page($ja, "/m/$S/access");
ok($sa['code'] === 200 && !str_contains($sa['body'], 'id="access-by-role"') && str_contains($sa['body'], 'A shared mailbox'), 'a shared mailbox\'s Access: no owner, no role holders');
$r = post($ja, '/access/grant', ['mailbox' => $S, 'person' => 28, 'level' => 'view', 'expires_at' => gmdate('Y-m-d', time() + 5 * 86400)]);
ok($r['code'] === 302 && perm($S, 'member', 28)['expires_at'] !== null && !perm($S, 'member', 28)['break_glass'], 'a Mail admin shares a shared mailbox with an expiry, not as break-glass');
$r = post($ja, '/access/grant', ['mailbox' => $D, 'person' => 28, 'level' => 'view', 'reason' => 'Contract check, nothing else', 'expires_at' => gmdate('Y-m-d', time() + 3 * 86400)]);
ok($r['code'] === 302 && perm($D, 'member', 28)['break_glass'] === true, 'a Mail admin\'s row on any person\'s mailbox is break-glass');
$r = post($ja, '/access/revoke', ['mailbox' => $D, 'permission' => perm($D, 'member', 28)['id']]);
ok($r['code'] === 302, '…and taken back');
$r = post($jp, '/access/grant', ['mailbox' => $D, 'person' => 28, 'level' => 'view']);
ok($r['code'] === 404, 'Pat cannot share Dana\'s mailbox: she cannot see it (404)');

echo "9. Every action refused to an agent, in the page and in SQL\n";
$pid = (int) perm($S, 'member', 28)['id'];
foreach ([['/access/grant', ['mailbox' => $E, 'person' => $JO, 'level' => 'view']], ['/access/revoke', ['mailbox' => $S, 'permission' => $pid]], ['/mail/settings-save', ['mailbox' => $E, 'share_with_apps' => 'yes']]] as [$path, $form]) {
    $r = tpost($EXPERT, $path, $form);
    ok($r['code'] === 403, "$path with the Expert's run token → 403", $r['code'] . ' ' . substr($r['body'], 0, 100));
}
ok(perm($E, 'member', $JO) === null && perm($S, 'member', 28) !== null && su("SELECT share_with_apps FROM mailboxes WHERE id = $E") === 'f', '…and nothing changed');
$r = tpost($EXPERT, '/access/grant', ['mailbox' => $E, 'person' => $JO, 'level' => 'view'], 9701);
foreach ([["SELECT ml_permission_grant($E, 40, 'member', $JO, 'view')", 'a person'], ["SELECT ml_permission_revoke($S, 40, 'member', 28)", 'a person'], ["SELECT ml_mailbox_settings_save($E, 40, '{}'::jsonb, NULL, true)", 'a person']] as [$sql, $like]) {
    $out = su($sql);
    ok(str_contains($out, 'That is a person') && perm($E, 'member', $JO) === null, 'called directly as the agent: refused ("That is a person\'s decision")', $out);
}
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
foreach (['mail_permission_grant', 'mail_permission_revoke', 'mail_mailbox_settings_save'] as $a) {
    ok(($reg['actions'][$a]['built'] ?? false) === true && ($reg['actions'][$a]['agent'] ?? true) === false, "$a is built and people only in the registry");
}
foreach (['mailbox-access', 'access-grant-new', 'mailbox-access-log', 'mailbox-settings'] as $s) {
    ok(($reg['screens'][$s]['built'] ?? false) === true, "screen $s is built");
}
ok(post($jp, '/access/grant', ['mailbox' => $P, 'person' => $JO, 'level' => 'view', 'csrf_token' => 'wrong'])['code'] === 403, 'a POST with a wrong CSRF token is refused');
ok(req('POST', '/access/grant', ['form' => ['mailbox' => $P], 'headers' => ['Accept: application/json']])['code'] === 401, 'an anonymous JSON POST is a 401');
ok(req('GET', '/access/grant', ['jar' => $jp])['code'] === 405, 'a GET to an action is refused (405)');

echo "10. What the log keeps\n";
$all = (string) one("SELECT string_agg(COALESCE(before::text, '') || COALESCE(after::text, ''), ' ') FROM activity_log WHERE action IN ('permission.grant', 'permission.break_glass', 'permission.revoke', 'permission.expire', 'mailbox.settings')");
ok(!str_contains($all, 'signed contract') && !str_contains($all, 'Contract check') && !str_contains($all, 'private note'), 'no reason and no mail text in any payload of the five events');
ok(count(array_unique(array_column(q("SELECT action FROM activity_log WHERE action IN ('permission.grant', 'permission.break_glass', 'permission.revoke', 'permission.expire', 'mailbox.settings')"), 'action'))) === 5, 'all five events of the slice were written');
ok((int) one("SELECT count(*) FROM activity_log WHERE action IN ('permission.grant', 'permission.break_glass', 'permission.revoke', 'mailbox.settings') AND mailbox_id IS NULL") === 0, 'every one carries its mailbox');
ok(str_contains(page($jp, "/m/$P/v/inbox")['body'], 'id="nav-manage-access"') && str_contains(page($jp, "/m/$P/v/inbox")['body'], 'id="nav-manage-settings"') && !str_contains(page($jd, "/m/$S/v/inbox")['body'], 'id="nav-manage-settings"'), 'the rail links Access to everyone and Settings to those who hold full');

finish();
