<?php
declare(strict_types=1);
/**
 * Action `mail_label` (log `message.label`; undo = the same with removed flipped): put labels on conversations — thread[] and/or every
 * conversation a search finds (`matching`, up to 1000) — or take them off (removed yes). The labels go on every message key of each
 * conversation, so a reply arriving keeps them. Triage; an agent too (never on a conversation marked sensitive). label is one id or a list.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'triage');
$mb = (int) $box['id'];
$have = array_map(static fn (array $l): int => (int) $l['id'], ml_labels_of($pdo, $mb));
$labels = [];
foreach (request_list('label') ?? [] as $l) {
    if (!ctype_digit($l) || !in_array((int) $l, $have, true)) {
        refuse(404, 'No such label in this mailbox.');
    }
    $labels[] = (int) $l;
}
if ($labels === []) {
    refuse(422, 'Choose a label.');
}
$remove = ml_yes('removed', false);
$matching = req_val('matching');
$threadIds = [];
[$n] = ml_label_apply_to($pdo, $mb, $labels, request_list('thread') ?? [], $matching, $remove, $threadIds);
$after = ['count' => $n, 'label' => $labels[0], 'removed' => $remove] + (count($labels) > 1 ? ['labels' => $labels] : []) + (($matching ?? '') !== '' ? ['matching' => true] : []);
log_activity($pdo, 'message.label', 'mail_thread', $threadIds[0] ?? null, ['after' => $after, 'mailbox_id' => $mb]);
ml_done(($remove ? 'Took the label off ' : 'Labelled ') . ($n === 1 ? '1 conversation' : $n . ' conversations'), $threadIds[0] ?? null,
        mail_action_land($mb, $remove ? 'unlabeled' : 'labeled', null, $n), 'mailChanged',
        ['count' => $n, 'undo' => ['action' => 'mail_label', 'thread' => $threadIds, 'label' => $labels, 'removed' => !$remove]]);
