<?php
declare(strict_types=1);
/** Action `mail_send_cancel` (log `message.cancel`): Undo inside the window, Cancel of a scheduled send, or an agent withdrawing its held message — the writer only (ml_outbound_cancel). The draft is reopened. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'view');
$mb = (int) $box['id'];
$o = (int) request_integer('outbound');
$draft = outbound_cancel($pdo, $mb, $o, (int) current_member_id());
log_activity($pdo, 'message.cancel', 'outbound_email', $o, ['after' => ['draft' => $draft], 'mailbox_id' => $mb]);
ml_done('Not sent: the message is back in your drafts', $draft, $draft !== null ? '/m/' . $mb . '/drafts/' . $draft : ml_land(return_path('/m/' . $mb . '/v/inbox'), 'cancelled'), 'mailChanged', ['draft' => $draft, 'outbound' => $o]);
