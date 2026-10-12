<?php
declare(strict_types=1);
/** Action `mail_token_revoke` (log `token.revoke`; confirm). The token's owner only — another's answers 404. People only. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/tokens/queries.php';
ml_handler_begin();
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('token');
$row = $id === null ? null : token_find($pdo, $me, $id);
if ($row === null || $row['revoked_at'] !== null || !token_revoke($pdo, $me, $id)) {
    refuse(404, 'Token not found.');
}
log_activity($pdo, 'token.revoke', 'mcp_access_token', $id, ['before' => ['name' => $row['label']], 'token_id' => $id]);
ml_done('Revoked the token "' . $row['label'] . '"', $id, '/tokens', 'tokenChanged');
