<?php
/**
 * Slice 4's page proof (writing.md Proof 1–5, 7–9 in their page half; sending-and-oversight.md §3): the composer at /m/{mb}/compose
 * and /m/{mb}/drafts/{draft}, the nine actions through the page (CSRF, the landing, Undo) and in JSON mode with an action token, the
 * prefill of a reply / reply all / forward, the attachment door's limits, autosave and its conflict, a phone's draft opened by its key,
 * an agent's token (purpose, held, withdrawn, people-only identities, the Expert in a person's run), identities through the fake
 * Mailbox MCP, and the walls. Run by tests/phase3/slice4/run.sh after service.py. The fixtures are driven through their control doors.
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

$CTL = need('ML_IMAP_CONTROL');
$STATE = need('ML_DEV_STATE');
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
function deliver(string $user, string $subject, string $text, array $headers = [], array $attachments = []): array
{
    global $RUN;
    $subject .= $RUN;
    $mid = '<' . bin2hex(random_bytes(8)) . '@outside.example>';
    $from = $headers['From'] ?? 'Outside Person <someone@outside.example>';
    unset($headers['From']);
    $h = "From: $from\r\nTo: $user\r\nSubject: $subject\r\nDate: " . date(DATE_RFC2822) . "\r\nMessage-ID: $mid\r\nMIME-Version: 1.0\r\n";
    foreach ($headers as $k => $v) { $h .= "$k: $v\r\n"; }
    if ($attachments === []) {
        $raw = $h . "Content-Type: text/plain; charset=utf-8\r\n\r\n" . $text;
    } else {
        $b = bin2hex(random_bytes(8));
        $raw = $h . "Content-Type: multipart/mixed; boundary=\"mix$b\"\r\n\r\n--mix$b\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n$text";
        foreach ($attachments as [$name, $type, $bytes]) {
            $raw .= "\r\n--mix$b\r\nContent-Type: $type; name=\"$name\"\r\nContent-Disposition: attachment; filename=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bytes), 76, "\r\n");
        }
        $raw .= "--mix$b--\r\n";
    }
    ctl(['op' => 'deliver', 'user' => $user, 'raw' => $raw]);
    $mb = mb(explode('@', $user)[0]);
    if (!wait_until(static fn (): bool => one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :mb AND message_id_header = :m', ['mb' => $mb, 'm' => $mid]) > 0)) { throw new RuntimeException("not indexed: $subject"); }
    $r = q('SELECT id, thread_id, message_key_id FROM mail_messages WHERE mailbox_id = :mb AND message_id_header = :m', ['mb' => $mb, 'm' => $mid])[0];
    return [(int) $r['id'], (int) $r['thread_id'], (int) $r['message_key_id'], $mid];
}
function act(string $jar, string $action, array $form, bool $htmx = false): array
{
    $form += ['csrf_token' => page_csrf($jar)];
    return req('POST', '/mail/' . $action, ['jar' => $jar, 'form' => $form, 'headers' => $htmx ? ['HX-Request: true'] : []]);
}
function jact(string $jar, string $action, array $form): array
{
    $form += ['csrf_token' => page_csrf($jar)];
    return req('POST', '/mail/' . $action, ['jar' => $jar, 'form' => $form, 'headers' => ['Accept: application/json']]);
}
function tact(int $member, string $action, array $form, ?int $run = null): array
{
    $tok = $run === null ? person_token($member) : run_token($member, $run);
    return req('POST', '/mail/' . $action, ['headers' => $run === null ? ['Accept: application/json', 'X-Action-Token: ' . $tok] : as_agent($tok), 'form' => $form]);
}
function jbody(array $r): array { return json_decode($r['body'], true) ?: []; }
function orow(int $id, string $cols = 'status'): array { return q("SELECT $cols FROM outbound_email WHERE id = :id", ['id' => $id])[0] ?? []; }
/** One multipart upload to the draft's files door. */
function upload(string $jar, int $mb, int $draft, string $name, string $bytes, string $type = 'application/octet-stream'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'mlup');
    file_put_contents($tmp, $bytes);
    $ch = curl_init(BASE . "/m/$mb/drafts/$draft/files");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60,
                            CURLOPT_HTTPHEADER => ['Accept: application/json'],
                            CURLOPT_POSTFIELDS => ['csrf_token' => page_csrf($jar), 'mailbox' => $mb, 'draft' => $draft, 'file' => new CURLFile($tmp, $type, $name)]]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unlink($tmp);
    return ['code' => $code, 'body' => $body];
}
function mcp_admin(string $tool, array $args): array
{
    $menv = substr(need('ML_DEV_ENV'), 0, -4) . '-mailer.env';
    preg_match('/^MALUMAIL_MAILBOX_API_KEY=(.*)$/m', (string) file_get_contents($menv), $m);
    $port = trim((string) file_get_contents(need('ML_DEV_STATE') . '/mcp.port'));
    $r = req('POST', "http://127.0.0.1:$port/mcp", ['json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]], 'headers' => ['Authorization: Bearer ' . trim($m[1] ?? '')]]);
    return jbody($r);
}

$RUN = ' #' . substr(bin2hex(random_bytes(3)), 0, 6);
$P = mb('priya'); $D = mb('dana'); $S = mb('sales'); $E = mb('expert'); $M = mb('marco');
[$jp] = sign_on(26); [$jd] = sign_on(30); [$jo] = sign_on(1);
$PRIYA = 'priya@business.example';
$undo = (int) one('SELECT undo_send_seconds FROM ml_settings WHERE id = 1');

echo "1. The composer\n";
$r = page($jp, "/m/$P/compose");
ok($r['code'] === 200 && str_contains($r['body'], 'id="compose-form"') && str_contains($r['body'], 'data-screen="compose"') && str_contains($r['body'], 'action="/mail/send"'), 'Priya opens a new message (screen compose; the form posts to mail_send)');
ok(str_contains($r['body'], 'id="compose-identity"') && str_contains($r['body'], 'priya@business.example') && str_contains($r['body'], 'id="compose-chips-to"') && str_contains($r['body'], 'id="compose-subject"'), 'From (the identity), To chips, Subject');
ok(str_contains($r['body'], 'id="compose-editor"') && str_contains($r['body'], 'name="body"') && str_contains($r['body'], '/assets/vendor/squire-2.4.9/squire.js') && str_contains($r['body'], '/assets/js/compose.js'), 'the editor (Squire, vendored) over a plain textarea');
ok(str_contains($r['body'], 'id="compose-send"') && str_contains($r['body'], 'id="compose-schedule"') && str_contains($r['body'], 'id="compose-file"') && str_contains($r['body'], 'id="compose-discard"') && str_contains($r['body'], 'mail-compose-bar'), 'Send with Schedule…, the paper-clip, Discard in the bar');
ok(preg_match_all('/class="modal fade"/', $r['body']) === 1 && str_contains($r['body'], 'id="record-picker"'), 'no modal but the record picker (the one exemption)');
$j = jbody(req('GET', "/m/$P/compose", ['jar' => $jp, 'headers' => ['Accept: application/json']]));
ok(($j['data']['can_send'] ?? null) === true && ($j['data']['identities'][0]['address'] ?? '') === $PRIYA && !isset($j['data']['identities'][0]['signature_html']), 'JSON: can_send, the identities (no signature body)');
$r = page($jp, "/m/$P/compose?to=lee%40customer.example&subject=Hi%20there&body=Hello");
ok(str_contains($r['body'], 'name="to[]" value="lee@customer.example"') && str_contains($r['body'], 'value="Hi there"') && str_contains($r['body'], 'Hello'), 'to=, subject= and body= prefill a new message');
$r = page($jd, "/m/$S/compose");
ok($r['code'] === 403 && str_contains($r['body'], 'file and organise mail here'), 'Dana (view on Sales) is refused the composer in a sentence (403)');
ok(page($jp, "/m/$M/compose")['code'] === 404, 'another person\'s mailbox: 404');
$r = page($jp, "/m/$D/compose");
ok($r['code'] === 200 && !str_contains($r['body'], 'id="compose-send"') && str_contains($r['body'], 'id="compose-no-send"'), 'Priya (triage on Dana\'s mailbox) writes a draft there but sees no Send');
ok(str_contains($r['body'], 'id="compose-delegate"') && str_contains($r['body'], 'sent by'), '…a delegate\'s page says who sends');

echo "2. Send through the page, Undo, Schedule\n";
$mark = last_activity_id();
$r = act($jp, 'send', ['mailbox' => $P, 'to' => 'one@customer.example, dana@business.example', 'subject' => 'Form send' . $RUN, 'body' => "Plain words\nsecond line", 'body_format' => 'text', 'return_to' => "/m/$P/v/inbox"]);
ok($r['code'] === 302 && str_contains($r['location'], "/m/$P/v/inbox?notice=sent"), 'a plain form POST (no JavaScript) sends: 302 to the list with the notice');
$o1 = (int) one("SELECT id FROM outbound_email WHERE subject = 'Form send$RUN'");
$row = orow($o1, "status, to_addresses, body_text, body_html, (send_after > now())::int AS ahead, external");
ok($o1 > 0 && $row['status'] === 'queued' && $row['to_addresses'] === '{one@customer.example,dana@business.example}' && $row['ahead'] === 1 && $row['body_html'] === null, 'the row: queued with the undo window ahead, both addresses, text only');
$log = activity('message.send', $mark);
$after = json_decode($log[0]['after'] ?? '{}', true);
ok(count($log) === 1 && $after['recipients_inside'] === 1 && $after['recipients_outside'] === 1 && !str_contains($log[0]['after'], 'customer.example') && !str_contains($log[0]['after'], 'Form send'), 'message.send logs counts inside/outside — never an address or the subject');
$r = jact($jp, 'send', ['mailbox' => $P, 'to' => ['two@customer.example'], 'subject' => 'Rich send' . $RUN, 'body' => '<div>Rich <b>words</b><script>alert(1)</script></div>', 'body_format' => 'html']);
$j = jbody($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['status'] === 'queued' && $j['undo_id'] === 'mail_send_cancel:' . $j['record_id'] && $j['seconds_left'] >= 1, 'JSON: queued with seconds_left and the undo id');
$o2 = (int) $j['record_id'];
$row = orow($o2, 'body_html, body_text');
ok(str_contains($row['body_html'], '<b>words</b>') && !str_contains($row['body_html'], 'script') && $row['body_text'] === 'Rich words', 'the HTML was cleaned by the mail service and its text derived');
$u = jact($jp, 'send-cancel', ['mailbox' => $P, 'outbound' => $o2]);
ok($u['code'] === 200 && orow($o2)['status'] === 'cancelled', 'Undo inside the window through mail_send_cancel: cancelled');
ok(count(activity('message.cancel', $mark)) === 1, '…logged message.cancel');
ok(wait_until(static fn (): bool => orow($o1)['status'] === 'sent', 25), 'the plain send went out when its window passed');
$u = jact($jp, 'send-cancel', ['mailbox' => $P, 'outbound' => $o1]);
ok($u['code'] === 422 && (str_contains($u['body'], 'cannot become') || str_contains($u['body'], 'Too late')), 'Undo after the send: refused with the guard\'s sentence');
$st = jbody(req('GET', "/m/$P/compose?status=$o1", ['jar' => $jp, 'headers' => ['Accept: application/json']]));
ok(($st['data']['status'] ?? '') === 'sent' && ($st['data']['thread_id'] ?? null) !== null, 'the status poll answers sent with the conversation to open');
$at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 hours')->format('Y-m-d\TH:i');
$r = jact($jp, 'send', ['mailbox' => $P, 'to' => ['later@customer.example'], 'subject' => 'Later' . $RUN, 'body' => 'Later.', 'body_format' => 'text', 'send_at' => $at]);
$j = jbody($r);
ok($r['code'] === 200 && $j['status'] === 'scheduled' && str_contains($j['did'], 'Scheduled for'), 'send_at two hours ahead: scheduled');
$o3 = (int) $j['record_id'];
ok(count(activity('message.schedule', $mark)) === 1, '…logged message.schedule');
$r = page($jp, "/m/$P/v/scheduled");
ok($r['code'] === 200 && str_contains($r['body'], 'Later' . $RUN) && preg_match('/action="\/mail\/send-cancel"[^>]*>.*?name="outbound" value="' . $o3 . '"/s', $r['body']) === 1, 'the Scheduled view shows it with a Cancel form');
$c = act($jp, 'send-cancel', ['mailbox' => $P, 'outbound' => $o3, 'return_to' => "/m/$P/v/scheduled"]);
ok($c['code'] === 302 && orow($o3)['status'] === 'cancelled', 'Cancel from the view: cancelled, back to a draft');
$r = jact($jp, 'send', ['mailbox' => $P, 'to' => ['x@customer.example'], 'subject' => 'Soon', 'body' => 'x', 'body_format' => 'text', 'send_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+20 seconds')->format('Y-m-d\TH:i:s')]);
ok($r['code'] === 422 && str_contains($r['body'], 'at least a minute'), 'a schedule under a minute ahead is refused');
$r = jact($jp, 'send', ['mailbox' => $P, 'subject' => 'Nobody', 'body' => 'x', 'body_format' => 'text']);
ok($r['code'] === 422 && str_contains($r['body'], 'recipient'), 'no recipient: refused');
$r = jact($jp, 'send', ['mailbox' => $P, 'to' => ['not an address'], 'subject' => 'Bad', 'body' => 'x', 'body_format' => 'text']);
ok($r['code'] === 422 && str_contains($r['body'], 'is not an address'), 'a recipient that is not an address: refused, naming it');
$r = jact($jp, 'send', ['mailbox' => $P, 'to' => ['x@customer.example'], 'subject' => 'Empty', 'body' => '', 'body_format' => 'text']);
ok($r['code'] === 422 && str_contains($r['body'], 'empty'), 'an empty message: refused');
$r = jact($jd, 'send', ['mailbox' => $S, 'to' => ['x@customer.example'], 'subject' => 'Dana', 'body' => 'x', 'body_format' => 'text']);
ok($r['code'] === 403, 'Dana (view on Sales) cannot send from it');
$r = jact($jp, 'send', ['mailbox' => $D, 'to' => ['x@customer.example'], 'subject' => 'Priya as Dana', 'body' => 'x', 'body_format' => 'text']);
ok($r['code'] === 403, 'Priya (triage on Dana\'s) cannot send from it');
$noCsrf = req('POST', '/mail/send', ['jar' => $jp, 'form' => ['mailbox' => $P, 'to' => 'x@customer.example', 'subject' => 'x', 'body' => 'x']]);
ok(in_array($noCsrf['code'], [403, 419, 422], true), 'without the CSRF token: refused');

echo "3. Reply, reply all, forward\n";
[$m1, $t1, $k1, $mid1] = deliver($PRIYA, 'Re: Pricing question', "Could you send the list?\n", ['From' => 'Lee Park <lee@customer.example>', 'Reply-To' => 'lee.park@customer.example', 'Cc' => 'ops@customer.example, priya@business.example']);
$r = page($jp, "/m/$P/compose?reply=$k1");
ok($r['code'] === 200 && str_contains($r['body'], 'name="to[]" value="lee.park@customer.example"') && !str_contains($r['body'], 'name="cc[]"'), 'Reply: To = the Reply-To, nothing in Cc');
ok(str_contains($r['body'], 'value="Re: Pricing question' . $RUN . '"') && !str_contains($r['body'], 'Re: Re:'), '…the subject keeps one "Re:"');
ok(str_contains($r['body'], 'Could you send the list?') && str_contains($r['body'], 'wrote:'), '…the original quoted with its attribution');
ok(str_contains($r['body'], 'id="compose-conversation"') && str_contains($r['body'], "/m/$P/t/$t1"), '…the conversation beside it (1280)');
$r = page($jp, "/m/$P/compose?reply_all=$k1");
ok(str_contains($r['body'], 'name="to[]" value="lee.park@customer.example"') && str_contains($r['body'], 'name="cc[]" value="ops@customer.example"') && !str_contains($r['body'], 'name="cc[]" value="priya@business.example"'), 'Reply all: Cc = the original\'s Cc minus the mailbox\'s own address');
$mark = last_activity_id();
$r = jact($jp, 'reply', ['mailbox' => $P, 'message' => $k1, 'body' => 'Attached below.', 'body_format' => 'text', 'reply_all' => 'yes']);
$j = jbody($r);
ok($r['code'] === 200 && $j['status'] === 'queued', 'mail_reply (reply all) queues the answer');
$o4 = (int) $j['record_id'];
$row = orow($o4, 'to_addresses, cc_addresses, subject, in_reply_to, reply_to_key_id, body_text');
ok($row['to_addresses'] === '{lee.park@customer.example}' && $row['cc_addresses'] === '{ops@customer.example}' && $row['subject'] === 'Re: Pricing question' . $RUN && $row['in_reply_to'] === $mid1 && (int) $row['reply_to_key_id'] === $k1, 'the row: the recipients by the rules, Re: once, In-Reply-To and the key', json_encode($row));
ok(str_contains($row['body_text'], 'Attached below.') && str_contains($row['body_text'], '> Could you send the list?'), '…the original quoted under the words (an agent\'s or a no-JS reply)');
$log = activity('message.reply', $mark);
ok(count($log) === 1 && (json_decode($log[0]['after'], true)['answers'] ?? 0) === $k1, 'message.reply logged with the answered key');
$pdf = "%PDF-1.4 forward me " . $RUN;
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP4z8DAwMDAxMDAwMDAAAANBAEB4u2E3wAAAABJRU5ErkJggg==');
[$m2, $t2, $k2] = deliver($PRIYA, 'Fwd: Plans', "Here are the plans.\n", ['From' => 'Facilities <fac@harbor.example>'], [['plan.pdf', 'application/pdf', $pdf], ['site.png', 'image/png', $png]]);
$r = page($jp, "/m/$P/compose?forward=$k2");
ok($r['code'] === 200 && str_contains($r['body'], 'value="Fwd: Plans' . $RUN . '"') && str_contains($r['body'], 'Forwarded message') && str_contains($r['body'], 'name="attachment_part[]"') && substr_count($r['body'], 'name="attachment_part[]"') === 2, 'Forward: "Fwd:" once, the forwarded header, the two attachments ticked');
$mark = last_activity_id();
$r = jact($jp, 'forward', ['mailbox' => $P, 'message' => $k2, 'to' => ['colleague@customer.example'], 'body' => 'FYI', 'body_format' => 'text']);
$j = jbody($r);
ok($r['code'] === 200 && $j['status'] === 'queued', 'mail_forward queues it');
$o5 = (int) $j['record_id'];
$atts = q("SELECT filename, mime_type, byte_size, sha256, storage_path FROM attachments WHERE record_type = 'outbound_email' AND record_id = :o ORDER BY id", ['o' => $o5]);
ok(count($atts) === 2 && $atts[0]['filename'] === 'plan.pdf' && (int) $atts[0]['byte_size'] === strlen($pdf) && $atts[0]['sha256'] === hash('sha256', $pdf) && $atts[1]['sha256'] === hash('sha256', $png), 'both attachments copied byte-identical (sha256 of the original bytes)');
ok(file_get_contents(need('ML_STORAGE_DIR') . '/' . $atts[0]['storage_path']) === $pdf, '…and the stored file is the original');
ok(str_contains(orow($o5, 'body_text')['body_text'], '---------- Forwarded message ----------') && str_contains(orow($o5, 'body_text')['body_text'], 'Here are the plans.'), 'the forwarded header and body under the words');
$r = jact($jp, 'forward', ['mailbox' => $P, 'message' => $k2, 'to' => ['colleague@customer.example'], 'body' => 'Without', 'body_format' => 'text', 'attachments_included' => 'no']);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM attachments WHERE record_type = 'outbound_email' AND record_id = :o", ['o' => jbody($r)['record_id']]) === 0, 'attachments_included=no: none');
$r = jact($jp, 'forward', ['mailbox' => $P, 'message' => $k2, 'to' => ['colleague@customer.example'], 'body' => 'One', 'body_format' => 'text', 'attachment_part' => ['2']]);
$a1 = q("SELECT filename FROM attachments WHERE record_type = 'outbound_email' AND record_id = :o", ['o' => jbody($r)['record_id']]);
ok(count($a1) === 1 && $a1[0]['filename'] === 'plan.pdf', 'a chosen part alone');
ok(count(activity('message.forward', $mark)) === 3, 'message.forward logged');
ok(jact($jp, 'reply', ['mailbox' => $P, 'message' => 999999, 'body' => 'x', 'body_format' => 'text'])['code'] === 404, 'a reply to no message: 404');
ok(jact($jd, 'reply', ['mailbox' => $P, 'message' => $k1, 'body' => 'x', 'body_format' => 'text'])['code'] === 404, 'Dana cannot reply from Priya\'s mailbox (404)');

echo "4. Autosave, conflict, a phone's draft\n";
$mark = last_activity_id();
$r = jact($jp, 'draft-save', ['mailbox' => $P, 'to' => ['a@customer.example'], 'subject' => 'Saved' . $RUN, 'body' => 'first', 'body_format' => 'text', 'mode' => 'new']);
$j = jbody($r);
ok($r['code'] === 200 && $j['ok'] === true && $j['draft'] > 0 && $j['version'] !== '', 'the first save makes the draft and answers its id and version');
$d1 = (int) $j['draft']; $v1 = $j['version'];
$r = jact($jp, 'draft-save', ['mailbox' => $P, 'draft' => $d1, 'version' => $v1, 'to' => ['a@customer.example'], 'subject' => 'Saved' . $RUN, 'body' => 'second', 'body_format' => 'text', 'mode' => 'new']);
$v2 = jbody($r)['version'];
$r = jact($jp, 'draft-save', ['mailbox' => $P, 'draft' => $d1, 'version' => $v2, 'to' => ['a@customer.example', 'b@customer.example'], 'subject' => 'Saved' . $RUN, 'body' => 'third', 'body_format' => 'text', 'mode' => 'new']);
$v3 = jbody($r)['version'];
ok($v1 !== $v2 && $v2 !== $v3 && one('SELECT body_text FROM mail_drafts WHERE id = :d', ['d' => $d1]) === 'third' && (int) one("SELECT count(*) FROM mail_drafts WHERE subject = 'Saved$RUN'") === 1, 'three saves: one row, the version moving each time');
ok(count(activity('draft.save', $mark)) === 1, 'draft.save logged ONCE (when the draft was made)');
$r = jact($jp, 'draft-save', ['mailbox' => $P, 'draft' => $d1, 'version' => $v1, 'to' => ['a@customer.example'], 'subject' => 'Saved' . $RUN, 'body' => 'stale', 'body_format' => 'text', 'mode' => 'new']);
ok($r['code'] === 409 && str_contains($r['body'], 'newer version') && one('SELECT body_text FROM mail_drafts WHERE id = :d', ['d' => $d1]) === 'third', 'a save carrying an older version: 409 conflict, nothing overwritten');
ok(wait_until(static fn (): bool => one('SELECT uid IS NOT NULL FROM mail_drafts WHERE id = :d', ['d' => $d1]) === true || one('SELECT uid IS NOT NULL FROM mail_drafts WHERE id = :d', ['d' => $d1]) === '1' || one('SELECT uid FROM mail_drafts WHERE id = :d', ['d' => $d1]) > 0, 20), 'the Drafts loop filed it in the Drafts folder (its UID recorded)');
$r = page($jp, "/m/$P/drafts/$d1");
ok($r['code'] === 200 && str_contains($r['body'], 'data-screen="draft-edit"') && str_contains($r['body'], 'third') && str_contains($r['body'], 'name="to[]" value="b@customer.example"'), 'the draft opens in draft-edit with its text and recipients');
$r = page($jp, "/m/$P/v/drafts");
ok($r['code'] === 200 && str_contains($r['body'], 'Saved' . $RUN), 'the Drafts view lists it');
$r = jact($jp, 'draft-save', ['mailbox' => $P, 'draft' => $d1, 'version' => $v3, 'to' => ['a@customer.example'], 'subject' => 'Saved' . $RUN, 'body' => 'fourth', 'body_format' => 'text', 'mode' => 'new', 'close' => '1']);
ok($r['code'] === 200 && jbody($r)['filed'] === true, 'close=1 (leaving the composer) files it synchronously');
ok(page($jd, "/m/$P/drafts/$d1")['code'] === 404, 'Dana cannot open Priya\'s draft');
$phone = trim((string) @file_get_contents("$STATE/phone-draft.txt"));
$pk = $phone !== '' ? (int) one('SELECT message_key_id FROM mail_messages WHERE mailbox_id = :mb AND message_id_header = :m', ['mb' => $P, 'm' => $phone]) : 0;
$r = page($jp, "/m/$P/compose?key=$pk");
ok($pk > 0 && $r['code'] === 302 && preg_match('#/m/' . $P . '/drafts/(\d+)#', $r['location'], $pm) === 1, 'a draft written on a phone (in Drafts by the sync) opens by its key: a row of our own, then draft-edit');
$r = page($jp, $r['location'] ?? '/');
ok($r['code'] === 200 && str_contains($r['body'], 'Typed on the train') && str_contains($r['body'], 'friend@outside.example'), '…with the phone\'s text and recipient');
ok(page($jp, "/m/$P/compose?key=$pk")['location'] === ($pm[0] ?? '-'), '…and the same row the second time (one row per draft key)');
$r = jact($jp, 'draft-discard', ['mailbox' => $P, 'draft' => $d1]);
ok($r['code'] === 200 && one('SELECT discarded_at IS NOT NULL FROM mail_drafts WHERE id = :d', ['d' => $d1]) && page($jp, "/m/$P/drafts/$d1")['code'] === 404, 'mail_draft_discard: gone from the composer');
ok(count(activity('draft.discard', $mark)) === 1, '…logged');

echo "5. Attachments\n";
$d2 = (int) jbody(jact($jp, 'draft-save', ['mailbox' => $P, 'to' => ['a@customer.example'], 'subject' => 'Files' . $RUN, 'body' => 'x', 'body_format' => 'text', 'mode' => 'new']))['draft'];
$seven = str_repeat('7', 7 * 1024 * 1024);
$r = upload($jp, $P, $d2, 'seven.bin', $seven);
$j = jbody($r);
ok($r['code'] === 200 && ($j['ok'] ?? false) && count($j['attachments']) === 1 && $j['attachments'][0]['size'] === 7 * 1024 * 1024, 'a 7 MB file is accepted');
$a = q("SELECT storage_path, sha256 FROM attachments WHERE record_type = 'mail_draft' AND record_id = :d", ['d' => $d2])[0];
ok(file_exists(need('ML_STORAGE_DIR') . '/' . $a['storage_path']) && $a['sha256'] === hash('sha256', $seven) && !str_starts_with($a['storage_path'], '/'), '…stored outside the web root under its hash');
$r = upload($jp, $P, $d2, 'tiny.bin', 'x');
ok($r['code'] === 422 && str_contains($r['body'], 'at most'), 'a second file pushing the total over the limit is refused with the limit named');
$d3 = (int) jbody(jact($jp, 'draft-save', ['mailbox' => $P, 'to' => ['a@customer.example'], 'subject' => 'Files2' . $RUN, 'body' => 'x', 'body_format' => 'text', 'mode' => 'new']))['draft'];
$r = upload($jp, $P, $d3, 'big.bin', $seven . '1');
ok($r['code'] === 422 && str_contains($r['body'], '7 MB'), '7 MB + 1 byte is refused');
$r = upload($jd, $P, $d2, 'dana.bin', 'x');
ok($r['code'] === 404, 'Dana cannot attach to Priya\'s draft');
$aid = (int) one("SELECT id FROM attachments WHERE record_type = 'mail_draft' AND record_id = :d", ['d' => $d2]);
$r = req('POST', "/m/$P/drafts/$d2/files?remove=$aid", ['jar' => $jp, 'headers' => ['Accept: application/json'], 'form' => ['csrf_token' => page_csrf($jp), 'mailbox' => $P, 'draft' => $d2]]);
ok($r['code'] === 200 && (int) one("SELECT count(*) FROM attachments WHERE record_type = 'mail_draft' AND record_id = :d", ['d' => $d2]) === 0 && !file_exists(need('ML_STORAGE_DIR') . '/' . $a['storage_path']), 'remove takes the row and the file');
$r = upload($jp, $P, $d2, 'small.txt', 'hello', 'text/plain');
$r = jact($jp, 'send', ['mailbox' => $P, 'draft' => $d2, 'to' => ['a@customer.example'], 'subject' => 'Files' . $RUN, 'body' => 'with a file', 'body_format' => 'text']);
$o6 = (int) jbody($r)['record_id'];
ok($r['code'] === 200 && one("SELECT has_attachments FROM outbound_email WHERE id = $o6") && (int) one("SELECT count(*) FROM attachments WHERE record_type = 'outbound_email' AND record_id = $o6") === 1, 'a send carries the draft\'s attachment (the row moves to the message)');
ok(wait_until(static fn (): bool => orow($o6)['status'] === 'sent', 25) && str_contains((string) file_get_contents(need('ML_SMTP_SPOOL') . '/index.jsonl'), orow($o6, 'message_id')['message_id']), '…and it went out');

echo "6. An agent's token\n";
kernel_state(static function (array $s): array {
    $s['facts']['9401'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 9401, 'endpoints' => [['application' => 'malumail']]];
    $s['facts']['9402'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 9402, 'requested_by_member_id' => 26, 'requested_by_kind' => 'human', 'endpoints' => [['application' => 'malumail']]];
    return $s;
});
$r = tact(40, 'send', ['mailbox' => $E, 'to' => ['someone@customer.example'], 'subject' => 'Agent' . $RUN, 'body' => 'An answer.', 'body_format' => 'text'], 9401);
ok($r['code'] === 422 && str_contains($r['body'], 'purpose'), 'the Expert\'s mail_send without a purpose: refused');
$r = tact(40, 'send', ['mailbox' => $E, 'to' => ['someone@customer.example'], 'subject' => 'Agent' . $RUN, 'body' => 'An answer.', 'body_format' => 'text', 'purpose' => 'answer the customer'], 9401);
$j = jbody($r);
ok($r['code'] === 200 && $j['status'] === 'held' && !isset($j['undo_id']) && str_contains($j['did'], 'Held for review'), 'with a purpose under hold_all: held, no undo window, the reasons said', $r['body']);
$o7 = (int) $j['record_id'];
ok((int) one("SELECT agent_run_id FROM outbound_email WHERE id = $o7") === 9401 && one("SELECT sender_is_agent FROM outbound_email WHERE id = $o7"), 'the row carries the run id and sender_is_agent');
$r = tact(40, 'send-cancel', ['mailbox' => $E, 'outbound' => $o7], 9401);
ok($r['code'] === 200 && orow($o7)['status'] === 'cancelled', 'the agent withdraws its own held message');
$r = tact(40, 'identity-save', ['mailbox' => $E, 'address' => 'expert@business.example', 'display_name' => 'Bot'], 9401);
ok($r['code'] === 403, 'mail_identity_save from an agent token: refused (people only)');
ok(tact(40, 'identity-delete', ['mailbox' => $E, 'identity' => 1], 9401)['code'] === 403, 'mail_identity_delete too');
$r = tact(40, 'send', ['mailbox' => $P, 'to' => ['someone@customer.example'], 'subject' => 'As Priya' . $RUN, 'body' => 'x', 'body_format' => 'text', 'purpose' => 'help'], 9401);
ok(in_array($r['code'], [403, 404], true), 'the Expert in its own run cannot send from Priya\'s mailbox');
su("UPDATE mailboxes SET ai_read = true WHERE id = $P");
$r = tact(40, 'draft-save', ['mailbox' => $P, 'to' => ['someone@customer.example'], 'subject' => 'Drafted for Priya' . $RUN, 'body' => 'A draft for you.', 'body_format' => 'text', 'mode' => 'new'], 9402);
ok($r['code'] === 200 && (int) one("SELECT member_id FROM mail_drafts WHERE subject = 'Drafted for Priya$RUN'") === 40, 'the Expert in Priya\'s chat run (ai_read on) saves a draft in her mailbox');
$r = tact(40, 'send', ['mailbox' => $P, 'to' => ['someone@customer.example'], 'subject' => 'Sent for Priya' . $RUN, 'body' => 'x', 'body_format' => 'text', 'purpose' => 'help'], 9402);
ok($r['code'] === 403, '…and is refused Send there (triage at most)');
ok(wait_until(static fn (): bool => str_contains(page($jp, "/m/$P/v/drafts")['body'], 'Drafted for Priya' . $RUN), 20), 'Priya sees the agent\'s draft in her Drafts (once the Drafts loop filed it)');
$r = tact(26, 'send', ['mailbox' => $P, 'to' => ['t@customer.example'], 'subject' => 'Token send' . $RUN, 'body' => 'x', 'body_format' => 'text']);
ok($r['code'] === 200 && jbody($r)['status'] === 'queued', 'a person\'s action token sends as the person (the kernel\'s command bar)');
jact($jp, 'send-cancel', ['mailbox' => $P, 'outbound' => (int) jbody($r)['record_id']]);
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
foreach (['mail_draft_save', 'mail_draft_discard', 'mail_send', 'mail_reply', 'mail_forward', 'mail_send_cancel', 'mail_outbox_retry', 'mail_identity_save', 'mail_identity_delete'] as $a) {
    ok(($reg[$a]['built'] ?? false) === true, "  $a is built in the registry");
}
ok($reg['mail_identity_save']['agent'] === false && $reg['mail_identity_delete']['agent'] === false && ($reg['mail_identity_delete']['confirm'] ?? false) === true, 'identities are people-only; delete confirms');

echo "7. Identities\n";
$r = page($jp, "/m/$P/identities/new");
ok($r['code'] === 200 && str_contains($r['body'], 'data-screen="identity-new"') && str_contains($r['body'], 'name="address"') && str_contains($r['body'], 'id="identity-signature-editor"'), 'the identity form (identity-new)');
$mark = last_activity_id();
$r = jact($jp, 'identity-save', ['mailbox' => $P, 'address' => 'help@business.example', 'display_name' => 'Help desk']);
ok($r['code'] === 422 && str_contains($r['body'], 'does not deliver'), 'an alias MaluMail does not deliver here: refused');
$mc = mcp_admin('create_alias', ['local_part' => 'help', 'domain' => 'business.example', 'forwards_to' => [$PRIYA]]);
ok(($mc['result']['isError'] ?? true) === false, 'the fake Mailbox MCP forwards help@ to Priya (the Mail admin\'s doing)');
$r = jact($jp, 'identity-save', ['mailbox' => $P, 'address' => 'help@business.example', 'display_name' => 'Help desk', 'reply_to_address' => 'priya@business.example', 'signature' => '<p>Help desk<script>alert(1)</script><br>Priya</p>']);
$j = jbody($r);
ok($r['code'] === 200 && ($j['ok'] ?? false) === true, 'the alias is saved once MaluMail delivers it', $r['code'] . ' ' . substr($r['body'], 0, 300));
$i2 = (int) $j['record_id'];
$row = q('SELECT kind, display_name, reply_to, signature_html, signature_text, is_default, checked_at FROM mail_identities WHERE id = :i', ['i' => $i2])[0];
ok($row['kind'] === 'alias' && $row['reply_to'] === 'priya@business.example' && !str_contains($row['signature_html'], 'script') && str_contains($row['signature_html'], 'Help desk') && $row['signature_text'] === "Help desk\nPriya" && $row['checked_at'] !== null, 'kind alias, the signature sanitised (no script) with its text, the check recorded', json_encode($row));
ok(count(activity('identity.save', $mark)) === 1 && !str_contains(activity('identity.save', $mark)[0]['after'], 'Help desk'), 'identity.save logged without the signature');
$r = page($jp, "/m/$P/compose");
ok(str_contains($r['body'], 'help@business.example') && str_contains($r['body'], '(alias)'), 'the composer offers the alias in From');
$r = page($jp, "/m/$P/identities/$i2/edit");
ok($r['code'] === 200 && str_contains($r['body'], 'data-screen="identity-edit"') && str_contains($r['body'], 'id="identity-delete"') && str_contains($r['body'], 'hx-confirm'), 'identity-edit with Delete (confirmed)');
$r = jact($jp, 'identity-delete', ['mailbox' => $P, 'identity' => 1]);
ok($r['code'] === 422 && str_contains($r['body'], 'own address'), 'the own identity is never deleted');
jact($jp, 'identity-save', ['mailbox' => $P, 'identity' => $i2, 'is_default' => 'yes']);
ok(one('SELECT is_default FROM mail_identities WHERE id = 1') == false && one('SELECT is_default FROM mail_identities WHERE id = :i', ['i' => $i2]) == true, 'making the alias default unmakes the other');
$r = jact($jp, 'identity-delete', ['mailbox' => $P, 'identity' => $i2]);
ok($r['code'] === 422 && str_contains($r['body'], 'another default'), 'the default is not deleted while another is not chosen');
jact($jp, 'identity-save', ['mailbox' => $P, 'identity' => 1, 'is_default' => 'yes']);
$r = jact($jp, 'identity-delete', ['mailbox' => $P, 'identity' => $i2]);
ok($r['code'] === 200 && one('SELECT count(*) FROM mail_identities WHERE id = :i', ['i' => $i2]) == 0, 'then deleted');
ok(jact($jd, 'identity-save', ['mailbox' => $S, 'address' => 'sales@business.example', 'display_name' => 'x'])['code'] === 403, 'Dana (view) cannot manage Sales\' identities');
ok(page($jd, "/m/$S/identities/new")['code'] === 403, '…nor open the form');
$r = jact($jp, 'send', ['mailbox' => $P, 'to' => ['a@customer.example'], 'subject' => 'Wrong identity', 'body' => 'x', 'body_format' => 'text', 'identity' => 2]);
ok($r['code'] === 422 && str_contains($r['body'], 'identity'), 'an identity of another mailbox is refused');

echo "8. The Outbox: retry\n";
$o8 = (int) one("INSERT INTO outbound_email (mailbox_id, sender_member_id, to_address, to_addresses, subject, body_text, message_id, status, send_after, attempts, body_digest, error)
                  VALUES ($P, 26, 'r@customer.example', ARRAY['r@customer.example'], 'Retry me$RUN', 'x', '<retry-' || md5('$RUN') || '@business.example>', 'queued', now() + interval '1 hour', 0, md5('r$RUN'), NULL) RETURNING id");
su("UPDATE outbound_email SET status = 'sending' WHERE id = $o8; UPDATE outbound_email SET status = 'failed', error = 'a proof failure' WHERE id = $o8");
$r = page($jp, "/m/$P/v/outbox");
ok($r['code'] === 200 && str_contains($r['body'], 'Retry me' . $RUN) && preg_match('/action="\/mail\/outbox-retry"[^>]*>.*?name="outbound" value="' . $o8 . '"/s', $r['body']) === 1, 'a failed message on the Outbox with a Retry form');
$mark = last_activity_id();
$r = act($jp, 'outbox-retry', ['mailbox' => $P, 'outbound' => $o8, 'return_to' => "/m/$P/v/outbox"]);
ok($r['code'] === 302 && in_array(orow($o8)['status'], ['queued', 'sending', 'sent'], true) && count(activity('outbound.retry', $mark)) === 1, 'Retry queues it again and logs outbound.retry');
ok(wait_until(static fn (): bool => orow($o8)['status'] === 'sent', 25), '…and it goes');
ok(jact($jd, 'outbox-retry', ['mailbox' => $P, 'outbound' => $o8])['code'] === 404, 'Dana cannot retry Priya\'s');

finish();
