<?php
declare(strict_types=1);
/** Action `mail_delete_forever` (log `message.delete_forever`; confirm; PAUSES for an agent — the kernel's deletion approval): the conversations' messages in Trash or Spam expunged. Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
mail_action_run('delete_forever', 'message.delete_forever');
