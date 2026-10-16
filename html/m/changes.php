<?php
declare(strict_types=1);
/**
 * GET /m/{mailbox}/changes?since={seq}&view=&folder=&top=&limit=&unread=&ids= — the list's poll (mail-list.md "The poll", design §8.1).
 * 204 when the mailbox's change counter is still `since` (one indexed read, no IMAP, nothing logged); otherwise 200 with out-of-band
 * swaps: the rail's counts (#rail-counts), the page's changed rows in place (#t-{id}), rows that left the page removed, and the
 * "N new" bar. X-ML-Seq is the next `since`; X-ML-Unread the Inbox's unread count. 404 for a mailbox the caller cannot open.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();                            // a poll never holds the session
}
$pdo = db();
$mailbox = (int) request_integer('mailbox');
$since = (int) request_integer('since');
$view = request_string('view', 'inbox');
$folder = request_integer('folder');
$label = request_integer('label');
if ($label !== null) {
    $view = 'label';
}
if ($folder === null && $label === null && !isset(ML_VIEWS[$view])) {
    respond_not_found('No such view.');
}
$ids = array_filter(array_map('intval', explode(',', request_string('ids'))), static fn (int $i): bool => $i > 0);
$limit = (int) (request_integer('limit') ?? 50);
$top = request_string('top');
$changes = mail_changes($pdo, $mailbox, $since, $folder !== null ? 'folder' : $view, $folder, array_slice($ids, 0, 100), $top !== '' ? $top : null,
                        $limit, request_string('unread') === '1', $label);
header('Cache-Control: no-store');
if (is_array($changes) && !empty($changes['not_found'])) {
    respond_not_found('No such mailbox.');
}
if ($changes === null) {
    header('X-ML-Seq: ' . $since);
    http_response_code(204);
    exit;
}
header('X-ML-Seq: ' . $changes['seq']);
header('X-ML-Unread: ' . $changes['unread']);
if (wants_json()) {
    respond_screen(['change_seq' => $changes['seq'], 'unread' => $changes['unread'], 'new' => $changes['new'], 'gone' => $changes['gone'],
                    'rows' => array_map('mail_row_json', $changes['rows'])]);
}
$box = ml_require_mailbox($mailbox, 'view');
$here = $label !== null ? '/m/' . $mailbox . '/label/' . $label : ($folder !== null ? '/m/' . $mailbox . '/f/' . $folder : '/m/' . $mailbox . '/v/' . $view);
$tz = member_timezone();
$out = view('mail/partials/rail-counts.php', ['railData' => mail_rail($pdo, $mailbox), 'here' => $here, 'oob' => true]);
foreach ($changes['rows'] as $r) {
    $out .= view('mail/partials/row.php', ['r' => $r, 'box' => $box, 'view' => $folder !== null ? 'folder' : $view, 'tz' => $tz, 'oob' => true]);
}
foreach ($changes['gone'] as $g) {
    $out .= '<div id="t-' . (int) $g . '" hx-swap-oob="delete"></div>';
}
$out .= view('mail/partials/new-bar.php', ['n' => (int) $changes['new'], 'base' => $here, 'unread' => request_string('unread') === '1']);
header('Content-Type: text/html; charset=utf-8');
echo $out;
