<?php
declare(strict_types=1);
/** Action `mail_trash_empty` (log `trash.empty`; confirm; PAUSES for an agent — the kernel's deletion approval): every message of Trash (which=trash) or Spam (which=spam) expunged. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
mail_action_run('trash_empty', 'trash.empty');
