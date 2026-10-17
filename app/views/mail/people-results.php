<?php
/** The correspondents' rows (#people-results; Older swaps it). Data: box, filters, page, agentBox, canMark, tz, base */
$mb = (int) $box['id'];
$self = $base . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$query = static fn (array $extra): string => $base . '?' . http_build_query(array_filter($extra + ['q' => $filters['q'], 'known' => $filters['known'], 'outside' => $filters['outside']], static fn ($v): bool => $v !== '' && $v !== null));
?>
<div id="people-results">
<?php if ($page['rows'] === []): ?>
    <div class="card"><div class="card-body text-muted" id="people-none"><?= array_filter($filters) === [] ? 'No correspondents yet — they appear as mail comes and goes.' : 'No correspondent matches.' ?></div></div>
<?php else: ?>
    <div class="card mb-2"><div class="list-group list-group-flush" id="people-rows" role="list">
    <?php foreach ($page['rows'] as $r): $href = '/m/' . $mb . '/people/' . rawurlencode((string) $r['address']); $known = $r['known_at'] !== null; ?>
        <div class="list-group-item people-row" id="person-<?= (int) $r['id'] ?>" role="listitem" data-address="<?= e((string) $r['address']) ?>">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <a class="fw-semibold text-reset text-break people-name" href="<?= e($href) ?>" hx-get="<?= e($href) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e($href) ?>"><?= e(($r['name'] ?? '') !== '' ? $r['name'] : $r['address']) ?></a>
                <?php if (($r['name'] ?? '') !== ''): ?><span class="fs-12 text-muted text-break people-address"><?= e($r['address']) ?></span><?php endif; ?>
                <?php if (!$r['is_internal']): ?><span class="badge bg-soft-warning text-warning fw-normal people-outside">Outside</span><?php endif; ?>
                <?php if ($agentBox && $known): ?><span class="badge bg-soft-success text-success fw-normal people-known">Known · <?= $r['known_how'] === 'marked' ? 'marked' : 'sent' ?></span><?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-3 fs-12 text-muted mt-1">
                <span class="people-first">First <?= e(format_ts((string) $r['first_seen_at'], $tz, 'M j, Y')) ?></span>
                <span class="people-last">Last <?= e(format_ts((string) $r['last_seen_at'], $tz, 'M j, Y')) ?></span>
                <span class="people-sent"><?= (int) $r['sent_count'] ?> sent</span>
                <span class="people-received"><?= (int) $r['received_count'] ?> received</span>
            </div>
            <?php if ($canMark && !$known): ?>
                <form method="post" action="/oversight/mark-known" hx-post="/oversight/mark-known" hx-target="#page-content" class="mt-2" id="person-<?= (int) $r['id'] ?>-known">
                    <?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $mb ?>"><input type="hidden" name="address" value="<?= e((string) $r['address']) ?>"><input type="hidden" name="return_to" value="<?= e($self) ?>">
                    <button type="submit" class="btn btn-sm btn-light btn-touch">Mark known</button></form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div></div>
    <?php if ($page['next'] !== null): $url = $query(['before' => $page['next']]); ?>
        <div class="text-center"><a class="btn btn-sm btn-light btn-touch" id="people-older" href="<?= e($url) ?>" hx-get="<?= e($url) ?>" hx-target="#people-results" hx-swap="outerHTML" hx-push-url="<?= e($url) ?>">Older <i class="feather-chevron-right"></i></a></div>
    <?php endif; ?>
<?php endif; ?>
</div>
