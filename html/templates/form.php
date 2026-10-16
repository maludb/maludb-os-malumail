<?php
declare(strict_types=1);
/**
 * /templates/new (screen `template-new`; params name, body) and /templates/{id}/edit (screen `template-edit`): a canned reply — name,
 * subject (optional), body, shared with everyone using MaluMail. A shared reply someone else owns opens read-only ("Owned by …") unless the
 * person is a Mail admin. Delete asks first.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!is_member_here() || !has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$id = request_integer('id');
$t = null;
if ($id !== null) {
    $t = ml_template_one($pdo, $id);
    if ($t === null) {
        respond_not_found('No such canned reply.');
    }
}
$editable = $t === null || $t['mine'] || has_right('settings.manage');
log_screen_view($pdo, $t === null ? 'template-new' : 'template-edit');
if (wants_json()) {
    respond_screen($t === null ? ['prefill' => ['name' => request_string('name'), 'body' => request_string('body')]]
        : ['template' => ['template_id' => (int) $t['template_id'], 'name' => $t['name'], 'subject' => $t['subject'], 'body' => $t['body'], 'shared' => $t['shared'], 'mine' => $t['mine'], 'owner' => $t['owner_name']], 'editable' => $editable]);
}
$isHuman = (current_member()['member_kind'] ?? '') === 'human';
render_screen($t === null ? 'New canned reply' : $t['name'], view('templates/form.php', ['t' => $t, 'editable' => $editable && $isHuman,
    'pre' => ['name' => request_string('name'), 'body' => request_string('body')]]), ['screen' => $t === null ? 'template-new' : 'template-edit', 'entity' => 'email_template', 'recordId' => $id, 'activeNav' => 'templates']);
