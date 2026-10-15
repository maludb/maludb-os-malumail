<?php
declare(strict_types=1);

/**
 * THE LIST (docs/build-specs/mail-list.md — slice 2, THE FIRST EXEMPLAR). Every page that lists mail reads it the way this file does:
 * ONE SQL gatherer, ml_mail_list() (db/013), behind mailbox-view, folder-view and the records tool mail_list — the wall inside it
 * (ml_level(): app.member_id as db() set it), an agent never shown a sensitive conversation, unread per person where ml_read_mode() says
 * the server's flag cannot serve. Paging is a keyset cursor `<unix ms>.<id>` on (last_message_at, id) descending; the poll keys on the
 * mailbox's change counter (mail_mailbox_changes.seq), never on timestamps.
 *
 *   mail_list_page()   one page: rows, the Older/Newer cursors, "1–50 of N", the counter the page was drawn at
 *   mail_rail()        the rail's groups (My mailbox, Shared with me, Agents I oversee, Agents) with counts; the current mailbox's
 *                      views with their counts and its folders as a tree
 *   mail_changes()     the poll: null when nothing changed (204), else what to swap
 *   mail_view_label()  a view's name
 */

const ML_PAGE_SIZES = [25, 50, 100];
/** The bulk bar's actions in Gmail's order (mail-list.md): [action, label, icon] — drawn only when the registry says the handler is built. */
const ML_BULK_ACTIONS = [
    ['mail_archive', 'Archive', 'feather-archive'], ['mail_trash', 'Delete', 'feather-trash-2'], ['mail_spam', 'Spam', 'feather-alert-octagon'],
    ['mail_not_spam', 'Not spam', 'feather-check-circle'], ['mail_mark_read', 'Mark read', 'feather-eye'], ['mail_flag', 'Star', 'feather-star'],
    ['mail_move', 'Move to', 'feather-folder'], ['mail_label', 'Label', 'feather-tag'], ['mail_snooze', 'Snooze', 'feather-clock'],
];

function mail_view_label(string $view): string
{
    return ML_VIEWS[$view][0] ?? ucfirst($view);
}

/** A cursor `<unix ms>.<id>` → [timestamptz text, id], or null when it is not one. */
function mail_cursor_parse(?string $cursor): ?array
{
    if ($cursor === null || !preg_match('/^([0-9]{1,15})\.([0-9]{1,18})$/', $cursor, $m)) {
        return null;
    }
    $ms = (int) $m[1];
    return [gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000), (int) $m[2]];
}

/** A row's own cursor (its time in unix ms and its id: the conversation, or the outbound row in Scheduled and Outbox). */
function mail_row_cursor(array $row): string
{
    return (string) $row['at_ms'] . '.' . (int) ($row['outbound_id'] ?? 0 ?: $row['thread_id']);
}

/** The mailbox's page size: its `page_size` setting (25, 50 or 100; default 50 — mail-list.md decision 5). */
function mail_page_size(array $box): int
{
    $s = is_string($box['settings'] ?? null) ? (json_decode($box['settings'], true) ?: []) : (array) ($box['settings'] ?? []);
    $n = (int) ($s['page_size'] ?? 50);
    return in_array($n, ML_PAGE_SIZES, true) ? $n : 50;
}

