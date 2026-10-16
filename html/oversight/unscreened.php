<?php
declare(strict_types=1);
/** /oversight/unscreened — the mail that did not wake its agent, newest first, and why in words; Release (one turn), Mark sender known, Spam, Dismiss, Open (screen `unscreened`). The subject of an agent's mail is escaped, never rendered. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
require_login();
require_human();
if (!has_right('mail.oversee')) {
    refuse(403, right_refusal('mail.oversee'));
}
$pdo = db();
$mailbox = request_integer('mailbox');
$rows = unscreened_list($pdo, (int) current_member_id(), $mailbox);
log_screen_view($pdo, 'unscreened', $mailbox);
if (wants_json()) {
    respond_screen(['unscreened' => array_map('present_unscreened', $rows), 'filters' => ['mailbox' => $mailbox]]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['released' => ['success', 'Released: the agent reads it in its next turn.'], 'dismissed' => ['success', 'Dismissed.'],
    'spam' => ['success', 'Moved to Spam.'], 'known' => ['success', 'The sender is known now: their next mail can wake the agent.']]);
render_screen('Unscreened', view('oversight/unscreened.php', ['rows' => $rows, 'mailbox' => $mailbox, 'notice' => $notice, 'tz' => member_timezone()]),
    ['screen' => 'unscreened', 'entity' => 'agent_dispatch', 'activeNav' => 'oversight', 'mailboxId' => $mailbox]);
