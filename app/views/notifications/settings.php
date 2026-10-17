<?php /** Screen `notification-settings`. Data: prefs, boxes, textPossible, failures, notice */ ?>
<?= view('shared/header.php', ['id' => 'notification-settings', 'title' => 'Notification settings', 'crumbs' => [[app_name(), '/'], ['Notifications', '/notifications'], ['Settings', null]]]) ?>
<div class="main-content" id="notification-settings-content">
    <?php if (!empty($notice)): ?><?= view('shared/banner.php', ['notice' => $notice]) ?><?php endif; ?>
    <?php if ($failures > 0): ?><div class="alert alert-warning" id="notification-mail-failed" role="status">A notice could not be mailed. Check that your address in the directory is right; the bell still has it.</div><?php endif; ?>
    <div class="alert alert-light border fs-12" id="notification-explain">Notices never contain mail: no subject, no sender, no words of a message. They say how many and where, and a click takes you there. Everything arrives in the bell; mail and text are extra.</div>
    <form method="post" action="/settings/notifications/save.php" id="notification-form" hx-post="/settings/notifications/save.php" hx-swap="none">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-body">
            <div class="form-check form-switch mb-2"><input type="hidden" name="email_enabled" value="no"><input class="form-check-input" type="checkbox" role="switch" name="email_enabled" value="yes" id="email_enabled"<?= $prefs['email_enabled'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="email_enabled">Send me mail for the notices I choose below</label></div>
            <?php if ($textPossible): ?>
            <div class="form-check form-switch"><input type="hidden" name="text_enabled" value="no"><input class="form-check-input" type="checkbox" role="switch" name="text_enabled" value="yes" id="text_enabled"<?= $prefs['text_enabled'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="text_enabled">Send me a text for the notices I choose below</label></div>
            <?php else: ?><div class="fs-12 text-muted" id="notification-text-hint">Texts need a verified phone in your profile.</div><?php endif; ?>
        </div></div>
        <div class="card mb-3"><div class="card-body">
            <h6 class="mb-2">Which notices</h6>
            <input type="hidden" name="kind[]" value=""><?php if ($textPossible): ?><input type="hidden" name="text_kind[]" value=""><?php endif; ?>
            <div class="list-group list-group-flush" id="notification-kinds">
            <?php foreach (NT_KINDS as $k => [$label, $icon, $what]): ?>
                <div class="list-group-item px-0" id="kind-<?= e($k) ?>">
                    <div class="d-flex align-items-start gap-2"><i class="<?= e($icon) ?> mt-1 text-muted" aria-hidden="true"></i>
                        <div class="flex-grow-1"><div class="fw-semibold"><?= e($label) ?></div><div class="fs-12 text-muted"><?= e($what) ?></div></div></div>
                    <div class="d-flex flex-wrap gap-3 mt-2 ms-4">
                        <span class="form-check"><input class="form-check-input" type="checkbox" checked disabled id="in-<?= e($k) ?>"><label class="form-check-label" for="in-<?= e($k) ?>">In MaluMail</label></span>
                        <span class="form-check"><input class="form-check-input" type="checkbox" name="kind[]" value="<?= e($k) ?>" id="mail-<?= e($k) ?>"<?= in_array($k, $prefs['kinds'], true) ? ' checked' : '' ?>><label class="form-check-label" for="mail-<?= e($k) ?>">By mail</label></span>
                        <?php if ($textPossible): ?><span class="form-check"><input class="form-check-input" type="checkbox" name="text_kind[]" value="<?= e($k) ?>" id="text-<?= e($k) ?>"<?= in_array($k, $prefs['text_kinds'], true) ? ' checked' : '' ?>><label class="form-check-label" for="text-<?= e($k) ?>">By text</label></span><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div></div>
        <div class="card mb-3"><div class="card-body">
            <h6 class="mb-1">New mail</h6>
            <div class="fs-12 text-muted mb-2">Tell me when mail arrives in these mailboxes. One notice per mailbox, counting up while you have not read it. None by default.</div>
            <input type="hidden" name="new_mail_mailbox[]" value="">
            <?php if ($boxes === []): ?><div class="text-muted" id="notification-no-boxes">You can open no mailbox yet.</div><?php endif; ?>
            <div id="notification-new-mail">
            <?php foreach ($boxes as $b): $bid = (int) $b['id']; ?>
                <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" role="switch" name="new_mail_mailbox[]" value="<?= $bid ?>" id="nm-<?= $bid ?>"<?= in_array($bid, $prefs['new_mail_mailbox_ids'], true) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="nm-<?= $bid ?>"><?= e($b['address']) ?></label></div>
            <?php endforeach; ?>
            </div>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch" id="notification-save">Save</button>
    </form>
</div>
