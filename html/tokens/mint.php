<?php
declare(strict_types=1);
/** Action `mail_token_mint` (log `token.mint`: the name and the expiry, never the value). Own and tokens.own; people only. The secret is shown once on /tokens; JSON carries it once. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/tokens/queries.php';
ml_handler_begin();
require_human();
if (!has_right('tokens.own')) {
    refuse(403, right_refusal('tokens.own'));
}
$pdo = db();
$name = trim((string) req_val('name'));
$errors = [];
if ($name === '' || mb_strlen($name) > 80) {
    $errors['name'] = 'Say what will use this token (up to 80 characters).';
}
$days = ml_int('expires_days', 90, 1, 365, 'The days', $errors) ?? 90;
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$minted = ml_guard($pdo, static fn (): array => token_mint($pdo, (int) current_member_id(), $name, $days));
log_activity($pdo, 'token.mint', 'mcp_access_token', $minted['id'], ['after' => ['name' => $name, 'expires_at' => $minted['expires_at']], 'token_id' => $minted['id']]);
$did = 'Minted the token "' . $name . '" — shown once';
emit_action_status(true, ['did' => $did, 'record_id' => $minted['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => $did, 'record_id' => $minted['id'], 'token' => $minted['secret'], 'location' => '/tokens', 'refresh' => 'tokenChanged']);
}
$_SESSION['minted_token'] = ['label' => $name, 'secret' => $minted['secret']];
saved_go('/tokens#token-row-' . $minted['id'], 'tokenChanged');
