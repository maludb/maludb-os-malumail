<?php
declare(strict_types=1);
/** /settings/notifications — which notices reach me by mail and by text, and the mailboxes whose new mail notifies me (screen `notification-settings`). A person's own. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/notifications/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/notifications/present.php';
require_login();
require_human();
if (!is_member_here() || !has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$me = (int) current_member_id();
$prefs = prefs_get($pdo, $me);
$boxes = nt_openable_mailboxes($pdo, $me);
$text = nt_text_possible($pdo, $me);
log_screen_view($pdo, 'notification-settings');
if (wants_json()) {
    respond_screen(present_notification_settings($prefs, $boxes, $text) + ['mail_failures' => nt_mail_failures($pdo, $me)]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'unchanged' => ['info', 'Nothing changed.']]);
render_screen('Notification settings', view('notifications/settings.php', ['prefs' => $prefs, 'boxes' => $boxes, 'textPossible' => $text, 'failures' => nt_mail_failures($pdo, $me), 'notice' => $notice]),
    ['screen' => 'notification-settings', 'entity' => 'member', 'activeNav' => 'notifications']);
