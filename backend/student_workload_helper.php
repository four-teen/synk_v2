<?php
require_once __DIR__.'/schedule_block_helper.php';
require_once __DIR__.'/schedule_merge_helper.php';
require_once __DIR__.'/schema_helper.php';

// One membership row per class, before any lecture/laboratory schedule joins.
function synk_student_workload_select_sql(): string
{
    return "SELECT es.student_enrollment_id,es.student_id,es.ay_id,es.semester,es.offering_id,es.subject_id,
        es.subject_code,es.section_text,es.section_id,es.program_id,es.year_level,
        s.student_number,s.last_name,s.first_name,s.middle_name,s.suffix_name,p.program_code,
        ps.ps_id AS curriculum_subject_id,ps.lec_units,ps.lab_units,ps.total_units
        FROM tbl_student_management_enrolled_subjects es
        JOIN tbl_student_management s ON s.student_id=es.student_id
        JOIN tbl_program p ON p.program_id=es.program_id
        LEFT JOIN tbl_prospectus_offering o ON o.offering_id=es.offering_id
            AND o.program_id=es.program_id AND o.section_id=es.section_id AND o.ay_id=es.ay_id AND o.semester=es.semester
        LEFT JOIN tbl_prospectus_subjects ps ON ps.ps_id=o.ps_id AND ps.sub_id=es.subject_id";
}

function synk_student_workload_query(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt=$conn->prepare($sql);
    try {
        synk_bind_dynamic_params($stmt,$types,$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

function synk_student_workload_number(float $value): string
{
    return rtrim(rtrim(number_format($value,2,'.',''),'0'),'.');
}

function synk_student_workload_subject_key(array $row): string
{
    $identity=(int)($row['subject_id']??0)>0 ? 'id:'.$row['subject_id'] : 'code:'.strtoupper(preg_replace('/\s+/u','',(string)$row['subject_code']));
    return ($row['ay_id']??0).':'.($row['semester']??0).':'.$identity;
}

function synk_student_workload_totals(array $rows): array
{
    $totals=['subjects'=>0,'units'=>0.0,'lecture_hours'=>0.0,'lab_hours'=>0.0,'weekly_hours'=>0.0,'pending_subjects'=>0];
    $subjects=[];
    foreach ($rows as $row) {
        $key=synk_student_workload_subject_key($row);
        // Prefer linked curriculum details if a repeated subject has an unlinked legacy row.
        if (!isset($subjects[$key]) || empty($subjects[$key]['curriculum_subject_id'])) $subjects[$key]=$row;
    }
    $totals['subjects']=count($subjects);
    foreach ($subjects as $row) {
        if (empty($row['curriculum_subject_id']) || ($row['total_units']===null && $row['lec_units']===null && $row['lab_units']===null)) {
            $totals['pending_subjects']++;
            continue;
        }
        $lecture=max(0.0,(float)$row['lec_units']);
        $lab=synk_lab_contact_hours($lecture,max(0.0,(float)$row['lab_units']),(float)$row['total_units']);
        $totals['units']+=$row['total_units']!==null ? max(0.0,(float)$row['total_units']) : synk_subject_units_total($lecture,$lab,0);
        $totals['lecture_hours']+=$lecture;
        $totals['lab_hours']+=$lab;
    }
    $totals['weekly_hours']=$totals['lecture_hours']+$totals['lab_hours'];
    foreach (['units','lecture_hours','lab_hours','weekly_hours'] as $key) $totals[$key]=round($totals[$key],2);
    return $totals;
}

function synk_student_workload_schedules(mysqli $conn, array $offeringIds, array $term): array
{
    $ids=synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (!$ids) return [];
    // The older merge loader performs schema/data preparation. Keep dashboard reads read-only.
    $memberMap=[]; $owners=[]; $cache=[];
    $table=synk_schedule_merge_table_name();
    if (synk_table_exists($conn,$table)) {
        $scopeColumn=synk_table_has_column($conn,$table,'merge_scope') ? 'merge_scope' : "'FULL' AS merge_scope";
        foreach ($conn->query("SELECT owner_offering_id,member_offering_id,{$scopeColumn} FROM `{$table}`")->fetch_all(MYSQLI_ASSOC) as $merge) {
            if ((int)$merge['owner_offering_id']>0 && (int)$merge['member_offering_id']>0) {
                $memberMap[(int)$merge['member_offering_id']][synk_schedule_merge_normalize_scope($merge['merge_scope'])]=(int)$merge['owner_offering_id'];
            }
        }
    }
    foreach ($ids as $id) foreach (['LEC','LAB'] as $type) $owners[$id][$type]=synk_schedule_merge_resolve_effective_owner_for_type($id,$type,$memberMap,$cache);
    $effective=synk_schedule_merge_collect_effective_owner_ids($owners);
    $slots=implode(',',array_fill(0,count($effective),'?'));
    $rows=synk_student_workload_query($conn,"SELECT cs.offering_id,cs.schedule_type,cs.days_json,cs.time_start,cs.time_end
        FROM tbl_class_schedule cs JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id
        WHERE cs.offering_id IN ({$slots}) AND o.ay_id=? AND o.semester=? AND cs.schedule_status<>'tba'
        AND cs.time_start IS NOT NULL AND cs.time_end>cs.time_start",str_repeat('i',count($effective)).'ii',array_merge($effective,[$term['ay_id'],$term['semester']]));
    $byOwner=[];
    foreach ($rows as $row) {
        $row['days']=synk_normalize_schedule_days(json_decode($row['days_json'],true));
        if ($row['days']) $byOwner[(int)$row['offering_id']][$row['schedule_type']][]=$row;
    }
    $result=[];
    foreach ($owners as $id=>$types) foreach ($types as $type=>$owner) {
        foreach ($byOwner[$owner][$type]??[] as $row) $result[$id][]=$row;
    }
    return $result;
}

function synk_student_workload_conflicts(array $rows, array $schedules): array
{
    $classes=[];
    foreach ($rows as $row) if ((int)$row['offering_id']>0) $classes[(int)$row['offering_id']]=$row;
    $classes=array_values($classes); $conflicts=[];
    for ($i=0;$i<count($classes);$i++) for ($j=$i+1;$j<count($classes);$j++) {
        $a=$classes[$i]; $b=$classes[$j]; $overlaps=[];
        foreach ($schedules[$a['offering_id']]??[] as $x) foreach ($schedules[$b['offering_id']]??[] as $y) {
            if ($x['time_start']>=$y['time_end'] || $y['time_start']>=$x['time_end']) continue;
            $days=array_values(array_intersect($x['days'],$y['days']));
            if ($days) $overlaps[]=implode(', ',$days).' '.substr(max($x['time_start'],$y['time_start']),0,5).'-'.substr(min($x['time_end'],$y['time_end']),0,5);
        }
        if ($overlaps) $conflicts[]=['subject_a'=>$a['subject_code'],'section_a'=>$a['section_text'],
            'subject_b'=>$b['subject_code'],'section_b'=>$b['section_text'],'times'=>array_values(array_unique($overlaps))];
    }
    return $conflicts;
}
