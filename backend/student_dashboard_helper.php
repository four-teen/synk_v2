<?php
require_once __DIR__ . '/student_portal_helper.php';

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
    $unique = [];
    foreach ($rows as $row) {
        $key = strtolower(implode("\n", [$row['subject_code'],$row['descriptive_title'],$row['section_display'],
            (string)$row['faculty_id'],$row['room_name'],$row['schedule_text']]));
        if (!isset($unique[$key])) $unique[$key] = $row;
    }
    return array_values($unique);
}
