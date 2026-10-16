<?php
declare(strict_types=1);
/**
 * Action `mail_template_save` (log `template.save`, the template's id and whether it is shared; undo = restore prior): a canned reply —
 * name, subject (optional), body, shared with everyone using MaluMail or kept to its owner. A person's alone (an agent keeps none: the
 * schema refuses it again); a shared reply someone else owns is changed by a Mail admin only. mail.use.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
ml_handler_begin();
require_human();
if (!has_right('mail.use')) {
    refuse(403, right_refusal('mail.use'));
}
$pdo = db();
$id = request_integer('template');
$cur = null;
if ($id !== null) {
    $cur = ml_template_one($pdo, $id);
    if ($cur === null) {
        refuse(404, 'No such canned reply.');
    }
}
$errors = [];
$name = ml_text('name', $cur['name'] ?? null, 120, 'The name', $errors, true);
$subject = ml_text('subject', $cur['subject'] ?? null, 300, 'The subject', $errors);
$body = ml_text('body', $cur['body'] ?? null, 20000, 'The text', $errors, true);
$shared = ml_yes('shared', (bool) ($cur['shared'] ?? false));
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$me = (int) current_member_id();
$saved = (int) ml_guard($pdo, static function () use ($pdo, $id, $name, $subject, $body, $shared, $me): int {
    $st = $pdo->prepare('SELECT ml_template_save(:t, :n, :s, :b, :sh, :me)');
    $st->bindValue('t', $id, $id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('n', $name);
    $st->bindValue('s', $subject ?? '');
    $st->bindValue('b', $body);
    $st->bindValue('sh', $shared, PDO::PARAM_BOOL);
    $st->bindValue('me', $me, PDO::PARAM_INT);
    $st->execute();
    return (int) $st->fetchColumn();
});
log_activity($pdo, 'template.save', 'mail_template', $saved, ['after' => ['template' => $saved, 'shared' => $shared, 'created' => $id === null]]);
ml_done($id === null ? 'Saved the canned reply' : 'Saved the changes', $saved, ml_land(return_path('/templates'), 'template_saved'), 'templatesChanged');
