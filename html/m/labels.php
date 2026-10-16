<?php
declare(strict_types=1);
/**
 * /m/{mailbox}/labels — the mailbox's labels with colour and conversation count; make one (a `/` nests: Work/Clients; eight swatches),
 * rename or recolour, delete with a confirmation naming how many conversations lose it (screen `labels`,
 * docs/build-specs/search-labels-snooze.md). Anyone who opens the mailbox sees it; the forms are triage's.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!is_member_here()) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$mb = (int) $box['id'];
$labels = ml_labels_of($pdo, $mb);
log_screen_view($pdo, 'labels', $mb);
$canManage = (ML_LEVELS[(string) $box['my_level']] ?? 0) >= 2;
if (wants_json()) {
    respond_screen(['mailbox_id' => $mb, 'can_manage' => $canManage, 'labels' => array_map(static fn (array $l): array => [
        'label_id' => (int) $l['id'], 'label' => $l['label'], 'colour' => $l['colour'], 'conversations' => (int) $l['conversations']], $labels)]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['label_created' => ['success', 'Made the label.'], 'label_saved' => ['success', 'Saved the label.'], 'label_deleted' => ['success', 'Removed the label.']]);
render_screen('Labels — ' . $box['address'], view('mail/labels.php', ['box' => $box, 'labels' => $labels, 'canManage' => $canManage, 'notice' => $notice]),
    ['screen' => 'labels', 'entity' => 'mailbox', 'recordId' => $mb, 'mailboxId' => $mb]);
