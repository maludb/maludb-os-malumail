<?php
declare(strict_types=1);
/**
 * /oversight/review/{outbound} — ONE held message exactly as it will go out (screen `review-view`, design §5): From and Sender, the
 * recipients with outside and new ones marked, the subject, the body in the sandboxed frame (/body, the same CSP as a message), every link
 * with its real host, the attachments with their digests, the disclosure; beside it the conversation it answers, the agent's purpose,
 * its run, every hold reason with its sentence, the decisions so far. Approve, Edit, Reject, Reply to the agent, Take over (a reply).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/oversight/queries.php';
require_login();
require_human();
if (!has_right('mail.oversee')) {
    refuse(403, right_refusal('mail.oversee'));
}
$pdo = db();
$id = (int) request_integer('id');
$q = review_get($pdo, (int) current_member_id(), $id);
if ($q === null) {
    respond_not_found('No such message.');
}
$notice = ml_notice($_GET['notice'] ?? null, ['approved' => ['success', 'Approved. The next one:'], 'edited' => ['success', 'Edited and approved. The next one:'],
    'rejected' => ['success', 'Rejected; the agent is told why. The next one:'], 'replied' => ['success', 'Your note reached the agent. The next one:'],
    'takenover' => ['success', 'Taken over. The next one:']]);
if ($q['decided']) {
    if (wants_json()) {
        respond_screen(['outbound' => $q['id'], 'status' => $q['status'], 'decided' => true, 'reviews' => array_map('present_review_decision', $q['reviews'])]);
    }
    render_screen('Review', view('oversight/review.php', ['q' => $q, 'notice' => $notice, 'tz' => member_timezone()]), ['screen' => 'review-view', 'entity' => 'outbound', 'recordId' => $id, 'activeNav' => 'oversight']);
    exit;
}
$links = ov_links_with_hosts($q['body_html'] ?? null, (string) $q['body_text']);
log_screen_view($pdo, 'review-view', (int) $q['mailbox_id']);
if (wants_json()) {
    respond_screen(['review' => present_review($q, $links)]);
}
render_screen('Review: ' . ($q['subject'] !== '' ? $q['subject'] : '(no subject)'), view('oversight/review.php', ['q' => $q, 'links' => $links, 'notice' => $notice, 'tz' => member_timezone(),
    'isSuper' => is_super_admin()]), ['screen' => 'review-view', 'entity' => 'outbound', 'recordId' => $id, 'mailboxId' => (int) $q['mailbox_id'], 'activeNav' => 'oversight']);
