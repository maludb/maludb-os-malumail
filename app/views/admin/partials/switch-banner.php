<?php /** The red banner across every admin page while a business-wide switch is on (home-admin.md). Data: none (reads the switches itself). */
$sw = admin_switches(db());
if ($sw['hold_all_agents'] || $sw['hold_all_external']): ?>
<div class="alert alert-danger d-flex flex-wrap align-items-center gap-2" role="alert" id="switch-banner">
    <i class="feather-alert-octagon"></i>
    <span><?= $sw['hold_all_agents'] ? 'Every agent\'s mail is being held' : 'Every agent\'s mail to anyone outside the business is being held' ?> —
        <?= hx_link('/admin/settings#switches', 'Settings', 'alert-link', 'id="switch-banner-link"') ?></span>
</div>
<?php endif; ?>
