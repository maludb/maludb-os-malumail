<?php
declare(strict_types=1);
/** /tokens/new — the full-page form that mints a token (part of `tokens-list`). People only. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
require_human();
if (!has_right('tokens.own')) {
    refuse(403, right_refusal('tokens.own'));
}
render_screen('Mint a token', view('tokens/form.php', ['errors' => [], 'values' => []]), ['screen' => 'tokens-list', 'entity' => 'mcp_access_token', 'activeNav' => 'tab-me']);
