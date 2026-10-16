<?php
/**
 * Slice 5's page and action proof (oversight.md Proof 1–9; sending-and-oversight.md §7–§9, §12): every hold reason from a REAL agent Send
 * (an action token for the Expert's run) shown on the review page with its sentence; the refusals; the five decisions through the pages
 * and their effects in the SMTP spool and on the fake kernel's chat endpoint (ids and a person's words, never mail text); agents never
 * decide (each people action with an agent's token → 403, the SQL function as the agent → refused); a person's mailbox; expiry; the four
 * kinds of Unscreened and their four answers; pause and resume; the policy. Run by tests/phase3/slice5/run.sh. The fixtures are driven
 * through their control doors; the worker's passes are run by hand.
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

$CTL = need('ML_IMAP_CONTROL');
$STATE = need('ML_DEV_STATE');
$SPOOL = need('ML_SMTP_SPOOL');
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
/** One message into a fixture INBOX, waited for in the index: [id, thread, key, Message-ID]. $o: from, dmarc (bool), headers, subject_exact */
function deliver(string $user, string $subject, string $text, array $o = []): array
{
    global $RUN;
    $subject = !empty($o['subject_exact']) ? $subject : $subject . $RUN;
    $mid = '<' . bin2hex(random_bytes(8)) . '@outside.example>';
    $from = $o['from'] ?? 'Outside Person <someone@outside.example>';
    $h = "From: $from\r\nTo: $user\r\nSubject: $subject\r\nDate: " . date(DATE_RFC2822) . "\r\nMessage-ID: $mid\r\nMIME-Version: 1.0\r\n";
    if ($o['dmarc'] ?? true) { $h .= 'X-Spamd-Result: ' . DMARC . "\r\n"; }
    foreach ($o['headers'] ?? [] as $k => $v) { $h .= "$k: $v\r\n"; }
    ctl(['op' => 'deliver', 'user' => $user, 'raw' => $h . "Content-Type: text/plain; charset=utf-8\r\n\r\n" . $text]);
    $mb = mb(explode('@', $user)[0]);
    if (!wait_until(static fn (): bool => one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :mb AND message_id_header = :m', ['mb' => $mb, 'm' => $mid]) > 0)) { throw new RuntimeException("not indexed: $subject"); }
    $r = q('SELECT id, thread_id, message_key_id FROM mail_messages WHERE mailbox_id = :mb AND message_id_header = :m', ['mb' => $mb, 'm' => $mid])[0];
    return [(int) $r['id'], (int) $r['thread_id'], (int) $r['message_key_id'], $mid];
}
/** A person's form POST to an oversight handler (CSRF from the page), as HTMX or a plain browser, or JSON. */
function oact(string $jar, string $action, array $form, string $mode = 'form'): array
{
    $form += ['csrf_token' => page_csrf($jar)];
    $h = $mode === 'json' ? ['Accept: application/json'] : ($mode === 'htmx' ? ['HX-Request: true'] : []);
    return req('POST', (str_starts_with($action, '/') ? '' : '/oversight/') . $action, ['jar' => $jar, 'form' => $form, 'headers' => $h]);
}
/** An agent's call under its run's action token (or a person's token when $run is null). */
function tact(int $member, string $path, array $form, ?int $run = 9501): array
{
    $tok = $run === null ? person_token($member) : run_token($member, $run);
    return req('POST', $path, ['headers' => $run === null ? ['Accept: application/json', 'X-Action-Token: ' . $tok] : as_agent($tok), 'form' => $form]);
}
function jbody(array $r): array { return json_decode($r['body'], true) ?: []; }
function orow(int $id, string $cols = 'status'): array { return q("SELECT $cols FROM outbound_email WHERE id = :id", ['id' => $id])[0] ?? []; }
function reasons(int $id): array { return ml_arr((string) one('SELECT hold_reasons FROM outbound_email WHERE id = :id', ['id' => $id])); }
function ml_arr(string $t): array { $t = trim($t, '{}'); return $t === '' ? [] : array_map(static fn ($x) => trim($x, '"'), explode(',', $t)); }
$N = 0;
/** The Expert sends (mail_send under its run token); every body unique (same_answer is a refusal). */
function asend(int $mb, array $to, array $extra = [], string $path = '/mail/send'): array
{
    global $RUN, $N;
    $N++;
    $r = tact(40, $path, $extra + ['mailbox' => $mb, 'to' => $to, 'subject' => "Agent note $N" . $RUN, 'body' => "Body $N of run $RUN.", 'body_format' => 'text', 'purpose' => 'answer the customer']);
    return [$r['code'], jbody($r), $r['body']];
}
function policy(string $set): void { su("UPDATE agent_mail_policies SET $set WHERE mailbox_id = " . mb('expert')); }
function chat_lines(): array
{
    $f = need('FAKE_KERNEL_STATE') . '.chat';
    return is_file($f) ? array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES)))) : [];
}
function worker(string $passes): array
{
    $out = (string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=' . escapeshellarg($passes) . ' 2>&1');
    return json_decode(trim((string) strrchr("\n" . trim($out), "\n")), true) ?: ['raw' => $out];
}
function spooled(string $mid): ?string
{
    global $SPOOL;
    if (!is_file("$SPOOL/index.jsonl")) { return null; }
    foreach (file("$SPOOL/index.jsonl", FILE_IGNORE_NEW_LINES) as $l) {
        $e = json_decode($l, true);
        if (($e['message_id'] ?? null) === $mid) { return (string) file_get_contents("$SPOOL/{$e['n']}.eml"); }
    }
    return null;
}

$RUN = ' #' . substr(bin2hex(random_bytes(3)), 0, 6);
$P = mb('priya'); $D = mb('dana'); $S = mb('sales'); $E = mb('expert'); $A = mb('ada');
[$jp] = sign_on(26); [$jd] = sign_on(30); [$jo] = sign_on(1);
kernel_state(static function (array $s): array {
    $s['facts']['9501'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 9501, 'endpoints' => [['application' => 'malumail']]];
    $s['facts']['9502'] = ['valid' => true, 'is_agent' => true, 'member_id' => 42, 'run_id' => 9502, 'endpoints' => [['application' => 'malumail']]];
    $s['chat'] = ['run_id' => 7001, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Noted; I will ask them for a date instead.', 'actions' => []];
    return $s;
});
// the base for isolating one reason at a time: nothing held but what each step switches on
su('UPDATE ml_settings SET agent_external_budget = 1000, hold_all_agents = false, hold_all_external = false WHERE id = 1');
policy("send_mode = 'auto', first_contact_review = false, attachments_review = false, max_per_hour = 1000, max_external_per_day = 1000, max_per_thread_per_day = 100");

echo "1. Every hold reason from a real agent Send, each on the review page with its sentence\n";
$held = [];
$expect = static function (string $reason, array $res, string $label, string $jar, string $fragment) use (&$held): void {
    [$code, $j] = $res;
    $id = (int) ($j['record_id'] ?? 0);
    $rs = $id > 0 ? reasons($id) : [];
    ok($code === 200 && ($j['status'] ?? '') === 'held' && in_array($reason, $rs, true), "$label → held with $reason", json_encode($j) . ' ' . json_encode($rs));
    $page = page($jar, '/oversight/review/' . $id);
    ok($page['code'] === 200 && str_contains($page['body'], 'id="review-reason-' . $reason . '"') && str_contains($page['body'], $fragment), "  review-view shows $reason with its sentence (\"$fragment\")", substr(strip_tags($page['body']), 0, 200));
    $held[$reason] = $id;
};
policy("send_mode = 'hold_all'");
$expect('mode', asend($E, ['colleague@business.example']), 'send mode hold_all', $jp, 'holds every message for a person');
policy("send_mode = 'auto', first_contact_review = true");
$expect('first_contact', asend($E, ['newcomer@outside.example']), 'first contact', $jp, 'This mailbox has never written to newcomer@outside.example');
policy('first_contact_review = false, attachments_review = true');
$r = tact(40, '/mail/draft-save', ['mailbox' => $E, 'to' => ['files@outside.example'], 'subject' => 'With a file' . $RUN, 'body' => 'See the file.', 'body_format' => 'text', 'mode' => 'new']);
$draft = (int) (jbody($r)['record_id'] ?? 0);
$tmp = tempnam(sys_get_temp_dir(), 'mlup');
file_put_contents($tmp, "a,b\n1,2\n");
$ch = curl_init(BASE . "/m/$E/drafts/$draft/files");
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => as_agent(run_token(40, 9501)),
                        CURLOPT_POSTFIELDS => ['mailbox' => $E, 'draft' => $draft, 'file' => new CURLFile($tmp, 'text/csv', 'figures.csv')]]);
