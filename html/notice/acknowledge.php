<?php
declare(strict_types=1);
/** Action `mail_notice_acknowledge` (log `notice.acknowledge`): a person records they read the version shown. People only; a stale version is refused. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/notice/queries.php';
ml_handler_begin();
require_human();
$pdo = db();
$me = (int) current_member_id();
$shown = request_integer('version');
$current = notice_current($pdo)['version'];
if ($shown === null || $shown !== $current) {
    refuse(409, 'The notice changed while you were reading it — read the new version, then acknowledge it.');
}
ml_guard($pdo, static function () use ($pdo, $me): void {
    $pdo->prepare('SELECT ml_notice_acknowledge(:m)')->execute(['m' => $me]);
});
log_activity($pdo, 'notice.acknowledge', 'notice', $current, ['after' => ['version' => $current]]);
$next = safe_local_path((string) req_val('next')) ?? '/';
ml_done('Acknowledged the monitoring notice (version ' . $current . ')', $current, $next === '/notice' ? '/' : $next);
