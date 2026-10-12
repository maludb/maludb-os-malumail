<?php
declare(strict_types=1);

/**
 * The mail service's client (sso-shell.md "The mail service's client"; docs/build-specs/mail-service.md is the protocol). PHP reaches
 * mail ONLY through this: a POST to the service's loopback door, signed exactly as services/mailer/door.py signs —
 *   X-ML-Mailer: v1.{ts}.{nonce}.{hex HMAC-SHA256(ML_MAILER_KEY, "v1.{ts}.{nonce}.POST.{path}.{hex sha256(body)}")}
 * with the body {"actor": <member id>, "args": {…}}. PHP never holds a mailbox credential, the customer key or the sealing secret key.
 */

final class MailerRefused extends RuntimeException
{
    public function __construct(public readonly string $codeName, string $message, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct($message);
    }
}

final class MailerDown extends RuntimeException
{
    public function __construct(string $message = 'The mail service is not answering; nothing was changed.')
    {
        parent::__construct($message);
    }
}

function mailer_base(): string
{
    return 'http://127.0.0.1:' . (int) env('ML_MAILER_PORT', '0');
}

/** The X-ML-Mailer header for one call (the same construction as services/mailer/door.py sign()). */
function mailer_sign(string $key, string $method, string $path, string $body, ?int $ts = null, ?string $nonce = null): string
{
    $ts ??= time();
    $nonce ??= bin2hex(random_bytes(16));
    $msg = 'v1.' . $ts . '.' . $nonce . '.' . strtoupper($method) . '.' . $path . '.' . hash('sha256', $body);
    return 'v1.' . $ts . '.' . $nonce . '.' . hash_hmac('sha256', $msg, $key);
}

/**
 * One call to the mail service as $actorMemberId: answers the decoded `result`; throws MailerRefused (the service's code and its
 * sentence, which the handler shows) or MailerDown (no answer, or not configured). $onBehalf is the person behind an agent's run
 * (mail-service.md §1): signed into the body, so the service computes ml_level_as(mailbox, actor, on_behalf) itself.
 */
function mailer_call(string $path, int $actorMemberId, array $args = [], ?int $onBehalf = null, int $timeout = 30): array
{
    $key = (string) env('ML_MAILER_KEY', '');
    if ($key === '' || (int) env('ML_MAILER_PORT', '0') <= 0) {
        throw new MailerDown('The mail service is not configured on this server.');
    }
    $body = json_encode(['actor' => $actorMemberId, 'on_behalf' => $onBehalf, 'args' => (object) $args], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init(mailer_base() . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-ML-Mailer: ' . mailer_sign($key, 'POST', $path, $body)],
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($raw) || $status === 0) {
        throw new MailerDown();
    }
    $doc = json_decode($raw, true);
    if (!is_array($doc)) {
        throw new MailerDown('The mail service answered something it should not have; nothing was changed.');
    }
    if (!empty($doc['ok'])) {
        return is_array($doc['result'] ?? null) ? $doc['result'] : [];
    }
    $err = is_array($doc['error'] ?? null) ? $doc['error'] : [];
    throw new MailerRefused((string) ($err['code'] ?? 'refused'), (string) ($err['message'] ?? 'The mail service refused that.'), $status,
        array_diff_key($err, ['code' => 1, 'message' => 1]));
}

/** The mail service's own health (GET /health, unsigned, counts only), or null when it does not answer. */
function mailer_health(float $timeout = 1.0): ?array
{
    if ((int) env('ML_MAILER_PORT', '0') <= 0) {
        return null;
    }
    $ch = curl_init(mailer_base() . '/health');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => (int) ($timeout * 1000), CURLOPT_TIMEOUT_MS => (int) ($timeout * 2000)]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $doc = is_string($raw) && $status === 200 ? json_decode($raw, true) : null;
    return is_array($doc) ? $doc : null;
}
