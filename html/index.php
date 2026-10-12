<?php
declare(strict_types=1);
/** / — home (screen `home`): the default mailbox's Inbox — the person's own, else the first they open; else the no-mailbox card. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();
if (!is_member_here()) {                       // admitted by the kernel with no MaluMail role: told why
    refuse(403, right_refusal('mail.use'));
}
$box = ml_default_mailbox();
if (wants_json()) {
    respond_screen(['mailbox_id' => $box !== null ? (int) $box['id'] : null, 'location' => $box !== null ? '/m/' . (int) $box['id'] . '/v/inbox' : null]);
}
if ($box !== null) {
    redirect('/m/' . (int) $box['id'] . '/v/inbox');
}
log_screen_view(db(), 'home');
render_screen(app_name(), view('home/no-mailbox.php', ['admins' => ml_mail_admin_names()]), ['screen' => 'home', 'activeNav' => 'tab-inbox']);