curl_exec($ch);
$upCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
unlink($tmp);
ok($draft > 0 && $upCode === 200, 'the Expert saves a draft and attaches a file through the draft\'s door (its token)', "draft $draft upload $upCode");
$expect('attachment', asend($E, ['files@outside.example'], ['draft' => $draft]), 'attachments review', $jp, 'policy reviews attachments');
ok(str_contains(page($jp, '/oversight/review/' . $held['attachment'])['body'], 'figures.csv') && str_contains(page($jp, '/oversight/review/' . $held['attachment'])['body'], 'sha256'), '  the attachment listed with its name and digest');
policy('attachments_review = false, max_per_hour = 0');
$expect('over_hour', asend($E, ['colleague@business.example']), 'the hour\'s cap', $jp, '0 messages an hour');
policy('max_per_hour = 1000, max_external_per_day = 0');
$expect('over_day', asend($E, ['far@outside.example']), 'the day\'s outside cap', $jp, 'messages leaving the business a day');
policy('max_external_per_day = 1000, max_per_thread_per_day = 1');
[$m1, $t1, $k1] = deliver('expert@business.example', 'Thread for caps', 'Please confirm.', ['from' => 'Cap Person <cap@outside.example>']);
[$c, $j] = asend($E, [], ['message' => $k1, 'quote' => 'no'], '/mail/reply');
ok($c === 200 && ($j['status'] ?? '') === 'queued', 'a first reply in a conversation goes (auto)', json_encode($j));
$expect('over_thread', asend($E, [], ['message' => $k1, 'quote' => 'no'], '/mail/reply'), 'the conversation\'s cap (the second reply)', $jp, 'messages a day in this conversation');
policy('max_per_thread_per_day = 100');
su('UPDATE ml_settings SET agent_external_budget = 0 WHERE id = 1');
$expect('over_business_budget', asend($E, ['budget@outside.example']), 'the business budget', $jp, 'budget of 0 messages');
su('UPDATE ml_settings SET agent_external_budget = 1000 WHERE id = 1');
su("SELECT ml_mailbox_pause($E, 26, true, 'checking the paused reason')");
$expect('paused', asend($E, ['colleague@business.example']), 'a paused mailbox', $jp, 'The mailbox is paused by SMOKE Priya');
su("SELECT ml_mailbox_pause($E, 26, false)");
su('UPDATE ml_settings SET hold_all_agents = true WHERE id = 1');
$expect('hold_all_agents', asend($E, ['colleague@business.example']), 'the switch holding every agent', $jp, 'A Mail admin holds every agent');
su('UPDATE ml_settings SET hold_all_agents = false, hold_all_external = true WHERE id = 1');
$expect('hold_all_external', asend($E, ['far2@outside.example']), 'the switch holding outside mail', $jp, 'mail leaving the business.');
su('UPDATE ml_settings SET hold_all_external = false WHERE id = 1');
$expect('on_behalf', asend($D, ['someone@outside.example']), 'an agent writing from Dana\'s own mailbox (D12)', $jd, 'its owner decides');
[$c, $j] = asend($S, ['someone@outside.example']);
$shared = (int) ($j['record_id'] ?? 0);
ok($c === 200 && reasons($shared) === ['mode'], 'an agent writing from the shared Sales mailbox → held with mode', json_encode(reasons($shared)));
ok(str_contains(page($jp, "/oversight/review/$shared")['body'], 'An agent wrote from a shared mailbox'), '  its sentence names the shared mailbox\'s people');
$pg = page($jp, '/oversight/review');
ok($pg['code'] === 200 && str_contains($pg['body'], 'data-screen="review-queue"') && str_contains($pg['body'], 'id="review-row-' . $held['mode'] . '"') && str_contains($pg['body'], 'data-reason="first_contact"'), 'the queue lists them with their reasons as chips (review-queue)');
ok(preg_match('/review-row-' . $held['first_contact'] . '.*?text-warning fw-semibold[^>]*>newcomer@outside\.example<\/span> <span class="badge bg-soft-info text-info">new/s', $pg['body']) === 1, '  an outside recipient amber and a new one marked new');
ok(str_contains($pg['body'], 'expires in 7') || str_contains($pg['body'], 'expires in 71'), '  each with its time left', '');
$pf = page($jp, '/oversight/review?reason=over_hour');
ok(str_contains($pf['body'], 'review-row-' . $held['over_hour']) && !str_contains($pf['body'], 'review-row-' . $held['mode'] . '"'), '  filtered by reason');
$pf = page($jp, '/oversight/review?mailbox=' . $S);
ok(str_contains($pf['body'], 'review-row-' . $shared) && !str_contains($pf['body'], 'review-row-' . $held['mode'] . '"'), '  filtered by mailbox');
$poll = req('GET', '/oversight/review', ['jar' => $jp, 'headers' => ['HX-Request: true', 'HX-Target: review-rows']]);
ok($poll['code'] === 200 && str_contains($poll['body'], 'id="review-list"') && !str_contains($poll['body'], 'review-queue-header'), 'the 30-second poll answers the rows alone');
$jq = jbody(req('GET', '/oversight/review', ['jar' => $jp, 'headers' => ['Accept: application/json']]));
ok(count($jq['data']['held'] ?? []) >= 11 && isset($jq['data']['held'][0]['hold_reasons']), 'JSON: the held rows with their reasons', json_encode(array_slice($jq['data']['held'] ?? [], 0, 1)));
$jv = jbody(req('GET', '/oversight/review/' . $held['first_contact'], ['jar' => $jp, 'headers' => ['Accept: application/json']]));
ok(($jv['data']['review']['reasons'][0]['sentence'] ?? '') !== '' && ($jv['data']['review']['purpose'] ?? '') === 'answer the customer' && ($jv['data']['review']['run_id'] ?? null) === 9501, 'JSON review-view: the reasons\' sentences, the purpose, the run');

