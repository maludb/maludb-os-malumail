<?php
declare(strict_types=1);
/** /templates?q= — my canned replies and the shared ones as cards (screen `templates`, docs/build-specs/search-labels-snooze.md). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
if (!is_member_here() || !has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$q = request_string('q');
$rows = ml_templates_list($pdo, $q);
log_screen_view($pdo, 'templates');
$box = ml_default_mailbox();
if (wants_json()) {
    respond_screen(['templates' => array_map(static fn (array $t): array => ['template_id' => (int) $t['template_id'], 'name' => $t['name'], 'subject' => $t['subject'], 'shared' => $t['shared'],
        'mine' => $t['mine'], 'owner' => $t['owner_name'], 'used' => (int) $t['usage_count']], $rows)]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['template_saved' => ['success', 'Saved the canned reply.'], 'template_deleted' => ['success', 'Deleted the canned reply.']]);
render_screen('Canned replies', view('templates/list.php', ['rows' => $rows, 'q' => $q, 'notice' => $notice, 'composeBox' => $box !== null && (ML_LEVELS[(string) $box['my_level']] ?? 0) >= 3 ? (int) $box['id'] : null]),
    ['screen' => 'templates', 'entity' => 'email_template', 'activeNav' => 'templates']);
