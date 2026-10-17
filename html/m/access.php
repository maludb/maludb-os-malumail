<?php
declare(strict_types=1);
/** /m/{mailbox}/access — who can open this mailbox and why: the owner, the rows (people, departments, agents), emergency (break-glass) rows in red, the holders no row creates; Revoke (screen `mailbox-access`). A reader below the people who decide sees only their own standing. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_login();
require_human();
$pdo = db();
$ctx = ac_context($pdo, request_string('mailbox'));
$mb = $ctx['id'];
$rows = access_rows($pdo, $mb);
$tz = member_timezone();
log_screen_view($pdo, 'mailbox-access', $mb);
if (wants_json()) {
    respond_screen(['mailbox_id' => $mb, 'address' => $ctx['row']['address'], 'kind' => $ctx['kind'], 'your_level' => $ctx['level'], 'sees_every_row' => $ctx['sees_rows'],
                    'rows' => array_map(static fn (array $r): array => present_access_row($r, $ctx['row'], $tz), $rows), 'can_grant' => $ctx['grant']]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['granted' => ['success', 'Shared.'], 'break_glass' => ['warning', 'Emergency access is open. Its owner has been told.'], 'revoked' => ['success', 'Access taken back.']]);
render_screen($ctx['row']['address'] . ' — access', view('access/access.php', ['ctx' => $ctx, 'rows' => $rows, 'notice' => $notice, 'tz' => $tz,
    'settings_url' => st_may_open($ctx) ? '/m/' . $mb . '/settings' : null]),
    ['screen' => 'mailbox-access', 'entity' => 'mailbox', 'recordId' => $mb, 'mailboxId' => $ctx['level'] === 'none' ? null : $mb, 'activeNav' => $ctx['level'] === 'none' ? 'admin' : 'tab-inbox']);
