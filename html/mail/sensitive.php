<?php
declare(strict_types=1);
/**
 * Action `mail_sensitive_set` (log `thread.sensitive`): a person with triage marks conversations sensitive — never in an agent's context
 * (the views and ml_thread_keys refuse an agent) — or takes the mark off. Anchored on a message key (db/008 mail_thread_states). People only.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
ml_handler_begin();
require_human();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'view');
$mb = (int) $box['id'];
if ((ML_LEVELS[(string) $box['my_level']] ?? 0) < 2) {
    refuse(403, 'Marking a conversation sensitive needs triage on the mailbox.');
}
$threads = request_list('thread') ?? [];
if ($threads === []) {
    refuse(422, 'Choose at least one conversation.');
}
$on = ml_yes('sensitive', true);
$threadIds = [];
$targets = ml_thread_targets($pdo, $mb, $threads, null, 'flag', null, null, $threadIds);
$anchor = $pdo->prepare('SELECT ml_thread_anchor(:k)');
$up = $pdo->prepare('INSERT INTO mail_thread_states (mailbox_id, anchor_key_id, sensitive, sensitive_by) VALUES (:mb, :k, :on, :by)
                      ON CONFLICT (mailbox_id, anchor_key_id) DO UPDATE SET sensitive = EXCLUDED.sensitive, sensitive_by = EXCLUDED.sensitive_by');
$n = 0;
$seen = [];
ml_guard($pdo, static function () use ($targets, $anchor, $up, $mb, $on, &$n, &$seen): void {
    foreach ($targets as $r) {
        $t = (int) $r['thread_id'];
        if (isset($seen[$t])) {
            continue;
        }
        $seen[$t] = true;
        $anchor->execute(['k' => (int) $r['message_key_id']]);
        $k = (int) $anchor->fetchColumn();
        $up->execute(['mb' => $mb, 'k' => $k, 'on' => $on ? 't' : 'f', 'by' => current_member_id()]);
        $n++;
    }
});
if ($n === 0) {
    refuse(422, 'Nothing to mark here.');
}
log_activity($pdo, 'thread.sensitive', 'mail_thread', $threadIds[0] ?? null, ['after' => ['count' => $n, 'on' => $on], 'mailbox_id' => $mb]);
ml_done(($on ? 'Marked sensitive: ' : 'No longer sensitive: ') . $n . ' conversation' . ($n === 1 ? '' : 's'), $threadIds[0] ?? null,
        mail_action_land($mb, $on ? 'sensitive' : 'notsensitive', null, $n), 'mailChanged', ['count' => $n, 'on' => $on]);
