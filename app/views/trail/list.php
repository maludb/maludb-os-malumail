<?php /** My trail (screen `my-trail`; the tab bar's Me). Data: rows, more, page, query, tz */ ?>
<?= view('shared/header.php', ['id' => 'trail', 'title' => 'My trail', 'crumbs' => [[app_name(), '/'], ['My trail', null]]]) ?>
<div class="main-content">
    <div class="d-flex flex-wrap gap-2 mb-3" id="me-links">
        <?= hx_link('/tokens', '<i class="feather-key me-1"></i>Tokens', 'btn btn-light btn-sm') ?>
        <?= hx_link('/notifications', '<i class="feather-bell me-1"></i>Notifications', 'btn btn-light btn-sm') ?>
        <?= hx_link('/settings/notifications', '<i class="feather-settings me-1"></i>My settings', 'btn btn-light btn-sm') ?>
        <?= hx_link('/notice', '<i class="feather-file-text me-1"></i>Monitoring notice', 'btn btn-light btn-sm') ?>
        <form method="post" action="/logout.php" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-light btn-sm" id="trail-signout"><i class="feather-log-out me-1"></i>Sign out</button></form>
    </div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="trail-empty">Nothing yet.</div></div>
    <?php else: ?>
    <ul class="list-group" id="trail-list">
        <?php foreach ($rows as $r): ?>
        <li class="list-group-item d-flex justify-content-between gap-2" id="trail-row-<?= (int) $r['id'] ?>">
            <div class="min-w-0"><div class="fw-semibold text-truncate"><?= e(trail_sentence($r)) ?></div><?php if ($r['mailbox']): ?><div class="fs-12 text-muted text-truncate"><?= e($r['mailbox']) ?></div><?php endif; ?></div>
            <div class="fs-12 text-muted text-nowrap"><?= e(format_ts((string) $r['occurred_at'], $tz)) ?></div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($more): ?><div class="mt-3"><?= hx_link('/my/trail?' . http_build_query($query + ['page' => $page + 1]), 'Older', 'btn btn-light btn-sm', 'id="trail-more"') ?></div><?php endif; ?>
    <?php endif; ?>
</div>
