<?php /** The attachment strip (writing.md "Attachments"): name, size, remove; the total and the limit. Data: box, draft (id|null), attachments, max (bytes) */
$total = array_sum(array_map(static fn (array $a): int => (int) $a['byte_size'], $attachments)); ?>
<div class="mail-attachment-strip" id="compose-attachment-strip" data-total="<?= $total ?>">
    <?php foreach ($attachments as $a): ?>
        <div class="d-inline-flex align-items-center gap-2 border rounded px-2 py-1 me-2 mb-2 fs-13 mail-attachment-chip" data-id="<?= (int) $a['id'] ?>">
            <i class="feather-file text-muted"></i><span class="text-truncate" style="max-width:14rem"><?= e($a['filename']) ?></span>
            <span class="text-muted"><?= e(ml_size_label((int) $a['byte_size'])) ?></span>
            <?php if ($draft !== null): ?>
                <button type="button" class="btn btn-sm btn-link p-0 mail-attachment-remove" data-url="/m/<?= (int) $box['id'] ?>/drafts/<?= (int) $draft ?>/files?remove=<?= (int) $a['id'] ?>" aria-label="Remove <?= e($a['filename']) ?>"><i class="feather-x"></i></button>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($attachments !== []): ?><div class="fs-12 text-muted mb-2" id="compose-attachment-total"><?= e(ml_size_label($total)) ?> of <?= e(ml_size_label($max)) ?></div><?php endif; ?>
</div>
