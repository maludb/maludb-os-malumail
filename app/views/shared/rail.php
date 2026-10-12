<?php
/**
 * THE MAILBOX RAIL (sso-shell.md "The shell"; design §9): every mailbox the person opens (ml_rail() — the wall decides), its kind's icon
 * and the Inbox's unread count (— until slice 2's sync fills it); under the current mailbox its views; then Oversight (a person who
 * decides for an agent mailbox), Templates, Admin (a Mail admin), Notifications. Before the notice is acknowledged only Notifications
 * and the notice show. Data: rail, current, activeNav, noticeOk.
 */
$kindIcon = ['person' => 'feather-user', 'shared' => 'feather-users', 'agent' => 'feather-cpu'];
$link = static function (string $id, string $url, string $icon, string $label, string $count = '', bool $active = false): string {
    return '<li class="nxl-item" id="nav-' . e($id) . '">'
        . '<a class="nxl-link' . ($active ? ' active' : '') . '" href="' . e($url) . '" hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">'
        . '<span class="nxl-micon"><i class="' . e($icon) . '"></i></span><span class="nxl-mtext text-truncate">' . e($label) . '</span>'
        . ($count !== '' ? '<span class="ms-auto fs-11 text-muted rail-count">' . e($count) . '</span>' : '')
        . '</a></li>';
};
$here = current_path();
?>
<ul class="nxl-navbar" id="shell-sidebar">
<?php if (!$noticeOk): ?>
    <li class="nxl-item nxl-caption" id="nav-group-start"><label>Before your mail</label></li>
    <?= $link('notice', '/notice', 'feather-file-text', 'Monitoring notice', '', true) ?>
<?php else: ?>
    <li class="nxl-item nxl-caption" id="nav-group-mailboxes"><label>Mailboxes</label></li>
    <?php if ($rail === []): ?>
        <li class="nxl-item px-3 py-2 fs-12 text-muted" id="rail-empty">No mailbox is open to you yet.</li>
    <?php endif; ?>
    <?php foreach ($rail as $b): $isCurrent = $current !== null && (int) $b['id'] === (int) $current['id']; ?>
        <?= $link('mailbox-' . (int) $b['id'], '/m/' . (int) $b['id'] . '/v/inbox', $kindIcon[$b['kind']] ?? 'feather-mail', (string) $b['address'],
                  $b['inbox_unseen'] === null ? '—' : (string) (int) $b['inbox_unseen'], $isCurrent && $here === '/m/' . (int) $b['id'] . '/v/inbox') ?>
        <?php if ($isCurrent): ?>
            <li class="nxl-item rail-views" id="rail-views-<?= (int) $b['id'] ?>"><ul class="list-unstyled ps-4 mb-1">
            <?php foreach (ML_VIEWS as $view => [$vlabel, $vicon]): if ($view === 'inbox') { continue; } $vurl = '/m/' . (int) $b['id'] . '/v/' . $view; ?>
                <?= $link('view-' . $view, $vurl, $vicon, $vlabel, '', $here === $vurl) ?>
            <?php endforeach; ?>
            </ul></li>
        <?php endif; ?>
    <?php endforeach; ?>
    <li class="nxl-item nxl-caption" id="nav-group-more"><label>More</label></li>
    <?php foreach (nav_top_items() as [$id, $url, $icon, $label, $shown]): if (!$shown) { continue; } ?>
        <?= $link($id, $url, $icon, $label, '', $activeNav === $id || str_starts_with($here, $url)) ?>
    <?php endforeach; ?>
<?php endif; ?>
</ul>
