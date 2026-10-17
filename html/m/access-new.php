<?php
declare(strict_types=1);
/** /m/{mailbox}/access/new — the grant form: who (the record picker over people, departments and agents), the level as four cards; on a person's mailbox for a Mail admin who is not its owner, the break-glass form instead (screen `access-grant-new`). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';
require_login();
require_human();
$pdo = db();
$ctx = ac_context($pdo, request_string('mailbox'));
$mb = $ctx['id'];
if ($ctx['grant'] === null) {
    refuse(403, ac_grant_refusal($ctx));
}
log_screen_view($pdo, 'access-grant-new', $mb);
$values = ['person' => request_integer('person'), 'department' => request_integer('department'), 'agent' => request_integer('agent'), 'level' => request_string('level')];
if (wants_json()) {
    respond_screen(['mailbox_id' => $mb, 'form' => $ctx['grant'] === 'break_glass' ? 'break_glass' : 'share', 'levels' => AC_LEVEL_WORDS,
                    'max_days' => $ctx['grant'] === 'break_glass' ? 30 : null]);
}
render_screen('Share ' . $ctx['row']['address'], view('access/grant-form.php', ['ctx' => $ctx, 'tz' => member_timezone(), 'values' => $values]),
    ['screen' => 'access-grant-new', 'entity' => 'mailbox', 'recordId' => $mb, 'mailboxId' => $ctx['level'] === 'none' ? null : $mb, 'activeNav' => $ctx['level'] === 'none' ? 'admin' : 'tab-inbox']);
