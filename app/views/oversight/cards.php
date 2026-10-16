<?php /** The agent mailboxes a person oversees, as cards (screen `oversight`). Data: cards (oversight_cards), notice, queued (held mail I decide), tz */
$n = static fn (array $s, string $k): int => (int) ($s[$k] ?? 0);
?>
<?= view('shared/header.php', ['id' => 'oversight', 'title' => 'Oversight', 'crumbs' => [[app_name(), '/'], ['Oversight', null]],
    'action' => hx_link('/oversight/review', '<i class="feather-inbox me-1"></i>Review' . ($queued > 0 ? ' <span class="badge bg-warning text-dark ms-1" id="oversight-queued">' . $queued . '</span>' : ''), 'btn btn-primary btn-sm btn-touch', 'id="oversight-review-btn"')]) ?>
<div class="main-content" id="oversight-content">
    <?= view('shared/banner.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 mb-3 fs-12">
        <?= hx_link('/oversight/review', 'Held mail', 'btn btn-sm btn-light btn-touch', 'id="oversight-queue-link"') ?>
        <?= hx_link('/oversight/unscreened', 'Unscreened', 'btn btn-sm btn-light btn-touch', 'id="oversight-unscreened-link"') ?>
    </div>
    <?php if ($cards === []): ?>
        <div class="card"><div class="card-body text-muted" id="oversight-empty">You oversee no agent's mailbox. A Mail admin names an agent mailbox's overseers.</div></div>
    <?php endif; ?>
    <div class="row g-3" id="oversight-cards">
        <?php foreach ($cards as $c): $id = (int) $c['id']; $s = $c['summary']; $paused = !empty($s['paused']); ?>
        <div class="col-12 col-md-6 col-xl-4" id="agent-card-<?= $id ?>">
            <div class="card h-100<?= $paused ? ' border-danger' : '' ?>">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-2">
                        <i class="feather-cpu mt-1"></i>
                        <div class="min-w-0 flex-grow-1">
                            <h6 class="fw-bold mb-0 text-break"><?= hx_link('/m/' . $id . '/v/inbox', e($c['display_name'] !== '' ? $c['display_name'] : $c['address']), '', 'id="agent-card-' . $id . '-link"') ?></h6>
                            <div class="fs-12 text-muted text-truncate"><?= e($c['address']) ?><?= $c['agent_name'] !== null ? ' · ' . e($c['agent_name']) : '' ?></div>
                        </div>
                        <?php if ($n($s, 'held_now') > 0): ?><span class="badge bg-warning text-dark" id="agent-card-<?= $id ?>-held"><?= $n($s, 'held_now') ?> held</span><?php endif; ?>
                    </div>
                    <?php if ($paused): ?>
                        <div class="text-danger fw-semibold fs-12 mt-2" id="agent-card-<?= $id ?>-paused">Paused<?= $c['paused_by_name'] !== null ? ' by ' . e($c['paused_by_name']) : '' ?><?= ($s['pause_reason'] ?? null) !== null ? ': ' . e((string) $s['pause_reason']) : '' ?></div>
                    <?php endif; ?>
                    <div class="fs-12 mt-2 text-muted">Last 7 days</div>
                    <dl class="row fs-12 mb-1 g-0" id="agent-card-<?= $id ?>-counts">
                        <?php foreach (['received' => 'received', 'woken' => 'woken', 'unscreened' => 'unscreened', 'sent' => 'sent', 'held_now' => 'held now', 'rejected' => 'rejected', 'expired' => 'expired'] as $k => $label): ?>
                            <dt class="col-7 fw-normal text-muted"><?= $label ?></dt><dd class="col-5 mb-0 text-end fw-semibold" data-count="<?= $k ?>"><?= $n($s, $k) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                    <div class="fs-12 text-muted" id="agent-card-<?= $id ?>-record">Its record: <?= $n($s, 'approved_unedited') ?> approved as written · <?= $n($s, 'edited') ?> edited · <?= $n($s, 'rejected') ?> rejected</div>
                    <?php if (($s['oldest_held_at'] ?? null) !== null): ?><div class="fs-12 text-warning mt-1">Oldest held: <?= e(format_ts((string) $s['oldest_held_at'], $tz, 'M j, g:i A')) ?></div><?php endif; ?>
                    <?php if ($c['primary_overseer_name'] === null): ?><div class="fs-12 text-danger mt-1">No primary overseer: its mail waits in Unscreened.</div><?php endif; ?>
                    <div class="d-flex flex-wrap gap-1 mt-3">
                        <?= hx_link('/oversight/review?mailbox=' . $id, 'Queue', 'btn btn-sm btn-light btn-touch', 'id="agent-card-' . $id . '-queue"') ?>
                        <?= hx_link('/oversight/unscreened?mailbox=' . $id, 'Unscreened' . ($n($s, 'unscreened') > 0 ? ' (' . $n($s, 'unscreened') . ')' : ''), 'btn btn-sm btn-light btn-touch', 'id="agent-card-' . $id . '-unscreened"') ?>
                        <?= hx_link('/m/' . $id . '/policy', 'Policy', 'btn btn-sm btn-light btn-touch', 'id="agent-card-' . $id . '-policy"') ?>
                    </div>
                    <?php if ($paused): ?>
                        <form method="post" action="/oversight/resume" hx-post="/oversight/resume" hx-target="#page-content" class="mt-2" id="agent-card-<?= $id ?>-resume-form"><?= csrf_field() ?>
                            <input type="hidden" name="mailbox" value="<?= $id ?>"><input type="hidden" name="return_to" value="/oversight">
                            <button type="submit" class="btn btn-primary btn-sm btn-touch w-100" id="agent-card-<?= $id ?>-resume">Resume</button></form>
                    <?php else: ?>
                        <details class="mt-2" id="agent-card-<?= $id ?>-pause-box"><summary class="btn btn-sm btn-outline-danger btn-touch w-100" id="agent-card-<?= $id ?>-pause-open">Pause</summary>
                            <form method="post" action="/oversight/pause" hx-post="/oversight/pause" hx-target="#page-content" class="mt-2" id="agent-card-<?= $id ?>-pause-form"><?= csrf_field() ?>
                                <input type="hidden" name="mailbox" value="<?= $id ?>"><input type="hidden" name="return_to" value="/oversight">
                                <label class="form-label fs-12 text-muted" for="agent-card-<?= $id ?>-reason">Why (the other overseers see it)</label>
                                <input type="text" name="reason" id="agent-card-<?= $id ?>-reason" class="form-control form-control-sm btn-touch mb-2" maxlength="300" required>
                                <button type="submit" class="btn btn-danger btn-sm btn-touch w-100" id="agent-card-<?= $id ?>-pause">Pause: hold its mail, wake it for nothing</button></form>
                        </details>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
