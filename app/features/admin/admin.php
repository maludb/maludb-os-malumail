<?php
declare(strict_types=1);

/**
 * Slice 1 — domains, mailboxes and forwarding (docs/build-specs/domains-mailboxes.md), THE CRUD EXEMPLAR: list → card → full-page
 * form → POST handler → the database's own function (or the mail service's administration door) → log_activity() → land on the
 * record. Every MaluMail call goes through the mail service (app/mailer.php): PHP never speaks to the Mailbox MCP and never holds
 * the customer key; a mailbox's credential never reaches PHP — the service seals and stores it. The one password PHP ever holds is
 * a PERSON's new one, for one redirect, to show it once (design §3/§4; D3).
 */

const ADMIN_KINDS = ['person' => 'A person\'s', 'agent' => 'An agent\'s', 'shared' => 'Shared'];
const ADMIN_INTAKE = ['none' => 'No other reader', 'kernel' => 'The OS assistant\'s intake', 'helpdesk' => 'Help Desk\'s queue',
                      'sales_crm' => 'The CRM\'s logging', 'other' => 'Another reader'];

/** A Mail admin's screen or action: signed in, a person, holding the right. */
function admin_gate(string $right): void
{
    require_login();
    require_human();
    if (!has_right($right)) {
        refuse(403, right_refusal($right));
    }
}

/** One call to the mail service's administration plane: its answer, or its refusal in its own words with its status. */
function admin_mailer(string $path, array $args = []): array
{
    try {
        return mailer_call($path, (int) current_member_id(), $args);
    } catch (MailerRefused $e) {
        emit_action_status(false, ['error' => $e->getMessage(), 'rule' => $e->codeName]);
        refuse(in_array($e->status, [403, 404, 409, 422, 503], true) ? $e->status : 502, $e->getMessage());
    } catch (MailerDown $e) {
        emit_action_status(false, ['error' => $e->getMessage(), 'rule' => 'mailer_down']);
        refuse(503, $e->getMessage());
    }
}

/** The same, but a failure answers null (a screen that can still show what it last knew). */
function admin_mailer_try(string $path, array $args = []): ?array
{
    try {
        return mailer_call($path, (int) current_member_id(), $args);
    } catch (MailerRefused | MailerDown) {
        return null;
    }
}

function admin_domain_of(string $address): string
{
    $at = strrpos($address, '@');
    return $at === false ? '' : strtolower(substr($address, $at + 1));
}

/** "a@b.example" → "b.example" for a log payload (D14: an outsider's whole address never). */
function admin_domains_of(array $addresses): array
{
    return array_values(array_unique(array_map('admin_domain_of', $addresses)));
}

// ---- domains -----------------------------------------------------------------------------------------------------------------

/** The served domains as recorded here (domain => row). */
function admin_domain_rows(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT id, domain::text AS domain, served, internal, spf_ok, dkim_ok, dmarc_ok, flags_read_at FROM ml_domains ORDER BY domain') as $r) {
        $out[$r['domain']] = $r;
    }
    return $out;
}

function admin_served_domains(PDO $pdo): array
{
    return array_values(array_filter(admin_domain_rows($pdo), static fn (array $d): bool => (bool) $d['served']));
}

/** A flag from MaluMail's domain row under whichever name it uses (`spf`, `spf_verified`, `spf_ok`). */
function admin_flag(array $d, string $name): ?bool
{
    foreach ([$name, $name . '_verified', $name . '_ok'] as $k) {
        if (array_key_exists($k, $d)) {
            return $d[$k] === null ? null : (bool) $d[$k];
        }
    }
    return null;
}

/**
 * MaluMail's hosted domains for the customer key merged with what this business serves: [rows, reached?]. The served domains' flags are
 * saved as read. When MaluMail does not answer, the served domains as last read.
 */
