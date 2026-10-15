<?php
/**
 * Slice 3's page proof (reading.md Proof 1–3, 5–7; reading-and-rendering.md §2–§3, §7): the conversation page and its partial, the
 * body's headers byte for byte, the part door's 404s and its inline-by-the-bytes rule, the image door's front, the Original and the .eml,
 * the eleven actions through the page (CSRF, the landing, Undo) and in JSON mode with an action token, the walls (a view holder's
 * per-person read state, the intake mailbox, an agent refused people-only actions, a sensitive conversation hidden from an agent), the
 * access log's reading rows. Run by tests/phase3/slice3/run.sh after door.py. The fixture is driven through its control directory.
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
function imap_state(string $user): array { return ctl(['op' => 'state', 'user' => $user])['folders']; }
function imap_stats(): array { usleep(350000); return json_decode((string) file_get_contents(need('ML_IMAP_STATS')), true)['_']; }
function wait_until(callable $f, float $timeout = 15.0): bool { $t = microtime(true); while (microtime(true) - $t < $timeout) { if ($f()) { return true; } usleep(150000); } return false; }
function as_member(int $m, string $sql, array $args = []) { pdo()->exec("SELECT set_config('app.member_id', '$m', false)"); $v = one($sql, $args); pdo()->exec("SELECT set_config('app.member_id', '', false)"); return $v; }
/** One message delivered through the fixture; answers [message id, thread id] once indexed. */
function deliver(string $user, string $subject, ?string $html = null, ?string $text = "A plain message.\n", array $headers = [], array $attachments = []): array
{
    global $RUN;
    $subject .= $RUN;
    $mid = '<' . bin2hex(random_bytes(8)) . '@outside.example>';
    $from = $headers['From'] ?? 'Outside Person <someone@outside.example>';
    unset($headers['From']);
    $h = "From: $from\r\nTo: $user\r\nSubject: $subject\r\nDate: " . date(DATE_RFC2822) . "\r\nMessage-ID: $mid\r\nMIME-Version: 1.0\r\n";
    foreach ($headers as $k => $v) { $h .= "$k: $v\r\n"; }
    $b = bin2hex(random_bytes(8));
    if ($html === null && $attachments === []) {
        $raw = $h . "Content-Type: text/plain; charset=utf-8\r\n\r\n" . $text;
    } else {
        $parts = [];
        if ($text !== null) { $parts[] = "Content-Type: text/plain; charset=utf-8\r\n\r\n$text"; }
        if ($html !== null) { $parts[] = "Content-Type: text/html; charset=utf-8\r\n\r\n$html"; }
        $inner = count($parts) > 1 ? "Content-Type: multipart/alternative; boundary=\"alt$b\"\r\n\r\n--alt$b\r\n" . implode("\r\n--alt$b\r\n", $parts) . "\r\n--alt$b--\r\n" : $parts[0];
        if ($attachments === []) { $raw = $h . $inner; }
        else {
            $raw = $h . "Content-Type: multipart/mixed; boundary=\"mix$b\"\r\n\r\n--mix$b\r\n" . $inner;
            foreach ($attachments as [$name, $type, $bytes]) {
                $raw .= "\r\n--mix$b\r\nContent-Type: $type; name=\"$name\"\r\nContent-Disposition: attachment; filename=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bytes), 76, "\r\n");
            }
            $raw .= "--mix$b--\r\n";
        }
    }
    ctl(['op' => 'deliver', 'user' => $user, 'raw' => $raw]);
    $mb = mb(explode('@', $user)[0]);
    if (!wait_until(static fn (): bool => one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :mb AND message_id_header = :m', ['mb' => $mb, 'm' => $mid]) > 0)) { throw new RuntimeException("not indexed: $subject"); }
    $r = q('SELECT id, thread_id FROM mail_messages WHERE mailbox_id = :mb AND message_id_header = :m', ['mb' => $mb, 'm' => $mid])[0];
    return [(int) $r['id'], (int) $r['thread_id']];
}
function where_is(int $mid): string { return (string) one('SELECT COALESCE(f.special_use, f.name) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.id = :id', ['id' => $mid]); }
function act(string $jar, string $action, array $form, bool $htmx = false): array
{
    $form += ['csrf_token' => page_csrf($jar)];
    return req('POST', '/mail/' . $action, ['jar' => $jar, 'form' => $form, 'headers' => $htmx ? ['HX-Request: true'] : []]);
}
function jact(int $member, string $action, array $form, ?int $run = null): array
{
    $tok = $run === null ? person_token($member) : run_token($member, $run);
    return req('POST', '/mail/' . $action, ['headers' => $run === null ? ['Accept: application/json', 'X-Action-Token: ' . $tok] : as_agent($tok), 'form' => $form]);
}

$RUN = ' #' . substr(bin2hex(random_bytes(3)), 0, 6);
$P = mb('priya'); $D = mb('dana'); $S = mb('sales'); $E = mb('expert'); $I = mb('intake');
[$jp] = sign_on(26); [$jd] = sign_on(30); [$jo] = sign_on(1);
$PRIYA = 'priya@business.example';
$CORPUS = dirname(__DIR__, 2) . '/sanitiser/corpus/benign/';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP4z8DAwMDAxMDAwMDAAAANBAEB4u2E3wAAAABJRU5ErkJggg==');
$DMARC_OK = 'default: False [-1.20 / 15.00]; DMARC_POLICY_ALLOW(-0.50)[outside.example,none]';
$DMARC_FAIL = 'default: False [6.50 / 15.00]; DMARC_POLICY_REJECT(2.00)[outside.example,reject]';

echo "1. The page\n";
[$m1, $t1] = deliver($PRIYA, 'Notes from the call', file_get_contents($CORPUS . 'gmail-thread.html'), null, ['From' => 'Leo <leo@marchetti.example>', 'X-Spamd-Result' => $DMARC_OK]);
$r = page($jp, "/m/$P/t/$t1");
ok($r['code'] === 200 && str_contains($r['body'], 'id="mail-thread"') && str_contains($r['body'], 'data-screen="thread-view"'), 'Priya opens a conversation of her own (thread-view)');
ok(preg_match('/<iframe class="mail-body-frame" id="frame-' . $m1 . '" sandbox="allow-popups allow-popups-to-escape-sandbox allow-scripts"\s+src="\/m\/' . $P . '\/msg\/' . $m1 . '\/body" referrerpolicy="no-referrer" loading="lazy"/', $r['body']) === 1,
   'the body is an <iframe sandbox> with exactly allow-popups allow-popups-to-escape-sandbox allow-scripts — never allow-same-origin');
ok(str_contains($r['body'], 'data-banner="outside"') && str_contains($r['body'], 'data-banner="dmarc-pass"') && str_contains($r['body'], 'data-banner="spam"'), 'the banners: from outside, DMARC passed, the spam score');
ok(str_contains($r['body'], 'id="act-archive"') && str_contains($r['body'], 'id="act-trash"') && str_contains($r['body'], 'id="act-spam"') && str_contains($r['body'], 'id="act-star"') && str_contains($r['body'], 'id="act-sensitive"'),
   'the actions this slice built are there (archive, delete, spam, star, sensitive)…');
ok(!str_contains($r['body'], 'id="act-reply"') && !str_contains($r['body'], 'id="act-label"') && !str_contains($r['body'], 'id="act-snooze"') && !str_contains($r['body'], 'id="act-take-over"'),
   '…and no button for an action another slice has not built (reply, label, snooze, take over — decision 6)');
ok(str_contains($r['body'], 'id="mail-shortcuts"') && str_contains($r['body'], 'data-keys="on"') && str_contains($r['body'], '/assets/mail/keys.js'), 'the shortcuts section and the keyboard script, on by default');
ok(str_contains($r['body'], 'id="msg-' . $m1 . '-original"'), 'Show original');
ok((string) one('SELECT seen FROM mail_messages WHERE id = :id', ['id' => $m1]) === '1' || one('SELECT seen FROM mail_messages WHERE id = :id', ['id' => $m1]) === true, 'opening marked it read on the server (store_seen for the owner)');
$st = imap_stats();
ok(end($st['stores'])['flags'] === ['\\Seen'] && end($st['stores'])['op'] === '+FLAGS.SILENT' && $st['seen_by_fetch'] === 0, '…by ONE UID STORE +FLAGS.SILENT (\\Seen), never by a fetch');
ok(page($jd, "/m/$P/t/$t1")['code'] === 404 && page($jo, "/m/$P/t/$t1")['code'] === 404, 'Dana and the Mail admin get 404 on Priya\'s conversation');
ok(page($jp, "/m/$P/t/999999")['code'] === 404 && str_contains(page($jp, "/m/$P/t/999999")['body'], 'moved or was deleted'), 'a conversation that is not there: 404 with the sentence and a way back');
$j = req('GET', "/m/$P/t/$t1", ['jar' => $jp, 'headers' => ['Accept: application/json']]);
$jd0 = json_decode($j['body'], true)['data'] ?? [];
ok($j['code'] === 200 && ($jd0['thread_id'] ?? 0) === $t1 && count($jd0['messages']) === 1 && !isset($jd0['messages'][0]['html']) && !isset($jd0['messages'][0]['body']), 'JSON: the index rows, never a body');
// a reply: the earlier message collapses, the latest is open; the partial opens the collapsed one
ctl(['op' => 'deliver', 'user' => $PRIYA, 'raw' => "From: Leo <leo@marchetti.example>\r\nTo: $PRIYA\r\nSubject: Re: Notes from the call$RUN\r\nDate: " . date(DATE_RFC2822) . "\r\nMessage-ID: <reply-" . time() . "@m.example>\r\nIn-Reply-To: " . one('SELECT message_id_header FROM mail_messages WHERE id = :id', ['id' => $m1]) . "\r\nContent-Type: text/plain\r\n\r\nSecond message.\r\n> quoted\r\n"]);
wait_until(static fn (): bool => one('SELECT message_count FROM mail_threads WHERE id = :t', ['t' => $t1]) >= 2);
$m2 = (int) one('SELECT id FROM mail_messages WHERE thread_id = :t ORDER BY id DESC LIMIT 1', ['t' => $t1]);
$r = page($jp, "/m/$P/t/$t1");
ok(preg_match('/id="msg-' . $m1 . '"[^>]*hx-get="\/m\/' . $P . '\/t\/' . $t1 . '\?message=' . $m1 . '/', $r['body']) === 1 && str_contains($r['body'], 'class="mail-msg mail-msg-collapsed'), 'the earlier message collapses to one line, opened with hx-get of its partial');
ok(str_contains($r['body'], 'id="msg-' . $m2 . '-plain"') && str_contains($r['body'], 'Second message.') && str_contains($r['body'], '<details class="mail-quoted">') && !str_contains($r['body'], 'id="frame-' . $m2 . '"'),
   'a plain-text message renders in the page (escaped, the quoted run folded), never in a frame');
$pr = req('GET', "/m/$P/t/$t1?message=$m1", ['jar' => $jp, 'headers' => ['HX-Request: true']]);
ok($pr['code'] === 200 && str_contains($pr['body'], 'id="frame-' . $m1 . '"') && !str_contains($pr['body'], 'id="mail-thread"'), 'the partial: the collapsed message open, with its frame');
ok(preg_match('/data-prev="[^"]*" data-next="[^"]*"/', $r['body']) === 1 || str_contains($r['body'], 'data-next='), 'the page carries j/k neighbours in the list\'s order');

echo "2. The body's headers\n";
$b = page($jp, "/m/$P/msg/$m1/body");
preg_match('/^Content-Security-Policy: (.*)$/mi', $b['headers'], $csp);
$nonce = preg_match("/'nonce-([^']+)'/", $csp[1] ?? '', $nm) ? $nm[1] : '';
$want = "sandbox allow-popups allow-popups-to-escape-sandbox allow-scripts; default-src 'none'; script-src 'nonce-$nonce'; img-src 'self' data:; style-src 'unsafe-inline'; font-src data:; frame-ancestors 'self'; form-action 'none'; base-uri 'none'";
ok($b['code'] === 200 && trim($csp[1] ?? '') === $want && strlen(base64_decode($nonce)) === 16, 'Content-Security-Policy byte for byte, a 16-byte nonce per response');
foreach (['X-Content-Type-Options: nosniff', 'Referrer-Policy: no-referrer', 'Cache-Control: private, no-store', 'Cross-Origin-Resource-Policy: same-origin', 'Content-Type: text/html; charset=utf-8'] as $hh) {
    ok(stripos($b['headers'], $hh) !== false, "  $hh");
}
ok(substr_count($b['body'], '<script') === 1 && str_contains($b['body'], 'nonce="' . $nonce . '"') && str_contains($b['body'], 'parent.postMessage({ml:"h"'), 'the height line is the only script, under the nonce');
ok(str_contains($b['body'], 'Ship the Q4 price list') && !str_contains($b['body'], 'googleusercontent') && str_contains($b['body'], '/assets/mail/remote-image.svg'), 'the sanitised body: the text, the signature\'s remote logo a placeholder');
$b2 = page($jp, "/m/$P/msg/$m1/body");
ok(!str_contains($b2['headers'], $nonce), 'a new nonce each time');
ok(page($jd, "/m/$P/msg/$m1/body")['code'] === 404, 'Dana: 404 on the body');
ok(page($jp, "/m/$P/msg/999999/body")['code'] === 404, 'no such message: 404');

echo "3. The part door\n";
[$m3, $t3] = deliver($PRIYA, 'Floor plan', str_replace('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP4z8DAwMDAxMDAwMDAAAANBAEB4u2E3wAAAABJRU5ErkJggg==', 'cid:key1@floorplan', file_get_contents($CORPUS . 'inline-image.html')) . '<img src="https://img.chairs.example/x.png" width="200">', 'See the plan.', ['From' => 'Facilities <facilities@harbor-logistics.example>', 'X-Spamd-Result' => $DMARC_OK], [['plan.pdf', 'application/pdf', "%PDF-1.4 plan"], ['evil.html', 'text/html', '<html><script>alert(1)</script><p>hi</p></html>'], ['photo.png', 'image/png', $png]]);
// the cid: image needs a related part with a Content-ID: deliver it as a raw message instead
$cidRaw = "From: Facilities <facilities@harbor-logistics.example>\r\nTo: $PRIYA\r\nSubject: Floor plan with key$RUN\r\nDate: " . date(DATE_RFC2822) . "\r\nMessage-ID: <cid-" . time() . "@h.example>\r\nX-Spamd-Result: $DMARC_OK\r\nMIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=\"rel1\"\r\n\r\n--rel1\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>The key: <img src=\"cid:key1@floorplan\" width=\"64\" height=\"64\" alt=\"Key\"></p>\r\n--rel1\r\nContent-Type: image/png\r\nContent-ID: <key1@floorplan>\r\nContent-Disposition: inline; filename=\"key.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode($png) . "\r\n--rel1--\r\n";
ctl(['op' => 'deliver', 'user' => $PRIYA, 'raw' => $cidRaw]);
wait_until(static fn (): bool => one("SELECT count(*) FROM mail_messages WHERE mailbox_id = $P AND subject = 'Floor plan with key$RUN'") > 0);
$m4 = (int) one("SELECT id FROM mail_messages WHERE mailbox_id = $P AND subject = 'Floor plan with key$RUN'");
$b = page($jp, "/m/$P/msg/$m4/body");
ok(preg_match('#src="/p/([A-Za-z0-9_-]+\.[0-9a-f]{64})"#', $b['body'], $pm) === 1, 'the cid: image became a signed /p/ link in the body');
$cidTok = $pm[1] ?? '';
$pp = req('GET', "/p/$cidTok");
ok($pp['code'] === 200 && stripos($pp['headers'], 'Content-Type: image/png') !== false && stripos($pp['headers'], 'Content-Disposition: inline') !== false && $pp['body'] === $png, '/p/ with no cookie at all: the PNG inline, by its bytes');
foreach (['X-Content-Type-Options: nosniff', "Content-Security-Policy: sandbox; default-src 'none'", 'Cache-Control: private, max-age=3600', 'Referrer-Policy: no-referrer'] as $hh) {
    ok(stripos($pp['headers'], $hh) !== false, "  $hh");
}
ok(stripos($pp['headers'], 'Set-Cookie') === false, '  and no cookie is set by the door');
$r = page($jp, "/m/$P/t/$t3");
preg_match_all('#<div class="mail-attachment[^"]*" data-part="([^"]+)">.*?<a href="/p/([^"]+)"[^>]*download="([^"]+)"#s', $r['body'], $att, PREG_SET_ORDER);
$byName = [];
foreach ($att as $a) { $byName[$a[3]] = $a[2]; }
ok(isset($byName['plan.pdf'], $byName['evil.html'], $byName['photo.png']), 'the attachments listed with download links through /p/', json_encode(array_keys($byName)));
$pdf = req('GET', '/p/' . $byName['plan.pdf']);
ok($pdf['code'] === 200 && stripos($pdf['headers'], 'Content-Disposition: attachment; filename="plan.pdf"') !== false && stripos($pdf['headers'], 'Content-Type: application/pdf') !== false && $pdf['body'] === '%PDF-1.4 plan', 'a PDF downloads with its name');
$ev = req('GET', '/p/' . $byName['evil.html']);
ok($ev['code'] === 200 && stripos($ev['headers'], 'Content-Disposition: attachment') !== false && stripos($ev['headers'], 'Content-Type: application/octet-stream') !== false && stripos($ev['headers'], 'text/html') === false, 'an HTML attachment downloads as application/octet-stream and never renders');
$ph = req('GET', '/p/' . $byName['photo.png']);
ok($ph['code'] === 200 && stripos($ph['headers'], 'Content-Disposition: inline') !== false && stripos($ph['headers'], 'Content-Type: image/png') !== false, 'a PNG attachment: inline (the bytes), shown as a preview');
ok(str_contains($r['body'], 'id="msg-' . $m3 . '-images"') && str_contains($r['body'], 'id="msg-' . $m3 . '-show-images"') && str_contains($r['body'], 'id="msg-' . $m3 . '-always-form"'), 'a message with remote images: "Images in this message are not shown — Show images · Always show from…" (Priya has full)');
// forged, expired, another member, another mailbox, unsigned
[$text, $mac] = explode('.', $cidTok, 2);
$forgeBody = static function (array $over) use ($text): string {
    $j = json_decode(base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4)), true);
    $j = array_merge($j, $over); ksort($j);
    $t = rtrim(strtr(base64_encode(json_encode($j, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    return $t . '.' . hash_hmac('sha256', $t, need('ML_PART_KEY'));
};
ok(req('GET', '/p/' . $text . '.' . str_repeat('0', 64))['code'] === 404, 'a forged MAC: 404');
ok(req('GET', '/p/notatoken')['code'] === 404 && req('GET', '/p/' . $text)['code'] === 404, 'an unsigned or shapeless token: 404');
ok(req('GET', '/p/' . $forgeBody(['e' => time() - 10]))['code'] === 404, 'an expired one: 404');
ok(req('GET', '/p/' . $forgeBody(['a' => 30]))['code'] === 404, 'one bound to Dana, who cannot open the mailbox: 404');
ok(req('GET', '/p/' . $forgeBody(['m' => $D]))['code'] === 404, 'one for another mailbox: 404');
$revoked = $forgeBody(['a' => 30, 'm' => $S]);
[$m5, $t5] = deliver('sales@business.example', 'Shared with a file', null, 'hi', [], [['note.txt', 'text/plain', 'hello']]);
$r5 = page($jd, "/m/$S/t/$t5");
preg_match('#<a href="/p/([^"]+)"[^>]*download="note.txt"#', $r5['body'], $nm5);
ok(($nm5[1] ?? '') !== '' && req('GET', '/p/' . $nm5[1])['code'] === 200, 'Dana (view on sales@) downloads an attachment of a shared mailbox');
su("DELETE FROM mailbox_permissions WHERE mailbox_id = $S AND principal_id = 30");
ok(req('GET', '/p/' . $nm5[1])['code'] === 404, '…and the same link answers 404 the moment her row is gone (the level re-checked on every use)');
su("INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) VALUES ($S, 'member', 30, 'view', 1)");
$partRows = q("SELECT after FROM activity_log WHERE action = 'message.part' AND mailbox_id = $S AND actor_member_id = 30 AND entity_id = $m5");
ok(count($partRows) === 1, 'a delegate\'s part fetch wrote message.part once (a second fetch within the hour writes none)');
req('GET', '/p/' . $nm5[1]);
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'message.part' AND mailbox_id = $S AND actor_member_id = 30 AND entity_id = $m5")) === 1, '  (still one after another fetch)');
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'message.part' AND mailbox_id = $P AND actor_member_id = 26")) === 0, 'the owner\'s part fetches write nothing');

echo "4. Images\n";
$FIX = 'http://127.0.0.1:' . (getenv('P_IMAGE_FIXTURE') ?: '9208');
[$m6, $t6] = deliver($PRIYA, 'With a remote image', '<p>Look:</p><img src="' . $FIX . '/logo.png" width="100"><img src="' . $FIX . '/pixel.gif" width="1" height="1">', null, ['From' => 'Chairs <news@chairs.example>', 'X-Spamd-Result' => $DMARC_OK]);
$b = page($jp, "/m/$P/msg/$m6/body");
ok(substr_count($b['body'], '/assets/mail/remote-image.svg') === 1 && !str_contains($b['body'], '/img/') && !str_contains($b['body'], 'pixel.gif'), 'by default: the placeholder, the pixel gone, nothing fetched');
$b = page($jp, "/m/$P/msg/$m6/body?images=1");
ok(preg_match('#src="/img/([A-Za-z0-9_-]+\.[0-9a-f]{64})"#', $b['body'], $im) === 1 && !str_contains($b['body'], 'pixel.gif'), 'Show images (?images=1): a signed /img/ link, the pixel still gone');
$ir = req('GET', '/img/' . ($im[1] ?? 'x'));
ok($ir['code'] === 200 && stripos($ir['headers'], 'Content-Type: image/png') !== false && str_starts_with($ir['body'], "\x89PNG") && stripos($ir['headers'], "Content-Security-Policy: default-src 'none'") !== false && stripos($ir['headers'], 'nosniff') !== false,
   '/img/ with no cookie: the PNG through the image door, with the door\'s headers');
[$txt, $mc] = explode('.', $im[1], 2);
ok(req('GET', '/img/' . $txt . '.' . str_repeat('1', 64))['code'] === 404 && req('GET', '/img/' . $cidTok)['code'] === 404, 'a forged image link, or a part link at the image door: 404');
[$m7, $t7] = deliver($PRIYA, 'Not an image', '<p>x</p><img src="' . $FIX . '/svg" width="100">', null, ['X-Spamd-Result' => $DMARC_OK]);
$b = page($jp, "/m/$P/msg/$m7/body?images=1");
preg_match('#src="/img/([^"]+)"#', $b['body'], $im2);
ok(req('GET', '/img/' . ($im2[1] ?? 'x'))['code'] === 404, 'an SVG the door refuses: 404 from the front');
// always show from a sender: a full holder, only for proven mail
$logMark = last_activity_id();
$a = act($jp, 'images-allow', ['mailbox' => $P, 'sender' => 'news@chairs.example', 'return_to' => "/m/$P/t/$t6"]);
ok($a['code'] === 302 && str_contains($a['location'], 'notice=images') && one("SELECT images_always FROM mail_correspondents WHERE mailbox_id = $P AND address = 'news@chairs.example'") == 1, 'Always show from a sender (Priya, full on her own mailbox)');
$b = page($jp, "/m/$P/msg/$m6/body");
ok(str_contains($b['body'], '/img/'), '…a DMARC-passed message from that sender shows its images without asking');
[$m8, $t8] = deliver($PRIYA, 'Forged', '<p>y</p><img src="' . $FIX . '/logo.png" width="100">', null, ['From' => 'Chairs <news@chairs.example>', 'X-Spamd-Result' => $DMARC_FAIL]);
$b = page($jp, "/m/$P/msg/$m8/body");
ok(!str_contains($b['body'], '/img/') && str_contains($b['body'], 'remote-image.svg'), '…and not a forged one from the same address (DMARC failed): the placeholder');
$a = act($jd, 'images-allow', ['mailbox' => $S, 'sender' => 'news@chairs.example']);
ok($a['code'] === 403 && str_contains($a['body'], 'full on the mailbox'), 'Dana (view on sales@) cannot set Always show: 403 in the schema\'s sentence');
$a = jact(40, 'images-allow', ['mailbox' => $E, 'sender' => 'news@chairs.example'], 9301);
ok($a['code'] === 403, 'an agent: refused (people only)');
$a = act($jp, 'images-allow', ['mailbox' => $P, 'sender' => 'news@chairs.example', 'on' => 'no']);
ok($a['code'] === 302 && one("SELECT images_always FROM mail_correspondents WHERE mailbox_id = $P AND address = 'news@chairs.example'") == 0, 'taken back with on=no');
$logs = q("SELECT after FROM activity_log WHERE action = 'sender.images_allow' AND mailbox_id = $P AND id > $logMark ORDER BY id");
ok(count($logs) === 2 && json_decode($logs[0]['after'], true) == ['domain' => 'chairs.example', 'on' => true] && !str_contains($logs[0]['after'], 'news@'), 'sender.images_allow logs the sender\'s DOMAIN only');

echo "5. The actions\n";
kernel_state(static function (array $s): array {
    $s['facts']['9301'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'endpoints' => [['application' => 'malumail']]];
    return $s;
});
[$ma, $ta] = deliver($PRIYA, 'Archive me');
$a = act($jp, 'archive', ['mailbox' => $P, 'thread' => [$ta], 'return_to' => "/m/$P/v/inbox"]);
ok($a['code'] === 302 && str_contains($a['location'], "/m/$P/v/inbox?notice=archived&n=1&undo=$ma") && where_is($ma) === 'archive', 'archive from the page: moved to Archive, the landing carries the notice and the Undo');
$land = page($jp, $a['location']);
ok(str_contains($land['body'], 'Archived 1 conversation.') && str_contains($land['body'], 'id="mail-undo"') && str_contains($land['body'], 'name="restore[]" value="' . $ma . '"'), '…the list shows "Archived 1 conversation." with Undo');
$u = act($jp, 'move', ['mailbox' => $P, 'restore' => [$ma], 'return_to' => "/m/$P/v/inbox"]);
ok($u['code'] === 302 && where_is($ma) === 'inbox', 'Undo puts a received message back in the Inbox');
$h = act($jp, 'archive', ['mailbox' => $P, 'thread' => [$ta], 'return_to' => "/m/$P/v/inbox"], true);
ok($h['code'] === 200 && str_contains($h['headers'], 'HX-Location') && str_contains($h['headers'], 'HX-Trigger: mailChanged'), 'through HTMX: HX-Location to the list, mailChanged');
act($jp, 'move', ['mailbox' => $P, 'restore' => [$ma]]);
$noCsrf = req('POST', '/mail/archive', ['jar' => $jp, 'form' => ['mailbox' => $P, 'thread' => [$ta]]]);
ok($noCsrf['code'] === 403, 'without the CSRF token: 403');
$j = jact(26, 'archive', ['mailbox' => $P, 'thread' => $ta]);
$jd1 = json_decode($j['body'], true);
ok($j['code'] === 200 && $jd1['ok'] === true && $jd1['record_id'] === $ta && $jd1['count'] === 1 && $jd1['undo'] === ['action' => 'mail_move', 'restore' => [$ma]] && str_starts_with($jd1['did'], 'Archived 1 conversation'), 'JSON with an action token: {ok, did, record_id, count, undo}', $j['body']);
$j = jact(26, 'move', ['mailbox' => $P, 'restore' => $ma]);
ok($j['code'] === 200 && where_is($ma) === 'inbox', 'JSON undo');
// move leaves Sent copies; trash then undo returns received to Inbox and sent to Sent
$sentRaw = "From: $PRIYA\r\nTo: pat@outside.example\r\nSubject: Our offer$RUN\r\nDate: " . date(DATE_RFC2822) . "\r\nMessage-ID: <sent-" . time() . "@business.example>\r\nContent-Type: text/plain\r\n\r\nHere it is.\r\n";
ctl(['op' => 'deliver', 'user' => $PRIYA, 'folder' => 'Sent', 'raw' => $sentRaw, 'flags' => ['\\Seen']]);
wait_until(static fn (): bool => one("SELECT count(*) FROM mail_messages WHERE mailbox_id = $P AND subject = 'Our offer$RUN'") > 0, 70);
$ms = (int) one("SELECT id FROM mail_messages WHERE mailbox_id = $P AND subject = 'Our offer$RUN'");
ctl(['op' => 'deliver', 'user' => $PRIYA, 'raw' => "From: Pat <pat@outside.example>\r\nTo: $PRIYA\r\nSubject: Re: Our offer$RUN\r\nDate: " . date(DATE_RFC2822) . "\r\nMessage-ID: <re-" . time() . "@outside.example>\r\nIn-Reply-To: <sent-" . explode('-', one("SELECT message_id_header FROM mail_messages WHERE id = $ms"))[1] . "\r\nContent-Type: text/plain\r\n\r\nThanks.\r\n"]);
wait_until(static fn (): bool => one("SELECT message_count FROM mail_threads WHERE id = (SELECT thread_id FROM mail_messages WHERE id = $ms)") >= 2);
$tOffer = (int) one("SELECT thread_id FROM mail_messages WHERE id = $ms");
$mre = (int) one("SELECT id FROM mail_messages WHERE thread_id = $tOffer AND id <> $ms");
ok(one("SELECT message_count FROM mail_threads WHERE id = $tOffer") == 2, 'a sent message and its reply are one conversation');
$folderA = (int) one("SELECT id FROM mail_folders WHERE mailbox_id = $P AND special_use = 'archive'");
$a = jact(26, 'move', ['mailbox' => $P, 'thread' => $tOffer, 'to_folder' => $folderA]);
ok($a['code'] === 200 && where_is($mre) === 'archive' && where_is($ms) === 'sent', 'move leaves the Sent copy where it is (decision 3)');
$a = jact(26, 'trash', ['mailbox' => $P, 'thread' => $tOffer]);
$tj = json_decode($a['body'], true);
ok($a['code'] === 200 && where_is($mre) === 'trash' && where_is($ms) === 'trash' && $tj['count'] === 2, 'trash takes every message but drafts');
$a = jact(26, 'move', ['mailbox' => $P, 'restore' => implode(',', $tj['undo']['restore'])]);
ok($a['code'] === 200 && where_is($mre) === 'inbox' && where_is($ms) === 'sent', 'undo of a trash: received → Inbox, sent → Sent (decision 2)');
$a = jact(26, 'spam', ['mailbox' => $P, 'thread' => $tOffer]);
ok($a['code'] === 200 && where_is($mre) === 'junk' && where_is($ms) === 'sent', 'spam takes the received message only');
$a = jact(26, 'not-spam', ['mailbox' => $P, 'thread' => $tOffer]);
ok($a['code'] === 200 && where_is($mre) === 'inbox', 'not spam: back to the Inbox', $a['code'] . ' ' . where_is($mre) . ' ' . substr($a['body'], 0, 200));
$a = jact(26, 'flag', ['mailbox' => $P, 'thread' => $tOffer, 'flagged' => 'yes']);
ok($a['code'] === 200 && one("SELECT flagged FROM mail_messages WHERE id = $mre") == 1 && one("SELECT flagged FROM mail_messages WHERE id = $ms") == 0, 'star: the latest message (Gmail)');
$a = jact(26, 'flag', ['mailbox' => $P, 'thread' => $tOffer, 'flagged' => 'no']);
ok($a['code'] === 200 && one("SELECT flagged FROM mail_messages WHERE id = $mre") == 0, 'unstar');
$a = jact(26, 'flag', ['mailbox' => $P, 'thread' => $tOffer, 'message' => $ms, 'flagged' => 'yes']);
ok($a['code'] === 200 && one("SELECT flagged FROM mail_messages WHERE id = $ms") == 1, 'star one named message of it');
$a = jact(26, 'mark-read', ['mailbox' => $P, 'thread' => $tOffer, 'read' => 'yes']);
ok($a['code'] === 200 && one("SELECT count(*) FROM mail_messages WHERE thread_id = $tOffer AND NOT seen") == 0, 'mark read: the whole conversation', $a['code'] . ' ' . substr($a['body'], 0, 160));
$a = jact(26, 'mark-read', ['mailbox' => $P, 'thread' => $tOffer, 'read' => 'no']);
ok($a['code'] === 200 && one("SELECT seen FROM mail_messages WHERE id = $mre") == 0 && one("SELECT seen FROM mail_messages WHERE id = $ms") == 1, 'mark unread: the latest message only (decision 5)', $a['code'] . ' ' . one("SELECT seen FROM mail_messages WHERE id = $mre") . '/' . one("SELECT seen FROM mail_messages WHERE id = $ms") . ' ' . substr($a['body'], 0, 160));
$a = jact(26, 'mark-read', ['mailbox' => $P, 'thread' => $tOffer, 'read' => 'no']);
ok($a['code'] === 422 && str_contains($a['body'], 'Nothing to mark unread'), 'marking unread what is unread: refused in words');
$a = jact(26, 'delete-forever', ['mailbox' => $P, 'thread' => $tOffer]);
ok($a['code'] === 422, 'delete forever of a conversation not in Trash or Spam: refused', $a['code'] . ' ' . substr($a['body'], 0, 160));
jact(26, 'trash', ['mailbox' => $P, 'thread' => $tOffer]);
$a = jact(26, 'delete-forever', ['mailbox' => $P, 'thread' => $tOffer]);
ok($a['code'] === 200 && json_decode($a['body'], true)['count'] === 2 && one("SELECT count(*) FROM mail_messages WHERE id IN ($ms, $mre)") == 0, 'delete forever from Trash: both expunged', $a['code'] . ' ' . substr($a['body'], 0, 160) . ' left=' . one("SELECT count(*) FROM mail_messages WHERE id IN ($ms, $mre)"));
[$mt1] = deliver($PRIYA, 'Trash A'); [$mt2] = deliver($PRIYA, 'Trash B');
jact(26, 'trash', ['mailbox' => $P, 'thread' => one("SELECT thread_id FROM mail_messages WHERE id = $mt1") . ',' . one("SELECT thread_id FROM mail_messages WHERE id = $mt2")]);
$a = jact(26, 'trash-empty', ['mailbox' => $P, 'which' => 'trash']);
$ej = json_decode($a['body'], true);
ok($a['code'] === 200 && $ej['count'] >= 2 && one("SELECT count(*) FROM mail_messages WHERE id IN ($mt1, $mt2)") == 0 && str_starts_with($ej['did'], 'Emptied Trash'), 'empty Trash with its count');
// matching: every conversation a search finds, and no other
foreach (['Newsletter one', 'Newsletter two'] as $sub) { deliver($PRIYA, $sub, null, 'weekly digest kumquat', ['From' => 'News <news@weekly.example>']); }
[$keep] = deliver($PRIYA, 'Keep me', null, 'nothing of the sort');
wait_until(static fn (): bool => one("SELECT count(*) FROM mail_messages WHERE mailbox_id = $P AND from_address = 'news@weekly.example' AND text_indexed_at IS NOT NULL") >= 2, 30);
$a = jact(26, 'archive', ['mailbox' => $P, 'matching' => 'from:news@weekly.example']);
$mj = json_decode($a['body'], true);
ok($a['code'] === 200 && $mj['count'] === 2 && str_starts_with($mj['did'], 'Archived 2 conversations') && one("SELECT count(*) FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = $P AND m.from_address = 'news@weekly.example' AND f.special_use = 'archive' AND m.subject LIKE '%' || :r", ['r' => $RUN]) == 2 && where_is($keep) === 'inbox',
   'matching: archives every conversation the search finds and no other', $a['body']);
$row = q("SELECT after FROM activity_log WHERE action = 'message.archive' ORDER BY id DESC LIMIT 1")[0];
ok((json_decode($row['after'], true)['matching'] ?? false) === true && json_decode($row['after'], true)['count'] === 2, '…one activity row with the count and matching: true');
$a = jact(26, 'archive', ['mailbox' => $P]);
ok($a['code'] === 422, 'neither thread nor matching: 422');
// the fixture agrees with the index after all that
$state = imap_state($PRIYA);
$idx = q("SELECT f.name, count(*) AS n FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = $P GROUP BY f.name");
$agree = true;
foreach ($idx as $x) { if ((int) ($state[$x['name']]['MESSAGES'] ?? -1) !== (int) $x['n']) { $agree = false; } }
ok($agree, 'the fixture\'s state agrees with the index after every action', json_encode(array_map(static fn ($x) => [$x['name'], $x['n'], $state[$x['name']]['MESSAGES'] ?? null], $idx)));

echo "6. The walls\n";
[$mv, $tv] = deliver('sales@business.example', 'For the team', null, 'shared text');
$stBefore = count(imap_stats()['stores']);
$r = page($jd, "/m/$S/t/$tv");
ok($r['code'] === 200 && one("SELECT count(*) FROM mail_viewer_reads WHERE member_id = 30 AND message_key_id = (SELECT message_key_id FROM mail_messages WHERE id = $mv)") == 1 && one("SELECT seen FROM mail_messages WHERE id = $mv") == 0,
   'Dana (view on sales@) opens a conversation: her own read mark, the message unread on the server');
ok(count(imap_stats()['stores']) === $stBefore, '…and no STORE reached the fixture');
ok(!str_contains($r['body'], 'id="act-archive"') && !str_contains($r['body'], 'id="act-trash"') && str_contains($r['body'], 'id="act-unread"'), '…the page offers her Mark unread, not archive or delete');
$a = act($jd, 'mark-read', ['mailbox' => $S, 'thread' => [$tv], 'read' => 'no']);
ok($a['code'] === 302 && one("SELECT count(*) FROM mail_viewer_reads WHERE member_id = 30 AND message_key_id = (SELECT message_key_id FROM mail_messages WHERE id = $mv)") == 0, 'her Mark unread takes her own mark off');
$a = act($jd, 'archive', ['mailbox' => $S, 'thread' => [$tv]]);
ok($a['code'] === 422 && str_contains($a['body'], 'Viewing a mailbox never changes it'), 'her archive: refused with the sentence');
$a = act($jp, 'archive', ['mailbox' => $S, 'thread' => [$tv]]);
ok($a['code'] === 302 && where_is($mv) === 'archive', 'Priya (triage) archives it');
[$mi, $ti] = deliver('intake@business.example', 'A ticket by mail');
$stBefore = count(imap_stats()['stores']);
$r = page($jd, "/m/$I/t/$ti");
ok($r['code'] === 200 && one("SELECT seen FROM mail_messages WHERE id = $mi") == 0 && count(imap_stats()['stores']) === $stBefore && one("SELECT count(*) FROM mail_viewer_reads WHERE member_id = 30 AND message_key_id = (SELECT message_key_id FROM mail_messages WHERE id = $mi)") == 1,
   'the intake mailbox: Dana (triage) opens a message — her own mark, no \\Seen ever stored');
foreach (['archive', 'trash', 'spam', 'delete-forever'] as $op) {
    $a = act($jd, $op, ['mailbox' => $I, 'thread' => [$ti]]);
    ok($a['code'] === 422 && (str_contains($a['body'], 'intake') || str_contains($a['body'], 'Nothing to delete')), "  $op of its INBOX message: refused with the sentence (" . $a['code'] . ')');
}
$folderI = (int) one("SELECT id FROM mail_folders WHERE mailbox_id = $I AND special_use = 'archive'");
$a = act($jd, 'move', ['mailbox' => $I, 'thread' => [$ti], 'to_folder' => $folderI]);
ok($a['code'] === 422 && str_contains($a['body'], 'intake'), '  move: refused too');
// agents
[$me1, $te1] = deliver('expert@business.example', 'To the expert');
$a = jact(40, 'archive', ['mailbox' => $E, 'thread' => $te1], 9301);
ok($a['code'] === 200 && where_is($me1) === 'archive', 'the Expert archives in its own mailbox through an action token');
$a = jact(40, 'sensitive', ['mailbox' => $E, 'thread' => $te1, 'sensitive' => 'yes'], 9301);
ok($a['code'] === 403, 'an agent\'s mail_sensitive_set: refused (people only)');
$reg = json_decode(file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
ok($reg['mail_delete_forever']['approval'] === 'deletion' && $reg['mail_trash_empty']['approval'] === 'deletion' && $reg['mail_delete_forever']['agent'] === true, 'mail_delete_forever and mail_trash_empty are declared paused for an agent (the kernel\'s deletion approval) in the registry');
ok($reg['mail_images_allow']['agent'] === false && $reg['mail_sensitive_set']['agent'] === false, 'mail_images_allow and mail_sensitive_set are people-only in the registry');
$a = act($jp, 'sensitive', ['mailbox' => $E, 'thread' => [$te1], 'sensitive' => 'yes']);
ok($a['code'] === 302 && one("SELECT sensitive FROM ml_thread_state($te1)") == 1, 'Priya (full on the agent mailbox) marks it sensitive');
ok(req('GET', "/m/$E/t/$te1", ['headers' => as_agent(run_token(40, 9301))])['code'] === 404, '…the agent\'s read of it answers 404');
$a = jact(40, 'archive', ['mailbox' => $E, 'thread' => $te1], 9301);
ok($a['code'] === 404, '…and its action on it too');
$a = act($jp, 'sensitive', ['mailbox' => $E, 'thread' => [$te1], 'sensitive' => 'no']);
ok($a['code'] === 302 && one("SELECT sensitive FROM ml_thread_state($te1)") == 0, 'taken off again');

echo "7. The access log and the Original\n";
$rawMark = last_activity_id();
$r = page($jp, "/m/$E/t/$te1");
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'message.open' AND mailbox_id = $E AND actor_member_id = 26 AND entity_id = $te1")) === 1, 'Priya\'s open of the agent\'s conversation wrote message.open once');
page($jp, "/m/$E/t/$te1");
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'message.open' AND mailbox_id = $E AND actor_member_id = 26 AND entity_id = $te1")) === 2, '…and once more for a second open (one row per open)');
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'message.open' AND mailbox_id = $P AND actor_member_id = 26")) === 0, 'the owner\'s own opens write none');
$o = page($jp, "/m/$E/msg/$me1/original");
ok($o['code'] === 200 && str_contains($o['body'], 'id="mail-original-headers"') && str_contains($o['body'], '<dt class="col-sm-3 text-truncate">Message-ID</dt>') && str_contains($o['body'], 'id="mail-original-source"') && str_contains($o['body'], 'To the expert'), 'the Original: the headers as a list and the source');
$w = page($jp, "/m/$E/msg/$me1/raw");
ok($w['code'] === 200 && stripos($w['headers'], 'Content-Type: message/rfc822') !== false && stripos($w['headers'], 'filename="' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower('To the expert' . $RUN)), '-') . '.eml"') !== false && str_starts_with($w['body'], 'From: ') && (int) one("SELECT size FROM mail_messages WHERE id = $me1") === strlen($w['body']),
   'the .eml download: message/rfc822, named by the subject, RFC822.SIZE bytes');
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'message.raw' AND mailbox_id = $E AND actor_member_id = 26 AND id > $rawMark")) === 2, 'the Original and the .eml each wrote message.raw');
ok(page($jd, "/m/$E/msg/$me1/original")['code'] === 404 && page($jd, "/m/$E/msg/$me1/raw")['code'] === 404, 'Dana: 404 on both');
$acc = as_member(26, "SELECT string_agg(DISTINCT action, ',' ORDER BY action) FROM mcp_mailbox_access_log WHERE mailbox_id = $E");
ok(str_contains((string) $acc, 'message.open') && str_contains((string) $acc, 'message.raw'), 'the access log view shows the reading rows to the overseer', (string) $acc);
$mcp = q("SELECT count(*) FROM activity_log WHERE action = 'mcp.read' AND mailbox_id = $E AND actor_member_id = 26");
ok($mcp[0]['count'] >= 1, 'an agent-mailbox read through the records queries (door.py) wrote mcp.read');
ok(count(q("SELECT 1 FROM activity_log WHERE after::text ILIKE '%Ship the Q4%' OR after::text ILIKE '%floor plan is attached%'")) === 0, 'no body text in any activity row');

finish();
