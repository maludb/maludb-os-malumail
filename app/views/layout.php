<?php
/**
 * The app shell (design-system nxl skeleton), PHONE FIRST — docs/build-specs/sso-shell.md. Wraps a screen's page HTML.
 * Data: title, content, activeNav?, screen?, entity?, recordId?, mailboxId?
 * TWO panes at 1280 px: THE MAILBOX RAIL (the sidebar: the mailboxes the person opens — ml_rail(), the wall in SQL — with the current
 * mailbox's views, then Oversight, Templates, Admin and Notifications as the person's rights allow) and the main pane #page-content (the
 * HTMX target). ONE pane at 375 px: the header's menu button opens the rail as the theme's offcanvas; the bottom tab bar carries
 * Inbox · Search · Compose · Me; the command bar sits above it.
 */
$title     = $title     ?? app_name();
$content   = $content   ?? '';
$activeNav = $activeNav ?? '';
$screen    = $screen    ?? '';
$entity    = $entity    ?? '';
$recordId  = $recordId  ?? '';
$pdo       = db();
$m         = current_member();
$me        = (int) ($m['id'] ?? 0);
$initials  = strtoupper(mb_substr((string) ($m['display_name'] ?? '?'), 0, 1));
$unread    = $m !== null ? bell_count($pdo, $me) : 0;
$mailboxId = $mailboxId ?? current_mailbox_id();
$rail      = ml_rail($pdo);
$current   = null;
foreach ($rail as $b) { if ((int) $b['id'] === (int) $mailboxId) { $current = $b; } }
$current ??= $rail[0] ?? null;
$noticeOk  = (current_member()['member_kind'] ?? '') !== 'human' || db_bool($pdo, 'SELECT ml_notice_acknowledged(:m)', ['m' => $me]);
$railData  = $noticeOk ? mail_rail($pdo, (int) ($current['id'] ?? 0)) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="x-ua-compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="description" content="<?= e(app_name()) ?>" />
    <meta name="theme-color" content="#3454d1" />
    <meta name="htmx-config" content='{"responseHandling":[{"code":"204","swap":false},{"code":"[23]..","swap":true},{"code":"422","swap":true,"error":false},{"code":"[45]..","swap":false,"error":true}]}'>
    <meta name="csrf-token" id="csrf-token-meta" content="<?= e(csrf_token()) ?>" />
    <title><?= e($title) ?> · <?= e(app_name()) ?></title>
    <link rel="manifest" href="/manifest.webmanifest" />
    <link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png" />
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png" />
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/vendors/css/vendors.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/vendors/css/select2.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/css/theme.min.css" />
    <link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css" />
