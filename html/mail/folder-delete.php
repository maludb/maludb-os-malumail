<?php
declare(strict_types=1);
/** Action `mail_folder_delete` (log `folder.delete`, the folder's id; an agent's pauses for approval): an EMPTY folder is deleted — IMAP's DELETE would take its messages with it, so one with mail in it is refused with the count ("Move or delete its N messages first"). System folders cannot be deleted. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'triage');
$mb = (int) $box['id'];
$folder = request_integer('folder');
if ($folder === null) {
    ml_refuse_fields(['folder' => 'Choose a folder.']);
}
ml_folder_call($mb, 'folder_delete', ['folder' => $folder]);
log_activity($pdo, 'folder.delete', 'mail_folder', $folder, ['after' => ['folder' => $folder], 'mailbox_id' => $mb]);
ml_done('Deleted the folder', $folder, ml_land(return_path('/m/' . $mb . '/folders'), 'folder_deleted'), 'mailChanged');
