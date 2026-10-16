<?php
/**
 * Slice 6's page and action proof (search-labels-snooze.md Proof 1–7): every operator of Gmail's search against an independent SQL truth
 * (with and without `-`) over 700 synthetic messages; the cursor over every result of a 2,000-message estate without a repeat or a gap and
 * in the order the messages came; the wall (a delegate's search, search-all naming each mailbox, an agent never finding a sensitive
 * conversation, `is:unread` for a view holder following their own reads); labels (create, label from the list and by `matching`, the view,
 * a reply keeping the label, rename, recolour, delete); snooze in a person's time zone, the worker's wake (unread, the chip), unsnooze,
 * an intake mailbox's refusal, an agent's wake as a dispatch and a turn; folders (create, rename, delete when empty, refused with mail in
 * it, system folders, an agent's delete paused); canned replies; the log vocabulary. Run by tests/phase3/slice6/run.sh.
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
function as_member(int $m, string $sql, array $args = []) { pdo()->exec("SELECT set_config('app.member_id', '$m', false)"); try { return one($sql, $args); } finally { pdo()->exec("SELECT set_config('app.member_id', '', false)"); } }
function rows_as(int $m, string $sql, array $args = []): array { pdo()->exec("SELECT set_config('app.member_id', '$m', false)"); try { return q($sql, $args); } finally { pdo()->exec("SELECT set_config('app.member_id', '', false)"); } }
function jbody(array $r): array { return json_decode($r['body'], true) ?: []; }
/** A person's POST (CSRF from the page), as JSON by default. */
function post(string $jar, string $path, array $form, string $mode = 'json'): array
{
    $form += ['csrf_token' => page_csrf($jar)];
    $h = $mode === 'json' ? ['Accept: application/json'] : ($mode === 'htmx' ? ['HX-Request: true'] : []);
    return req('POST', $path, ['jar' => $jar, 'form' => $form, 'headers' => $h]);
}
function tact(int $member, string $path, array $form, int $run = 9601): array
{
    return req('POST', $path, ['headers' => as_agent(run_token($member, $run)), 'form' => $form]);
}
function jget(string $jar, string $path): array { $r = page($jar, $path, ['headers' => ['Accept: application/json']]); return [$r['code'], jbody($r)['data'] ?? jbody($r)]; }
function worker(string $passes): array
{
    $out = (string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=' . escapeshellarg($passes) . ' 2>&1');
    return json_decode(trim((string) strrchr("\n" . trim($out), "\n")), true) ?: ['raw' => $out];
}
function chat_lines(): array
{
    $f = need('FAKE_KERNEL_STATE') . '.chat';
    return is_file($f) ? array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES)))) : [];
}
function fixture_state(string $user): array { return ctl(['op' => 'state', 'user' => $user . '@business.example'])['folders'] ?? []; }

$P = mb('priya'); $D = mb('dana'); $S = mb('sales'); $E = mb('expert'); $I = mb('intake');
$B1 = mb('big1'); $B2 = mb('big2'); $B3 = mb('big3'); $H = mb('hush'); $AB = mb('agentbox');
[$jp] = sign_on(26); [$jd] = sign_on(30); [$jo] = sign_on(1);
kernel_state(static function (array $s): array {
    $s['facts']['9601'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 9601, 'endpoints' => [['application' => 'malumail']]];
    $s['chat'] = ['run_id' => 7101, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Back on it.', 'actions' => []];
    return $s;
});
$since = last_activity_id();
echo "0. The index is seeded and the live mailboxes are in\n";
ok((int) one('SELECT count(*) FROM mail_messages WHERE mailbox_id IN (:a, :b, :c)', ['a' => $B1, 'b' => $B2, 'c' => $B3]) === 2000, 'the three big mailboxes hold 2,000 messages');
ok(wait_until(static fn (): bool => (int) one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :m', ['m' => $P]) >= 6 && (int) one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :m', ['m' => $E]) >= 3
   && (int) one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :m', ['m' => $I]) >= 3 && (int) one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :m', ['m' => $S]) >= 3, 60), 'the live mailboxes were synced from the fixture');

