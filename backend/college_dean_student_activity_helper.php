<?php
require_once __DIR__.'/college_dean_students_helper.php';
require_once __DIR__.'/student_presence_helper.php';

function synk_dean_student_activity(mysqli $conn, array $scope, array $term): array
{
    if (!synk_student_login_activity_ready($conn) || !synk_student_login_activity_term_ready($conn) || !synk_student_presence_ready($conn)) {
        throw new RuntimeException('Student activity tracking is not prepared.');
    }
    // A student appears once for memberships in this specific academic term.
    $source = synk_dean_students_source($scope, ['ay_id'=>$term['ay_id'],'semester'=>$term['semester']], $conn);
    $rows = synk_dean_students_query($conn, "SELECT DISTINCT s.student_id,s.student_number,s.last_name,s.first_name,s.middle_name,s.suffix_name
        {$source['sql']} ORDER BY s.last_name,s.first_name,s.middle_name,s.suffix_name,s.student_id", $source['types'], $source['params']);
    $counts = []; $presence = [];
    foreach (array_chunk(array_column($rows, 'student_id'), 1000) as $ids) {
        $counts += synk_student_login_activity_counts($conn, $ids, $term);
        // A current session must never appear as live activity in a past term.
        if (empty($term['is_current'])) continue;
        $slots = implode(',', array_fill(0, count($ids), '?'));
        $sessions = synk_dean_students_query($conn, "SELECT student_id,
            MAX(CASE WHEN ended_at IS NULL AND last_activity_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)
                THEN 1 ELSE 0 END) AS has_session,
            MAX(CASE WHEN ended_at IS NULL THEN GREATEST(0,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),DATE_ADD(last_activity_at,INTERVAL 15 MINUTE))) ELSE 0 END) AS session_seconds,
            MAX(CASE WHEN ended_at IS NULL AND last_activity_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)
                AND last_seen_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 SECOND)
                THEN TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),LEAST(DATE_ADD(last_activity_at,INTERVAL 15 MINUTE),DATE_ADD(last_seen_at,INTERVAL 90 SECOND))) ELSE 0 END) AS online_seconds,
            MAX(last_seen_at) AS last_seen_at,
            MAX(COALESCE(ended_at,CASE WHEN last_activity_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)
                THEN DATE_ADD(last_activity_at,INTERVAL 15 MINUTE) END)) AS last_logout_at
            FROM tbl_student_portal_sessions WHERE student_id IN ({$slots}) GROUP BY student_id", str_repeat('i', count($ids)), $ids);
        foreach ($sessions as $session) $presence[(int)$session['student_id']] = $session;
    }
    $students = []; $summary = ['students'=>count($rows),'online'=>0,'signed_in_before'=>0,'never_signed_in'=>0];
    foreach ($rows as $row) {
        $id = (int)$row['student_id'];
        $count = (int)($counts[$id]['login_success_count'] ?? 0);
        $session = $presence[$id] ?? [];
        $online = (int)($session['online_seconds'] ?? 0) > 0;
        $status = empty($term['is_current']) ? 'historical' : ($online ? 'online' : (!empty($session['has_session']) ? 'offline' : ($session || $count ? 'logged_out' : 'never')));
        $students[] = ['student_id'=>$id,'student_number'=>(string)$row['student_number'],'student_name'=>synk_dean_students_name($row),
            'successful_logins'=>$count,'level'=>$count >= 10 ? 3 : ($count >= 5 ? 2 : ($count > 0 ? 1 : 0)),
            'presence'=>$status,'online_seconds'=>(int)($session['online_seconds'] ?? 0),
            'session_seconds'=>(int)($session['session_seconds'] ?? 0),
            'last_seen_at'=>synk_student_login_activity_iso($session['last_seen_at'] ?? null),
            'last_logout_at'=>synk_student_login_activity_iso($session['last_logout_at'] ?? null)];
        if ($online) $summary['online']++;
        $summary[$count ? 'signed_in_before' : 'never_signed_in']++;
    }
    return ['students'=>$students,'summary'=>$summary,'term'=>['ay_id'=>(int)$term['ay_id'],'semester'=>(int)$term['semester'],'term_text'=>$term['term_text']],
        'live_presence'=>!empty($term['is_current']),'generated_at'=>gmdate('Y-m-d\TH:i:s\Z'),'refresh_seconds'=>30,'idle_seconds'=>900];
}
