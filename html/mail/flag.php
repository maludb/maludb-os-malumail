<?php
declare(strict_types=1);
/** Action `mail_flag` (log `message.flag`): star (flagged yes — the latest message, or `message`) or unstar (every starred one). Triage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/mail/reading.php';
mail_action_run(ml_yes('flagged', true) ? 'flag' : 'unflag', 'message.flag');
