<?php
declare(strict_types=1);
/** Action `mail_move` (log `message.move`): the conversations' messages (Drafts and Sent left where they are) to `to_folder`; with restore[] — the Undo — each message back to the Inbox (received) or Sent (the mailbox's own). Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
mail_action_run('move', 'message.move', ['undo' => true]);
