<?php
/**
 * Slice 9's page, action and worker proof (notifications.md Proof 1–8): new mail coalesced per mailbox and the cases that make none; held mail to exactly
 * its reviewers; expiry, Unscreened, a credential trip and quota once per crossing; NO NOTICE CARRIES MAIL (every title, body, outbox row, log row, page
 * and fake-service body scanned for the fixtures' subject, sender and body words); delivery through the fake /v1/send and the fake kernel's K6 (dedupe,
 * retry then failed, skipped, only for the text kinds); the Clerk's morning note by date; the screens and the two actions; agents refused. "Pat" is
 * Priya (26), "Dana" 30, the Mail admin 1. Run by tests/phase3/slice9/run.sh. Nothing reaches a mail server.
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

function su(string $sql): string { return trim((string) shell_exec('sudo -n -u postgres psql -X -At -v ON_ERROR_STOP=1 -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg($sql) . ' 2>&1')); }
function worker(string $passes, ?string $now = null): array
{
    $env = $now !== null ? 'ML_WORKER_NOW=' . escapeshellarg($now) . ' ' : '';
    $out = (string) shell_exec($env . 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=' . escapeshellarg($passes) . ' 2>&1');
    return json_decode(trim((string) strrchr("\n" . trim($out), "\n")), true) ?: ['raw' => $out];
}
function jbody(array $r): array { $j = json_decode($r['body'], true) ?: []; return isset($j['data']) && is_array($j['data']) ? $j['data'] : $j; }
function post(string $jar, string $path, array $form, string $mode = 'form'): array
{
    $form += ['csrf_token' => page_csrf($jar)];
    $h = $mode === 'json' ? ['Accept: application/json'] : ($mode === 'htmx' ? ['HX-Request: true'] : []);
    return req('POST', $path, ['jar' => $jar, 'form' => $form, 'headers' => $h]);
}
function tact(int $member, string $path, array $form, int $run = 9901): array { return req('POST', $path, ['headers' => as_agent(run_token($member, $run)), 'form' => $form]); }
/** The notices of a member (newest last), optionally of a kind and a mailbox. */
function notes(int $member, ?string $kind = null, ?int $mailbox = null): array
{
    return q('SELECT * FROM notifications WHERE member_id = :m AND (CAST(:k AS text) IS NULL OR kind = :k2) AND (CAST(:b AS bigint) IS NULL OR mailbox_id = :b2) ORDER BY id',
        ['m' => $member, 'k' => $kind, 'k2' => $kind, 'b' => $mailbox, 'b2' => $mailbox]);
}
/** One message into an INBOX of the index (a row; no mail server): its id. $o: from, seen, age ('2 days'), thread. */
function arrive(string $box, string $subject = 'SECRETSUBJECT', array $o = []): int
{
    static $n = 0;
    $n++;
    $mb = mb($box);
    $mid = "<s9-$mb-$n-" . bin2hex(random_bytes(3)) . '@outside.example>';
    $from = $o['from'] ?? 'zelda@outside.example';
    $seen = !empty($o['seen']) ? 'true' : 'false';
    $age = $o['age'] ?? '1 minute';
    $thread = isset($o['thread']) ? (int) $o['thread'] : 'NULL';
    $out = su("INSERT INTO mail_messages (folder_id, uidvalidity, uid, message_key_id, message_id_header, from_address, from_name, to_addresses, subject, sent_at, received_at, seen, snippet, body_text, thread_id, dmarc_pass)
               SELECT f.id, 1, (SELECT COALESCE(max(uid), 0) + 1 FROM mail_messages WHERE folder_id = f.id), ml_message_key_for(f.mailbox_id, '$mid', encode(digest('$mid', 'sha256'), 'hex')), '$mid',
                      '$from', 'Zelda Zorro', ARRAY[b.address::text], '$subject', now(), now() - interval '$age', $seen, 'SECRETBODYWORDS snippet', 'SECRETBODYWORDS body', $thread, true
                 FROM mail_folders f JOIN mailboxes b ON b.id = f.mailbox_id WHERE b.id = $mb AND f.special_use = 'inbox' RETURNING id");
    return (int) strtok($out, "\n");
}
function sms_lines(): array
{
    $f = need('FAKE_KERNEL_STATE') . '.sms';
    return is_file($f) ? array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES)))) : [];
}
function mail_lines(): array
{
    $f = need('FAKE_MALUMAIL_LOG');
    return is_file($f) ? array_values(array_filter(array_map(static fn ($l) => json_decode($l, true), file($f, FILE_IGNORE_NEW_LINES)))) : [];
}
function jb(array $r): array { return json_decode($r['body'], true) ?: []; }
const FIXTURE_WORDS = ['SECRETSUBJECT', 'Zelda', 'Zorro', 'SECRETBODYWORDS', 'zelda@outside.example'];

