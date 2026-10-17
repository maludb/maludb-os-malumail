<?php
/**
 * Slice 2's list proof (docs/build-specs/mail-list.md Proof 2–7 and 9): the wall, the ten views and a folder, unread per person, keyset
 * paging, the poll, the access log, the timings — against the index the mail service keeps from the IMAP fixture (run by
 * tests/phase3/slice2/run.sh after sync.py). The fixture is driven through its control directory; the rest through the screens' doors.
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
function wait_until(callable $f, float $timeout = 10.0): ?float
{
    $t = microtime(true);
    while (microtime(true) - $t < $timeout) { if ($f()) { return microtime(true) - $t; } usleep(100000); }
    return null;
}
function su(string $sql): string { return trim((string) shell_exec('sudo -n -u postgres psql -X -At -v ON_ERROR_STOP=1 -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg($sql) . ' 2>&1')); }
function rows_of(string $html): array { preg_match_all('/class="list-group-item mail-row[^"]*" id="([to])-(\d+)"/', $html, $m); return array_map(static fn ($k, $v) => $k . $v, $m[1], $m[2]); }
function tids(string $html): array { return array_map(static fn ($x) => (int) substr($x, 1), array_filter(rows_of($html), static fn ($x) => $x[0] === 't')); }
function jlist(string $jar, string $path): array { $r = page($jar, $path, ['headers' => ['Accept: application/json']]); return json_decode($r['body'], true)['data'] ?? []; }
function as_member(int $m, string $sql, array $args = []) { pdo()->exec("SELECT set_config('app.member_id', '$m', false)"); $v = one($sql, $args); pdo()->exec("SELECT set_config('app.member_id', '', false)"); return $v; }

$priya = mb('priya'); $dana = mb('dana'); $sales = mb('sales'); $team = mb('team'); $expert = mb('expert'); $intake = mb('intake'); $big = mb('big');
[$jp] = sign_on(26); [$jd] = sign_on(30); [$jo] = sign_on(1); [$jm] = sign_on(27); [$jb] = sign_on(28);

echo "2. The wall\n";
$codes = static fn (string $jar, array $boxes): array => array_map(static fn (int $b): int => page($jar, "/m/$b/v/inbox")['code'], $boxes);
ok($codes($jp, [$priya, $sales, $team, $big, $expert]) === [200, 200, 200, 200, 200], 'Priya opens her own, sales@ (triage), team@ (her department), big@ (full) and the agent mailbox she oversees');
ok($codes($jp, [$dana, $intake]) === [404, 404], '…and nothing else: Dana\'s and intake@ answer 404 (never 403 — an address never leaks)');
ok($codes($jd, [$dana, $sales, $team, $intake]) === [200, 200, 200, 200] && $codes($jd, [$priya, $expert, $big]) === [404, 404, 404],
   'Dana: her own, sales@ (view), team@ (department), intake@ (triage) — not Priya\'s, the agent\'s or big@');
ok($codes($jo, [$expert]) === [200] && $codes($jo, [$priya, $dana]) === [404, 404], 'a Mail admin opens an agent\'s mailbox — never a person\'s by holding the role');
ok($codes($jm, [$priya, $expert, $sales]) === [404, 404, 404], 'Marco (an admin role, no Mail admin) opens none of them');
kernel_state(static function (array $s): array {
    $s['facts']['9101'] = ['valid' => true, 'is_agent' => true, 'member_id' => 43, 'endpoints' => [['application' => 'malumail']]];
    $s['facts']['9102'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'endpoints' => [['application' => 'malumail']]];
    return $s;
});
ok((int) one('SELECT count(*) FROM department_members WHERE member_id = 43 AND department_id = 8') === 1
   && req('GET', "/m/$team/v/inbox", ['headers' => as_agent(run_token(43, 9101))])['code'] === 404,
   'a department row admits the department\'s PEOPLE only: Ada, an agent put in Sales, gets 404 on team@');
$ag = req('GET', "/m/$expert/v/inbox", ['headers' => as_agent(run_token(40, 9102))]);
$agRows = json_decode($ag['body'], true)['data']['rows'] ?? null;
ok($ag['code'] === 200 && is_array($agRows) && count($agRows) > 0, 'the Expert reads its own mailbox\'s list through the same gatherer (JSON)');
ok(req('GET', "/m/$priya/v/inbox", ['headers' => as_agent(run_token(40, 9102))])['code'] === 404, '…and not Priya\'s (no person behind a screen\'s run: its own level)');
$sens = (int) $agRows[0]['thread_id'];
su("INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, sensitive, sensitive_by) SELECT $expert, message_key_id, true, 26 FROM mail_messages WHERE thread_id = $sens LIMIT 1");
$agIds = array_column(json_decode(req('GET', "/m/$expert/v/inbox", ['headers' => as_agent(run_token(40, 9102))])['body'], true)['data']['rows'] ?? [], 'thread_id');
$pb = page($jp, "/m/$expert/v/inbox")['body'];
ok(!in_array($sens, $agIds, true) && in_array($sens, tids($pb), true) && preg_match('/id="t-' . $sens . '".*?mail-sensitive/s', $pb) === 1,
   'a conversation marked sensitive: hidden from the agent, shown to Priya with a lock');

echo "   the rail\n";
$rail = static function (string $html): array {
    preg_match_all('/id="rail-group-([a-z]+)"|id="nav-mailbox-([0-9]+)"/', $html, $m, PREG_SET_ORDER);
    $out = []; $g = null;
    foreach ($m as $x) { if (($x[1] ?? '') !== '') { $g = $x[1]; $out[$g] = []; } else { $out[$g][] = (int) $x[2]; } }
    return $out;
};
$rp = $rail($pb);
ok($rp === ['mine' => [$priya], 'shared' => [$big, $sales, $team], 'oversee' => [$expert]], 'Priya\'s rail: My mailbox, Shared with me, Agents I oversee', json_encode($rp));
ok($rail(page($jo, "/m/$expert/v/inbox")['body']) === ['agents' => [$expert]], 'a Mail admin\'s rail: the agent mailbox under Agents');
$unreadPriya = (int) as_member(26, 'SELECT ml_unread_count(:m)', ['m' => $priya]);
ok(preg_match('/id="nav-mailbox-' . $priya . '".*?rail-count">' . $unreadPriya . '</s', $pb) === 1 && $unreadPriya > 0,
   "the rail's count is the caller's unread Inbox ($unreadPriya)");
ok(preg_match('/id="rail-views-' . $expert . '".*?id="nav-view-drafts"/s', $pb) === 1 && str_contains($pb, 'id="nav-view-trash"'), 'the current mailbox expands to its views');
su("INSERT INTO mailboxes (address, display_name, kind) VALUES ('nosync@business.example', 'Never synced', 'shared');
    INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) SELECT id, 'member', 28, 'view', 1 FROM mailboxes WHERE address = 'nosync@business.example'");
$nosync = (int) one("SELECT id FROM mailboxes WHERE address = 'nosync@business.example'");
$bb = page($jb, "/m/$nosync/v/inbox")['body'];
ok(preg_match('/id="nav-mailbox-' . $nosync . '".*?rail-count">—</s', $bb) === 1, 'a mailbox never synced shows — (its count is not known yet)');
ok(str_contains($bb, 'This mailbox is not connected yet'), '…and its list says it is not connected yet');
ok(count($rail($bb)['shared'] ?? []) === 31, 'Bea\'s rail lists the 31 mailboxes shared with her');

echo "3. The views\n";
// fixtures for the views: a phone's draft, a sent message, spam, trash, a folder of its own; a snooze; scheduled and outbox rows
ctl(['op' => 'deliver', 'user' => 'priya@business.example', 'folder' => 'Drafts', 'subject' => 'Draft from my phone', 'flags' => ['\\Draft', '\\Seen']]);
ctl(['op' => 'deliver', 'user' => 'priya@business.example', 'folder' => 'Sent', 'subject' => 'Sent by me', 'from' => 'priya@business.example', 'to' => 'client@outside.example', 'flags' => ['\\Seen']]);
ctl(['op' => 'deliver', 'user' => 'priya@business.example', 'folder' => 'Junk', 'subject' => 'Buy now']);
ctl(['op' => 'deliver', 'user' => 'priya@business.example', 'folder' => 'Trash', 'subject' => 'Thrown away']);
ctl(['op' => 'create', 'user' => 'priya@business.example', 'folder' => 'Projects.Alpha']);
ctl(['op' => 'deliver', 'user' => 'priya@business.example', 'folder' => 'Projects.Alpha', 'subject' => 'Alpha kickoff']);
$arrived = wait_until(static fn (): bool => (int) one("SELECT count(*) FROM mail_messages WHERE mailbox_id = $priya AND subject IN ('Draft from my phone', 'Sent by me', 'Buy now', 'Thrown away', 'Alpha kickoff')") === 5, 75);
ok($arrived !== null, 'mail in Drafts, Sent, Junk, Trash and a new folder reaches the index (NOTIFY\'s STATUS events)');
$tid = static fn (string $subject): int => (int) one('SELECT thread_id FROM mail_messages WHERE mailbox_id = :m AND subject = :s', ['m' => $priya, 's' => $subject]);
$view = static fn (string $v, string $jar = '') => page($jar ?: $GLOBALS['jp'], "/m/$priya/v/$v");
ok(tids($view('drafts')['body']) === [$tid('Draft from my phone')], 'Drafts lists the phone\'s draft');
ok(tids($view('sent')['body']) === [$tid('Sent by me')] && str_contains($view('sent')['body'], 'client'), 'Sent lists what was sent, with whom');
ok(tids($view('spam')['body']) === [$tid('Buy now')], 'Spam lists Junk');
ok(tids($view('trash')['body']) === [$tid('Thrown away')], 'Trash lists Trash');
su("UPDATE mailboxes SET settings = settings || '{\"page_size\": 100}' WHERE id = $priya");
$inbox = tids($view('inbox')['body']);
$all = array_column(jlist($jp, "/m/$priya/v/all")['rows'], 'thread_id');
ok(in_array($tid('Sent by me'), $all, true) && in_array($inbox[0], $all, true) && !array_intersect([$tid('Buy now'), $tid('Thrown away')], $all),
   'All mail holds Sent and the Inbox, never Spam or Trash');
$starredExpected = array_map('intval', array_column(q("SELECT t.id FROM mail_threads t WHERE t.mailbox_id = $priya AND t.flagged ORDER BY t.last_message_at DESC, t.id DESC"), 'id'));
ok(tids($view('starred')['body']) === $starredExpected && $starredExpected !== [], 'Starred lists the starred conversations', json_encode($starredExpected));
$folderId = (int) one("SELECT id FROM mail_folders WHERE mailbox_id = $priya AND name = 'Projects.Alpha'");
$fp = page($jp, "/m/$priya/f/$folderId");
ok($fp['code'] === 200 && tids($fp['body']) === [$tid('Alpha kickoff')] && str_contains($fp['body'], 'data-screen="folder-view"'), 'folder-view lists one folder\'s conversations');
ok(preg_match('/id="nav-folder-' . $folderId . '"[^>]*padding-left: 0\.75rem.*?>Alpha</s', $fp['body']) === 1, '…and the rail shows the folder as a tree by its delimiter (Projects ▸ Alpha)');
ok(page($jp, "/m/$priya/f/" . (int) one("SELECT id FROM mail_folders WHERE mailbox_id = $big LIMIT 1"))['code'] === 404, 'another mailbox\'s folder: 404');
ok(page($jp, "/m/$priya/v/nonsense")['code'] === 404, 'an unknown view: 404');
// a snooze
$snoozed = $inbox[3];
su("INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, snoozed_until, snoozed_from_folder, snoozed_by)
    SELECT $priya, message_key_id, now() + interval '2 days', 'INBOX', 26 FROM mail_messages WHERE thread_id = $snoozed LIMIT 1");
$sz = $view('snoozed')['body'];
ok(tids($sz) === [$snoozed] && preg_match('/id="t-' . $snoozed . '".*?feather-clock/s', $sz) === 1, 'a snoozed conversation is in Snoozed, its wake time shown');
ok(!in_array($snoozed, tids($view('inbox')['body']), true), '…and not in the Inbox');
// scheduled and the outbox, from outbound_email
$key = (int) one("SELECT message_key_id FROM mail_messages WHERE mailbox_id = $expert ORDER BY id LIMIT 1");
su("INSERT INTO outbound_email (mailbox_id, to_address, to_addresses, subject, status, send_after, sender_member_id)
    VALUES ($priya, 'later@outside.example', '{later@outside.example}', 'Goes tomorrow', 'queued', now() + interval '1 day', 26),
           ($priya, 'gone@outside.example', '{gone@outside.example}', 'Bounced away', 'queued', now() - interval '1 hour', 26),
           ($priya, 'slow@outside.example', '{slow@outside.example}', 'Trying again', 'queued', now() - interval '1 hour', 26);
    UPDATE outbound_email SET status = 'sending' WHERE mailbox_id = $priya AND subject IN ('Bounced away', 'Trying again');
    UPDATE outbound_email SET status = CASE subject WHEN 'Bounced away' THEN 'failed' ELSE 'deferred' END, smtp_reply = '550 5.1.1 no such user'
     WHERE mailbox_id = $priya AND subject IN ('Bounced away', 'Trying again');
    INSERT INTO outbound_email (mailbox_id, to_address, to_addresses, subject, status, send_after, sender_member_id, sender_is_agent, purpose, hold_reasons, reply_to_key_id)
    VALUES ($expert, 'new@outside.example', '{new@outside.example}', 'Re: a question', 'held', now(), 40, true, 'Answer a question', '{first_contact}', $key)");
$sc = $view('scheduled')['body'];
ok(count(rows_of($sc)) === 1 && str_contains($sc, 'Goes tomorrow') && str_contains($sc, 'later@outside.example') && str_contains($sc, 'feather-send'),
   'Scheduled lists a queued row past the undo window: To, subject, the time it goes');
$ob = $view('outbox')['body'];
ok(count(rows_of($ob)) === 2 && str_contains($ob, '>Failed<') && str_contains($ob, '>Trying again<'), 'the Outbox lists failed and deferred rows with their state');
$eb = page($jp, "/m/$expert/v/outbox")['body'];
ok(str_contains($eb, 'Held for review') && str_contains($eb, 'first_contact'), '…an agent\'s held reply with its reasons');
$keyThread = (int) one("SELECT thread_id FROM mail_messages WHERE message_key_id = $key");
ok(preg_match('/id="t-' . $keyThread . '".*?mail-agent-badge[^>]*>.*?awaiting review/s', page($jp, "/m/$expert/v/inbox")['body']) === 1,
   'the conversation it answers carries the "awaiting review" badge (from outbound_email, never a header)');
ok(str_contains(page($jd, "/m/$dana/v/scheduled")['body'], 'Nothing scheduled.') && str_contains(page($jd, "/m/$dana/v/spam")['body'], 'Nothing here.'),
   'empty views say so: "Nothing scheduled.", "Nothing here."');

echo "4. Unread\n";
$serverUnread = (int) one("SELECT count(*) FROM mail_threads t JOIN mail_folders f ON f.mailbox_id = t.mailbox_id AND f.special_use = 'inbox'
                            WHERE t.mailbox_id = $sales AND f.id = ANY (t.folder_ids) AND t.unread_count > 0");
$pS = (int) as_member(26, 'SELECT ml_unread_count(:m)', ['m' => $sales]);
$dS = (int) as_member(30, 'SELECT ml_unread_count(:m)', ['m' => $sales]);
ok($pS === $serverUnread && $dS === $serverUnread, "before any mark: Priya (triage) and Dana (view) both count the server's unread ($serverUnread)");
$unreadKey = (int) one("SELECT m.message_key_id FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id AND f.special_use = 'inbox'
                         WHERE m.mailbox_id = $sales AND NOT m.seen AND (SELECT message_count FROM mail_threads WHERE id = m.thread_id) = 1 LIMIT 1");
$stores0 = json_decode((string) file_get_contents(need('ML_IMAP_STATS')), true)['_']['stores'];
su("INSERT INTO mail_viewer_reads (member_id, message_key_id) VALUES (30, $unreadKey)");
ok((int) as_member(30, 'SELECT ml_unread_count(:m)', ['m' => $sales]) === $serverUnread - 1, 'Dana (a view holder) marking one read lowers HER count by one');
ok((int) as_member(26, 'SELECT ml_unread_count(:m)', ['m' => $sales]) === $serverUnread, '…Priya\'s (triage: the server\'s flag) is unchanged');
$dRow = page($jd, "/m/$sales/v/inbox")['body'];
$readThread = (int) one("SELECT thread_id FROM mail_messages WHERE message_key_id = $unreadKey");
ok(preg_match('/class="list-group-item mail-row d-flex[^"]*" id="t-' . $readThread . '"/', $dRow) === 1, '…the row is no longer bold on Dana\'s list');
ok(preg_match('/class="list-group-item mail-row d-flex[^"]*mail-unread[^"]*" id="t-' . $readThread . '"/', page($jp, "/m/$sales/v/inbox")['body']) === 1, '…and still bold on Priya\'s');
ok(json_decode((string) file_get_contents(need('ML_IMAP_STATS')), true)['_']['stores'] === $stores0, '…and nothing reached the server');
$iU = (int) one("SELECT count(*) FROM mail_threads t JOIN mail_folders f ON f.mailbox_id = t.mailbox_id AND f.special_use = 'inbox' WHERE t.mailbox_id = $intake AND f.id = ANY (t.folder_ids) AND t.unread_count > 0");
$iKey = (int) one("SELECT m.message_key_id FROM mail_messages m WHERE m.mailbox_id = $intake AND NOT m.seen AND (SELECT message_count FROM mail_threads WHERE id = m.thread_id) = 1 LIMIT 1");
su("INSERT INTO mail_viewer_reads (member_id, message_key_id) VALUES (30, $iKey)");
ok((int) as_member(30, 'SELECT ml_unread_count(:m)', ['m' => $intake]) === $iU - 1, 'on an intake mailbox even a triage holder counts their own marks (D13: never \\Seen there)');

echo "5. Paging\n";
$p1 = jlist($jp, "/m/$big/v/inbox");
$total = (int) $p1['total'];
$expected = array_map('intval', explode(',', su("SELECT string_agg(row_id::text, ',' ORDER BY row_at DESC, row_id DESC) FROM ml_mail_list_ids_core($big, 'inbox', NULL, NULL, false, NULL, NULL, 100, false, 26, true)")));
ok(count($p1['rows']) === 50 && $p1['older'] !== null && $p1['newer'] === null && $p1['start'] === 0, 'page 1: 50 rows, an Older cursor, no Newer');
for ($i = 0; $i < 10; $i++) { ctl(['op' => 'deliver', 'user' => 'big@business.example', 'subject' => "Mid-way $i"]); }
ok(wait_until(static fn (): bool => (int) one("SELECT count(*) FROM mail_messages WHERE mailbox_id = $big AND subject LIKE 'Mid-way %'") === 10, 20) !== null,
   '10 new conversations arrive mid-way');
$p2 = jlist($jp, "/m/$big/v/inbox?before=" . $p1['older'] . '&n=50');
$seen = array_merge(array_column($p1['rows'], 'thread_id'), array_column($p2['rows'], 'thread_id'));
ok($seen === $expected, 'keyset: page 2 continues exactly where page 1 stopped — no row twice, none skipped, the new mail not shifting it');
ok($p2['start'] === 50 && $p2['newer'] === '', '…page 2 knows it starts at 51 and that Newer is the first page');
$h2 = page($jp, "/m/$big/v/inbox?before=" . $p1['older'] . '&n=50')['body'];
ok(preg_match('/id="mail-range">51–100 of ' . number_format($total + 10) . '</', $h2) === 1 && str_contains($h2, 'id="mail-newer"') && str_contains($h2, 'id="mail-older"'),
   '"51–100 of N" with Newer and Older', (preg_match('/id="mail-range">([^<]*)/', $h2, $mm) ? $mm[1] : ''));
$h2n = page($jp, "/m/$big/v/inbox?before=" . $p1['older'])['body'];
ok(preg_match('/id="mail-range">61–110 of/', $h2n) === 1, 'a link without the row number counts it once (61–110: the 10 new ones are above it now)');
foreach ([25, 100, 50] as $size) {
    su("UPDATE mailboxes SET settings = settings || '{\"page_size\": $size}' WHERE id = $big");
    $n = count(tids(page($jp, "/m/$big/v/inbox")['body']));
    ok($n === $size, "the mailbox's page size: $size rows");
}
$hx = page($jp, "/m/$big/v/inbox?before=" . $p1['older'] . '&n=50', ['headers' => ['HX-Request: true', 'HX-Target: mail-list']]);
ok($hx['code'] === 200 && str_starts_with(ltrim($hx['body']), '<div id="mail-list"') && !str_contains($hx['body'], '<html'), 'Older/Newer swap #mail-list alone (HTMX)');
// more than 10,000
su("INSERT INTO mailboxes (address, display_name, kind) VALUES ('huge@business.example', 'Huge', 'shared');
    INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) SELECT id, 'member', 26, 'view', 1 FROM mailboxes WHERE address = 'huge@business.example';
    INSERT INTO mail_folders (mailbox_id, name, special_use) SELECT id, 'INBOX', 'inbox' FROM mailboxes WHERE address = 'huge@business.example';
    UPDATE mailboxes SET last_sync_at = now(), sync_state = 'live' WHERE address = 'huge@business.example';
    SET app.ml_bulk = 'on';
    INSERT INTO mail_threads (mailbox_id, subject, participants, last_message_at, first_message_at, message_count, folder_ids)
    SELECT b.id, 'Huge ' || g, '{x@outside.example}', now() - g * interval '1 minute', now() - g * interval '1 minute', 1, ARRAY[f.id]
      FROM mailboxes b JOIN mail_folders f ON f.mailbox_id = b.id, generate_series(1, 10050) g WHERE b.address = 'huge@business.example'");
$huge = (int) one("SELECT id FROM mailboxes WHERE address = 'huge@business.example'");
ok(preg_match('/id="mail-range">1–50 of more than 10,000</', page($jp, "/m/$huge/v/inbox")['body']) === 1, '"1–50 of more than 10,000" (a bounded count)');

echo "6. The poll\n";
$pp = page($jp, "/m/$priya/v/inbox")['body'];
preg_match('/data-change-seq="(\d+)"/', $pp, $m1); preg_match('/data-top="([^"]*)"/', $pp, $m2);
preg_match('/data-limit="(\d+)"/', $pp, $m3);
$since = (int) $m1[1]; $top = $m2[1]; $ids = implode(',', tids($pp)); $lim = (int) $m3[1];
$poll = static fn (int $s, string $jar = '') => page($jar ?: $GLOBALS['jp'], "/m/$priya/changes?since=$s&view=inbox&top=" . rawurlencode($GLOBALS['top']) . "&limit={$GLOBALS['lim']}&ids=" . $GLOBALS['ids']);
$r = $poll($since);
ok($r['code'] === 204 && $r['body'] === '' && preg_match('/^X-ML-Seq: ' . $since . '\r?$/mi', $r['headers']) === 1, '204 when nothing changed (the counter is the page\'s)');
$times = [];
for ($i = 0; $i < 20; $i++) { $t = microtime(true); as_member(26, 'SELECT ml_mailbox_seq(:m)', ['m' => $priya]); $times[] = (microtime(true) - $t) * 1000; }
sort($times);
ok($times[10] < 10, sprintf('…its query under 10 ms (median %.2f ms)', $times[10]));
ok(page($jd, "/m/$priya/changes?since=0&view=inbox")['code'] === 404, 'the poll of a mailbox the caller cannot open: 404');
ctl(['op' => 'deliver', 'user' => 'priya@business.example', 'subject' => 'Polled in']);
wait_until(static fn (): bool => (int) one("SELECT count(*) FROM mail_messages WHERE mailbox_id = $priya AND subject = 'Polled in'") === 1, 10);
$r = $poll($since);
preg_match('/^X-ML-Seq: (\d+)/mi', $r['headers'], $sq); preg_match('/^X-ML-Unread: (\d+)/mi', $r['headers'], $un);
ok($r['code'] === 200 && str_contains($r['body'], '1 new conversation') && str_contains($r['body'], 'id="mail-new-bar" hx-swap-oob="outerHTML"'),
   'a delivered message → 200 with the "1 new" bar (the page itself not shifted)');
ok(str_contains($r['body'], 'id="rail-counts" hx-swap-oob="outerHTML"') && (int) ($un[1] ?? -1) === (int) as_member(26, 'SELECT ml_unread_count(:m)', ['m' => $priya])
   && (int) ($sq[1] ?? 0) > $since, '…the rail\'s counts swapped, X-ML-Unread the Inbox\'s count, X-ML-Seq the next since');
$since2 = (int) $sq[1];
$pageIds = tids($pp);
$flagT = $pageIds[1];
$flagUid = (int) one("SELECT uid FROM mail_messages WHERE thread_id = $flagT ORDER BY received_at DESC LIMIT 1");
ctl(['op' => 'flag', 'user' => 'priya@business.example', 'uid' => $flagUid, 'add' => ['\\Flagged']]);
wait_until(static fn (): bool => one("SELECT flagged FROM mail_threads WHERE id = $flagT") === true, 10);
$r = $poll($since2);
ok($r['code'] === 200 && preg_match('/<div class="list-group-item mail-row[^"]*" id="t-' . $flagT . '"[^>]*hx-swap-oob="outerHTML".*?mail-starred/s', $r['body']) === 1,
   'another client\'s flag: the row swapped in place, starred');
preg_match('/^X-ML-Seq: (\d+)/mi', $r['headers'], $sq); $since3 = (int) $sq[1];
$goneT = null;
foreach (array_slice($pageIds, 2) as $cand) { if ((int) one("SELECT message_count FROM mail_threads WHERE id = $cand") === 1) { $goneT = $cand; break; } }
$goneUid = (int) one("SELECT uid FROM mail_messages WHERE thread_id = $goneT LIMIT 1");
ctl(['op' => 'expunge', 'user' => 'priya@business.example', 'uid' => $goneUid]);
wait_until(static fn (): bool => (int) one("SELECT count(*) FROM mail_threads WHERE id = $goneT") === 0, 10);
$r = $poll($since3);
ok($r['code'] === 200 && str_contains($r['body'], '<div id="t-' . $goneT . '" hx-swap-oob="delete"></div>'), 'an expunged conversation\'s row is removed (its tombstone)');

echo "7. The access log\n";
$a0 = (int) one("SELECT count(*) FROM activity_log WHERE action = 'mailbox.view' AND mailbox_id = $sales AND actor_member_id = 30");
page($jd, "/m/$sales/v/inbox"); page($jd, "/m/$sales/v/starred");
$a1 = q("SELECT after FROM activity_log WHERE action = 'mailbox.view' AND mailbox_id = $sales AND actor_member_id = 30 ORDER BY id");
ok(count($a1) === max(1, $a0) && str_contains((string) end($a1)['after'], '"door": "web"'), 'a view holder\'s list views within half an hour write ONE mailbox.view, with the door');
page($jp, "/m/$priya/v/inbox"); page($jp, "/m/$priya/v/sent");
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'mailbox.view' AND mailbox_id = $priya") === 0, 'the owner\'s own list views write none');
ok((int) one("SELECT count(*) FROM activity_log WHERE action LIKE '%poll%' OR (route LIKE '%/changes%')") === 0, 'the poll is never logged');

echo "9. Timings\n";
// The page's gatherer and the rail's, on one connection after its first query (each PHP request pays a fresh backend's warm-up, reported
// beside them): what the screens read, timed as the screens read it.
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
$_SESSION = ['member_id' => 26];
$time = static function (callable $f): float { $f(); $best = []; for ($i = 0; $i < 5; $i++) { $t = microtime(true); $f(); $best[] = (microtime(true) - $t) * 1000; } sort($best); return $best[2]; };
$conn = static function (int $m): PDO { $p = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $p->exec("SELECT set_config('app.member_id', '$m', false)"); return $p; };
$c26 = $conn(26);
$tl = $time(static fn () => mail_list_page($c26, $big, 'inbox', null, null, false, 50));
ok($tl < 100, sprintf('a list page of a %s-conversation mailbox: %.1f ms (rows, cursors, the total, the counter)', number_format($total + 10), $tl));
$tl2 = $time(static fn () => mail_list_page($c26, $big, 'inbox', null, $p1['older'], false, 50, 50));
ok($tl2 < 100, sprintf('…an Older page: %.1f ms', $tl2));
$_SESSION = ['member_id' => 28];
$c28 = $conn(28);
$tr = $time(static fn () => mail_rail($c28, $nosync));
ok($tr < 100, sprintf('the rail with %d mailboxes: %.1f ms', count(q("SELECT 1 FROM mailbox_permissions WHERE principal_id = 28")), $tr));
$t = microtime(true); page($jp, "/m/$big/v/inbox"); $whole = (microtime(true) - $t) * 1000;
$t = microtime(true); req('GET', '/api/v1/health'); $floor = (microtime(true) - $t) * 1000;
echo sprintf("       (the whole page over HTTP %.0f ms; this host's floor for any request — /api/v1/health — %.0f ms)\n", $whole, $floor);
finish();
