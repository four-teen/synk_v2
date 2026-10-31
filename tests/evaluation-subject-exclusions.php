<?php
// Real persistence and report queries, isolated colleges and enclosing rollback.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/college_dean_evaluations_helper.php';
require_once __DIR__.'/../backend/professor_evaluations_helper.php';
require_once __DIR__.'/../backend/student_portal_helper.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$check(synk_evaluation_subject_exclusions_ready($conn),'Prepare the subject exclusion migration before testing.');
$snapshot=static function(mysqli $db,array $tables):string {
    $data=[]; foreach ($tables as $table) $data[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
};
$tables=['tbl_college_evaluation_subject_exclusions','tbl_college_evaluation_subject_exclusion_audit','tbl_student_faculty_evaluation_subject_scope','tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_faculty','tbl_college','tbl_program','tbl_useraccount','tbl_useraccount_roles','tbl_useraccount_faculty_links','tbl_college_evaluation_settings','tbl_class_schedule','tbl_faculty_workload_sched','tbl_academic_settings'];
$before=$snapshot($conn,$tables);
class EvaluationSubjectQAConnection extends mysqli {
    public bool $fixture=false;
    public bool $failAudit=false;
    #[\ReturnTypeWillChange]
    public function begin_transaction($flags=0,$name=null) { return $this->fixture ? $this->query('SAVEPOINT subject_exclusion_request') : parent::begin_transaction($flags,$name); }
    #[\ReturnTypeWillChange]
    public function commit($flags=0,$name=null) { return $this->fixture ? $this->query('RELEASE SAVEPOINT subject_exclusion_request') : parent::commit($flags,$name); }
    #[\ReturnTypeWillChange]
    public function rollback($flags=0,$name=null) { return $this->fixture ? $this->query('ROLLBACK TO SAVEPOINT subject_exclusion_request') : parent::rollback($flags,$name); }
    #[\ReturnTypeWillChange]
    public function prepare($sql) {
        if ($this->failAudit && stripos($sql,'INSERT INTO tbl_college_evaluation_subject_exclusion_audit')!==false) throw new RuntimeException('Deliberate audit failure');
        return parent::prepare($sql);
    }
    #[\ReturnTypeWillChange]
    public function query($sql,$mode=MYSQLI_STORE_RESULT) {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql)) throw new RuntimeException('Fixture schema changes are forbidden.');
        return parent::query($sql,$mode);
    }
}
$conn->close(); $conn=new EvaluationSubjectQAConnection($servername,$username,$password,$dbase); $conn->set_charset('utf8mb4'); $conn->begin_transaction(); $conn->fixture=true;
try {
    $term=synk_fetch_current_academic_term($conn); $ay=(int)$term['ay_id']; $semester=(int)$term['semester'];
    $campus=(int)$conn->query("SELECT campus_id FROM tbl_campus WHERE status='active' LIMIT 1")->fetch_assoc()['campus_id'];
    $tag='SubjectQA'.bin2hex(random_bytes(6));
    $colleges=[]; $programs=[];
    foreach ([0,1] as $i) {
        synk_evaluation_query($conn,"INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES (?,'EXQA',?,'active')",'is',[$campus,$tag.$i]); $colleges[$i]=(int)$conn->insert_id;
        synk_evaluation_query($conn,"INSERT INTO tbl_program (college_id,program_code,program_name,status) VALUES (?,'EXQA',?,'active')",'is',[$colleges[$i],$tag.$i]); $programs[$i]=(int)$conn->insert_id;
        synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_settings (college_id,ay_id,semester,student_open,faculty_released) VALUES (?,?,?,1,1)','iii',[$colleges[$i],$ay,$semester]);
    }
    $college=$colleges[0];
    $subjectRows=$conn->query('SELECT sub_id AS subject_id,sub_code AS subject_code,sub_description AS descriptive_title FROM tbl_subject_masterlist ORDER BY sub_id LIMIT 2')->fetch_all(MYSQLI_ASSOC);
    $check(count($subjectRows)===2,'Two subjects are available for isolated rosters.');
    $a=(int)$subjectRows[0]['subject_id']; $b=(int)$subjectRows[1]['subject_id'];
    $labelA=synk_evaluation_subject_label($subjectRows[0]); $labelB=synk_evaluation_subject_label($subjectRows[1]);
    $teachers=[]; $students=[];
    foreach ([0,1] as $i) { synk_evaluation_query($conn,"INSERT INTO tbl_faculty (first_name,last_name,status) VALUES ('Teacher',?,'active')",'s',[$tag.$i]); $teachers[$i]=(int)$conn->insert_id; }
    foreach ([0,1,2] as $i) {
        synk_evaluation_query($conn,"INSERT INTO tbl_student_management (student_number,first_name,last_name,ay_id,semester,program_id) VALUES (?,'Student',?,?,?,?)",'ssiii',[$tag.$i,$tag,$ay,$semester,$programs[0]]); $students[$i]=(int)$conn->insert_id;
    }
    $enroll=static function(int $student,int $teacher,int $subject,int $index=0)use($conn,$subjectRows,$colleges,$programs,$campus,$ay,$semester,$tag):void {
        $s=$subjectRows[$subject];
        synk_evaluation_query($conn,'INSERT INTO tbl_student_management_enrolled_subjects (import_batch_key,campus_id,college_id,program_id,ay_id,semester,student_id,faculty_id,subject_id,subject_code,descriptive_title,section_id,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)',
            'siiiiiiiissi',[$tag,$campus,$colleges[$index],$programs[$index],$ay,$semester,$student,$teacher,(int)$s['subject_id'],$s['subject_code'],$s['descriptive_title'],$teacher]);
    };
    $enroll($students[0],$teachers[0],0); $enroll($students[1],$teachers[0],1); $enroll($students[2],$teachers[0],0); $enroll($students[2],$teachers[0],1); $enroll($students[0],$teachers[1],0); $enroll($students[0],$teachers[0],0,1);
    $save=static function(int $student,int $teacher,string $summary,int $rating,int $collegeId)use($conn,$ay,$semester,$tag):array {
        return synk_student_save_faculty_evaluation($conn,['student_id'=>$student,'faculty_id'=>$teacher,'college_id'=>$collegeId,'ay_id'=>$ay,'semester'=>$semester,'faculty_name'=>$tag.' Teacher '.$teacher,'subject_summary'=>$summary,'comment_text'=>'Saved comment '.$rating],array_fill_keys(array_keys(synk_student_faculty_evaluation_flat_questions()),$rating));
    };
    $r0=$save($students[0],$teachers[0],$labelA,1,$college);
    $r1=$save($students[1],$teachers[0],$labelB,5,$college);
    $r2=$save($students[2],$teachers[0],$labelA.'; '.$labelB,3,$college);
    $r3=$save($students[0],$teachers[1],$labelA,2,$college);
    $r4=$save($students[0],$teachers[0],$labelA,4,$colleges[1]);
    synk_student_finalize_faculty_evaluations_for_term($conn,$students[0],$ay,$semester,$teachers,['college_id'=>$college]);
    foreach ([1,2] as $i) synk_student_finalize_faculty_evaluations_for_term($conn,$students[$i],$ay,$semester,[$teachers[0]],['college_id'=>$college]);
    synk_student_finalize_faculty_evaluations_for_term($conn,$students[0],$ay,$semester,[$teachers[0]],['college_id'=>$colleges[1]]);
    $check((int)$conn->query('SELECT COUNT(*) n FROM tbl_student_faculty_evaluation_subject_scope WHERE evaluation_id IN ('.implode(',',array_column([$r0,$r1,$r2,$r3,$r4],'evaluation_id')).')')->fetch_assoc()['n']===5,'Each teacher response has its own subject snapshot, including mixed subjects and separate colleges.');
    $original=$snapshot($conn,['tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_class_schedule','tbl_faculty_workload_sched','tbl_academic_settings']);
    synk_evaluation_query($conn,"INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id) VALUES (?,?,'unusable','college_dean','active',?)",'ssi',[$tag,$tag.'@example.test',$college]);
    $dean=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$dean,['college_dean'],'college_dean');
    $_SESSION=['user_id'=>$dean,'role'=>'college_dean','csrf_token'=>'qa-csrf'];
    $input=['csrf_token'=>'qa-csrf','college_id'=>$college,'ay_id'=>$ay,'semester'=>$semester,'subject_id'=>$a,'excluded'=>1,'reason'=>'Faculty issue <script>private</script>','version'=>0];
    synk_evaluation_save_subject_exclusion($conn,$input);
    $states=synk_evaluation_score_states($conn,$ay,$semester);
    $check($states[$r0['evaluation_id']]['state']==='excluded' && $states[$r3['evaluation_id']]['state']==='excluded','Selected subject is excluded across its teachers.');
    $check($states[$r1['evaluation_id']]['state']==='included','A different subject for the same teacher remains included.');
    $check($states[$r2['evaluation_id']]['state']==='review','A mixed teacher response remains counted for Dean review.');
    $check(!isset($states[$r4['evaluation_id']]),'The other college is unaffected.');
    $scope=['college_id'=>$college,'campus_id'=>$campus]; $period=['ay_id'=>$ay,'semester'=>$semester];
    $report=synk_dean_evaluations_data($conn,$scope,$period,['faculty_id'=>$teachers[0],'instrument_version'=>'all','search'=>'']);
    $row=array_values(array_filter($report['rows'],static fn(array $row):bool=>$row['faculty_id']===$teachers[0]))[0];
    $check($row['submitted_count']===3 && $row['included_count']===2 && $row['excluded_count']===1 && $row['review_count']===1,'Submission counts remain intact and eligibility counts are distinct.');
    $check($row['score']['percentage']===80.0 && $report['detail']['groups'][0]['categories']['teaching_learning']['score']['percentage']===80.0,'Dean overall and category scores use eligible ratings only.');
    $individual=synk_student_fetch_faculty_evaluation_individual_report_rows($conn,$ay,$semester);
    $faculty=array_values(array_filter($individual,static fn(array $row):bool=>$row['faculty_id']===$teachers[0]))[0];
    $check($faculty['evaluations_count']===3 && $faculty['overall_percentage']===80.0,'Administrator scores include eligible responses from both colleges consistently.');
    $ratingSheet=synk_student_fetch_faculty_evaluation_rating_sheet_rows($conn,$ay,$semester);
    $record=array_values(array_filter($ratingSheet,static fn(array $row):bool=>$row['evaluation_id']===$r0['evaluation_id']))[0];
    $check($record['score_state']==='excluded' && $record['average_percentage']===20.0,'Original administrator rating records are retained and labelled excluded.');
    $summary=synk_student_fetch_faculty_evaluation_report_summary($conn,$ay,$semester);
    $eligibleRows=array_values(array_filter($ratingSheet,static fn(array $row):bool=>$row['score_state']!=='excluded'));
    $expectedScore=synk_student_faculty_evaluation_score(array_sum(array_column($eligibleRows,'total_score')),(int)array_sum(array_column($eligibleRows,'question_count')));
    $check($summary['included_count']===count($eligibleRows) && $summary['excluded_count']===count($ratingSheet)-count($eligibleRows) && $summary['overall_percentage']===$expectedScore['percentage'],'Administrator summary keeps original submissions and computes from eligible raw points only.');
    $conn->query('SAVEPOINT legacy_subject_check');
    synk_evaluation_query($conn,'DELETE FROM tbl_student_faculty_evaluation_subject_scope WHERE evaluation_id=?','i',[$r0['evaluation_id']]);
    synk_evaluation_query($conn,'UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_id=? AND college_id=?','ii',[$students[0],$college]);
    $check(synk_evaluation_score_states($conn,$ay,$semester)[$r0['evaluation_id']]['state']==='excluded','Exact original subject summaries can use inactive historical enrollment evidence.');
    synk_evaluation_query($conn,'UPDATE tbl_student_faculty_evaluations SET college_id=0 WHERE evaluation_id=?','i',[$r0['evaluation_id']]);
    $check(!isset(synk_evaluation_score_states($conn,$ay,$semester)[$r0['evaluation_id']]),'Ambiguous legacy college ownership is never guessed for exclusions.');
    $conn->query('ROLLBACK TO SAVEPOINT legacy_subject_check');
    $deanSession=$_SESSION;
    synk_evaluation_query($conn,"INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES (?,?,'unusable','professor','active')",'ss',[$tag.' Faculty',$tag.'-faculty@example.test']);
    $professor=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$professor,['professor'],'professor');
    synk_evaluation_query($conn,'INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES (?,?)','ii',[$professor,$teachers[0]]);
    $_SESSION=['user_id'=>$professor,'role'=>'professor'];
    $released=synk_professor_evaluation_results($conn,$period+['college_id'=>$college]);
    $check($released[0]['score']['percentage']===80.0 && $released[0]['response_count']===2 && count($released[0]['comments'])===3,'Released faculty scores follow the same eligibility rule while submitted comments remain.');
    synk_evaluation_query($conn,'UPDATE tbl_useraccount_faculty_links SET faculty_id=? WHERE user_id=?','ii',[$teachers[1],$professor]);
    $released=synk_professor_evaluation_results($conn,$period+['college_id'=>$college]);
    $check(count($released)===1 && $released[0]['score']===null && $released[0]['response_count']===0 && count($released[0]['comments'])===1,'All-excluded faculty results retain comments with no misleading zero score.');
    $_SESSION=$deanSession;
    foreach ([['csrf_token'=>'bad'],['version'=>0],['college_id'=>$colleges[1]],['subject_id'=>2147483647],['exclude_mixed'=>['1']],['reason'=>'  ']] as $bad) {
        $rejected=false;
        try { synk_evaluation_save_subject_exclusion($conn,array_replace($input,['version'=>1],$bad)); } catch (DomainException $error) { $rejected=true; }
        $check($rejected,'Invalid, stale or unauthorized exclusion writes are rejected: '.json_encode($bad));
    }
    $_SESSION['role']='program_chair'; $rejected=false;
    try { synk_evaluation_save_subject_exclusion($conn,$input); } catch (DomainException $error) { $rejected=true; }
    $check($rejected,'Program Chairs cannot change exclusions.'); $_SESSION['role']='college_dean';
    $conn->failAudit=true; $failed=false;
    try { synk_evaluation_save_subject_exclusion($conn,array_replace($input,['excluded'=>0,'version'=>1])); } catch (RuntimeException $error) { $failed=true; }
    $conn->failAudit=false;
    $check($failed && synk_evaluation_score_states($conn,$ay,$semester)[$r0['evaluation_id']]['state']==='excluded','An audit failure rolls back the eligibility change.');
    $check(synk_evaluation_score_states($conn,$ay,$semester)[$r2['evaluation_id']]['state']==='review','Mixed responses remain counted for review after rule changes fail.');
    // Subject snapshots remain authoritative after a roster changes.
    $conn->query('SAVEPOINT subject_roster_check');
    synk_evaluation_query($conn,'DELETE FROM tbl_student_management_enrolled_subjects WHERE college_id=?','i',[$college]);
    $check(synk_evaluation_score_states($conn,$ay,$semester)[$r0['evaluation_id']]['state']==='excluded','Submitted subject snapshots survive removed rosters.');
    $conn->query('ROLLBACK TO SAVEPOINT subject_roster_check');
    synk_evaluation_save_subject_exclusion($conn,array_replace($input,['excluded'=>0,'version'=>1,'reason'=>'Restore after review']));
    $restored=synk_dean_evaluations_data($conn,$scope,$period,['faculty_id'=>$teachers[0],'instrument_version'=>'all','search'=>'']);
    $check($restored['detail']['groups'][0]['score']['percentage']===60.0,'Restoring a subject restores its original score without rewriting responses.');
    $check($original===$snapshot($conn,['tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_class_schedule','tbl_faculty_workload_sched','tbl_academic_settings']),'Exclusions never modify source evaluations, students, rosters, schedules, workloads or academic settings.');
    $check((int)$conn->query('SELECT COUNT(*) n FROM tbl_college_evaluation_subject_exclusion_audit WHERE college_id='.$college)->fetch_assoc()['n']===2,'Only committed changes create audit records.');
    $check(synk_evaluation_resolve_subjects($labelA,[$subjectRows[0],array_replace($subjectRows[0],['subject_id'=>$b])])[0]['subject_id']===0,'Ambiguous historical labels are never guessed.');
    $check(synk_evaluation_classify_subjects([],[$a=>[]])==='review','Missing historical associations are kept for review.');
} finally { $conn->fixture=false; $conn->rollback(); }
$check($before===$snapshot($conn,$tables),'All test data rolled back, including schedules and workloads.');
echo 'Evaluation subject exclusions: ',$checks,' checks passed. All fixture records rolled back.',PHP_EOL;