</head>
<body data-mailbox="<?= e((string) ($current['id'] ?? '')) ?>">
    <nav class="nxl-navigation" id="left-sidenav">
        <div class="navbar-wrapper">
            <div class="m-header">
                <a href="/" class="b-brand" hx-get="/" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="/">
                    <img src="/assets/images/logo-full.png" alt="<?= e(app_name()) ?>" class="logo logo-lg" />
                    <img src="/assets/images/logo-abbr.png" alt="" class="logo logo-sm" />
                </a>
            </div>
            <div class="navbar-content">
                <?= view('shared/rail.php', ['railData' => $railData, 'activeNav' => $activeNav, 'noticeOk' => $noticeOk]) ?>
            </div>
        </div>
    </nav>

    <header class="nxl-header" id="top-header">
        <div class="header-wrapper">
            <div class="header-left d-flex align-items-center gap-3">
                <a href="javascript:void(0);" class="nxl-head-mobile-toggler" id="mobile-collapse" aria-label="Menu">
                    <div class="hamburger hamburger--arrowturn">
                        <div class="hamburger-box"><div class="hamburger-inner"></div></div>
                    </div>
                </a>
                <div class="nxl-navigation-toggle">
                    <a href="javascript:void(0);" id="menu-mini-button"><i class="feather-align-left"></i></a>
                    <a href="javascript:void(0);" id="menu-expend-button" style="display: none"><i class="feather-arrow-right"></i></a>
                </div>
                <div class="header-role d-flex align-items-center" id="header-person">
                    <span class="fw-semibold text-dark text-truncate" id="header-mailbox-name"><?= e($current !== null ? $current['address'] : app_name()) ?></span>
                </div>
                <form class="d-none d-md-flex" id="header-search" method="get" action="<?= e($current !== null && preg_match('#^/m/[0-9]+#', current_path()) ? '/m/' . (int) $current['id'] . '/search' : '/search') ?>" role="search">
                    <input type="search" name="q" class="form-control form-control-sm" placeholder="Search mail — from: has:attachment…" aria-label="Search mail" autocomplete="off" />
                </form>
            </div>
            <div class="header-right ms-auto">
                <div class="d-flex align-items-center">
                    <div class="dropdown nxl-h-item" id="bell-dropdown">
                        <a href="javascript:void(0);" class="nxl-head-link me-0 position-relative" id="bell" aria-label="Notifications" role="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                            <i class="feather-bell"></i>
                            <?= view('shared/bell.php', ['unread' => $unread]) ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown p-0" id="bell-menu" style="width:340px;max-width:calc(100vw - 24px)"
                             hx-get="/notifications?menu=1" hx-trigger="mouseenter from:#bell-dropdown throttle:15s, click from:#bell throttle:15s, show.bs.dropdown from:#bell throttle:15s, notificationChanged from:body" hx-swap="innerHTML">
                            <div class="px-3 py-3 text-muted fs-12">Loading…</div>
                        </div>
                    </div>
                    <?= view('shared/app-switcher.php') ?>
                    <div class="nxl-h-item dark-light-theme">
                        <a href="javascript:void(0);" class="nxl-head-link me-0 dark-button"><i class="feather-moon"></i></a>
                        <a href="javascript:void(0);" class="nxl-head-link me-0 light-button" style="display:none"><i class="feather-sun"></i></a>
                    </div>
                    <div class="dropdown nxl-h-item">
                        <a href="javascript:void(0);" data-bs-toggle="dropdown" role="button" data-bs-auto-close="outside" id="header-user-menu" aria-label="Your account">
                            <span class="avatar-text avatar-md user-avtar me-0"><?= e($initials) ?></span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end nxl-h-dropdown nxl-user-dropdown">
                            <div class="dropdown-header">
                                <div class="d-flex align-items-center">
                                    <span class="avatar-text avatar-md user-avtar"><?= e($initials) ?></span>
                                    <div class="min-w-0">
                                        <h6 class="text-dark mb-0" id="header-user-name"><?= e($m['display_name'] ?? 'Member') ?></h6>
                                        <span class="fs-12 fw-medium text-muted" id="header-user-email"><?= e($m['email'] ?? '') ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a href="/settings/notifications" class="dropdown-item" hx-get="/settings/notifications" hx-target="#page-content" hx-push-url="/settings/notifications" id="header-settings-link"><i class="feather-settings"></i><span>My settings</span></a>
                            <a href="/my/trail" class="dropdown-item" hx-get="/my/trail" hx-target="#page-content" hx-push-url="/my/trail" id="header-trail-link"><i class="feather-activity"></i><span>My trail</span></a>
                            <a href="/tokens" class="dropdown-item" hx-get="/tokens" hx-target="#page-content" hx-push-url="/tokens" id="header-tokens-link"><i class="feather-key"></i><span>Tokens</span></a>
                            <a href="/notice" class="dropdown-item" hx-get="/notice" hx-target="#page-content" hx-push-url="/notice" id="header-notice-link"><i class="feather-file-text"></i><span>Monitoring notice</span></a>
                            <a href="<?= e(launcher_url()) ?>" class="dropdown-item" id="header-launcher-item"><i class="feather-grid"></i><span>All applications</span></a>
                            <div class="dropdown-divider"></div>
                            <form method="post" action="/logout.php" class="px-2">
                                <?= csrf_field() ?>
                                <button type="submit" id="header-logout-btn" class="dropdown-item border-0 bg-transparent w-100 text-start"><i class="feather-log-out"></i><span>Sign out</span></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="nxl-container app-has-assistant-bar app-has-tabbar">
        <div id="flash"></div>
        <div class="nxl-content" id="page-content"
             data-screen="<?= e($screen) ?>" data-entity="<?= e($entity) ?>" data-record-id="<?= e($recordId) ?>" data-mailbox="<?= e((string) ($mailboxId ?? '')) ?>">
            <?= $content ?>
        </div>
        <footer class="footer" id="page-footer">
            <p class="fs-11 text-muted fw-medium text-uppercase mb-0 copyright"><span>© <?= date('Y') ?> <?= e(app_name()) ?> · an application for the Business OS</span></p>
            <div class="d-flex align-items-center gap-4">
                <a href="/my/trail" class="fs-11 fw-semibold text-uppercase" hx-get="/my/trail" hx-target="#page-content" hx-push-url="/my/trail">My trail</a>
                <a href="<?= e(launcher_url()) ?>" class="fs-11 fw-semibold text-uppercase">Applications</a>
            </div>
        </footer>
    </main>

    <!-- RECORD PICKER (design-system references/record-picker.md; app/picker.php, app/pickers.php): the ONE modal that chooses a record for any
         picker field on the screen — outside #page-content so swaps keep it. The second exemption to the no-modal rule, beside hx-confirm. -->
    <div class="modal fade" id="record-picker" tabindex="-1" aria-labelledby="record-picker-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-md-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="record-picker-title">Choose</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="record-picker-close"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="record-picker-search p-3 border-bottom">
                        <div class="input-group">
                            <span class="input-group-text"><i class="feather-search"></i></span>
                            <input type="search" class="form-control" id="record-picker-search" placeholder="Search…" autocomplete="off" aria-controls="record-picker-results"
                                   hx-get="/pick/" hx-trigger="input changed delay:300ms, search, pickerload" hx-target="#record-picker-results" hx-swap="innerHTML"
                                   hx-sync="this:replace" hx-vals="js:{...recordPickerValues()}" />
                        </div>
                    </div>
                    <div id="record-picker-results" aria-live="polite" hx-on::before-swap="if(event.detail.xhr.status>=400){event.detail.shouldSwap=true;event.detail.isError=false;}"></div>
                </div>
                <div class="modal-footer d-none" id="record-picker-footer">
                    <button type="button" class="btn btn-light-brand" id="record-picker-create-btn"><i class="feather-plus me-1"></i>New</button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($noticeOk): ?><?= view('shared/assistant-bar.php') ?><?php endif; ?>
    <?= view('shared/tab-bar.php', ['activeNav' => $activeNav, 'noticeOk' => $noticeOk]) ?>

    <script src="/assets/vendors/js/vendors.min.js"></script>
    <script src="/assets/vendors/js/select2.min.js"></script>
    <script src="/assets/vendors/js/select2-active.min.js"></script>
    <script src="/assets/vendors/js/htmx.min.js"></script>
    <script>
        document.body.addEventListener('htmx:configRequest', function (e) {
            if (e.detail.verb && e.detail.verb.toLowerCase() !== 'get') {
                var meta = document.querySelector('#csrf-token-meta');
                if (meta) { e.detail.headers['X-CSRF-Token'] = meta.content; }
            }
        });
        (function () { var f = document.getElementById('assistant-form'), i = document.getElementById('assistant-input'); if (!f || !i) return;
            i.addEventListener('input', function () { f.classList.toggle('in-use', i.value.trim() !== ''); }); })();
        document.body.addEventListener('htmx:afterSwap', function (e) {
            var pc = document.getElementById('page-content');
            if (!pc) return;
            var xt = e.detail && e.detail.xhr ? e.detail.xhr.getResponseHeader('HX-Title') : null;
            if (xt) { document.title = decodeURIComponent(xt); }
            if (e.detail && e.detail.target && e.detail.target.id === 'flash') { window.scrollTo({ top: 0, behavior: 'smooth' }); }
            var xhr = e.detail && e.detail.xhr;
            if (xhr && xhr.getResponseHeader('X-Screen') !== null) {
                pc.dataset.screen = xhr.getResponseHeader('X-Screen') || '';
                pc.dataset.entity = xhr.getResponseHeader('X-Entity') || '';
                pc.dataset.recordId = xhr.getResponseHeader('X-Record-Id') || '';
            }
            if (!e.detail || !e.detail.target || e.detail.target.id !== 'page-content') return;
            var navId = pc.dataset.screen || '';
            document.querySelectorAll('.nxl-navbar .nxl-link, #shell-tabbar .app-tab').forEach(function (a) { a.classList.remove('active'); });
            var link = document.querySelector('#nav-' + navId + ' .nxl-link');
            if (link) { link.classList.add('active'); }
            var tab = document.getElementById('tab-' + navId);
            if (tab) { tab.classList.add('active'); }
            if (window.jQuery) {
                if (jQuery.fn.tooltip) { jQuery('[data-bs-toggle="tooltip"]').tooltip(); }
                if (jQuery.fn.select2) { jQuery('#page-content select[data-select2-selector]').each(function () { if (!jQuery(this).hasClass('select2-hidden-accessible')) { jQuery(this).select2({ width: '100%' }); } }); }
            }
            var nav = document.querySelector('nav.nxl-navigation');
            if (nav && nav.classList.contains('mob-navigation-active')) { nav.classList.remove('mob-navigation-active'); var ov = document.querySelector('.nxl-menu-overlay'); if (ov) ov.remove(); }
        });
        document.body.addEventListener('htmx:responseError', function (e) {
            var f = document.getElementById('flash');
            if (f && e.detail.xhr && e.detail.xhr.status >= 500) { f.innerHTML = '<div class="alert alert-danger m-3">Something went wrong. Try again.</div>'; }
        });
    </script>
    <script src="/assets/js/common-init.min.js"></script>
    <script src="/assets/js/record-picker.js"></script>
    <script src="/assets/js/malumail.js"></script>
    <script src="/assets/js/mail-list.js"></script>
    <script src="/assets/js/theme-customizer-init.min.js"></script>
</body>
</html>
