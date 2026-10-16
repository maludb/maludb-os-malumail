<?php
declare(strict_types=1);
/**
 * Action `mail_unsnooze` (log `thread.unsnooze`; undo mail_snooze): the conversation comes back now, as it would at its time — the mail
 * leaves the Snoozed folder for where it came from, its latest message unread, the "back from snooze" chip on it. Triage.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(req_val('mailbox') ?? request_integer('mailbox'), 'triage');
$mb = (int) $box['id'];
$me = (int) current_member_id();
$threads = array_values(array_filter(array_map('intval', request_list('thread') ?? []), static fn (int $t): bool => $t > 0));
$matching = req_val('matching');
if (($matching ?? '') !== '') {
    $threads = array_values(array_unique(array_merge($threads, ml_matching_threads($pdo, $mb, trim($matching)))));
}
if ($threads === []) {
    refuse(422, 'Choose at least one conversation.');
}
$count = 0;
$moved = 0;
foreach ($threads as $t) {
    $owns = $pdo->prepare('SELECT 1 FROM mcp_threads WHERE id = :t AND mailbox_id = :mb');
    $owns->execute(['t' => $t, 'mb' => $mb]);
    if ($owns->fetchColumn() === false) {
        refuse(404, 'No such conversation.');
    }
    $anchors = ml_guard($pdo, static function () use ($pdo, $mb, $t, $me): array {
        $st = $pdo->prepare('SELECT anchor_key_id, back_to FROM ml_thread_unsnooze(:mb, :t, :me)');
        $st->execute(['mb' => $mb, 't' => $t, 'me' => $me]);
        return $st->fetchAll();
    });
    if ($anchors === []) {
        continue;                                                // not snoozed: nothing to undo
    }
    $count++;
    foreach ($anchors as $a) {
        try {
            $moved += ml_snooze_mail_back($pdo, $mb, (int) $a['anchor_key_id'], $a['back_to'], $me, ml_on_behalf())['moved'];
        } catch (MailerRefused $e) {
            emit_action_status(false, ['error' => $e->getMessage(), 'rule' => $e->codeName]);
            refuse(in_array($e->status, [403, 404, 409, 422, 503], true) ? $e->status : 502, $e->getMessage());
        } catch (MailerDown $e) {
            emit_action_status(false, ['error' => $e->getMessage(), 'rule' => 'mailer_down']);
            refuse(503, $e->getMessage());
        }
    }
}
if ($count === 0) {
    refuse(422, 'Nothing here is snoozed.');
}
log_activity($pdo, 'thread.unsnooze', 'mail_thread', $threads[0], ['after' => ['count' => $count, 'messages' => $moved], 'mailbox_id' => $mb]);
ml_done('Brought back ' . ($count === 1 ? '1 conversation' : $count . ' conversations'), $threads[0], mail_action_land($mb, 'unsnoozed', null, $count), 'mailChanged',
        ['count' => $count, 'undo' => ['action' => 'mail_snooze', 'thread' => $threads]]);
