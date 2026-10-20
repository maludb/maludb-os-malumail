<?php /** Sync health per mailbox (screen `admin-sync`). Data: rows, reached (the mail service answered), state (filter), tz, refresh (seconds) */
$tabs = ['' => 'All', 'failing' => 'Failing', 'syncing' => 'Syncing', 'failed' => 'Credential failed'];
?>
<?= view('shared/header.php', ['id' => 'admin-sync', 'title' => 'Sync health', 'crumbs' => [[app_name(), '/'], ['Admin', '/admin'], ['Sync health', null]]]) ?>
<div class="main-content" id="admin-sync-content">
    <?= view('admin/partials/switch-banner.php') ?>
    <?php if (!$reached): ?>
        <div class="alert alert-warning" id="admin-sync-down">The mail service is not answering. This shows the database's columns alone.</div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="admin-sync-chips">
        <?php foreach ($tabs as $k => $l): ?><?= hx_link('/admin/sync' . ($k === '' ? '' : '?state=' . $k), e($l), 'btn btn-sm btn-touch ' . ($state === $k ? 'btn-primary' : 'btn-light'), 'id="admin-sync-state-' . ($k === '' ? 'all' : $k) . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="admin-sync-empty"><?= $state === '' ? 'No mailbox is connected yet.' : 'No mailbox matches.' ?> <?= hx_link('/admin/mailboxes', 'Mailboxes', 'ms-1') ?></div></div>
    <?php else: ?>
    <div class="card"><div class="card-body p-0 px-2">
        <div class="row g-2 mx-0 py-2 border-bottom fw-semibold fs-12 text-muted d-none d-md-flex" id="admin-sync-head">
            <div class="col-md-3">Mailbox</div><div class="col-md-1">Sync</div><div class="col-md-1">Watcher</div><div class="col-md-2">Last sync</div><div class="col-md-2">Indexed</div><div class="col-md-1">Quota</div><div class="col-md-1">Credential</div>
        </div>
        <div id="admin-sync-rows" hx-get="/admin/sync?rows=1<?= $state === '' ? '' : '&state=' . e($state) ?>" hx-trigger="every <?= (int) $refresh ?>s [document.visibilityState === 'visible']" hx-target="this" hx-swap="innerHTML">
            <?php foreach ($rows as $r): ?><?= view('admin/partials/sync-row.php', ['r' => $r, 'tz' => $tz]) ?><?php endforeach; ?>
        </div>
    </div></div>
    <?php endif; ?>
</div>
