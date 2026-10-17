<?php
declare(strict_types=1);
/**
 * /notifications?unread=1&before= — my notices, newest first (screen `notifications`, docs/build-specs/notifications.md). With ?menu=1 the bell's menu
 * (the latest eight and See all) instead of the page. A person's own: an agent has no notices.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notifications/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/notifications/present.php';
require_login();
require_human();
if (!is_member_here() || !has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$me = (int) current_member_id();
$tz = member_timezone();
if (request_bool('menu')) {
    header('Cache-Control: no-store');
    echo view('notifications/_bell.php', ['rows' => notifications_list($pdo, $me, false, 8), 'unread' => notifications_unread_count($pdo, $me), 'tz' => $tz]);
    exit;
}
$unreadOnly = request_bool('unread');
$before = request_integer('before');
$page = 50;
$rows = notifications_list($pdo, $me, $unreadOnly, $page + 1, $before !== null && $before > 0 ? $before : null);
$more = null;
if (count($rows) > $page) {
    $rows = array_slice($rows, 0, $page);
    $more = (int) $rows[$page - 1]['id'];
}
$unread = notifications_unread_count($pdo, $me);
log_screen_view($pdo, 'notifications');
if (wants_json()) {
    respond_screen(present_notifications($rows, $unread, $unreadOnly) + ['next_before' => $more]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['read' => ['success', 'Marked read.']]);
render_screen('Notifications', view('notifications/list.php', ['rows' => $rows, 'unread' => $unread, 'unreadOnly' => $unreadOnly, 'tz' => $tz, 'notice' => $notice, 'more' => $more]),
    ['screen' => 'notifications', 'entity' => 'notification', 'activeNav' => 'notifications']);
