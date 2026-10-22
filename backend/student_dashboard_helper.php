<?php
require_once __DIR__ . '/student_portal_helper.php';
require_once __DIR__ . '/faculty_profile_image_helper.php';
require_once __DIR__ . '/student_workload_helper.php';

function synk_student_dashboard_terms(mysqli $conn, int $studentId): array
{
    $current = synk_fetch_current_academic_term($conn);
    $terms = [];
    $previewScopeSql = synk_student_preview_subject_scope_sql($conn);
    $stmt = $conn->prepare("SELECT es.ay_id,es.semester,ay.ay AS ay_label,COUNT(*) AS subject_count
        FROM tbl_student_management_enrolled_subjects es JOIN tbl_academic_years ay ON ay.ay_id=es.ay_id
        WHERE es.student_id=? AND es.is_active=1 AND es.semester BETWEEN 1 AND 3
        {$previewScopeSql}
        GROUP BY es.ay_id,es.semester,ay.ay ORDER BY ay.ay DESC,es.semester DESC");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $row['ay_id'] = (int)$row['ay_id']; $row['semester'] = (int)$row['semester'];
        $row['key'] = $row['ay_id'] . ':' . $row['semester'];
        $row['term_text'] = $row['ay_label'] . ' · ' . synk_semester_label($row['semester']);
        $row['is_current'] = $row['ay_id'] === (int)$current['ay_id'] && $row['semester'] === (int)$current['semester'];
        $terms[$row['key']] = $row;
    }
    $stmt->close();
    if ((int)$current['ay_id'] > 0 && in_array((int)$current['semester'], [1,2,3], true)) {
        $key = $current['ay_id'] . ':' . $current['semester'];
        if (!isset($terms[$key])) $terms[$key] = array_merge($current, ['key'=>$key,'subject_count'=>0,'is_current'=>true]);
    }
    return array_values($terms);
}

function synk_student_dashboard_view_term(int $studentId, array $terms, bool $preview = false): array
{
    if (!$terms) return ['ay_id'=>0,'semester'=>0,'term_text'=>'No academic term available','key'=>'','is_current'=>true,'subject_count'=>0];
    $byKey = array_column($terms, null, 'key');
    $requested = is_string($_GET['student_term'] ?? null) ? $_GET['student_term'] : '';
    if ($requested !== '' && isset($byKey[$requested])) {
        if (!$preview) $_SESSION['student_view_term'] = ['student_id'=>$studentId,'key'=>$requested];
        return $byKey[$requested];
    }
    $saved = $_SESSION['student_view_term'] ?? [];
    if (!$preview && (int)($saved['student_id'] ?? 0) === $studentId && isset($byKey[$saved['key'] ?? ''])) return $byKey[$saved['key']];
    foreach ($terms as $term) if ($term['is_current'] && (int)$term['subject_count'] > 0) return $term;
    foreach ($terms as $term) if ((int)$term['subject_count'] > 0) return $term;
    return $terms[0];
}

function synk_student_dashboard_subjects(mysqli $conn, int $studentId, array $term): array
{
    $rows = synk_student_fetch_subject_rows_by_academic_year($conn, $studentId, (int)$term['ay_id'], (int)$term['semester']);
    $images = synk_student_dashboard_faculty_images($conn, array_column($rows, 'faculty_id'));
    $unique = [];
    foreach ($rows as $row) {
        $row['faculty_image_url'] = $images[(int)$row['faculty_id']] ?? '';
        $key = strtolower(implode("\n", [$row['subject_code'],$row['descriptive_title'],$row['section_display'],
            (string)$row['faculty_id'],$row['room_name'],$row['schedule_text']]));
        if (!isset($unique[$key])) $unique[$key] = $row;
    }
    return array_values($unique);
}

function synk_student_dashboard_faculty_images(mysqli $conn, array $facultyIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $facultyIds), static function($id) { return $id > 0; })));
    if (!$ids) return [];
    $accounts = [];
    if (synk_table_exists($conn, 'tbl_useraccount_faculty_links')) {
        // Match the profile editor's storage choice without loading personal profile fields.
        $recordProfile = synk_table_exists($conn, 'tbl_faculty_record_profiles')
            ? 'EXISTS(SELECT 1 FROM tbl_faculty_record_profiles p WHERE p.faculty_id=l.faculty_id)' : '0';
        $accountProfile = synk_table_exists($conn, 'tbl_faculty_profiles')
            ? 'EXISTS(SELECT 1 FROM tbl_faculty_profiles p WHERE p.user_id=l.user_id)' : '0';
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT l.faculty_id, CASE WHEN {$recordProfile} AND NOT {$accountProfile} THEN 0 ELSE l.user_id END AS image_user_id
            FROM tbl_useraccount_faculty_links l JOIN tbl_useraccount u ON u.user_id=l.user_id AND u.status='active'
            WHERE l.faculty_id IN ({$slots})");
        synk_bind_dynamic_params($stmt, str_repeat('i', count($ids)), $ids);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $accounts[(int)$row['faculty_id']] = (int)$row['image_user_id'];
        $stmt->close();
    }
    $images = [];
    foreach ($ids as $id) $images[$id] = synk_faculty_profile_image_url($accounts[$id] ?? 0, $id);
    return $images;
}

function synk_student_dashboard_workload(mysqli $conn, array $subjects): array
{
    // Use only the memberships already authorized for this student's displayed term.
    $ids=array_values(array_unique(array_filter(array_map('intval',array_column($subjects,'student_enrollment_id')))));
    if (!$ids) return synk_student_workload_totals([]);
    $slots=implode(',',array_fill(0,count($ids),'?'));
    $rows=synk_student_workload_query($conn,synk_student_workload_select_sql()." WHERE es.is_active=1 AND es.student_enrollment_id IN ({$slots})",str_repeat('i',count($ids)),$ids);
    return synk_student_workload_totals($rows);
}
