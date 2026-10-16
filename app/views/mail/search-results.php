<?php
/** Search's results (#search-results; Older swaps it). Data: box (null for search-all), multi, q, page, tz, labels, canLabel, base, notice */
$rows = $page['rows'];
$searched = $multi ? 'every mailbox you can open' : ($box['display_name'] !== '' ? $box['display_name'] : $box['address']);
$hasIn = (bool) preg_match('/(^|\s)-?in:/i', $q);
?>
<div id="search-results">
<?php if ($q === ''): ?>
    <p class="text-muted" id="search-empty-prompt">Type what to look for. Gmail's operators work: from:, to:, subject:, has:attachment, is:unread, label:, in:, after:, before:.</p>
<?php else: ?>
    <p class="fs-12 text-muted mb-2" id="search-scope">Searched <?= e($searched) ?><?= $hasIn ? '' : ' — Trash and Spam left out; add <code>in:anywhere</code> to include them' ?>.</p>
    <?php if ($page['error'] !== null): ?><div class="alert alert-warning" id="search-error"><?= e($page['error']) ?></div><?php endif; ?>
    <?php if ($page['unknown'] !== []): ?><p class="fs-12 mb-2" id="search-unknown">Unknown operators searched as words: <?php foreach ($page['unknown'] as $u): ?><code class="me-1"><?= e($u) ?></code><?php endforeach; ?></p><?php endif; ?>
    <?php if ($page['capped']): ?><p class="fs-12 text-muted" id="search-capped">This search finds more than <?= number_format(ML_SEARCH_WALK) ?> messages; narrow it to see the rest.</p><?php endif; ?>
    <?php if ($rows === [] && $page['error'] === null): ?>
        <div class="card"><div class="card-body text-muted" id="search-none">No mail matches <?= e($q) ?></div></div>
    <?php endif; ?>
    <?php if ($rows !== []): ?>
        <div class="fs-12 text-muted mb-2" id="search-count"><?= number_format($page['total']) ?> <?= $page['total'] === 1 ? 'conversation' : 'conversations' ?> found</div>
        <?php if (!empty($canLabel)): ?>
            <details class="mb-2" id="search-label-all"><summary class="btn btn-sm btn-light btn-touch"><i class="feather-tag me-1"></i>Label every conversation this search finds</summary>
                <form method="post" action="/mail/label" hx-post="/mail/label" hx-target="#page-content" class="card card-body mt-2">
                    <?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= (int) $box['id'] ?>"><input type="hidden" name="matching" value="<?= e($q) ?>">
                    <input type="hidden" name="return_to" value="<?= e($base . '?q=' . rawurlencode($q)) ?>">
                    <p class="fs-12 text-muted mb-2">Up to <?= number_format(ML_MATCHING_CAP) ?> conversations.</p>
                    <?php foreach ($labels as $l): ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="label[]" value="<?= (int) $l['id'] ?>" id="search-label-<?= (int) $l['id'] ?>">
                            <label class="form-check-label" for="search-label-<?= (int) $l['id'] ?>"><?= e($l['label']) ?></label></div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary btn-touch mt-2" id="search-label-all-go">Apply the label</button>
                </form></details>
        <?php endif; ?>
        <div class="card mb-2"><div class="list-group list-group-flush" id="search-rows" role="list">
        <?php foreach ($rows as $r): $rb = (int) $r['mailbox_id']; $href = $r['thread_id'] !== null ? '/m/' . $rb . '/t/' . (int) $r['thread_id'] : null;
            $who = mail_participants($r, (string) $r['mailbox_address']); ?>
            <div class="list-group-item mail-row d-flex align-items-start gap-2<?= $r['unread'] ? ' mail-unread fw-bold' : '' ?>" id="s-<?= (int) $r['thread_id'] ?>" role="listitem" data-thread="<?= (int) $r['thread_id'] ?>" data-cursor="<?= e($r['cursor']) ?>">
                <span class="pt-1 mail-star<?= $r['flagged'] ? ' text-warning' : ' text-muted' ?>" role="img" aria-label="<?= $r['flagged'] ? 'Starred' : 'Not starred' ?>"><i class="feather-star<?= $r['flagged'] ? ' mail-starred' : '' ?>"></i></span>
                <?php if ($href !== null): ?><a class="mail-row-link d-flex flex-column flex-md-row flex-grow-1 min-w-0 gap-md-3 text-reset text-decoration-none" href="<?= e($href) ?>" hx-get="<?= e($href) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($href) ?>"><?php else: ?><div class="d-flex flex-column flex-md-row flex-grow-1 min-w-0 gap-md-3"><?php endif; ?>
                    <span class="mail-who text-truncate"><?= e($who) ?><?php if ((int) $r['message_count'] > 1): ?> <span class="fw-normal text-muted fs-12 mail-count"><?= (int) $r['message_count'] ?></span><?php endif; ?></span>
                    <span class="mail-what flex-grow-1 min-w-0 text-truncate">
                        <?php if ($r['sensitive']): ?><i class="feather-lock me-1 text-muted mail-sensitive" role="img" aria-label="Marked sensitive"></i><?php endif; ?>
                        <?php foreach ($r['labels'] as $l): ?><span class="badge bg-soft-primary text-primary me-1 fw-normal mail-label"><?= e($l['label'] ?? '') ?></span><?php endforeach; ?>
                        <span class="mail-subject"><?= e(($r['subject'] ?? '') !== '' ? $r['subject'] : '(no subject)') ?></span>
                        <?php if (($r['snippet'] ?? '') !== ''): ?><span class="fw-normal text-muted mail-snippet"> — <?= e($r['snippet']) ?></span><?php endif; ?>
                    </span>
                <?= $href !== null ? '</a>' : '</div>' ?>
                <div class="d-flex flex-column align-items-end flex-shrink-0 gap-1 fs-12 mail-meta">
                    <span class="text-nowrap mail-date"><?php if ($r['has_attachments']): ?><i class="feather-paperclip me-1" role="img" aria-label="Has attachments"></i><?php endif; ?><?= e(mail_list_date($r['last_message_at'], $tz)) ?></span>
                    <?php if ((int) $r['matched'] > 1): ?><span class="fw-normal text-muted search-matched"><?= (int) $r['matched'] ?> messages match</span><?php endif; ?>
                    <?php if ($multi): ?><span class="badge bg-soft-secondary text-secondary fw-normal search-mailbox"><?= e($r['mailbox_address']) ?></span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div></div>
        <?php if ($page['next'] !== null): $url = $base . '?q=' . rawurlencode($q) . '&before=' . rawurlencode($page['next']); ?>
            <div class="text-center"><a class="btn btn-sm btn-light btn-touch" id="search-older" href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#search-results" hx-swap="outerHTML" hx-push-url="<?= e($url) ?>">Older <i class="feather-chevron-right"></i></a></div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</div>
