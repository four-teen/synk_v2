<?php
// Synthetic records only. The caller owns an outer transaction and rolls it back.
require_once __DIR__.'/../../backend/student_evaluation_reset_helper.php';
require_once __DIR__.'/../../backend/student_faculty_evaluation_instrument.php';
class StudentEvaluationResetQAConnection extends mysqli
{
    public $requestMode = false;
    public $failAt = '';
    #[\ReturnTypeWillChange]
    public function begin_transaction($flags=0,$name=null) { return $this->requestMode ? $this->query('SAVEPOINT reset_request') : parent::begin_transaction($flags); }
    #[\ReturnTypeWillChange]
    public function commit($flags=0,$name=null) { return $this->requestMode ? $this->query('RELEASE SAVEPOINT reset_request') : parent::commit($flags); }
    #[\ReturnTypeWillChange]
    public function rollback($flags=0,$name=null) { return $this->requestMode ? $this->query('ROLLBACK TO SAVEPOINT reset_request') : parent::rollback($flags); }
    #[\ReturnTypeWillChange]
    public function prepare($sql) {
        if (($this->failAt==='audit' && strpos($sql,'INSERT INTO tbl_student_evaluation_reset_history')===0)
            || ($this->failAt==='headers' && strpos($sql,'DELETE FROM tbl_student_faculty_evaluations')===0)) throw new RuntimeException('Deliberate reset failure.');
        return parent::prepare($sql);
    }
}
function student_evaluation_reset_fixture(mysqli $conn): array
{
    $tag = 'EvaluationResetQA'.bin2hex(random_bytes(8));
    $term = synk_fetch_current_academic_term($conn);
    $campus = (int)$conn->query("SELECT campus_id FROM tbl_campus WHERE status='active' ORDER BY campus_id LIMIT 1")->fetch_assoc()['campus_id'];
    $colleges=[]; $programs=[];
    foreach (['A','B'] as $code) {
        $shortCode='ER'.substr(hash('sha256',$tag),0,6).$code;
        synk_evaluation_query($conn, "INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES (?,?,?,'active')", 'iss', [$campus,$shortCode,'Evaluation reset QA '.$code]);
        $colleges[]=(int)$conn->insert_id;
        synk_evaluation_query($conn, "INSERT INTO tbl_program (college_id,program_code,program_name,status) VALUES (?,?,?,'active')", 'iss', [$colleges[count($colleges)-1],$shortCode,'Reset QA Program']);
        $programs[]=(int)$conn->insert_id;
    }
    synk_evaluation_query($conn, "INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES (?,?,'unusable-test-password','admin','active')", 'ss', [$tag,strtolower($tag).'@example.test']);
    $admin=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$admin,['admin'],'admin');
    $students=[]; $faculty=[];
    foreach ([1,2,3] as $i) {
        synk_evaluation_query($conn, "INSERT INTO tbl_student_management (student_number,email_address,first_name,last_name,ay_id,semester,program_id,year_level) VALUES (?,?,'Reset',?,?,?,?,2)", 'sssiii', [(string)random_int(700000000000,799999999999),strtolower($tag.$i).'@example.test','Student '.$i,(int)$term['ay_id'],(int)$term['semester'],$programs[0]]);
        $students[]=(int)$conn->insert_id;
    }
    foreach (['First','Second'] as $name) {
        synk_evaluation_query($conn, "INSERT INTO tbl_faculty (first_name,last_name,status) VALUES (?,?,'active')", 'ss', [$name,$tag]); $faculty[]=(int)$conn->insert_id;
    }
    foreach ($colleges as $college) synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_settings (college_id,ay_id,semester,student_open,faculty_released) VALUES (?,?,?,0,0)', 'iii', [$college,(int)$term['ay_id'],(int)$term['semester']]);
    foreach ($faculty as $index=>$person) {
        $subjectId=$index+1;
        synk_evaluation_query($conn, "INSERT INTO tbl_student_management_enrolled_subjects (import_batch_key,campus_id,college_id,program_id,ay_id,semester,student_id,subject_id,subject_code,descriptive_title,faculty_id,is_active) VALUES (?,?,?,?,?,?,?,{$subjectId},'QA-RESET{$subjectId}','QA Evaluation Subject',?,1)", 'siiiiiii', [$tag,$campus,$colleges[0],$programs[0],(int)$term['ay_id'],(int)$term['semester'],$students[0],$person]);
    }
    $seed = static function(int $student,int $person,int $college,string $status,int $semester,string $version='',int $year=0) use($conn,$tag,$term): int {
        $token=bin2hex(random_bytes(24)); $version=$version ?: synk_student_faculty_evaluation_current_version();
        synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,college_id,ay_id,semester,faculty_name,instrument_version,question_count,total_score,average_rating,evaluation_token,submission_status,final_submission_token,comment_text) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'iiiiissiidssss', [$student,$person,$college,$year ?: (int)$term['ay_id'],$semester,'Saved QA Faculty',$version,1,4,4,$token,$status,$status==='submitted'?$token:'',$tag.' private comment']);
        $id=(int)$conn->insert_id;
        synk_evaluation_query($conn,"INSERT INTO tbl_student_faculty_evaluation_answers (evaluation_id,category_key,category_title,question_key,question_order,question_text,rating) VALUES (?,'QA','QA','QA1',1,'Private QA answer',4)", 'i', [$id]);
        synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluation_subject_scope (evaluation_id,subjects_json) VALUES (?,?)', 'is', [$id,'[{"subject_id":1,"label":"QA-RESET"}]']);
        return $id;
    };
    $semester=(int)$term['semester']; $otherSemester=$semester===1?2:1;
    $target=[$seed($students[0],$faculty[0],$colleges[0],'submitted',$semester),$seed($students[0],$faculty[1],$colleges[0],'submitted',$semester,'legacy-20'),$seed($students[1],$faculty[0],$colleges[0],'draft',$semester),$seed($students[2],$faculty[0],$colleges[0],'submitted',$semester)];
    $other=[$seed($students[0],$faculty[0],$colleges[1],'submitted',$semester),$seed($students[0],$faculty[0],$colleges[0],'submitted',$otherSemester),$seed($students[1],$faculty[1],0,'draft',$semester)];
    $otherYear=$conn->query('SELECT ay_id FROM tbl_academic_years WHERE ay_id<>'.(int)$term['ay_id'].' ORDER BY ay_id LIMIT 1')->fetch_assoc();
    if($otherYear)$other[]=$seed($students[0],$faculty[0],$colleges[0],'submitted',$semester,'',(int)$otherYear['ay_id']);
    // Supervisor evaluation data in the exact target scope must survive.
    synk_evaluation_query($conn,"INSERT INTO tbl_supervisor_evaluation_assignments (college_id,ay_id,semester,faculty_id,reviewer_user_id,reviewer_role,updated_by) VALUES (?,?,?,?,?,'college_dean',?)", 'iiiiii', [$colleges[0],(int)$term['ay_id'],$semester,$faculty[0],$admin,$admin]); $assignment=(int)$conn->insert_id;
    synk_evaluation_query($conn,"INSERT INTO tbl_supervisor_evaluations (assignment_id,assignment_revision,college_id,ay_id,semester,faculty_id,evaluator_user_id,evaluator_faculty_id,evaluator_role,evaluator_name,faculty_name,context_snapshot,instrument_version,answers_snapshot,comment_text,total_score,question_count,submission_status) VALUES (?,1,?,?,?,?,?,?,'college_dean','QA Dean','QA Faculty','{}','QA','[]','Preserve supervisor response',4,1,'submitted')", 'iiiiiii', [$assignment,$colleges[0],(int)$term['ay_id'],$semester,$faculty[0],$admin,$faculty[1]]);
    return ['tag'=>$tag,'admin'=>$admin,'students'=>$students,'faculty'=>$faculty,'target'=>$target,'other'=>$other,'other_college'=>$colleges[1],'scope'=>['campus_id'=>$campus,'college_id'=>$colleges[0],'ay_id'=>(int)$term['ay_id'],'semester'=>$semester]];
}
