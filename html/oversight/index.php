<?php
declare(strict_types=1);
/** /oversight — a card per agent mailbox the person decides, its seven days and its record, Pause/Resume (screen `oversight`). One gatherer, ml_agent_mail_summary(), with the tools agent_mail_summary and oversight_summary (decision 1). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
require_login();
require_human();
if (!has_right('mail.oversee')) {
    refuse(403, right_refusal('mail.oversee'));
}
$pdo = db();
$cards = oversight_cards($pdo, (int) current_member_id());
log_screen_view($pdo, 'oversight');
if (wants_json()) {
    respond_screen(['cards' => array_map('present_oversight_card', $cards)]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['paused' => ['warning', 'Paused: the agent sends nothing and is woken by nothing until it is resumed.'],
    'resumed' => ['success', 'Resumed: the agent follows its policy again.']]);
$queued = (int) one_value($pdo, 'SELECT count(*) FROM mcp_review_queue WHERE can_decide');
render_screen('Oversight', view('oversight/cards.php', ['cards' => $cards, 'notice' => $notice, 'queued' => $queued, 'tz' => member_timezone()]),
    ['screen' => 'oversight', 'entity' => 'mailbox', 'activeNav' => 'oversight']);
