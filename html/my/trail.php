<?php
declare(strict_types=1);
/** /my/trail?since=&mailbox=&page= — my own trail (screen `my-trail`; the phone's Me). Reachable before acknowledging the notice. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/trail/queries.php';
require_login();
$pdo = db();
$since = request_date('since') ?: null;
$mailbox = request_integer('mailbox');
$page = max(1, request_integer('page') ?? 1);
$r = trail_for($pdo, (int) current_member_id(), $since, $mailbox, $page);
log_screen_view($pdo, 'my-trail');
if (wants_json()) {
    respond_screen(['rows' => array_map(static fn (array $x): array => ['id' => (int) $x['id'], 'at' => json_ts($x['occurred_at']), 'action' => $x['action'],
        'what' => trail_sentence($x), 'mailbox_id' => $x['mailbox_id'] !== null ? (int) $x['mailbox_id'] : null], $r['rows']), 'page' => $page, 'more' => $r['more']]);
}
$query = array_filter(['since' => $since, 'mailbox' => $mailbox], static fn ($v) => $v !== null && $v !== '');
render_screen('My trail', view('trail/list.php', $r + ['page' => $page, 'query' => $query, 'tz' => member_timezone()]), ['screen' => 'my-trail', 'activeNav' => 'tab-me']);
