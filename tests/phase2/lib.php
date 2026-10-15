<?php
/**
 * Helpers for the Phase 2 proofs (docs/build-specs/sso-shell.md), ported from Restaurant Inventory's. Run them through tests/phase2/run.sh,
 * which builds the scratch database (ml_dev2), seeds the mailboxes, starts the application (:9201), a fake kernel (:9202), a fake MaluDB +
 * MaluMail REST (:9203) and the mail service's skeleton (:9206, against the fake Mailbox MCP), and puts the scratch environment in the
 * process environment. Every proof prints "  ok   …" / "  FAIL …" and ends "all N passed" or "N FAILED".
 * The fixture (bin/dev_directory.json): 1 the owner (super-admin: the Mail admin), 26 Priya (Mail user), 27 Marco (holds the admin role but is
 * no super-admin), 28 Bea (write, no roles — a Mail user by capability), 29 Ann (read: no MaluMail role), 30 Dana (Mail user), 31 Lee (read,
 * no role), 40 the Expert (an agent), 42 the Clerk (an agent). The mailboxes run.sh seeds are in $ML_P2_SEED (JSON: address => id).
 */
chdir(dirname(__DIR__, 2));
$GLOBALS['fails'] = 0;
$GLOBALS['passes'] = 0;
define('BASE', 'http://127.0.0.1:' . (getenv('P_APP') ?: '9201'));
const APP = 'malumail';
function need(string $k): string { $v = getenv($k); if ($v === false || $v === '') { fwrite(STDERR, "$k is not set — run tests/phase2/run.sh\n"); exit(2); } return $v; }
function ok(bool $c, string $l, string $d = ''): void { $GLOBALS[$c ? 'passes' : 'fails']++; echo ($c ? '  ok   ' : '  FAIL ') . $l . ($c || $d === '' ? '' : ' — ' . $d) . "\n"; }
function finish(): never { echo $GLOBALS['fails'] ? "{$GLOBALS['fails']} FAILED ({$GLOBALS['passes']} passed)\n" : "all {$GLOBALS['passes']} passed\n"; exit($GLOBALS['fails'] ? 1 : 0); }
function pdo(): PDO {
    static $p = null;
    return $p ??= new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function q(string $sql, array $args = []): array { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchAll(); }
function one(string $sql, array $args = []) { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchColumn(); }
function fixture(): array { return json_decode(file_get_contents(dirname(__DIR__, 2) . '/bin/dev_directory.json'), true); }

function req(string $method, string $path, array $o = []): array
{
    $ch = curl_init((str_starts_with($path, 'http') ? '' : BASE) . $path);
    $h = $o['headers'] ?? [];
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => $o['jar'] ?? '/dev/null', CURLOPT_COOKIEFILE => $o['jar'] ?? '/dev/null'];
    if (isset($o['form'])) { $opt[CURLOPT_POSTFIELDS] = http_build_query($o['form']); }
    if (isset($o['json'])) { $opt[CURLOPT_POSTFIELDS] = json_encode($o['json']); $h[] = 'Content-Type: application/json'; }
    if (isset($o['raw'])) { $opt[CURLOPT_POSTFIELDS] = $o['raw']; }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($r, 0, $hs);
    $loc = preg_match('/^Location: (.+)$/mi', $headers, $m) ? trim($m[1]) : '';
    if (str_starts_with($loc, BASE)) { $loc = substr($loc, strlen(BASE)) ?: '/'; }      // Apache answers an absolute Location; compare paths
    return ['code' => $code, 'headers' => $headers, 'body' => substr($r, $hs), 'location' => $loc];
}
function jar(): string { return tempnam(sys_get_temp_dir(), 'mljar'); }
function csrf_of(string $html): string { preg_match('/name="csrf-token" id="csrf-token-meta" content="([^"]+)"/', $html, $m); return $m[1] ?? ''; }

/** A single-use hand-off URL path (/sso?token=&claims=), signed as the kernel signs one. $o: key, ttl, app, claims, scope. */
function handoff(int $member, array $o = []): string
{
    $key = $o['key'] ?? need('ACTION_TOKEN_KEY');
    $payload = $member . '.' . (time() + ($o['ttl'] ?? 60)) . '.' . ($o['app'] ?? APP) . '.' . bin2hex(random_bytes(16));
    $token = $payload . '.' . hash_hmac('sha256', 'sso:' . $payload, $key);
    $claims = ($o['claims'] ?? (fixture()['claims'][(string) $member] ?? [])) + ['member_id' => $member];
    if (array_key_exists('scope', $o)) { $claims['scope'] = $o['scope']; }
    $text = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    return '/sso?' . http_build_query(['token' => $token, 'claims' => $text . '.' . hash_hmac('sha256', $text, $key)]);
}
function sign_on(int $member, array $o = []): array { $j = jar(); return [$j, req('GET', handoff($member, $o), ['jar' => $j])]; }
function page(string $jar, string $path, array $o = []): array { return req('GET', $path, ['jar' => $jar] + $o); }
function page_csrf(string $jar): string { return csrf_of(req('GET', '/my/trail', ['jar' => $jar])['body']); }
function person_token(int $m, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl); return $p . '.' . hash_hmac('sha256', $p, need('ACTION_TOKEN_KEY')); }
function run_token(int $m, int $runId, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl) . '.' . $runId; return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY')); }
function relay_of(string $tok): string { return hash_hmac('sha256', $tok, need('ACTIONS_RELAY_KEY')); }
function as_agent(string $tok): array { return ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . relay_of($tok)]; }
function kernel_state(?callable $change = null): array
{
    $f = need('FAKE_KERNEL_STATE');
    $s = json_decode((string) file_get_contents($f), true) ?: [];
    if ($change) { $s = $change($s) ?? $s; file_put_contents($f, json_encode($s)); }
    return $s;
}
function incr(array $lists, string $next): array
{
    return array_merge(['schema' => 'os.directory-changes/1', 'since' => 'x', 'next' => $next, 'full' => false, 'members' => [], 'departments' => [], 'memberships' => [],
        'deleted_departments' => [], 'scopes' => [], 'access' => []], $lists);
}
function sync(string $args = ''): string { return trim((string) shell_exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/directory_sync.php') . ' ' . $args . ' 2>&1')); }
function activity(string $action, ?int $since = null): array { return q('SELECT * FROM activity_log WHERE action = :a AND id > :s ORDER BY id', ['a' => $action, 's' => $since ?? 0]); }
function last_activity_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM activity_log'); }
/** The seeded mailboxes' ids by local part (priya, dana, sales, expert). */
function mb(string $local): int { static $s = null; $s ??= json_decode((string) file_get_contents(need('ML_P2_SEED')), true); return (int) $s[$local]; }