function admin_domains(PDO $pdo): array
{
    $mine = admin_domain_rows($pdo);
    $counts = [];
    foreach ($pdo->query("SELECT split_part(address::text, '@', 2) AS d, count(*) AS n FROM mailboxes GROUP BY 1") as $r) {
        $counts[$r['d']]['mailboxes'] = (int) $r['n'];
    }
    foreach ($pdo->query("SELECT split_part(address::text, '@', 2) AS d, count(*) AS n FROM ml_aliases GROUP BY 1") as $r) {
        $counts[$r['d']]['aliases'] = (int) $r['n'];
    }
    $hosted = admin_mailer_try('/v1/admin/list_hosted_domains');
    $rows = [];
    if ($hosted !== null) {
        foreach ((array) ($hosted['domains'] ?? []) as $d) {
            if (!is_array($d) || !isset($d['domain'])) {
                continue;
            }
            $name = strtolower((string) $d['domain']);
            $m = $mine[$name] ?? null;
            $spf = admin_flag($d, 'spf');
            $dkim = admin_flag($d, 'dkim');
            $dmarc = admin_flag($d, 'dmarc');
            if ($m !== null && $m['served']) {
                ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_domain_flags_save(:by, :d, :s, :k, :m)')->execute(
                    ['by' => current_member_id(), 'd' => $name, 's' => $spf === null ? null : ($spf ? 't' : 'f'), 'k' => $dkim === null ? null : ($dkim ? 't' : 'f'), 'm' => $dmarc === null ? null : ($dmarc ? 't' : 'f')]));
            }
            $rows[$name] = ['domain' => $name, 'hosted' => true, 'served' => (bool) ($m['served'] ?? false), 'internal' => (bool) ($m['internal'] ?? false),
                            'spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc, 'read_at' => null,
                            'mailboxes' => $counts[$name]['mailboxes'] ?? 0, 'aliases' => $counts[$name]['aliases'] ?? 0];
        }
    }
    foreach ($mine as $name => $m) {
        if (!isset($rows[$name]) && $m['served']) {          // served here, not (or no longer) in MaluMail's answer
            $rows[$name] = ['domain' => $name, 'hosted' => $hosted === null ? null : false, 'served' => true, 'internal' => (bool) $m['internal'],
                            'spf' => $m['spf_ok'], 'dkim' => $m['dkim_ok'], 'dmarc' => $m['dmarc_ok'], 'read_at' => $m['flags_read_at'],
                            'mailboxes' => $counts[$name]['mailboxes'] ?? 0, 'aliases' => $counts[$name]['aliases'] ?? 0];
        }
    }
    ksort($rows);
    return [array_values($rows), $hosted !== null];
}

// ---- mailboxes ---------------------------------------------------------------------------------------------------------------

const ADMIN_MAILBOX_SELECT = <<<'SQL'
    SELECT b.id, b.address::text AS address, b.display_name, b.kind, b.owner_member_id, o.display_name AS owner_name, o.member_kind AS owner_kind,
           b.primary_overseer_id, po.display_name AS primary_overseer_name, b.quota_bytes, b.quota_used_bytes, b.sync_state, b.last_sync_at,
           b.last_error, b.last_error_at, b.intake_owner, b.intake_proposed, b.share_with_apps, b.suspended_at, b.active, b.created_at,
           c.status AS credential_status, c.last_ok_at AS credential_ok_at, c.failed_at AS credential_failed_at, c.last4 AS credential_last4
      FROM mailboxes b
      JOIN ml_domains d ON d.domain = split_part(b.address::text, '@', 2) AND d.served
      LEFT JOIN members o ON o.id = b.owner_member_id
      LEFT JOIN members po ON po.id = b.primary_overseer_id
      LEFT JOIN mailbox_credentials c ON c.id = b.credential_id
SQL;

