<?php
declare(strict_types=1);
/**
 * Action `mail_notification_read` (log `notification.read`, once, with the count): mark one of MY notices read (`notification`), or all of them
 * (`every_one` yes). A person's alone; nobody marks another's. Opening a notice in the list posts here and lands on the notice's own link (`return_to`).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notifications/queries.php';
ml_handler_begin();
require_human();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$all = ml_yes('every_one', false);
$one = ml_int('notification', null, 1, PHP_INT_MAX, 'The notification', $errors, true);
if (!$all && $one === null && $errors === []) {
    $errors['notification'] = 'Say which notification, or mark every one read.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
if (!$all && $one !== null && (int) one_value($pdo, 'SELECT count(*) FROM notifications WHERE id = :id AND member_id = :m', ['id' => $one, 'm' => $me]) === 0) {
    refuse(404, 'No such notification.');                                       // another person's is the same 404 as a missing one
}
$n = (int) ml_guard($pdo, static fn (): int => notification_read($pdo, $me, $one, $all));
log_activity($pdo, 'notification.read', 'notification', $all ? null : $one, ['after' => ['count' => $n] + ($all ? ['every_one' => true] : [])]);
ml_done($all ? 'Marked every notice read' : 'Marked the notice read', $all ? null : $one, ml_land(return_path('/notifications')), 'notificationChanged', ['changed' => $n]);
