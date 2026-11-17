<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../backend/student_assessment_helper_lms.php';
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$base=['assessment_id'=>1,'title'=>'Quiz <script>literal</script>','assessment_type'=>'quiz','subject_label'=>'CS 111','status'=>'published','attempt_status'=>null,'opens_at'=>null,'due_at'=>'2026-11-17 23:59:59'];
$rows=[
    $base,
    array_replace($base,['assessment_id'=>2,'assessment_type'=>'exam','deadline_at'=>'2026-11-17 00:00:00']),
    array_replace($base,['assessment_id'=>3,'assessment_type'=>'assignment','due_at'=>'2026-11-18 00:00:00','opens_at'=>'2026-11-18 00:00:00']),
    array_replace($base,['assessment_id'=>4,'attempt_status'=>'submitted']),
    array_replace($base,['assessment_id'=>5,'attempt_status'=>'expired']),
    array_replace($base,['assessment_id'=>6,'status'=>'closed']),
    array_replace($base,['assessment_id'=>7,'due_at'=>null]),
    array_replace($base,['assessment_id'=>8,'due_at'=>'2026-11-19 17:00:00','deadline_at'=>'2026-11-17 08:30:00','attempt_status'=>'in_progress']),
    array_replace($base,['assessment_id'=>9,'due_at'=>'2026-11-16 23:59:59'])
];
$notices=synk_student_assessment_deadline_notices_lms($rows,'2026-11-17 08:00:00');$byId=array_column($notices,null,'assessment_id');
$check(count($notices)===8&&!isset($byId[7]),'All assessment types with deadlines appear; untimed work without a due date does not invent a deadline.');
$check($byId[1]['is_today']&&$byId[2]['is_today']&&!$byId[3]['is_today'],'Today includes both midnight and 23:59:59 in Philippine time.');
$check($byId[1]['notice_label']==='Due today'&&$byId[2]['notice_label']==='Due today'&&$byId[2]['status_label']==='Overdue','Passed deadlines today remain prominent red notices.');
$check($byId[3]['status_label']==='Upcoming'&&!$byId[3]['is_complete'],'Not-yet-open work still shows its future deadline.');
$check($byId[4]['is_complete']&&$byId[4]['notice_label']==='Completed'&&$byId[4]['status_label']==='Submitted','Only submitted work becomes green, including work awaiting professor review.');
$check(!$byId[5]['is_complete']&&$byId[5]['status_label']==='Time ended'&&!$byId[6]['is_complete']&&$byId[6]['status_label']==='Closed','Expiry and professor closing are never marked complete.');
$check($byId[8]['deadline_at']==='2026-11-17 08:30:00'&&$byId[8]['status_label']==='In progress','An earlier attempt timer overrides the general due date.');
$check($byId[9]['notice_label']==='Deadline passed'&&!$byId[9]['is_today'],'Older unsubmitted deadlines remain visible.');
$check($notices[0]['assessment_id']===2&&end($notices)['assessment_id']===4,'Pending deadlines today come first and completed work comes last.');
$check($byId[8]['deadline_label']==='Nov 17, 2026 at 8:30 AM PHT','Visible dates explicitly identify Philippine time.');
$check(!isset($byId[1]['questions'],$byId[1]['answers'],$byId[1]['result'],$byId[1]['priority']),'The deadline feed exposes display metadata only.');
$next=synk_student_assessment_deadline_notices_lms($rows,'2026-11-18 00:00:00');$next=array_column($next,null,'assessment_id');
$check(!$next[1]['is_today']&&$next[3]['is_today'],'Today rolls over at Philippine midnight.');
echo 'Student LMS deadlines: ',$checks,' checks passed.',PHP_EOL;
