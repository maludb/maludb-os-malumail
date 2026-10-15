<?php
declare(strict_types=1);
/**
 * GET /m/{mailbox}/msg/{message}/body — THE SANDBOXED BODY (docs/build-specs/reading-and-rendering.md §2, layer two): the sanitised HTML on
 * its own response under the exact Content-Security-Policy below, framed by the conversation page in <iframe sandbox> without
 * allow-same-origin. Level view; ?images=1 (or the sender always shown — ml_images_always_for) asks the mail service to show remote
 * images through the image door; a stale message answers 410. The height line is the only script the policy admits.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
require_login();
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$mb = (int) $box['id'];
$id = (int) request_integer('message');
$st = $pdo->prepare('SELECT m.id, m.uidvalidity, f.uidvalidity AS folder_uidvalidity, ml_images_always_for(m.id) AS always
                       FROM mcp_messages m JOIN mcp_folders f ON f.id = m.folder_id WHERE m.id = :id AND m.mailbox_id = :mb');
$st->execute(['id' => $id, 'mb' => $mb]);
$m = $st->fetch();
$doc = static function (string $inner, string $nonce): string {
    return '<!doctype html><html><head><meta charset="utf-8"><base target="_blank">'
        . '<style>html,body{margin:0;padding:8px 10px;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:15px;line-height:1.5;color:#212529;background:#fff;overflow-wrap:anywhere;word-break:break-word}'
        . 'img,table{max-width:100% !important;height:auto}pre{white-space:pre-wrap}blockquote{margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex;color:#555}a{color:#0d6efd}</style>'
        . '</head><body>' . $inner . '<script nonce="' . $nonce . '">(function(){function h(){parent.postMessage({ml:"h",h:document.documentElement.scrollHeight},"*")}'
        . 'h();window.addEventListener("load",h);var i=document.images;for(var k=0;k<i.length;k++){i[k].addEventListener("load",h);i[k].addEventListener("error",h)}})();</script></body></html>';
};
$nonce = base64_encode(random_bytes(16));
header('Content-Security-Policy: sandbox allow-popups allow-popups-to-escape-sandbox allow-scripts; default-src \'none\'; script-src \'nonce-' . $nonce . '\'; img-src \'self\' data:; style-src \'unsafe-inline\'; font-src data:; frame-ancestors \'self\'; form-action \'none\'; base-uri \'none\'');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
header('Cross-Origin-Resource-Policy: same-origin');
header('Content-Type: text/html; charset=utf-8');
if ($m === false) {
    http_response_code(404);
    echo $doc('<p>No such message.</p>', $nonce);
    exit;
}
if ($m['folder_uidvalidity'] !== null && (int) $m['folder_uidvalidity'] !== (int) $m['uidvalidity']) {
    http_response_code(410);
    echo $doc('<p>This message moved — reload.</p>', $nonce);
    exit;
}
$images = request_string('images') === '1' || $m['always'] === true || $m['always'] === 't';
$f = ml_message_fetch($mb, $id, 'html', $images);
if ($f === null) {
    http_response_code(503);
    echo $doc('<p>The message body is not available right now.</p>', $nonce);
    exit;
}
if (!empty($f['moved']) || !empty($f['gone'])) {
    http_response_code(410);
    echo $doc('<p>This message moved — reload.</p>', $nonce);
    exit;
}
$inner = $f['html'] ?? null;
if ($inner === null) {                                       // a message with no HTML part renders in the page itself (decision 4): nothing here
    $inner = '';
}
echo $doc($inner, $nonce);
