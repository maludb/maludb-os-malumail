<?php
declare(strict_types=1);
/** /search?q=&before= — the same search over every mailbox the person can open, each result naming its mailbox (screen `search-all`). The shell's search box lands here when no mailbox is current. */
require_once __DIR__ . '/../app/bootstrap.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$q = request_string('q');
$before = request_string('before');
if ($before !== '' && ml_search_cursor($before) === null) {
    refuse(422, 'That page marker is not one this search made: search again.');
}
$page = ml_search_page(null, $q, $before !== '' ? $before : null);
if (!is_htmx_request() || ($_SERVER['HTTP_HX_TARGET'] ?? '') !== 'search-results') {
    log_screen_view($pdo, 'search-all');
}
foreach (array_unique(array_column($page['rows'], 'mailbox_id')) as $b) {
    mail_log_list_view($pdo, (int) $b, 'search');
}
if ($page['error'] !== null) {
    http_response_code(422);
}
if (wants_json()) {
    if ($page['error'] !== null) {
        json_error('bad_query', $page['error'], 422);
    }
    respond_screen(['q' => $q, 'unknown_operators' => $page['unknown'], 'conversations' => array_map('ml_search_row_json', $page['rows']), 'next' => $page['next']]);
}
$data = ['box' => null, 'multi' => true, 'q' => $q, 'page' => $page, 'tz' => member_timezone(), 'labels' => [], 'canLabel' => false, 'base' => '/search', 'notice' => null];
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'search-results') {
    header('Vary: HX-Request');
    echo view('mail/search-results.php', $data);
    exit;
}
render_screen(($q !== '' ? $q . ' — ' : '') . 'Search all mail', view('mail/search.php', $data), ['screen' => 'search-all', 'entity' => 'mailbox', 'activeNav' => 'tab-search']);
