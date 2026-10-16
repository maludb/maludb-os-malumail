<?php
declare(strict_types=1);
/** Action `mail_draft_discard` (log `draft.discard`): the draft's files go now, its Drafts copy by the mail service. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'triage');
$mb = (int) $box['id'];
$draft = (int) request_integer('draft');
draft_discard($pdo, $mb, $draft, (int) current_member_id());
log_activity($pdo, 'draft.discard', 'mail_draft', $draft, ['mailbox_id' => $mb]);
mailer_draft_append($mb, $draft, true);                         // the delete half now when the service answers; else its sweep
ml_done('Draft discarded', $draft, ml_land(return_path('/m/' . $mb . '/v/inbox'), 'discarded'), 'draftDiscarded');
