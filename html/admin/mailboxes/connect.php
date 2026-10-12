<?php
declare(strict_types=1);
/**
 * Action `mail_mailbox_connect` (log `mailbox.connect`; confirm). People only. An existing MaluMail mailbox on a served domain is recorded
 * here; an agent's or a shared one is given a new password at MaluMail, sealed and stored by the mail service, then probed (the cost the
 * confirmation names: a client still using the old password stops). A person's waits on MM1, with no credential.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
ml_handler_begin();
admin_gate('mailboxes.manage');
$pdo = db();
$errors = [];
$kind = ml_choice('kind', null, array_keys(ADMIN_KINDS), 'The kind', $errors, false);
$address = strtolower((string) req_val('address'));
if (!preg_match('/^[^@\s]+@[a-z0-9.-]+\.[a-z]{2,}$/', $address)) {
    $errors['address'] = 'Name the existing mailbox by its address.';
}
$display = ml_text('display_name', null, 120, 'The display name', $errors);
$owner = req_val('owner');
if ($owner === null || $owner === '') {
    $owner = $kind === 'person' ? req_val('owner_person') : ($kind === 'agent' ? req_val('owner_agent') : null);
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$args = ['address' => $address, 'kind' => $kind, 'display_name' => $display ?? '',
         'owner' => $kind === 'shared' ? null : ($owner === null || $owner === '' ? null : (int) $owner)];
if ($kind === 'agent') {
    $args['primary_overseer'] = ctype_digit((string) req_val('primary_overseer')) ? (int) req_val('primary_overseer') : null;
    $args['overseers'] = admin_overseer_ids();
}
$res = admin_mailer('/v1/admin/mailboxes/connect', $args);
$id = (int) $res['mailbox_id'];
log_activity($pdo, 'mailbox.connect', 'mailbox', $id, ['after' => ['kind' => $kind, 'domain' => admin_domain_of($address), 'probe' => $res['probe'] ?? null,
    'intake_proposed' => $res['intake_proposed'] ?? null]]);
$did = 'Connected ' . $address . match ($res['probe'] ?? null) {
    'ok' => ' — the webmail logs in to it', null => ($kind === 'person' ? ' — it waits on MaluMail\'s application passwords (MM1)' : ''),
    default => ' — but the mail server could not be used yet (' . $res['probe'] . ')',
};
ml_done($did, $id, '/admin/mailboxes/' . $id, 'mailboxesChanged', ['probe' => $res['probe'] ?? null]);