// ================================================================================================================ 1. the operators
echo "1. Every operator of ml_search against an independent SQL truth (big1, as Priya)\n";
su("INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, snoozed_until, snoozed_from_folder, snoozed_by) SELECT mailbox_id, message_key_id, now() + interval '3 days', 'INBOX', 26 FROM mail_messages WHERE mailbox_id = $B1 AND uid = 2");
su("INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, muted, muted_by) SELECT mailbox_id, message_key_id, true, 26 FROM mail_messages WHERE mailbox_id = $B1 AND uid = 3");
su("INSERT INTO tags (slug, label, colour, created_by, mailbox_id) VALUES ('seeded', 'Seeded', '#0d6efd', 26, $B1)");
su("INSERT INTO record_tags (record_type, record_id, tag_id) SELECT 'mail_message', message_key_id, (SELECT id FROM tags WHERE slug = 'seeded' AND mailbox_id = $B1) FROM mail_messages WHERE mailbox_id = $B1 AND uid IN (6, 7, 8)");
function walk(int $member, string $qq, ?int $mailbox = null): array
{
    $all = [];
    for ($off = 0; $off < 6000; $off += 500) {
        $rows = rows_as($member, 'SELECT message_id, thread_id, mailbox_id FROM ml_search(CAST(:q AS text), CAST(:mb AS bigint), 500, CAST(:off AS integer))', ['q' => $qq, 'mb' => $mailbox, 'off' => $off]);
        $all = array_merge($all, $rows);
        if (count($rows) < 500) { break; }
    }
    return $all;
}
function truth(string $where, array $boxes, bool $default = true): array
{
    $sql = 'SELECT m.id FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id IN (' . implode(',', $boxes) . ") AND ($where)"
         . ($default ? " AND COALESCE(f.special_use, '') NOT IN ('trash', 'junk')" : '') . ' ORDER BY m.id';
    return array_map('intval', array_column(q($sql), 'id'));
}
function op_check(string $label, string $qq, string $where, bool $default = true, ?int $box = null, bool $negate = true): void
{
    global $B1;
    $box ??= $B1;
    $got = array_map('intval', array_column(walk(26, $qq, $box), 'message_id'));
    sort($got);
    $want = truth($where, [$box], $default);
    ok($got === $want && $want !== [], "$label — " . count($want) . ' messages', 'got ' . count($got) . ' want ' . count($want));
    if (!$negate) {
        return;
    }
    $neg = '-' . $qq;
    $gotN = array_map('intval', array_column(walk(26, $neg, $box), 'message_id'));
    sort($gotN);
    $wantN = truth("NOT COALESCE(($where), false)", [$box], $default);
    ok($gotN === $wantN, "  and $neg — " . count($wantN) . ' messages', 'got ' . count($gotN) . ' want ' . count($wantN));
}
op_check('from:', 'from:u3@outside', "m.from_address::text ILIKE '%u3@outside%' OR m.from_name ILIKE '%u3@outside%'");
op_check('from: a name', 'from:"Person 4"', "m.from_address::text ILIKE '%Person 4%' OR m.from_name ILIKE '%Person 4%'");
op_check('to:', 'to:big1@', "EXISTS (SELECT 1 FROM unnest(m.to_addresses) a WHERE a ILIKE '%big1@%')");
op_check('cc:', 'cc:copy@outside', "EXISTS (SELECT 1 FROM unnest(m.cc_addresses) a WHERE a ILIKE '%copy@outside%')");
op_check('bcc:', 'bcc:hidden@', "EXISTS (SELECT 1 FROM unnest(m.bcc_addresses) a WHERE a ILIKE '%hidden@%')");
op_check('subject:', 'subject:invoice', "m.subject ILIKE '%invoice%'");
op_check('filename:', 'filename:report1', "EXISTS (SELECT 1 FROM unnest(m.attachment_names) a WHERE a ILIKE '%report1%')");
op_check('has:attachment', 'has:attachment', 'm.has_attachments');
op_check('has:label', 'has:label', "EXISTS (SELECT 1 FROM record_tags rt WHERE rt.record_type = 'mail_message' AND rt.record_id = m.message_key_id)");
op_check('has:nolabel', 'has:nolabel', "NOT EXISTS (SELECT 1 FROM record_tags rt WHERE rt.record_type = 'mail_message' AND rt.record_id = m.message_key_id)");
op_check('label:', 'label:seeded', "EXISTS (SELECT 1 FROM record_tags rt JOIN tags g ON g.id = rt.tag_id WHERE rt.record_type = 'mail_message' AND rt.record_id = m.message_key_id AND g.slug = 'seeded')");
op_check('is:unread', 'is:unread', 'NOT m.seen');
op_check('is:read', 'is:read', 'm.seen');
op_check('is:starred', 'is:starred', 'm.flagged');
op_check('is:answered', 'is:answered', 'm.answered');
op_check('is:snoozed', 'is:snoozed', 'EXISTS (SELECT 1 FROM mail_thread_states s JOIN mail_messages x ON x.message_key_id = s.anchor_key_id WHERE x.thread_id = m.thread_id AND s.snoozed_until IS NOT NULL)');
op_check('is:muted', 'is:muted', 'EXISTS (SELECT 1 FROM mail_thread_states s JOIN mail_messages x ON x.message_key_id = s.anchor_key_id WHERE x.thread_id = m.thread_id AND s.muted)');
op_check('in:inbox', 'in:inbox', "f.special_use = 'inbox'", false);
op_check('in:sent', 'in:sent', "f.special_use = 'sent'", false);
op_check('in:trash', 'in:trash', "f.special_use = 'trash'", false);
op_check('in:spam', 'in:spam', "f.special_use = 'junk'", false);
ok(walk(26, 'in:archive', $B1) === [] && walk(26, 'in:snoozed', $B1) === [], 'in:archive and in:snoozed — this mailbox has neither folder: nothing');
op_check('in:anywhere', 'in:anywhere', 'true', false);
op_check('after:', 'after:' . gmdate('Y-m-d', time() - 2 * 86400), "COALESCE(m.sent_at, m.received_at) >= '" . gmdate('Y-m-d', time() - 2 * 86400) . "'::date");
op_check('before:', 'before:' . gmdate('Y-m-d', time() - 2 * 86400), "COALESCE(m.sent_at, m.received_at) < '" . gmdate('Y-m-d', time() - 2 * 86400) . "'::date");
op_check('older_than:', 'older_than:1d', "m.received_at < now() - interval '1 day'");
op_check('newer_than:', 'newer_than:1d', "m.received_at >= now() - interval '1 day'");
op_check('larger:', 'larger:2M', 'm.size > 2097152');
op_check('smaller:', 'smaller:500K', 'm.size < 512000');
op_check('a phrase', '"quarterly review"', "m.body_text ILIKE '%quarterly review%'");
op_check('words', 'lunch', "m.subject ILIKE '%lunch%'");
op_check('operators together', 'from:u2@outside has:attachment is:unread', "(m.from_address::text ILIKE '%u2@outside%' OR m.from_name ILIKE '%u2@outside%') AND m.has_attachments AND NOT m.seen", true, null, false);
echo "  an unknown operator is searched as words and named\n";
[$c, $d] = jget($jp, "/m/$B1/search?q=" . rawurlencode('foo:bar invoice'));
ok($c === 200 && $d['unknown_operators'] === ['foo:'], 'JSON names the operator it did not know (foo:)', json_encode($d['unknown_operators'] ?? null));
$pg = page($jp, "/m/$B1/search?q=" . rawurlencode('foo:bar is:weird'));
ok($pg['code'] === 200 && str_contains($pg['body'], 'Unknown operators searched as words:') && str_contains($pg['body'], '<code class="me-1">foo:</code>') && str_contains($pg['body'], 'is:weird'), 'the page names foo: and is:weird');
ok(str_contains($pg['body'], 'No mail matches foo:bar is:weird') && str_contains($pg['body'], '<details class="mt-3" id="search-help" open>'), 'an empty result says so and opens the operator help');
ok(str_contains($pg['body'], 'Trash and Spam left out; add <code>in:anywhere</code> to include them'), 'the line says Trash and Spam are left out');
$pg = page($jp, "/m/$B1/search?q=" . rawurlencode('before:2026-13-40'));
$r = page($jp, "/m/$B1/search?q=" . rawurlencode('before:2026-13-40'), ['headers' => ['Accept: application/json']]);
ok($pg['code'] === 422 && str_contains($pg['body'], 'id="search-error"') && $r['code'] === 422 && (jbody($r)['error']['code'] ?? '') === 'bad_query', 'before:2026-13-40 → 422 in words on the page and bad_query in JSON', $pg['code'] . ' ' . $r['body']);
$r = page($jp, "/m/$B1/search?q=" . rawurlencode('older_than:soon'), ['headers' => ['Accept: application/json']]);
ok($r['code'] === 422 && (jbody($r)['error']['code'] ?? '') === 'bad_query', 'a malformed period is bad_query too');

