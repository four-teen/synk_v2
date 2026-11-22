<?php
session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
[$conn, $account] = synk_hris_require_admin();
require_once __DIR__ . '/faculty-data.php';
require_once __DIR__ . '/faculty-status-data.php';

$owner = $_POST['record_key'] ?? '';
$status = $_POST['status'] ?? '';
$revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483646]]);
$confirmed = ($_POST['verified_by_hr'] ?? '') === '1';
$options = synk_hris_faculty_statuses();
$responseStatus = 200;

try {
    if (!synk_hris_valid_post()) throw new DomainException('This form expired. Review the status and try again.', 403);
    if (!is_string($status) || !isset($options[$status])) throw new DomainException('Select an HR status.');
    if (!is_string($owner) || !preg_match('/\A(?:faculty|account|pds):[1-9][0-9]{0,9}\z/', $owner)
        || $revision === false) {
        throw new DomainException('Open the faculty record again before saving its status.');
    }
    $sources = synk_hris_faculty_sources($conn);
    if (!isset($sources['profiles'][$owner]) && !isset($sources['pds'][$owner])) {
        throw new DomainException('This faculty record is unavailable. Reopen the directory and check the record.', 404);
    }

    $conn->begin_transaction();
    try {
        if (strpos($owner, 'faculty:') === 0) synk_hris_lock_employee_record($conn, (int)substr($owner, 8));
        synk_hris_save_faculty_status($conn, $owner, $status, $revision, synk_hris_actor_id($account), $confirmed);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    $flash = ['kind' => 'success', 'message' => 'HR status saved as ' . $options[$status][0] . '.'];
} catch (DomainException $error) {
    $responseStatus = $error->getCode() ?: 422;
    $flash = ['kind' => 'error', 'message' => $error->getMessage()];
} catch (mysqli_sql_exception $error) {
    error_log('Kawani faculty status could not be saved. Database error ' . $error->getCode());
    $responseStatus = 503;
    $flash = ['kind' => 'error', 'message' => 'The HR status could not be saved. Try again.'];
}

if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($responseStatus);
    echo json_encode($flash, JSON_UNESCAPED_UNICODE);
    exit;
}

$_SESSION['hris_faculty_status_flash'] = $flash;
$returnParams = array_filter(array_intersect_key($_GET, ['q' => true, 'filter' => true, 'category' => true, 'page' => true]), 'is_string');
header('Location: ./?' . http_build_query(['view' => 'faculty'] + $returnParams), true, 303);
