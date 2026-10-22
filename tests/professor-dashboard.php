<?php
// Run: php tests/professor-dashboard.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../backend/professor_dashboard_helper.php';

$checks = 0;
function dashboard_check(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
}

$terms = [
    ['ay_id' => 2, 'semester' => 2, 'workload_count' => 5, 'student_count' => 200],
    ['ay_id' => 2, 'semester' => 1, 'workload_count' => 3, 'student_count' => 150],
];
$current = synk_professor_dashboard_term_summary($terms, ['ay_id' => 2, 'semester' => 1]);
dashboard_check($current['subjects'] === 3 && $current['enrollments'] === 150, 'Count the current term even when another term appears first.');
dashboard_check($current['terms'] === 2 && $current['workload_url'] === 'workload.php?ay_id=2&semester=1', 'Keep history separate and link to the current workload.');
$noCurrent = synk_professor_dashboard_term_summary($terms, ['ay_id' => 3, 'semester' => 1]);
dashboard_check($noCurrent['subjects'] === 0 && $noCurrent['enrollments'] === 0, 'Historical assignments must not be labeled as current.');
dashboard_check($noCurrent['terms'] === 2 && $noCurrent['workload_url'] === 'workload.php', 'Historical terms remain accessible when no current workload exists.');
$empty = synk_professor_dashboard_term_summary([], []);
dashboard_check($empty['subjects'] === 0 && $empty['terms'] === 0, 'Empty or unconfigured accounts show an empty teaching summary.');

$profile = [
    'saved' => true,
    'completion' => [
        'percent' => 58, 'missing' => ['master_status'],
        'sections' => [
            'personal' => ['completed' => 6, 'total' => 6],
            'employment' => ['completed' => 3, 'total' => 3],
            'education' => ['completed' => 5, 'total' => 15],
        ],
    ],
];
$actions = synk_professor_dashboard_actions($profile, true, true);
dashboard_check(count($actions) === 1 && $actions[0]['url'] === 'manage-profile.php#education', 'Only the incomplete section becomes a profile reminder.');
dashboard_check(strpos($actions[0]['description'], '10 details') !== false, 'Reminders state the remaining applicable detail count.');
$profile['completion']['sections']['education'] = ['completed' => 7, 'total' => 7];
$profile['completion']['percent'] = 100;
$profile['completion']['missing'] = [];
dashboard_check(synk_professor_dashboard_actions($profile, true, true) === [], 'A saved complete profile with active faculty access has no invented actions.');
$profile['saved'] = false;
$actions = synk_professor_dashboard_actions($profile, true, true);
dashboard_check(count($actions) === 1 && $actions[0]['title'] === 'Save your faculty profile', 'A complete unsaved draft still needs saving.');
$profile['saved'] = true;
$unlinked = synk_professor_dashboard_actions($profile, false, false);
dashboard_check(count($unlinked) === 1 && $unlinked[0]['url'] === null, 'Missing faculty access prompts administrator help without a fake action link.');
$inactive = synk_professor_dashboard_actions($profile, true, false);
dashboard_check(count($inactive) === 1 && $inactive[0]['title'] === 'Review faculty access', 'Inactive faculty records need an access review.');
$unavailable = synk_professor_dashboard_actions(null, true, true);
dashboard_check(count($unavailable) === 1 && $unavailable[0]['url'] === 'manage-profile.php', 'Unavailable progress offers profile access instead of claiming zero completion.');

