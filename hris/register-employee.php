<?php
session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();
[$conn, $account] = synk_hris_require_permission('employee_manage', true);
require_once __DIR__ . '/employee-data.php';

header('Content-Type: application/json; charset=utf-8');
$reply = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $reply(405, ['message' => 'Use the New Employee form.']);
}
if (!synk_hris_valid_post()) {
    $reply(403, ['message' => 'This form expired. Reload Employee Profiles before trying again.']);
}

try {
    if (!synk_faculty_record_profiles_ready($conn) || !synk_hris_personnel_ready($conn)) {
        $reply(503, ['message' => 'Employee registration storage is unavailable.']);
    }
    [$ranks, $locations] = synk_hris_employee_catalog($conn);
    [$values, $errors] = synk_hris_validate_new_employee($_POST, $ranks, $locations, new DateTimeImmutable('today'));
    [$personnel, $personnelErrors] = synk_hris_validate_personnel($_POST, synk_hris_position_catalog($conn), $values['employment_classification']);
    $errors = $personnelErrors + $errors;
    $officialEmail = '';
    try {
        $officialEmail = synk_hris_official_email($_POST['official_email'] ?? '', (string)synk_auth_settings()['allowed_domain'], true);
    } catch (DomainException $error) {
        $errors['official_email'] = $error->getMessage();
    }
    if ($errors) {
        $reply(422, ['message' => 'Review the highlighted fields and try again.', 'errors' => $errors]);
    }
    if ($officialEmail !== '' && !synk_hris_employee_accounts_ready($conn)) {
        $reply(503, ['message' => 'Kawani email storage is unavailable. Your entries are still here.']);
    }

    synk_hris_create_employee($conn, $values, $personnel, synk_hris_actor_id($account), $officialEmail);
} catch (DomainException $error) {
    if ($error->getCode() === 422) $reply(422, ['message' => $error->getMessage(), 'errors' => ['official_email' => $error->getMessage()]]);
    $reply(409, ['message' => $error->getMessage(),
        'errors' => $error->getCode() === 409 ? ['employee_id' => $error->getMessage()] : []]);
} catch (Throwable $error) {
    error_log('Kawani could not register an employee. ' . get_class($error) . ' ' . $error->getCode());
    $reply(503, ['message' => 'The employee could not be saved. Your entries are still here.']);
}

$_SESSION['hris_employee_registered'] = true;
$reply(201, ['url' => './?' . http_build_query(['view' => 'faculty', 'q' => $values['faculty_id_number']])]);
