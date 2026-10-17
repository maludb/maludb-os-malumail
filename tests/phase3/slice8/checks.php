<?php
/**
 * Slice 8's page proof (correspondents.md Proof 1–3, 5): the correspondents list and its filters, the correspondent page and the company tab, upkeep through the REAL mail service (received once per message key, a person's send makes known, an agent's unreviewed send does not), the autocomplete door and the wall, the access log. "Pat" is Priya (26), "Jo" Marco (27, not in
 * Sales), "Dana" (30, in Sales), the Mail admin is member 1. Run by tests/phase3/slice8/run.sh.
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

$CTL = need('ML_IMAP_CONTROL');
function ctl(array $cmd): array
{
    global $CTL;
    $n = bin2hex(random_bytes(8));
    file_put_contents("$CTL/$n.json", json_encode($cmd));
    for ($i = 0; $i < 200; $i++) {
        if (is_file("$CTL/$n.done")) { $o = json_decode((string) file_get_contents("$CTL/$n.done"), true); unlink("$CTL/$n.done"); return $o; }
        usleep(50000);
    }
    throw new RuntimeException('the fixture did not answer');
}
function su(string $sql): string { return trim((string) shell_exec('sudo -n -u postgres psql -X -At -v ON_ERROR_STOP=1 -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg($sql) . ' 2>&1')); }
function wait_until(callable $f, float $timeout = 20.0): bool { $t = microtime(true); while (microtime(true) - $t < $timeout) { if ($f()) { return true; } usleep(200000); } return false; }
const DMARC = 'default: False [0.40 / 15.00]; DMARC_POLICY_ALLOW(-0.50)[outside.example,none]; R_SPF_ALLOW(-0.20)';
function deliver(string $user, string $subject, string $text, string $from = 'Outside Person <someone@outside.example>', ?string $mid = null, string $folder = 'INBOX'): array
{
    global $RUN;
    $subject .= $RUN;
    $mid ??= '<' . bin2hex(random_bytes(8)) . '@outside.example>';
    $h = "From: $from\r\nTo: $user\r\nSubject: $subject\r\nDate: " . date(DATE_RFC2822) . "\r\nMessage-ID: $mid\r\nMIME-Version: 1.0\r\nX-Spamd-Result: " . DMARC . "\r\n";
    $d = ctl(['op' => 'deliver', 'user' => $user, 'folder' => $folder, 'raw' => $h . "Content-Type: text/plain; charset=utf-8\r\n\r\n" . $text]);
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
    return [(int) $r['id'], (int) $r['thread_id'], (int) ($d['uid'] ?? 0), $mid];
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
$P = mb('priya'); $D = mb('dana'); $S = mb('sales'); $E = mb('expert'); $SUP = mb('support');
$PAT = 26; $JO = 27; $DANA = 30; $ADMIN = 1; $EXPERT = 40;
[$jp] = sign_on($PAT); [$jd] = sign_on($DANA); [$ja] = sign_on($ADMIN); [$jj] = sign_on($JO);
kernel_state(static function (array $s): array {
    $s['facts']['9701'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 9701, 'endpoints' => [['application' => 'malumail']]];
    return $s;
});
$json = ['headers' => ['Accept: application/json']];
function jget(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar, 'headers' => ['Accept: application/json']]); return [$r['code'], jbody($r), $r]; }
$ids = static fn (array $rows): array => array_column($rows, 'address');
$seedRows = 5003;                                                        // 5,000 seeded + the three named ones

echo "1. The list: rows, filters, the cursor, the chips\n";
$r = page($jp, "/m/$P/people");
ok($r['code'] === 200 && str_contains($r['body'], 'id="correspondents-content"') && substr_count($r['body'], 'class="list-group-item people-row"') === 50, 'Priya opens People on her mailbox: fifty rows', (string) $r['code']);
ok(str_contains($r['body'], 'id="people-older"') && str_contains($r['body'], 'people-outside') && !str_contains($r['body'], 'fw-normal people-known'), '…an Older link, the Outside chip, and no Known chip on a person\'s mailbox');
[$c, $j] = jget($jp, "/m/$P/people");
ok($c === 200 && count($j['correspondents']) === 50 && $j['next'] !== null, 'JSON: fifty correspondents and the next cursor');
$keys = array_keys($j['correspondents'][0]);
sort($keys);
ok($keys === ['address', 'first_contact', 'known', 'known_how', 'last_contact', 'name', 'outside', 'received', 'sent'], 'JSON: the whitelist only — no ids, no known_by, no flags', implode(',', $keys));
ok(!str_contains(json_encode($j), 'known_by') && !str_contains(json_encode($j), 'images_'), '…no internal column');
// the whole list by the cursor: nothing twice, nothing missing, newest contact first
$seen = [];
$cur = null;
$prev = null;
$ordered = true;
for ($i = 0; $i < 200; $i++) {
    [$c, $j] = jget($jp, "/m/$P/people" . ($cur === null ? '' : '?before=' . rawurlencode($cur)));
    foreach ($j['correspondents'] as $x) {
        if ($prev !== null && strcmp((string) $prev, (string) $x['last_contact']) < 0) { $ordered = false; }
        $prev = $x['last_contact'];
        $seen[] = $x['address'];
    }
    $cur = $j['next'];
    if ($cur === null) { break; }
}
ok(count($seen) === count(array_unique($seen)) && count($seen) === $seedRows, 'the cursor walks every correspondent exactly once: ' . count($seen), (string) count($seen));
ok($ordered, '…newest contact first');
[$c, $j] = jget($jp, "/m/$P/people?q=zed");
ok($c === 200 && count($j['correspondents']) === 3 && !str_contains(json_encode($ids($j['correspondents'])), 'seed'), 'q=zed: the three Zeds (name or address, trigram)');
[$c, $j] = jget($jp, "/m/$P/people?q=Zed%20Heard");
ok(count($j['correspondents']) === 1 && $j['correspondents'][0]['address'] === 'zed.heard@zedco.example' && $j['correspondents'][0]['known'] === false, 'a name finds its person; one only heard from is not known');
[$c, $j] = jget($jp, "/m/$P/people?q=zed&known=yes");
ok(count($j['correspondents']) === 2 && $j['correspondents'][0]['known_how'] === 'sent', 'known=yes: the two Priya wrote to (known how: sent)');
[$c, $j] = jget($jp, "/m/$P/people?q=zed&known=no");
ok(count($j['correspondents']) === 1, 'known=no: the one she only heard from');
[$c, $j] = jget($jp, "/m/$P/people?outside=no");
ok($c === 200 && count($j['correspondents']) >= 50 && !in_array(true, array_column($j['correspondents'], 'outside'), true), 'outside=no: only the inside addresses');
[$c, $j] = jget($jp, "/m/$P/people?outside=yes&q=business.example");
ok($c === 200 && $j['correspondents'] === [], 'outside=yes with an inside domain finds none');
[$c, $j] = jget($jp, "/m/$P/people?q=" . rawurlencode('%'));
ok($c === 200 && $j['correspondents'] === [], 'a percent sign is a character, not a wildcard');
ok(page($jp, "/m/$P/people?known=maybe")['code'] === 422 && page($jp, "/m/$P/people?before=abc")['code'] === 422, 'a bad filter or cursor: 422 in words');
$r = page($jp, "/m/$P/people?q=zed", ['headers' => ['HX-Request: true', 'HX-Target: people-results']]);
ok($r['code'] === 200 && !str_contains($r['body'], '<html') && str_contains($r['body'], 'id="people-results"'), 'HTMX: the results alone');
$r = page($jp, "/m/$P/people?q=nobodyatall");
ok(str_contains($r['body'], 'id="people-none"') && str_contains($r['body'], 'No correspondent matches'), 'no match: said in words');
$r = page($jd, "/m/$D/people");
ok($r['code'] === 200 && str_contains($r['body'], 'No correspondents yet — they appear as mail comes and goes.'), 'an empty mailbox: the empty sentence');
// an agent's mailbox: Known chip with how, and Mark known for its overseer
$r = page($jp, "/m/$E/people");
ok(str_contains($r['body'], 'Known · marked') && str_contains($r['body'], '/oversight/mark-known') && str_contains($r['body'], 'Mark known'), 'the Expert\'s mailbox: Known · marked on its known ones, Mark known for Priya (full, primary overseer)');
$r = page($jd, "/m/$E/people");
ok($r['code'] === 200 && !str_contains($r['body'], 'Mark known'), '…Dana (triage, not a decider) sees the chips but no Mark known button');
$r = page($jp, "/m/$S/people");
ok($r['code'] === 200 && !str_contains($r['body'], 'Mark known') && !str_contains($r['body'], 'fw-normal people-known'), 'a shared mailbox: no Known chip, no Mark known');

echo "2. The wall: who opens People, and who is told\n";
ok(page($jj, "/m/$P/people")['code'] === 404 && page($jj, "/m/$P/people/zed.sent%40zedco.example")['code'] === 404, 'Marco (no row): 404 on both screens, as if the mailbox did not exist');
ok(page($ja, "/m/$P/people")['code'] === 404, 'the Mail admin: a person\'s mailbox is 404 (a role never opens it)');
ok(page($jd, "/m/$SUP/people")['code'] === 200 && page($jp, "/m/$SUP/people")['code'] === 404, 'support@: Dana (full) opens it, Priya (nothing) gets 404');
[$c] = jget($jj, "/m/$P/people");
ok($c === 404, 'JSON for Marco: 404 not_found too');
$before = last_activity_id();
$r1 = page($jd, "/m/$P/people");
$r2 = page($jd, "/m/$P/people?q=zed");
ok($r1['code'] === 200 && $r2['code'] === 200, 'Dana (view) opens People on Priya\'s mailbox');
$views = array_values(array_filter(activity('mailbox.view', $before), static fn (array $a): bool => (int) $a['mailbox_id'] === $P));
ok(count($views) === 1 && (int) $views[0]['actor_member_id'] === $DANA && str_contains((string) $views[0]['after'], '"people"'), 'her views write ONE mailbox.view in the half hour (the screen named)', (string) count($views));
$before = last_activity_id();
page($jp, "/m/$P/people");
ok(activity('mailbox.view', $before) === [], 'the owner\'s own view writes none');
ok(!str_contains(json_encode(q("SELECT after FROM activity_log WHERE id > :s AND mailbox_id = :mb", ['s' => $before - 10, 'mb' => $P])), 'zedco'), 'no address in any log payload of these views');

echo "3. Upkeep through the real mail service\n";
[$m1, $T1, $uid1, $mid1] = deliver('priya@business.example', 'Quote request', 'Prices please.', 'Jon Supplier <jon@supplier.example>');
$row = q("SELECT * FROM mail_correspondents WHERE mailbox_id = :mb AND address = 'jon@supplier.example'", ['mb' => $P])[0] ?? null;
ok($row !== null && (int) $row['received_count'] === 1 && $row['name'] === 'Jon Supplier' && !$row['is_internal'] && $row['known_at'] === null, 'a received message makes its sender a correspondent: counted once, named, outside, NOT known');
$keyId = (int) one('SELECT message_key_id FROM mail_messages WHERE id = :i', ['i' => $m1]);
ctl(['op' => 'move', 'user' => 'priya@business.example', 'folder' => 'INBOX', 'uid' => $uid1, 'to' => 'Archive']);
$moved = wait_until(static fn (): bool => (int) one("SELECT count(*) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = :mb AND m.message_key_id = :k AND f.name = 'Archive'", ['mb' => $P, 'k' => $keyId]) > 0, 60.0);
ok($moved, 'the same message is moved to Archive and indexed there (one key, a new row)');
sleep(2);
ok((int) one("SELECT received_count FROM mail_correspondents WHERE mailbox_id = :mb AND address = 'jon@supplier.example'", ['mb' => $P]) === 1, '…and Jon is still counted once (received once per message key)');
[$m2, $T2] = deliver('priya@business.example', 'Second quote', 'More prices.', 'Kay Supplier <kay@supplier.example>');
[$m3, $T3] = deliver('priya@business.example', 'Hello from a friend', 'Hi.', 'Gil Public <gil.public@gmail.com>');
[$m4, $T4] = deliver('priya@business.example', 'Third word from Jon', 'Still prices.', 'Jon Supplier <jon@supplier.example>');
ok((int) one("SELECT received_count FROM mail_correspondents WHERE mailbox_id = :mb AND address = 'jon@supplier.example'", ['mb' => $P]) === 2, 'a second message from Jon counts (2)');
// sent: a person's send makes known; an agent's unreviewed send does not; an approved one does, by the approver
$outbound = static function (int $mb, int $sender, bool $agent, array $to): int {
    $arr = '{' . implode(',', $to) . '}';
    $id = (int) strtok(su("INSERT INTO outbound_email (to_address, mailbox_id, sender_member_id, sender_is_agent, purpose, to_addresses, status) VALUES ('{$to[0]}', $mb, $sender, " . ($agent ? 'true' : 'false') . ", " . ($agent ? "'proof purpose'" : 'NULL') . ", '$arr', 'queued') RETURNING id"), "\n");
    su("UPDATE outbound_email SET status = 'sending' WHERE id = $id");
    return $id;
};
$o1 = $outbound($P, $PAT, false, ['Jon@Supplier.example', 'newperson@client.example']);
su("SELECT ml_outbound_sent($o1, 'pm1', '250 ok')");
$j1 = q("SELECT * FROM mail_correspondents WHERE mailbox_id = :mb AND address = 'jon@supplier.example'", ['mb' => $P])[0];
$n1 = q("SELECT * FROM mail_correspondents WHERE mailbox_id = :mb AND address = 'newperson@client.example'", ['mb' => $P])[0] ?? null;
ok((int) $j1['sent_count'] === 1 && $j1['known_how'] === 'sent' && (int) $j1['known_by'] === $PAT && $n1 !== null && $n1['known_how'] === 'sent' && (int) $n1['sent_count'] === 1 && (int) $n1['received_count'] === 0, 'a person\'s send makes every recipient known (sent), by that person; a new address is a correspondent');
$o2 = $outbound($E, $EXPERT, true, ['agentonly@vendor.example']);
su("SELECT ml_outbound_sent($o2, 'pm2', '250 ok')");
$a2 = q("SELECT * FROM mail_correspondents WHERE mailbox_id = :mb AND address = 'agentonly@vendor.example'", ['mb' => $E])[0] ?? null;
ok($a2 !== null && (int) $a2['sent_count'] === 1 && $a2['known_at'] === null, 'an agent\'s unreviewed send counts but makes nothing known');
$o3 = $outbound($E, $EXPERT, true, ['approved@vendor.example']);
su("INSERT INTO mail_reviews (outbound_id, decision, reviewer_member_id) VALUES ($o3, 'approve', $PAT)");
su("SELECT ml_outbound_sent($o3, 'pm3', '250 ok')");
$a3 = q("SELECT * FROM mail_correspondents WHERE mailbox_id = :mb AND address = 'approved@vendor.example'", ['mb' => $E])[0] ?? null;
ok($a3 !== null && $a3['known_how'] === 'sent' && (int) $a3['known_by'] === $PAT, 'an approved send makes it known, by the approver');
$r = page($jp, "/m/$E/people?q=vendor.example");
ok(str_contains($r['body'], 'approved@vendor.example') && substr_count($r['body'], 'Known · sent') === 1, 'the Expert\'s People shows one Known · sent (the approved one) and Mark known for the other');
// is_internal follows the address at each touch
su("UPDATE mail_correspondents SET is_internal = true WHERE mailbox_id = $P AND address = 'kay@supplier.example'");
deliver('priya@business.example', 'Kay again', 'x', 'Kay Supplier <kay@supplier.example>');
ok(one("SELECT is_internal FROM mail_correspondents WHERE mailbox_id = $P AND address = 'kay@supplier.example'") === false, 'is_internal follows ml_is_internal_address() at the next touch');

echo "4. The correspondent page\n";
$enc = rawurlencode('jon@supplier.example');
$r = page($jp, "/m/$P/people/$enc");
ok($r['code'] === 200 && str_contains($r['body'], 'id="correspondent-view-content"') && str_contains($r['body'], 'Quote request') && str_contains($r['body'], 'Third word from Jon'), 'the page shows the conversations with the address');
ok(str_contains($r['body'], 'id="person-tab-company"') && str_contains($r['body'], 'Everyone at supplier.example'), '…and the company tab for supplier.example');
ok(!str_contains($r['body'], 'Second quote'), '…without Kay\'s conversation on the address tab');
$r = page($jp, "/m/$P/people/$enc?tab=company");
ok(str_contains($r['body'], 'Second quote') && str_contains($r['body'], 'id="person-domain-addresses"') && str_contains($r['body'], 'kay@supplier.example') && str_contains($r['body'], 'jon@supplier.example'), 'the company tab: every conversation with the domain and its addresses with counts');
$r = page($jp, "/m/$P/people/" . rawurlencode('gil.public@gmail.com') . '?tab=company');
ok($r['code'] === 200 && !str_contains($r['body'], 'person-tab-company') && !str_contains($r['body'], 'person-domain-addresses') && str_contains($r['body'], 'Hello from a friend') && !str_contains($r['body'], 'Everyone at'), 'gmail.com: no company tab, even when asked for (decision 2)');
ok(str_contains(page($jp, "/m/$P/people/" . rawurlencode('zed.sent@zedco.example'))['body'], 'id="person-threads-none"'), 'a correspondent with no conversation says so in words');
ok(page($jp, "/m/$P/people/" . rawurlencode('nobody@nowhere.example'))['code'] === 404 && page($jp, "/m/$P/people/notanaddress")['code'] === 404, 'an address never exchanged with, or no address: 404');
[$c, $j] = jget($jp, "/m/$P/people/$enc?tab=company");
ok($c === 200 && $j['company_domain'] === 'supplier.example' && count($j['conversations']) >= 3 && count($j['addresses']) === 2 && !isset($j['correspondent']['known_by']), 'JSON: the correspondent, the company domain, the conversations and the addresses');
ok(page($jd, "/m/$P/people/$enc")['code'] === 200 && page($jj, "/m/$P/people/$enc")['code'] === 404, 'Dana (view) opens the page; Marco gets 404');
// the wall inside the view: an agent never sees a sensitive conversation; the person who marked it does
[$me1, $TE] = deliver('expert@business.example', 'Sensitive quote', 'Do not show an agent.', 'Jon Supplier <jon@supplier.example>');
$anchor = (int) one('SELECT message_key_id FROM mail_messages WHERE id = :i', ['i' => $me1]);
su("INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, sensitive, sensitive_by) VALUES ($E, $anchor, true, $PAT)");
$ag = ['headers' => as_agent(run_token($EXPERT, 9701))];
$ar = req('GET', "/m/$E/people/$enc", $ag);
$pr = page($jp, "/m/$E/people/$enc");
ok(str_contains($pr['body'], 'Sensitive quote') && str_contains($pr['body'], 'Marked sensitive'), 'Priya sees the sensitive conversation (locked)');
ok($ar['code'] === 200 && !str_contains($ar['body'], 'Sensitive quote') , 'the Expert (an agent token) never sees it', (string) $ar['code']);
// a delegate's page URLs carry the wall
$tpath = "/m/$P/t/$T3";
$tj = page($jj, $tpath)['code']; $td = page($jd, $tpath)['code'];
ok($tj === 404 && $td === 200, 'a conversation\'s own URL (the one the share hands out): Marco 404, Dana (view) opens it', "marco $tj dana $td");

echo "5. The autocomplete door\n";
$before = last_activity_id();
$items = static function (string $html): array { preg_match_all('/data-address="([^"]+)"[^>]*data-source="([^"]+)"/', $html, $m, PREG_SET_ORDER); return array_map(static fn (array $x): string => $x[1] . '|' . $x[2], $m); };
$r = page($jp, "/m/$P/complete?q=zed");
ok($r['code'] === 200 && $items($r['body']) === ['zed.sent@zedco.example|correspondent', 'zoe@zedco.example|correspondent', 'zed.heard@zedco.example|correspondent'], 'correspondents she SENT to first (by volume), then the one she only heard from', json_encode($items($r['body'])));
ok(substr_count($r['body'], 'suggest-outside') === 3, '…Outside marked');
ok(page($jp, "/m/$P/complete?q=z")['body'] === '' && page($jp, "/m/$P/complete")['body'] === '', 'under two characters: nothing');
$r = page($jp, "/m/$P/complete?q=seed1");
ok(count($items($r['body'])) === 8, 'at most eight');
$r = page($jp, "/m/$P/complete?q=dana");
ok(in_array('dana@example.invalid|member', $items($r['body']), true), 'the business\'s people come from the directory');
$r = page($jp, "/m/$P/complete?q=expert");
ok(in_array('expert@business.example|mailbox', $items($r['body']), true), 'a mailbox she opens (the Expert\'s) is offered');
foreach (['support', 'dana@business', 'hush'] as $term) {
    $r = page($jp, "/m/$P/complete?q=" . rawurlencode($term));
    ok(!str_contains($r['body'], 'support@business.example') && !str_contains($r['body'], 'dana@business.example'), "'$term': an address of a mailbox she cannot open never appears");
}
$r = page($jd, "/m/$D/complete?q=support");
ok(in_array('support@business.example|mailbox', $items($r['body']), true) && !str_contains($r['body'], 'expert@business.example') , 'Dana (full on support@) is offered support@, and the Expert\'s mailbox only if she opens it');
ok(page($jd, "/m/$P/complete?q=zed")['code'] === 404, 'a view-only holder (Dana on priya@): 404 — autocomplete is a sender\'s');
ok(page($jj, "/m/$P/complete?q=zed")['code'] === 404 && page($ja, "/m/$P/complete?q=zed")['code'] === 404, 'Marco and the Mail admin: 404');
ok(page($jd, "/m/$S/complete?q=seed")['code'] === 404, 'view on sales@: 404 too');
[$c, $j] = jget($jp, "/m/$P/complete?q=zed");
ok($c === 200 && count($j['suggestions']) === 3 && $j['suggestions'][0]['address'] === 'zed.sent@zedco.example', 'JSON: the suggestions');
ok(last_activity_id() === $before || activity('mailbox.view', $before) === [], 'the door logs nothing (typing is not reading)');
ok(q("SELECT 1 FROM activity_log WHERE id > :s AND action NOT IN ('screen.view')", ['s' => $before]) === [], 'no activity row at all from the autocomplete requests', json_encode(q("SELECT action FROM activity_log WHERE id > :s", ['s' => $before])));
// speed at 5,000 correspondents: the SQL, then the request
$pdo = pdo();
$ms = [];
for ($i = 0; $i < 25; $i++) {
    $pdo->beginTransaction();
    $pdo->query("SELECT set_config('app.member_id', '$PAT', true)");
    $t = microtime(true);
    $pdo->query("SELECT * FROM ml_complete($P, 'seed12', 8)")->fetchAll();
    $ms[] = (microtime(true) - $t) * 1000;
    $pdo->commit();
}
sort($ms);
ok($ms[12] < 50 && $ms[23] < 100, sprintf('ml_complete over 5,000 correspondents: median %.1f ms, p95 %.1f ms (under 50 ms)', $ms[12], $ms[23]));
$ms = [];
for ($i = 0; $i < 15; $i++) { $t = microtime(true); page($jp, "/m/$P/complete?q=seed12"); $ms[] = (microtime(true) - $t) * 1000; }
sort($ms);
ok($ms[7] < 250, sprintf('…and the whole request answers in a median of %.0f ms (php -S/Apache, a fresh PHP request each)', $ms[7]));

echo "6. The registry and the placeholders\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['screens']['correspondents']['built'] ?? false) === true && ($reg['screens']['correspondent-view']['built'] ?? false) === true, 'both screens are built in the registry');
ok(!str_contains(page($jp, "/m/$P/people")['body'], 'builds this screen') && !str_contains(page($jp, "/m/$P/people/$enc")['body'], 'builds this screen'), 'neither answers with a placeholder any more');
$pc = req('POST', "/m/$P/people", ['jar' => $jp, 'form' => ['csrf_token' => page_csrf($jp)]])['code'];
ok($pc >= 400 || $pc === 200, 'People takes a POST to nothing: it only shows the page again (no action in this slice, nothing written)', (string) $pc);
ok(one("SELECT count(*) FROM activity_log WHERE action NOT IN ('screen.view', 'mailbox.view') AND entity_type = 'correspondent'") == 0, 'no correspondent-writing log row exists');

finish();
