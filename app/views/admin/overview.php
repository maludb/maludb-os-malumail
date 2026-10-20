<?php /** The Mail admin's overview as cards (screen `admin`). Data: o (admin_overview), tz */
$chip = static fn (string $t, string $c, string $id = ''): string => '<span class="badge bg-soft-' . $c . ' text-' . $c . ' me-1"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e($t) . '</span>';
$plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
$card = static function (string $id, string $title, string $icon, string $url, string $body, string $border = '') : string {
    return '<div class="col-12 col-md-6 col-xl-4" id="admin-card-' . e($id) . '"><div class="card h-100' . ($border !== '' ? ' border-' . $border : '') . '"><div class="card-body">'
        . '<div class="d-flex align-items-center gap-2 mb-2"><i class="' . e($icon) . '"></i><h6 class="fw-bold mb-0">' . hx_link($url, e($title), '', 'id="admin-card-' . e($id) . '-link"') . '</h6></div>'
        . $body . '</div></div></div>';
};
$d = $o['domains']; $m = $o['mailboxes']; $f = $o['forwarding']; $s = $o['sync']; $h = $o['held']; $b = $o['budget'];
?>
<?= view('shared/header.php', ['id' => 'admin', 'title' => 'Admin', 'crumbs' => [[app_name(), '/'], ['Admin', null]]]) ?>
<div class="main-content" id="admin-content">
    <?= view('admin/partials/switch-banner.php') ?>
    <div class="d-flex flex-wrap gap-2 mb-3 fs-12" id="admin-links">
        <?= hx_link('/admin/sync', 'Sync health', 'btn btn-sm btn-light btn-touch', 'id="admin-link-sync"') ?>
        <?= hx_link('/admin/agents', 'Agents', 'btn btn-sm btn-light btn-touch', 'id="admin-link-agents"') ?>
        <?= hx_link('/admin/settings', 'Settings', 'btn btn-sm btn-light btn-touch', 'id="admin-link-settings"') ?>
    </div>
    <div class="row g-3" id="admin-cards">
        <?php
        $body = '<div class="fs-5 fw-bold" data-figure="served">' . $plural($d['served'], 'domain served', 'domains served') . '</div><div class="fs-12 text-muted">' . $d['hosted'] . ' in all</div>';
        foreach ($d['unverified'] as $u) {
            $miss = array_keys(array_filter(['SPF' => $u['spf_ok'], 'DKIM' => $u['dkim_ok'], 'DMARC' => $u['dmarc_ok']], static fn ($v): bool => $v === false));
            $body .= '<div class="text-danger fs-12 mt-1" data-unverified="' . e($u['domain']) . '">' . e($u['domain']) . ': ' . e(implode(', ', $miss)) . ' not verified</div>';
        }
        echo $card('domains', 'Domains', 'feather-globe', '/admin/domains', $body, $d['unverified'] !== [] ? 'danger' : '');

        $body = '<div class="fs-5 fw-bold" data-figure="mailboxes">' . $plural($m['total'], 'mailbox', 'mailboxes') . '</div><div class="fs-12 text-muted">' . $m['kinds']['person'] . ' people\'s · ' . $m['kinds']['agent'] . ' agents\' · ' . $m['kinds']['shared'] . ' shared</div>'
            . '<div class="mt-1">' . ($m['suspended'] > 0 ? $chip($m['suspended'] . ' suspended', 'dark', 'admin-suspended') : '') . ($m['not_connected'] > 0 ? $chip($m['not_connected'] . ' not connected', 'warning', 'admin-not-connected') : '') . '</div>';
        echo $card('mailboxes', 'Mailboxes', 'feather-inbox', '/admin/mailboxes', $body);

        $body = '<div class="fs-5 fw-bold" data-figure="forwarding">' . $plural($f['count'], 'forwarding address', 'forwarding addresses') . '</div>'
            . ($f['outside'] > 0 ? '<div class="text-warning fw-semibold fs-12" data-figure="outside">' . $f['outside'] . ' go outside</div>' : '<div class="fs-12 text-muted">None go outside</div>');
        echo $card('forwarding', 'Forwarding', 'feather-corner-up-right', '/admin/aliases', $body, $f['outside'] > 0 ? 'warning' : '');

        $body = '<div class="fs-12" data-figure="sync">' . $s['live'] . ' live · ' . $s['syncing'] . ' syncing · ' . $s['failing'] . ' failing</div>'
            . ($s['credential_failed'] > 0 ? '<div class="text-danger fw-semibold fs-12 mt-1" data-figure="credentials">' . $plural($s['credential_failed'], 'credential', 'credentials') . ' failed</div>' : '<div class="fs-12 text-muted mt-1">No credential has failed</div>');
        echo $card('sync', 'Sync', 'feather-refresh-cw', '/admin/sync', $body, $s['credential_failed'] > 0 ? 'danger' : '');

        $body = '<div class="fs-5 fw-bold" data-figure="held">' . $h['total'] . ' held</div>'
            . ($h['total'] > 0 ? '<div class="fs-12 text-muted" data-figure="oldest">The oldest has waited ' . e(admin_age($h['age_seconds'])) . '</div>' : '<div class="fs-12 text-muted">Nothing is waiting</div>')
            . '<div class="fs-12 text-muted">The overseers decide it.</div>';
        echo $card('held', 'Held mail', 'feather-pause-circle', '/oversight/review', $body, $h['total'] > 0 ? 'warning' : '');

        $body = '<div class="fs-5 fw-bold" data-figure="budget">' . $b['used'] . ' of ' . $b['budget'] . '</div><div class="fs-12 text-muted">' . ($b['cap'] !== null ? 'The Mail admins\' cap' : 'The warm-up week') . ' · outside mail from all agents, last day</div>';
        echo $card('budget', 'Agents\' budget today', 'feather-bar-chart-2', '/admin/settings#budget', $body);

        $sw = $o['switches'];
        $body = '<div class="fs-12">' . ($sw['hold_all_agents'] ? $chip('every agent\'s mail held', 'danger', 'admin-switch-agents') : $chip('agents free to follow their policies', 'success', 'admin-switch-agents'))
            . '</div><div class="fs-12 mt-1">' . ($sw['hold_all_external'] ? $chip('outside mail held', 'danger', 'admin-switch-external') : $chip('outside mail follows policy', 'success', 'admin-switch-external')) . '</div>'
            . '<div class="fs-12 text-muted mt-1">MM3 ' . ($sw['mm3'] ? 'enforced' : 'not yet confirmed') . '</div>';
        echo $card('switches', 'Switches', 'feather-toggle-right', '/admin/settings#switches', $body, ($sw['hold_all_agents'] || $sw['hold_all_external']) ? 'danger' : '');
        ?>
    </div>
</div>
