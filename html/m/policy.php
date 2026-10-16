<?php
declare(strict_types=1);
/** /m/{mailbox}/policy — an agent mailbox's sending policy: each setting with its meaning and value, what the business also applies, Save (asks first when it widens what leaves unseen), the pause switch, the history (screen `agent-policy`). An agent's own mailbox only; anything else 404. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
require_login();
require_human();
if (!has_right('mail.oversee')) {
    refuse(403, right_refusal('mail.oversee'));
}
$pdo = db();
$box = ov_require_agent_mailbox(request_string('mailbox'), 'view');
$mb = (int) $box['id'];
$p = policy_get($pdo, $mb, (int) current_member_id());
$history = policy_history($pdo, $mb);
log_screen_view($pdo, 'agent-policy', $mb);
if (wants_json()) {
    respond_screen(['policy' => present_policy($p), 'history' => array_map(static fn (array $h): array => ['action' => $h['action'], 'by' => $h['actor_name'], 'at' => json_ts($h['created_at']), 'changes' => $h['after']], $history)]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'unchanged' => ['info', 'Nothing changed.'], 'paused' => ['warning', 'Paused.'], 'resumed' => ['success', 'Resumed.']]);
render_screen($box['address'] . ' — policy', view('oversight/policy.php', ['box' => $box, 'p' => $p, 'history' => $history, 'notice' => $notice, 'tz' => member_timezone()]),
    ['screen' => 'agent-policy', 'entity' => 'agent_mail_policy', 'recordId' => $mb, 'mailboxId' => $mb, 'activeNav' => 'oversight']);
