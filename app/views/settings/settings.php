<?php /** A mailbox's settings (screen `mailbox-settings`). Data: ctx, s (mailbox_settings_read), switches (bool), identities (list), notice, tz */
$box = $ctx['row'];
$mb = (int) $ctx['id'];
$full = $ctx['level'] === 'full';
$yn = static fn (string $k, bool $on, string $label, string $help = ''): string =>
    '<div class="mb-3 pb-3 border-bottom" id="settings-row-' . $k . '"><input type="hidden" name="' . $k . '" value="no">'
    . '<label class="d-flex align-items-start gap-2 border rounded px-3 py-2 btn-touch" for="settings-' . $k . '"><input type="checkbox" class="form-check-input mt-1" name="' . $k . '" value="yes" id="settings-' . $k . '"' . ($on ? ' checked' : '') . '>'
    . '<span><span class="fw-semibold d-block">' . e($label) . '</span>' . ($help !== '' ? '<span class="fs-12 text-muted d-block">' . e($help) . '</span>' : '') . '</span></label></div>';
$sel = static fn (string $k, string $label, array $opts, string $cur): string =>
    '<div class="mb-3 pb-3 border-bottom" id="settings-row-' . $k . '"><label class="form-label fw-semibold mb-1" for="settings-' . $k . '">' . e($label) . '</label>'
    . '<select name="' . $k . '" id="settings-' . $k . '" class="form-select btn-touch" style="max-width:18rem">'
    . implode('', array_map(static fn ($v, $l): string => '<option value="' . e((string) $v) . '"' . ((string) $v === $cur ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($opts), $opts)) . '</select></div>';
?>
<?= view('shared/header.php', ['id' => 'mailbox-settings', 'title' => 'Settings — ' . $box['address'], 'crumbs' => [[app_name(), '/'], [$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $mb . '/v/inbox'], ['Settings', null]],
    'back' => [$ctx['level'] === 'none' ? '/admin/mailboxes/' . $mb : '/m/' . $mb . '/v/inbox', 'the mailbox']]) ?>
<div class="main-content" id="mailbox-settings-content">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <form method="post" action="/mail/settings-save" hx-post="/mail/settings-save" hx-target="#page-content" id="settings-form"><?= csrf_field() ?>
        <input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="return_to" value="/m/<?= $mb ?>/settings">
        <div class="row g-3">
            <?php if ($full): ?>
            <div class="col-12 col-xl-6">
                <div class="card mb-3" id="settings-reading"><div class="card-header"><h5 class="card-title mb-0">Reading</h5></div><div class="card-body">
                    <?= $yn('conversation_view', $s['conversation_view'], 'Show mail as conversations', 'Replies are grouped under one subject, as in Gmail.') ?>
                    <?= $sel('images', 'Remote images', ['ask' => 'Ask me first', 'always' => 'Always show'], $s['images']) ?>
                    <?= $sel('density', 'Density', ['comfortable' => 'Comfortable', 'compact' => 'Compact'], $s['density']) ?>
                    <?= $sel('page_size', 'Conversations on a page', [25 => '25', 50 => '50', 100 => '100'], (string) $s['page_size']) ?>
                    <?= $yn('shortcuts', $s['shortcuts'], 'Keyboard shortcuts') ?>
                </div></div>
                <div class="card mb-3" id="settings-sending"><div class="card-header"><h5 class="card-title mb-0">Sending</h5></div><div class="card-body">
                    <div class="mb-0"><label class="form-label fw-semibold mb-1" for="settings-undo_seconds">Undo send lasts (seconds)</label>
                        <input type="number" name="undo_seconds" id="settings-undo_seconds" class="form-control btn-touch" style="max-width:9rem" min="0" max="30" value="<?= (int) $s['undo_seconds'] ?>">
                        <div class="form-text">0 sends at once; up to 30 seconds to take a message back.</div></div>
                </div></div>
            </div>
            <?php endif; ?>
            <div class="col-12 col-xl-6">
                <?php if ($switches): ?>
                <div class="card mb-3" id="settings-privacy"><div class="card-header"><h5 class="card-title mb-0">Privacy</h5></div><div class="card-body">
                    <?= $yn('ai_read', (bool) $box['ai_read'], 'Let the Mail Expert read my mail when I ask', 'When you ask the Mail Expert about your mail, it may read this mailbox — only in conversations you start.') ?>
                    <?= $yn('share_with_apps', (bool) $box['share_with_apps'], 'Share with applications', 'Other applications of the business (the CRM, Help Desk) may see subjects and short snippets of conversations with an address.') ?>
                </div></div>
                <?php endif; ?>
                <?php if ($full): ?>
                <div class="card mb-3" id="settings-identities"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Addresses and signatures</h5>
                    <?= hx_link('/m/' . $mb . '/identities/new', '<i class="feather-plus"></i>', 'btn btn-sm btn-light btn-touch', 'id="settings-identity-new" title="Add an identity" aria-label="Add an identity"') ?></div>
                    <div class="list-group list-group-flush">
                    <?php foreach ($identities as $i): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="settings-identity-<?= (int) ($i['id'] ?? 0) ?>">
                            <div class="min-w-0 text-break"><?= e((string) $i['address']) ?><?php if ($i['is_default']): ?> <span class="badge bg-soft-primary text-primary">default</span><?php endif; ?>
                                <div class="fs-12 text-muted"><?= e((string) ($i['display_name'] ?? '')) ?></div></div>
                            <?php if ($i['id'] !== null): ?><?= hx_link('/m/' . $mb . '/identities/' . (int) $i['id'] . '/edit', 'Edit', 'btn btn-sm btn-light btn-touch') ?><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div></div>
                <?php endif; ?>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-touch" id="settings-save">Save</button>
    </form>
</div>
