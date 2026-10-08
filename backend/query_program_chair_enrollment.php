<?php
session_start();

include 'db.php';
require_once __DIR__ . '/program_chair_helper.php';
require_once __DIR__ . '/academic_term_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'program_chair') {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized access.'
    ]);
    exit;
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
if ($collegeId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing college assignment.'
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
        'message' => 'Academic settings are not configured.'
    ]);
    exit;
}

if (isset($_GET['load_section_offerings'])) {
    $sectionId = (int)($_GET['section_id'] ?? 0);
    if ($sectionId <= 0) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid section.'
        ]);
        exit;
    }

    $sectionRows = synk_program_chair_fetch_section_rows($conn, $collegeId, $ayId, $semester);
    $sectionMeta = null;
    foreach ($sectionRows as $sectionRow) {
        if ((int)($sectionRow['section_id'] ?? 0) === $sectionId) {
            $sectionMeta = $sectionRow;
            break;
        }
    }

    if (!$sectionMeta) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Section not found for the current term.'
        ]);
        exit;
    }

    $rows = synk_program_chair_fetch_section_scheduled_offerings($conn, $collegeId, $sectionId, $ayId, $semester);
    echo json_encode([
        'status' => 'ok',
        'meta' => [
            'section_id' => (int)($sectionMeta['section_id'] ?? 0),
            'program_id' => (int)($sectionMeta['program_id'] ?? 0),
            'section_display' => (string)($sectionMeta['section_display'] ?? ''),
            'section_name' => (string)($sectionMeta['section_name'] ?? ''),
            'full_section' => (string)($sectionMeta['full_section'] ?? ''),
            'year_level' => (int)($sectionMeta['year_level'] ?? 0),
            'program_code' => (string)($sectionMeta['program_code'] ?? ''),
            'program_name' => (string)($sectionMeta['program_name'] ?? ''),
            'major' => (string)($sectionMeta['major'] ?? ''),
            'semester' => $semester,
            'semester_label' => synk_program_chair_semester_basis_label($semester),
            'ay_id' => $ayId,
            'ay_label' => (string)($currentTerm['ay_label'] ?? ''),
        ],
        'rows' => $rows
    ]);
    exit;
}

echo json_encode([
    'status' => 'error',
    'message' => 'Invalid request.'
]);
exit;
