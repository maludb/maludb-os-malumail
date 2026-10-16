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

/**
 * A streamed answer from the mail service (`part`, `raw` — mail-service.md §10): the call is signed as mailer_call()'s; the service's
 * status and headers reach $onHeaders(status, headers) once the first bytes are in hand (so the caller can sniff them — reading-and-
 * rendering.md §3: inline only for a raster image, by the bytes); $onHeaders answers false to stop (the caller refused), else the body
 * is handed to $onChunk(bytes) in the order it arrives. The session is released first (DocCloud's rule). Throws MailerDown when nothing
 * answers; a non-2xx status reaches $onHeaders with the decoded error body in $headers['__error'].
 */
function mailer_stream(string $path, int $actorMemberId, array $args, callable $onHeaders, callable $onChunk, ?int $onBehalf = null, int $timeout = 300): void
{
    $key = (string) env('ML_MAILER_KEY', '');
    if ($key === '' || (int) env('ML_MAILER_PORT', '0') <= 0) {
        throw new MailerDown('The mail service is not configured on this server.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $body = json_encode(['actor' => $actorMemberId, 'on_behalf' => $onBehalf, 'args' => (object) $args], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $headers = [];
    $status = 0;
    $started = false;
    $head = '';
    $stopped = false;
    $errorBody = '';
    $ch = curl_init(mailer_base() . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-ML-Mailer: ' . mailer_sign($key, 'POST', $path, $body)],
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers, &$status): int {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            } elseif (str_contains($line, ':')) {
                [$n, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($n))] = trim($v);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($c, string $data) use (&$started, &$head, &$stopped, &$errorBody, &$headers, &$status, $onHeaders, $onChunk): int {
            if ($stopped) {
                return -1;
            }
            if ($status >= 300) {
                $errorBody .= $data;
                return strlen($data);
            }
            if (!$started) {
                $head .= $data;
                if (strlen($head) < 16) {
                    return strlen($data);
                }
                $started = true;
                $headers['__head'] = $head;
                if ($onHeaders($status, $headers) === false) {
                    $stopped = true;
                    return -1;
                }
                if ($onChunk($head) === false) {
                    $stopped = true;
                    return -1;
                }
                return strlen($data);
            }
            if ($onChunk($data) === false) {
                $stopped = true;
                return -1;
            }
            return strlen($data);
        },
    ]);
    $ok = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($status === 0) {
        throw new MailerDown();
    }
    if ($status >= 300) {
        $doc = json_decode($errorBody, true);
        $err = is_array($doc['error'] ?? null) ? $doc['error'] : [];
        $headers['__error'] = ['code' => (string) ($err['code'] ?? 'refused'), 'message' => (string) ($err['message'] ?? 'The mail service refused that.')];
        $onHeaders($status, $headers);
        return;
    }
    if (!$started) {                                         // a body shorter than 16 bytes (or empty): hand it over whole
        $started = true;
        $headers['__head'] = $head;
        if ($onHeaders($status, $headers) !== false && $head !== '') {
            $onChunk($head);
        }
    }
    if ($ok === false && $errno !== 0 && !$stopped) {
        $headers['__short'] = true;                          // the stream ended early: the caller's output is already partly sent
    }
}

// ---- slice 4 (writing.md "Mail-service calls"): the door calls the composer makes, by name -----------------------------------------
/** APPEND a draft's current version to Drafts now (the composer closing; otherwise the service files it on its own within seconds). Null when it could not. */
function mailer_draft_append(int $mailbox, int $draft, bool $discard = false): ?array
{
    try {
        return mailer_call('/v1/mailboxes/' . $mailbox . '/append_draft', (int) current_member_id(), ['draft' => $draft, 'discard' => $discard], ml_on_behalf(), 30);
    } catch (MailerRefused | MailerDown) {
        return null;
    }
}

/** The original's sanitised body for a quote (the compose profile: no images, links kept). */
function mailer_fetch_for_quote(int $mailbox, int $messageId): ?array
{
    return ml_message_fetch($mailbox, $messageId, 'html', false, ['profile' => 'compose']);
}

/** HTML a person wrote, cleaned by the mail service: ['html' => …, 'text' => …]; null when the service cannot answer. $profile: compose · signature. */
function mailer_sanitise(string $html, string $profile = 'compose'): ?array
{
    try {
        $r = mailer_call('/v1/sanitise', (int) current_member_id(), ['html' => $html, 'profile' => $profile], ml_on_behalf(), 30);
        return ['html' => (string) ($r['html'] ?? ''), 'text' => (string) ($r['text'] ?? '')];
    } catch (MailerRefused | MailerDown) {
        return null;
    }
}

/** Does an address deliver to this mailbox (MaluMail's list_aliases under the customer key, the list never returned)? ['delivers' => bool, 'kind' => own|alias], or a refusal's words. */
function mailer_identity_check(int $mailbox, string $address): array
{
    try {
        $r = mailer_call('/v1/mailboxes/' . $mailbox . '/identity_check', (int) current_member_id(), ['address' => $address], ml_on_behalf(), 30);
        return ['delivers' => !empty($r['delivers']), 'kind' => (string) ($r['kind'] ?? 'alias'), 'error' => null];
    } catch (MailerRefused $e) {
        return ['delivers' => false, 'kind' => 'alias', 'error' => $e->getMessage()];
    } catch (MailerDown $e) {
        return ['delivers' => false, 'kind' => 'alias', 'error' => $e->getMessage()];
    }
}
