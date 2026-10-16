<?php
/**
 * #mail-list: the filter chip, "1–50 of N" with Newer / Older, the "N new" bar's slot, the bulk bar, the rows. Older and Newer swap this
 * element whole (outerHTML) and push the URL. Data: as app/views/mail/list.php, plus base (the page's path).
 */
$id = (int) $box['id'];
$label = $label ?? null;
$matching = mail_view_matching($label !== null ? 'label' : ($folder !== null ? 'folder' : $view), $folder, $label, !empty($unread));
$rows = $page['rows'];
$first = $rows[0]['cursor'] ?? '';
$q = static function (array $over) use ($unread): string {
    $p = array_filter(array_merge(['unread' => $unread ? '1' : ''], $over), static fn ($v): bool => $v !== '' && $v !== null);
    return $p === [] ? '' : '?' . http_build_query($p);
};
$from = $rows === [] ? 0 : $page['start'] + 1;
$to = $page['start'] + count($rows);
$totalText = $page['total'] > 10000 ? 'more than 10,000' : number_format($page['total']);
$nav = static function (string $url, string $html, string $btnId): string {
    return '<a class="btn btn-sm btn-light btn-touch" id="' . e($btnId) . '" href="' . e($url) . '" hx-get="' . e($url) . '" hx-target="#mail-list" hx-swap="outerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
};
?>
<div id="mail-list" class="mail-list density-<?= e($density) ?>" data-screen="<?= $label !== null ? 'label-view' : ($folder !== null ? 'folder-view' : 'mailbox-view') ?>" data-mailbox="<?= $id ?>"
     data-view="<?= e($label !== null ? 'label' : ($folder !== null ? 'folder' : $view)) ?>" data-folder="<?= $folder !== null ? (int) $folder['id'] : '' ?>" data-label="<?= $label !== null ? (int) $label['id'] : '' ?>" data-matching="<?= e((string) $matching) ?>" data-change-seq="<?= (int) $page['seq'] ?>"
     data-top="<?= e($first) ?>" data-limit="<?= (int) $page['limit'] ?>" data-unread="<?= $unread ? '1' : '' ?>" data-base="<?= e($base) ?>"
     data-poll-visible="<?= (int) $poll[0] ?>" data-poll-hidden="<?= (int) $poll[1] ?>" data-title="<?= e($title . ' — ' . $box['address']) ?>">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2" id="mail-list-toolbar">
        <div class="form-check mb-0 ms-1">
            <input class="form-check-input mail-select-all" type="checkbox" id="mail-select-page" aria-label="Select every conversation on this page">
        </div>
        <?= hx_link($base . $q(['unread' => $unread ? '' : '1']), '<i class="feather-filter me-1"></i>Unread only',
            'btn btn-sm btn-touch ' . ($unread ? 'btn-primary' : 'btn-light'), 'id="mail-unread-chip" hx-target="#mail-list" hx-swap="outerHTML"') ?>
        <div class="ms-auto d-flex align-items-center gap-2">
            <span class="fs-12" id="mail-range"><?= $rows === [] ? '' : e(number_format($from) . '–' . number_format($to) . ' of ' . $totalText) ?></span>
            <?php if ($page['newer'] !== null): ?>
                <?= $nav($base . $q(['before' => $page['newer'], 'n' => $page['newer'] === '' ? '' : (string) max(0, $page['start'] - $page['limit'])]), '<i class="feather-chevron-left"></i><span class="d-none d-md-inline ms-1">Newer</span>', 'mail-newer') ?>
            <?php endif; ?>
            <?php if ($page['older'] !== null): ?>
                <?= $nav($base . $q(['before' => $page['older'], 'n' => (string) $to]), '<span class="d-none d-md-inline me-1">Older</span><i class="feather-chevron-right"></i>', 'mail-older') ?>
            <?php endif; ?>
        </div>
    </div>
    <div id="mail-new-bar"></div>
    <?= view('mail/partials/bulk-bar.php', ['box' => $box, 'folder' => $folder, 'label' => $label, 'view' => $view, 'total' => $page['total'], 'count' => count($rows), 'matching' => $matching]) ?>
    <?php if ($rows === []): ?>
        <?= view('mail/partials/empty.php', ['box' => $box, 'view' => $view, 'folder' => $folder, 'label' => $label, 'status' => $status, 'unread' => $unread]) ?>
    <?php endif; ?>
    <div class="card mb-0<?= $rows === [] ? ' d-none' : '' ?>"><div class="list-group list-group-flush mail-rows" id="mail-rows" role="list">
        <?php foreach ($rows as $r): ?><?= view('mail/partials/row.php', ['r' => $r, 'box' => $box, 'view' => $view, 'tz' => $tz, 'oob' => false]) ?><?php endforeach; ?>
    </div></div>
</div>
