<?php
declare(strict_types=1);
/** /notice — the business's mail-monitoring notice (screen `notice`, design §3): read it, acknowledge this version. Reachable before acknowledging. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/notice/queries.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$notice = notice_current($pdo);
$at = notice_acknowledged_at($pdo, (int) current_member_id(), $notice['version']);
$next = safe_local_path($_GET['next'] ?? null) ?? '/';
log_screen_view($pdo, 'notice');
if (wants_json()) {
    respond_screen(['text' => $notice['text'], 'version' => $notice['version'], 'acknowledged_at' => json_ts($at)]);
}
render_screen('Monitoring notice', view('notice/notice.php', ['notice' => $notice, 'acknowledgedAt' => $at, 'next' => $next, 'tz' => member_timezone()]),
    ['screen' => 'notice', 'activeNav' => 'notice']);
