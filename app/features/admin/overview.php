<?php
declare(strict_types=1);

/**
 * Slice 10 — the Mail admin's overview, sync health, settings and the agents' page (docs/build-specs/home-admin.md). Replicates slice 1's
 * admin screens and slice 2's reads: ADMINISTRATIVE facts only — a state, a count, an error, a domain — never a subject, a correspondent
 * or a body. Every figure is the database's (ml_sync_health(), the settings, the dispatch table); PHP adds the mail service's watchers.
 */

require_once __DIR__ . '/admin.php';

/** The kernel's address for the agent's page and its runs (OS_URL, else the launcher's with `os.` for `app.`), or ''. */
function admin_os_url(): string
{
    $osUrl = (string) env('OS_URL', '');
    if ($osUrl === '' && ($l = (string) env('OS_LAUNCHER_URL', '')) !== '' && ($h = parse_url($l, PHP_URL_HOST))) {
        $osUrl = (parse_url($l, PHP_URL_SCHEME) ?: 'https') . '://' . preg_replace('/^app\./', 'os.', (string) $h);
    }
    return rtrim($osUrl, '/');
}

/** The overview's cards (screen `admin`): every figure from the database. */
function admin_overview(PDO $pdo): array
{
    $o = [];
    $o['domains'] = ['served' => (int) one_value($pdo, 'SELECT count(*) FROM ml_domains WHERE served'), 'hosted' => (int) one_value($pdo, 'SELECT count(*) FROM ml_domains'),
        'unverified' => $pdo->query("SELECT domain::text AS domain, spf_ok, dkim_ok, dmarc_ok FROM ml_domains
                                      WHERE served AND (spf_ok IS FALSE OR dkim_ok IS FALSE OR dmarc_ok IS FALSE) ORDER BY domain")->fetchAll()];
    $kinds = ['person' => 0, 'agent' => 0, 'shared' => 0];
    foreach ($pdo->query('SELECT kind, count(*) AS n FROM mailboxes GROUP BY kind') as $r) {
        $kinds[$r['kind']] = (int) $r['n'];
    }
    $o['mailboxes'] = ['kinds' => $kinds, 'total' => array_sum($kinds), 'suspended' => (int) one_value($pdo, 'SELECT count(*) FROM mailboxes WHERE suspended_at IS NOT NULL'),
        'not_connected' => (int) one_value($pdo, "SELECT count(*) FROM mailboxes b LEFT JOIN mailbox_credentials c ON c.id = b.credential_id WHERE c.status IS DISTINCT FROM 'ok'")];
    $o['forwarding'] = ['count' => (int) one_value($pdo, 'SELECT count(*) FROM mcp_aliases'), 'outside' => (int) one_value($pdo, 'SELECT count(*) FROM mcp_aliases WHERE forwards_outside')];
    $h = $pdo->query("SELECT count(*) AS n, min(held_at) AS oldest, extract(epoch FROM now() - min(held_at))::bigint AS age FROM outbound_email WHERE status = 'held'")->fetch();
    $o['held'] = ['total' => (int) $h['n'], 'oldest' => $h['oldest'], 'age_seconds' => $h['age'] === null ? null : (int) $h['age']];
    $s = ['live' => 0, 'syncing' => 0, 'failing' => 0, 'credential_failed' => 0, 'other' => 0];
    foreach ($pdo->query('SELECT sync_state, credential_status FROM ml_sync_health()') as $r) {
        if ($r['credential_status'] === 'failed' || $r['credential_status'] === 'revoked') {
            $s['credential_failed']++;
        }
        match ($r['sync_state']) {
            'live' => $s['live']++, 'new', 'syncing' => $s['syncing']++, 'failed' => $s['failing']++, default => $s['other']++,
        };
    }
    $o['sync'] = $s;
    $b = $pdo->query("SELECT ml_agent_external_budget() AS budget, s.agent_external_budget AS cap, s.agent_budget_started_at AS started,
                             (SELECT count(*) FROM outbound_email o WHERE o.sender_is_agent AND o.external AND o.status IN ('queued', 'sending', 'sent', 'deferred')
                                 AND o.created_at > now() - interval '1 day') AS used
                        FROM ml_settings s WHERE s.id = 1")->fetch();
    $o['budget'] = ['used' => (int) $b['used'], 'budget' => (int) $b['budget'], 'cap' => $b['cap'] === null ? null : (int) $b['cap'], 'started' => $b['started']];
    $o['switches'] = admin_switches($pdo);
    return $o;
}

/** Whole-number age in words ("3 hours", "2 days"). */
function admin_age(?int $seconds): string
{
    if ($seconds === null) {
        return '';
    }
    foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$n, $w]) {
        if ($seconds >= $n) {
            $v = intdiv($seconds, $n);
            return $v . ' ' . $w . ($v === 1 ? '' : 's');
        }
    }
    return 'under a minute';
}

/**
 * Per mailbox, the sync health: ml_sync_health() (counts only) joined with the mail service's watchers. [rows, mailer answered?]. $state:
 * failing (a failed sync or an error left standing), syncing (new or syncing), failed (the credential failed or was revoked), all/'' . $only: one mailbox.
 */
function admin_sync_rows(PDO $pdo, string $state = '', ?int $only = null): array
{
    $watch = admin_mailer_try('/v1/status');
    $rows = [];
    foreach ($pdo->query('SELECT * FROM ml_sync_health()') as $r) {
        $id = (int) $r['mailbox_id'];
        if ($only !== null && $id !== $only) {
            continue;
        }
        $cred = (string) ($r['credential_status'] ?? '');
        $keep = match ($state) {
            'failing' => $r['sync_state'] === 'failed' || ($r['last_error'] !== null && $r['sync_state'] !== 'live'),
            'syncing' => in_array($r['sync_state'], ['new', 'syncing'], true),
            'failed' => in_array($cred, ['failed', 'revoked'], true),
            default => true,
        };
        if (!$keep) {
            continue;
        }
        $w = $watch['mailboxes'][(string) $id] ?? null;
        $rows[] = ['mailbox_id' => $id, 'address' => $r['address'], 'kind' => $r['kind'], 'sync_state' => $r['sync_state'], 'last_sync_at' => $r['last_sync_at'],
            'messages' => (int) $r['messages'], 'text_indexed' => (int) $r['text_indexed'], 'quota_used_bytes' => $r['quota_used_bytes'], 'quota_bytes' => $r['quota_bytes'],
            'credential' => $cred === '' ? 'none' : $cred, 'last_error' => $r['last_error'], 'last_error_at' => $r['last_error_at'],
            'watcher' => $watch === null ? null : ($w['watcher'] ?? 'down'), 'backoff_seconds' => (int) ($w['backoff_seconds'] ?? 0)];
    }
    return [$rows, $watch !== null];
}

function admin_sync_json(array $r): array
{
    return ['mailbox_id' => $r['mailbox_id'], 'address' => $r['address'], 'kind' => $r['kind'], 'sync_state' => $r['sync_state'], 'watcher' => $r['watcher'],
        'last_sync_at' => json_ts($r['last_sync_at']), 'messages' => $r['messages'], 'text_indexed' => $r['text_indexed'],
        'quota_used_bytes' => $r['quota_used_bytes'] === null ? null : (int) $r['quota_used_bytes'], 'quota_bytes' => $r['quota_bytes'] === null ? null : (int) $r['quota_bytes'],
        'credential' => $r['credential'], 'last_error' => $r['last_error'], 'last_error_at' => json_ts($r['last_error_at'])];
}

/** The agents with a mailbox: its paused state, send mode, the last seven days' turns by status and its primary overseer. */
function admin_agent_cards(PDO $pdo): array
{
    return $pdo->query("SELECT b.id AS mailbox_id, b.address::text AS address, b.display_name AS mailbox_name, b.owner_member_id AS agent_id, a.display_name AS agent_name,
                               COALESCE(p.paused, false) AS paused, p.send_mode, po.display_name AS overseer_name,
                               COALESCE(t.sent, 0) AS sent, COALESCE(t.answered, 0) AS answered, COALESCE(t.failed, 0) AS failed,
                               COALESCE(t.unscreened, 0) AS unscreened, COALESCE(t.refused, 0) AS refused
                          FROM mailboxes b
                          LEFT JOIN members a ON a.id = b.owner_member_id
                          LEFT JOIN members po ON po.id = b.primary_overseer_id
                          LEFT JOIN agent_mail_policies p ON p.mailbox_id = b.id
                          LEFT JOIN (SELECT mailbox_id, count(*) FILTER (WHERE status = 'sent') AS sent, count(*) FILTER (WHERE status = 'answered') AS answered,
                                            count(*) FILTER (WHERE status = 'failed') AS failed, count(*) FILTER (WHERE status = 'unscreened') AS unscreened,
                                            count(*) FILTER (WHERE status = 'refused') AS refused
                                       FROM agent_dispatches WHERE created_at > now() - interval '7 days' GROUP BY mailbox_id) t ON t.mailbox_id = b.id
                         WHERE b.kind = 'agent' ORDER BY b.address")->fetchAll();
}

/** Why a mail turn was or was not given (ml_dispatch_decide()'s decision), in words; the unscreened four as slice 5 words them. */
const ADMIN_DISPATCH_WHY = [
    'wake' => 'The agent was woken',
    'kernel_intake' => 'The OS reads this mailbox itself',
    'suspended' => 'The mailbox is suspended',
    'paused' => 'The agent is paused',
    'wake_off' => 'Arriving mail wakes no one here',
    'own_mail' => 'The mailbox\'s own mail',
    'automated' => 'Automated or list mail',
    'sensitive' => 'A sensitive conversation',
    'taken_over' => 'A person took the conversation over',
    'muted' => 'The conversation is muted',
    'internal_unproven' => 'Looks internal, but this application did not send it',
    'unauthenticated' => 'No DMARC pass',
    'no_overseer' => 'No primary overseer to act',
    'unknown_sender' => 'An outside sender this mailbox does not know',
];

const ADMIN_DISPATCH_STATUSES = ['queued', 'sent', 'answered', 'failed', 'unscreened', 'refused', 'skipped', 'awaiting_approval'];

/** The mail turns, newest first; the sender's DOMAIN only (an agent mailbox's mail, never its subject). One row when $only names a dispatch. */
function admin_dispatches(PDO $pdo, ?string $status, ?int $agent, int $limit = 50, ?int $only = null): array
{
    $st = $pdo->prepare("SELECT d.id, d.created_at, d.kind, d.status, d.decision, d.attempts, d.run_id, d.mailbox_id, d.agent_member_id, a.display_name AS agent_name,
                                b.address::text AS mailbox, split_part(m.from_address::text, '@', 2) AS sender_domain
                           FROM agent_dispatches d
                           JOIN mailboxes b ON b.id = d.mailbox_id AND b.kind = 'agent'
                           LEFT JOIN members a ON a.id = d.agent_member_id
                           LEFT JOIN mail_messages m ON m.id = d.record_id AND d.record_type = 'mail_message'
                          WHERE (CAST(:s AS text) IS NULL OR d.status = :s2) AND (CAST(:a AS bigint) IS NULL OR d.agent_member_id = :a2)
                            AND (CAST(:o AS bigint) IS NULL OR d.id = :o2)
                          ORDER BY d.id DESC LIMIT :l");
    $st->bindValue('s', $status);
    $st->bindValue('s2', $status);
    $st->bindValue('a', $agent, $agent === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('a2', $agent, $agent === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('o', $only, $only === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('o2', $only, $only === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue('l', max(1, min(200, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function admin_dispatch_json(array $d): array
{
    return ['id' => (int) $d['id'], 'at' => json_ts($d['created_at']), 'agent' => ['member_id' => (int) $d['agent_member_id'], 'name' => $d['agent_name']], 'mailbox' => $d['mailbox'],
        'kind' => $d['kind'], 'status' => $d['status'], 'decision' => $d['decision'], 'sender_domain' => $d['sender_domain'] === '' ? null : $d['sender_domain'],
        'attempts' => (int) $d['attempts'], 'run_id' => $d['run_id'] === null ? null : (int) $d['run_id']];
}

/** The settings as the form shows them (mcp_settings) plus the notice's acknowledgement count and the budget's figure today. */
function admin_settings(PDO $pdo): array
{
    $s = $pdo->query('SELECT s.*, ml_agent_external_budget() AS budget_today, ex.display_name AS expert_name, cl.display_name AS clerk_name,
                             to_char(s.clerk_note_at, \'HH24:MI\') AS clerk_hhmm
                        FROM ml_settings s LEFT JOIN members ex ON ex.id = s.expert_member_id LEFT JOIN members cl ON cl.id = s.clerk_member_id WHERE s.id = 1')->fetch();
    $s['budget_used'] = (int) one_value($pdo, "SELECT count(*) FROM outbound_email o WHERE o.sender_is_agent AND o.external AND o.status IN ('queued', 'sending', 'sent', 'deferred')
                                                  AND o.created_at > now() - interval '1 day'");
    $s['acked'] = (int) one_value($pdo, "SELECT count(*) FROM ml_notice_acknowledgements a JOIN members m ON m.id = a.member_id
                                          WHERE a.notice_version = :v AND m.status = 'active' AND m.member_kind = 'human' AND cardinality(m.roles) > 0", ['v' => (int) $s['notice_version']]);
    $s['people'] = (int) one_value($pdo, "SELECT count(*) FROM members m WHERE m.status = 'active' AND m.member_kind = 'human' AND cardinality(m.roles) > 0");
    return $s;
}

/** The settings keys of the form: [key => [kind, label, min, max]]; booleans are yes/no, `time` is HH:MM. */
const ADMIN_SETTING_FIELDS = [
    'undo_send_seconds' => ['int', 'Undo send (seconds)', 0, 30], 'images_default' => ['choice', 'Remote images', ['ask', 'always']],
    'index_text_max_kb' => ['int', 'Text kept per message in the index (KB)', 10, 1024], 'max_attachments_mb' => ['int', 'Attachments per message (MB)', 1, 9],
    'draft_save_seconds' => ['int', 'Draft saved every (seconds)', 5, 120], 'poll_visible_seconds' => ['int', 'Check for new mail while visible (seconds)', 5, 300],
    'poll_hidden_seconds' => ['int', 'Check for new mail while hidden (seconds)', 15, 900], 'held_expiry_hours' => ['int', 'Held mail expires after (hours)', 1, 720],
    'default_send_mode' => ['choice', 'Send mode', ['hold_all', 'internal_auto', 'allowlist_auto', 'auto']], 'default_first_contact_review' => ['bool', 'Review first contact'],
    'default_attachments_review' => ['bool', 'Review attachments'], 'default_max_external_per_day' => ['int', 'Messages leaving the business a day', 0, 10000],
    'default_max_per_hour' => ['int', 'Messages an hour', 0, 10000], 'default_max_per_thread_per_day' => ['int', 'Messages a day in one conversation', 1, 1000],
    'default_wake_on_mail' => ['bool', 'Arriving mail wakes the agent'], 'default_wake_from' => ['choice', 'Whose mail wakes it', ['none', 'internal', 'internal_and_known', 'all_authenticated']],
    'default_disclosure' => ['bool', 'Carry the disclosure line'], 'disclosure_text' => ['text', 'The disclosure line', 10, 500],
    'agent_external_budget' => ['nint', 'Agents\' outside mail a day, together', 0, 100000], 'hold_all_agents' => ['bool', 'Hold every agent\'s mail'],
    'hold_all_external' => ['bool', 'Hold every agent\'s outside mail'], 'mm3_identity_enforced' => ['bool', 'MaluMail enforces sender identity (MM3)'], 'clerk_note_at' => ['time', 'The Clerk\'s hour'],
];

/** A handler's answer for a row swap: JSON learns the result, HTMX gets the partial, a browser lands on $land. */
function admin_row_reply(string $did, int $id, string $partial, string $land, string $event = ''): never
{
    emit_action_status(true, ['did' => $did, 'record_id' => $id, 'refresh' => $event]);
    if (wants_json()) {
        respond_saved(['did' => $did, 'record_id' => $id, 'location' => $land, 'refresh' => $event]);
    }
    if (is_htmx_request()) {
        if ($event !== '') {
            hx_trigger($event);
        }
        echo $partial;
        exit;
    }
    saved_go($land, $event);
}
