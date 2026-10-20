<?php /** MaluMail's hosted domains: serve or not, internal or not, the flags (screen `admin-domains`). Data: rows, reached, notice */
$flag = static function (?bool $v, string $name): string {
    $c = $v === null ? 'secondary' : ($v ? 'success' : 'warning');
    $t = $v === null ? 'unknown' : ($v ? 'verified' : 'not verified');
    return '<span class="badge bg-soft-' . $c . ' text-' . $c . ' me-1" title="' . e($name . ' ' . $t) . '">' . e($name) . ($v === null ? ' ?' : ($v ? ' ✓' : ' ✗')) . '</span>';
};
?>
<?= view('shared/header.php', ['id' => 'admin-domains', 'title' => 'Domains', 'crumbs' => [[app_name(), '/'], ['Admin', '/admin'], ['Domains', null]]]) ?>
<div class="main-content" id="admin-domains-content">
    <?= view('admin/partials/switch-banner.php') ?>
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <?php if (!$reached): ?>
        <div class="alert alert-warning" id="admin-domains-unreached">MaluMail did not answer, so this shows the served domains as last read.</div>
    <?php endif; ?>
    <p class="text-muted fs-12">The customer key reaches every domain MaluMail hosts for this business; MaluMail administers only the domains it <b>serves</b>. An <b>internal</b> domain's mailboxes are colleagues: an agent's mail to them is not outside mail (design §5).</p>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="admin-domains-empty">MaluMail hosts no domain for this business yet.</div></div>
    <?php endif; ?>
    <div class="row g-3" id="admin-domains-cards">
        <?php foreach ($rows as $d): $slug = str_replace('.', '-', $d['domain']); ?>
        <div class="col-12 col-md-6 col-xl-4" id="domain-<?= e($slug) ?>">
            <div class="card h-100<?= $d['hosted'] === false ? ' opacity-50' : '' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h6 class="fw-bold mb-1 text-break"><?= e($d['domain']) ?></h6>
                        <?php if ($d['served']): ?><span class="badge bg-soft-primary text-primary">served</span><?php endif; ?>
                    </div>
                    <div class="mb-2"><?= $flag($d['spf'], 'SPF') . $flag($d['dkim'], 'DKIM') . $flag($d['dmarc'], 'DMARC') ?></div>
                    <div class="fs-12 text-muted mb-2"><?= (int) $d['mailboxes'] ?> mailbox<?= $d['mailboxes'] === 1 ? '' : 'es' ?> · <?= (int) $d['aliases'] ?> forwarding address<?= $d['aliases'] === 1 ? '' : 'es' ?><?= $d['hosted'] === false ? ' · no longer hosted' : '' ?></div>
                    <?php if ($d['hosted'] !== false): ?>
                    <form method="post" action="/admin/domains/serve.php" hx-post="/admin/domains/serve.php" class="d-flex flex-wrap gap-2"
                          hx-confirm="<?= $d['served'] ? 'Stop serving ' . e($d['domain']) . '? Its mailboxes leave the webmail\'s administration.' : 'Serve ' . e($d['domain']) . ' here?' ?>"
                          id="domain-<?= e($slug) ?>-serve-form">
                        <?= csrf_field() ?><input type="hidden" name="domain" value="<?= e($d['domain']) ?>">
                        <input type="hidden" name="served" value="<?= $d['served'] ? 'no' : 'yes' ?>">
                        <?php if (!$d['served']): ?>
                            <label class="d-flex align-items-center gap-2 border rounded px-2 btn-touch fs-12"><input type="checkbox" class="form-check-input mt-0" name="internal" value="yes" checked id="domain-<?= e($slug) ?>-internal"> internal</label>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-sm <?= $d['served'] ? 'btn-light' : 'btn-primary' ?> btn-touch" id="domain-<?= e($slug) ?>-serve-btn"><?= $d['served'] ? 'Stop serving' : 'Serve' ?></button>
                    </form>
                    <?php if ($d['served']): ?>
                    <form method="post" action="/admin/domains/serve.php" hx-post="/admin/domains/serve.php" class="mt-2" id="domain-<?= e($slug) ?>-internal-form"
                          hx-confirm="<?= $d['internal'] ? 'Treat ' . e($d['domain']) . ' as outside? Agents\' mail to it is then held as outside mail.' : 'Treat ' . e($d['domain']) . ' as internal? Its mailboxes become colleagues.' ?>">
                        <?= csrf_field() ?><input type="hidden" name="domain" value="<?= e($d['domain']) ?>"><input type="hidden" name="served" value="yes">
                        <input type="hidden" name="internal" value="<?= $d['internal'] ? 'no' : 'yes' ?>">
                        <span class="fs-12 me-2"><?= $d['internal'] ? 'Internal' : 'Outside' ?></span>
                        <button type="submit" class="btn btn-sm btn-light btn-touch" id="domain-<?= e($slug) ?>-internal-btn"><?= $d['internal'] ? 'Mark outside' : 'Mark internal' ?></button>
                    </form>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
