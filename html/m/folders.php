<?php
declare(strict_types=1);
/**
 * /m/{mailbox}/folders — the mailbox's IMAP folders with total and unread, system ones marked; a form to make one (under an existing folder),
 * rename in a row's own form, delete with a confirmation — refused for a folder with mail in it (screen `folders`,
 * docs/build-specs/search-labels-snooze.md). Anyone who opens the mailbox sees it; the forms are triage's.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$mb = (int) $box['id'];
$folders = ml_folder_list($pdo, $mb);
log_screen_view($pdo, 'folders', $mb);
$canManage = (ML_LEVELS[(string) $box['my_level']] ?? 0) >= 2;
if (wants_json()) {
    respond_screen(['mailbox_id' => $mb, 'can_manage' => $canManage, 'folders' => array_map(static fn (array $f): array => [
        'folder_id' => (int) $f['id'], 'name' => $f['name'], 'special_use' => $f['special_use'], 'system' => $f['system'], 'unread' => $f['unseen'], 'total' => $f['total']], $folders)]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['folder_created' => ['success', 'Made the folder.'], 'folder_renamed' => ['success', 'Renamed the folder.'], 'folder_deleted' => ['success', 'Deleted the folder.']]);
render_screen('Folders — ' . $box['address'], view('mail/folders.php', ['box' => $box, 'folders' => $folders, 'canManage' => $canManage, 'notice' => $notice]),
    ['screen' => 'folders', 'entity' => 'mailbox', 'recordId' => $mb, 'mailboxId' => $mb]);
