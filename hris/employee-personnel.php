<?php
session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();
[$conn, $account] = synk_hris_require_permission('employee_manage', true);
require_once __DIR__ . '/faculty-data.php';
require_once __DIR__ . '/personnel-data.php';

header('Content-Type: application/json; charset=utf-8');
$reply = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $reply(405, ['message' => 'Use the Personnel details form.']);
}
if (!synk_hris_valid_post()) {
    $reply(403, ['message' => 'This form expired. Reload Employee Profiles before trying again.']);
}

$owner = $_POST['record_key'] ?? '';
$revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483646]]);
if (!is_string($owner) || !preg_match('/\A(?:faculty|account|pds):[1-9][0-9]{0,9}\z/', $owner) || $revision === false) {
    $reply(422, ['message' => 'Reopen the employee record before saving personnel details.']);
}

try {
    if (!synk_hris_personnel_ready($conn)) {
        $reply(503, ['message' => 'Personnel details storage is unavailable.']);
    }
    $sources = synk_hris_faculty_sources($conn);
    if (!isset($sources['profiles'][$owner]) && !isset($sources['pds'][$owner])) {
        $reply(404, ['message' => 'This employee record is unavailable. Refresh Employee Profiles.']);
    }
    $classification = (string)($sources['profiles'][$owner]['employment_classification'] ?? '');
    [$values, $errors] = synk_hris_validate_personnel($_POST, synk_hris_position_catalog($conn), $classification);
    if ($errors) {
        $reply(422, ['message' => 'Review the highlighted fields and try again.', 'errors' => $errors]);
    }
    $conn->begin_transaction();
    try {
        if (strpos($owner, 'faculty:') === 0) synk_hris_lock_employee_record($conn, (int)substr($owner, 8));
        synk_hris_save_personnel_details($conn, $owner, $values, $revision, synk_hris_actor_id($account));
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} catch (DomainException $error) {
    $reply($error->getCode() === 404 ? 404 : 409, ['message' => $error->getMessage()]);
} catch (mysqli_sql_exception $error) {
    error_log('Kawani could not save personnel details. Database error ' . $error->getCode());
    $reply(503, ['message' => 'The personnel details could not be saved. Your entries are still here.']);
}

if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
    $reply(200, ['message' => 'Personnel details saved.']);
}

$_SESSION['hris_personnel_saved'] = true;
$returnParams = array_filter(array_intersect_key($_GET, ['q' => true, 'filter' => true, 'category' => true, 'page' => true]), 'is_string');
$reply(200, ['url' => './?' . http_build_query(['view' => 'faculty'] + $returnParams)]);
