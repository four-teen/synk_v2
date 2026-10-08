<?php
// Run once per installation: php backend/tools/add_vpaa_di_roles.php
// Extends the existing enum without removing role values or changing its default.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../db.php';

$result = $conn->query("SHOW FULL COLUMNS FROM tbl_useraccount LIKE 'role'");
$column = $result->fetch_assoc();
$result->close();
if (!$column) {
    throw new RuntimeException('The account role column is missing.');
}

$oldType = (string)$column['Type'];
if (preg_match('/^(?:var)?char\((\d+)\)$/i', $oldType, $matches) && (int)$matches[1] >= 4) {
    echo "The account role column already accepts VPAA and DI.\n";
    exit;
}
if (stripos($oldType, 'enum(') !== 0 || substr($oldType, -1) !== ')') {
    throw new RuntimeException('Unsupported account role column type: ' . $oldType);
}

$newType = preg_replace(["/'vpaa'/i", "/'di'/i"], ["'VPAA'", "'DI'"], $oldType);
foreach (['VPAA', 'DI'] as $role) {
    if (strpos($newType, "'{$role}'") === false) {
        $newType = substr($newType, 0, -1) . ",'{$role}')";
    }
}
if ($newType === $oldType) {
    echo "VPAA and DI are already available in the account role enum.\n";
    exit;
}

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

if (!$conn->query('ALTER TABLE tbl_useraccount MODIFY COLUMN role ' . $definition)) {
    throw new RuntimeException('Unable to extend the account role column: ' . $conn->error);
}
echo "Added VPAA and DI; existing role values and the column default were preserved.\n";
