<?php
declare(strict_types=1);

/** Slice 9 — the JSON presenters (whitelists: never a raw row) and the page's words for a notice's time. */

/** "5 min ago", "3 h ago", "Oct 4": a notice's age for a person, in their zone. */
function nt_when(?string $utc, string $tz): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    try {
        $age = time() - (new DateTimeImmutable($utc))->getTimestamp();
    } catch (Exception) {
        return $utc;
    }
    if ($age < 60) {
        return 'just now';
    }
    if ($age < 3600) {
        return intdiv($age, 60) . ' min ago';
    }
    if ($age < 86400) {
        return intdiv($age, 3600) . ' h ago';
    }
    return format_ts($utc, $tz, 'M j');
}

/** One notice for JSON. */
function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['id'], 'kind' => (string) $n['kind'], 'title' => (string) $n['title'], 'body' => $n['body'] !== null ? (string) $n['body'] : null,
            'count' => $n['item_count'] !== null ? (int) $n['item_count'] : null, 'mailbox_id' => $n['mailbox_id'] !== null ? (int) $n['mailbox_id'] : null,
            'mailbox' => $n['mailbox_address'] ?? null, 'unread' => $n['read_at'] === null, 'created_at' => json_ts((string) $n['created_at']), 'href' => (string) $n['href']];
}

/** The notifications screen for JSON. */
function present_notifications(array $rows, int $unread, bool $unreadOnly): array
{
    return ['unread_count' => $unread, 'unread_only' => $unreadOnly, 'notifications' => array_map('present_notification', $rows)];
}

/** The settings screen for JSON. */
function present_notification_settings(array $prefs, array $mailboxes, bool $textPossible): array
{
    $kinds = [];
    foreach (NT_KINDS as $k => [$label, , $what]) {
        $kinds[] = ['kind' => $k, 'label' => $label, 'about' => $what, 'in_app' => true, 'by_mail' => in_array($k, $prefs['kinds'], true), 'by_text' => in_array($k, $prefs['text_kinds'], true)];
    }
    return ['email_enabled' => $prefs['email_enabled'], 'text_enabled' => $prefs['text_enabled'], 'texts_possible' => $textPossible, 'kinds' => $kinds,
            'new_mail_mailboxes' => array_map(static fn (array $b): array => ['mailbox_id' => (int) $b['id'], 'address' => (string) $b['address'], 'notify' => in_array((int) $b['id'], $prefs['new_mail_mailbox_ids'], true)], $mailboxes)];
}
