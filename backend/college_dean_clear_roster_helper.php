<?php
require_once __DIR__.'/college_dean_manual_roster_helper.php';
require_once __DIR__.'/student_class_clear_history_helper.php';

function synk_dean_clear_plan(mysqli $conn,array $class,bool $lock=false): array
{
    $rows=synk_dean_students_query($conn,"SELECT es.*,s.student_number,s.last_name,s.first_name,s.middle_name,s.suffix_name
        FROM tbl_student_management_enrolled_subjects es JOIN tbl_student_management s ON s.student_id=es.student_id
        WHERE (es.offering_id=? OR es.offering_id=0) AND es.program_id=? AND es.section_id=? AND es.subject_id=?
        AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=? AND es.is_active=1
        ORDER BY es.student_enrollment_id".($lock?' FOR UPDATE':''),'iiiiiiii',[
            $class['offering_id'],$class['program_id'],$class['section_id'],$class['subject_id'],
            $class['college_id'],$class['campus_id'],$class['ay_id'],$class['semester']]);
    if (!$rows) throw new DomainException('This class list is already empty. Refresh the workload page.');
    if (count($rows)>5000) throw new DomainException('This class has more than 5,000 membership records. Ask an administrator to review it.');
    $students=synk_dean_roster_rows($conn,$class);
    $target=array_intersect_key($class,array_flip(['offering_id','college_id','campus_id','ay_id','semester','program_id','section_id','subject_id','subject_code','descriptive_title','section_display','faculty_id','faculty_name','schedules','term_text']));
    return ['rows'=>$rows,'students'=>$students,'target'=>$target,'fingerprint'=>hash('sha256',json_encode([$target,$rows]))];
}

function synk_dean_clear_preview(mysqli $conn,array $class): array
{
    $plan=synk_dean_clear_plan($conn,$class);
    $pending=(array)($_SESSION['dean_clear_roster_previews'] ?? []);
    foreach ($pending as $key=>$row) if ($row['expires_at']<time()) unset($pending[$key]);
    while (count($pending)>=3) array_shift($pending);
    $token=bin2hex(random_bytes(32));
    $pending[$token]=['user_id'=>(int)$_SESSION['user_id'],'college_id'=>$class['college_id'],'campus_id'=>$class['campus_id'],
        'ay_id'=>$class['ay_id'],'semester'=>$class['semester'],'faculty_id'=>$class['faculty_id'],'offering_id'=>$class['offering_id'],
        'expires_at'=>time()+900,'fingerprint'=>$plan['fingerprint']];
    $_SESSION['dean_clear_roster_previews']=$pending;
    return ['preview_token'=>$token,'class'=>$plan['target'],'students'=>array_map(static function(array $row):array {
        return array_intersect_key($row,array_flip(['student_number','student_name','entry_source']));
    },$plan['students']),'summary'=>['students'=>count($plan['students']),'memberships'=>count($plan['rows'])]];
}

function synk_dean_clear_save(mysqli $conn,array $scope,array $term,array $pending,string $token): array
{
    $userId=(int)$_SESSION['user_id'];
    if ($pending['user_id']!==$userId || $pending['college_id']!==$scope['college_id'] || $pending['campus_id']!==$scope['campus_id']
        || $pending['ay_id']!==$term['ay_id'] || $pending['semester']!==$term['semester'] || $pending['expires_at']<time()) throw new DomainException('This clearing preview expired or your access changed. Preview again.');
    $lock=$conn->query("SELECT GET_LOCK('synk_college_dean_roster_import',5) AS acquired")->fetch_assoc();
    if ((int)$lock['acquired']!==1) throw new DomainException('Another class-list update is being saved. Please try again.');
    $transaction=false; $emailLocked=false;
    try {
        synk_student_email_lock($conn); $emailLocked=true;
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin clearing this class list.');
        $transaction=true; synk_student_email_catalog($conn,true);
        // Hold the selected workload/schedule rows while the class is rechecked and cleared.
        $assignmentSource=synk_professor_workload_assignment_source_sql();
        $assignments=synk_dean_students_query($conn,"SELECT fw.workload_id {$assignmentSource}
            WHERE fw.faculty_id=? AND po.offering_id=? AND fw.ay_id=? AND fw.semester=? FOR UPDATE",'iiii',
            [$pending['faculty_id'],$pending['offering_id'],$pending['ay_id'],$pending['semester']]);
        if (!$assignments) throw new DomainException('This workload assignment changed. Refresh the class list before clearing.');
        $fresh=synk_college_dean_dashboard_scope($conn);
        synk_dean_require_current_term_for_write($conn,['ay_id'=>$pending['ay_id'],'semester'=>$pending['semester']]);
        if (($_SESSION['role'] ?? '')!=='college_dean' || !$fresh || $fresh['college_id']!==$scope['college_id'] || $fresh['campus_id']!==$scope['campus_id']) throw new DomainException('Your college access changed. Reload the page.');
        $class=synk_dean_workload_class($conn,$fresh,$term,$pending['faculty_id'],$pending['offering_id']);
        synk_dean_students_query($conn,'SELECT offering_id FROM tbl_prospectus_offering WHERE offering_id=? FOR UPDATE','i',[$class['offering_id']]);
        $plan=synk_dean_clear_plan($conn,$class,true);
        if (!hash_equals($pending['fingerprint'],$plan['fingerprint'])) throw new DomainException('The class assignment or student list changed after preview. Nothing was cleared. Preview again.');
        $actor=synk_find_useraccount_by_id($conn,$userId);
        foreach ($plan['rows'] as $row) {
            $params=[$token,(int)$row['student_enrollment_id'],(int)$row['student_id'],$row['student_number'],
                $class['campus_id'],$class['college_id'],$class['program_id'],$class['ay_id'],$class['semester'],$class['section_id'],
                $class['offering_id'],$class['subject_id'],$class['faculty_id'],json_encode($row,JSON_UNESCAPED_UNICODE),$userId,(string)($actor['username'] ?? 'College Dean')];
            $stmt=$conn->prepare('INSERT INTO tbl_student_class_clear_history
                (operation_key,student_enrollment_id,student_id,student_number,campus_id,college_id,program_id,ay_id,semester,section_id,offering_id,subject_id,faculty_id,membership_snapshot,actor_user_id,actor_name,recorded_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            synk_bind_dynamic_params($stmt,'siisiiiiiiiiisis',$params);
            try { synk_dean_roster_execute($stmt); } finally { $stmt->close(); }
            $stmt=$conn->prepare('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_enrollment_id=? AND is_active=1');
            $id=(int)$row['student_enrollment_id']; $stmt->bind_param('i',$id);
            try { synk_dean_roster_execute($stmt); if ($stmt->affected_rows!==1) throw new RuntimeException('The class membership changed.'); } finally { $stmt->close(); }
        }
        synk_dean_manual_count($conn,$class,$userId);
        if (!$conn->commit()) throw new RuntimeException('Unable to commit the class clearing.');
        $transaction=false;
        return ['offering_id'=>$class['offering_id'],'cleared_students'=>count($plan['students']),'cleared_memberships'=>count($plan['rows']),'roster_count'=>0,
            'message'=>count($plan['students']).' students cleared from this class. They remain in the Students list; accounts, other subjects and submitted evaluations were kept.'];
    } catch (Throwable $error) { if ($transaction) $conn->rollback(); throw $error; }
    finally { if ($emailLocked) synk_student_email_unlock($conn); $conn->query("SELECT RELEASE_LOCK('synk_college_dean_roster_import')"); }
}
