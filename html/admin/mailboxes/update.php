<?php
declare(strict_types=1);
/** Action `mail_mailbox_update` (log `mailbox.update`): display name, intake owner, share with applications (agent/shared), primary overseer. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/admin.php';
ml_handler_begin();
admin_gate('mailboxes.manage');
$pdo = db();
$b = admin_require_mailbox($pdo);
$id = (int) $b['id'];
$me = (int) current_member_id();
$errors = [];
$name = ml_text('display_name', $b['display_name'], 120, 'The display name', $errors, true);
$intake = ml_choice('intake_owner', $b['intake_owner'], array_keys(ADMIN_INTAKE), 'The intake', $errors, false);
$share = req_has('share_with_apps') ? ml_yes('share_with_apps') : (bool) $b['share_with_apps'];
if ($b['kind'] === 'person' && $share !== (bool) $b['share_with_apps']) {
    $errors['share_with_apps'] = 'Whether a person\'s mailbox is shared with other applications is its owner\'s choice, on its settings page.';
}
$primary = req_has('primary_overseer') && req_val('primary_overseer') !== '' ? (ctype_digit((string) req_val('primary_overseer')) ? (int) req_val('primary_overseer') : -1) : null;
if ($primary === -1) {
    $errors['primary_overseer'] = 'Choose the primary overseer from the list of people.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$before = ['display_name' => $b['display_name'], 'intake_owner' => $b['intake_owner'], 'share_with_apps' => (bool) $b['share_with_apps'], 'primary_overseer_id' => $b['primary_overseer_id'] === null ? null : (int) $b['primary_overseer_id']];
ml_guard($pdo, static function () use ($pdo, $b, $id, $me, $name, $intake, $share, $primary): void {
    $pdo->beginTransaction();
    if ($name !== $b['display_name']) {
        $pdo->prepare('SELECT ml_mailbox_display_name_set(:b, :by, :n)')->execute(['b' => $id, 'by' => $me, 'n' => $name]);
    }
    if ($intake !== $b['intake_owner'] || $b['intake_proposed'] !== null) {
        $pdo->prepare('SELECT ml_mailbox_intake_set(:b, :by, :o)')->execute(['b' => $id, 'by' => $me, 'o' => $intake]);
    }
    if ($b['kind'] !== 'person' && $share !== (bool) $b['share_with_apps']) {
        $pdo->prepare('SELECT ml_mailbox_share_set(:b, :by, :on)')->execute(['b' => $id, 'by' => $me, 'on' => $share ? 't' : 'f']);
    }
    if ($primary !== null && $primary !== (int) $b['primary_overseer_id']) {
        if ($b['kind'] !== 'agent') {
            throw new DomainException('Only an agent\'s mailbox has a primary overseer.');
        }
        $pdo->prepare('SELECT ml_primary_overseer_set(:b, :by, :m)')->execute(['b' => $id, 'by' => $me, 'm' => $primary]);
    }
    $pdo->commit();
});
$after = admin_mailbox($pdo, $id);
$diff = ml_diff($before, ['display_name' => $after['display_name'], 'intake_owner' => $after['intake_owner'], 'share_with_apps' => (bool) $after['share_with_apps'],
    'primary_overseer_id' => $after['primary_overseer_id'] === null ? null : (int) $after['primary_overseer_id']]);
log_activity($pdo, 'mailbox.update', 'mailbox', $id, $diff);
ml_done('Saved ' . $b['address'], $id, ml_land('/admin/mailboxes/' . $id, 'saved'), 'mailboxesChanged');
