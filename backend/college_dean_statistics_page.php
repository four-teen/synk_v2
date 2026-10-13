<?php
if (!isset($statisticsKind) || !in_array($statisticsKind, ['students', 'faculty'], true)) { http_response_code(404); exit; }
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/college_dean_statistics_helper.php';
require_once __DIR__ . '/faculty_profile_form_helper.php';
synk_college_dean_require_login($conn);
$scope = synk_college_dean_dashboard_require_scope($conn, [], true);
$collegeDeanPortalContext = synk_college_dean_portal_context($conn);
$collegeDeanPortalDisplayName = (string)($collegeDeanPortalContext['account_name'] ?? 'College Dean');
$collegeDeanPortalDisplayEmail = (string)($collegeDeanPortalContext['email'] ?? '');
header('Cache-Control: no-store, private');
$isStudentStatistics = $statisticsKind === 'students';
$pageTitle = $isStudentStatistics ? 'Student Statistics' : 'Faculty Statistics';
$statisticsError = '';
$terms = [];
$selectedTerm = '';
$statistics = [];
$kpis = [];
$charts = [];
try {
    $term = synk_dean_view_term($conn);
    if ($isStudentStatistics) {
        $terms = synk_dean_statistics_terms($conn, $scope, $term);
        $selectedTerm = $_GET['term'] ?? $term['key'];
        if (!is_string($selectedTerm) || ($selectedTerm !== '' && !in_array($selectedTerm, array_column($terms, 'key'), true))) {
            throw new DomainException('Choose an available academic term.');
        }
        if ($selectedTerm !== '') {
            [$ayId, $semester] = explode(':', $selectedTerm);
            if (isset($_GET['term'])) $term = synk_dean_select_view_term($conn, $ayId, $semester);
        }
        $statistics = synk_dean_student_statistics($conn, $scope, $term);
        $kpis = [
            ['label' => 'College students', 'value' => $statistics['available'] ? $statistics['students'] : null, 'icon' => 'bx-group', 'color' => 'primary'],
            ['label' => 'Programs', 'value' => count($statistics['programs']), 'icon' => 'bx-book-content', 'color' => 'info'],
            ['label' => 'Active sections', 'value' => $statistics['sections'], 'icon' => 'bx-grid-alt', 'color' => 'success'],
        ];
        $rows = [];
        foreach ($statistics['programs'] as $program) $rows[] = ['label' => $program['label'], 'value' => $program['students'], 'sections' => $program['sections']];
        $charts[] = ['id' => 'dean-statistics-programs', 'title' => 'Students by program', 'copy' => 'Compare program totals at a glance.',
            'label' => 'Program', 'count_label' => 'Students', 'rows' => $rows, 'sections' => true];
    } else {
        $statistics = synk_dean_faculty_statistics($conn, $scope, $term);
        $kpis = [
            ['label' => 'College faculty', 'value' => $statistics['faculty'], 'icon' => 'bx-group', 'color' => 'primary'],
            ['label' => 'Doctoral / PhD', 'value' => $statistics['education']['Doctoral / PhD'], 'icon' => 'bxs-graduation', 'color' => 'success'],
            ['label' => "Master's (highest degree)", 'value' => $statistics['education']["Master's"], 'icon' => 'bx-book-reader', 'color' => 'info'],
        ];
        foreach (['ranks' => ['Faculty by rank', 'Reported academic rank.', 'Rank'],
            'education' => ['Highest completed degree', 'Each faculty member is counted in one degree level.', 'Degree']] as $key => $chart) {
            $rows = [];
            foreach ($statistics[$key] as $label => $count) $rows[] = ['label' => $label, 'value' => $count];
            $charts[] = ['id' => 'dean-statistics-' . $key, 'title' => $chart[0], 'copy' => $chart[1],
                'label' => $chart[2], 'count_label' => 'Faculty', 'rows' => $rows, 'sections' => false];
        }
    }
} catch (DomainException $exception) {
    http_response_code(400);
    $statisticsError = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Dean statistics: ' . $exception->getMessage());
    http_response_code(503);
    $statisticsError = 'Statistics are temporarily unavailable. Please try again.';
}
$deanDirectory = dirname(__DIR__) . '/college-dean';
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= synk_faculty_profile_h($pageTitle) ?> | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css">
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css">
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="custom_css.css?v=<?= filemtime($deanDirectory . '/custom_css.css') ?>">
  <link rel="stylesheet" href="statistics.css?v=<?= filemtime($deanDirectory . '/statistics.css') ?>">
  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
