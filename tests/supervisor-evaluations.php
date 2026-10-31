<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
define('SYNK_PROGRAM_CHAIR_API',true);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/supervisor_evaluation_helper.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$snapshot=static function(mysqli $db,array $tables):array { $data=[];foreach($tables as $table)$data[$table]=hash('sha256',json_encode($db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));return $data; };
$protected=['tbl_prospectus_header','tbl_prospectus_year_sem','tbl_prospectus_subjects','tbl_subject_masterlist','tbl_section_curriculum','tbl_sections','tbl_prospectus_offering','tbl_faculty','tbl_college_faculty','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_class_schedule','tbl_faculty_workload_sched','tbl_academic_settings','tbl_offering_enrollee_counts','tbl_college_evaluation_settings','tbl_college_evaluation_subject_exclusions'];
$all=array_merge($protected,['tbl_supervisor_evaluation_settings','tbl_supervisor_evaluation_assignments','tbl_supervisor_evaluations','tbl_supervisor_evaluation_audit','tbl_useraccount','tbl_useraccount_roles','tbl_useraccount_faculty_links','tbl_faculty_college_appointments','tbl_college','tbl_program']);
$check(synk_sef_ready($conn),'Supervisor tables are prepared.'); $before=$snapshot($conn,$all);
class SupervisorQAConnection extends mysqli {
    public bool $fixture=false; public bool $failAudit=false;
    #[\ReturnTypeWillChange] public function begin_transaction($flags=0,$name=null){return $this->fixture?$this->query('SAVEPOINT sef_request'):parent::begin_transaction($flags,$name);}
    #[\ReturnTypeWillChange] public function commit($flags=0,$name=null){return $this->fixture?$this->query('RELEASE SAVEPOINT sef_request'):parent::commit($flags,$name);}
    #[\ReturnTypeWillChange] public function rollback($flags=0,$name=null){return $this->fixture?$this->query('ROLLBACK TO SAVEPOINT sef_request'):parent::rollback($flags,$name);}
    #[\ReturnTypeWillChange] public function prepare($sql){if($this->failAudit && stripos($sql,'INSERT INTO tbl_supervisor_evaluation_audit')!==false)throw new RuntimeException('Deliberate supervisor audit failure');return parent::prepare($sql);}
    #[\ReturnTypeWillChange] public function query($sql,$mode=MYSQLI_STORE_RESULT){if(preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql))throw new RuntimeException('No fixture DDL is permitted.');return parent::query($sql,$mode);}
}
$conn->close();$conn=new SupervisorQAConnection($servername,$username,$password,$dbase);$conn->set_charset('utf8mb4');$conn->begin_transaction();$conn->fixture=true;
try {
    $term=synk_fetch_current_academic_term($conn);$ay=(int)$term['ay_id'];$semester=(int)$term['semester'];$term=synk_dean_term_from_ids($conn,$ay,$semester);
    $campus=(int)$conn->query("SELECT campus_id FROM tbl_campus WHERE status='active' LIMIT 1")->fetch_assoc()['campus_id'];$tag='SEFQA'.bin2hex(random_bytes(5));
    synk_evaluation_query($conn,"INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES (?,'SEFQA',?,'active')",'is',[$campus,$tag]);$college=(int)$conn->insert_id;
    synk_evaluation_query($conn,"INSERT INTO tbl_program (college_id,program_code,program_name,status) VALUES (?,'SEFQA',?,'active')",'is',[$college,$tag]);$program=(int)$conn->insert_id;
    $faculty=[];$users=[];
    foreach (['dean','chair','chair2','teacher','foreign'] as $key) {
        synk_evaluation_query($conn,"INSERT INTO tbl_faculty (first_name,last_name,status) VALUES (?,?,'active')",'ss',[$key,$tag]);$faculty[$key]=(int)$conn->insert_id;
        if ($key==='foreign')continue;
        synk_evaluation_query($conn,"INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES (?,?,?,?,'active')",'iiii',[$college,$faculty[$key],$ay,$semester]);
        if ($key==='teacher')continue;
        $role=$key==='dean'?'college_dean':'program_chair';
        synk_evaluation_query($conn,"INSERT INTO tbl_useraccount (username,email,password,role,status,college_id,college_dean_college_id) VALUES (?,?,'unusable',?,'active',?,?)",'sssii',[$tag.' '.$key,$tag.$key.'@example.test',$role,$college,$college]);$users[$key]=(int)$conn->insert_id;
        synk_persist_useraccount_roles($conn,$users[$key],[$role],$role);
        synk_evaluation_query($conn,'INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES (?,?)','ii',[$users[$key],$faculty[$key]]);
    }
    synk_evaluation_query($conn,"INSERT INTO tbl_faculty_college_appointments (college_id,ay_id,semester,appointment_role,program_id,faculty_id,status) VALUES (?,?,?,'dean',0,?,'active'),(?,?,?,'chair',?,?,'active')",'iiiiiiiii',[$college,$ay,$semester,$faculty['dean'],$college,$ay,$semester,$program,$faculty['chair']]);
    synk_evaluation_query($conn,"INSERT INTO tbl_student_management (student_number,first_name,last_name,ay_id,semester,program_id) VALUES (?,'Student','SEF',?,?,?)",'siii',[$tag,$ay,$semester,$program]);$student=(int)$conn->insert_id;
    foreach (['dean','teacher'] as $i=>$key) synk_evaluation_query($conn,"INSERT INTO tbl_student_management_enrolled_subjects (import_batch_key,student_id,campus_id,college_id,program_id,ay_id,semester,subject_id,subject_code,descriptive_title,faculty_id,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,1)",'siiiiiiissi',[$tag,$student,$campus,$college,$program,$ay,$semester,$i+1,$tag.$i,'Subject '.$i,$faculty[$key]]);
    require __DIR__.'/fixtures/supervisor-workloads.php';
    synk_evaluation_query($conn,"INSERT INTO tbl_program(college_id,program_code,program_name,status) VALUES (?,'SECOND','Second QA Program','active')",'i',[$college]);$program2=(int)$conn->insert_id;
    synk_evaluation_query($conn,"INSERT INTO tbl_faculty_college_appointments(college_id,ay_id,semester,appointment_role,program_id,faculty_id,status) VALUES (?,?,?,'chair',?,?,'active')",'iiiii',[$college,$ay,$semester,$program2,$faculty['chair2']]);
    $classA=sef_qa_workload($conn,$program,$ay,$semester,$faculty['teacher'],$users['dean'],3);
    $classB=sef_qa_workload($conn,$program2,$ay,$semester,$faculty['teacher'],$users['dean'],3);
    sef_qa_workload($conn,$program,$ay,$semester,$faculty['dean'],$users['dean'],3);
    $session=static function(string $key)use($users):void { $_SESSION=['user_id'=>$users[$key],'role'=>$key==='dean'?'college_dean':'program_chair','csrf_token'=>'sef-test-csrf']; };
    $base=['csrf_token'=>'sef-test-csrf','college_id'=>$college,'ay_id'=>$ay,'semester'=>$semester];
    $reject=static function(array $input,string $message)use($conn,$base,$check):void { $bad=false;try{synk_sef_write($conn,array_replace($base,$input));}catch(DomainException $error){$bad=true;}$check($bad,$message); };
    $session('dean');$actor=synk_sef_actor($conn);$scope=$actor['scope'];
    $catalog=synk_sef_catalog($conn,$scope,$term);$assignments=synk_sef_assignments($conn,$scope,$term);
    $check(count($assignments[$faculty['teacher']]['reviewer_user_ids'])===2,'Equal teaching workloads share both program Chairs.');
    $check($assignments[$faculty['dean']]['reviewer_user_ids']===[$users['chair']],'Teaching Dean routes to the Chair of the highest workload program.');
    $check($assignments[$faculty['chair']]['reviewer_user_ids']===[$users['dean']],'Program Chairs route automatically to the Dean.');
    $check(!isset($catalog[$faculty['foreign']]),'Foreign faculty are absent.');
    $check((int)$conn->query('SELECT COUNT(*) n FROM tbl_supervisor_evaluation_assignments WHERE college_id='.$college)->fetch_assoc()['n']===0,'Viewing automatic assignments does not write records.');
    $conn->query('SAVEPOINT split_load');
    synk_evaluation_query($conn,'UPDATE tbl_class_schedule SET schedule_group_id=? WHERE schedule_id=?','ii',[$classA['schedule_id'],$classA['schedule_id']]);
    synk_evaluation_query($conn,"INSERT INTO tbl_class_schedule(offering_id,schedule_type,schedule_status,schedule_group_id,days_json,created_by) VALUES (?,'LEC','tba',?,'[]',?)",'iii',[$classA['offering_id'],$classA['schedule_id'],$users['dean']]);
    $check(synk_sef_workloads($conn,$scope,$term)[$faculty['teacher']][$program]['load']===1.5,'Split schedule ownership uses the existing fractional faculty load, not full subject units.');
    $conn->query('ROLLBACK TO SAVEPOINT split_load');
    $conn->query('SAVEPOINT laboratory_load');
    synk_evaluation_query($conn,'UPDATE tbl_class_schedule SET schedule_group_id=? WHERE schedule_id=?','ii',[$classA['schedule_id'],$classA['schedule_id']]);
    synk_evaluation_query($conn,'UPDATE tbl_prospectus_subjects SET lab_units=3,total_units=4 WHERE ps_id=?','i',[$classA['ps_id']]);
    synk_evaluation_query($conn,"INSERT INTO tbl_class_schedule(offering_id,schedule_type,schedule_status,schedule_group_id,days_json,created_by) VALUES (?,'LAB','tba',?,'[]',?)",'iii',[$classA['offering_id'],$classA['schedule_id'],$users['dean']]);$labSchedule=(int)$conn->insert_id;
    synk_evaluation_query($conn,'INSERT INTO tbl_faculty_workload_sched(schedule_id,faculty_id,ay_id,semester) VALUES (?,?,?,?)','iiii',[$labSchedule,$faculty['teacher'],$ay,$semester]);
    $check(synk_sef_workloads($conn,$scope,$term)[$faculty['teacher']][$program]['load']===5.25,'Lecture and laboratory load follows the existing 0.75 laboratory multiplier without double counting.');
    $conn->query('ROLLBACK TO SAVEPOINT laboratory_load');
    $conn->query('SAVEPOINT routing_variants');
    synk_evaluation_query($conn,'UPDATE tbl_prospectus_subjects SET lec_units=6,total_units=6 WHERE ps_id=?','i',[$classB['ps_id']]);
    $check(synk_sef_assignments($conn,$scope,$term)[$faculty['teacher']]['reviewer_user_ids']===[$users['chair2']],'Unequal workload routes only to the highest program Chair.');
    synk_evaluation_query($conn,"INSERT INTO tbl_program_chair_signatories(college_id,program_id,faculty_id,status) VALUES (?,?,?,'active')",'iii',[$college,$program2,$faculty['chair2']]);
    synk_evaluation_query($conn,'UPDATE tbl_faculty_college_appointments SET status=\'inactive\' WHERE program_id=?','i',[$program2]);
    $check(synk_sef_assignments($conn,$scope,$term)[$faculty['teacher']]['reviewer_user_ids']===[],'An inactive term appointment cannot be overridden by a stale current signatory mapping.');
    $conn->query('ROLLBACK TO SAVEPOINT routing_variants');
    $reject(['action'=>'assign_suggested'],'Manual reviewer actions are retired.');
    synk_sef_write($conn,$base+['action'=>'collection','is_open'=>1,'version'=>0]);
    $ratings=array_fill_keys(array_keys(synk_sef_questions()),4);
    $save=['action'=>'save','faculty_id'=>$faculty['teacher'],'assignment_revision'=>1,'version'=>0,'ratings'=>$ratings,'comment_text'=>'Shared draft <script>safe</script>'];
    $session('chair');$reject(array_replace($save,['csrf_token'=>'bad']),'CSRF is enforced.');
    $reject(array_replace($save,['comment_text'=>'  ']),'Visible comments are required.');
    $reject(array_replace($save,['ratings'=>array_replace($ratings,['SEF1'=>6])]),'Ratings must be in range.');
    $reject(array_replace($save,['faculty_id'=>$faculty['chair']]),'Self evaluation is rejected.');
    $reject(array_replace($save,['faculty_id'=>$faculty['foreign']]),'Foreign faculty writes are rejected.');
    $reject(['action'=>'collection','is_open'=>0,'version'=>1],'Chairs cannot control collection.');
    $protectedBefore=$snapshot($conn,$protected);
    synk_sef_write($conn,$base+$save);
    $record=synk_sef_records($conn,$scope,$term,synk_sef_actor($conn))[$faculty['teacher']];
    $check((int)$record['total_score']===60 && count($record['answers'])===15,'Complete answer snapshots save correctly.');
    $session('chair2');$shared=synk_sef_records($conn,$scope,$term,synk_sef_actor($conn))[$faculty['teacher']];
    $check($shared['comment_text']===$record['comment_text'],'The other tied Chair sees the same draft inputs.');
    $conn->query('SAVEPOINT changed_identity');
    synk_evaluation_query($conn,'UPDATE tbl_useraccount_faculty_links SET faculty_id=? WHERE user_id=?','ii',[$faculty['foreign'],$users['chair2']]);
    $check(!isset(synk_sef_records($conn,$scope,$term,synk_sef_actor($conn))[$faculty['teacher']]),'A relinked account cannot read another faculty identity shared draft.');
    $reject(array_replace($save,['version'=>1]),'A relinked account cannot edit the frozen shared evaluation.');
    $conn->query('ROLLBACK TO SAVEPOINT changed_identity');
    $reject($save,'A stale simultaneous save cannot overwrite a shared draft.');
    synk_sef_write($conn,$base+array_replace($save,['version'=>1,'comment_text'=>'Updated by second Chair']));
    $session('chair');$record=synk_sef_records($conn,$scope,$term,synk_sef_actor($conn))[$faculty['teacher']];
    $check($record['comment_text']==='Updated by second Chair' && (int)$record['evaluator_user_id']===$users['chair2'],'Shared draft edits and their author are visible to the first Chair.');
    synk_sef_write($conn,$base+array_replace($save,['version'=>2,'comment_text'=>'Reviewed by first Chair']));
    $record=synk_sef_records($conn,$scope,$term,synk_sef_actor($conn))[$faculty['teacher']];
    $session('dean');$check(!isset(synk_sef_records($conn,$scope,$term,synk_sef_actor($conn))[$faculty['teacher']]),'An unrelated evaluator cannot read the shared draft.');
    $check(synk_sef_performance($conn,$scope,$term,synk_sef_actor($conn))[$faculty['teacher']]['supervisor']===null,'Drafts do not enter combined scores.');
    $conn->query('SAVEPOINT frozen_route');synk_evaluation_query($conn,'DELETE FROM tbl_faculty_workload_sched WHERE schedule_id=?','i',[$classA['schedule_id']]);
    $check(count(synk_sef_assignments($conn,$scope,$term)[$faculty['teacher']]['reviewer_user_ids'])===2,'Started evaluations preserve their routing snapshot after workload changes.');$conn->query('ROLLBACK TO SAVEPOINT frozen_route');
    $session('chair2');$final=['action'=>'finalize','confirm_final'=>'1','draft_versions'=>[$faculty['teacher']=>synk_sef_review_token($record)]];
    $reject(array_replace($final,['confirm_final'=>'0']),'Explicit final confirmation is required.');
    $reject(array_replace($final,['draft_versions'=>[$faculty['teacher']=>'stale']]),'Final submission rejects stale reviewed versions.');
    $conn->failAudit=true;$failed=false;try{synk_sef_write($conn,$base+$final);}catch(RuntimeException $error){$failed=true;}$conn->failAudit=false;
    $check($failed && synk_sef_records($conn,$scope,$term,synk_sef_actor($conn))[$faculty['teacher']]['submission_status']==='draft','Audit failure rolls back final submission.');
    synk_sef_write($conn,$base+$final);
    $session('chair');$submitted=synk_sef_records($conn,$scope,$term,synk_sef_actor($conn))[$faculty['teacher']];
    $check($submitted['submission_status']==='submitted' && (int)$submitted['evaluator_user_id']===$users['chair2'],'The other tied Chair sees completion and the submitting Chair.');
    $reject(array_replace($save,['version'=>3]),'A completed shared evaluation cannot be edited.');
    $reject($final,'A duplicate final submission cannot create a second score.');
    $check((int)$conn->query('SELECT COUNT(*) n FROM tbl_supervisor_evaluations WHERE faculty_id='.$faculty['teacher'])->fetch_assoc()['n']===1,'Tied Chairs create one evaluation and one score.');
    $result=synk_sef_performance($conn,$scope,$term,synk_sef_actor($conn))[$faculty['teacher']];
    $check($result['supervisor']['component']===32.0,'The shared evaluation contributes once to supervisor scoring.');
    synk_dean_select_view_term($conn,$ay,$semester===1?2:1);
    $reject(array_replace($save,['version'=>3]),'A changed module viewing term rejects stale form submissions.');
    $check($protectedBefore===$snapshot($conn,$protected),'Evaluation operations leave workloads, schedules and other protected data unchanged.');
} finally {$conn->fixture=false;$conn->rollback();$conn->close();$conn=new mysqli($servername,$username,$password,$dbase);$conn->set_charset('utf8mb4');}
$check($before===$snapshot($conn,$all),'All fixture records roll back.');
echo 'Supervisor evaluations: ',$checks,' checks passed. All fixture records rolled back.',PHP_EOL;
