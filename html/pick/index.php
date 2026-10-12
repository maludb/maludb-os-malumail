<?php
declare(strict_types=1);
/**
 * GET /pick/?source=<key>&q=&page=&selected=&<declared params> — the record picker's rows (app/picker.php, app/pickers.php).
 * Pattern A fragment: the answer lands inside the one #record-picker modal, so every refusal is a small fragment, never a page
 * and never a redirect. The source names its params (an integer each — nothing else of the request reaches it) and its
 * gate, the same authorization the screens that use the field apply. A search is not an action: nothing is logged.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Vary: HX-Request');
header('Cache-Control: no-store');
function pick_refuse(int $status, string $words): never
{
    http_response_code($status);
    echo view('shared/picker-rows.php', ['error' => $words]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); pick_refuse(405, 'That list is read with a GET.'); }
if (!is_logged_in()) { pick_refuse(401, 'Sign in again to choose.'); }
if ((current_member()['member_kind'] ?? '') !== 'human') { pick_refuse(403, 'This is for people; an agent uses the tools.'); }
$key = preg_replace('/[^a-z0-9_]/', '', strtolower(request_string('source')));
$source = $key !== '' ? picker_source($key) : null;
if ($source === null) { pick_refuse(404, 'That list does not exist.'); }
$params = [];
foreach ($source['params'] as $name => $type) {
    $params[$name] = request_integer($name);                       // every declared param is an integer here
}
$refusal = ($source['gate'])($params);
if ($refusal !== null) { pick_refuse(403, $refusal); }
$q = mb_substr(request_string('q'), 0, 80);
$page = max(1, request_integer('page') ?? 1);
$selected = request_string('selected');
$selected = ctype_digit($selected) ? $selected : null;
echo view('shared/picker-rows.php', [
    'source' => $key, 'q' => $q, 'params' => $params, 'selected' => $selected, 'noun' => $source['noun'],
    'result' => ($source['search'])(db(), $q, $params, $page),
]);
