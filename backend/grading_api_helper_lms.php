<?php
require_once __DIR__ . '/db_lms.php';
require_once __DIR__ . '/professor_class_helper_lms.php';
require_once __DIR__ . '/grading_helper_lms.php';

function synk_grading_request_lms(mysqli $conn, array $input): array
{
    if (($_SESSION['role'] ?? '') !== 'professor' || empty($_SESSION['user_id'])) throw new DomainException('Sign in as a professor to continue.', 401);
    if (empty($_SESSION['lms_grading_csrf']) || !is_string($input['csrf_token'] ?? null) || !hash_equals((string)$_SESSION['lms_grading_csrf'], $input['csrf_token'])) {
        throw new DomainException('Reload the grading workspace before continuing.', 403);
    }
    if (!is_array($input['context'] ?? null) || ($input['context']['view'] ?? '') !== 'grading') throw new DomainException('Open Grading system from your class workspace.', 403);
    $action = $input['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['get','save'], true)) throw new DomainException('Choose a valid grading action.', 400);
    $context = synk_professor_class_context_lms($conn, $input['context']);
    $lms = synk_database_connection_lms($conn);
    if (!synk_grading_ready_lms($lms)) throw new DomainException('The grading workspace is not ready yet. Contact your administrator.', 503);
    $payload = $input['payload'] ?? [];
    if (!is_array($payload)) throw new DomainException('Enter valid grading details.', 400);
    if (!$lms->begin_transaction()) throw new RuntimeException('Unable to begin the grading request.');
    try {
        if ($action === 'get') $data = ['schemes'=>[
            'midterm'=>synk_grading_detail_lms($lms,$context,'midterm'), 'final'=>synk_grading_detail_lms($lms,$context,'final')]];
        else $data = ['scheme'=>synk_grading_save_lms($lms, $context, $payload, (int)$_SESSION['user_id'])];
        if (!$lms->commit()) throw new RuntimeException('Unable to complete the grading request.');
        return $data;
    } catch (Throwable $error) {
        $lms->rollback();
        if ($error instanceof mysqli_sql_exception && in_array((int)$error->getCode(), [1062,1205,1213], true)) {
            throw new DomainException('This grading system is being updated in another tab. Reload this period before saving.', 409);
        }
        throw $error;
    }
}
