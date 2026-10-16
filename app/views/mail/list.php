<?php
/**
 * A mailbox's view or one of its folders as Gmail's conversation list (screens `mailbox-view`, `folder-view` — mail-list.md). The page
 * is #page-content; everything the list owns — the bulk bar, the pager, the rows, the "N new" bar — is inside #mail-list, which Older
 * and Newer swap whole (and the poll keeps up to date). Checking rows is plain JavaScript (assets/js/mail-list.js), no request.
 * Data: box, view, folder (mcp_folders' row or null), page (mail_list_page()), unread (bool), title, status (the mail service's, or null),
 *       tz, poll ([visible, hidden] seconds), density
 */
$id = (int) $box['id'];
$label = $label ?? null;
$unsnooze = $unsnooze ?? null;
$base = $label !== null ? '/m/' . $id . '/label/' . (int) $label['id'] : ($folder !== null ? '/m/' . $id . '/f/' . (int) $folder['id'] : '/m/' . $id . '/v/' . $view);
?>
<?= view('shared/header.php', ['id' => 'mail-list', 'title' => $title, 'crumbs' => [[$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $id . '/v/inbox'], [$title, null]]]) ?>
<div class="main-content mail-list-page" id="mail-page">
    <?php if (!empty($notice)): ?><?= view('shared/banner.php', ['notice' => $notice]) ?><?php endif; ?>
    <?php if (!empty($undo)): ?>
        <form method="post" action="/mail/move" class="alert alert-light d-flex align-items-center gap-2 mb-3" id="mail-undo-form" hx-post="/mail/move" hx-target="#page-content">
            <?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($base) ?>">
            <?php foreach ($undo as $u): ?><input type="hidden" name="restore[]" value="<?= (int) $u ?>"><?php endforeach; ?>
            <span>Done.</span><button type="submit" class="btn btn-sm btn-link p-0" id="mail-undo">Undo</button>
        </form>
    <?php endif; ?>
    <?php if (!empty($unsnooze)): ?>
        <form method="post" action="/mail/unsnooze" class="alert alert-light d-flex align-items-center gap-2 mb-3" id="mail-unsnooze-form" hx-post="/mail/unsnooze" hx-target="#page-content">
            <?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($base) ?>">
            <?php foreach ($unsnooze as $u): ?><input type="hidden" name="thread[]" value="<?= (int) $u ?>"><?php endforeach; ?>
            <span>Snoozed.</span><button type="submit" class="btn btn-sm btn-link p-0" id="mail-unsnooze">Undo</button>
        </form>
    <?php endif; ?>
    <?= view('mail/partials/rows.php', ['box' => $box, 'view' => $view, 'folder' => $folder, 'label' => $label, 'page' => $page, 'unread' => $unread, 'title' => $title,
        'status' => $status, 'tz' => $tz, 'poll' => $poll, 'density' => $density, 'base' => $base]) ?>
</div>
