<?php
/** A mailbox's labels (screen `labels`). Data: box, labels (ml_labels_of()), canManage, notice */
$mb = (int) $box['id'];
$swatches = static function (string $idp, ?string $current): string {
    $out = '<div class="swatch-pick d-flex flex-wrap gap-1 position-relative" role="radiogroup" aria-label="Colour">';
    foreach (ML_LABEL_COLOURS as $hex => $name) {
        $out .= '<span><input type="radio" name="colour" value="' . e($hex) . '" id="' . e($idp) . '-' . substr($hex, 1) . '"' . ($current === $hex ? ' checked' : '') . '>'
              . '<label for="' . e($idp) . '-' . substr($hex, 1) . '" style="background:' . e($hex) . '" title="' . e($name) . '"><span class="visually-hidden">' . e($name) . '</span></label></span>';
    }
    return $out . '</div>';
};
?>
<?= view('shared/header.php', ['id' => 'labels', 'title' => 'Labels', 'crumbs' => [[$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $mb . '/v/inbox'], ['Labels', null]], 'back' => ['/m/' . $mb . '/v/inbox', 'Inbox']]) ?>
<div class="main-content" id="labels-content">
    <?php if (!empty($notice)): ?><?= view('shared/banner.php', ['notice' => $notice]) ?><?php endif; ?>
    <div class="card mb-3"><div class="list-group list-group-flush" id="label-rows" role="list">
    <?php foreach ($labels as $l): $lid = (int) $l['id']; $n = (int) $l['conversations']; ?>
        <div class="list-group-item label-row" id="label-<?= $lid ?>" role="listitem">
            <div class="d-flex align-items-center gap-2">
                <span class="label-swatch" style="background:<?= e($l['colour'] ?: '#6c757d') ?>" role="img" aria-label="Colour"></span>
                <?= hx_link('/m/' . $mb . '/label/' . $lid, e($l['label']), 'fw-semibold label-name', 'id="label-' . $lid . '-open"') ?>
                <span class="ms-auto fs-12 text-muted label-count"><?= number_format($n) ?> <?= $n === 1 ? 'conversation' : 'conversations' ?></span>
            </div>
            <?php if ($canManage): ?>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <form method="post" action="/mail/label-update" hx-post="/mail/label-update" hx-target="#page-content" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1" id="label-<?= $lid ?>-form">
                    <?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="label" value="<?= $lid ?>">
                    <label class="visually-hidden" for="label-<?= $lid ?>-name">Name of <?= e($l['label']) ?></label>
                    <input type="text" name="name" id="label-<?= $lid ?>-name" class="form-control form-control-sm btn-touch" style="max-width:16rem" value="<?= e($l['label']) ?>" maxlength="50" required>
                    <?= $swatches('label-' . $lid . '-c', $l['colour']) ?>
                    <button type="submit" class="btn btn-sm btn-light btn-touch" id="label-<?= $lid ?>-save">Save</button>
                </form>
                <form method="post" action="/mail/label-delete" hx-post="/mail/label-delete" hx-target="#page-content" id="label-<?= $lid ?>-delete-form"
                      hx-confirm="Remove the label from <?= $n === 1 ? '1 conversation' : $n . ' conversations' ?>? The mail stays.">
                    <?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="label" value="<?= $lid ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger btn-touch" id="label-<?= $lid ?>-delete">Delete</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($labels === []): ?><div class="list-group-item text-muted" id="labels-none">No labels yet.<?= $canManage ? ' Make one below.' : '' ?></div><?php endif; ?>
    </div></div>
    <?php if ($canManage): ?>
    <form method="post" action="/mail/label-create" hx-post="/mail/label-create" hx-target="#page-content" class="card" id="label-new-form">
        <div class="card-header"><h5 class="card-title mb-0">Make a label</h5></div>
        <div class="card-body"><?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>">
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="label-new-name">Name — a / nests it, like Work/Clients</label>
                <input type="text" name="name" id="label-new-name" class="form-control btn-touch" required maxlength="50" placeholder="Work/Clients"></div>
            <div class="mb-3"><span class="form-label fs-12 text-muted d-block mb-1">Colour</span><?= $swatches('label-new-c', '#0d6efd') ?></div>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="label-new-save">Make the label</button>
        </div>
    </form>
    <?php endif; ?>
</div>
