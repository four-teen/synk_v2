<?php
// Run once: php backend/tools/add_schedule_tba.php. No schema changes occur in page requests.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/schedule_tba_helper.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
synk_schedule_acquire_write_lock($conn);
foreach (['tbl_class_schedule', 'tbl_program_schedule_set_row'] as $table) {
    if (!synk_table_exists($conn, $table)) {
        if ($table === 'tbl_class_schedule') { throw new RuntimeException('Class schedule table is missing.'); }
        continue;
    }
    $clauses = [];
    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `{$table}`");
    while ($row = $result->fetch_assoc()) { $columns[$row['Field']] = $row; }
    $originalFields = '`' . implode('`,`', array_keys($columns)) . '`';
    $beforeRows = $conn->query("SELECT {$originalFields} FROM `{$table}` ORDER BY 1")->fetch_all(MYSQLI_ASSOC);
    if (!isset($columns['schedule_status'])) {
        $clauses[] = "ADD COLUMN schedule_status ENUM('scheduled','tba','room_tba') NOT NULL DEFAULT 'scheduled' AFTER schedule_type";
    }
    foreach (['room_id', 'time_start', 'time_end'] as $field) {
        if (!isset($columns[$field])) { throw new RuntimeException('Required schedule field is missing.'); }
        if ($columns[$field]['Null'] !== 'YES') {
            $clauses[] = "MODIFY COLUMN `{$field}` " . $columns[$field]['Type'] . ' NULL DEFAULT NULL';
        }
    }
    if ($clauses) { $conn->query("ALTER TABLE `{$table}` " . implode(', ', $clauses)); }
    $afterRows = $conn->query("SELECT {$originalFields} FROM `{$table}` ORDER BY 1")->fetch_all(MYSQLI_ASSOC);
    if ($beforeRows !== $afterRows) { throw new RuntimeException('Existing schedule values changed during migration.'); }
    echo $table . ': ' . count($afterRows) . " existing rows verified unchanged.\n";
}
$conn->query("CREATE TABLE IF NOT EXISTS tbl_class_schedule_tba_event (
    tba_event_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id INT UNSIGNED NULL,
    offering_id INT UNSIGNED NOT NULL,
    college_id INT UNSIGNED NOT NULL,
    changed_by INT UNSIGNED NOT NULL,
    previous_status VARCHAR(16) NOT NULL,
    new_status VARCHAR(16) NOT NULL,
    previous_values LONGTEXT NOT NULL,
    new_values LONGTEXT NOT NULL,
    date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tba_event_id), KEY idx_tba_event_schedule (schedule_id),
    KEY idx_tba_event_offering (offering_id),
    CONSTRAINT fk_tba_event_schedule FOREIGN KEY (schedule_id) REFERENCES tbl_class_schedule(schedule_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "TBA schedule schema is ready. Existing schedule values and workload assignments were preserved.\n";
