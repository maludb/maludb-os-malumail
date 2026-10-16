<?php /** One held message in the queue. Data: r (review_queue's row), tz */
$id = (int) $r['id'];
$recips = array_merge($r['to_addresses'], $r['cc_addresses'], $r['bcc_addresses']);
$tag = static function (string $a) use ($r): string {
    $out = in_array($a, $r['outside_recipients'], true);
    $new = in_array($a, $r['new_recipients'], true);
    return '<span class="' . ($out ? 'text-warning fw-semibold' : '') . '"' . ($out ? ' title="outside the business"' : '') . '>' . e($a) . '</span>' . ($new ? ' <span class="badge bg-soft-info text-info">new</span>' : '');
};
?>
<div class="list-group-item py-2" id="review-row-<?= $id ?>" data-outbound="<?= $id ?>">
    <div class="d-flex flex-wrap align-items-baseline gap-2">
        <?php if ($r['can_decide']): ?>
            <?= hx_link('/oversight/review/' . $id, e($r['subject'] !== '' ? $r['subject'] : '(no subject)'), 'fw-semibold text-break', 'id="review-row-' . $id . '-open"') ?>
        <?php else: ?>
            <span class="fw-semibold text-break"><?= e($r['subject'] !== '' ? $r['subject'] : '(no subject)') ?></span>
        <?php endif; ?>
        <span class="fs-11 text-muted ms-auto" id="review-row-<?= $id ?>-expires"><?= e(ov_expires_in($r['expires_at'])) ?></span>
    </div>
    <div class="fs-12 text-muted text-break"><?= e($r['mailbox_address']) ?> · by <?= e((string) ($r['sender_name'] ?? 'an agent')) ?> · to <?= implode(', ', array_map($tag, array_slice($recips, 0, 8))) ?><?= count($recips) > 8 ? ' …' : '' ?></div>
    <div class="mt-1" id="review-row-<?= $id ?>-reasons">
        <?php foreach ($r['hold_reasons'] as $why): ?><span class="badge bg-soft-warning text-warning me-1" data-reason="<?= e($why) ?>"><?= e(OV_REASON_LABELS[$why] ?? $why) ?></span><?php endforeach; ?>
        <?php if (!$r['can_decide']): ?><span class="fs-12 text-muted" id="review-row-<?= $id ?>-not-mine">For its overseers</span><?php endif; ?>
    </div>
</div>
