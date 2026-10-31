<?php
// Only invoked by the token-protected, loopback-only test route.
if (!defined('SYNK_SCHEDULE_SET_FILE_QA')) { http_response_code(404); exit; }
define('SYNK_SCHEDULER_TBA_QA',true);
$fixtureSource=file_get_contents(__DIR__.'/scheduler-tba-request.php');
$fixtureSource=substr($fixtureSource,0,strpos($fixtureSource,'switch($scenario)'));
$fixtureSource=str_replace('__DIR__',var_export(__DIR__,true),$fixtureSource);
eval('?>'.$fixtureSource);
require_once $root.'/backend/scheduler_request_guard.php';
require_once $root.'/backend/academic_schedule_policy_helper.php';
require_once $root.'/backend/schedule_merge_helper.php';
$endpoint=file_get_contents($root.'/backend/query_class_schedule.php');
$start=strpos($endpoint,'function time_fmt(');
$end=strpos($endpoint,"if (isset(\$_POST['export_schedule_file']))");
// The actual condition includes all three actions.
if($end===false) $end=strpos($endpoint,"if (isset(\$_POST['export_schedule_file'])");
$declarations=substr($endpoint,$start,$end-$start);
eval(str_replace('__DIR__',var_export($root.'/backend',true),$declarations));
require_once $root.'/backend/schedule_set_file_helper.php';

$case=(string)($_GET['case'] ?? 'export');
// An isolated college prevents any real timetable from affecting these scenarios.
$collegeRow=$conn->query('SELECT * FROM tbl_college WHERE college_id='.$college)->fetch_assoc();
$college=$clone('tbl_college',$collegeRow,['college_code'=>'F'.substr($tag,-6),'college_name'=>'File Set QA '.$tag,'status'=>'active']);
$conn->query("UPDATE tbl_program SET college_id={$college} WHERE program_id={$programme}");
$conn->query("UPDATE tbl_useraccount SET college_id={$college} WHERE user_id={$user}");
$conn->query("UPDATE tbl_college_faculty SET college_id={$college} WHERE faculty_id={$faculty}");
$conn->query("UPDATE tbl_room_college_access SET college_id={$college} WHERE room_id IN ({$room},{$room2})");
$_SESSION['college_id']=$college;
$_SESSION['college_name']='File Set QA';
$_SESSION['campus_id']=(int)$template['campus_id'];
$lec=$makeSchedule($target,'LEC','scheduled',true,$room,'09:00:00','10:00:00');
$lab=$makeSchedule($target,'LAB','scheduled',true,$room2,'13:00:00','16:00:00');
$group=123456;$conn->query("UPDATE tbl_class_schedule SET schedule_group_id={$group} WHERE schedule_id IN ({$lec},{$lab})");
$secondProgram=$clone('tbl_program',$programmeRow,['college_id'=>$college,'program_code'=>$tag.'B']);
$secondHeader=$clone('tbl_prospectus_header',$header,['program_id'=>$secondProgram]);
$secondYear=$clone('tbl_prospectus_year_sem',$yearRow,['prospectus_id'=>$secondHeader]);
$secondSubject=$clone('tbl_prospectus_subjects',$subjectRow,['pys_id'=>$secondYear]);
$secondSection=$clone('tbl_sections',$sectionRow,['program_id'=>$secondProgram,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'section_name'=>substr($tag,-6).'B','full_section'=>$tag.'B']);
$conn->query("INSERT INTO tbl_section_curriculum (section_id,prospectus_id,assigned_by) VALUES ({$secondSection},{$secondHeader},{$user})");
$secondOffering=$clone('tbl_prospectus_offering',$template,['program_id'=>$secondProgram,'prospectus_id'=>$secondHeader,'ps_id'=>$secondSubject,
    'section_id'=>$secondSection,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'status'=>'pending']);
$secondMeeting=$makeSchedule(['offering_id'=>$secondOffering],'LEC','tba');
$policy=synk_fetch_effective_schedule_policy($conn,$college);
// Template policy overrides must not make the fixture depend on a real college.
$conn->query('DELETE FROM tbl_college_schedule_policy_overrides WHERE college_id='.$college);
$export=synk_set_file_export($conn,$college,$term['ay_id'],$term['semester'],'Set A, "Original"');
$file=synk_set_file_parse($export['csv']);
$original=synk_set_file_state($conn,$college,$term['ay_id'],$term['semester']);