</head>
<body>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
  <?php include $deanDirectory . '/sidebar.php'; ?>
  <div class="layout-page">
    <?php include $deanDirectory . '/navbar.php'; ?>
    <div class="content-wrapper">
      <main class="container-xxl flex-grow-1 container-p-y dean-statistics-page" id="dean-statistics">
        <div class="dean-statistics-heading">
          <div><p class="card-section-title mb-2">Reports</p><h1><?= synk_faculty_profile_h($pageTitle) ?></h1><p><?= synk_faculty_profile_h($scope['college_name']) ?></p></div>
          <?php if (isset($term)): ?><span class="badge bg-label-primary"><?= synk_faculty_profile_h($term['term_text']) ?></span><?php endif; ?>
        </div>
        <?php if ($statisticsError !== ''): ?>
          <div class="alert alert-danger" role="alert"><?= synk_faculty_profile_h($statisticsError) ?></div>
        <?php else: ?>
          <?php if ($isStudentStatistics && !$statistics['available']): ?>
            <div class="alert alert-info" role="status">Student lists are not available yet. Import class lists to see student totals.</div>
          <?php elseif ($isStudentStatistics && $selectedTerm === ''): ?>
            <div class="alert alert-info" role="status">Add an academic year to view student and section totals.</div>
          <?php endif; ?>
          <div class="dean-statistics-kpis">
            <?php foreach ($kpis as $kpi): ?><div class="card dean-statistics-kpi"><span class="dean-statistics-kpi-icon bg-label-<?= $kpi['color'] ?>"><i class="bx <?= $kpi['icon'] ?>" aria-hidden="true"></i></span><div><span><?= synk_faculty_profile_h($kpi['label']) ?></span><strong data-statistic="<?= synk_faculty_profile_h($kpi['label']) ?>"><?= $kpi['value'] === null ? '—' : number_format($kpi['value']) ?></strong></div></div><?php endforeach; ?>
          </div>
          <div class="dean-statistics-charts<?= !$isStudentStatistics ? ' has-two-charts' : ''; ?>">
            <?php foreach ($charts as $chart): $maximum = max(array_merge([1], array_column($chart['rows'], 'value'))); ?>
              <section class="card dean-statistics-chart<?= $chart['id'] === 'dean-statistics-education' ? ' is-education' : ''; ?>" aria-labelledby="<?= $chart['id'] ?>">
                <h2 id="<?= $chart['id'] ?>"><?= synk_faculty_profile_h($chart['title']) ?></h2><p><?= synk_faculty_profile_h($chart['copy']) ?></p>
                <?php if (!$chart['rows'] || (!$isStudentStatistics && $statistics['faculty'] === 0)): ?>
                  <div class="dean-statistics-empty">No <?= $isStudentStatistics ? 'programs' : 'faculty' ?> recorded for this college.</div>
                <?php elseif ($isStudentStatistics && !$statistics['available']): ?>
                  <div class="dean-statistics-empty">Student totals will appear after student lists are available.</div>
                <?php else: ?>
                  <table class="dean-statistics-bar-table"><caption class="visually-hidden"><?= synk_faculty_profile_h($chart['title']) ?></caption>
                    <thead><tr><th scope="col"><?= synk_faculty_profile_h($chart['label']) ?></th><th scope="col"><?= synk_faculty_profile_h($chart['count_label']) ?></th><?php if ($chart['sections']): ?><th scope="col">Sections</th><?php endif; ?></tr></thead>
                    <tbody><?php foreach ($chart['rows'] as $row): ?><tr><th scope="row"><?= synk_faculty_profile_h($row['label']) ?></th><td><div class="dean-statistics-bar-value"><span class="dean-statistics-bar-track" aria-hidden="true"><span style="width:<?= round($row['value'] * 100 / $maximum, 2) ?>%"></span></span><strong><?= number_format($row['value']) ?></strong></div></td><?php if ($chart['sections']): ?><td class="dean-statistics-sections"><?= number_format($row['sections']) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody>
                  </table>
                <?php endif; ?>
              </section>
            <?php endforeach; ?>
          </div>
          <p class="dean-statistics-note"><?php if ($isStudentStatistics): ?>Student totals use recorded class lists and manually added students for the selected term. The college total counts each student once. Sections include active sections, even those without student lists.<?php if ($statistics['overlapping_programs']): ?> Students recorded in multiple programs appear in each program total.<?php endif; ?><?php else: ?><?= !empty($term['is_current']) ? 'Includes active and on-leave faculty.' : 'Includes faculty recorded in this college’s roster or assigned workloads for the selected term.' ?> Rank and education show the latest saved faculty profiles. Doctoral includes PhD and other completed doctorates; ongoing studies are excluded. “Not recorded” identifies missing qualification details.<?php endif; ?></p>
        <?php endif; ?>
      </main>
      <?php include dirname(__DIR__) . '/footer.php'; ?>
    </div>
  </div>
</div><div class="layout-overlay layout-menu-toggle"></div></div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/libs/popper/popper.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
</body></html>
