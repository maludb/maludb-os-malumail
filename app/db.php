<?php
declare(strict_types=1);

/** PostgreSQL connection (the malumail_rw role). One PDO per request — never persistent: prefork's workers against max_connections = 100
 * shared by the estate (design §8, record §6.1); app.member_id set from the session. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '5432'), env('DB_NAME', 'malumail'));
    $pdo = new PDO($dsn, env('DB_USER', 'malumail_rw'), (string) env('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    db_apply_context($pdo);
    return $pdo;
}

/** Connection-scoped acting member (memory.md §1). Anonymous = '' and every rule denies. */
function db_apply_context(PDO $pdo): void
{
    $memberId = isset($_SESSION['member_id']) ? (string) ((int) $_SESSION['member_id']) : '';
    $stmt = $pdo->prepare('SELECT set_config(?, ?, false)');
    $stmt->execute(['app.member_id', $memberId]);
    $pdo->exec("SET TIME ZONE 'UTC'");
}

/** One boolean from a gate function in SQL — the same rule the views enforce. */
function db_bool(PDO $pdo, string $sql, array $args = []): bool
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return (bool) $st->fetchColumn();
}

/** A database error in words a person can act on: only our own RAISE (P0001) is shown. */
function db_message(Throwable $e, string $fallback): string
{
    error_log('db error: ' . $e->getMessage());
    if (!($e instanceof PDOException) || !in_array((string) $e->getCode(), DB_SENTENCE_CODES, true)) {
        return $fallback;
    }
    $text = $e->getMessage();
    $at = strpos($text, 'ERROR:');
    if ($at === false) {
        return $fallback;
    }
    $text = trim((string) (preg_split('/\R|CONTEXT:|DETAIL:|HINT:/', substr($text, $at + 6))[0] ?? ''));
    // PostgreSQL's own constraint text is not a sentence for a person: only a trigger's RAISE is (the schema raises sentences)
    if ($text === '' || preg_match('/violates (check|unique|foreign key|not-null) constraint|duplicate key value|null value in column/i', $text)) {
        return $fallback;
    }
    return $text;
}

/**
 * The codes the schema's triggers and functions raise with a sentence for a person: our own RAISE (P0001), a rule refused
 * (check_violation), a name or key another record holds (unique_violation), a referent that does not exist (foreign_key_violation), a
 * record that does not (no_data_found). Carried from Sales CRM's kit (found in its Phase 2, 2026-10-09: a kit that read only P0001
 * answered 500 to every other refusal; Inventory found the check_violation half the same day).
 */
const DB_SENTENCE_CODES = ['P0001', '23514', '23505', '23503', 'P0002', '42501', '22023'];   // + insufficient_privilege and invalid_parameter_value: the schema RAISEs sentences under them (slice 3)

/** The first column of the first row, or null. */
function one_value(PDO $pdo, string $sql, array $args = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}
