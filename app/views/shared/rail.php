<?php
/**
 * THE MAILBOX RAIL (sso-shell.md "The shell"; mail-list.md "The rail"; design §9): the mailboxes the person opens in four groups with
 * their unread counts (app/views/mail/partials/rail.php — mail_rail(), the wall in SQL), the current mailbox's views and folders; then
 * Oversight (a person who decides for an agent mailbox), Templates, Admin (a Mail admin), Notifications. Before the notice is
 * acknowledged only Notifications and the notice show. Data: railData (mail_rail()'s answer, or null before the notice), activeNav, noticeOk.
 */
$here = current_path();
$link = static function (string $id, string $url, string $icon, string $label, bool $active = false): string {
    return '<li class="nxl-item" id="nav-' . e($id) . '">'
        . '<a class="nxl-link' . ($active ? ' active' : '') . '" href="' . e($url) . '" hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">'
        . '<span class="nxl-micon"><i class="' . e($icon) . '"></i></span><span class="nxl-mtext text-truncate">' . e($label) . '</span></a></li>';
};
?>
<ul class="nxl-navbar" id="shell-sidebar">
<?php if (!$noticeOk): ?>
    <li class="nxl-item nxl-caption" id="nav-group-start"><label>Before your mail</label></li>
    <?= $link('notice', '/notice', 'feather-file-text', 'Monitoring notice', true) ?>
<?php else: ?>
    <?= view('mail/partials/rail.php', ['railData' => $railData, 'here' => $here]) ?>
    <li class="nxl-item nxl-caption" id="nav-group-more"><label>More</label></li>
    <?php foreach (nav_top_items() as [$id, $url, $icon, $label, $shown]): if (!$shown) { continue; } ?>
        <?= $link($id, $url, $icon, $label, $activeNav === $id || str_starts_with($here, $url)) ?>
    <?php endforeach; ?>
<?php endif; ?>
</ul>
