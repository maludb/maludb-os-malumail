<?php
/**
 * Search (screens `search` and `search-all`, search-labels-snooze.md): the box filled, the line saying what was searched, the results
 * (#search-results — Older swaps it), the operators in a <details>. Data: box (null for search-all), multi, q, page (ml_search_page()),
 * tz, labels, canLabel, base, notice
 */
$title = $multi ? 'Search all mail' : 'Search ' . ($box['display_name'] !== '' ? $box['display_name'] : $box['address']);
$crumbs = $multi ? [[app_name(), '/'], ['Search', null]] : [[$box['display_name'] !== '' ? $box['display_name'] : $box['address'], '/m/' . (int) $box['id'] . '/v/inbox'], ['Search', null]];
?>
<?= view('shared/header.php', ['id' => $multi ? 'search-all' : 'search', 'title' => $title, 'crumbs' => $crumbs]) ?>
<div class="main-content" id="search-page">
    <?php if (!empty($notice)): ?><?= view('shared/banner.php', ['notice' => $notice]) ?><?php endif; ?>
    <form method="get" action="<?= e($base) ?>" class="mb-3" id="search-form" role="search">
        <div class="input-group">
            <input type="search" name="q" id="search-q" class="form-control btn-touch" value="<?= e($q) ?>" placeholder="from:ada has:attachment newer_than:7d" aria-label="Search mail" autocomplete="off" autocapitalize="none">
            <button type="submit" class="btn btn-primary btn-touch" id="search-go"><i class="feather-search me-1"></i>Search</button>
        </div>
    </form>
    <?= view('mail/search-results.php', ['box' => $box, 'multi' => $multi, 'q' => $q, 'page' => $page, 'tz' => $tz, 'labels' => $labels, 'canLabel' => $canLabel, 'base' => $base, 'notice' => null]) ?>
    <details class="mt-3" id="search-help"<?= $q === '' || ($page['rows'] === [] && $page['error'] === null) ? ' open' : '' ?>>
        <summary class="text-muted">Search operators</summary>
        <div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="search-help-table"><tbody>
            <?php foreach (ml_search_help() as [$op, $what, $ex]): ?>
                <tr><td class="text-nowrap"><code><?= e($op) ?></code></td><td><?= e($what) ?></td><td class="text-muted text-nowrap d-none d-md-table-cell"><code><?= e($ex) ?></code></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </details>
</div>
