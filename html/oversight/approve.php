<?php
declare(strict_types=1);
/** Action `mail_review_approve` (log `review.approve`): a held message goes as written — held → queued with the undo window from now, `Auto-Submitted` dropped; its recipients become known once it is sent. People only (here and in ml_review_decide). Answers with the next held message. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
$pdo = ov_handler_begin();
$o = ov_outbound($pdo, (int) request_integer('outbound'));
$rid = review_decide($pdo, (int) $o['id'], (int) current_member_id(), 'approve', null, null);
log_activity($pdo, 'review.approve', 'outbound_email', (int) $o['id'], ['after' => ['review' => $rid] + ov_log_subject($o), 'mailbox_id' => (int) $o['mailbox_id']]);
ml_done('Approved: it goes in a few seconds', (int) $o['id'], ov_after_decision($pdo, (int) $o['id'], 'approved'), 'reviewChanged', ['next' => review_next($pdo, (int) $o['id'])]);