/** Every mailbox of the served domains, for a Mail admin — ADMINISTRATIVE facts only (never a count of mail, a subject or an unread). */
function admin_mailboxes(PDO $pdo, array $f): array
{
    $where = [];
    $args = [];
    if (($f['kind'] ?? '') !== '' && isset(ADMIN_KINDS[$f['kind']])) {
        $where[] = 'b.kind = :kind';
        $args['kind'] = $f['kind'];
    }
    if (($f['domain'] ?? '') !== '') {
        $where[] = "split_part(b.address::text, '@', 2) = :domain";
        $args['domain'] = strtolower((string) $f['domain']);
    }
    $state = (string) ($f['state'] ?? '');
    if ($state === 'suspended') {
        $where[] = 'b.suspended_at IS NOT NULL';
    } elseif ($state === 'failed') {
        $where[] = "(c.status = 'failed' OR b.sync_state = 'failed')";
    } elseif ($state === 'no_credential') {
        $where[] = 'b.credential_id IS NULL';
    }
    if (($f['q'] ?? '') !== '') {
        $where[] = '(b.address::text ILIKE :q OR b.display_name ILIKE :q OR o.display_name ILIKE :q)';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $f['q']) . '%';
    }
    $st = $pdo->prepare(ADMIN_MAILBOX_SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY b.kind, b.address');
    $st->execute($args);
    return $st->fetchAll();
}

function admin_mailbox(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(ADMIN_MAILBOX_SELECT . ' WHERE b.id = :id');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: null;
}

