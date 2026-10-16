<?php
declare(strict_types=1);
/** /m/{mailbox}/identities/new (screen `identity-new`, param address) and /m/{mailbox}/identities/{identity}/edit (screen `identity-edit`): full on the mailbox, people. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
require_human();
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'full');
$mb = (int) $box['id'];
$id = request_integer('identity');
$identity = null;
if ($id !== null) {
    $st = $pdo->prepare('SELECT * FROM mcp_identities WHERE id = :id AND mailbox_id = :mb');
    $st->execute(['id' => $id, 'mb' => $mb]);
    $identity = $st->fetch() ?: null;
    if ($identity === null) {
        respond_not_found('No such identity.');
    }
    $identity['is_default'] = $identity['is_default'] === true || $identity['is_default'] === 't';
}
$screen = $identity !== null ? 'identity-edit' : 'identity-new';
log_screen_view($pdo, $screen, $mb);
if (wants_json()) {
    respond_screen(['mailbox_id' => $mb, 'identity' => $identity !== null ? present_identity($identity) + ['signature_html' => $identity['signature_html'], 'signature_text' => $identity['signature_text']] : null]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.']]);
$count = (int) db_value($pdo, 'SELECT count(*) FROM mcp_identities WHERE mailbox_id = :mb', ['mb' => $mb]);
render_screen(($identity !== null ? 'Identity' : 'New identity') . ' — ' . $box['address'], view('compose/identity_form.php', [
    'box' => $box, 'identity' => $identity, 'address' => request_string('address'), 'notice' => $notice, 'count' => $count,
]), ['screen' => $screen, 'entity' => 'mail_identity', 'recordId' => $id, 'mailboxId' => $mb]);