echo "2. Refusals: the loop rules and the same answer\n";
foreach ([['auto-replied', ['Auto-Submitted' => 'auto-replied']], ['a list', ['List-Id' => '<news.outside.example>']], ['a null return path', ['Return-Path' => '<>']]] as [$what, $hdrs]) {
    [, , $k] = deliver('expert@business.example', "Machine $what", 'Automatic.', ['headers' => $hdrs, 'from' => 'Robot <robot@outside.example>']);
    [$c, $j] = asend($E, [], ['message' => $k, 'quote' => 'no'], '/mail/reply');
    ok($c === 200 && ($j['status'] ?? '') === 'refused' && ($j['rule'] ?? '') === 'loop_rule' && ($j['did'] ?? '') !== '', "a reply to $what → refused, loop_rule, said to the agent", json_encode($j));
}
$r1 = tact(40, '/mail/send', ['mailbox' => $E, 'to' => ['same@outside.example'], 'subject' => 'Same' . $RUN, 'body' => 'The very same words' . $RUN, 'body_format' => 'text', 'purpose' => 'answer']);
$r2 = tact(40, '/mail/send', ['mailbox' => $E, 'to' => ['same@outside.example'], 'subject' => 'Same again' . $RUN, 'body' => 'The very same words' . $RUN, 'body_format' => 'text', 'purpose' => 'answer']);
ok(jbody($r1)['status'] === 'queued' && jbody($r2)['status'] === 'refused' && jbody($r2)['rule'] === 'same_answer' && jbody($r2)['did'] !== '', 'the same body to the same recipient twice → same_answer, said to the agent', $r2['body']);
ok(count(activity('outbound.refuse')) >= 4, '…each logged outbound.refuse');

