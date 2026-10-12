<?php /** Forwarding addresses as cards (screen `admin-aliases`). Data: rows, f, reached, notice, domains */
$link = static function (array $over) use ($f): string {
    $q = array_filter(array_merge($f, $over), static fn ($v): bool => $v !== '' && $v !== null);
    return '/admin/aliases' . ($q === [] ? '' : '?' . http_build_query($q));
};
?>
<?= view('shared/header.php', ['id' => 'admin-aliases', 'title' => 'Forwarding', 'crumbs' => [[app_name(), '/'], ['Admin', '/admin'], ['Forwarding', null]],
    'action' => hx_link('/admin/aliases/new', '<i class="feather-plus me-1"></i>New forwarding address', 'btn btn-primary btn-sm btn-touch', 'id="admin-aliases-new-btn"')]) ?>
<div class="main-content" id="admin-aliases-content">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <?php if (!$reached): ?><div class="alert alert-warning" id="admin-aliases-unreached">MaluMail did not answer for every domain, so this shows what was last read.</div><?php endif; ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="admin-aliases-chips">
        <?= hx_link($link(['outside' => '', 'domain' => '']), 'All', 'btn btn-sm btn-touch ' . ($f['outside'] === '' && $f['domain'] === '' ? 'btn-primary' : 'btn-light')) ?>
        <?= hx_link($link(['outside' => $f['outside'] === '' ? '1' : '']), 'Forwards outside', 'btn btn-sm btn-touch ' . ($f['outside'] !== '' ? 'btn-primary' : 'btn-light'), 'id="admin-aliases-outside-chip"') ?>
        <?php foreach ($domains as $d): ?><?= hx_link($link(['domain' => $f['domain'] === $d['domain'] ? '' : $d['domain']]), '@' . e($d['domain']), 'btn btn-sm btn-touch ' . ($f['domain'] === $d['domain'] ? 'btn-primary' : 'btn-light')) ?><?php endforeach; ?>
    </div>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="admin-aliases-empty">No forwarding addresses<?= array_filter($f) ? ' match' : ' yet' ?>.</div></div><?php endif; ?>
    <div class="row g-3" id="admin-aliases-cards">
        <?php foreach ($rows as $a): $id = (int) $a['id']; ?>
        <div class="col-12 col-md-6 col-xl-4" id="alias-card-<?= $id ?>">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <h6 class="fw-bold mb-1 text-break"><?= hx_link('/admin/aliases/' . $id, e($a['address']), '', 'id="alias-card-' . $id . '-link"') ?></h6>
                    <?php if ($a['forwards_outside']): ?><span class="badge bg-soft-warning text-warning" id="alias-card-<?= $id ?>-outside">Outside</span><?php endif; ?>
                </div>
                <div class="fs-12 text-muted">→ <?= e(implode(', ', $a['destinations'])) ?></div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
