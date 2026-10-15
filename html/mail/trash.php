<?php
declare(strict_types=1);
/** Action `mail_trash` (log `message.trash`; undo mail_move): every message of the conversations but drafts to Trash. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
mail_action_run('trash', 'message.trash', ['undo' => true]);
