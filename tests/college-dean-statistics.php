<?php
// php tests/college-dean-statistics.php -- database fixtures are rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$snapshot = static function () use ($conn): string {
    $data = [];
    foreach (['tbl_academic_settings', 'tbl_academic_years', 'tbl_college', 'tbl_program', 'tbl_sections', 'tbl_useraccount', 'tbl_useraccount_roles', 'tbl_faculty', 'tbl_college_faculty',
        'tbl_faculty_profiles', 'tbl_faculty_record_profiles', 'tbl_useraccount_faculty_links', 'tbl_student_management',
        'tbl_student_management_enrolled_subjects', 'tbl_faculty_education_options'] as $table) $data[$table] = $conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256', json_encode($data));
};
$before = $snapshot();
$token = bin2hex(random_bytes(24));
$route = __DIR__ . '/../tmp/dean-statistics-qa-' . bin2hex(random_bytes(8)) . '.php';
file_put_contents($route, '<?php define(\'SYNK_DEAN_STATISTICS_QA\',' . var_export($token, true) . '); require ' . var_export(__DIR__ . '/fixtures/college-dean-statistics-request.php', true) . ';');
$request = static function (string $scenario, bool $authorized = true) use ($route, $token): array {
    $curl = curl_init('http://localhost/synk/tmp/' . basename($route) . '?' . http_build_query(['qa' => $authorized ? $token : 'wrong', 'scenario' => $scenario]));
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException(curl_error($curl));
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
};
try {
    $check($request('metrics', false)['status'] === 404, 'Fixture routes reject missing or invalid tokens.');
    $response = $request('metrics');
    $metrics = json_decode($response['body'], true);
    $check($response['status'] === 200 && is_array($metrics), 'Statistics load through the actual web runtime: ' . substr($response['body'], 0, 800));
    $check(strpos($response['headers'], 'X-QA-Unchanged: yes') !== false, 'Reading statistics does not change student or profile data.');
    $students = $metrics['students'];
    $check($students['students'] === 3, 'College headcount deduplicates subject lists and shared programs, includes manual students, and excludes foreign/inactive/historical lists.');
    $programs = array_column($students['programs'], null, 'label');
    $check($programs['BSCS']['students'] === 2 && $programs['BSIT']['students'] === 2 && $programs['BSIS']['students'] === 0, 'Program totals count each person once per program and include empty programs.');
    $check($students['overlapping_programs'] === true, 'Shared program memberships are identified so program totals are explained.');
    $check($students['sections'] === 3 && $programs['BSCS']['sections'] === 2 && $programs['BSIT']['sections'] === 1, 'Section totals include empty active sections and exclude foreign/inactive/historical sections.');
    $check($metrics['historical']['students'] === 1 && $metrics['historical']['sections'] === 1, 'Changing the term uses that term alone.');
    $check(count($metrics['terms']) >= 3 && count($metrics['terms']) % 3 === 0
        && in_array(3, array_column($metrics['terms'], 'semester'), true), 'Every academic year offers both semesters and Midyear, including terms without imported data.');
    $faculty = $metrics['faculty'];
    $check($faculty['faculty'] === 6, 'Faculty headcount includes on-leave and current-roster faculty, excludes retired, foreign, wrong-campus, and old-roster faculty.');
    $check($faculty['education'] === ['Doctoral / PhD' => 2, "Master's" => 2, "Bachelor's" => 1, 'Not recorded' => 1], 'Highest completed degree counts PhD and other doctorates, excludes ongoing studies, and gives existing account profiles priority.');
    $check($faculty['ranks']['Instructor I'] === 2 && $faculty['ranks']['Not recorded'] === 1 && array_sum($faculty['ranks']) === 6, 'Ranks include profiles saved by the Dean before account linking and expose missing details.');
    foreach (['students' => 'Student Statistics', 'faculty' => 'Faculty Statistics', 'scope_tamper' => 'Student Statistics', 'empty' => 'Faculty Statistics'] as $scenario => $title) {
        $page = $request($scenario);
        $check($page['status'] === 200 && strpos($page['body'], '<h1>' . $title . '</h1>') !== false
            && !preg_match('/Fatal error|Parse error|<b>Warning<\/b>/i', $page['body']), 'Statistics page renders cleanly: ' . $scenario);
        preg_match('/<nav\b[^>]*id="layout-navbar"[^>]*>(.*?)<\/nav>/s', $page['body'], $header);
        $check(isset($header[1]) && strpos($header[1], 'id="dean-view-label"') !== false
            && strpos($header[1], 'id="dean-term-settings-toggle"') !== false && strpos($header[1], '<select') === false,
            'The header shows the viewing term and settings button, with selectors kept in the modal.');
        $check(strpos($page['body'], 'id="deanTermSettingsModal"') !== false
            && strpos($page['body'], 'id="dean-view-apply"') !== false && strpos($page['body'], 'view-term-settings.js?v=') !== false,
            'The term settings modal and its controller are available on Dean pages.');
        $check(strpos($page['body'], '<details class="college-dean-report-group" open>') !== false
            && strpos($page['body'], 'aria-current="page"') !== false, 'Reports group is expanded with the active child identified: ' . $scenario);
        $check(strpos($page['body'], 'Faculty Student Summary') !== false && strpos($page['body'], 'href="reports.php"') !== false, 'The existing summary remains accessible in Reports.');
        if ($scenario === 'scope_tamper') $check(strpos($page['body'], 'Statistics Foreign College') === false, 'URL input cannot widen the Dean college.');
        if ($scenario === 'empty') $check(strpos($page['body'], 'No faculty recorded for this college.') !== false, 'An empty college has a clear empty state.');
    }
    foreach (['bad_term', 'array_term'] as $scenario) $check($request($scenario)['status'] === 400, 'Malformed term filters are rejected: ' . $scenario);
    $historicalPage = $request('historical_page');
    $check($historicalPage['status'] === 200 && preg_match('/data-statistic="College students">1<\/strong>/', $historicalPage['body']) === 1
        && preg_match('/data-statistic="Active sections">1<\/strong>/', $historicalPage['body']) === 1,
        'Selecting a historical term updates both student and section totals in the actual page.');
    $summaryPage = $request('summary');
    $check($summaryPage['status'] === 200 && strpos($summaryPage['body'], 'id="deanFacultyReport"') !== false
        && strpos($summaryPage['body'], 'id="deanReportTerm"') !== false && strpos($summaryPage['body'], 'src="reports.js?') !== false,
        'The existing Faculty Student Summary page still renders its filters and controller under Reports.');
    $check(strpos($summaryPage['body'], 'href="reports.php" class="college-dean-report-link is-active" aria-current="page"') !== false,
        'The existing summary selects its own report menu item.');
    foreach (['wrong_role', 'revoked'] as $scenario) $check($request($scenario)['status'] === 302, 'Statistics require an authorized Dean: ' . $scenario);
    $viewResponse = $request('view_checks');
    $view = json_decode($viewResponse['body'], true);
    $check($viewResponse['status'] === 200 && is_array($view), 'The viewing-term helper works in the web runtime.');
    $check($view['remembered'] === $view['selected'] && $view['stillSelected'] === $view['selected'], 'The Dean viewing choice persists while another tab reads a different term.');
    $check($view['read']['key'] === $view['initial']['key'] && $view['read']['is_current'] === true, 'Read requests can select a valid term independently of session browsing.');
    $check($view['invalidRejected'] && $view['afterInvalid'] === $view['selected'], 'An invalid viewing choice preserves the last valid preference.');
    $check($view['writeRejected'] && $view['currentUnchanged'], 'A historical view is read-only and does not alter the administrator settings.');
    $check($view['restored'] === $view['initial'] && $view['newUser'] === $view['initial'], 'Current term restores the default, and viewing preferences cannot leak between users.');
    foreach (['invalid_view', 'array_view'] as $scenario) $check($request($scenario)['status'] === 400, 'Malformed viewing controls are rejected: ' . $scenario);
    foreach (['historical_dashboard', 'historical_workload', 'historical_student_page', 'historical_faculty', 'historical_profile'] as $scenario) {
        $page = $request($scenario);
        $check($page['status'] === 200 && strpos($page['body'], 'id="dean-view-year"') !== false
            && strpos($page['body'], 'id="dean-view-semester"') !== false && strpos($page['body'], 'View only') !== false
            && !preg_match('/Fatal error|Parse error|<b>Warning<\/b>|xdebug-error/i', $page['body']), 'The shared term controls render in a historical view: ' . $scenario);
        if ($scenario === 'historical_profile') $check(strpos($page['body'], '<fieldset disabled') !== false && strpos($page['body'], 'id="profile-save-button"') === false, 'Historical profile details are disabled and have no save action.');
        if ($scenario === 'historical_faculty') $check(preg_match('/data-statistic="College faculty">1<\/strong>/', $page['body']) === 1, 'Historical faculty statistics use that term roster.');
    }
    $bootstrap = $request('historical_bootstrap');
    $data = json_decode($bootstrap['body'], true);
    $check($bootstrap['status'] === 200 && $data['can_import'] === false && $data['term']['is_current'] === false && count($data['faculties']) === 1, 'Historical workload reads return the term roster with imports disabled.');
    $classes = $request('historical_classes');
    $check($classes['status'] === 200 && json_decode($classes['body'], true)['status'] === 'ok', 'Historical assigned classes can be read.');
    $studentBootstrap = $request('historical_student_bootstrap');
    $studentData = json_decode($studentBootstrap['body'], true);
    $check($studentBootstrap['status'] === 200 && $studentData['view_term']['key'] !== $studentData['current_term']['ay_id'] . ':' . $studentData['current_term']['semester'], 'Student lists retain separate viewing and current terms.');
    foreach (['historical_import' => 409, 'historical_student_update' => 422, 'historical_delete' => 422, 'historical_profile_post' => 403] as $scenario => $status) {
        $blocked = $request($scenario);
        $check($blocked['status'] === $status && strpos($blocked['headers'], 'X-QA-Unchanged: yes') !== false, 'Historical updates are rejected without changing records: ' . $scenario);
    }
} finally { if (is_file($route)) unlink($route); }
$check($before === $snapshot(), 'All fixture records were rolled back; real data is unchanged.');
echo 'College Dean statistics: ', $checks, ' checks passed. Fixtures rolled back.', PHP_EOL;
