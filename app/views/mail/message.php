<?php
/**
 * One message of the conversation (reading.md "The messages"): collapsed to one line (from, date, snippet; opens with hx-get of its
 * partial) or open — from, to/cc, date, the banners, the body (the sandboxed frame, or the escaped text), the attachments, Reply /
 * Reply all / Forward (slice 4, when built), Show original. Data: box, m (ml_thread_page's row, with `fetch` when open), tz, index,
 * total, built, level, listUrl, view
 */
$mb = (int) $box['id'];
$id = (int) $m['id'];
$who = ($m['from_name'] ?? '') !== '' ? (string) $m['from_name'] : (string) ($m['from_address'] ?? '(unknown sender)');
$when = format_ts($m['sent_at'] ?? $m['received_at'], $tz, 'D, M j, Y g:i A');
$folderChip = match ($m['special_use']) { 'trash' => 'In Trash', 'junk' => 'In Spam', 'sent' => 'Sent', 'drafts' => 'Draft', 'archive' => 'Archived', default => null };
$ownAddress = strtolower((string) $box['address']);
if (empty($m['open'])): ?>
<div class="mail-msg mail-msg-collapsed p-2 px-3" id="msg-<?= $id ?>" role="listitem" tabindex="0" hx-get="/m/<?= $mb ?>/t/<?= (int) $m['thread_id'] ?>?message=<?= $id ?>&view=<?= e($view) ?>" hx-trigger="click, keyup[key=='Enter']" hx-target="this" hx-swap="outerHTML">
    <div class="d-flex align-items-baseline gap-2 mail-msg-line">
        <span class="fw-semibold text-truncate<?= $m['unread_for_me'] ? ' mail-unread' : '' ?>" style="max-width:14rem"><?= e($who) ?></span>
        <span class="text-muted text-truncate flex-grow-1 min-w-0"><?= e((string) ($m['snippet'] ?? '')) ?></span>
        <?php if ($m['has_attachments']): ?><i class="feather-paperclip text-muted"></i><?php endif; ?>
        <?php if ($folderChip !== null): ?><span class="badge bg-soft-secondary text-secondary fw-normal"><?= e($folderChip) ?></span><?php endif; ?>
        <span class="text-muted text-nowrap fs-12"><?= e(mail_list_date($m['received_at'], $tz)) ?></span>
    </div>
