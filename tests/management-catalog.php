<?php
// php tests/management-catalog.php <catalog.json>
// Checks imported values and actual MySQL constraints. Fixture writes roll back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../backend/db.php';
$input = $argv[1] ?? '';
if (!$input || !is_file($input)) { fwrite(STDERR, "Usage: php tests/management-catalog.php <catalog.json>\n"); exit(1); }
$manifest = json_decode(file_get_contents($input), true);
if (!is_array($manifest)) throw new RuntimeException('Invalid catalog test manifest.');
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message); $checks++;
};
$decimal = static function (string $value, int $scale): string {
    $parts = explode('.', $value); return $parts[0] . '.' . str_pad($parts[1] ?? '', $scale, '0');
};
$tables = ['tbl_management_organizations','tbl_management_contributions','tbl_management_events','tbl_management_event_sessions'];
$snapshot = static function () use ($conn, $tables): array {
    return $conn->query('CHECKSUM TABLE ' . implode(',', $tables) . ' EXTENDED')->fetch_all(MYSQLI_ASSOC);
};
$before = $snapshot();
foreach ($tables as $table) {
    $info = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")->fetch_assoc();
    $check(($info['ENGINE'] ?? '') === 'InnoDB', $table . ': catalog storage is not transactional.');
    $rows = $conn->query('SELECT * FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $row) foreach (['title','organization_code','organization_name','status','audience_mode','source_table','source_checksum'] as $field) {
        if (isset($row[$field])) $check($row[$field] === mb_strtoupper($row[$field], 'UTF-8'), $table . ': a business text value is not uppercase.');
    }
}
$stmt = $conn->prepare('SELECT c.*,o.organization_code,ay.ay FROM tbl_management_contributions c LEFT JOIN tbl_management_organizations o ON o.college_id=c.college_id AND o.organization_id=c.organization_id JOIN tbl_academic_years ay ON ay.ay_id=c.ay_id WHERE c.source_table=? AND c.source_record_id=?');
foreach ($manifest['contributions'] as $expected) {
    $stmt->bind_param('si', $expected['source_table'], $expected['source_record_id']); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $check(count($rows) === 1, 'Missing or duplicated contribution definition.'); $actual = $rows[0];
    foreach (['title','contribution_date','audience_mode','status','source_checksum','organization_code'] as $field) $check($actual[$field] === $expected[$field], 'Contribution field mismatch: ' . $field);
    $check($actual['amount'] === $decimal($expected['amount'], 2), 'Contribution amount changed.');
    $check($actual['ay'] === $expected['academic_year'] && (int)$actual['semester'] === $expected['semester'], 'Contribution academic term changed.');
    $check($actual['event_id'] === null, 'An event link was guessed from the contribution title.');
    $check(json_decode($actual['source_metadata'], true) === $expected['source_metadata'], 'Contribution source audience was lost.');
}
$stmt->close();
$stmt = $conn->prepare('SELECT e.*,o.organization_code,ay.ay FROM tbl_management_events e LEFT JOIN tbl_management_organizations o ON o.college_id=e.college_id AND o.organization_id=e.organization_id LEFT JOIN tbl_academic_years ay ON ay.ay_id=e.ay_id WHERE e.source_table=? AND e.source_record_id=?');
$sessionCount = 0;
foreach ($manifest['events'] as $expected) {
    $stmt->bind_param('si', $expected['source_table'], $expected['source_record_id']); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $check(count($rows) === 1, 'Missing or duplicated event definition.'); $actual = $rows[0];
    foreach (['title','event_date','audience_mode','status','source_checksum','organization_code'] as $field) $check($actual[$field] === $expected[$field], 'Event field mismatch: ' . $field);
    $check($actual['ay'] === $expected['academic_year'] && ($actual['semester'] === null ? null : (int)$actual['semester']) === $expected['semester'], 'Event academic term was changed or inferred.');
    $check($actual['absence_fine'] === ($expected['absence_fine'] === null ? null : $decimal($expected['absence_fine'], 2)), 'Event absence fine changed.');
    foreach (['latitude','longitude','attendance_radius_m'] as $field) $check($actual[$field] === null ? $expected[$field] === null : (string)$actual[$field] === (string)$expected[$field], 'Event location setting changed.');
    $actualMetadata = $actual['source_metadata'] === null ? null : json_decode($actual['source_metadata'], true);
    $expectedMetadata = $expected['source_metadata'];
    if (is_array($actualMetadata)) ksort($actualMetadata);
    if (is_array($expectedMetadata)) ksort($expectedMetadata);
    $check($actualMetadata === $expectedMetadata, 'Legacy event flag was lost.');
    $sessions = $conn->query('SELECT * FROM tbl_management_event_sessions WHERE event_id=' . (int)$actual['event_id'])->fetch_all(MYSQLI_ASSOC);
    $check(count($sessions) === ($expected['session'] === null ? 0 : 1), 'Timed event session count is incorrect.');
    if ($expected['session'] !== null) {
        $sessionCount++; $session = $sessions[0];
        foreach (['title','starts_at','ends_at','status'] as $field) $check($session[$field] === $expected['session'][$field], 'Timed session field mismatch: ' . $field);
        $check($session['fine_per_minute'] === $decimal($expected['session']['fine_per_minute'], 4), 'Fine rate changed.');
        $check($session['potential_fine'] === $decimal($expected['session']['potential_fine'], 2), 'Potential fine reference changed.');
        $check((int)$session['grace_minutes'] === $expected['session']['grace_minutes'], 'Grace period changed.');
        $check((int)$session['college_id'] === (int)$actual['college_id'] && $session['source_checksum'] === $actual['source_checksum'], 'Session source or college link changed.');
    }
}
$stmt->close();
foreach (['contributions','events','event_sessions'] as $group) {
    $expectedCount = $group === 'event_sessions' ? $sessionCount : count($manifest[$group]);
    $actualCount = (int)$conn->query("SELECT COUNT(*) AS n FROM tbl_management_$group WHERE source_table IS NOT NULL")->fetch_assoc()['n'];
    $check($actualCount === $expectedCount, 'Imported catalog total differs: ' . $group);
}
$event = $conn->query('SELECT event_id,college_id FROM tbl_management_events WHERE organization_id IS NOT NULL LIMIT 1')->fetch_assoc();
$org = $conn->query('SELECT organization_id,college_id FROM tbl_management_organizations WHERE college_id=' . (int)$event['college_id'] . ' LIMIT 1')->fetch_assoc();
$otherCollege = (int)$conn->query('SELECT college_id FROM tbl_college WHERE college_id<>' . (int)$event['college_id'] . ' LIMIT 1')->fetch_assoc()['college_id'];
$year = (int)$conn->query('SELECT ay_id FROM tbl_academic_years LIMIT 1')->fetch_assoc()['ay_id'];
$rejected = static function (string $sql, int $errorCode) use ($conn, $check): void {
    $conn->begin_transaction();
    try {
        $code = 0; try { $conn->query($sql); } catch (mysqli_sql_exception $error) { $code = (int)$error->getCode(); }
        $check($code === $errorCode, 'Database did not enforce catalog integrity.');
    } finally { $conn->rollback(); }
};
$rejected("INSERT INTO tbl_management_events (college_id,organization_id,title,event_date) VALUES ($otherCollege," . (int)$org['organization_id'] . ",'CATALOG INTEGRITY FIXTURE','2026-10-26')", 1452);
$rejected("INSERT INTO tbl_management_event_sessions (college_id,event_id,title,starts_at,ends_at) VALUES ($otherCollege," . (int)$event['event_id'] . ",'CATALOG INTEGRITY FIXTURE','2026-10-26 08:00:00','2026-10-26 09:00:00')", 1452);
$rejected("INSERT INTO tbl_management_contributions (college_id,ay_id,semester,event_id,title,contribution_date,amount) VALUES ($otherCollege,$year,1," . (int)$event['event_id'] . ",'CATALOG INTEGRITY FIXTURE','2026-10-26',0)", 1452);
$check($before === $snapshot(), 'Catalog integrity fixtures changed stored data.');
echo 'Management catalog database: ', $checks, " checks passed.\n";
