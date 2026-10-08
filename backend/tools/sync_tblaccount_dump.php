<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth_useraccount.php';

const SYNK_ADMIN_EMAIL = 'elbrenantonio@sksu.edu.ph';
const SOURCE_TABLE_NAME = 'tblaccount';
const TEMP_TABLE_NAME = 'tmp_sync_tblaccount';

function sync_usage(): never
{
    fwrite(STDERR, "Usage: php backend/tools/sync_tblaccount_dump.php <path-to-tblaccount-sql>\n");
    exit(1);
}

function sync_extract_statements(string $dumpSql): array
{
    $statements = [];

    if (preg_match('/CREATE TABLE `' . SOURCE_TABLE_NAME . '`\s*\(.*?\)\s*ENGINE=.*?;/is', $dumpSql, $matches) !== 1) {
        throw new RuntimeException('Unable to find CREATE TABLE statement for tblaccount.');
    }

    $statements[] = str_replace('`' . SOURCE_TABLE_NAME . '`', '`' . TEMP_TABLE_NAME . '`', $matches[0]);

    if (preg_match_all('/INSERT INTO `' . SOURCE_TABLE_NAME . '`\s*\(.*?\)\s*VALUES\s*.*?;/is', $dumpSql, $matches) === false) {
        throw new RuntimeException('Unable to parse INSERT statements for tblaccount.');
    }

    foreach ($matches[0] as $statement) {
        $statements[] = str_replace('`' . SOURCE_TABLE_NAME . '`', '`' . TEMP_TABLE_NAME . '`', $statement);
    }

    if (count($statements) < 2) {
        throw new RuntimeException('No tblaccount data rows were found in the SQL dump.');
    }

    return $statements;
}

function sync_map_role(string $sourceRole): ?string
{
    $sourceRole = strtolower(trim($sourceRole));

    if ($sourceRole === 'administrator') {
        return 'admin';
    }

    if ($sourceRole === 'progchair') {
        return 'program_chair';
    }

    return null;
}

function sync_lookup_college_id(mysqli $conn, ?int $programId): ?int
{
    static $cache = [];

    if ($programId === null || $programId <= 0) {
        return null;
    }

    if (array_key_exists($programId, $cache)) {
        return $cache[$programId];
    }

    $stmt = $conn->prepare("SELECT college_id FROM tbl_program WHERE program_id = ? LIMIT 1");
    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $cache[$programId] = isset($row['college_id']) ? (int)$row['college_id'] : null;
    return $cache[$programId];
}

