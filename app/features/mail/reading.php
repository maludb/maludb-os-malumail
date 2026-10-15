<?php
declare(strict_types=1);

/**
 * READING (docs/build-specs/reading.md — slice 3, THE SECOND EXEMPLAR; reading-and-rendering.md the three layers). Every later page
 * that shows mail copies this: the conversation from the index (mcp_messages, the wall in the view), each OPEN message's body asked of
 * the mail service once (`fetch`, form html — cached there, so the sandboxed body response that follows costs no second read), the
 * banners from the index and the fetch, the attachments through the signed part door, and the eleven actions through ONE runner,
 * mail_action_run(): the targets from ml_thread_targets() (a conversation's messages in the folders the op touches, or every
 * conversation a search finds), ml_mutation_refusal() asked here first so the page can say why, the mail service's `mutate` (one call,
 * the targets grouped by folder there), one activity row per request with the count, the landing with Undo where Gmail has one.
 *
 *   ml_thread_page()      the conversation: messages oldest first, states, labels, which are open
 *   ml_message_fetch()    one message's fetch (html · plain · headers · text) through the mail service; null when it cannot answer
 *   ml_thread_targets()   the message rows an action touches, for an op, from thread[] or `matching`
 *   ml_mail_act()         the refusals, then the mail service's mutate; {count, refused, moved, to_folder}
 *   ml_open_marks()       what opening marks read: store_seen (the server) or viewer_read (this person only)
 *   mail_action_run()     the eleven handlers' one body
 */

require_once __DIR__ . '/links.php';

/** What each op touches (reading.md "Query functions": the rule per op), as a filter on a message row's folder. */
const ML_OP_RULES = [
    'archive' => ['only' => ['inbox']], 'spam' => ['not' => ['sent', 'drafts', 'junk']], 'not_spam' => ['only' => ['junk']],
    'delete_forever' => ['only' => ['trash', 'junk']], 'trash' => ['not' => ['drafts', 'trash']], 'move' => ['not' => ['drafts', 'sent']],
    'seen' => ['not' => []], 'unseen' => ['not' => ['sent', 'drafts']], 'flag' => ['not' => []], 'unflag' => ['not' => []],
    'seen_viewer' => ['not' => ['sent', 'drafts']], 'unseen_viewer' => ['not' => ['sent', 'drafts']],   // a view holder's own marks (mail_viewer_reads)
];
const ML_OP_VERB = ['archive' => 'Archived', 'move' => 'Moved', 'trash' => 'Deleted', 'spam' => 'Marked as spam', 'not_spam' => 'Marked not spam',
                    'flag' => 'Starred', 'unflag' => 'Unstarred', 'seen' => 'Marked read', 'unseen' => 'Marked unread', 'delete_forever' => 'Deleted forever',
                    'trash_empty' => 'Emptied'];
const ML_OP_NOTHING = ['archive' => 'Nothing to archive here.', 'move' => 'Nothing to move here.', 'trash' => 'Nothing to delete here.', 'spam' => 'Nothing to mark as spam here.',
                       'not_spam' => 'Nothing here is in Spam.', 'flag' => 'Nothing to star here.', 'unflag' => 'Nothing to unstar here.', 'seen' => 'Nothing unread here.',
                       'unseen' => 'Nothing to mark unread here.', 'delete_forever' => 'Nothing to delete here: delete forever takes mail from Trash or Spam.'];
const ML_MATCHING_CAP = 1000;

/** The person behind an agent's run (K38), when the kernel said so; null otherwise. */
function ml_on_behalf(): ?int
{
    $f = $GLOBALS['__run_facts'] ?? null;
    $id = is_array($f) ? (int) ($f['requested_by_member_id'] ?? 0) : 0;
    return $id > 0 ? $id : null;
}

/** Is an action built (the registry's flag — reading.md decision 6: a button for another slice's action appears only when built)? */
function ml_action_built(string $action): bool
{
    static $reg = null;
    $reg ??= json_decode((string) @file_get_contents(APP_ROOT . '/mcp/action_registry.json'), true)['actions'] ?? [];
    return !empty($reg[$action]['built']);
}

