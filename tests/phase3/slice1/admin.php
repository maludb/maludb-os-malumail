<?php
/**
 * Slice 1's HTTP proof (docs/build-specs/domains-mailboxes.md "Proof" 1–8): domains served through the screen, an agent's and a person's
 * mailbox created, a person's password shown once and never stored, resets decided by the kind, connect and reconnect with the probe
 * against the IMAP fixture, suspend both sides, quota, update, forwarding addresses with an outside destination typed back, every right,
 * and no issued password anywhere it should not be. Run through tests/phase3/slice1/run.sh.
 * Members (bin/dev_directory.json): 1 the owner (super-admin: the Mail admin), 26 Priya and 30 Dana (Mail users), 27 Marco (the admin role,
 * no super-admin), 28 Bea, 40 the Expert (an agent).
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

$state = getenv('ML_DEV_STATE') ?: '/tmp/ml-dev1-state';
$issued = static function (string $address) use ($state): array {
    $f = $state . '/mcp/passwords.jsonl';
    $out = [];
    foreach (is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : [] as $l) {
        $r = json_decode($l, true);
        if (($r['address'] ?? '') === $address) { $out[] = $r['password']; }
    }
    return $out;
};
$mcpCalls = static function (string $tool) use ($state): array {
    $out = [];
    foreach (is_file($state . '/mcp.jsonl') ? file($state . '/mcp.jsonl', FILE_IGNORE_NEW_LINES) : [] as $l) {
        $r = json_decode($l, true);
        if (($r['tool'] ?? '') === $tool) { $out[] = $r['args']; }
    }
    return $out;
};
[$owner] = sign_on(1);
[$priya] = sign_on(26);
[$marco] = sign_on(27);
$csrf = page_csrf($owner);
$post = static fn (string $jar, string $path, array $form, array $o = []): array => req('POST', $path, ['jar' => $jar, 'form' => $form + ['csrf_token' => page_csrf($jar)]] + $o);
$json = ['headers' => ['Accept: application/json']];
$bodies = [];
$keep = static function (array $r) use (&$bodies): array { $bodies[] = $r['body']; return $r; };

// ---- 1. domains -----------------------------------------------------------------------------------------------------------------
$r = $keep(page($owner, '/admin/domains'));
ok($r['code'] === 200 && str_contains($r['body'], 'id="domain-business-example"') && str_contains($r['body'], 'id="domain-elsewhere-example"'),
   'Domains: MaluMail\'s two hosted domains are cards, neither served yet');
$r = $post($owner, '/admin/domains/serve.php', ['domain' => 'business.example', 'served' => 'yes', 'internal' => 'yes']);
ok($r['code'] === 302 && one("SELECT served::text || internal::text FROM ml_domains WHERE domain = 'business.example'") === 'truetrue', 'the owner serves business.example as internal');
$r = $post($owner, '/admin/domains/serve.php', ['domain' => 'business.example', 'served' => 'yes', 'internal' => 'no']);
ok(one("SELECT internal::text FROM ml_domains WHERE domain = 'business.example'") === 'false', '…marks it outside');
$post($owner, '/admin/domains/serve.php', ['domain' => 'business.example', 'served' => 'yes', 'internal' => 'yes']);
ok(one("SELECT internal::text FROM ml_domains WHERE domain = 'business.example'") === 'true', '…and internal again');
$r = $post($owner, '/admin/domains/serve.php', ['domain' => 'nothere.example', 'served' => 'yes'], $json);
ok($r['code'] === 422 && str_contains($r['body'], 'hosts no domain'), 'serving a domain MaluMail does not host for this business is refused');
ok(one("SELECT spf_ok::text FROM ml_domains WHERE domain = 'business.example'") !== false, 'a served domain\'s flags are saved as MaluMail last read them');
ok(count(activity('domains.serve')) === 3, 'every serve is in the trail (domains.serve)');

// ---- rights: a Mail user, the admin role without super-admin, an agent ----------------------------------------------------------
foreach (['/admin/domains', '/admin/mailboxes', '/admin/mailboxes/new', '/admin/aliases', '/admin/aliases/new'] as $p) {
    ok(page($priya, $p)['code'] === 403 && page($marco, $p)['code'] === 403, "a Mail user and Marco (the admin role, no super-admin) are refused $p");
}
$r = $post($priya, '/admin/mailboxes/create.php', ['domain' => 'business.example', 'local_part' => 'x', 'kind' => 'shared'], $json);
ok($r['code'] === 403, 'a Mail user cannot create a mailbox');
$tok = person_token(40);
$r = req('POST', '/admin/mailboxes/create.php', ['headers' => as_agent($tok), 'form' => ['domain' => 'business.example', 'local_part' => 'y', 'kind' => 'shared']]);
ok(in_array($r['code'], [401, 403], true) && $issued('y@business.example') === [], 'an agent\'s token is refused administration — MaluMail is never asked (' . $r['code'] . ')');

// ---- 2. an agent's mailbox -----------------------------------------------------------------------------------------------------
$r = $post($owner, '/admin/mailboxes/create.php', ['domain' => 'business.example', 'local_part' => 'scheduler', 'kind' => 'agent', 'display_name' => 'Scheduler',
    'owner_agent' => '40', 'primary_overseer' => '26', 'overseer_2' => '30', 'quota_gib' => '3']);
$sched = (int) one("SELECT id FROM mailboxes WHERE address = 'scheduler@business.example'");
ok($r['code'] === 302 && $r['location'] === "/admin/mailboxes/$sched", 'an agent\'s mailbox is created and the admin lands on it');
ok(one("SELECT kind || ',' || owner_member_id || ',' || primary_overseer_id || ',' || (quota_bytes / 1073741824) FROM mailboxes WHERE id = $sched") === 'agent,40,26,3',
   '…owned by the agent, overseen first by Priya, 3 GiB');
ok(one("SELECT string_agg(principal_id::text, ',' ORDER BY principal_id) FROM mailbox_permissions WHERE mailbox_id = $sched AND principal_kind = 'member' AND level = 'full'") === '26,30',
   '…both overseers hold full');
ok(one("SELECT c.status FROM mailboxes b JOIN mailbox_credentials c ON c.id = b.credential_id WHERE b.id = $sched") === 'ok' && (int) one("SELECT count(*) FROM agent_mail_policies WHERE mailbox_id = $sched") === 1,
   '…its credential sealed and stored, its policy seeded');
$p = $issued('scheduler@business.example');
$r = $keep(page($owner, "/admin/mailboxes/$sched"));
ok($p !== [] && $r['code'] === 200 && !str_contains($r['body'], $p[0]) && str_contains($r['body'], 'SMOKE Priya') && str_contains($r['body'], 'SMOKE Dana'),
   'its page names both overseers and never shows its password');
$r = $post($owner, '/admin/mailboxes/create.php', ['domain' => 'business.example', 'local_part' => 'lonely', 'kind' => 'agent', 'owner_agent' => '40'], $json);
ok($r['code'] === 422 && str_contains($r['body'], 'oversees') && $issued('lonely@business.example') === [], 'an agent\'s mailbox with no overseer is refused before MaluMail is asked');
$r = $post($owner, '/admin/mailboxes/create.php', ['domain' => 'elsewhere.example', 'local_part' => 'z', 'kind' => 'shared'], $json);
ok($r['code'] === 422, 'a mailbox on an unserved domain is refused');

// ---- 3. a person's mailbox: the password once, nothing stored --------------------------------------------------------------------
$r = $post($owner, '/admin/mailboxes/create.php', ['domain' => 'business.example', 'local_part' => 'robin', 'kind' => 'person', 'display_name' => 'Robin',
    'owner_person' => '28', 'quota_gib' => '2']);
$robin = (int) one("SELECT id FROM mailboxes WHERE address = 'robin@business.example'");
$rp = $issued('robin@business.example');
$shown = page($owner, "/admin/mailboxes/$robin");
ok($r['code'] === 302 && $rp !== [] && str_contains($shown['body'], 'id="mailbox-revealed-password"') && str_contains($shown['body'], $rp[0]),
   'a person\'s mailbox: its new password is shown once on its page, for their devices');
$again = $keep(page($owner, "/admin/mailboxes/$robin"));
ok(!str_contains($again['body'], $rp[0]) && !str_contains($again['body'], 'mailbox-revealed'), '…and a reload shows it no more');
ok(one("SELECT credential_id IS NULL FROM mailboxes WHERE id = $robin") === true && (int) one("SELECT count(*) FROM mailbox_credentials WHERE mailbox_id = $robin") === 0
   && str_contains($again['body'], 'waits on MM1'), '…nothing is stored for it: it waits on MaluMail\'s application passwords (MM1)');
$r = $post($owner, '/admin/mailboxes/create.php', ['domain' => 'business.example', 'local_part' => 'robin2', 'kind' => 'person', 'owner' => '28'], $json);
ok($r['code'] === 422 && $issued('robin2@business.example') === [], 'asked for a person\'s mailbox in JSON, it is refused before MaluMail is asked (the password would be lost or leaked)');
$keep($r);

// ---- 4. resets decided by the kind ---------------------------------------------------------------------------------------------------
$r = $post($owner, '/admin/mailboxes/reset.php', ['mailbox' => (string) $robin]);
$rp2 = $issued('robin@business.example');
$shown = page($owner, "/admin/mailboxes/$robin");
ok(count($rp2) === 2 && str_contains($shown['body'], $rp2[1]) && (int) one("SELECT count(*) FROM mailbox_credentials WHERE mailbox_id = $robin") === 0,
   'a person\'s reset shows the new password once and stores nothing');
$keep(page($owner, "/admin/mailboxes/$robin"));
$r = $post($owner, '/admin/mailboxes/reset.php', ['mailbox' => (string) $robin], $json);
ok($r['code'] === 422, 'a person\'s reset asked in JSON is refused');
$r = $keep($post($owner, '/admin/mailboxes/reset.php', ['mailbox' => (string) $sched], $json));
$sp = $issued('scheduler@business.example');
ok($r['code'] === 200 && count($sp) === 2 && !str_contains($r['body'], $sp[1]) && !str_contains(page($owner, "/admin/mailboxes/$sched")['body'], $sp[1]),
   'an agent\'s reset never shows the password, in JSON or on its page');
ok(one("SELECT string_agg(status, ',' ORDER BY id) FROM mailbox_credentials WHERE mailbox_id = $sched") === 'revoked,ok', '…the old credential revoked, the new one stored');

// ---- 5. connect, reconnect, the intake proposal --------------------------------------------------------------------------------------
$r = $post($owner, '/admin/mailboxes/connect.php', ['address' => 'info@business.example', 'kind' => 'shared', 'display_name' => 'Info']);
$info = (int) one("SELECT id FROM mailboxes WHERE address = 'info@business.example'");
ok($r['code'] === 302 && $info > 0 && one("SELECT c.status || ',' || (c.last_ok_at IS NOT NULL)::text FROM mailboxes b JOIN mailbox_credentials c ON c.id = b.credential_id WHERE b.id = $info") === 'ok,true',
   'connecting info@ resets it at MaluMail, stores the credential and the probe logs in (the IMAP fixture)');
$r = $post($owner, '/admin/mailboxes/connect.php', ['address' => 'desk@business.example', 'kind' => 'shared', 'display_name' => 'Desk']);
$desk = (int) one("SELECT id FROM mailboxes WHERE address = 'desk@business.example'");
$dv = page($owner, "/admin/mailboxes/$desk");
ok(one("SELECT intake_proposed FROM mailboxes WHERE id = $desk") === 'helpdesk' && str_contains($dv['body'], 'id="mailbox-update-intake-proposed"'),
   'desk@ has Help Desk\'s webhook at MaluMail: its intake is PROPOSED as Help Desk\'s and the page says so');
$r = $post($owner, '/admin/mailboxes/update.php', ['mailbox' => (string) $desk, 'display_name' => 'Help desk', 'intake_owner' => 'helpdesk', 'share_with_apps' => 'no']);
ok(one("SELECT intake_owner || ',' || COALESCE(intake_proposed, '-') || ',' || display_name || ',' || share_with_apps::text FROM mailboxes WHERE id = $desk") === 'helpdesk,-,Help desk,false',
   'the admin confirms the intake, renames it and stops sharing it with applications');
$r = $post($owner, '/admin/mailboxes/connect.php', ['address' => 'ghost@business.example', 'kind' => 'shared'], $json);
ok($r['code'] === 422 && str_contains($r['body'], 'has no mailbox'), 'connecting a mailbox MaluMail does not have is refused');
$r = $post($owner, '/admin/mailboxes/connect.php', ['address' => 'info@business.example', 'kind' => 'shared'], $json);
ok($r['code'] === 200 && one("SELECT string_agg(status, ',' ORDER BY id) FROM mailbox_credentials WHERE mailbox_id = $info") === 'revoked,ok', 'Reconnect gives it a new credential, the old revoked');
$keep($r);

// ---- 6. suspend both sides, 7. quota, 8. update -------------------------------------------------------------------------------------
$r = $post($owner, '/admin/mailboxes/suspend.php', ['mailbox' => (string) $sched, 'reason' => 'Retired']);
$act = $mcpCalls('set_mailbox_active');
ok(one("SELECT (suspended_at IS NOT NULL)::text || ',' || sync_state FROM mailboxes WHERE id = $sched") === 'true,paused' && end($act) === ['address' => 'scheduler@business.example', 'active' => false],
   'suspend: MaluMail told the mailbox is inactive, and the row suspended with its sync paused');
ok(str_contains(page($owner, "/admin/mailboxes/$sched")['body'], 'id="mailbox-suspended-banner"'), '…its page says so');
$r = $post($owner, '/admin/mailboxes/suspend.php', ['mailbox' => (string) $sched], $json);
ok($r['code'] === 422, 'a suspension needs a reason');
$post($owner, '/admin/mailboxes/reactivate.php', ['mailbox' => (string) $sched]);
$act = $mcpCalls('set_mailbox_active');
ok(one("SELECT suspended_at IS NULL FROM mailboxes WHERE id = $sched") === true && end($act)['active'] === true, 'reactivate lifts both');
$post($owner, '/admin/mailboxes/quota.php', ['mailbox' => (string) $sched, 'quota_gib' => '5']);
$q = $mcpCalls('set_mailbox_quota');
ok((int) one("SELECT quota_bytes / 1073741824 FROM mailboxes WHERE id = $sched") === 5 && end($q)['quota_gib'] === 5, 'the quota is changed at MaluMail and recorded');
$r = $post($owner, '/admin/mailboxes/quota.php', ['mailbox' => (string) $sched, 'quota_gib' => '60'], $json);
ok($r['code'] === 422, 'a quota over 50 GiB is refused');
$post($owner, '/admin/mailboxes/update.php', ['mailbox' => (string) $sched, 'display_name' => 'Scheduler agent', 'intake_owner' => 'none', 'primary_overseer' => '30']);
ok(one("SELECT display_name || ',' || primary_overseer_id FROM mailboxes WHERE id = $sched") === 'Scheduler agent,30', 'the admin renames it and makes Dana the primary overseer');
$r = $post($owner, '/admin/mailboxes/update.php', ['mailbox' => (string) $sched, 'primary_overseer' => '28'], $json);
ok($r['code'] === 422, 'a primary overseer must already hold full on it (Bea does not)');
$r = $post($owner, '/admin/mailboxes/update.php', ['mailbox' => (string) $robin, 'share_with_apps' => 'yes'], $json);
ok(one("SELECT share_with_apps FROM mailboxes WHERE id = $robin") === false, 'a person\'s mailbox is never shared with applications by a Mail admin');

// ---- the list ---------------------------------------------------------------------------------------------------------------------
$r = $keep(page($owner, '/admin/mailboxes'));
ok($r['code'] === 200 && preg_match_all('/id="mailbox-card-\d+"/', $r['body']) === 4, 'the list: four mailboxes as cards');
ok(str_contains(page($owner, '/admin/mailboxes?kind=person')['body'], "mailbox-card-$robin") && !str_contains(page($owner, '/admin/mailboxes?kind=person')['body'], "mailbox-card-$sched"),
   'the kind chip filters the cards');
$j = json_decode(page($owner, '/admin/mailboxes', $json)['body'], true)['data'] ?? [];
$mailKeys = array_filter(array_merge(...array_map('array_keys', $j['mailboxes'] ?? [[]])), static fn (string $k): bool => in_array($k, ['inbox_unseen', 'held_count', 'unread', 'subject', 'messages'], true));
ok(count($j['mailboxes'] ?? []) === 4 && $mailKeys === [], 'JSON: the same four, administrative facts only (no mail facts)');

// ---- 9. forwarding addresses --------------------------------------------------------------------------------------------------------
$r = $post($owner, '/admin/aliases/create.php', ['domain' => 'business.example', 'local_part' => 'sales', 'destination' => "robin@business.example\ninfo@business.example"]);
$sales = (int) one("SELECT id FROM ml_aliases WHERE address = 'sales@business.example'");
ok($r['code'] === 302 && $sales > 0 && str_starts_with($r['location'], "/admin/aliases/$sales") && one("SELECT bool_and(ml_is_internal_address(x)) FROM ml_aliases, unnest(destinations) x WHERE id = $sales") === true,
   'a forwarding address to two inside mailboxes is made with no confirmation');
$r = $post($owner, '/admin/aliases/create.php', ['domain' => 'business.example', 'local_part' => 'partner', 'destination' => 'rep@outside.example'], $json);
ok($r['code'] === 422 && str_contains($r['body'], 'rep@outside.example') && $mcpCalls('create_alias') !== [] && count($mcpCalls('create_alias')) === 1,
   'forwarding outside without typing the address back is refused — MaluMail is not asked');
$r = $post($owner, '/admin/aliases/create.php', ['domain' => 'business.example', 'local_part' => 'partner', 'destination' => 'rep@outside.example', 'confirm_outside' => 'rep@outside.example']);
$partner = (int) one("SELECT id FROM ml_aliases WHERE address = 'partner@business.example'");
$list = page($owner, '/admin/aliases');
ok($partner > 0 && str_contains($list['body'], "id=\"alias-card-$partner-outside\""), '…typed back, it is made, and its card is flagged Outside');
$r = $post($owner, '/admin/aliases/create.php', ['domain' => 'business.example', 'local_part' => 'info', 'destination' => 'robin@business.example'], $json);
ok($r['code'] === 422 && str_contains($r['body'], 'already exists'), 'MaluMail\'s own refusal (an address already a mailbox) is shown in its words');
$post($owner, '/admin/aliases/update.php', ['alias' => 'sales@business.example', 'destination' => 'robin@business.example']);
ok(one("SELECT array_to_string(destinations, ',') FROM ml_aliases WHERE id = $sales") === 'robin@business.example' && array_slice($mcpCalls('update_alias'), -1)[0]['forwards_to'] === ['robin@business.example'],
   'its destinations are changed at MaluMail and in the cache');
$post($owner, '/admin/aliases/delete.php', ['alias' => 'partner@business.example']);
ok(one("SELECT count(*) FROM ml_aliases WHERE address = 'partner@business.example'") === 0 && array_slice($mcpCalls('delete_alias'), -1)[0]['address'] === 'partner@business.example', 'deleted at MaluMail and from the cache');
ok(page($priya, "/admin/aliases/$sales")['code'] === 403, 'a Mail user cannot open a forwarding address');
$a = activity('alias.create');
ok(count($a) === 2 && !str_contains((string) $a[1]['after'], 'rep@outside.example') && str_contains((string) $a[1]['after'], 'outside.example'), 'the trail names an outside destination\'s domain, never the address');

// ---- the trail, and no password where it should not be ---------------------------------------------------------------------------
foreach (['mailbox.create' => 2, 'mailbox.connect' => 3, 'mailbox.update' => 2, 'mailbox.suspend' => 1, 'mailbox.reactivate' => 1, 'mailbox.quota' => 1,
          'mailbox.password_reset' => 2, 'alias.update' => 1, 'alias.delete' => 1] as $ev => $n) {
    ok(count(activity($ev)) === $n, "the trail holds $n $ev");
}
$all = [];
foreach (['scheduler', 'robin', 'info', 'desk'] as $l) { $all = array_merge($all, $issued("$l@business.example")); }
$trail = (string) one("SELECT string_agg(COALESCE(before::text, '') || COALESCE(after::text, ''), ' ') FROM activity_log");
$logs = (string) @file_get_contents($state . '/mailer.log') . (string) @file_get_contents($state . '/app.log') . (string) @file_get_contents($state . '/apache/apache.err');
$pages = implode("\n", $bodies);
ok($all !== [] && !array_filter($all, static fn (string $p): bool => str_contains($trail, $p)), 'no issued password is in the activity log');
ok(!array_filter($all, static fn (string $p): bool => str_contains($logs, $p)), 'none in the mail service\'s or the application\'s log');
ok(!array_filter($all, static fn (string $p): bool => str_contains($pages, $p)), 'none in any page or JSON answer but the once-shown page');
finish();
