<?php
declare(strict_types=1);
/** /oversight/review/{outbound}/edit — edit a held message before it goes: subject and body in slice 4's editor (Squire), the recipients read-only; Save and send posts mail_review_edit (screen `review-edit`). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/oversight/queries.php';
require_login();
require_human();
if (!has_right('mail.oversee')) {
    refuse(403, right_refusal('mail.oversee'));
}
$pdo = db();
$id = (int) request_integer('id');
$q = review_get($pdo, (int) current_member_id(), $id);
if ($q === null) {
    respond_not_found('No such message.');
}
if ($q['decided']) {
    redirect('/oversight/review/' . $id);
}
if (!$q['can_decide']) {
    refuse(403, 'Deciding this message is its overseers\'.');
}
log_screen_view($pdo, 'review-edit', (int) $q['mailbox_id']);
if (wants_json()) {
    respond_screen(['outbound' => $id, 'subject' => (string) $q['subject'], 'body_text' => (string) $q['body_text'], 'has_html' => $q['body_html'] !== null,
                    'to' => $q['to_addresses'], 'cc' => $q['cc_addresses'], 'bcc' => $q['bcc_addresses']]);
}
render_screen('Edit before sending', view('oversight/review_edit.php', ['q' => $q]), ['screen' => 'review-edit', 'entity' => 'outbound', 'recordId' => $id, 'mailboxId' => (int) $q['mailbox_id'], 'activeNav' => 'oversight']);
