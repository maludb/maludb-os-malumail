<?php
declare(strict_types=1);

/**
 * WRITING (docs/build-specs/writing.md — slice 4, THE THIRD EXEMPLAR; sending-and-oversight.md §3): the query functions behind the
 * composer and the nine actions. Every write is the schema's own function or a guarded statement under the wall: a draft's writer
 * needs triage (ml_drafts_guard), Send needs send (ml_outbound_submit — a person's mail is never held, an agent's passes the
 * policy there, never here), identities need full (people). Nothing here speaks IMAP or SMTP: the mail service files drafts
 * (pg_notify ml_drafts) and submits the outbox (pg_notify ml_outbox).
 *
 *   compose_identities()        the mailbox's identities, the default first
 *   compose_addresses()         a recipient field (chips, a form's list, or an agent's comma-separated string) → lower-cased addresses
 *   draft_save()                the row (made or replaced), the conflict rule, ['draft', 'version', 'conflict', 'created']
 *   draft_discard()             discarded_at (the mail service expunges its Drafts copy), its files removed
 *   draft_attachments()         the strip; draft_attachment_total() the bytes
 *   attachment_store()          one uploaded file into ML_STORAGE_DIR/files, its row on the draft
 *   outbound_send()             ml_outbound_submit() + the attachments moved; ['outbound', 'status', 'hold_reasons', 'refusal', 'send_after', 'error']
 *   outbound_cancel()           ml_outbound_cancel() → the reopened draft
 *   outbound_retry()            ml_outbound_retry()
 *   identity_save() / identity_delete()
 */

const ML_ADDRESS_RE = '/^[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+$/';
const ML_RECIPIENTS_MAX = 100;
const ML_ATTACHMENT_MAX_BYTES = 7 * 1024 * 1024;

/** The mailbox's identities (mcp_identities — the caller opens the mailbox), the default first; the mailbox's own address when it has none. */
function compose_identities(PDO $pdo, array $box): array
{
    $st = $pdo->prepare('SELECT id, address, kind, display_name, reply_to, signature_html, signature_text, is_default FROM mcp_identities WHERE mailbox_id = :mb ORDER BY is_default DESC, kind, address');
    $st->execute(['mb' => (int) $box['id']]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['is_default'] = $r['is_default'] === true || $r['is_default'] === 't';
    }
    unset($r);
    if ($rows === []) {
        $rows[] = ['id' => null, 'address' => $box['address'], 'kind' => 'own', 'display_name' => $box['display_name'], 'reply_to' => null,
                   'signature_html' => null, 'signature_text' => null, 'is_default' => true];
    }
    return $rows;
}

/** The served internal domains (an outside address is outlined on the composer). */
function compose_internal_domains(PDO $pdo): array
{
    return array_map('strtolower', array_column($pdo->query('SELECT domain FROM mcp_domains WHERE internal')->fetchAll(), 'domain'));
}

/** A recipient field as posted: the chips (`to[]`, the script's) and what was typed in the box (`to_typed` — all there is without a script). */
function compose_posted(string $field): array
{
    $v = $_POST[$field] ?? [];
    $items = is_array($v) ? array_values($v) : [(string) $v];
    $typed = $_POST[$field . '_typed'] ?? '';
    if (is_string($typed) && trim($typed) !== '') {
        $items[] = $typed;
    }
    return $items === [] ? [] : preg_split('/[,;\n]+/', implode("\n", array_map('strval', $items)));
}

/**
 * A recipient field → addresses: a list (`to[]`), or one string with commas, semicolons or newlines; "Name <addr>" accepted; lower-cased,
 * unique, each validated. Adds a sentence to $errors for a bad one; more than 100 refused.
 */
