<?php
session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();

header('Content-Type: application/json; charset=utf-8');
$reply = static function (int $status, array $body): void {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    http_response_code($json === false ? 503 : $status);
    echo $json === false ? '{"message":"Kawani could not display the deletion review."}' : $json;
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $reply(405, ['message' => 'Use the Delete employee confirmation.']);
}
[$conn, $account] = synk_hris_require_admin();
if (!synk_hris_valid_post()) {
    $reply(403, ['message' => 'This form expired. Reload Employee Profiles before trying again.']);
}
$facultyId = filter_var($_POST['faculty_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($facultyId === false) $reply(422, ['message' => 'Select an employee record before trying again.']);
$operation = $_POST['operation'] ?? '';
if (!in_array($operation, ['review', 'delete'], true)) {
    $reply(422, ['message' => 'Open the deletion review from Employee Profiles.']);
}

$actorId = synk_hris_actor_id($account);
$fingerprint = null;
if ($operation === 'delete') {
    $review = $_SESSION['hris_employee_delete_review'] ?? null;
    $token = $_POST['review_token'] ?? '';
    if (!is_array($review) || !is_string($review['fingerprint'] ?? null) || !is_string($review['token'] ?? null)
        || !is_string($token) || !hash_equals($review['token'], $token) || $review['faculty_id'] !== $facultyId
        || $review['actor_id'] !== $actorId || $review['expires_at'] < time()) {
        $reply(409, ['message' => 'Review the employee details again before confirming deletion.']);
    }
    unset($_SESSION['hris_employee_delete_review']);
    $fingerprint = $review['fingerprint'];
} else {
    unset($_SESSION['hris_employee_delete_review']);
}

require_once __DIR__ . '/employee-delete-data.php';
try {
    $plan = synk_hris_employee_deletion($conn, $facultyId, $account, $fingerprint);
    if ($operation === 'review') {
        $preview = ['records' => $plan['records'], 'protected' => $plan['protected']];
        if ($plan['protected']) $reply(200, $preview);

        $token = bin2hex(random_bytes(24));
        $_SESSION['hris_employee_delete_review'] = [
            'token' => $token, 'faculty_id' => $facultyId, 'actor_id' => $actorId,
            'fingerprint' => $plan['fingerprint'], 'expires_at' => time() + 600,
        ];
        $reply(200, $preview + ['review_token' => $token]);
    }
} catch (DomainException $error) {
    $reply($error->getCode() ?: 409, ['message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Kawani employee deletion failed. ' . get_class($error) . ' ' . $error->getCode());
    $reply(503, ['message' => 'Kawani could not confirm that the employee can be deleted. Refresh Employee Profiles and try again.']);
}

$_SESSION['hris_employee_deleted'] = true;
$returnParams = array_filter(array_intersect_key($_GET, ['q' => true, 'filter' => true, 'category' => true, 'page' => true]), 'is_string');
$reply(200, ['url' => './?' . http_build_query(['view' => 'faculty'] + $returnParams)]);
