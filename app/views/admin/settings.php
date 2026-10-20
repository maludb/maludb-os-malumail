<?php /** MaluMail's settings and the monitoring notice (screen `admin-settings`). Data: s (admin_settings), notice (banner), tz, errors? (field => sentence) */
$errors = $errors ?? [];
$v = static fn (string $k) => $s[$k];
$num = static function (string $k, string $label, int $min, int $max, string $help = '', bool $nullable = false) use ($s, $errors): string {
    return '<div class="col-12 col-md-6"><label class="form-label fs-12 fw-semibold" for="set-' . $k . '">' . e($label) . '</label>'
        . '<input type="number" inputmode="numeric" class="form-control btn-touch' . (isset($errors[$k]) ? ' is-invalid' : '') . '" name="' . $k . '" id="set-' . $k . '" min="' . $min . '" max="' . $max . '" value="' . e((string) ($s[$k] ?? '')) . '"' . ($nullable ? '' : ' required') . '>'
        . ($help !== '' ? '<div class="form-text">' . e($help) . '</div>' : '') . (isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '') . '</div>';
};
$sel = static function (string $k, string $label, array $opts, string $help = '') use ($s): string {
    $o = '';
    foreach ($opts as $val => $text) { $o .= '<option value="' . e((string) $val) . '"' . ((string) $s[$k] === (string) $val ? ' selected' : '') . '>' . e($text) . '</option>'; }
    return '<div class="col-12 col-md-6"><label class="form-label fs-12 fw-semibold" for="set-' . $k . '">' . e($label) . '</label><select class="form-select btn-touch" name="' . $k . '" id="set-' . $k . '">' . $o . '</select>'
        . ($help !== '' ? '<div class="form-text">' . e($help) . '</div>' : '') . '</div>';
};
$chk = static fn (string $k, string $label, string $help = ''): string =>
    '<div class="col-12 col-md-6"><input type="hidden" name="' . $k . '" value="no"><label class="d-flex align-items-start gap-2 btn-touch"><input type="checkbox" class="form-check-input mt-1" name="' . $k . '" id="set-' . $k . '" value="yes"' . ($s[$k] ? ' checked' : '') . '>'
    . '<span><span class="fw-semibold fs-12">' . e($label) . '</span>' . ($help !== '' ? '<span class="d-block form-text mt-0">' . e($help) . '</span>' : '') . '</span></label></div>';