/** An agent mailbox's overseers: the people holding full on it by a row. */
function admin_overseers(PDO $pdo, int $mailbox): array
{
    $st = $pdo->prepare("SELECT p.principal_id AS member_id, m.display_name FROM mailbox_permissions p JOIN members m ON m.id = p.principal_id
                          WHERE p.mailbox_id = :b AND p.principal_kind = 'member' AND p.level = 'full' ORDER BY m.display_name");
    $st->execute(['b' => $mailbox]);
    return $st->fetchAll();
}

/** The mailbox a handler names (`mailbox` = id or address), on a served domain; 404 otherwise. */
function admin_require_mailbox(PDO $pdo): array
{
    $raw = trim((string) ($_POST['mailbox'] ?? $_GET['mailbox'] ?? $_GET['id'] ?? ''));
    $row = null;
    if (ctype_digit($raw)) {
        $row = admin_mailbox($pdo, (int) $raw);
    } elseif ($raw !== '') {
        $st = $pdo->prepare('SELECT id FROM mailboxes WHERE address = :a');
        $st->execute(['a' => strtolower($raw)]);
        $id = $st->fetchColumn();
        $row = $id === false ? null : admin_mailbox($pdo, (int) $id);
    }
    if ($row === null) {
        refuse(404, 'No such mailbox.');
    }
    return $row;
}

/** The mailbox a screen's URL names (`id`), on a served domain; 404 otherwise. */
function admin_mailbox_for_screen(PDO $pdo): array
{
    $id = request_integer('id') ?? request_integer('mailbox');
    $row = $id === null ? null : admin_mailbox($pdo, $id);
    if ($row === null) {
        respond_not_found('No such mailbox.');
    }
    return $row;
}

/** The credential's state in words, as its chip says it: [label, colour]. */
function admin_credential_chip(array $b): array
{
    if ($b['credential_status'] === 'ok') {
        return ['credential ok', 'success'];
    }
    if ($b['credential_status'] === 'failed') {
        return ['credential refused', 'danger'];
    }
    if ($b['kind'] === 'person') {
        return ['waits on MM1', 'secondary'];
    }
    return ['no credential', 'warning'];
}

function admin_bytes(?string $bytes): string
{
    if ($bytes === null || $bytes === '') {
        return '—';
    }
    $b = (float) $bytes;
    return $b >= 1073741824 ? rtrim(rtrim(number_format($b / 1073741824, 1), '0'), '.') . ' GiB' : number_format($b / 1048576, 0) . ' MiB';
}

/** A screen's JSON for a mailbox: administrative facts, never mail. */
function admin_mailbox_json(array $b): array
{
    return ['id' => (int) $b['id'], 'address' => $b['address'], 'display_name' => $b['display_name'], 'kind' => $b['kind'],
            'owner' => $b['owner_member_id'] === null ? null : ['member_id' => (int) $b['owner_member_id'], 'name' => $b['owner_name']],
            'primary_overseer' => $b['primary_overseer_id'] === null ? null : ['member_id' => (int) $b['primary_overseer_id'], 'name' => $b['primary_overseer_name']],
            'quota_bytes' => $b['quota_bytes'] === null ? null : (int) $b['quota_bytes'], 'quota_used_bytes' => $b['quota_used_bytes'] === null ? null : (int) $b['quota_used_bytes'],
            'sync_state' => $b['sync_state'], 'credential' => $b['credential_status'] ?? 'none', 'intake_owner' => $b['intake_owner'],
            'intake_proposed' => $b['intake_proposed'], 'share_with_apps' => (bool) $b['share_with_apps'], 'suspended' => $b['suspended_at'] !== null];
}

/** The overseers a create or connect names: the repeated `overseer[]` and the form's two extra pickers. */
function admin_overseer_ids(): array
{
    $ids = request_list('overseer') ?? [];
    foreach (['overseer_2', 'overseer_3'] as $k) {
        $v = req_val($k);
        if ($v !== null && $v !== '') {
            $ids[] = $v;
        }
    }
    $out = [];
    foreach ($ids as $v) {
        if (!ctype_digit((string) $v)) {
            ml_refuse_fields(['overseer' => 'Choose overseers from the list of people.']);
        }
        $out[] = (int) $v;
    }
    return array_values(array_unique($out));
}

/** A person's new password, kept for the ONE redirect that shows it (never in JSON, never under an action token). */
function admin_reveal_once(int $mailbox, string $password, string $address, ?string $owner): void
{
    $_SESSION['revealed_password'] = ['mailbox' => $mailbox, 'password' => $password, 'address' => $address, 'owner' => $owner];
}

/** The password kept for this mailbox's page — handed over once and forgotten. */
function admin_take_revealed(int $mailbox): ?array
{
    $r = $_SESSION['revealed_password'] ?? null;
    if (!is_array($r) || (int) ($r['mailbox'] ?? 0) !== $mailbox) {
        return null;
    }
    unset($_SESSION['revealed_password']);
    return $r;
}

// ---- forwarding addresses -----------------------------------------------------------------------------------------------------

/** Refresh the cache from MaluMail for every served domain: true when MaluMail answered for all of them. */
function admin_aliases_refresh(PDO $pdo): bool
{
    $all = true;
    foreach (admin_served_domains($pdo) as $d) {
        $res = admin_mailer_try('/v1/admin/list_aliases', ['domain' => $d['domain']]);
        if ($res === null) {
            $all = false;
            continue;
        }
        $seen = [];
        foreach ((array) ($res['aliases'] ?? []) as $a) {
            if (!is_array($a) || !isset($a['address'])) {
                continue;
            }
            $dest = $a['forwards_to'] ?? $a['destinations'] ?? [];
            $dest = is_array($dest) ? $dest : preg_split('/\s*,\s*/', (string) $dest);
            $dest = array_values(array_filter(array_map('strval', $dest), static fn (string $x): bool => $x !== ''));
            if ($dest === []) {
                continue;
            }
            $seen[] = strtolower((string) $a['address']);
            ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_alias_cache_put(:by, :a, :d)')->execute(
                ['by' => current_member_id(), 'a' => $a['address'], 'd' => pg_array_literal($dest)]));
        }
        $st = $pdo->prepare("SELECT address::text FROM ml_aliases WHERE split_part(address::text, '@', 2) = :d");
        $st->execute(['d' => $d['domain']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cached) {
            if (!in_array($cached, $seen, true)) {
                ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_alias_cache_drop(:by, :a)')->execute(['by' => current_member_id(), 'a' => $cached]));
            }
        }
    }
    return $all;
}

