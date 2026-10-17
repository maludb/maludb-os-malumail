<?php /** Screen `notifications`. Data: rows, unread, unreadOnly, tz, notice, more (an id to continue from, or null) */ ?>
<?= view('shared/header.php', ['id' => 'notifications', 'title' => 'Notifications', 'crumbs' => [[app_name(), '/'], ['Notifications', null]],
    'action' => hx_link('/settings/notifications', '<i class="feather-settings me-1"></i>Settings', 'btn btn-light btn-sm', 'id="notifications-settings-link"')]) ?>
<div class="main-content" id="notifications-content">
    <?php if (!empty($notice)): ?><?= view('shared/banner.php', ['notice' => $notice]) ?><?php endif; ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="notifications-bar">
        <?= hx_link('/notifications' . ($unreadOnly ? '' : '?unread=1'), $unreadOnly ? 'Show all' : 'Unread only', 'btn btn-light btn-touch' . ($unreadOnly ? ' active' : ''), 'id="notifications-filter"') ?>
        <span class="fs-12 text-muted" id="notifications-unread"><?= (int) $unread ?> unread</span>
        <?php if ($unread > 0): ?>
        <form method="post" action="/notifications/read.php" class="ms-auto" id="notifications-read-all-form" hx-post="/notifications/read.php" hx-swap="none">
            <?= csrf_field() ?><input type="hidden" name="every_one" value="yes"><input type="hidden" name="return_to" value="/notifications<?= $unreadOnly ? '?unread=1' : '' ?>">
            <button type="submit" class="btn btn-primary btn-touch" id="notifications-read-all">Mark all read</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="notifications-none"><?= $unreadOnly ? 'Nothing unread.' : 'No notices. You choose what reaches you in ' ?><?php if (!$unreadOnly): ?><?= hx_link('/settings/notifications', 'Notification settings') ?>.<?php endif; ?></div></div>
    <?php else: ?>
    <div class="card"><div class="list-group list-group-flush" id="notifications-list">
        <?php foreach ($rows as $n): ?><?= view('notifications/_row.php', ['n' => $n, 'tz' => $tz]) ?><?php endforeach; ?>
    </div></div>
    <?php if ($more !== null): ?><div class="mt-3"><?= hx_link('/notifications?before=' . (int) $more . ($unreadOnly ? '&unread=1' : ''), 'Older notices', 'btn btn-light btn-touch', 'id="notifications-older"') ?></div><?php endif; ?>
    <?php endif; ?>
</div>
