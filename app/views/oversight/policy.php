<?php /** An agent mailbox's sending policy (screen `agent-policy`). Data: box, p (policy_get), history, notice, tz */
$mb = (int) $box['id'];
$can = $p['can_decide'];
$dis = $can ? '' : ' disabled';
$yes = static fn (string $k, bool $v) => '<input type="hidden" name="' . $k . '" value="no"><input type="checkbox" class="form-check-input mt-0" name="' . $k . '" value="yes" id="policy-' . $k . '"' . ($v ? ' checked' : '') . ' data-current="' . ($v ? 'yes' : 'no') . '">';
$row = static function (string $k, string $label, string $control) {
    return '<div class="mb-3 pb-3 border-bottom" id="policy-row-' . $k . '"><label class="form-label fw-semibold mb-1" for="policy-' . $k . '">' . e($label) . '</label>'
         . '<div class="fs-12 text-muted mb-2">' . e(OV_POLICY_MEANINGS[$k]) . '</div>' . $control . '</div>';
};
$b = $p['business'];
?>
<?= view('shared/header.php', ['id' => 'agent-policy', 'title' => 'Sending policy', 'crumbs' => [[app_name(), '/'], ['Oversight', '/oversight'], [$box['address'], '/m/' . $mb . '/v/inbox'], ['Policy', null]], 'back' => ['/oversight', 'Oversight']]) ?>
<div class="main-content" id="agent-policy-content">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <?php if ($p['paused']): ?>
        <div class="alert alert-danger" id="policy-paused-banner">Paused<?= $p['paused_by_name'] !== null ? ' by ' . e((string) $p['paused_by_name']) : '' ?><?= $p['pause_reason'] !== null ? ': «' . e((string) $p['pause_reason']) . '»' : '' ?> — it sends nothing and is woken by nothing.</div>
    <?php endif; ?>
    <?php if (!$can): ?><div class="alert alert-light" id="policy-read-only">You see this policy; changing it is its overseers'.</div><?php endif; ?>
    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <form method="post" action="/oversight/policy" hx-post="/oversight/policy" hx-target="#page-content" id="policy-form" class="card"><div class="card-body"><?= csrf_field() ?>
                <input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="return_to" value="/m/<?= $mb ?>/policy">
                <?= $row('send_mode', 'Send mode', '<select name="send_mode" id="policy-send_mode" class="form-select btn-touch" data-current="' . e($p['send_mode']) . '"' . $dis . '>'
                    . implode('', array_map(static fn (string $m): string => '<option value="' . $m . '"' . ($p['send_mode'] === $m ? ' selected' : '') . '>' . e(str_replace('_', ' ', $m)) . ' — ' . e(OV_SEND_MODE_WORDS[$m]) . '</option>', OV_SEND_MODES)) . '</select>') ?>
                <?= $row('allow_domains', 'Allowed domains', '<textarea name="allow_domains" id="policy-allow_domains" rows="2" class="form-control" placeholder="supplier.example" data-current="' . e(implode("\n", $p['allow_domains'])) . '"' . $dis . '>' . e(implode("\n", $p['allow_domains'])) . '</textarea>') ?>
                <?= $row('allow_addresses', 'Allowed addresses', '<textarea name="allow_addresses" id="policy-allow_addresses" rows="2" class="form-control" placeholder="jon@supplier.example" data-current="' . e(implode("\n", $p['allow_addresses'])) . '"' . $dis . '>' . e(implode("\n", $p['allow_addresses'])) . '</textarea>') ?>
                <?php foreach (['first_contact_review' => 'Review first contact', 'attachments_review' => 'Review attachments'] as $k => $l): ?>
                    <?= $row($k, $l, '<label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="policy-' . $k . '">' . $yes($k, $p[$k]) . '<span class="fs-12">' . ($p[$k] ? 'On' : 'Off') . ' now</span></label>') ?>
                <?php endforeach; ?>
                <?php foreach (['max_external_per_day' => ['Outside mail a day', 0, 10000], 'max_per_hour' => ['Mail an hour', 0, 10000], 'max_per_thread_per_day' => ['Mail a day in one conversation', 1, 1000]] as $k => [$l, $lo, $hi]): ?>
                    <?= $row($k, $l, '<input type="number" name="' . $k . '" id="policy-' . $k . '" class="form-control btn-touch" style="max-width:9rem" min="' . $lo . '" max="' . $hi . '" value="' . (int) $p[$k] . '" data-current="' . (int) $p[$k] . '"' . $dis . '>') ?>
                <?php endforeach; ?>
                <?= $row('wake_on_mail', 'Wake on mail', '<label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="policy-wake_on_mail">' . $yes('wake_on_mail', $p['wake_on_mail']) . '<span class="fs-12">' . ($p['wake_on_mail'] ? 'On' : 'Off') . ' now</span></label>') ?>
                <?= $row('wake_from', 'Woken by', '<select name="wake_from" id="policy-wake_from" class="form-select btn-touch" data-current="' . e($p['wake_from']) . '"' . $dis . '>'
                    . implode('', array_map(static fn (string $m): string => '<option value="' . $m . '"' . ($p['wake_from'] === $m ? ' selected' : '') . '>' . e(OV_WAKE_WORDS[$m]) . '</option>', OV_WAKE_FROM)) . '</select>') ?>
                <?= $row('disclosure', 'AI disclosure', '<label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="policy-disclosure">' . $yes('disclosure', $p['disclosure']) . '<span class="fs-12">' . ($p['disclosure'] ? 'On' : 'Off') . ' now</span></label>') ?>
                <?= $row('disclosure_text', 'Disclosure line', '<input type="text" name="disclosure_text" id="policy-disclosure_text" class="form-control btn-touch" maxlength="500" value="' . e((string) ($p['disclosure_text'] ?? '')) . '" placeholder="' . e((string) ($b['default_disclosure_text'] ?? '')) . '"' . $dis . '>') ?>
                <?php if ($can): ?><button type="submit" class="btn btn-primary btn-touch" id="policy-save">Save</button><?php endif; ?>
                <div class="fs-12 text-muted mt-2" id="policy-updated">Last set by <?= e((string) ($p['updated_by_name'] ?? '—')) ?>, <?= e(format_ts((string) $p['updated_at'], $tz, 'M j, Y g:i A')) ?></div>
            </div></form>
        </div>
        <div class="col-12 col-xl-5">
            <div class="card mb-3" id="policy-business"><div class="card-header"><h5 class="card-title mb-0">The business also applies</h5></div><div class="card-body fs-13">
                <div class="mb-1">Every agent's outside mail today, together: at most <strong id="policy-budget"><?= (int) ($b['agent_external_budget_today'] ?? 0) ?></strong> (the warm-up grows it week by week)</div>
                <div class="mb-1">Hold every agent's mail: <strong id="policy-hold-all"><?= ($b['hold_all_agents'] ?? false) === true || ($b['hold_all_agents'] ?? '') === 't' ? 'on' : 'off' ?></strong></div>
                <div class="mb-1">Hold every agent's outside mail: <strong id="policy-hold-external"><?= ($b['hold_all_external'] ?? false) === true || ($b['hold_all_external'] ?? '') === 't' ? 'on' : 'off' ?></strong></div>
                <div class="text-muted fs-12">Held mail expires after <?= (int) ($b['held_expiry_hours'] ?? 72) ?> hours with no decision. The Mail admins set these.</div>
            </div></div>
            <?php if ($can): ?>
            <div class="card mb-3" id="policy-pause"><div class="card-body">
                <?php if ($p['paused']): ?>
                    <form method="post" action="/oversight/resume" hx-post="/oversight/resume" hx-target="#page-content" id="policy-resume-form"><?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="return_to" value="/m/<?= $mb ?>/policy">
                        <button type="submit" class="btn btn-primary btn-touch w-100" id="policy-resume">Resume</button></form>
                <?php else: ?>
                    <form method="post" action="/oversight/pause" hx-post="/oversight/pause" hx-target="#page-content" id="policy-pause-form"><?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="return_to" value="/m/<?= $mb ?>/policy">
                        <label class="form-label fs-12 text-muted" for="policy-pause-reason">Pause: hold all its mail and wake it for nothing. Why?</label>
                        <input type="text" name="reason" id="policy-pause-reason" class="form-control btn-touch mb-2" maxlength="300" required>
                        <button type="submit" class="btn btn-outline-danger btn-touch w-100" id="policy-pause-btn">Pause</button></form>
                <?php endif; ?>
            </div></div>
            <?php endif; ?>
            <?= view('oversight/_history.php', ['history' => $history, 'tz' => $tz]) ?>
        </div>
    </div>
</div>
<script src="/assets/mail/policy.js"></script>
