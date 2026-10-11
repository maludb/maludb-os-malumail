<?php
declare(strict_types=1);

/**
 * MaluMail's worker — one pass a minute from malumail-worker.timer (design §6.5, §14). It never touches a mailbox: every change to
 * mail is one IMAP command through the mail service (services/mailer/, the only holder of open credentials), and the mail service —
 * not this worker — syncs, fetches and sends (its outbox, undo and schedule). The worker keeps the application's own housekeeping —
 * the index's catch-up checks, mail turns (arriving mail that may wake an agent = one chat turn of that agent through the kernel,
 * carrying ids, never mail text), held mail expiring after held_expiry_hours (the agent told), snoozed conversations waking,
 * break-glass rows expiring, the notification outbox (in-app, mail, K6 texts), the Mail Clerk's inputs, key-usage pruning.
 * Idempotent; advisory-locked so two runs never overlap; each pass its own try (one failing never stops the next); the report is
 * printed as one JSON line, logged once as `worker.pass` (counts and sentences, never a subject, a body, an address, a token or a
 * key) and kept as a worker_passes row when the table exists (db/006+, Spaces' shape).
 *   php bin/worker.php [--passes=a,b] [--limit=200]
 * With --passes the named passes run whether or not they are due; without it every pass runs. ML_WORKER_NOW (ISO 8601) replaces the clock
 * a pass selects by; honoured only when APP_ENV is not prod. Exits 1 when a pass failed.
 *
 * PHASE 0: every pass but `key_usage_prune` is a STUB that changes nothing and answers 0 — the slice whose data it touches fills it in
 * (design §10): `index_catch_up` slice 2's; `dispatches`, `held_expiry` slice 5's; `snoozes` slice 6's; `break_glass_expiry` slice
 * 7's; `notify_outbox`, `clerk_inputs` slice 9's. Each stub names its slice. REAL from the kit: `key_usage_prune`
 * (ml_prune_key_usage, db/005).
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
require_once dirname(__DIR__) . '/app/bootstrap.php';

/** The passes, in the order they run. A new pass is a name here and a function worker_pass_<name>(PDO, int $limit, DateTimeImmutable $now): array. */
const WORKER_PASSES = ['index_catch_up', 'dispatches', 'held_expiry', 'snoozes', 'break_glass_expiry', 'notify_outbox', 'clerk_inputs', 'key_usage_prune'];

$only = [];
$limit = 200;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--passes=')) {
        $only = array_values(array_filter(explode(',', substr($a, 9))));
        if (array_diff($only, WORKER_PASSES) !== []) {
            fwrite(STDERR, 'Unknown pass: ' . implode(', ', array_diff($only, WORKER_PASSES)) . '. Passes: ' . implode(', ', WORKER_PASSES) . ".\n");
            exit(2);
        }
    } elseif (str_starts_with($a, '--limit=')) {
        $limit = max(1, min(1000, (int) substr($a, 8)));
    } else {
        fwrite(STDERR, "Usage: php bin/worker.php [--passes=a,b] [--limit=N]\n");
        exit(2);
    }
}
$now = new DateTimeImmutable();
if (($fake = getenv('ML_WORKER_NOW')) !== false && $fake !== '' && app_is_prod() === false) {
    $now = new DateTimeImmutable($fake);
}
$pdo = db();
if (!(bool) $pdo->query("SELECT pg_try_advisory_lock(hashtext('malumail_worker'))")->fetchColumn()) {
    echo json_encode(['skipped' => 'another pass holds the lock']) . "\n";
    exit(0);
}
$GLOBALS['__public_door'] = 'cron';                 // every row a pass writes is source cron, actor none
$started = microtime(true);
$report = ['at' => $now->format(DATE_ATOM), 'ran' => [], 'errors' => []];
foreach ($only === [] ? WORKER_PASSES : $only as $pass) {
    try {
        $report['ran'][$pass] = ('worker_pass_' . $pass)($pdo, $limit, $now);
    } catch (Throwable $e) {
        error_log('worker ' . $pass . ': ' . $e->getMessage());
        $report['errors'][] = $pass . ': ' . mb_substr($e->getMessage(), 0, 200);
    }
}
$report['seconds'] = round(microtime(true) - $started, 2);
log_activity($pdo, 'worker.pass', null, null, ['source' => 'cron', 'actor_member_id' => null, 'after' => $report]);
try {
    if ($pdo->query("SELECT to_regclass('public.worker_passes') IS NOT NULL")->fetchColumn()) {
        $pdo->prepare('INSERT INTO worker_passes (started_at, finished_at, steps, error) VALUES (:s, now(), :steps, :err)')
            ->execute(['s' => date(DATE_ATOM, (int) $started), 'steps' => json_encode($report['ran'], JSON_UNESCAPED_SLASHES),
                       'err' => $report['errors'] === [] ? null : implode('; ', $report['errors'])]);
    }
} catch (Throwable $e) {
    error_log('worker_passes: ' . $e->getMessage());
}
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
$pdo->query("SELECT pg_advisory_unlock(hashtext('malumail_worker'))");
exit($report['errors'] === [] ? 0 : 1);

// ---- the passes (stubs until their slice) ------------------------------------------------------------------------------------------

/** SLICE 2 — the index's catch-up checks: a mailbox whose folders the mail service has not confirmed within the hour is asked to resync (the mail service does the work; never this process). */
function worker_pass_index_catch_up(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['asked' => 0, 'stub' => 'slice 2']; }
/** SLICE 5 — agent_dispatches: arriving mail that may wake an agent (wake_from, DMARC, internal only when sent from here, not muted, taken over or paused, never a kernel intake) becomes ONE chat turn of that agent through the kernel — the mailbox, the message key and the conversation in `context`, never mail text; the primary overseer the acting member until K8; a refusal shown in the kernel's words, a 5xx backed off. */
function worker_pass_dispatches(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['sent' => 0, 'stub' => 'slice 5']; }
/** SLICE 5 — held mail older than held_expiry_hours: `expired`, logged review.expire, the agent told in its next mail turn. */
function worker_pass_held_expiry(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['expired' => 0, 'stub' => 'slice 5']; }
/** SLICE 6 — snoozed conversations whose time has come: back in the INBOX (the mail service moves them; never an intake mailbox's INBOX, which is never moved). */
function worker_pass_snoozes(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['woken' => 0, 'stub' => 'slice 6']; }
/** SLICE 7 — break-glass permission rows past expires_at (at most 30 days): revoked, logged permission.revoke, the owner told. */
function worker_pass_break_glass_expiry(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['revoked' => 0, 'stub' => 'slice 7']; }
/** SLICE 9 — notification_outbox: queued email (MaluMail's REST send) and text (K6) rows sent, failed ones retried with backoff. */
function worker_pass_notify_outbox(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['sent' => 0, 'stub' => 'slice 9']; }
/** SLICE 9 — the Mail Clerk's inputs at clerk_note_at on weekdays: held mail older than a day, unscreened mail, mailboxes near quota, sync failures — counts for its note, never mail text. */
function worker_pass_clerk_inputs(PDO $pdo, int $limit, DateTimeImmutable $now): array { return ['cut' => 0, 'stub' => 'slice 9']; }
/** ml_prune_key_usage() (db/005): minute buckets older than a day, day buckets older than 35 days. Real from the kit. */
function worker_pass_key_usage_prune(PDO $pdo, int $limit, DateTimeImmutable $now): array
{
    return ['pruned' => (int) one_value($pdo, 'SELECT ml_prune_key_usage()')];
}
