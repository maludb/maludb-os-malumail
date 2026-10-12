<?php /** Mint a token (full-page form, part of `tokens-list`). Data: errors, values */ ?>
<?= view('shared/header.php', ['id' => 'token-new', 'title' => 'Mint a token', 'crumbs' => [[app_name(), '/'], ['Tokens', '/tokens'], ['Mint a token', null]]]) ?>
<div class="main-content">
    <div class="card"><div class="card-body">
        <form method="post" action="/tokens/mint.php" id="token-form">
            <?= csrf_field() ?>
            <div class="row mb-3 align-items-center">
                <div class="col-lg-4"><label class="fw-semibold" for="token-name">What will use it</label></div>
                <div class="col-lg-8"><input type="text" class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="token-name" name="name" maxlength="80" required value="<?= e($values['name'] ?? '') ?>" placeholder="My laptop's Claude" />
                    <?php if (isset($errors['name'])): ?><div class="invalid-feedback d-block"><?= e($errors['name']) ?></div><?php endif; ?></div>
            </div>
            <div class="row mb-3 align-items-center">
                <div class="col-lg-4"><label class="fw-semibold" for="token-days">Expires after (days)</label></div>
                <div class="col-lg-8"><input type="number" class="form-control<?= isset($errors['expires_days']) ? ' is-invalid' : '' ?>" id="token-days" name="expires_days" min="1" max="365" value="<?= e((string) ($values['expires_days'] ?? 90)) ?>" />
                    <?php if (isset($errors['expires_days'])): ?><div class="invalid-feedback d-block"><?= e($errors['expires_days']) ?></div><?php endif; ?></div>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <?= hx_link('/tokens', 'Cancel', 'btn btn-light') ?>
                <button type="submit" class="btn btn-primary" id="token-mint-btn">Mint</button>
            </div>
        </form>
    </div></div>
</div>