echo "3. The five decisions as a person\n";
$mark = last_activity_id();
// approve: the first contact
$o = $held['first_contact'];
$r = oact($jp, 'approve', ['outbound' => $o]);
ok($r['code'] === 302 && preg_match('#^/oversight/review(/\d+)?\?notice=approved#', $r['location']) === 1, 'Approve (no JavaScript): 302 to the next held message (decision 4)', $r['location']);
$row = orow($o, 'status, auto_submitted, message_id');
ok($row['status'] === 'queued' && $row['auto_submitted'] === null, '…queued with Auto-Submitted dropped');
ok(wait_until(static fn (): bool => orow($o)['status'] === 'sent', 30), '…sent when its window passed');
$eml = (string) spooled((string) $row['message_id']);
ok($eml !== '' && stripos($eml, "\nX-OS-Agent: ") !== false && stripos($eml, "\nAuto-Submitted:") === false, 'the spool: X-OS-Agent present, Auto-Submitted absent', substr($eml, 0, 400));
ok(one("SELECT ml_is_known($E, 'newcomer@outside.example')") === true, '…and the recipient is now known to the mailbox (a person approved)');
ok(count(activity('review.approve', $mark)) === 1 && !str_contains((string) activity('review.approve', $mark)[0]['after'], 'Body '), 'review.approve logged, never the body');
// edit: over_hour's message, through the page's form
$o = $held['over_hour'];
$ef = page($jp, "/oversight/review/$o/edit");
ok($ef['code'] === 200 && str_contains($ef['body'], 'data-screen="review-edit"') && str_contains($ef['body'], 'id="review-edit-editor"') && str_contains($ef['body'], 'squire.js') && str_contains($ef['body'], 'Recipients are not edited here'), 'review-edit: the editor, the recipients read-only');
$before = orow($o, 'body_text, message_id');
$r = oact($jp, 'edit', ['outbound' => $o, 'subject' => 'Edited subject' . $RUN, 'body' => "A reviewer's better words.\nSecond line.", 'body_format' => 'text']);
ok($r['code'] === 302, 'Save and send (mail_review_edit)', $r['body']);
ok(wait_until(static fn (): bool => orow($o)['status'] === 'sent', 30), '…sent');
$eml = (string) spooled((string) $before['message_id']);
ok(str_contains($eml, "A reviewer's better words.") && !str_contains($eml, $before['body_text']) && str_contains($eml, 'Edited subject'), 'the spool carries the new text, not the agent\'s');
$dv = page($jp, "/oversight/review/$o");
ok(str_contains($dv['body'], 'id="review-decided"') && str_contains($dv['body'], 'data-op="removed"') && str_contains($dv['body'], 'data-op="added"') && str_contains($dv['body'], 'better words'), 'the history shows the diff before → after');
$ed = activity('review.edit', $mark);
$ea = json_decode((string) ($ed[0]['after'] ?? '{}'), true);
ok(count($ed) === 1 && strlen((string) ($ea['before_digest'] ?? '')) > 10 && ($ea['before_digest'] ?? '') !== ($ea['after_digest'] ?? '') && !str_contains($ed[0]['after'], 'better words'), 'review.edit logs the two digests, never the text');
// reject and reply → one turn each, the note and ids, no mail text
$chat0 = count(chat_lines());
$o = $held['over_day'];
$bodyText = (string) orow($o, 'body_text')['body_text'];
$r = oact($jp, 'reject', ['outbound' => $o, 'note' => "Don't promise a date; ask them for one."], 'json');
ok($r['code'] === 200 && orow($o)['status'] === 'rejected' && (jbody($r)['next'] ?? null) !== null, 'Reject with a note → rejected; JSON names the next held message', $r['body']);
$r = oact($jp, 'reject', ['outbound' => $held['mode']], 'json');
ok($r['code'] === 422 && str_contains($r['body'], 'note'), 'Reject without a note: refused in a sentence', $r['body']);
$o2 = $held['over_business_budget'];
$r = oact($jp, 'reply', ['outbound' => $o2, 'note' => 'Which order is this about?'], 'json');
ok($r['code'] === 200 && orow($o2)['status'] === 'held' && orow($o2, 'review_id')['review_id'] !== null, 'Reply to the agent → it stays held, the review recorded');
$w = worker('dispatches');
$turns = array_slice(chat_lines(), $chat0);
$rej = array_values(array_filter($turns, static fn ($t) => str_contains((string) $t['utterance'], "outbound $o)")));
$rep = array_values(array_filter($turns, static fn ($t) => str_contains((string) $t['utterance'], "outbound $o2)")));
ok(count($rej) === 1 && str_contains($rej[0]['utterance'], "Don't promise a date; ask them for one.") && $rej[0]['agent'] === '40' && $rej[0]['acting'] === '26'
   && ($rej[0]['context']['outbound'] ?? null) === $o && ($rej[0]['context']['decision'] ?? '') === 'reject', 'the reject\'s turn: the note, the outbound id, the Expert, Priya acting', json_encode($turns));
ok(count($rep) === 1 && str_contains($rep[0]['utterance'], 'Which order is this about?') && str_contains($rep[0]['utterance'], 'stays held'), 'the reply\'s turn: the question, the message still held');
ok(!str_contains(json_encode($turns), $bodyText) && !str_contains(json_encode($turns), 'Agent note'), '…no mail text in either turn (no body, no subject)');
ok(one("SELECT count(*) FROM agent_dispatches WHERE kind = 'review' AND record_id IN ($o, $o2) AND status = 'answered' AND run_id = 7001 AND reply_excerpt LIKE 'Noted%'") == 2, 'both dispatches answered with the run id and the agent\'s words (an excerpt)');
ok(count(activity('agent.dispatch', $mark)) >= 2 && count(activity('review.reject', $mark)) === 1 && count(activity('review.reply', $mark)) === 1, 'review.reject, review.reply and agent.dispatch logged');
// take over: a held reply → cancelled, the conversation muted for the agent, a later arrival wakes no one
su("SELECT ml_sender_mark_known($E, 'known@outside.example', 26)");
[$m2, $t2, $k2, $mid2] = deliver('expert@business.example', 'Please quote', 'How much for 40 units?', ['from' => 'Known Person <known@outside.example>']);
ok(one("SELECT decision FROM agent_dispatches WHERE message_key_id = $k2") === 'wake', 'a known sender\'s DMARC-passing mail wakes the Expert (decision wake)');
policy("send_mode = 'hold_all'");
[$c, $j] = asend($E, [], ['message' => $k2, 'quote' => 'no'], '/mail/reply');
$ot = (int) ($j['record_id'] ?? 0);
ok(($j['status'] ?? '') === 'held', 'the Expert\'s reply is held (hold_all)');
$rv = page($jp, "/oversight/review/$ot");
ok(str_contains($rv['body'], 'id="review-take-over"') && str_contains($rv['body'], 'id="review-answers-dmarc"') && str_contains($rv['body'], 'DMARC pass') && str_contains($rv['body'], "/m/$E/t/$t2"), 'its review page offers Take over, shows the conversation it answers and its DMARC');
$r = oact($jp, 'take-over', ['outbound' => $ot, 'mailbox' => $E]);
ok($r['code'] === 302 && orow($ot)['status'] === 'cancelled', 'Take over → cancelled', $r['body']);
ok(one("SELECT agent_muted AND taken_over_by = 26 FROM mail_thread_states s WHERE s.mailbox_id = $E AND s.anchor_key_id IN (SELECT message_key_id FROM mail_messages WHERE thread_id = $t2)") === true, '…the conversation taken over and muted for the agent');
[, , $k3] = deliver('expert@business.example', 'Re: Please quote', 'Any news?', ['from' => 'Known Person <known@outside.example>', 'headers' => ['In-Reply-To' => $mid2, 'References' => $mid2], 'subject_exact' => false]);
ok(one("SELECT decision || '/' || status FROM agent_dispatches WHERE message_key_id = $k3") === 'taken_over/skipped', 'a later arrival in it wakes no one (taken_over, skipped)');
ok(count(activity('thread.take_over', $mark)) === 1, 'thread.take_over logged');
$tp = page($jp, "/m/$E/t/$t2");
ok(str_contains($tp['body'], 'Give back to the agent') && str_contains($tp['body'], 'mail-thread-taken-over'), 'the conversation page shows it taken over, with Give back');
$r = oact($jp, 'take-over', ['mailbox' => $E, 'thread' => $t2, 'taken_over' => 'no']);
ok($r['code'] === 302 && one("SELECT count(*) FROM mail_thread_states s WHERE s.mailbox_id = $E AND (s.agent_muted OR s.taken_over_by IS NOT NULL) AND s.anchor_key_id IN (SELECT message_key_id FROM mail_messages WHERE thread_id = $t2)") == 0, 'Give back: the agent follows the conversation again');
$r = oact($jp, 'take-over', ['mailbox' => $E, 'thread' => $t2], 'json');
ok($r['code'] === 200 && (jbody($r)['taken_over'] ?? null) === true, 'Take over from the conversation page (mailbox + thread)');
oact($jp, 'take-over', ['mailbox' => $E, 'thread' => $t2, 'taken_over' => 'no']);
policy("send_mode = 'auto'");
// mute: an agent may
$r = tact(40, '/mail/mute', ['mailbox' => $E, 'thread' => (string) $t1, 'muted' => 'yes']);
ok($r['code'] === 200 && one("SELECT bool_or(muted) FROM mail_thread_states WHERE mailbox_id = $E AND anchor_key_id IN (SELECT message_key_id FROM mail_messages WHERE thread_id = $t1)") === true, 'mail_mute by the Expert\'s token: the conversation muted (an agent may)', $r['body']);
$r = oact($jp, '/mail/mute', ['mailbox' => $E, 'thread[]' => $t1, 'muted' => 'no']);
ok($r['code'] === 302 && one("SELECT COALESCE(bool_or(muted), false) FROM mail_thread_states WHERE mailbox_id = $E AND anchor_key_id IN (SELECT message_key_id FROM mail_messages WHERE thread_id = $t1)") === false, 'Unmute from the conversation page');
ok(str_contains(page($jp, "/m/$E/t/$t1")['body'], 'id="act-mute"'), 'the conversation page offers Mute');

