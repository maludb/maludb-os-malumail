<?php
/** Canned replies as cards (screen `templates`). Data: rows (ml_templates_list()), q, notice, composeBox (the mailbox to write in, or null) */
?>
<?= view('shared/header.php', ['id' => 'templates', 'title' => 'Canned replies', 'crumbs' => [[app_name(), '/'], ['Canned replies', null]],
    'action' => hx_link('/templates/new', '<i class="feather-plus me-1"></i>New', 'btn btn-primary btn-touch', 'id="template-new-btn"')]) ?>
<div class="main-content" id="templates-content">
    <?php if (!empty($notice)): ?><?= view('shared/banner.php', ['notice' => $notice]) ?><?php endif; ?>
    <form method="get" action="/templates" class="mb-3" role="search" id="templates-search">
        <div class="input-group"><input type="search" name="q" class="form-control btn-touch" value="<?= e($q) ?>" placeholder="Search canned replies" aria-label="Search canned replies">
            <button type="submit" class="btn btn-light btn-touch">Search</button></div>
    </form>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="templates-none"><?= $q !== '' ? 'No canned reply matches ' . e($q) . '.' : 'No canned replies yet — write one for answers you give often.' ?></div></div>
    <?php endif; ?>
    <div class="row g-3" id="template-cards">
    <?php foreach ($rows as $t): $tid = (int) $t['template_id']; ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 template-card" id="template-<?= $tid ?>">
            <div class="card-body d-flex flex-column">
                <div class="d-flex align-items-start gap-2 mb-1">
                    <?= hx_link('/templates/' . $tid . '/edit', e($t['name']), 'fw-semibold text-reset template-name', 'id="template-' . $tid . '-open"') ?>
                    <span class="ms-auto badge <?= $t['shared'] ? 'bg-soft-info text-info' : 'bg-soft-secondary text-secondary' ?> fw-normal template-scope"><?= $t['shared'] ? 'Shared' : 'Mine' ?></span>
                </div>
                <?php if (($t['subject'] ?? '') !== ''): ?><div class="fs-12 text-muted text-truncate template-subject"><?= e($t['subject']) ?></div><?php endif; ?>
                <div class="mt-2 template-first-line"><?= e(ml_template_first_line((string) $t['body'])) ?></div>
                <div class="mt-auto pt-2 fs-12 text-muted d-flex flex-wrap gap-2 align-items-center">
                    <span class="template-used">Used <?= (int) $t['usage_count'] ?> <?= (int) $t['usage_count'] === 1 ? 'time' : 'times' ?></span>
                    <?php if (!$t['mine']): ?><span class="template-owner">Owned by <?= e($t['owner_name'] !== '' ? $t['owner_name'] : 'someone who left') ?></span><?php endif; ?>
                    <?php if ($composeBox !== null): ?><?= hx_link('/m/' . $composeBox . '/compose?template=' . $tid, 'Use', 'ms-auto', 'id="template-' . $tid . '-use"') ?><?php endif; ?>
                </div>
            </div>
        </div></div>
    <?php endforeach; ?>
    </div>
</div>
