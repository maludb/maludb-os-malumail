<?php
declare(strict_types=1);
/** /m/{mailbox}/settings — a mailbox's settings: reading, sending, the addresses it sends as, and on a person's mailbox its owner's two privacy switches (screen `mailbox-settings`). A person holding full on it; a Mail admin only an agent's or shared mailbox's switches. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_login();
require_human();
$pdo = db();
$ctx = ac_context($pdo, request_string('mailbox'));
$mb = $ctx['id'];
if (!st_may_open($ctx)) {
    $ctx['level'] === 'none' ? respond_not_found('No such mailbox.') : refuse(403, 'You can ' . (ML_LEVEL_WORDS[$ctx['level']] ?? 'open this mailbox') . ' (' . $ctx['level'] . '), but not ' . ML_LEVEL_WORDS['full'] . '.');
}
$box = $ctx['row'];
$s = mailbox_settings_read($pdo, $box);
// the two switches: a person's mailbox, its owner alone; an agent's or shared mailbox, a decider or a Mail admin
$switches = $ctx['kind'] === 'person' ? $ctx['owner'] : ($ctx['decider'] || $ctx['manager']);
$identities = $ctx['level'] === 'full' ? compose_identities($pdo, $box) : [];
log_screen_view($pdo, 'mailbox-settings', $mb);
if (wants_json()) {
    respond_screen(present_mailbox_settings($box, $s, $switches));
}
$notice = ml_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'unchanged' => ['info', 'Nothing changed.']]);
render_screen($box['address'] . ' — settings', view('settings/settings.php', ['ctx' => $ctx, 's' => $s, 'switches' => $switches, 'identities' => $identities, 'notice' => $notice, 'tz' => member_timezone()]),
    ['screen' => 'mailbox-settings', 'entity' => 'mailbox', 'recordId' => $mb, 'mailboxId' => $ctx['level'] === 'none' ? null : $mb, 'activeNav' => $ctx['level'] === 'none' ? 'admin' : 'tab-inbox']);
