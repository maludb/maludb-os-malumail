<?php
declare(strict_types=1);
/**
 * /m/{mailbox}/search?q=&before= — Gmail's operators over one mailbox (screen `search`, docs/build-specs/search-labels-snooze.md): one row
 * per conversation, its best-matching message's snippet, the matched messages counted; cursor paging; the operators it did not know named
 * (searched as words). A non-owner's search writes `mailbox.view` (once per half hour). JSON: the conversations and the next cursor.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$mb = (int) $box['id'];
$q = request_string('q');
$before = request_string('before');
if ($before !== '' && ml_search_cursor($before) === null) {
    refuse(422, 'That page marker is not one this search made: search again.');
}
$page = ml_search_page($mb, $q, $before !== '' ? $before : null);
if (!is_htmx_request() || ($_SERVER['HTTP_HX_TARGET'] ?? '') !== 'search-results') {
    log_screen_view($pdo, 'search', $mb);
}
if ($q !== '') {
    mail_log_list_view($pdo, $mb, 'search');
}
if ($page['error'] !== null) {
    http_response_code(422);
}
if (wants_json()) {
    $payload = ['mailbox_id' => $mb, 'q' => $q, 'unknown_operators' => $page['unknown'], 'conversations' => array_map('ml_search_row_json', $page['rows']), 'next' => $page['next']];
    if ($page['error'] !== null) {
        json_error('bad_query', $page['error'], 422);
    }
    respond_screen($payload);
}
$labels = ml_labels_of($pdo, $mb);
$data = ['box' => $box, 'multi' => false, 'q' => $q, 'page' => $page, 'tz' => member_timezone(), 'labels' => $labels,
         'canLabel' => (ML_LEVELS[(string) $box['my_level']] ?? 0) >= 2 && ml_action_built('mail_label') && $labels !== [],
         'base' => '/m/' . $mb . '/search', 'notice' => function_exists('mail_action_notice') ? mail_action_notice($_GET['notice'] ?? null, (int) ($_GET['n'] ?? 0)) : null];
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'search-results') {
    header('Vary: HX-Request');
    echo view('mail/search-results.php', $data);
    exit;
}
render_screen(($q !== '' ? $q . ' — ' : '') . 'Search — ' . $box['address'], view('mail/search.php', $data),
    ['screen' => 'search', 'entity' => 'mailbox', 'recordId' => $mb, 'mailboxId' => $mb]);
