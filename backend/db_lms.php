<?php

// Separate LMS connection. Never replace the core application's $conn.
function synk_database_connection_lms(mysqli $coreConnection): mysqli
{
    static $lmsConnection = null;
    if ($lmsConnection instanceof mysqli) return $lmsConnection;

    $path = __DIR__ . '/db_config.local_lms.php';
    if (!is_file($path)) throw new RuntimeException('LMS database configuration is missing.');
    $settings = require $path;
    if (!is_array($settings)) throw new RuntimeException('LMS database configuration is invalid.');
    foreach (['host', 'username', 'password', 'database'] as $key) {
        if (!isset($settings[$key]) || !is_string($settings[$key])) throw new RuntimeException('LMS database configuration is invalid.');
    }
    $coreDatabase = (string)($coreConnection->query('SELECT DATABASE() AS database_name')->fetch_assoc()['database_name'] ?? '');
    if (trim($settings['database']) === '' || strcasecmp($coreDatabase, $settings['database']) === 0) {
        throw new RuntimeException('LMS storage must use a separate database.');
    }
    try {
        $connection = @mysqli_connect($settings['host'], $settings['username'], $settings['password'], $settings['database']);
        if (!$connection || !$connection->set_charset('utf8mb4')) throw new RuntimeException('Unable to open LMS storage.');
    } catch (Throwable $error) {
        throw new RuntimeException('LMS storage is temporarily unavailable.');
    }
    $lmsConnection = $connection;
    return $lmsConnection;
}
