<?php
declare(strict_types=1);
/** Action `mail_unscreened_release` (log `unscreened.release`): ONE turn of the agent for this message, the releasing person the acting member (ml_unscreened_release). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
$pdo = ov_handler_begin();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'view');
$mb = (int) $box['id'];
$key = (int) request_integer('message');
$d = unscreened_dispatch($pdo, $mb, $key);
unscreened_answer($pdo, $mb, $key, (int) current_member_id(), 'release');
log_activity($pdo, 'unscreened.release', 'agent_dispatch', (int) $d['id'], ['after' => ['message_key' => $key, 'reason' => $d['decision']], 'mailbox_id' => $mb]);
ml_done('Released: the agent reads it in its next turn', (int) $d['id'], ml_land(return_path('/oversight/unscreened'), 'released'), 'reviewChanged');