$researchItems = [];
foreach ([1, 2, 3, 4, 5] as $id) {
    $researchItems[] = ['id' => $id, 'title' => $id === 5 ? '<img src=x onerror=alert(1)> Research' : 'Research ' . $id,
        'authors' => 'Faculty authors', 'status' => 'Completed', 'research_type' => 'Thesis',
        'roles' => $id === 1 ? ['Adviser', 'Panelist'] : ['Adviser'], 'is_published' => $id % 2 === 1,
        'updated_at' => $id === 5 ? null : '2026-10-0' . $id . ' 10:00:00', 'submitted_at' => '2026-10-05 10:00:00',
        'program' => [1 => 'BSIT', 2 => 'bsit', 3 => 'BSCS', 4 => 'BSIS', 5 => 'BSIT'][$id],
        'academic_year' => [1 => '2023-2024', 2 => '2024 - 2025', 3 => '2024-2025', 4 => '2025–2026', 5 => '2025-2026'][$id],
        'abstract' => 'Large abstract excluded from dashboard response', 'owner' => 'Not needed'];
}
$researchSummary = synk_professor_dashboard_research_summary(['items' => $researchItems, 'role_counts' => ['Adviser' => 5, 'Panelist' => 1]], ['Adviser' => 'Advisory', 'Panelist' => 'Panelist', 'Statistician' => 'Statistician', 'English Critic' => 'English Critic']);
dashboard_check($researchSummary['total'] === 5 && $researchSummary['published'] === 3, 'Research totals count distinct authorized records rather than adding overlapping roles.');
dashboard_check(count($researchSummary['roles']) === 2 && $researchSummary['roles'][0]['label'] === 'Advisory' && $researchSummary['roles'][1]['count'] === 1, 'Show the professor\'s actual role counts and omit unassigned roles.');
dashboard_check(array_column($researchSummary['recent'], 'id') === [5, 4, 3, 2], 'Show the four newest authorized records, using submission time when update time is absent.');
dashboard_check(!isset($researchSummary['recent'][0]['abstract']) && !isset($researchSummary['recent'][0]['owner']), 'The dashboard response omits full abstracts and unnecessary team metadata.');
$emptyResearch = synk_professor_dashboard_research_summary(['items' => [], 'role_counts' => []], ['Adviser' => 'Advisory']);
dashboard_check($emptyResearch === ['total' => 0, 'published' => 0, 'roles' => [], 'recent' => [], 'trend' => ['years' => [], 'series' => [], 'without_year' => 0]], 'An authorized empty portfolio has real zero totals and no invented roles or trend.');
dashboard_check($researchSummary['trend']['years'] === ['2023-2024','2024-2025','2025-2026'], 'Research years are normalized and ordered chronologically.');
$programSeries = array_column($researchSummary['trend']['series'], 'data', 'name');
dashboard_check($programSeries['BSIT'] === [1,1,1] && $programSeries['BSCS'] === [0,1,0] && $programSeries['BSIS'] === [0,0,1], 'Programs get separate series with true counts, zeroes for other available periods, and case-insensitive grouping.');
$missingYear = $researchItems[0]; $missingYear['id'] = 999; $missingYear['academic_year'] = '';
$chartWithMissing = synk_professor_dashboard_research_chart(array_merge($researchItems, [$researchItems[0], $missingYear]));
dashboard_check($chartWithMissing['without_year'] === 1 && $chartWithMissing['series'] === $researchSummary['trend']['series'], 'Duplicate IDs and missing academic years cannot inflate or invent a trend.');

$workloadRows = [];
foreach ([50,42,38,30,25] as $offset => $enrollments) {
    $workloadRows[] = ['offering_id' => 10 + $offset, 'ay_id' => 2, 'semester' => 1, 'subject_code' => ['IT 101','IT 202','CS 303','IS 204','IT 105'][$offset],
        'section_display' => 'BSIT ' . ($offset + 1) . 'A', 'descriptive_title' => 'Teaching subject ' . ($offset + 1), 'program_label' => 'BSIT', 'student_count' => $enrollments];
}
$mixedRows = array_merge($workloadRows, [$workloadRows[0], array_replace($workloadRows[0], ['offering_id' => 90, 'ay_id' => 3, 'student_count' => 999]),
    array_replace($workloadRows[0], ['offering_id' => 91, 'semester' => 2, 'student_count' => 999])]);
$testTerm = ['ay_id' => 2, 'semester' => 1, 'term_text' => '2026-2027 / 1st Semester'];
$workloadChart = synk_professor_dashboard_workload_chart($mixedRows, $testTerm);
dashboard_check(count($workloadChart['classes']) === 5 && array_sum(array_column($workloadChart['classes'], 'enrollments')) === 185, 'Workload chart includes only the configured year and semester, with no duplicate lecture/lab classes.');
dashboard_check(array_column($workloadChart['classes'], 'enrollments') === [50,42,38,30,25], 'The workload chart ranks assigned classes by enrollment.');
$freshSummary = synk_professor_dashboard_term_summary($terms, $testTerm, $mixedRows);
dashboard_check($freshSummary['subjects'] === 5 && $freshSummary['enrollments'] === 185, 'Dashboard metrics and chart share the same current-term class rows.');
dashboard_check(synk_professor_dashboard_workload_chart($mixedRows, ['ay_id' => 4, 'semester' => 1])['classes'] === []
    && synk_professor_dashboard_workload_chart($mixedRows, [])['classes'] === [], 'Empty or unconfigured current terms never fall back to historical classes.');

