<?php /** One mailbox's sync health, a ledger row at 768+ and a stacked card below. Data: r (admin_sync_rows' row), tz. Re-sync / Re-index swap this row (#sync-{mailbox}). */
$id = (int) $r['mailbox_id'];
$stateColour = ['live' => 'success', 'syncing' => 'info', 'new' => 'info', 'paused' => 'secondary', 'failed' => 'danger'][$r['sync_state']] ?? 'secondary';
$credColour = ['ok' => 'success', 'failed' => 'danger', 'revoked' => 'danger'][$r['credential']] ?? 'secondary';
$pct = $r['messages'] > 0 ? (int) floor(100 * $r['text_indexed'] / $r['messages']) : 0;
$label = static fn (string $t): string => '<span class="d-md-none text-muted fs-12 me-1">' . e($t) . ':</span>';
$btn = static fn (string $action, string $text, string $cls, string $confirm = ''): string =>
    '<form method="post" action="/admin/sync/' . $action . '.php" hx-post="/admin/sync/' . $action . '.php" hx-target="#sync-' . $id . '" hx-swap="outerHTML" class="d-inline"'
    . ($confirm !== '' ? ' hx-confirm="' . e($confirm) . '"' : '') . ' id="sync-' . $id . '-' . $action . '-form">' . csrf_field() . '<input type="hidden" name="mailbox" value="' . $id . '">'
    . '<button type="submit" class="btn btn-sm ' . $cls . ' btn-touch" id="sync-' . $id . '-' . $action . '">' . e($text) . '</button></form>';
?>
<div class="row g-2 align-items-center border-bottom py-2 mx-0 fs-12" id="sync-<?= $id ?>" data-state="<?= e($r['sync_state']) ?>">
    <div class="col-12 col-md-3 min-w-0"><div class="fw-semibold text-break fs-13"><?= hx_link('/admin/mailboxes/' . $id, e($r['address']), '', 'id="sync-' . $id . '-link"') ?></div>
        <div class="text-muted"><?= e(ADMIN_KINDS[$r['kind']] ?? $r['kind']) ?></div></div>
    <div class="col-6 col-md-1"><?= $label('Sync') ?><span class="badge bg-soft-<?= $stateColour ?> text-<?= $stateColour ?>" data-cell="state"><?= e($r['sync_state']) ?></span></div>
    <div class="col-6 col-md-1"><?= $label('Watcher') ?><span data-cell="watcher"><?= $r['watcher'] === null ? '—' : e($r['watcher']) ?></span></div>
    <div class="col-12 col-md-2"><?= $label('Last sync') ?><span data-cell="last-sync"><?= $r['last_sync_at'] === null ? 'never' : e(format_ts($r['last_sync_at'], $tz, 'M j, g:i A')) ?></span></div>
    <div class="col-12 col-md-2"><?= $label('Indexed') ?><span data-cell="indexed"><?= $r["messages"] ?> message<?= $r["messages"] === 1 ? "" : "s" ?> · <?= $r["text_indexed"] ?> text</span>
        <div class="progress mt-1" style="height:4px" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div></div>
    <div class="col-6 col-md-1"><?= $label('Quota') ?><span data-cell="quota"><?= e(admin_bytes($r['quota_used_bytes'])) ?></span></div>
    <div class="col-6 col-md-1"><?= $label('Credential') ?><span class="badge bg-soft-<?= $credColour ?> text-<?= $credColour ?>" data-cell="credential"><?= e($r['credential']) ?></span></div>
    <?php if ($r['last_error'] !== null): ?>
    <div class="col-12 text-danger text-break" data-cell="error"><?= e($r['last_error']) ?><?= $r['last_error_at'] !== null ? ' · ' . e(format_ts($r['last_error_at'], $tz, 'M j, g:i A')) : '' ?></div>
    <?php endif; ?>
    <?php if ($r['backoff_seconds'] > 0): ?><div class="col-12 text-muted" data-cell="backoff">The mail service is backing off for <?= (int) $r['backoff_seconds'] ?> s.</div><?php endif; ?>
    <div class="col-12 d-flex flex-wrap gap-2 mt-1">
        <?= $btn('resync', 'Re-sync', 'btn-light') ?>
        <?= $btn('reindex', 'Re-index text', 'btn-light', 'Empty the indexed text of ' . $r['address'] . '? The text pass refills it gently.') ?>
    </div>
</div>
