<?php /** A new forwarding address (`admin-alias-new`) or one to change and delete (`admin-alias-view`). Data: a (null for new), domains, pre, notice? */
$new = $a === null;
$screen = $new ? 'admin-alias-new' : 'admin-alias-view';
?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $new ? 'New forwarding address' : $a['address'],
    'crumbs' => [[app_name(), '/'], ['Admin', '/admin'], ['Forwarding', '/admin/aliases'], [$new ? 'New' : $a['address'], null]], 'back' => ['/admin/aliases', 'Forwarding']]) ?>
<div class="main-content" id="<?= $screen ?>-content">
    <?= view('admin/partials/switch-banner.php') ?>
    <?= view('shared/banner.php', ['notice' => $notice ?? null]) ?>
    <?php if ($new && $domains === []): ?>
        <div class="alert alert-warning">No domain is served yet. <?= hx_link('/admin/domains', 'Serve a domain', 'alert-link') ?> first.</div>
    <?php else: ?>
    <form method="post" action="<?= $new ? '/admin/aliases/create.php' : '/admin/aliases/update.php' ?>" hx-post="<?= $new ? '/admin/aliases/create.php' : '/admin/aliases/update.php' ?>" class="card mb-3" id="alias-form">
        <div class="card-header"><h5 class="card-title mb-0"><?= $new ? 'Forward an address' : 'Where it forwards' ?></h5></div>
        <div class="card-body">
            <?= csrf_field() ?>
            <?php if ($new): ?>
            <div class="row g-2 mb-3">
                <div class="col-7"><label class="form-label fs-12 text-muted" for="alias-form-local-part">Address</label>
                    <input type="text" name="local_part" id="alias-form-local-part" class="form-control btn-touch" required maxlength="64" pattern="[A-Za-z0-9._+\-]+" value="<?= e($pre['local_part'] ?? '') ?>" placeholder="sales" autocapitalize="none"></div>
                <div class="col-5"><label class="form-label fs-12 text-muted" for="alias-form-domain">Domain</label>
                    <select name="domain" id="alias-form-domain" class="form-select btn-touch"><?php foreach ($domains as $d): ?><option value="<?= e($d['domain']) ?>" <?= ($pre['domain'] ?? '') === $d['domain'] ? 'selected' : '' ?>>@<?= e($d['domain']) ?></option><?php endforeach; ?></select></div>
            </div>
            <?php else: ?>
                <input type="hidden" name="alias" value="<?= e($a['address']) ?>">
                <?php if ($a['forwards_outside']): ?><div class="alert alert-warning py-2 fs-12" id="alias-form-outside">This forwards mail outside the business.</div><?php endif; ?>
            <?php endif; ?>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="alias-form-destinations">Forward to — one address a line, at most 20</label>
                <textarea name="destination" id="alias-form-destinations" class="form-control" rows="4" required autocapitalize="none"><?= e($new ? '' : implode("\n", $a['destinations'])) ?></textarea></div>
            <div class="mb-3"><label class="form-label fs-12 text-muted" for="alias-form-confirm-outside">An address outside the business forwards mail out by itself: type each one again here to confirm</label>
                <input type="text" name="confirm_outside" id="alias-form-confirm-outside" class="form-control btn-touch" autocapitalize="none" placeholder="Only when forwarding outside"></div>
            <button type="submit" class="btn btn-primary btn-touch w-100" id="alias-form-save"><?= $new ? 'Make the forwarding address' : 'Save' ?></button>
        </div>
    </form>
    <?php if (!$new): ?>
    <form method="post" action="/admin/aliases/delete.php" hx-post="/admin/aliases/delete.php" hx-confirm="Delete <?= e($a['address']) ?>? Mail to it is refused from then on." id="alias-delete-form">
        <?= csrf_field() ?><input type="hidden" name="alias" value="<?= e($a['address']) ?>">
        <button type="submit" class="btn btn-outline-danger btn-touch w-100" id="alias-delete-btn">Delete the forwarding address</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</div>
