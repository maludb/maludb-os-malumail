<?php
declare(strict_types=1);
/** Action `mail_unscreened_dismiss` (log `unscreened.dismiss`, spam true or false): the message wakes no one; `as_spam` yes moves it to Spam through the mail service first. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
$pdo = ov_handler_begin();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'view');
$mb = (int) $box['id'];
$key = (int) request_integer('message');
$spam = ml_yes('as_spam', false);
$d = unscreened_dispatch($pdo, $mb, $key);
unscreened_answer($pdo, $mb, $key, (int) current_member_id(), $spam ? 'spam' : 'dismiss');
log_activity($pdo, 'unscreened.dismiss', 'agent_dispatch', (int) $d['id'], ['after' => ['message_key' => $key, 'spam' => $spam, 'reason' => $d['decision']], 'mailbox_id' => $mb]);
ml_done($spam ? 'Marked as spam' : 'Dismissed', (int) $d['id'], ml_land(return_path('/oversight/unscreened'), $spam ? 'spam' : 'dismissed'), 'reviewChanged');
