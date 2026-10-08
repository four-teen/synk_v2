<?php

function synk_table_exists(mysqli $conn, string $tableName): bool
{
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $safeTableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTableName}'");
    $cache[$tableName] = ($result instanceof mysqli_result) && $result->num_rows > 0;

    return $cache[$tableName];
}

function synk_describe_columns(mysqli $conn, string $tableName): array
{
    static $cache = [];

    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    if (!synk_table_exists($conn, $tableName)) {
        $cache[$tableName] = [];
        return $cache[$tableName];
    }

    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `{$tableName}`");

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $fieldName = strtolower(trim((string)($row['Field'] ?? '')));
            if ($fieldName !== '') {
                $columns[$fieldName] = $row;
            }
        }
    }

    $cache[$tableName] = $columns;
    return $cache[$tableName];
}

function synk_table_has_column(mysqli $conn, string $tableName, string $columnName): bool
{
    $columns = synk_describe_columns($conn, $tableName);
    return isset($columns[strtolower($columnName)]);
}

function synk_table_has_index(mysqli $conn, string $tableName, string $indexName): bool
{
    if (!synk_table_exists($conn, $tableName)) {
        return false;
    }

    $safeTableName = $conn->real_escape_string($tableName);
    $safeIndexName = $conn->real_escape_string($indexName);
    $result = $conn->query("SHOW INDEX FROM `{$safeTableName}` WHERE Key_name = '{$safeIndexName}'");

    return ($result instanceof mysqli_result) && $result->num_rows > 0;
}

function synk_bind_dynamic_params(mysqli_stmt $stmt, string $types, array &$params): bool
{
    if ($types === '' || count($params) === 0) {
        return true;
    }

    $bindParams = [$types];
    foreach ($params as $index => &$value) {
        $bindParams[] = &$value;
    }

    return call_user_func_array([$stmt, 'bind_param'], $bindParams);
}
