<?php
session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();
[$conn, $account] = synk_hris_require_account();
require __DIR__ . '/views/navigation.php';
$requestedView = $_GET['view'] ?? '';
$view = is_string($requestedView) && isset($viewPermissions[$requestedView]) ? $requestedView : (key($views) ?: 'workspace');
if ($view !== 'workspace' && !isset($views[$view])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('This page is not assigned to your Kawani account.');
}
if ($view === 'workspace' && $canManageAccounts) {
    header('Location: accounts.php', true, 302);
    exit;
}
if ($view === 'dashboard') {
    $chartStyleNonce = base64_encode(random_bytes(18));
    synk_hris_headers($chartStyleNonce);
}
$canSeeProfiles = isset($views['faculty']);
$canSeePdsTracker = isset($views['pds']);
$token = synk_hris_csrf();
$flash = null;
if (($_GET['partial'] ?? '') === '' && !empty($_SESSION['hris_account_flash']['workspace'])) {
    $flash = $_SESSION['hris_account_flash'];
    unset($_SESSION['hris_account_flash']);
}
$facultyFlash = null;
if ($view === 'faculty' && ($_GET['partial'] ?? '') !== 'results') {
    $facultyFlash = !empty($_SESSION['hris_employee_registered'])
        ? ['kind' => 'success', 'message' => 'Employee added to Employee Profiles.']
        : ($_SESSION['hris_faculty_status_flash'] ?? null);
    if (!empty($_SESSION['hris_personnel_saved'])) {
        $facultyFlash = ['kind' => 'success', 'message' => 'Personnel details saved.'];
    }
    if (!empty($_SESSION['hris_employee_deleted'])) {
        $facultyFlash = ['kind' => 'success', 'message' => 'Employee deleted from Employee Profiles.'];
    }
    unset($_SESSION['hris_employee_registered'], $_SESSION['hris_faculty_status_flash'], $_SESSION['hris_personnel_saved'], $_SESSION['hris_employee_deleted']);
}
session_write_close();

require_once __DIR__ . '/../backend/user_avatar_helper.php';
if ($view === 'workspace') {
    $pageTitle = 'Human Resources';
    require __DIR__ . '/views/layout-start.php';
    ?>
    <main id="main-content" class="hris-main" tabindex="-1">
        <?php require __DIR__ . '/views/account-alert.php'; ?>
        <section class="hris-hero">
            <div>
                <h1>Human Resources</h1>
                <?php if ($canManagePositions): ?>
                    <p>Manage non-teaching positions for Kawani.</p>
                    <div class="hris-hero-actions">
                        <button type="button" class="hris-action hris-action-primary" data-open-position-catalog aria-haspopup="dialog" aria-controls="position-catalog">Open Position Catalog</button>
                    </div>
                <?php else: ?>
                    <p>Your account has no tools assigned. Contact your Kawani administrator to request access.</p>
                <?php endif; ?>
            </div>
        </section>
    <?php
    require __DIR__ . '/views/layout-end.php';
    exit;
}
require_once __DIR__ . '/faculty-data.php';

$viewTitle = $views[$view]['title'];
$sectionTitles = array_column(synk_faculty_pds_preview_sections(), 'title', 'key');
$sectionCount = count(synk_hr_pds_sections());

$sources = synk_hris_faculty_sources($conn, $view === 'dashboard', $view === 'faculty');
$unavailable = array_diff_key(['profiles' => 'Professor Profiles', 'pds' => 'PDS progress'], $sources);
$records = synk_hris_faculty_records($sources['profiles'] ?? [], $sources['pds'] ?? [], $sectionCount);
if ($view === 'faculty') {
    synk_hris_format_directory_names($records, $sources['pds'] ?? [], $sources['faculty_names'] ?? []);
}
require_once __DIR__ . '/faculty-status-data.php';
$facultyStatusOptions = synk_hris_faculty_statuses();
$requestedFilter = is_string($_GET['filter'] ?? null) ? $_GET['filter'] : '';
$facultyStatuses = null;
if ($view !== 'pds' || $requestedFilter === 'active' || strpos($requestedFilter, 'active-') === 0) {
    try {
        $facultyStatuses = synk_hris_load_faculty_statuses($conn);
    } catch (mysqli_sql_exception $error) {
        error_log('Kawani could not load faculty statuses. Database error ' . $error->getCode());
    }
}
$personnelDetails = $positionCatalog = null;
if ($view !== 'pds') {
    require_once __DIR__ . '/personnel-data.php';
    $personnelCategories = synk_hris_personnel_categories();
    try {
        $personnelDetails = synk_hris_load_personnel_details($conn);
        if ($view === 'faculty' && $canManageEmployees && $personnelDetails !== null) $positionCatalog = synk_hris_position_catalog($conn);
    } catch (mysqli_sql_exception $error) {
        error_log('Kawani could not load personnel details. Database error ' . $error->getCode());
    }
}
$pdsAvailable = !isset($unavailable['pds']);
$directoryAvailable = isset($sources['profiles']) || $pdsAvailable;

