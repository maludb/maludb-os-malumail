<?php
declare(strict_types=1);
/** /m/{mailbox}/f/{folder} — one IMAP folder's conversations, the same list (screen `folder-view`, docs/build-specs/mail-list.md). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$pdo = db();
$st = $pdo->prepare('SELECT id, name, delimiter, special_use, messages_total, messages_unseen FROM mcp_folders WHERE id = :f AND mailbox_id = :mb');
$st->execute(['f' => (int) request_integer('folder'), 'mb' => (int) $box['id']]);
$folder = $st->fetch();
if ($folder === false) {
    respond_not_found('No such folder.');
}
mail_list_respond($pdo, $box, 'folder', $folder);
