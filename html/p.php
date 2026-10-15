<?php
declare(strict_types=1);
/**
 * GET /p/{signed} — THE PART DOOR (docs/build-specs/reading-and-rendering.md §3), on the public allow-list: the signed token is the
 * authority (an image inside the opaque-origin frame carries no cookie). It verifies the MAC in constant time, the expiry, that the member
 * it names is still active and still opens the mailbox at view — re-checked on every use — then streams the mail service's `part`.
 * Inline only for a raster image, by the BYTES (never the declared type); an HTML or SVG part is never inline and is served as
 * application/octet-stream. Anything else answers 404. A non-owner's part writes message.part once per part per hour.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/mail/reading.php';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
header_remove('Set-Cookie');
$gone = static function (): never {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Not found\n";
    exit;
};
$link = ml_part_verify(request_string('t'));
if ($link === null || $link['k'] !== 'part' || !preg_match('/^[0-9]+(\.[0-9]+)*$/', $link['p'])) {
    $gone();
}
$pdo = db();
if (!ml_part_admit($pdo, $link)) {
    $gone();
}
[$fid, $v, $uid] = array_map('intval', explode('.', $link['r']));
$st = $pdo->prepare('SELECT m.id, m.message_key_id, m.mailbox_id FROM mail_messages m WHERE m.mailbox_id = :mb AND m.folder_id = :f AND m.uidvalidity = :v AND m.uid = :u');
$st->execute(['mb' => $link['m'], 'f' => $fid, 'v' => $v, 'u' => $uid]);
$row = $st->fetch();
if ($row === false) {
    $gone();
}
$sent = false;
try {
    mailer_stream('/v1/mailboxes/' . $link['m'] . '/part', $link['a'], ['ref' => $link['r'], 'part' => $link['p']],
        static function (int $status, array $h) use ($gone, $link, $pdo, $row, &$sent): bool {
            if ($status !== 200) {
                $gone();
            }
            $head = (string) ($h['__head'] ?? '');
            $raster = ml_sniff_raster($head);
            $declared = strtolower(trim(explode(';', (string) ($h['content-type'] ?? 'application/octet-stream'))[0]));
            $name = ml_safe_filename(isset($h['x-ml-name']) ? rawurldecode((string) $h['x-ml-name']) : null, 'part-' . str_replace('.', '-', $link['p']));
            $type = $raster ?? ($declared === '' || str_starts_with($declared, 'text/html') || str_starts_with($declared, 'image/svg') || str_starts_with($declared, 'application/xhtml') ? 'application/octet-stream' : $declared);
            if ($raster === null && !str_contains($name, '.') && $declared !== 'application/octet-stream') {
                $name .= '.bin';
            }
            header('Content-Type: ' . $type);
            header('Content-Disposition: ' . ($raster !== null ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('X-Content-Type-Options: nosniff');
            header('Content-Security-Policy: sandbox; default-src \'none\'');
            header('Cache-Control: private, max-age=3600');
            header('Referrer-Policy: no-referrer');
            if (isset($h['content-length'])) {
                header('Content-Length: ' . (int) $h['content-length']);
            }
            ml_log_read($pdo, (int) $link['m'], 'message.part', (int) $row['id'],
                        ['message_key_id' => (int) $row['message_key_id'], 'part' => $link['p'], 'type' => $type, 'size' => (int) ($h['x-ml-size'] ?? 0)], $link['a']);
            $sent = true;
            return true;
        },
        static function (string $chunk): bool {
            echo $chunk;
            flush();
            return true;
        });
} catch (MailerDown) {
    if (!$sent) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Could not load this part — try again\n";
    }
}
