<?php
// Run: php tests/college-dean-dashboard.php [--http]
// Scope, chart, faculty, report, and read-only matrix checks use rolled-back fixtures.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
$snapshot = static function () use ($conn): array {
    $state = [];
    foreach (['tbl_useraccount','tbl_useraccount_roles','tbl_faculty','tbl_useraccount_faculty_links','tbl_user_college_access','tbl_useraccount_registrar_scopes','tbl_faculty_need','tbl_faculty_need_workload_sched','tbl_class_schedule','tbl_faculty_workload_sched','tbl_room_college_access'] as $table) {
        $state[$table] = hash('sha256', json_encode($conn->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $state;
};
$before = $snapshot();
$http = in_array('--http', $argv, true);
$routePath = null; $routeName = ''; $token = ''; $phpVersion = PHP_VERSION;
if ($http) {
    $token = bin2hex(random_bytes(24));
    $routeName = 'college-dean-dashboard-qa-' . bin2hex(random_bytes(8)) . '.php';
    $routePath = __DIR__ . '/../tmp/' . $routeName;
    $route = <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(QA_TOKEN, $_GET['qa'])) { http_response_code(404); exit; }
header('X-QA-PHP-Version: ' . PHP_VERSION);
define('SYNK_COLLEGE_DEAN_QA_HTTP', true);
require QA_FIXTURE;
PHP;
    file_put_contents($routePath, str_replace(['QA_TOKEN','QA_FIXTURE'], [var_export($token, true), var_export(__DIR__ . '/fixtures/college-dean-request.php', true)], $route));
}
$request = static function (string $scenario) use ($http, $routeName, $token, &$phpVersion): array {
    if ($http) {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20, 'header' => "Connection: close\r\n"]]);
        $body = file_get_contents('http://localhost/synk/tmp/' . $routeName . '?qa=' . $token . '&scenario=' . rawurlencode($scenario), false, $context);
        preg_match('/X-QA-PHP-Version: ([^\r\n]+)/i', implode("\n", $http_response_header ?? []), $version);
        $phpVersion = $version[1] ?? 'unknown';
    } else {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/college-dean-request.php', $scenario], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, __DIR__ . '/..');
        $body = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException($scenario . ': ' . $errors . substr($body, 0, 1500));
    }
    $result = json_decode((string)$body, true);
    if (!is_array($result) || isset($result['fixture_error'])) throw new RuntimeException($scenario . ': ' . substr((string)$body, 0, 1500));
    return $result;
};
$checks = 0;
$check = static function (bool $passed, string $message) use (&$checks): void {
    if (!$passed) throw new RuntimeException($message);
    $checks++;
};
$readVariable = static function (string $body, string $name) {
    if (!preg_match('/var ' . preg_quote($name, '/') . ' = ([^\r\n]+);/', $body, $match)) throw new RuntimeException('Missing dashboard variable: ' . $name);
    return json_decode($match[1], true);
};
try {
    foreach (['dashboard_fixed','dashboard_reassigned'] as $scenario) {
        $r = $request($scenario);
        $body = $r['response'];
        $assigned = $scenario === 'dashboard_reassigned' ? $r['chair_college'] : $r['dean_college'];
        $check($r['http_status'] === 200 && is_string($body) && !preg_match('/Fatal error|Parse error|<b>Warning<\/b>|xdebug-error/i', $body), 'Dashboard rendering failed.');
        $check($readVariable($body, 'selectedCollegeId') === $assigned && $r['session_college'] === $assigned, 'URL filters or a stale session changed the assigned college scope.');
        $check($readVariable($body, 'dashboardViewType') === 'college', 'The Dean dashboard expanded to a campus or university view.');
        foreach (['Academic Operations Overview','scheduleHeatmapChart','roomUtilizationChart','Faculty Directory','Program Offerings Overview','btnOpenCollegeWorkloadReport','openMatrixModal'] as $feature) {
            $check(strpos($body, $feature) !== false, 'Missing reference dashboard feature: ' . $feature);
        }
        foreach (['Switch Campus','Back to Institutional View','href="manage-faculty.php"','href="manage-prospectus.php"','href="../scheduler/"','href="campus_dashboard.php'] as $forbidden) {
            $check(strpos($body, $forbidden) === false, 'The Dean dashboard exposes an unrelated administrative navigation: ' . $forbidden);
        }
        $check(strpos($body, 'dashboard_college_dean_schedule_heatmap.php') !== false && strpos($body, 'query_college_dean_faculty_workload.php') !== false, 'Charts and details do not use assigned-college endpoints.');
        $scope = $readVariable($body, 'consolidatedReportScope');
        $check($scope['view_type'] === 'college' && $scope['college_name'] !== 'All Colleges', 'The report preview widened the college scope.');
        if ($scenario === 'dashboard_fixed') {
            $directory = $readVariable($body, 'facultyDirectoryData');
            $check(count($directory) > 0, 'The assigned-college faculty directory is empty.');
            $scripts = [];
            preg_match_all('/<script(?:\s[^>]*)?>(.*?)<\/script>/s', $body, $matches);
            foreach ($matches[1] as $inline) if (trim($inline) !== '') $scripts[] = $inline;
            $scriptPath = __DIR__ . '/../tmp/college-dean-dashboard-rendered.js';
            file_put_contents($scriptPath, implode("\n", $scripts));
            exec('node --check ' . escapeshellarg($scriptPath) . ' 2>&1', $output, $exit);
            unlink($scriptPath);
            $check($exit === 0, 'Rendered dashboard JavaScript failed to compile: ' . implode("\n", $output));
        }
    }
    $r = $request('dashboard_heatmap');
    $heatmap = $r['response']['schedule_heatmap'];
    $check($r['response']['status'] === 'ok' && $heatmap['scope_kind'] === 'college' && $heatmap['total_rooms'] > 0 && count($heatmap['series']) === 5, 'The assigned-college weekly heatmap did not load.');
    $rooms = $heatmap['room_options'];
    $r = $request('dashboard_heatmap_room');
    $check($r['response']['schedule_heatmap']['selected_room_id'] === 0 && $r['response']['schedule_heatmap']['room_options'] === $rooms, 'An arbitrary room ID expanded the accessible room pool.');
    foreach (['dashboard_heatmap_other','dashboard_heatmap_all','dashboard_revoked','dashboard_inactive','dashboard_unauthorized','dashboard_workload_other','dashboard_matrix_other'] as $scenario) {
        $r = $request($scenario);
        $check($r['http_status'] === 403, 'Assigned-college access restriction failed: ' . $scenario);
    }
    $r = $request('dashboard_heatmap_bad_csrf');
    $check($r['response']['status'] === 'error' && $r['response']['message'] === 'CSRF validation failed.', 'Heatmap bypassed CSRF validation.');
    $r = $request('dashboard_workload');
    $check($r['response']['status'] === 'ok' && count($r['response']['rows']) > 0 && $r['response']['external_rows'] === [], 'Faculty details did not load only the college workload.');
    foreach ($r['response']['rows'] as $row) $check((int)$row['schedule_college_id'] === $r['dean_college'], 'A faculty detail leaked another college workload.');
    $r = $request('dashboard_need');
    $check($r['response']['status'] === 'ok' && $r['response']['meta']['assignee_type'] === 'faculty_need', 'Faculty needs cannot load in the consolidated workload report.');
    $r = $request('dashboard_matrix');
    $check(is_string($r['response']) && strpos($r['response'], 'matrix-entry-readonly') !== false && strpos($r['response'], 'matrix-entry-actionable') === false, 'Schedule matrix is missing or permits schedule edits.');
    $r = $request('dashboard_matrix_bad_csrf');
    $check(is_string($r['response']) && strpos($r['response'], 'CSRF validation failed.') !== false, 'Matrix bypassed CSRF validation.');
    $check($snapshot() === $before, 'Dashboard tests changed real records.');
    echo 'College Dean dashboard ', $http ? 'HTTP' : 'CLI', ': ', $checks, ' checks passed on PHP ', $phpVersion, '. All fixture records rolled back.', PHP_EOL;
} finally {
    if ($routePath !== null && is_file($routePath)) unlink($routePath);
}
