<?php
declare(strict_types=1);
/**
 * GET /m/{mailbox}/complete?q= — the composer's recipient suggestions (not a screen; docs/build-specs/correspondents.md "The autocomplete
 * door"). `send` on the mailbox, else 404 like a missing mailbox. At most eight rows from ml_complete(): this mailbox's correspondents (sent-to
 * first), the business's people, the mailboxes the person opens — never another mailbox's address. Logs nothing: it is typing, not reading.
 * Answers an HTML list (JSON on request) and caches nothing.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
$box = ml_require_mailbox(request_string('mailbox'), 'view');
if ((ML_LEVELS[(string) $box['my_level']] ?? 0) < ML_LEVELS['send']) {
    respond_not_found('No such mailbox.');                     // a viewer's mailbox is not one they write from: the same words as a missing one
}
$q = trim(request_string('q'));
$rows = mb_strlen($q) >= 2 ? ml_complete_rows((int) $box['id'], mb_substr($q, 0, 100)) : [];
header('Cache-Control: no-store');
if (wants_json()) {
    respond_screen(['suggestions' => $rows]);
}
echo view('mail/complete.php', ['rows' => $rows, 'q' => $q]);
