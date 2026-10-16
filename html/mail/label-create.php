<?php
declare(strict_types=1);
/** Action `mail_label_create` (log `label.create`, the label's id; undo mail_label_delete): a label for the mailbox. A `/` in the name nests it (Work/Clients); the colour is one of the eight swatches. Triage; an agent too. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'triage');
$mb = (int) $box['id'];
$errors = [];
$name = ml_text('name', null, 50, 'The label\'s name', $errors, true);
$colour = ml_choice('colour', null, array_keys(ML_LABEL_COLOURS), 'Colour', $errors);
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$me = (int) current_member_id();
$id = (int) ml_guard($pdo, static function () use ($pdo, $mb, $name, $colour, $me): int {
    $st = $pdo->prepare('SELECT ml_label_save(:mb, NULL, :n, :c, :me)');
    $st->execute(['mb' => $mb, 'n' => $name, 'c' => $colour, 'me' => $me]);
    return (int) $st->fetchColumn();
});
log_activity($pdo, 'label.create', 'mail_label', $id, ['after' => ['label' => $id, 'nested' => str_contains((string) $name, '/')], 'mailbox_id' => $mb]);
ml_done('Made the label', $id, ml_land(return_path('/m/' . $mb . '/labels'), 'label_created'), 'mailChanged', ['undo' => ['action' => 'mail_label_delete', 'label' => $id]]);
