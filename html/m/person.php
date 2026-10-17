<?php
declare(strict_types=1);
/**
 * /m/{mailbox}/people/{correspondent}?tab=&before= — what this mailbox exchanged with one address, as conversations; for an outside address
 * whose domain is not a public mail provider, a tab "Everyone at {domain}" (every conversation with any address of the domain, and the
 * domain's addresses with their counts). Screen `correspondent-view`. An address the mailbox never exchanged with: 404. Read through the
 * views, so the wall and the sensitive rule decide.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$mb = (int) $box['id'];
$c = ml_correspondent($mb, request_string('correspondent'));
if ($c === null) {
    respond_not_found('This mailbox has not exchanged mail with that address.');
}
$domain = strtolower((string) substr((string) $c['address'], (int) strrpos((string) $c['address'], '@') + 1));
$company = !$c['is_internal'] && !ml_public_provider($domain);
$tab = request_string('tab') === 'company' && $company ? 'company' : 'address';
$before = request_string('before');
if ($before !== '' && ml_people_cursor($before) === null) {
    refuse(422, 'That page marker is not one this list made: open the page again.');
}
$threads = ml_correspondence_threads($mb, $tab === 'company' ? $domain : (string) $c['address'], $tab === 'company', $before !== '' ? $before : null);
$addresses = $tab === 'company' ? ml_domain_addresses($mb, $domain) : [];
if (!is_htmx_request() || ($_SERVER['HTTP_HX_TARGET'] ?? '') !== 'person-threads') {
    log_screen_view($pdo, 'correspondent-view', $mb);
}
mail_log_list_view($pdo, $mb, 'person');
if (wants_json()) {
    respond_screen(['mailbox_id' => $mb, 'correspondent' => ml_correspondent_json($c), 'tab' => $tab, 'company_domain' => $company ? $domain : null,
                    'conversations' => array_map(static fn (array $t): array => ['thread_id' => $t['thread_id'], 'subject' => $t['subject'], 'participants' => $t['participants'],
                        'last_message_at' => $t['last_message_at'], 'message_count' => $t['message_count'], 'snippet' => $t['snippet']], $threads['rows']),
                    'addresses' => $addresses, 'next' => $threads['next']]);
}
$data = ['box' => $box, 'c' => $c, 'domain' => $domain, 'company' => $company, 'tab' => $tab, 'threads' => $threads, 'addresses' => $addresses, 'tz' => member_timezone(),
         'base' => '/m/' . $mb . '/people/' . rawurlencode((string) $c['address'])];
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'person-threads') {
    header('Vary: HX-Request');
    echo view('mail/person-threads.php', $data);
    exit;
}
render_screen(($c['name'] ?: $c['address']) . ' — ' . $box['address'], view('mail/person.php', $data),
    ['screen' => 'correspondent-view', 'entity' => 'mailbox', 'recordId' => $mb, 'mailboxId' => $mb]);
