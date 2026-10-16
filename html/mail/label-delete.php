<?php
declare(strict_types=1);
/** Action `mail_label_delete` (log `label.delete`, the label's id and how many conversations carried it): the label leaves every conversation; the mail stays. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'triage');
$mb = (int) $box['id'];
$label = ml_label_one($pdo, $mb, (int) request_integer('label'));
if ($label === null) {
    refuse(404, 'No such label in this mailbox.');
}
$me = (int) current_member_id();
$n = (int) ml_guard($pdo, static function () use ($pdo, $mb, $label, $me): int {
    $st = $pdo->prepare('SELECT ml_label_delete(:mb, :t, :me)');
    $st->execute(['mb' => $mb, 't' => (int) $label['id'], 'me' => $me]);
    return (int) $st->fetchColumn();
});
log_activity($pdo, 'label.delete', 'mail_label', (int) $label['id'], ['after' => ['label' => (int) $label['id'], 'count' => $n], 'mailbox_id' => $mb]);
ml_done('Removed the label from ' . ($n === 1 ? '1 conversation' : $n . ' conversations'), (int) $label['id'], ml_land(return_path('/m/' . $mb . '/labels'), 'label_deleted'), 'mailChanged', ['count' => $n]);
