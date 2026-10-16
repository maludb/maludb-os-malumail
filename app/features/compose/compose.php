<?php
declare(strict_types=1);

/**
 * The composer's rules (writing.md "compose"): prefill for a reply, reply all and forward from a message KEY (decision 3), the
 * subject and recipient rules, the quote from the SANITISED body (decision 4 — the mail service's fetch, compose profile), the
 * forwarded header block, the signature, the schedule's times. Nothing here sends.
 */

/** The message a reply or forward starts from, by its message key, in a mailbox the caller opens; null when not there. */
function compose_original(PDO $pdo, int $mailbox, int $key): ?array
{
    $st = $pdo->prepare('SELECT m.id, m.message_key_id, m.thread_id, m.from_address, m.from_name, m.sender_address, m.to_addresses, m.cc_addresses, m.recipient_names,
                                m.reply_to, m.subject, m.sent_at, m.received_at, m.message_id_header, m.references_ids, m.has_attachments, m.attachment_names
                           FROM mcp_messages m WHERE m.mailbox_id = :mb AND m.message_key_id = :k ORDER BY m.id LIMIT 1');
    $st->execute(['mb' => $mailbox, 'k' => $key]);
    $m = $st->fetch();
    if ($m === false) {
        return null;
    }
    foreach (['to_addresses', 'cc_addresses', 'reply_to', 'references_ids', 'attachment_names'] as $k) {
        $m[$k] = ml_pg_text_array((string) $m[$k]);
    }
    $m['recipient_names'] = is_string($m['recipient_names']) ? (json_decode($m['recipient_names'], true) ?: []) : [];
    return $m;
}

/** The mailbox's own addresses (its address and its identities), lower-cased — left out of a reply all. */
function compose_own_addresses(PDO $pdo, array $box): array
{
    $own = [strtolower((string) $box['address'])];
    foreach (compose_identities($pdo, $box) as $i) {
        $own[] = strtolower((string) $i['address']);
    }
    return array_values(array_unique($own));
}

/** `Re: ` once, `Fwd: ` once — on the normalised subject (ml_subject_norm strips every prefix in every language). */
function compose_subject(PDO $pdo, string $mode, ?string $subject): string
{
    $norm = (string) db_value($pdo, 'SELECT ml_subject_norm(:s)', ['s' => (string) $subject]);
    $base = trim((string) $subject);
    // keep the original's casing: take the text after the stripped prefixes when it is a suffix of the original
    $tail = $norm !== '' && stripos($base, $norm) !== false ? substr($base, stripos($base, $norm), strlen($norm)) : $base;
    if ($tail === '') {
        $tail = $base;
    }
    return ($mode === 'forward' ? 'Fwd: ' : 'Re: ') . $tail;
}

/** The attribution line above a quote. */
function compose_attribution(array $m, string $tz): string
{
    $when = format_ts($m['sent_at'] ?? $m['received_at'], $tz, 'D, j M Y \a\t H:i');
    $who = ($m['from_name'] ?? '') !== '' ? $m['from_name'] . ' <' . $m['from_address'] . '>' : (string) $m['from_address'];
    return 'On ' . $when . ', ' . $who . ' wrote:';
}

/**
 * The prefill: to, cc, subject, the quote (html and text), the original's attachments (for a forward), the threading fields.
 * $mode: reply · reply_all · forward. The quote is the mail service's sanitised body under the compose profile (no images, links
 * kept); when the service cannot answer, the index's snippet stands in and `quote_unavailable` says so.
 */
function compose_prefill(PDO $pdo, int $mailbox, array $box, string $mode, int $key, int $member): ?array
{
    $m = compose_original($pdo, $mailbox, $key);
    if ($m === null) {
        return null;
    }
    $tz = member_timezone();
    $own = compose_own_addresses($pdo, $box);
    $to = [];
    $cc = [];
    if ($mode !== 'forward') {
        $replyTo = $m['reply_to'] !== [] ? $m['reply_to'] : [strtolower((string) $m['from_address'])];
        foreach ($replyTo as $a) {
            $a = strtolower((string) $a);
            if ($a !== '' && !in_array($a, $own, true)) {
                $to[] = $a;
            }
        }
        if ($to === [] && !in_array(strtolower((string) $m['from_address']), $own, true)) {
            $to[] = strtolower((string) $m['from_address']);
        }
        if ($mode === 'reply_all') {
            foreach (array_merge($m['to_addresses'], $m['cc_addresses']) as $a) {
                $a = strtolower((string) $a);
                if ($a !== '' && !in_array($a, $own, true) && !in_array($a, $to, true) && !in_array($a, $cc, true)) {
                    $cc[] = $a;
                }
            }
        }
        if ($to === [] && $m['to_addresses'] !== []) {       // answering one's own sent message: back to its recipients
            $to = array_values(array_map('strtolower', $m['to_addresses']));
        }
    }
    $fetch = ml_message_fetch($mailbox, (int) $m['id'], 'html', false, ['profile' => 'compose']);
    $quoteHtml = null;
    $quoteText = null;
    $unavailable = false;
    $parts = [];
    if (is_array($fetch) && empty($fetch['moved']) && empty($fetch['gone'])) {
        $parts = $fetch['parts'] ?? [];
        if (($fetch['html'] ?? null) !== null) {
            $quoteHtml = (string) $fetch['html'];
            $quoteText = null;
        } elseif (($fetch['text'] ?? null) !== null) {
            $quoteText = (string) $fetch['text'];
        }
    } else {
        $unavailable = true;
        $quoteText = (string) ($m['snippet'] ?? '');
    }
    if ($quoteText !== null && $quoteHtml === null) {
        $quoteHtml = '<div style="white-space:pre-wrap">' . e($quoteText) . '</div>';
    }
    $attribution = compose_attribution($m, $tz);
    if ($mode === 'forward') {
        $head = '<div><b>From:</b> ' . e((($m['from_name'] ?? '') !== '' ? $m['from_name'] . ' ' : '') . '<' . $m['from_address'] . '>') . '<br><b>Date:</b> ' . e(format_ts($m['sent_at'] ?? $m['received_at'], $tz, 'D, j M Y H:i'))
              . '<br><b>Subject:</b> ' . e((string) $m['subject']) . '<br><b>To:</b> ' . e(implode(', ', $m['to_addresses'])) . ($m['cc_addresses'] !== [] ? '<br><b>Cc:</b> ' . e(implode(', ', $m['cc_addresses'])) : '') . '</div>';
        $quoteBlockHtml = '<br><div class="mail-forwarded">---------- Forwarded message ----------<br>' . $head . '<br>' . $quoteHtml . '</div>';
        $quoteBlockText = "\n\n---------- Forwarded message ----------\nFrom: " . (($m['from_name'] ?? '') !== '' ? $m['from_name'] . ' ' : '') . '<' . $m['from_address'] . ">\nDate: " . format_ts($m['sent_at'] ?? $m['received_at'], $tz, 'D, j M Y H:i')
                        . "\nSubject: " . (string) $m['subject'] . "\nTo: " . implode(', ', $m['to_addresses']) . "\n\n" . ($quoteText ?? mime_text_of_html($quoteHtml));
    } else {
        $quoteBlockHtml = '<br><div class="mail-quote-attr">' . e($attribution) . '</div><blockquote type="cite" style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">' . $quoteHtml . '</blockquote>';
        $quoteBlockText = "\n\n" . $attribution . "\n" . implode("\n", array_map(static fn (string $l): string => '> ' . $l, explode("\n", $quoteText ?? mime_text_of_html($quoteHtml))));
    }
    $attachments = [];
    foreach ($parts as $p) {
        if (($p['disposition'] ?? '') === 'attachment' || (!str_starts_with((string) $p['type'], 'text/') && ($p['name'] ?? '') !== '' && empty($p['content_id']))) {
            $attachments[] = ['part' => $p['part'], 'name' => $p['name'], 'type' => $p['type'], 'size' => (int) ($p['size'] ?? 0)];
        }
    }
    return ['original' => $m, 'to' => $to, 'cc' => $cc, 'subject' => compose_subject($pdo, $mode, $m['subject']), 'quote_html' => $quoteBlockHtml, 'quote_text' => $quoteBlockText,
            'quote_unavailable' => $unavailable, 'attachments' => $attachments, 'reply_to_key_id' => $mode === 'forward' ? null : (int) $m['message_key_id'],
            'in_reply_to' => $mode === 'forward' ? null : $m['message_id_header'],
            'references_ids' => $mode === 'forward' ? [] : array_values(array_filter(array_merge($m['references_ids'], [$m['message_id_header']]))), 'message_id' => (int) $m['id']];
}

/** HTML → text for a quote's plain-text twin, when the service gave HTML alone (block elements to lines, links as text <url>). */
function mime_text_of_html(string $html): string
{
    $t = preg_replace('#<\s*(br|/p|/div|/li|/tr|/h[1-6])\s*/?>#i', "\n", $html) ?? $html;
    $t = preg_replace('#<li[^>]*>#i', '- ', $t) ?? $t;
    $t = preg_replace_callback('#<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', static fn (array $m): string => trim(strip_tags($m[2])) !== '' && trim(strip_tags($m[2])) !== $m[1] ? trim(strip_tags($m[2])) . ' <' . $m[1] . '>' : $m[1], $t) ?? $t;
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = array_map(static fn (string $l): string => trim(preg_replace('/[ \t]+/', ' ', $l) ?? $l), explode("\n", str_replace("\r\n", "\n", $t)));
    return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');
}

/** The signature block a person's composer inserts (Gmail's way): `-- ` first; the identity's html, else its text. */
function compose_signature_html(array $identity): string
{
    if (($identity['signature_html'] ?? '') !== '') {
        return '<div class="mail-signature">-- <br>' . $identity['signature_html'] . '</div>';
    }
    if (($identity['signature_text'] ?? '') !== '') {
        return '<div class="mail-signature">-- <br>' . nl2br(e((string) $identity['signature_text'])) . '</div>';
    }
    return '';
}

function compose_signature_text(array $identity): string
{
    $t = ($identity['signature_text'] ?? '') !== '' ? (string) $identity['signature_text'] : (($identity['signature_html'] ?? '') !== '' ? mime_text_of_html((string) $identity['signature_html']) : '');
    return $t === '' ? '' : "\n\n-- \n" . $t;
}

/** The schedule panel's choices, in the person's zone (writing.md "Schedule…"). */
function compose_schedule_choices(string $tz): array
{
    $zone = new DateTimeZone($tz !== '' ? $tz : 'UTC');
    $now = new DateTimeImmutable('now', $zone);
    $tomorrow = $now->modify('tomorrow');
    $monday = $now->modify('next monday');
    return [
        ['label' => 'Tomorrow morning', 'at' => $tomorrow->setTime(9, 0)], ['label' => 'Tomorrow afternoon', 'at' => $tomorrow->setTime(14, 0)],
        ['label' => 'Monday morning', 'at' => $monday->setTime(9, 0)],
    ];
}

/** A `send_at` the person chose (ISO, their zone or with an offset) → a UTC timestamp string; null when none; a sentence when it is unusable. */
function compose_send_at(?string $raw, string $tz, array &$errors): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    try {
        $at = new DateTimeImmutable($raw, new DateTimeZone($tz !== '' ? $tz : 'UTC'));
    } catch (Exception) {
        $errors['send_at'] = 'The time to send is not one that can be read.';
        return null;
    }
    $now = new DateTimeImmutable('now');
    if ($at->getTimestamp() < $now->getTimestamp() + 60) {
        $errors['send_at'] = 'A scheduled send is at least a minute ahead.';
        return null;
    }
    if ($at->getTimestamp() > $now->getTimestamp() + 366 * 86400) {
        $errors['send_at'] = 'A scheduled send is at most a year ahead.';
        return null;
    }
    return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
}
