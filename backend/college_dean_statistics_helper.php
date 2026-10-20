<?php
require_once __DIR__ . '/college_dean_students_helper.php';
require_once __DIR__ . '/college_dean_profile_helper.php';
require_once __DIR__ . '/college_dean_workload_helper.php';

function synk_dean_statistics_terms(mysqli $conn, array $scope, array $current): array
{
    return synk_dean_term_catalog($conn);
}

function synk_dean_student_statistics(mysqli $conn, array $scope, array $term): array
{
    $statistics = ['available' => synk_dean_students_ready($conn), 'students' => 0, 'sections' => 0,
        'programs' => [], 'overlapping_programs' => false];
    $programs = synk_dean_students_query($conn, 'SELECT program_id,program_code,program_name,major,status
        FROM tbl_program WHERE college_id=? ORDER BY program_code,major,program_id', 'i', [$scope['college_id']]);
    $filters = ['ay_id' => (int)$term['ay_id'], 'semester' => (int)$term['semester']];
    $counts = [];
    if ($statistics['available'] && $filters['ay_id'] > 0 && $filters['semester'] > 0) {
        $source = synk_dean_students_source($scope, $filters, $conn);
        $statistics['students'] = (int)synk_dean_students_query($conn,
            "SELECT COUNT(DISTINCT s.student_id) AS total {$source['sql']}", $source['types'], $source['params'])[0]['total'];
        foreach (synk_dean_students_query($conn, "SELECT p.program_id,COUNT(DISTINCT s.student_id) AS students
            {$source['sql']} GROUP BY p.program_id", $source['types'], $source['params']) as $row) {
            $counts[(int)$row['program_id']] = (int)$row['students'];
        }
    }
    $sections = [];
    if ($filters['ay_id'] > 0 && $filters['semester'] > 0) {
        foreach (synk_dean_students_query($conn, "SELECT sec.program_id,COUNT(*) AS sections FROM tbl_sections sec
            JOIN tbl_program p ON p.program_id=sec.program_id WHERE p.college_id=? AND sec.ay_id=?
            AND sec.semester=? AND sec.status='active' GROUP BY sec.program_id", 'iii',
            [$scope['college_id'], $filters['ay_id'], $filters['semester']]) as $row) {
            $sections[(int)$row['program_id']] = (int)$row['sections'];
        }
    }
    foreach ($programs as $program) {
        $id = (int)$program['program_id'];
        if ($program['status'] !== 'active' && empty($counts[$id]) && empty($sections[$id])) continue;
        $label = trim((string)$program['program_code']) ?: $program['program_name'];
        if (trim((string)$program['major']) !== '') $label .= ' - ' . $program['major'];
        $statistics['programs'][] = ['label' => $label, 'students' => $counts[$id] ?? 0, 'sections' => $sections[$id] ?? 0];
        $statistics['sections'] += $sections[$id] ?? 0;
    }
    $statistics['overlapping_programs'] = array_sum(array_column($statistics['programs'], 'students')) > $statistics['students'];
    return $statistics;
}

function synk_dean_statistics_highest_degree(array $profile): string
{
    foreach (['doctoral' => 'Doctoral / PhD', 'master' => "Master's", 'bachelor' => "Bachelor's"] as $level => $label) {
        if (($profile[$level . '_status'] ?? '') === 'Completed' && trim((string)($profile[$level . '_degree'] ?? '')) !== '') return $label;
    }
    return 'Not recorded';
}

function synk_dean_faculty_statistics(mysqli $conn, array $scope, array $term): array
{
    $current = synk_fetch_current_academic_term($conn);
    $isCurrent = (int)$term['ay_id'] === (int)$current['ay_id'] && (int)$term['semester'] === (int)$current['semester'];
    if ($isCurrent) {
        $faculties = array_values(array_filter(synk_dean_profile_faculties($conn, $scope, $term), static function (array $row): bool {
            return in_array($row['status'], ['active', 'on_leave'], true);
        }));
    } else {
        $ids = array_column(synk_dean_workload_faculties($conn, $scope, $term), 'faculty_id');
        $faculties = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $faculties = synk_dean_students_query($conn, "SELECT f.faculty_id,COALESCE(l.user_id,0) AS profile_user_id
                FROM tbl_faculty f LEFT JOIN tbl_useraccount_faculty_links l ON l.faculty_id=f.faculty_id
                WHERE f.faculty_id IN ({$placeholders})", str_repeat('i', count($ids)), $ids);
            foreach ($faculties as &$faculty) { $faculty['faculty_id'] = (int)$faculty['faculty_id']; $faculty['profile_user_id'] = (int)$faculty['profile_user_id']; }
            unset($faculty);
        }
    }
    $statistics = ['faculty' => count($faculties), 'ranks' => [],
        'education' => ['Doctoral / PhD' => 0, "Master's" => 0, "Bachelor's" => 0, 'Not recorded' => 0]];
    if (!$faculties) return $statistics;
    $accountIds = array_values(array_unique(array_filter(array_column($faculties, 'profile_user_id'))));
    $profiles = [];
    if ($accountIds && synk_table_exists($conn, 'tbl_faculty_profiles')) {
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        foreach (synk_dean_students_query($conn, "SELECT user_id,faculty_rank,doctoral_degree,doctoral_status,
            master_degree,master_status,bachelor_degree,bachelor_status FROM tbl_faculty_profiles
            WHERE user_id IN ({$placeholders})", str_repeat('i', count($accountIds)), $accountIds) as $row) {
            $profiles[(int)$row['user_id']] = $row;
        }
    }
    $recordProfiles = [];
    if (synk_faculty_record_profiles_ready($conn)) {
        $facultyIds = array_column($faculties, 'faculty_id');
        $placeholders = implode(',', array_fill(0, count($facultyIds), '?'));
        foreach (synk_dean_students_query($conn, "SELECT faculty_id,profile_data FROM tbl_faculty_record_profiles
            WHERE faculty_id IN ({$placeholders})", str_repeat('i', count($facultyIds)), $facultyIds) as $row) {
            $decoded = json_decode((string)$row['profile_data'], true);
            if (!is_array($decoded)) throw new RuntimeException('Faculty qualifications could not be read.');
            $recordProfiles[(int)$row['faculty_id']] = $decoded;
        }
    }
    foreach ($faculties as $faculty) {
        $profile = $profiles[$faculty['profile_user_id']] ?? ($recordProfiles[$faculty['faculty_id']] ?? []);
        $rank = trim((string)($profile['faculty_rank'] ?? '')) ?: 'Not recorded';
        $statistics['ranks'][$rank] = ($statistics['ranks'][$rank] ?? 0) + 1;
        $statistics['education'][synk_dean_statistics_highest_degree($profile)]++;
    }
    $rankOrder = synk_table_exists($conn, 'tbl_faculty_ranks') ? array_keys(synk_faculty_profile_ranks($conn)) : [];
    uksort($statistics['ranks'], static function (string $left, string $right) use ($rankOrder): int {
        if ($left === $right) return 0;
        if ($left === 'Not recorded') return 1;
        if ($right === 'Not recorded') return -1;
        $leftIndex = array_search($left, $rankOrder, true);
        $rightIndex = array_search($right, $rankOrder, true);
        $result = ($leftIndex === false ? PHP_INT_MAX : $leftIndex) <=> ($rightIndex === false ? PHP_INT_MAX : $rightIndex);
        return $result ?: strnatcasecmp($left, $right);
    });
    return $statistics;
}
