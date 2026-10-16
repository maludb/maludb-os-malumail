<?php
declare(strict_types=1);

/**
 * SEARCH (docs/build-specs/search-labels-snooze.md — slice 6; design §6.3). Gmail's operators over the index: one mailbox, or every
 * mailbox the person can open. The SQL is ml_search() (db/014, the wall inside it — an agent never finds a sensitive conversation, an
 * unknown operator is searched as words and named by ml_search_unknown()); this file makes conversations of its message rows.
 *
 *   ml_search_page()       a page of conversations: each conversation once, at its NEWEST matching message, newest first; the best-ranked
 *                          message's snippet; how many of its messages matched; the cursor `<µs>.<message id>` of the last one
 *   ml_search_help()       the operators for the help section
 *   ml_snooze_choices()    the five snooze times in the person's time zone, as UTC instants
 *
 * Paging by conversation without a repeat or a gap: a conversation is represented by its newest matching message, so the walk
 * (ml_search's cursor form, 500 messages at a time, newest first) meets every conversation first at its representative and the cursor
 * is a position in that order. A page walks from the newest again (decision 3) — bounded to ML_SEARCH_WALK messages; past that the page
 * says to narrow the search.
 */

const ML_SEARCH_WALK = 5000;
const ML_SEARCH_PAGE = 50;

/** The operators, for the page's help section: [operator, what it finds, an example]. */
function ml_search_help(): array
{
    return [
        ['from:', 'sender (address or name)', 'from:ada'], ['to:', 'a To recipient', 'to:sales@'], ['cc:', 'a Cc recipient', 'cc:ada'], ['bcc:', 'a Bcc recipient', 'bcc:ada'],
        ['subject:', 'words of the subject', 'subject:invoice'], ['filename:', 'an attachment\'s name', 'filename:report.pdf'],
        ['label:', 'a label', 'label:work/clients'], ['has:attachment', 'mail with a file', 'has:attachment'], ['has:label · has:nolabel', 'mail with or without any label', 'has:nolabel'],
        ['is:unread · is:read', 'read state (yours where reading is per person)', 'is:unread'], ['is:starred · is:answered', 'stars, answered', 'is:starred'],
        ['is:snoozed · is:muted', 'conversations snoozed or muted', 'is:snoozed'], ['in:', 'a folder: inbox sent trash spam archive snoozed anywhere', 'in:sent'],
        ['after: · before:', 'a date (YYYY-MM-DD)', 'after:2026-01-01'], ['older_than: · newer_than:', 'a period: 7d, 3m, 1y', 'newer_than:7d'],
        ['larger: · smaller:', 'a size: 500K, 2M', 'larger:2M'], ['"a phrase"', 'the exact words', '"quarterly review"'], ['-term', 'leave out what matches', '-from:newsletter'],
    ];
}

/** A cursor `<µs>.<id>` → [µs, id] or null. */
function ml_search_cursor(?string $c): ?array
{
    return $c !== null && preg_match('/^([0-9]{1,18})\.([0-9]{1,18})$/', $c, $m) ? [(int) $m[1], (int) $m[2]] : null;
}

/**
 * The conversations a search finds, one page: ['rows' => […], 'next' => cursor|null, 'unknown' => ['foo:'], 'capped' => bool,
 * 'error' => string|null, 'total' => the matching conversations walked]. A query ml_search() refuses (a malformed date, period or size)
 * answers 'error' in the database's sentence and no rows. $mailbox null = every mailbox the caller opens.
 */