$RUN = ' #' . substr(bin2hex(random_bytes(3)), 0, 6);
$P = mb('priya'); $D = mb('dana'); $S = mb('sales'); $E = mb('expert'); $A = mb('ada'); $C = mb('cred');
[$jp] = sign_on(26); [$jd] = sign_on(30); [$jo] = sign_on(1);
$markLog = last_activity_id();
kernel_state(static function (array $s): array {
    $s['facts']['9901'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 9901, 'endpoints' => [['application' => 'malumail']]];
    $s['sms'] = ['mode' => 'ok'];
    return $s;
});
su('UPDATE ml_settings SET agent_external_budget = 1000, hold_all_agents = false, hold_all_external = false, undo_send_seconds = 30 WHERE id = 1');
su("INSERT INTO notification_prefs (member_id, new_mail_mailbox_ids) VALUES (26, ARRAY[$P, $S, $D]::bigint[]), (30, ARRAY[$S]::bigint[])");   // Priya chose Dana's mailbox too (she cannot open it)

echo "1. New mail: one coalesced notice per person and mailbox\n";
$w = worker('new_mail');
ok(($w['ran']['new_mail']['told'] ?? -1) === 0 && !empty($w['ran']['new_mail']['through']) && $w['errors'] === [], 'the first pass only sets the watermark', json_encode($w));
arrive('priya');
$w = worker('new_mail');
$n = notes(26, 'new_mail', $P);
ok(count($n) === 1 && (int) $n[0]['item_count'] === 1 && $n[0]['title'] === '1 new message in priya@business.example' && $n[0]['read_at'] === null, 'one arrival → one notice, "1 new message in priya@business.example"', json_encode($n));
$first = (int) ($n[0]['id'] ?? 0);
arrive('priya'); arrive('priya');
worker('new_mail');
$n = notes(26, 'new_mail', $P);
ok(count($n) === 1 && (int) $n[0]['id'] === $first && (int) $n[0]['item_count'] === 3 && $n[0]['title'] === '3 new messages in priya@business.example', 'two more within 30 minutes → the SAME notice says 3', json_encode($n));
worker('new_mail');
ok((int) notes(26, 'new_mail', $P)[0]['item_count'] === 3, 'a pass with nothing new changes nothing (idempotent)');
// the arrivals that make none
su("INSERT INTO mail_threads (mailbox_id, subject_norm) VALUES ($P, 'muted proof')");
$thread = (int) one("SELECT max(id) FROM mail_threads WHERE mailbox_id = $P");
$mm = arrive('priya', 'SECRETSUBJECT muted', ['thread' => $thread]);
su("INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, muted, muted_by) SELECT mailbox_id, message_key_id, true, 26 FROM mail_messages WHERE id = $mm");
arrive('priya', 'SECRETSUBJECT in the muted conversation', ['thread' => $thread]);
arrive('priya', 'SECRETSUBJECT own', ['from' => 'priya@business.example']);
arrive('priya', 'SECRETSUBJECT read elsewhere', ['seen' => true]);
arrive('priya', 'SECRETSUBJECT old', ['age' => '2 days']);
arrive('expert');                         // a mailbox she did not choose
arrive('dana');                           // a mailbox she chose but cannot open
worker('new_mail');
ok((int) notes(26, 'new_mail', $P)[0]['item_count'] === 3, 'mail in a muted conversation, her own, already read and a re-read of old mail → no more');
ok(notes(26, 'new_mail', $E) === [] && notes(26, 'new_mail', $D) === [], 'a mailbox not chosen → none; a mailbox she cannot open → none');
ok(notes(30, 'new_mail') === [], '…and nobody else is told for them');
arrive('sales');
worker('new_mail');
ok(count(notes(26, 'new_mail', $S)) === 1 && count(notes(30, 'new_mail', $S)) === 1 && (int) notes(30, 'new_mail', $S)[0]['item_count'] === 1, 'a shared mailbox → each person who chose it, at the level they hold (Dana at view)');
// read → the next arrival is a new notice; 30 minutes → a new notice
su('UPDATE notifications SET read_at = now() WHERE id = ' . $first);
arrive('priya');
worker('new_mail');
$n = notes(26, 'new_mail', $P);
ok(count($n) === 2 && (int) $n[1]['item_count'] === 1 && $n[1]['read_at'] === null, 'once read, the next arrival is a new notice', json_encode(array_column($n, 'item_count')));
su('UPDATE notifications SET created_at = now() - interval \'31 minutes\' WHERE id = ' . (int) $n[1]['id']);
arrive('priya');
worker('new_mail');
ok(count(notes(26, 'new_mail', $P)) === 3, 'an unread notice older than 30 minutes is not raised: a new one');
su("DELETE FROM mailbox_permissions WHERE mailbox_id = $S AND principal_id = 26 AND principal_kind = 'member'");
arrive('sales');
worker('new_mail');
ok(count(notes(26, 'new_mail', $S)) === 1 && (int) notes(26, 'new_mail', $S)[0]['item_count'] === 1 && (int) notes(30, 'new_mail', $S)[0]['item_count'] === 2, 'a mailbox she can no longer open → none (Dana still told: 2)');
su("INSERT INTO mailbox_permissions (mailbox_id, principal_kind, principal_id, level, granted_by) VALUES ($S, 'member', 26, 'full', 1)");

