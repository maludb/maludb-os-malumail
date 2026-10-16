<?php
declare(strict_types=1);

/**
 * Agents' mail and oversight (docs/build-specs/oversight.md — slice 5, THE FOURTH EXEMPLAR; sending-and-oversight.md §7–§9): what the six
 * screens read and what the twelve handlers change. Every rule is the schema's — ml_review_decide(), ml_thread_take_over(),
 * ml_thread_mute(), ml_unscreened_release()/_dismiss(), ml_sender_mark_known(), ml_policy_set(), ml_mailbox_pause() each refuse an
 * agent (ml_require_person) and a person who does not decide the mailbox (ml_require_decider); the views (mcp_review_queue,
 * mcp_dispatches, mcp_agent_mail_policies) are the wall for the reads. The cards and the two summary tools share ONE gatherer,
 * ml_agent_mail_summary() (decision 1). Required by the oversight screens and handlers.
 */

require_once dirname(__DIR__) . '/mail/reading.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/links.php';

const OV_POLICY_KEYS = ['send_mode', 'allow_domains', 'allow_addresses', 'first_contact_review', 'attachments_review', 'max_external_per_day',
                        'max_per_hour', 'max_per_thread_per_day', 'wake_on_mail', 'wake_from', 'disclosure', 'disclosure_text'];
const OV_SEND_MODES = ['hold_all', 'internal_auto', 'allowlist_auto', 'auto'];
const OV_WAKE_FROM = ['none', 'internal', 'internal_and_known', 'all_authenticated'];
const OV_REASON_KEYS = ['mode', 'first_contact', 'attachment', 'over_hour', 'over_day', 'over_thread', 'over_business_budget', 'paused',
                        'hold_all_agents', 'hold_all_external', 'on_behalf'];

