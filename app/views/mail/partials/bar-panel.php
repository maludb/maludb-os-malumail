<?php
/**
 * The bulk bar's Label and Snooze: a small form below the bar (a <details>, no modal) whose inputs belong to #mail-bulk-form, so they post
 * thread[] with the rest of it. Data: a (the action: name, label, icon, endpoint), box
 */
$ep = preg_replace('/\.php$/', '', $a['endpoint']);
$isLabel = $a['name'] === 'mail_label';
$mb = (int) $box['id'];
?>
<details class="mail-bar-panel" id="mail-bulk-panel-<?= $isLabel ? 'label' : 'snooze' ?>">
    <summary class="btn btn-sm btn-light btn-touch" id="mail-bulk-<?= e($a['name']) ?>"><i class="<?= e($a['icon']) ?> me-1"></i><?= e($a['label']) ?></summary>
    <div class="card card-body p-2 mt-1 mail-bar-form">
        <?= $isLabel ? view('mail/label-form.php', ['mb' => $mb, 'ep' => $ep, 'pfx' => 'mail-label', 'fa' => ' form="mail-bulk-form"'])
                     : view('mail/snooze-form.php', ['ep' => $ep, 'pfx' => 'mail-snooze', 'fa' => ' form="mail-bulk-form"']) ?>
    </div>
</details>