if($case==='swap_faculty' || $case==='switch_back_b') {
    $facultyB=$clone('tbl_faculty',$facultyRow,['last_name'=>'Set B '.$tag,'first_name'=>'Faculty']);
    $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$college},{$facultyB},{$term['ay_id']},{$term['semester']},'active')");
    $conn->query("UPDATE tbl_faculty_workload_sched SET faculty_id={$facultyB} WHERE schedule_id IN ({$lec},{$lab})");
    $conn->query("UPDATE tbl_class_schedule SET days_json='[\"T\"]' WHERE schedule_id IN ({$lec},{$lab})");
    if($case==='switch_back_b') {
        $export=synk_set_file_export($conn,$college,$term['ay_id'],$term['semester'],'Set B');$file=synk_set_file_parse($export['csv']);
        $conn->query("UPDATE tbl_faculty_workload_sched SET faculty_id={$faculty} WHERE schedule_id IN ({$lec},{$lab})");
        $conn->query("UPDATE tbl_class_schedule SET days_json='[\"M\"]' WHERE schedule_id IN ({$lec},{$lab})");
    }
}

if($case==='external_room_conflict' || $case==='external_faculty_conflict') {
    $foreignCollege=$conn->query("SELECT college_id FROM tbl_college WHERE college_id<>{$college} AND status='active' LIMIT 1")->fetch_assoc()['college_id'];
    $foreignProgram=$clone('tbl_program',$programmeRow,['college_id'=>$foreignCollege,'program_code'=>$tag.'F']);
    $foreignHeader=$clone('tbl_prospectus_header',$header,['program_id'=>$foreignProgram]);
    $foreignYear=$clone('tbl_prospectus_year_sem',$yearRow,['prospectus_id'=>$foreignHeader]);
    $foreignSubject=$clone('tbl_prospectus_subjects',$subjectRow,['pys_id'=>$foreignYear]);
    $foreignSection=$clone('tbl_sections',$sectionRow,['program_id'=>$foreignProgram,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'section_name'=>substr($tag,-6).'F','full_section'=>$tag.'F']);
    $conn->query("INSERT INTO tbl_section_curriculum (section_id,prospectus_id,assigned_by) VALUES ({$foreignSection},{$foreignHeader},{$user})");
    $foreignOffering=$clone('tbl_prospectus_offering',$template,['program_id'=>$foreignProgram,'prospectus_id'=>$foreignHeader,'ps_id'=>$foreignSubject,
        'section_id'=>$foreignSection,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'status'=>'active']);
    $makeSchedule(['offering_id'=>$foreignOffering],'LEC',$case==='external_room_conflict'?'scheduled':'room_tba',$case==='external_faculty_conflict',$room,'09:00:00','10:00:00');
}

