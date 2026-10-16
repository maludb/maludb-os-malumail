<?php
/** An identity (screens identity-new / identity-edit; writing.md): address (own or an alias MaluMail delivers here), display name, reply-to, the signature (the same editor; sanitised by the mail service), Default; Delete at the foot of the edit page. Data: box, identity (row|null), address (the prefill), notice, count */
$mb = (int) $box['id'];
$new = $identity === null;
$settingsUrl = '/m/' . $mb . '/settings';
?>
<?= view('shared/header.php', ['id' => 'identity', 'title' => $new ? 'New identity' : 'Identity', 'crumbs' => [[$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $mb . '/v/inbox'], ['Settings', $settingsUrl], [$new ? 'New identity' : (string) $identity['address'], null]], 'back' => [$settingsUrl, 'settings']]) ?>
<div class="main-content" id="identity-form-page" data-mailbox="<?= $mb ?>">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <div class="card" style="max-width:760px"><div class="card-body">
        <form method="post" action="/mail/identity-save" id="identity-form" hx-post="/mail/identity-save" hx-target="#page-content">
            <?= csrf_field() ?>
            <input type="hidden" name="mailbox" value="<?= $mb ?>">
            <?php if (!$new): ?><input type="hidden" name="identity" value="<?= (int) $identity['id'] ?>"><?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="identity-address">Address</label>
                <?php if ($new): ?>
                    <input type="email" class="form-control" name="address" id="identity-address" value="<?= e($address) ?>" required placeholder="sales@<?= e(substr((string) strrchr((string) $box['address'], '@'), 1)) ?>">
                    <div class="form-text">The mailbox's own address, or an alias MaluMail delivers to it (checked before it is saved).</div>
                <?php else: ?>
                    <input type="text" class="form-control" id="identity-address" value="<?= e((string) $identity['address']) ?>" disabled>
                    <div class="form-text"><?= $identity['kind'] === 'own' ? "The mailbox's own address." : 'An alias delivering to this mailbox' . ($identity['checked_at'] !== null ? ', last checked ' . e(format_ts($identity['checked_at'], member_timezone())) : '') . '.' ?></div>
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label" for="identity-name">Display name</label>
                <input type="text" class="form-control" name="display_name" id="identity-name" value="<?= e((string) ($identity['display_name'] ?? $box['display_name'])) ?>" maxlength="120">
            </div>
            <div class="mb-3">
                <label class="form-label" for="identity-reply-to">Reply-to</label>
                <input type="email" class="form-control" name="reply_to_address" id="identity-reply-to" value="<?= e((string) ($identity['reply_to'] ?? '')) ?>" placeholder="Replies go to this address when set">
            </div>
            <div class="mb-3 mail-compose" id="identity-signature-wrap" data-format="html">
                <label class="form-label" for="identity-signature-editor">Signature</label>
                <div class="mail-editor-toolbar btn-toolbar gap-1 mb-1 d-none" id="compose-toolbar" role="toolbar" aria-label="Formatting">
                    <?php foreach ([['bold', 'Bold', 'feather-bold'], ['italic', 'Italic', 'feather-italic'], ['link', 'Link', 'feather-link'], ['clear', 'Remove formatting', 'feather-x-square']] as [$cmd, $label, $icon]): ?>
                        <button type="button" class="btn btn-sm btn-light" data-cmd="<?= $cmd ?>" title="<?= $label ?>" aria-label="<?= $label ?>"><i class="<?= $icon ?>"></i></button>
                    <?php endforeach; ?>
                </div>
                <div class="mail-editor form-control" id="identity-signature-editor" role="textbox" aria-multiline="true" aria-label="Signature" data-initial-html="<?= e((string) ($identity['signature_html'] ?? (($identity['signature_text'] ?? '') !== '' ? nl2br(e((string) $identity['signature_text'])) : ''))) ?>" style="min-height:6rem"></div>
                <textarea class="form-control d-none" name="signature" id="identity-signature" rows="4"><?= e((string) ($identity['signature_html'] ?? $identity['signature_text'] ?? '')) ?></textarea>
                <div class="form-text">Inserted above what you write, after a "-- " line. Cleaned the way mail is before it is kept.</div>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="is_default" value="yes" id="identity-default"<?= ($identity['is_default'] ?? ($count === 0)) ? ' checked' : '' ?>>
                <label class="form-check-label" for="identity-default">Default identity</label>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-touch" id="identity-save"><?= $new ? 'Add identity' : 'Save' ?></button>
                <?= hx_link($settingsUrl, 'Cancel', 'btn btn-light btn-touch') ?>
            </div>
        </form>
        <?php if (!$new && $identity['kind'] !== 'own'): ?>
            <form method="post" action="/mail/identity-delete" class="mt-4 pt-3 border-top" hx-post="/mail/identity-delete" hx-target="#page-content" hx-confirm="Delete this identity?" id="identity-delete-form">
                <?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="identity" value="<?= (int) $identity['id'] ?>"><input type="hidden" name="return_to" value="<?= e($settingsUrl) ?>">
                <button type="submit" class="btn btn-outline-danger btn-touch" id="identity-delete"><i class="feather-trash-2 me-1"></i>Delete identity</button>
            </form>
        <?php endif; ?>
    </div></div>
</div>
<script src="/assets/vendor/dompurify-3.4.16/purify.min.js"></script>
<script src="/assets/vendor/squire-2.4.9/squire.js"></script>
<script src="/assets/js/compose.js"></script>
