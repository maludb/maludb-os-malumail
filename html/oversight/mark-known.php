<?php
declare(strict_types=1);
/** Action `mail_sender_mark_known` (log `sender.mark_known`, the address's domain only): the sender is known to this mailbox (known_how marked), so its later mail can wake the agent. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
$pdo = ov_handler_begin();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'view');
$mb = (int) $box['id'];
$address = strtolower(trim((string) (req_val('address') ?? '')));
if (!preg_match('/^[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+$/', $address)) {
    ml_refuse_fields(['address' => 'Give the sender\'s address.']);
}
sender_mark_known($pdo, $mb, $address, (int) current_member_id());
log_activity($pdo, 'sender.mark_known', 'mailbox', $mb, ['after' => ['domain' => substr($address, strrpos($address, '@') + 1)], 'mailbox_id' => $mb]);
ml_done('Known: ' . $address . '\'s mail can wake the agent now', $mb, ml_land(return_path('/oversight/unscreened'), 'known'), 'reviewChanged');