// ================================================================================================================ the cursor
echo "2. The cursor pages every result of the estate without a repeat or a gap\n";
$wlk = walk(26, 'in:anywhere');
$order = [];
foreach ($wlk as $row) { $order[$row['thread_id'] ?? 'm' . $row['message_id']] ??= (int) $row['mailbox_id']; }
$wantThreads = array_map('intval', array_keys($order));
$got = [];
$cursor = '';
$pages = 0;
$firstRows = 0;
do {
    [$c, $d] = jget($jp, '/search?q=' . rawurlencode('in:anywhere') . ($cursor !== '' ? '&before=' . rawurlencode($cursor) : ''));
    if ($c !== 200) { break; }
    $pages++;
    $firstRows = $firstRows ?: count($d['conversations']);
    foreach ($d['conversations'] as $row) { $got[] = (int) $row['thread_id']; }
    $cursor = (string) ($d['next'] ?? '');
} while ($cursor !== '' && $pages < 80);
ok(count($wantThreads) > 1200 && $firstRows === 50 && $pages > 20, 'search-all in:anywhere is ' . count($wantThreads) . ' conversations over ' . $pages . ' pages of 50');
ok($got === $wantThreads, 'the pages hold every conversation once, in the order their newest message came — no repeat, no gap', count($got) . ' vs ' . count($wantThreads) . ' unique ' . count(array_unique($got)));
ok(count(array_unique($got)) === count($got), '…no conversation twice');
$pg = page($jp, '/search?q=' . rawurlencode('in:anywhere'));
ok(preg_match('/id="search-count">([\d,]+) conversations found/', $pg['body'], $m) && (int) str_replace(',', '', $m[1]) === count($wantThreads) && str_contains($pg['body'], 'id="search-older"'), 'the page counts them and offers Older');
$hx = page($jp, '/search?q=' . rawurlencode('in:anywhere') . '&before=' . rawurlencode($cursor ?: '1.1'), ['headers' => ['HX-Request: true', 'HX-Target: search-results']]);
ok($hx['code'] === 200 && str_starts_with(ltrim($hx['body']), '<div id="search-results"') && !str_contains($hx['body'], '<html'), 'Older swaps #search-results alone (HTMX)');
ok(page($jp, "/m/$B1/search?q=lunch&before=zzz")['code'] === 422, 'a made-up page marker is refused in words');
ok(preg_match('/id="search-scope">Searched Big one — /', page($jp, "/m/$B1/search?q=lunch")['body']) === 1 && str_contains(page($jp, '/search?q=lunch')['body'], 'class="badge bg-soft-secondary text-secondary fw-normal search-mailbox"'), 'a mailbox search says what it searched; search-all names each result\'s mailbox');
$best = rows_as(26, 'SELECT message_id, thread_id, rank FROM ml_search(CAST(:q AS text), CAST(:mb AS bigint), 500, 0)', ['q' => 'from:outside', 'mb' => $B1]);
[$c, $d] = jget($jp, "/m/$B1/search?q=" . rawurlencode('from:outside'));
$multi = array_values(array_filter($d['conversations'], static fn ($r) => $r['matched'] > 1));
ok($c === 200 && $multi !== [] && $multi[0]['matched'] === count(array_filter($best, static fn ($r) => (int) $r['thread_id'] === (int) $multi[0]['thread_id'])), 'a conversation with several matching messages counts them (matched)');

// ================================================================================================================ the wall
echo "3. The wall\n";
ok(page($jd, "/m/$P/search?q=lunch")['code'] === 404 && page($jd, "/m/$B2/search?q=lunch")['code'] === 404, 'a delegate cannot search a mailbox she does not open (404, as the mailbox itself)');
$mbs = static function (string $jar): array { $o = []; $cur = ''; do { [$c, $d] = jget($jar, '/search?q=' . rawurlencode('in:anywhere') . ($cur ? '&before=' . rawurlencode($cur) : '')); foreach ($d['conversations'] ?? [] as $r) { $o[$r['mailbox']] = true; } $cur = (string) ($d['next'] ?? ''); } while ($cur !== ''); ksort($o); return array_keys($o); };
$dm = $mbs($jd); $pm = $mbs($jp);
ok($dm === ['big1@business.example', 'dana@business.example', 'hush@business.example', 'intake@business.example', 'sales@business.example'], 'Dana\'s search-all finds only her mailboxes (and names them)', json_encode($dm));
ok(!in_array('hush@business.example', $pm, true) && !in_array('dana@business.example', $pm, true) && in_array('big3@business.example', $pm, true), 'Priya\'s finds big3 and not hush or Dana\'s', json_encode($pm));
// an agent never finds a sensitive conversation
$sensitiveThread = (int) one("SELECT m.thread_id FROM mail_messages m WHERE m.mailbox_id = $AB AND m.uid = 5");
$agentJson = req('GET', "/m/$AB/search?q=" . rawurlencode('in:anywhere'), ['headers' => as_agent(run_token(40, 9601))]);
$ad = jbody($agentJson)['data']['conversations'] ?? [];
$aj = req('GET', "/m/$AB/search?q=" . rawurlencode('in:anywhere'), ['jar' => $jp, 'headers' => ['Accept: application/json']]);
$pj = jbody($aj)['data']['conversations'] ?? [];
ok($agentJson['code'] === 200 && $ad !== [] && !in_array($sensitiveThread, array_column($ad, 'thread_id'), true) && in_array($sensitiveThread, array_column($pj, 'thread_id'), true),
   'an agent never finds the sensitive conversation; the person who marked it does', 'agent ' . count($ad) . ' person ' . count($pj));
// is:unread for a view holder follows their own reads
su("INSERT INTO mail_viewer_reads (member_id, message_key_id) SELECT 30, message_key_id FROM mail_messages WHERE mailbox_id = $B1 AND uid IN (1, 2, 4, 10, 11)");
$gotU = array_map('intval', array_column(walk(30, 'is:unread', $B1), 'message_id'));
sort($gotU);
$wantU = truth("COALESCE(f.special_use, '') NOT IN ('sent', 'drafts') AND NOT EXISTS (SELECT 1 FROM mail_viewer_reads r WHERE r.member_id = 30 AND r.message_key_id = m.message_key_id)", [$B1]);
ok($gotU === $wantU && count($wantU) > 400, 'is:unread for Dana (view) follows her own reads (' . count($wantU) . ' unread), not the mailbox\'s flags', count($gotU) . ' vs ' . count($wantU));
ok(count(walk(26, 'is:unread', $B1)) !== count($gotU), '…while Priya (full) reads the mailbox\'s own flags');
$before = (int) one("SELECT count(*) FROM activity_log WHERE action = 'mailbox.view' AND actor_member_id = 30 AND mailbox_id = $B1");
page($jd, "/m/$B1/search?q=lunch"); page($jd, "/m/$B1/search?q=invoice");
$after = (int) one("SELECT count(*) FROM activity_log WHERE action = 'mailbox.view' AND actor_member_id = 30 AND mailbox_id = $B1");
ok($after === 1 && $before === 1, 'a non-owner\'s many searches write mailbox.view once per half hour (one row for all of them)', "$before → $after");
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'mailbox.view' AND actor_member_id = 26 AND mailbox_id = $P") === 0, '…and the owner\'s own never');

