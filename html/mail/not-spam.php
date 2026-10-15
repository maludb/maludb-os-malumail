<?php
declare(strict_types=1);
/** Action `mail_not_spam` (log `message.not_spam`; undo mail_spam): the conversations' messages in Spam back to the Inbox. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
mail_action_run('not_spam', 'message.not_spam', ['undo' => true]);
