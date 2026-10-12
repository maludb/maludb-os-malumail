<?php /** The phone's tab bar (hidden from 992 px, where the rail is): Inbox · Search · Compose · Me (nav_tabs()). Before the notice is acknowledged: the notice and Me. Data: activeNav, noticeOk */
$tabs = ($noticeOk ?? true) ? nav_tabs() : [['tab-notice', '/notice', 'feather-file-text', 'Notice'], ['tab-me', '/my/trail', 'feather-user', 'Me']]; ?>
<nav class="app-tabbar d-lg-none" id="shell-tabbar" aria-label="Main">
    <?php foreach ($tabs as [$tid, $turl, $ticon, $tlabel]): ?>
        <a href="<?= e($turl) ?>" id="<?= e($tid) ?>" class="app-tab<?= ($activeNav ?? '') === $tid ? ' active' : '' ?>"
           hx-get="<?= e($turl) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($turl) ?>">
            <i class="<?= e($ticon) ?>"></i><span><?= e($tlabel) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
