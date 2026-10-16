<?php
declare(strict_types=1);

/**
 * The words of oversight (oversight.md; design §5): each hold reason as a sentence about THIS message, why mail waits in Unscreened,
 * what each policy setting means — and the JSON presenters (whitelists: never a raw row, never a credential).
 */

const OV_REASON_LABELS = [
    'mode' => 'policy', 'first_contact' => 'first contact', 'attachment' => 'attachment', 'over_hour' => 'over the hour\'s cap',
    'over_day' => 'over the day\'s cap', 'over_thread' => 'over the conversation\'s cap', 'over_business_budget' => 'over the business budget',
    'paused' => 'paused', 'hold_all_agents' => 'all agents held', 'hold_all_external' => 'outside mail held', 'on_behalf' => 'on someone\'s behalf',
];

const OV_SEND_MODE_WORDS = [
    'hold_all' => 'holds every message for a person',
    'internal_auto' => 'sends inside the business on its own and holds mail leaving it',
    'allowlist_auto' => 'sends on its own to the domains and addresses on its list and holds the rest',
    'auto' => 'sends on its own',
];

const OV_WAKE_WORDS = [
    'none' => 'never: arriving mail wakes no one',
    'internal' => 'mail from the business\'s own people (proven)',
    'internal_and_known' => 'the business\'s own people and outside senders this mailbox knows',
    'all_authenticated' => 'anyone whose mail proves its sender (DMARC)',
];

const OV_UNSCREENED_WORDS = [
    'unknown_sender' => 'An outside sender this mailbox does not know',
    'internal_unproven' => 'Looks internal, but this application did not send it',
    'unauthenticated' => 'No DMARC pass',
    'no_overseer' => 'No primary overseer to act',
];

/** What each policy setting means (the agent-policy page, the policy tool). */
const OV_POLICY_MEANINGS = [
    'send_mode' => 'What the agent may send without a person: hold everything, send inside the business, send to an allow-list, or send on its own.',
    'allow_domains' => 'The outside domains an allow-list agent writes to without review.',
    'allow_addresses' => 'The outside addresses an allow-list agent writes to without review.',
    'first_contact_review' => 'Hold a message to anyone this mailbox has never written to (a person sent it, or approved it, or marked them known).',
    'attachments_review' => 'Hold every message that carries a file.',
    'max_external_per_day' => 'At most this many messages leaving the business a day; more wait for a person.',
    'max_per_hour' => 'At most this many messages an hour; more wait for a person.',
    'max_per_thread_per_day' => 'At most this many messages a day in one conversation; more wait for a person (stops a loop).',
    'wake_on_mail' => 'Arriving mail wakes the agent for one turn (it reads ids, never the mail as an instruction).',
    'wake_from' => 'Whose mail wakes it; the rest waits in Unscreened for a person.',
    'disclosure' => 'Its mail carries a line saying an AI agent wrote it.',
    'disclosure_text' => 'The line, with {agent} for its name (the business\'s default when empty).',
];

