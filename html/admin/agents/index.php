<?php
declare(strict_types=1);
/** /admin/agents — every agent with a mailbox, its mail turns and their states, Retry for a failed one, the way to the kernel's agent page (screen `admin-agents`). The sender's domain only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/admin/overview.php';
admin_gate('agents.manage');
$pdo = db();
$status = request_string('status');
$status = in_array($status, ADMIN_DISPATCH_STATUSES, true) ? $status : '';
$agentRaw = request_string('agent');
$agent = ctype_digit($agentRaw) && $agentRaw !== '' ? (int) $agentRaw : null;
$cards = admin_agent_cards($pdo);
$rows = admin_dispatches($pdo, $status === '' ? null : $status, $agent, 50);
log_screen_view($pdo, 'admin-agents');
if (wants_json()) {
    respond_screen(['agents' => array_map(static fn (array $c): array => ['agent' => ['member_id' => (int) $c['agent_id'], 'name' => $c['agent_name']], 'mailbox_id' => (int) $c['mailbox_id'], 'mailbox' => $c['address'],
        'paused' => (bool) $c['paused'], 'send_mode' => $c['send_mode'], 'primary_overseer' => $c['overseer_name'],
        'last_7_days' => ['sent' => (int) $c['sent'], 'answered' => (int) $c['answered'], 'failed' => (int) $c['failed'], 'unscreened' => (int) $c['unscreened'], 'refused' => (int) $c['refused']]], $cards),
        'dispatches' => array_map('admin_dispatch_json', $rows), 'filters' => ['status' => $status, 'agent' => $agent]]);
}
$notice = ml_notice($_GET['notice'] ?? null, ['retried' => ['success', 'That mail turn is queued again.']]);
render_screen('Agents', view('admin/agents.php', ['cards' => $cards, 'rows' => $rows, 'status' => $status, 'agent' => $agent, 'os' => admin_os_url(), 'tz' => member_timezone(), 'notice' => $notice]),
    ['screen' => 'admin-agents', 'entity' => 'agent', 'activeNav' => 'admin']);
