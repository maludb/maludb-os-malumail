<?php
/**
 * Slice 10's page and action proof (home-admin.md Proof 1–7): who may open the four screens and post the five actions (a Mail user, an
 * administrator who is no super-admin, an agent's token and the SQL called directly are all refused); every settings key saved and read back,
 * the CHECKs' and the allow-list's refusals, the two switches changing the hold reasons at once and the banner on every admin page, MM3 asking for
 * its name and changing the dispatch decision; the sync page against the IMAP fixture (a failing mailbox, Re-sync's SELECT, Re-index's reset and
 * refill, the mail service down); the agents' page (a failed turn retried once, sender domains only); the overview's figures against the SQL;
 * the notice's version, count and the gate. Priya is 26, Marco 27, Dana 30, the Mail admin 1. Run by tests/phase3/slice10/run.sh.
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

$CTL = need('ML_IMAP_CONTROL');
function ctl(array $cmd): array
{
    global $CTL;
    $n = bin2hex(random_bytes(8));
    file_put_contents("$CTL/$n.part", json_encode($cmd));
    rename("$CTL/$n.part", "$CTL/$n.json");
    for ($i = 0; $i < 200; $i++) {
        if (is_file("$CTL/$n.done")) { $o = json_decode((string) file_get_contents("$CTL/$n.done"), true); unlink("$CTL/$n.done"); return $o; }
        usleep(50000);
    }
    throw new RuntimeException('the fixture did not answer');
}
function su(string $sql): string { return trim((string) shell_exec('sudo -n -u postgres psql -X -At -v ON_ERROR_STOP=1 -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg($sql) . ' 2>&1')); }
function wait_until(callable $f, float $timeout = 30.0): bool { $t = microtime(true); while (microtime(true) - $t < $timeout) { if ($f()) { return true; } usleep(200000); } return false; }
function post(string $jar, string $path, array $form, string $mode = 'form'): array
{
    $form += ['csrf_token' => page_csrf($jar)];
    $h = $mode === 'json' ? ['Accept: application/json'] : ($mode === 'htmx' ? ['HX-Request: true'] : []);
    return req('POST', $path, ['jar' => $jar, 'form' => $form, 'headers' => $h]);
}
function tpost(int $member, string $path, array $form, int $run = 9910): array { return req('POST', $path, ['headers' => as_agent(run_token($member, $run)), 'form' => $form]); }
function jbody(array $r): array { $j = json_decode($r['body'], true) ?: []; return isset($j['data']) && is_array($j['data']) ? $j['data'] : $j; }
/** A log row's payload the way the activity table keeps it: before/after columns. */
function lp(array $row): array { return ['before' => json_decode((string) ($row['before'] ?? ''), true), 'after' => json_decode((string) ($row['after'] ?? ''), true)]; }
function setting(string $col) { return one("SELECT $col FROM ml_settings WHERE id = 1"); }
function stats(): array { return json_decode((string) @file_get_contents(need('ML_IMAP_STATS')), true) ?: []; }
const SECRET_WORDS = ['SECRETSUBJECT', 'SECRETBODYWORDS', 'Zelda', 'zelda@outside.example'];
/** One message into an INBOX of the index (a row, no mail server): its id. */
function arrive(string $box, string $from, array $o = []): int
{
    static $n = 0;
    $n++;
    $mb = mb($box);
    $mid = "<s10-$mb-$n-" . bin2hex(random_bytes(3)) . '@outside.example>';
    $local = !empty($o['local']) ? 'true' : 'false';
    return (int) strtok(su("INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, from_address, from_name, to_addresses, subject, sent_at, received_at, seen, snippet, body_text, dmarc_pass, local_submission)
               SELECT f.id, 1, (SELECT COALESCE(max(uid), 0) + 1 FROM mail_messages WHERE folder_id = f.id), ml_message_key_for(f.mailbox_id, '$mid', encode(digest('$mid', 'sha256'), 'hex')), '$mid',
                      '$from', 'Zelda Zorro', ARRAY[b.address::text], 'SECRETSUBJECT', now(), now(), false, 'SECRETBODYWORDS snippet', 'SECRETBODYWORDS body', NULL, $local
                 FROM mail_folders f JOIN mailboxes b ON b.id = f.mailbox_id WHERE b.id = $mb AND f.special_use = 'inbox' RETURNING id"), "\n");
}

$RUN = ' #' . substr(bin2hex(random_bytes(3)), 0, 6);
$P = mb('priya'); $D = mb('dana'); $S = mb('sales'); $E = mb('expert'); $A = mb('ada'); $C = mb('cred');
[$jp] = sign_on(26); [$jm] = sign_on(27); [$jd] = sign_on(30); [$ja] = sign_on(1);
kernel_state(static function (array $s): array {
    $s['facts']['9910'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 9910, 'endpoints' => [['application' => 'malumail']]];
    return $s;
});
$PAGES = ['/admin', '/admin/sync', '/admin/settings', '/admin/agents'];
$SQLCHECK = static fn (string $s): bool => str_contains($s, 'ERROR');

echo "0. The fixture mailbox goes live and its text is indexed\n";
ctl(['op' => 'deliver', 'user' => 'priya@business.example', 'subject' => 'SECRETSUBJECT', 'from' => 'Zelda Zorro <zelda@outside.example>', 'body' => 'SECRETBODYWORDS']);
ok(wait_until(static fn (): bool => one('SELECT sync_state FROM mailboxes WHERE id = :m', ['m' => $P]) === 'live'
    && (int) one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :m AND text_indexed_at IS NOT NULL', ['m' => $P]) >= 6, 90.0), 'Priya\'s mailbox is live and its six messages are text-indexed');

echo "1. Who: a Mail user, an administrator who is no super-admin, an agent, the SQL called directly\n";
$wrong = [];
foreach ($PAGES as $path) {
    foreach ([[$jp, 'Priya'], [$jm, 'Marco'], [$jd, 'Dana']] as [$j, $who]) {
        $r = page($j, $path);
        if ($r['code'] !== 403 || !str_contains($r['body'], 'super-admin')) { $wrong[] = "$who $path {$r['code']}"; }
    }
    $r = page($ja, $path);
    if ($r['code'] !== 200 || !str_contains($r['body'], 'data-screen=')) { $wrong[] = "admin $path {$r['code']}"; }
    $r = req('GET', $path, ['headers' => as_agent(run_token(40, 9910))]);
    if ($r['code'] !== 403) { $wrong[] = "agent $path {$r['code']}"; }
}
ok($wrong === [], 'the four screens: 403 in the right\'s sentence for Priya, Marco and Dana; 200 for the Mail admin; 403 for an agent\'s token', implode('; ', $wrong));
$forms = ['/admin/settings/save.php' => ['undo_send_seconds' => '9'], '/admin/settings/notice.php' => ['text' => str_repeat('A notice nobody should be able to save. ', 3)],
          '/admin/sync/resync.php' => ['mailbox' => $P], '/admin/sync/reindex.php' => ['mailbox' => $P], '/admin/agents/retry.php' => ['dispatch' => 1]];
$wrong = [];
$undo0 = setting('undo_send_seconds'); $ver0 = setting('notice_version');
foreach ($forms as $path => $form) {
    foreach ([[$jp, 'Priya'], [$jm, 'Marco']] as [$j, $who]) {
        $r = post($j, $path, $form);
        if ($r['code'] !== 403) { $wrong[] = "$who POST $path {$r['code']}"; }
    }
    $r = tpost(40, $path, $form);
    if ($r['code'] !== 403) { $wrong[] = "agent POST $path {$r['code']}"; }
}
ok($wrong === [], 'the five actions: 403 for Priya, Marco and an agent\'s token', implode('; ', $wrong));
ok(setting('undo_send_seconds') === $undo0 && setting('notice_version') === $ver0, '…and nothing changed');
$bad = [];
foreach (["SELECT ml_settings_save(40, '{\"undo_send_seconds\":4}')", "SELECT ml_notice_save(40, repeat('x', 60))", "SELECT ml_mailbox_reindex($P, 40)", "SELECT ml_dispatch_retry(1, 40)",
         "SELECT ml_settings_save(26, '{\"undo_send_seconds\":4}')", "SELECT ml_notice_save(26, repeat('x', 60))", "SELECT ml_mailbox_reindex($P, 26)", "SELECT ml_dispatch_retry(1, 26)"] as $sql) {
    if (!$SQLCHECK(su($sql))) { $bad[] = $sql; }
}
ok($bad === [], 'the SQL functions refuse an agent and a person without the right when called directly', implode('; ', $bad));

echo "2. Settings: every key saved and read back\n";
$r = page($ja, '/admin/settings');
$need = ['set-undo_send_seconds', 'set-images_default', 'set-index_text_max_kb', 'set-max_attachments_mb', 'set-draft_save_seconds', 'set-poll_visible_seconds', 'set-poll_hidden_seconds', 'set-held_expiry_hours',
         'set-default_send_mode', 'set-default_wake_from', 'set-default_max_external_per_day', 'set-disclosure_text', 'set-agent_external_budget', 'set-hold_all_agents', 'set-hold_all_external', 'set-mm3_identity_enforced', 'set-clerk_note_at', 'admin-notice-form'];
$missing = array_filter($need, static fn ($id) => !str_contains($r['body'], 'id="' . $id . '"'));
ok($missing === [], 'the form carries every field and the notice form', implode(',', $missing));
ok(str_contains($r['body'], 'These change every mailbox and every agent') && str_contains($r['body'], 'Everyone acknowledges the new version'), 'both saves ask first (hx-confirm)');
$new = ['undo_send_seconds' => '7', 'images_default' => 'always', 'index_text_max_kb' => '200', 'max_attachments_mb' => '8', 'draft_save_seconds' => '20', 'poll_visible_seconds' => '20',
        'poll_hidden_seconds' => '90', 'held_expiry_hours' => '48', 'default_send_mode' => 'internal_auto', 'default_first_contact_review' => 'no', 'default_attachments_review' => 'no',
        'default_max_external_per_day' => '30', 'default_max_per_hour' => '40', 'default_max_per_thread_per_day' => '4', 'default_wake_on_mail' => 'yes', 'default_wake_from' => 'internal',
        'default_disclosure' => 'yes', 'disclosure_text' => 'Written by {agent}, an AI agent of the business.', 'agent_external_budget' => '150', 'clerk_note_at' => '08:15'];
$mark = last_activity_id();
$r = post($ja, '/admin/settings/save.php', $new);
ok($r['code'] === 302 && str_contains($r['location'], '/admin/settings') && str_contains($r['location'], 'notice=saved'), 'Save lands back on the settings with a notice', $r['code'] . ' ' . $r['location']);
$back = q("SELECT undo_send_seconds, images_default, index_text_max_kb, max_attachments_mb, draft_save_seconds, poll_visible_seconds, poll_hidden_seconds, held_expiry_hours, default_send_mode,
                  default_first_contact_review, default_attachments_review, default_max_external_per_day, default_max_per_hour, default_max_per_thread_per_day, default_wake_on_mail,
                  default_wake_from, default_disclosure, disclosure_text, agent_external_budget, to_char(clerk_note_at, 'HH24:MI') AS clerk_note_at FROM ml_settings")[0];
$exp = $new;
$wrong = [];
foreach ($new as $k => $v) {
    $got = $back[$k];
    $want = in_array($v, ['yes', 'no'], true) ? ($v === 'yes') : (ctype_digit($v) ? (int) $v : $v);
    if ($got !== $want && (string) $got !== (string) $want) { $wrong[] = "$k=" . var_export($got, true); }
}
ok($wrong === [], 'every one of the twenty keys read back from the table', implode(', ', $wrong));
$log = activity('settings.save', $mark);
$payload = lp($log[0] ?? []);
$changedKeys = array_keys($payload['after'] ?? []);
ok(count($log) === 1 && in_array('undo_send_seconds', $changedKeys, true) && !in_array('hold_all_agents', $changedKeys, true) && !in_array('notice_text', $changedKeys, true), 'the log row carries the changed keys only', json_encode($changedKeys));
ok(!str_contains(json_encode($payload), 'Written by') && ($payload['after']['disclosure_text'] ?? null) === mb_strlen('Written by {agent}, an AI agent of the business.'), 'the disclosure text is logged by its length, never its words');
$mark = last_activity_id();
$r = post($ja, '/admin/settings/save.php', $new);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=unchanged') && activity('settings.save', $mark) === [], 'saving the same values again: "Nothing changed" and no log row');
$r = post($ja, '/admin/settings/save.php', ['agent_external_budget' => ''] , 'json');
ok(($j = json_decode($r['body'], true)) && ($j['ok'] ?? false) === true && setting('agent_external_budget') === null, 'JSON mode: an empty budget is the warm-up (NULL)', $r['body']);
foreach ([['undo_send_seconds', '31', 'whole number from 0 to 30'], ['index_text_max_kb', '5', 'from 10 to 1024'], ['images_default', 'sometimes', 'one of'], ['disclosure_text', 'No placeholder in this one', '{agent}'],
          ['clerk_note_at', '25:00', 'time like'], ['held_expiry_hours', 'abc', 'whole number']] as [$k, $v, $frag]) {
    $r = post($ja, '/admin/settings/save.php', [$k => $v], 'json');
    $j = json_decode($r['body'], true) ?: [];
    ok($r['code'] === 422 && str_contains(json_encode($j), $frag), "$k=$v refused (422) in words", $r['code'] . ' ' . substr($r['body'], 0, 160));
}
$r = post($ja, '/admin/settings/save.php', ['poll_visible_seconds' => '200', 'poll_hidden_seconds' => '100'], 'json');
ok($r['code'] === 422 && setting('poll_visible_seconds') == 20, 'the table\'s own CHECK stands (visible poll longer than the hidden one): 422', $r['code'] . ' ' . substr($r['body'], 0, 200));
$r = post($ja, '/admin/settings/save.php', ['default_max_external_per_day' => '1000', 'default_max_per_hour' => '10'], 'json');
ok($r['code'] === 422 && setting('default_max_external_per_day') == 30, '…and the day cap against the hour cap: 422');
ok($SQLCHECK(su("SELECT ml_settings_save(1, '{\"key_rate_per_minute\":5}')")) && str_contains(su("SELECT ml_settings_save(1, '{\"key_rate_per_minute\":5}')"), 'Not a setting saved here'), 'a key outside the allow-list is refused by name in SQL');
ok(post($ja, '/admin/settings/save.php', [], 'json')['code'] === 422, 'a save with no setting in it: 422');

echo "3. The two switches change the hold reasons at once; the banner is on every admin page\n";
$reasons = static fn (string $to): string => su("SELECT ml_outbound_hold_reasons($E, 40, ARRAY['$to'], '{}', '{}', false, NULL)::text");
$admin_pages = ['/admin', '/admin/sync', '/admin/settings', '/admin/agents', '/admin/domains', '/admin/mailboxes', '/admin/aliases', '/admin/mailboxes/new', "/admin/mailboxes/$E", '/admin/aliases/new'];
$banner = static function () use ($ja, $admin_pages): array { return array_values(array_filter($admin_pages, static fn ($p) => !str_contains(page($ja, $p)['body'], 'id="switch-banner"'))); };
ok(!str_contains($reasons('x@outside.example'), 'hold_all_agents') && !str_contains($reasons('x@outside.example'), 'hold_all_external') && $banner() === $admin_pages, 'both off: no such reason, no banner on any admin page');
$r = post($ja, '/admin/settings/save.php', ['hold_all_agents' => 'yes']);
ok($r['code'] === 302 && str_contains($reasons('priya@business.example'), 'hold_all_agents') && $banner() === [], 'hold_all_agents: the reason is on an INTERNAL message at once and the banner is on all ' . count($admin_pages) . ' admin pages', json_encode($banner()));
ok(str_contains(page($ja, '/admin')['body'], 'Every agent&#039;s mail is being held') || str_contains(page($ja, '/admin')['body'], 'Every agent\'s mail is being held'), 'the banner says so in words');
post($ja, '/admin/settings/save.php', ['hold_all_agents' => 'no', 'hold_all_external' => 'yes']);
ok(str_contains($reasons('x@outside.example'), 'hold_all_external') && !str_contains($reasons('priya@business.example'), 'hold_all_external') && !str_contains($reasons('priya@business.example'), 'hold_all_agents') && $banner() === [], 'hold_all_external: outside mail only, banner still shown');
post($ja, '/admin/settings/save.php', ['hold_all_external' => 'no']);
ok(!str_contains($reasons('x@outside.example'), 'hold_all') && $banner() === $admin_pages, 'off again: both reasons and the banner gone');

echo "4. MM3 asks for its name and changes the dispatch decision\n";
$msg = arrive('ada', 'priya@business.example', ['local' => true]);
ok(su("SELECT ml_dispatch_decide($msg)") === 'internal_unproven', 'before: a colleague\'s message by authenticated submission is "internal_unproven"', su("SELECT ml_dispatch_decide($msg)"));
$r = post($ja, '/admin/settings/save.php', ['mm3_identity_enforced' => 'yes'], 'json');
ok($r['code'] === 422 && str_contains($r['body'], 'type MM3') && setting('mm3_identity_enforced') === false, 'MM3 on without the name typed: refused', $r['body']);
$mark = last_activity_id();
$r = post($ja, '/admin/settings/save.php', ['mm3_identity_enforced' => 'yes', 'mm3_confirm' => 'MM3'], 'json');
ok($r['code'] === 200 && setting('mm3_identity_enforced') === true, 'MM3 on with the name typed back');
ok(su("SELECT ml_dispatch_decide($msg)") === 'wake', 'after: the same message wakes the agent', su("SELECT ml_dispatch_decide($msg)"));
post($ja, '/admin/settings/save.php', ['mm3_identity_enforced' => 'no'], 'json');
ok(setting('mm3_identity_enforced') === false, 'MM3 off needs no confirmation');
ok(post($ja, '/admin/settings/save.php', ['agent_external_budget' => ''], 'json')['code'] === 200, '(the budget back to the warm-up)');

echo "5. Sync health\n";
$r = page($ja, '/admin/sync');
$body = $r['body'];
ok($r['code'] === 200 && str_contains($body, 'id="sync-' . $P . '"') && str_contains($body, 'id="sync-' . $A . '"') && str_contains($body, 'id="sync-' . $C . '"'), 'a row for every mailbox');
ok(preg_match('/id="sync-' . $P . '".*?data-cell="watcher">(notify|idle)</s', $body) === 1, 'Priya\'s watcher is notify or idle (from the mail service)');
ok(str_contains($body, 'The mail server refused the password') && str_contains($body, 'Could not reach the mail server'), 'the failing mailboxes show their last errors');
foreach (SECRET_WORDS as $w) { if (str_contains($body, $w)) { ok(false, "the sync page shows no mail: found $w"); } }
ok(true, 'the sync page shows no subject, no correspondent, no body (the fixture\'s words are absent)');
$f = page($ja, '/admin/sync?state=failing')['body'];
ok(str_contains($f, 'id="sync-' . $A . '"') && str_contains($f, 'id="sync-' . $C . '"') && !str_contains($f, 'id="sync-' . $P . '"'), 'filter failing: the two failed mailboxes, not Priya\'s');
$f = page($ja, '/admin/sync?state=failed')['body'];
ok(str_contains($f, 'id="sync-' . $A . '"') && !str_contains($f, 'id="sync-' . $C . '"') && !str_contains($f, 'id="sync-' . $P . '"'), 'filter failed: only the mailbox whose credential failed');
ok(!str_contains(page($ja, '/admin/sync?state=syncing')['body'], 'id="sync-' . $P . '"') && str_contains(page($ja, '/admin/sync?state=syncing')['body'], 'id="admin-sync-empty"'), 'filter syncing: none, with the empty sentence');
$j = jbody(req('GET', '/admin/sync', ['jar' => $ja, 'headers' => ['Accept: application/json']]));
$jr = array_values(array_filter($j['mailboxes'] ?? [], static fn ($m) => $m['mailbox_id'] === $P))[0] ?? [];
ok(($j['mail_service_answered'] ?? false) === true && ($jr['messages'] ?? 0) >= 6 && !isset($jr['subject']), 'JSON: the rows with counts and no content', json_encode($jr));
// Re-sync
$sel0 = (int) ((stats()['_'] ?? [])['selects'] ?? 0);
$mark = last_activity_id();
$r = post($ja, '/admin/sync/resync.php', ['mailbox' => $P], 'htmx');
ok($r['code'] === 200 && str_contains($r['body'], 'id="sync-' . $P . '"') && !str_contains($r['body'], '<html'), 'Re-sync answers the row alone (HTMX)', $r['code']);
ok(wait_until(static fn (): bool => (int) ((stats()['_'] ?? [])['selects'] ?? 0) > $sel0, 30.0), 'the fixture sees the re-read (SELECT … QRESYNC) after Re-sync');
$lg = activity('mailbox.resync', $mark);
ok(count($lg) === 1 && (int) $lg[0]['mailbox_id'] === $P, 'mailbox.resync logged with the mailbox id');
$r = post($ja, '/admin/sync/resync.php', ['mailbox' => $A], 'json');
ok($r['code'] === 409 && str_contains($r['body'], 'not being kept in step'), 'Re-sync of a mailbox with no working credential: 409 in words', $r['code'] . ' ' . substr($r['body'], 0, 150));
ok(post($ja, '/admin/sync/resync.php', ['mailbox' => 999999], 'json')['code'] === 404, 'a missing mailbox: 404');
// Re-index
$mark = last_activity_id();
$before = (int) one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :m AND text_indexed_at IS NOT NULL', ['m' => $P]);
$r = post($ja, '/admin/sync/reindex.php', ['mailbox' => $P], 'json');
$lg = activity('mailbox.reindex', $mark);
$pl = lp($lg[0] ?? []);
ok($r['code'] === 200 && count($lg) === 1 && ($pl['after']['reset'] ?? 0) === $before && $before >= 6, 'Re-index emptied every indexed text (the count is logged: ' . $before . ')', $r['body']);
ok(wait_until(static fn (): bool => (int) one('SELECT count(*) FROM mail_messages WHERE mailbox_id = :m AND text_indexed_at IS NOT NULL', ['m' => $P]) >= $before, 60.0), 'the text pass refills it');
ok(su("SELECT count(*) FROM mail_messages WHERE mailbox_id = $P AND body_text IS NOT NULL") >= '6', '…with the text back');
// the mail service down
$pid = (int) trim((string) @file_get_contents(need('ML_DEV_STATE') . '/mailer.pid'));
posix_kill($pid, SIGKILL);
usleep(800000);
$r = page($ja, '/admin/sync');
ok($r['code'] === 200 && str_contains($r['body'], 'id="admin-sync-down"') && str_contains($r['body'], 'The mail service is not answering') && str_contains($r['body'], 'id="sync-' . $P . '"') && preg_match('/id="sync-' . $P . '".*?data-cell="watcher">—</s', $r['body']) === 1,
   'the mail service down: the banner and the database\'s columns, no watcher');
$r = post($ja, '/admin/sync/resync.php', ['mailbox' => $P], 'json');
ok($r['code'] === 503, 'Re-sync with the service down: 503, nothing pretended', (string) $r['code']);
shell_exec('bash ' . escapeshellarg(dirname(__DIR__, 3) . '/tests/phase2/servers.sh') . ' restart-mailer >/dev/null 2>&1');
ok(wait_until(static fn (): bool => str_contains(page($ja, '/admin/sync')['body'], 'data-cell="watcher">notify<') || str_contains(page($ja, '/admin/sync')['body'], 'data-cell="watcher">idle<'), 60.0), 'the service back: the watcher is shown again');

echo "6. The agents' page\n";
$m1 = arrive('expert', 'zelda@outside.example');
$m2 = arrive('expert', 'zelda@outside.example');
su("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, status, decision, mailbox_id, message_key_id, attempts, run_id, created_at)
    SELECT 'mail_message', m.id, 40, 'mail', 'chat', 'failed', 'wake', $E, m.message_key_id, 4, 777, now() FROM mail_messages m WHERE m.id = $m1");
su("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, status, decision, mailbox_id, message_key_id, attempts, created_at)
    SELECT 'mail_message', m.id, 40, 'mail', 'chat', 'sent', 'wake', $E, m.message_key_id, 1, now() FROM mail_messages m WHERE m.id = $m2");
$fid = (int) one("SELECT id FROM agent_dispatches WHERE status = 'failed' AND mailbox_id = :e ORDER BY id DESC LIMIT 1", ['e' => $E]);
$r = page($ja, '/admin/agents');
$b = $r['body'];
ok($r['code'] === 200 && str_contains($b, 'id="agent-admin-' . $E . '"') && str_contains($b, 'id="agent-admin-' . $A . '"'), 'a card for each agent mailbox');
ok(str_contains($b, 'The agent was woken') && !preg_match('/Why:<\/span>\s*wake\b/', $b), 'a mail turn says why in words, never the decision code');
ok(preg_match('/data-turns="' . $E . '">1 sent · 0 answered · 1 failed · 0 unscreened · 0 refused</', $b) === 1, 'the Expert\'s card: the last seven days by status, from the table');
ok(str_contains($b, 'id="dispatch-' . $fid . '"') && str_contains($b, 'outside.example') && !str_contains($b, 'zelda') && !str_contains($b, 'SECRETSUBJECT') && !str_contains($b, 'Zelda'), 'the dispatch shows the sender\'s DOMAIN only — no name, no address, no subject');
ok(str_contains($b, 'id="dispatch-' . $fid . '-retry"') && !preg_match('/id="dispatch-\d+-retry"[^>]*>.*?data-status="sent"/s', $b), 'Retry on the failed turn');
ok(str_contains($b, '/ai/runs/777') || str_contains($b, 'Run #777'), 'the run is named (with a link to the kernel\'s run page when the OS address is known)');
ok(str_contains(page($ja, '/admin/agents?status=failed')['body'], 'id="dispatch-' . $fid . '"') && !str_contains(page($ja, '/admin/agents?status=sent')['body'], 'id="dispatch-' . $fid . '"') && str_contains(page($ja, '/admin/agents?agent=40')['body'], 'id="dispatch-' . $fid . '"') && !str_contains(page($ja, '/admin/agents?agent=43')['body'], 'id="dispatch-' . $fid . '"'), 'filters by status and by agent');
$att0 = (int) one('SELECT attempts FROM agent_dispatches WHERE id = :i', ['i' => $fid]);
$mark = last_activity_id();
$r = post($ja, '/admin/agents/retry.php', ['dispatch' => $fid], 'htmx');
ok($r['code'] === 200 && str_contains($r['body'], 'id="dispatch-' . $fid . '"') && str_contains($r['body'], 'data-status="queued"') && !str_contains($r['body'], '-retry"'), 'Retry swaps the row: queued, no Retry button');
ok(one('SELECT status FROM agent_dispatches WHERE id = :i', ['i' => $fid]) === 'queued' && (int) one('SELECT attempts FROM agent_dispatches WHERE id = :i', ['i' => $fid]) === $att0, 'queued, attempts unchanged (the worker counts the try)');
$lg = activity('dispatch.retry', $mark);
ok(count($lg) === 1 && (int) $lg[0]['mailbox_id'] === $E, 'dispatch.retry logged with the mailbox');
$r = post($ja, '/admin/agents/retry.php', ['dispatch' => $fid], 'json');
ok($r['code'] === 422 && str_contains($r['body'], 'Only a failed mail turn'), 'a second Retry is refused: only a failed turn is tried again', $r['code'] . ' ' . substr($r['body'], 0, 120));
ok(post($ja, '/admin/agents/retry.php', ['dispatch' => 999999], 'json')['code'] === 404, 'a missing turn: 404');
$j = jbody(req('GET', '/admin/agents', ['jar' => $ja, 'headers' => ['Accept: application/json']]));
ok(count($j['agents'] ?? []) === 2 && count($j['dispatches'] ?? []) === 3 && !str_contains(json_encode($j), 'zelda'), 'JSON: two agents, three turns (the MM3 proof made one for Ada), no sender address', json_encode(array_map('count', $j)));

echo "7. The overview's figures equal the SQL's\n";
su("INSERT INTO ml_aliases (domain_id, address, destinations) SELECT id, 'info@business.example', ARRAY['priya@business.example']::citext[] FROM ml_domains WHERE domain = 'business.example'");
su("INSERT INTO ml_aliases (domain_id, address, destinations) SELECT id, 'out@business.example', ARRAY['someone@gmail.example']::citext[] FROM ml_domains WHERE domain = 'business.example'");
su("UPDATE ml_domains SET dkim_ok = false, spf_ok = true, dmarc_ok = true WHERE domain = 'business.example'");
$j = jbody(req('GET', '/admin', ['jar' => $ja, 'headers' => ['Accept: application/json']]));
$sqlKinds = array_column(q('SELECT kind, count(*) AS n FROM mailboxes GROUP BY kind'), 'n', 'kind');
ok(($j['mailboxes']['kinds']['person'] ?? -1) == $sqlKinds['person'] && $j['mailboxes']['kinds']['agent'] == $sqlKinds['agent'] && $j['mailboxes']['kinds']['shared'] == $sqlKinds['shared'] && $j['mailboxes']['total'] == array_sum($sqlKinds), 'mailboxes by kind');
ok($j['domains']['served'] == 1 && $j['domains']['unverified'][0]['domain'] === 'business.example' && $j['domains']['unverified'][0]['dkim'] === false, 'domains: one served, DKIM not verified');
ok($j['forwarding']['count'] == 2 && $j['forwarding']['outside'] == 1, 'forwarding: two addresses, one goes outside');
$health = q('SELECT m.sync_state, c.status AS credential_status FROM mailboxes m LEFT JOIN mailbox_credentials c ON c.id = m.credential_id');
$live = count(array_filter($health, static fn ($h) => $h['sync_state'] === 'live')); $fail = count(array_filter($health, static fn ($h) => $h['sync_state'] === 'failed')); $cf = count(array_filter($health, static fn ($h) => in_array($h['credential_status'], ['failed', 'revoked'], true)));
ok($j['sync']['live'] == $live && $j['sync']['failing'] == $fail && $j['sync']['credential_failed'] == $cf && $cf === 1, "sync: $live live, $fail failing, $cf credential failed");
ok($j['held']['total'] == (int) one("SELECT count(*) FROM outbound_email WHERE status = 'held'") && $j['budget']['budget'] == (int) one('SELECT ml_agent_external_budget()') && $j['budget']['cap'] === null, 'held mail and the budget (the warm-up)');
$b = page($ja, '/admin')['body'];
ok(str_contains($b, 'id="admin-card-domains"') && str_contains($b, 'data-unverified="business.example"') && str_contains($b, 'DKIM not verified') && str_contains($b, '1 go outside') && str_contains($b, '1 credential failed') && str_contains($b, 'id="admin-card-switches"'), 'the cards say it in words, in red or amber');
ok(str_contains($b, 'href="/oversight/review"') && str_contains($b, 'href="/admin/sync"'), 'the cards link to their pages');
su('UPDATE ml_domains SET dkim_ok = true');
su("DELETE FROM ml_aliases WHERE address IN ('info@business.example', 'out@business.example')");

echo "8. The notice: a new version is acknowledged again\n";
$mark = last_activity_id();
$v0 = (int) setting('notice_version');
$r = post($ja, '/admin/settings/notice.php', ['text' => 'Too short.'], 'json');
ok($r['code'] === 422 && (int) setting('notice_version') === $v0, 'a notice under fifty characters: refused');
$cur = (string) setting('notice_text');
$r = post($ja, '/admin/settings/notice.php', ['text' => $cur]);
ok($r['code'] === 302 && str_contains($r['location'], 'notice=unchanged') && (int) setting('notice_version') === $v0, 'the same words: the version stays');
$newText = 'How mail is looked after here, in a revised form for the proof of the notice: overseers read what their agents send and receive, and every look at someone else\'s mailbox is logged.';
$r = post($ja, '/admin/settings/notice.php', ['text' => $newText]);
ok($r['code'] === 302 && (int) setting('notice_version') === $v0 + 1 && str_contains($r['location'], 'notice=notice'), 'a changed text is a new version (' . ($v0 + 1) . ')', $r['code'] . ' ' . $r['location']);
$lg = activity('notice.save', $mark);
$pl = lp($lg[0] ?? []);
ok(count($lg) === 1 && ($pl['after']['version'] ?? 0) === $v0 + 1 && ($pl['after']['length'] ?? 0) === mb_strlen($newText) && !str_contains(json_encode($pl), 'revised form'), 'notice.save logs the version and the length, not the words');
$r = page($jp, "/m/$P/v/inbox");
ok($r['code'] === 302 && str_starts_with($r['location'], '/notice'), 'Priya, who acknowledged version 1, is sent to /notice on her next page', $r['code'] . ' ' . $r['location']);
su("SELECT ml_notice_acknowledge(26)");
su("SELECT ml_notice_acknowledge(1)");
ok(page($jp, "/m/$P/v/inbox")['code'] === 200, '…and after acknowledging version ' . ($v0 + 1) . ' she reads her mail');
$people = (int) one("SELECT count(*) FROM members m WHERE m.status = 'active' AND m.member_kind = 'human' AND cardinality(m.roles) > 0");
$acked = (int) one('SELECT count(*) FROM ml_notice_acknowledgements a JOIN members m ON m.id = a.member_id WHERE a.notice_version = :v AND m.status = \'active\' AND m.member_kind = \'human\' AND cardinality(m.roles) > 0', ['v' => $v0 + 1]);
$b = page($ja, '/admin/settings')['body'];
ok(preg_match('/id="notice-acked">' . $acked . ' of ' . $people . '</', $b) === 1 && $acked === 2, "the page counts the acknowledgements: $acked of $people", substr(strip_tags((string) strstr($b, 'id="notice-state"')), 0, 200));
ok(str_contains($b, 'Version <b>' . ($v0 + 1) . '</b>') && str_contains($b, 'revised form'), 'the notice form shows the current version and words');
su("INSERT INTO ml_notice_acknowledgements (member_id, notice_version) SELECT m.id, " . ($v0 + 1) . " FROM members m WHERE m.member_kind = 'human' ON CONFLICT DO NOTHING");

finish();