</div>
<?php return; endif;
$f = $m['fetch'] ?? null;
$summary = is_array($f) ? ($f['summary'] ?? []) : [];
$parts = is_array($f) ? ($f['parts'] ?? []) : [];
$attachments = array_values(array_filter($parts, static fn (array $p): bool => ($p['disposition'] ?? '') === 'attachment' || (!str_starts_with((string) $p['type'], 'text/') && ($p['name'] ?? '') !== '' && empty($p['content_id']))));
$hasHtml = is_array($f) && isset($f['html']) && $f['html'] !== null;
$plain = is_array($f) && !$hasHtml ? ($f['text'] ?? null) : null;
$imagesShown = $hasHtml && !empty($f['images_shown']);
$remoteImages = $hasHtml ? (int) ($f['remote_images'] ?? 0) : 0;
$ref = is_array($f) ? (string) ($f['ref'] ?? '') : '';
$canAllow = (ML_LEVELS[$level] ?? 0) >= 4 && (current_member()['member_kind'] ?? '') === 'human';
$proven = $m['dmarc_pass'] === true || ($m['local_submission'] === true && !$m['from_outside']);
?>
<div class="mail-msg p-3" id="msg-<?= $id ?>" role="listitem" data-message="<?= $id ?>" data-key="<?= (int) $m['message_key_id'] ?>">
    <div class="mail-msg-head d-flex flex-wrap align-items-start gap-2 mb-2">
        <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold"><?= e($who) ?> <span class="fw-normal text-muted mail-addr">&lt;<?= e((string) $m['from_address']) ?>&gt;</span>
                <?php if ($m['sender_address'] !== null && strtolower((string) $m['sender_address']) !== strtolower((string) $m['from_address'])): ?><span class="fw-normal text-muted fs-12"> via <?= e((string) $m['sender_address']) ?></span><?php endif; ?></div>
            <div class="fs-12 text-muted mail-addr">to <?= e(implode(', ', array_map(static fn (string $a): string => ($m['recipient_names'][$a] ?? '') !== '' ? $m['recipient_names'][$a] . ' <' . $a . '>' : ($a === $ownAddress ? 'me' : $a), $m['to_addresses']))) ?>
                <?php if ($m['cc_addresses'] !== []): ?> · cc <?= e(implode(', ', $m['cc_addresses'])) ?><?php endif; ?></div>
        </div>
        <div class="text-end fs-12 text-muted text-nowrap"><?= e($when) ?><?php if ($folderChip !== null): ?> <span class="badge bg-soft-secondary text-secondary fw-normal ms-1"><?= e($folderChip) ?></span><?php endif; ?>
            <?php if ($m['flagged']): ?><i class="feather-star text-warning ms-1" title="Starred"></i><?php endif; ?></div>
    </div>
    <?= view('mail/banners.php', ['m' => $m, 'f' => $f, 'summary' => $summary]) ?>
    <?php if ($f === null): ?>
        <div class="alert alert-warning mail-banner mb-2" id="msg-<?= $id ?>-unavailable">The message body is not available right now — the mail service is not answering. The headers above are from the index.</div>
    <?php elseif (!empty($f['moved']) || !empty($f['gone'])): ?>
        <div class="alert alert-warning mail-banner mb-2" id="msg-<?= $id ?>-moved">This message moved — <?= hx_link('/m/' . $mb . '/t/' . (int) $m['thread_id'], 'reload', 'alert-link') ?>.</div>
    <?php elseif ($hasHtml): ?>
        <?php if ($remoteImages > 0 && !$imagesShown): ?>
            <div class="alert alert-light mail-banner d-flex flex-wrap align-items-center gap-2 mb-2" id="msg-<?= $id ?>-images">
                <span>Images in this message are not shown.</span>
                <button type="button" class="btn btn-sm btn-light btn-touch mail-show-images" data-frame="frame-<?= $id ?>" data-src="/m/<?= $mb ?>/msg/<?= $id ?>/body?images=1" id="msg-<?= $id ?>-show-images">Show images</button>
                <?php if ($canAllow): ?>
                    <form method="post" action="/mail/images-allow" class="d-inline" hx-post="/mail/images-allow" hx-target="#page-content" id="msg-<?= $id ?>-always-form"><?= csrf_field() ?>
                        <input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="sender" value="<?= e((string) $m['from_address']) ?>"><input type="hidden" name="return_to" value="/m/<?= $mb ?>/t/<?= (int) $m['thread_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-link p-0">Always show from <?= e((string) $m['from_address']) ?></button>
                        <span class="text-muted fs-12 ms-1">(only for mail that proves its sender)</span></form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <iframe class="mail-body-frame" id="frame-<?= $id ?>" sandbox="allow-popups allow-popups-to-escape-sandbox allow-scripts"
                src="/m/<?= $mb ?>/msg/<?= $id ?>/body<?= $imagesShown ? '?images=1' : '' ?>" referrerpolicy="no-referrer" loading="lazy" title="Message"></iframe>
    <?php elseif (!empty($f['html_too_large'])): ?>
        <div class="alert alert-light mail-banner mb-2">This message's HTML is too large to show; its text:</div>
        <div class="mail-plain" id="msg-<?= $id ?>-plain"><?= ml_plain_html((string) ($f['text'] ?? '')) ?></div>
    <?php else: ?>
        <div class="mail-plain" id="msg-<?= $id ?>-plain"><?= $plain === null || $plain === '' ? '<span class="text-muted">(no text)</span>' : ml_plain_html((string) $plain) ?></div>
    <?php endif; ?>
    <?php if ($attachments !== [] || ($f === null && $m['attachment_names'] !== [])): ?>
        <?= view('mail/attachments.php', ['box' => $box, 'm' => $m, 'attachments' => $attachments, 'ref' => $ref, 'names' => $m['attachment_names']]) ?>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-1 mt-3 mail-msg-actions" id="msg-<?= $id ?>-actions">
        <?php foreach (['mail_reply' => ['act-reply', 'Reply', 'feather-corner-up-left', 'reply'], 'mail_reply_all' => ['act-reply-all', 'Reply all', 'feather-corner-up-left', 'reply_all'], 'mail_forward' => ['act-forward', 'Forward', 'feather-corner-up-right', 'forward']] as $a => [$aid, $label, $icon, $q]): ?>
            <?php if (!empty($built[$a]) && (ML_LEVELS[$level] ?? 0) >= 3): ?><a class="btn btn-sm btn-light btn-touch" id="<?= $aid ?>-<?= $id ?>" href="/m/<?= $mb ?>/compose?<?= $q ?>=<?= $id ?>"><i class="<?= $icon ?> me-1"></i><?= $label ?></a><?php endif; ?>
        <?php endforeach; ?>
        <?= hx_link('/m/' . $mb . '/msg/' . $id . '/original', '<i class="feather-file-text me-1"></i>Show original', 'btn btn-sm btn-light btn-touch', 'id="msg-' . $id . '-original"') ?>
    </div>
</div>
