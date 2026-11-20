<?php
session_start();
require_once __DIR__ . '/auth.php';
$view = $_GET['view'] ?? '';
$view = is_string($view) && in_array($view, ['dashboard', 'faculty', 'pds'], true) ? $view : 'dashboard';
$chartStyleNonce = $view === 'dashboard' ? base64_encode(random_bytes(18)) : '';
synk_hris_headers($chartStyleNonce);
[$conn, $account] = synk_hris_require_admin();
require __DIR__ . '/views/navigation.php';
$token = synk_hris_csrf();
session_write_close();

require_once __DIR__ . '/faculty-data.php';
require_once __DIR__ . '/../backend/user_avatar_helper.php';

$viewTitle = $views[$view]['title'];
$sectionTitles = array_column(synk_faculty_pds_preview_sections(), 'title', 'key');
$sectionCount = count(synk_hr_pds_sections());

$sources = synk_hris_faculty_sources($conn, $view === 'dashboard');
$unavailable = array_diff_key(['profiles' => 'Professor Profiles', 'pds' => 'PDS progress'], $sources);
$records = synk_hris_faculty_records($sources['profiles'] ?? [], $sources['pds'] ?? [], $sectionCount);
$pdsAvailable = !isset($unavailable['pds']);

$pdsStatuses = [
    'not-started' => ['Not started', 'neutral'],
    'in-progress' => ['In progress', 'blue'],
    'saved' => ['All sections saved', 'green'],
];