/** The ids of a view in order (ml_mail_list_ids — the list's own filter), at most $limit, newest first, after $before. */
function mail_list_ids(PDO $db, int $mailbox, string $view, ?int $folder, bool $unread, ?array $before, int $limit): array
{
    $st = $db->prepare('SELECT row_kind, row_id, (floor(extract(epoch FROM row_at) * 1000))::bigint AS at_ms
                          FROM ml_mail_list_ids(:mb, :view, :folder, NULL, :unread, :bat, :bid, :lim)');
    $st->bindValue('mb', $mailbox, PDO::PARAM_INT);
    $st->bindValue('view', $folder !== null ? null : $view);
    $st->bindValue('folder', $folder, $folder === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('unread', $unread, PDO::PARAM_BOOL);
    $st->bindValue('bat', $before[0] ?? null);
    $st->bindValue('bid', $before[1] ?? null, isset($before[1]) ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $st->bindValue('lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** The rows of one page from the gatherer, in the shape the templates and the JSON read use. */
function mail_list_rows(PDO $db, int $mailbox, string $view, ?int $folder, bool $unread, ?array $before, int $limit): array
{
    $st = $db->prepare('SELECT thread_id, subject, array_to_json(participants) AS participants, names, last_message_at,
                               (floor(extract(epoch FROM last_message_at) * 1000))::bigint AS at_ms, message_count, unread, flagged,
                               has_attachments, snippet, labels, snoozed_until, sensitive, agent_badge, latest_message, outbound_id,
                               outbound_status, send_after, array_to_json(hold_reasons) AS hold_reasons
                          FROM ml_mail_list(:mb, :view, :folder, NULL, :unread, :bat, :bid, :lim)');
    $st->bindValue('mb', $mailbox, PDO::PARAM_INT);
    $st->bindValue('view', $folder !== null ? null : $view);
    $st->bindValue('folder', $folder, $folder === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('unread', $unread, PDO::PARAM_BOOL);
    $st->bindValue('bat', $before[0] ?? null);
    $st->bindValue('bid', $before[1] ?? null, isset($before[1]) ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $st->bindValue('lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        foreach (['participants', 'names', 'labels', 'hold_reasons'] as $k) {
            $r[$k] = $r[$k] === null ? [] : (json_decode((string) $r[$k], true) ?: []);
        }
        foreach (['unread', 'flagged', 'has_attachments', 'sensitive'] as $k) {
            $r[$k] = $r[$k] === true || $r[$k] === 't' || $r[$k] === 1;
        }
        $r['cursor'] = mail_row_cursor($r);
        $rows[] = $r;
    }
    return $rows;
}

/**
 * One page (mail-list.md "Paging"): keyset on (last_message_at, id) descending. $before is the cursor the Older link carried; $start the
 * row number of the page's first row, carried in the links (`n`) so "26–50 of N" needs no count of what came before — when a link did
 * not carry it, it is counted once.
 */
function mail_list_page(PDO $db, int $mailbox, string $view, ?int $folder, ?string $before, bool $unread, int $limit, ?int $start = null): array
{
    $limit = max(1, min(100, $limit));
    $cur = mail_cursor_parse($before);
    $rows = mail_list_rows($db, $mailbox, $view, $folder, $unread, $cur, $limit);
    $older = null;
    if (count($rows) === $limit) {
        $last = mail_cursor_parse($rows[$limit - 1]['cursor']);
        if (mail_list_ids($db, $mailbox, $view, $folder, $unread, $last, 1) !== []) {
            $older = $rows[$limit - 1]['cursor'];
        }
    }
    if ($cur === null) {
        $start = 0;
    } elseif ($start === null || $start < 0) {
        $start = 0;
        foreach (mail_list_ids($db, $mailbox, $view, $folder, $unread, null, 10001) as $i) {
            if ([(int) $i['at_ms'], (int) $i['row_id']] >= [(int) explode('.', (string) $before)[0], $cur[1]]) {
                $start++;
            }
        }
    }
    $newer = null;
    if ($cur !== null) {
        if ($start <= $limit) {
            $newer = '';                                               // the first page
        } else {
            $ids = mail_list_ids($db, $mailbox, $view, $folder, $unread, null, $start - $limit);
            $lastId = end($ids);
            $newer = $lastId === false ? '' : $lastId['at_ms'] . '.' . $lastId['row_id'];
        }
    }
    $total = (int) db_value($db, 'SELECT ml_mail_list_total(:mb, :view, :folder, NULL, :unread)', [
        'mb' => $mailbox, 'view' => $folder !== null ? null : $view, 'folder' => $folder, 'unread' => $unread]);
    $seq = (int) db_value($db, 'SELECT ml_mailbox_seq(:mb)', ['mb' => $mailbox]);
    return ['rows' => $rows, 'older' => $older, 'newer' => $newer, 'total' => $total, 'seq' => $seq, 'start' => $start, 'limit' => $limit];
}

/** One scalar from a prepared statement (null-safe binding for the list's integer and boolean parameters). */
function db_value(PDO $db, string $sql, array $args = []): mixed
{
    $st = $db->prepare($sql);
    foreach ($args as $k => $v) {
        $st->bindValue($k, $v, match (true) { $v === null => PDO::PARAM_NULL, is_bool($v) => PDO::PARAM_BOOL, is_int($v) => PDO::PARAM_INT,
                                               default => PDO::PARAM_STR });
    }
    $st->execute();
    return $st->fetchColumn();
}

/**
 * The rail (mail-list.md "The rail"): the mailboxes the caller opens in four groups, each with its Inbox's unread count for the caller
 * (ml_unread_counts() — ml_unread_count()'s answer for every listed mailbox in one statement, db/017: the server's flag or their own marks), a dot when it is failing to sync, a pause mark when an agent's mail is
 * paused, the held count of an agent's mailbox; and for the current mailbox its views' counts (ml_mailbox_counts) and its folders.
 * A mailbox never synced shows — (the count is not known yet).
 */
function mail_rail(PDO $db, int $currentMailbox): array
{
    $me = (int) (current_member_id() ?? 0);
    // ml_unread_counts() (db/017): every mailbox's level and unread count for the caller in one statement — ml_unread_count()'s answer
    $st = $db->prepare("SELECT b.id, b.address, b.display_name, b.kind, b.owner_member_id, u.level AS my_level, b.held_count, b.sync_state,
                               b.credential_status, b.suspended_at, b.last_sync_at, b.primary_overseer_id,
                               CASE WHEN b.last_sync_at IS NULL THEN NULL ELSE u.unread END AS unread,
                               (b.kind = 'agent' AND (b.primary_overseer_id = :me
                                 OR EXISTS (SELECT 1 FROM mcp_mailbox_permissions p WHERE p.mailbox_id = b.id AND p.principal_kind = 'member'
                                               AND p.principal_id = :me AND p.level = 'full'))) AS oversee,
                               COALESCE((SELECT p.paused FROM mcp_agent_mail_policies p WHERE p.mailbox_id = b.id), false) AS paused
                          FROM mcp_mailboxes b JOIN ml_unread_counts() u ON u.mailbox_id = b.id
                         ORDER BY (b.owner_member_id = :me) DESC, CASE b.kind WHEN 'person' THEN 1 WHEN 'shared' THEN 2 ELSE 3 END, b.address
                         LIMIT 50");
    $st->execute(['me' => $me]);
    $groups = ['mine' => ['My mailbox', []], 'shared' => ['Shared with me', []], 'oversee' => ['Agents I oversee', []], 'agents' => ['Agents', []]];
    $current = null;
    foreach ($st->fetchAll() as $b) {
        $b['oversee'] = $b['oversee'] === true || $b['oversee'] === 't';
        $b['paused'] = $b['paused'] === true || $b['paused'] === 't';
        $b['failing'] = $b['credential_status'] === 'failed' || $b['sync_state'] === 'failed';
        $key = (int) $b['owner_member_id'] === $me && $me > 0 ? 'mine' : ($b['kind'] !== 'agent' ? 'shared' : ($b['oversee'] ? 'oversee' : 'agents'));
        $groups[$key][1][] = $b;
        if ((int) $b['id'] === $currentMailbox) {
            $current = $b;
        }
    }
    $counts = null;
    $folders = [];
    if ($current !== null) {
        $counts = json_decode((string) db_value($db, 'SELECT ml_mailbox_counts(:mb)', ['mb' => (int) $current['id']]), true) ?: null;
        $fs = $db->prepare("SELECT id, name, delimiter, messages_unseen FROM mcp_folders WHERE mailbox_id = :mb AND special_use IS NULL
                             AND upper(name) <> 'INBOX' ORDER BY name");
        $fs->execute(['mb' => (int) $current['id']]);
        foreach ($fs->fetchAll() as $f) {
            $d = (string) ($f['delimiter'] ?? '');
            $parts = $d !== '' ? explode($d, (string) $f['name']) : [(string) $f['name']];
            $f['depth'] = count($parts) - 1;
            $f['leaf'] = end($parts);
            $folders[] = $f;
        }
    }
    return ['groups' => array_filter($groups, static fn (array $g): bool => $g[1] !== []), 'current' => $current, 'counts' => $counts,
            'folders' => $folders];
}

/** A view's count for the rail, where it matters: Inbox unread, Drafts total, Scheduled total, Outbox failed + held. */
function mail_view_count(?array $counts, string $view): string
{
    if ($counts === null) {
        return '';
    }
    $n = match ($view) {
        'inbox' => (int) ($counts['inbox_unread'] ?? 0), 'drafts' => (int) ($counts['drafts'] ?? 0), 'scheduled' => (int) ($counts['scheduled'] ?? 0),
        'outbox' => (int) ($counts['failed'] ?? 0) + (int) ($counts['held'] ?? 0), default => 0,
    };
    return $n > 0 ? (string) $n : '';
}

/**
 * The poll (mail-list.md "The poll"): null when the mailbox's counter is still $since (the 204); otherwise what changed for the page
 * the browser holds — the page's window is re-read from the gatherer (its rows at and below $top, as many as the page holds): a changed
 * row on the page is swapped in place, a page row no longer in the window (gone, archived, moved up by a new message) is removed, and
 * conversations newer than $top are counted for the "N new" bar (they never shift the page — decision 4).
 */
function mail_changes(PDO $db, int $mailbox, int $since, string $view, ?int $folder, array $pageThreadIds, ?string $top = null,
                      int $limit = 50, bool $unread = false): ?array
{
    $seq = db_value($db, 'SELECT ml_mailbox_seq(:mb)', ['mb' => $mailbox]);
    if ($seq === null || $seq === false) {
        return ['not_found' => true];
    }
    $seq = (int) $seq;
    if ($seq === $since) {
        return null;
    }
    $page = array_values(array_unique(array_map('intval', $pageThreadIds)));
    $changed = [];
    $st = $db->prepare('SELECT thread_id, gone FROM ml_mailbox_changed(:mb, :since, 1000)');
    $st->execute(['mb' => $mailbox, 'since' => $since]);
    foreach ($st->fetchAll() as $c) {
        $changed[(int) $c['thread_id']] = $c['gone'] === true || $c['gone'] === 't';
    }
    $topCur = mail_cursor_parse($top);
    $rowsOut = [];
    $gone = [];
    $new = 0;
    if ($topCur !== null) {
        $window = mail_list_rows($db, $mailbox, $view, $folder, $unread, [$topCur[0], $topCur[1] + 1], max(1, min(100, $limit)));
        $inWindow = [];
        foreach ($window as $r) {
            $inWindow[(int) ($r['outbound_id'] ?? 0 ?: $r['thread_id'])] = $r;
        }
        foreach ($page as $id) {
            if (!isset($inWindow[$id])) {
                $gone[] = $id;
            } elseif (isset($changed[$id])) {
                $rowsOut[] = $inWindow[$id];
            }
        }
        $topMs = (int) explode('.', (string) $top)[0];
        foreach (mail_list_ids($db, $mailbox, $view, $folder, $unread, null, 100) as $i) {
            if ([(int) $i['at_ms'], (int) $i['row_id']] > [$topMs, $topCur[1]]) {
                $new++;
            }
        }
    } else {
        foreach ($page as $id) {
            if (($changed[$id] ?? false) === true) {
                $gone[] = $id;
            }
        }
        $new = count(mail_list_ids($db, $mailbox, $view, $folder, $unread, null, 100));
    }
    $unreadCount = db_value($db, 'SELECT ml_unread_count(:mb)', ['mb' => $mailbox]);
    return ['seq' => $seq, 'unread' => $unreadCount === null ? 0 : (int) $unreadCount, 'rows' => $rowsOut, 'gone' => $gone, 'new' => $new];
}

/** The list's date: today the time, this year the day and month, else the date with its year (in the person's time zone). */
function mail_list_date(?string $ts, string $tz): string
{
    if ($ts === null || $ts === '') {
        return '';
    }
    $zone = new DateTimeZone($tz !== '' ? $tz : 'UTC');
    $d = (new DateTimeImmutable($ts))->setTimezone($zone);
    $now = new DateTimeImmutable('now', $zone);
    if ($d->format('Y-m-d') === $now->format('Y-m-d')) {
        return $d->format('g:i A');
    }
    return $d->format('Y') === $now->format('Y') ? $d->format('M j') : $d->format('n/j/y');
}

/** Who a conversation is with, as the row shows it: names (addresses where none), the mailbox's own address as "me", a count. */
function mail_participants(array $row, string $ownAddress): string
{
    $own = strtolower($ownAddress);
    $names = [];
    $me = false;
    foreach ($row['participants'] as $a) {
        $a = strtolower((string) $a);
        if ($a === $own) {
            $me = true;
            continue;
        }
        $n = (string) ($row['names'][$a] ?? '');
        $names[] = $n !== '' ? preg_replace('/\s+.*/', '', $n) : $a;
        if (count($names) >= 3) {
            break;
        }
    }
    if ($me) {
        $names[] = 'me';
    }
    $out = $names === [] ? '(no one)' : implode(', ', array_unique($names));
    return $out;
}

/** The bulk bar's buttons: the actions whose handler is built (the registry's flag — decision 3). */
function mail_bulk_actions(): array
{
    static $actions = null;
    if ($actions === null) {
        $reg = json_decode((string) @file_get_contents(APP_ROOT . '/mcp/action_registry.json'), true)['actions'] ?? [];
        $actions = [];
        foreach (ML_BULK_ACTIONS as [$name, $label, $icon]) {
            if (!empty($reg[$name]['built'])) {
                $actions[] = ['name' => $name, 'label' => $label, 'icon' => $icon, 'endpoint' => (string) ($reg[$name]['endpoint'] ?? '')];
            }
        }
    }
    return $actions;
}

/** A row for the JSON read and the records tool: the list's fields, never more. */
function mail_row_json(array $r): array
{
    return [
        'thread_id' => $r['thread_id'] === null ? null : (int) $r['thread_id'], 'subject' => $r['subject'], 'participants' => $r['participants'],
        'last_message_at' => $r['last_message_at'], 'message_count' => (int) $r['message_count'], 'unread' => $r['unread'],
        'starred' => $r['flagged'], 'has_attachments' => $r['has_attachments'], 'snippet' => $r['snippet'], 'labels' => $r['labels'],
        'snoozed_until' => $r['snoozed_until'], 'agent_badge' => $r['agent_badge'], 'message' => $r['latest_message'] === null ? null : (int) $r['latest_message'],
        'outbound_id' => $r['outbound_id'] === null ? null : (int) $r['outbound_id'], 'outbound_status' => $r['outbound_status'],
        'send_after' => $r['send_after'], 'hold_reasons' => $r['hold_reasons'], 'cursor' => $r['cursor'],
    ];
}

/** The mailbox.view access-log row: a list view by anyone but the owner, at most once per mailbox per person per half hour (§3). */
function mail_log_list_view(PDO $db, int $mailbox, string $view): void
{
    $me = (int) (current_member_id() ?? 0);
    if ($me > 0 && db_bool($db, "SELECT ml_access_should_log(:mb, :me, 'mailbox.view')", ['mb' => $mailbox, 'me' => $me])) {
        log_activity($db, 'mailbox.view', 'mailbox', $mailbox, ['after' => ['view' => $view, 'door' => 'web']]);
    }
}

/**
 * The two screens' one body (html/m/view.php, html/m/folder.php): the page (or, for Older / Newer / the chip, #mail-list alone), the
 * JSON read, the access log. $box is ml_require_mailbox()'s row (404 already answered below view).
 */
function mail_list_respond(PDO $pdo, array $box, string $view, ?array $folder): never
{
    $id = (int) $box['id'];
    $screen = $folder !== null ? 'folder-view' : 'mailbox-view';
    $unread = request_string('unread') === '1';
    $before = request_string('before');
    if ($before !== '' && mail_cursor_parse($before) === null) {
        refuse(422, 'That page marker is not one this list made: open the list again.');
    }
    $n = request_integer('n');
    $page = mail_list_page($pdo, $id, $view, $folder !== null ? (int) $folder['id'] : null, $before !== '' ? $before : null, $unread,
                           mail_page_size($box), $n);
    if (!is_htmx_request() || ($_SERVER['HTTP_HX_TARGET'] ?? '') !== 'mail-list') {
        log_screen_view($pdo, $screen, $id);
    }
    mail_log_list_view($pdo, $id, $folder !== null ? 'folder' : $view);
    if (wants_json()) {
        respond_screen(['mailbox_id' => $id, 'view' => $folder !== null ? 'folder' : $view, 'folder_id' => $folder !== null ? (int) $folder['id'] : null,
                        'rows' => array_map('mail_row_json', $page['rows']), 'older' => $page['older'], 'newer' => $page['newer'],
                        'total' => $page['total'], 'more_than' => $page['total'] > 10000 ? 10000 : null, 'start' => $page['start'],
                        'change_seq' => $page['seq']]);
    }
    $status = null;
    if ($page['rows'] === [] && in_array((string) $box['sync_state'], ['new', 'syncing'], true) && ($box['credential_status'] ?? null) === 'ok') {
        try {
            $status = mailer_call('/v1/mailboxes/' . $id . '/status', (int) current_member_id(), [], null, 5);
        } catch (MailerRefused | MailerDown) {
            $status = null;
        }
    }
    $settings = is_string($box['settings'] ?? null) ? (json_decode($box['settings'], true) ?: []) : [];
    $poll = $pdo->query('SELECT poll_visible_seconds, poll_hidden_seconds FROM ml_settings WHERE id = 1')->fetch(PDO::FETCH_NUM) ?: [15, 60];
    $title = $folder !== null ? (string) $folder['name'] : mail_view_label($view);
    $data = ['box' => $box, 'view' => $view, 'folder' => $folder, 'page' => $page, 'unread' => $unread, 'title' => $title, 'status' => $status,
             'tz' => member_timezone(), 'poll' => [(int) $poll[0], (int) $poll[1]],
             'density' => ($settings['density'] ?? '') === 'compact' ? 'compact' : 'comfortable',
             'base' => $folder !== null ? '/m/' . $id . '/f/' . (int) $folder['id'] : '/m/' . $id . '/v/' . $view,
             // an action's landing (reading.md, slice 3): the notice and, after an archive / move / trash / spam, the Undo
             'notice' => function_exists('mail_action_notice') ? mail_action_notice($_GET['notice'] ?? null, (int) ($_GET['n'] ?? 0)) : null,
             'undo' => isset($_GET['undo']) ? array_values(array_filter(array_map('intval', explode(',', (string) $_GET['undo'])))) : null];
    $count = $view === 'inbox' && $folder === null ? (int) db_value($pdo, 'SELECT ml_unread_count(:mb)', ['mb' => $id]) : 0;
    $pageTitle = ($count > 0 ? '(' . $count . ') ' : '') . $title . ' — ' . $box['address'];
    if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'mail-list') {
        header('Vary: HX-Request');
        header('HX-Title: ' . rawurlencode($pageTitle . ' · ' . app_name()));
        echo view('mail/partials/rows.php', $data);
        exit;
    }
    render_screen($pageTitle, view('mail/list.php', $data), ['screen' => $screen, 'entity' => 'mailbox', 'recordId' => $id, 'mailboxId' => $id]);
    exit;
}
