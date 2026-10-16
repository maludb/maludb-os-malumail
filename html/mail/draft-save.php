<?php
declare(strict_types=1);
/**
 * Action `mail_draft_save` (log `draft.save` — once, when the draft is made): the composer's autosave and an agent's draft. Triage.
 * Fields: draft (to replace), identity, to[], cc[], bcc[], subject, body, body_format (text or html), reply_to (a message key id), mode,
 * version (the saved_at the client holds — an older one loses: conflict), close (1: file it in Drafts now, synchronously).
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
$errors = [];
$to = compose_addresses(compose_posted('to'), 'To', $errors);
$cc = compose_addresses(compose_posted('cc'), 'Cc', $errors);
$bcc = compose_addresses(compose_posted('bcc'), 'Bcc', $errors);
$errors = [];                                                 // a draft keeps what was typed, even an address not finished
$format = (string) (req_val('body_format') ?? 'text');
$body = (string) (req_val('body') ?? '');
$html = null;
$text = $body;
if ($format === 'html') {
    $clean = mailer_sanitise($body, 'compose');
    if ($clean !== null) {
        $html = $clean['html'];
        $text = $clean['text'];
    } else {
        $html = null;
        $text = mime_text_of_html($body);                     // the service down: the text alone is kept (never unsanitised HTML)
    }
}
$replyTo = request_integer('reply_to');
$irt = null;
$refs = [];
if ($replyTo !== null) {
    $st = $pdo->prepare('SELECT message_id_header, references_ids FROM mcp_messages WHERE mailbox_id = :mb AND message_key_id = :k ORDER BY id LIMIT 1');
    $st->execute(['mb' => $mb, 'k' => $replyTo]);
    $orig = $st->fetch();
    if ($orig === false) {
        refuse(404, 'No such message to answer.');
    }
    $irt = $orig['message_id_header'];
    $refs = array_values(array_filter(array_merge(ml_pg_text_array((string) $orig['references_ids']), [$orig['message_id_header']])));
}
$draftId = request_integer('draft');
$identity = request_integer('identity');
if ($identity !== null && !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_identities WHERE id = :id AND mailbox_id = :mb)', ['id' => $identity, 'mb' => $mb])) {
    $identity = null;
}
$res = draft_save($pdo, $mb, $draftId, ['identity_id' => $identity, 'to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => mb_substr((string) (req_val('subject') ?? ''), 0, 998),
    'body_html' => $html, 'body_text' => $text, 'reply_to_key_id' => $replyTo, 'mode' => (string) (req_val('mode') ?? 'new'), 'in_reply_to' => $irt, 'references_ids' => $refs,
    'version' => req_val('version')], $me);
if ($res['conflict']) {
    emit_action_status(false, ['error' => 'A newer version was saved elsewhere.', 'conflict' => true, 'record_id' => $res['draft'], 'version' => $res['version']]);
    if (wants_json()) {
        json_response(['error' => ['code' => 'conflict', 'message' => 'A newer version was saved elsewhere — open it.'], 'draft' => $res['draft'], 'version' => $res['version']], 409);
    }
    refuse(409, 'A newer version of this draft was saved elsewhere — open it.');
}
if ($res['created']) {
    log_activity($pdo, 'draft.save', 'mail_draft', $res['draft'], ['after' => ['mode' => (string) (req_val('mode') ?? 'new'), 'recipients' => count($to) + count($cc) + count($bcc)], 'mailbox_id' => $mb]);
}
$filed = null;
if (ml_yes('close', false)) {
    $filed = mailer_draft_append($mb, $res['draft']);
}
ml_done($res['created'] ? 'Draft saved' : 'Draft updated', $res['draft'], '/m/' . $mb . '/drafts/' . $res['draft'], 'draftSaved',
        ['version' => $res['version'], 'filed' => $filed !== null, 'draft' => $res['draft']]);