function compose_addresses(mixed $v, string $label, array &$errors): array
{
    $items = is_array($v) ? $v : preg_split('/[,;\n]+/', (string) $v);
    $out = [];
    foreach ($items as $x) {
        $x = trim((string) $x);
        if ($x === '') {
            continue;
        }
        if (preg_match('/<([^<>]+)>\s*$/', $x, $m)) {
            $x = trim($m[1]);
        }
        $x = strtolower($x);
        if (!preg_match(ML_ADDRESS_RE, $x)) {
            $errors[strtolower($label)] = $label . ': "' . mb_substr($x, 0, 60) . '" is not an address.';
            continue;
        }
        $out[$x] = true;
    }
    $list = array_keys($out);
    if (count($list) > ML_RECIPIENTS_MAX) {
        $errors[strtolower($label)] = 'At most ' . ML_RECIPIENTS_MAX . ' recipients.';
    }
    return $list;
}

/** A draft's row, as the composer and the JSON read it; null when it is not here or not the caller's to see (mcp_drafts). */
function draft_row(PDO $pdo, int $mailbox, int $draft): ?array
{
    $st = $pdo->prepare('SELECT d.*, r.in_reply_to, r.references_ids, r.uid, r.uidvalidity, r.folder_id, r.discarded_at, r.filed_at, (SELECT o.status FROM outbound_email o WHERE o.id = d.outbound_id) AS outbound_status FROM mcp_drafts d JOIN mail_drafts r ON r.id = d.id WHERE d.id = :id AND d.mailbox_id = :mb');
    $st->execute(['id' => $draft, 'mb' => $mailbox]);
    $d = $st->fetch();
    if ($d === false) {
        return null;
    }
    foreach (['to_addresses', 'cc_addresses', 'bcc_addresses'] as $k) {
        $d[$k] = ml_pg_text_array((string) $d[$k]);
    }
    return $d;
}

/**
 * Save a draft (writing.md "Autosave"; decision 2: the row is the immediate save, the mail service files it within seconds). $fields:
 * identity_id, to, cc, bcc (arrays), subject, body_html (already sanitised), body_text, reply_to_key_id, mode, version (the saved_at the
 * client holds — a save whose version is older than the row's LOSES: ['conflict' => true], nothing written).
 */
