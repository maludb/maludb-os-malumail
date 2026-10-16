<?php
/**
 * The rail's mailboxes with their counts — ONE block (#rail-counts) so the poll can swap it out of band (mail-list.md "The poll").
 * Groups: My mailbox, Shared with me, Agents I oversee, Agents (a Mail admin). Each row: the name (the address on hover, and under it at
 * 1280), the Inbox's unread count for this person (— before the first sync), a dot when it is failing, a pause mark when an agent's mail
 * is paused, "3 for review". The current mailbox expands to its views (with counts where they matter) and its own folders as a tree.
 * Data: railData (mail_rail()), here (the page's path), oob (bool: the poll's copy)
 */
$kindIcon = ['person' => 'feather-user', 'shared' => 'feather-users', 'agent' => 'feather-cpu'];
$groups = $railData['groups'] ?? [];
$cur = $railData['current'] ?? null;
$counts = $railData['counts'] ?? null;
$item = static function (string $id, string $url, string $icon, string $label, string $count, bool $active, string $extra = '', string $title = ''): string {
    return '<li class="nxl-item" id="nav-' . e($id) . '">'
        . '<a class="nxl-link' . ($active ? ' active' : '') . '" href="' . e($url) . '" hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '"'
        . ($title !== '' ? ' title="' . e($title) . '"' : '') . '>'
        . '<span class="nxl-micon"><i class="' . e($icon) . '"></i></span><span class="nxl-mtext text-truncate">' . $label . '</span>'
        . $extra . '<span class="ms-auto fs-11 ' . ($count !== '' && $count !== '—' ? 'fw-bold text-body' : 'text-muted') . ' rail-count">' . e($count) . '</span>'
        . '</a></li>';
};
?>
<li class="nxl-item rail-counts" id="rail-counts"<?= !empty($oob) ? ' hx-swap-oob="outerHTML"' : '' ?>><ul class="list-unstyled mb-0">
<?php if ($groups === []): ?>
    <li class="nxl-item px-3 py-2 fs-12 text-muted" id="rail-empty">No mailbox is open to you yet.</li>
<?php endif; ?>
<?php foreach ($groups as $gkey => [$glabel, $rows]): ?>
    <li class="nxl-item px-3 pt-2 pb-1 fs-11 text-uppercase text-muted fw-semibold rail-group" id="rail-group-<?= e($gkey) ?>"><?= e($glabel) ?></li>
    <?php foreach ($rows as $b): $id = (int) $b['id']; $isCur = $cur !== null && (int) $cur['id'] === $id; $inbox = '/m/' . $id . '/v/inbox';
        $label = e($b['display_name'] !== '' ? $b['display_name'] : $b['address']) . '<span class="d-none d-xl-block fs-11 text-muted text-truncate rail-address">' . e($b['address']) . '</span>';
        $extra = ($b['failing'] ? '<span class="ms-1 text-danger rail-failing" role="img" title="Not syncing" aria-label="Not syncing">●</span>' : '')
            . ($b['paused'] ? '<i class="feather-pause-circle ms-1 text-warning rail-paused" role="img" title="Agent mail paused" aria-label="Agent mail paused"></i>' : '')
            . ((int) $b['held_count'] > 0 ? '<span class="badge bg-soft-warning text-warning ms-1 rail-held">' . (int) $b['held_count'] . ' for review</span>' : '');
        $count = $b['unread'] === null ? '—' : ((int) $b['unread'] > 0 ? (string) (int) $b['unread'] : ''); ?>
        <?= $item('mailbox-' . $id, $inbox, $kindIcon[$b['kind']] ?? 'feather-mail', $label, $count, $isCur && $here === $inbox, $extra, (string) $b['address']) ?>
        <?php if ($isCur): ?>
            <li class="nxl-item rail-views" id="rail-views-<?= $id ?>"><ul class="list-unstyled ps-4 mb-1">
            <?php foreach (ML_VIEWS as $view => [$vlabel, $vicon]): if ($view === 'inbox') { continue; } $vurl = '/m/' . $id . '/v/' . $view; ?>
                <?= $item('view-' . $view, $vurl, $vicon, e($vlabel), mail_view_count($counts, $view), $here === $vurl) ?>
            <?php endforeach; ?>
            <?php foreach ($railData['folders'] ?? [] as $f): $furl = '/m/' . $id . '/f/' . (int) $f['id']; ?>
                <li class="nxl-item" id="nav-folder-<?= (int) $f['id'] ?>" style="padding-left: <?= (int) $f['depth'] * 0.75 ?>rem">
                    <a class="nxl-link<?= $here === $furl ? ' active' : '' ?>" href="<?= e($furl) ?>" hx-get="<?= e($furl) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($furl) ?>" title="<?= e($f['name']) ?>">
                        <span class="nxl-micon"><i class="feather-folder"></i></span><span class="nxl-mtext text-truncate"><?= e($f['leaf']) ?></span>
                        <?php if ((int) $f['messages_unseen'] > 0): ?><span class="ms-auto fs-11 text-muted rail-count"><?= (int) $f['messages_unseen'] ?></span><?php endif; ?>
                    </a></li>
            <?php endforeach; ?>
            <?php foreach ($railData['labels'] ?? [] as $l): $lurl = '/m/' . $id . '/label/' . (int) $l['id']; ?>
                <li class="nxl-item" id="nav-label-<?= (int) $l['id'] ?>">
                    <a class="nxl-link<?= $here === $lurl ? ' active' : '' ?>" href="<?= e($lurl) ?>" hx-get="<?= e($lurl) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($lurl) ?>" title="<?= e($l['label']) ?>">
                        <span class="nxl-micon"><i class="feather-tag"<?= !empty($l['colour']) ? ' style="color:' . e($l['colour']) . '"' : '' ?>></i></span><span class="nxl-mtext text-truncate"><?= e($l['label']) ?></span></a></li>
            <?php endforeach; ?>
            <?php foreach ([['folders', 'Folders', 'feather-folder-plus'], ['labels', 'Labels', 'feather-tag']] as [$mid, $mlabel, $micon]): $murl = '/m/' . $id . '/' . $mid; ?>
                <?= $item('manage-' . $mid, $murl, $micon, e($mlabel), '', $here === $murl) ?>
            <?php endforeach; ?>
            </ul></li>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endforeach; ?>
</ul></li>
