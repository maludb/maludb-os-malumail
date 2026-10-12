<?php /** My tokens (screen `tokens-list`). Data: rows, minted (the token just minted — shown once — or null), tz */ ?>
<?= view('shared/header.php', ['id' => 'tokens', 'title' => 'Tokens', 'crumbs' => [[app_name(), '/'], ['Tokens', null]],
    'action' => hx_link('/tokens/new', '<i class="feather-plus me-1"></i>Mint a token', 'btn btn-primary btn-sm', 'id="tokens-new-btn"')]) ?>
<div class="main-content">
    <?php if ($minted !== null): ?>
        <div class="alert alert-warning" id="token-minted" role="status">
            <div class="fw-semibold mb-1">Your token "<?= e($minted['label']) ?>" — copy it now; it is not shown again.</div>
            <code class="d-block text-break user-select-all" id="token-secret"><?= e($minted['secret']) ?></code>
        </div>
    <?php endif; ?>
    <p class="text-muted fs-12">A token lets your own AI tool read your mail through MaluMail's two MCP servers — exactly what you may open here, never more.</p>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="tokens-empty">No tokens yet.</div></div>
    <?php endif; ?>
    <div class="row g-3" id="tokens-cards">
        <?php foreach ($rows as $t): ?>
        <div class="col-12 col-md-6 col-xl-4" id="token-row-<?= (int) $t['id'] ?>">
            <div class="card h-100<?= $t['revoked_at'] !== null ? ' opacity-50' : '' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h6 class="fw-bold mb-1 text-truncate"><?= e($t['label']) ?></h6>
                        <?php if ($t['revoked_at'] !== null): ?><span class="badge bg-soft-secondary text-secondary">revoked</span><?php endif; ?>
                    </div>
                    <div class="fs-12 text-muted">Made <?= e(format_ts((string) $t['created_at'], $tz, 'M j, Y')) ?> · expires <?= $t['expires_at'] ? e(format_ts((string) $t['expires_at'], $tz, 'M j, Y')) : 'never' ?></div>
                    <div class="fs-12 text-muted">Last used <?= $t['last_used_at'] ? e(format_ts((string) $t['last_used_at'], $tz)) : 'never' ?></div>
                    <?php if ($t['revoked_at'] === null): ?>
                    <form method="post" action="/tokens/revoke.php" class="mt-2" hx-post="/tokens/revoke.php" hx-confirm="Revoke the token &quot;<?= e($t['label']) ?>&quot;? Whatever uses it stops at once.">
                        <?= csrf_field() ?><input type="hidden" name="token" value="<?= (int) $t['id'] ?>" />
                        <button type="submit" class="btn btn-sm btn-light-brand" id="token-revoke-<?= (int) $t['id'] ?>"><i class="feather-x me-1"></i>Revoke</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
