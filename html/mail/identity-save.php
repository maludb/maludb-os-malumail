<?php
declare(strict_types=1);
/**
 * Action `mail_identity_save` (log `identity.save`): an address this mailbox sends as — its own, or an alias MaluMail delivers to it
 * (the mail service's identity_check; the alias list never reaches PHP) — with display name, reply-to, a signature sanitised by the
 * mail service (signature profile), and Default. Full on the mailbox; people only.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
require_human();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'full');
$mb = (int) $box['id'];
$id = request_integer('identity');
$existing = null;
if ($id !== null) {
    $st = $pdo->prepare('SELECT * FROM mcp_identities WHERE id = :id AND mailbox_id = :mb');
    $st->execute(['id' => $id, 'mb' => $mb]);
    $existing = $st->fetch() ?: null;
    if ($existing === null) {
        refuse(404, 'No such identity.');
    }
}
$errors = [];
$name = ml_text('display_name', $existing['display_name'] ?? null, 120, 'The display name', $errors);
$replyTo = strtolower((string) ml_text('reply_to_address', $existing['reply_to'] ?? null, 320, 'The reply-to address', $errors));
if ($replyTo !== '' && !preg_match(ML_ADDRESS_RE, $replyTo)) {
    $errors['reply_to_address'] = 'The reply-to is an email address.';
}
$fields = ['display_name' => $name, 'reply_to' => $replyTo === '' ? null : $replyTo, 'is_default' => ml_yes('is_default', (bool) ($existing['is_default'] ?? false))];
if ($existing === null) {
    $address = strtolower((string) ml_text('address', null, 320, 'The address', $errors, true));
    if ($errors === [] && !preg_match(ML_ADDRESS_RE, $address)) {
        $errors['address'] = 'The address is an email address.';
    }
    if ($errors !== []) {
        ml_refuse_fields($errors);
    }
    $check = mailer_identity_check($mb, $address);
    if ($check['error'] !== null && $check['kind'] !== 'own') {
        refuse(503, $check['error']);
    }
    if (!$check['delivers']) {
        refuse(422, $address . ' does not deliver to this mailbox; ask a Mail admin to forward it here first.');
    }
    $fields['address'] = $address;
    $fields['kind'] = $check['kind'];
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
if (req_has('signature')) {
    $sig = (string) req_val('signature');
    if (trim(strip_tags($sig)) === '') {
        $fields['signature_html'] = null;
        $fields['signature_text'] = null;
    } else {
        $clean = mailer_sanitise($sig, 'signature');
        if ($clean === null) {
            refuse(503, 'The mail service is not answering, so the signature cannot be cleaned and saved right now.');
        }
        $fields['signature_html'] = $clean['html'];
        $fields['signature_text'] = $clean['text'];
    }
} else {
    $fields['signature_html'] = $existing['signature_html'] ?? null;
    $fields['signature_text'] = $existing['signature_text'] ?? null;
}
$idOut = identity_save($pdo, $mb, $id, $fields, (int) current_member_id());
log_activity($pdo, 'identity.save', 'mail_identity', $idOut, ['after' => ['kind' => $fields['kind'] ?? $existing['kind'], 'is_default' => $fields['is_default'], 'has_signature' => $fields['signature_html'] !== null], 'mailbox_id' => $mb]);
ml_done(($id === null ? 'Added ' : 'Saved ') . ($fields['address'] ?? $existing['address']), $idOut, ml_land('/m/' . $mb . '/identities/' . $idOut . '/edit', 'saved'), 'identitiesChanged');
