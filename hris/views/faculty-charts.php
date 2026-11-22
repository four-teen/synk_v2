<?php
if (!isset($view, $unrankedCount) || $view !== 'dashboard') { http_response_code(404); return; }
$rankSeries = ['faculty' => 'Active faculty', 'master' => 'Master’s degree', 'doctoral' => 'Doctorate'];
$chartTabs = [
    'rank' => ['Academic Rank', 'Active faculty by academic rank', 'bx-line-chart'],
    'employment' => ['Employment Classification', 'Active employees by employment classification', 'bx-bar-chart-alt-2'],
];
$requestedChart = $_GET['chart'] ?? '';
$activeChart = is_string($requestedChart) && isset($chartTabs[$requestedChart]) ? $requestedChart : 'rank';
?>
<section class="hris-card hris-panel hris-faculty-charts" id="faculty-charts" aria-labelledby="faculty-chart-title">
    <div class="hris-panel-heading hris-charts-heading">
        <div>
            <h2 id="faculty-chart-title"><?= synk_hris_h($chartTabs[$activeChart][1]) ?></h2>
            <p>Employees verified as Active by HR</p>
        </div>
        <nav class="hris-chart-tabs" aria-label="Faculty charts" data-chart-tabs>
            <?php foreach ($chartTabs as $key => [$label, $title, $icon]): ?>
                <a id="chart-tab-<?= $key ?>" href="?chart=<?= $key ?>#faculty-charts" data-chart-tab="<?= $key ?>"
                    data-chart-title="<?= synk_hris_h($title) ?>"<?= $key === $activeChart ? ' aria-current="page"' : '' ?>>
                    <i class="bx <?= $icon ?>" aria-hidden="true"></i><span><?= synk_hris_h($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>

    <div id="chart-panel-rank" data-chart-panel="rank"<?= $activeChart !== 'rank' ? ' hidden' : '' ?>>
        <?php if ($rankCounts === null): ?>
            <p class="hris-empty">Academic rank counts are unavailable.</p>
        <?php else: ?>
            <ul class="hris-rank-legend" aria-label="Chart legend" hidden>
                <?php foreach ($rankSeries as $series => $label): ?>
                    <li><span class="hris-rank-swatch hris-rank-swatch-<?= $series ?>" aria-hidden="true"></span><?= synk_hris_h($label) ?></li>
                <?php endforeach; ?>
            </ul>
            <p id="rank-chart-scroll" class="hris-rank-scroll-hint" hidden>Scroll horizontally to see every rank.</p>
            <div class="hris-rank-chart-scroll" role="region" aria-label="Academic rank chart" data-rank-chart-scroll hidden>
                <div class="hris-rank-chart" role="img" aria-label="Active faculty and completed degrees by academic rank"
                    data-rank-counts="<?= synk_hris_h(json_encode($rankCounts)) ?>"
                    data-rank-series="<?= synk_hris_h(json_encode($rankSeries)) ?>"></div>
            </div>
            <?php if ($unrankedCount > 0): ?>
                <p class="hris-note"><?= number_format($unrankedCount) ?> <?= $unrankedCount === 1 ? 'profile has' : 'profiles have' ?> no matching academic rank.</p>
            <?php endif; ?>
            <p class="hris-note">Degree lines count completed studies. Faculty with both degrees appear in both lines.</p>
            <?php if (!isset($sources['degrees'])): ?><p class="hris-note" role="status">Additional degree records could not be loaded.</p><?php endif; ?>
            <details class="hris-rank-values">
                <summary>View rank counts</summary>
                <table>
                    <thead><tr>
                        <th scope="col">Academic rank</th>
                        <?php foreach ($rankSeries as $label): ?><th scope="col"><?= synk_hris_h($label) ?></th><?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($rankCounts as $rank => $counts): ?>
                            <tr>
                                <th scope="row"><?= synk_hris_h($rank) ?></th>
                                <?php foreach ($rankSeries as $series => $label): ?><td><?= number_format($counts[$series]) ?></td><?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>
    </div>

    <div id="chart-panel-employment" data-chart-panel="employment"<?= $activeChart !== 'employment' ? ' hidden' : '' ?>>
        <?php if ($employmentCounts === null): ?>
            <p class="hris-empty">Employment classification counts are unavailable.</p>
        <?php else: ?>
            <div class="hris-employment-chart" role="img" aria-label="Active employee counts by employment classification"
                data-employment-counts="<?= synk_hris_h(json_encode($employmentCounts)) ?>" hidden></div>
            <details class="hris-rank-values">
                <summary>View classification counts</summary>
                <table>
                    <thead><tr><th scope="col">Employment classification</th><th scope="col">Employee count</th></tr></thead>
                    <tbody>
                        <?php foreach ($employmentCounts as $classification => $count): ?>
                            <tr><th scope="row"><?= synk_hris_h($classification) ?></th><td><?= number_format($count) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php endif; ?>
    </div>
</section>
