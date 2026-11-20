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
    foreach (['schema.sql', 'faculty-review.sql'] as $file) {
        $schema = file_get_contents(__DIR__ . '/' . $file);
        if ($schema === false) {
            throw new RuntimeException('HRIS schema file is unavailable.');
        }
        $conn->query($schema);
    }
    $conn->begin_transaction();
    $inTransaction = true;
    $stmt = $conn->prepare('SELECT user_id, status FROM tbl_useraccount WHERE LOWER(email) = ? LIMIT 2 FOR UPDATE');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $account = $result->num_rows === 1 ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$account || $account['status'] !== 'active') {
        throw new DomainException('Expected exactly one active Synk account for the supplied email.');
    }

    $userId = (int)$account['user_id'];
    synk_hris_save_role($conn, $userId, SYNK_HRIS_ADMIN);
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