echo "4. Agents never decide\n";
$victim = $held['mode'];
$before = orow($victim, 'status, review_id');
$people = ['approve' => ['outbound' => $victim], 'edit' => ['outbound' => $victim, 'body' => 'x', 'body_format' => 'text'], 'reject' => ['outbound' => $victim, 'note' => 'no'],
           'reply' => ['outbound' => $victim, 'note' => 'why'], 'take-over' => ['mailbox' => $E, 'thread' => $t1], 'release' => ['mailbox' => $E, 'message' => $k1],
           'dismiss' => ['mailbox' => $E, 'message' => $k1], 'mark-known' => ['mailbox' => $E, 'address' => 'x@outside.example'],
           'policy' => ['mailbox' => $E, 'send_mode' => 'auto'], 'pause' => ['mailbox' => $E, 'reason' => 'x'], 'resume' => ['mailbox' => $E]];
foreach ($people as $h => $form) {
    $r = tact(40, "/oversight/$h", $form);
    ok($r['code'] === 403, "  $h with the Expert's token → 403", $r['code'] . ' ' . substr($r['body'], 0, 120));
}
ok(orow($victim, 'status, review_id') === $before, 'the held message the agent wrote is untouched');
$msg = '';
try { pdo()->query("SELECT ml_review_decide($victim, 40, 'approve')"); } catch (PDOException $e) { $msg = $e->getMessage(); }
ok($msg !== '' && orow($victim)['status'] === 'held', 'ml_review_decide() called as the agent member: refused in SQL (ml_require_person)', $msg);
$msg = '';
try { pdo()->query("SELECT ml_policy_set($E, 40, '{\"send_mode\": \"auto\"}')"); } catch (PDOException $e) { $msg = $e->getMessage(); }
ok($msg !== '', 'ml_policy_set() as the agent: refused', $msg);
$r = tact(42, '/oversight/approve', ['outbound' => $victim], 9502);
ok($r['code'] === 403 && orow($victim)['status'] === 'held', 'the Clerk\'s token is refused a decision too');
$jd2 = page($jd, '/oversight/review');
ok(str_contains($jd2['body'], 'id="review-row-' . $victim . '-not-mine"') && !str_contains($jd2['body'], 'id="review-row-' . $victim . '-open"'), 'Dana (triage on the Expert\'s mailbox, no decider) sees its held mail "For its overseers" with nothing to press');
ok(page($jd, "/oversight/review/$victim")['body'] !== '' && str_contains(page($jd, "/oversight/review/$victim")['body'], 'id="review-not-mine"'), '…and its review page has no buttons');
ok(oact($jd, 'approve', ['outbound' => $victim])['code'] === 403, '…and is refused Approve (403 from the SQL guard)');
foreach (['oversight', 'oversight/review', 'oversight/unscreened', "m/$E/policy"] as $u) {
    $r = req('GET', "/$u", ['headers' => as_agent(run_token(40, 9501))]);
    ok($r['code'] === 403, "  /$u with the agent's token → 403 (people only)", (string) $r['code']);
}

