<?php
declare(strict_types=1);
/** Action `mail_mute` (log `thread.mute`, one row with a count): mute conversations (Gmail's) — later mail in them skips the Inbox's attention and never wakes an agent; `muted` no unmutes. Triage; an agent may (design §5: an agent stops following a conversation; taking it over is a person's). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'triage');
$mb = (int) $box['id'];
$threads = array_values(array_filter(array_map('intval', request_list('thread') ?? []), static fn (int $t): bool => $t > 0));
if ($threads === []) {
    refuse(422, 'Choose at least one conversation.');
}
$on = ml_yes('muted', true);
$n = thread_mute($pdo, $mb, $threads, (int) current_member_id(), $on);
log_activity($pdo, 'thread.mute', 'mail_thread', $threads[0], ['after' => ['count' => $n, 'muted' => $on], 'mailbox_id' => $mb]);
ml_done(($on ? 'Muted ' : 'Unmuted ') . ($n === 1 ? '1 conversation' : $n . ' conversations'), $threads[0], ml_land(return_path('/m/' . $mb . '/t/' . $threads[0]), $on ? 'muted' : 'unmuted'), 'mailChanged',
        ['count' => $n, 'undo' => ['action' => 'mail_mute', 'thread' => $threads, 'muted' => !$on]]);
