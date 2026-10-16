<?php /** The queue's rows (the 30-second poll answers these alone). Data: rows, tz, mailbox, reason */
if ($rows === []): ?>
    <div class="card"><div class="card-body text-muted" id="review-empty">Nothing waits for your review<?= $mailbox !== null || $reason !== null ? ' here' : '' ?>.</div></div>
<?php endif; ?>
<div class="list-group" id="review-list">
    <?php foreach ($rows as $r): ?><?= view('oversight/_row.php', ['r' => $r, 'tz' => $tz]) ?><?php endforeach; ?>
</div>
