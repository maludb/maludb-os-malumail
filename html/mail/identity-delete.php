<?php
declare(strict_types=1);
/** Action `mail_identity_delete` (log `identity.delete`; confirm): never the own identity, never the default while another is not chosen. Full; people only. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
require_human();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'full');
$mb = (int) $box['id'];
$id = (int) request_integer('identity');
identity_delete($pdo, $mb, $id);
log_activity($pdo, 'identity.delete', 'mail_identity', $id, ['mailbox_id' => $mb]);
ml_done('Identity deleted', $id, ml_land(return_path('/m/' . $mb . '/settings'), 'deleted'), 'identitiesChanged');
