<?php
// php tests/scheduler-dashboard.php
// The endpoint fixtures use transactions and discard every temporary record.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';

$snapshot = static function () use ($conn): array {
    $state = [];
    foreach (['tbl_class_schedule','tbl_class_schedule_merge','tbl_faculty_workload_sched','tbl_useraccount','tbl_program','tbl_prospectus_offering','tbl_scheduler_planning_terms'] as $table) {
        $state[$table] = hash('sha256', json_encode($conn->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $state;
};
$before = $snapshot();
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$request = static function (string $scenario): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/scheduler-tba-request.php', $scenario], [1=>['pipe','w'], 2=>['pipe','w']], $pipes, dirname(__DIR__));
    $body = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    $result = json_decode($body, true);
    if ($exit !== 0 || $errors !== '' || !is_array($result) || isset($result['fixture_error']) || !is_array($result['response'] ?? null)) {
        throw new RuntimeException($scenario . ': ' . $errors . substr($body, 0, 1800));
    }
    return $result;
};

foreach (['', 'planning_nextyear_'] as $termPrefix) {
    foreach (['college', 'campus'] as $scope) {
        $suffix = $scope === 'campus' ? '_campus' : '';
        $counts = $request($termPrefix . 'dashboard_counts' . $suffix);
        $charts = $request($termPrefix . 'dashboard_charts' . $suffix);
        $c = $counts['response']; $rows = $charts['response']['program_progress'] ?? [];
        $check($c['scope'] === $scope && $charts['response']['scope'] === $scope, 'Dashboard scope changed.');
        $totals = ['total'=>0, 'scheduled'=>0, 'tba'=>0, 'pending'=>0];
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) $totals[$key] += $row[$key];
            $check($row['total'] === $row['scheduled'] + $row['tba'] + $row['pending'], 'Offering categories overlap or omit classes.');
        }
        $check($totals['total'] === $c['offering_items'], 'Graph and overview offering totals differ.');
        $check($totals['tba'] === $c['tba_classes'], 'Graph and overview TBA totals differ.');
        $check($totals['pending'] === $c['unscheduled_classes'], 'Graph and overview unscheduled totals differ.');
        $check($c['tba_classes'] >= 4 && $c['faculty'] > 0, 'Assigned TBA classes were excluded.');
        if ($scope === 'college') {
            $fixtureRows = array_values(array_filter($rows, static function (array $row) use ($charts): bool { return $row['program'] === $charts['program_code']; }));
            $check(count($fixtureRows) === 1, 'Fixture program is missing.');
            $row = $fixtureRows[0];
            $check($row['total'] === 7 && $row['scheduled'] === 2 && $row['tba'] === 4 && $row['pending'] === 1,
                'TBA, Room TBA, mixed meetings, merges, or selected-term filtering were counted incorrectly: ' . json_encode($row));
        }
        $check($counts['before'] === $counts['after'] && $charts['before'] === $charts['after'], 'A dashboard read changed fixture data.');
    }
}
$check($snapshot() === $before, 'Temporary records were not rolled back.');
echo 'Scheduler dashboard: ' . $checks . " checks passed. All temporary records rolled back.\n";
