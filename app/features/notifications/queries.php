<?php
declare(strict_types=1);

/**
 * Slice 9 — the bell and its page (notifications.md): the queries every notification screen shares. A notice is the caller's own, never
 * another's; none carries a word of any message (no subject, no sender's name, no body, no folder name) — it says how many and where.
 * The writers are the schema's (ml_notify(), ml_notify_coalesce(), the held-mail trigger — db/007, db/015) and the worker's passes
 * (passes.php); this file only reads them and records what a person did with them.
 */

/** The kinds, in the settings page's order: [label, icon, what it is]. `new_mail` is the only one a person chooses mailboxes for. */
const NT_KINDS = [
    'new_mail' => ['New mail', 'feather-inbox', 'How many new messages arrived in a mailbox you chose, and where.'],
    'held_mail' => ['Held mail', 'feather-pause-circle', 'An agent\'s message waits for your review.'],
    'review_decided' => ['Review decided', 'feather-check-circle', 'A held message expired, or another reviewer decided it.'],
    'unscreened' => ['Unscreened mail', 'feather-help-circle', 'Mail for an agent waits for a person to look at it.'],
    'break_glass' => ['Emergency access', 'feather-alert-triangle', 'Someone was given emergency access to your mailbox.'],
    'permission_granted' => ['Access given to you', 'feather-key', 'You were given access to a mailbox.'],
    'credential_failed' => ['A credential failed', 'feather-lock', 'The mail server refused a mailbox\'s credential (Mail admins).'],
    'sync_failed' => ['A sync failed', 'feather-refresh-cw', 'A mailbox could not be read (Mail admins).'],
    'quota' => ['A mailbox is filling', 'feather-hard-drive', 'A mailbox crossed 90 % or 100 % of its space.'],
    'snooze_woke' => ['A snooze ended', 'feather-clock', 'A conversation you snoozed is back.'],
    'send_failed' => ['A message was not sent', 'feather-x-circle', 'Your message could not be sent.'],
    'send_deferred' => ['A message is delayed', 'feather-watch', 'Your message could not be sent yet and will be tried again.'],
    'agent_replied' => ['An agent replied', 'feather-message-square', 'An agent answered on your behalf.'],
    'dispatch_failed' => ['An agent\'s mail turn failed', 'feather-zap-off', 'An agent could not be woken for its mail (overseers).'],
    'digest' => ['Your agents\' mail', 'feather-clipboard', 'The Mail Clerk\'s morning note for overseers.'],
];

/** The screen a notice opens, by what it is about. $n: a notifications row (kind, record_type, record_id, mailbox_id). $viewer: the person it is for when no request is (the worker's mail and text). */
function nt_href(array $n, ?int $viewer = null): string
{
    $admin = $viewer === null ? has_right('mailboxes.manage') : db_bool(db(), 'SELECT ml_member_has_right(:m, :r)', ['m' => $viewer, 'r' => 'mailboxes.manage']);
    $mb = $n['mailbox_id'] !== null ? (int) $n['mailbox_id'] : null;
    $rid = $n['record_id'] !== null ? (int) $n['record_id'] : null;
    return match ((string) $n['kind']) {
        'held_mail', 'review_decided' => $rid !== null && ($n['record_type'] ?? '') === 'outbound' ? '/oversight/review/' . $rid : '/oversight/review',
        'unscreened' => '/oversight/unscreened' . ($mb !== null ? '?mailbox=' . $mb : ''),
        'credential_failed', 'sync_failed', 'quota' => $mb !== null ? ($admin ? '/admin/mailboxes/' . $mb : '/m/' . $mb . '/settings') : '/notifications',
        'break_glass' => $mb !== null ? '/m/' . $mb . '/access' : '/notifications',
        'send_failed', 'send_deferred' => $mb !== null ? '/m/' . $mb . '/v/outbox' : '/notifications',
        'snooze_woke' => $mb !== null && $rid !== null && ($n['record_type'] ?? '') === 'mail_thread' ? '/m/' . $mb . '/t/' . $rid : ($mb !== null ? '/m/' . $mb . '/v/inbox' : '/notifications'),
        'digest', 'dispatch_failed' => '/oversight',
        default => $mb !== null ? '/m/' . $mb . '/v/inbox' : '/notifications',      // new_mail, permission_granted, agent_replied
    };
}

/**
 * My notices, newest first. The mailbox's address is shown only while I can still open the mailbox. $before: an id — the page after it.
 * Answers the rows with `href` and `mailbox_address` added.
 */
