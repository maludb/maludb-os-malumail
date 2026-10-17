<?php /** A mailbox's Access page (screen `mailbox-access`). Data: ctx (ac_context), rows, notice, tz, log_url?, settings_url? */
$box = $ctx['row'];
$mb = (int) $ctx['id'];
$person = $ctx['kind'] === 'person';
$initials = static fn (string $n): string => strtoupper(mb_substr(trim($n) !== '' ? $n : '?', 0, 1));
$kindWord = ['member' => 'Person', 'department' => 'Department', 'agent' => 'Agent'];
$normal = array_values(array_filter($rows, static fn (array $r): bool => !$r['break_glass']));
$glass = array_values(array_filter($rows, static fn (array $r): bool => $r['break_glass']));
$card = static function (array $r) use ($ctx, $box, $tz, $mb, $initials, $kindWord): string {
    $id = (int) $r['id'];
    $canRevoke = $ctx['can_revoke'] && !($ctx['kind'] === 'person' && $ctx['owner'] && !$ctx['audit'] && $r['break_glass']);
    $h = '<div class="col-12 col-xl-6" id="access-row-' . $id . '"><div class="card h-100' . ($r['break_glass'] ? ' border-danger bg-soft-danger' : '') . '"><div class="card-body">'
       . '<div class="d-flex align-items-start gap-2"><span class="avatar-text avatar-md flex-shrink-0">' . e($initials((string) $r['principal_name'])) . '</span>'
       . '<div class="flex-grow-1 min-w-0"><div class="fw-bold text-break" id="access-row-' . $id . '-name">' . e((string) ($r['principal_name'] ?? 'Someone who has left')) . '</div>'
       . '<div class="fs-12 text-muted">' . e($kindWord[$r['principal_kind']] ?? $r['principal_kind']) . '</div></div>'
       . '<span class="badge ' . ($r['break_glass'] ? 'bg-danger' : 'bg-soft-primary text-primary') . '" id="access-row-' . $id . '-level">' . e($r['break_glass'] ? 'emergency · ' . $r['level'] : $r['level']) . '</span></div>'
       . '<div class="fs-12 mt-2 text-break" id="access-row-' . $id . '-why">' . e(ac_row_why($r, $box, $tz)) . '</div>';
    if ($canRevoke) {
        $h .= '<form method="post" action="/access/revoke" hx-post="/access/revoke" hx-target="#page-content" id="access-row-' . $id . '-revoke-form" class="mt-2"'
            . ' hx-confirm="' . e('Take back ' . ($r['principal_name'] ?? 'this') . '\'s access to ' . $box['address'] . '?') . '">' . csrf_field()
            . '<input type="hidden" name="mailbox" value="' . $mb . '"><input type="hidden" name="permission" value="' . $id . '">'
            . '<button type="submit" class="btn btn-sm btn-outline-danger btn-touch" id="access-row-' . $id . '-revoke">Revoke</button></form>';
    }
    return $h . '</div></div></div>';
};
$actions = '';
if ($ctx['reads_log']) { $actions .= hx_link('/m/' . $mb . '/access-log', '<i class="feather-eye me-1"></i>Who looked', 'btn btn-light btn-sm btn-touch me-1', 'id="access-log-link"'); }
if (!empty($settings_url)) { $actions .= hx_link($settings_url, '<i class="feather-settings me-1"></i>Settings', 'btn btn-light btn-sm btn-touch', 'id="access-settings-link"'); }
?>
<?= view('shared/header.php', ['id' => 'mailbox-access', 'title' => 'Who can open ' . $box['address'], 'crumbs' => [[app_name(), '/'], [$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $mb . '/v/inbox'], ['Access', null]],
    'back' => [$ctx['level'] === 'none' ? '/admin/mailboxes/' . $mb : '/m/' . $mb . '/v/inbox', $ctx['level'] === 'none' ? 'the mailbox' : 'the mailbox'], 'action' => $actions]) ?>
