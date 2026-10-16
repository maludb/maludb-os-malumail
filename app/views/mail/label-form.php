<?php
/**
 * The choosing half of "Label": the mailbox's labels as checkboxes with a filter, Apply and Take off. Used by the bulk bar (its inputs
 * belong to #mail-bulk-form) and the conversation page (inside its own form). Data: mb, ep (the handler's path), pfx (id prefix), fa (extra
 * attributes for each input, e.g. ' form="mail-bulk-form"')
 */
$labels = ml_labels_of(db(), $mb);
$fa = $fa ?? '';
?>
<?php if ($labels === []): ?>
    <span class="fs-12 text-muted">No labels yet. <?= hx_link('/m/' . $mb . '/labels', 'Make one', '', 'id="' . e($pfx) . '-make"') ?></span>
<?php else: ?>
    <label class="visually-hidden" for="<?= e($pfx) ?>-filter">Filter the labels</label>
    <input type="search" class="form-control form-control-sm mb-2 mail-label-filter" id="<?= e($pfx) ?>-filter" placeholder="Filter labels" autocomplete="off">
    <div class="mail-label-choices" id="<?= e($pfx) ?>-choices">
    <?php foreach ($labels as $l): ?>
        <div class="form-check mail-label-choice" data-name="<?= e(strtolower($l['label'])) ?>"><input class="form-check-input" type="checkbox" name="label[]" value="<?= (int) $l['id'] ?>" id="<?= e($pfx) ?>-<?= (int) $l['id'] ?>"<?= $fa ?>>
            <label class="form-check-label" for="<?= e($pfx) ?>-<?= (int) $l['id'] ?>"><?= e($l['label']) ?></label></div>
    <?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-sm btn-primary btn-touch mt-2 mail-bulk-action" formaction="<?= e($ep) ?>" hx-post="<?= e($ep) ?>" hx-target="#page-content" id="<?= e($pfx) ?>-apply">Apply</button>
    <button type="submit" class="btn btn-sm btn-link btn-touch mail-bulk-action" formaction="<?= e($ep) ?>" hx-post="<?= e($ep) ?>" hx-target="#page-content" id="<?= e($pfx) ?>-remove" name="removed" value="yes">Take off</button>
<?php endif; ?>
