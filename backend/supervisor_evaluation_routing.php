<?php
// Evaluation-only projection of existing workload records. No workload writes.
require_once __DIR__.'/professor_portal_helper.php';
require_once __DIR__.'/schedule_block_helper.php';

function synk_sef_workloads(mysqli $conn,array $scope,array $term): array
{
    $source=synk_professor_workload_assignment_source_sql();
    $rows=synk_evaluation_query($conn,"SELECT fw.faculty_id,po.program_id,p.program_code,cs.schedule_id,cs.offering_id,cs.schedule_group_id,cs.schedule_type,ps.lec_units,ps.lab_units,ps.total_units
        {$source} JOIN tbl_program p ON p.program_id=po.program_id
        WHERE p.college_id=? AND fw.ay_id=? AND fw.semester=?",'iii',[$scope['college_id'],$term['ay_id'],$term['semester']]);
    $contexts=[];$totals=[];
    foreach ($rows as $row) {
        $key=(int)$row['schedule_group_id']>0?'group:'.$row['schedule_group_id']:'schedule:'.$row['schedule_id'];
        $contexts[(int)$row['faculty_id']][(int)$row['program_id']][$key][]=$row;
        $totals[$key]=null;
    }
    if (!$rows) return [];
    // Count the complete schedule context, so split ownership follows the
    // existing faculty-load metric rather than multiplying subject units.
    $all=synk_evaluation_query($conn,"SELECT cs.schedule_id,cs.schedule_group_id,cs.schedule_type FROM tbl_class_schedule cs
        JOIN tbl_prospectus_offering po ON po.offering_id=cs.offering_id JOIN tbl_program p ON p.program_id=po.program_id
        WHERE p.college_id=? AND po.ay_id=? AND po.semester=?",'iii',[$scope['college_id'],$term['ay_id'],$term['semester']]);
    foreach ($all as $row) {
        $key=(int)$row['schedule_group_id']>0?'group:'.$row['schedule_group_id']:'schedule:'.$row['schedule_id'];
        if (!array_key_exists($key,$totals)) continue;
        if ($totals[$key]===null) $totals[$key]=['total_count'=>0,'lec_count'=>0,'lab_count'=>0];
        $totals[$key]['total_count']++;
        $totals[$key][strtoupper($row['schedule_type'])==='LAB'?'lab_count':'lec_count']++;
    }
    $result=[];
    foreach ($contexts as $facultyId=>$programs) foreach ($programs as $programId=>$groups) {
        $load=0;$label='';
        foreach ($groups as $key=>$group) { $load+=(float)synk_schedule_sum_display_metrics($group,$totals[$key] ?? [])['faculty_load'];$label=$group[0]['program_code']; }
        if ($load>0) $result[$facultyId][$programId]=['program_id'=>$programId,'program_code'=>$label,'load'=>round($load,2)];
    }
    return $result;
}

function synk_sef_routes(mysqli $conn,array $scope,array $term,array $catalog,array $reviewers): array
{
    $loads=synk_sef_workloads($conn,$scope,$term);$chairs=[];
    if (synk_evaluation_table_exists($conn,'tbl_faculty_college_appointments')) {
        foreach (synk_evaluation_query($conn,"SELECT program_id,faculty_id,status FROM tbl_faculty_college_appointments WHERE college_id=? AND ay_id=? AND semester=? AND appointment_role='chair'",'iii',[$scope['college_id'],$term['ay_id'],$term['semester']]) as $row) $chairs[(int)$row['program_id']]=$row['status']==='active'?(int)$row['faculty_id']:0;
    }
    // Existing signatory mappings are a fallback only when a term appointment
    // is absent, and only for the current term (never rewrite history).
    if (!empty($term['is_current']) && synk_evaluation_table_exists($conn,'tbl_program_chair_signatories')) foreach (synk_evaluation_query($conn,"SELECT program_id,faculty_id FROM tbl_program_chair_signatories WHERE college_id=? AND status='active'",'i',[$scope['college_id']]) as $row) {
        if (!isset($chairs[(int)$row['program_id']])) $chairs[(int)$row['program_id']]=(int)$row['faculty_id'];
    }
    $routes=[];
    foreach ($catalog as $id=>$faculty) {
        $programs=$loads[$id] ?? [];$eligible=[];$names=[];$winning=[];$identities=[];
        $highest=$programs?max(array_column($programs,'load')):0;
        foreach ($programs as $programId=>$program) if (abs($program['load']-$highest)<0.005) $winning[$programId]=$program;
        foreach (synk_sef_options($faculty,$reviewers) as $reviewer) {
            $match=$faculty['reviewer_role']==='college_dean';
            foreach ($winning as $programId=>$program) if (($chairs[$programId] ?? 0)===$reviewer['faculty_id']) $match=true;
            if ($match) { $eligible[]=$reviewer['user_id'];$names[]=$reviewer['name'];$identities[$reviewer['user_id']]=$reviewer['faculty_id']; }
        }
        sort($eligible,SORT_NUMERIC);
        $reason=$faculty['reviewer_role']==='college_dean'?'Dean evaluates Program Chair':($winning?implode(' / ',array_column($winning,'program_code')).' — highest teaching load: '.number_format($highest,2):'No assigned teaching workload');
        if (!$eligible) $reason.='; no eligible linked evaluator';
        $routes[$id]=['reviewer_user_ids'=>$eligible,'reviewer_faculty_ids'=>$identities,'reviewer_role'=>$faculty['reviewer_role'],'reviewer_name'=>implode(' / ',$names),'routing_reason'=>$reason,'workload_programs'=>array_values($programs)];
    }
    return $routes;
}

function synk_sef_assignment_owned(array $actor,array $assignment): bool
{
    return $assignment['reviewer_role']===$actor['role'] && in_array((int)$actor['user_id'],array_map('intval',$assignment['reviewer_user_ids'] ?? [(int)$assignment['reviewer_user_id']]),true);
}