?>
<?= view('shared/header.php', ['id' => 'admin-settings', 'title' => 'Settings', 'crumbs' => [[app_name(), '/'], ['Admin', '/admin'], ['Settings', null]]]) ?>
<div class="main-content" id="admin-settings-content">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <?= view('admin/partials/switch-banner.php') ?>
    <form method="post" action="/admin/settings/save.php" hx-post="/admin/settings/save.php" hx-target="#page-content" hx-confirm="These change every mailbox and every agent's mail. Save?" id="admin-settings-form">
        <?= csrf_field() ?>
        <fieldset class="card mb-3"><div class="card-body" id="settings-reading">
            <legend class="fs-6 fw-bold">Reading and writing</legend>
            <p class="text-muted fs-12">What a new person's mail does until they choose otherwise, and how the index keeps text.</p>
            <div class="row g-3">
                <?= $num('undo_send_seconds', 'Undo send (seconds)', 0, 30, 'Each person may choose their own, 0 to 30.') ?>
                <?= $sel('images_default', 'Remote images', ['ask' => 'Ask first', 'always' => 'Always load'], 'Asking first stops a sender from learning that you opened a message.') ?>
                <?= $num('index_text_max_kb', 'Text kept per message in the index (KB)', 10, 1024, 'More makes search deeper and the index bigger.') ?>
                <?= $num('max_attachments_mb', 'Attachments per message (MB)', 1, 9, 'MaluMail\'s limit is about 9.8 MB once encoded.') ?>
                <?= $num('draft_save_seconds', 'A draft is saved every (seconds)', 5, 120) ?>
                <?= $num('poll_visible_seconds', 'Look for new mail while the page is visible (seconds)', 5, 300) ?>
                <?= $num('poll_hidden_seconds', 'Look for new mail while it is hidden (seconds)', 15, 900, 'Never shorter than the visible interval.') ?>
            </div>
        </div></fieldset>
        <fieldset class="card mb-3"><div class="card-body" id="settings-held">
            <legend class="fs-6 fw-bold">Held mail</legend>
            <p class="text-muted fs-12">A held message waits for a person this long, then expires and the agent is told.</p>
            <div class="row g-3"><?= $num('held_expiry_hours', 'Held mail expires after (hours)', 1, 720) ?></div>
        </div></fieldset>
        <fieldset class="card mb-3"><div class="card-body" id="settings-policy">
            <legend class="fs-6 fw-bold">A new agent mailbox's policy</legend>
            <p class="text-muted fs-12">What a new agent mailbox starts with. An overseer changes one agent's within these and never raises the business-wide budget.</p>
            <div class="row g-3">
                <?= $sel('default_send_mode', 'Send mode', ['hold_all' => 'Hold every message', 'internal_auto' => 'Send inside the business, hold the rest', 'allowlist_auto' => 'Send to an allow-list, hold the rest', 'auto' => 'Send on its own'], 'Applies when a mailbox is made, not to existing ones.') ?>
                <?= $sel('default_wake_from', 'Whose mail wakes the agent', ['none' => 'No one', 'internal' => 'The business\'s own people', 'internal_and_known' => 'Its own people and known senders', 'all_authenticated' => 'Anyone who proves their sender'], 'The rest waits in Unscreened for a person.') ?>
                <?= $chk('default_first_contact_review', 'Hold a first contact for review', 'A message to someone this mailbox never wrote to.') ?>
                <?= $chk('default_attachments_review', 'Hold messages with files for review') ?>
                <?= $chk('default_wake_on_mail', 'Arriving mail wakes the agent', 'One turn, with the message\'s id, never its text as an instruction.') ?>
                <?= $chk('default_disclosure', 'Carry the disclosure line', 'A line saying an AI agent wrote the message.') ?>
                <?= $num('default_max_external_per_day', 'Messages leaving the business a day', 0, 10000, 'At most 24 times the hourly cap.') ?>
                <?= $num('default_max_per_hour', 'Messages an hour', 0, 10000) ?>
                <?= $num('default_max_per_thread_per_day', 'Messages a day in one conversation', 1, 1000, 'Stops a loop.') ?>
                <div class="col-12"><label class="form-label fs-12 fw-semibold" for="set-disclosure_text">The disclosure line</label>
                    <input type="text" class="form-control btn-touch<?= isset($errors['disclosure_text']) ? ' is-invalid' : '' ?>" name="disclosure_text" id="set-disclosure_text" maxlength="500" value="<?= e((string) $v('disclosure_text')) ?>" required>
                    <div class="form-text">Must contain {agent}, which becomes the agent's name.</div><?= isset($errors['disclosure_text']) ? '<div class="invalid-feedback d-block">' . e($errors['disclosure_text']) . '</div>' : '' ?></div>
            </div>
        </div></fieldset>
        <fieldset class="card mb-3" id="budget"><div class="card-body" id="settings-budget">
            <legend class="fs-6 fw-bold">The agents' business-wide budget</legend>
            <p class="text-muted fs-12" id="settings-budget-today">Today: <b><?= (int) $s['budget_used'] ?> of <?= (int) $s['budget_today'] ?></b> messages to the outside, all agents together over the last day<?= $s['agent_external_budget'] === null ? ' (the warm-up: 20, 50, 100, then 200 a day from the first agent message)' : ' (the Mail admins\' cap)' ?>.</p>
            <div class="row g-3"><?= $num('agent_external_budget', 'A cap of your own (empty = the warm-up)', 0, 100000, 'Overseers cannot raise it.', true) ?></div>
        </div></fieldset>
        <fieldset class="card mb-3 border-danger" id="switches"><div class="card-body" id="settings-switches">
            <legend class="fs-6 fw-bold">The two switches</legend>
            <div class="row g-3">
                <?= $chk('hold_all_agents', 'Hold every agent\'s mail', 'Every agent\'s message waits for a person, until you switch it off.') ?>
                <?= $chk('hold_all_external', 'Hold every agent\'s mail to anyone outside the business', 'Every agent\'s message to the outside waits for a person.') ?>
            </div>
            <p class="text-muted fs-12 mt-3 mb-0">One agent is stopped from its card in Oversight (Pause). The hard stop at the mail server is MaluMail's (MM10); until then, suspending the mailbox under Mailboxes is the hard stop.</p>
        </div></fieldset>
        <fieldset class="card mb-3" id="mm3"><div class="card-body" id="settings-mm3">
            <legend class="fs-6 fw-bold">MM3: sender identity</legend>
            <?= $chk('mm3_identity_enforced', 'MaluMail enforces that a mailbox sends only as itself', 'Turn on only when MaluMail confirms that a mailbox can no longer send as another: internal mail then wakes agents without this application having sent it.') ?>
            <div class="mt-2"><label class="form-label fs-12 fw-semibold" for="set-mm3_confirm">To turn it on, type MM3</label>
                <input type="text" class="form-control btn-touch<?= isset($errors['mm3_confirm']) ? ' is-invalid' : '' ?>" name="mm3_confirm" id="set-mm3_confirm" maxlength="10" autocomplete="off" style="max-width:10rem">
                <?= isset($errors['mm3_confirm']) ? '<div class="invalid-feedback d-block">' . e($errors['mm3_confirm']) . '</div>' : '' ?></div>
        </div></fieldset>
        <fieldset class="card mb-3"><div class="card-body" id="settings-clerk">
            <legend class="fs-6 fw-bold">The Mail Clerk</legend>
            <p class="text-muted fs-12">The hour (in the business's time zone) the overseers' morning note is cut at.</p>
            <div class="row g-3"><div class="col-12 col-md-6"><label class="form-label fs-12 fw-semibold" for="set-clerk_note_at">The Clerk's hour</label>
                <input type="time" class="form-control btn-touch<?= isset($errors['clerk_note_at']) ? ' is-invalid' : '' ?>" name="clerk_note_at" id="set-clerk_note_at" value="<?= e((string) $s['clerk_hhmm']) ?>" required style="max-width:10rem">
                <?= isset($errors['clerk_note_at']) ? '<div class="invalid-feedback d-block">' . e($errors['clerk_note_at']) . '</div>' : '' ?></div></div>
            <dl class="row fs-12 mt-3 mb-0" id="settings-named-agents">
                <dt class="col-6 col-md-3 fw-normal text-muted">The Expert</dt><dd class="col-6 col-md-9"><?= $s['expert_name'] !== null ? e($s['expert_name']) : 'none named' ?></dd>
                <dt class="col-6 col-md-3 fw-normal text-muted">The Clerk</dt><dd class="col-6 col-md-9"><?= $s['clerk_name'] !== null ? e($s['clerk_name']) : 'none named' ?><?php if (admin_os_url() !== ''): ?> · <a href="<?= e(admin_os_url() . '/agents') ?>" target="_blank" rel="noopener" id="settings-os-agents">Hires, grants and duties — in the OS</a><?php endif; ?></dd>
            </dl>
        </div></fieldset>
        <button type="submit" class="btn btn-primary btn-touch w-100 w-md-auto mb-4" id="admin-settings-save">Save settings</button>
    </form>

    <form method="post" action="/admin/settings/notice.php" hx-post="/admin/settings/notice.php" hx-target="#page-content" hx-confirm="Everyone acknowledges the new version on their next visit. Save?" id="admin-notice-form">
        <?= csrf_field() ?>
        <div class="card mb-4" id="notice"><div class="card-body">
            <h6 class="fw-bold">The monitoring notice</h6>
            <p class="text-muted fs-12" id="notice-state">Version <b><?= (int) $s['notice_version'] ?></b>, changed <?= e(format_ts($s['notice_updated_at'], $tz)) ?>.
                <b id="notice-acked"><?= (int) $s['acked'] ?> of <?= (int) $s['people'] ?></b> people with MaluMail have acknowledged it. A change of the words is a new version: everyone acknowledges again.</p>
            <label class="form-label fs-12 fw-semibold" for="notice-text">The notice, in plain words</label>
            <textarea class="form-control<?= isset($errors['text']) ? ' is-invalid' : '' ?>" name="text" id="notice-text" rows="8" minlength="50" maxlength="10000" required><?= e((string) $s['notice_text']) ?></textarea>
            <?= isset($errors['text']) ? '<div class="invalid-feedback d-block">' . e($errors['text']) . '</div>' : '' ?>
            <button type="submit" class="btn btn-primary btn-touch mt-3" id="admin-notice-save">Save the notice</button>
        </div></div>
    </form>
</div>