/** The conversation a person opens: null when it is not theirs to see (404). */
function ml_thread_page(PDO $pdo, int $mailbox, int $thread, bool $viewerMode): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_threads WHERE id = :t AND mailbox_id = :mb');
    $st->execute(['t' => $thread, 'mb' => $mailbox]);
    $t = $st->fetch();
    if ($t === false) {
        return null;
    }
    $ms = $pdo->prepare("SELECT m.id, m.message_key_id, m.thread_id, m.folder_id, f.name AS folder_name, f.special_use, m.uidvalidity, m.uid,
                                m.from_address, m.from_name, m.sender_address, m.to_addresses, m.cc_addresses, m.recipient_names, m.reply_to, m.subject,
                                m.sent_at, m.received_at, m.size, m.seen, m.flagged, m.answered, m.draft, m.read_by_me, m.has_attachments,
                                m.attachment_names, m.dmarc_pass, m.spam_score, m.from_outside, m.automated, m.is_list, m.local_submission, m.snippet,
                                (f.uidvalidity IS NOT NULL AND f.uidvalidity <> m.uidvalidity) AS stale,
                                m.from_name IS NOT NULL AND m.from_outside AND EXISTS (SELECT 1 FROM members mm WHERE mm.status = 'active' AND mm.member_kind = 'human'
                                     AND lower(mm.display_name) = lower(m.from_name)) AS name_mismatch,
                                ml_images_always_for(m.id) AS images_always
                           FROM mcp_messages m JOIN mcp_folders f ON f.id = m.folder_id
                          WHERE m.thread_id = :t AND m.mailbox_id = :mb
                          ORDER BY m.received_at, m.id");
    $ms->execute(['t' => $thread, 'mb' => $mailbox]);
    $messages = [];
    foreach ($ms->fetchAll() as $m) {
        foreach (['to_addresses', 'cc_addresses', 'reply_to', 'attachment_names'] as $k) {
            $m[$k] = ml_pg_text_array((string) $m[$k]);
        }
        $m['recipient_names'] = is_string($m['recipient_names']) ? (json_decode($m['recipient_names'], true) ?: []) : (array) $m['recipient_names'];
        foreach (['seen', 'flagged', 'answered', 'draft', 'read_by_me', 'has_attachments', 'dmarc_pass', 'from_outside', 'automated', 'is_list',
                  'local_submission', 'stale', 'name_mismatch', 'images_always'] as $k) {
            $m[$k] = $m[$k] === null ? null : ($m[$k] === true || $m[$k] === 't' || $m[$k] === 1);
        }
        $m['unread_for_me'] = $viewerMode ? (!$m['read_by_me'] && !in_array($m['special_use'], ['sent', 'drafts'], true)) : !$m['seen'];
        $messages[] = $m;
    }
    if ($messages === []) {
        return null;
    }
    $labels = [];
    $ls = $pdo->prepare('SELECT DISTINCT g.id, g.label, g.colour FROM mcp_message_labels ml JOIN mcp_labels g ON g.id = ml.tag_id
                          WHERE ml.message_key_id = ANY (ml_thread_keys(:t)) AND g.archived_at IS NULL ORDER BY g.label');
    $ls->execute(['t' => $thread]);
    $labels = $ls->fetchAll();
    // which are open: the latest unread (for this reader) and the last (reading.md "The messages")
    $last = count($messages) - 1;
    $latestUnread = null;
    foreach ($messages as $i => $m) {
        if ($m['unread_for_me']) {
            $latestUnread = $i;
        }
    }
    foreach ($messages as $i => &$m) {
        $m['open'] = $i === $last || $i === $latestUnread;
    }
    unset($m);
    foreach (['muted', 'agent_muted', 'sensitive'] as $k) {
        $t[$k] = $t[$k] === true || $t[$k] === 't';
    }
    return ['thread' => $t, 'messages' => $messages, 'labels' => $labels];
}

/** "{a,b}" (text[]) → ['a', 'b'] — quoted elements unquoted. */
function ml_pg_text_array(string $text): array
{
    $text = trim($text);
    if ($text === '' || $text === '{}') {
        return [];
    }
    $out = [];
    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,{}]+)/', substr($text, 1, -1), $mm, PREG_SET_ORDER);
    foreach ($mm as $x) {
        $out[] = isset($x[2]) && $x[2] !== '' ? $x[2] : stripcslashes($x[1]);
    }
    return $out;
}

