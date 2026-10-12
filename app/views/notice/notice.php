<?php /** The monitoring notice (screen `notice`). Data: notice [text, version, updated_at], acknowledgedAt, next, tz */ ?>
<?= view('shared/header.php', ['id' => 'notice', 'title' => 'How mail is looked after here', 'crumbs' => [[app_name(), '/'], ['Monitoring notice', null]]]) ?>
<div class="main-content">
    <div class="card notice-card" id="notice-card">
        <div class="card-body">
            <p class="fs-14 lh-lg mb-3" id="notice-text"><?= nl2br(e($notice['text'])) ?></p>
            <p class="fs-12 text-muted mb-3">Version <?= (int) $notice['version'] ?><?= $notice['updated_at'] ? ' · ' . e(format_ts((string) $notice['updated_at'], $tz, 'M j, Y')) : '' ?></p>
            <?php if ($acknowledgedAt !== null): ?>
                <div class="alert alert-success mb-0" id="notice-acknowledged"><i class="feather-check me-1"></i>You acknowledged this version on <?= e(format_ts($acknowledgedAt, $tz)) ?>.</div>
            <?php else: ?>
                <form method="post" action="/notice/acknowledge.php" id="notice-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="version" value="<?= (int) $notice['version'] ?>" />
                    <input type="hidden" name="next" value="<?= e($next) ?>" />
                    <button type="submit" class="btn btn-primary" id="notice-acknowledge-btn"><i class="feather-check me-1"></i>I have read this</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
