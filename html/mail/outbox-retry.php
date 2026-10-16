<?php
declare(strict_types=1);
/** Action `mail_outbox_retry` (log `outbound.retry`): a failed message queued again — its writer, or a holder of send (ml_outbound_retry); the claim re-holds a paused agent's. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'view');
$mb = (int) $box['id'];
$o = (int) request_integer('outbound');
outbound_retry($pdo, $mb, $o, (int) current_member_id());
log_activity($pdo, 'outbound.retry', 'outbound_email', $o, ['mailbox_id' => $mb]);
ml_done('Sending again', $o, ml_land(return_path('/m/' . $mb . '/v/outbox'), 'retried'), 'mailChanged');
