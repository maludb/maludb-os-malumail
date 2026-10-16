<?php /** The schedule panel (writing.md "Schedule…"): an inline panel, not a modal — three choices in the person's zone and a time picker. Data: choices, tz */ ?>
<div class="mail-schedule border rounded p-2 mb-2 d-none" id="compose-schedule" aria-label="Schedule the send">
    <div class="fs-13 fw-semibold mb-1">Send later (<?= e($tz) ?>)</div>
    <div class="d-flex flex-wrap gap-1 mb-2">
        <?php foreach ($choices as $c): ?>
            <button type="button" class="btn btn-sm btn-light mail-schedule-choice" data-at="<?= e($c['at']->format('Y-m-d\TH:i')) ?>"><?= e($c['label']) ?> <span class="text-muted">(<?= e($c['at']->format('D H:i')) ?>)</span></button>
        <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <label class="fs-13 mb-0" for="compose-schedule-at">Pick a date and time</label>
        <input type="datetime-local" class="form-control form-control-sm" id="compose-schedule-at" style="max-width:14rem">
        <button type="button" class="btn btn-sm btn-primary" id="compose-schedule-send">Schedule send</button>
        <button type="button" class="btn btn-sm btn-link" id="compose-schedule-close">Close</button>
    </div>
</div>
