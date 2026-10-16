<?php
declare(strict_types=1);
/** /oversight/review — the held mail waiting for me, oldest first (it expires), each reason a chip; filters by mailbox and reason; the rows polled every 30 s while visible (screen `review-queue`). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/oversight/queries.php';
require_login();
require_human();
if (!has_right('mail.oversee')) {
    refuse(403, right_refusal('mail.oversee'));
}
$pdo = db();
$mailbox = request_integer('mailbox');
$reason = request_string('reason');
$reason = in_array($reason, OV_REASON_KEYS, true) ? $reason : null;
$rows = review_queue($pdo, (int) current_member_id(), $mailbox, $reason);
if (wants_json()) {
    respond_screen(['held' => array_map('present_review_row', $rows), 'filters' => ['mailbox' => $mailbox, 'reason' => $reason]]);
}
$data = ['rows' => $rows, 'mailbox' => $mailbox, 'reason' => $reason, 'boxes' => review_queue_filters($pdo), 'tz' => member_timezone()];
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'review-rows') {     // the 30-second poll: the rows alone, logged never
    header('Vary: HX-Request');
    echo view('oversight/_rows.php', $data);
    exit;
}
log_screen_view($pdo, 'review-queue');
$notice = ml_notice($_GET['notice'] ?? null, ['approved' => ['success', 'Approved. Nothing else waits for you.'], 'edited' => ['success', 'Edited and approved. Nothing else waits for you.'],
    'rejected' => ['success', 'Rejected; the agent is told why. Nothing else waits for you.'], 'replied' => ['success', 'Your note reached the agent; the message stays held.'],
    'takenover' => ['success', 'Taken over: you answer that conversation.']]);
render_screen('Review', view('oversight/queue.php', $data + ['notice' => $notice]), ['screen' => 'review-queue', 'entity' => 'outbound', 'activeNav' => 'oversight']);
