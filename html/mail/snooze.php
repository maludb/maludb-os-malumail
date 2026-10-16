<?php
declare(strict_types=1);
/**
 * Action `mail_snooze` (log `thread.snooze`; undo mail_unsnooze): the conversation's Inbox mail moves to the Snoozed folder (the mail
 * service makes it by name the first time) and the conversation comes back, unread, at `until` — the worker's snoozes pass. `until` is an
 * ISO time (the choices the list offers are UTC instants of the person's own 18:00 / 08:00); `until_local` (a datetime-local value) is read
 * in the person's time zone. Triage; never an intake mailbox's Inbox (the schema says why). An agent may: it is woken when the time comes.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'triage');
$mb = (int) $box['id'];
$me = (int) current_member_id();
$threads = request_list('thread') ?? [];
$matching = req_val('matching');
if ($threads === [] && ($matching === null || trim($matching) === '')) {
    refuse(422, 'Choose at least one conversation.');
}
$raw = (string) (req_val('until') ?? '');
$local = (string) (req_val('until_local') ?? '');
try {
    $until = $raw !== '' ? new DateTimeImmutable($raw) : ($local !== '' ? new DateTimeImmutable($local, new DateTimeZone(member_timezone())) : null);
} catch (Exception) {
    $until = null;
}
if ($until === null) {
    ml_refuse_fields(['until' => 'Say when the conversation comes back.']);
}
if ($until <= new DateTimeImmutable('now +30 seconds')) {
    ml_refuse_fields(['until' => 'A snooze ends in the future.']);
}
$untilIso = $until->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$threadIds = [];
$targets = ml_thread_targets($pdo, $mb, $threads, $matching, 'snooze', null, null, $threadIds);
if ($targets === []) {
    refuse(422, ML_OP_NOTHING['snooze']);
}
$res = ml_mail_act($pdo, $mb, 'snooze', $targets);
if ($res['count'] === 0) {
    refuse(422, $res['refused'][0]['reason'] ?? 'Nothing was snoozed.');
}
// the state: one anchor per conversation (its latest Inbox message), from the Inbox it came out of
$done = [];
$moved = array_column($res['moved'] ?? [], 'message');
foreach ($targets as $r) {
    $t = (int) $r['thread_id'];
    if (isset($done[$t]) || ($moved !== [] && !in_array((int) $r['id'], array_map('intval', $moved), true))) {
        continue;
    }
    $done[$t] = true;
    try {
        $st = $pdo->prepare('SELECT ml_thread_snooze(:mb, :k, :me, CAST(:until AS timestamptz), :f)');
        $st->execute(['mb' => $mb, 'k' => (int) $r['message_key_id'], 'me' => $me, 'until' => $untilIso, 'f' => (int) $r['folder_id']]);
    } catch (Throwable $e) {
        // the mail has moved and the state could not be kept: put it back rather than leave it where nothing will wake it
        try {
            $back = array_values(array_filter($targets, static fn (array $x): bool => (int) $x['thread_id'] === $t));
            $dest = ml_folders_of($pdo, $mb)[1]['inbox'] ?? null;
            if ($dest !== null) {
                $rows = $pdo->prepare('SELECT m.id, m.message_key_id, m.folder_id, m.uidvalidity, m.uid FROM mail_messages m WHERE m.id = ANY (CAST(:ids AS bigint[]))');
                $rows->execute(['ids' => '{' . implode(',', array_map(static fn (array $x): int => (int) $x['id'], $back)) . '}']);
                ml_mail_act($pdo, $mb, 'move', $rows->fetchAll(), ['to_folder' => $dest]);
            }
        } catch (Throwable) {
        }
        if ($e instanceof PDOException && in_array((string) $e->getCode(), ['42501', '22023', 'P0001'], true)) {
            refuse(422, db_message($e, 'That could not be snoozed.'));
        }
        throw $e;
    }
}
$count = count($done);
log_activity($pdo, 'thread.snooze', 'mail_thread', $threadIds[0] ?? null, ['after' => ['count' => $count, 'until' => $untilIso] + (($matching ?? '') !== '' ? ['matching' => true] : []) + ($res['refused'] !== [] ? ['refused' => array_sum(array_column($res['refused'], 'count'))] : []), 'mailbox_id' => $mb]);
$land = mail_action_land($mb, 'snoozed', null, $count) . '&unsnooze=' . implode(',', array_slice(array_map('intval', array_keys($done)), 0, 100));
ml_done('Snoozed ' . ($count === 1 ? '1 conversation' : $count . ' conversations') . ' until ' . format_ts($untilIso, member_timezone(), 'M j, g:i A'), $threadIds[0] ?? null, $land, 'mailChanged',
        ['count' => $count, 'until' => $untilIso, 'refused' => $res['refused'], 'undo' => ['action' => 'mail_unsnooze', 'thread' => array_map('intval', array_keys($done))]]);
