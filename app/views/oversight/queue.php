<?php /** The held mail a person reviews (screen `review-queue`). Data: rows (review_queue), mailbox, reason (filters), boxes, notice, tz */
$link = static function (array $over) use ($mailbox, $reason): string {
    $q = array_filter(array_merge(['mailbox' => $mailbox, 'reason' => $reason], $over), static fn ($v): bool => $v !== '' && $v !== null);
    return '/oversight/review' . ($q === [] ? '' : '?' . http_build_query($q));
};
$self = $link([]);
?>
<?= view('shared/header.php', ['id' => 'review-queue', 'title' => 'Held mail', 'crumbs' => [[app_name(), '/'], ['Oversight', '/oversight'], ['Held mail', null]], 'back' => ['/oversight', 'Oversight']]) ?>
<div class="main-content" id="review-queue-content">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-2" id="review-filter-mailbox">
        <?= hx_link($link(['mailbox' => null]), 'Every mailbox', 'btn btn-sm btn-touch ' . ($mailbox === null ? 'btn-primary' : 'btn-light')) ?>
        <?php foreach ($boxes as $b): ?><?= hx_link($link(['mailbox' => (int) $b['id']]), e((string) $b['address']), 'btn btn-sm btn-touch ' . ($mailbox === (int) $b['id'] ? 'btn-primary' : 'btn-light'), 'id="review-filter-mailbox-' . (int) $b['id'] . '"') ?><?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="review-filter-reason">
        <?= hx_link($link(['reason' => null]), 'Any reason', 'btn btn-sm btn-touch ' . ($reason === null ? 'btn-primary' : 'btn-light')) ?>
        <?php foreach (OV_REASON_LABELS as $k => $l): ?><?= hx_link($link(['reason' => $k]), e($l), 'btn btn-sm btn-touch ' . ($reason === $k ? 'btn-primary' : 'btn-light'), 'id="review-filter-reason-' . $k . '"') ?><?php endforeach; ?>
    </div>
    <div id="review-rows" hx-get="<?= e($self) ?>" hx-trigger="every 30s [document.visibilityState=='visible']" hx-target="#review-rows" hx-swap="innerHTML">
        <?= view('oversight/_rows.php', ['rows' => $rows, 'tz' => $tz, 'mailbox' => $mailbox, 'reason' => $reason]) ?>
    </div>
</div>
