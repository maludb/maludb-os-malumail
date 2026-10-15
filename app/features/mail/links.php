<?php
declare(strict_types=1);

/**
 * The signed links of the two public doors, /p/ and /img/ (docs/build-specs/reading-and-rendering.md §3–§4; mail-service.md §4,
 * decision 3): ONE key, ML_PART_KEY, which the mail service uses to mint links as it sanitises (cid: images, image links) and PHP uses
 * for the links it renders itself (attachment rows, the Original's download); PHP's doors are the only verifiers.
 *
 *   {signed} = base64url(JSON {k: "part"|"img", m: mailbox id, r: "folder.uidvalidity.uid", p: part (or the image's index), a: member id,
 *                              e: unix expiry, u?: the image's URL}) "." hex HMAC-SHA256(ML_PART_KEY, the base64url text)
 *
 * Valid one hour; bound to the member and the message; the member's level on the mailbox is re-checked by the door on every use.
 */

const ML_LINK_TTL = 3600;

function ml_part_key(): string
{
    $k = (string) env('ML_PART_KEY', '');
    if (strlen($k) < 32) {
        throw new RuntimeException('ML_PART_KEY is not configured.');
    }
    return $k;
}

function ml_b64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** A signed link token for a part (kind `part`) or a remote image (kind `img`, with its URL). */
function ml_part_sign(string $kind, int $mailbox, string $ref, string $part, int $member, ?string $url = null, int $ttl = ML_LINK_TTL): string
{
    $body = ['a' => $member, 'e' => time() + $ttl, 'k' => $kind, 'm' => $mailbox, 'p' => $part, 'r' => $ref];
    if ($url !== null) {
        $body['u'] = $url;
    }
    ksort($body);
    $text = ml_b64url(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return $text . '.' . hash_hmac('sha256', $text, ml_part_key());
}

/** The link's body when its MAC and expiry hold (constant-time), else null. */
function ml_part_verify(string $token): ?array
{
    if (!preg_match('/^([A-Za-z0-9_-]{10,4000})\.([0-9a-f]{64})$/', $token, $m)) {
        return null;
    }
    [$_, $text, $mac] = $m;
    if (!hash_equals(hash_hmac('sha256', $text, ml_part_key()), $mac)) {
        return null;
    }
    $json = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    $body = $json === false ? null : json_decode($json, true);
    if (!is_array($body) || !isset($body['k'], $body['m'], $body['r'], $body['a'], $body['e'])) {
        return null;
    }
    if ((int) $body['e'] < time() || !in_array($body['k'], ['part', 'img'], true)) {
        return null;
    }
    if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', (string) $body['r'])) {
        return null;
    }
    return ['k' => (string) $body['k'], 'm' => (int) $body['m'], 'r' => (string) $body['r'], 'p' => (string) ($body['p'] ?? ''),
            'a' => (int) $body['a'], 'e' => (int) $body['e'], 'u' => isset($body['u']) ? (string) $body['u'] : null];
}

/**
 * The door's gate (the token is the authority — no cookie inside the opaque-origin frame): the member it names is still active and
 * still opens the mailbox at `view` NOW (ml_level_as, re-checked on every use). Anything else is a 404 — never a hint.
 */
function ml_part_admit(PDO $pdo, array $link): bool
{
    $st = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM members WHERE id = :a AND status = 'active')
                            AND ml_level_rank(ml_level_as(:m, :a, NULL)) >= 1");
    $st->execute(['a' => $link['a'], 'm' => $link['m']]);
    return (bool) $st->fetchColumn();
}

/** A file name safe for a Content-Disposition: no path, no control characters, at most 150 characters; a fallback when empty. */
function ml_safe_filename(?string $name, string $fallback = 'attachment'): string
{
    $n = preg_replace('/[\x00-\x1f\x7f\/\\\\]+/', '', (string) $name) ?? '';
    $n = trim($n, " .");
    $n = mb_substr($n, 0, 150);
    return $n === '' ? $fallback : $n;
}

/** The four MIME types the door shows inline, by the bytes' magic, never by a declared type. */
function ml_sniff_raster(string $head): ?string
{
    if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
        return 'image/png';
    }
    if (str_starts_with($head, "\xff\xd8\xff")) {
        return 'image/jpeg';
    }
    if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
        return 'image/gif';
    }
    if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return null;
}
