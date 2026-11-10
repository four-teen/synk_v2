<?php
require_once __DIR__.'/evaluation_access_helper.php';

function synk_evaluation_student_exclusions_ready(mysqli $conn): bool
{
    return synk_evaluation_table_exists($conn,'tbl_college_evaluation_student_exclusions')
        && synk_evaluation_table_exists($conn,'tbl_college_evaluation_student_exclusion_audit');
}

// Original enrollment, saved responses and existing rules keep historical students manageable.
// Identity and counts only: this list never exposes a student's ratings or teacher feedback.
function synk_evaluation_student_candidates(mysqli $conn,int $collegeId,int $ayId,int $semester): array
{
    $sources=['SELECT es.student_id FROM tbl_student_management_enrolled_subjects es
        JOIN tbl_college c ON c.college_id=es.college_id AND c.campus_id=es.campus_id
        WHERE es.college_id=? AND es.ay_id=? AND es.semester=?'];
    $params=[$collegeId,$ayId,$semester];
    if (synk_evaluation_table_exists($conn,'tbl_student_faculty_evaluations')) {
        $sources[]='SELECT e.student_id FROM tbl_student_faculty_evaluations e WHERE e.ay_id=? AND e.semester=? AND '.synk_evaluation_owner_sql($conn,'e',$collegeId);
        $params=array_merge($params,[$ayId,$semester]);
    }
    if (synk_evaluation_table_exists($conn,'tbl_college_evaluation_student_exclusions')) {
        $sources[]='SELECT student_id FROM tbl_college_evaluation_student_exclusions WHERE college_id=? AND ay_id=? AND semester=?';
        $params=array_merge($params,[$collegeId,$ayId,$semester]);
    }
    $rows=synk_evaluation_query($conn,"SELECT ids.student_id,s.student_number,
        TRIM(CONCAT_WS(' ',s.first_name,NULLIF(s.middle_name,''),s.last_name,NULLIF(s.suffix_name,''))) AS student_name
        FROM (".implode(' UNION ',$sources).") ids LEFT JOIN tbl_student_management s ON s.student_id=ids.student_id
        WHERE ids.student_id>0 ORDER BY s.last_name,s.first_name,s.student_number,ids.student_id",str_repeat('i',count($params)),$params);
    $students=[];
    foreach ($rows as $row) {
        $id=(int)$row['student_id'];
        $name=trim((string)$row['student_name']) ?: 'Student #'.$id;
        $number=trim((string)$row['student_number']);
        $students[$id]=['student_id'=>$id,'label'=>$name.($number!==''?' - '.$number:''),'submitted_count'=>0,'draft_count'=>0,'excluded_count'=>0];
    }
    if (synk_evaluation_table_exists($conn,'tbl_college_evaluation_student_exclusions')) {
        foreach (synk_evaluation_query($conn,'SELECT * FROM tbl_college_evaluation_student_exclusions WHERE college_id=? AND ay_id=? AND semester=?','iii',[$collegeId,$ayId,$semester]) as $rule) {
            if (isset($students[(int)$rule['student_id']])) $students[(int)$rule['student_id']]['rule']=$rule;
        }
    }
    return $students;
}

function synk_evaluation_save_student_exclusion(mysqli $conn,array $input): void
{
    if (($_SESSION['role'] ?? '')!=='college_dean' || empty($_SESSION['user_id'])) throw new DomainException('Only the assigned College Dean can manage student exclusions.');
    foreach (['csrf_token','college_id','ay_id','semester','student_id','version','excluded','reason'] as $key) {
        if (!isset($input[$key]) || !is_scalar($input[$key])) throw new DomainException('Invalid student exclusion request.');
    }
    if (!is_string($input['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$input['csrf_token'])) throw new DomainException('Your session token changed. Reload and try again.');
    foreach (['college_id','ay_id','semester','student_id','version'] as $key) {
        if (filter_var($input[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>$key==='version'?0:1,'max_range'=>2147483647]])===false) throw new DomainException('Choose a valid student and academic term.');
    }
    if (!in_array((string)$input['excluded'],['0','1'],true)) throw new DomainException('Choose a valid exclusion action.');
    $reason=trim((string)$input['reason']);
    if ($reason==='' || preg_match('//u',$reason)!==1 || mb_strlen($reason,'UTF-8')>1000) throw new DomainException('Enter a reason using 1 to 1,000 characters.');
    $college=(int)$input['college_id']; $ay=(int)$input['ay_id']; $semester=(int)$input['semester']; $student=(int)$input['student_id']; $actor=(int)$_SESSION['user_id'];
    if ($semester>3 || !synk_evaluation_query($conn,'SELECT ay_id FROM tbl_academic_years WHERE ay_id=?','i',[$ay])) throw new DomainException('Choose an available academic term.');
    if (!synk_evaluation_student_exclusions_ready($conn)) throw new DomainException('Student exclusion storage is not prepared.');
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start the student exclusion transaction.');
    try {
        synk_evaluation_query($conn,'SELECT user_id FROM tbl_useraccount WHERE user_id=? FOR UPDATE','i',[$actor]);
        synk_evaluation_query($conn,'SELECT user_id FROM tbl_useraccount_roles WHERE user_id=? FOR UPDATE','i',[$actor]);
        $account=synk_find_useraccount_by_id($conn,$actor);
        if (!$account || !synk_college_dean_account_has_access($conn,$account) || (int)$account['college_dean_college_id']!==$college) throw new DomainException('Your college assignment changed. Reload the page.');
        $active=synk_evaluation_query($conn,"SELECT c.college_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.college_id=? AND c.status='active' AND cp.status='active' FOR UPDATE",'i',[$college]);
        if (!$active) throw new DomainException('An active college and campus are required.');
        $students=synk_evaluation_student_candidates($conn,$college,$ay,$semester);
        if (!isset($students[$student])) throw new DomainException('This student is outside your college and selected term.');
        $previous=synk_evaluation_query($conn,'SELECT excluded,reason,version FROM tbl_college_evaluation_student_exclusions WHERE college_id=? AND ay_id=? AND semester=? AND student_id=? FOR UPDATE','iiii',[$college,$ay,$semester,$student]);
        $old=$previous[0] ?? ['excluded'=>0,'reason'=>'','version'=>0];
        if ((int)$old['version']!==(int)$input['version']) throw new DomainException('This student exclusion changed in another request. Reload before saving.');
        $new=['excluded'=>(int)$input['excluded'],'reason'=>$reason,'version'=>(int)$old['version']+1];
        $oldJson=json_encode($old,JSON_UNESCAPED_UNICODE); $newJson=json_encode($new,JSON_UNESCAPED_UNICODE);
        if ($oldJson===false || $newJson===false) throw new RuntimeException('Unable to preserve the student exclusion history.');
        synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_student_exclusions (college_id,ay_id,semester,student_id,excluded,reason,version,updated_by)
            VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE excluded=VALUES(excluded),reason=VALUES(reason),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP',
            'iiiiisii',[$college,$ay,$semester,$student,$new['excluded'],$reason,$new['version'],$actor]);
        synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_student_exclusion_audit (college_id,ay_id,semester,student_id,actor_user_id,previous_value,new_value) VALUES (?,?,?,?,?,?,?)',
            'iiiiiss',[$college,$ay,$semester,$student,$actor,$oldJson,$newJson]);
        if (!$conn->commit()) throw new RuntimeException('Unable to commit the student exclusion change.');
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
