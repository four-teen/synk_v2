<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/account-access.php';
$settings = synk_auth_settings();
$email = synk_normalize_email($argv[1] ?? '');
if ($argc !== 2 || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !synk_is_allowed_email_domain($email, (string)$settings['allowed_domain'])) {
    fwrite(STDERR, "Usage: php hris/grant-admin.php <registered university Google email>\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../backend/db.php';
$inTransaction = false;
try {
    foreach (['schema.sql', 'faculty-review.sql', 'faculty-status.sql', 'personnel.sql', 'employee-accounts.sql'] as $file) {
        $schema = file_get_contents(__DIR__ . '/' . $file);
        if ($schema === false) {
            throw new RuntimeException('HRIS schema file is unavailable.');
        }
        $conn->multi_query($schema);
        do {
            $result = $conn->store_result();
            if ($result instanceof mysqli_result) $result->close();
        } while ($conn->more_results() && $conn->next_result());
    }
    $conn->begin_transaction();
    $inTransaction = true;
    $existing = synk_hris_employee_account($conn, 'email', $email, true);
    $facultyId = (int)($existing['faculty_id'] ?? 0);
    $shared = synk_hris_matching_synk_account($conn, $email, $facultyId);
    if (!$shared) throw new DomainException('Expected an active Synk account for the supplied email.');
    $userId = (int)$shared['user_id'];
    if (!$existing && synk_table_exists($conn, 'tbl_useraccount_faculty_links')) {
        $stmt = $conn->prepare('SELECT faculty_id FROM tbl_useraccount_faculty_links WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $facultyId = (int)($stmt->get_result()->fetch_assoc()['faculty_id'] ?? 0);
        $stmt->close();
    }
    if (($existing['role_code'] ?? null) !== SYNK_HRIS_ADMIN || (int)($existing['synk_user_id'] ?? 0) !== $userId) {
        synk_hris_write_account($conn, $existing, $facultyId ?: null, $userId, $email, SYNK_HRIS_ADMIN, null);
    }
    $conn->commit();
    $inTransaction = false;
    echo "HRIS Administrator access is active for {$email}.\n";
} catch (Throwable $error) {
    if ($inTransaction) {
        $conn->rollback();
    }
    $message = $error instanceof DomainException ? $error->getMessage() : 'Could not install the HRIS role or grant access. Check the main database configuration and privileges.';
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