echo "5. A person's mailbox\n";
$ob = $held['on_behalf'];
ok(!str_contains(page($jo, '/oversight/review')['body'], 'review-row-' . $ob . '"'), 'the Mail admin\'s queue does not show what an agent wrote from Dana\'s mailbox');
ok(page($jo, "/oversight/review/$ob")['code'] === 404, '…nor its review page (404)');
ok(str_contains(page($jd, '/oversight/review')['body'], 'id="review-row-' . $ob . '-open"'), 'Dana\'s queue shows it, hers to decide');
ok(str_contains(page($jd, "/m/$D/v/inbox")['body'], 'href="/oversight"'), 'Dana is shown Oversight (held mail of hers waits)');
$r = oact($jd, 'approve', ['outbound' => $ob]);
ok($r['code'] === 302 && orow($ob)['status'] === 'queued', 'Dana approves it');
ok(oact($jo, 'reject', ['outbound' => $shared, 'note' => 'no'])['code'] === 403 || orow($shared)['status'] === 'held', 'the Mail admin decides no shared mailbox\'s mail it does not hold full on');

echo "6. Expiry\n";
$ox = $held['hold_all_agents'];
su("UPDATE outbound_email SET held_at = now() - interval '73 hours' WHERE id = $ox");
$chat0 = count(chat_lines());
$w = worker('held_expiry,dispatches');
ok(($w['ran']['held_expiry']['expired'] ?? 0) >= 1 && orow($ox)['status'] === 'expired', 'a held message aged 73 h → expired by the worker\'s pass', json_encode($w));
ok(one("SELECT count(*) FROM mail_reviews WHERE outbound_id = $ox AND decision = 'expire' AND reviewer_member_id IS NULL") == 1, '…with an expire review (no reviewer)');
$turns = array_slice(chat_lines(), $chat0);
ok(count(array_filter($turns, static fn ($t) => str_contains((string) $t['utterance'], "outbound $ox") && str_contains((string) $t['utterance'], 'expired after 72 hours'))) === 1, 'the agent\'s turn says so', json_encode($turns));
ok(one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'review_decided' AND record_id = $ox") == 1, 'the reviewers\' review_decided notice');
ok(count(activity('review.expire')) >= 1, 'review.expire logged');

echo "7. Unscreened\n";
policy("send_mode = 'auto', wake_from = 'internal_and_known'");
[, , $ku] = deliver('expert@business.example', 'Unknown sender', 'Hello.', ['from' => 'Stranger <stranger@outside.example>']);
[, , $kn] = deliver('expert@business.example', 'No DMARC', 'Hello.', ['from' => 'Unproven <unproven@outside.example>', 'dmarc' => false]);
[, , $ki] = deliver('expert@business.example', 'Looks internal', 'Hello.', ['from' => 'Dana (forged) <dana@business.example>', 'dmarc' => false]);
su("UPDATE agent_mail_policies SET wake_from = 'all_authenticated' WHERE mailbox_id = $A");
[, , $ka] = deliver('ada@business.example', 'For Ada', 'Hello.', ['from' => 'Customer <customer@outside.example>']);
$dec = static fn (int $k): string => (string) one("SELECT decision || '/' || status FROM agent_dispatches WHERE message_key_id = $k");
ok($dec($ku) === 'unknown_sender/unscreened' && $dec($kn) === 'unauthenticated/unscreened' && $dec($ki) === 'internal_unproven/unscreened' && $dec($ka) === 'no_overseer/unscreened',
   'four kinds: unknown_sender, unauthenticated, internal_unproven, no_overseer', implode(' ', [$dec($ku), $dec($kn), $dec($ki), $dec($ka)]));
$up = page($jp, '/oversight/unscreened');
ok($up['code'] === 200 && str_contains($up['body'], 'data-screen="unscreened"') && str_contains($up['body'], "id=\"unscreened-$ku-why\"") && str_contains($up['body'], 'An outside sender this mailbox does not know')
   && str_contains($up['body'], 'No DMARC pass') && str_contains($up['body'], 'Looks internal, but this application did not send it'), 'Priya\'s Unscreened lists hers with why in words');
ok(!str_contains($up['body'], "unscreened-$ka\""), '…not Ada\'s (Priya does not oversee it)');
ok(str_contains(page($jo, '/oversight/unscreened')['body'], 'No primary overseer to act'), 'the Mail admin sees Ada\'s: "No primary overseer to act"');
ok(one("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'unscreened'") >= 3, 'the primary overseer got an unscreened notice');
$chat0 = count(chat_lines());
$r = oact($jp, 'release', ['mailbox' => $E, 'message' => $ku]);
ok($r['code'] === 302 && one("SELECT status || '/' || acting_member_id || '/' || released_by FROM agent_dispatches WHERE message_key_id = $ku") === 'queued/26/26', 'Release → queued, the releasing person acting', $r['body']);
worker('dispatches');
$turns = array_slice(chat_lines(), $chat0);
ok(count($turns) === 1 && $turns[0]['acting'] === '26' && str_contains($turns[0]['utterance'], 'released it from Unscreened') && str_contains($turns[0]['utterance'], 'thread_read')
   && !str_contains($turns[0]['utterance'], 'Hello.') && ($turns[0]['context']['decision'] ?? '') === 'unknown_sender' && str_starts_with((string) $turns[0]['conversation_id'], "mail:$E:"), 'one turn: ids and the person\'s release, no mail text, the conversation id', json_encode($turns));
$r = oact($jp, 'mark-known', ['mailbox' => $E, 'address' => 'stranger@outside.example']);
ok($r['code'] === 302 && one("SELECT ml_is_known($E, 'stranger@outside.example')") === true, 'Mark sender known');
$mk = activity('sender.mark_known');
ok(str_contains((string) end($mk)['after'], 'outside.example') && !str_contains((string) end($mk)['after'], 'stranger@'), '…logged by the domain only');
[, , $ku2] = deliver('expert@business.example', 'Unknown sender again', 'Hello again.', ['from' => 'Stranger <stranger@outside.example>']);
ok($dec($ku2) === 'wake/queued', 'the next mail from that sender wakes the agent', $dec($ku2));
$r = oact($jp, 'dismiss', ['mailbox' => $E, 'message' => $kn, 'as_spam' => 'yes']);
ok($r['code'] === 302 && one("SELECT status || '/' || detail FROM agent_dispatches WHERE message_key_id = $kn") === 'skipped/spam', 'Spam → dismissed as spam', $r['body']);
ok(wait_until(static fn (): bool => (string) one("SELECT f.special_use FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id WHERE m.mailbox_id = $E AND m.message_key_id = $kn ORDER BY m.id DESC LIMIT 1") === 'junk', 15), '…the message in Junk in the IMAP fixture (the index follows)');
$r = oact($jp, 'dismiss', ['mailbox' => $E, 'message' => $ki]);
ok($r['code'] === 302 && !str_contains(page($jp, '/oversight/unscreened')['body'], "unscreened-$ki\""), 'Dismiss → gone from the list');
ok(count(activity('unscreened.release')) === 1 && count(activity('unscreened.dismiss')) === 2, 'unscreened.release and unscreened.dismiss logged');
ok(oact($jd, 'release', ['mailbox' => $A, 'message' => $ka])['code'] === 404, 'Dana cannot reach Ada\'s Unscreened (404)');

echo "8. Pause and resume\n";
su('UPDATE ml_settings SET undo_send_seconds = 30 WHERE id = 1');
policy("send_mode = 'auto'");
$later = (new DateTimeImmutable('+3 hours', new DateTimeZone('UTC')))->format('Y-m-d\TH:i');
[$c, $j] = asend($E, ['later@business.example'], ['send_at' => $later]);
$oq = (int) $j['record_id'];
[$c, $j] = asend($E, ['deferred@business.example'], ['send_at' => $later]);
$od = (int) $j['record_id'];
su("UPDATE outbound_email SET status = 'sending' WHERE id = $od");                       // as the outbox claims it…
su("SELECT ml_outbound_deferred($od, '451 4.3.0 try again later')");                  // …and the server answers 451
su("UPDATE outbound_email SET retry_at = now() + interval '1 hour' WHERE id = $od");
$oa = $held['paused'];
$r = oact($jp, 'approve', ['outbound' => $oa]);               // approved: queued inside the 30-second window
$ou = $held['mode'];
ok(orow($oq)['status'] === 'queued' && orow($od)['status'] === 'deferred' && orow($oa)['status'] === 'queued', 'a scheduled, a deferred and an approved agent message wait to go');
$r = oact($jp, 'pause', ['mailbox' => $E, 'reason' => 'Checking its tone', 'return_to' => '/oversight']);
ok($r['code'] === 302 && str_contains($r['location'], '/oversight?notice=paused'), 'Pause from the card (a reason)', $r['location']);
ok(orow($oq)['status'] === 'held' && orow($od)['status'] === 'held' && orow($oa)['status'] === 'held' && in_array('paused', reasons($oq), true) && in_array('paused', reasons($od), true), 'queued and deferred agent rows → held with paused');
$card = page($jp, '/oversight');
ok(str_contains($card['body'], 'id="agent-card-' . $E . '-paused"') && str_contains($card['body'], 'Checking its tone') && str_contains($card['body'], 'id="agent-card-' . $E . '-resume"'), 'the card says Paused in red, by whom and why, with Resume');
$pa = activity('mailbox.pause');
ok(str_contains((string) end($pa)['after'], 'Checking its tone'), 'mailbox.pause logged with the reason');
// a turn queued while paused waits
[, , $kw] = deliver('expert@business.example', 'While paused', 'Hello.', ['from' => 'Known Person <known@outside.example>']);
ok($dec($kw) === 'paused/skipped', 'mail arriving while paused wakes no one (paused)');
$chat0 = count(chat_lines());
su("UPDATE agent_dispatches SET status = 'queued', acting_member_id = 26, next_attempt_at = now() WHERE message_key_id = $ku2");
worker('dispatches');
ok(count(chat_lines()) === $chat0 && one("SELECT status FROM agent_dispatches WHERE message_key_id = $ku2") === 'queued', 'a queued turn waits while paused: no turn');
$r = oact($jp, 'resume', ['mailbox' => $E, 'return_to' => '/oversight']);
ok($r['code'] === 302, 'Resume');
ok(orow($oa)['status'] === 'queued', 'the approved message goes (queued again)', json_encode(orow($oa, 'status, hold_reasons')));
ok(orow($ou)['status'] === 'held' && reasons($ou) === ['mode'], 'a message held for its own reasons waits for a person, without paused', json_encode(reasons($ou)));
ok(orow($oq)['status'] === 'held' && reasons($oq) === ['paused'], 'one held for the pause alone and never reviewed waits for a person, still saying why (the schema\'s rule)', json_encode(reasons($oq)));
worker('dispatches');
ok(count(chat_lines()) === $chat0 + 1, 'after resume the waiting turn goes');
su('UPDATE ml_settings SET undo_send_seconds = 3 WHERE id = 1');
ok(count(activity('mailbox.resume')) === 1, 'mailbox.resume logged');

echo "9. The policy\n";
$pp = page($jp, "/m/$E/policy");
ok($pp['code'] === 200 && str_contains($pp['body'], 'data-screen="agent-policy"') && str_contains($pp['body'], 'id="policy-row-send_mode"') && str_contains($pp['body'], 'id="policy-business"') && str_contains($pp['body'], 'id="policy-history"')
   && str_contains($pp['body'], 'Hold a message to anyone this mailbox has never written to'), 'agent-policy: each setting with its meaning, what the business applies, the history');
ok(page($jp, "/m/$P/policy")['code'] === 404 && page($jp, "/m/$S/policy")['code'] === 404, 'a person\'s or a shared mailbox has no policy page (404)');
ok(page($jd, "/m/$E/policy")['code'] === 200 && str_contains(page($jd, "/m/$E/policy")['body'], 'id="policy-read-only"'), 'Dana (triage) reads it, read-only');
$all = ['mailbox' => $E, 'send_mode' => 'allowlist_auto', 'allow_domains' => "supplier.example\npartner.example", 'allow_addresses' => 'jon@vendor.example', 'first_contact_review' => 'no',
        'attachments_review' => 'yes', 'max_external_per_day' => '7', 'max_per_hour' => '9', 'max_per_thread_per_day' => '3', 'wake_on_mail' => 'yes', 'wake_from' => 'internal',
        'disclosure' => 'yes', 'disclosure_text' => 'Written by {agent}, an AI agent of the business.'];
$r = oact($jp, 'policy', $all, 'json');
ok($r['code'] === 200, 'every key saved', $r['body']);
$pj = jbody(req('GET', "/m/$E/policy", ['jar' => $jp, 'headers' => ['Accept: application/json']]))['data']['policy'] ?? [];
ok(($pj['send_mode'] ?? '') === 'allowlist_auto' && $pj['allow_domains'] === ['supplier.example', 'partner.example'] && $pj['allow_addresses'] === ['jon@vendor.example'] && $pj['first_contact_review'] === false
   && $pj['attachments_review'] === true && $pj['max_external_per_day'] === 7 && $pj['max_per_hour'] === 9 && $pj['max_per_thread_per_day'] === 3 && $pj['wake_on_mail'] === true
   && $pj['wake_from'] === 'internal' && $pj['disclosure'] === true && $pj['disclosure_text'] === 'Written by {agent}, an AI agent of the business.', '…and read back', json_encode($pj));
$ps = activity('policy.set');
$pa = json_decode((string) end($ps)['after'], true);
ok(count(array_intersect(['send_mode', 'allow_domains', 'allow_addresses', 'attachments_review', 'max_external_per_day', 'max_per_hour', 'max_per_thread_per_day', 'wake_from', 'disclosure_text'], $pa['keys'] ?? [])) === 9
   && !in_array('first_contact_review', $pa['keys'] ?? [], true) && ($pa['allow_addresses'] ?? null) === ['vendor.example'] && !str_contains((string) end($ps)['after'], 'jon@'), 'policy.set logs the keys that changed (not the unchanged), old and new — an address by its domain', json_encode($pa));
$r = oact($jp, 'policy', ['mailbox' => $E, 'send_mode' => 'allowlist_auto', 'allow_domains' => '', 'allow_addresses' => ''], 'json');
ok($r['code'] === 422 && str_contains($r['body'], 'needs at least one domain or address'), 'allowlist_auto with an empty list: refused in the CHECK\'s sentence', $r['body']);
$r = tact(40, '/oversight/policy', ['mailbox' => $E, 'send_mode' => 'auto']);
ok($r['code'] === 403, 'the agent\'s token is refused');
ok(str_contains(page($jp, "/m/$E/policy")['body'], 'data-action="policy.set"') && str_contains(page($jp, "/m/$E/policy")['body'], 'data-action="mailbox.pause"'), 'the history lists the change, the pause and the resume');
ok(str_contains($pp['body'], '/assets/mail/policy.js'), 'the page asks first when a change widens (policy.js sets hx-confirm)');
oact($jp, 'policy', ['mailbox' => $E, 'send_mode' => 'hold_all', 'first_contact_review' => 'yes', 'max_external_per_day' => '20', 'max_per_hour' => '50', 'max_per_thread_per_day' => '5', 'wake_from' => 'internal_and_known'], 'json');

echo "10. The cards\n";
$card = page($jp, '/oversight');
ok($card['code'] === 200 && str_contains($card['body'], 'data-screen="oversight"') && str_contains($card['body'], 'id="agent-card-' . $E . '"') && !str_contains($card['body'], 'id="agent-card-' . $A . '"'), 'Priya\'s cards: the Expert\'s mailbox, not Ada\'s');
ok(preg_match('/agent-card-' . $E . '-counts.*?data-count="sent">(\d+)</s', $card['body'], $m) === 1 && (int) $m[1] >= 3, '…its counts (sent this week)', $m[1] ?? '');
ok(str_contains($card['body'], 'approved as written') && str_contains($card['body'], 'id="agent-card-' . $E . '-pause-form"'), '…its record and Pause');
$cj = jbody(req('GET', '/oversight', ['jar' => $jp, 'headers' => ['Accept: application/json']]))['data']['cards'] ?? [];
pdo()->query("SELECT set_config('app.member_id', '26', false)");
$sum = json_decode((string) one("SELECT ml_agent_mail_summary($E, 7)"), true);
pdo()->query("SELECT set_config('app.member_id', '', false)");
ok(count($cj) === 1 && $cj[0]['held_now'] === (int) $sum['held_now'] && $cj[0]['rejected'] === (int) $sum['rejected'] && $cj[0]['sent'] === (int) $sum['sent'], 'the JSON card and ml_agent_mail_summary() agree (one gatherer)');
ok(count(jbody(req('GET', '/oversight', ['jar' => $jo, 'headers' => ['Accept: application/json']]))['data']['cards'] ?? []) === 2, 'the Mail admin\'s cards: both agent mailboxes');
[$jm] = sign_on(27);
ok(str_contains(page($jm, '/oversight')['body'], 'id="oversight-empty"'), 'Marco oversees none: "You oversee no agent\'s mailbox…"');

$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
foreach (['mail_review_approve', 'mail_review_edit', 'mail_review_reject', 'mail_review_reply', 'mail_thread_take_over', 'mail_mute', 'mail_unscreened_release', 'mail_unscreened_dismiss',
          'mail_sender_mark_known', 'mail_policy_set', 'mail_mailbox_pause', 'mail_mailbox_resume'] as $a) {
    ok(($reg['actions'][$a]['built'] ?? false) === true && (($reg['actions'][$a]['agent'] ?? null) === ($a === 'mail_mute')), "  $a built; " . ($a === 'mail_mute' ? 'an agent may' : 'people only'));
}
foreach (['oversight', 'review-queue', 'review-view', 'review-edit', 'unscreened', 'agent-policy'] as $s) {
    ok(($reg['screens'][$s]['built'] ?? false) === true, "  screen $s built");
}
file_put_contents($STATE . '/s5-held.json', json_encode(['held' => $held, 'shared' => $shared]));
finish();
