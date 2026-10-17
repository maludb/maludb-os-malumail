<?php
declare(strict_types=1);
/**
 * Action `mail_permission_revoke` (log `permission.revoke`; confirm): take a row back. The owner of a person's mailbox revokes the rows they
 * made, never a break-glass row; a Mail admin any; on an agent's or shared mailbox a person with full or a Mail admin (ml_permission_revoke
 * decides, db/010). People only. Ids, kind, level — never the row's reason.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';
ml_handler_begin();
require_human();
if (!has_right('mail.use') && !has_right('access.audit')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$ctx = ac_context($pdo, req_val('mailbox') ?? request_integer('mailbox'));
$mb = $ctx['id'];
$perm = request_integer('permission');
if ($perm === null || $perm <= 0) {
    ml_refuse_fields(['permission' => 'Choose the row to revoke.']);
}
$row = access_revoke($pdo, (int) current_member_id(), $mb, $perm);
log_activity($pdo, 'permission.revoke', 'mailbox_permission', $perm,
    ['after' => ['principal_kind' => $row['principal_kind'], 'principal_id' => (int) $row['principal_id'], 'level' => $row['level'], 'break_glass' => $row['break_glass']], 'mailbox_id' => $mb]);
// the caller may have taken their own way in: land where they can still stand
$still = db_value($pdo, 'SELECT my_level FROM mcp_mailboxes WHERE id = :id', ['id' => $mb]);
$gone = $still === null || ($still === 'none' && !has_right('access.audit') && !has_right('mailboxes.manage'));
ml_done('Access taken back', $perm, $gone ? '/' : ml_land(return_path('/m/' . $mb . '/access'), 'revoked'), 'accessChanged');
