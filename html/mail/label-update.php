<?php
declare(strict_types=1);
/** Action `mail_label_update` (log `label.update`, the label's id): rename and/or recolour a label — a field left out stays as it was. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'triage');
$mb = (int) $box['id'];
$label = ml_label_one($pdo, $mb, (int) request_integer('label'));
if ($label === null) {
    refuse(404, 'No such label in this mailbox.');
}
$errors = [];
$name = ml_text('name', (string) $label['label'], 50, 'The label\'s name', $errors, true);
$colour = ml_choice('colour', $label['colour'], array_keys(ML_LABEL_COLOURS), 'Colour', $errors);
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$me = (int) current_member_id();
ml_guard($pdo, static function () use ($pdo, $mb, $label, $name, $colour, $me): void {
    $st = $pdo->prepare('SELECT ml_label_save(:mb, :t, :n, :c, :me)');
    $st->execute(['mb' => $mb, 't' => (int) $label['id'], 'n' => $name, 'c' => $colour, 'me' => $me]);
});
log_activity($pdo, 'label.update', 'mail_label', (int) $label['id'], ['after' => ['label' => (int) $label['id'], 'renamed' => $name !== $label['label'], 'recoloured' => $colour !== $label['colour']], 'mailbox_id' => $mb]);
ml_done('Saved the label', (int) $label['id'], ml_land(return_path('/m/' . $mb . '/labels'), 'label_saved'), 'mailChanged');
