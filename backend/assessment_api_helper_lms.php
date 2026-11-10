<?php
require_once __DIR__ . '/db_lms.php';
require_once __DIR__ . '/professor_class_helper_lms.php';
require_once __DIR__ . '/assessment_helper_lms.php';
require_once __DIR__ . '/assessment_delivery_helper_lms.php';

function synk_assessment_request_lms(mysqli $conn, array $input): array
{
    if (($_SESSION['role'] ?? '') !== 'professor' || empty($_SESSION['user_id'])) throw new DomainException('Sign in as a professor to continue.', 401);
    if (empty($_SESSION['lms_assessment_csrf']) || !is_string($input['csrf_token'] ?? null)
        || !hash_equals((string)$_SESSION['lms_assessment_csrf'], $input['csrf_token'])) throw new DomainException('Reload the assessment workspace before continuing.', 403);
    if (!is_array($input['context'] ?? null) || ($input['context']['view'] ?? '') !== 'assessments') throw new DomainException('Open Activities & assessments from your class workspace.', 403);
    $action = $input['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['list','detail','save','duplicate','archive','publish','close','submissions'], true)) throw new DomainException('Choose a valid assessment action.', 400);
    $context = synk_professor_class_context_lms($conn, $input['context']);
    $lms = synk_database_connection_lms($conn);
    if (!synk_assessment_ready_lms($lms)) throw new DomainException('The assessment workspace is not ready yet. Contact your administrator.', 503);
    $payload = $input['payload'] ?? [];
    if (!is_array($payload)) throw new DomainException('Enter valid assessment details.', 400);
    if ($action === 'list') return ['assessments'=>synk_assessment_list_with_participation_lms($conn, $lms, $context)];
    if ($action === 'detail') return ['assessment'=>synk_assessment_detail_lms($lms, $context, synk_assessment_id_lms($payload['assessment_id'] ?? null))];
    if (in_array($action,['publish','close','submissions'],true) && !synk_assessment_delivery_ready_lms($lms)) throw new DomainException('Assessment delivery storage is not ready yet.',503);
    if ($action === 'submissions') return ['submissions'=>synk_assessment_submissions_lms($conn,$lms,$context,synk_assessment_id_lms($payload['assessment_id']??null))];
    if (!$lms->begin_transaction()) throw new RuntimeException('Unable to begin an assessment update.');
    try {
        $actor = (int)$_SESSION['user_id'];
        if ($action === 'save') $data = ['assessment'=>synk_assessment_save_lms($lms, $context, $payload, $actor)];
        elseif ($action === 'publish') $data = ['assessment'=>synk_assessment_publish_lms($lms,$context,$payload,$actor)];
        elseif ($action === 'close') $data = ['assessment'=>synk_assessment_close_lms($lms,$context,$payload,$actor)];
        elseif ($action === 'duplicate') $data = ['assessment'=>synk_assessment_duplicate_lms($lms, $context, $payload, $actor)];
        else { synk_assessment_archive_lms($lms, $context, $payload, $actor); $data = []; }
        if (!$lms->commit()) throw new RuntimeException('Unable to save the assessment update.');
        return $data;
    } catch (Throwable $error) { $lms->rollback(); throw $error; }
}
