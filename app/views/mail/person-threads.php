<?php
/** The conversations with an address or a company (#person-threads; Older swaps it). Data: box, c, domain, tab, threads, tz, base */
$mb = (int) $box['id'];
$own = strtolower((string) $box['address']);
?>
<div id="person-threads">
<?php if ($threads['rows'] === []): ?>
    <div class="card"><div class="card-body text-muted" id="person-threads-none">No conversations <?= $tab === 'company' ? 'with ' . e($domain) : 'with ' . e($c['address']) ?> that you can open.</div></div>
<?php else: ?>
    <div class="fs-12 text-muted mb-2" id="person-threads-title"><?= $tab === 'company' ? 'Conversations with anyone at ' . e($domain) : 'Conversations with ' . e($c['address']) ?></div>
    <div class="card mb-2"><div class="list-group list-group-flush" id="person-thread-rows" role="list">
    <?php foreach ($threads['rows'] as $t): $href = '/m/' . $mb . '/t/' . (int) $t['thread_id']; ?>
        <div class="list-group-item mail-row d-flex align-items-start gap-2<?= $t['unread_count'] > 0 ? ' mail-unread fw-bold' : '' ?>" id="pt-<?= (int) $t['thread_id'] ?>" role="listitem" data-thread="<?= (int) $t['thread_id'] ?>">
            <a class="mail-row-link d-flex flex-column flex-md-row flex-grow-1 min-w-0 gap-md-3 text-reset text-decoration-none" href="<?= e($href) ?>" hx-get="<?= e($href) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($href) ?>">
                <span class="mail-who text-truncate"><?= e(implode(', ', array_slice(array_values(array_filter(array_map('strval', $t['participants']), static fn (string $a): bool => strtolower($a) !== $own)), 0, 3))) ?><?php if ($t['message_count'] > 1): ?> <span class="fw-normal text-muted fs-12 mail-count"><?= (int) $t['message_count'] ?></span><?php endif; ?></span>
                <span class="mail-what flex-grow-1 min-w-0 text-truncate">
                    <?php if ($t['sensitive']): ?><i class="feather-lock me-1 text-muted" role="img" aria-label="Marked sensitive"></i><?php endif; ?>
                    <span class="mail-subject"><?= e(($t['subject'] ?? '') !== '' ? $t['subject'] : '(no subject)') ?></span>
                    <?php if (($t['snippet'] ?? '') !== ''): ?><span class="fw-normal text-muted mail-snippet"> — <?= e($t['snippet']) ?></span><?php endif; ?>
                </span>
            </a>
            <span class="text-nowrap fs-12 mail-meta"><?php if ($t['has_attachments']): ?><i class="feather-paperclip me-1" role="img" aria-label="Has attachments"></i><?php endif; ?><?= e(mail_list_date($t['last_message_at'], $tz)) ?></span>
        </div>
    <?php endforeach; ?>
    </div></div>
    <?php if ($threads['next'] !== null): $url = $base . '?' . http_build_query(array_filter(['tab' => $tab === 'company' ? 'company' : '', 'before' => $threads['next']], static fn ($v): bool => $v !== '')); ?>
        <div class="text-center"><a class="btn btn-sm btn-light btn-touch" id="person-older" href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#person-threads" hx-swap="outerHTML" hx-push-url="<?= e($url) ?>">Older <i class="feather-chevron-right"></i></a></div>
    <?php endif; ?>
<?php endif; ?>
</div>
