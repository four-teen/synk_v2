<?php
// Repeatable, additive organization assignment and violation-term migration.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db.php';
function management_migration_column(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->bind_param('ss',$table,$column); $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0; $stmt->close(); return $found;
}
function management_migration_constraint(mysqli $conn, string $table, string $name, string $definition): void {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
    $stmt->bind_param('ss',$table,$name); $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0; $stmt->close();
    if (!$found) $conn->query('ALTER TABLE '.$table.' ADD CONSTRAINT '.$name.' '.$definition);
}
if (!management_migration_column($conn,'tbl_useraccount','management_organization_id')) {
    $conn->query('ALTER TABLE tbl_useraccount ADD management_organization_id INT UNSIGNED NULL AFTER management_college_id');
}
management_migration_constraint($conn,'tbl_useraccount','fk_user_management_org',
    'FOREIGN KEY (management_college_id,management_organization_id) REFERENCES tbl_management_organizations (college_id,organization_id) ON DELETE RESTRICT');
foreach (['organization_id'=>'INT UNSIGNED NULL','ay_id'=>'INT UNSIGNED NULL','semester'=>'TINYINT UNSIGNED NULL'] as $column=>$definition) {
    if (!management_migration_column($conn,'tbl_management_violations',$column)) $conn->query('ALTER TABLE tbl_management_violations ADD '.$column.' '.$definition);
}
management_migration_constraint($conn,'tbl_management_violations','fk_management_violation_org',
    'FOREIGN KEY (college_id,organization_id) REFERENCES tbl_management_organizations (college_id,organization_id) ON DELETE RESTRICT');
management_migration_constraint($conn,'tbl_management_violations','fk_management_violation_ay',
    'FOREIGN KEY (ay_id) REFERENCES tbl_academic_years (ay_id) ON DELETE RESTRICT');
management_migration_constraint($conn,'tbl_management_violations','uq_management_violation_org_term',
    'UNIQUE (organization_id,ay_id,semester,violation_type)');
if ($conn->query("SHOW INDEX FROM tbl_management_violations WHERE Key_name='uq_management_violation_type'")->num_rows) {
    $conn->query('ALTER TABLE tbl_management_violations DROP INDEX uq_management_violation_type');
}
// Explicit opt-in only: the user approved the single existing CCS account -> CSSO.
if (in_array('--assign-existing-ccs-to-csso',$argv,true)) {
    $conn->begin_transaction();
    try {
        $orgs = $conn->query("SELECT o.organization_id,o.college_id FROM tbl_management_organizations o JOIN tbl_college c ON c.college_id=o.college_id JOIN tbl_campus p ON p.campus_id=c.campus_id WHERE o.organization_code='CSSO' AND c.college_code='CCS' AND c.college_id=1 AND c.campus_id=1 AND UPPER(TRIM(p.campus_name)) IN ('ISULAN','ISULAN CAMPUS') FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        if (count($orgs)!==1) throw new RuntimeException('Expected one ISULAN / CCS / CSSO organization.');
        $college = (int)$orgs[0]['college_id']; $org = (int)$orgs[0]['organization_id'];
        $accounts = $conn->query("SELECT user_id FROM tbl_useraccount u WHERE management_college_id=$college AND management_organization_id IS NULL AND (u.role='management' OR EXISTS (SELECT 1 FROM tbl_useraccount_roles r WHERE r.user_id=u.user_id AND r.role='management' AND r.status='active')) FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        if (count($accounts)>1) throw new RuntimeException('Multiple unassigned accounts found; assign them individually in Administrator.');
        if ($accounts) { $id=(int)$accounts[0]['user_id']; $conn->query("UPDATE tbl_useraccount SET management_organization_id=$org WHERE user_id=$id AND management_organization_id IS NULL"); }
        $conn->commit(); echo count($accounts)." existing Management account assigned to CSSO.\n";
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
echo "Organization assignment and violation term storage prepared. Existing catalog values preserved.\n";
