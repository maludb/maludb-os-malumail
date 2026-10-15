<?php
/**
 * The attachments under a message (reading-and-rendering.md §5): name, type, decoded size, an inline preview for a raster image through
 * /p/ (the door decides inline by the bytes), a download link. An HTML or SVG attachment is never opened inline. Without a fetch (the
 * service down) the names alone, from the index. Data: box, m, attachments (the fetch's parts), ref, names
 */
$mb = (int) $box['id'];
$me = (int) current_member_id();
?>
<div class="mail-attachments mt-3" id="msg-<?= (int) $m['id'] ?>-attachments">
    <div class="fs-12 text-muted mb-1"><i class="feather-paperclip me-1"></i><?= count($attachments ?: $names) ?> attachment<?= count($attachments ?: $names) === 1 ? '' : 's' ?></div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($attachments === []): foreach ($names as $n): ?>
            <span class="badge bg-soft-secondary text-secondary fw-normal mail-attachment"><?= e($n) ?></span>
        <?php endforeach; else: foreach ($attachments as $p): $isRaster = in_array(strtolower((string) $p['type']), ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
            $tok = ml_part_sign('part', $mb, $ref, (string) $p['part'], $me); ?>
            <div class="mail-attachment border rounded p-2 d-flex align-items-center gap-2" data-part="<?= e((string) $p['part']) ?>">
                <?php if ($isRaster): ?><img src="/p/<?= e($tok) ?>" alt="" loading="lazy"><?php else: ?><i class="feather-file fs-4 text-muted"></i><?php endif; ?>
                <div class="min-w-0">
                    <div class="text-truncate" style="max-width:14rem"><?= e((string) ($p['name'] ?? ($p['type'] ?? 'file'))) ?></div>
                    <div class="fs-12 text-muted"><?= e((string) $p['type']) ?> · <?= e(ml_size_label((int) ($p['size'] ?? 0))) ?></div>
                    <a href="/p/<?= e($tok) ?>" class="fs-12" download="<?= e(ml_safe_filename($p['name'] ?? null)) ?>">Download</a>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>
