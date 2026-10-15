<?php
/**
 * What a person sees around the frame (reading-and-rendering.md §5), from the index and the fetch: outside sender, sender mismatch,
 * authentication, spam score, Reply-To elsewhere, deceptive links, hidden content removed, automated. Data: m, f (the fetch or null), summary
 */
$banners = [];
if ($m['from_outside']) {
    $banners[] = ['secondary', 'feather-globe', 'From outside the business', 'outside'];
}
if ($m['name_mismatch']) {
    $banners[] = ['danger', 'feather-alert-triangle', 'This says it is from ' . $m['from_name'] . ', but the address ' . $m['from_address'] . ' is not theirs.', 'mismatch'];
}
if ($m['dmarc_pass'] === false) {
    $banners[] = ['danger', 'feather-shield-off', 'Authentication failed (DMARC): the sender could not be verified.', 'dmarc-fail'];
} elseif ($m['dmarc_pass'] === true) {
    $banners[] = ['light', 'feather-shield', 'Authentication passed (DMARC).', 'dmarc-pass'];
} elseif ($m['local_submission'] && !$m['from_outside']) {
    $banners[] = ['light', 'feather-home', 'Sent inside the business.', 'internal'];
} else {
    $banners[] = ['light', 'feather-help-circle', 'Authentication not stated.', 'dmarc-none'];
}
if ($m['spam_score'] !== null) {
    $score = (float) $m['spam_score'];
    $banners[] = [$score >= 6 || $m['special_use'] === 'junk' ? 'warning' : 'light', 'feather-activity', 'Spam score ' . rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.') . ($score >= 6 ? ' — this looks like spam' : ''), 'spam'];
}
$fromDomain = strtolower((string) substr((string) strrchr((string) $m['from_address'], '@'), 1));
foreach ($m['reply_to'] as $rt) {
    $d = strtolower((string) substr((string) strrchr((string) $rt, '@'), 1));
    if ($d !== '' && $d !== $fromDomain) {
        $banners[] = ['warning', 'feather-corner-down-right', 'Replies go to ' . $d . '.', 'reply-to'];
        break;
    }
}
if (is_array($f) && (int) ($f['deceptive_links'] ?? 0) > 0) {
    $n = (int) $f['deceptive_links'];
    $banners[] = ['danger', 'feather-link', $n . ($n === 1 ? ' link names' : ' links name') . ' another site than ' . ($n === 1 ? 'it opens' : 'they open') . '.', 'deceptive'];
}
if (is_array($f) && (int) ($f['hidden_removed'] ?? 0) > 0) {
    $banners[] = ['light', 'feather-eye-off', 'Hidden text was removed from this message.', 'hidden'];
}
if ($m['automated']) {
    $banners[] = ['light', 'feather-cpu', 'Automated mail' . ($m['is_list'] ? ' (a mailing list)' : '') . '.', 'automated'];
}
?>
<div class="d-flex flex-wrap gap-1 mb-2 mail-banners" id="msg-<?= (int) $m['id'] ?>-banners">
    <?php foreach ($banners as [$kind, $icon, $text, $key]): ?>
        <span class="badge bg-soft-<?= e($kind) ?> text-<?= e($kind === 'light' ? 'muted' : $kind) ?> fw-normal mail-banner" data-banner="<?= e($key) ?>"><i class="<?= e($icon) ?> me-1"></i><?= e($text) ?></span>
    <?php endforeach; ?>
</div>
