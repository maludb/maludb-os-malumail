<?php
/**
 * One row of the list (mail-list.md "A row"): the check box, the star, who it is with, the subject and the snippet, label chips, the
 * paper clip, the date (the wake time in Snoozed; the time it goes in Scheduled; the state in the Outbox), agent badges, a lock on a
 * sensitive conversation. Unread rows are bold. Data: r (mail_list_rows()), box, view, tz, oob (bool: the poll's copy, swapped in place)
 */
$isOut = $r['outbound_id'] !== null;
$rid = $isOut ? 'o-' . (int) $r['outbound_id'] : 't-' . (int) $r['thread_id'];
$mb = (int) $box['id'];
$href = $r['thread_id'] !== null ? '/m/' . $mb . '/t/' . (int) $r['thread_id'] : null;
if ($view === 'drafts' && $r['latest_message'] !== null && !$isOut) {                 // slice 4: a draft opens in the composer (its row by the index's key)
    $href = '/m/' . $mb . '/compose?key=' . (int) $r['latest_message'];
}
$when = match (true) {
    $view === 'snoozed' && $r['snoozed_until'] !== null => '<i class="feather-clock me-1"></i>' . e(mail_list_date($r['snoozed_until'], $tz)),
    $view === 'scheduled' && $r['send_after'] !== null => '<i class="feather-send me-1"></i>' . e(format_ts($r['send_after'], $tz, 'M j, g:i A')),
    default => e(mail_list_date($r['last_message_at'], $tz)),
};
$who = $isOut ? 'To: ' . implode(', ', array_slice(array_map('strval', $r['participants']), 0, 3)) : mail_participants($r, (string) $box['address']);
$outState = ['deferred' => ['Trying again', 'warning'], 'failed' => ['Failed', 'danger'], 'held' => ['Held for review', 'warning'],
             'refused' => ['Refused', 'danger'], 'sending' => ['Sending', 'info'], 'queued' => ['Scheduled', 'secondary']];
?>
<div class="list-group-item mail-row d-flex align-items-start gap-2<?= $r['unread'] ? ' mail-unread fw-bold' : '' ?>" id="<?= e($rid) ?>" role="listitem"
     data-cursor="<?= e($r['cursor']) ?>" data-thread="<?= $r['thread_id'] !== null ? (int) $r['thread_id'] : '' ?>"<?= !empty($oob) ? ' hx-swap-oob="outerHTML"' : '' ?>>
    <div class="form-check mb-0 pt-1 mail-check">
        <input class="form-check-input mail-select" type="checkbox" name="thread[]" form="mail-bulk-form" value="<?= $r['thread_id'] !== null ? (int) $r['thread_id'] : '' ?>"
               aria-label="Select <?= e($r['subject'] ?? '(no subject)') ?>"<?= $r['thread_id'] === null ? ' disabled' : '' ?>>
    </div>
    <span class="pt-1 mail-star<?= $r['flagged'] ? ' text-warning' : ' text-muted' ?>" role="img" aria-label="<?= $r['flagged'] ? 'Starred' : 'Not starred' ?>" title="<?= $r['flagged'] ? 'Starred' : 'Not starred' ?>">
        <i class="feather-star<?= $r['flagged'] ? ' mail-starred' : '' ?>"></i></span>
    <?php if ($href !== null): ?><a class="mail-row-link d-flex flex-column flex-md-row flex-grow-1 min-w-0 gap-md-3 text-reset text-decoration-none" href="<?= e($href) ?>"
        hx-get="<?= e($href) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($href) ?>"><?php else: ?><div class="d-flex flex-column flex-md-row flex-grow-1 min-w-0 gap-md-3"><?php endif; ?>
        <span class="mail-who text-truncate"><?= e($who) ?><?php if ((int) $r['message_count'] > 1): ?> <span class="fw-normal text-muted fs-12 mail-count"><?= (int) $r['message_count'] ?></span><?php endif; ?></span>
        <span class="mail-what flex-grow-1 min-w-0 text-truncate">
            <?php if ($r['sensitive']): ?><i class="feather-lock me-1 text-muted mail-sensitive" role="img" title="Marked sensitive" aria-label="Marked sensitive"></i><?php endif; ?>
            <?php if (!empty($r['woke'])): ?><span class="badge bg-soft-warning text-warning me-1 fw-normal mail-woke"><i class="feather-clock me-1"></i>Back from snooze</span><?php endif; ?>
            <?php foreach ($r['labels'] as $l): ?><span class="badge bg-soft-primary text-primary me-1 fw-normal mail-label"><?= e($l['label'] ?? '') ?></span><?php endforeach; ?>
            <span class="mail-subject"><?= e(($r['subject'] ?? '') !== '' ? $r['subject'] : '(no subject)') ?></span>
            <?php if (($r['snippet'] ?? '') !== ''): ?><span class="fw-normal text-muted mail-snippet"> — <?= e($r['snippet']) ?></span><?php endif; ?>
        </span>
    <?= $href !== null ? '</a>' : '</div>' ?>
    <div class="d-flex flex-column align-items-end flex-shrink-0 gap-1 fs-12 mail-meta">
        <span class="text-nowrap mail-date"><?php if ($r['has_attachments']): ?><i class="feather-paperclip me-1 mail-clip" role="img" aria-label="Has attachments"></i><?php endif; ?><?= $when ?></span>
        <?php if ($isOut && isset($outState[$r['outbound_status']])): [$ot, $oc] = $outState[$r['outbound_status']]; ?>
            <span class="badge bg-soft-<?= $oc ?> text-<?= $oc ?> fw-normal mail-out-state" title="<?= e(implode(', ', $r['hold_reasons'])) ?>"><?= e($ot) ?></span>
        <?php endif; ?>
        <?php if ($r['agent_badge'] !== null && !($isOut && $r['outbound_status'] === 'held')): ?>
            <span class="badge bg-soft-info text-info fw-normal mail-agent-badge"><i class="feather-cpu me-1"></i><?= e($r['agent_badge']) ?></span>
        <?php endif; ?>
        <?php if ($isOut && in_array($r['outbound_status'], ['queued', 'failed'], true)): // slice 4: Cancel a scheduled send, Retry a failed one ?>
            <form method="post" action="<?= $r['outbound_status'] === 'queued' ? '/mail/send-cancel' : '/mail/outbox-retry' ?>" class="d-inline" hx-post="<?= $r['outbound_status'] === 'queued' ? '/mail/send-cancel' : '/mail/outbox-retry' ?>" hx-target="#page-content">
                <?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="outbound" value="<?= (int) $r['outbound_id'] ?>"><input type="hidden" name="return_to" value="/m/<?= $mb ?>/v/<?= e($view) ?>">
                <button type="submit" class="btn btn-sm btn-link p-0 fs-12 mail-out-action"><?= $r['outbound_status'] === 'queued' ? 'Cancel' : 'Retry' ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>
