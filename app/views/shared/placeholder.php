<?php
/** A screen the manifest names that a later slice builds (sso-shell.md "Placeholders"): its name, what it will do, the slice. Data: id, screen (the registry's row), slice */ ?>
<?= view('shared/header.php', ['id' => 'placeholder', 'title' => ucfirst(str_replace('-', ' ', $id)), 'crumbs' => [[app_name(), '/'], [ucfirst(str_replace('-', ' ', $id)), null]]]) ?>
<div class="main-content">
    <div class="card" id="placeholder-card">
        <div class="card-body">
            <p class="mb-2" id="placeholder-when"><?= e(ucfirst((string) ($screen['when'] ?? ''))) ?>.</p>
            <p class="text-muted mb-0" id="placeholder-slice">This screen is not built yet: <?= e($slice) ?> builds this screen.</p>
        </div>
    </div>
</div>
