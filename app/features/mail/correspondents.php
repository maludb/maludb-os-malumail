<?php
declare(strict_types=1);

/**
 * CORRESPONDENTS (docs/build-specs/correspondents.md — slice 8): the addresses a mailbox wrote to and heard from, what was exchanged with an
 * address or its company, and the autocomplete door's query. Every read goes through the `mcp_*` views (the wall inside them: the mailbox at
 * `view`, an agent never shown a sensitive conversation) or ml_complete() (the wall inside it: `send` on the mailbox).
 *
 *   ml_correspondents_page()       a mailbox's correspondents, filtered (q, known, outside), keyset-paged by last contact
 *   ml_correspondent()             one correspondent by address, or null (the page answers 404)
 *   ml_correspondence_threads()    the conversations with an address, or with every address of its domain, newest first
 *   ml_domain_addresses()          a domain's addresses in this mailbox, with their counts
 *   ml_public_provider()           is the domain a public mail provider (the company view offers the address only)
 *   ml_complete_rows()             the autocomplete door's rows
 */

const ML_PEOPLE_PAGE = 50;
/** The fixed list of public providers (decision 2): "what have we exchanged with this company" means nothing for these. Edited here, not a setting. */
const ML_PUBLIC_PROVIDERS = ['gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'ymail.com', 'icloud.com',
                             'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.de', 'web.de', 'mail.com', 'zoho.com',
                             'yandex.com', 'fastmail.com'];

function ml_public_provider(string $domain): bool
{
    return in_array(strtolower(trim($domain)), ML_PUBLIC_PROVIDERS, true);
}

/** A cursor `<unix µs>.<id>` → [µs, id], or null when it is not one. */
function ml_people_cursor(?string $cursor): ?array
{
    if ($cursor === null || !preg_match('/^([0-9]{1,17})\.([0-9]{1,18})$/', $cursor, $m)) {
        return null;
    }
    return [(int) $m[1], (int) $m[2]];
}

/** A single clean address from a URL segment or a field, or null. */
function ml_clean_address(string $raw): ?string
{
    $a = strtolower(trim($raw));
    return preg_match('/^[^@\s<>,;"]{1,200}@[^@\s<>,;"]{1,200}\.[^@\s<>,;"]{2,}$/', $a) ? $a : null;
}

/**
 * One page of a mailbox's correspondents (the screen and the `find_correspondents` tool read the same view). $filters: q (name or address),
 * known (yes|no|''), outside (yes|no|''). Newest contact first; $before the cursor the last page answered. Answers rows, the next cursor.
 */
function ml_correspondents_page(int $mailbox, array $filters, ?string $before): array
{
    $where = ['c.mailbox_id = :mb'];
    $args = ['mb' => $mailbox];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(c.address ILIKE ml_like(:q1) OR c.name ILIKE ml_like(:q2))';
        $args['q1'] = $args['q2'] = $q;
    }
    $known = (string) ($filters['known'] ?? '');
    if ($known === 'yes' || $known === 'no') {
        $where[] = $known === 'yes' ? 'c.known_at IS NOT NULL' : 'c.known_at IS NULL';
    }
    $outside = (string) ($filters['outside'] ?? '');
    if ($outside === 'yes' || $outside === 'no') {
        $where[] = $outside === 'yes' ? 'NOT c.is_internal' : 'c.is_internal';
    }
    $cur = ml_people_cursor($before);
    if ($cur !== null) {
        $where[] = "(c.last_seen_at, c.id) < (timestamptz 'epoch' + :cus * interval '1 microsecond', :cid)";
        $args['cus'] = $cur[0];
        $args['cid'] = $cur[1];
    }
    $st = db()->prepare('SELECT c.id, c.address, c.name, c.first_seen_at, c.last_seen_at, c.sent_count, c.received_count, c.is_internal, c.known_at, c.known_how,
                                (floor(extract(epoch FROM c.last_seen_at) * 1000000))::bigint AS at_us
                           FROM mcp_correspondents c WHERE ' . implode(' AND ', $where) . '
                          ORDER BY c.last_seen_at DESC, c.id DESC LIMIT ' . (ML_PEOPLE_PAGE + 1));
    foreach ($args as $k => $v) {
        $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->execute();
    $rows = $st->fetchAll();
    $next = null;
    if (count($rows) > ML_PEOPLE_PAGE) {
        array_pop($rows);
        $last = $rows[ML_PEOPLE_PAGE - 1];
        $next = (string) $last['at_us'] . '.' . (int) $last['id'];
    }
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['sent_count'] = (int) $r['sent_count'];
        $r['received_count'] = (int) $r['received_count'];
        $r['is_internal'] = $r['is_internal'] === true || $r['is_internal'] === 't' || $r['is_internal'] === 1;
    }
    unset($r);
    return ['rows' => $rows, 'next' => $next];
}