if (($argv[1] ?? '') === '--render') {
    $facultyName = 'Professor Alex Reyes';
    $facultyIsLinked = true;
    $teachingSummary = ['subjects' => 5, 'enrollments' => 185, 'terms' => 4];
    $workloadUrl = 'workload.php?ay_id=2&semester=1';
    $currentTerm = ['ay_id' => 2, 'semester' => 1, 'term_text' => '1st Semester · AY 2026–2027'];
    $dashboardWorkloadChart = synk_professor_dashboard_workload_chart($workloadRows, $currentTerm);
    $dashboardActions = synk_professor_dashboard_actions($profile, true, true);
    $professorSidebarCompletion = $profile['completion'];
    $_SERVER['PHP_SELF'] = '/synk/professor/index.php';
    ob_start();
    ?>
    <!doctype html><html lang="en" class="light-style layout-menu-fixed" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Professor Dashboard | Synk</title>
    <?php foreach (['../assets/vendor/fonts/boxicons.css', '../assets/vendor/css/core.css', '../assets/vendor/css/theme-default.css', '../assets/css/demo.css', '../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css', '../assets/vendor/libs/apex-charts/apex-charts.css', 'custom_css.css', 'dashboard.css'] as $stylesheet): ?><link rel="stylesheet" href="<?php echo $stylesheet; ?>"><?php endforeach; ?>
    <script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script></head><body><div class="layout-wrapper layout-content-navbar"><div class="layout-container">
    <?php include __DIR__ . '/../professor/sidebar.php'; ?>
    <div class="layout-page"><nav class="layout-navbar container-xxl navbar navbar-detached bg-navbar-theme"><div class="layout-menu-toggle navbar-nav me-3 d-xl-none"><a class="nav-item nav-link" href="#" aria-label="Open menu"><i class="bx bx-menu"></i></a></div><span class="badge bg-label-primary">Professor Portal</span><span class="ms-auto small">Professor Alex Reyes</span></nav><div class="content-wrapper">
    <?php include __DIR__ . '/../backend/professor_dashboard_view.php'; ?>
    </div></div></div><div class="layout-overlay layout-menu-toggle"></div></div>
    <?php foreach (['../assets/vendor/libs/jquery/jquery.js', '../assets/vendor/libs/popper/popper.js', '../assets/vendor/js/bootstrap.js', '../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js', '../assets/vendor/js/menu.js', '../assets/js/main.js', '../assets/vendor/libs/apex-charts/apexcharts.js', 'dashboard-charts.js', 'dashboard.js'] as $script): ?><script src="<?php echo $script; ?>"></script><?php endforeach; ?>
    </body></html>
    <?php
    $html = ob_get_clean();
    file_put_contents($argv[2], $html);
    file_put_contents($argv[3], json_encode(['ok' => true, 'email' => 'faculty@sksu.edu.ph', 'summary' => $researchSummary]));
    dashboard_check(strpos($html, 'professor-profile-ring') !== false && strpos($html, 'dashboard-progress-ring') === false, 'Profile completion is compact in the sidebar and removed from the dashboard content.');
}

foreach ([['GET', 'none', '', 401], ['GET', 'admin', '', 401], ['GET', 'professor', 'email=other%40sksu.edu.ph', 422], ['GET', 'professor', 'user_id=999', 422], ['POST', 'professor', '', 405]] as $request) {
    $command = escapeshellarg(PHP_BINARY) . ' -d display_startup_errors=0 -d display_errors=stderr ' . escapeshellarg(__DIR__ . '/fixtures/professor-dashboard-request.php');
    foreach (array_slice($request, 0, 3) as $argument) { $command .= ' ' . escapeshellarg($argument); }
    $response = json_decode((string)shell_exec($command), true);
    dashboard_check(is_array($response) && $response['status'] === $request[3] && $response['body']['ok'] === false, 'Dashboard endpoint rejects unauthorized identities, account overrides, and unsupported methods before any data lookup.');
}

echo 'Professor dashboard: ', $checks, ' checks passed.', PHP_EOL;