/**
 * One message through the mail service's `fetch` (mail-service.md §4): the header summary, the part list, and by $form the sanitised
 * html (with its links minted there), the plain text, the raw header list or the agent's text. Null when the service cannot answer
 * (down, the mailbox not live, busy); ['moved' => true] when the message moved (the page says "reload").
 */
function ml_message_fetch(int $mailbox, int $messageId, string $form = 'html', bool $images = false, array $extra = []): ?array
{
    try {
        return mailer_call('/v1/mailboxes/' . $mailbox . '/fetch', (int) current_member_id(),
                           ['message' => $messageId, 'form' => $form, 'images' => $images ? 'show' : 'ask'] + $extra, ml_on_behalf(), 40);
    } catch (MailerRefused $e) {
        if ($e->codeName === 'moved') {
            return ['moved' => true];
        }
        if ($e->codeName === 'not_found') {
            return ['gone' => true];
        }
        return null;
    } catch (MailerDown) {
        return null;
    }
}

/** The index's folder ids of a mailbox by special use, and every folder by id. */
function ml_folders_of(PDO $pdo, int $mailbox): array
{
    $st = $pdo->prepare('SELECT id, name, special_use, uidvalidity FROM mcp_folders WHERE mailbox_id = :mb');
    $st->execute(['mb' => $mailbox]);
    $byId = [];
    $bySpecial = [];
    foreach ($st->fetchAll() as $f) {
        $byId[(int) $f['id']] = $f;
        if ($f['special_use'] !== null) {
            $bySpecial[$f['special_use']] = (int) $f['id'];
        }
    }
    return [$byId, $bySpecial];
}

/** The conversations a search finds in one mailbox, at most ML_MATCHING_CAP (reading.md decision 1). */
function ml_matching_threads(PDO $pdo, int $mailbox, string $q): array
{
    $st = $pdo->prepare('SELECT thread_id FROM ml_search(:q, :mb, 5000, 0) WHERE thread_id IS NOT NULL GROUP BY thread_id ORDER BY max(received_at) DESC LIMIT :cap');
    $st->bindValue('q', $q);
    $st->bindValue('mb', $mailbox, PDO::PARAM_INT);
    $st->bindValue('cap', ML_MATCHING_CAP, PDO::PARAM_INT);
    $st->execute();
    return array_map('intval', array_column($st->fetchAll(), 'thread_id'));
}

/**
 * The message rows an action touches (reading.md "Query functions"): $threads resolved through mcp_threads (one the person cannot open
 * answers 404), or $matching through ml_search(); then the op's rule on each message's folder; `message` (mail_flag) names one message;
 * $fromFolder (the bulk bar's view) restricts a `move`. Answers [rows], each {id, message_key_id, folder_id, uidvalidity, uid,
 * special_use, thread_id, seen, flagged, received_at}, plus the thread ids in $threadIds.
 */
