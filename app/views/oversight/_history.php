<?php /** The policy's history from the activity log: policy.set (the keys, old → new), mailbox.pause (the reason), mailbox.resume. Data: history, tz */ ?>
<div class="card mb-3" id="policy-history"><div class="card-header"><h5 class="card-title mb-0">History</h5></div><div class="card-body fs-13">
    <?php if ($history === []): ?><div class="text-muted" id="policy-history-empty">No change yet.</div><?php endif; ?>
    <?php foreach ($history as $h): $a = $h['after']; ?>
        <div class="mb-2" id="policy-history-<?= (int) $h['id'] ?>" data-action="<?= e((string) $h['action']) ?>">
            <span class="fw-semibold"><?= e(['policy.set' => 'Changed', 'mailbox.pause' => 'Paused', 'mailbox.resume' => 'Resumed'][$h['action']] ?? $h['action']) ?></span>
            · <?= e((string) ($h['actor_name'] ?? '—')) ?> · <span class="text-muted"><?= e(format_ts((string) $h['created_at'], $tz, 'M j, g:i A')) ?></span>
            <?php if ($h['action'] === 'policy.set'): foreach (($a['keys'] ?? []) as $k): ?>
                <div class="fs-12 text-break"><?= e(str_replace('_', ' ', (string) $k)) ?>: <?= e(json_encode($a[$k] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></div>
            <?php endforeach; elseif ($h['action'] === 'mailbox.pause' && ($a['reason'] ?? null) !== null): ?>
                <div class="fs-12 text-break">«<?= e((string) $a['reason']) ?>»</div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div></div>
