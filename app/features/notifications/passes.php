<?php
declare(strict_types=1);

/**
 * Slice 9 — the worker's notification passes (notifications.md "Delivery"; bin/worker.php names them): `new_mail`, `quota`, `clerk_note`,
 * `notify_outbox`. Idempotent; each reads its own place from the last pass's report in worker_passes (the watermark of new_mail, the levels
 * of quota), so a pass run twice makes nothing twice. NO NOTICE CARRIES MAIL: the words of every notice made here are a count, an address
 * of a mailbox of the business, a percentage — never a subject, a sender, a folder name or any text of a message.
 */

/** The last report of a pass that kept $field: its value, or null (never run). The report is worker_passes.steps. */
function nt_last_report(PDO $pdo, string $pass, string $field): mixed
{
    $v = one_value($pdo, "SELECT steps->CAST(:p AS text)->>CAST(:f AS text) FROM worker_passes
                           WHERE jsonb_typeof(steps->CAST(:p2 AS text)) = 'object' AND steps->CAST(:p3 AS text)->>CAST(:f2 AS text) IS NOT NULL ORDER BY id DESC LIMIT 1",
        ['p' => $pass, 'f' => $field, 'p2' => $pass, 'p3' => $pass, 'f2' => $field]);
    return $v;
}

/** The database's clock (one source for the watermark: rows carry its now()). */
function nt_db_now(PDO $pdo): DateTimeImmutable
{
    return new DateTimeImmutable((string) one_value($pdo, 'SELECT clock_timestamp()'));
}

/**
 * New mail: for each person and each mailbox they chose (notification_prefs.new_mail_mailbox_ids), the INBOX messages that arrived in
 * (since, until], still unread, not one's own (sent from the mailbox's addresses, from the person's own mailboxes or their address), not in a
 * muted conversation, received within the last day (a re-read of old mail after a UIDVALIDITY change is no arrival) — as ONE coalesced notice
 * (ml_notify_coalesce(): a mailbox the person can no longer open gets none). Answers how many (person, mailbox) pairs were told.
 */
