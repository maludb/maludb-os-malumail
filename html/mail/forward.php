<?php
declare(strict_types=1);
/**
 * Action `mail_forward` (log `message.forward`; undo mail_send_cancel): `message` (a key id) on to `to[]`: `Fwd:` once, the forwarded
 * header block and the original's body after the person's words, its attachments copied when attachments_included (yes by default).
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
$pre = compose_prefill($pdo, $mb, $box, 'forward', $key, (int) current_member_id());
if ($pre === null) {
    refuse(404, 'No such message to forward.');
}
$errors = [];
$fields = send_fields($pdo, $box, $errors);
if ($fields['to'] === [] && $fields['cc'] === [] && $fields['bcc'] === [] && !isset($errors['to']) && !isset($errors['cc']) && !isset($errors['bcc'])) {
    $errors['to'] = 'A forward needs a recipient.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
if ((string) (req_val('subject') ?? '') === '') {
    $fields['subject'] = $pre['subject'];
}
if (ml_yes('quote', true)) {
    $fields['body_text'] .= $pre['quote_text'];
    if ($fields['body_html'] !== null) {
        $fields['body_html'] .= $pre['quote_html'];
    } elseif ($fields['body_text'] !== '' && $pre['quote_html'] !== '') {
        $fields['body_html'] = '<div style="white-space:pre-wrap">' . e((string) (req_val('body') ?? '')) . '</div>' . $pre['quote_html'];
    }
}
$included = ml_yes('attachments_included', true);
$chosen = request_list('attachment_part');                    // the composer's checkboxes name the parts; none named = all
if ($included && $pre['attachments'] !== []) {
    $parts = $chosen === null || $chosen === [] ? $pre['attachments'] : array_values(array_filter($pre['attachments'], static fn (array $p): bool => in_array((string) $p['part'], $chosen, true)));
    $fields['extra_attachments'] = forward_copy_attachments($mb, (int) $pre['message_id'], $parts, (int) current_member_id());
}
$res = outbound_send($pdo, $mb, (int) current_member_id(), $fields);
send_answer($pdo, $box, $res, 'message.forward', send_log_counts($pdo, $fields) + ['forwards' => $key, 'attachments' => count($fields['extra_attachments'] ?? [])], send_undo_seconds($pdo, $box));
