<?php
/**
 * An empty list says why (mail-list.md "Empty states"): a mailbox not connected yet, a refused credential (no retry: the breaker), one
 * still being brought in (the mail service's status), or the view's own words. Data: box, view, folder, status (or null), unread
 */
$isAdmin = has_right('mailboxes.manage');
$cred = (string) ($box['credential_status'] ?? '');
$state = (string) ($box['sync_state'] ?? '');
$text = null;
$extra = '';
if ($cred === 'failed' || $state === 'failed') {
    $text = 'Not connected: a Mail admin stores a new credential.';
} elseif ($cred === '' || $cred === 'revoked') {
    $text = 'This mailbox is not connected yet.';
    if ($isAdmin) {
        $extra = hx_link('/admin/mailboxes/' . (int) $box['id'], 'Open its admin page', 'ms-1', 'id="mail-empty-admin"');
    }
} elseif ($state === 'new' || $state === 'syncing') {
    $have = 0;
    $about = 0;
    foreach ($status['folders'] ?? [] as $f) {
        $have += (int) ($f['indexed'] ?? 0);
        $about += (int) ($f['messages_total'] ?? 0);
    }
    $text = $about > 0 ? 'Bringing this mailbox in: ' . number_format($have) . ' of about ' . number_format($about) . ' messages so far.'
                       : 'Bringing this mailbox in: the first messages arrive in a moment.';
} elseif ($unread) {
    $text = 'Nothing unread here.';
} else {
    $text = match (($label ?? null) !== null ? 'label' : ($folder !== null ? 'folder' : $view)) {
        'label' => 'Nothing has this label yet.',
        'inbox' => 'Nothing new. Mail that arrives shows here within a few seconds.',
        'scheduled' => 'Nothing scheduled.',
        'outbox' => 'Nothing waiting to go.',
        'starred' => 'Nothing starred.',
        'snoozed' => 'Nothing snoozed.',
        'drafts' => 'No drafts.',
        'sent' => 'Nothing sent yet.',
        default => 'Nothing here.',
    };
}
?>
<div class="card mb-2"><div class="card-body text-muted" id="mail-empty"><?= e($text) ?><?= $extra ?></div></div>