function draft_save(PDO $pdo, int $mailbox, ?int $draft, array $fields, int $member): array
{
    $created = false;
    if ($draft !== null) {
        $row = draft_row($pdo, $mailbox, $draft);
        if ($row === null) {
            refuse(404, 'No such draft.');
        }
        if ($row['discarded_at'] !== null) {
            refuse(422, 'This draft was discarded.');
        }
        if ($row['outbound_id'] !== null && in_array($row['outbound_status'], ['queued', 'sending', 'sent', 'deferred', 'held'], true)) {
            refuse(422, 'This draft was sent.');
        }
        $version = (string) ($fields['version'] ?? '');
        if ($version !== '' && (new DateTimeImmutable($row['saved_at']))->format('U.u') > (new DateTimeImmutable($version))->format('U.u')) {
            return ['draft' => $draft, 'version' => $row['saved_at'], 'conflict' => true, 'created' => false];
        }
    }
    $args = [
        'identity' => $fields['identity_id'] ?? null, 'to' => pg_array_literal($fields['to'] ?? []), 'cc' => pg_array_literal($fields['cc'] ?? []),
        'bcc' => pg_array_literal($fields['bcc'] ?? []), 'subject' => (string) ($fields['subject'] ?? ''), 'html' => $fields['body_html'] ?? null,
        'text' => (string) ($fields['body_text'] ?? ''), 'reply' => $fields['reply_to_key_id'] ?? null, 'mode' => $fields['mode'] ?? 'new',
        'irt' => $fields['in_reply_to'] ?? null, 'refs' => pg_array_literal($fields['references_ids'] ?? []), 'member' => $member,
    ];
    if (!in_array($args['mode'], ['new', 'reply', 'reply_all', 'forward'], true)) {
        $args['mode'] = 'new';
    }
    return ml_guard($pdo, static function () use ($pdo, $mailbox, $draft, $args, &$created): array {
        if ($draft === null) {
            $domain = (string) db_value($pdo, "SELECT split_part(address::text, '@', 2) FROM mailboxes WHERE id = :mb", ['mb' => $mailbox]);
            $st = $pdo->prepare('INSERT INTO mail_drafts (mailbox_id, member_id, draft_key, identity_id, to_addresses, cc_addresses, bcc_addresses, subject, body_html, body_text,
                                                           reply_to_key_id, mode, in_reply_to, references_ids)
                                 VALUES (:mb, :member, :key, :identity, :to, :cc, :bcc, :subject, :html, :text, :reply, :mode, :irt, :refs) RETURNING id, saved_at');
            $st->execute($args + ['mb' => $mailbox, 'key' => '<draft-' . bin2hex(random_bytes(12)) . '.malumail@' . ($domain !== '' ? $domain : 'local') . '>']);
            $r = $st->fetch();
            $created = true;
            return ['draft' => (int) $r['id'], 'version' => $r['saved_at'], 'conflict' => false, 'created' => true];
        }
        $st = $pdo->prepare('UPDATE mail_drafts SET member_id = COALESCE(member_id, :member), identity_id = :identity, to_addresses = :to, cc_addresses = :cc, bcc_addresses = :bcc,
                                subject = :subject, body_html = :html, body_text = :text, reply_to_key_id = :reply, mode = :mode, in_reply_to = :irt, references_ids = :refs,
                                saved_at = clock_timestamp()
                              WHERE id = :id AND mailbox_id = :mb RETURNING saved_at');
        $st->execute($args + ['id' => $draft, 'mb' => $mailbox]);
        $r = $st->fetch();
        return ['draft' => $draft, 'version' => $r['saved_at'], 'conflict' => false, 'created' => false];
    });
}

/** Discard a draft: its files go now, its row is marked, the mail service expunges its Drafts copy. */
function draft_discard(PDO $pdo, int $mailbox, int $draft, int $member): void
{
    $row = draft_row($pdo, $mailbox, $draft);
    if ($row === null) {
        refuse(404, 'No such draft.');
    }
    if ($row['outbound_id'] !== null && in_array($row['outbound_status'], ['queued', 'sending', 'sent', 'deferred', 'held'], true)) {
        refuse(422, 'This draft was sent; cancel the message instead.');
    }
    foreach (draft_attachments($pdo, $draft) as $a) {
        attachment_unlink($a);
    }
    $pdo->prepare("DELETE FROM attachments WHERE record_type = 'mail_draft' AND record_id = :id")->execute(['id' => $draft]);
    $pdo->prepare('UPDATE mail_drafts SET discarded_at = COALESCE(discarded_at, now()) WHERE id = :id AND mailbox_id = :mb')->execute(['id' => $draft, 'mb' => $mailbox]);
}

function draft_attachments(PDO $pdo, int $draft): array
{
    $st = $pdo->prepare("SELECT id, filename, mime_type, byte_size, sha256, storage_path, created_at FROM attachments WHERE record_type = 'mail_draft' AND record_id = :id ORDER BY id");
    $st->execute(['id' => $draft]);
    return $st->fetchAll();
}

function draft_attachment_total(PDO $pdo, int $draft): int
{
    return (int) db_value($pdo, "SELECT COALESCE(sum(byte_size), 0) FROM attachments WHERE record_type = 'mail_draft' AND record_id = :id", ['id' => $draft]);
}

/** The attachment store (ML_STORAGE_DIR, default storage/ under the application): files/ for drafts, kept until the message is sent and filed. */
function attachment_dir(): string
{
    $d = (string) env('ML_STORAGE_DIR', 'storage');
    if (!str_starts_with($d, '/')) {
        $d = APP_ROOT . '/' . $d;
    }
    return rtrim($d, '/');
}

/** One uploaded file onto a draft: ≤ 7 MB, the draft's total ≤ max_attachments_mb; the bytes in files/, the row on the draft. */
function attachment_store(PDO $pdo, int $draft, string $tmpPath, string $name, int $size, int $member): array
{
    $max = (int) db_value($pdo, 'SELECT max_attachments_mb FROM ml_settings WHERE id = 1');
    $max = ($max > 0 ? $max : 7) * 1024 * 1024;
    if ($size > ML_ATTACHMENT_MAX_BYTES) {
        refuse(422, 'One file is at most 7 MB; this one is ' . ml_size_label($size) . '.');
    }
    $total = draft_attachment_total($pdo, $draft);
    if ($total + $size > $max) {
        refuse(422, 'The attachments would come to ' . ml_size_label($total + $size) . '; a message carries at most ' . ml_size_label($max) . '.');
    }
    $name = ml_safe_filename($name, 'file');
    $sha = hash_file('sha256', $tmpPath) ?: bin2hex(random_bytes(16));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($tmpPath) ?: 'application/octet-stream');
    $rel = 'files/' . date('Y/m') . '/' . substr($sha, 0, 2) . '/' . $sha . '-' . bin2hex(random_bytes(4));
    $abs = attachment_dir() . '/' . $rel;
    if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0750, true) && !is_dir(dirname($abs))) {
        refuse(503, 'The attachment store is not writable.');
    }
    if (!(is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $abs) : rename($tmpPath, $abs))) {
        refuse(503, 'The file could not be stored.');
    }
    chmod($abs, 0640);
    $st = $pdo->prepare("INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by)
                         VALUES ('mail_draft', :id, :name, :mime, :size, :sha, :path, :by) RETURNING id");
    $st->execute(['id' => $draft, 'name' => $name, 'mime' => $mime, 'size' => $size, 'sha' => $sha, 'path' => $rel, 'by' => $member]);
    return ['id' => (int) $st->fetchColumn(), 'filename' => $name, 'mime_type' => $mime, 'byte_size' => $size, 'storage_path' => $rel];
}