$pdsStatuses = [
    'not-started' => ['Not started', 'neutral'],
    'in-progress' => ['In progress', 'blue'],
    'saved' => ['All sections saved', 'green'],
];

if ($view === 'dashboard') {
    $analyticsAvailable = !$unavailable && $facultyStatuses !== null;
    $directory = array_column($records, null, 'owner');
    $directoryStatuses = $analyticsAvailable ? array_intersect_key($facultyStatuses, $directory) : [];
    $employeeCounts = array_count_values(array_column($directoryStatuses, 'status'));
    $activeStatuses = array_filter($directoryStatuses, static function (array $review): bool {
        return $review['status'] === 'active';
    });
    $activeRecords = array_intersect_key($directory, $activeStatuses);
    $activeCount = count($activeRecords);
    $activeProfiles = array_intersect_key($sources['profiles'] ?? [], $activeRecords);
    $nonTeaching = array_filter($personnelDetails ?? [], static function (array $details): bool {
        return $details['personnel_category'] === 'non_teaching';
    });
    $rankProfiles = array_diff_key($activeProfiles, $nonTeaching);
    $rankCounts = $analyticsAvailable ? synk_hris_rank_counts($rankProfiles, $sources['degrees'] ?? []) : null;
    $employmentProfiles = $activeProfiles + array_fill_keys(array_keys($activeRecords), []);
    $employmentCounts = $analyticsAvailable ? synk_hris_employment_counts($employmentProfiles) : null;
    $unrankedCount = $rankCounts === null ? 0 : count($rankProfiles) - array_sum(array_column($rankCounts, 'faculty'));
    $statusCounts = array_count_values(array_column($activeRecords, 'pds_status'));
    $recent = array_values(array_filter($activeRecords, static function (array $record): bool {
        return $record['last_saved'] !== null;
    }));
    usort($recent, static function (array $left, array $right): int {
        return strcmp($right['last_saved'], $left['last_saved']);
    });
    $employeeMetrics = [
        'active' => ['Verified by HR', 'bx-check-shield'],
        'enactive' => ['Inactive per HR records', 'bx-pause-circle'],
        'retired' => ['Confirmed by HR', 'bx-time-five'],
    ];
    $metrics = [];
    foreach ($employeeMetrics as $status => [$description, $icon]) {
        [$label, $tone] = $facultyStatusOptions[$status];
        $count = $analyticsAvailable ? ($employeeCounts[$status] ?? 0) : null;
        $metrics[] = [$label . ' Employee', $description, $icon, $tone, $count, '?view=faculty&filter=' . $status];
    }
    $metrics[] = ['Total Employee', 'Active, Enactive, and Retired', 'bx-group', 'neutral',
        $analyticsAvailable ? array_sum(array_intersect_key($employeeCounts, $employeeMetrics)) : null, '?view=faculty&filter=reviewed'];
    $followUps = [
        ['Missing employee ID', 'No ID saved in either source', 'bx-id-card', 'amber', 'faculty',
            '?view=faculty&filter=active-missing-id', $analyticsAvailable ? count(array_filter($activeRecords, static function (array $record): bool {
                return $record['employee_id'] === '';
            })) : null],
        ['Employee ID conflicts', 'Different or duplicate IDs', 'bx-error-circle', 'neutral', 'faculty',
            '?view=faculty&filter=active-id-review', $analyticsAvailable ? count(array_filter($activeRecords, static function (array $record): bool {
                return $record['id_mismatch'] || $record['duplicate_id'];
            })) : null],
        ['PDS link review', 'Drafts that need a faculty link checked', 'bx-link-alt', 'blue', 'pds',
            '?view=pds&filter=active-link-review', $analyticsAvailable ? count(array_filter(array_column($activeRecords, 'link_issue'))) : null],
    ];
} else {
    $trackingPds = $view === 'pds';
    $filters = $trackingPds ? [
        'all' => 'All PDS statuses', 'started' => 'PDS started', 'not-started' => 'Not started',
        'in-progress' => 'In progress', 'saved' => 'All sections saved', 'link-review' => 'PDS link review',
    ] : [
        'all' => 'All employees', 'saved-profile' => 'Saved Professor Profile', 'has-id' => 'Employee ID recorded',
        'missing-id' => 'Missing employee ID', 'id-review' => 'Employee ID conflicts',
    ];
    if ($trackingPds) {
        $filters['active'] = 'Active employees';
        $activeFilters = ['not-started', 'in-progress', 'saved', 'link-review'];
    } else {
        foreach ($facultyStatusOptions as $status => [$label]) {
            $filters[$status] = $label . ' Employee';
        }
        $filters['reviewed'] = 'Employees with an HR status';
        $activeFilters = ['missing-id', 'id-review'];
    }
    foreach ($activeFilters as $key) {
        $filters['active-' . $key] = $filters[$key] . ' · Active employees';
    }
    $filter = isset($filters[$requestedFilter]) ? $requestedFilter : 'all';
    $recordFilter = $filter;
    $requiredStatus = '';
    if (strpos($filter, 'active-') === 0) {
        $recordFilter = substr($filter, strlen('active-'));
        $requiredStatus = 'active';
    } elseif (isset($facultyStatusOptions[$filter])) {
        $recordFilter = 'all';
        $requiredStatus = $filter;
    }
    $needsHrStatus = $requiredStatus !== '' || $filter === 'reviewed';
    $category = $_GET['category'] ?? '';
    $category = !$trackingPds && is_string($category) && isset($personnelCategories[$category]) ? $category : 'all';
    $resultsAvailable = ($trackingPds ? $pdsAvailable : $directoryAvailable)
        && (!$needsHrStatus || $facultyStatuses !== null) && ($category === 'all' || $personnelDetails !== null);
    $query = $_GET['q'] ?? '';
    $query = is_string($query) && strlen($query) <= 480 && preg_match('//u', $query) ? trim($query) : '';
    $matching = array_values(array_filter($records, static function (array $record) use ($query, $recordFilter,
        $requiredStatus, $facultyStatuses, $facultyStatusOptions, $category, $personnelDetails): bool {
        if ($category !== 'all' && ($personnelDetails[$record['owner']]['personnel_category'] ?? '') !== $category) return false;
        $status = $facultyStatuses[$record['owner']]['status'] ?? '';
        if ($requiredStatus !== '' && $status !== $requiredStatus) return false;
        if ($query !== '') {
            $searchText = implode(' ', [$record['name'], $record['directory_name'] ?? '', $record['employee_id'], $record['profile_id_number']]);
            $position = function_exists('mb_stripos') ? mb_stripos($searchText, $query, 0, 'UTF-8') : stripos($searchText, $query);
            if ($position === false) return false;
        }
        switch ($recordFilter) {
            case 'reviewed': return isset($facultyStatusOptions[$status]);
            case 'saved-profile': return $record['has_profile'];
            case 'has-id': return $record['employee_id'] !== '';
            case 'missing-id': return $record['employee_id'] === '';
            case 'id-review': return $record['id_mismatch'] || $record['duplicate_id'];
            case 'link-review': return $record['link_issue'] !== '';
            case 'started': return $record['pds_status'] !== 'not-started';
            case 'all': return true;
            default: return $record['pds_status'] === $recordFilter;
        }
    }));
    $pageSize = 15;
    $pageCount = max(1, (int)ceil(count($matching) / $pageSize));
    $page = filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $page = min($page, $pageCount);
    $pageOffset = ($page - 1) * $pageSize;
    $visibleRecords = array_slice($matching, $pageOffset, $pageSize);
    $pageUrl = static function (int $number) use ($view, $query, $filter, $category): string {
        $params = ['view' => $view, 'q' => $query, 'filter' => $filter, 'page' => $number];
        if ($category !== 'all') $params['category'] = $category;
        return '?' . http_build_query($params);
    };
}
if ($view === 'faculty' && ($_GET['partial'] ?? '') === 'results') {
    if (!$resultsAvailable) {
        http_response_code(503);
        exit;
    }
    require __DIR__ . '/views/directory-results.php';
    exit;
}
$employeeFields = [];
if ($view === 'faculty' && $canManageEmployees) {
    require_once __DIR__ . '/employee-data.php';
    try {
        if ($positionCatalog !== null && synk_faculty_record_profiles_ready($conn)) {
            [$employeeRanks, $employeeLocations] = synk_hris_employee_catalog($conn);
            $employeeFields = synk_hris_employee_fields($employeeRanks, $employeeLocations);
            if (!synk_hris_employee_accounts_ready($conn)) unset($employeeFields['official_email']);
        }
    } catch (mysqli_sql_exception $error) {
        error_log('Kawani could not load employee registration options. Database error ' . $error->getCode());
    }
}
$pageTitle = $viewTitle;
require __DIR__ . '/views/layout-start.php';
?>
        <main id="main-content" class="hris-main" tabindex="-1">
            <?php require __DIR__ . '/views/account-alert.php'; ?>
            <?php if ($unavailable): ?>
                <p class="hris-notice" role="status"><i class="bx bx-info-circle" aria-hidden="true"></i>
                    <?= synk_hris_h(implode(' and ', $unavailable)) ?> could not be loaded. Refresh the page to try again.
                </p>
            <?php endif; ?>

            <?php if ($view === 'dashboard'): ?>
                <section class="hris-hero" aria-labelledby="dashboard-title">
                    <div>
                        <h1 id="dashboard-title">Human Resources Dashboard</h1>
                        <p>Review employee totals, employment trends, and PDS progress.</p>
                        <?php if ($canSeePdsTracker || $canSeeProfiles): ?>
                            <div class="hris-hero-actions">
                                <?php if ($canSeePdsTracker): ?><a class="hris-action hris-action-primary" href="?view=pds"><i class="bx bx-file" aria-hidden="true"></i> Open PDS tracker</a><?php endif; ?>
                                <?php if ($canSeeProfiles): ?><a class="hris-action" href="?view=faculty">Browse employees <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="hris-hero-art" aria-hidden="true">
                        <span class="hris-hero-document"><i class="bx bx-file"></i></span>
                    </div>
                </section>

                <section class="hris-metrics" aria-label="Employee counts">
                    <?php foreach ($metrics as [$label, $description, $icon, $tone, $count, $url]): ?>
                        <article class="hris-card hris-metric hris-tone-<?= $tone ?>">
                            <span class="hris-icon"><i class="bx <?= $icon ?>" aria-hidden="true"></i></span>
                            <div class="hris-metric-label">
                                <h2>
                                    <?php if ($count === null || !$canSeeProfiles): ?><?= synk_hris_h($label) ?>
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
                            <div><h2 id="pds-status-title">PDS status</h2>
                                <?php if ($analyticsAvailable): ?><p>Across <?= number_format($activeCount) ?> active <?= $activeCount === 1 ? 'employee' : 'employees' ?></p><?php endif; ?>
                            </div>
                            <span class="hris-icon hris-tone-blue"><i class="bx bx-bar-chart-alt-2" aria-hidden="true"></i></span>
                        </div>
                        <?php if ($analyticsAvailable): ?>
                            <ul class="hris-progress-list">
                                <?php foreach ($pdsStatuses as $key => [$label, $tone]): $count = $statusCounts[$key] ?? 0; ?>
                                    <li>
                                        <div>
                                            <?php if ($canSeePdsTracker): ?><a href="?view=pds&amp;filter=active-<?= $key ?>"><?= synk_hris_h($label) ?></a>
                                            <?php else: ?><span><?= synk_hris_h($label) ?></span><?php endif; ?>
                                            <strong><?= number_format($count) ?></strong>
                                        </div>
                                        <progress class="hris-progress hris-progress-<?= $tone ?>" max="<?= max(1, $activeCount) ?>" value="<?= $count ?>" aria-label="<?= synk_hris_h($label) ?>"></progress>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="hris-note">Progress reflects faculty drafts saved in the Professor Portal.</p>
                        <?php else: ?><p class="hris-empty">PDS status is unavailable.</p><?php endif; ?>
                    </section>

                    <?php if ($canSeeProfiles || $canSeePdsTracker): ?>
                    <section class="hris-card hris-panel" aria-labelledby="follow-up-title">
                        <div class="hris-panel-heading"><div><h2 id="follow-up-title">Needs follow-up</h2><p>ID and PDS link checks for active employees</p></div></div>
                        <ul class="hris-follow-ups">
                            <?php foreach ($followUps as [$label, $description, $icon, $tone, $targetView, $url, $count]): ?>
                                <?php if (!isset($views[$targetView])) continue; ?>
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
                    <?php endif; ?>
                </div>

                <?php if ($canSeePdsTracker): ?>
                <section class="hris-card hris-panel" aria-labelledby="recent-pds-title">
                    <div class="hris-panel-heading">
                        <div><h2 id="recent-pds-title">Recently saved PDS</h2><p>Latest section saves by active employees</p></div>
                        <a class="hris-text-link" href="?view=pds&amp;filter=active">View tracker <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>
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
                    <?php else: ?><p class="hris-empty"><?= $analyticsAvailable ? 'No PDS saves from active employees yet.' : 'Recent saves are unavailable.' ?></p><?php endif; ?>
                </section>
                <?php endif; ?>
            <?php else: ?>
                <header class="hris-view-heading">
                    <div class="hris-view-heading-row">
                        <h1><?= synk_hris_h($viewTitle) ?></h1>
                        <?php if (!$trackingPds && $canManageEmployees): ?>
                            <div class="hris-directory-actions">
                                <button type="button" class="hris-action hris-action-primary" data-new-employee aria-haspopup="dialog"<?= $employeeFields ? '' : ' disabled' ?>>
                                    <i class="bx bx-user-plus" aria-hidden="true"></i> New Employee
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <p><?= $trackingPds ? 'Track saved PDS sections. Faculty update their entries in the Synk Professor Portal.' : 'Browse teaching and non-teaching personnel.' ?></p>
                </header>
                <?php if ($facultyFlash): ?>
                    <?php $success = $facultyFlash['kind'] === 'success'; ?>
                    <div class="hris-account-alert hris-account-alert-<?= $success ? 'success' : 'error' ?>" role="<?= $success ? 'status' : 'alert' ?>">
                        <i class="bx <?= $success ? 'bx-check-circle' : 'bx-error-circle' ?>" aria-hidden="true"></i>
                        <span><?= synk_hris_h($facultyFlash['message']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!$trackingPds && $canManageEmployees && !$employeeFields): ?>
                    <p class="hris-notice" role="status"><i class="bx bx-info-circle" aria-hidden="true"></i> New employee registration is unavailable.</p>
                <?php endif; ?>
                <?php if ($facultyStatuses === null && ($needsHrStatus || (!$trackingPds && $directoryAvailable))): ?>
                    <p class="hris-notice" role="status"><i class="bx bx-info-circle" aria-hidden="true"></i> HR status is unavailable.</p>
                <?php endif; ?>
                <section class="hris-card hris-directory" aria-label="<?= synk_hris_h($viewTitle) ?> results">
                    <?php if (!$resultsAvailable): ?>
                        <p class="hris-empty"><?= $trackingPds ? 'PDS records are unavailable.' : 'Employee records are unavailable for this view.' ?>
                            <?php if ($category !== 'all'): ?><a class="hris-text-link" href="./?view=faculty">Show all employees</a><?php endif; ?>
                        </p>
                    <?php else: ?>
                        <form class="hris-filters<?= $trackingPds ? '' : ' hris-faculty-search' ?>" method="get" action="./" role="search" aria-label="Search employee records"<?= $trackingPds ? '' : ' data-live-faculty-search' ?>>
                            <input type="hidden" name="view" value="<?= $view ?>">
                            <?php if ($category !== 'all'): ?><input type="hidden" name="category" value="<?= synk_hris_h($category) ?>"><?php endif; ?>
                            <label for="faculty-search">Find an employee
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
                                    <a class="hris-text-link" href="<?= synk_hris_h('./?' . http_build_query(['view' => 'faculty', 'category' => $category, 'q' => $query])) ?>">Clear status or record filter</a>
                                </div>
                            <?php endif; ?>
                        </form>
                        <?php if (!$trackingPds): ?>
                            <p class="hris-faculty-search-announcement" data-faculty-search-announcement role="status"></p>
                            <p class="hris-empty" data-faculty-search-error role="alert" hidden>Employee search could not refresh. Try again.</p>
                        <?php endif; ?>
                        <?php require __DIR__ . '/views/directory-results.php'; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
<?php if ($view === 'faculty'): ?>
    <?php if ($resultsAvailable): ?>
        <div class="hris-directory-feedback" role="status" data-faculty-save-feedback>
            <div class="hris-account-alert hris-account-alert-success" hidden>
                <i class="bx bx-check-circle" aria-hidden="true"></i><span></span>
            </div>
        </div>
        <?php if ($canDeleteEmployees) require __DIR__ . '/views/delete-employee-dialog.php'; ?>
    <?php endif; ?>
    <?php if ($employeeFields) require __DIR__ . '/views/new-employee-dialog.php'; ?>
    <?php if ($positionCatalog !== null && $directoryAvailable) require __DIR__ . '/views/employee-personnel-dialog.php'; ?>
    <?php if ($canManageEmployees && $facultyStatuses !== null && $directoryAvailable) require __DIR__ . '/views/faculty-status-dialog.php'; ?>
    <?php if ($canManageEmployees) require __DIR__ . '/views/work-experience.php'; ?>
<?php endif; ?>
<?php require __DIR__ . '/views/layout-end.php'; ?>