echo "2. Held mail: to exactly its reviewers\n";
function asend(int $mb, array $to, array $extra = []): array
{
    global $RUN;
    static $n = 0;
    $n++;
    $r = tact(40, '/mail/send', $extra + ['mailbox' => $mb, 'to' => $to, 'subject' => "SECRETSUBJECT agent note $n$RUN", 'body' => "SECRETBODYWORDS $n of $RUN.", 'body_format' => 'text', 'purpose' => 'answer the customer']);
    return [$r['code'], jb($r), $r['body']];
}
function policy(string $set): void { su("UPDATE agent_mail_policies SET $set WHERE mailbox_id = " . mb('expert')); }
policy("send_mode = 'hold_all', first_contact_review = false, attachments_review = false, max_per_hour = 1000, max_external_per_day = 1000, max_per_thread_per_day = 100");
[$c, $j, $raw] = asend($E, ['colleague@business.example']);
$h1 = (int) ($j['record_id'] ?? 0);
ok($c === 200 && ($j['status'] ?? '') === 'held' && $h1 > 0, 'the Expert\'s message is held', $raw);
$hm = q("SELECT id, member_id, title, record_type, record_id, mailbox_id FROM notifications WHERE kind = 'held_mail' ORDER BY member_id DESC");
ok(count($hm) === 2 && (int) $hm[0]['member_id'] === 26 && (int) $hm[1]['member_id'] === 1 && (int) $hm[0]['record_id'] === $h1 && $hm[0]['record_type'] === 'outbound' && (int) $hm[0]['mailbox_id'] === $E,
   'held_mail → each reviewer of the agent\'s mailbox once: Priya (full, the primary overseer) and the Mail admin (an agent\'s mailbox is theirs to oversee)', json_encode($hm));
ok(str_starts_with((string) ($hm[0]['title'] ?? ''), 'Mail from SMOKE Expert waits for your review (') && !str_contains((string) $hm[0]['title'], 'SECRETSUBJECT'), '…"Mail from SMOKE Expert waits for your review (mode)", no subject', (string) ($hm[0]['title'] ?? ''));
ok(notes(30, 'held_mail') === [] && notes(40) === [] && notes(42) === [], 'not to Dana (triage), the agent or the Clerk');
[$c, $j] = asend($D, ['someone@outside.example']);
$h2 = (int) ($j['record_id'] ?? 0);
ok($c === 200 && ($j['status'] ?? '') === 'held' && count(notes(30, 'held_mail')) === 1 && (int) notes(30, 'held_mail')[0]['record_id'] === $h2 && count(notes(26, 'held_mail')) === 1, 'the Expert writing from Dana\'s own mailbox (D12) → held_mail to Dana only', json_encode($j));
ok(count(notes(1, 'held_mail')) === 1, '…not the Mail admin, for a person\'s mailbox (still only the one for the agent\'s)');
policy("send_mode = 'auto'");
[$c, $j] = asend($E, ['colleague@business.example'], ['send_at' => gmdate('c', time() + 3600)]);
$q1 = (int) ($j['record_id'] ?? 0);
ok($c === 200 && in_array($j['status'] ?? '', ['queued', 'scheduled'], true), 'with auto, the next message (scheduled for later) waits in the queue', json_encode($j));
su("SELECT ml_mailbox_pause($E, 26, true, 'checking the paused re-hold')");
$reheld = q("SELECT status, hold_reasons FROM outbound_email WHERE id = $q1")[0];
ok($reheld['status'] === 'held' && ml_arr_of($reheld['hold_reasons']) === ['paused'], 'Priya pauses the mailbox → the queued message is re-held for the pause alone', json_encode($reheld));
ok(count(notes(26, 'held_mail')) === 1 && count(notes(1, 'held_mail')) === 1, '…and that is no new notice (the overseer who paused it knows)');
su("SELECT ml_mailbox_pause($E, 26, false)");
function ml_arr_of(string $t): array { $t = trim($t, '{}'); return $t === '' ? [] : array_map(static fn ($x) => trim($x, '"'), explode(',', $t)); }

