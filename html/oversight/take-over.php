<?php
declare(strict_types=1);
/** Action `mail_thread_take_over` (log `thread.take_over`): a person answers this conversation — the agent muted on it, its held replies there cancelled with a take_over review each and the agent told (ml_thread_take_over); `taken_over` no gives it back. From the review page the conversation is the held reply's. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
$pdo = ov_handler_begin();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'view');
$mb = (int) $box['id'];
if ($box['kind'] === 'person' && (int) $box['owner_member_id'] !== (int) current_member_id()) {
    refuse(403, 'Only its owner takes over a conversation in a person\'s mailbox.');
}
$thread = request_integer('thread');
$outbound = request_integer('outbound');
if ($thread === null && $outbound !== null) {                       // the review page names the held reply
    $o = ov_outbound($pdo, $outbound);
    if ((int) $o['mailbox_id'] !== $mb || $o['reply_to_key_id'] === null) {
        refuse(422, 'Only a reply belongs to a conversation to take over: reject it instead.');
    }
    $thread = (int) one_value($pdo, 'SELECT ml_key_thread(:k)', ['k' => (int) $o['reply_to_key_id']]) ?: null;
}
if ($thread === null) {
    refuse(422, 'Choose the conversation to take over.');
}
$on = ml_yes('taken_over', true);
$n = thread_take_over($pdo, $mb, $thread, (int) current_member_id(), $on);
log_activity($pdo, 'thread.take_over', 'mail_thread', $thread, ['after' => ['taken_over' => $on, 'cancelled' => $n], 'mailbox_id' => $mb]);
$land = $outbound !== null ? ov_after_decision($pdo, $outbound, 'takenover') : ml_land(return_path('/m/' . $mb . '/t/' . $thread), $on ? 'takenover' : 'givenback');
ml_done($on ? 'Taken over: you answer this conversation; the agent will not' . ($n > 0 ? ' (' . $n . ' held ' . ($n === 1 ? 'reply' : 'replies') . ' cancelled)' : '') : 'Given back to the agent', $thread, $land, 'reviewChanged', ['cancelled' => $n, 'taken_over' => $on]);
