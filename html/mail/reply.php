<?php
declare(strict_types=1);
/**
 * Action `mail_reply` (log `message.reply`; undo mail_send_cancel): an answer to `message` (a message key id). To = the original's
 * Reply-To, else From; reply_all adds its To and Cc minus the mailbox's own addresses; cc[]/bcc[] are added to that; `Re:` once; the
 * threading headers are ml_outbound_submit()'s from the key. Send on the mailbox.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'send');
$mb = (int) $box['id'];
$key = (int) request_integer('message');
$all = ml_yes('reply_all', false);
$pre = compose_prefill($pdo, $mb, $box, $all ? 'reply_all' : 'reply', $key, (int) current_member_id());
if ($pre === null) {
    refuse(404, 'No such message to answer.');
}
$errors = [];
$fields = send_fields($pdo, $box, $errors);
$fields['to'] = $pre['to'];
$fields['cc'] = array_values(array_unique(array_merge($pre['cc'], $fields['cc'])));
$fields['reply_to_key_id'] = $pre['reply_to_key_id'];
if ((string) (req_val('subject') ?? '') === '') {
    $fields['subject'] = $pre['subject'];
}
if (trim($fields['body_text']) === '' && ($fields['body_html'] === null || trim(strip_tags($fields['body_html'])) === '')) {
    $errors['body'] = 'Say something: the reply is empty.';
}
if ($fields['to'] === []) {
    $errors['to'] = 'The message has no one to answer.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
if (ml_yes('quote', true)) {                                   // an agent's reply and a no-JS form quote the original; the composer sends its own quote inside the body
    $fields['body_text'] .= $pre['quote_text'];
    if ($fields['body_html'] !== null) {
        $fields['body_html'] .= $pre['quote_html'];
    }
}
$res = outbound_send($pdo, $mb, (int) current_member_id(), $fields);
send_answer($pdo, $box, $res, 'message.reply', send_log_counts($pdo, $fields) + ['answers' => $key], send_undo_seconds($pdo, $box));
