<?php
declare(strict_types=1);
/** Action `mail_mailbox_resume` (log `mailbox.resume`): the pause lifted — mail a person approved goes, the rest waits for a person without `paused` among its reasons (ml_mailbox_pause off). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
$pdo = ov_handler_begin();
$box = ov_require_agent_mailbox(req_val('mailbox') ?? request_integer('mailbox'));
$mb = (int) $box['id'];
mailbox_pause($pdo, $mb, (int) current_member_id(), false, null);
log_activity($pdo, 'mailbox.resume', 'mailbox', $mb, ['after' => ['paused' => false], 'mailbox_id' => $mb]);
ml_done('Resumed: ' . $box['address'] . ' follows its policy again', $mb, ml_land(return_path('/oversight'), 'resumed'), 'reviewChanged', ['undo' => ['action' => 'mail_mailbox_pause', 'mailbox' => $mb]]);
