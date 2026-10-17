<?php /** The access log (screen `mailbox-access-log`). Data: ctx, rows, who (id|null), who_name, since, more (next before id|null), notice, tz */
$box = $ctx['row'];
$mb = (int) $ctx['id'];
$mine = (bool) $ctx['owner'];
?>
<?= view('shared/header.php', ['id' => 'mailbox-access-log', 'title' => 'Who looked at ' . $box['address'], 'crumbs' => [[app_name(), '/'], [$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $mb . '/v/inbox'], ['Access', '/m/' . $mb . '/access'], ['Who looked', null]],
    'back' => ['/m/' . $mb . '/access', 'Access']]) ?>
<div class="main-content" id="mailbox-access-log-content">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <form method="get" action="/m/<?= $mb ?>/access-log" id="access-log-filter" class="card mb-3"><div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-6"><?= picker_field(['id' => 'access-log-field-who', 'name' => 'who', 'source' => 'member', 'value' => $who, 'placeholder' => 'Anyone', 'title' => 'People']) ?></div>
            <div class="col-8 col-md-3"><label class="form-label fs-12 text-muted mb-0" for="access-log-since">Since</label><input type="date" name="since" id="access-log-since" class="form-control btn-touch" value="<?= e((string) $since) ?>"></div>
            <div class="col-4 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="access-log-apply">Show</button></div>
        </div>
    </div></form>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="access-log-empty"><?= $who !== null || $since !== '' ? 'No look matches.' : ($mine ? 'No one but you has looked.' : 'No one but its owner has looked.') ?></div></div>
    <?php else: ?>
    <div class="card" id="access-log-list"><div class="list-group list-group-flush">
        <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
        <div class="list-group-item" id="access-log-<?= $id ?>" data-action="<?= e((string) $r['action']) ?>">
            <div class="d-flex flex-wrap justify-content-between gap-1">
                <div class="fw-semibold text-break"><span id="access-log-<?= $id ?>-who"><?= e((string) ($r['actor_name'] ?? 'The system')) ?></span> <span class="fw-normal" id="access-log-<?= $id ?>-what"><?= e(AC_WHAT[$r['action']] ?? $r['action']) ?></span></div>
                <div class="fs-12 text-muted" id="access-log-<?= $id ?>-when"><?= e(format_ts((string) $r['occurred_at'], $tz, 'M j, Y g:i A')) ?></div>
            </div>
            <div class="fs-12 text-muted" id="access-log-<?= $id ?>-through"><?= e(ac_through($r)) ?></div>
        </div>
        <?php endforeach; ?>
    </div></div>
    <?php if ($more !== null): ?><div class="mt-3"><?= hx_link('/m/' . $mb . '/access-log?' . http_build_query(array_filter(['who' => $who, 'since' => $since, 'before' => $more])), 'Older', 'btn btn-light btn-touch', 'id="access-log-older"') ?></div><?php endif; ?>
    <?php endif; ?>
</div>
