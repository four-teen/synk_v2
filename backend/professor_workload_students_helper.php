<?php
require_once __DIR__ . '/professor_workload_view_helper.php';
require_once __DIR__ . '/student_login_activity_helper.php';
require_once __DIR__ . '/student_profile_image_helper.php';

// Classes must come from the signed-in professor's current assignment query.
// Reuse the enrollment matcher, including legacy rows without an offering ID.
function synk_professor_workload_student_memberships(mysqli $conn, array $classes, int $ayId, int $semester): array
{
    $ids = array_column($classes, 'offering_id');
    if (!$ids) return [];
    $merged = synk_schedule_merge_load_display_context($conn, $ids);
    $groups = [];
    $lookup = [];
    foreach ($classes as $class) {
        $id = (int)$class['offering_id'];
        $groups[$id] = synk_normalize_offering_ids($merged[$id]['group_offering_ids'] ?? [$id]);
        $lookup = array_merge($lookup, $groups[$id]);
    }
    $members = synk_fetch_offering_imported_student_map($conn, $lookup, $ayId, $semester);
    $rosters = [];
    foreach ($groups as $id => $offerings) {
        $rosters[$id] = [];
        foreach ($offerings as $offering) {
            foreach ($members[$offering] ?? [] as $studentId => $present) $rosters[$id][$studentId] = true;
        }
    }
    return $rosters;
}

function synk_professor_workload_student_summary(mysqli $conn, array $classes, int $ayId, int $semester): array
{
    $rosters = synk_professor_workload_student_memberships($conn, $classes, $ayId, $semester);
    $students = [];
    foreach ($rosters as $members) $students += $members;
    // No term filter: Active means a successful Student sign-in at any time.
    $logins = synk_student_login_activity_counts($conn, array_keys($students));
    $available = synk_student_login_activity_ready($conn);
    $totals = ['active' => 0, 'inactive' => 0, 'students' => count($students)];
    $counts = [];
    foreach ($rosters as $offeringId => $members) {
        $active = 0;
        foreach ($members as $studentId => $present) $active += ($logins[$studentId]['login_success_count'] ?? 0) > 0 ? 1 : 0;
        $counts[$offeringId] = ['active' => $active, 'inactive' => count($members) - $active, 'students' => count($members)];
    }
    foreach ($students as $studentId => $present) {
        if (($logins[$studentId]['login_success_count'] ?? 0) > 0) $totals['active']++;
        else $totals['inactive']++;
    }
    return ['available' => $available, 'totals' => $totals, 'classes' => $counts];
}

function synk_professor_workload_students(mysqli $conn, int $facultyId, int $ayId, int $semester, int $offeringId): array
{
    // Reassignment, inactive faculty and forged class/term IDs are checked afresh.
    $classes = synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyId, $ayId, $semester);
    $class = null;
    foreach ($classes as $candidate) if ((int)$candidate['offering_id'] === $offeringId) $class = $candidate;
    if (!$class) throw new DomainException('This class is unavailable in your assigned workload.', 404);
    if (!synk_student_login_activity_ready($conn)) throw new DomainException('Student sign-in history is temporarily unavailable. Please try again later.', 503);
    $memberships = synk_professor_workload_student_memberships($conn, [$class], $ayId, $semester);
    $ids = array_keys($memberships[$offeringId] ?? []);
    $students = [];
    if ($ids) {
        $logins = synk_student_login_activity_counts($conn, $ids);
        $stmt = $conn->prepare('SELECT student_id,student_number,first_name,middle_name,last_name,suffix_name
            FROM tbl_student_management WHERE student_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
            ORDER BY last_name,first_name,middle_name,student_id');
        try {
            synk_bind_dynamic_params($stmt, str_repeat('i', count($ids)), $ids);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $student) {
                $id = (int)$student['student_id'];
                $parts = array_map(static function ($part): string { return trim((string)$part); },
                    [$student['first_name'], $student['middle_name'], $student['last_name'], $student['suffix_name']]);
                $name = implode(' ', array_filter($parts, 'strlen'));
                $students[] = ['student_id' => $id, 'student_number' => (string)$student['student_number'],
                    'full_name' => $name !== '' ? $name : 'Student ' . $id,
                    'photo_url' => synk_student_profile_image_url($id) ?: '../student/profile-placeholder.svg',
                    'active' => ($logins[$id]['login_success_count'] ?? 0) > 0];
            }
        } finally { $stmt->close(); }
    }
    $active = count(array_filter($students, static function (array $student): bool { return $student['active']; }));
    return ['class' => ['offering_id' => $offeringId, 'ay_id' => $ayId, 'semester' => $semester,
            'subject_code' => $class['subject_code'], 'title' => $class['descriptive_title'],
            'section' => $class['section_display'], 'enrolled' => $class['student_count']],
        'counts' => ['active' => $active, 'inactive' => count($students) - $active, 'students' => count($students)],
        'students' => $students];
}
