<?php
declare(strict_types=1);

/**
 * A mailbox's settings (delegation-access.md — slice 7): the keys the schema allows (ml_mailbox_settings_save, db/012 + db/020), their
 * defaults, and the save. The two switches (`ai_read`, `share_with_apps`) are columns of the mailbox, off for a person's until its owner
 * turns them on. Required by the settings screen and its handler.
 */

require_once dirname(__DIR__) . '/access/queries.php';

/** May the caller open this mailbox's settings: a person holding full on it; a Mail admin only on an agent's or shared mailbox (its two switches). */
function st_may_open(array $ctx): bool
{
    return $ctx['level'] === 'full' || ($ctx['kind'] !== 'person' && $ctx['manager']);
}

const ST_KEYS = ['undo_seconds', 'conversation_view', 'images', 'density', 'shortcuts', 'page_size'];

/** The mailbox's settings with the defaults filled in: [key => value]. */
function mailbox_settings_read(PDO $pdo, array $box): array
{
    $s = is_string($box['settings'] ?? null) ? (json_decode($box['settings'], true) ?: []) : (array) ($box['settings'] ?? []);
    $undo = $s['undo_seconds'] ?? db_value($pdo, 'SELECT undo_send_seconds FROM mcp_settings');
    $images = $s['images'] ?? db_value($pdo, 'SELECT images_default FROM mcp_settings');
    return [
        'undo_seconds' => (int) $undo,
        'conversation_view' => array_key_exists('conversation_view', $s) ? (bool) $s['conversation_view'] : true,
        'images' => in_array($images, ['ask', 'always'], true) ? $images : 'ask',
        'density' => ($s['density'] ?? '') === 'compact' ? 'compact' : 'comfortable',
        'shortcuts' => array_key_exists('shortcuts', $s) ? (bool) $s['shortcuts'] : true,
        'page_size' => in_array((int) ($s['page_size'] ?? 50), [25, 50, 100], true) ? (int) ($s['page_size'] ?? 50) : 50,
    ];
}

/** Save: db/012's ml_mailbox_settings_save() under ml_guard — it decides who may save what and who may flip the two switches. */
function mailbox_settings_save(PDO $pdo, int $by, int $mailbox, array $settings, ?bool $aiRead, ?bool $share): void
{
    ml_guard($pdo, static function () use ($pdo, $by, $mailbox, $settings, $aiRead, $share): void {
        $st = $pdo->prepare('SELECT ml_mailbox_settings_save(:mb, :by, CAST(:s AS jsonb), :ai, :sh)');
        $st->bindValue('mb', $mailbox, PDO::PARAM_INT);
        $st->bindValue('by', $by, PDO::PARAM_INT);
        $st->bindValue('s', json_encode((object) $settings, JSON_THROW_ON_ERROR));
        $st->bindValue('ai', $aiRead, $aiRead === null ? PDO::PARAM_NULL : PDO::PARAM_BOOL);
        $st->bindValue('sh', $share, $share === null ? PDO::PARAM_NULL : PDO::PARAM_BOOL);
        $st->execute();
    });
}

function present_mailbox_settings(array $box, array $s, bool $showSwitches): array
{
    $o = ['mailbox_id' => (int) $box['id'], 'settings' => $s];
    if ($showSwitches) {
        $o['ai_read'] = (bool) $box['ai_read'];
        $o['share_with_apps'] = (bool) $box['share_with_apps'];
    }
    return $o;
}