function ml_search_page(?int $mailbox, string $q, ?string $before, int $limit = ML_SEARCH_PAGE): array
{
    $pdo = db();
    $q = trim($q);
    $out = ['rows' => [], 'next' => null, 'unknown' => [], 'capped' => false, 'error' => null, 'total' => 0];
    if ($q === '') {
        return $out;
    }
    $limit = max(1, min(100, $limit));
    $out['unknown'] = ml_pg_text_array((string) db_value($pdo, 'SELECT ml_search_unknown(:q)', ['q' => $q]));
    // the walk: ml_search's CURSOR form, 500 messages a statement, each chunk starting where the last ended — (received_at, id) < (before, id)
    // costs the rows it returns, where an offset re-sorts everything it skips (decision 1; measured in the spec's Built and proven)
    $walk = $pdo->prepare('SELECT message_id, thread_id, mailbox_id, received_at, (extract(epoch FROM received_at) * 1000000)::bigint AS us, rank
                             FROM ml_search(CAST(:q AS text), CAST(:mb AS bigint), 500, CAST(:bat AS timestamptz), CAST(:bid AS bigint))');
    $reps = [];                                                  // thread key => [message id, µs, mailbox, thread, matched, best rank, best message]
    $seen = 0;
    $bat = null;
    $bid = null;
    try {
        while ($seen < ML_SEARCH_WALK) {
            $walk->execute(['q' => $q, 'mb' => $mailbox, 'bat' => $bat, 'bid' => $bid]);
            $rows = $walk->fetchAll();
            foreach ($rows as $r) {
                $k = $r['thread_id'] !== null ? 't' . $r['thread_id'] : 'm' . $r['message_id'];
                if (!isset($reps[$k])) {
                    $reps[$k] = ['message' => (int) $r['message_id'], 'us' => (int) $r['us'], 'mailbox' => (int) $r['mailbox_id'],
                                 'thread' => $r['thread_id'] === null ? null : (int) $r['thread_id'], 'matched' => 0, 'rank' => -1.0, 'best' => (int) $r['message_id']];
                }
                $reps[$k]['matched']++;
                if ((float) $r['rank'] > $reps[$k]['rank']) {
                    $reps[$k]['rank'] = (float) $r['rank'];
                    $reps[$k]['best'] = (int) $r['message_id'];
                }
            }
            $seen += count($rows);
            if (count($rows) < 500) {
                break;
            }
            $last = end($rows);
            $bat = $last['received_at'];
            $bid = (int) $last['message_id'];
        }
    } catch (PDOException $e) {
        if (in_array((string) $e->getCode(), ['22007', '22008', '22023', '22P02', '22003'], true)) {
            $out['error'] = db_message($e, 'A date is YYYY-MM-DD, a period 7d, 3m or 1y, a size 500K or 2M: one of those could not be read.');
            return $out;
        }
        throw $e;
    }
    $out['capped'] = $seen >= ML_SEARCH_WALK;
    $reps = array_values($reps);                                 // already newest first (the walk's order)
    $out['total'] = count($reps);
    $cur = ml_search_cursor($before);
    if ($cur !== null) {
        $reps = array_values(array_filter($reps, static fn (array $r): bool => $r['us'] < $cur[0] || ($r['us'] === $cur[0] && $r['message'] < $cur[1])));
    }
    $more = count($reps) > $limit;
    $reps = array_slice($reps, 0, $limit);
    $out['rows'] = ml_search_rows($pdo, $reps);
    $out['next'] = $more && $reps !== [] ? end($reps)['us'] . '.' . end($reps)['message'] : null;
    return $out;
}

/** The reps made into the rows the page shows (the list's row shape, plus the mailbox, the matched count and the matching snippet). */
function ml_search_rows(PDO $pdo, array $reps): array
{
    if ($reps === []) {
        return [];
    }
    // the planner prices ml_thread_state() at 1,000 rows a call and JIT-compiles the statement: 250 ms to build 50 rows. This request's connection only.
    $pdo->exec('SET jit = off');
    $me = (int) (current_member_id() ?? 0);
    $threads = array_values(array_filter(array_map(static fn (array $r): ?int => $r['thread'], $reps)));
    $by = [];
    if ($threads !== []) {
        // reading mode once per mailbox, not once per conversation (ml_read_mode asks the wall each time)
        $behalf = ml_on_behalf();
        $viewers = [];
        foreach (array_unique(array_column($reps, 'mailbox')) as $b) {
            if ((string) db_value($pdo, 'SELECT ml_read_mode(:mb, :me, :b)', ['mb' => (int) $b, 'me' => $me, 'b' => $behalf]) === 'viewer_read') {
                $viewers[] = (int) $b;
            }
        }
        // literal integer lists (never user text): a generic prepared plan chose a scan for the names subquery — 400 ms for 50 conversations
        $st = $pdo->prepare("SELECT t.id AS thread_id, t.mailbox_id, t.subject, array_to_json(t.participants) AS participants, t.last_message_at, t.message_count, t.flagged,
                                    t.has_attachments, (SELECT jsonb_object_agg(n.a, n.nm) FROM (SELECT DISTINCT ON (lower(m.from_address::text)) lower(m.from_address::text) AS a, m.from_name AS nm
                                          FROM mail_messages m WHERE m.thread_id = t.id AND m.from_name IS NOT NULL ORDER BY lower(m.from_address::text), m.received_at DESC) n) AS names,
                                    ml_thread_unread_for(t.id, t.mailbox_id = ANY ('{" . implode(',', $viewers) . "}'::bigint[]), " . (int) $me . ") AS unread,
                                    COALESCE((SELECT jsonb_agg(DISTINCT jsonb_build_object('id', g.id, 'label', g.label, 'colour', g.colour))
                                                FROM mail_messages m JOIN record_tags rt ON rt.record_type = 'mail_message' AND rt.record_id = m.message_key_id
                                                JOIN tags g ON g.id = rt.tag_id AND g.archived_at IS NULL WHERE m.thread_id = t.id), '[]'::jsonb) AS labels,
                                    st.sensitive, st.snoozed_until
                               FROM mail_threads t CROSS JOIN LATERAL ml_thread_state(t.id) st WHERE t.id = ANY ('{" . implode(',', $threads) . "}'::bigint[])");
        $st->execute();
        foreach ($st->fetchAll() as $r) {
            $by[(int) $r['thread_id']] = $r;
        }
    }
    $mids = array_values(array_unique(array_merge(array_column($reps, 'best'), array_column($reps, 'message'))));
    $snip = [];
    // the threads and messages below came out of ml_search(), which is the wall: base tables here, not the walled views a second time
    $ms = $pdo->prepare('SELECT m.id, m.subject, m.snippet, m.from_address, m.from_name, m.received_at, m.mailbox_id, m.seen, m.flagged, m.has_attachments
                           FROM mail_messages m WHERE m.id = ANY (CAST(:ids AS bigint[]))');
    $ms->execute(['ids' => '{' . implode(',', $mids) . '}']);
    foreach ($ms->fetchAll() as $r) {
        $snip[(int) $r['id']] = $r;
    }
    $boxes = [];
    foreach (array_unique(array_column($reps, 'mailbox')) as $b) {
        $boxes[$b] = db()->query('SELECT address, display_name FROM mcp_mailboxes WHERE id = ' . (int) $b)->fetch() ?: ['address' => '', 'display_name' => ''];
    }
    $rows = [];
    foreach ($reps as $r) {
        $t = $r['thread'] !== null ? ($by[$r['thread']] ?? null) : null;
        $best = $snip[$r['best']] ?? [];
        $rep = $snip[$r['message']] ?? [];
        $row = [
            'thread_id' => $r['thread'], 'mailbox_id' => $r['mailbox'], 'mailbox_address' => (string) ($boxes[$r['mailbox']]['address'] ?? ''),
            'subject' => $t['subject'] ?? ($rep['subject'] ?? null),
            'participants' => $t !== null ? (json_decode((string) $t['participants'], true) ?: []) : array_filter([(string) ($rep['from_address'] ?? '')]),
            'names' => $t !== null ? (json_decode((string) ($t['names'] ?? '{}'), true) ?: []) : [],
            'last_message_at' => $t['last_message_at'] ?? ($rep['received_at'] ?? null), 'message_count' => (int) ($t['message_count'] ?? 1),
            'unread' => $t !== null ? ($t['unread'] === true || $t['unread'] === 't') : (isset($rep['seen']) ? !($rep['seen'] === true || $rep['seen'] === 't') : false),
            'flagged' => $t !== null ? ($t['flagged'] === true || $t['flagged'] === 't') : false,
            'has_attachments' => $t !== null ? ($t['has_attachments'] === true || $t['has_attachments'] === 't') : false,
            'labels' => $t !== null ? (json_decode((string) $t['labels'], true) ?: []) : [], 'snoozed_until' => $t['snoozed_until'] ?? null,
            'sensitive' => $t !== null && ($t['sensitive'] === true || $t['sensitive'] === 't'),
            'snippet' => (string) ($best['snippet'] ?? ''), 'matched' => $r['matched'], 'message' => $r['best'], 'match_us' => $r['us'],
            'cursor' => $r['us'] . '.' . $r['message'],
        ];
        $rows[] = $row;
    }
    return $rows;
}

/** A row for the JSON read and the records tool. */
function ml_search_row_json(array $r): array
{
    return ['thread_id' => $r['thread_id'], 'mailbox_id' => $r['mailbox_id'], 'mailbox' => $r['mailbox_address'], 'subject' => $r['subject'],
            'participants' => array_values($r['participants']), 'last_message_at' => json_ts($r['last_message_at']), 'message_count' => $r['message_count'],
            'matched' => $r['matched'], 'message' => $r['message'], 'unread' => $r['unread'], 'starred' => $r['flagged'], 'snippet' => $r['snippet'],
            'labels' => $r['labels'], 'cursor' => $r['cursor']];
}

/**
 * The snooze choices in the person's time zone (decision 6): [key, label, DateTimeImmutable in UTC, "Sat 8:00 AM"]. Later today (18:00)
 * only while it is before six; Tomorrow 08:00; This weekend (Saturday 08:00 — on a weekend, next Saturday); Next week (Monday 08:00).
 */
function ml_snooze_choices(string $tz, ?DateTimeImmutable $now = null): array
{
    $zone = new DateTimeZone($tz !== '' ? $tz : 'UTC');
    $now = ($now ?? new DateTimeImmutable('now'))->setTimezone($zone);
    $today = $now->setTime(0, 0);
    $make = static function (string $key, string $label, DateTimeImmutable $local): array {
        return ['key' => $key, 'label' => $label, 'at' => $local->setTimezone(new DateTimeZone('UTC')), 'text' => $local->format('D g:i A')];
    };
    $out = [];
    $six = $today->setTime(18, 0);
    if ($now < $six) {
        $out[] = $make('later', 'Later today', $six);
    }
    $out[] = $make('tomorrow', 'Tomorrow', $today->modify('+1 day')->setTime(8, 0));
    $dow = (int) $today->format('N');                              // 1 Monday … 7 Sunday
    $out[] = $make('weekend', 'This weekend', $today->modify('+' . ($dow >= 6 ? 7 - $dow + 6 : 6 - $dow) . ' days')->setTime(8, 0));
    $out[] = $make('nextweek', 'Next week', $today->modify('+' . (8 - $dow) . ' days')->setTime(8, 0));
    return $out;
}