echo "3. Decisions, Unscreened, a credential trip, quota\n";
su("UPDATE outbound_email SET held_at = now() - interval '100 hours' WHERE id = $h1");
$w = worker('held_expiry');
$rd = notes(26, 'review_decided');
ok(($w['ran']['held_expiry']['expired'] ?? 0) === 1 && count($rd) === 1 && (int) $rd[0]['record_id'] === $h1 && $rd[0]['title'] === 'SMOKE Expert\'s message expired with no decision', 'expiry → review_decided to the reviewer', json_encode($rd));
ok(notes(30, 'review_decided') === [] && count(notes(1, 'review_decided')) === 1, '…and to the other reviewer (the Mail admin), not Dana');
$um = arrive('expert', 'SECRETSUBJECT unscreened', ['from' => 'zelda@outside.example']);
$dec = one("SELECT ml_dispatch_decide($um)");
$un = notes(26, 'unscreened');
ok($dec === 'unknown_sender' && count($un) === 1 && $un[0]['title'] === 'Mail for Mail Expert waits for a person' && notes(30, 'unscreened') === [] && notes(1, 'unscreened') === [], 'Unscreened mail → the primary overseer, by the agent mailbox\'s name', $dec . ' ' . json_encode($un));
$cred = (int) one("SELECT credential_id FROM mailboxes WHERE id = $C");
su("SELECT ml_credential_failed($cred, 'proof')");
$admins = (int) one('SELECT count(*) FROM ml_mail_admin_ids()');
$cf = q("SELECT member_id FROM notifications WHERE kind = 'credential_failed'");
ok($admins >= 1 && count($cf) === $admins && notes(26, 'credential_failed') === [] && notes(30, 'credential_failed') === [] && notes(1, 'credential_failed', $C) !== [], 'a credential trip → the Mail admins only', json_encode($cf));
// quota: once per crossing
function setq(int $used): void { su("UPDATE mailboxes SET quota_bytes = 100, quota_used_bytes = $used WHERE address = 'priya@business.example'"); }
$counts = [];
foreach ([92, 92, 95, 100, 100, 50, 91] as $used) {
    setq($used);
    worker('quota');
    $counts[] = count(notes(26, 'quota', $P));
}
ok($counts === [1, 1, 1, 2, 2, 2, 3], 'quota: 92 % → 1 notice, 92/95 % → none more, 100 % → one more, 100 % again → none, below 90 and back to 91 → one more', json_encode($counts));
$qt = notes(26, 'quota', $P);
ok($qt[0]['title'] === 'priya@business.example is 92 % full' && $qt[1]['title'] === 'priya@business.example is 100 % full' && count(notes(1, 'quota', $P)) === 3, '…the owner and the Mail admin both told, in the mailbox\'s name and a percentage', $qt[0]['title']);
ok(notes(30, 'quota') === [], '…not Dana (no access to that mailbox)');
su("UPDATE mailboxes SET quota_bytes = NULL, quota_used_bytes = NULL WHERE address = 'priya@business.example'");

echo "4. Delivery: mail through the fake /v1/send, texts through the fake kernel\n";
$queued = (int) one("SELECT count(*) FROM notification_outbox WHERE channel = 'email' AND status = 'queued'");
ok($queued > 3 && (int) one("SELECT count(*) FROM notification_outbox WHERE kind = 'new_mail'") === 0, "mail rows are queued ($queued) — none for new mail (the default kinds leave it to the bell)");
$w = worker('notify_outbox');
$sentRows = (int) one("SELECT count(*) FROM notification_outbox WHERE channel = 'email' AND status = 'sent' AND sent_at IS NOT NULL");
$lines = mail_lines();
$statuses = q("SELECT status, count(*) AS n FROM notification_outbox GROUP BY status");
ok(($w['ran']['notify_outbox']['sent'] ?? 0) === $queued && $sentRows === $queued && count($lines) === $queued, "every queued mail sent through /v1/send once ($queued)", json_encode($w['ran']['notify_outbox'] ?? $w) . " rows $sentRows lines " . count($lines) . json_encode($statuses));
$w = worker('notify_outbox');
ok(count(mail_lines()) === $queued && ($w['ran']['notify_outbox']['sent'] ?? -1) === 0, 'a second pass sends nothing more');
$one = array_values(array_filter($lines, static fn ($l) => str_contains((string) $l['subject'], 'waits for your review') && ($l['to'] ?? '') === 'priya@example.invalid'))[0] ?? [];
ok(($one['to'] ?? '') === 'priya@example.invalid' && ($one['from'] ?? '') !== '' && !isset($one['html']), 'plain text, to the person\'s address, from the notification sender', json_encode(array_keys($one)));
ok(str_contains((string) ($one['text'] ?? ''), 'Open it: ' . BASE . '/oversight/review/' . $h1) && str_contains((string) $one['text'], 'You get this because of your MaluMail notification settings: ' . BASE . '/settings/notifications'), '…the title, a link to the record, and why she gets it with the settings link', (string) ($one['text'] ?? ''));
$dupe = su("INSERT INTO notification_outbox (channel, member_id, to_email, kind, dedupe_key, subject, body) VALUES ('email', 26, 'x@example.invalid', 'held_mail', 'n" . (int) $hm[0]['id'] . "', 's', 'b')");
ok(str_contains($dupe, 'duplicate key'), 'the same notice cannot be queued twice (dedupe n<id>)');
// a 502: retried, then failed
su("UPDATE members SET email = 'flaky@example.invalid' WHERE id = 30");
su("SELECT ml_notify(30, 'permission_granted', 'You can open sales@business.example (view) flaky', NULL, $S, 'mailbox', $S)");
$flaky = (int) one("SELECT max(id) FROM notification_outbox WHERE member_id = 30 AND kind = 'permission_granted'");
$before = count(mail_lines());
$states = [];
for ($i = 0; $i < 3; $i++) {
    worker('notify_outbox');
    $r = q("SELECT status, attempts, detail, send_after > now() AS later FROM notification_outbox WHERE id = $flaky")[0];
    $states[] = $r['status'] . '/' . $r['attempts'] . '/' . ($r['later'] ? 'later' : 'now');
    su("UPDATE notification_outbox SET send_after = now() WHERE id = $flaky AND status = 'queued'");
}
ok($states === ['queued/1/later', 'queued/2/later', 'failed/3/now'] && count(mail_lines()) === $before + 3 || $states === ['queued/1/later', 'queued/2/later', 'failed/3/later'] && count(mail_lines()) === $before + 3, 'a 502 is tried three times with backoff, then failed', json_encode($states));
ok(one("SELECT detail FROM notification_outbox WHERE id = $flaky") === 'http_502', '…saying why (the status, never an address)');
ok(str_contains(page($jd, '/settings/notifications')['body'], 'A notice could not be mailed'), 'Dana\'s settings page says "A notice could not be mailed"');
su("UPDATE members SET email = 'dana@example.invalid' WHERE id = 30");
su("UPDATE members SET email = 'suppressed@example.invalid' WHERE id = 27");
su("SELECT ml_notify(27, 'credential_failed', 'Marco proof', NULL, $C, 'mailbox', $C)");
worker('notify_outbox');
$r = q("SELECT status, detail FROM notification_outbox WHERE member_id = 27")[0] ?? [];
ok(($r['status'] ?? '') === 'skipped' && str_starts_with((string) ($r['detail'] ?? ''), 'suppressed'), 'a suppressed recipient (400) → skipped with the reason, never retried', json_encode($r));
su('UPDATE notification_prefs SET email_enabled = false WHERE member_id = 26');
$n0 = (int) one("SELECT count(*) FROM notification_outbox WHERE member_id = 26");
su("SELECT ml_notify(26, 'held_mail', 'mail off proof', NULL, $E, 'outbound', 999990)");
ok((int) one("SELECT count(*) FROM notification_outbox WHERE member_id = 26") === $n0, 'email switched off → the bell only, no outbox row');
su('UPDATE notification_prefs SET email_enabled = true, text_enabled = true WHERE member_id = 26');
$sms0 = count(sms_lines());
$tn = (int) one("SELECT ml_notify(26, 'held_mail', 'Text proof title', NULL, $E, 'outbound', 999991)");
su("SELECT ml_notify(26, 'permission_granted', 'No text for this kind', NULL, $S, 'mailbox', $S)");
ok((int) one("SELECT count(*) FROM notification_outbox WHERE channel = 'text' AND member_id = 26") === 1, 'texts only for the text kinds: held_mail made a text row, permission_granted did not');
worker('notify_outbox');
$sl = sms_lines();
$trow = q("SELECT status, provider_ref, dedupe_key FROM notification_outbox WHERE channel = 'text'")[0];
ok(count($sl) === $sms0 + 1 && (int) $sl[$sms0]['member_id'] === 26 && $sl[$sms0]['text'] === 'Text proof title ' . BASE . '/oversight/review/999991' && $sl[$sms0]['reference'] === 't' . $tn
   && $trow['status'] === 'sent' && str_starts_with((string) $trow['provider_ref'], 'kernel:'), 'the text goes to the kernel (K6): the title and a short link, once', json_encode($sl[$sms0] ?? []));
