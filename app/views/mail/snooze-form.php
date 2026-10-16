<?php
/**
 * The choosing half of "Snooze": the five times in the person's time zone (as UTC instants) and a date and time of one's own.
 * Data: ep (the handler's path), pfx (id prefix), fa (extra attributes for each input, e.g. ' form="mail-bulk-form"')
 */
$fa = $fa ?? '';
?>
<?php foreach (ml_snooze_choices(member_timezone()) as $c): ?>
    <div class="form-check"><input class="form-check-input mail-snooze-choice" type="radio" name="until" value="<?= e($c['at']->format('Y-m-d\TH:i:s\Z')) ?>" id="<?= e($pfx) ?>-<?= e($c['key']) ?>"<?= $fa ?>>
        <label class="form-check-label" for="<?= e($pfx) ?>-<?= e($c['key']) ?>"><?= e($c['label']) ?> <span class="text-muted fs-12"><?= e($c['text']) ?></span></label></div>
<?php endforeach; ?>
<label class="form-label fs-12 text-muted mt-2 mb-1" for="<?= e($pfx) ?>-local">Pick a date and time</label>
<input type="datetime-local" class="form-control form-control-sm mail-snooze-local" name="until_local" id="<?= e($pfx) ?>-local"<?= $fa ?>>
<button type="submit" class="btn btn-sm btn-primary btn-touch mt-2 mail-bulk-action" formaction="<?= e($ep) ?>" hx-post="<?= e($ep) ?>" hx-target="#page-content" id="<?= e($pfx) ?>-apply">Snooze</button>
