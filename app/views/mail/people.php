<?php
/** A mailbox's correspondents (screen `correspondents`). Data: box, filters, page, agentBox, canMark, tz, base, notice */
$mb = (int) $box['id'];
?>
<?= view('shared/header.php', ['id' => 'correspondents', 'title' => 'People', 'crumbs' => [[$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . $mb . '/v/inbox'], ['People', null]], 'back' => ['/m/' . $mb . '/v/inbox', 'Inbox']]) ?>
<div class="main-content" id="correspondents-content">
    <?php if (!empty($notice)): ?><?= view('shared/banner.php', ['notice' => $notice]) ?><?php endif; ?>
    <form method="get" action="<?= e($base) ?>" hx-get="<?= e($base) ?>" hx-target="#people-results" hx-swap="outerHTML" hx-push-url="true" class="card card-body mb-3" id="people-filter">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label fs-12 text-muted mb-1" for="people-q">Name or address</label>
                <input type="search" class="form-control btn-touch" name="q" id="people-q" value="<?= e($filters['q']) ?>" maxlength="100" autocomplete="off">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fs-12 text-muted mb-1" for="people-known">Known</label>
                <select class="form-select btn-touch" name="known" id="people-known">
                    <?php foreach (['' => 'Any', 'yes' => 'Known', 'no' => 'Not known'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['known'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fs-12 text-muted mb-1" for="people-outside">Outside</label>
                <select class="form-select btn-touch" name="outside" id="people-outside">
                    <?php foreach (['' => 'Any', 'yes' => 'Outside', 'no' => 'Inside'] as $v => $l): ?><option value="<?= e($v) ?>"<?= $filters['outside'] === $v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="people-go">Show</button></div>
        </div>
    </form>
    <?= view('mail/people-results.php', compact('box', 'filters', 'page', 'agentBox', 'canMark', 'tz', 'base')) ?>
</div>
