<?php
require_once __DIR__.'/grading_helper_lms.php';

function synk_online_attendance_tables_lms(mysqli $lms): array
{
    static $tables = [];
    $key = spl_object_hash($lms);
    if (!isset($tables[$key])) {
        $rows = synk_grading_query_lms($lms, "SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (
                'tbl_lms_online_attendance_periods','tbl_lms_online_attendance_sessions',
                'tbl_lms_online_attendance_roster','tbl_lms_online_attendance_logs')");
        $tables[$key] = array_column($rows,'TABLE_NAME');
    }
    return $tables[$key];
}

function synk_online_attendance_ready_lms(mysqli $lms): bool
{
    return count(synk_online_attendance_tables_lms($lms)) === 4;
}
