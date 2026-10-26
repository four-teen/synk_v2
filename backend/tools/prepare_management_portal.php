<?php
// php backend/tools/prepare_management_portal.php
// Additive and repeatable. Does not create Management accounts or business data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db.php';
$existingFields = array_column($conn->query('SHOW COLUMNS FROM tbl_useraccount')->fetch_all(MYSQLI_ASSOC), 'Field');
$column = $conn->query("SHOW FULL COLUMNS FROM tbl_useraccount LIKE 'role'")->fetch_assoc();
if (!$column) throw new RuntimeException('The account role column is missing.');
$clauses = [];
if (stripos($column['Type'], 'enum(') === 0) {
    if (strpos($column['Type'], "'management'") === false) {
        $type = substr($column['Type'], 0, -1) . ",'management')";
        $definition = $type . (!empty($column['Collation']) ? ' COLLATE `' . $column['Collation'] . '`' : '');
        $definition .= $column['Null'] === 'YES' ? ' NULL' : ' NOT NULL';
        if ($column['Default'] !== null) $definition .= " DEFAULT '" . $conn->real_escape_string($column['Default']) . "'";
        elseif ($column['Null'] === 'YES') $definition .= ' DEFAULT NULL';
        if ($column['Extra'] !== '') throw new RuntimeException('Review the role column attributes before migrating.');
        $definition .= " COMMENT '" . $conn->real_escape_string($column['Comment'] ?? '') . "'";
        $clauses[] = 'MODIFY COLUMN role ' . $definition;
    }
} elseif (!preg_match('/^(?:var)?char\((\d+)\)$/i', $column['Type'], $match) || (int)$match[1] < 10) {
    throw new RuntimeException('The account role column cannot store Management.');
}
if (!$conn->query("SHOW COLUMNS FROM tbl_useraccount LIKE 'management_college_id'")->num_rows) {
    $clauses[] = 'ADD COLUMN management_college_id INT UNSIGNED NULL DEFAULT NULL AFTER college_id';
}
// Install missing Google identity metadata from the existing phase 4 contract.
// Defaults preserve legacy logins; no password or account values are rewritten.
foreach ([
    'auth_provider'=>"ENUM('legacy','google') NOT NULL DEFAULT 'legacy'",
    'google_sub'=>'VARCHAR(64) NULL DEFAULT NULL',
    'google_email_verified'=>'TINYINT(1) NOT NULL DEFAULT 0',
    'last_login_at'=>'DATETIME NULL DEFAULT NULL',
    'last_google_name'=>'VARCHAR(150) NULL DEFAULT NULL',
] as $field=>$definition) {
    if (!in_array($field, $existingFields, true)) $clauses[] = 'ADD COLUMN `' . $field . '` ' . $definition;
}
if (!$conn->query("SHOW INDEX FROM tbl_useraccount WHERE Key_name='uk_useraccount_google_sub'")->num_rows) {
    if (in_array('google_sub', $existingFields, true) && $conn->query("SELECT google_sub FROM tbl_useraccount WHERE google_sub IS NOT NULL GROUP BY google_sub HAVING COUNT(*)>1 LIMIT 1")->num_rows) {
        throw new RuntimeException('Review duplicate Google identities before installing the unique index.');
    }
    $clauses[] = 'ADD UNIQUE KEY uk_useraccount_google_sub (google_sub)';
}
if ($clauses) $conn->query('ALTER TABLE tbl_useraccount ' . implode(', ', $clauses));
echo $clauses ? "Management role, college assignment, and Google identity storage prepared. Existing account values were preserved.\n" : "Management schema is already installed.\n";
