<?php
/**
 * A mailbox's view or one of its folders as Gmail's conversation list (screens `mailbox-view`, `folder-view` — mail-list.md). The page
 * is #page-content; everything the list owns — the bulk bar, the pager, the rows, the "N new" bar — is inside #mail-list, which Older
 * and Newer swap whole (and the poll keeps up to date). Checking rows is plain JavaScript (assets/js/mail-list.js), no request.
 * Data: box, view, folder (mcp_folders' row or null), page (mail_list_page()), unread (bool), title, status (the mail service's, or null),
 *       tz, poll ([visible, hidden] seconds), density
 */
$id = (int) $box['id'];
$base = $folder !== null ? '/m/' . $id . '/f/' . (int) $folder['id'] : '/m/' . $id . '/v/' . $view;
?>
<?= view('shared/header.php', ['id' => 'mail-list', 'title' => $title, 'crumbs' => [[$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $id . '/v/inbox'], [$title, null]]]) ?>
<div class="main-content mail-list-page" id="mail-page">
    <?= view('mail/partials/rows.php', ['box' => $box, 'view' => $view, 'folder' => $folder, 'page' => $page, 'unread' => $unread, 'title' => $title,
        'status' => $status, 'tz' => $tz, 'poll' => $poll, 'density' => $density, 'base' => $base]) ?>
</div>
