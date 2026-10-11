<?php
declare(strict_types=1);

/**
 * Notification mail only: one outbound send through MaluMail's REST API (`/v1/send`, bearer MALUMAIL_API_KEY — the installer's
 * notification key, the same door every sibling's bell uses; MALUMAIL_API_URL points the proofs at a stub). The bell's mail copies
 * (held mail for an overseer, a break-glass notice to a mailbox's owner, a failure for the Mail admins) go through malumail_send().
 *
 * WHAT IS NOT HERE, ON PURPOSE (design §6.1, §6.2): PHP never calls MaluMail's Mailbox MCP and never holds the customer key — every
 * administrative call (create a mailbox, an alias, a reset) and every IMAP/SMTP connection is the MAIL SERVICE's (services/mailer/,
 * its key only in /etc/malumail/mailer.env), reached over its signed loopback door. PHP seals a mailbox password with the PUBLIC key
 * and can never open it. MaluMail takes NO webhook: the mail service's IMAP NOTIFY/IDLE replaces it (D6), leaving each mailbox's one
 * webhook slot to the reader that owns it (D13). (The kit's malumail_mcp_call() and webhook check were removed for this reason.)
 */

/** Send one email. Answers ['status' => int, 'body' => array, 'message_id' => ?string]; throws on a missing key or a transport error (never on an HTTP status). */
function malumail_send(array $mail): array
{
    $key = (string) env('MALUMAIL_API_KEY', '');
    if ($key === '') {
        throw new RuntimeException('MALUMAIL_API_KEY is not configured.');
    }
    $ch = curl_init(rtrim((string) env('MALUMAIL_API_URL', 'https://api.malumail.com'), '/') . '/v1/send');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($mail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || $raw === false) {
        throw new RuntimeException('MaluMail transport error.');
    }
    $body = json_decode((string) $raw, true);
    $body = is_array($body) ? $body : [];
    return ['status' => $status, 'body' => $body, 'message_id' => isset($body['message_id']) ? (string) $body['message_id'] : null];
}
