<?php
declare(strict_types=1);
/**
 * GET /img/{signed} — the image door's front (docs/build-specs/reading-and-rendering.md §4), on the public allow-list: the signed token
 * (kind img, minted by the mail service as it sanitised with images shown, bound to the member and the message, one hour; the member's
 * level re-checked on every use) names the remote image's URL; PHP asks the image door on loopback (GET /fetch?u=) and streams its
 * answer with the type the door sniffed. Anything the door refuses answers 404 (the frame shows nothing where the image was).
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
if ($link === null || $link['k'] !== 'img' || $link['u'] === null || !preg_match('#^https?://#i', $link['u'])) {
    $gone();
}
if (!ml_part_admit(db(), $link)) {
    $gone();
}
$port = (int) env('ML_IMAGES_PORT', '0');
if ($port <= 0) {
    $gone();
}
$ch = curl_init('http://127.0.0.1:' . $port . '/fetch?u=' . rawurlencode($link['u']));
$status = 0;
$type = 'application/octet-stream';
$started = false;
curl_setopt_array($ch, [
    CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 25, CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$status, &$type): int {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (stripos($line, 'content-type:') === 0) {
            $type = trim(substr($line, 13));
        }
        return strlen($line);
    },
    CURLOPT_WRITEFUNCTION => static function ($c, string $data) use (&$status, &$type, &$started, $gone): int {
        if ($status !== 200) {
            return strlen($data);                             // the door's one-word reason: swallowed, the answer is 404
        }
        if (!$started) {
            $started = true;
            if (!in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                return -1;
            }
            header('Content-Type: ' . $type);
            header('X-Content-Type-Options: nosniff');
            header('Content-Security-Policy: default-src \'none\'');
            header('Cache-Control: private, max-age=3600');
            header('Referrer-Policy: no-referrer');
        }
        echo $data;
        flush();
        return strlen($data);
    },
]);
curl_exec($ch);
curl_close($ch);
if (!$started) {
    $gone();
}
