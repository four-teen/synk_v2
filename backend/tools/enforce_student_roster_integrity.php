<?php
// Audit: php backend/tools/enforce_student_roster_integrity.php
// Apply: php backend/tools/enforce_student_roster_integrity.php --apply
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../student_identity_helper.php';

$master = 'tbl_student_management'; $links = 'tbl_student_management_enrolled_subjects';
$audit = [];
foreach ([$master,$links] as $table) {
    $engine = $conn->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}'")->fetch_assoc();
    if (!$engine || $engine['ENGINE'] !== 'InnoDB') throw new RuntimeException('An existing InnoDB student table is required: ' . $table);
    $audit[$table] = (int)$conn->query('SELECT COUNT(*) n FROM ' . $table)->fetch_assoc()['n'];
}
$issues = [
    'duplicate_student_ids'=>"SELECT COUNT(*) n FROM (SELECT student_number FROM {$master} GROUP BY student_number HAVING COUNT(*)>1) x",
    'invalid_student_ids'=>"SELECT COUNT(*) n FROM {$master} WHERE CAST(student_number AS CHAR) NOT REGEXP '^[0-9]{1,32}$' OR CAST(student_number AS CHAR) REGEXP '^0+$'",
    'duplicate_active_offerings'=>"SELECT COUNT(*) n FROM (SELECT student_id,offering_id FROM {$links} WHERE is_active=1 AND offering_id>0 GROUP BY student_id,offering_id HAVING COUNT(*)>1) x",
    'duplicate_active_classes'=>"SELECT COUNT(*) n FROM (SELECT student_id,program_id,ay_id,semester,section_id,subject_id FROM {$links} WHERE is_active=1 GROUP BY student_id,program_id,ay_id,semester,section_id,subject_id HAVING COUNT(*)>1) x",
    'orphan_student_links'=>"SELECT COUNT(*) n FROM {$links} es LEFT JOIN {$master} s ON s.student_id=es.student_id WHERE s.student_id IS NULL",
];
foreach ($issues as $label=>$sql) $audit[$label] = (int)$conn->query($sql)->fetch_assoc()['n'];
echo json_encode($audit,JSON_PRETTY_PRINT) . PHP_EOL;
if (!in_array('--apply',$argv,true)) { echo "Audit only. Use --apply to install the constraints.\n"; exit; }
foreach (array_keys($issues) as $label) if ($audit[$label] > 0) throw new RuntimeException('Resolve ' . $label . ' before migrating. No records will be merged or deleted automatically.');

$backupDir = dirname(__DIR__,2) . '/.tmp/student-integrity-backup';
if (!is_dir($backupDir) && !mkdir($backupDir,0770,true)) throw new RuntimeException('Unable to create a protected migration backup.');
file_put_contents($backupDir . '/.htaccess',"Require all denied\n");
$snapshot = [];
foreach ([$master,$links] as $table) $snapshot[$table] = [
    'schema'=>$conn->query('SHOW CREATE TABLE ' . $table)->fetch_assoc(),
    'rows'=>$conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC),
];
$backupFile = $backupDir . '/before-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
if (file_put_contents($backupFile,json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) === false) throw new RuntimeException('Unable to save the migration backup.');

$column = $conn->query("SHOW FULL COLUMNS FROM {$master} LIKE 'student_number'")->fetch_assoc();
$clauses = [];
if ($column['Type'] !== 'varchar(32)' || $column['Collation'] !== 'ascii_bin') {
    $clauses[] = "MODIFY student_number VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''";
}
if (!synk_table_has_index($conn,$master,'uk_student_identity_number')) $clauses[] = 'ADD UNIQUE KEY uk_student_identity_number (student_number)';
if ($clauses) $conn->query('ALTER TABLE ' . $master . ' ' . implode(', ',$clauses));

$clauses = [];
if (!synk_table_has_column($conn,$links,'active_student_id')) $clauses[] = 'ADD COLUMN active_student_id INT UNSIGNED GENERATED ALWAYS AS (IF(is_active=1,student_id,NULL)) VIRTUAL';
if (!synk_table_has_column($conn,$links,'active_offering_id')) $clauses[] = 'ADD COLUMN active_offering_id INT UNSIGNED GENERATED ALWAYS AS (IF(is_active=1 AND offering_id>0,offering_id,NULL)) VIRTUAL';
if (!synk_table_has_index($conn,$links,'uk_student_active_offering')) $clauses[] = 'ADD UNIQUE KEY uk_student_active_offering (student_id,active_offering_id)';
if (!synk_table_has_index($conn,$links,'uk_student_active_class')) $clauses[] = 'ADD UNIQUE KEY uk_student_active_class (active_student_id,program_id,ay_id,semester,section_id,subject_id)';
if ($clauses) $conn->query('ALTER TABLE ' . $links . ' ' . implode(', ',$clauses));

// Verify values and existing foreign-key IDs are preserved; generated fields are excluded.
foreach ([$master,$links] as $table) {
    $fields = array_keys($snapshot[$table]['rows'][0] ?? []);
    $fields = array_values(array_diff($fields,['active_student_id','active_offering_id']));
    if (!$fields) continue;
    $beforeRows = $snapshot[$table]['rows'];
    foreach ($beforeRows as &$row) {
        unset($row['active_student_id'],$row['active_offering_id']);
        if ($table === $master) $row['student_number'] = (string)$row['student_number'];
    }
    unset($row);
    $afterRows = $conn->query('SELECT `' . implode('`,`',$fields) . '` FROM ' . $table . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    if ($beforeRows !== $afterRows) throw new RuntimeException('Migration record verification failed for ' . $table . '. Review the protected backup.');
}
echo "Installed unique student IDs and active class memberships. Existing student profiles, links, and upload history were preserved.\n";
