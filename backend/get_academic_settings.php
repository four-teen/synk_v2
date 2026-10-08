<?php
/*
|--------------------------------------------------------------------------
| GET CURRENT ACADEMIC SETTINGS
|--------------------------------------------------------------------------
| Purpose:
| - Returns the currently active academic year & semester
| - Used by Academic Settings UI and dashboards
|--------------------------------------------------------------------------
*/

session_start();
include 'db.php';
require_once __DIR__ . '/academic_schedule_policy_helper.php';
require_once __DIR__ . '/signatory_settings_helper.php';
header('Content-Type: application/json');

$schedulePolicy = synk_fetch_schedule_policy($conn);
$signatorySettings = synk_fetch_signatory_settings($conn, 'global', 0);
$selectedCollegeId = (int)($_GET['college_id'] ?? 0);
$overrideColleges = synk_fetch_colleges_using_schedule_overrides($conn);
$collegeOverridePolicy = $selectedCollegeId > 0
    ? synk_fetch_college_schedule_policy_settings($conn, $selectedCollegeId)
    : null;
$collegeEffectivePolicy = $selectedCollegeId > 0
    ? synk_fetch_effective_schedule_policy($conn, $selectedCollegeId)
    : null;

$sql = "
    SELECT 
        s.current_ay_id,
        ay.ay AS academic_year,
        s.current_semester
    FROM tbl_academic_settings s
    JOIN tbl_academic_years ay ON ay.ay_id = s.current_ay_id
    LIMIT 1
";

$res = mysqli_query($conn, $sql);

if ($row = mysqli_fetch_assoc($res)) {

    $semester_map = [
        1 => '1st Semester',
        2 => '2nd Semester',
        3 => 'Midyear'
    ];

    echo json_encode([
        'status' => 'success',
        'current_ay_id' => (int)($row['current_ay_id'] ?? 0),
        'current_semester' => (int)($row['current_semester'] ?? 0),
        'academic_year' => $row['academic_year'],
        'semester' => $semester_map[$row['current_semester']] ?? 'Unknown',
        'schedule_policy' => $schedulePolicy,
        'signatory_settings' => $signatorySettings,
        'override_colleges' => $overrideColleges,
        'selected_college_id' => $selectedCollegeId,
        'college_override_policy' => $collegeOverridePolicy,
        'college_effective_policy' => $collegeEffectivePolicy
    ]);
} else {
    echo json_encode([
        'status' => 'error',
        'message' => 'Academic settings not found.',
        'schedule_policy' => $schedulePolicy,
        'signatory_settings' => $signatorySettings,
        'override_colleges' => $overrideColleges,
        'selected_college_id' => $selectedCollegeId,
        'college_override_policy' => $collegeOverridePolicy,
        'college_effective_policy' => $collegeEffectivePolicy
    ]);
}
