<?php
// Included only by the loopback/token guarded Student fixture transaction.
require_once $root.'/backend/management_student_account_helper.php';
require_once $root.'/backend/management_catalog_helper.php';
$handler='student/finances.php';
$_GET['exam_term']='FINAL';
$financeBaseline=synk_management_student_account($conn,$students['first'],'FINAL');
$financeMidtermBaseline=synk_management_student_account($conn,$students['first'],'MIDTERM');
$financeInsert=static function(string $table,array $row)use($conn):int{
    synk_management_execute($conn,'INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',str_repeat('s',count($row)),array_values($row));return (int)$conn->insert_id;
};
$financeOrgA=$financeInsert('tbl_management_organizations',['college_id'=>$program['college_id'],'organization_code'=>'QA FIN A '.$tag,'organization_name'=>'QA Finance Organization A']);
$financeOrgB=$financeInsert('tbl_management_organizations',['college_id'=>$program['college_id'],'organization_code'=>'QA FIN B '.$tag,'organization_name'=>'QA Finance Organization B']);
foreach([$financeOrgA=>'FINAL',$financeOrgB=>'MIDTERM'] as $org=>$exam)$financeInsert('tbl_management_period_settings',['organization_id'=>$org,'college_id'=>$program['college_id'],'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'current_exam_term'=>$exam]);
$financeScope=['college_id'=>$program['college_id'],'organization_id'=>$financeOrgA,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'exam_term'=>'FINAL','status'=>'ACTIVE'];
$financeInsert('tbl_management_contributions',$financeScope+['title'=>'OWN CONTRIBUTION <img src=x onerror=alert(1)>','contribution_date'=>date('Y-m-d'),'amount'=>'0.10']);
$financeInsert('tbl_management_contributions',array_merge($financeScope,['organization_id'=>$financeOrgB,'exam_term'=>'FINAL','title'=>'SECOND ORGANIZATION CONTRIBUTION','contribution_date'=>date('Y-m-d'),'amount'=>'0.20']));
foreach(['WRONG MANAGEMENT TERM'=>['exam_term'=>'MIDTERM'],'INACTIVE CONTRIBUTION'=>['status'=>'INACTIVE'],'OLD ACADEMIC PERIOD'=>['ay_id'=>$oldTerm['ay_id'],'semester'=>$oldTerm['semester']]] as $title=>$overrides){$financeInsert('tbl_management_contributions',array_merge($financeScope,['title'=>$title,'contribution_date'=>date('Y-m-d'),'amount'=>'9999.00'],$overrides));}
foreach(['PRESENT EVENT','ABSENT EVENT','PENDING EVENT','NOT IN ROSTER EVENT'] as $label){
    $scope=$label==='PENDING EVENT'?array_merge($financeScope,['organization_id'=>$financeOrgB,'exam_term'=>'FINAL']):$financeScope;
    $eventId=$financeInsert('tbl_management_events',$scope+['title'=>$label,'event_date'=>date('Y-m-d'),'absence_fine'=>'25.10']);
    if($label==='PENDING EVENT')continue;
    $financeInsert('tbl_management_attendance_events',['event_id'=>$eventId,'college_id'=>$program['college_id'],'organization_id'=>$scope['organization_id'],'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'exam_term'=>$scope['exam_term'],'absence_fine'=>'25.10','attendance_status'=>'CLOSED','opened_by'=>$adminId,'closed_by'=>$adminId,'closed_at'=>date('Y-m-d H:i:s')]);
    foreach($label==='NOT IN ROSTER EVENT'?[$students['other']]:array_values($students) as $rosterId)$financeInsert('tbl_management_attendance_roster',['event_id'=>$eventId,'student_id'=>$rosterId]);
    if($label!=='NOT IN ROSTER EVENT')$financeInsert('tbl_management_attendance_logs',['event_id'=>$eventId,'student_id'=>$label==='PRESENT EVENT'?$students['first']:$students['other'],'recorded_by'=>$adminId]);
}
$financeInsert('tbl_management_events',array_merge($financeScope,['exam_term'=>'MIDTERM','title'=>'WRONG TERM EVENT','event_date'=>date('Y-m-d'),'absence_fine'=>'9999.00']));
$foreign=$conn->query('SELECT p.program_id,p.college_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE c.college_id<>'.(int)$program['college_id']." AND c.status='active' ORDER BY p.program_id LIMIT 1")->fetch_assoc();
if(!$foreign)throw new RuntimeException('Another college is needed for financial scope checks.');
$foreignOrg=$financeInsert('tbl_management_organizations',['college_id'=>$foreign['college_id'],'organization_code'=>'QA FOREIGN '.substr($tag,-12),'organization_name'=>'Foreign organization']);
$financeInsert('tbl_management_period_settings',['organization_id'=>$foreignOrg,'college_id'=>$foreign['college_id'],'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'current_exam_term'=>'FINAL']);
$financeInsert('tbl_management_contributions',array_merge($financeScope,['college_id'=>$foreign['college_id'],'organization_id'=>$foreignOrg,'title'=>'FOREIGN COLLEGE SECRET CONTRIBUTION','contribution_date'=>date('Y-m-d'),'amount'=>'9999.00']));
$financeInsert('tbl_management_violations',['college_id'=>$program['college_id'],'organization_id'=>$financeOrgA,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'violation_type'=>'CATALOG TYPE ONLY - NOT A STUDENT VIOLATION','amount'=>'9999.00']);
$financeExpected=['due'=>synk_management_money_text(synk_management_money_cents($financeBaseline['totals']['due'])+2540),
    'contributions'=>synk_management_money_text(synk_management_money_cents($financeBaseline['totals']['contributions'])+30),
    'event_fines'=>synk_management_money_text(synk_management_money_cents($financeBaseline['totals']['event_fines'])+2510),
    'present'=>$financeBaseline['attendance']['present']+1,'absent'=>$financeBaseline['attendance']['absent']+1,'pending'=>$financeBaseline['attendance']['pending']+1,'not_in_roster'=>$financeBaseline['attendance']['not_in_roster']+1];
if($scenario==='finances_midterm') {
    $_GET['exam_term']='MIDTERM';
    $financeExpected=['due'=>synk_management_money_text(synk_management_money_cents($financeMidtermBaseline['totals']['due'])+999900),'contributions'=>synk_management_money_text(synk_management_money_cents($financeMidtermBaseline['totals']['contributions'])+999900),'event_fines'=>$financeMidtermBaseline['totals']['event_fines']];
}
register_shutdown_function(static function()use($financeExpected):void{header('X-QA-Finance-Expected: '.rawurlencode(json_encode($financeExpected)));});
if($scenario==='finances_tamper')$_GET+=['student_id'=>$students['other'],'organization_id'=>$foreignOrg,'college_id'=>$foreign['college_id'],'term'=>$oldTerm['ay_id'].':'.$oldTerm['semester'],'view_ay_id'=>$oldTerm['ay_id'],'view_semester'=>$oldTerm['semester']];
if($scenario==='finances_invalid_term')$_GET['exam_term']='INVALID';
if($scenario==='finances_array_term')$_GET['exam_term']=['FINAL'];
if($scenario==='finances_profile_other_college')$conn->query('UPDATE tbl_student_management SET program_id='.(int)$foreign['program_id'].' WHERE student_id='.(int)$students['first']);
if($scenario==='finances_empty')$conn->query('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_id='.(int)$students['first']);
if($scenario==='finances_staff')$_SESSION['role']='admin';
if($scenario==='finances_anonymous')$_SESSION=[];
if($scenario==='finances_post')$_POST=['forged'=>'1'];
