<?php
declare(strict_types=1);
/**
 * Action `mail_mailbox_settings_save` (log `mailbox.settings` — which keys changed, never a value of mail): a mailbox's settings, any subset
 * (a field left out stays as it was). Reading, sending and — on a person's mailbox, its OWNER alone — the two privacy switches `ai_read` and
 * `share_with_apps` (ml_mailbox_settings_save, db/012 + db/020: a person holding full; the switches by the owner, or on an agent's or shared
 * mailbox by a decider or a Mail admin). People only; the schema refuses an agent again.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
ml_handler_begin();
require_human();
$pdo = db();
$ctx = ac_context($pdo, req_val('mailbox') ?? request_integer('mailbox'));
$mb = $ctx['id'];
$box = $ctx['row'];
if (!st_may_open($ctx)) {                                  // the same wall as the page (the schema refuses again): a person's mailbox is its owner's alone
    $ctx['level'] === 'none' ? respond_not_found('No such mailbox.') : refuse(403, 'You can ' . (ML_LEVEL_WORDS[$ctx['level']] ?? 'open this mailbox') . ' (' . $ctx['level'] . '), but not ' . ML_LEVEL_WORDS['full'] . '.');
}
$errors = [];
$cur = mailbox_settings_read($pdo, $box);
$new = [];
$v = ml_int('undo_seconds', null, 0, 30, 'Undo send', $errors);
if ($v !== null) { $new['undo_seconds'] = $v; }
foreach (['conversation_view', 'shortcuts'] as $k) {
    if (req_has($k)) { $new[$k] = ml_yes($k); }
}
$v = ml_choice('images', null, ['ask', 'always'], 'Remote images', $errors, false);
if ($v !== null) { $new['images'] = $v; }
$v = ml_choice('density', null, ['comfortable', 'compact'], 'Density', $errors, false);
if ($v !== null) { $new['density'] = $v; }
$v = ml_int('page_size', null, 25, 100, 'The page size', $errors);
if ($v !== null && !in_array($v, [25, 50, 100], true)) { $errors['page_size'] = 'A page shows 25, 50 or 100 conversations.'; }
elseif ($v !== null) { $new['page_size'] = $v; }
$ai = req_has('ai_read') ? ml_yes('ai_read') : null;
$share = req_has('share_with_apps') ? ml_yes('share_with_apps') : null;
if ($errors !== []) {
    ml_refuse_fields($errors);
}
// only what changes
$changed = [];
foreach ($new as $k => $val) {
    if ($cur[$k] !== $val) { $changed[$k] = $val; }
}
$keys = array_keys($changed);
$aiSet = $ai !== null && $ai !== (bool) $box['ai_read'] ? $ai : null;
$shSet = $share !== null && $share !== (bool) $box['share_with_apps'] ? $share : null;
if ($changed === [] && $aiSet === null && $shSet === null) {
    ml_done('Nothing changed', $mb, ml_land(return_path('/m/' . $mb . '/settings'), 'unchanged'), 'settingsChanged');
}
mailbox_settings_save($pdo, (int) current_member_id(), $mb, $changed, $aiSet, $shSet);
$after = $changed;
if ($aiSet !== null) { $after['ai_read'] = $aiSet; $keys[] = 'ai_read'; }
if ($shSet !== null) { $after['share_with_apps'] = $shSet; $keys[] = 'share_with_apps'; }
log_activity($pdo, 'mailbox.settings', 'mailbox', $mb, ['after' => ['keys' => $keys] + $after, 'mailbox_id' => $mb]);
ml_done('Saved the settings of ' . $box['address'], $mb, ml_land(return_path('/m/' . $mb . '/settings'), 'saved'), 'settingsChanged',
        ['changed' => $keys]);
