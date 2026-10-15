<?php
declare(strict_types=1);
/**
 * /m/{mailbox}/t/{thread} — the conversation (screen `thread-view`, docs/build-specs/reading.md; THE SECOND EXEMPLAR). With ?message=
 * and HX-Request, one collapsed message opened (its partial). Opening marks read by ml_read_mode(); a non-owner's open writes
 * message.open once. JSON: the conversation's index rows (no body — an agent reads through thread_read).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$mb = (int) $box['id'];
$tid = (int) request_integer('thread');
$me = (int) current_member_id();
$viewerMode = (string) db_value($pdo, 'SELECT ml_read_mode(:mb, :me, :b)', ['mb' => $mb, 'me' => $me, 'b' => ml_on_behalf()]) === 'viewer_read';
$page = $tid > 0 ? ml_thread_page($pdo, $mb, $tid, $viewerMode) : null;
$view = request_string('view', 'inbox');
$folder = request_integer('folder');
if (!isset(ML_VIEWS[$view])) {
    $view = 'inbox';
}
$listUrl = $folder !== null ? '/m/' . $mb . '/f/' . $folder : '/m/' . $mb . '/v/' . $view;
if ($page === null) {
    if (wants_json()) {
        json_error('not_found', 'No such conversation.', 404);
    }
    if (db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mail_thread_tombstones WHERE mailbox_id = :mb AND thread_id = :t)', ['mb' => $mb, 't' => $tid])) {
        http_response_code(410);
        render_screen('Conversation', view('shared/banner.php', ['notice' => ['warning', 'This conversation moved — reload the list.'], 'link' => [$listUrl, 'Back to the list']]), ['screen' => 'thread-view', 'mailboxId' => $mb]);
        exit;
    }
    http_response_code(404);
    render_screen('Conversation', view('shared/banner.php', ['notice' => ['warning', 'This conversation moved or was deleted.'], 'link' => [$listUrl, 'Back to the list']]), ['screen' => 'thread-view', 'mailboxId' => $mb]);
    exit;
}
$one = request_integer('message');
if ($one !== null && is_htmx_request()) {                     // a collapsed message opened: its partial, fetched and marked
    foreach ($page['messages'] as $m) {
        if ((int) $m['id'] === $one) {
            $m['open'] = true;
            $m['fetch'] = $m['stale'] ? ['moved' => true] : ml_message_fetch($mb, (int) $m['id'], 'html', $m['images_always']);
            ml_open_marks($pdo, $mb, [$m]);
            header('Vary: HX-Request');
            echo view('mail/message.php', ['box' => $box, 'm' => $m, 'tz' => member_timezone(), 'index' => 0, 'total' => count($page['messages']),
                                           'built' => ['mail_reply' => ml_action_built('mail_reply'), 'mail_reply_all' => ml_action_built('mail_reply_all'), 'mail_forward' => ml_action_built('mail_forward')],
                                           'level' => (string) $box['my_level'], 'listUrl' => $listUrl, 'view' => $view]);
            exit;
        }
    }
    respond_not_found('No such message.');
}
log_screen_view($pdo, 'thread-view', $mb);
ml_log_read($pdo, $mb, 'message.open', $tid, ['thread_id' => $tid, 'messages' => count($page['messages'])]);
if (wants_json()) {
    respond_screen(['mailbox_id' => $mb, 'thread_id' => $tid, 'subject' => $page['thread']['subject'], 'message_count' => (int) $page['thread']['message_count'],
                    'sensitive' => $page['thread']['sensitive'], 'labels' => $page['labels'],
                    'messages' => array_map(static fn (array $m): array => ['message' => (int) $m['id'], 'key' => (int) $m['message_key_id'], 'folder_id' => (int) $m['folder_id'],
                        'special_use' => $m['special_use'], 'from' => $m['from_address'], 'from_name' => $m['from_name'], 'to' => $m['to_addresses'], 'cc' => $m['cc_addresses'],
                        'subject' => $m['subject'], 'sent_at' => $m['sent_at'], 'received_at' => $m['received_at'], 'seen' => $m['seen'], 'unread_for_me' => $m['unread_for_me'],
                        'starred' => $m['flagged'], 'has_attachments' => $m['has_attachments'], 'attachment_names' => $m['attachment_names'], 'dmarc_pass' => $m['dmarc_pass'],
                        'spam_score' => $m['spam_score'], 'from_outside' => $m['from_outside'], 'name_mismatch' => $m['name_mismatch']], $page['messages'])]);
}
// the open messages' bodies: one fetch each (cached in the service, so the frame's body response costs no second read)
foreach ($page['messages'] as &$m) {
    if ($m['open']) {
        $m['fetch'] = $m['stale'] ? ['moved' => true] : ml_message_fetch($mb, (int) $m['id'], 'html', $m['images_always']);
    }
}
unset($m);
ml_open_marks($pdo, $mb, array_values(array_filter($page['messages'], static fn (array $m): bool => $m['open'])));
foreach ($page['messages'] as &$m) {                          // the page shows what opening did: the open messages read (so "Mark unread" is offered)
    if ($m['open'] && !is_action_authed()) {
        $m['unread_for_me'] = false;
        if (!$viewerMode) {
            $m['seen'] = true;
        }
    }
}
unset($m);
[$prev, $next] = ml_thread_neighbours($pdo, $mb, $view, $folder, (string) $page['thread']['last_message_at'], $tid);
$settings = is_string($box['settings'] ?? null) ? (json_decode($box['settings'], true) ?: []) : [];
$folders = array_values(array_filter(ml_folders_of($pdo, $mb)[0], static fn (array $f): bool => !in_array($f['special_use'], ['drafts', 'sent', 'junk', 'trash'], true)));
usort($folders, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
$notice = mail_action_notice($_GET['notice'] ?? null, (int) ($_GET['n'] ?? 0));
$undo = isset($_GET['undo']) ? array_values(array_filter(array_map('intval', explode(',', (string) $_GET['undo'])))) : null;
$subject = ($page['thread']['subject'] ?? '') !== '' ? (string) $page['thread']['subject'] : '(no subject)';
render_screen($subject . ' — ' . $box['address'], view('mail/thread.php', [
    'box' => $box, 't' => $page['thread'], 'messages' => $page['messages'], 'labels' => $page['labels'], 'tz' => member_timezone(), 'listUrl' => $listUrl,
    'prev' => $prev, 'next' => $next, 'keys' => ($settings['keyboard_shortcuts'] ?? true) !== false, 'notice' => $notice, 'undo' => $undo,
    'built' => ['mail_reply' => ml_action_built('mail_reply'), 'mail_reply_all' => ml_action_built('mail_reply_all'), 'mail_forward' => ml_action_built('mail_forward')],
    'level' => (string) $box['my_level'], 'isOwner' => (int) ($box['owner_member_id'] ?? 0) === $me, 'view' => $view, 'folder' => $folder, 'folders' => $folders,
]), ['screen' => 'thread-view', 'entity' => 'mail_thread', 'recordId' => $tid, 'mailboxId' => $mb]);
