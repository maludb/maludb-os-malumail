<?php
declare(strict_types=1);
/** /m/{mailbox}/access-log — every look at this mailbox by anyone but its owner: who, what, when, through what (screen `mailbox-access-log`). Its owner and the Mail admins read it; on an agent's or shared mailbox its full holders (mcp_mailbox_access_log). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';
require_login();
require_human();
$pdo = db();
$ctx = ac_context($pdo, request_string('mailbox'));
$mb = $ctx['id'];
if (!$ctx['reads_log']) {
    refuse(403, $ctx['kind'] === 'person' ? 'Only its owner and the Mail admins read who looked at a person\'s mailbox.' : 'Only the people with full access to this mailbox and the Mail admins read who looked at it.');
}
$who = request_integer('who');
$since = request_string('since');
if ($since !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
    refuse(422, 'Give the date as 2026-10-24.');
}
$before = request_integer('before');
$page = 50;
$rows = access_log($pdo, $mb, $who, $since !== '' ? $since : null, $page + 1, $before);
$more = null;
if (count($rows) > $page) {
    $rows = array_slice($rows, 0, $page);
    $more = (int) end($rows)['id'];
}
$tz = member_timezone();
log_screen_view($pdo, 'mailbox-access-log', $mb);
if (wants_json()) {
    respond_screen(['mailbox_id' => $mb, 'looks' => array_map(static fn (array $r): array => present_access_log_row($r, $tz), $rows), 'next' => $more]);
}
render_screen($ctx['row']['address'] . ' — who looked', view('access/log.php', ['ctx' => $ctx, 'rows' => $rows, 'who' => $who, 'since' => $since, 'more' => $more, 'notice' => null, 'tz' => $tz]),
    ['screen' => 'mailbox-access-log', 'entity' => 'mailbox', 'recordId' => $mb, 'mailboxId' => $ctx['level'] === 'none' ? null : $mb, 'activeNav' => $ctx['level'] === 'none' ? 'admin' : 'tab-inbox']);
