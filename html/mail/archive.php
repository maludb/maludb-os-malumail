<?php
declare(strict_types=1);
/** Action `mail_archive` (log `message.archive`; undo mail_move): the Inbox's copies of each conversation (or every conversation `matching` finds, ≤ 1000) move to Archive, created by name when absent. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
mail_action_run('archive', 'message.archive', ['undo' => true]);
