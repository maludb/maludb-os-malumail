<?php
declare(strict_types=1);
/** Action `mail_review_reply` (log `review.reply`): a question to the agent — the message stays held, the note (required) reaches the agent in one turn; it may withdraw (mail_send_cancel) and write again. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
$pdo = ov_handler_begin();
$o = ov_outbound($pdo, (int) request_integer('outbound'));
$errors = [];
$note = ml_text('note', null, 2000, 'A note for the agent', $errors, true);
if ($errors !== [] || $note === null || trim($note) === '') {
    ml_refuse_fields(['note' => 'Write the question for the agent.']);
}
$rid = review_decide($pdo, (int) $o['id'], (int) current_member_id(), 'reply', $note, null);
log_activity($pdo, 'review.reply', 'outbound_email', (int) $o['id'], ['after' => ['review' => $rid, 'note_chars' => mb_strlen((string) $note)] + ov_log_subject($o), 'mailbox_id' => (int) $o['mailbox_id']]);
ml_done('Sent to the agent; the message stays held', (int) $o['id'], ov_after_decision($pdo, (int) $o['id'], 'replied'), 'reviewChanged', ['next' => review_next($pdo, (int) $o['id'])]);
