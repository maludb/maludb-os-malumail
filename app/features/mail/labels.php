<?php
declare(strict_types=1);

/**
 * LABELS (docs/build-specs/search-labels-snooze.md — slice 6; design §6.3). Labels live in this application's index only (decision 3):
 * `tags` rows of the mailbox, applied to MESSAGE KEYS through record_tags, so a conversation shows any label of any of its messages and a
 * reply arriving keeps it (decision 2). The SQL — ml_label_apply(), ml_label_save(), ml_label_delete() (db/014) — asks the wall and the
 * mutation refusals itself (triage; an agent never labels a sensitive conversation; an intake mailbox's labels are fine).
 *
 *   ML_LABEL_COLOURS      the eight swatches
 *   ml_labels_of()        a mailbox's labels with the number of conversations carrying each
 *   ml_label_one()        one label of a mailbox, or null
 *   ml_label_apply_to()   label (or unlabel) conversations: thread[] and/or a search `matching`
 */

const ML_LABEL_COLOURS = ['#dc3545' => 'Red', '#fd7e14' => 'Orange', '#ffc107' => 'Yellow', '#198754' => 'Green',
                          '#0dcaf0' => 'Teal', '#0d6efd' => 'Blue', '#6f42c1' => 'Purple', '#6c757d' => 'Grey'];

/** A mailbox's labels, by name, each with the conversations it is on: [{id, label, slug, colour, conversations}]. Memoised per request. */
function ml_labels_of(PDO $pdo, int $mailbox): array
{
    static $memo = [];
    if (isset($memo[$mailbox])) {
        return $memo[$mailbox];
    }
    $st = $pdo->prepare('SELECT g.id, g.label, g.slug, g.colour, count(DISTINCT m.thread_id) AS conversations
                           FROM mcp_labels g
                           LEFT JOIN mcp_message_labels l ON l.tag_id = g.id
                           LEFT JOIN mcp_messages m ON m.message_key_id = l.message_key_id AND m.mailbox_id = g.mailbox_id
                          WHERE g.mailbox_id = :mb AND g.archived_at IS NULL
                          GROUP BY g.id, g.label, g.slug, g.colour ORDER BY lower(g.label)');
    $st->execute(['mb' => $mailbox]);
    return $memo[$mailbox] = $st->fetchAll();
}

function ml_label_one(PDO $pdo, int $mailbox, int $label): ?array
{
    foreach (ml_labels_of($pdo, $mailbox) as $l) {
        if ((int) $l['id'] === $label) {
            return $l;
        }
    }
    return null;
}

/**
 * Label (or take the label off) the conversations $threads and/or those a search finds ($matching, at most ML_MATCHING_CAP): every message
 * key of each conversation gets it. Answers [conversations touched, rows changed]. The database refuses a level below triage, a label of
 * another mailbox, and an agent's label on a sensitive conversation (as no such message).
 */
function ml_label_apply_to(PDO $pdo, int $mailbox, array $labelIds, array $threads, ?string $matching, bool $remove, ?array &$threadIds = null): array
{
    $threadIds = [];
    $targets = ml_thread_targets($pdo, $mailbox, $threads, $matching, 'label', null, null, $threadIds);
    if ($threadIds === []) {
        refuse(422, 'Choose at least one conversation, or say what to match.');
    }
    $keys = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['message_key_id'], $targets)));
    if ($keys === []) {
        refuse(422, 'Nothing to label here.');
    }
    $changed = 0;
    $me = (int) current_member_id();
    foreach ($labelIds as $tag) {
        $changed += (int) ml_guard($pdo, static function () use ($pdo, $mailbox, $keys, $tag, $me, $remove): int {
            $st = $pdo->prepare('SELECT ml_label_apply(:mb, CAST(:keys AS bigint[]), :tag, :me, :rm)');
            $st->bindValue('mb', $mailbox, PDO::PARAM_INT);
            $st->bindValue('keys', '{' . implode(',', $keys) . '}');
            $st->bindValue('tag', $tag, PDO::PARAM_INT);
            $st->bindValue('me', $me, PDO::PARAM_INT);
            $st->bindValue('rm', $remove, PDO::PARAM_BOOL);
            $st->execute();
            return (int) $st->fetchColumn();
        });
    }
    return [count($threadIds), $changed];
}
