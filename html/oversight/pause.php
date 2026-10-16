<?php
declare(strict_types=1);
/** Action `mail_mailbox_pause` (log `mailbox.pause`, the reason): THE KILL SWITCH — the agent's queued and deferred mail held with `paused`, no mail turns, its new mail held (ml_mailbox_pause). Undone by mail_mailbox_resume. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
$pdo = ov_handler_begin();
$box = ov_require_agent_mailbox(req_val('mailbox') ?? request_integer('mailbox'));
$mb = (int) $box['id'];
$errors = [];
$reason = ml_text('reason', null, 300, 'The reason', $errors);
$held = (int) one_value($pdo, "SELECT count(*) FROM outbound_email WHERE mailbox_id = :mb AND sender_is_agent AND status IN ('queued', 'deferred')", ['mb' => $mb]);
mailbox_pause($pdo, $mb, (int) current_member_id(), true, $reason);
log_activity($pdo, 'mailbox.pause', 'mailbox', $mb, ['after' => ['reason' => $reason, 'held' => $held], 'mailbox_id' => $mb]);
ml_done('Paused: ' . $box['address'] . ' sends nothing and is woken by nothing' . ($held > 0 ? ' (' . $held . ' waiting ' . ($held === 1 ? 'message' : 'messages') . ' held)' : ''), $mb,
        ml_land(return_path('/oversight'), 'paused'), 'reviewChanged', ['undo' => ['action' => 'mail_mailbox_resume', 'mailbox' => $mb]]);