function ml_thread_targets(PDO $pdo, int $mailbox, array $threads, ?string $matching, string $op, ?int $message = null, ?int $fromFolder = null,
                           ?array &$threadIds = null): array
{
    $threads = array_values(array_unique(array_filter(array_map('intval', $threads), static fn (int $t): bool => $t > 0)));
    if ($threads !== []) {
        $st = $pdo->prepare('SELECT id FROM mcp_threads WHERE mailbox_id = :mb AND id = ANY (:ids)');
        $st->execute(['mb' => $mailbox, 'ids' => '{' . implode(',', $threads) . '}']);
        $found = array_map('intval', array_column($st->fetchAll(), 'id'));
        if (count($found) !== count($threads)) {
            refuse(404, 'No such conversation.');
        }
    }
    if ($matching !== null && trim($matching) !== '') {
        $threads = array_values(array_unique(array_merge($threads, ml_matching_threads($pdo, $mailbox, trim($matching)))));
    }
    $threadIds = $threads;
    if ($threads === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT m.id, m.message_key_id, m.folder_id, m.uidvalidity, m.uid, f.special_use, m.thread_id, m.seen, m.flagged, m.received_at,
                                m.from_address
                           FROM mcp_messages m JOIN mcp_folders f ON f.id = m.folder_id
                          WHERE m.mailbox_id = :mb AND m.thread_id = ANY (:ids) AND (f.uidvalidity IS NULL OR f.uidvalidity = m.uidvalidity)
                          ORDER BY m.thread_id, m.received_at DESC, m.id DESC');
    $st->execute(['mb' => $mailbox, 'ids' => '{' . implode(',', $threads) . '}']);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['seen'] = $r['seen'] === true || $r['seen'] === 't';
        $r['flagged'] = $r['flagged'] === true || $r['flagged'] === 't';
        $rows[] = $r;
    }
    if ($message !== null) {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['id'] === $message));
    }
    $rule = ML_OP_RULES[$op] ?? ['not' => []];
    $out = [];
    $latestByThread = [];
    foreach ($rows as $r) {
        $su = (string) ($r['special_use'] ?? '');
        if (isset($rule['only']) && !in_array($su, $rule['only'], true)) {
            continue;
        }
        if (isset($rule['not']) && in_array($su, $rule['not'], true)) {
            continue;
        }
        if ($op === 'move' && $fromFolder !== null && (int) $r['folder_id'] !== $fromFolder) {
            continue;
        }
        if ($op === 'seen' && $r['seen']) {
            continue;
        }
        if ($op === 'unflag' && !$r['flagged']) {
            continue;
        }
        if (in_array($op, ['unseen', 'unseen_viewer', 'flag'], true) && $message === null) {
            // mark unread marks the LATEST message unread; star stars the latest (Gmail — decision 5)
            $t = (int) $r['thread_id'];
            if (isset($latestByThread[$t])) {
                continue;
            }
            $latestByThread[$t] = true;
            if ($op === 'flag' && $r['flagged']) {
                continue;
            }
            if ($op === 'unseen' && !$r['seen']) {
                continue;
            }
        }
        $out[] = $r;
    }
    return $out;
}

/**
 * The refusals (ml_mutation_refusal per folder — here first, so the page can say why), then the mail service's `mutate`, one call with
 * the targets grouped by folder there. Answers {count, refused: [{reason, count}], moved: [{message, ref}], to_folder}.
 */
function ml_mail_act(PDO $pdo, int $mailbox, string $op, array $targets, array $args = []): array
{
    $me = (int) current_member_id();
    $behalf = ml_on_behalf();
    $refusalOp = ['not_spam' => 'move', 'trash_empty' => 'delete_forever'][$op] ?? $op;
    $refused = [];
    $allowed = [];
    $byFolder = [];
    foreach ($targets as $r) {
        $byFolder[(int) $r['folder_id']][] = $r;
    }
    $st = $pdo->prepare('SELECT ml_mutation_refusal(:mb, :me, :b, :f, :op)');
    foreach ($byFolder as $fid => $rows) {
        $st->execute(['mb' => $mailbox, 'me' => $me, 'b' => $behalf, 'f' => $fid, 'op' => $refusalOp]);
        $why = $st->fetchColumn();
        if ($why !== null && $why !== false) {
            $refused[(string) $why] = ($refused[(string) $why] ?? 0) + count($rows);
            continue;
        }
        foreach ($rows as $r) {
            $allowed[] = $r;
        }
    }
    if ($op === 'trash_empty') {
        $st->execute(['mb' => $mailbox, 'me' => $me, 'b' => $behalf, 'f' => $args['folder_id'] ?? null, 'op' => 'delete_forever']);
        $why = $st->fetchColumn();
        if ($why !== null && $why !== false) {
            return ['count' => 0, 'refused' => [['reason' => (string) $why, 'count' => 1]], 'moved' => [], 'to_folder' => null];
        }
    } elseif ($allowed === []) {
        return ['count' => 0, 'refused' => array_map(static fn ($k, $v) => ['reason' => $k, 'count' => $v], array_keys($refused), $refused),
                'moved' => [], 'to_folder' => null];
    }
    $call = ['op' => $op, 'targets' => array_map(static fn (array $r): array => [(int) $r['folder_id'], (int) $r['uidvalidity'], (int) $r['uid']], $allowed)];
    if ($op === 'move') {
        $call['to_folder'] = (int) ($args['to_folder'] ?? 0);
    }
    if ($op === 'trash_empty') {
        $call['which'] = (string) ($args['which'] ?? 'trash');
    }
    try {
        $res = mailer_call('/v1/mailboxes/' . $mailbox . '/mutate', $me, $call, $behalf, 120);
    } catch (MailerRefused $e) {
        emit_action_status(false, ['error' => $e->getMessage(), 'rule' => $e->codeName]);
        refuse(in_array($e->status, [403, 404, 409, 422, 503], true) ? $e->status : 502, $e->getMessage());
    } catch (MailerDown $e) {
        emit_action_status(false, ['error' => $e->getMessage(), 'rule' => 'mailer_down']);
        refuse(503, $e->getMessage());
    }
    foreach ($res['refused'] ?? [] as $x) {
        $refused[(string) $x['reason']] = ($refused[(string) $x['reason']] ?? 0) + (int) $x['count'];
    }
    return ['count' => (int) ($res['count'] ?? 0), 'refused' => array_map(static fn ($k, $v) => ['reason' => $k, 'count' => $v], array_keys($refused), $refused),
            'moved' => $res['moved'] ?? [], 'deleted' => $res['deleted'] ?? [], 'to_folder' => $res['to_folder'] ?? null];
}

