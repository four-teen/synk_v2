<?php

$databaseConfigPath = __DIR__ . '/db_config.local.php';
if (!is_file($databaseConfigPath)) {
    http_response_code(500);
    exit('Database configuration is missing. Configure backend/db_config.local.php for this workstation.');
}

$databaseConfig = require $databaseConfigPath;
if (!is_array($databaseConfig)) {
    http_response_code(500);
    exit('Invalid local database configuration.');
}

foreach (['host', 'username', 'password', 'database'] as $databaseConfigKey) {
    if (!array_key_exists($databaseConfigKey, $databaseConfig) || !is_string($databaseConfig[$databaseConfigKey])) {
        http_response_code(500);
        exit('Invalid local database configuration.');
    }
}

// Preserve the connection variables used by existing pages and the backup module.
$servername = $databaseConfig['host'];
$username = $databaseConfig['username'];
$password = $databaseConfig['password'];
$dbase = $databaseConfig['database'];

try {
    $conn = @mysqli_connect($servername, $username, $password, $dbase);
    if (!$conn || !mysqli_set_charset($conn, 'utf8mb4')) {
        http_response_code(500);
        exit('Database connection failed. Check the local database configuration.');
    }
} catch (mysqli_sql_exception $databaseConnectionException) {
    http_response_code(500);
    exit('Database connection failed. Check the local database configuration.');
}

// PH Timezone
date_default_timezone_set('Asia/Manila');
