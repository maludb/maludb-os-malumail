<?php
declare(strict_types=1);
/**
 * GET /oversight/review/{outbound}/body — the held message's body on its OWN sandboxed response (decision 2: an agent's HTML is untrusted
 * too), under the exact policy of /m/{mailbox}/msg/{message}/body: framed by the review page in <iframe sandbox> without
 * allow-same-origin, the nonce'd height line the only script. The HTML is what the mail service sanitised at Send; text is escaped.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/oversight/queries.php';
require_login();
$pdo = db();
$st = $pdo->prepare('SELECT q.body_html, q.body_text, q.disclosure FROM mcp_review_queue q WHERE q.id = :id');
$st->execute(['id' => (int) request_integer('id')]);
$m = has_right('mail.oversee') ? $st->fetch() : false;
$nonce = base64_encode(random_bytes(16));
header('Content-Security-Policy: sandbox allow-popups allow-popups-to-escape-sandbox allow-scripts; default-src \'none\'; script-src \'nonce-' . $nonce . '\'; img-src \'self\' data:; style-src \'unsafe-inline\'; font-src data:; frame-ancestors \'self\'; form-action \'none\'; base-uri \'none\'');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
header('Cross-Origin-Resource-Policy: same-origin');
header('Content-Type: text/html; charset=utf-8');
$inner = '<p>No such message.</p>';
if ($m === false) {
    http_response_code(404);
} else {
    $inner = ($m['body_html'] ?? null) !== null && trim((string) $m['body_html']) !== '' ? (string) $m['body_html'] : '<div style="white-space:pre-wrap">' . e((string) $m['body_text']) . '</div>';
}
echo '<!doctype html><html><head><meta charset="utf-8"><base target="_blank">'
    . '<style>html,body{margin:0;padding:8px 10px;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:15px;line-height:1.5;color:#212529;background:#fff;overflow-wrap:anywhere;word-break:break-word}'
    . 'img,table{max-width:100% !important;height:auto}pre{white-space:pre-wrap}blockquote{margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex;color:#555}a{color:#0d6efd}</style>'
    . '</head><body>' . $inner . '<script nonce="' . $nonce . '">(function(){function h(){parent.postMessage({ml:"h",h:document.documentElement.scrollHeight},"*")}'
    . 'h();window.addEventListener("load",h);var i=document.images;for(var k=0;k<i.length;k++){i[k].addEventListener("load",h);i[k].addEventListener("error",h)}})();</script></body></html>';