function notifications_list(PDO $pdo, int $member, bool $unreadOnly, int $limit = 50, ?int $before = null): array
{
    $st = $pdo->prepare('SELECT n.id, n.kind, n.title, n.body, n.mailbox_id, n.record_type, n.record_id, n.read_at, n.created_at, n.item_count,
                                CASE WHEN n.mailbox_id IS NOT NULL AND ml_level_rank(ml_mailbox_level(n.mailbox_id, n.member_id)) > 0
                                     THEN (SELECT address::text FROM mailboxes WHERE id = n.mailbox_id) END AS mailbox_address
                           FROM notifications n
                          WHERE n.member_id = :m AND (NOT :u OR n.read_at IS NULL) AND (CAST(:b AS bigint) IS NULL OR n.id < CAST(:b2 AS bigint))
                          ORDER BY n.id DESC LIMIT :l');
    $st->bindValue('m', $member, PDO::PARAM_INT);
    $st->bindValue('u', $unreadOnly, PDO::PARAM_BOOL);
    $st->bindValue('b', $before, $before === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('b2', $before, $before === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('l', max(1, min(200, $limit)), PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['href'] = nt_href($r);
    }
    return $rows;
}

/** My unread notices — the bell's number. */
function notifications_unread_count(PDO $pdo, int $member): int
{
    return (int) one_value($pdo, 'SELECT count(*) FROM notifications WHERE member_id = :m AND read_at IS NULL', ['m' => $member]);
}

/** Mark mine read: one ($notification), or all of them ($everyOne). Answers how many changed — a notice already read, or another person's, changes nothing. */
function notification_read(PDO $pdo, int $member, ?int $notification, bool $everyOne): int
{
    if (!$everyOne && $notification === null) {
        return 0;
    }
    $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND read_at IS NULL AND (:all OR id = CAST(:n AS bigint))');
    $st->bindValue('m', $member, PDO::PARAM_INT);
    $st->bindValue('all', $everyOne, PDO::PARAM_BOOL);
    $st->bindValue('n', $notification, $notification === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->execute();
    return $st->rowCount();
}

/** My choices (the table's defaults when I have never saved): email_enabled, text_enabled, kinds[], text_kinds[], new_mail_mailbox_ids[]. */
function prefs_get(PDO $pdo, int $member): array
{
    $st = $pdo->prepare('SELECT p.email_enabled, p.text_enabled, p.kinds, p.text_kinds, p.new_mail_mailbox_ids FROM notification_prefs p WHERE p.member_id = :m');
    $st->execute(['m' => $member]);
    $r = $st->fetch();
    if ($r === false) {
        $r = $pdo->query("SELECT true AS email_enabled, false AS text_enabled, '{held_mail,review_decided,unscreened,break_glass,permission_granted,credential_failed,sync_failed,send_failed}'::text AS kinds,
                                 '{held_mail}'::text AS text_kinds, '{}'::text AS new_mail_mailbox_ids")->fetch();
    }
    return ['email_enabled' => (bool) $r['email_enabled'], 'text_enabled' => (bool) $r['text_enabled'],
            'kinds' => ml_pg_text_array((string) $r['kinds']), 'text_kinds' => ml_pg_text_array((string) $r['text_kinds']),
            'new_mail_mailbox_ids' => pg_int_array((string) $r['new_mail_mailbox_ids'])];
}

/** Save mine (an upsert of the five choices; the caller has validated them). */
function prefs_save(PDO $pdo, int $member, array $prefs): void
{
    $pdo->prepare('INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds, new_mail_mailbox_ids, updated_at)
                   VALUES (:m, :e, :t, CAST(:k AS text[]), CAST(:tk AS text[]), CAST(:mb AS bigint[]), now())
                   ON CONFLICT (member_id) DO UPDATE SET email_enabled = EXCLUDED.email_enabled, text_enabled = EXCLUDED.text_enabled, kinds = EXCLUDED.kinds,
                          text_kinds = EXCLUDED.text_kinds, new_mail_mailbox_ids = EXCLUDED.new_mail_mailbox_ids, updated_at = now()')
        ->execute(['m' => $member, 'e' => $prefs['email_enabled'] ? 't' : 'f', 't' => $prefs['text_enabled'] ? 't' : 'f', 'k' => pg_array_literal(array_values($prefs['kinds'])),
                   'tk' => pg_array_literal(array_values($prefs['text_kinds'])), 'mb' => pg_array_literal(array_map('intval', $prefs['new_mail_mailbox_ids']))]);
}

/** The mailboxes I may open (any level), for the new-mail switches: [{id, address, kind}] — through the wall, never a guess. */
function nt_openable_mailboxes(PDO $pdo, int $member): array
{
    $st = $pdo->prepare("SELECT b.id, b.address::text AS address, b.kind FROM mailboxes b
                          WHERE b.active AND ml_level_rank(ml_mailbox_level(b.id, :m)) > 0 ORDER BY b.kind, b.address");
    $st->execute(['m' => $member]);
    return $st->fetchAll();
}

/**
 * Whether a text can reach me: the directory's phone for me is on file. The kernel (K6) decides at send time whether it is VERIFIED, whether the
 * business has a notification number and whether I opted out; it offers no way to ask beforehand, so a refusal there is a `skipped` row.
 */
function nt_text_possible(PDO $pdo, int $member): bool
{
    return trim((string) one_value($pdo, 'SELECT phone FROM members WHERE id = :m', ['m' => $member])) !== '';
}

/** Mail that could not be sent to me (three tries and out): the settings page says so. */
function nt_mail_failures(PDO $pdo, int $member): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM notification_outbox WHERE member_id = :m AND status = 'failed' AND channel = 'email' AND created_at > now() - interval '14 days'", ['m' => $member]);
}
