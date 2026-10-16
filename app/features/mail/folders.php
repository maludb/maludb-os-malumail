<?php
declare(strict_types=1);

/**
 * FOLDERS AND SNOOZE (docs/build-specs/search-labels-snooze.md — slice 6; mail-service.md §5). A mailbox's IMAP folders are made, renamed
 * and deleted by the mail service (`mutate` folder_create · folder_rename · folder_delete — one IMAP command each, the index following in
 * the same request); a snooze is the Inbox copies moving to a Snoozed folder the service creates by name, and its end moving them back.
 * The state — until, from, by — is ml_thread_snooze() / ml_snoozes_due() / ml_thread_unsnooze() (db/010, db/019), the mail is the service's.
 *
 *   ml_folder_list()          the folders with totals, depth and whether they are a system folder
 *   ml_folder_call()          one folder op through the mail service → the result, or refuse() in the service's words
 *   ml_snooze_mail_back()     the mail of a conversation from Snoozed to where it came from, the latest message unread
 */

/** A mailbox's folders for the page: [{id, name, leaf, depth, delimiter, special_use, system, total, unseen}], by name. */
function ml_folder_list(PDO $pdo, int $mailbox): array
{
    $st = $pdo->prepare('SELECT id, name, delimiter, special_use, messages_total AS total, messages_unseen AS unseen FROM mcp_folders WHERE mailbox_id = :mb');
    $st->execute(['mb' => $mailbox]);
    $order = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'snoozed' => 4, 'junk' => 5, 'trash' => 6];
    $rows = [];
    foreach ($st->fetchAll() as $f) {
        $d = (string) ($f['delimiter'] ?? '');
        $parts = $d !== '' ? explode($d, (string) $f['name']) : [(string) $f['name']];
        $f['depth'] = count($parts) - 1;
        $f['leaf'] = end($parts);
        $f['system'] = $f['special_use'] !== null || strtoupper((string) $f['name']) === 'INBOX';
        $f['sort'] = $f['system'] ? ($order[$f['special_use'] ?? 'inbox'] ?? 3) : 10;
        $f['total'] = (int) $f['total'];
        $f['unseen'] = (int) $f['unseen'];
        $rows[] = $f;
    }
    usort($rows, static fn (array $a, array $b): int => [$a['sort'], strtolower((string) $a['name'])] <=> [$b['sort'], strtolower((string) $b['name'])]);
    return $rows;
}

/** The words for a folder's name: Inbox, Sent… for the system ones. */
function ml_folder_title(array $f): string
{
    if ($f['special_use'] === null && strtoupper((string) $f['name']) !== 'INBOX') {
        return (string) $f['leaf'];
    }
    return ['inbox' => 'Inbox', 'sent' => 'Sent', 'drafts' => 'Drafts', 'junk' => 'Spam', 'trash' => 'Trash', 'archive' => 'Archive', 'snoozed' => 'Snoozed'][$f['special_use'] ?? 'inbox'] ?? (string) $f['name'];
}

/** One folder op through the mail service as the caller; the service's refusal becomes the handler's (its sentence), a down service a 503. */
function ml_folder_call(int $mailbox, string $op, array $args): array
{
    try {
        return mailer_call('/v1/mailboxes/' . $mailbox . '/mutate', (int) current_member_id(), ['op' => $op] + $args, ml_on_behalf(), 60);
    } catch (MailerRefused $e) {
        emit_action_status(false, ['error' => $e->getMessage(), 'rule' => $e->codeName]);
        refuse(in_array($e->status, [403, 404, 409, 422, 503], true) ? $e->status : 502, $e->getMessage());
    } catch (MailerDown $e) {
        emit_action_status(false, ['error' => $e->getMessage(), 'rule' => 'mailer_down']);
        refuse(503, $e->getMessage());
    }
}

/**
 * The mail of the conversation holding $anchorKey, from the Snoozed folder back to $backTo (the folder it came from; the Inbox when that
 * folder is gone), and its latest message marked unread — the wake (decision 4). Throws MailerRefused / MailerDown for the caller to
 * answer (the handler refuses, the worker reports). Answers ['moved' => n, 'thread' => id|null, 'latest' => message id|null].
 */
function ml_snooze_mail_back(PDO $pdo, int $mailbox, int $anchorKey, ?string $backTo, int $actor, ?int $behalf): array
{
    $st = $pdo->prepare("SELECT m.id, m.folder_id, m.uidvalidity, m.uid, m.thread_id
                           FROM mail_messages m JOIN mail_folders f ON f.id = m.folder_id
                          WHERE m.mailbox_id = :mb AND f.special_use = 'snoozed' AND (f.uidvalidity IS NULL OR f.uidvalidity = m.uidvalidity)
                            AND m.thread_id = (SELECT x.thread_id FROM mail_messages x WHERE x.message_key_id = :k AND x.thread_id IS NOT NULL ORDER BY x.id LIMIT 1)
                          ORDER BY m.received_at DESC, m.id DESC");
    $st->execute(['mb' => $mailbox, 'k' => $anchorKey]);
    $rows = $st->fetchAll();
    if ($rows === []) {
        return ['moved' => 0, 'thread' => null, 'latest' => null];
    }
    $dest = $pdo->prepare("SELECT id FROM mail_folders WHERE mailbox_id = :mb AND (name = :n OR special_use = 'inbox') ORDER BY (name = :n2) DESC, id LIMIT 1");
    $dest->execute(['mb' => $mailbox, 'n' => (string) $backTo, 'n2' => (string) $backTo]);
    $to = (int) $dest->fetchColumn();
    if ($to <= 0) {
        return ['moved' => 0, 'thread' => (int) $rows[0]['thread_id'], 'latest' => null];
    }
    $res = mailer_call('/v1/mailboxes/' . $mailbox . '/mutate', $actor,
        ['op' => 'move', 'to_folder' => $to, 'targets' => array_map(static fn (array $r): array => [(int) $r['folder_id'], (int) $r['uidvalidity'], (int) $r['uid']], $rows)], $behalf, 120);
    $latest = (int) $rows[0]['id'];
    if ((int) ($res['count'] ?? 0) > 0) {
        try {
            mailer_call('/v1/mailboxes/' . $mailbox . '/mutate', $actor, ['op' => 'unseen', 'messages' => [$latest]], $behalf, 60);
        } catch (MailerRefused) {
            // a mailbox that keeps its own read marks (an intake) says so; the mail is back all the same
        }
    }
    return ['moved' => (int) ($res['count'] ?? 0), 'thread' => (int) $rows[0]['thread_id'], 'latest' => $latest];
}
