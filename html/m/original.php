<?php
declare(strict_types=1);
/** /m/{mailbox}/msg/{message}/original — the Original (screen `message-original`, reading.md): the headers, the source cut at 1 MB, Download .eml. Level view; a non-owner's open writes message.raw. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$mb = (int) $box['id'];
$id = (int) request_integer('message');
$st = $pdo->prepare('SELECT m.*, f.uidvalidity AS folder_uidvalidity FROM mcp_messages m JOIN mcp_folders f ON f.id = m.folder_id WHERE m.id = :id AND m.mailbox_id = :mb');
$st->execute(['id' => $id, 'mb' => $mb]);
$m = $st->fetch();
if ($m === false) {
    respond_not_found('No such message.');
}
$threadUrl = '/m/' . $mb . '/t/' . (int) $m['thread_id'];
if ($m['folder_uidvalidity'] !== null && (int) $m['folder_uidvalidity'] !== (int) $m['uidvalidity']) {
    http_response_code(410);
    render_screen('Original', view('shared/banner.php', ['notice' => ['warning', 'This message moved — reload.'], 'link' => [$threadUrl, 'Back to the conversation']]), ['screen' => 'message-original', 'mailboxId' => $mb]);
    exit;
}
log_screen_view($pdo, 'message-original', $mb);
ml_log_read($pdo, $mb, 'message.raw', $id, ['message_key_id' => (int) $m['message_key_id'], 'door' => 'original']);
$f = ml_message_fetch($mb, $id, 'headers');
$headers = is_array($f) ? ($f['headers'] ?? []) : [];
$source = '';
$truncated = false;
$cap = 1048576;
try {
    mailer_stream('/v1/mailboxes/' . $mb . '/raw', (int) current_member_id(), ['message' => $id],
        static fn (int $status, array $h): bool => $status === 200,
        static function (string $chunk) use (&$source, &$truncated, $cap): bool {
            $source .= $chunk;
            if (strlen($source) > $cap) {
                $source = substr($source, 0, $cap);
                $truncated = true;
                return false;
            }
            return true;
        }, ml_on_behalf(), 60);
} catch (MailerDown) {
    $source = '';
}
if ($source === '' && $f === null) {
    $source = '(the mail service is not answering — the source is not available right now)';
}
if (wants_json()) {
    respond_screen(['mailbox_id' => $mb, 'message' => $id, 'headers' => $headers, 'source_bytes' => strlen($source), 'truncated' => $truncated]);
}
render_screen('Original — ' . $box['address'], view('mail/original.php', ['box' => $box, 'm' => $m, 'headers' => $headers, 'source' => $source, 'truncated' => $truncated,
    'tz' => member_timezone(), 'threadUrl' => $threadUrl]), ['screen' => 'message-original', 'entity' => 'mail_message', 'recordId' => $id, 'mailboxId' => $mb]);
