<?php /** Mail that did not wake its agent (screen `unscreened`). Data: rows (unscreened_list), mailbox (filter), notice, tz */
$self = '/oversight/unscreened' . ($mailbox !== null ? '?mailbox=' . (int) $mailbox : '');
$act = static function (string $action, string $fid, array $r, string $label, string $cls, array $extra = []) use ($self): string {
    $h = '<form method="post" action="/oversight/' . $action . '" hx-post="/oversight/' . $action . '" hx-target="#page-content" class="d-inline" id="' . e($fid) . '">' . csrf_field()
       . '<input type="hidden" name="mailbox" value="' . (int) $r['mailbox_id'] . '"><input type="hidden" name="message" value="' . (int) $r['message_key_id'] . '">'
       . '<input type="hidden" name="return_to" value="' . e($self) . '">';
    foreach ($extra as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    return $h . '<button type="submit" class="' . $cls . '">' . e($label) . '</button></form>';
};
?>
<?= view('shared/header.php', ['id' => 'unscreened', 'title' => 'Unscreened', 'crumbs' => [[app_name(), '/'], ['Oversight', '/oversight'], ['Unscreened', null]], 'back' => ['/oversight', 'Oversight']]) ?>
<div class="main-content" id="unscreened-content">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <?php if ($mailbox !== null): ?><div class="mb-2 fs-12"><?= hx_link('/oversight/unscreened', 'Every mailbox', '', 'id="unscreened-all"') ?></div><?php endif; ?>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="unscreened-empty">Nothing waits — every message that arrived either woke its agent or needed no one.</div></div>
    <?php endif; ?>
    <div class="list-group" id="unscreened-list">
        <?php foreach ($rows as $r): $k = (int) $r['message_key_id']; $id = 'unscreened-' . $k; $dm = $r['dmarc_pass']; ?>
        <div class="list-group-item py-2" id="<?= $id ?>" data-message="<?= $k ?>">
            <div class="d-flex flex-wrap align-items-baseline gap-2">
                <span class="fw-semibold text-break"><?= e((string) ($r['subject'] ?? '') !== '' ? (string) $r['subject'] : '(no subject)') ?></span>
                <span class="fs-11 text-muted ms-auto"><?= e(format_ts((string) ($r['received_at'] ?? $r['created_at']), $tz, 'M j, g:i A')) ?></span>
            </div>
            <div class="fs-12 text-muted text-break"><?= e((string) $r['mailbox_address']) ?> · from <?= e((string) $r['from_address']) ?>
                <span class="badge <?= $dm === null ? 'bg-soft-secondary text-secondary' : ($dm ? 'bg-soft-success text-success' : 'bg-soft-danger text-danger') ?>">DMARC <?= $dm === null ? 'none' : ($dm ? 'pass' : 'fail') ?></span></div>
            <div class="fs-13 mt-1" id="<?= $id ?>-why"><i class="feather-help-circle me-1 text-muted"></i><?= e(OV_UNSCREENED_WORDS[$r['decision']] ?? (string) $r['decision']) ?></div>
            <div class="d-flex flex-wrap gap-1 mt-2">
                <?php if ($r['can_decide']): ?>
                    <?= $act('release', $id . '-release', $r, 'Release (one turn)', 'btn btn-sm btn-primary btn-touch') ?>
                    <?php if (($r['from_address'] ?? '') !== ''): ?><form method="post" action="/oversight/mark-known" hx-post="/oversight/mark-known" hx-target="#page-content" class="d-inline" id="<?= $id ?>-known"><?= csrf_field() ?>
                        <input type="hidden" name="mailbox" value="<?= (int) $r['mailbox_id'] ?>"><input type="hidden" name="address" value="<?= e((string) $r['from_address']) ?>"><input type="hidden" name="return_to" value="<?= e($self) ?>">
                        <button type="submit" class="btn btn-sm btn-light btn-touch">Mark sender known</button></form><?php endif; ?>
                    <?= $act('dismiss', $id . '-spam', $r, 'Spam', 'btn btn-sm btn-light btn-touch', ['as_spam' => 'yes']) ?>
                    <?= $act('dismiss', $id . '-dismiss', $r, 'Dismiss', 'btn btn-sm btn-light btn-touch') ?>
                <?php else: ?><span class="fs-12 text-muted">For its overseers</span><?php endif; ?>
                <?php if ($r['thread_id'] !== null): ?><?= hx_link('/m/' . (int) $r['mailbox_id'] . '/t/' . (int) $r['thread_id'], 'Open', 'btn btn-sm btn-light btn-touch', 'id="' . $id . '-open"') ?><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