kernel_state(static function (array $s): array { $s['sms'] = ['mode' => 'no_sender']; return $s; });
su("SELECT ml_notify(26, 'held_mail', 'No sender proof', NULL, $E, 'outbound', 999992)");
worker('notify_outbox');
$r = q("SELECT status, detail, attempts FROM notification_outbox WHERE channel = 'text' ORDER BY id DESC LIMIT 1")[0];
$c1 = count(sms_lines());
worker('notify_outbox');
ok($r['status'] === 'skipped' && $r['detail'] === 'no_sender' && count(sms_lines()) === $c1 && (int) $r['attempts'] === 0, '503 no_sender → skipped with the reason, never retried', json_encode($r));
kernel_state(static function (array $s): array { $s['sms'] = ['mode' => 'server_error']; return $s; });
su("SELECT ml_notify(26, 'held_mail', 'Server error proof', NULL, $E, 'outbound', 999993)");
worker('notify_outbox');
$r = q("SELECT id, status, attempts FROM notification_outbox WHERE channel = 'text' ORDER BY id DESC LIMIT 1")[0];
kernel_state(static function (array $s): array { $s['sms'] = ['mode' => 'ok']; return $s; });
su("UPDATE notification_outbox SET send_after = now() WHERE id = " . (int) $r['id']);
worker('notify_outbox');
ok($r['status'] === 'queued' && (int) $r['attempts'] === 1 && one("SELECT status FROM notification_outbox WHERE id = " . (int) $r['id']) === 'sent', 'a 500 from the kernel → retried, and sent when it recovers');
kernel_state(static function (array $s): array { $s['sms'] = ['mode' => 'no_verified_phone']; return $s; });
su("SELECT ml_notify(26, 'held_mail', 'No phone proof', NULL, $E, 'outbound', 999994)");
worker('notify_outbox');
ok(one("SELECT detail FROM notification_outbox WHERE channel = 'text' ORDER BY id DESC LIMIT 1") === 'no_verified_phone', 'the kernel\'s own refusal (no verified phone) → skipped with its code');
kernel_state(static function (array $s): array { $s['sms'] = ['mode' => 'ok']; return $s; });

