<?php
declare(strict_types=1);
/** /admin/settings — MaluMail's settings in fieldsets, the two switches, MM3, the Clerk's hour and the monitoring notice (screen `admin-settings`). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/overview.php';
admin_gate('settings.manage');
$pdo = db();
$s = admin_settings($pdo);
log_screen_view($pdo, 'admin-settings');
if (wants_json()) {
    $out = ['notice' => ['version' => (int) $s['notice_version'], 'updated_at' => json_ts($s['notice_updated_at']), 'acknowledged' => $s['acked'], 'people' => $s['people']],
            'budget' => ['today' => (int) $s['budget_today'], 'used' => $s['budget_used']], 'expert_member_id' => $s['expert_member_id'], 'clerk_member_id' => $s['clerk_member_id'], 'settings' => []];
    foreach (ADMIN_SETTING_FIELDS as $k => $_) {
        $out['settings'][$k] = $k === 'clerk_note_at' ? $s['clerk_hhmm'] : $s[$k];
    }
    respond_screen($out);
}
$notice = ml_notice($_GET['notice'] ?? null, ['saved' => ['success', 'The settings are saved.'], 'unchanged' => ['info', 'Nothing changed.'], 'notice' => ['success', 'The notice is saved as a new version; everyone acknowledges it again on their next visit.']]);
render_screen('Settings', view('admin/settings.php', ['s' => $s, 'notice' => $notice, 'tz' => member_timezone()]), ['screen' => 'admin-settings', 'entity' => 'settings', 'activeNav' => 'admin']);
