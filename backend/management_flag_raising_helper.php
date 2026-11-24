<?php
require_once __DIR__.'/management_attendance_helper.php';

function synk_management_flag_raising_absences(mysqli $conn, array $scope, array $period, array $input): array
{
    $search=synk_management_input_text($input,'absence_search',150,false);
    $page=filter_var($input['absence_page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($page===false) throw new DomainException('Choose a valid student results page.');
    if (!synk_management_attendance_ready($conn)) return ['rows'=>[],'search'=>$search,'page'=>1,'pages'=>1,'total'=>0];

    $absence='l.student_id IS NULL';
    $exemptionJoin='';
    if (synk_management_exemptions_ready($conn)) {
        $exemptionJoin=" LEFT JOIN tbl_management_exemptions x ON x.event_id=e.event_id AND x.student_id=r.student_id AND x.status='ACTIVE'";
        $absence.=' AND x.exemption_id IS NULL';
    }
    $name="CONCAT_WS(' ',s.last_name,s.first_name,NULLIF(s.middle_name,''),NULLIF(s.suffix_name,''))";
    $from=" FROM tbl_management_events e
        JOIN tbl_management_attendance_events a ON a.event_id=e.event_id AND a.attendance_status='CLOSED'
        JOIN tbl_management_attendance_roster r ON r.event_id=e.event_id
        JOIN tbl_student_management s ON s.student_id=r.student_id
        LEFT JOIN tbl_management_attendance_logs l ON l.event_id=r.event_id AND l.student_id=r.student_id".
        $exemptionJoin." WHERE e.college_id=? AND e.organization_id=? AND e.ay_id=? AND e.semester=? AND e.status='ACTIVE'
        AND ".synk_management_flag_raising_sql('e.source_metadata');
    $values=[$scope['college_id'],$scope['organization_id'],$period['ay_id'],$period['semester']];
    $types='iiii';
    if ($search!=='') {
        $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
        $from.=" AND (s.student_number LIKE ? ESCAPE '!' OR $name LIKE ? ESCAPE '!')";
        array_push($values,$pattern,$pattern);
        $types.='ss';
    }
    $count=(int)synk_management_query($conn,'SELECT COUNT(DISTINCT s.student_id) AS total'.$from,$types,$values)[0]['total'];
    $pages=max(1,(int)ceil($count/25));
    $page=min($page,$pages);
    $rows=synk_management_query($conn,"SELECT s.student_id,s.student_number,$name AS student_name,
        SUM(CASE WHEN $absence THEN 1 ELSE 0 END) AS absences".$from.
        ' GROUP BY s.student_id,s.student_number,student_name ORDER BY absences DESC,student_name,s.student_id LIMIT 25 OFFSET ?',
        $types.'i',array_merge($values,[($page-1)*25]));
    return ['rows'=>$rows,'search'=>$search,'page'=>$page,'pages'=>$pages,'total'=>$count];
}
