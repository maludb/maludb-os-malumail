<?php
declare(strict_types=1);
/**
 * Action `mail_images_allow` (log `sender.images_allow`): a sender's remote images always shown on this mailbox — a person with full
 * (ml_images_allow, db/014), and only for mail that proves its sender (ml_images_always_for). `on` no takes it back. People only.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
ml_handler_begin();
require_human();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'view');
$mb = (int) $box['id'];
$errors = [];
$sender = strtolower((string) ml_text('sender', null, 320, 'The sender', $errors, true));
if ($errors === [] && !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
    $errors['sender'] = 'The sender is an email address.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$on = ml_yes('on', true);
ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_images_allow(:mb, :a, :by, :on)')->execute(['mb' => $mb, 'a' => $sender, 'by' => current_member_id(), 'on' => $on ? 't' : 'f']));
log_activity($pdo, 'sender.images_allow', 'mailbox', $mb, ['after' => ['domain' => substr((string) strrchr($sender, '@'), 1), 'on' => $on]]);
ml_done(($on ? 'Images always shown from ' : 'Images asked for again from ') . $sender, $mb, mail_action_land($mb, $on ? 'images' : 'noimages'), 'mailChanged', ['on' => $on]);
