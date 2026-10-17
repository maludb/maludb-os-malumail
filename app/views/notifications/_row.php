<?php /** One notice, a card row. Data: n (notifications_list's row), tz, compact? (the bell's menu). Opening it marks it read (hx-post) and follows its link. */
$compact = $compact ?? false;
$id = (int) $n['id'];
$unread = $n['read_at'] === null;
[$label, $icon] = NT_KINDS[$n['kind']] ?? [$n['kind'], 'feather-bell'];
$prefix = $compact ? 'bell-row-' : 'notice-';
?>
<div class="list-group-item py-2 <?= $unread ? 'notice-unread' : '' ?>" id="<?= $prefix . $id ?>" data-kind="<?= e($n['kind']) ?>" data-unread="<?= $unread ? '1' : '0' ?>">
    <a href="<?= e($n['href']) ?>" class="d-flex gap-2 align-items-start text-reset text-decoration-none" id="<?= $prefix . $id ?>-open"<?php if ($unread): ?>
       hx-post="/notifications/read.php" hx-vals='<?= e(json_encode(['notification' => $id, 'return_to' => $n['href']], JSON_UNESCAPED_SLASHES)) ?>' hx-swap="none"<?php endif; ?>>
        <i class="<?= e($icon) ?> mt-1 text-muted flex-shrink-0" title="<?= e($label) ?>" aria-hidden="true"></i>
        <span class="flex-grow-1 min-w-0">
            <span class="d-block text-break <?= $unread ? 'fw-bold' : '' ?>" id="<?= $prefix . $id ?>-title"><?= e($n['title']) ?></span>
            <?php if (!$compact && ($n['body'] ?? '') !== ''): ?><span class="d-block fs-12 text-muted text-break" id="notice-<?= $id ?>-body"><?= nl2br(e($n['body'])) ?></span><?php endif; ?>
            <span class="d-block fs-11 text-muted">
                <?php if (($n['mailbox_address'] ?? null) !== null): ?><span class="notice-mailbox"><?= e($n['mailbox_address']) ?></span> · <?php endif; ?>
                <span class="notice-when"><?= e(nt_when((string) $n['created_at'], $tz)) ?></span>
            </span>
        </span>
        <?php if ($unread): ?><span class="badge bg-danger rounded-pill flex-shrink-0 mt-1" aria-label="unread">new</span><?php endif; ?>
    </a>
</div>
