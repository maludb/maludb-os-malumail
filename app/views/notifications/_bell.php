<?php /** The bell's menu: my latest eight notices and See all. Data: rows, unread, tz */ ?>
<div class="px-3 py-2 d-flex align-items-center border-bottom" id="bell-menu-head">
    <span class="fw-semibold">Notifications</span>
    <span class="ms-auto fs-12 text-muted" id="bell-menu-unread"><?= (int) $unread ?> unread</span>
</div>
<div class="list-group list-group-flush" id="bell-menu-rows" style="max-height:60vh;overflow-y:auto">
    <?php if ($rows === []): ?><div class="px-3 py-3 text-muted fs-12" id="bell-menu-empty">No notices.</div><?php endif; ?>
    <?php foreach ($rows as $n): ?><?= view('notifications/_row.php', ['n' => $n, 'tz' => $tz, 'compact' => true]) ?><?php endforeach; ?>
</div>
<div class="px-3 py-2 border-top text-center"><?= hx_link('/notifications', 'See all', 'fw-semibold', 'id="bell-see-all"') ?></div>
