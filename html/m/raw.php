<?php
declare(strict_types=1);
/** /m/{mailbox}/msg/{message}/raw — the .eml, streamed from the mail service's `raw` (reading-and-rendering.md §3). Level view; a non-owner's download writes message.raw. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
require_login();
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$mb = (int) $box['id'];
$id = (int) request_integer('message');
$st = $pdo->prepare('SELECT m.id, m.message_key_id, m.subject, m.uidvalidity, f.uidvalidity AS folder_uidvalidity FROM mcp_messages m JOIN mcp_folders f ON f.id = m.folder_id WHERE m.id = :id AND m.mailbox_id = :mb');
$st->execute(['id' => $id, 'mb' => $mb]);
$m = $st->fetch();
if ($m === false) {
    respond_not_found('No such message.');
}
if ($m['folder_uidvalidity'] !== null && (int) $m['folder_uidvalidity'] !== (int) $m['uidvalidity']) {
    refuse(410, 'This message moved — reload.');
}
ml_log_read($pdo, $mb, 'message.raw', $id, ['message_key_id' => (int) $m['message_key_id'], 'door' => 'raw']);
$name = ml_subject_slug($m['subject']) . '.eml';
try {
    mailer_stream('/v1/mailboxes/' . $mb . '/raw', (int) current_member_id(), ['message' => $id],
        static function (int $status, array $h) use ($name): bool {
            if ($status !== 200) {
                $e = $h['__error'] ?? ['code' => 'failed', 'message' => 'The message could not be read; try again.'];
                refuse($e['code'] === 'moved' ? 410 : 502, $e['message']);
            }
            header('Content-Type: message/rfc822');
            header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            if (isset($h['content-length'])) {
                header('Content-Length: ' . (int) $h['content-length']);
            }
            return true;
        },
        static function (string $chunk): bool {
            echo $chunk;
            flush();
            return true;
        }, ml_on_behalf(), 300);
} catch (MailerDown $e) {
    refuse(503, $e->getMessage());
}
