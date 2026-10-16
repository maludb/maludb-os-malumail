<?php
declare(strict_types=1);
/** Action `mail_review_edit` (log `review.edit`, the digests before and after — never the text): the reviewer's subject and body replace the agent's, then it goes as an approval does. HTML is cleaned by the mail service; the recipients are not edited (oversight.md, divergence). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
$pdo = ov_handler_begin();
$o = ov_outbound($pdo, (int) request_integer('outbound'));
$errors = [];
$subject = ml_text('subject', (string) $o['subject'], 998, 'The subject', $errors);
$format = ml_choice('body_format', 'text', ['text', 'html'], 'The body\'s format', $errors, false);
$body = (string) (req_val('body') ?? '');
if (trim($body) === '') {
    $errors['body'] = 'Write the message you want sent.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$html = null;
$text = $body;
if ($format === 'html') {
    $clean = mailer_sanitise($body, 'compose');
    if ($clean === null) {
        refuse(503, 'The mail service is not answering, so the edit cannot be cleaned and sent right now. The message stays held.');
    }
    [$html, $text] = [$clean['html'], $clean['text']];
}
$rid = review_decide($pdo, (int) $o['id'], (int) current_member_id(), 'edit', null, ['subject' => $subject, 'body_text' => $text, 'body_html' => $html]);
$d = $pdo->prepare('SELECT before_digest, after_digest FROM mail_reviews WHERE id = :r');
$d->execute(['r' => $rid]);
$dig = $d->fetch() ?: [];
log_activity($pdo, 'review.edit', 'outbound_email', (int) $o['id'], ['after' => ['review' => $rid, 'before_digest' => $dig['before_digest'] ?? null, 'after_digest' => $dig['after_digest'] ?? null] + ov_log_subject($o), 'mailbox_id' => (int) $o['mailbox_id']]);
ml_done('Edited and approved: it goes in a few seconds', (int) $o['id'], ov_after_decision($pdo, (int) $o['id'], 'edited'), 'reviewChanged', ['next' => review_next($pdo, (int) $o['id'])]);