/** The agent mailboxes the member decides (a person with full — the Mail admins hold it on every agent mailbox), one summary each. */
function oversight_cards(PDO $pdo, int $member, int $days = 7): array
{
    $st = $pdo->prepare("SELECT b.id, b.address::text AS address, b.display_name, b.owner_member_id, a.display_name AS agent_name, b.primary_overseer_id,
                                po.display_name AS primary_overseer_name, p.paused_at, pb.display_name AS paused_by_name,
                                ml_agent_mail_summary(b.id, :days) AS summary
                           FROM mailboxes b
                           LEFT JOIN members a ON a.id = b.owner_member_id
                           LEFT JOIN members po ON po.id = b.primary_overseer_id
                           LEFT JOIN agent_mail_policies p ON p.mailbox_id = b.id
                           LEFT JOIN members pb ON pb.id = p.paused_by
                          WHERE b.kind = 'agent' AND ml_is_decider(b.id, :m)");
    $st->execute(['m' => $member, 'days' => $days]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['summary'] = json_decode((string) $r['summary'], true) ?: [];
        $rows[] = $r;
    }
    usort($rows, static fn (array $a, array $b): int => [(int) ($b['summary']['held_now'] ?? 0), mb_strtolower((string) $a['display_name'])]
                                                         <=> [(int) ($a['summary']['held_now'] ?? 0), mb_strtolower((string) $b['display_name'])]);
    return $rows;
}

/** The held mail the member sees (mcp_review_queue — a Mail admin never sees a person's mailbox's), oldest first: it expires. */
function review_queue(PDO $pdo, int $member, ?int $mailbox, ?string $reason): array
{
    $st = $pdo->prepare("SELECT q.id, q.mailbox_id, q.mailbox_address, q.mailbox_kind, q.sender_member_id, s.display_name AS sender_name, q.subject,
                                q.to_addresses, q.cc_addresses, q.bcc_addresses, q.hold_reasons, q.held_at, q.expires_at, q.can_decide, q.new_recipients,
                                q.outside_recipients, q.has_attachments, q.external, b.display_name AS mailbox_name, o.owner_member_id AS mailbox_owner_id
                           FROM mcp_review_queue q
                           JOIN mailboxes b ON b.id = q.mailbox_id
                           LEFT JOIN members s ON s.id = q.sender_member_id
                           LEFT JOIN mailboxes o ON o.id = q.mailbox_id
                          WHERE (CAST(:mb AS bigint) IS NULL OR q.mailbox_id = :mb2) AND (CAST(:reason AS text) IS NULL OR :reason2 = ANY (q.hold_reasons))
                          ORDER BY q.held_at, q.id");
    $st->execute(['mb' => $mailbox, 'mb2' => $mailbox, 'reason' => $reason, 'reason2' => $reason]);
    return array_map('ov_queue_row', $st->fetchAll());
}

function ov_queue_row(array $r): array
{
    foreach (['to_addresses', 'cc_addresses', 'bcc_addresses', 'hold_reasons', 'new_recipients', 'outside_recipients'] as $k) {
        $r[$k] = ml_pg_text_array((string) ($r[$k] ?? ''));
    }
    foreach (['can_decide', 'has_attachments', 'external'] as $k) {
        $r[$k] = $r[$k] === true || $r[$k] === 't';
    }
    return $r;
}

/** The mailboxes and reasons the queue can be filtered by (what the member's queue holds). */
function review_queue_filters(PDO $pdo): array
{
    $boxes = $pdo->query('SELECT DISTINCT q.mailbox_id AS id, q.mailbox_address AS address FROM mcp_review_queue q ORDER BY 2')->fetchAll();
    return $boxes;
}

/**
 * One held message for its review page: the queue's row (null when the member does not see it held), the mailbox, the agent's purpose
 * and run, attachments, the reviews so far (with an edit's before-text), the conversation it answers (the arriving message's DMARC
 * and spam score from the index), the policy the reasons are measured against. A message already decided answers ['decided' => …].
 */
function review_get(PDO $pdo, int $member, int $outbound): ?array
{
    $st = $pdo->prepare('SELECT q.*, b.display_name AS mailbox_name, b.owner_member_id AS mailbox_owner_id, ow.display_name AS mailbox_owner_name,
                                s.display_name AS sender_name, s.member_kind AS sender_kind, i.address::text AS from_address, i.display_name AS from_name
                           FROM mcp_review_queue q
                           JOIN mailboxes b ON b.id = q.mailbox_id
                           LEFT JOIN members ow ON ow.id = b.owner_member_id
                           LEFT JOIN members s ON s.id = q.sender_member_id
                           LEFT JOIN outbound_email e ON e.id = q.id
                           LEFT JOIN mail_identities i ON i.id = e.identity_id
                          WHERE q.id = :id');
    $st->execute(['id' => $outbound]);
    $q = $st->fetch();
    if ($q === false) {
        $st = $pdo->prepare('SELECT o.id, o.mailbox_id, o.status, o.subject, o.body_text FROM mcp_outbound o WHERE o.id = :id');
        $st->execute(['id' => $outbound]);
        $o = $st->fetch();
        if ($o === false) {
            return null;
        }
        return ['decided' => true, 'id' => (int) $o['id'], 'mailbox_id' => (int) $o['mailbox_id'], 'status' => (string) $o['status'],
                'subject' => (string) $o['subject'], 'body_text' => (string) $o['body_text'], 'reviews' => review_history($pdo, $outbound)];
    }
    $q = ov_queue_row($q);
    $q['decided'] = false;
    $q['from_address'] ??= $q['mailbox_address'];
    $q['reviews'] = review_history($pdo, $outbound);
    $at = $pdo->prepare("SELECT id, filename, mime_type, byte_size, sha256 FROM attachments WHERE record_type = 'outbound_email' AND record_id = :o ORDER BY id");
    $at->execute(['o' => $outbound]);
    $q['attachments'] = $at->fetchAll();
    $q['answered'] = null;
    if ($q['reply_to_key_id'] !== null) {
        $am = $pdo->prepare('SELECT m.id, m.thread_id, m.subject, m.from_address::text AS from_address, m.from_name, m.received_at, m.dmarc_pass, m.spam_score,
                                    m.auto_submitted, m.is_list
                               FROM mcp_messages m WHERE m.mailbox_id = :mb AND m.message_key_id = :k ORDER BY m.id LIMIT 1');
        $am->execute(['mb' => (int) $q['mailbox_id'], 'k' => (int) $q['reply_to_key_id']]);
        $a = $am->fetch();
        $q['answered'] = $a === false ? null : $a;
    }
    $q['policy'] = null;
    if ($q['mailbox_kind'] === 'agent') {
        $pp = $pdo->prepare('SELECT p.*, pb.display_name AS paused_by_name FROM mcp_agent_mail_policies p LEFT JOIN members pb ON pb.id = p.paused_by WHERE p.mailbox_id = :mb');
        $pp->execute(['mb' => (int) $q['mailbox_id']]);
        $q['policy'] = $pp->fetch() ?: null;
    }
    $q['settings'] = $pdo->query('SELECT held_expiry_hours, hold_all_agents, hold_all_external, agent_external_budget_today FROM mcp_settings')->fetch() ?: [];
    return $q;
}

/** The decisions on a held message, oldest first, with each reviewer's name; an edit carries the text before (db/015). */
function review_history(PDO $pdo, int $outbound): array
{
    $st = $pdo->prepare('SELECT r.id, r.decision, r.reviewer_member_id, m.display_name AS reviewer_name, r.note, r.created_at, r.before_subject, r.before_body_text
                           FROM mail_reviews r LEFT JOIN members m ON m.id = r.reviewer_member_id
                          WHERE r.outbound_id = :o ORDER BY r.created_at, r.id');
    $st->execute(['o' => $outbound]);
    return $st->fetchAll();
}

/** The next held message the member decides, after this one (oldest first), or null. */
function review_next(PDO $pdo, int $after): ?int
{
    $v = one_value($pdo, 'SELECT q.id FROM mcp_review_queue q WHERE q.can_decide AND q.id <> :a ORDER BY q.held_at, q.id LIMIT 1', ['a' => $after]);
    return $v === null || $v === false ? null : (int) $v;
}

/** A decision (ml_review_decide(): the person, the reviewer rule, the five decisions); answers the review's id. $edit: subject, body_text, body_html. */
function review_decide(PDO $pdo, int $outbound, int $member, string $decision, ?string $note, ?array $edit): int
{
    return (int) ml_guard($pdo, static function () use ($pdo, $outbound, $member, $decision, $note, $edit): int {
        $st = $pdo->prepare('SELECT ml_review_decide(:o, :m, :d, :note, :subject, :text, :html)');
        $st->execute(['o' => $outbound, 'm' => $member, 'd' => $decision, 'note' => $note, 'subject' => $edit['subject'] ?? null,
                      'text' => $edit['body_text'] ?? null, 'html' => $edit['body_html'] ?? null]);
        return (int) $st->fetchColumn();
    });
}

/** A conversation's anchor key: its lowest message key (ml_thread_anchor()'s rule), or null when the thread is not in this mailbox. */
function ov_thread_anchor(PDO $pdo, int $mailbox, int $thread): ?int
{
    $v = one_value($pdo, 'SELECT min(m.message_key_id) FROM mcp_messages m WHERE m.mailbox_id = :mb AND m.thread_id = :t', ['mb' => $mailbox, 't' => $thread]);
    return $v === null || $v === false ? null : (int) $v;
}

/** Take a conversation over (or give it back): the agent muted on it, its held replies there cancelled with a take_over review each. Answers how many were cancelled. */
function thread_take_over(PDO $pdo, int $mailbox, int $thread, int $member, bool $on): int
{
    $anchor = ov_thread_anchor($pdo, $mailbox, $thread);
    if ($anchor === null) {
        refuse(404, 'No such conversation.');
    }
    return (int) ml_guard($pdo, static function () use ($pdo, $mailbox, $anchor, $member, $on): int {
        $pdo->beginTransaction();
        $before = (int) one_value($pdo, "SELECT count(*) FROM mail_reviews r JOIN outbound_email o ON o.id = r.outbound_id WHERE o.mailbox_id = :mb AND r.decision = 'take_over'", ['mb' => $mailbox]);
        $pdo->prepare('SELECT ml_thread_take_over(:mb, :k, :m, :on)')->execute(['mb' => $mailbox, 'k' => $anchor, 'm' => $member, 'on' => $on ? 't' : 'f']);
        $after = (int) one_value($pdo, "SELECT count(*) FROM mail_reviews r JOIN outbound_email o ON o.id = r.outbound_id WHERE o.mailbox_id = :mb AND r.decision = 'take_over'", ['mb' => $mailbox]);
        $pdo->commit();
        return $after - $before;
    });
}

/** Mute (or unmute) conversations — triage; an agent may (design §5). Answers how many. */
function thread_mute(PDO $pdo, int $mailbox, array $threads, int $member, bool $on): int
{
    $n = 0;
    foreach ($threads as $t) {
        $anchor = ov_thread_anchor($pdo, $mailbox, (int) $t);
        if ($anchor === null) {
            refuse(404, 'No such conversation.');
        }
        ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_thread_mute(:mb, :k, :m, :on)')->execute(['mb' => $mailbox, 'k' => $anchor, 'm' => $member, 'on' => $on ? 't' : 'f']));
        $n++;
    }
    return $n;
}

/** The mail that did not wake its agent (mcp_dispatches, status unscreened), newest first, for the mailboxes the member sees there. */
function unscreened_list(PDO $pdo, int $member, ?int $mailbox): array
{
    $st = $pdo->prepare("SELECT d.id, d.mailbox_id, b.address::text AS mailbox_address, b.display_name AS mailbox_name, d.agent_member_id, d.decision,
                                d.message_key_id, d.message_id, d.from_address, d.from_name, d.subject, d.received_at, d.dmarc_pass, d.created_at,
                                ml_is_decider(d.mailbox_id, :m) AS can_decide, m.thread_id
                           FROM mcp_dispatches d
                           JOIN mailboxes b ON b.id = d.mailbox_id
                           LEFT JOIN mcp_messages m ON m.id = d.message_id
                          WHERE d.status = 'unscreened' AND d.kind = 'mail' AND (CAST(:mb AS bigint) IS NULL OR d.mailbox_id = :mb2)
                          ORDER BY COALESCE(d.received_at, d.created_at) DESC, d.id DESC");
    $st->execute(['m' => $member, 'mb' => $mailbox, 'mb2' => $mailbox]);
    return array_map(static function (array $r): array {
        $r['can_decide'] = $r['can_decide'] === true || $r['can_decide'] === 't';
        $r['dmarc_pass'] = $r['dmarc_pass'] === null ? null : ($r['dmarc_pass'] === true || $r['dmarc_pass'] === 't');
        return $r;
    }, $st->fetchAll());
}

/** The unscreened dispatch of a message key in a mailbox (the manifest names the message by its key), or a 404. */
function unscreened_dispatch(PDO $pdo, int $mailbox, int $messageKey): array
{
    $st = $pdo->prepare("SELECT d.id, d.mailbox_id, d.message_key_id, d.message_id, d.from_address, d.subject, d.decision
                           FROM mcp_dispatches d WHERE d.mailbox_id = :mb AND d.message_key_id = :k AND d.kind = 'mail' AND d.status = 'unscreened'
                          ORDER BY d.id DESC LIMIT 1");
    $st->execute(['mb' => $mailbox, 'k' => $messageKey]);
    $d = $st->fetch();
    if ($d === false) {
        refuse(404, 'That mail is not waiting for a person.');
    }
    return $d;
}

/** Answer Unscreened mail: release (one turn, the person acting), dismiss, or spam (the message moved to Spam by the mail service first). */
function unscreened_answer(PDO $pdo, int $mailbox, int $messageKey, int $member, string $how): void
{
    $d = unscreened_dispatch($pdo, $mailbox, $messageKey);
    if (!db_bool($pdo, 'SELECT ml_is_decider(:mb, :m)', ['mb' => $mailbox, 'm' => $member])) {
        refuse(403, 'You do not oversee this mailbox.');
    }
    if ($how === 'release') {
        ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_unscreened_release(:d, :m)')->execute(['d' => (int) $d['id'], 'm' => $member]));
        return;
    }
    if ($how === 'spam') {
        $st = $pdo->prepare('SELECT m.id, m.message_key_id, m.folder_id, m.uidvalidity, m.uid, f.special_use, m.thread_id, m.from_address
                               FROM mcp_messages m JOIN mcp_folders f ON f.id = m.folder_id
                              WHERE m.mailbox_id = :mb AND m.message_key_id = :k AND f.special_use IS DISTINCT FROM \'junk\'');
        $st->execute(['mb' => $mailbox, 'k' => $messageKey]);
        $rows = $st->fetchAll();
        if ($rows !== []) {
            $res = ml_mail_act($pdo, $mailbox, 'spam', $rows);
            if ($res['count'] === 0) {
                refuse(422, $res['refused'][0]['reason'] ?? 'The message could not be moved to Spam.');
            }
        }
    }
    ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_unscreened_dismiss(:d, :m, :s)')->execute(['d' => (int) $d['id'], 'm' => $member, 's' => $how === 'spam' ? 't' : 'f']));
}

function sender_mark_known(PDO $pdo, int $mailbox, string $address, int $member): void
{
    ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_sender_mark_known(:mb, :a, :m)')->execute(['mb' => $mailbox, 'a' => $address, 'm' => $member]));
}

/** An agent mailbox's policy (the wall's view), the paused-by name, and what the business also applies. */
function policy_get(PDO $pdo, int $mailbox, int $member): array
{
    $st = $pdo->prepare('SELECT p.*, u.display_name AS updated_by_name, pb.display_name AS paused_by_name
                           FROM mcp_agent_mail_policies p LEFT JOIN members u ON u.id = p.updated_by LEFT JOIN members pb ON pb.id = p.paused_by
                          WHERE p.mailbox_id = :mb');
    $st->execute(['mb' => $mailbox]);
    $p = $st->fetch();
    if ($p === false) {
        refuse(404, 'No such mailbox.');
    }
    foreach (['allow_domains', 'allow_addresses'] as $k) {
        $p[$k] = ml_pg_text_array((string) $p[$k]);
    }
    foreach (['first_contact_review', 'attachments_review', 'paused', 'wake_on_mail', 'disclosure'] as $k) {
        $p[$k] = $p[$k] === true || $p[$k] === 't';
    }
    foreach (['max_external_per_day', 'max_per_hour', 'max_per_thread_per_day'] as $k) {
        $p[$k] = (int) $p[$k];
    }
    $p['business'] = $pdo->query('SELECT agent_external_budget_today, agent_external_budget, hold_all_agents, hold_all_external, held_expiry_hours, disclosure_text AS default_disclosure_text FROM mcp_settings')->fetch() ?: [];
    $p['can_decide'] = db_bool($pdo, 'SELECT ml_is_decider(:mb, :m)', ['mb' => $mailbox, 'm' => $member]);
    return $p;
}

/** Change an agent's policy (ml_policy_set — any subset of the keys). */
function policy_set(PDO $pdo, int $mailbox, int $member, array $changes): void
{
    ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_policy_set(:mb, :m, CAST(:p AS jsonb))')
        ->execute(['mb' => $mailbox, 'm' => $member, 'p' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]));
}

/** THE KILL SWITCH and back (ml_mailbox_pause: queued and deferred agent mail held; on resume an approved one goes). */
function mailbox_pause(PDO $pdo, int $mailbox, int $member, bool $on, ?string $reason): void
{
    ml_guard($pdo, static fn () => $pdo->prepare('SELECT ml_mailbox_pause(:mb, :m, :on, :r)')->execute(['mb' => $mailbox, 'm' => $member, 'on' => $on ? 't' : 'f', 'r' => $reason]));
}

/** The policy's history: every policy.set, mailbox.pause, mailbox.resume on the mailbox, with who and when (the activity log). */
function policy_history(PDO $pdo, int $mailbox, int $limit = 50): array
{
    $st = $pdo->prepare("SELECT a.id, a.action, a.after, a.created_at, m.display_name AS actor_name
                           FROM activity_log a LEFT JOIN members m ON m.id = a.actor_member_id
                          WHERE a.mailbox_id = :mb AND a.action IN ('policy.set', 'mailbox.pause', 'mailbox.resume')
                          ORDER BY a.id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute(['mb' => $mailbox]);
    return array_map(static function (array $r): array {
        $r['after'] = json_decode((string) $r['after'], true) ?: [];
        return $r;
    }, $st->fetchAll());
}

/** An agent mailbox the caller may see at $level, else 404 — a person's or a shared mailbox has no policy page. */
function ov_require_agent_mailbox(int|string|null $id, string $level = 'view'): array
{
    $box = ml_require_mailbox($id, $level);
    if ($box['kind'] !== 'agent') {
        respond_not_found('No such mailbox.');
    }
    return $box;
}

/** The prelude of every oversight handler but Mute: POST, login, CSRF, a PERSON (an agent's token is refused here and again in SQL), the right. */
function ov_handler_begin(): PDO
{
    ml_handler_begin();
    require_human();
    if (!has_right('mail.oversee')) {
        refuse(403, right_refusal('mail.oversee'));
    }
    return db();
}

/** A held (or decided) message the caller sees, for a handler: its row (mcp_outbound), else 404. */
function ov_outbound(PDO $pdo, int $outbound): array
{
    $st = $pdo->prepare('SELECT o.id, o.mailbox_id, o.status, o.subject, o.sender_member_id, o.reply_to_key_id, b.kind AS mailbox_kind
                           FROM mcp_outbound o JOIN mailboxes b ON b.id = o.mailbox_id WHERE o.id = :id');
    $st->execute(['id' => $outbound]);
    $o = $st->fetch();
    if ($o === false) {
        refuse(404, 'No such message.');
    }
    return $o;
}

/** Where a decision lands: the next held message the person decides (decision 4), else the queue with the notice. */
function ov_after_decision(PDO $pdo, int $outbound, string $notice): string
{
    $next = review_next($pdo, $outbound);
    return $next !== null ? '/oversight/review/' . $next . '?notice=' . rawurlencode($notice) : '/oversight/review?notice=' . rawurlencode($notice);
}

/** A subject excerpt for the log — an agent's or a shared mailbox's only (D14), at most 200 characters. */
function ov_log_subject(array $o): array
{
    return ($o['mailbox_kind'] ?? '') !== 'person' && ($o['subject'] ?? '') !== '' ? ['subject' => mb_substr((string) $o['subject'], 0, 200)] : [];
}
