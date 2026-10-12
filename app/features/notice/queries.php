<?php
declare(strict_types=1);

/** The business's mail-monitoring notice (design §3; db/005–010): its text and version, and whether a person acknowledged this version. */

function notice_current(PDO $pdo): array
{
    $row = $pdo->query('SELECT notice_text, notice_version, notice_updated_at FROM ml_settings WHERE id = 1')->fetch();
    return ['text' => (string) ($row['notice_text'] ?? ''), 'version' => (int) ($row['notice_version'] ?? 1), 'updated_at' => $row['notice_updated_at'] ?? null];
}

/** When the person acknowledged the CURRENT version (null when not yet). */
function notice_acknowledged_at(PDO $pdo, int $member, int $version): ?string
{
    $st = $pdo->prepare('SELECT acknowledged_at FROM ml_notice_acknowledgements WHERE member_id = :m AND notice_version = :v');
    $st->execute(['m' => $member, 'v' => $version]);
    $at = $st->fetchColumn();
    return $at === false ? null : (string) $at;
}
