<?php
declare(strict_types=1);
/** Action `mail_folder_rename` (log `folder.rename`, the folder's id): a folder gets a new last name (its subfolders go with it); a mailbox's system folders keep their names. The mail service does it. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'triage');
$mb = (int) $box['id'];
$folder = request_integer('folder');
$errors = [];
$name = ml_text('name', null, 80, 'The folder\'s name', $errors, true);
if ($folder === null) {
    $errors['folder'] = 'Choose a folder.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$res = ml_folder_call($mb, 'folder_rename', ['folder' => $folder, 'name' => $name]);
log_activity($pdo, 'folder.rename', 'mail_folder', $folder, ['after' => ['folder' => $folder], 'mailbox_id' => $mb]);
ml_done('Renamed the folder', $folder, ml_land(return_path('/m/' . $mb . '/folders'), 'folder_renamed'), 'mailChanged');
