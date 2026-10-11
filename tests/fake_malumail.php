<?php
/**
 * A FAKE MaluMail REST send for the kit's proofs — `php -S 127.0.0.1:9203 tests/fake_malumail.php`: /v1/send logs each body to
 * $FAKE_MALUMAIL_LOG and answers as the address asks ("suppressed" → 400 all rejected, "rejected" → 200 with a rejection, "flaky" → 502,
 * else 200 accepted with a message_id). That is all PHP ever calls at MaluMail (notification mail, app/mail.php). The FAKE MAILBOX MCP
 * (create_mailbox, aliases, resets — the admin plane) and the IMAP/SMTP FIXTURE (the content plane) are the mail service's, in its own
 * proofs (services/mailer/, design §10 Phase 0) — never here, because PHP never speaks to either.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . getenv('MALUMAIL_API_KEY')) { http_response_code(401); echo '{"error":"bad key"}'; exit; }
if ($path === '/v1/send' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $n = substr_count((string) @file_get_contents((string) getenv('FAKE_MALUMAIL_LOG')), "\n") + 1;
    file_put_contents((string) getenv('FAKE_MALUMAIL_LOG'), json_encode($in) . "\n", FILE_APPEND);
    $to = (string) ($in['to'] ?? '');
    if (str_contains($to, 'suppressed')) { http_response_code(400); echo json_encode(['error' => 'No deliverable recipients.', 'rejected' => [['email' => $to, 'reason' => 'suppressed:bounce']]]); exit; }
    if (str_contains($to, 'flaky')) { http_response_code(502); echo '{"error":"relay refused"}'; exit; }
    if (str_contains($to, 'rejected')) { echo json_encode(['status' => 'sent', 'accepted' => [], 'rejected' => [['email' => $to, 'reason' => 'invalid_address']], 'message_id' => null]); exit; }
    echo json_encode(['status' => 'sent', 'accepted' => [$to], 'rejected' => [], 'message_id' => '<mm-' . $n . '@fake.malumail>']); exit;
}
http_response_code(404); echo '{"error":"not found"}';