<div class="main-content" id="mailbox-access-content">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <?php if (!$ctx['sees_rows']): ?>
        <div class="card" id="access-yours"><div class="card-body">
            <div class="fw-semibold mb-1" id="access-yours-level">You can <?= e(ML_LEVEL_WORDS[$ctx['level']] ?? 'open this mailbox') ?> (<?= e($ctx['level']) ?>).</div>
            <?php $mine = $rows[0] ?? null; ?>
            <?php if ($mine !== null): ?><div class="fs-13 text-break" id="access-yours-why"><?= e(ac_row_why($mine, $box, $tz)) ?></div>
            <?php else: ?><div class="fs-13 text-muted" id="access-yours-why">It was shared with a department you belong to.</div><?php endif; ?>
            <div class="fs-12 text-muted mt-2"><?= $person ? 'Only its owner decides who else may open it.' : 'The people with full access to it decide who else may open it.' ?></div>
        </div></div>
    <?php else: ?>
    <div class="mb-3" id="access-owner"><h6 class="text-uppercase fs-11 text-muted fw-semibold">Owner</h6>
        <div class="fs-14" id="access-owner-name"><?php
            if ($box['kind'] === 'shared') { echo 'A shared mailbox: it has no owner; the people below and the Mail admins decide who opens it.'; }
            elseif ($box['kind'] === 'agent') { echo e((string) ($box['owner_name'] ?? 'An agent')) . ' (the agent itself). The people with full access oversee its mail.'; }
            else { echo e((string) ($box['owner_name'] ?? 'Its owner')) . ($ctx['owner'] ? ' (you)' : '') . '. Only its owner shares it.'; }
        ?></div></div>
    <div class="mb-3" id="access-shared"><h6 class="text-uppercase fs-11 text-muted fw-semibold">Shared with</h6>
        <?php if ($normal === []): ?><div class="card"><div class="card-body text-muted" id="access-shared-empty">Not shared with anyone yet.</div></div><?php endif; ?>
        <div class="row g-3"><?php foreach ($normal as $r) { echo $card($r); } ?></div>
    </div>
    <?php if ($glass !== [] || $ctx['audit'] && $person): ?>
    <div class="mb-3" id="access-break-glass"><h6 class="text-uppercase fs-11 text-danger fw-semibold">Emergency access (break-glass)</h6>
        <?php if ($glass === []): ?><div class="card"><div class="card-body text-muted" id="access-break-glass-empty">No emergency access is open.</div></div><?php endif; ?>
        <div class="row g-3"><?php foreach ($glass as $r) { echo $card($r); } ?></div>
    </div>
    <?php endif; ?>
    <?php if ($ctx['kind'] === 'agent'): ?>
    <div class="mb-3" id="access-by-role"><h6 class="text-uppercase fs-11 text-muted fw-semibold">By role</h6>
        <div class="card"><div class="card-body fs-13">
            <div class="mb-1" id="access-role-mail-admins"><span class="fw-semibold">Every Mail admin</span> · full — a Mail admin administers every agent's mailbox, so no one is locked out of an agent's mail.</div>
            <?php foreach ($rows as $r): if ($r['is_clerk']): ?><div id="access-role-clerk"><span class="fw-semibold"><?= e((string) $r['principal_name']) ?></span> · <?= e($r['level']) ?> — the Filing Clerk, who tells the business what waits for a person.</div><?php endif; endforeach; ?>
        </div></div>
    </div>
    <?php endif; ?>
    <?php if ($ctx['grant'] !== null): ?>
        <?= hx_link('/m/' . $mb . '/access/new', $ctx['grant'] === 'break_glass' ? '<i class="feather-alert-triangle me-1"></i>Give emergency access' : '<i class="feather-share-2 me-1"></i>Share this mailbox',
            'btn ' . ($ctx['grant'] === 'break_glass' ? 'btn-outline-danger' : 'btn-primary') . ' btn-touch', 'id="access-share-btn"') ?>
    <?php endif; ?>
    <?php endif; ?>
</div>
