<?php /** One mailbox for a Mail admin (screen `admin-mailbox-view`). Data: b, overseers, revealed (a person's new password, once) or null, notice, tz */
$id = (int) $b['id'];
[$ct, $cc] = admin_credential_chip($b);
$suspended = $b['suspended_at'] !== null;
$form = static fn (string $action, string $inner, string $confirm = '', string $fid = ''): string =>
    '<form method="post" action="' . e($action) . '" hx-post="' . e($action) . '"' . ($confirm !== '' ? ' hx-confirm="' . e($confirm) . '"' : '') . ($fid !== '' ? ' id="' . e($fid) . '"' : '') . '>'
    . csrf_field() . '<input type="hidden" name="mailbox" value="' . $id . '">' . $inner . '</form>';
?>
<?= view('shared/header.php', ['id' => 'admin-mailbox-view', 'title' => $b['address'], 'crumbs' => [[app_name(), '/'], ['Admin', '/admin'], ['Mailboxes', '/admin/mailboxes'], [$b['address'], null]],
    'back' => ['/admin/mailboxes', 'Mailboxes']]) ?>
<div class="main-content" id="admin-mailbox-view-content">
    <?= view('admin/partials/switch-banner.php') ?>
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <?php if ($revealed !== null): ?>
        <div class="alert alert-warning" id="mailbox-revealed" role="status">
            <div class="fw-semibold mb-1">The password for <?= e($revealed['address']) ?> — give it to <?= e($b['owner_name'] ?? 'its owner') ?> for their phone and computer. It is not shown again.</div>
            <code class="d-block text-break user-select-all fs-5" id="mailbox-revealed-password"><?= e($revealed['password']) ?></code>
            <div class="fs-11 text-muted mt-1">The webmail keeps no copy: until MaluMail issues application passwords (MM1), this mailbox is read on the person's own devices.</div>
        </div>
    <?php endif; ?>
    <?php if ($suspended): ?>
        <div class="alert alert-dark" id="mailbox-suspended-banner">Suspended since <?= e(format_ts((string) $b['suspended_at'], $tz)) ?>: MaluMail refuses its mail and its logins.</div>
    <?php endif; ?>
    <?php if ($b['credential_status'] === 'failed'): ?>
        <div class="alert alert-danger" id="mailbox-credential-failed-banner">The mail server refused this mailbox's credential, so it is never tried again. Reconnect it below to give it a new one.</div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-12 col-xl-6">
            <div class="card mb-3" id="mailbox-facts"><div class="card-header"><h5 class="card-title mb-0"><?= e($b['display_name']) ?></h5></div>
                <div class="card-body fs-13">
                    <div class="mb-1"><span class="text-muted">Kind</span> · <?= e(ADMIN_KINDS[$b['kind']]) ?><?= $b['owner_name'] !== null ? ' — ' . e($b['owner_name']) : '' ?></div>
                    <div class="mb-1"><span class="text-muted">Credential</span> · <span class="badge bg-soft-<?= $cc ?> text-<?= $cc ?>" id="mailbox-credential-chip"><?= e($ct) ?></span>
                        <?php if ($b['credential_ok_at'] !== null): ?><span class="fs-11 text-muted">last worked <?= e(format_ts((string) $b['credential_ok_at'], $tz)) ?></span><?php endif; ?></div>
                    <div class="mb-1"><span class="text-muted">Sync</span> · <?= e($b['sync_state']) ?><?= $b['last_error'] !== null ? ' — ' . e($b['last_error']) : '' ?></div>
                    <div class="mb-1"><span class="text-muted">Quota</span> · <?= e(admin_bytes($b['quota_bytes'])) ?><?= $b['quota_used_bytes'] !== null ? ', ' . e(admin_bytes($b['quota_used_bytes'])) . ' used' : '' ?></div>
                    <div class="mb-1"><span class="text-muted">Created</span> · <?= e(format_ts((string) $b['created_at'], $tz, 'M j, Y')) ?></div>
                </div>
            </div>
            <?php if ($b['kind'] === 'agent'): ?>
            <div class="card mb-3" id="mailbox-overseers"><div class="card-header"><h5 class="card-title mb-0">Overseers</h5></div>
                <div class="card-body fs-13">
                    <?php if ($overseers === []): ?><div class="text-muted">No person oversees this agent's mail yet.</div><?php endif; ?>
                    <?php foreach ($overseers as $o): ?>
                        <div class="d-flex align-items-center gap-2 mb-1" id="mailbox-overseer-<?= (int) $o['member_id'] ?>"><i class="feather-user"></i><?= e($o['display_name']) ?>
                            <?php if ((int) $o['member_id'] === (int) $b['primary_overseer_id']): ?><span class="badge bg-soft-primary text-primary">primary</span><?php endif; ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="fs-12 mb-3"><?= hx_link('/m/' . $id . '/access', 'Who can open it — the Access page', '', 'id="mailbox-access-link"') ?></div>
        </div>
        <div class="col-12 col-xl-6">
            <div class="card mb-3" id="mailbox-update"><div class="card-header"><h5 class="card-title mb-0">Settings</h5></div><div class="card-body">
                <?= $form('/admin/mailboxes/update.php',
                    '<div class="mb-3"><label class="form-label fs-12 text-muted" for="mailbox-update-display-name">Display name</label><input type="text" name="display_name" id="mailbox-update-display-name" class="form-control btn-touch" maxlength="120" required value="' . e($b['display_name']) . '"></div>'
                    . '<div class="mb-3"><label class="form-label fs-12 text-muted" for="mailbox-update-intake">Intake: another reader takes this mailbox\'s new mail</label><select name="intake_owner" id="mailbox-update-intake" class="form-select btn-touch">'
                    . implode('', array_map(static fn (string $k, string $l): string => '<option value="' . $k . '"' . ($b['intake_owner'] === $k ? ' selected' : '') . '>' . e($l) . ($b['intake_proposed'] === $k && $b['intake_owner'] !== $k ? ' (proposed)' : '') . '</option>', array_keys(ADMIN_INTAKE), ADMIN_INTAKE)) . '</select>'
                    . ($b['intake_proposed'] !== null && $b['intake_owner'] !== $b['intake_proposed'] ? '<div class="form-text" id="mailbox-update-intake-proposed">MaluMail\'s webhook record suggests: ' . e(ADMIN_INTAKE[$b['intake_proposed']] ?? $b['intake_proposed']) . '. An intake mailbox is never marked read here and its Inbox is never moved.</div>' : '') . '</div>'
                    . ($b['kind'] !== 'person' ? '<input type="hidden" name="share_with_apps" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-3" for="mailbox-update-share"><input type="checkbox" class="form-check-input mt-0" name="share_with_apps" value="yes" id="mailbox-update-share"' . ($b['share_with_apps'] ? ' checked' : '') . '><span class="fs-12">Share with the business\'s other applications (the CRM, Help Desk: subjects and short snippets)</span></label>' : '<div class="fs-12 text-muted mb-3">Whether a person\'s mailbox is shared with other applications is its owner\'s choice, on its settings page.</div>')
                    . '<button type="submit" class="btn btn-primary btn-touch" id="mailbox-update-save">Save</button>', '', 'mailbox-update-form') ?>
            </div></div>
            <div class="card mb-3" id="mailbox-quota"><div class="card-header"><h5 class="card-title mb-0">Quota</h5></div><div class="card-body">
                <?= $form('/admin/mailboxes/quota.php', '<div class="d-flex gap-2 align-items-center"><input type="number" name="quota_gib" min="1" max="50" required class="form-control btn-touch" style="max-width:7rem" id="mailbox-quota-gib" value="' . ($b['quota_bytes'] !== null ? (int) round(((int) $b['quota_bytes']) / 1073741824) : 2) . '"><span class="fs-12">GiB</span><button type="submit" class="btn btn-light btn-touch" id="mailbox-quota-save">Change</button></div>', '', 'mailbox-quota-form') ?>
            </div></div>
            <div class="card mb-3" id="mailbox-danger"><div class="card-header"><h5 class="card-title mb-0">Password and access</h5></div><div class="card-body d-grid gap-2">
                <?= $form('/admin/mailboxes/reset.php', '<button type="submit" class="btn btn-light btn-touch w-100" id="mailbox-reset-btn">Reset the password</button>',
                    $b['kind'] === 'person' ? 'Reset ' . $b['address'] . '\'s password? Its owner\'s phone and computer stop until given the new one, which is shown once here.' : 'Reset ' . $b['address'] . '\'s password? The webmail is given the new one; any other client using the old one stops.', 'mailbox-reset-form') ?>
                <?php if ($b['kind'] !== 'person' && ($b['credential_status'] === null || $b['credential_status'] !== 'ok')): ?>
                    <?= $form('/admin/mailboxes/connect.php', '<input type="hidden" name="address" value="' . e($b['address']) . '"><input type="hidden" name="kind" value="' . e($b['kind']) . '"><button type="submit" class="btn btn-light btn-touch w-100" id="mailbox-reconnect-btn">Reconnect the webmail</button>',
                        'Reconnect ' . $b['address'] . '? It is given a new password at MaluMail.', 'mailbox-reconnect-form') ?>
                <?php endif; ?>
                <?php if ($suspended): ?>
                    <?= $form('/admin/mailboxes/reactivate.php', '<button type="submit" class="btn btn-primary btn-touch w-100" id="mailbox-reactivate-btn">Reactivate</button>', '', 'mailbox-reactivate-form') ?>
                <?php else: ?>
                    <?= $form('/admin/mailboxes/suspend.php', '<label class="form-label fs-12 text-muted" for="mailbox-suspend-reason">Why (kept in the trail)</label><input type="text" name="reason" id="mailbox-suspend-reason" class="form-control btn-touch mb-2" maxlength="200" required><button type="submit" class="btn btn-outline-danger btn-touch w-100" id="mailbox-suspend-btn">Suspend</button>',
                        'Suspend ' . $b['address'] . '? MaluMail refuses its mail and its logins until it is reactivated.', 'mailbox-suspend-form') ?>
                <?php endif; ?>
            </div></div>
        </div>
    </div>
</div>
