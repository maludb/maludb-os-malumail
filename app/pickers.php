<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; the design system's reference is
 * record-picker.md). Phase 2 ships four sources (sso-shell.md): `member` (people), `agent`, `department` and `mailbox` — the grant form
 * of slice 7 and the new-mailbox form of slice 1 use them. Every row comes from an mcp_* view, so the wall decides what a person sees;
 * every `gate` is the right the screens that use the field apply (null = allowed, else the refusal's words).
 */
$member = static function (string $kind): array {
    return [
        'title' => $kind === 'agent' ? 'Agents' : 'People', 'noun' => $kind === 'agent' ? 'agent' : 'person', 'params' => [],
        'gate' => static fn (array $p): ?string => has_right('mail.use') ? null : right_refusal('mail.use'),
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($kind): array {
            $st = $pdo->prepare('SELECT member_id, display_name, email FROM mcp_members WHERE kind = :k ORDER BY display_name');
            $st->execute(['k' => $kind]);
            $rows = array_map(static fn (array $m): array => ['id' => (int) $m['member_id'], 'label' => $m['display_name'], 'detail' => (string) ($m['email'] ?? '')], $st->fetchAll());
            return picker_result_from_list($rows, $q, $page);
        },
        'label' => static function (PDO $pdo, int|string $id) use ($kind): ?string {
            $st = $pdo->prepare('SELECT display_name FROM mcp_members WHERE member_id = :id AND kind = :k');
            $st->execute(['id' => (int) $id, 'k' => $kind]);
            $n = $st->fetchColumn();
            return $n === false ? null : (string) $n;
        },
    ];
};
$sources = [
    'member' => $member('person'),
    'agent' => $member('agent'),
    'department' => [
        'title' => 'Departments', 'noun' => 'department', 'params' => [],
        'gate' => static fn (array $p): ?string => has_right('mail.use') ? null : right_refusal('mail.use'),
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            $rows = array_map(static fn (array $d): array => ['id' => (int) $d['department_id'], 'label' => $d['name']],
                $pdo->query('SELECT department_id, name FROM mcp_departments ORDER BY name')->fetchAll());
            return picker_result_from_list($rows, $q, $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string {
            $st = $pdo->prepare('SELECT name FROM mcp_departments WHERE department_id = :id');
            $st->execute(['id' => (int) $id]);
            $n = $st->fetchColumn();
            return $n === false ? null : (string) $n;
        },
    ],
    'mailbox' => [
        'title' => 'Mailboxes', 'noun' => 'mailbox', 'params' => [],
        'gate' => static fn (array $p): ?string => has_right('mail.use') ? null : right_refusal('mail.use'),
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            $rows = array_map(static fn (array $b): array => ['id' => (int) $b['id'], 'label' => $b['address'], 'detail' => $b['display_name'], 'badge' => $b['kind'], 'color' => $b['kind'] === 'agent' ? 'info' : 'secondary'],
                ml_rail($pdo));
            return picker_result_from_list($rows, $q, $page);
        },
        'label' => static function (PDO $pdo, int|string $id): ?string {
            foreach (ml_rail($pdo) as $b) {
                if ((int) $b['id'] === (int) $id) {
                    return (string) $b['address'];
                }
            }
            return null;
        },
    ],
];
$extra = getenv('ML_PICKER_EXTRA');                       // the proofs' own sources (never set in production)
if ($extra !== false && $extra !== '' && getenv('APP_ENV') !== 'prod' && is_file($extra)) {
    $sources += (array) require $extra;
}
return $sources;