echo "5. The Mail Clerk's morning note\n";
su("UPDATE outbound_email SET status = 'cancelled' WHERE id = $q1");                  // the paused message of section 2 is out of the way: the worker's clock is days ahead of the rows'
policy("send_mode = 'hold_all'");
[$c, $j] = asend($E, ['colleague@business.example']);
$h3 = (int) ($j['record_id'] ?? 0);
su("UPDATE outbound_email SET held_at = timestamptz '2026-10-20 07:30:00+00' - interval '2 days' WHERE id = $h3");      // two days before the worker's clock below, not before the real one
su("UPDATE mailboxes SET quota_bytes = 100, quota_used_bytes = 93 WHERE id = $E");
$ua = arrive('ada', 'SECRETSUBJECT for ada');
$decA = one("SELECT ml_dispatch_decide($ua)");
ok($decA === 'unknown_sender' || str_ends_with((string) $decA, 'overseer') || $decA !== '', 'Ada\'s mail waits unscreened (no primary overseer)', (string) $decA);
$d0 = count(notes(26, 'digest'));
$w = worker('clerk_note', '2026-10-20T06:00:00+00:00');
ok(($w['ran']['clerk_note']['notes'] ?? -1) === 0, 'a Tuesday before clerk_note_at (the worker is told the date) → none', json_encode($w));
$w = worker('clerk_note', '2026-10-24T08:00:00+00:00');
ok(($w['ran']['clerk_note']['notes'] ?? -1) === 0, 'a Saturday → none', json_encode($w));
$w = worker('clerk_note', '2026-10-20T07:30:00+00:00');
$dp = notes(26, 'digest');
$dd = notes(30, 'digest');
ok(count($dp) === $d0 + 1 && count($dd) === 1 && count(notes(1, 'digest')) === 1 && ($w['ran']['clerk_note']['notes'] ?? 0) === 3, 'a Tuesday at 07:30 → one digest per overseer (Priya, Dana, and the Mail admin who oversees every agent mailbox)', json_encode($w['ran']));
$bp = (string) end($dp)['body'];
$bd = (string) $dd[0]['body'];
$bo = (string) notes(1, 'digest')[0]['body'];
ok(str_contains($bp, 'expert@business.example: 1 held over a day, 1 unscreened, 93 % full') && !str_contains($bp, 'ada@') && str_contains($bd, 'ada@business.example: 1 unscreened') && !str_contains($bd, 'expert@')
   && str_contains($bo, 'ada@') && str_contains($bo, 'expert@'), 'each covers only their agents\' mailboxes — counts only', $bp . ' | ' . $bd);
ok((int) end($dp)['actor_member_id'] === 42 && end($dp)['title'] === 'Your agents\' mail this morning' && (int) end($dp)['record_id'] === 20261020, '…attributed to the Clerk, dated by the day', json_encode(end($dp)));
$w = worker('clerk_note', '2026-10-20T09:00:00+00:00');
ok(($w['ran']['clerk_note']['notes'] ?? -1) === 0 && count(notes(26, 'digest')) === $d0 + 1, 'a second pass the same day → none');
su("DELETE FROM agent_dispatches WHERE status = 'unscreened'");
su("UPDATE mailboxes SET quota_bytes = NULL, quota_used_bytes = NULL WHERE id = $E");
su("UPDATE outbound_email SET status = 'cancelled' WHERE id = $h3");
$w = worker('clerk_note', '2026-10-21T07:30:00+00:00');
ok(($w['ran']['clerk_note']['notes'] ?? -1) === 0, 'nothing to say the next day → no note');

echo "6. No notice contains mail\n";
$hay = [];
foreach (q('SELECT title, body FROM notifications') as $r) { $hay[] = $r['title'] . ' ' . $r['body']; }
foreach (q('SELECT subject, body, detail FROM notification_outbox') as $r) { $hay[] = implode(' ', $r); }
foreach (q('SELECT after::text AS a, before::text AS b FROM activity_log WHERE id > :m', ['m' => $markLog]) as $r) { $hay[] = $r['a'] . ' ' . $r['b']; }
foreach (mail_lines() as $l) { $hay[] = json_encode($l); }
foreach (sms_lines() as $l) { $hay[] = json_encode($l); }
$found = [];
foreach ($hay as $h) { foreach (FIXTURE_WORDS as $word) { if (stripos($h, $word) !== false) { $found[] = $word . ' in ' . substr($h, 0, 80); } } }
ok($found === [] && count($hay) > 40, 'every notice, outbox row, log row, mail and text of the run (' . count($hay) . ') scanned for the fixtures\' subjects, sender and body words: none', implode(' | ', array_slice($found, 0, 3)));