function attachment_unlink(array $a): void
{
    $p = (string) $a['storage_path'];
    $abs = str_starts_with($p, '/') ? $p : attachment_dir() . '/' . $p;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

/**
 * Send (sending-and-oversight.md §3): the size of the attachments checked, ml_outbound_submit() under ml_guard() (the policy, the refusal,
 * the undo window or the schedule are the schema's), the draft's attachments moved to the message. $fields: identity_id, to, cc, bcc,
 * subject, body_text, body_html, reply_to_key_id, draft, purpose, send_at (a UTC timestamp string or null), extra_attachments (rows
 * copied from a forwarded original: [[filename, mime, bytes path]]).
 */
function outbound_send(PDO $pdo, int $mailbox, int $member, array $fields): array
{
    $draft = isset($fields['draft']) ? (int) $fields['draft'] : null;
    $atts = $draft !== null ? draft_attachments($pdo, $draft) : [];
    $extra = $fields['extra_attachments'] ?? [];
    $total = array_sum(array_map(static fn (array $a): int => (int) $a['byte_size'], $atts)) + array_sum(array_map(static fn (array $a): int => (int) $a['byte_size'], $extra));
    $max = (int) db_value($pdo, 'SELECT max_attachments_mb FROM ml_settings WHERE id = 1');
    $max = ($max > 0 ? $max : 7) * 1024 * 1024;
    if ($total > $max) {
        refuse(422, 'The attachments come to ' . ml_size_label($total) . '; a message carries at most ' . ml_size_label($max) . '.');
    }
    $runId = current_agent_run_id();
    $oid = ml_guard($pdo, static function () use ($pdo, $mailbox, $member, $fields, $draft, $total, $extra, $runId): int {
        $st = $pdo->prepare('SELECT ml_outbound_submit(:mb, :sender, :identity, :to, :cc, :bcc, :subject, :text, :html, :reply, :draft, :purpose, :has_att, :send_at, :run)');
        $st->execute(['mb' => $mailbox, 'sender' => $member, 'identity' => $fields['identity_id'] ?? null, 'to' => pg_array_literal($fields['to'] ?? []),
                      'cc' => pg_array_literal($fields['cc'] ?? []), 'bcc' => pg_array_literal($fields['bcc'] ?? []), 'subject' => (string) ($fields['subject'] ?? ''),
                      'text' => (string) ($fields['body_text'] ?? ''), 'html' => $fields['body_html'] ?? null, 'reply' => $fields['reply_to_key_id'] ?? null,
                      'draft' => $draft, 'purpose' => $fields['purpose'] ?? null, 'has_att' => $total > 0 ? 't' : 'f', 'send_at' => $fields['send_at'] ?? null, 'run' => $runId]);
        return (int) $st->fetchColumn();
    });
    if ($draft !== null) {
        $pdo->prepare("UPDATE attachments SET record_type = 'outbound_email', record_id = :o WHERE record_type = 'mail_draft' AND record_id = :d")->execute(['o' => $oid, 'd' => $draft]);
    }
    foreach ($extra as $a) {
        $pdo->prepare("INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES ('outbound_email', :o, :n, :m, :s, :sha, :p, :by)")
            ->execute(['o' => $oid, 'n' => $a['filename'], 'm' => $a['mime_type'], 's' => $a['byte_size'], 'sha' => $a['sha256'], 'p' => $a['storage_path'], 'by' => $member]);
    }
    $st = $pdo->prepare('SELECT id, status, hold_reasons, refusal, error, send_after, message_id FROM outbound_email WHERE id = :id');
    $st->execute(['id' => $oid]);
    $o = $st->fetch();
    return ['outbound' => $oid, 'status' => $o['status'], 'hold_reasons' => ml_pg_text_array((string) $o['hold_reasons']), 'refusal' => $o['refusal'],
            'error' => $o['error'], 'send_after' => $o['send_after'], 'message_id' => $o['message_id']];
}

/** Undo (the writer, inside the window) or a held message withdrawn: ml_outbound_cancel() → the draft reopened. */
function outbound_cancel(PDO $pdo, int $mailbox, int $outbound, int $member): ?int
{
    $st = $pdo->prepare('SELECT status FROM mcp_outbound WHERE id = :id AND mailbox_id = :mb');
    $st->execute(['id' => $outbound, 'mb' => $mailbox]);
    if ($st->fetchColumn() === false) {
        refuse(404, 'No such message.');
    }
    $d = ml_guard($pdo, static function () use ($pdo, $outbound, $member) {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT ml_outbound_cancel(:o, :m)');
            $st->execute(['o' => $outbound, 'm' => $member]);
            $d = $st->fetchColumn();
            if ($d !== null && $d !== false) {
                // the attachments outbound_send() moved to the message go back to the reopened draft with it
                $pdo->prepare("UPDATE attachments SET record_type = 'mail_draft', record_id = :d WHERE record_type = 'outbound_email' AND record_id = :o")
                    ->execute(['d' => (int) $d, 'o' => $outbound]);
            }
            $pdo->commit();
            return $d;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    });
    return $d === null || $d === false ? null : (int) $d;
}

function outbound_retry(PDO $pdo, int $mailbox, int $outbound, int $member): void
{
    $st = $pdo->prepare('SELECT status FROM mcp_outbound WHERE id = :id AND mailbox_id = :mb');
    $st->execute(['id' => $outbound, 'mb' => $mailbox]);
    if ($st->fetchColumn() === false) {
        refuse(404, 'No such message.');
    }
    ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_outbound_retry(:o, :m)')->execute(['o' => $outbound, 'm' => $member]));
}

/** The outbound row for a status poll (the composer's bar) — the caller must open the mailbox (mcp_outbound). */
function outbound_status(PDO $pdo, int $mailbox, int $outbound): ?array
{
    $st = $pdo->prepare('SELECT o.id, o.status, o.hold_reasons, o.refusal, o.error, o.send_after, o.sent_at, o.smtp_reply, (SELECT e.draft_id FROM outbound_email e WHERE e.id = o.id) AS draft_id,
                                (SELECT m.thread_id FROM mail_messages m WHERE m.message_id_header = o.message_id AND m.mailbox_id = o.mailbox_id ORDER BY m.id LIMIT 1) AS thread_id
                           FROM mcp_outbound o WHERE o.id = :id AND o.mailbox_id = :mb');
    $st->execute(['id' => $outbound, 'mb' => $mailbox]);
    $o = $st->fetch();
    if ($o === false) {
        return null;
    }
    $o['hold_reasons'] = ml_pg_text_array((string) $o['hold_reasons']);
    return $o;
}

/** An identity saved (people with full — the handler checks): own or alias (checked through the mail service by the handler). */
function identity_save(PDO $pdo, int $mailbox, ?int $identity, array $fields, int $member): int
{
    return ml_guard($pdo, static function () use ($pdo, $mailbox, $identity, $fields, $member): int {
        if (!empty($fields['is_default'])) {
            $pdo->prepare('UPDATE mail_identities SET is_default = false WHERE mailbox_id = :mb' . ($identity !== null ? ' AND id <> :id' : ''))
                ->execute($identity !== null ? ['mb' => $mailbox, 'id' => $identity] : ['mb' => $mailbox]);
        }
        if ($identity === null) {
            $st = $pdo->prepare('INSERT INTO mail_identities (mailbox_id, address, kind, display_name, reply_to, signature_html, signature_text, is_default, created_by, checked_at)
                                 VALUES (:mb, :address, :kind, :name, :reply, :html, :text, :def, :by, :checked) RETURNING id');
            $st->execute(['mb' => $mailbox, 'address' => $fields['address'], 'kind' => $fields['kind'], 'name' => $fields['display_name'], 'reply' => $fields['reply_to'],
                          'html' => $fields['signature_html'], 'text' => $fields['signature_text'], 'def' => !empty($fields['is_default']) ? 't' : 'f', 'by' => $member,
                          'checked' => $fields['kind'] === 'alias' ? date('c') : null]);
            return (int) $st->fetchColumn();
        }
        $st = $pdo->prepare('UPDATE mail_identities SET display_name = :name, reply_to = :reply, signature_html = :html, signature_text = :text,
                                    is_default = CASE WHEN :def::boolean THEN true ELSE is_default END
                              WHERE id = :id AND mailbox_id = :mb');
        $st->execute(['id' => $identity, 'mb' => $mailbox, 'name' => $fields['display_name'], 'reply' => $fields['reply_to'], 'html' => $fields['signature_html'],
                      'text' => $fields['signature_text'], 'def' => !empty($fields['is_default']) ? 't' : 'f']);
        if ($st->rowCount() === 0) {
            throw new DomainException('Not found.');
        }
        return $identity;
    });
}

/** Delete an identity: never the `own` one, never the default while another is not chosen. */
function identity_delete(PDO $pdo, int $mailbox, int $identity): void
{
    $st = $pdo->prepare('SELECT kind, is_default FROM mail_identities WHERE id = :id AND mailbox_id = :mb');
    $st->execute(['id' => $identity, 'mb' => $mailbox]);
    $row = $st->fetch();
    if ($row === false) {
        refuse(404, 'No such identity.');
    }
    if ($row['kind'] === 'own') {
        refuse(422, "The mailbox's own address is not deleted.");
    }
    if ($row['is_default'] === true || $row['is_default'] === 't') {
        refuse(422, 'Choose another default identity first.');
    }
    $pdo->prepare('DELETE FROM mail_identities WHERE id = :id AND mailbox_id = :mb')->execute(['id' => $identity, 'mb' => $mailbox]);
}

/** A person's choice of rich or plain, remembered on their own mailbox's settings (decision 6). */
function compose_format_pref(PDO $pdo, int $member, ?string $set = null): string
{
    $st = $pdo->prepare("SELECT id, settings FROM mailboxes WHERE kind = 'person' AND owner_member_id = :m LIMIT 1");
    $st->execute(['m' => $member]);
    $own = $st->fetch();
    if ($own === false) {
        return 'html';
    }
    $settings = is_string($own['settings']) ? (json_decode($own['settings'], true) ?: []) : [];
    if ($set !== null && in_array($set, ['html', 'text'], true) && ($settings['compose_format'] ?? 'html') !== $set) {
        $settings['compose_format'] = $set;
        $pdo->prepare('UPDATE mailboxes SET settings = :s WHERE id = :id')->execute(['s' => json_encode($settings), 'id' => (int) $own['id']]);
        return $set;
    }
    return ($settings['compose_format'] ?? 'html') === 'text' ? 'text' : 'html';
}
