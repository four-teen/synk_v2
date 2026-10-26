<?php
// php tests/scheduler-tba-bulk.php
// Exercise real HTTP endpoints with isolated fixtures; every request rolls back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException($message); }
    $checks++;
};
$snapshot = static function () use ($conn): array {
    $data = [];
    foreach (['tbl_class_schedule','tbl_class_schedule_tba_event','tbl_faculty_workload_sched','tbl_faculty_need_workload_sched',
        'tbl_prospectus_offering','tbl_class_schedule_merge','tbl_program_schedule_set_row','tbl_student_management_enrolled_subjects'] as $table) {
        $data[$table] = hash('sha256', json_encode($conn->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $data;
};
$before = $snapshot();
$token = bin2hex(random_bytes(24));
$name = 'scheduler-tba-bulk-qa-' . bin2hex(random_bytes(8)) . '.php';
$route = __DIR__ . '/../tmp/' . $name;
file_put_contents($route, '<?php if (!in_array($_SERVER["REMOTE_ADDR"] ?? "",["127.0.0.1","::1"],true) || !hash_equals('
    . var_export($token, true) . ',(string)($_GET["qa"] ?? ""))) { http_response_code(404);exit; } define("SYNK_SCHEDULER_TBA_QA",true); require '
    . var_export(__DIR__ . '/fixtures/scheduler-tba-request.php', true) . ';');
$request = static function (string $scenario) use ($name, $token): array {
    $context = stream_context_create(['http'=>['method'=>'POST','timeout'=>30,'ignore_errors'=>true,'header'=>"Connection: close\r\n",'content'=>'']]);
    $body = file_get_contents('http://localhost/synk/tmp/' . $name . '?qa=' . $token . '&scenario=' . rawurlencode($scenario), false, $context);
    $result = json_decode((string)$body, true);
    if (!is_array($result) || isset($result['fixture_error']) || !is_array($result['response'] ?? null)) {
        throw new RuntimeException($scenario . ': ' . substr((string)$body, 0, 2400));
    }
    return $result;
};
try {
    foreach (['bulk_preview_generate','bulk_preview_convert'] as $scenario) {
        $r = $request($scenario);
        $check($r['response']['status'] === 'ok', $scenario . ': preview failed.');
        $check($r['before'] === $r['after'], $scenario . ': preview changed data.');
        $check(!isset($r['response']['changes']), 'Preview exposed internal write data.');
    }
    foreach (['bulk_generate','bulk_generate_lec_only','planning_nextyear_bulk_generate'] as $scenario) {
        $r = $request($scenario);
        $check($r['response']['status'] === 'ok', $scenario . ': ' . json_encode($r['response']));
        $expected = $scenario === 'bulk_generate_lec_only' ? 2 : 4;
        $check(count($r['after']['classes']) === $expected && count($r['after']['events']) === $expected, 'Generated meetings/history missing.');
        foreach ($r['after']['classes'] as $row) {
            $check($row['schedule_status'] === 'tba' && $row['room_id'] === null && $row['time_start'] === null && $row['time_end'] === null && $row['days_json'] === '[]', 'Generated TBA reserved a slot.');
        }
        if ($expected === 4) {
            $groups = [];
            foreach ($r['after']['classes'] as $row) { $groups[$row['offering_id']][] = $row['schedule_group_id']; }
            foreach ($groups as $ids) { $check($ids[0] !== null && count(array_unique($ids)) === 1, 'Lecture/lab grouping was not created.'); }
        }
    }
    $r = $request('bulk_generate_existing');
    $check($r['response']['summary']['eligible_count'] === 0 && $r['before'] === $r['after'], 'Generation replaced existing blocks.');
    foreach (['bulk_convert','bulk_convert_need','bulk_convert_students','bulk_convert_scope','planning_nextyear_bulk_convert'] as $scenario) {
        $r = $request($scenario);
        $check($r['response']['status'] === 'ok', $scenario . ': ' . json_encode($r['response']));
        $check($r['response']['summary']['eligible_count'] === 1 && $r['response']['summary']['meeting_count'] === 3, 'Conversion counts do not match changed meetings.');
        $check($r['before']['workload'] === $r['after']['workload'] && $r['before']['need_workload'] === $r['after']['need_workload'], 'Conversion changed workload assignments.');
        $check($r['before']['enrollments'] === $r['after']['enrollments'], 'Conversion changed enrollments.');
        if ($scenario === 'bulk_convert_students') { $check(count($r['before']['enrollments']) > 0, 'The enrollment preservation fixture is empty.'); }
        $check($r['before']['outside_classes'] === $r['after']['outside_classes'], 'Conversion changed another program.');
        if ($scenario === 'bulk_convert_scope') { $check(count($r['before']['outside_classes']) === 1, 'The outside-program fixture is empty.'); }
        $check(array_column($r['before']['classes'], 'schedule_id') === array_column($r['after']['classes'], 'schedule_id'), 'Conversion replaced meeting IDs.');
        $check(array_column($r['before']['classes'], 'schedule_group_id') === array_column($r['after']['classes'], 'schedule_group_id'), 'Conversion changed meeting groups/load calculation.');
        foreach ($r['after']['classes'] as $index => $row) {
            if ((int)$row['offering_id'] !== (int)$r['target']['offering_id']) {
                $check($row === $r['before']['classes'][$index], 'Conversion changed a class outside the plan.');
                continue;
            }
            $check($row['schedule_status'] === 'tba' && $row['room_id'] === null && $row['time_start'] === null && $row['time_end'] === null && $row['days_json'] === '[]', 'Conversion kept a real slot.');
        }
        $check(count($r['after']['events']) === 3, 'Conversion audit is incomplete.');
        foreach ($r['after']['events'] as $event) {
            $check($event['new_status'] === 'tba' && json_decode($event['previous_values'], true)['schedule_id'] === json_decode($event['new_values'], true)['schedule_id'], 'History lost the original meeting.');
        }
        foreach ($r['after']['offerings'] as $offering) {
            if ((int)$offering['offering_id'] === (int)$r['target']['offering_id']) { $check($offering['status'] === 'pending', 'Converted class is not pending.'); }
        }
    }
    foreach (['bulk_generate_merged','bulk_convert_merged','bulk_convert_locked'] as $scenario) {
        $r = $request($scenario);
        $check($r['response']['status'] === 'ok' && $r['response']['summary']['eligible_count'] === 0, $scenario . ': protected class was eligible.');
        $check($r['before'] === $r['after'], $scenario . ': protected class changed.');
    }
    $r = $request('bulk_generate_locked');
    $check($r['response']['summary']['eligible_count'] === 1 && $r['response']['summary']['skipped_count'] === 1, 'Locked generation did not report the skipped class.');
    foreach ($r['after']['classes'] as $row) { $check((int)$row['offering_id'] !== (int)$r['target']['offering_id'], 'Generated blocks on a locked class.'); }
    foreach (['bulk_generate_repeat','bulk_convert_repeat'] as $scenario) {
        $r = $request($scenario);
        $check($r['response']['status'] === 'ok' && $r['response']['summary']['eligible_count'] === 0 && $r['before'] === $r['after'], $scenario . ': repeating the operation created duplicate meetings or history.');
    }
    foreach (['bulk_bad_csrf','bulk_wrong_role','bulk_foreign_program','bulk_wrong_term','bulk_invalid_mode','bulk_array_mode',
        'bulk_missing_preview','bulk_convert_stale','bulk_generate_stale','bulk_convert_history_failure','bulk_generate_history_failure'] as $scenario) {
        $r = $request($scenario);
        $check($r['response']['status'] === 'error', $scenario . ': invalid operation accepted.');
        $check($r['before'] === $r['after'], $scenario . ': rejected request left partial changes.');
    }
    $check($snapshot() === $before, 'Temporary fixture records were not rolled back.');
    echo 'Bulk TBA schedules: ' . $checks . " checks passed over actual HTTP. All temporary records rolled back.\n";
} finally {
    if (is_file($route)) { unlink($route); }
}
