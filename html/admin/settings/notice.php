<?php
declare(strict_types=1);
/** Action `mail_notice_save` (log `notice.save`; confirm): the monitoring notice's words. A new version: everyone acknowledges again on their next visit. A Mail admin's alone. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/overview.php';
ml_handler_begin();
admin_gate('settings.manage');
$pdo = db();
$errors = [];
$text = ml_text('text', null, 10000, 'The notice', $errors, true);
if ($text !== null && mb_strlen(trim($text)) < 50) {
    $errors['text'] = 'The notice is at least 50 characters, in plain words.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
$cur = (string) one_value($pdo, 'SELECT notice_text FROM ml_settings WHERE id = 1');
if (trim((string) $text) === trim($cur)) {
    ml_done('The words are the same, so the version stays', 1, ml_land('/admin/settings', 'unchanged', 'notice'));
}
$version = (int) ml_guard($pdo, static function () use ($pdo, $text): int {
    $st = $pdo->prepare('SELECT ml_notice_save(:by, :t)');
    $st->execute(['by' => current_member_id(), 't' => $text]);
    return (int) $st->fetchColumn();
});
log_activity($pdo, 'notice.save', 'settings', 1, ['after' => ['version' => $version, 'length' => mb_strlen(trim((string) $text))]]);
ml_done('Saved the notice as version ' . $version, $version, ml_land('/admin/settings', 'notice', 'notice'), 'settingsChanged');
