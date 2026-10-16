<?php
/** A mailbox's folders (screen `folders`). Data: box, folders (ml_folder_list()), canManage, notice */
$mb = (int) $box['id'];
$names = array_values(array_filter(array_map(static fn (array $f): string => (string) $f['name'], $folders), static fn (string $n): bool => strtoupper($n) !== 'INBOX'));
?>
<?= view('shared/header.php', ['id' => 'folders', 'title' => 'Folders', 'crumbs' => [[$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $mb . '/v/inbox'], ['Folders', null]], 'back' => ['/m/' . $mb . '/v/inbox', 'Inbox']]) ?>
<div class="main-content" id="folders-content">
    <?php if (!empty($notice)): ?><?= view('shared/banner.php', ['notice' => $notice]) ?><?php endif; ?>
    <div class="card mb-3"><div class="list-group list-group-flush" id="folder-rows" role="list">
    <?php foreach ($folders as $f): $fid = (int) $f['id']; $title = ml_folder_title($f); ?>
        <div class="list-group-item folder-row" id="folder-<?= $fid ?>" role="listitem" style="padding-left: <?= 1 + (int) $f['depth'] * 0.75 ?>rem">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <i class="<?= $f['system'] ? 'feather-folder text-muted' : 'feather-folder' ?>"></i>
                <span class="fw-semibold folder-name"><?= e($title) ?></span>
                <?php if ($f['system']): ?><span class="badge bg-soft-secondary text-secondary fw-normal folder-system">System</span><?php endif; ?>
                <span class="ms-auto fs-12 text-muted folder-count"><?= number_format($f['total']) ?> <?= $f['total'] === 1 ? 'message' : 'messages' ?><?= $f['unseen'] > 0 ? ' · ' . number_format($f['unseen']) . ' unread' : '' ?></span>
            </div>
            <?php if ($canManage && !$f['system']): ?>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <form method="post" action="/mail/folder-rename" hx-post="/mail/folder-rename" hx-target="#page-content" class="d-flex gap-1 flex-grow-1" id="folder-<?= $fid ?>-rename-form">
                        <?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="folder" value="<?= $fid ?>">
                        <label class="visually-hidden" for="folder-<?= $fid ?>-name">New name for <?= e($title) ?></label>
                        <input type="text" name="name" id="folder-<?= $fid ?>-name" class="form-control form-control-sm btn-touch" value="<?= e($f['leaf']) ?>" maxlength="80" required autocapitalize="none">
                        <button type="submit" class="btn btn-sm btn-light btn-touch" id="folder-<?= $fid ?>-rename">Rename</button>
                    </form>
                    <form method="post" action="/mail/folder-delete" hx-post="/mail/folder-delete" hx-target="#page-content" id="folder-<?= $fid ?>-delete-form"
                          hx-confirm="Delete <?= e($title) ?>? <?= $f['total'] > 0 ? 'It has mail in it, so it will be refused: move or delete its ' . (int) $f['total'] . ' messages first.' : 'It is empty.' ?>">
                        <?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="folder" value="<?= $fid ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger btn-touch" id="folder-<?= $fid ?>-delete">Delete</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($folders === []): ?><div class="list-group-item text-muted" id="folders-none">This mailbox has no folders yet: they appear when it first syncs.</div><?php endif; ?>
    </div></div>
    <?php if ($canManage): ?>
    <form method="post" action="/mail/folder-create" hx-post="/mail/folder-create" hx-target="#page-content" class="card" id="folder-new-form">
        <div class="card-header"><h5 class="card-title mb-0">Make a folder</h5></div>
        <div class="card-body"><?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>">
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="folder-new-name">Name</label>
                <input type="text" name="name" id="folder-new-name" class="form-control btn-touch" required maxlength="80" autocapitalize="none" placeholder="Acme"></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="folder-new-parent">Under (optional — an existing folder's full name)</label>
                <input type="text" name="parent" id="folder-new-parent" class="form-control btn-touch" list="folder-new-parents" maxlength="200" autocapitalize="none" placeholder="Projects">
                <datalist id="folder-new-parents"><?php foreach ($names as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?></datalist></div>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="folder-new-save">Make the folder</button>
        </div>
    </form>
    <?php endif; ?>
</div>
