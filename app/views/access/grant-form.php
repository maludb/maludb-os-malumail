<?php /** The grant form (screen `access-grant-new`): the ordinary share, or — a Mail admin on a person's mailbox — the break-glass form. Data: ctx, tz, values (re-render) */
$box = $ctx['row'];
$mb = (int) $ctx['id'];
$bg = $ctx['grant'] === 'break_glass';
$levelRadio = static function (string $current) use ($bg): string {
    $o = '';
    foreach (AC_LEVELS as $l) {
        $o .= '<div class="col-12 col-md-6"><label class="d-block border rounded p-3 h-100 btn-touch" for="grant-level-' . $l . '">'
            . '<div class="form-check mb-1"><input class="form-check-input" type="radio" name="level" id="grant-level-' . $l . '" value="' . $l . '"' . ($current === $l ? ' checked' : '') . ' required>'
            . '<span class="form-check-label fw-semibold">' . e(ucfirst($l)) . '</span></div><div class="fs-12 text-muted">' . e(AC_LEVEL_WORDS[$l]) . '</div></label></div>';
    }
    return '<div class="row g-2" id="grant-levels">' . $o . '</div>';
};
$ownerName = (string) ($box['owner_name'] ?? 'the owner');
?>
<?= view('shared/header.php', ['id' => 'access-grant-new', 'title' => $bg ? 'Emergency access' : 'Share ' . $box['address'], 'crumbs' => [[app_name(), '/'], [$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $mb . '/v/inbox'], ['Access', '/m/' . $mb . '/access'], [$bg ? 'Emergency access' : 'Share', null]],
    'back' => ['/m/' . $mb . '/access', 'Access']]) ?>
<div class="main-content" id="access-grant-new-content">
    <form method="post" action="/access/grant" hx-post="/access/grant" hx-target="#page-content" id="grant-form" class="card"><div class="card-body"><?= csrf_field() ?>
        <input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="return_to" value="/m/<?= $mb ?>/access">
        <?php if ($bg): ?>
            <div class="alert alert-danger" id="grant-break-glass-note"><strong>Break-glass.</strong> This is a person's mailbox and you are not its owner. Emergency access is for one person, needs a reason, lasts at most 30 days, and every look is in the access log. <?= e($ownerName) ?> is told at once.</div>
            <?= form_picker('grant', 'person', 'Who', 'member', $values['person'] ?? null, [], ['help' => 'One person.', 'required' => true]) ?>
        <?php else: ?>
            <?= form_picker('grant', 'person', 'A person', 'member', $values['person'] ?? null, [], ['help' => 'Choose one person, one department or one agent.']) ?>
            <?= form_picker('grant', 'department', 'A department', 'department', $values['department'] ?? null, [], ['help' => 'Its people. A department never admits an agent.']) ?>
            <?= form_picker('grant', 'agent', 'An agent', 'agent', $values['agent'] ?? null, [], ['help' => $ctx['kind'] === 'person' ? 'An agent reaches a person\'s mailbox at most at send.' : 'An agent reaches this mailbox at the level you choose.']) ?>
        <?php endif; ?>
        <div class="mb-3" id="grant-level-group"><div class="fw-semibold mb-2" id="grant-level-label">Level</div><?= $levelRadio((string) ($values['level'] ?? '')) ?></div>
        <?php if ($bg): ?>
            <div class="mb-3"><label class="form-label fw-semibold" for="grant-reason">Why (<?= e($ownerName) ?> reads this)</label>
                <textarea name="reason" id="grant-reason" class="form-control" rows="3" minlength="10" maxlength="300" required><?= e((string) ($values['reason'] ?? '')) ?></textarea></div>
            <div class="mb-3"><label class="form-label fw-semibold" for="grant-expires">Until</label>
                <input type="date" name="expires_at" id="grant-expires" class="form-control btn-touch" style="max-width:12rem" value="<?= e((string) ($values['expires_at'] ?? gmdate('Y-m-d', time() + 7 * 86400))) ?>"
                       min="<?= e(gmdate('Y-m-d')) ?>" max="<?= e(gmdate('Y-m-d', time() + 30 * 86400)) ?>" required>
                <div class="form-text">Seven days unless you change it; at most 30.</div></div>
        <?php else: ?>
            <div class="mb-3"><label class="form-label fw-semibold" for="grant-expires">Until (optional)</label>
                <input type="date" name="expires_at" id="grant-expires" class="form-control btn-touch" style="max-width:12rem" min="<?= e(gmdate('Y-m-d', time() + 86400)) ?>" value="<?= e((string) ($values['expires_at'] ?? '')) ?>">
                <div class="form-text">Leave empty to keep it until you take it back.</div></div>
        <?php endif; ?>
        <button type="submit" class="btn <?= $bg ? 'btn-danger' : 'btn-primary' ?> btn-touch" id="grant-save"><?= $bg ? 'Give emergency access' : 'Share' ?></button>
        <?= hx_link('/m/' . $mb . '/access', 'Cancel', 'btn btn-light btn-touch ms-1', 'id="grant-cancel"') ?>
    </div></form>
</div>
