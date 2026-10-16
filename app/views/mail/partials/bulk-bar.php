<?php
/**
 * The bulk bar (mail-list.md): shown by mail-list.js when a row is checked — the selection count, Select all on this page / all N
 * matching, then ONLY the actions whose handler is built (the registry's flag; slices 3 and 6 add them — decision 3). The bar posts
 * thread[] with the view's from_folder. Data: box, folder, view, total, count
 */
$actions = mail_bulk_actions();
$fromFolder = $folder !== null ? (int) $folder['id'] : '';
?>
<form id="mail-bulk-form" method="post" class="mail-bulk-bar d-none align-items-center flex-wrap gap-2 mb-2 p-2 rounded bg-body-tertiary" aria-label="Selected conversations">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="mailbox" value="<?= (int) $box['id'] ?>">
    <input type="hidden" name="from_folder" value="<?= e((string) $fromFolder) ?>">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <input type="hidden" name="matching" value="" id="mail-all-matching">
    <span class="fw-semibold fs-13" id="mail-selected-count" aria-live="polite">0 selected</span>
    <button type="button" class="btn btn-sm btn-light btn-touch" id="mail-select-all-page">Select all on this page</button>
    <?php if ($total > $count && ($matching ?? null) !== null): ?>
        <button type="button" class="btn btn-sm btn-light btn-touch" id="mail-select-all-matching" data-total="<?= (int) $total ?>">Select all <?= $total > 10000 ? 'matching' : e(number_format($total)) ?></button>
    <?php endif; ?>
    <?php foreach ($actions as $a): ?>
        <?php if (in_array($a['name'], ['mail_label', 'mail_snooze'], true)): ?><?= view('mail/partials/bar-panel.php', ['a' => $a, 'box' => $box]) ?><?php continue; endif; ?>
        <button type="submit" class="btn btn-sm btn-light btn-touch mail-bulk-action" formaction="<?= e(preg_replace('/\.php$/', '', $a['endpoint'])) ?>"
                id="mail-bulk-<?= e($a['name']) ?>" hx-post="<?= e(preg_replace('/\.php$/', '', $a['endpoint'])) ?>" hx-target="#page-content"><i class="<?= e($a['icon']) ?> me-1"></i><?= e($a['label']) ?></button>
    <?php endforeach; ?>
    <button type="button" class="btn btn-sm btn-link btn-touch ms-auto" id="mail-select-none">Clear</button>
</form>
