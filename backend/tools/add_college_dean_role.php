<?php
// Run once per installation: php backend/tools/add_college_dean_role.php
// Add the role and its independent college assignment without modifying accounts.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth_useraccount.php';

$result = $conn->query("SHOW FULL COLUMNS FROM tbl_useraccount LIKE 'role'");
$column = $result->fetch_assoc();
$result->close();
if (!$column) throw new RuntimeException('The account role column is missing.');

$oldType = (string)$column['Type'];
$newType = $oldType;
if (stripos($oldType, 'enum(') === 0 && substr($oldType, -1) === ')') {
    // Older installations omit current module roles from this primary-role enum.
    // Each supported module must remain selectable as the default for a shared account.
    foreach (synk_supported_module_roles() as $role) {
        if (stripos($newType, "'{$role}'") === false) {
            $newType = substr($newType, 0, -1) . ",'{$role}')";
        }
    }
} elseif (!preg_match('/^(?:var)?char\((\d+)\)$/i', $oldType, $matches) || (int)$matches[1] < strlen('college_dean')) {
    throw new RuntimeException('Unsupported account role column type: ' . $oldType);
}

$scopeResult = $conn->query("SHOW COLUMNS FROM tbl_useraccount LIKE 'college_dean_college_id'");
$scopeExists = $scopeResult->num_rows > 0;
$scopeResult->close();
$clauses = [];
if ($newType !== $oldType) {
    $definition = $newType;
    if (!empty($column['Collation'])) {
        $definition .= ' COLLATE `' . str_replace('`', '``', (string)$column['Collation']) . '`';
    }
    $definition .= $column['Null'] === 'YES' ? ' NULL' : ' NOT NULL';
    if ($column['Default'] !== null) {
        $definition .= " DEFAULT '" . $conn->real_escape_string((string)$column['Default']) . "'";
    } elseif ($column['Null'] === 'YES') {
        $definition .= ' DEFAULT NULL';
    }
    if ((string)$column['Extra'] !== '') {
        throw new RuntimeException('Review the account role column extra attributes before migrating.');
    }
    $definition .= " COMMENT '" . $conn->real_escape_string((string)($column['Comment'] ?? '')) . "'";
    $clauses[] = 'MODIFY COLUMN role ' . $definition;
}
if (!$scopeExists) {
    $clauses[] = 'ADD COLUMN college_dean_college_id INT UNSIGNED NULL DEFAULT NULL AFTER college_id';
}

if ($clauses) {
    $conn->query('ALTER TABLE tbl_useraccount ' . implode(', ', $clauses));
    echo "Added College Dean and its independent college assignment. Existing accounts and role defaults were preserved.\n";
} else {
    echo "College Dean schema is already installed.\n";
}
