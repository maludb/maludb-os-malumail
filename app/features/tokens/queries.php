<?php
declare(strict_types=1);

/**
 * A person's own tokens for the two read MCP servers (the kit's mcp_access_tokens, db/003; sso-shell.md "Query functions"). The value is
 * stored only as its SHA-256 and shown once, on the page that minted it.
 */

function tokens_for(PDO $pdo, int $member): array
{
    $st = $pdo->prepare("SELECT id, label, last_used_at, expires_at, revoked_at, created_at FROM mcp_access_tokens
                          WHERE member_id = :m AND scope = 'mcp' ORDER BY revoked_at IS NOT NULL, created_at DESC");
    $st->execute(['m' => $member]);
    return $st->fetchAll();
}

function token_find(PDO $pdo, int $member, int $id): ?array
{
    $st = $pdo->prepare("SELECT id, label, revoked_at FROM mcp_access_tokens WHERE id = :id AND member_id = :m AND scope = 'mcp'");
    $st->execute(['id' => $id, 'm' => $member]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Mint one: answers [id, secret, expires_at] — the secret exists only in this answer. */
function token_mint(PDO $pdo, int $member, string $name, int $days): array
{
    $secret = 'mlm_' . bin2hex(random_bytes(24));
    $st = $pdo->prepare("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, expires_at)
                         VALUES (:m, :l, :h, 'mcp', now() + make_interval(days => CAST(:d AS integer))) RETURNING id, expires_at");
    $st->execute(['m' => $member, 'l' => $name, 'h' => hash('sha256', $secret), 'd' => $days]);
    $r = $st->fetch();
    return ['id' => (int) $r['id'], 'secret' => $secret, 'expires_at' => $r['expires_at']];
}

function token_revoke(PDO $pdo, int $member, int $id): bool
{
    $st = $pdo->prepare("UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND member_id = :m AND scope = 'mcp' AND revoked_at IS NULL RETURNING id");
    $st->execute(['id' => $id, 'm' => $member]);
    return $st->fetchColumn() !== false;
}
