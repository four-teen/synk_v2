<?php
// Real SQL and deletion helper, with every synthetic record rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/fixtures/student-evaluation-reset-data.php';
require_once __DIR__.'/../backend/student_evaluation_prompt_helper.php';
$conn->close(); $conn=new StudentEvaluationResetQAConnection($servername,$username,$password,$dbase); $conn->set_charset('utf8mb4');
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$denied=static function(callable $fn,int $status)use($check):void{try{$fn();throw new RuntimeException('An invalid deletion succeeded.');}catch(DomainException $e){$check($e->getCode()===$status,'Expected '.$status.', got '.$e->getCode().': '.$e->getMessage());}};
$tables=['tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_faculty_evaluation_subject_scope','tbl_student_evaluation_reset_history','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_faculty','tbl_useraccount','tbl_useraccount_roles','tbl_college','tbl_program','tbl_academic_settings','tbl_class_schedule','tbl_faculty_workload_sched','tbl_supervisor_evaluations','tbl_supervisor_evaluation_assignments','tbl_supervisor_evaluation_settings','tbl_supervisor_evaluation_audit','tbl_college_evaluation_settings','tbl_college_evaluation_audit','tbl_college_faculty_evaluation_clear_history','tbl_college_evaluation_subject_exclusions','tbl_college_evaluation_student_exclusions'];
$snapshot=static function(array $names)use($conn):array{$out=[];foreach($names as $name)$out[$name]=$conn->query('CHECKSUM TABLE '.$name)->fetch_all(MYSQLI_ASSOC);return $out;};
$before=$snapshot($tables);$conn->begin_transaction();
try {
    $f=student_evaluation_reset_fixture($conn);$conn->requestMode=true;
    $_SESSION=['user_id'=>$f['admin'],'role'=>'admin','student_evaluation_reset_csrf'=>bin2hex(random_bytes(24))];
    $protected=array_values(array_diff($tables,['tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_faculty_evaluation_subject_scope','tbl_student_evaluation_reset_history']));
    $protectedBefore=$snapshot($protected);$seeded=$snapshot($tables);
    $otherState=static function()use($conn,$f):array{$out=[];foreach(['tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_faculty_evaluation_subject_scope'] as $table)$out[$table]=$conn->query('SELECT * FROM '.$table.' WHERE evaluation_id IN ('.implode(',',$f['other']).') ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return $out;};
    $otherBefore=$otherState();
    $preview=synk_student_evaluation_reset_preview($conn,$f['scope']);
    $check($preview['counts']===['students'=>2,'submitted'=>3,'drafts'=>1,'records'=>4,'answers'=>4,'snapshots'=>4],'Unique student counts, multi-faculty records, drafts, answers and snapshots must be distinct.');
    $check($snapshot($tables)===$seeded,'Preview must change no database records.');
    $check(!isset($preview['ids'],$preview['answers'],$preview['fingerprint'])&&strpos(json_encode($preview),'private comment')===false,'Preview must not disclose private ratings, student identities or comments.');
    $post=$f['scope']+['csrf_token'=>$_SESSION['student_evaluation_reset_csrf'],'preview_token'=>$preview['preview_token'],'confirm_delete'=>'1','confirmation'=>'DELETE STUDENT EVALUATIONS'];
    $session=$_SESSION;$conn->query('SAVEPOINT cases');
    foreach ([['csrf_token'=>'bad',403],['preview_token'=>[],422],['preview_token'=>str_repeat('0',64),409],['college_id'=>$f['other_college'],409],['semester'=>3,409],['confirm_delete'=>'0',422],['confirmation'=>'DELETE',422]] as $bad) {
        $code=array_pop($bad);$denied(static function()use($conn,$post,$bad){synk_student_evaluation_reset_confirm($conn,array_replace($post,$bad));},$code);
    }
    foreach (['student','professor','program_chair','college_dean','scheduler'] as $role) {$_SESSION['role']=$role;$denied(static function()use($conn,$post){synk_student_evaluation_reset_confirm($conn,$post);},403);}$_SESSION=$session;
    $_SESSION['student_evaluation_reset_previews'][$post['preview_token']]['expires_at']=time()-1;$denied(static function()use($conn,$post){synk_student_evaluation_reset_confirm($conn,$post);},409);$_SESSION=$session;
    $_SESSION['student_evaluation_reset_previews'][$post['preview_token']]['session_id']='other-session';$denied(static function()use($conn,$post){synk_student_evaluation_reset_confirm($conn,$post);},409);$_SESSION=$session;
    $denied(static function()use($conn,$f){synk_student_evaluation_reset_preview($conn,array_replace($f['scope'],['campus_id'=>2147483647]));},422);
    $denied(static function()use($conn,$f){synk_student_evaluation_reset_preview($conn,array_replace($f['scope'],['college_id'=>[$f['scope']['college_id']]]));},422);
    $denied(static function()use($conn,$f){synk_student_evaluation_reset_preview($conn,array_replace($f['scope'],['semester'=>4]));},422);
    foreach (['revoked','inactive','open','comment','answer','new_record','snapshot'] as $case) {
        if($case==='revoked')$conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id=".$f['admin']);
        if($case==='inactive')$conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id=".$f['admin']);
        if($case==='open')$conn->query('UPDATE tbl_college_evaluation_settings SET student_open=1 WHERE college_id='.$f['scope']['college_id']);
        if($case==='comment')$conn->query("UPDATE tbl_student_faculty_evaluations SET comment_text='Changed' WHERE evaluation_id=".$f['target'][0]);
        if($case==='answer')$conn->query('UPDATE tbl_student_faculty_evaluation_answers SET rating=1 WHERE evaluation_id='.$f['target'][0]);
        if($case==='snapshot')$conn->query("UPDATE tbl_student_faculty_evaluation_subject_scope SET subjects_json='[]' WHERE evaluation_id=".$f['target'][0]);
        if($case==='new_record')$conn->query('DELETE FROM tbl_student_faculty_evaluations WHERE evaluation_id='.$f['target'][0]);
        $state=$snapshot($tables);
        $denied(static function()use($conn,$post){synk_student_evaluation_reset_confirm($conn,$post);},in_array($case,['revoked','inactive'],true)?403:409);
        $check($snapshot($tables)===$state,'Rejected '.$case.' changes must not delete records or write history.');
        $conn->query('ROLLBACK TO SAVEPOINT cases');$_SESSION=$session;
    }
    foreach (['audit','headers'] as $failure) {
        $conn->failAt=$failure;$thrown=false;
        try{synk_student_evaluation_reset_confirm($conn,$post);}catch(RuntimeException $e){$thrown=true;}
        $conn->failAt='';$check($thrown&&$snapshot($tables)===$seeded,'A '.$failure.' failure must roll back answers, snapshots, headers and history together.');
    }
    $result=synk_student_evaluation_reset_confirm($conn,$post);
    $check($result['counts']===$preview['counts'],'Confirmation must delete exactly the reviewed scope.');
    foreach (['tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_faculty_evaluation_subject_scope'] as $table) $check((int)$conn->query('SELECT COUNT(*) AS n FROM '.$table.' WHERE evaluation_id IN ('.implode(',',$f['target']).')')->fetch_assoc()['n']===0,'Only the selected response bundle is removed: '.$table);
    $check($otherState()===$otherBefore,'Other colleges, academic years/semesters, unassigned legacy records, and all their answers/snapshots must survive exactly.');
    $check($snapshot($protected)===$protectedBefore,'Profiles, accounts, enrollments, faculty, settings, supervisor evaluations, audit archives and workloads must remain byte-for-byte unchanged.');
    $audit=$conn->query('SELECT * FROM tbl_student_evaluation_reset_history WHERE college_id='.$f['scope']['college_id'])->fetch_assoc();
    $check($audit&&(int)$audit['student_count']===2&&(int)$audit['submitted_count']===3&&(int)$audit['draft_count']===1&&(int)$audit['actor_user_id']===$f['admin']&&strpos(json_encode($audit),'private comment')===false,'History records operation counts and actor without preserving deleted response contents.');
    $denied(static function()use($conn,$post){synk_student_evaluation_reset_confirm($conn,$post);},409);
    $empty=synk_student_evaluation_reset_preview($conn,$f['scope']);$check($empty['counts']['records']===0&&$empty['preview_token']===null,'An empty scope cannot be deleted again.');
    // The actual evaluation must become available again to the same student,
    // rather than remaining locked by pilot submission tokens or progress.
    $conn->query('UPDATE tbl_college_evaluation_settings SET student_open=1 WHERE college_id='.$f['scope']['college_id']);
    $progress=synk_student_evaluation_prompt_state($conn,$f['students'][0]);
    $check($progress['required']&&$progress['remaining']===2,'Reopening collection after reset returns the student to both pending faculty.');
    $answers=array_fill_keys(array_keys(synk_student_faculty_evaluation_flat_questions()),4);
    foreach($f['faculty'] as $person){
        $saved=synk_student_save_faculty_evaluation($conn,['student_id'=>$f['students'][0],'faculty_id'=>$person,'college_id'=>$f['scope']['college_id'],'ay_id'=>$f['scope']['ay_id'],'semester'=>$f['scope']['semester'],'faculty_name'=>'Actual QA faculty','comment_text'=>'New actual evaluation comment','subject_summary'=>'QA-RESET1'],$answers);
        $check(!in_array($saved['evaluation_id'],$f['target'],true),'Actual evaluations receive new records after reset.');
    }
    $final=synk_student_finalize_faculty_evaluations_for_term($conn,$f['students'][0],$f['scope']['ay_id'],$f['scope']['semester'],$f['faculty'],['college_id'=>$f['scope']['college_id']]);
    $check(!empty($final['final_submission_token'])&&!synk_student_evaluation_prompt_state($conn,$f['students'][0])['required'],'Students can complete the real save/finalization flow after reset.');
    $conn->query('ROLLBACK TO SAVEPOINT cases');$_SESSION=$session;
    // Exercise multiple exact-ID deletion batches, including partial drafts.
    for($i=1;$i<=501;$i++)synk_evaluation_query($conn,"INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,college_id,ay_id,semester,faculty_name,instrument_version,evaluation_token,submission_status) VALUES (?,?,?,?,?,'Bulk QA Faculty',?,?,'draft')",'iiiiiss',[$f['students'][0],1000000+$f['faculty'][1]+$i,$f['scope']['college_id'],$f['scope']['ay_id'],$f['scope']['semester'],synk_student_faculty_evaluation_current_version(),bin2hex(random_bytes(24))]);
    $bulk=synk_student_evaluation_reset_preview($conn,$f['scope']);$bulkPost=array_replace($post,['preview_token'=>$bulk['preview_token']]);
    $check($bulk['counts']['records']===505&&$bulk['counts']['students']===2,'A large scope still counts submitted students once.');
    synk_student_evaluation_reset_confirm($conn,$bulkPost);
    $check(synk_student_evaluation_reset_plan($conn,$f['scope'])['counts']['records']===0&&$otherState()===$otherBefore&&$snapshot($protected)===$protectedBefore,'Multiple deletion batches remove the exact scope without changing other responses or protected data.');
    $conn->query('ROLLBACK TO SAVEPOINT cases');$_SESSION=$session;
    $conn->query('UPDATE tbl_college_evaluation_settings SET student_open=1 WHERE college_id='.$f['scope']['college_id']);
    $open=synk_student_evaluation_reset_preview($conn,$f['scope']);$check($open['blocked']&&$open['preview_token']===null&&$open['counts']['records']===4,'Open collection still displays counts but cannot issue a deletion token.');
} finally {$conn->requestMode=false;$conn->rollback();}
$check($snapshot($tables)===$before,'All synthetic records and test deletions must roll back.');
echo 'Administrator student evaluation reset: ',$checks,' checks passed on PHP ',PHP_VERSION,'. All fixtures rolled back.',PHP_EOL;
