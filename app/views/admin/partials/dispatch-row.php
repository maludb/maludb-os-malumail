<?php /** One mail turn, a ledger row at 768+ and a card below. Data: d (admin_dispatches' row), os (kernel url), tz. Retry swaps this row (#dispatch-{id}). */
$id = (int) $d['id'];
$col = ['failed' => 'danger', 'sent' => 'success', 'answered' => 'success', 'unscreened' => 'warning', 'refused' => 'secondary', 'queued' => 'info'][$d['status']] ?? 'secondary';
$label = static fn (string $t): string => '<span class="d-md-none text-muted fs-12 me-1">' . e($t) . ':</span>';
?>
<div class="row g-2 align-items-center border-bottom py-2 mx-0 fs-12" id="dispatch-<?= $id ?>" data-status="<?= e($d['status']) ?>">
    <div class="col-6 col-md-2"><?= e(format_ts($d['created_at'], $tz, 'M j, g:i A')) ?></div>
    <div class="col-6 col-md-2 text-break"><?= e($d['agent_name'] ?? '') ?><div class="text-muted"><?= e($d['mailbox']) ?></div></div>
    <div class="col-4 col-md-1"><?= $label('Kind') ?><?= e($d['kind']) ?></div>
    <div class="col-4 col-md-1"><span class="badge bg-soft-<?= $col ?> text-<?= $col ?>" data-cell="status"><?= e($d['status']) ?></span></div>
    <div class="col-4 col-md-2"><?= $label('Why') ?><?= e(ADMIN_DISPATCH_WHY[$d['decision'] ?? ''] ?? (string) ($d['decision'] ?? '')) ?></div>
    <div class="col-6 col-md-1 text-break"><?= $label('From') ?><span data-cell="sender-domain"><?= $d['sender_domain'] !== '' && $d['sender_domain'] !== null ? e($d['sender_domain']) : '—' ?></span></div>
    <div class="col-3 col-md-1"><?= $label('Tries') ?><?= (int) $d['attempts'] ?></div>
    <div class="col-3 col-md-2"><?php if ($d['run_id'] !== null): ?><?php if ($os !== ''): ?><a href="<?= e($os . '/ai/runs/' . (int) $d['run_id']) ?>" target="_blank" rel="noopener">Run #<?= (int) $d['run_id'] ?></a><?php else: ?>Run #<?= (int) $d['run_id'] ?><?php endif; ?><?php endif; ?></div>
    <?php if ($d['status'] === 'failed'): ?>
    <div class="col-12">
        <form method="post" action="/admin/agents/retry.php" hx-post="/admin/agents/retry.php" hx-target="#dispatch-<?= $id ?>" hx-swap="outerHTML" class="d-inline" id="dispatch-<?= $id ?>-retry-form">
            <?= csrf_field() ?><input type="hidden" name="dispatch" value="<?= $id ?>">
            <button type="submit" class="btn btn-sm btn-light btn-touch" id="dispatch-<?= $id ?>-retry">Retry</button></form>
    </div>
    <?php endif; ?>
</div>
