<?php
declare(strict_types=1);
/**
 * The answer for every manifest screen no slice has built yet (sso-shell.md "Placeholders") — the fallback of tests/dev_router.php and of
 * the vhost when a canonical URL's own file does not exist. It finds the screen by the registry's URL templates (mcp/action_registry.json),
 * applies the right and the mailbox level the screen needs (a mailbox the caller cannot open: 404, never 403), and answers 200 with the
 * screen's name, what it will do and the slice that builds it. A POST, or a JSON caller: 501 not_built. An unknown path: 404.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
$hit = screen_for_path(current_path());
if ($hit === null) {                              // no screen: 404 to everyone, signed in or not (never a sign-on detour that hints at a page)
    respond_not_found('There is no such page.');
}
require_login();
[$id, $params] = $hit;
$screen = registry_screens()[$id];
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
[$right, $level] = screen_gate($id);
if (!has_right($right)) {
    refuse(403, right_refusal($right));
}
$box = null;
if ($level !== null && isset($params['mailbox'])) {
    $box = ml_require_mailbox($params['mailbox'], $level);
    if ($id === 'agent-policy' && $box['kind'] !== 'agent') {
        respond_not_found('No such mailbox.');
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || wants_json()) {
    if (wants_json()) {
        json_error('not_built', ucfirst(str_replace('-', ' ', $id)) . ' is not built yet: ' . screen_slice($screen) . ' builds it.', 501);
    }
    http_response_code(501);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not built yet: ' . screen_slice($screen) . ' builds ' . $id . ".\n";
    exit;
}
render_screen(ucfirst(str_replace('-', ' ', $id)), view('shared/placeholder.php', ['id' => $id, 'screen' => $screen, 'slice' => screen_slice($screen)]),
    ['screen' => $id, 'mailboxId' => $box !== null ? (int) $box['id'] : null]);
