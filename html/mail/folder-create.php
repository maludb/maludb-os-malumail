<?php
declare(strict_types=1);
/** Action `mail_folder_create` (log `folder.create`, the folder's id — never its name; undo mail_folder_delete): a new IMAP folder, optionally under an existing one (its full name). The mail service makes it; the server's delimiter joins the names. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'triage');
$mb = (int) $box['id'];
$errors = [];
$name = ml_text('name', null, 80, 'The folder\'s name', $errors, true);
$parent = ml_text('parent', null, 200, 'The folder to put it under', $errors);
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$res = ml_folder_call($mb, 'folder_create', ['name' => $name, 'parent' => $parent ?? '']);
log_activity($pdo, 'folder.create', 'mail_folder', (int) ($res['folder'] ?? 0), ['after' => ['nested' => $parent !== null], 'mailbox_id' => $mb]);
ml_done('Made the folder', (int) ($res['folder'] ?? 0), ml_land(return_path('/m/' . $mb . '/folders'), 'folder_created'), 'mailChanged', ['undo' => ['action' => 'mail_folder_delete', 'folder' => (int) ($res['folder'] ?? 0)]]);
