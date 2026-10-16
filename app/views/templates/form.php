<?php
/** A canned reply (screens `template-new`, `template-edit`). Data: t (null for new), editable, pre (name, body from the query) */
$new = $t === null;
$screen = $new ? 'template-new' : 'template-edit';
$v = static fn (string $k): string => (string) ($new ? ($pre[$k] ?? '') : ($t[$k] ?? ''));
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $new ? 'New canned reply' : $t['name'], 'crumbs' => [[app_name(), '/'], ['Canned replies', '/templates'], [$new ? 'New' : $t['name'], null]], 'back' => ['/templates', 'Canned replies']]) ?>
<div class="main-content" id="<?= $screen ?>-content">
    <?php if (!$editable): ?>
        <div class="alert alert-light" id="template-readonly">Owned by <?= e(($t['owner_name'] ?? '') !== '' ? $t['owner_name'] : 'someone who left') ?>. A shared canned reply is changed by its owner or a Mail admin.</div>
    <?php endif; ?>
    <form method="post" action="/templates/save" hx-post="/templates/save" hx-target="#page-content" class="card mb-3" id="template-form">
        <div class="card-body"><?= csrf_field() ?>
            <?php if (!$new): ?><input type="hidden" name="template" value="<?= (int) $t['template_id'] ?>"><?php endif; ?>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="template-name">Name</label>
                <input type="text" name="name" id="template-name" class="form-control btn-touch" required maxlength="120" value="<?= e($v('name')) ?>"<?= $editable ? '' : ' readonly' ?>></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="template-subject">Subject (optional)</label>
                <input type="text" name="subject" id="template-subject" class="form-control btn-touch" maxlength="300" value="<?= e($v('subject')) ?>"<?= $editable ? '' : ' readonly' ?>></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="template-body">Text</label>
                <textarea name="body" id="template-body" class="form-control" rows="8" required maxlength="20000"<?= $editable ? '' : ' readonly' ?>><?= e($v('body')) ?></textarea></div>
            <div class="form-check mb-3"><input type="hidden" name="shared" value="no"><input class="form-check-input" type="checkbox" name="shared" value="yes" id="template-shared"<?= !$new && $t['shared'] ? ' checked' : '' ?><?= $editable ? '' : ' disabled' ?>>
                <label class="form-check-label" for="template-shared">Shared with everyone using MaluMail</label></div>
            <?php if ($editable): ?><button type="submit" class="btn btn-primary btn-touch w-100" id="template-save"><?= $new ? 'Save the canned reply' : 'Save' ?></button><?php endif; ?>
        </div>
    </form>
    <?php if (!$new && $editable): ?>
    <form method="post" action="/templates/delete" hx-post="/templates/delete" hx-target="#page-content" hx-confirm="Delete this canned reply?" id="template-delete-form">
        <?= csrf_field() ?><input type="hidden" name="template" value="<?= (int) $t['template_id'] ?>">
        <button type="submit" class="btn btn-outline-danger btn-touch w-100" id="template-delete">Delete the canned reply</button>
    </form>
    <?php endif; ?>
</div>
