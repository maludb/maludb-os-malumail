<?php /** The agents with a mailbox and their mail turns (screen `admin-agents`). Data: cards, rows (dispatches), status, agent (filter), os (kernel url), tz, notice */
$link = static function (array $over) use ($status, $agent): string {
    $q = array_filter(array_merge(['status' => $status, 'agent' => $agent === null ? '' : (string) $agent], $over), static fn ($v): bool => $v !== '' && $v !== null);
    return '/admin/agents' . ($q === [] ? '' : '?' . http_build_query($q));
};
?>
<?= view('shared/header.php', ['id' => 'admin-agents', 'title' => 'Agents', 'crumbs' => [[app_name(), '/'], ['Admin', '/admin'], ['Agents', null]]]) ?>
<div class="main-content" id="admin-agents-content">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <?= view('admin/partials/switch-banner.php') ?>
    <?php if ($cards === []): ?>
        <div class="card mb-3"><div class="card-body text-muted" id="admin-agents-empty">No agent has a mailbox yet. <?= hx_link('/admin/mailboxes/new', 'New mailbox', 'ms-1') ?></div></div>
    <?php endif; ?>
    <div class="row g-3 mb-4" id="admin-agents-cards">
        <?php foreach ($cards as $c): $aid = (int) $c['agent_id']; ?>
        <div class="col-12 col-md-6 col-xl-4" id="agent-admin-<?= (int) $c['mailbox_id'] ?>">
            <div class="card h-100<?= $c['paused'] ? ' border-danger' : '' ?>"><div class="card-body">
                <div class="d-flex align-items-start gap-2"><i class="feather-cpu mt-1"></i>
                    <div class="min-w-0 flex-grow-1"><h6 class="fw-bold mb-0 text-break"><?= e($c['agent_name'] ?? $c['mailbox_name']) ?></h6>
                        <div class="fs-12 text-muted text-truncate"><?= hx_link('/admin/mailboxes/' . (int) $c['mailbox_id'], e($c['address'])) ?></div></div>
                    <?php if ($c['paused']): ?><span class="badge bg-danger" id="agent-admin-<?= (int) $c['mailbox_id'] ?>-paused">paused</span><?php endif; ?>
                </div>
                <div class="fs-12 mt-2">Send mode: <b><?= e(str_replace('_', ' ', (string) ($c['send_mode'] ?? 'none'))) ?></b> · overseen by <b><?= $c['overseer_name'] !== null ? e($c['overseer_name']) : 'no one' ?></b></div>
                <div class="fs-12 text-muted mt-2">Last 7 days</div>
                <div class="fs-12" data-turns="<?= (int) $c['mailbox_id'] ?>"><?= (int) $c['sent'] ?> sent · <?= (int) $c['answered'] ?> answered · <?= (int) $c['failed'] ?> failed · <?= (int) $c['unscreened'] ?> unscreened · <?= (int) $c['refused'] ?> refused</div>
                <div class="d-flex flex-wrap gap-1 mt-3">
                    <?= hx_link($link(['agent' => (string) $aid]), 'Its turns', 'btn btn-sm btn-light btn-touch', 'id="agent-admin-' . (int) $c['mailbox_id'] . '-turns"') ?>
                    <?php if ($os !== ''): ?><a href="<?= e($os . '/agents/' . $aid) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-light btn-touch" id="agent-admin-<?= (int) $c['mailbox_id'] ?>-os">Hire, grants and duties — in the OS</a><?php endif; ?>
                </div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>
    <h6 class="fw-bold">Mail turns</h6>
    <div class="d-flex flex-wrap gap-1 mb-3" id="admin-agents-chips">
        <?= hx_link($link(['status' => '']), 'All', 'btn btn-sm btn-touch ' . ($status === '' ? 'btn-primary' : 'btn-light'), 'id="admin-agents-status-all"') ?>
        <?php foreach (['failed', 'queued', 'sent', 'answered', 'unscreened', 'refused'] as $st): ?><?= hx_link($link(['status' => $st]), e($st), 'btn btn-sm btn-touch ' . ($status === $st ? 'btn-primary' : 'btn-light'), 'id="admin-agents-status-' . $st . '"') ?><?php endforeach; ?>
        <?php if ($agent !== null): ?><?= hx_link($link(['agent' => '']), 'Every agent', 'btn btn-sm btn-touch btn-outline-primary', 'id="admin-agents-agent-clear"') ?><?php endif; ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="admin-dispatches-empty">No mail turn matches.</div></div>
    <?php else: ?>
    <div class="card"><div class="card-body p-0 px-2" id="admin-dispatches">
        <div class="row g-2 mx-0 py-2 border-bottom fw-semibold fs-12 text-muted d-none d-md-flex">
            <div class="col-md-2">Time</div><div class="col-md-2">Agent</div><div class="col-md-1">Kind</div><div class="col-md-1">Status</div><div class="col-md-2">Decision</div><div class="col-md-1">Sender</div><div class="col-md-1">Tries</div><div class="col-md-2">Run</div>
        </div>
        <?php foreach ($rows as $d): ?><?= view('admin/partials/dispatch-row.php', ['d' => $d, 'os' => $os, 'tz' => $tz]) ?><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
</div>
