<?php
declare(strict_types=1);
/**
 * POST /m/{mailbox}/drafts/{draft}/files — THE ATTACHMENT DOOR (writing.md "Attachments"): one file (≤ 7 MB; the draft's total ≤
 * max_attachments_mb) into ML_STORAGE_DIR/files and a row on the draft; `?remove=<attachment id>` takes one off. Answers the strip
 * (HTMX) or JSON. The draft's writer, or anyone with triage on the mailbox (the draft is the mailbox's).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'triage');
$mb = (int) $box['id'];
$draftId = (int) request_integer('draft');
$draft = draft_row($pdo, $mb, $draftId);
if ($draft === null) {
    respond_not_found('No such draft.');
}
if ($draft['discarded_at'] !== null) {
    refuse(422, 'This draft was discarded.');
}
$me = (int) current_member_id();
$remove = request_integer('remove');
if ($remove !== null) {
    $st = $pdo->prepare("SELECT * FROM attachments WHERE id = :id AND record_type = 'mail_draft' AND record_id = :d");
    $st->execute(['id' => $remove, 'd' => $draftId]);
    $a = $st->fetch();
    if ($a === false) {
        refuse(404, 'No such attachment.');
    }
    attachment_unlink($a);
    $pdo->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $remove]);
    $pdo->prepare('UPDATE mail_drafts SET saved_at = clock_timestamp() WHERE id = :id')->execute(['id' => $draftId]);
    emit_action_status(true, ['did' => 'Removed ' . $a['filename'], 'record_id' => $draftId]);
} else {
    $f = $_FILES['file'] ?? null;
    if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        refuse(422, 'Choose a file.');
    }
    if ((int) $f['error'] === UPLOAD_ERR_INI_SIZE || (int) $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        refuse(422, 'One file is at most 7 MB.');
    }
    if ((int) $f['error'] !== UPLOAD_ERR_OK) {
        refuse(422, 'The upload did not arrive whole; try again.');
    }
    $a = attachment_store($pdo, $draftId, (string) $f['tmp_name'], (string) $f['name'], (int) $f['size'], $me);
    $pdo->prepare('UPDATE mail_drafts SET saved_at = clock_timestamp() WHERE id = :id')->execute(['id' => $draftId]);
    emit_action_status(true, ['did' => 'Attached ' . $a['filename'], 'record_id' => $draftId, 'attachment' => $a['id']]);
}
$attachments = draft_attachments($pdo, $draftId);
if (wants_json()) {
    respond_saved(['draft' => $draftId, 'attachments' => array_map(static fn (array $x): array => ['id' => (int) $x['id'], 'name' => $x['filename'], 'type' => $x['mime_type'], 'size' => (int) $x['byte_size']], $attachments),
                   'total' => draft_attachment_total($pdo, $draftId)]);
}
$max = (int) db_value($pdo, 'SELECT max_attachments_mb FROM ml_settings WHERE id = 1');
echo view('compose/_attachments.php', ['box' => $box, 'draft' => $draftId, 'attachments' => $attachments, 'max' => ($max > 0 ? $max : 7) * 1024 * 1024]);
