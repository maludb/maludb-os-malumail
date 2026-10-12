<?php
declare(strict_types=1);
/** /m/{mailbox}/v/{view} — a mailbox's view as Gmail's conversation list (screen `mailbox-view`, docs/build-specs/mail-list.md). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$view = request_string('view', 'inbox');
if (!isset(ML_VIEWS[$view])) {
    respond_not_found('No such view.');
}
mail_list_respond(db(), $box, $view, null);