// ================================================================================================================ labels
echo "4. Labels\n";
$r = post($jp, '/mail/label-create', ['mailbox' => $B1, 'name' => 'Work/Clients', 'colour' => '#198754']);
$L = (int) (jbody($r)['record_id'] ?? 0);
ok($r['code'] === 200 && $L > 0 && one('SELECT slug FROM tags WHERE id = :i', ['i' => $L]) === 'work/clients', 'create Work/Clients (the slash nests, the slug is lower case)', $r['body']);
$r = post($jp, '/mail/label-create', ['mailbox' => $B1, 'name' => 'work/clients']);
ok($r['code'] === 422 && str_contains(jbody($r)['error']['message'] ?? '', 'already has a label called'), 'the same name again is refused in words', $r['body']);
ok(post($jp, '/mail/label-create', ['mailbox' => $B1, 'name' => 'Bad', 'colour' => 'red'])['code'] === 422, 'a colour outside the swatches is refused');
$ids = array_map('intval', array_column(q("SELECT DISTINCT thread_id FROM mail_messages WHERE mailbox_id = $B1 AND uid IN (10, 12, 14) ORDER BY 1"), 'thread_id'));
$r = post($jp, '/mail/label', ['mailbox' => $B1, 'thread' => $ids, 'label' => [$L]]);
ok($r['code'] === 200 && (jbody($r)['count'] ?? 0) === 3, 'label three conversations from the list', $r['body']);
$r = post($jp, '/mail/label', ['mailbox' => $B1, 'matching' => 'zebra', 'label' => $L]);
ok($r['code'] === 200 && (jbody($r)['count'] ?? 0) === 1, 'label one more by `matching` (a search)', $r['body']);
[$c, $d] = jget($jp, "/m/$B1/label/$L");
$viewThreads = array_map('intval', array_column($d['rows'] ?? [], 'thread_id'));
ok($c === 200 && count($viewThreads) === 4 && ($d['label_id'] ?? 0) === $L, 'the label view shows four conversations', json_encode($viewThreads));
$zebra = (int) one("SELECT thread_id FROM mail_messages WHERE mailbox_id = $B1 AND subject = 'Zebra crossing'");
// a reply arriving in a labelled conversation keeps it in the view
$tail = (int) one('SELECT id FROM mail_messages WHERE thread_id = :t ORDER BY received_at DESC, id DESC LIMIT 1', ['t' => $zebra]);
$anchorMid = (string) one('SELECT message_id_header FROM mail_messages WHERE id = :i', ['i' => $tail]);
su("INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, in_reply_to, references_ids, from_address, to_addresses, subject, received_at, size, seen)
    SELECT folder_id, 1, 90001, ml_message_key_for(mailbox_id, '<s6-reply@x>', encode(digest('s6reply', 'sha256'), 'hex')), '<s6-reply@x>', message_id_header, ARRAY[message_id_header], 'u1@outside.example', ARRAY['big1@business.example'],
           'Re: ' || (SELECT subject FROM mail_threads WHERE id = $zebra), now(), 100, false FROM mail_messages WHERE id = $tail");
[$c, $d] = jget($jp, "/m/$B1/label/$L");
ok(count($d['rows'] ?? []) === 4 && (int) one("SELECT message_count FROM mail_threads WHERE id = $zebra") === 2, 'a reply arriving in a labelled conversation keeps it in the view (labels sit on the conversation\'s messages)');
$lp = page($jp, "/m/$B1/label/$L");
ok($lp['code'] === 200 && str_contains($lp['body'], 'data-screen="label-view"') && str_contains($lp['body'], 'data-label="' . $L . '"') && str_contains($lp['body'], 'Work/Clients'), 'the label view is the list (screen label-view) with the label in its header');
ok(str_contains(page($jp, "/m/$B1/v/inbox")['body'], 'id="mail-bulk-panel-label"') && str_contains(page($jp, "/m/$B1/v/inbox")['body'], 'name="label[]" value="' . $L . '"'), 'the bulk bar offers Label with this mailbox\'s labels as checkboxes');
$r = post($jp, '/mail/label-update', ['mailbox' => $B1, 'label' => $L, 'name' => 'Clients', 'colour' => '#dc3545']);
ok($r['code'] === 200 && one('SELECT slug || colour FROM tags WHERE id = :i', ['i' => $L]) === 'clients#dc3545', 'rename and recolour (the slug follows the name)', $r['body']);
$r = post($jp, '/mail/label-update', ['mailbox' => $B1, 'label' => $L, 'colour' => '#0d6efd']);
ok($r['code'] === 200 && one('SELECT label FROM tags WHERE id = :i', ['i' => $L]) === 'Clients', 'a field left out stays as it was');
$r = post($jp, '/mail/label', ['mailbox' => $B1, 'thread' => [$ids[0]], 'label' => [$L], 'removed' => 'yes']);
ok($r['code'] === 200 && count(jget($jp, "/m/$B1/label/$L")[1]['rows']) === 3, 'take the label off one conversation: three left');
$labelsPage = page($jp, "/m/$B1/labels");
ok(str_contains($labelsPage['body'], 'hx-confirm="Remove the label from 3 conversations? The mail stays."'), 'the labels page confirms with the count of conversations that lose it');
$msgsBefore = (int) one("SELECT count(*) FROM mail_messages WHERE mailbox_id = $B1");
$r = post($jp, '/mail/label-delete', ['mailbox' => $B1, 'label' => $L]);
ok($r['code'] === 200 && (jbody($r)['count'] ?? -1) === 3 && (int) one('SELECT count(*) FROM tags WHERE id = :i', ['i' => $L]) === 0
   && (int) one('SELECT count(*) FROM record_tags WHERE tag_id = :i', ['i' => $L]) === 0 && (int) one("SELECT count(*) FROM mail_messages WHERE mailbox_id = $B1") === $msgsBefore, 'delete: it leaves every conversation and the mail stays', $r['body']);
ok(page($jp, "/m/$B1/label/$L")['code'] === 404, '…and its view is gone (404)');
// who may
ok(post($jd, '/mail/label-create', ['mailbox' => $B1, 'name' => 'Dana\'s'])['code'] === 403, 'a view holder cannot make a label (403)');
ok(post($jd, '/mail/label', ['mailbox' => $B1, 'thread' => [$ids[1]], 'label' => [1]])['code'] === 403, '…or label a conversation (403)');
ok(post($jp, '/mail/label', ['mailbox' => $B2, 'thread' => [$ids[1]], 'label' => [1]])['code'] === 404, 'a conversation from another mailbox is not found (404)');
$r = tact(40, '/mail/label-create', ['mailbox' => $AB, 'name' => 'Agent filed']);
$AL = (int) (jbody($r)['record_id'] ?? 0);
ok($r['code'] === 200 && $AL > 0, 'an agent makes a label on its own mailbox (at its level)', $r['body']);
$plain = (int) one("SELECT thread_id FROM mail_messages WHERE mailbox_id = $AB AND uid = 3");
$r1 = tact(40, '/mail/label', ['mailbox' => $AB, 'thread' => [$plain], 'label' => [$AL]]);
$r2 = tact(40, '/mail/label', ['mailbox' => $AB, 'thread' => [$sensitiveThread], 'label' => [$AL]]);
ok($r1['code'] === 200 && in_array($r2['code'], [404, 422], true), 'an agent labels an ordinary conversation, and the sensitive one is "no such message"', $r1['code'] . ' ' . $r2['code'] . ' ' . $r2['body']);
$IL = (int) (jbody(post($jp, '/mail/label-create', ['mailbox' => $I, 'name' => 'Filed']))['record_id'] ?? 0);
$it = (int) one("SELECT thread_id FROM mail_messages WHERE mailbox_id = $I ORDER BY id LIMIT 1");
ok($IL > 0 && post($jp, '/mail/label', ['mailbox' => $I, 'thread' => [$it], 'label' => [$IL]])['code'] === 200, 'labels are fine on an intake mailbox (they live in MaluMail\'s index only)');

// ================================================================================================================ snooze
echo "5. Snooze, the worker's wake and unsnooze\n";
$box = page($jp, "/m/$P/v/inbox");
$tomorrow = (new DateTimeImmutable('tomorrow 08:00', new DateTimeZone('Europe/Berlin')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
ok(str_contains($box['body'], 'id="mail-bulk-panel-snooze"') && str_contains($box['body'], 'value="' . $tomorrow . '"'), 'the Snooze choices are Berlin\'s: Tomorrow 08:00 is ' . $tomorrow . ' (UTC)');
ok(preg_match('/<label class="form-check-label" for="mail-snooze-weekend">This weekend/', $box['body']) === 1 && preg_match('/for="mail-snooze-nextweek">Next week/', $box['body']) === 1, '…with This weekend and Next week');
$pt = array_map('intval', array_column(q("SELECT DISTINCT m.thread_id FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = $P AND f.special_use = 'inbox' ORDER BY 1"), 'thread_id'));
$t1 = $pt[0]; $t2 = $pt[1];
$r = post($jp, '/mail/snooze', ['mailbox' => $P, 'thread' => [$t1], 'until' => $tomorrow]);
$stored = (string) one('SELECT max(s.snoozed_until) FROM mail_thread_states s JOIN mail_messages m ON m.message_key_id = s.anchor_key_id WHERE m.thread_id = :t', ['t' => $t1]);
ok($r['code'] === 200 && strtotime($stored) === strtotime($tomorrow), 'Tomorrow 08:00 in Europe/Berlin is stored as its UTC instant', $r['code'] . ' ' . $stored . ' ' . $r['body']);
$state = fixture_state('priya');
ok(isset($state['Snoozed']) && $state['Snoozed']['MESSAGES'] >= 1 && (string) one("SELECT special_use FROM mail_folders WHERE mailbox_id = $P AND name = 'Snoozed'") === 'snoozed', 'the conversation left the Inbox for the Snoozed folder (recorded special_use snoozed)', json_encode($state['Snoozed'] ?? null));
$inboxIds = array_column(jget($jp, "/m/$P/v/inbox")[1]['rows'], 'thread_id');
$snoozedIds = array_column(jget($jp, "/m/$P/v/snoozed")[1]['rows'], 'thread_id');
ok(!in_array($t1, $inboxIds, true) && in_array($t1, $snoozedIds, true), 'it is gone from the Inbox and listed under Snoozed');
ok(post($jp, '/mail/snooze', ['mailbox' => $P, 'thread' => [$t2], 'until' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600)])['code'] === 422, 'a time in the past is refused');
ok(post($jp, '/mail/snooze', ['mailbox' => $P, 'thread' => [$t2]])['code'] === 422, 'no time is refused');
// the worker (the clock moved: the due time is put in the past)
su("UPDATE mail_thread_states SET snoozed_until = now() - interval '1 minute' WHERE snoozed_until IS NOT NULL AND mailbox_id = $P");
$w = worker('snoozes');
$wr = $w['ran']['snoozes'] ?? [];
ok(($wr['woken'] ?? 0) === 1 && ($wr['moved'] ?? 0) >= 1 && $w['errors'] === [], 'the worker\'s snoozes pass wakes it', json_encode($w));
$inboxRows = jget($jp, "/m/$P/v/inbox")[1]['rows'];
$back = array_values(array_filter($inboxRows, static fn ($r) => (int) $r['thread_id'] === $t1));
ok($back !== [] && $back[0]['unread'] === true, 'it is back in the Inbox, unread', json_encode($back));
$latest = (int) one('SELECT id FROM mail_messages WHERE thread_id = :t ORDER BY received_at DESC, id DESC LIMIT 1', ['t' => $t1]);
$fl = fixture_state('priya')['INBOX']['flags'] ?? [];
$uid = (string) one('SELECT uid FROM mail_messages WHERE id = :i', ['i' => $latest]);
ok(isset($fl[$uid]) && !in_array('\\Seen', $fl[$uid], true) && (int) (fixture_state('priya')['Snoozed']['MESSAGES'] ?? -1) === 0, 'on the server its latest message is unread and Snoozed is empty again', json_encode([$uid, $fl[$uid] ?? null]));
$html = page($jp, "/m/$P/v/inbox")['body'];
ok(preg_match('/id="t-' . $t1 . '".*?mail-woke/s', $html) === 1, 'the list shows the "Back from snooze" chip');
$th = page($jp, "/m/$P/t/$t1");
ok(str_contains($th['body'], 'id="mail-thread-woke"'), 'opening the conversation shows the chip once…');
ok(!str_contains(page($jp, "/m/$P/t/$t1")['body'], 'id="mail-thread-woke"') && !preg_match('/id="t-' . $t1 . '".*?mail-woke/s', page($jp, "/m/$P/v/inbox")['body']), '…and it is gone after it');
ok((int) one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'snooze_woke' AND mailbox_id = $P") === 1, 'Priya got a snooze_woke notice');
// unsnooze at once
$r = post($jp, '/mail/snooze', ['mailbox' => $P, 'thread' => [$t2], 'until' => gmdate('Y-m-d\TH:i:s\Z', time() + 2 * 86400)]);
ok($r['code'] === 200 && in_array($t2, array_column(jget($jp, "/m/$P/v/snoozed")[1]['rows'], 'thread_id'), true), 'snooze a second conversation for two days', $r['body']);
$r = post($jp, '/mail/unsnooze', ['mailbox' => $P, 'thread' => [$t2]]);
$row2 = array_values(array_filter(jget($jp, "/m/$P/v/inbox")[1]['rows'], static fn ($x) => (int) $x['thread_id'] === $t2));
ok($r['code'] === 200 && $row2 !== [] && $row2[0]['unread'] === true && (int) one('SELECT count(*) FROM mail_thread_states s JOIN mail_messages m ON m.message_key_id = s.anchor_key_id WHERE m.thread_id = :t AND s.snoozed_until IS NOT NULL', ['t' => $t2]) === 0,
   'unsnooze does the same at once: back in the Inbox, unread, nothing pending', $r['code'] . ' ' . $r['body']);
ok(post($jp, '/mail/unsnooze', ['mailbox' => $P, 'thread' => [$t2]])['code'] === 422, 'unsnoozing what is not snoozed says so');
$ur = page($jp, "/m/$P/v/inbox?notice=snoozed&n=1&unsnooze=$t2");
ok(str_contains($ur['body'], 'id="mail-unsnooze-form"'), 'a snooze lands with Undo');
// an intake mailbox's INBOX refuses
$r = post($jp, '/mail/snooze', ['mailbox' => $I, 'thread' => [$it], 'until' => $tomorrow]);
ok(in_array($r['code'], [403, 422], true) && str_contains($r['body'], 'intake'), 'an intake mailbox\'s Inbox is never snoozed (the schema says why)', $r['code'] . ' ' . $r['body']);
ok(count(fixture_state('intake')['INBOX']['flags'] ?? []) >= 3 && (int) (fixture_state('intake')['Snoozed']['MESSAGES'] ?? -1) === 0, '…and nothing moved on the server');
// an agent's wake
$et = (int) one("SELECT m.thread_id FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = $E AND f.special_use = 'inbox' ORDER BY m.id LIMIT 1");
$r = tact(40, '/mail/snooze', ['mailbox' => $E, 'thread' => [$et], 'until' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]);
ok($r['code'] === 200, 'the Expert snoozes a conversation of its own mailbox', $r['code'] . ' ' . $r['body']);
su("UPDATE mail_thread_states SET snoozed_until = now() - interval '1 minute' WHERE snoozed_until IS NOT NULL AND mailbox_id = $E");
$w = worker('snoozes');
$dk = q("SELECT status, kind, agent_member_id, acting_member_id FROM agent_dispatches WHERE mailbox_id = $E AND kind = 'snooze'");
ok(($w['ran']['snoozes']['dispatched'] ?? 0) === 1 && count($dk) === 1 && $dk[0]['status'] === 'queued' && (int) $dk[0]['agent_member_id'] === 40, 'an agent mailbox\'s wake writes a dispatch of kind snooze for the agent', json_encode([$w, $dk]));
$chat0 = count(chat_lines());
worker('dispatches');
$cl = array_slice(chat_lines(), $chat0);
$subject = (string) one('SELECT subject FROM mail_messages WHERE thread_id = :t ORDER BY id LIMIT 1', ['t' => $et]);
ok(count($cl) === 1 && (int) ($cl[0]['agent'] ?? 0) === 40 && str_contains($cl[0]['utterance'] ?? '', 'is back') && !str_contains($cl[0]['utterance'] ?? '', $subject), 'the worker gives the agent one turn — ids, never mail text', json_encode($cl));
ok(post($jp, '/mail/snooze', ['mailbox' => $S, 'thread' => [$t1], 'until' => $tomorrow])['code'] === 404, 'a conversation of another mailbox cannot be snoozed here (404)');
ok(post($jd, '/mail/snooze', ['mailbox' => $S, 'thread' => [(int) one("SELECT thread_id FROM mail_messages WHERE mailbox_id = $S LIMIT 1")], 'until' => $tomorrow])['code'] === 403, 'a view holder cannot snooze (403)');

// ================================================================================================================ folders
echo "6. Folders\n";
$r = post($jp, '/mail/folder-create', ['mailbox' => $P, 'name' => 'Projects']);
$r2 = post($jp, '/mail/folder-create', ['mailbox' => $P, 'name' => 'Acme', 'parent' => 'Projects']);
$fAcme = (int) (jbody($r2)['record_id'] ?? 0);
ok($r['code'] === 200 && $r2['code'] === 200 && one("SELECT name FROM mail_folders WHERE id = :i", ['i' => $fAcme]) === 'Projects.Acme' && isset(fixture_state('priya')['Projects.Acme']), 'create Projects, then Acme under it → Projects.Acme (on the server and in the index)', $r['body'] . $r2['body']);
ok(post($jp, '/mail/folder-create', ['mailbox' => $P, 'name' => 'Acme', 'parent' => 'Projects'])['code'] === 422, 'the same folder again is refused');
ok(post($jp, '/mail/folder-create', ['mailbox' => $P, 'name' => 'a.b'])['code'] === 422 && post($jp, '/mail/folder-create', ['mailbox' => $P, 'name' => 'X', 'parent' => 'Nope'])['code'] === 422, 'a name with the delimiter, and a parent that does not exist, are refused');
$r = post($jp, '/mail/folder-rename', ['mailbox' => $P, 'folder' => $fAcme, 'name' => 'Zeta']);
ok($r['code'] === 200 && one('SELECT name FROM mail_folders WHERE id = :i', ['i' => $fAcme]) === 'Projects.Zeta' && isset(fixture_state('priya')['Projects.Zeta']) && !isset(fixture_state('priya')['Projects.Acme']), 'rename Acme to Zeta', $r['body']);
// non-empty is refused with its count
$mv = (int) one("SELECT m.id FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = $P AND f.special_use = 'inbox' ORDER BY m.id DESC LIMIT 1");
$mvThread = (int) one("SELECT thread_id FROM mail_messages WHERE id = $mv");
$r = post($jp, '/mail/move', ['mailbox' => $P, 'thread' => [$mvThread], 'to_folder' => $fAcme]);
ok($r['code'] === 200, 'move a conversation into Projects.Zeta (slice 3\'s Move)', $r['body']);
$total = (int) one('SELECT count(*) FROM mail_messages WHERE folder_id = :f', ['f' => $fAcme]);
$r = post($jp, '/mail/folder-delete', ['mailbox' => $P, 'folder' => $fAcme]);
ok($r['code'] === 422 && str_contains(jbody($r)['error']['message'] ?? '', "Move or delete its $total message") && isset(fixture_state('priya')['Projects.Zeta']), 'a folder with mail in it is not deleted: "Move or delete its N messages first"', $r['body']);
ok(str_contains(page($jp, "/m/$P/folders")['body'], 'hx-confirm="Delete Zeta? It has mail in it'), 'the page warns before it asks');
$r = post($jp, '/mail/folder-create', ['mailbox' => $P, 'name' => 'Temp']);
$fTemp = (int) (jbody($r)['record_id'] ?? 0);
$r = post($jp, '/mail/folder-delete', ['mailbox' => $P, 'folder' => $fTemp]);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM mail_folders WHERE id = :i', ['i' => $fTemp]) === 0 && !isset(fixture_state('priya')['Temp']), 'an empty folder is deleted', $r['body']);
$inbox = (int) one("SELECT id FROM mail_folders WHERE mailbox_id = $P AND special_use = 'inbox'");
$sent = (int) one("SELECT id FROM mail_folders WHERE mailbox_id = $P AND special_use = 'sent'");
ok(post($jp, '/mail/folder-rename', ['mailbox' => $P, 'folder' => $inbox, 'name' => 'Mine'])['code'] === 403 && post($jp, '/mail/folder-delete', ['mailbox' => $P, 'folder' => $sent])['code'] === 403
   && post($jp, '/mail/folder-rename', ['mailbox' => $P, 'folder' => $sent, 'name' => 'Out'])['code'] === 403, 'Inbox and Sent refuse rename and delete (403, the system folders keep their names)');
ok(post($jd, '/mail/folder-create', ['mailbox' => $S, 'name' => 'Nope'])['code'] === 403, 'a view holder cannot make a folder (403)');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
ok(($reg['mail_folder_delete']['approval'] ?? $reg['mail_folder_delete']['paused'] ?? null) !== null && $reg['mail_folder_delete']['built'], 'an agent\'s mail_folder_delete pauses for a deletion approval (the registry says so)', json_encode($reg['mail_folder_delete'] ?? null));
$fp = page($jp, "/m/$P/folders");
ok($fp['code'] === 200 && str_contains($fp['body'], 'data-screen="folders"') && str_contains($fp['body'], 'folder-system') && str_contains($fp['body'], 'id="folder-new-form"'), 'the Folders page: system ones marked, the form to make one');
ok(!str_contains(page($jd, "/m/$S/folders")['body'], 'id="folder-new-form"'), '…and a view holder sees the folders without the forms');

// ================================================================================================================ templates
echo "7. Canned replies\n";
$r = post($jp, '/templates/save', ['name' => 'Thanks', 'subject' => 'Thank you', 'body' => "Thank you for writing.\nI will reply today.", 'shared' => 'no']);
$T1 = (int) (jbody($r)['record_id'] ?? 0);
$r = post($jp, '/templates/save', ['name' => 'Office hours', 'body' => 'We are open 9 to 5.', 'shared' => 'yes']);
$T2 = (int) (jbody($r)['record_id'] ?? 0);
ok($T1 > 0 && $T2 > 0, 'Priya writes one of her own and a shared one');
$dl = page($jd, '/templates');
ok(str_contains($dl['body'], 'Office hours') && !str_contains($dl['body'], 'Thank you for writing') && str_contains($dl['body'], 'Owned by'), 'Dana reads the shared one, not Priya\'s own', '');
$de = page($jd, "/templates/$T2/edit");
ok($de['code'] === 200 && str_contains($de['body'], 'id="template-readonly"') && str_contains($de['body'], 'Owned by') && !str_contains($de['body'], 'id="template-save"'), 'she opens it read-only: "Owned by …"');
ok(post($jd, '/templates/save', ['template' => $T2, 'name' => 'Hacked', 'body' => 'x'])['code'] === 403 && page($jd, "/templates/$T1/edit")['code'] === 404, '…cannot change it (403) and cannot open Priya\'s private one (404)');
$r = post($jo, '/templates/save', ['template' => $T2, 'body' => 'We are open 8 to 6.']);
ok($r['code'] === 200 && one('SELECT body FROM email_templates WHERE id = :i', ['i' => $T2]) === 'We are open 8 to 6.', 'a Mail admin edits it', $r['body']);
ok(str_contains(page($jp, '/templates')['body'], 'Thank you for writing.') && !str_contains(page($jp, '/templates')['body'], "I will reply today") && str_contains(page($jp, '/templates?q=hours')['body'], 'Office hours'), 'the cards show the first line; the search narrows them');
ok(str_contains(page($jp, "/m/$P/compose?template=$T1")['body'], 'Thank you for writing.') , 'Use opens the composer with its text');
$r = tact(40, '/templates/save', ['name' => 'Agent', 'body' => 'x']);
ok($r['code'] === 403, 'an agent\'s mail_template_save is refused (403)', $r['body']);
ok(post($jp, '/templates/save', ['name' => '', 'body' => 'x'])['code'] === 422 && post($jp, '/templates/save', ['name' => 'No body', 'body' => ''])['code'] === 422, 'a canned reply needs a name and a text (422)');
ok(str_contains(page($jp, "/templates/$T1/edit")['body'], 'hx-confirm="Delete this canned reply?"'), 'Delete asks first');
$r = post($jp, '/templates/delete', ['template' => $T1]);
ok($r['code'] === 200 && page($jp, "/templates/$T1/edit")['code'] === 404, 'delete archives it', $r['body']);
ok(post($jd, '/templates/delete', ['template' => $T2])['code'] === 403, 'Dana cannot delete the shared one');
ok(str_contains(page($jp, '/templates?q=zzzz')['body'], 'No canned reply matches zzzz') , 'an empty search says so');
$r = post($jp, '/templates/delete', ['template' => $T2]);
ok(str_contains(page($jp, '/templates')['body'], 'No canned replies yet'), 'the empty state invites the first one');

// ================================================================================================================ select all
echo "9. \"Select all N\" acts on every conversation the view holds, not only the rows ticked\n";
$BK = mb('bulk');
ok(wait_until(static fn (): bool => (int) one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :m', ['m' => $BK]) >= 60, 90), 'the bulk mailbox holds its 60 messages');
su("UPDATE mailboxes SET settings = settings || '{\"page_size\": 25}' WHERE id = $BK");
$cnt = static fn (string $where): int => (int) one("SELECT count(DISTINCT m.thread_id) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = $BK AND $where");
$msgs = static fn (string $name): int => (int) one("SELECT count(*) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = $BK AND f.name = :n", ['n' => $name]);
$threads0 = $cnt("f.special_use = 'inbox'");
$msgs0 = $msgs('INBOX');
$pg = page($jp, "/m/$BK/v/inbox");
ok($threads0 > 25 && str_contains($pg['body'], 'data-matching="in:inbox"') && str_contains($pg['body'], 'id="mail-select-all-matching"') && str_contains($pg['body'], 'name="matching" value="" id="mail-all-matching"'),
   "the Inbox view hands the bar its search (in:inbox) and offers Select all $threads0", "$threads0");
foreach (['all', 'starred', 'drafts', 'scheduled'] as $v) {
    $x = page($jp, "/m/$BK/v/$v")['body'];
    ok(str_contains($x, 'data-matching=""') && !str_contains($x, 'id="mail-select-all-matching"'), "the $v view has no exact search: no Select all");
}
ok(str_contains(page($jp, "/m/$BK/v/inbox?unread=1")['body'], 'data-matching="in:inbox is:unread"'), 'the Unread-only filter adds is:unread');
$rows0 = count(jget($jp, "/m/$BK/v/inbox")[1]['rows']);
ok($rows0 === 25 && $threads0 > $rows0, "the page holds 25 of $threads0 conversations");
// a folder with more conversations than one page: move the Inbox into it by `matching`, then archive it from the folder view
$fMany = (int) (jbody(post($jp, '/mail/folder-create', ['mailbox' => $BK, 'name' => 'Many']))['record_id'] ?? 0);
$first = jget($jp, "/m/$BK/v/inbox")[1]['rows'][0]['thread_id'];
$r = post($jp, '/mail/move', ['mailbox' => $BK, 'thread' => [$first], 'matching' => 'in:inbox', 'to_folder' => $fMany]);
ok($r['code'] === 200 && $msgs('INBOX') === 0 && $msgs('Many') === $msgs0, "Select all + Move: all $msgs0 messages leave the Inbox (the one ticked row plus every match)", $r['body']);
$fp = page($jp, "/m/$BK/f/$fMany");
ok(str_contains($fp['body'], 'data-matching="in:&quot;Many&quot;"') && str_contains($fp['body'], 'id="mail-select-all-matching"') && count(jget($jp, "/m/$BK/f/$fMany")[1]['rows']) === 25, 'the folder view hands the bar in:"Many" and more than one page');
$first = jget($jp, "/m/$BK/f/$fMany")[1]['rows'][0]['thread_id'];
$r = post($jp, '/mail/trash', ['mailbox' => $BK, 'thread' => [$first], 'matching' => 'in:"Many"', 'from_folder' => $fMany]);
ok($r['code'] === 200 && $msgs('Many') === 0 && $msgs('Trash') === $msgs0, 'Select all + Delete from the folder view: the whole folder moves, not the page', $r['code'] . ' ' . $r['body']);
// a label view
$LB = (int) (jbody(post($jp, '/mail/label-create', ['mailbox' => $BK, 'name' => 'Bulkly']))['record_id'] ?? 0);
$r = post($jp, '/mail/label', ['mailbox' => $BK, 'matching' => 'in:trash', 'label' => $LB]);
$labelled = (int) (jbody($r)['count'] ?? 0);
$lp = page($jp, "/m/$BK/label/$LB");
ok($labelled > 25 && str_contains($lp['body'], 'data-matching="label:bulkly in:anywhere"') && str_contains($lp['body'], 'id="mail-select-all-matching"') && count(jget($jp, "/m/$BK/label/$LB")[1]['rows']) === 25, "a label view of $labelled conversations hands the bar label:bulkly", $r['body']);
$first = jget($jp, "/m/$BK/label/$LB")[1]['rows'][0]['thread_id'];
$arch = (int) one("SELECT id FROM mail_folders WHERE mailbox_id = $BK AND special_use = 'archive'");
$r = post($jp, '/mail/move', ['mailbox' => $BK, 'thread' => [$first], 'matching' => 'label:bulkly in:anywhere', 'to_folder' => $arch]);
ok($r['code'] === 200 && $msgs('Trash') === 0 && $msgs('Archive') === $msgs0, "Select all + Move to Archive from the label view: all $msgs0 messages leave Trash", $r['code'] . ' ' . $r['body']);
$sn = post($jp, '/mail/snooze', ['mailbox' => $BK, 'matching' => 'in:archive', 'until' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 86400)]);
ok($sn['code'] === 422, 'snooze honours matching and refuses what is not in the Inbox (nothing to snooze)', $sn['body']);

// ================================================================================================================ the walk's cost
echo "10. What a page of a broad search costs on a 5,000-message mailbox (big4, as Bea)\n";
[$jb] = sign_on(28);
$B4 = mb('big4');
foreach (['plain' => 'a common word (plain)', 'is:read' => 'is:read'] as $qq => $what) {
    $cur = ''; $ms = []; $seen = []; $dups = 0; $pgs = 0;
    do {
        $t = microtime(true);
        $r = page($jb, "/m/$B4/search?q=" . rawurlencode($qq) . ($cur !== '' ? '&before=' . rawurlencode($cur) : ''), ['headers' => ['Accept: application/json']]);
        $ms[] = (microtime(true) - $t) * 1000;
        $d = jbody($r)['data'] ?? [];
        foreach ($d['conversations'] ?? [] as $c) { $dups += isset($seen[$c['thread_id']]) ? 1 : 0; $seen[$c['thread_id']] = true; }
        $cur = (string) ($d['next'] ?? '');
        $pgs++;
    } while ($cur !== '' && $pgs < 120);
    $past = $ms[min(count($ms) - 1, 21)];
    echo sprintf("  time %s: page 1 %.0f ms, page 22 (past %d conversations) %.0f ms, slowest %.0f ms over %d pages\n", $what, $ms[0], 21 * 50, $past, max($ms), count($ms));
    ok($dups === 0 && count($seen) > 800, "$what: " . count($seen) . ' conversations over ' . count($ms) . ' pages, no repeat');
    ok($past < 500 && $ms[0] < 500, "$what: a page stays under 500 ms (page 1 " . round($ms[0]) . ', past 1,000 ' . round($past) . ')');
}

// ================================================================================================================ the log
echo "8. The activity log\n";
$need = ['message.label', 'thread.snooze', 'thread.unsnooze', 'folder.create', 'folder.rename', 'folder.delete', 'label.create', 'label.update', 'label.delete', 'template.save', 'template.delete', 'mailbox.view'];
foreach ($need as $a) { ok(count(activity($a, $since)) > 0, "$a is logged"); }
$leak = (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = ANY (:acts) AND (after::text ~* '(Work/Clients|Projects|Zeta|Acme|Many|Bulkly|Thank you|Office hours|Zebra|Invoice|outside\\.example)' OR before::text ~* '(Work/Clients|Projects|Zeta|Acme)')",
    ['s' => $since, 'acts' => '{' . implode(',', array_diff($need, ['mailbox.view'])) . '}']);
ok($leak === 0, 'no log payload carries a label, folder, template or subject name, or an outside address', (string) $leak);
$snz = activity('thread.snooze', $since)[0] ?? [];
ok(isset(json_decode($snz['after'] ?? '{}', true)['until']) && (int) ($snz['mailbox_id'] ?? 0) === $P, 'thread.snooze carries the count and the wake time, with the mailbox');
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action IN ('message.label', 'thread.snooze', 'folder.create', 'label.create') AND mailbox_id IS NULL", ['s' => $since]) === 0, 'every row about a mailbox carries mailbox_id');
finish();