/**
 * Opening marks read (reading.md "Opening marks read"): by ml_read_mode() — store_seen → the mail service's `mutate seen` on the
 * conversation's unread messages; viewer_read → ml_viewer_read() for each (this person only, never a \Seen); an action-token caller
 * (an agent reading JSON) marks nothing. Quiet: a failure never stops the page.
 */
function ml_open_marks(PDO $pdo, int $mailbox, array $messages): void
{
    if (is_action_authed()) {
        return;
    }
    $me = (int) current_member_id();
    $mode = (string) db_value($pdo, 'SELECT ml_read_mode(:mb, :me, :b)', ['mb' => $mailbox, 'me' => $me, 'b' => ml_on_behalf()]);
    if ($mode === 'viewer_read') {
        $st = $pdo->prepare('SELECT ml_viewer_read(:me, :k, true)');
        foreach ($messages as $m) {
            if ($m['unread_for_me']) {
                $st->execute(['me' => $me, 'k' => (int) $m['message_key_id']]);
            }
        }
        return;
    }
    if ($mode !== 'store_seen') {
        return;
    }
    $targets = array_values(array_filter($messages, static fn (array $m): bool => !$m['seen'] && !$m['stale']));
    if ($targets === []) {
        return;
    }
    try {
        mailer_call('/v1/mailboxes/' . $mailbox . '/mutate', $me,
                    ['op' => 'seen', 'targets' => array_map(static fn (array $m): array => [(int) $m['folder_id'], (int) $m['uidvalidity'], (int) $m['uid']], $targets)],
                    ml_on_behalf(), 30);
    } catch (MailerRefused | MailerDown) {
        // the page still shows; the message stays unread until the next open
    }
}

