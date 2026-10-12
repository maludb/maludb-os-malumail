<?php /** Create a mailbox at MaluMail, or connect an existing one (screen `admin-mailbox-new`). Data: domains, pre */
$kindRadios = static function (string $prefix, string $checked, array $kinds): string {
    $out = '<div class="d-flex flex-wrap gap-2" role="radiogroup" aria-label="Kind">';
    foreach ($kinds as $k => $hint) {
        $out .= '<label class="d-flex align-items-start gap-2 border rounded px-3 py-2 btn-touch flex-fill" for="' . $prefix . '-kind-' . $k . '">'
            . '<input type="radio" class="form-check-input mt-1 ml-kind" name="kind" value="' . $k . '" id="' . $prefix . '-kind-' . $k . '"' . ($checked === $k ? ' checked' : '') . '>'
            . '<span><span class="fw-semibold d-block">' . e(ADMIN_KINDS[$k]) . '</span><span class="fs-11 text-muted">' . e($hint) . '</span></span></label>';
    }
    return $out . '</div>';
};
$owners = static function (string $prefix, ?int $owner): string {
    return '<div data-kind-show="person">' . form_picker($prefix, 'owner_person', 'Owner (a person)', 'member', $owner, [], ['help' => 'Whose mailbox it is. Their new password is shown once, for their phone and computer.']) . '</div>'
        . '<div data-kind-show="agent">' . form_picker($prefix, 'owner_agent', 'Owner (an agent)', 'agent', $owner, [], ['help' => 'The hired agent whose mailbox it is. Its password is sealed and never shown.']) . '</div>';
};
$overseers = static function (string $prefix): string {
    return '<div data-kind-show="agent">'
        . form_picker($prefix, 'primary_overseer', 'Primary overseer', 'member', null, [], ['help' => 'The person who reviews the agent\'s held mail first and acts for its mail turns.'])
        . form_picker($prefix, 'overseer_2', 'Another overseer', 'member', null)
        . form_picker($prefix, 'overseer_3', 'Another overseer', 'member', null) . '</div>';
};
?>
<?= view('shared/header.php', ['id' => 'admin-mailbox-new', 'title' => 'New mailbox', 'crumbs' => [[app_name(), '/'], ['Admin', '/admin'], ['Mailboxes', '/admin/mailboxes'], ['New', null]],
    'back' => ['/admin/mailboxes', 'Mailboxes']]) ?>
<div class="main-content" id="admin-mailbox-new-content">
    <?php if ($domains === []): ?>
        <div class="alert alert-warning" id="admin-mailbox-new-no-domain">No domain is served yet. <?= hx_link('/admin/domains', 'Serve a domain', 'alert-link') ?> first.</div>
    <?php else: ?>
    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <form method="post" action="/admin/mailboxes/create.php" hx-post="/admin/mailboxes/create.php" class="card ml-kind-form" id="mailbox-new-form">
                <div class="card-header"><h5 class="card-title mb-0">A new mailbox at MaluMail</h5></div>
                <div class="card-body">
                    <?= csrf_field() ?>
                    <div class="mb-3"><?= $kindRadios('mailbox-new', $pre['kind'], ['agent' => 'For a hired agent; its mail is governed and overseen', 'person' => 'For a person; shown once, kept by them', 'shared' => 'info@, sales@ — opened by the people given rows']) ?></div>
                    <div class="row g-2 mb-3">
                        <div class="col-7"><label class="form-label fs-12 text-muted" for="mailbox-new-field-local-part">Address</label>
                            <input type="text" name="local_part" id="mailbox-new-field-local-part" class="form-control btn-touch" required maxlength="64" pattern="[A-Za-z0-9._+\-]+" value="<?= e($pre['local_part']) ?>" placeholder="scheduler" autocapitalize="none"></div>
                        <div class="col-5"><label class="form-label fs-12 text-muted" for="mailbox-new-field-domain">Domain</label>
                            <select name="domain" id="mailbox-new-field-domain" class="form-select btn-touch"><?php foreach ($domains as $d): ?><option value="<?= e($d['domain']) ?>" <?= $pre['domain'] === $d['domain'] ? 'selected' : '' ?>>@<?= e($d['domain']) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="mb-3"><label class="form-label fs-12 text-muted" for="mailbox-new-field-display-name">Display name</label>
                        <input type="text" name="display_name" id="mailbox-new-field-display-name" class="form-control btn-touch" maxlength="120" placeholder="Shown beside the address"></div>
                    <?= $owners('mailbox-new', $pre['owner']) ?>
                    <?= $overseers('mailbox-new') ?>
                    <div class="mb-3"><label class="form-label fs-12 text-muted" for="mailbox-new-field-quota">Quota (GiB)</label>
                        <input type="number" name="quota_gib" id="mailbox-new-field-quota" class="form-control btn-touch" min="1" max="50" value="2" style="max-width:8rem"></div>
                    <button type="submit" class="btn btn-primary btn-touch w-100" id="mailbox-new-create-btn">Create the mailbox</button>
                </div>
            </form>
        </div>
        <div class="col-12 col-xl-5">
            <form method="post" action="/admin/mailboxes/connect.php" hx-post="/admin/mailboxes/connect.php" class="card ml-kind-form" id="mailbox-connect-form"
                  hx-confirm="Connecting an agent's or a shared mailbox gives it a new password at MaluMail: any phone or desktop still using the old one stops until given the new. Connect it?">
                <div class="card-header"><h5 class="card-title mb-0">Connect an existing mailbox</h5></div>
                <div class="card-body">
                    <?= csrf_field() ?>
                    <p class="fs-12 text-muted">A mailbox MaluMail already holds on a served domain. An agent's or a shared one gets a new password, sealed for the webmail; a person's is registered without one until MaluMail issues application passwords (MM1).</p>
                    <div class="mb-3"><?= $kindRadios('mailbox-connect', 'shared', ['shared' => 'info@, sales@', 'agent' => 'A hired agent\'s', 'person' => 'Waits on MM1']) ?></div>
                    <div class="mb-3"><label class="form-label fs-12 text-muted" for="mailbox-connect-field-address">Address</label>
                        <input type="email" name="address" id="mailbox-connect-field-address" class="form-control btn-touch" required placeholder="info@<?= e($domains[0]['domain']) ?>" autocapitalize="none"></div>
                    <div class="mb-3"><label class="form-label fs-12 text-muted" for="mailbox-connect-field-display-name">Display name</label>
                        <input type="text" name="display_name" id="mailbox-connect-field-display-name" class="form-control btn-touch" maxlength="120"></div>
                    <?= $owners('mailbox-connect', null) ?>
                    <?= $overseers('mailbox-connect') ?>
                    <button type="submit" class="btn btn-light btn-touch w-100" id="mailbox-connect-btn">Connect it</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>
(function () {
    document.querySelectorAll('.ml-kind-form').forEach(function (form) {
        function show() {
            var k = (form.querySelector('.ml-kind:checked') || {}).value || '';
            form.querySelectorAll('[data-kind-show]').forEach(function (el) { el.hidden = el.getAttribute('data-kind-show') !== k; });
        }
        form.addEventListener('change', function (ev) { if (ev.target.classList.contains('ml-kind')) { show(); } });
        show();
    });
})();
</script>
