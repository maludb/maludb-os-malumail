<?php
declare(strict_types=1);
/** Action `mail_settings_save` (log `settings.save`; confirm): the settings, only the keys sent — one SQL function with a key allow-list (ml_settings_save). MM3 is turned on only with its name typed back. People only; a Mail admin's alone. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/overview.php';
ml_handler_begin();
admin_gate('settings.manage');
$pdo = db();
$cur = admin_settings($pdo);
$errors = [];
$values = [];
foreach (ADMIN_SETTING_FIELDS as $k => $f) {
    if (!req_has($k)) {
        continue;
    }
    switch ($f[0]) {
        case 'int':
            $v = ml_int($k, null, $f[2], $f[3], $f[1], $errors);
            if (!isset($errors[$k]) && $v !== null) { $values[$k] = $v; }
            break;
        case 'nint':
            $v = ml_int($k, null, $f[2], $f[3], $f[1], $errors, true);
            if (!isset($errors[$k])) { $values[$k] = $v; }
            break;
        case 'choice':
            $v = ml_choice($k, null, $f[2], $f[1], $errors, false);
            if (!isset($errors[$k]) && $v !== null) { $values[$k] = $v; }
            break;
        case 'bool':
            $values[$k] = ml_yes($k);
            break;
        case 'text':
            $v = ml_text($k, null, $f[3], $f[1], $errors, true);
            if (!isset($errors[$k]) && $v !== null) {
                if (mb_strlen($v) < $f[2]) { $errors[$k] = $f[1] . ' is at least ' . $f[2] . ' characters.'; }
                elseif (!str_contains($v, '{agent}')) { $errors[$k] = $f[1] . ' must contain {agent}, which becomes the agent\'s name.'; }
                else { $values[$k] = $v; }
            }
            break;
        case 'time':
            $v = req_val($k);
            if ($v === null || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $v)) { $errors[$k] = $f[1] . ' is a time like 07:10.'; }
            else { $values[$k] = $v; }
            break;
    }
}
if (isset($values['mm3_identity_enforced']) && $values['mm3_identity_enforced'] === true && !$cur['mm3_identity_enforced'] && strtoupper((string) req_val('mm3_confirm')) !== 'MM3') {
    $errors['mm3_confirm'] = 'To turn MM3 on, type MM3 in the box.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
if ($values === []) {
    ml_refuse_fields(['settings' => 'Send at least one setting.']);
}
$diff = ml_guard($pdo, static function () use ($pdo, $values): array {
    $st = $pdo->prepare('SELECT ml_settings_save(:by, CAST(:v AS jsonb))');
    $st->execute(['by' => current_member_id(), 'v' => json_encode($values, JSON_THROW_ON_ERROR)]);
    return json_decode((string) $st->fetchColumn(), true) ?: [];
});
if ($diff === []) {
    ml_done('Nothing changed', 1, ml_land('/admin/settings', 'unchanged'));
}
$before = [];
$after = [];
foreach ($diff as $k => $d) {
    $before[$k] = $d['before'];
    $after[$k] = $d['after'];
}
log_activity($pdo, 'settings.save', 'settings', 1, ['before' => $before, 'after' => $after]);
ml_done('Saved ' . count($diff) . ' setting' . (count($diff) === 1 ? '' : 's'), 1, ml_land('/admin/settings', 'saved'), 'settingsChanged', ['changed' => array_keys($diff)]);
