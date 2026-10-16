<?php
declare(strict_types=1);

/**
 * The send handlers' shared body (sending-and-oversight.md §3; writing.md): the fields read the same way for Send, Reply and Forward,
 * the HTML cleaned by the mail service, the size checked, ml_outbound_submit() through outbound_send(), the log row, and the answer by
 * the row's status — queued (the undo window), scheduled, held (its reasons), refused (its sentence). Required by the three handlers.
 */

/** The fields a send carries; $errors gains sentences. body_format html → the HTML is sanitised and its text derived; text → text alone. */
function send_fields(PDO $pdo, array $box, array &$errors): array
{
    $to = compose_addresses(compose_posted('to'), 'To', $errors);
    $cc = compose_addresses(compose_posted('cc'), 'Cc', $errors);
    $bcc = compose_addresses(compose_posted('bcc'), 'Bcc', $errors);
    $format = (string) (req_val('body_format') ?? 'text');
    $body = (string) (req_val('body') ?? '');
    $html = null;
    $text = $body;
    if ($format === 'html' && trim($body) !== '') {
        $clean = mailer_sanitise($body, 'compose');
        if ($clean === null) {
            refuse(503, 'The mail service is not answering, so the message cannot be cleaned and sent right now. Your draft is kept.');
        }
        $html = $clean['html'];
        $text = $clean['text'];
    }
    $identity = request_integer('identity');
    if ($identity !== null && !db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_identities WHERE id = :id AND mailbox_id = :mb)', ['id' => $identity, 'mb' => (int) $box['id']])) {
        $errors['identity'] = 'Choose an identity of this mailbox.';
    }
    $sendAt = compose_send_at(req_val('send_at'), member_timezone(), $errors);
    return ['to' => $to, 'cc' => $cc, 'bcc' => $bcc, 'subject' => mb_substr((string) (req_val('subject') ?? ''), 0, 998), 'body_html' => $html, 'body_text' => $text,
            'identity_id' => $identity, 'draft' => request_integer('draft'), 'purpose' => req_val('purpose'), 'send_at' => $sendAt];
}

/** The answer (§3 step 7) — JSON for the kernel and the composer's script, a landing for a form. */
function send_answer(PDO $pdo, array $box, array $res, string $event, array $log, int $undo): never
{
    $mb = (int) $box['id'];
    $o = $res['outbound'];
    $status = (string) $res['status'];
    $logEvent = $status === 'queued' && $res['send_after'] !== null && (new DateTimeImmutable((string) $res['send_after']))->getTimestamp() > time() + $undo + 5 ? 'message.schedule' : $event;
    if ($status === 'refused') {
        $logEvent = 'outbound.refuse';
    }
    $after = $log + ['status' => $status, 'held' => $res['hold_reasons'], 'refusal' => $res['refusal']];
    if ($logEvent === 'message.schedule') {
        $after['scheduled'] = true;
    }
    log_activity($pdo, $logEvent, 'outbound_email', $o, ['after' => $after, 'mailbox_id' => $mb]);
    if ($status !== 'refused') {
        ml_template_used($pdo, request_integer('template'));      // slice 6: a canned reply the composer started from counts once, when its message goes
    }
    $tz = member_timezone();
    $land = return_path('/m/' . $mb . '/v/inbox');
    switch ($status) {
        case 'queued':
            $secs = max(0, (new DateTimeImmutable((string) $res['send_after']))->getTimestamp() - time());
            if ($logEvent === 'message.schedule') {
                ml_done('Scheduled for ' . format_ts($res['send_after'], $tz, 'D j M, H:i'), $o, ml_land($land, 'scheduled'), 'mailChanged', ['status' => 'scheduled', 'send_after' => $res['send_after'], 'undo_id' => 'mail_send_cancel:' . $o]);
            }
            ml_done($secs > 0 ? 'Sending in ' . $secs . ' s — Undo' : 'Sending', $o, ml_land($land, 'sent'), 'mailChanged', ['status' => 'queued', 'seconds_left' => $secs, 'undo_id' => 'mail_send_cancel:' . $o, 'send_after' => $res['send_after']]);
        case 'held':
            ml_done('Held for review: ' . implode(', ', array_map(static fn (string $r): string => str_replace('_', ' ', $r), $res['hold_reasons'])), $o, ml_land($land, 'held'), 'mailChanged', ['status' => 'held', 'held' => $res['hold_reasons']]);
        case 'refused':
            emit_action_status(true, ['did' => (string) $res['error'], 'record_id' => $o, 'status' => 'refused', 'rule' => $res['refusal']]);
            if (wants_json()) {
                respond_saved(['did' => (string) $res['error'], 'record_id' => $o, 'status' => 'refused', 'rule' => $res['refusal'], 'location' => $land]);
            }
            refuse(422, (string) $res['error']);
        default:
            ml_done('Recorded as ' . $status, $o, ml_land($land, 'sent'), 'mailChanged', ['status' => $status]);
    }
}

