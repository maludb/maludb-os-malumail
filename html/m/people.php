<?php
declare(strict_types=1);
/**
 * /m/{mailbox}/people?q=&known=&outside=&before= — everyone this mailbox wrote to or heard from (screen `correspondents`,
 * docs/build-specs/correspondents.md): name and address, first and last contact, sent and received, Known (how) on an agent's mailbox, Outside;
 * Mark known for an agent mailbox's overseer. Read through mcp_correspondents (the wall in the view). A non-owner's view writes `mailbox.view`
 * (once per half hour). JSON: the whitelist rows and the next cursor.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$mb = (int) $box['id'];
$filters = ['q' => request_string('q'), 'known' => request_string('known'), 'outside' => request_string('outside')];
foreach (['known', 'outside'] as $f) {
    if (!in_array($filters[$f], ['', 'yes', 'no'], true)) {
        refuse(422, 'The ' . $f . ' filter is yes or no.');
    }
}
$before = request_string('before');
if ($before !== '' && ml_people_cursor($before) === null) {
    refuse(422, 'That page marker is not one this list made: open the list again.');
}
$page = ml_correspondents_page($mb, $filters, $before !== '' ? $before : null);
if (!is_htmx_request() || ($_SERVER['HTTP_HX_TARGET'] ?? '') !== 'people-results') {
    log_screen_view($pdo, 'correspondents', $mb);
}
mail_log_list_view($pdo, $mb, 'people');
$agentBox = $box['kind'] === 'agent';
$canMark = $agentBox && ml_action_built('mail_sender_mark_known') && db_bool($pdo, 'SELECT ml_is_decider(:mb, :m)', ['mb' => $mb, 'm' => (int) current_member_id()]);
if (wants_json()) {
    respond_screen(['mailbox_id' => $mb, 'address' => $box['address'], 'filters' => $filters,
                    'correspondents' => array_map('ml_correspondent_json', $page['rows']), 'next' => $page['next']]);
}
$data = ['box' => $box, 'filters' => $filters, 'page' => $page, 'agentBox' => $agentBox, 'canMark' => $canMark, 'tz' => member_timezone(),
         'base' => '/m/' . $mb . '/people', 'notice' => ml_notice($_GET['notice'] ?? null, ['known' => ['success', 'Known: its mail can wake the agent now.']])];
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'people-results') {
    header('Vary: HX-Request');
    echo view('mail/people-results.php', $data);
    exit;
}
render_screen('People — ' . $box['address'], view('mail/people.php', $data), ['screen' => 'correspondents', 'entity' => 'mailbox', 'recordId' => $mb, 'mailboxId' => $mb]);
