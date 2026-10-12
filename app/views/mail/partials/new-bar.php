<?php /** The "N new" bar (mail-list.md "The poll"): conversations newer than the page's first row wait here; "show" reloads the first page. Data: n, base, unread */
$url = $base . ($unread ? '?unread=1' : ''); ?>
<div id="mail-new-bar" hx-swap-oob="outerHTML"><?php if ($n > 0): ?>
    <a class="alert alert-info d-block py-2 px-3 mb-2 fs-13 text-decoration-none" id="mail-new-link" href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#mail-list" hx-swap="outerHTML" hx-push-url="<?= e($url) ?>">
        <i class="feather-arrow-up me-1"></i><?= $n >= 100 ? '100+ new conversations' : ($n === 1 ? '1 new conversation' : (int) $n . ' new conversations') ?> — show</a>
<?php endif; ?></div>