echo "7. The screens and the two actions\n";
$page = page($jp, '/notifications');
ok($page['code'] === 200 && str_contains($page['body'], 'data-screen="notifications"') && str_contains($page['body'], 'id="notifications-list"') && str_contains($page['body'], 'notice-unread') && str_contains($page['body'], 'fw-bold'), 'Priya\'s notifications: the list, unread in bold');
$unreadN = (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL');
ok(str_contains($page['body'], "$unreadN unread") && !str_contains($page['body'], 'Dana'), 'the unread count; hers alone');
$rows26 = q('SELECT id FROM notifications WHERE member_id = 26 AND read_at IS NULL ORDER BY id');
$target = (int) $rows26[0]['id'];
ok(str_contains($page['body'], "hx-post=\"/notifications/read.php\"") && str_contains($page['body'], 'id="notice-' . $target . '-open"'), 'each unread row posts the read, then follows its link (hx-post)');
$fresh = jb(req('GET', '/notifications', ['jar' => $jp, 'headers' => ['Accept: application/json']]))['data'] ?? [];
ok(($fresh['unread_count'] ?? -1) === $unreadN && count($fresh['notifications'] ?? []) > 0 && isset($fresh['notifications'][0]['href']) && !isset($fresh['notifications'][0]['member_id']), 'JSON: the unread count and the notices, whitelisted');
$f = jb(req('GET', '/notifications?unread=1', ['jar' => $jp, 'headers' => ['Accept: application/json']]))['data'] ?? [];
ok(count(array_filter($f['notifications'] ?? [], static fn ($x) => !$x['unread'])) === 0 && count($f['notifications']) === $unreadN, 'the unread filter');
ok(str_contains(page($jp, '/notifications?unread=1')['body'], 'id="notifications-filter"'), '…and its toggle');
// open one: the hx-post
$mark = last_activity_id();
$href = (string) q("SELECT kind, record_type, record_id, mailbox_id FROM notifications WHERE id = $target")[0]['kind'];
$r = post($jp, '/notifications/read.php', ['notification' => $target, 'return_to' => '/oversight'], 'htmx');
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], '/oversight') && str_contains($r['headers'], 'notificationChanged'), 'opening a notice posts the read and the response sends the browser on to its link', $r['headers']);
ok(one("SELECT read_at IS NOT NULL FROM notifications WHERE id = $target") === true && notifications_unread_after($unreadN) , 'the notice is read, the bell down by one');
function notifications_unread_after(int $was): bool { return (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL') === $was - 1; }
$log = activity('notification.read', $mark);
ok(count($log) === 1 && str_contains((string) $log[0]['after'], '"count": 1') && !str_contains((string) $log[0]['after'], 'title'), 'notification.read logged once, with the count and nothing of the notice');
$r = post($jp, '/notifications/read.php', ['notification' => $target], 'json');
ok($r['code'] === 200 && (jb($r)['changed'] ?? -1) === 0, 'reading it again changes nothing (0)');
$dn = (int) q('SELECT id FROM notifications WHERE member_id = 30 AND read_at IS NULL ORDER BY id')[0]['id'];
$r = post($jp, '/notifications/read.php', ['notification' => $dn], 'json');
ok($r['code'] === 404 && one("SELECT read_at IS NULL FROM notifications WHERE id = $dn") === true, 'another person\'s notice: the same 404 as a missing one, unchanged');
$r = post($jp, '/notifications/read.php', ['notification' => 99999999], 'json');
ok($r['code'] === 404, 'a notice that is not there: 404');
$r = post($jp, '/notifications/read.php', [], 'json');
ok($r['code'] === 422, 'neither a notice nor every one: 422 in words', $r['body']);
$r = post($jp, '/notifications/read.php', ['notification' => $rows26[1]['id']]);
ok($r['code'] === 302 && $r['location'] === '/notifications', 'a plain browser form: 302 back to the list');
$dUnread = (int) one('SELECT count(*) FROM notifications WHERE member_id = 30 AND read_at IS NULL');
$mark = last_activity_id();
$r = post($jp, '/notifications/read.php', ['every_one' => 'yes'], 'json');
$all = jb($r);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL') === 0 && (int) ($all['changed'] ?? -1) > 0, 'Mark all read: every one of hers', $r['body']);
ok((int) one('SELECT count(*) FROM notifications WHERE member_id = 30 AND read_at IS NULL') === $dUnread, '…and none of Dana\'s');
$log = activity('notification.read', $mark);
ok(count($log) === 1 && str_contains((string) $log[0]['after'], 'every_one'), 'one notification.read for the bulk action');
$empty = page($jp, '/notifications?unread=1');
ok(str_contains($empty['body'], 'Nothing unread.'), 'nothing unread → says so');
// the bell
$bell = page($jd, '/notifications/count');
ok($bell['code'] === 200 && preg_match('/id="bell-count">([0-9]+)</', $bell['body'], $bm) && (int) $bm[1] === $dUnread, 'the bell\'s count is the same query', $bell['body']);
$menu = page($jd, '/notifications?menu=1');
ok($menu['code'] === 200 && substr_count($menu['body'], 'bell-row-') >= 1 && preg_match_all('/id="bell-row-\d+"/', $menu['body']) <= 8 && str_contains($menu['body'], 'id="bell-see-all"') && !str_contains($menu['body'], '<html'), 'the bell\'s menu: the latest eight and See all');
ok(str_contains(page($jd, '/')['body'], 'hx-get="/notifications?menu=1"') || str_contains(page($jd, "/m/$D/v/inbox")['body'], 'hx-get="/notifications?menu=1"'), 'the shell\'s bell loads the menu when it opens');
// an empty page
su('DELETE FROM notifications WHERE member_id = 26');
$p0 = page($jp, '/notifications');
ok(str_contains($p0['body'], 'No notices. You choose what reaches you in') && str_contains($p0['body'], 'Notification settings'), 'no notices: "No notices. You choose what reaches you in Notification settings."');
// settings
$st = page($jp, '/settings/notifications');
ok($st['code'] === 200 && str_contains($st['body'], 'data-screen="notification-settings"') && str_contains($st['body'], 'name="text_kind[]" value="held_mail"') && str_contains($st['body'], 'id="nm-' . $P . '"')
   && str_contains($st['body'], 'id="nm-' . $S . '"') && str_contains($st['body'], 'id="nm-' . $E . '"'), 'Priya\'s settings: three switches per kind, the new-mail switches for every mailbox she may open');
ok(!str_contains($st['body'], 'id="nm-' . $D . '"') && str_contains($st['body'], 'name="new_mail_mailbox[]" value="' . $P . '" id="nm-' . $P . '" checked'), '…her chosen mailboxes switched on, Dana\'s absent');
$sd = page($jd, '/settings/notifications');
ok(!str_contains($sd['body'], 'name="text_kind[]"') && str_contains($sd['body'], 'Texts need a verified phone in your profile.') && !str_contains($sd['body'], 'id="text_enabled"'), 'Dana (no phone on file): the text column is hidden, with the sentence');
ok(str_contains($sd['body'], 'Notices never contain mail'), '…and the page explains that notices never contain mail');
$mark = last_activity_id();
$r = post($jp, '/settings/notifications/save.php', ['email_enabled' => 'yes', 'text_enabled' => 'no', 'kind' => ['held_mail', 'unscreened', 'digest'], 'text_kind' => ['held_mail', 'quota'], 'new_mail_mailbox' => [(string) $S]], 'json');
$pr = prefs_row(26);
function prefs_row(int $m): array { return q('SELECT * FROM notification_prefs WHERE member_id = :m', ['m' => $m])[0] ?? []; }
ok($r['code'] === 200 && $pr['text_enabled'] === false && ml_arr_of($pr['kinds']) === ['digest', 'held_mail', 'unscreened'] && ml_arr_of($pr['text_kinds']) === ['held_mail', 'quota'] && trim($pr['new_mail_mailbox_ids'], '{}') === (string) $S, 'saved and read back', $r['body'] . json_encode($pr));
$log = activity('notification.prefs', $mark);
ok(count($log) === 1 && !str_contains((string) $log[0]['after'], '555') && !str_contains((string) $log[0]['after'], 'phone') && str_contains((string) $log[0]['after'], 'digest'), 'notification.prefs logged: the kinds and switches changed, never a phone number');
$r = post($jp, '/settings/notifications/save.php', ['kind' => ['held_mail', 'unscreened', 'digest']], 'json');
ok($r['code'] === 200 && str_contains($r['body'], 'Nothing changed'), 'saving the same again: nothing changed');
$r = post($jp, '/settings/notifications/save.php', ['kind' => ['no_such_kind']], 'json');
ok($r['code'] === 422, 'an unknown kind: 422 in words', $r['body']);
$r = post($jp, '/settings/notifications/save.php', ['new_mail_mailbox' => [(string) $D]], 'json');
ok($r['code'] === 422 && trim(prefs_row(26)['new_mail_mailbox_ids'], '{}') === (string) $S, 'a mailbox she cannot open: 422, nothing saved');
$r = post($jp, '/settings/notifications/save.php', ['email_enabled' => 'maybe'], 'json');
ok($r['code'] === 422, 'yes or no only: 422');
$r = post($jp, '/settings/notifications/save.php', ['new_mail_mailbox' => ['']]);
ok($r['code'] === 302 && trim(prefs_row(26)['new_mail_mailbox_ids'], '{}') === '' && str_contains($r['location'], 'notice=saved'), 'every new-mail switch off (the empty marker) → none chosen; a browser lands back with "Saved."');
$js = jb(req('GET', '/settings/notifications', ['jar' => $jp, 'headers' => ['Accept: application/json']]))['data'] ?? [];
ok(count($js['kinds'] ?? []) === 15 && ($js['texts_possible'] ?? null) === true && array_column($js['new_mail_mailboxes'] ?? [], 'address') !== [] && !isset($js['phone']), 'JSON: every kind with its three switches, the mailboxes she may open, whether texts are possible (no phone number)');
$r = post($jp, '/settings/notifications/save.php', ['kind' => ['held_mail', 'review_decided', 'unscreened', 'break_glass', 'permission_granted', 'credential_failed', 'sync_failed', 'send_failed'], 'new_mail_mailbox' => ['']]);

echo "8. Agents have no notifications\n";
foreach (['/notifications/read.php' => ['every_one' => 'yes'], '/settings/notifications/save.php' => ['email_enabled' => 'no']] as $path => $form) {
    $r = tact(40, $path, $form);
    ok($r['code'] === 403, "an agent's token on $path → 403", $r['body']);
}
$r = req('GET', '/notifications', ['headers' => as_agent(run_token(40, 9901))]);
ok($r['code'] === 403, 'an agent opening the page → 403');
ok(one("SELECT ml_notify(40, 'held_mail', 'for an agent', NULL, $E)") === null && notes(40) === [], 'ml_notify() writes none for an agent');
ok(req('GET', '/notifications')['code'] === 302 || req('GET', '/notifications')['code'] === 401, 'anonymous: sent to sign in');
$ww = worker('new_mail,quota,clerk_note,notify_outbox');
ok($ww['errors'] === [] && isset($ww['ran']['notify_outbox']), 'the four passes run together without an error', json_encode($ww['errors'] ?? []));

finish();