if ($view === 'dashboard') {
    $rankCounts = isset($sources['profiles']) ? synk_hris_rank_counts($sources['profiles'], $sources['degrees'] ?? []) : null;
    $employmentCounts = isset($sources['profiles']) ? synk_hris_employment_counts($sources['profiles']) : null;
    $unrankedCount = $rankCounts === null ? 0 : count($sources['profiles']) - array_sum(array_column($rankCounts, 'faculty'));
    $statusCounts = array_count_values(array_column($records, 'pds_status'));
    $recent = array_values(array_filter($records, static function (array $record): bool {
        return $record['last_saved'] !== null;
    }));
    usort($recent, static function (array $left, array $right): int {
        return strcmp($right['last_saved'], $left['last_saved']);
    });
    $metrics = [
        ['Faculty profiles', 'Saved Professor Profiles', 'bx-id-card', 'neutral',
            isset($sources['profiles']) ? count($sources['profiles']) : null, '?view=faculty&filter=saved-profile'],
        ['PDS started', 'At least one section saved', 'bx-file', 'blue',
            $pdsAvailable ? count($recent) : null, '?view=pds&filter=started'],
        ['All sections saved', $sectionCount . ' sections saved', 'bx-check-circle', 'green',
            $pdsAvailable ? ($statusCounts['saved'] ?? 0) : null, '?view=pds&filter=saved'],
        ['Employee IDs recorded', 'From the PDS or Professor Profile', 'bx-fingerprint', 'amber',
            !$unavailable ? count(array_filter(array_column($records, 'employee_id'), 'strlen')) : null, '?view=faculty&filter=has-id'],
    ];
    $followUps = [
        ['Missing employee ID', 'No ID saved in either source', 'bx-id-card', 'amber',
            '?view=faculty&filter=missing-id', !$unavailable ? count(array_filter($records, static function (array $record): bool {
                return $record['employee_id'] === '';
            })) : null],
        ['Employee ID conflicts', 'Different or duplicate IDs', 'bx-error-circle', 'neutral',
            '?view=faculty&filter=id-review', !$unavailable ? count(array_filter($records, static function (array $record): bool {
                return $record['id_mismatch'] || $record['duplicate_id'];
            })) : null],
        ['PDS link review', 'Drafts that need a faculty link checked', 'bx-link-alt', 'blue',
            '?view=pds&filter=link-review', $pdsAvailable ? count(array_filter(array_column($records, 'link_issue'))) : null],
    ];
} else {
    $trackingPds = $view === 'pds';
    $filters = $trackingPds ? [
        'all' => 'All PDS statuses', 'started' => 'PDS started', 'not-started' => 'Not started',
        'in-progress' => 'In progress', 'saved' => 'All sections saved', 'link-review' => 'PDS link review',
    ] : [
        'all' => 'All faculty records', 'saved-profile' => 'Saved Professor Profile', 'has-id' => 'Employee ID recorded',
        'missing-id' => 'Missing employee ID', 'id-review' => 'Employee ID conflicts',
    ];
    $filter = $_GET['filter'] ?? '';
    $filter = is_string($filter) && isset($filters[$filter]) ? $filter : 'all';
    $query = $_GET['q'] ?? '';
    $query = is_string($query) && strlen($query) <= 480 && preg_match('//u', $query) ? trim($query) : '';
    $matching = array_values(array_filter($records, static function (array $record) use ($query, $filter): bool {
        if ($query !== '') {
            $searchText = implode(' ', [$record['name'], $record['employee_id'], $record['profile_id_number']]);
            $position = function_exists('mb_stripos') ? mb_stripos($searchText, $query, 0, 'UTF-8') : stripos($searchText, $query);
            if ($position === false) return false;
        }
        switch ($filter) {
            case 'saved-profile': return $record['has_profile'];
            case 'has-id': return $record['employee_id'] !== '';
            case 'missing-id': return $record['employee_id'] === '';
            case 'id-review': return $record['id_mismatch'] || $record['duplicate_id'];
            case 'link-review': return $record['link_issue'] !== '';
            case 'started': return $record['pds_status'] !== 'not-started';
            case 'all': return true;
            default: return $record['pds_status'] === $filter;
        }
    }));
    $pageSize = 15;
    $pageCount = max(1, (int)ceil(count($matching) / $pageSize));
    $page = filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $page = min($page, $pageCount);
    $pageOffset = ($page - 1) * $pageSize;
    $visibleRecords = array_slice($matching, $pageOffset, $pageSize);
    $pageUrl = static function (int $number) use ($view, $query, $filter): string {
        return '?' . http_build_query(['view' => $view, 'q' => $query, 'filter' => $filter, 'page' => $number]);
    };
}
if ($view === 'faculty' && ($_GET['partial'] ?? '') === 'results') {
    if (!$sources) {
        http_response_code(503);
        exit;
    }
    require __DIR__ . '/views/directory-results.php';
    exit;
}
$pageTitle = $viewTitle;
require __DIR__ . '/views/layout-start.php';
?>
        <main id="main-content" class="hris-main" tabindex="-1">
            <?php if ($unavailable): ?>
                <p class="hris-notice" role="status"><i class="bx bx-info-circle" aria-hidden="true"></i>
                    <?= synk_hris_h(implode(' and ', $unavailable)) ?> could not be loaded. Refresh the page to try again.
                </p>
            <?php endif; ?>

            <?php if ($view === 'dashboard'): ?>
                <section class="hris-hero" aria-labelledby="dashboard-title">
                    <div>
                        <h1 id="dashboard-title">Human Resources Dashboard</h1>
                        <p>Review faculty profiles and track Personal Data Sheets saved in Synk.</p>
                        <div class="hris-hero-actions">
                            <a class="hris-action hris-action-primary" href="?view=pds"><i class="bx bx-file" aria-hidden="true"></i> Open PDS tracker</a>
                            <a class="hris-action" href="?view=faculty">Browse faculty <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>
                        </div>
                    </div>
                    <div class="hris-hero-art" aria-hidden="true">
                        <span class="hris-hero-document"><i class="bx bx-file"></i></span>
                    </div>
                </section>

                <section class="hris-metrics" aria-label="Faculty record counts">
                    <?php foreach ($metrics as [$label, $description, $icon, $tone, $count, $url]): ?>
                        <article class="hris-card hris-metric hris-tone-<?= $tone ?>">
                            <span class="hris-icon"><i class="bx <?= $icon ?>" aria-hidden="true"></i></span>
                            <div class="hris-metric-label">
                                <h2>
                                    <?php if ($count === null): ?><?= synk_hris_h($label) ?>
                                    <?php else: ?><a href="<?= synk_hris_h($url) ?>"><?= synk_hris_h($label) ?></a><?php endif; ?>
                                </h2>
                                <p><?= $count === null ? 'Data unavailable' : synk_hris_h($description) ?></p>
                            </div>
                            <strong><?= $count === null ? '—' : number_format($count) ?></strong>
                        </article>
                    <?php endforeach; ?>
                </section>

                <?php require __DIR__ . '/views/faculty-charts.php'; ?>

                <div class="hris-dashboard-columns">
                    <section class="hris-card hris-panel" aria-labelledby="pds-status-title">
                        <div class="hris-panel-heading">
                            <div><h2 id="pds-status-title">PDS status</h2><p>Across <?= number_format(count($records)) ?> faculty records with saved data</p></div>
                            <span class="hris-icon hris-tone-blue"><i class="bx bx-bar-chart-alt-2" aria-hidden="true"></i></span>
                        </div>
                        <?php if ($pdsAvailable): ?>
                            <ul class="hris-progress-list">
                                <?php foreach ($pdsStatuses as $key => [$label, $tone]): $count = $statusCounts[$key] ?? 0; ?>
                                    <li>
                                        <div><a href="?view=pds&amp;filter=<?= $key ?>"><?= synk_hris_h($label) ?></a><strong><?= number_format($count) ?></strong></div>
                                        <progress class="hris-progress hris-progress-<?= $tone ?>" max="<?= max(1, count($records)) ?>" value="<?= $count ?>" aria-label="<?= synk_hris_h($label) ?>"></progress>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="hris-note">Progress reflects faculty drafts saved in the Professor Portal.</p>
                        <?php else: ?><p class="hris-empty">PDS status is unavailable.</p><?php endif; ?>
                    </section>

                    <section class="hris-card hris-panel" aria-labelledby="follow-up-title">
                        <div class="hris-panel-heading"><div><h2 id="follow-up-title">Needs follow-up</h2><p>Employee IDs and PDS links to review</p></div></div>
                        <ul class="hris-follow-ups">
                            <?php foreach ($followUps as [$label, $description, $icon, $tone, $url, $count]): ?>
                                <li>
                                    <a href="<?= synk_hris_h($url) ?>">
                                        <span class="hris-icon hris-tone-<?= $tone ?>"><i class="bx <?= $icon ?>" aria-hidden="true"></i></span>
                                        <span><strong><?= synk_hris_h($label) ?></strong><small><?= synk_hris_h($description) ?></small></span>
                                        <span class="hris-issue-count"><?= $count === null ? '—' : number_format($count) ?></span>
                                        <i class="bx bx-chevron-right" aria-hidden="true"></i>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                </div>

                <section class="hris-card hris-panel" aria-labelledby="recent-pds-title">
                    <div class="hris-panel-heading">
                        <div><h2 id="recent-pds-title">Recently saved PDS</h2><p>Latest section saves from the Professor Portal</p></div>
                        <a class="hris-text-link" href="?view=pds">View tracker <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>
                    </div>
                    <?php if ($recent): ?>
                        <ul class="hris-recent-list">
                            <?php foreach (array_slice($recent, 0, 5) as $record): ?>
                                <li>
                                    <span class="hris-icon hris-tone-blue"><i class="bx bx-file" aria-hidden="true"></i></span>
                                    <span><strong><?= synk_hris_h($record['name']) ?></strong><small><?= $record['employee_id'] !== '' ? synk_hris_h($record['employee_id']) : 'Employee ID missing' ?></small></span>
                                    <span class="hris-recent-detail"><strong><?= count($record['saved_sections']) ?> of <?= $sectionCount ?> sections saved</strong>
                                        <time datetime="<?= synk_hris_h(str_replace(' ', 'T', $record['last_saved'])) ?>"><?= synk_hris_h(date('M j, Y · g:i A', strtotime($record['last_saved']))) ?></time>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?><p class="hris-empty"><?= $pdsAvailable ? 'Recent saves will appear here as faculty save their PDS sections.' : 'Recent saves are unavailable.' ?></p><?php endif; ?>
                </section>
            <?php else: ?>
                <header class="hris-view-heading">
                    <h1><?= synk_hris_h($viewTitle) ?></h1>
                    <p><?= $trackingPds ? 'Track saved PDS sections. Faculty update their entries in the Synk Professor Portal.' : 'Browse faculty with a saved Professor Profile or Personal Data Sheet.' ?></p>
                </header>
                <section class="hris-card hris-directory" aria-label="<?= synk_hris_h($viewTitle) ?> results">
                    <?php if (($trackingPds && !$pdsAvailable) || !$sources): ?>
                        <p class="hris-empty"><?= $trackingPds ? 'PDS records are unavailable.' : 'Faculty records are unavailable.' ?></p>
                    <?php else: ?>
                        <form class="hris-filters<?= $trackingPds ? '' : ' hris-faculty-search' ?>" method="get" action="./" role="search" aria-label="Search faculty records"<?= $trackingPds ? '' : ' data-live-faculty-search' ?>>
                            <input type="hidden" name="view" value="<?= $view ?>">
                            <label for="faculty-search">Find a faculty member
                                <input id="faculty-search" type="search" name="q" maxlength="120" value="<?= synk_hris_h($query) ?>" placeholder="Name or employee ID"<?= $trackingPds ? '' : ' aria-controls="directory-results"' ?>>
                            </label>
                            <?php if ($trackingPds): ?>
                                <label for="faculty-filter">Filter records<select id="faculty-filter" name="filter">
                                    <?php foreach ($filters as $key => $label): ?>
                                        <option value="<?= $key ?>"<?= $key === $filter ? ' selected' : '' ?>><?= synk_hris_h($label) ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                                <button class="hris-action hris-action-primary" type="submit"><i class="bx bx-search" aria-hidden="true"></i> Search</button>
                                <?php if ($query !== '' || $filter !== 'all'): ?><a class="hris-text-link hris-clear" href="?view=<?= $view ?>">Clear filters</a><?php endif; ?>
                            <?php elseif ($filter !== 'all'): ?>
                                <input type="hidden" name="filter" value="<?= synk_hris_h($filter) ?>">
                                <div class="hris-faculty-search-context">
                                    <span><?= synk_hris_h($filters[$filter]) ?></span>
                                    <a class="hris-text-link" href="./?view=faculty">Show all faculty</a>
                                </div>
                            <?php endif; ?>
                        </form>
                        <?php if (!$trackingPds): ?>
                            <p class="hris-faculty-search-announcement" data-faculty-search-announcement role="status"></p>
                            <p class="hris-empty" data-faculty-search-error role="alert" hidden>Faculty search could not refresh. Try again.</p>
                        <?php endif; ?>
                        <?php require __DIR__ . '/views/directory-results.php'; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
<?php require __DIR__ . '/views/layout-end.php'; ?>