function pass_new_mail(PDO $pdo, DateTimeImmutable $since, ?DateTimeImmutable $until = null): int
{
    $until ??= nt_db_now($pdo);
    $pairs = $pdo->query("SELECT p.member_id, b.mailbox_id FROM notification_prefs p
                            JOIN members mem ON mem.id = p.member_id AND mem.status = 'active' AND mem.member_kind = 'human'
                            CROSS JOIN LATERAL unnest(p.new_mail_mailbox_ids) AS b(mailbox_id)
                           ORDER BY p.member_id, b.mailbox_id")->fetchAll();
    $count = $pdo->prepare("SELECT count(*) FROM mail_messages m
                              JOIN mail_folders f ON f.id = m.folder_id AND f.special_use = 'inbox'
                             WHERE m.mailbox_id = :mb AND m.created_at > CAST(:since AS timestamptz) AND m.created_at <= CAST(:until AS timestamptz)
                               AND m.received_at > CAST(:until2 AS timestamptz) - interval '1 day'
                               AND NOT m.seen AND NOT m.draft
                               AND NOT EXISTS (SELECT 1 FROM mail_identities i WHERE i.mailbox_id = m.mailbox_id AND i.address = m.from_address)
                               AND m.from_address IS DISTINCT FROM (SELECT x.address FROM mailboxes x WHERE x.id = m.mailbox_id)
                               AND NOT EXISTS (SELECT 1 FROM mailboxes o WHERE o.owner_member_id = :me AND o.address = m.from_address)
                               AND m.from_address IS DISTINCT FROM (SELECT y.email FROM members y WHERE y.id = :me2)
                               AND NOT COALESCE((SELECT t.muted FROM ml_thread_state(m.thread_id) t), false)");
    $told = 0;
    $fmt = 'Y-m-d H:i:s.uP';
    foreach ($pairs as $p) {
        $count->execute(['mb' => (int) $p['mailbox_id'], 'since' => $since->format($fmt), 'until' => $until->format($fmt), 'until2' => $until->format($fmt),
                         'me' => (int) $p['member_id'], 'me2' => (int) $p['member_id']]);
        $n = (int) $count->fetchColumn();
        if ($n > 0 && one_value($pdo, 'SELECT ml_notify_coalesce(:m, :mb, :n)', ['m' => (int) $p['member_id'], 'mb' => (int) $p['mailbox_id'], 'n' => $n]) !== null) {
            $told++;
        }
    }
    return $told;
}

/**
 * Quota: a mailbox at 90 % and at 100 % of its space, once per crossing — told to its owner (a person's mailbox) or its overseers (an agent's) and
 * the Mail admins. The last pass's levels say what was told; a mailbox falling below 90 % forgets, so the next crossing is told again.
 * Answers the notices made. $levels: [mailbox => level] now, for the report.
 */
function pass_quota(PDO $pdo, array &$levels = []): int
{
    $prev = json_decode((string) nt_last_report($pdo, 'quota', 'levels_json'), true);
    $prev = is_array($prev) ? $prev : [];
    $rows = $pdo->query("SELECT id, address::text AS address, kind, owner_member_id,
                                floor(quota_used_bytes * 100.0 / quota_bytes)::int AS pct
                           FROM mailboxes WHERE active AND quota_bytes IS NOT NULL AND quota_used_bytes IS NOT NULL ORDER BY id")->fetchAll();
    $made = 0;
    $levels = [];
    foreach ($rows as $b) {
        $id = (int) $b['id'];
        $level = (int) $b['pct'] >= 100 ? 100 : ((int) $b['pct'] >= 90 ? 90 : 0);
        if ($level > 0) {
            $levels[$id] = $level;
        }
        $before = (int) ($prev[(string) $id] ?? 0);
        if ($level === 0 || $level <= $before) {
            continue;
        }
        $who = [];
        foreach ($pdo->query('SELECT ml_mail_admin_ids()')->fetchAll(PDO::FETCH_COLUMN) as $a) {
            $who[(int) $a] = true;
        }
        if ($b['kind'] === 'person' && $b['owner_member_id'] !== null) {
            $who[(int) $b['owner_member_id']] = true;
        } elseif ($b['kind'] === 'agent') {
            $st = $pdo->prepare("SELECT m.id FROM members m WHERE m.member_kind = 'human' AND m.status = 'active' AND ml_is_decider(:mb, m.id)");
            $st->execute(['mb' => $id]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $o) {
                $who[(int) $o] = true;
            }
        }
        $title = $b['address'] . ' is ' . (int) $b['pct'] . ' % full';
        foreach (array_keys($who) as $m) {
            if (one_value($pdo, "SELECT ml_notify(:m, 'quota', :t, NULL, :mb, 'mailbox', :mb2)", ['m' => $m, 't' => $title, 'mb' => $id, 'mb2' => $id]) !== null) {
                $made++;
            }
        }
    }
    return $made;
}

/**
 * The Mail Clerk's morning note: once a weekday, at or after clerk_note_at in the business's time zone, each overseer (a person with FULL on an
 * agent's mailbox) gets ONE `digest` covering only their agents' mailboxes — held mail over a day old, Unscreened mail, a mailbox at 90 % or more,
 * a failing credential or sync — counts and the mailbox's address, never mail text; nothing to say, no note. Attributed to the named Clerk.
 * Once a day per person: the notice's record_id is the local date (YYYYMMDD). Answers the notes made.
 */
function pass_clerk_note(PDO $pdo, DateTimeImmutable $now): int
{
    $s = $pdo->query('SELECT timezone, to_char(clerk_note_at, \'HH24:MI\') AS at, clerk_member_id FROM ml_settings WHERE id = 1')->fetch();
    try {
        $local = $now->setTimezone(new DateTimeZone((string) ($s['timezone'] ?: 'UTC')));
    } catch (Exception) {
        $local = $now->setTimezone(new DateTimeZone('UTC'));
    }
    if ((int) $local->format('N') > 5 || $local->format('H:i') < (string) $s['at']) {
        return 0;
    }
    $day = (int) $local->format('Ymd');
    $overseers = $pdo->query("SELECT DISTINCT m.id FROM members m JOIN mailboxes b ON b.kind = 'agent' AND b.active
                               WHERE m.member_kind = 'human' AND m.status = 'active' AND ml_is_decider(b.id, m.id) ORDER BY m.id")->fetchAll(PDO::FETCH_COLUMN);
    $already = $pdo->prepare("SELECT 1 FROM notifications WHERE member_id = :m AND kind = 'digest' AND record_type = 'clerk_note' AND record_id = :d");
    $boxes = $pdo->prepare("SELECT b.id, b.address::text AS address,
                                   (SELECT count(*) FROM outbound_email o WHERE o.mailbox_id = b.id AND o.status = 'held' AND o.held_at < CAST(:now AS timestamptz) - interval '1 day') AS held,
                                   (SELECT count(*) FROM agent_dispatches d WHERE d.mailbox_id = b.id AND d.status = 'unscreened') AS unscreened,
                                   CASE WHEN b.quota_bytes IS NOT NULL AND b.quota_used_bytes IS NOT NULL THEN floor(b.quota_used_bytes * 100.0 / b.quota_bytes)::int END AS pct,
                                   (b.sync_state = 'failed' OR EXISTS (SELECT 1 FROM mailbox_credentials c WHERE c.id = b.credential_id AND c.status <> 'ok')) AS failing
                              FROM mailboxes b WHERE b.kind = 'agent' AND b.active AND ml_is_decider(b.id, :m) ORDER BY b.address");
    $made = 0;
    foreach ($overseers as $m) {
        $m = (int) $m;
        $already->execute(['m' => $m, 'd' => $day]);
        if ($already->fetchColumn() !== false) {
            continue;
        }
        $boxes->execute(['now' => $now->format('Y-m-d H:i:sP'), 'm' => $m]);
        $lines = [];
        foreach ($boxes->fetchAll() as $b) {
            $bits = [];
            if ((int) $b['held'] > 0) {
                $bits[] = (int) $b['held'] . ' held over a day';
            }
            if ((int) $b['unscreened'] > 0) {
                $bits[] = (int) $b['unscreened'] . ' unscreened';
            }
            if ($b['pct'] !== null && (int) $b['pct'] >= 90) {
                $bits[] = (int) $b['pct'] . ' % full';
            }
            if ($b['failing']) {
                $bits[] = 'not syncing';
            }
            if ($bits !== []) {
                $lines[] = $b['address'] . ': ' . implode(', ', $bits);
            }
        }
        if ($lines === []) {
            continue;
        }
        $nid = one_value($pdo, "SELECT ml_notify(:m, 'digest', :t, :b, NULL, 'clerk_note', :d, :clerk)",
            ['m' => $m, 't' => 'Your agents\' mail this morning', 'b' => implode("\n", $lines), 'd' => $day, 'clerk' => $s['clerk_member_id'] !== null ? (int) $s['clerk_member_id'] : null]);
        $made += $nid !== null ? 1 : 0;
    }
    return $made;
}

/**
 * The notification outbox: queued rows whose time has come, `email` through MaluMail's transactional /v1/send (app/mail.php — never through a
 * mailbox) and `text` through the kernel (K6: its own refusals — no verified phone, no sender, opted out, the daily cap — end the row as
 * skipped with the kernel's code). Three attempts, backed off one and five minutes, then failed. Plain text: the title, a link, and why you get it.
 * $detail: the counts by outcome. Answers the rows sent.
 */
function pass_notify_outbox(PDO $pdo, int $limit = 50, array &$detail = []): int
{
    $detail = ['sent' => 0, 'skipped' => 0, 'retry' => 0, 'failed' => 0];
    $st = $pdo->prepare("SELECT o.id, o.channel, o.member_id, o.to_email::text AS to_email, o.dedupe_key, o.subject, o.body, o.attempts
                           FROM notification_outbox o WHERE o.status = 'queued' AND o.send_after <= now() ORDER BY o.id LIMIT :l");
    $st->bindValue('l', max(1, $limit), PDO::PARAM_INT);
    $st->execute();
    $note = $pdo->prepare('SELECT kind, record_type, record_id, mailbox_id FROM notifications WHERE id = :id');
    $finish = static function (int $id, string $status, ?string $detailText, ?string $ref) use ($pdo): void {
        $pdo->prepare('UPDATE notification_outbox SET status = :s, detail = left(:d, 200), provider_ref = :r, sent_at = CASE WHEN :s2 = \'sent\' THEN now() END WHERE id = :id')
            ->execute(['s' => $status, 's2' => $status, 'd' => $detailText, 'r' => $ref, 'id' => $id]);
    };
    foreach ($st->fetchAll() as $o) {
        $id = (int) $o['id'];
        $href = '/notifications';
        if (preg_match('/^[nt]([0-9]+)$/', (string) $o['dedupe_key'], $m)) {
            $note->execute(['id' => (int) $m[1]]);
            $n = $note->fetch();
            $href = $n !== false ? nt_href($n, (int) $o['member_id']) : $href;
        }
        $link = app_url($href);
        if ($o['channel'] === 'text') {
            [$outcome, $code, $ref] = kernel_send_text((int) $o['member_id'], (string) $o['body'] . ' ' . $link, (string) $o['dedupe_key']);
            $result = $outcome === 'sent' ? ['sent', null, $ref] : ($outcome === 'retry' ? ['retry', $code, null] : ['skipped', $code, null]);
        } else {
            $text = (string) $o['subject'] . "\n\n" . ((string) $o['body'] !== '' && (string) $o['body'] !== (string) $o['subject'] ? (string) $o['body'] . "\n\n" : '')
                . 'Open it: ' . $link . "\n\n--\nYou get this because of your MaluMail notification settings: " . app_url('/settings/notifications') . "\n";
            try {
                $r = malumail_send(['from' => (string) env('MAIL_FROM', ''), 'from_name' => (string) env('MAIL_FROM_NAME', 'MaluMail'), 'to' => (string) $o['to_email'],
                                    'subject' => (string) $o['subject'], 'text' => $text]);
                if ($r['status'] === 200 && ($r['body']['accepted'] ?? []) !== []) {
                    $result = ['sent', null, $r['message_id']];
                } elseif ($r['status'] === 200 || $r['status'] === 400) {
                    $result = ['skipped', (string) ($r['body']['rejected'][0]['reason'] ?? 'no deliverable recipient'), null];
                } else {
                    $result = ['retry', 'http_' . $r['status'], null];
                }
            } catch (RuntimeException $e) {
                $result = str_contains($e->getMessage(), 'not configured') ? ['skipped', 'MaluMail\'s key is not configured', null] : ['retry', 'transport', null];
            }
        }
        [$status, $why, $ref] = $result;
        if ($status === 'retry') {
            $attempts = (int) $o['attempts'] + 1;
            if ($attempts >= 3) {
                $pdo->prepare("UPDATE notification_outbox SET status = 'failed', attempts = :a, detail = left(:d, 200) WHERE id = :id")->execute(['a' => $attempts, 'd' => $why, 'id' => $id]);
                $detail['failed']++;
            } else {
                $pdo->prepare("UPDATE notification_outbox SET attempts = :a, detail = left(:d, 200), send_after = now() + make_interval(mins => :m) WHERE id = :id")
                    ->execute(['a' => $attempts, 'd' => $why, 'm' => [1 => 1, 2 => 5][$attempts], 'id' => $id]);
                $detail['retry']++;
            }
            continue;
        }
        $finish($id, $status, $why, $ref);
        $detail[$status]++;
    }
    return $detail['sent'];
}
