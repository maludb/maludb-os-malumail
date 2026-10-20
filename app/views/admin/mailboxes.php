<?php /** The mailboxes of the served domains as cards (screen `admin-mailboxes`). Data: rows, f (filters), domains */
$chip = static fn (string $t, string $c, string $id = ''): string => '<span class="badge bg-soft-' . $c . ' text-' . $c . ' me-1"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e($t) . '</span>';
$link = static function (array $over) use ($f): string {
    $q = array_filter(array_merge($f, $over), static fn ($v): bool => $v !== '' && $v !== null);
    return '/admin/mailboxes' . ($q === [] ? '' : '?' . http_build_query($q));
};
?>
<?= view('shared/header.php', ['id' => 'admin-mailboxes', 'title' => 'Mailboxes', 'crumbs' => [[app_name(), '/'], ['Admin', '/admin'], ['Mailboxes', null]],
    'action' => hx_link('/admin/mailboxes/new', '<i class="feather-plus me-1"></i>New mailbox', 'btn btn-primary btn-sm btn-touch', 'id="admin-mailboxes-new-btn"')]) ?>
<div class="main-content" id="admin-mailboxes-content">
    <?= view('admin/partials/switch-banner.php') ?>
    <form method="get" action="/admin/mailboxes" class="d-flex flex-wrap gap-2 mb-3" hx-get="/admin/mailboxes" hx-target="#page-content" hx-push-url="true" id="admin-mailboxes-filter">
        <input type="search" name="q" value="<?= e($f['q']) ?>" class="form-control form-control-sm btn-touch" style="max-width:16rem" placeholder="Address, name or owner" id="admin-mailboxes-q">
        <input type="hidden" name="kind" value="<?= e($f['kind']) ?>"><input type="hidden" name="state" value="<?= e($f['state']) ?>"><input type="hidden" name="domain" value="<?= e($f['domain']) ?>">
        <button type="submit" class="btn btn-sm btn-light btn-touch">Search</button>
    </form>
    <div class="d-flex flex-wrap gap-1 mb-3" id="admin-mailboxes-chips">
        <?= hx_link($link(['kind' => '']), 'All', 'btn btn-sm btn-touch ' . ($f['kind'] === '' ? 'btn-primary' : 'btn-light')) ?>
        <?php foreach (ADMIN_KINDS as $k => $l): ?><?= hx_link($link(['kind' => $k]), e($l), 'btn btn-sm btn-touch ' . ($f['kind'] === $k ? 'btn-primary' : 'btn-light'), 'id="admin-mailboxes-kind-' . $k . '"') ?><?php endforeach; ?>
        <?= hx_link($link(['state' => $f['state'] === 'failed' ? '' : 'failed']), 'Failing', 'btn btn-sm btn-touch ' . ($f['state'] === 'failed' ? 'btn-primary' : 'btn-light')) ?>
        <?= hx_link($link(['state' => $f['state'] === 'suspended' ? '' : 'suspended']), 'Suspended', 'btn btn-sm btn-touch ' . ($f['state'] === 'suspended' ? 'btn-primary' : 'btn-light')) ?>
        <?php foreach ($domains as $d): ?><?= hx_link($link(['domain' => $f['domain'] === $d['domain'] ? '' : $d['domain']]), '@' . e($d['domain']), 'btn btn-sm btn-touch ' . ($f['domain'] === $d['domain'] ? 'btn-primary' : 'btn-light')) ?><?php endforeach; ?>
    </div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="admin-mailboxes-empty">No mailboxes on the served domains<?= array_filter($f) ? ' match' : ' yet' ?>.
            <?= hx_link('/admin/mailboxes/new', 'New mailbox', 'ms-1') ?></div></div>
    <?php endif; ?>
    <div class="row g-3" id="admin-mailboxes-cards">
        <?php foreach ($rows as $b): $id = (int) $b['id']; [$ct, $cc] = admin_credential_chip($b); ?>
        <div class="col-12 col-md-6 col-xl-4" id="mailbox-card-<?= $id ?>">
            <div class="card h-100<?= $b['suspended_at'] !== null ? ' opacity-75' : '' ?>">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-2">
                        <i class="<?= $b['kind'] === 'agent' ? 'feather-cpu' : ($b['kind'] === 'shared' ? 'feather-users' : 'feather-user') ?> mt-1"></i>
                        <div class="min-w-0 flex-grow-1">
                            <h6 class="fw-bold mb-0 text-break"><?= hx_link('/admin/mailboxes/' . $id, e($b['address']), '', 'id="mailbox-card-' . $id . '-link"') ?></h6>
                            <div class="fs-12 text-muted text-truncate"><?= e($b['display_name']) ?> · <?= e(ADMIN_KINDS[$b['kind']]) ?><?php if ($b['owner_name'] !== null): ?> · <?= e($b['owner_name']) ?><?php endif; ?></div>
                        </div>
                    </div>
                    <div class="mt-2">
                        <?= $chip($ct, $cc, 'mailbox-card-' . $id . '-credential') ?>
                        <?= $chip($b['sync_state'], $b['sync_state'] === 'failed' ? 'danger' : ($b['sync_state'] === 'live' ? 'success' : 'secondary')) ?>
                        <?php if ($b['suspended_at'] !== null): ?><?= $chip('suspended', 'dark', 'mailbox-card-' . $id . '-suspended') ?><?php endif; ?>
                        <?php if ($b['intake_owner'] !== 'none'): ?><?= $chip('intake: ' . $b['intake_owner'], 'info') ?><?php elseif ($b['intake_proposed'] !== null): ?><?= $chip('intake? ' . $b['intake_proposed'], 'warning') ?><?php endif; ?>
                    </div>
                    <div class="fs-12 text-muted mt-2">Quota <?= e(admin_bytes($b['quota_bytes'])) ?><?= $b['quota_used_bytes'] !== null ? ' · ' . e(admin_bytes($b['quota_used_bytes'])) . ' used' : '' ?><?php if ($b['kind'] === 'agent' && $b['primary_overseer_name'] !== null): ?> · overseen by <?= e($b['primary_overseer_name']) ?><?php endif; ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