function sync_existing_college_id(mysqli $conn, string $email): ?int
{
    $stmt = $conn->prepare("SELECT college_id FROM tbl_useraccount WHERE LOWER(email) = ? LIMIT 1");
    $normalizedEmail = synk_normalize_email($email);
    $stmt->bind_param('s', $normalizedEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return isset($row['college_id']) ? (int)$row['college_id'] : null;
}

function sync_upsert_user(mysqli $conn, array $record): string
{
    $existing = synk_find_useraccount_by_email($conn, $record['email']);

    if ($existing) {
        $assignments = [
            'username = ?',
            'role = ?',
            'status = ?',
        ];
        $types = 'sss';
        $values = [
            $record['username'],
            $record['role'],
            $record['status'],
        ];

        if ($record['college_id'] === null) {
            $assignments[] = 'college_id = NULL';
        } else {
            $assignments[] = 'college_id = ?';
            $types .= 'i';
            $values[] = $record['college_id'];
        }

        if (synk_useraccount_has_column($conn, 'auth_provider')) {
            $assignments[] = 'auth_provider = ?';
            $types .= 's';
            $values[] = 'google';
        }

        if (synk_useraccount_has_column($conn, 'google_sub')) {
            $assignments[] = 'google_sub = NULL';
        }

        if (synk_useraccount_has_column($conn, 'google_email_verified')) {
            $assignments[] = 'google_email_verified = ?';
            $types .= 'i';
            $values[] = 0;
        }

        if (synk_useraccount_has_column($conn, 'last_google_name')) {
            $assignments[] = 'last_google_name = ?';
            $types .= 's';
            $values[] = $record['username'];
        }

        $types .= 'i';
        $values[] = (int)$existing['user_id'];

        $sql = "UPDATE tbl_useraccount SET " . implode(', ', $assignments) . " WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        synk_stmt_bind_params($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();

        return 'updated';
    }

    $columns = ['username', 'email', 'password', 'role', 'status'];
    $placeholders = ['?', '?', '?', '?', '?'];
    $types = 'sssss';
    $values = [
        $record['username'],
        $record['email'],
        synk_build_placeholder_password(),
        $record['role'],
        $record['status'],
    ];

    $columns[] = 'college_id';
    if ($record['college_id'] === null) {
        $placeholders[] = 'NULL';
    } else {
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = $record['college_id'];
    }

    if (synk_useraccount_has_column($conn, 'auth_provider')) {
        $columns[] = 'auth_provider';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = 'google';
    }

    if (synk_useraccount_has_column($conn, 'google_email_verified')) {
        $columns[] = 'google_email_verified';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = 0;
    }

    if (synk_useraccount_has_column($conn, 'last_google_name')) {
        $columns[] = 'last_google_name';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $record['username'];
    }

    $sql = "INSERT INTO tbl_useraccount (" . implode(', ', $columns) . ")
            VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $conn->prepare($sql);
    synk_stmt_bind_params($stmt, $types, $values);
    $stmt->execute();
    $stmt->close();

    return 'inserted';
}

function sync_deactivate_other_admins(mysqli $conn, string $adminEmail): int
{
    $normalizedEmail = synk_normalize_email($adminEmail);
    $stmt = $conn->prepare("
        UPDATE tbl_useraccount
        SET status = 'inactive'
        WHERE role = 'admin'
          AND LOWER(email) <> ?
    ");
    $stmt->bind_param('s', $normalizedEmail);
    $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    return $affectedRows;
}

$dumpPath = $argv[1] ?? '';
if ($dumpPath === '') {
    sync_usage();
}

$realPath = realpath($dumpPath);
if ($realPath === false || !is_file($realPath)) {
    throw new RuntimeException("SQL dump not found: {$dumpPath}");
}

$dumpSql = file_get_contents($realPath);
if ($dumpSql === false) {
    throw new RuntimeException("Unable to read SQL dump: {$realPath}");
}

$statements = sync_extract_statements($dumpSql);

$conn->begin_transaction();

try {
    $conn->query("DROP TABLE IF EXISTS `" . TEMP_TABLE_NAME . "`");
    foreach ($statements as $statement) {
        $conn->query($statement);
    }

    $result = $conn->query("
        SELECT
            accountid,
            acc_fullname,
            LOWER(TRIM(email)) AS email,
            approved,
            role,
            program_id,
            status
        FROM `" . TEMP_TABLE_NAME . "`
        WHERE LOWER(TRIM(email)) LIKE '%@sksu.edu.ph'
        ORDER BY accountid ASC
    ");

    $summary = [
        'inserted' => 0,
        'updated' => 0,
        'skipped_unsupported' => 0,
        'forced_inactive' => 0,
        'missing_college' => 0,
    ];

    while ($row = $result->fetch_assoc()) {
        $email = synk_normalize_email((string)$row['email']);
        $mappedRole = sync_map_role((string)$row['role']);

        if ($mappedRole === null) {
            $summary['skipped_unsupported']++;
            continue;
        }

        $status = ((int)$row['approved'] === 1 && (string)$row['status'] === 'active') ? 'active' : 'inactive';
        if ($status === 'inactive') {
            $summary['forced_inactive']++;
        }

        $collegeId = null;
        if ($mappedRole === 'scheduler') {
            $programId = isset($row['program_id']) ? (int)$row['program_id'] : null;
            $collegeId = sync_lookup_college_id($conn, $programId);

            if ($collegeId === null) {
                $collegeId = sync_existing_college_id($conn, $email);
            }

            if ($collegeId === null) {
                $summary['missing_college']++;
            }
        }

        if ($email === SYNK_ADMIN_EMAIL) {
            $mappedRole = 'admin';
            $status = 'active';
            $collegeId = null;
        }

        $syncStatus = sync_upsert_user($conn, [
            'username' => trim((string)$row['acc_fullname']),
            'email' => $email,
            'role' => $mappedRole,
            'college_id' => $collegeId,
            'status' => $status,
        ]);

        $summary[$syncStatus]++;
    }

    $deactivatedAdmins = sync_deactivate_other_admins($conn, SYNK_ADMIN_EMAIL);

    $conn->query("DROP TABLE IF EXISTS `" . TEMP_TABLE_NAME . "`");
    $conn->commit();

    echo "Sync completed.\n";
    echo "Inserted: {$summary['inserted']}\n";
    echo "Updated: {$summary['updated']}\n";
    echo "Skipped unsupported roles: {$summary['skipped_unsupported']}\n";
    echo "Forced inactive from approval/status: {$summary['forced_inactive']}\n";
    echo "Scheduler rows without derived college: {$summary['missing_college']}\n";
    echo "Other admin accounts deactivated: {$deactivatedAdmins}\n";
    echo "Primary admin email: " . SYNK_ADMIN_EMAIL . "\n";
} catch (Throwable $e) {
    $conn->rollback();
    $conn->query("DROP TABLE IF EXISTS `" . TEMP_TABLE_NAME . "`");
    throw $e;
}
