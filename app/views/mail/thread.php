<?php
/**
 * The conversation (screen `thread-view`, reading.md): the header with the subject, the labels and the actions; the messages oldest first,
 * earlier ones collapsed; the shortcuts section. 375 first: Archive, Delete and a More <details>; at 768+ the actions as a toolbar.
 * Data: box, t (mcp_threads' row), messages (ml_thread_page's, each with `fetch` for an open one or null), labels, tz, listUrl, prev,
 *       next, keys (bool), notice ([kind, text]|null), undo (ids|null), built (action => bool), level ('view'|'triage'|…), isOwner,
 *       csrf — and `view`, `folder` the list came from
 */
$mb = (int) $box['id'];
$tid = (int) $t['id'];
$subject = ($t['subject'] ?? '') !== '' ? (string) $t['subject'] : '(no subject)';
$canTriage = (ML_LEVELS[$level] ?? 0) >= 2;
$isHuman = (current_member()['member_kind'] ?? '') === 'human';
$inTrash = false;
$inSpam = false;
$inInbox = false;
foreach ($messages as $m) {
    $inTrash = $inTrash || $m['special_use'] === 'trash';
    $inSpam = $inSpam || $m['special_use'] === 'junk';
    $inInbox = $inInbox || $m['special_use'] === 'inbox';
}
$anyFlagged = (bool) ($t['flagged'] === true || $t['flagged'] === 't');
$anyUnread = false;
foreach ($messages as $m) { $anyUnread = $anyUnread || $m['unread_for_me']; }
$act = static function (string $action, string $id, string $label, string $icon, array $fields = [], string $confirm = '', string $cls = 'btn btn-sm btn-light btn-touch'): string {
    $html = '<form method="post" action="/mail/' . e($action) . '" class="d-inline mail-act-form" id="' . e($id) . '" hx-post="/mail/' . e($action) . '" hx-target="#page-content"'
          . ($confirm !== '' ? ' hx-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
          . '<input type="hidden" name="mailbox" value="' . $GLOBALS['__mb'] . '"><input type="hidden" name="thread[]" value="' . $GLOBALS['__tid'] . '">'
          . '<input type="hidden" name="return_to" value="' . e($GLOBALS['__list']) . '"><input type="hidden" name="view" value="' . e($GLOBALS['__view']) . '">';
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    return $html . '<button type="submit" class="' . e($cls) . '" title="' . e($label) . '"><i class="' . e($icon) . ' me-1"></i>' . e($label) . '</button></form>';
};
$GLOBALS['__mb'] = $mb; $GLOBALS['__tid'] = $tid; $GLOBALS['__list'] = $listUrl; $GLOBALS['__view'] = $view;
$primary = [];
$more = [];
if ($canTriage) {
    if ($inInbox) { $primary[] = $act('archive', 'act-archive', 'Archive', 'feather-archive'); }
    if (!$inTrash) { $primary[] = $act('trash', 'act-trash', 'Delete', 'feather-trash-2'); }
    if ($inTrash || $inSpam) { $more[] = $act('delete-forever', 'act-delete-forever', 'Delete forever', 'feather-x-octagon', [], 'Delete this conversation forever? It cannot be undone.'); }
    $more[] = $inSpam ? $act('not-spam', 'act-not-spam', 'Not spam', 'feather-check-circle') : $act('spam', 'act-spam', 'Spam', 'feather-alert-octagon');
    $more[] = $act('flag', 'act-star', $anyFlagged ? 'Unstar' : 'Star', 'feather-star', ['flagged' => $anyFlagged ? 'no' : 'yes']);
    if (ml_action_built('mail_move') && $folders !== []) {
        $sel = '<form method="post" action="/mail/move" class="d-inline-flex align-items-center gap-1 mail-act-form" id="act-move" hx-post="/mail/move" hx-target="#page-content">' . csrf_field()
             . '<input type="hidden" name="mailbox" value="' . $mb . '"><input type="hidden" name="thread[]" value="' . $tid . '"><input type="hidden" name="return_to" value="' . e($listUrl) . '"><input type="hidden" name="view" value="' . e($view) . '">'
             . '<label class="visually-hidden" for="act-move-to">Move to</label><select class="form-select form-select-sm" name="to_folder" id="act-move-to" style="max-width:12rem">';
        foreach ($folders as $f) { $sel .= '<option value="' . (int) $f['id'] . '">' . e((string) $f['name']) . '</option>'; }
        $more[] = $sel . '</select><button type="submit" class="btn btn-sm btn-light btn-touch"><i class="feather-folder me-1"></i>Move</button></form>';
    }
}
$more[] = $act('mark-read', $anyUnread ? 'act-read' : 'act-unread', $anyUnread ? 'Mark read' : 'Mark unread', $anyUnread ? 'feather-eye' : 'feather-eye-off', ['read' => $anyUnread ? 'yes' : 'no']);
foreach (['mail_label' => ['act-label', 'Label', 'feather-tag'], 'mail_snooze' => ['act-snooze', 'Snooze', 'feather-clock'], 'mail_mute' => ['act-mute', 'Mute', 'feather-bell-off']] as $a => [$id, $label, $icon]) {
    if ($canTriage && ml_action_built($a)) { $more[] = '<a class="btn btn-sm btn-light btn-touch" id="' . $id . '" href="/m/' . $mb . '/' . substr($a, 5) . '?thread=' . $tid . '"><i class="' . $icon . ' me-1"></i>' . $label . '</a>'; }
}
if ($isHuman && $canTriage) {
    $more[] = $act('sensitive', 'act-sensitive', $t['sensitive'] ? 'Not sensitive' : 'Mark sensitive', 'feather-lock', ['sensitive' => $t['sensitive'] ? 'no' : 'yes']);
}
if ($box['kind'] === 'agent' && $isHuman && ml_action_built('mail_take_over')) {
    $more[] = $act('take-over', 'act-take-over', 'Take over', 'feather-user-check', [], '', 'btn btn-sm btn-warning btn-touch');
}
$more[] = '<button type="button" class="btn btn-sm btn-light btn-touch" id="act-print" onclick="window.print()"><i class="feather-printer me-1"></i>Print</button>';
?>
<?= view('shared/header.php', ['id' => 'mail-thread', 'title' => $subject, 'crumbs' => [[$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $mb . '/v/inbox'], [mail_view_label($view), $listUrl], [$subject, null]], 'back' => [$listUrl, mail_view_label($view)]]) ?>
<div class="main-content mail-thread" id="mail-thread" data-mailbox="<?= $mb ?>" data-thread="<?= $tid ?>" data-list="<?= e($listUrl) ?>" data-inbox="/m/<?= $mb ?>/v/inbox"
     data-prev="<?= $prev !== null ? '/m/' . $mb . '/t/' . (int) $prev . '?view=' . e($view) . ($folder !== null ? '&folder=' . (int) $folder : '') : '' ?>"
     data-next="<?= $next !== null ? '/m/' . $mb . '/t/' . (int) $next . '?view=' . e($view) . ($folder !== null ? '&folder=' . (int) $folder : '') : '' ?>"
     data-keys="<?= $keys ? 'on' : 'off' ?>" data-level="<?= e($level) ?>">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <?php if ($undo !== null && $undo !== []): ?>
        <form method="post" action="/mail/move" class="alert alert-light d-flex align-items-center gap-2 mb-3" id="mail-undo-form" hx-post="/mail/move" hx-target="#page-content">
            <?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="return_to" value="<?= e($listUrl) ?>">
            <?php foreach ($undo as $id): ?><input type="hidden" name="restore[]" value="<?= (int) $id ?>"><?php endforeach; ?>
            <span>Moved.</span><button type="submit" class="btn btn-sm btn-link p-0" id="mail-undo">Undo</button>
        </form>
    <?php endif; ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 mail-actions" id="mail-thread-actions">
        <div class="d-flex flex-wrap gap-1 mail-actions-desk" id="mail-actions-desk"><?= implode('', $primary) ?><?= implode('', $more) ?></div>
        <div class="d-flex flex-wrap gap-1 mail-actions-phone w-100" id="mail-actions-phone">
            <?= implode('', $primary) ?>
            <details class="mail-actions-more" id="mail-actions-more"><summary class="btn btn-sm btn-light btn-touch"><i class="feather-more-horizontal me-1"></i>More</summary>
                <div class="mail-more-menu"><?= implode('', $more) ?></div></details>
        </div>
        <?php if ($labels !== []): ?><div class="d-flex flex-wrap gap-1 ms-md-auto" id="mail-thread-labels"><?php foreach ($labels as $l): ?><span class="badge bg-soft-primary text-primary fw-normal"><?= e($l['label']) ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php if ($t['sensitive']): ?><span class="badge bg-soft-secondary text-secondary fw-normal" id="mail-thread-sensitive"><i class="feather-lock me-1"></i>Sensitive — no agent reads it</span><?php endif; ?>
        <?php if ($box['kind'] === 'agent' && $t['taken_over_by'] !== null): ?><span class="badge bg-soft-warning text-warning fw-normal" id="mail-thread-taken-over"><i class="feather-user-check me-1"></i>Taken over by a person</span><?php endif; ?>
    </div>
    <div id="mail-messages" role="list">
        <?php foreach ($messages as $i => $m): ?>
            <?= view('mail/message.php', ['box' => $box, 'm' => $m, 'tz' => $tz, 'index' => $i, 'total' => count($messages), 'built' => $built, 'level' => $level, 'listUrl' => $listUrl, 'view' => $view]) ?>
        <?php endforeach; ?>
    </div>
    <details class="mail-shortcuts mt-4" id="mail-shortcuts"><summary class="text-muted">Keyboard shortcuts <kbd>?</kbd></summary>
        <div class="row g-2 mt-1">
            <div class="col-6 col-md-3"><kbd>j</kbd> / <kbd>k</kbd> next / previous</div><div class="col-6 col-md-3"><kbd>u</kbd> back to the list</div>
            <div class="col-6 col-md-3"><kbd>e</kbd> archive · <kbd>#</kbd> delete · <kbd>!</kbd> spam</div><div class="col-6 col-md-3"><kbd>s</kbd> star</div>
            <div class="col-6 col-md-3"><kbd>Shift</kbd>+<kbd>I</kbd> / <kbd>U</kbd> read / unread</div><div class="col-6 col-md-3"><kbd>v</kbd> move · <kbd>l</kbd> label · <kbd>b</kbd> snooze</div>
            <div class="col-6 col-md-3"><kbd>r</kbd> / <kbd>a</kbd> / <kbd>f</kbd> reply · all · forward</div><div class="col-6 col-md-3"><kbd>g</kbd> then <kbd>i</kbd> Inbox</div>
        </div>
    </details>
</div>
<script src="/assets/mail/frame.js"></script>
<script src="/assets/mail/keys.js"></script>
