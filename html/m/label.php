<?php
declare(strict_types=1);
/** /m/{mailbox}/label/{label} — the conversations carrying a label (any of their messages), the same list (screen `label-view`, docs/build-specs/search-labels-snooze.md). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_login();
$box = ml_require_mailbox(request_string('mailbox'), 'view');
$label = ml_label_one(db(), (int) $box['id'], (int) request_integer('label'));
if ($label === null) {
    respond_not_found('No such label.');
}
mail_list_respond(db(), $box, 'label', null, $label);