/** One correspondent of a mailbox by address (the wall in the view), or null. */
function ml_correspondent(int $mailbox, string $address): ?array
{
    $a = ml_clean_address($address);
    if ($a === null) {
        return null;
    }
    $st = db()->prepare('SELECT id, address, name, first_seen_at, last_seen_at, sent_count, received_count, is_internal, known_at, known_how
                           FROM mcp_correspondents WHERE mailbox_id = :mb AND address = :a');
    $st->execute(['mb' => $mailbox, 'a' => $a]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['id'] = (int) $r['id'];
    $r['sent_count'] = (int) $r['sent_count'];
    $r['received_count'] = (int) $r['received_count'];
    $r['is_internal'] = $r['is_internal'] === true || $r['is_internal'] === 't' || $r['is_internal'] === 1;
    return $r;
}

/**
 * The conversations a mailbox has with an address ($domain false) or with any address of a domain ($domain true, $key the domain): slice 2's
 * keyset order (last message, then id), read from `mcp_threads` so the wall and the sensitive rule decide. At most ML_PEOPLE_PAGE.
 */
function ml_correspondence_threads(int $mailbox, string $key, bool $domain, ?string $before): array
{
    $key = strtolower(trim($key));
    $where = ['t.mailbox_id = :mb'];
    $args = ['mb' => $mailbox];
    if ($domain) {
        $where[] = 'EXISTS (SELECT 1 FROM unnest(t.participants) x WHERE split_part(x, \'@\', 2) = :d)';
        $args['d'] = $key;
    } else {
        $where[] = ':a = ANY (t.participants)';
        $args['a'] = $key;
    }
    $cur = ml_people_cursor($before);
    if ($cur !== null) {
        $where[] = "(t.last_message_at, t.id) < (timestamptz 'epoch' + :cus * interval '1 microsecond', :cid)";
        $args['cus'] = $cur[0];
        $args['cid'] = $cur[1];
    }
    $st = db()->prepare('SELECT t.id AS thread_id, t.subject, t.participants, t.last_message_at, t.message_count, t.unread_count, t.has_attachments, t.snippet, t.sensitive,
                                (floor(extract(epoch FROM t.last_message_at) * 1000000))::bigint AS at_us
                           FROM mcp_threads t WHERE ' . implode(' AND ', $where) . '
                          ORDER BY t.last_message_at DESC, t.id DESC LIMIT ' . (ML_PEOPLE_PAGE + 1));
    foreach ($args as $k => $v) {
        $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->execute();
    $rows = $st->fetchAll();
    $next = null;
    if (count($rows) > ML_PEOPLE_PAGE) {
        array_pop($rows);
        $last = $rows[ML_PEOPLE_PAGE - 1];
        $next = (string) $last['at_us'] . '.' . (int) $last['thread_id'];
    }
    foreach ($rows as &$r) {
        $r['thread_id'] = (int) $r['thread_id'];
        $r['message_count'] = (int) $r['message_count'];
        $r['unread_count'] = (int) $r['unread_count'];
        $r['has_attachments'] = $r['has_attachments'] === true || $r['has_attachments'] === 't';
        $r['sensitive'] = $r['sensitive'] === true || $r['sensitive'] === 't';
        $r['participants'] = ml_pg_array_or_json($r['participants']);
    }
    unset($r);
    return ['rows' => $rows, 'next' => $next];
}

/** A text[] as PDO gives it ('{a,b}') → a PHP list. */
function ml_pg_array_or_json(mixed $v): array
{
    if (is_array($v)) {
        return $v;
    }
    $s = trim((string) $v, '{}');
    if ($s === '') {
        return [];
    }
    return array_map(static fn (string $x): string => trim($x, '"'), str_getcsv($s));
}

/** A domain's addresses in this mailbox with their counts, most exchanged first. */
function ml_domain_addresses(int $mailbox, string $domain): array
{
    $st = db()->prepare('SELECT address, name, sent_count, received_count FROM mcp_correspondents
                          WHERE mailbox_id = :mb AND split_part(address, \'@\', 2) = :d
                          ORDER BY sent_count + received_count DESC, address LIMIT 50');
    $st->execute(['mb' => $mailbox, 'd' => strtolower($domain)]);
    return array_map(static fn (array $r): array => ['address' => $r['address'], 'name' => $r['name'], 'sent' => (int) $r['sent_count'], 'received' => (int) $r['received_count']], $st->fetchAll());
}

/** The autocomplete door's rows: ml_complete() under the caller's wall (send on the mailbox, else nothing). */
function ml_complete_rows(int $mailbox, string $q): array
{
    $st = db()->prepare('SELECT address, name, source, outside FROM ml_complete(:mb, :q, 8)');
    $st->execute(['mb' => $mailbox, 'q' => $q]);
    return array_map(static fn (array $r): array => ['address' => $r['address'], 'name' => $r['name'], 'source' => $r['source'],
                                                     'outside' => $r['outside'] === true || $r['outside'] === 't'], $st->fetchAll());
}

/** A correspondent as the JSON read and the tool give it (a whitelist, never the row). */
function ml_correspondent_json(array $r): array
{
    return ['address' => $r['address'], 'name' => $r['name'], 'first_contact' => $r['first_seen_at'], 'last_contact' => $r['last_seen_at'],
            'sent' => (int) $r['sent_count'], 'received' => (int) $r['received_count'], 'outside' => !$r['is_internal'],
            'known' => $r['known_at'] !== null, 'known_how' => $r['known_how']];
}