/** One hold reason as a sentence about this message ($q: review_get()'s row). */
function ov_reason_sentence(string $reason, array $q): string
{
    $p = $q['policy'] ?? null;
    $s = $q['settings'] ?? [];
    $box = (string) ($q['mailbox_address'] ?? 'This mailbox');
    $list = static fn (array $a): string => implode(', ', array_slice($a, 0, 6)) . (count($a) > 6 ? ' and ' . (count($a) - 6) . ' more' : '');
    return match ($reason) {
        'mode' => ($q['mailbox_kind'] ?? '') === 'shared'
            ? 'An agent wrote from a shared mailbox: its people with full access decide every such message.'
            : 'This mailbox\'s policy ' . (OV_SEND_MODE_WORDS[(string) ($p['send_mode'] ?? 'hold_all')] ?? 'holds this message') . '.',
        'first_contact' => 'This mailbox has never written to ' . ($q['new_recipients'] !== [] ? $list($q['new_recipients']) : 'one of its recipients') . '.',
        'attachment' => 'It carries ' . (count($q['attachments'] ?? []) === 1 ? 'a file' : 'files') . ', and this mailbox\'s policy reviews attachments.',
        'over_hour' => 'The agent has reached its cap of ' . (int) ($p['max_per_hour'] ?? 0) . ' messages an hour.',
        'over_day' => 'The agent has reached its cap of ' . (int) ($p['max_external_per_day'] ?? 0) . ' messages leaving the business a day.',
        'over_thread' => 'The agent has reached its cap of ' . (int) ($p['max_per_thread_per_day'] ?? 0) . ' messages a day in this conversation.',
        'over_business_budget' => 'The business\'s agents together have reached today\'s budget of ' . (int) ($s['agent_external_budget_today'] ?? 0) . ' messages leaving the business.',
        'paused' => 'The mailbox is paused' . (($p['paused_by_name'] ?? null) !== null ? ' by ' . $p['paused_by_name'] : '') . (($p['pause_reason'] ?? null) !== null ? ': «' . $p['pause_reason'] . '»' : '') . '.',
        'hold_all_agents' => 'A Mail admin holds every agent\'s mail for review.',
        'hold_all_external' => 'A Mail admin holds every agent\'s mail leaving the business.',
        'on_behalf' => 'An agent wrote it from ' . (($q['mailbox_owner_name'] ?? null) !== null ? $q['mailbox_owner_name'] . '\'s' : 'a person\'s') . ' own mailbox (' . $box . '): its owner decides.',
        default => 'Held: ' . $reason . '.',
    };
}

/** Hours (or minutes) until a held message expires, in words. */
function ov_expires_in(?string $expiresAt): string
{
    if ($expiresAt === null) {
        return '';
    }
    $secs = (new DateTimeImmutable($expiresAt))->getTimestamp() - time();
    if ($secs <= 0) {
        return 'expires now';
    }
    return $secs >= 3600 ? 'expires in ' . intdiv($secs, 3600) . ' h' : 'expires in ' . max(1, intdiv($secs, 60)) . ' min';
}

/** A line diff of the text before and after an edit: [[' '|'-'|'+', line]] (an LCS over lines, capped). */
function ov_line_diff(string $before, string $after): array
{
    $a = preg_split('/\R/u', $before) ?: [];
    $b = preg_split('/\R/u', $after) ?: [];
    $a = array_slice($a, 0, 400);
    $b = array_slice($b, 0, 400);
    $n = count($a);
    $m = count($b);
    $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $l[$i][$j] = $a[$i] === $b[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
        }
    }
    $out = [];
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) { $out[] = [' ', $a[$i]]; $i++; $j++; }
        elseif ($l[$i + 1][$j] >= $l[$i][$j + 1]) { $out[] = ['-', $a[$i]]; $i++; }
        else { $out[] = ['+', $b[$j]]; $j++; }
    }
    for (; $i < $n; $i++) { $out[] = ['-', $a[$i]]; }
    for (; $j < $m; $j++) { $out[] = ['+', $b[$j]]; }
    return $out;
}

// ---- JSON presenters -------------------------------------------------------------------------------------------------------------------

function present_oversight_card(array $c): array
{
    $s = $c['summary'];
    return ['mailbox' => (int) $c['id'], 'address' => (string) $c['address'], 'name' => (string) $c['display_name'], 'agent_member_id' => $c['owner_member_id'] !== null ? (int) $c['owner_member_id'] : null,
            'primary_overseer' => $c['primary_overseer_name'], 'received' => (int) ($s['received'] ?? 0), 'woken' => (int) ($s['woken'] ?? 0),
            'unscreened' => (int) ($s['unscreened'] ?? 0), 'sent' => (int) ($s['sent'] ?? 0), 'held_now' => (int) ($s['held_now'] ?? 0),
            'rejected' => (int) ($s['rejected'] ?? 0), 'expired' => (int) ($s['expired'] ?? 0), 'approved_unedited' => (int) ($s['approved_unedited'] ?? 0),
            'edited' => (int) ($s['edited'] ?? 0), 'oldest_held_at' => json_ts($s['oldest_held_at'] ?? null), 'paused' => (bool) ($s['paused'] ?? false),
            'paused_by' => $c['paused_by_name'], 'pause_reason' => $s['pause_reason'] ?? null, 'days' => (int) ($s['days'] ?? 7)];
}