/** The conversation's neighbours in the list's order (j / k): [prev, next] thread ids, null where there is none. */
function ml_thread_neighbours(PDO $pdo, int $mailbox, string $view, ?int $folder, string $at, int $thread): array
{
    $next = $pdo->prepare('SELECT row_id FROM ml_mail_list_ids(:mb, :view, :folder, NULL, false, :at, :id, 1)');
    $next->bindValue('mb', $mailbox, PDO::PARAM_INT);
    $next->bindValue('view', $folder !== null ? null : $view);
    $next->bindValue('folder', $folder, $folder === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $next->bindValue('at', $at);
    $next->bindValue('id', $thread, PDO::PARAM_INT);
    $next->execute();
    $n = $next->fetchColumn();
    $prev = $pdo->prepare('SELECT row_id FROM ml_mail_list_ids(:mb, :view, :folder, NULL, false, NULL, NULL, 10001) WHERE (row_at, row_id) > (:at::timestamptz, :id) ORDER BY row_at, row_id LIMIT 1');
    $prev->bindValue('mb', $mailbox, PDO::PARAM_INT);
    $prev->bindValue('view', $folder !== null ? null : $view);
    $prev->bindValue('folder', $folder, $folder === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $prev->bindValue('at', $at);
    $prev->bindValue('id', $thread, PDO::PARAM_INT);
    $prev->execute();
    $p = $prev->fetchColumn();
    return [$p === false ? null : (int) $p, $n === false ? null : (int) $n];
}

/** The access log's reading rows (§3): message.open / message.raw once per request by anyone but the owner; message.part once per part per hour. */
function ml_log_read(PDO $pdo, int $mailbox, string $action, int $entityId, array $after, ?int $member = null): void
{
    $me = $member ?? (int) (current_member_id() ?? 0);
    if ($me <= 0 || !db_bool($pdo, 'SELECT ml_access_should_log(:mb, :me, :a)', ['mb' => $mailbox, 'me' => $me, 'a' => $action])) {
        return;
    }
    if ($action === 'message.part') {
        $st = $pdo->prepare("SELECT 1 FROM activity_log WHERE mailbox_id = :mb AND actor_member_id = :me AND action = 'message.part' AND entity_id = :e
                              AND after->>'part' = :p AND occurred_at > now() - interval '1 hour' LIMIT 1");
        $st->execute(['mb' => $mailbox, 'me' => $me, 'e' => $entityId, 'p' => (string) ($after['part'] ?? '')]);
        if ($st->fetchColumn() !== false) {
            return;
        }
    }
    log_activity($pdo, $action, 'mail_message', $entityId, ['after' => $after, 'mailbox_id' => $mailbox, 'actor_member_id' => $me]);
}

/** Plain text for the page (decision 4): escaped, quoted runs folded, URLs made links with the host rule. */
function ml_plain_html(string $text): string
{
    $lines = explode("\n", str_replace("\r\n", "\n", $text));
    $out = '';
    $quote = [];
    $flush = static function () use (&$out, &$quote): void {
        if ($quote !== []) {
            $out .= '<details class="mail-quoted"><summary>Show quoted text</summary><div class="text-muted">' . implode("\n", $quote) . '</div></details>';
            $quote = [];
        }
    };
    foreach ($lines as $line) {
        $html = ml_autolink(e($line));
        if (str_starts_with(ltrim($line), '>')) {
            $quote[] = $html;
            continue;
        }
        $flush();
        $out .= $html . "\n";
    }
    $flush();
    return $out;
}

/** http(s) URLs in ESCAPED text → links that open in a new tab, titled with their host. */
function ml_autolink(string $escaped): string
{
    return preg_replace_callback('#https?://[^\s<>"\']+#i', static function (array $m): string {
        $url = html_entity_decode($m[0], ENT_QUOTES, 'UTF-8');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer" title="' . e($host) . '">' . $m[0] . '</a>';
    }, $escaped) ?? $escaped;
}

/** A size for a person: 12 KB, 1.4 MB. */
function ml_size_label(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024) . ' KB';
    }
    return number_format($bytes / 1048576, 1) . ' MB';
}

/** The subject as a file name slug (the .eml download). */
function ml_subject_slug(?string $subject): string
{
    $s = strtolower(trim((string) $subject));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s === '' ? 'message' : substr($s, 0, 60);
}

/**
 * THE ELEVEN HANDLERS' ONE BODY (html/mail/*.php): the gates, the mailbox at view (404 below), the targets, the act, the log, the landing.
 * $op: archive · move · trash · spam · not_spam · flag · unflag · seen · unseen · delete_forever · trash_empty. $opts: event (the log
 * event), to_folder, which, message, flagged/read (the yes-or-no field's value already read), undo (bool: offer Undo on the landing).
 */
function mail_action_run(string $op, string $event, array $opts = []): never
{
    ml_handler_begin();
    if (!has_right('mail.use')) {
        refuse(403, right_refusal('mail.use'));
    }
    $pdo = db();
    $box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'view');
    $mb = (int) $box['id'];
    $threads = request_list('thread') ?? [];
    $restore = request_list('restore');                     // Undo: the message ids to put back where Gmail would (decision 2)
    $matching = req_val('matching');
    $message = request_integer('message');
    $fromFolder = request_integer('from_folder');
    [$byId, $bySpecial] = ml_folders_of($pdo, $mb);
    $threadIds = [];
    $args = [];
    $undo = null;
    if ($op === 'trash_empty') {
        $which = strtolower((string) (req_val('which') ?? 'trash')) === 'spam' ? 'spam' : 'trash';
        $fid = $bySpecial[$which === 'spam' ? 'junk' : 'trash'] ?? null;
        if ($fid === null) {
            refuse(422, 'This mailbox has no ' . ($which === 'spam' ? 'Spam' : 'Trash') . ' folder yet.');
        }
        $targets = [];
        $args = ['which' => $which, 'folder_id' => $fid];
        $count = (int) db_value($pdo, 'SELECT count(*) FROM mcp_messages WHERE mailbox_id = :mb AND folder_id = :f', ['mb' => $mb, 'f' => $fid]);
    } elseif ($restore !== null && $restore !== []) {
        // Undo of an archive / move / trash / spam: received mail → the Inbox, mail the mailbox sent → Sent (reading.md decision 2)
        $own = array_map('strtolower', array_column($pdo->query("SELECT address FROM mcp_identities WHERE mailbox_id = $mb")->fetchAll() ?: [], 'address'));
        $own[] = strtolower((string) $box['address']);
        $st = $pdo->prepare('SELECT m.id, m.message_key_id, m.folder_id, m.uidvalidity, m.uid, f.special_use, m.thread_id, m.seen, m.flagged, m.received_at, m.from_address
                               FROM mcp_messages m JOIN mcp_folders f ON f.id = m.folder_id WHERE m.mailbox_id = :mb AND m.id = ANY (:ids)');
        $st->execute(['mb' => $mb, 'ids' => '{' . implode(',', array_map('intval', $restore)) . '}']);
        $groups = [];
        foreach ($st->fetchAll() as $r) {
            $dest = in_array(strtolower((string) $r['from_address']), $own, true) ? ($bySpecial['sent'] ?? null) : ($bySpecial['inbox'] ?? null);
            if ($dest === null || (int) $r['folder_id'] === $dest) {
                continue;
            }
            $groups[$dest][] = $r;
            $threadIds[] = (int) $r['thread_id'];
        }
        $count = 0;
        $refusedAll = [];
        foreach ($groups as $dest => $rows) {
            $res = ml_mail_act($pdo, $mb, 'move', $rows, ['to_folder' => $dest]);
            $count += $res['count'];
            $refusedAll = array_merge($refusedAll, $res['refused']);
        }
        $threadIds = array_values(array_unique($threadIds));
        if ($count === 0) {
            refuse(422, $refusedAll[0]['reason'] ?? 'Nothing to put back.');
        }
        log_activity($pdo, 'message.move', 'mail_thread', $threadIds[0] ?? null, ['after' => ['count' => $count, 'undo' => true], 'mailbox_id' => $mb]);
        ml_done('Put back ' . $count . ' ' . ($count === 1 ? 'message' : 'messages'), $threadIds[0] ?? null, mail_action_land($mb, 'restored'), 'mailChanged', ['count' => $count]);
    } else {
        if ($threads === [] && ($matching === null || trim($matching) === '')) {
            refuse(422, 'Choose at least one conversation, or say what to match.');
        }
        if ($op === 'move') {
            $to = request_integer('to_folder');
            if ($to === null || !isset($byId[$to])) {
                refuse(422, 'Choose the folder to move to.');
            }
            $args['to_folder'] = $to;
        }
        $targets = ml_thread_targets($pdo, $mb, $threads, $matching, $op, $message, $fromFolder, $threadIds);
        if ($targets === []) {
            refuse(422, ML_OP_NOTHING[$op] ?? 'Nothing to change here.');
        }
        $count = count($targets);
    }
    $res = ml_mail_act($pdo, $mb, $op, $targets, $args);
    if ($res['count'] === 0 && $op !== 'trash_empty') {
        refuse(422, $res['refused'][0]['reason'] ?? 'Nothing was changed.');
    }
    if ($op === 'trash_empty' && $res['refused'] !== []) {
        refuse(422, $res['refused'][0]['reason']);
    }
    $n = $res['count'];
    $byMessage = [];
    foreach ($targets as $r) {
        $byMessage[(int) $r['id']] = (int) $r['thread_id'];
    }
    $touchedIds = array_merge(array_map(static fn (array $m): int => (int) $m['message'], $res['moved'] ?? []), array_map('intval', $res['deleted'] ?? []));
    $touched = $touchedIds === [] ? count(array_unique(array_filter(array_map(static fn (array $r): int => (int) $r['thread_id'], $targets))))
                                  : count(array_unique(array_filter(array_map(static fn (int $id): int => $byMessage[$id] ?? 0, $touchedIds))));
    $after = ['count' => $n];
    if ($matching !== null && trim($matching) !== '') {
        $after['matching'] = true;
    }
    if ($op === 'move') {
        $after['to_folder'] = $args['to_folder'];
    }
    if ($op === 'trash_empty') {
        $after['folder'] = $args['which'];
    }
    if ($res['refused'] !== []) {
        $after['refused'] = array_sum(array_column($res['refused'], 'count'));
    }
    log_activity($pdo, $event, $op === 'trash_empty' ? 'mailbox' : 'mail_thread', $op === 'trash_empty' ? $mb : ($threadIds[0] ?? null), ['after' => $after, 'mailbox_id' => $mb]);
    $movedIds = array_map(static fn (array $m): int => (int) $m['message'], $res['moved'] ?? []);
    if (!empty($opts['undo']) && $movedIds !== []) {
        $undo = $movedIds;
    }
    $what = $op === 'trash_empty' ? ($args['which'] === 'spam' ? 'Spam' : 'Trash') : ($touched === 1 ? '1 conversation' : $touched . ' conversations');
    $did = ML_OP_VERB[$op] . ' ' . $what . ' in ' . $box['address'];
    $extra = ['count' => $n, 'refused' => $res['refused']];
    if ($undo !== null) {
        $extra['undo'] = ['action' => 'mail_move', 'restore' => $undo];
    }
    $notice = ['archive' => 'archived', 'move' => 'moved', 'trash' => 'trashed', 'spam' => 'spam', 'not_spam' => 'notspam', 'flag' => 'starred', 'unflag' => 'unstarred',
               'seen' => 'read', 'unseen' => 'unread', 'delete_forever' => 'deleted', 'trash_empty' => 'emptied'][$op];
    ml_done($did, $op === 'trash_empty' ? $mb : ($threadIds[0] ?? null), mail_action_land($mb, $notice, $undo, $op === 'trash_empty' ? $n : $touched), 'mailChanged', $extra);
}

/** Where an action lands: `return_to` when local, else the mailbox's Inbox; with the notice and, for an archive/move/trash/spam, the Undo. */
function mail_action_land(int $mailbox, string $notice, ?array $undo = null, int $count = 0): string
{
    $view = (string) (req_val('view') ?? 'inbox');
    $default = isset(ML_VIEWS[$view]) ? '/m/' . $mailbox . '/v/' . $view : '/m/' . $mailbox . '/v/inbox';
    $path = return_path($default);
    $path .= (str_contains($path, '?') ? '&' : '?') . 'notice=' . rawurlencode($notice) . '&n=' . $count;
    if ($undo !== null && $undo !== []) {
        $path .= '&undo=' . implode(',', array_slice($undo, 0, 500));
    }
    return $path;
}

/** The landing notices of the list and the conversation (?notice=): [kind, sentence]; the count makes the sentence. */
function mail_action_notice(?string $key, int $n): ?array
{
    $c = $n === 1 ? '1 conversation' : $n . ' conversations';
    return match ($key) {
        'archived' => ['success', 'Archived ' . $c . '.'], 'moved' => ['success', 'Moved ' . $c . '.'], 'trashed' => ['success', 'Deleted ' . $c . '.'],
        'spam' => ['success', 'Marked ' . $c . ' as spam.'], 'notspam' => ['success', 'Marked ' . $c . ' not spam.'],
        'starred' => ['success', 'Starred.'], 'unstarred' => ['success', 'Unstarred.'], 'read' => ['success', 'Marked read.'], 'unread' => ['success', 'Marked unread.'],
        'deleted' => ['success', 'Deleted forever: ' . $c . '.'], 'emptied' => ['success', 'Emptied: ' . $n . ($n === 1 ? ' message' : ' messages') . ' deleted forever.'],
        'restored' => ['success', 'Put back.'], 'images' => ['success', 'Images from this sender will always show.'], 'noimages' => ['success', 'Images from this sender are asked for again.'],
        'sensitive' => ['success', 'Marked sensitive: no agent will read it.'], 'notsensitive' => ['success', 'No longer marked sensitive.'],
        'gone' => ['warning', 'This conversation moved or was deleted.'],
        default => null,
    };
}
