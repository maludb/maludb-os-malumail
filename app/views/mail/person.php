<?php
/** One correspondent (screen `correspondent-view`). Data: box, c, domain, company, tab, threads, addresses, tz, base */
$mb = (int) $box['id'];
$label = ($c['name'] ?? '') !== '' ? $c['name'] : $c['address'];
?>
<?= view('shared/header.php', ['id' => 'correspondent-view', 'title' => $label, 'crumbs' => [[$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $mb . '/v/inbox'], ['People', '/m/' . $mb . '/people'], [$label, null]], 'back' => ['/m/' . $mb . '/people', 'People']]) ?>
<div class="main-content" id="correspondent-view-content">
    <div class="card mb-3"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold text-break" id="person-name"><?= e($label) ?></span>
            <?php if (($c['name'] ?? '') !== ''): ?><span class="fs-12 text-muted text-break" id="person-address"><?= e($c['address']) ?></span><?php endif; ?>
            <span class="badge <?= $c['is_internal'] ? 'bg-soft-secondary text-secondary' : 'bg-soft-warning text-warning' ?> fw-normal" id="person-where"><?= $c['is_internal'] ? 'Inside' : 'Outside' ?></span>
            <?php if ($box['kind'] === 'agent' && $c['known_at'] !== null): ?><span class="badge bg-soft-success text-success fw-normal" id="person-known">Known · <?= $c['known_how'] === 'marked' ? 'marked' : 'sent' ?></span><?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-3 fs-12 text-muted mt-2">
            <span id="person-first">First contact <?= e(format_ts((string) $c['first_seen_at'], $tz, 'M j, Y')) ?></span>
            <span id="person-last">Last contact <?= e(format_ts((string) $c['last_seen_at'], $tz, 'M j, Y')) ?></span>
            <span id="person-sent"><?= (int) $c['sent_count'] ?> sent</span>
            <span id="person-received"><?= (int) $c['received_count'] ?> received</span>
        </div>
    </div></div>
    <?php if ($company): ?>
    <ul class="nav nav-pills mb-3" id="person-tabs">
        <li class="nav-item"><a class="nav-link btn-touch<?= $tab === 'address' ? ' active' : '' ?>" id="person-tab-address" href="<?= e($base) ?>" hx-get="<?= e($base) ?>" hx-target="#page-content" hx-push-url="<?= e($base) ?>">This address</a></li>
        <li class="nav-item"><a class="nav-link btn-touch<?= $tab === 'company' ? ' active' : '' ?>" id="person-tab-company" href="<?= e($base . '?tab=company') ?>" hx-get="<?= e($base . '?tab=company') ?>" hx-target="#page-content" hx-push-url="<?= e($base . '?tab=company') ?>">Everyone at <?= e($domain) ?></a></li>
    </ul>
    <?php endif; ?>
    <?php if ($tab === 'company' && $addresses !== []): ?>
    <div class="card mb-3"><div class="card-header"><h6 class="card-title mb-0">Addresses at <?= e($domain) ?></h6></div>
        <div class="list-group list-group-flush" id="person-domain-addresses" role="list">
        <?php foreach ($addresses as $a): $h = '/m/' . $mb . '/people/' . rawurlencode($a['address']); ?>
            <div class="list-group-item d-flex flex-wrap gap-2 align-items-center" role="listitem">
                <a class="text-reset text-break" href="<?= e($h) ?>" hx-get="<?= e($h) ?>" hx-target="#page-content" hx-push-url="<?= e($h) ?>"><?= e(($a['name'] ?? '') !== '' ? $a['name'] . ' · ' . $a['address'] : $a['address']) ?></a>
                <span class="ms-auto fs-12 text-muted"><?= (int) $a['sent'] ?> sent · <?= (int) $a['received'] ?> received</span>
            </div>
        <?php endforeach; ?>
        </div></div>
    <?php endif; ?>
    <?= view('mail/person-threads.php', compact('box', 'c', 'domain', 'tab', 'threads', 'tz', 'base')) ?>
</div>