function present_review_row(array $r): array
{
    return ['outbound' => (int) $r['id'], 'mailbox' => (int) $r['mailbox_id'], 'mailbox_address' => (string) $r['mailbox_address'], 'sender' => $r['sender_name'],
            'to' => $r['to_addresses'], 'cc' => $r['cc_addresses'], 'subject' => (string) $r['subject'], 'hold_reasons' => $r['hold_reasons'],
            'new_recipients' => $r['new_recipients'], 'outside_recipients' => $r['outside_recipients'], 'held_at' => json_ts($r['held_at']),
            'expires_at' => json_ts($r['expires_at']), 'can_decide' => $r['can_decide']];
}

function present_review(array $q, array $links): array
{
    return present_review_row($q) + [
        'from' => (string) $q['from_address'], 'sender_kind' => $q['sender_kind'] ?? null, 'bcc' => $q['bcc_addresses'], 'purpose' => $q['purpose'],
        'run_id' => $q['agent_run_id'] !== null ? (int) $q['agent_run_id'] : null, 'body_text' => (string) $q['body_text'], 'has_html' => ($q['body_html'] ?? null) !== null,
        'disclosure' => $q['disclosure'], 'links' => array_map(static fn (array $l): array => ['text' => $l[0], 'href' => $l[1], 'host' => $l[2]], $links),
        'attachments' => array_map(static fn (array $a): array => ['name' => (string) $a['filename'], 'type' => (string) $a['mime_type'], 'size' => (int) $a['byte_size'], 'sha256' => (string) $a['sha256']], $q['attachments']),
        'reasons' => array_map(static fn (string $r): array => ['reason' => $r, 'sentence' => ov_reason_sentence($r, $q)], $q['hold_reasons']),
        'answers' => $q['answered'] === null ? null : ['thread_id' => $q['answered']['thread_id'] !== null ? (int) $q['answered']['thread_id'] : null,
                                                       'dmarc_pass' => $q['answered']['dmarc_pass'] === null ? null : ($q['answered']['dmarc_pass'] === true || $q['answered']['dmarc_pass'] === 't'),
                                                       'spam_score' => $q['answered']['spam_score'] !== null ? (float) $q['answered']['spam_score'] : null],
        'reviews' => array_map('present_review_decision', $q['reviews']),
    ];
}

function present_review_decision(array $r): array
{
    return ['decision' => (string) $r['decision'], 'by' => $r['reviewer_name'], 'note' => $r['note'], 'at' => json_ts($r['created_at'])];
}

function present_unscreened(array $r): array
{
    return ['message' => (int) $r['message_key_id'], 'mailbox' => (int) $r['mailbox_id'], 'mailbox_address' => (string) $r['mailbox_address'],
            'from' => (string) $r['from_address'], 'dmarc_pass' => $r['dmarc_pass'], 'subject' => (string) ($r['subject'] ?? ''),
            'received_at' => json_ts($r['received_at']), 'reason' => (string) $r['decision'], 'why' => OV_UNSCREENED_WORDS[$r['decision']] ?? (string) $r['decision'],
            'thread' => $r['thread_id'] !== null ? (int) $r['thread_id'] : null, 'can_decide' => $r['can_decide']];
}

function present_policy(array $p): array
{
    $out = ['mailbox' => (int) $p['mailbox_id']];
    foreach (OV_POLICY_KEYS as $k) {
        $out[$k] = $p[$k];
    }
    return $out + ['paused' => $p['paused'], 'paused_at' => json_ts($p['paused_at']), 'paused_by' => $p['paused_by_name'], 'pause_reason' => $p['pause_reason'],
                   'updated_by' => $p['updated_by_name'], 'updated_at' => json_ts($p['updated_at']), 'meanings' => OV_POLICY_MEANINGS,
                   'business' => ['budget_today' => isset($p['business']['agent_external_budget_today']) ? (int) $p['business']['agent_external_budget_today'] : null,
                                  'hold_all_agents' => ($p['business']['hold_all_agents'] ?? false) === true || ($p['business']['hold_all_agents'] ?? '') === 't',
                                  'hold_all_external' => ($p['business']['hold_all_external'] ?? false) === true || ($p['business']['hold_all_external'] ?? '') === 't'],
                   'can_decide' => $p['can_decide']];
}