/** A send's log payload: counts of recipients inside and outside — never an address, never a subject of a person's mailbox. */
function send_log_counts(PDO $pdo, array $fields): array
{
    $all = array_unique(array_merge($fields['to'], $fields['cc'], $fields['bcc']));
    $inside = 0;
    foreach ($all as $a) {
        if (db_bool($pdo, 'SELECT ml_is_internal_address(:a)', ['a' => $a])) {
            $inside++;
        }
    }
    return ['recipients_inside' => $inside, 'recipients_outside' => count($all) - $inside, 'attachments' => isset($fields['draft']) ? count(draft_attachments($pdo, (int) $fields['draft'])) : 0];
}

/** The undo window the mailbox uses (the mailbox's setting, else the business's). */
function send_undo_seconds(PDO $pdo, array $box): int
{
    $s = is_string($box['settings'] ?? null) ? (json_decode($box['settings'], true) ?: []) : [];
    return (int) ($s['undo_seconds'] ?? db_value($pdo, 'SELECT undo_send_seconds FROM ml_settings WHERE id = 1'));
}

/** The forwarded original's attachments, copied into the store for the new message (writing.md "Forward"): [[filename, mime_type, byte_size, sha256, storage_path]]. */
function forward_copy_attachments(int $mailbox, int $messageId, array $parts, int $member): array
{
    $out = [];
    foreach ($parts as $p) {
        $tmp = tempnam(sys_get_temp_dir(), 'mlfwd');
        $fh = fopen($tmp, 'wb');
        $ok = true;
        try {
            mailer_stream('/v1/mailboxes/' . $mailbox . '/part', $member, ['message' => $messageId, 'part' => (string) $p['part']],
                static function (int $status, array $h) use (&$ok): bool { $ok = $status === 200; return $ok; },
                static function (string $chunk) use ($fh): bool { fwrite($fh, $chunk); return true; }, ml_on_behalf(), 120);
        } catch (MailerDown) {
            $ok = false;
        }
        fclose($fh);
        if (!$ok) {
            @unlink($tmp);
            refuse(502, 'The original\'s attachment "' . $p['name'] . '" could not be read; try again.');
        }
        $size = (int) filesize($tmp);
        $sha = hash_file('sha256', $tmp) ?: bin2hex(random_bytes(16));
        $rel = 'files/' . date('Y/m') . '/' . substr($sha, 0, 2) . '/' . $sha . '-' . bin2hex(random_bytes(4));
        $abs = attachment_dir() . '/' . $rel;
        if (!is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0750, true);
        }
        rename($tmp, $abs);
        chmod($abs, 0640);
        $out[] = ['filename' => ml_safe_filename($p['name'] ?? null, 'attachment'), 'mime_type' => (string) ($p['type'] ?? 'application/octet-stream'), 'byte_size' => $size, 'sha256' => $sha, 'storage_path' => $rel];
    }
    return $out;
}