function admin_aliases(PDO $pdo, array $f): array
{
    $where = ["split_part(a.address, '@', 2) IN (SELECT domain::text FROM ml_domains WHERE served)"];
    $args = [];
    if (($f['domain'] ?? '') !== '') {
        $where[] = "split_part(a.address, '@', 2) = :domain";
        $args['domain'] = strtolower((string) $f['domain']);
    }
    if (!empty($f['outside'])) {
        $where[] = 'a.forwards_outside';
    }
    if (($f['q'] ?? '') !== '') {
        $where[] = '(a.address ILIKE :q OR array_to_string(a.destinations, \',\') ILIKE :q)';
        $args['q'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $f['q']) . '%';
    }
    $st = $pdo->prepare('SELECT a.id, a.address, a.destinations, a.forwards_outside, a.synced_at FROM mcp_aliases a WHERE ' . implode(' AND ', $where) . ' ORDER BY a.address');
    $st->execute($args);
    return array_map(static function (array $r): array {
        $r['destinations'] = pg_text_array($r['destinations']);
        return $r;
    }, $st->fetchAll());
}

function admin_alias(PDO $pdo, string $idOrAddress): ?array
{
    $st = ctype_digit($idOrAddress)
        ? $pdo->prepare('SELECT id, address, destinations, forwards_outside, synced_at FROM mcp_aliases WHERE id = :k')
        : $pdo->prepare('SELECT id, address, destinations, forwards_outside, synced_at FROM mcp_aliases WHERE address = :k');
    $st->execute(['k' => ctype_digit($idOrAddress) ? (int) $idOrAddress : strtolower($idOrAddress)]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }
    $r['destinations'] = pg_text_array($r['destinations']);
    return $r;
}

/** "{a,b}" → ['a', 'b'] (a text[] column, unquoted where simple). */
function pg_text_array(?string $text): array
{
    $text = trim((string) $text, '{}');
    if ($text === '') {
        return [];
    }
    return array_map(static fn (string $x): string => trim($x, '"'), str_getcsv($text, ',', '"', '\\'));
}

/** The destinations a form or a call sends: `destination[]`, or one string split on commas, spaces or new lines. Each an address. */
function admin_destinations(): array
{
    $raw = $_POST['destination'] ?? $_POST['destinations'] ?? null;
    $list = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw);
    $out = [];
    foreach ($list as $x) {
        $x = strtolower(trim((string) $x));
        if ($x === '') {
            continue;
        }
        if (!preg_match('/^[^@\s]+@[a-z0-9.-]+\.[a-z]{2,}$/', $x)) {
            ml_refuse_fields(['destination' => '"' . $x . '" is not an address.']);
        }
        $out[] = $x;
    }
    $out = array_values(array_unique($out));
    if ($out === []) {
        ml_refuse_fields(['destination' => 'Name at least one address to forward to.']);
    }
    if (count($out) > 20) {
        ml_refuse_fields(['destination' => 'A forwarding address forwards to at most 20 addresses.']);
    }
    return $out;
}

/** The outside destinations (design §5: not a served internal mailbox or alias) — and refused unless typed back in `confirm_outside`. */
function admin_confirm_outside(PDO $pdo, array $destinations): array
{
    $outside = [];
    $st = $pdo->prepare('SELECT ml_is_internal_address(:a)');
    foreach ($destinations as $d) {
        $st->execute(['a' => $d]);
        if (!$st->fetchColumn()) {
            $outside[] = $d;
        }
    }
    if ($outside !== []) {
        $typed = array_map('strtolower', array_filter(preg_split('/[\s,;]+/', (string) ($_POST['confirm_outside'] ?? '')) ?: []));
        $missing = array_values(array_diff($outside, $typed));
        if ($missing !== []) {
            ml_refuse_fields(['confirm_outside' => 'This forwards mail outside the business. Type ' . implode(', ', $missing) . ' to confirm.']);
        }
    }
    return $outside;
}

/** The two business-wide switches and the MM3 flag, for the banner every admin page carries while a switch is on. */
function admin_switches(PDO $pdo): array
{
    $r = $pdo->query('SELECT hold_all_agents, hold_all_external, mm3_identity_enforced FROM ml_settings WHERE id = 1')->fetch();
    return ['hold_all_agents' => (bool) $r['hold_all_agents'], 'hold_all_external' => (bool) $r['hold_all_external'], 'mm3' => (bool) $r['mm3_identity_enforced']];
}
