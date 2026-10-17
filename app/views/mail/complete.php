<?php
/** The recipient suggestions (GET /m/{mailbox}/complete): a listbox the composer's script fills under the field. Data: rows, q */
?>
<?php foreach ($rows as $i => $r): ?>
<li class="list-group-item list-group-item-action py-2 mail-suggestion" role="option" id="suggest-<?= $i ?>" data-address="<?= e($r['address']) ?>" data-name="<?= e((string) ($r['name'] ?? '')) ?>" data-source="<?= e($r['source']) ?>">
    <span class="d-block text-truncate suggest-name"><?= e(($r['name'] ?? '') !== '' ? $r['name'] : $r['address']) ?><?php if ($r['outside']): ?> <span class="badge bg-soft-warning text-warning fw-normal suggest-outside">Outside</span><?php endif; ?></span>
    <?php if (($r['name'] ?? '') !== ''): ?><span class="d-block fs-12 text-muted text-truncate suggest-address"><?= e($r['address']) ?></span><?php endif; ?>
</li>
<?php endforeach; ?>
