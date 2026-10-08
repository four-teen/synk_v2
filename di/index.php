<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../backend/di_profile_analytics_helper.php';

$moduleIcon = 'bx-book-open';
$dashboardError = false;
try {
    $dashboard = synk_di_profile_dashboard($conn, $moduleTerm, (int)($_GET['campus_id'] ?? 0), (int)($_GET['college_id'] ?? 0));
} catch (Throwable $exception) {
    error_log('DI faculty analytics failed: ' . $exception->getMessage());
    http_response_code(503);
    $dashboardError = true;
}
$statusLabels = ['complete' => 'Complete', 'in_progress' => 'In progress', 'not_started' => 'Not started', 'unavailable' => 'Unavailable'];
$statusColors = ['complete' => 'success', 'in_progress' => 'warning', 'not_started' => 'secondary', 'unavailable' => 'danger'];
$chartData = [];
if (!$dashboardError) {
    $summary = $dashboard['summary'];
    $chartData = [
        'completion' => [
            'Complete' => $summary['complete'], '25%+ in progress' => $summary['qualified_in_progress'],
            'Started below 25%' => $summary['started_below_25'], 'Not started' => $summary['not_started'],
            'Unavailable' => $summary['unavailable'],
        ],
        'employment' => $summary['employment'], 'education' => $summary['education'], 'ranks' => $summary['ranks'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>DI Faculty Profile Analytics | Synk</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />
    <link rel="stylesheet" href="custom_css.css?v=<?= filemtime(__DIR__ . '/custom_css.css') ?>" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
  </head>
  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="layout-page">
          <?php include __DIR__ . '/navbar.php'; ?>
          <div class="content-wrapper">
            <main class="container-xxl flex-grow-1 container-p-y">
              <div class="card di-hero mb-4">
                <div class="card-body d-flex align-items-center justify-content-between gap-3 p-4">
                  <div class="di-hero-copy">
                    <span class="badge bg-label-primary mb-3">DI · Director for Instruction</span>
                    <h1 class="di-page-heading">Faculty Profile Analytics</h1>
                    <p class="mb-3">Welcome back, <strong><?= synk_leadership_h($moduleAccount['username']) ?></strong>. Monitor faculty profile coverage, academic qualifications, and employment across the university.</p>
                    <span class="badge bg-label-info"><?= synk_leadership_h($dashboardError ? 'All campuses' : $dashboard['scope_label']) ?></span>
                    <small class="text-muted d-block mt-2">Profiles reflect the latest information saved by professors.</small>
                  </div>
                  <img src="../assets/img/illustrations/man-with-laptop-light.png" alt="" width="165" class="d-none d-lg-block" />
                </div>
              </div>
              <?php if ($dashboardError): ?>
                <div class="alert alert-warning" role="alert">Faculty profile analytics are temporarily unavailable. Please try again or contact your administrator.</div>
              <?php else: ?>
                <?php if (!$dashboard['profiles_available']): ?>
                  <div class="alert alert-warning" role="alert">Faculty profiles are unavailable. Ask your administrator to check the faculty profile setup.</div>
                <?php endif; ?>
                <div class="card mb-4"><div class="card-body">
                  <form action="index.php" method="get" class="di-filters" aria-label="Filter faculty analytics">
                    <div class="di-filter-field"><label class="form-label" for="campusFilter">Campus</label>
                      <select name="campus_id" id="campusFilter" class="form-select"><option value="0">All campuses</option>
                        <?php foreach ($dashboard['campuses'] as $id => $campus): ?><option value="<?= $id ?>" <?= $dashboard['campus_id'] === $id ? 'selected' : '' ?>><?= synk_leadership_h($campus['campus_name']) ?></option><?php endforeach; ?>
                      </select>
                    </div>
                    <div class="di-filter-field"><label class="form-label" for="collegeFilter">College</label>
                      <select name="college_id" id="collegeFilter" class="form-select"><option value="0">All colleges</option>
                        <?php foreach ($dashboard['colleges'] as $id => $college): ?><option value="<?= $id ?>" data-campus="<?= (int)$college['campus_id'] ?>" <?= $dashboard['college_id'] === $id ? 'selected' : '' ?> <?= $dashboard['campus_id'] > 0 && (int)$college['campus_id'] !== $dashboard['campus_id'] ? 'hidden disabled' : '' ?>><?= synk_leadership_h($college['college_code'] . ' - ' . $college['college_name']) ?></option><?php endforeach; ?>
                      </select>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bx bx-filter-alt me-1"></i>Apply Filters</button>
                    <a href="index.php" class="btn btn-outline-secondary">Reset</a>
                  </form>
                  <p class="di-scope-note">Analytics use profiles at 25% completion or higher. Started profiles below 25% remain visible in the register. Campus and college filters use recorded affiliations. <a href="professors.php">Browse profiles at 25% or higher</a>.</p>
                </div></div>
                <div class="row g-4 mb-4" aria-label="Faculty profile summary">
                  <?php foreach ([
                    ['Professor Accounts', $summary['accounts'], $summary['linked'] . ' linked to a faculty record', 'bx-user-voice', 'info'],
                    ['Profiles at 25%+', $summary['qualified_25'], $summary['coverage_percent'] . '% of active professor accounts meet the coverage threshold', 'bx-id-card', 'success'],
                    ['Started below 25%', $summary['started_below_25'], 'Shown in the profile register; detailed analytics use the 25%+ cohort', 'bx-user-check', 'warning'],
                  ] as $metric): ?>
                    <div class="col-6 col-xl-4"><div class="card di-kpi"><div class="card-body">
                      <div class="avatar"><span class="avatar-initial rounded bg-label-<?= $metric[4] ?>"><i class="bx <?= $metric[3] ?>"></i></span></div>
                      <p class="di-kpi-label"><?= synk_leadership_h($metric[0]) ?></p>
                      <div class="di-kpi-number"><?= number_format($metric[1]) ?></div>
                      <p class="di-kpi-note"><?= synk_leadership_h($metric[2]) ?></p>
                    </div></div></div>
                  <?php endforeach; ?>
                </div>
                <section id="coverage" class="row g-4 mb-4" aria-label="Profile coverage">
                  <div class="col-lg-5"><div class="card h-100">
                    <div class="card-header"><h2 class="di-section-heading">Profile Completion</h2><small class="text-muted">25%+ profiles, with started profiles shown separately</small></div>
                    <div id="completionChart" class="di-chart" role="img" aria-label="<?= synk_leadership_h($summary['complete'] . ' complete, ' . $summary['qualified_in_progress'] . ' at 25% or higher in progress, ' . $summary['started_below_25'] . ' started below 25%, ' . $summary['not_started'] . ' not started') ?>"><div class="di-chart-empty"><i class="bx bx-pie-chart-alt-2"></i><span><?= $summary['accounts'] ? 'Loading profile completion' : 'No professor accounts in this scope.' ?></span></div></div>
                    <div class="di-chart-summary">Average among profiles at 25%+: <strong><?= $summary['average_completion'] ?>%</strong>. <?= $summary['started_below_25'] ?> profile<?= $summary['started_below_25'] === 1 ? ' has' : 's have' ?> started below 25%; <?= $summary['not_started'] ?> account<?= $summary['not_started'] === 1 ? ' has' : 's have' ?> not started.</div>
                  </div></div>
                  <div class="col-lg-7"><div class="card h-100">
                    <div class="card-header"><h2 class="di-section-heading">Profile Fields Recorded</h2><small class="text-muted">Completion follows the professor profile form, including “Not applicable” education choices.</small></div>
                    <div class="card-body">
                      <p class="small text-muted mb-3">Based on profiles at 25% completion or higher.</p>
                      <?php foreach (['personal' => 'Personal Details', 'employment' => 'Employment', 'education' => 'Education'] as $key => $label): $section = $summary['sections'][$key]; $percent = $section['total'] ? (int)round($section['completed'] * 100 / $section['total']) : 0; ?>
                        <div class="di-completion-row"><div class="d-flex justify-content-between gap-3 mb-2"><span class="fw-semibold"><?= $label ?></span><strong><?= $percent ?>%</strong></div>
                          <div class="progress di-progress" role="progressbar" aria-label="<?= $label ?> fields recorded" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-primary" style="width:<?= $percent ?>%"></div></div>
                          <small class="text-muted d-block mt-1"><?= number_format($section['completed']) ?> of <?= number_format($section['total']) ?> applicable fields recorded</small>
                        </div>
                      <?php endforeach; ?>
                      <div class="border-top mt-4 pt-3"><span class="badge bg-label-info me-2"><?= $summary['with_eligibility'] ?></span><span class="small">25%+ profiles with an eligibility, license, or other credential.</span></div>
                    </div>
                  </div></div>
                </section>
                <section id="qualifications" class="row g-4 mb-4" aria-label="Faculty qualifications">
                  <?php foreach ([['educationChart', 'Highest Completed Degree', 'Completed studies only; ongoing degrees are excluded.', 'bx-book-reader'], ['employmentChart', 'Employment Classification', 'Employment information recorded in saved profiles.', 'bx-briefcase-alt']] as $chart): ?>
                    <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h2 class="di-section-heading"><?= $chart[1] ?></h2><small class="text-muted"><?= $chart[2] ?></small></div>
                      <div id="<?= $chart[0] ?>" class="di-chart" role="img" aria-label="<?= $chart[1] ?> distribution"><div class="di-chart-empty"><i class="bx <?= $chart[3] ?>"></i><span>No saved profiles in this scope.</span></div></div>
                      <div class="di-chart-summary">Based on <?= $summary['qualified_25'] ?> profile<?= $summary['qualified_25'] === 1 ? '' : 's' ?> at 25% completion or higher.</div>
                    </div></div>
                  <?php endforeach; ?>
                  <div class="col-lg-7"><div class="card h-100"><div class="card-header"><h2 class="di-section-heading">Academic Rank Distribution</h2><small class="text-muted">Ranks recorded in saved faculty profiles</small></div>
                    <div id="rankChart" class="di-chart" role="img" aria-label="Academic rank distribution"><div class="di-chart-empty"><i class="bx bx-bar-chart-alt-2"></i><span>No saved profiles in this scope.</span></div></div>
                    <div class="di-chart-summary">Unspecified ranks appear as “Not recorded”.</div>
                  </div></div>
                  <div class="col-lg-5"><div class="card h-100"><div class="card-header"><h2 class="di-section-heading">Eligibility &amp; Licenses</h2><small class="text-muted">Most frequently recorded credentials</small></div>
                    <div class="card-body">
                      <?php if (!$summary['eligibilities']): ?><p class="text-muted small mb-3">No listed eligibility or license has been selected in these profiles.</p><?php else: ?>
                        <ul class="di-credential-list"><?php foreach (array_slice($summary['eligibilities'], 0, 5, true) as $label => $count): ?><li><span><?= synk_leadership_h($label) ?></span><span class="badge bg-label-info"><?= $count ?></span></li><?php endforeach; ?></ul>
                      <?php endif; ?>
                      <small class="text-muted d-block">A professor may hold several credentials. The summary also includes credentials entered as free text.</small>
                      <h3 class="h6 mt-4">Gender Profile</h3>
                      <div class="di-gender-row"><?php if (!$summary['gender']): ?><span class="text-muted small">No saved profiles in this scope.</span><?php endif; ?><?php foreach ($summary['gender'] as $label => $count): ?><span class="badge bg-label-secondary"><?= synk_leadership_h($label) ?> · <?= $count ?></span><?php endforeach; ?></div>
                    </div>
                  </div></div>
                </section>
                <section class="card mb-4" aria-labelledby="campus-coverage-heading">
                  <div class="card-header"><h2 class="di-section-heading" id="campus-coverage-heading">Campus Profile Coverage</h2><small class="text-muted">Coverage is the share of active professor accounts with profiles at 25% or higher; started profiles below 25% are shown separately.</small></div>
                  <div class="table-responsive"><table class="table mb-0"><thead><tr><th>Campus</th><th>Faculty Directory</th><th>Professor Accounts</th><th>Started below 25%</th><th>Profiles at 25%+</th><th>Complete</th><th>25%+ Coverage</th></tr></thead><tbody>
                    <?php foreach ($dashboard['campus_rows'] as $campusRow): $campusSummary = $campusRow['summary']; ?><tr>
                      <td class="fw-semibold"><?= synk_leadership_h($campusRow['campus']['campus_name']) ?></td><td><?= $campusRow['faculty_count'] ?></td><td><?= $campusSummary['accounts'] ?></td><td><?= $campusSummary['started_below_25'] ?></td><td><?= $campusSummary['qualified_25'] ?></td><td><?= $campusSummary['complete'] ?></td>
                      <td><span class="badge bg-label-<?= $campusSummary['accounts'] ? 'primary' : 'secondary' ?>"><?= $campusSummary['accounts'] ? $campusSummary['coverage_percent'] . '%' : 'No accounts' ?></span></td>
                    </tr><?php endforeach; ?>
                    <?php if (!$dashboard['campus_rows']): ?><tr><td colspan="7" class="text-muted text-center py-4">No campus records in this scope.</td></tr><?php endif; ?>
                  </tbody></table></div>
                  <div class="card-body py-3"><small class="text-muted">Faculty with several recorded campus connections may appear in more than one campus; the university total counts each faculty record once. <?= $dashboard['unassigned_faculty'] ?> faculty records have no recorded campus connection.</small></div>
                </section>
                <section id="profiles" class="card" aria-labelledby="profile-register-heading">
                  <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div><h2 class="di-section-heading" id="profile-register-heading">Faculty Profile Register</h2><small class="text-muted">Active professor accounts across all profile stages · <?= synk_leadership_h($dashboard['scope_label']) ?></small></div>
                    <div class="input-group di-table-search"><span class="input-group-text"><i class="bx bx-search"></i></span><input type="search" id="profileSearch" class="form-control" placeholder="Search name, email, or college" aria-label="Search profile register" /></div>
                  </div>
                  <div class="table-responsive"><table class="table di-profile-table mb-0"><thead><tr><th>Faculty / Professor</th><th>Campus / College</th><th>Academic Rank</th><th>Highest Degree</th><th>Profile Status</th><th>Completion</th><th>Last Saved</th></tr></thead><tbody>
                    <?php foreach ($dashboard['accounts'] as $account): ?><tr class="di-profile-row" data-search="<?= synk_leadership_h(implode(' ', [$account['name'], $account['email'], $account['campus_name'], $account['college_name'], $account['analytics_values']['faculty_rank'], $statusLabels[$account['profile_status']]])) ?>">
                      <td class="di-person"><strong class="d-block"><?= synk_leadership_h($account['name']) ?></strong><small class="text-muted di-email d-block"><?= synk_leadership_h($account['email']) ?></small><?php if ($account['faculty_id'] === 0): ?><span class="badge bg-label-warning mt-1">No faculty link</span><?php endif; ?></td>
                      <td><?= synk_leadership_h($account['campus_name']) ?><small class="text-muted d-block mt-1"><?= synk_leadership_h($account['college_name']) ?></small></td>
                      <td><?= synk_leadership_h($account['has_profile'] ? ($account['analytics_values']['faculty_rank'] ?: 'Not recorded') : 'Not recorded') ?></td><td><?= synk_leadership_h($account['highest_degree']) ?></td>
                      <td><span class="badge bg-label-<?= $statusColors[$account['profile_status']] ?>"><?= $statusLabels[$account['profile_status']] ?></span></td>
                      <td class="di-progress-cell"><strong class="small"><?= $account['completion']['percent'] ?>%</strong><div class="progress di-progress mt-1" role="progressbar" aria-label="<?= synk_leadership_h($account['name']) ?> profile completion" aria-valuenow="<?= $account['completion']['percent'] ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-<?= $account['profile_status'] === 'complete' ? 'success' : 'primary' ?>" style="width:<?= $account['completion']['percent'] ?>%"></div></div></td>
                      <td><?= $account['updated_at'] ? synk_leadership_h(date('M j, Y', strtotime($account['updated_at']))) : 'Never saved' ?></td>
                    </tr><?php endforeach; ?>
                    <tr id="profileEmptyRow" <?= $dashboard['accounts'] ? 'hidden' : '' ?>><td colspan="7" class="text-center text-muted py-4">No professor accounts match this view.</td></tr>
                  </tbody></table></div>
                  <div class="card-body py-3 d-flex flex-wrap align-items-center justify-content-between gap-3"><span id="profileTableCount" class="di-table-meta" aria-live="polite"><?= count($dashboard['accounts']) ?> professor accounts</span><div class="di-pagination"><button id="profilePrevious" type="button" class="btn btn-sm btn-outline-secondary" disabled>Previous</button><span id="profilePage" class="small text-muted">Page 1</span><button id="profileNext" type="button" class="btn btn-sm btn-outline-secondary" disabled>Next</button></div></div>
                </section>
              <?php endif; ?>
            </main>
            <?php include __DIR__ . '/../footer.php'; ?>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script id="diChartData" type="application/json"><?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="dashboard.js"></script>
  </body>
</html>
