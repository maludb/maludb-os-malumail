<?php
declare(strict_types=1);
/** Action `mail_template_delete` (log `template.delete`, the template's id and whether it was shared): a canned reply is archived. Its owner, or a Mail admin for a shared one. A person's alone. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
require_human();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$id = request_integer('template');
$cur = $id === null ? null : ml_template_one($pdo, $id);
if ($cur === null) {
    refuse(404, 'No such canned reply.');
}
$me = (int) current_member_id();
ml_guard($pdo, static function () use ($pdo, $id, $me): void {
    $st = $pdo->prepare('SELECT ml_template_delete(:t, :me)');
    $st->execute(['t' => $id, 'me' => $me]);
});
log_activity($pdo, 'template.delete', 'mail_template', $id, ['after' => ['template' => $id, 'shared' => (bool) $cur['shared']]]);
ml_done('Deleted the canned reply', $id, ml_land(return_path('/templates'), 'template_deleted'), 'templatesChanged');
