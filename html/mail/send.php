<?php
declare(strict_types=1);
/**
 * Action `mail_send` (log `message.send` / `message.schedule`; undo mail_send_cancel): a new message. Send on the mailbox; an agent's
 * message needs a purpose and passes the policy in ml_outbound_submit() (held or queued, no undo window — sending-and-oversight.md §2–§3).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'send');
$mb = (int) $box['id'];
$errors = [];
$fields = send_fields($pdo, $box, $errors);
if ($fields['to'] === [] && $fields['cc'] === [] && $fields['bcc'] === [] && !isset($errors['to']) && !isset($errors['cc']) && !isset($errors['bcc'])) {
    $errors['to'] = 'A message needs a recipient.';
}
if (trim($fields['body_text']) === '' && ($fields['body_html'] === null || trim(strip_tags($fields['body_html'])) === '')) {
    $errors['body'] = 'Say something: the message is empty.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
if (ml_yes('compose_format_remember', false)) {
    compose_format_pref($pdo, (int) current_member_id(), (req_val('body_format') ?? 'text') === 'html' ? 'html' : 'text');
}
$res = outbound_send($pdo, $mb, (int) current_member_id(), $fields);
send_answer($pdo, $box, $res, 'message.send', send_log_counts($pdo, $fields), send_undo_seconds($pdo, $box));
