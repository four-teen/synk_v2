<?php
session_start();

include 'db.php';
require_once __DIR__ . '/program_chair_helper.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/enrollment_draft_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'program_chair') {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized access.',
        'code' => 'unauthorized',
    ]);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$collegeId = (int)($_SESSION['college_id'] ?? 0);
if ($userId <= 0 || $collegeId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing Program Chair college assignment.',
        'code' => 'missing_college_scope',
    ]);
    exit;
}

if (!synk_enrollment_draft_tables_ready($conn)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Enrollment draft tables are not ready yet. Run the provided SQL first.',
        'code' => 'schema_error',
    ]);
    exit;
}

$currentTerm = synk_fetch_current_academic_term($conn);
$ayId = (int)($currentTerm['ay_id'] ?? 0);
$semester = (int)($currentTerm['semester'] ?? 0);
if ($ayId <= 0 || $semester <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Current academic term is not configured.',
        'code' => 'missing_academic_term',
    ]);
    exit;
}

function synk_program_chair_draft_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function synk_program_chair_draft_payload(): array
{
    $draftItemsJson = (string)($_POST['draft_items_json'] ?? $_POST['draft_items'] ?? '[]');
    $draftItems = json_decode($draftItemsJson, true);
    if (!is_array($draftItems)) {
        $draftItems = [];
    }

    return [
        'enrollment_id' => (int)($_POST['enrollment_id'] ?? 0),
        'program_id' => (int)($_POST['program_id'] ?? 0),
        'section_id' => (int)($_POST['section_id'] ?? 0),
        'enrollment_type' => (string)($_POST['enrollment_type'] ?? 'regular'),
        'student_record_mode' => (string)($_POST['student_record_mode'] ?? 'first_year'),
        'student_id' => (int)($_POST['student_id'] ?? 0),
        'student_number' => (string)($_POST['student_number'] ?? ''),
        'last_name' => (string)($_POST['last_name'] ?? ''),
        'first_name' => (string)($_POST['first_name'] ?? ''),
        'middle_name' => (string)($_POST['middle_name'] ?? ''),
        'suffix_name' => (string)($_POST['suffix_name'] ?? ''),
        'sex' => (string)($_POST['sex'] ?? ''),
        'birthdate' => (string)($_POST['birthdate'] ?? ''),
        'email_address' => (string)($_POST['email_address'] ?? ''),
        'contact_number' => (string)($_POST['contact_number'] ?? ''),
        'verification_note' => (string)($_POST['verification_note'] ?? ''),
        'chair_notes' => (string)($_POST['chair_notes'] ?? ''),
        'draft_items' => $draftItems,
    ];
}

if (isset($_POST['save_draft'])) {
    $payload = synk_program_chair_draft_payload();
    if ((int)($payload['program_id'] ?? 0) <= 0 || (int)($payload['section_id'] ?? 0) <= 0) {
        synk_program_chair_draft_response([
            'status' => 'error',
            'message' => 'Select the program and section first before saving the draft.',
            'code' => 'missing_setup',
        ], 400);
    }

    $sectionContext = synk_enrollment_fetch_section_context(
        $conn,
        (int)$payload['section_id'],
        $collegeId,
        $ayId,
        $semester
    );

    if (!$sectionContext || (int)($sectionContext['program_id'] ?? 0) !== (int)$payload['program_id']) {
        synk_program_chair_draft_response([
            'status' => 'error',
            'message' => 'The selected section does not match the chosen program for the current term.',
            'code' => 'invalid_section',
        ], 400);
    }

    $liveOfferingRows = synk_program_chair_fetch_section_scheduled_offerings(
        $conn,
        $collegeId,
        (int)$payload['section_id'],
        $ayId,
        $semester
    );
    $subjectRows = synk_enrollment_normalize_subject_selection((array)($payload['draft_items'] ?? []), $liveOfferingRows);

    $saveResult = synk_enrollment_save_program_chair_draft(
        $conn,
        $userId,
        $collegeId,
        $sectionContext,
        $payload,
        $subjectRows,
        (int)($payload['enrollment_id'] ?? 0)
    );

    if (isset($saveResult['error'])) {
        synk_program_chair_draft_response([
            'status' => 'error',
            'message' => 'The enrollment draft could not be saved right now.',
            'code' => (string)$saveResult['error'],
        ], 400);
    }

    synk_program_chair_draft_response([
        'status' => 'ok',
        'message' => 'Enrollment draft saved.',
        'detail' => $saveResult['detail'],
    ]);
}

if (isset($_POST['submit_draft'])) {
    $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
    if ($enrollmentId <= 0) {
        synk_program_chair_draft_response([
            'status' => 'error',
            'message' => 'Save the draft first before submitting it to registrar.',
            'code' => 'missing',
        ], 400);
    }

    $submitResult = synk_enrollment_submit_program_chair_draft($conn, $enrollmentId, $userId, $collegeId);
    if (isset($submitResult['error'])) {
        $code = (string)$submitResult['error'];
        $messageMap = [
            'draft_not_found' => 'That draft could not be found or is no longer editable.',
            'submit_not_allowed' => 'Only draft or returned records can be submitted to registrar.',
            'missing_setup' => 'Program and section must be complete before submission.',
            'missing_student' => 'Complete the student profile before submitting to registrar.',
            'missing_subjects' => 'Add at least one scheduled subject before submitting to registrar.',
            'save_failed' => 'The draft could not be submitted right now.',
        ];

        synk_program_chair_draft_response([
            'status' => 'error',
            'message' => $messageMap[$code] ?? 'The draft could not be submitted right now.',
            'code' => $code,
        ], 400);
    }

    synk_program_chair_draft_response([
        'status' => 'ok',
        'message' => 'Draft submitted to registrar queue.',
        'detail' => $submitResult['detail'],
    ]);
}

if (isset($_GET['detail'])) {
    $enrollmentId = (int)($_GET['enrollment_id'] ?? 0);
    if ($enrollmentId <= 0) {
        synk_program_chair_draft_response([
            'status' => 'error',
            'message' => 'Invalid enrollment draft.',
            'code' => 'missing',
        ], 400);
    }

    $detail = synk_enrollment_fetch_program_chair_draft_detail($conn, $enrollmentId, $userId, $collegeId, false);
    if (!$detail) {
        synk_program_chair_draft_response([
            'status' => 'error',
            'message' => 'Draft not found.',
            'code' => 'draft_not_found',
        ], 404);
    }

    synk_program_chair_draft_response([
        'status' => 'ok',
        'detail' => $detail,
    ]);
}

synk_program_chair_draft_response([
    'status' => 'error',
    'message' => 'Invalid request.',
    'code' => 'invalid_request',
], 400);
