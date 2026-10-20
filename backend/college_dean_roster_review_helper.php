<?php
require_once __DIR__.'/college_dean_workload_helper.php';
require_once __DIR__.'/workload_student_guard_helper.php';

// Read-only comparison of active student memberships against the selected college/term's assignments.
function synk_dean_roster_review(mysqli $conn,array $scope,array $term): array
{
    if (!synk_dean_roster_ready($conn)) throw new DomainException('Student roster storage is not available yet.');
    $params=[$scope['college_id'],$scope['campus_id'],$term['ay_id'],$term['semester']];
    $offerings=synk_workload_guard_query($conn,'SELECT o.offering_id,o.program_id,o.section_id,ps.sub_id AS subject_id
        FROM tbl_prospectus_offering o JOIN tbl_prospectus_subjects ps ON ps.ps_id=o.ps_id
        JOIN tbl_program p ON p.program_id=o.program_id JOIN tbl_college c ON c.college_id=p.college_id
        WHERE p.college_id=? AND c.campus_id=? AND o.ay_id=? AND o.semester=? ORDER BY o.offering_id','iiii',$params);
    $byId=[]; $byClass=[];
    foreach ($offerings as $offering) {
        $id=(int)$offering['offering_id']; $byId[$id]=$offering;
        $byClass[$offering['program_id'].':'.$offering['section_id'].':'.$offering['subject_id']][$id]=$offering;
    }
    $assigned=synk_workload_guard_query($conn,"SELECT DISTINCT o.offering_id,fw.faculty_id,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ',f.first_name,f.middle_name,f.last_name,f.ext_name)),''),CONCAT('Faculty #',fw.faculty_id)) AS faculty_name
        FROM tbl_faculty_workload_sched fw JOIN tbl_class_schedule cs ON cs.schedule_id=fw.schedule_id
        JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id AND o.ay_id=fw.ay_id AND o.semester=fw.semester
        JOIN tbl_program p ON p.program_id=o.program_id JOIN tbl_college c ON c.college_id=p.college_id
        LEFT JOIN tbl_faculty f ON f.faculty_id=fw.faculty_id
        WHERE p.college_id=? AND c.campus_id=? AND fw.ay_id=? AND fw.semester=? ORDER BY o.offering_id,fw.faculty_id",'iiii',$params);
    $assignments=[];
    foreach ($assigned as $row) $assignments[(int)$row['offering_id']][(int)$row['faculty_id']]=$row['faculty_name'];
    $source=synk_professor_workload_assignment_source_sql();
    $viewable=synk_workload_guard_query($conn,"SELECT DISTINCT po.offering_id,fw.faculty_id {$source}
        JOIN tbl_program p ON p.program_id=po.program_id JOIN tbl_college c ON c.college_id=p.college_id
        WHERE p.college_id=? AND c.campus_id=? AND fw.ay_id=? AND fw.semester=?",'iiii',$params);
    $open=[]; foreach ($viewable as $row) $open[(int)$row['offering_id']][(int)$row['faculty_id']]=true;
    $members=synk_workload_guard_query($conn,"SELECT es.student_id,es.offering_id,es.program_id,es.section_id,es.subject_id,es.subject_code,es.descriptive_title,es.faculty_id,
        COALESCE(NULLIF(sec.full_section,''),NULLIF(es.section_text,''),CONCAT('Section ',es.section_id)) AS section_label,
        COALESCE(NULLIF(p.program_code,''),CONCAT('Program #',es.program_id)) AS program_label,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ',f.first_name,f.middle_name,f.last_name,f.ext_name)),''),IF(es.faculty_id>0,CONCAT('Faculty #',es.faculty_id),'Not recorded')) AS saved_faculty_name
        FROM tbl_student_management_enrolled_subjects es
        LEFT JOIN tbl_program p ON p.program_id=es.program_id AND p.college_id=es.college_id
        LEFT JOIN tbl_sections sec ON sec.section_id=es.section_id AND sec.program_id=es.program_id AND sec.ay_id=es.ay_id AND sec.semester=es.semester
        LEFT JOIN tbl_faculty f ON f.faculty_id=es.faculty_id
        WHERE es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=? AND es.is_active=1
        ORDER BY es.subject_code,es.program_id,es.section_id,es.student_enrollment_id",'iiii',$params);
    $groups=[];
    foreach ($members as $member) {
        $logical=$member['program_id'].':'.$member['section_id'].':'.$member['subject_id'];
        $original=(int)$member['offering_id']; $id=$original; $problem='';
        if (!$id) {
            $matches=$byClass[$logical] ?? [];
            if (count($matches)===1) $id=(int)key($matches);
            else $problem=count($matches)>1?'ambiguous_class':'class_unavailable';
        } elseif (!isset($byId[$id]) || $logical!==$byId[$id]['program_id'].':'.$byId[$id]['section_id'].':'.$byId[$id]['subject_id']) $problem='class_unavailable';
        $key=$problem?$original.':'.$logical.':'.$problem:(string)$id;
        if (!isset($groups[$key])) $groups[$key]=['offering_id'=>$id,'subject_code'=>$member['subject_code'],'descriptive_title'=>$member['descriptive_title'],
            'section_label'=>$member['section_label'],'program_label'=>$member['program_label'],'problem'=>$problem,'students'=>[],'affected'=>[],'saved'=>[]];
        $student=(int)$member['student_id']; $faculty=(int)$member['faculty_id'];
        $groups[$key]['students'][$student]=true; $groups[$key]['saved'][$faculty]=$member['saved_faculty_name'];
        if ($problem || !isset($assignments[$id][$faculty])) $groups[$key]['affected'][$student]=true;
    }
    $rows=[]; $affected=[]; $counts=['missing_assignment'=>0,'faculty_mismatch'=>0,'class_unavailable'=>0,'ambiguous_class'=>0];
    foreach ($groups as $group) {
        $id=$group['offering_id']; $current=$group['problem']?[]:($assignments[$id] ?? []); $available=$group['problem']?[]:($open[$id] ?? []);
        $status=$group['problem'];
        if (!$status && !$current) $status='missing_assignment';
        elseif (!$status && $group['affected']) $status='faculty_mismatch';
        elseif (!$status && !empty($term['is_current']) && !$available) $status='class_unavailable';
        if (!$status) continue;
        if ($status!=='faculty_mismatch') $group['affected']=$group['students'];
        $affected+=$group['affected']; $counts[$status]++;
        $faculty=$available?(int)key($available):0;
        $rows[]=['offering_id'=>$id,'subject_code'=>$group['subject_code'],'descriptive_title'=>$group['descriptive_title'],
            'section_label'=>$group['section_label'],'program_label'=>$group['program_label'],'status'=>$status,
            'student_count'=>count($group['students']),'affected_student_count'=>count($group['affected']),
            'saved_faculty'=>array_values($group['saved']),'assigned_faculty'=>array_values($current),
            'review_faculty_id'=>$faculty,'review_faculty_name'=>$faculty?($current[$faculty] ?? ''):''];
    }
    return ['rows'=>$rows,'summary'=>['classes'=>count($rows),'affected_students'=>count($affected),'statuses'=>$counts],
        'term'=>$term,'checked_at'=>gmdate('Y-m-d\TH:i:s\Z')];
}
