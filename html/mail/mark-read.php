<?php
declare(strict_types=1);
/**
 * Action `mail_mark_read` (log `message.mark_read`): read yes marks the whole conversation read, no marks the latest message unread (Gmail).
 * A view holder, and everyone on an intake mailbox, marks FOR THEMSELVES (mail_viewer_reads — never a \Seen); triage and the owner mark
 * the mailbox itself through the mail service. View.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'view');
$mb = (int) $box['id'];
$me = (int) current_member_id();
$read = ml_yes('read', true);
$mode = (string) db_value($pdo, 'SELECT ml_read_mode(:mb, :me, :b)', ['mb' => $mb, 'me' => $me, 'b' => ml_on_behalf()]);
if ($mode !== 'viewer_read') {
    mail_action_run($read ? 'seen' : 'unseen', 'message.mark_read');
}
// per person: this reader's own marks, never a change to the mailbox
$threads = request_list('thread') ?? [];
$matching = req_val('matching');
if ($threads === [] && ($matching === null || trim($matching) === '')) {
    refuse(422, 'Choose at least one conversation, or say what to match.');
}
$threadIds = [];
$targets = ml_thread_targets($pdo, $mb, $threads, $matching, $read ? 'seen_viewer' : 'unseen_viewer', null, null, $threadIds);
$st = $pdo->prepare('SELECT ml_viewer_read(:me, :k, :on)');
$n = 0;
foreach ($targets as $r) {
    if (in_array($r['special_use'], ['sent', 'drafts'], true)) {
        continue;
    }
    $st->execute(['me' => $me, 'k' => (int) $r['message_key_id'], 'on' => $read ? 't' : 'f']);
    $n++;
}
log_activity($pdo, 'message.mark_read', 'mail_thread', $threadIds[0] ?? null, ['after' => ['count' => $n, 'read' => $read, 'per_person' => true] + (($matching ?? '') !== '' ? ['matching' => true] : []), 'mailbox_id' => $mb]);
ml_done(($read ? 'Marked read' : 'Marked unread') . ' for you: ' . count($threadIds) . ' conversation' . (count($threadIds) === 1 ? '' : 's'), $threadIds[0] ?? null,
        mail_action_land($mb, $read ? 'read' : 'unread', null, count($threadIds)), 'mailChanged', ['count' => $n, 'per_person' => true]);
