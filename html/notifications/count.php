<?php
declare(strict_types=1);
/** GET /notifications/count — the header bell's count fragment (not a screen; the bell polls it every minute). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
header('Vary: HX-Request');
header('Cache-Control: no-store');
echo view('shared/bell.php', ['unread' => bell_count(db(), (int) current_member_id())]);