if(in_array($case,['restore','restore_students','restore_need','stale','history_failure','preview','cancel'],true)) {
    $conn->query("UPDATE tbl_class_schedule SET schedule_status='tba',room_id=NULL,days_json='[]',time_start=NULL,time_end=NULL WHERE schedule_id IN ({$lec},{$lab})");
}
if(in_array($case,['restore','workload_students','workload_changed'],true)) {
    $conn->query("DELETE FROM tbl_faculty_workload_sched WHERE schedule_id={$lab}");
}
if(in_array($case,['restore_students','workload_students'],true)) {
    $enrollment=$conn->query('SELECT * FROM tbl_student_management_enrolled_subjects WHERE is_active=1 LIMIT 1')->fetch_assoc();
    if(!$enrollment) throw new RuntimeException('An active enrollment template is required.');
    $clone('tbl_student_management_enrolled_subjects',$enrollment,['program_id'=>$programme,'college_id'=>$college,'campus_id'=>(int)$template['campus_id'],
        'section_id'=>$target['section_id'],'offering_id'=>$target['offering_id'],'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'subject_id'=>(int)$subjectRow['sub_id'],'is_active'=>1]);
}
if($case==='restore_need') {
    $need=$clone('tbl_faculty_need',[],['college_id'=>$college,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'need_label'=>$tag,'status'=>'active','created_by'=>$user]);
    $conn->query("DELETE FROM tbl_faculty_workload_sched WHERE schedule_id={$lab}");
    $conn->query("INSERT INTO tbl_faculty_need_workload_sched (faculty_need_id,schedule_id,ay_id,semester) VALUES ({$need},{$lab},{$term['ay_id']},{$term['semester']})");
    $export=synk_set_file_export($conn,$college,$term['ay_id'],$term['semester'],'Set with faculty need');
    $file=synk_set_file_parse($export['csv']);
    $conn->query("DELETE FROM tbl_faculty_need_workload_sched WHERE schedule_id={$lab}");
}
if(in_array($case,['merge_roundtrip','merge_changed','merged_locked','merged_students'],true)) {
    $conn->query("INSERT INTO tbl_class_schedule_merge (owner_offering_id,member_offering_id,merge_scope,created_by) VALUES ({$target['offering_id']},{$other['offering_id']},'FULL',{$user})");
    $export=synk_set_file_export($conn,$college,$term['ay_id'],$term['semester'],'Merged set');$file=synk_set_file_parse($export['csv']);
    if($case==='merge_changed') $conn->query("DELETE FROM tbl_class_schedule_merge WHERE member_offering_id={$other['offering_id']}");
    if($case==='merged_locked') {
        $conn->query("UPDATE tbl_prospectus_offering SET status='locked' WHERE offering_id={$other['offering_id']}");
        $conn->query("UPDATE tbl_class_schedule SET schedule_status='tba',room_id=NULL,days_json='[]',time_start=NULL,time_end=NULL WHERE schedule_id IN ({$lec},{$lab})");
    }
    if($case==='merged_students') {
        $conn->query("DELETE FROM tbl_faculty_workload_sched WHERE schedule_id={$lab}");
        $enrollment=$conn->query('SELECT * FROM tbl_student_management_enrolled_subjects WHERE is_active=1 LIMIT 1')->fetch_assoc();
        $clone('tbl_student_management_enrolled_subjects',$enrollment,['program_id'=>$programme,'college_id'=>$college,'campus_id'=>(int)$template['campus_id'],
            'section_id'=>$other['section_id'],'offering_id'=>$other['offering_id'],'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'subject_id'=>(int)$subjectRow['sub_id'],'is_active'=>1]);
    }
}
if($case==='wrong_college') $file['meta']['college_id']=(string)($college+1);
if($case==='wrong_term') $file['meta']['semester']=$term['semester']===3?'1':'3';
if($case==='missing_meeting') $conn->query('DELETE FROM tbl_class_schedule WHERE schedule_id='.$secondMeeting);
if($case==='extra_meeting') $makeSchedule($other,'LEC','tba');
if($case==='wrong_group') $conn->query('UPDATE tbl_class_schedule SET schedule_group_id=789 WHERE schedule_id='.$lec);
if($case==='locked') {
    $conn->query("UPDATE tbl_class_schedule SET schedule_status='tba',room_id=NULL,days_json='[]',time_start=NULL,time_end=NULL WHERE schedule_id={$lec}");
    $conn->query("UPDATE tbl_prospectus_offering SET status='locked' WHERE offering_id={$target['offering_id']}");
}
if($case==='inactive_faculty') $conn->query("UPDATE tbl_faculty SET status='inactive' WHERE faculty_id={$faculty}");
if($case==='invalid_room') $conn->query("DELETE FROM tbl_room_college_access WHERE room_id={$room}");
if($case==='room_conflict' || $case==='faculty_conflict' || $case==='section_conflict') {
    $conflictOffering=$case==='section_conflict'?$makeOffering('S',$target['section_id']):$other;
    $makeSchedule($conflictOffering,'LEC','scheduled',$case==='faculty_conflict',$case==='room_conflict'?$room:$room2,'09:00:00','10:00:00');
    // Include the conflicting meeting in the file: this tests the set's own conflict checks.
    $export=synk_set_file_export($conn,$college,$term['ay_id'],$term['semester'],'Conflicting set');$file=synk_set_file_parse($export['csv']);
}
$csv=$export['csv'];
if($case==='wrong_college' || $case==='wrong_term') $csv=synk_set_file_csv($file['meta'],$file['records']);
if($case==='edited') $csv=str_replace('09:00:00','10:00:00',$csv);
if($case==='truncated') $csv=substr($csv,0,strrpos(rtrim($csv),"\n"));
if($case==='history_failure') $conn->failHistory=true;
$action=$case==='export'?'export_schedule_file':(in_array($case,['preview','cancel'],true)?'preview_schedule_file':'apply_schedule_file');
$retiredActions=['retired_list'=>'load_schedule_sets','retired_save'=>'save_schedule_set','retired_load'=>'load_schedule_set_into_live',
    'retired_rename'=>'update_schedule_set_meta','retired_delete'=>'delete_schedule_set'];
if(isset($retiredActions[$case])) $action=$retiredActions[$case];
$token='not-previewed';
if($action==='apply_schedule_file' && !in_array($case,['edited','truncated','wrong_college','wrong_term','workload_students','missing_meeting','extra_meeting','wrong_group','locked','inactive_faculty','invalid_room','merge_changed','room_conflict','faculty_conflict','section_conflict','external_room_conflict','external_faculty_conflict','merged_locked','merged_students'],true)) {
    $plan=synk_set_file_plan($conn,$file,$college,$term['ay_id'],$term['semester'],$policy);$token=$plan['preview_token'];
}
if($case==='stale') $conn->query("UPDATE tbl_class_schedule SET schedule_status='room_tba',days_json='[\"T\"]',time_start='10:00:00',time_end='11:00:00' WHERE schedule_id={$lec}");
$_POST=[$action=>1,'set_name'=>'Set A, "Original"','csrf_token'=>'tba-qa','ay_id'=>$term['ay_id'],'semester'=>$term['semester'],
    'scheduler_term_key'=>$term['ay_id'].':'.$term['semester'],'scheduler_college_id'=>$college,'preview_token'=>$token];
if($case==='bad_csrf') $_POST['csrf_token']='bad';
if($case==='wrong_role') $_SESSION['role']='professor';
if($case==='stale_college') $_POST['scheduler_college_id']=$college+1;
if($case==='forged_term') $_POST['scheduler_term_key']=$term['ay_id'].':'.($term['semester']===3?1:3);
if(isset($_FILES['schedule_set_file']['tmp_name'])) {
    file_put_contents($_FILES['schedule_set_file']['tmp_name'],$csv);$_FILES['schedule_set_file']['size']=strlen($csv);
}
$take=static function() use($conn,$college,$term):array {
    return ['state'=>synk_set_file_state($conn,$college,$term['ay_id'],$term['semester']),
        'events'=>$conn->query('SELECT * FROM tbl_class_schedule_tba_event WHERE college_id='.$college.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC),
        'audit'=>$conn->query('SELECT * FROM tbl_scheduler_workload_audit_logs WHERE college_id='.$college.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC),
        'outside_meetings'=>$conn->query('SELECT cs.* FROM tbl_class_schedule cs JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id JOIN tbl_program p ON p.program_id=o.program_id WHERE p.college_id<>'.$college.' ORDER BY cs.schedule_id')->fetch_all(MYSQLI_ASSOC),
        'legacy_sets'=>$conn->query('SELECT * FROM tbl_program_schedule_set ORDER BY 1')->fetch_all(MYSQLI_ASSOC),
        'legacy_rows'=>$conn->query('SELECT * FROM tbl_program_schedule_set_row ORDER BY 1')->fetch_all(MYSQLI_ASSOC),
        'enrollments'=>$conn->query('SELECT * FROM tbl_student_management_enrolled_subjects ORDER BY 1')->fetch_all(MYSQLI_ASSOC)];
};
$before=$take();session_write_close();ob_start();
register_shutdown_function(static function() use($conn,$take,$before,$original,$export,$case):void {
    $body='';while(ob_get_level()>0)$body=ob_get_clean().$body;
    try {$after=$take();$result=['response'=>json_decode($body,true) ?: $body,'response_http_status'=>http_response_code(),'before'=>$before,'after'=>$after,'original'=>$original,'export'=>$export,'case'=>$case];
        $conn->discardFixtures();if(session_status()===PHP_SESSION_ACTIVE)session_destroy();http_response_code(200);header('Content-Type: application/json; charset=utf-8');echo json_encode($result);
    } catch(Throwable $error) {$conn->discardFixtures();http_response_code(500);echo json_encode(['fixture_error'=>$error->getMessage(),'body'=>$body]);}
});
$endpoint=substr($endpoint,0,$start).substr($endpoint,$end);
$endpoint=preg_replace('/\binclude\s+[\'"]db\.php[\'"];/', '',$endpoint);
$endpoint=str_replace('__DIR__',var_export($root.'/backend',true),$endpoint);
chdir($root.'/backend');eval('?>'.$endpoint);
