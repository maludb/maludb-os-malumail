<?php
declare(strict_types=1);
/**
 * Action `mail_notification_prefs_save` (log `notification.prefs`: the switches and kinds changed, never a phone number): MY choices — whether notices
 * may mail (`email_enabled`) and text (`text_enabled`), which kinds may mail (`kind[]`) and text (`text_kind[]`), and the mailboxes whose new mail
 * notifies me (`new_mail_mailbox[]`, each one I may open). A field left out stays as it was. A person's alone.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/notifications/queries.php';
ml_handler_begin();
require_human();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$me = (int) current_member_id();
$cur = prefs_get($pdo, $me);
$new = $cur;
$errors = [];
$new['email_enabled'] = ml_yes('email_enabled', $cur['email_enabled']);
$new['text_enabled'] = ml_yes('text_enabled', $cur['text_enabled']);
foreach (['kind' => 'kinds', 'text_kind' => 'text_kinds'] as $field => $key) {
    $list = request_list($field);
    if ($list === null) {
        continue;
    }
    $bad = array_values(array_diff($list, array_keys(NT_KINDS)));
    if ($bad !== []) {
        $errors[$field] = 'Not a kind of notice: ' . implode(', ', array_slice($bad, 0, 3)) . '.';
        continue;
    }
    $new[$key] = array_values(array_unique($list));
}
$boxes = request_list('new_mail_mailbox');
if ($boxes !== null) {
    $open = array_map(static fn (array $b): int => (int) $b['id'], nt_openable_mailboxes($pdo, $me));
    $ids = [];
    foreach ($boxes as $b) {
        if (filter_var($b, FILTER_VALIDATE_INT) === false || !in_array((int) $b, $open, true)) {
            $errors['new_mail_mailbox'] = 'Choose mailboxes you can open.';        // a hidden mailbox is the same sentence as a missing one
            break;
        }
        $ids[] = (int) $b;
    }
    $new['new_mail_mailbox_ids'] = array_values(array_unique($ids));
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
sort($cur['new_mail_mailbox_ids']);
sort($new['new_mail_mailbox_ids']);
foreach (['kinds', 'text_kinds'] as $k) {
    sort($cur[$k]);
    sort($new[$k]);
}
$diff = ml_diff($cur, $new);
if ($diff['after'] === []) {
    ml_done('Nothing changed', $me, ml_land(return_path('/settings/notifications'), 'unchanged'), 'notificationChanged');
}
ml_guard($pdo, static function () use ($pdo, $me, $new): void {
    prefs_save($pdo, $me, $new);
});
log_activity($pdo, 'notification.prefs', 'member', $me, ['before' => $diff['before'], 'after' => $diff['after']]);
ml_done('Saved your notification settings', $me, ml_land(return_path('/settings/notifications'), 'saved'), 'notificationChanged');
