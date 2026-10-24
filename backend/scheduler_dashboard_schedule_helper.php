<?php

require_once __DIR__ . '/schedule_merge_helper.php';

// One row per offering keeps lecture/lab meetings and workload assignments from
// inflating dashboard totals. Any pending meeting makes the offering TBA.
function synk_scheduler_dashboard_schedule_join_sql(mysqli $conn): string
{
    $hasStatus = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_status');
    $pendingSql = $hasStatus ? "cs.schedule_status IN ('tba', 'room_tba')" : '0';
    $meetingSql = "SELECT cs.offering_id, {$pendingSql} AS is_tba FROM tbl_class_schedule cs";

    if (synk_table_exists($conn, synk_schedule_merge_table_name())) {
        $table = synk_schedule_merge_table_name();
        $scope = synk_table_has_column($conn, $table, 'merge_scope') ? 'merge_map.merge_scope' : "'FULL'";
        $scopeMatch = synk_schedule_merge_scope_match_sql('cs.schedule_type', $scope);
        $meetingSql .= "
            UNION ALL
            SELECT merge_map.member_offering_id AS offering_id, {$pendingSql} AS is_tba
            FROM `{$table}` merge_map
            INNER JOIN tbl_class_schedule cs
                ON cs.offering_id = merge_map.owner_offering_id
               AND ({$scope} = 'FULL' OR {$scopeMatch})
        ";
    }

    return "
        LEFT JOIN (
            SELECT offering_id, MAX(is_tba) AS has_tba
            FROM ({$meetingSql}) dashboard_meetings
            GROUP BY offering_id
        ) sched ON sched.offering_id = o.offering_id
    ";
}
