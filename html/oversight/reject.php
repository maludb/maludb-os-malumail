<?php
declare(strict_types=1);
/** Action `mail_review_reject` (log `review.reject`): held → rejected; the note (required) reaches the agent in its next turn — one dispatch, kind review, the reviewer the acting member (ml_review_dispatch). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
$pdo = ov_handler_begin();
$o = ov_outbound($pdo, (int) request_integer('outbound'));
$errors = [];
$note = ml_text('note', null, 2000, 'A note for the agent', $errors, true);
if ($errors !== [] || $note === null || trim($note) === '') {
    ml_refuse_fields(['note' => 'Say why, for the agent: a rejection carries a note.']);
}
$rid = review_decide($pdo, (int) $o['id'], (int) current_member_id(), 'reject', $note, null);
log_activity($pdo, 'review.reject', 'outbound_email', (int) $o['id'], ['after' => ['review' => $rid, 'note_chars' => mb_strlen((string) $note)] + ov_log_subject($o), 'mailbox_id' => (int) $o['mailbox_id']]);
ml_done('Rejected: the agent is told why', (int) $o['id'], ov_after_decision($pdo, (int) $o['id'], 'rejected'), 'reviewChanged', ['next' => review_next($pdo, (int) $o['id'])]);
