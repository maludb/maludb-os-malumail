<?php
declare(strict_types=1);
/**
 * Action `mail_policy_set` (log `policy.set` — the keys changed with their old and new values; an outside address is logged by its domain):
 * an agent mailbox's sending policy, any subset of the settings (a field left out stays as it was), changed by a person who decides the
 * mailbox (ml_policy_set). The page asks first (hx-confirm) when the change widens what leaves unseen.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/oversight/queries.php';
$pdo = ov_handler_begin();
$box = ov_require_agent_mailbox(req_val('mailbox') ?? request_integer('mailbox'));
$mb = (int) $box['id'];
$cur = policy_get($pdo, $mb, (int) current_member_id());
$errors = [];
/** A list of domains or addresses: `name[]` from a form, or one string split on commas, spaces and lines. */
$list = static function (array $names) {
    foreach ($names as $n) {
        if (array_key_exists($n, $_POST)) {
            $v = $_POST[$n];
            $items = is_array($v) ? $v : preg_split('/[\s,;]+/', (string) $v);
            return array_values(array_unique(array_filter(array_map(static fn ($x): string => strtolower(trim((string) $x)), $items), static fn (string $x): bool => $x !== '')));
        }
    }
    return null;
};
$new = [];
$v = ml_choice('send_mode', null, OV_SEND_MODES, 'The send mode', $errors, false);
if ($v !== null) { $new['send_mode'] = $v; }
$domains = $list(['allow_domain', 'allow_domains']);
if ($domains !== null) {
    foreach ($domains as $d) {
        if (!preg_match('/^(?=.{3,253}$)([a-z0-9-]+\.)+[a-z0-9-]{2,}$/', $d)) { $errors['allow_domain'] = '"' . $d . '" is not a domain (write example.com).'; }
    }
    $new['allow_domains'] = $domains;
}
$addresses = $list(['allow_address', 'allow_addresses']);
if ($addresses !== null) {
    foreach ($addresses as $a) {
        if (!preg_match('/^[^@\s<>]+@[^@\s<>]+\.[^@\s<>]+$/', $a)) { $errors['allow_address'] = '"' . $a . '" is not an address.'; }
    }
    $new['allow_addresses'] = $addresses;
}
foreach (['first_contact_review', 'attachments_review', 'wake_on_mail', 'disclosure'] as $k) {
    if (req_has($k)) { $new[$k] = ml_yes($k); }
}
foreach (['max_external_per_day' => [0, 10000, 'The cap on outside mail a day'], 'max_per_hour' => [0, 10000, 'The cap an hour'], 'max_per_thread_per_day' => [1, 1000, 'The cap a day in one conversation']] as $k => [$lo, $hi, $label]) {
    $n = ml_int($k, null, $lo, $hi, $label, $errors);
    if ($n !== null) { $new[$k] = $n; }
}
$v = ml_choice('wake_from', null, OV_WAKE_FROM, 'Whose mail wakes it', $errors, false);
if ($v !== null) { $new['wake_from'] = $v; }
if (req_has('disclosure_text')) {
    $t = trim((string) req_val('disclosure_text'));
    if ($t !== '' && (mb_strlen($t) < 10 || mb_strlen($t) > 500 || !str_contains($t, '{agent}'))) {
        $errors['disclosure_text'] = 'The disclosure line is 10 to 500 characters and names the agent with {agent}.';
    }
    $new['disclosure_text'] = $t;
}
$mode = $new['send_mode'] ?? $cur['send_mode'];
if ($mode === 'allowlist_auto' && count($new['allow_domains'] ?? $cur['allow_domains']) + count($new['allow_addresses'] ?? $cur['allow_addresses']) === 0) {
    $errors['send_mode'] = 'Sending to an allow-list needs at least one domain or address on the list.';
}
if ($errors !== []) {
    ml_refuse_fields($errors);
}
// only what changes: the log's old and new values, an outside address by its domain
$changes = [];
$before = [];
$after = [];
$shown = static function (string $k, mixed $v): mixed {
    if ($k === 'allow_addresses') { return array_values(array_unique(array_map(static fn (string $a): string => substr($a, strrpos($a, '@') + 1), $v))); }
    if ($k === 'disclosure_text') { return $v === null || $v === '' ? null : 'changed'; }
    return $v;
};
foreach ($new as $k => $v) {
    $old = $cur[$k];
    $same = is_array($v) ? (array_values($v) == array_values((array) $old)) : ($k === 'disclosure_text' ? (string) ($old ?? '') === (string) $v : $old === $v);
    if (!$same) {
        $changes[$k] = $v;
        $before[$k] = $shown($k, $old);
        $after[$k] = $shown($k, $v);
    }
}
if ($changes === []) {
    ml_done('Nothing changed', $mb, ml_land(return_path('/m/' . $mb . '/policy'), 'unchanged'), 'reviewChanged');
}
policy_set($pdo, $mb, (int) current_member_id(), $changes);
log_activity($pdo, 'policy.set', 'agent_mail_policy', $mb, ['before' => $before, 'after' => $after + ['keys' => array_keys($changes)], 'mailbox_id' => $mb]);
ml_done('Saved ' . $box['address'] . '\'s policy (' . implode(', ', array_map(static fn (string $k): string => str_replace('_', ' ', $k), array_keys($changes))) . ')', $mb,
        ml_land(return_path('/m/' . $mb . '/policy'), 'saved'), 'reviewChanged', ['changed' => array_keys($changes)]);
