<?php
/**
 * The Original (screen `message-original`, reading.md): the headers as a two-column list, the source escaped in a pre-wrap block cut at
 * 1 MB, Download .eml. Data: box, m, headers ([[name, value]]), source (string), truncated (bool), tz, threadUrl
 */
$mb = (int) $box['id'];
$id = (int) $m['id'];
$subject = ($m['subject'] ?? '') !== '' ? (string) $m['subject'] : '(no subject)';
?>
<?= view('shared/header.php', ['id' => 'mail-original', 'title' => 'Original message', 'crumbs' => [[$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $mb . '/v/inbox'], [$subject, $threadUrl], ['Original', null]], 'back' => [$threadUrl, 'the conversation'],
    'action' => '<a class="btn btn-sm btn-primary btn-touch" id="mail-original-download" href="/m/' . $mb . '/msg/' . $id . '/raw" download><i class="feather-download me-1"></i>Download .eml</a>']) ?>
<div class="main-content mail-original" id="mail-original" data-message="<?= $id ?>">
    <div class="card mb-3"><div class="card-body">
        <dl class="row mb-0 fs-13" id="mail-original-headers">
            <?php foreach ($headers as [$n, $v]): ?>
                <dt class="col-sm-3 text-truncate"><?= e($n) ?></dt><dd class="col-sm-9 text-break"><?= e($v) ?></dd>
            <?php endforeach; ?>
        </dl>
    </div></div>
    <?php if ($truncated): ?><div class="alert alert-light fs-13">The source is cut at 1 MB here — <a href="/m/<?= $mb ?>/msg/<?= $id ?>/raw" download>download the whole message (.eml)</a>.</div><?php endif; ?>
    <div class="card"><div class="card-body"><pre id="mail-original-source"><?= e($source) ?></pre></div></div>
</div>
