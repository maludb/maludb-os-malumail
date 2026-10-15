<?php
declare(strict_types=1);
/** Action `mail_spam` (log `message.spam`; undo mail_not_spam): the received messages of the conversations to Spam. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
mail_action_run('spam', 'message.spam', ['undo' => true]);
