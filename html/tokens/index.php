<?php
declare(strict_types=1);
/** /tokens — my tokens for the two read MCP servers (screen `tokens-list`). The secret is shown ONCE, from the session, after minting. People only. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/tokens/queries.php';
require_login();
require_human();
if (!has_right('tokens.own')) {
    refuse(403, right_refusal('tokens.own'));
}
$pdo = db();
$rows = tokens_for($pdo, (int) current_member_id());
log_screen_view($pdo, 'tokens-list');
if (wants_json()) {
    respond_screen(['tokens' => array_map(static fn (array $t): array => ['id' => (int) $t['id'], 'name' => $t['label'], 'last_used_at' => json_ts($t['last_used_at']),
        'expires_at' => json_ts($t['expires_at']), 'revoked' => $t['revoked_at'] !== null], $rows), 'records_url' => app_url('/mcp/records'), 'activity_url' => app_url('/mcp/activity')]);
}
$minted = $_SESSION['minted_token'] ?? null;
unset($_SESSION['minted_token']);
render_screen('Tokens', view('tokens/list.php', ['rows' => $rows, 'minted' => $minted, 'tz' => member_timezone()]), ['screen' => 'tokens-list', 'entity' => 'mcp_access_token', 'activeNav' => 'tab-me']);
