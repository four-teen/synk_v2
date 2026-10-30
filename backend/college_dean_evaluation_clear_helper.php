<?php
require_once __DIR__.'/college_dean_evaluations_helper.php';

function synk_dean_evaluation_clear_ready(mysqli $conn): bool
{
    return synk_evaluation_table_exists($conn,'tbl_college_faculty_evaluation_clear_history');
}

function synk_dean_evaluation_clear_actor(mysqli $conn, array $expected, bool $lock=false): array
{
    if (($_SESSION['role'] ?? '')!=='college_dean' || empty($_SESSION['user_id'])) throw new DomainException('Only the assigned College Dean can clear a faculty evaluation.',403);
    if ($lock) {
        synk_evaluation_query($conn,'SELECT user_id FROM tbl_useraccount WHERE user_id=? FOR UPDATE','i',[(int)$_SESSION['user_id']]);
        synk_evaluation_query($conn,'SELECT user_id FROM tbl_useraccount_roles WHERE user_id=? FOR UPDATE','i',[(int)$_SESSION['user_id']]);
    }
    $scope=synk_college_dean_dashboard_scope($conn);
    if (!$scope || $scope['college_id']!==(int)$expected['college_id'] || $scope['campus_id']!==(int)$expected['campus_id']) {
        throw new DomainException('Your college access changed. Reload the page before clearing.',403);
    }
    return $scope;
}

function synk_dean_evaluation_clear_plan(mysqli $conn,array $scope,array $term,int $facultyId,string $version,bool $lock=false): array
{
    if ($facultyId<1) throw new DomainException('Select one faculty member to clear.',422);
    $filters=synk_dean_evaluations_filters(['faculty_id'=>$facultyId,'instrument_version'=>$version]);
    $years=synk_evaluation_query($conn,'SELECT ay FROM tbl_academic_years WHERE ay_id=?','i',[(int)$term['ay_id']]);
    if (!$years || !in_array((int)$term['semester'],[1,2,3],true)) throw new DomainException('Choose a valid academic term.',422);
    if ($lock) {
        // Student draft/final writers share-lock this setting. Serialize clearing
        // with those writes, including concurrent attempts to create a response.
        synk_evaluation_query($conn,'SELECT version FROM tbl_college_evaluation_settings WHERE college_id=? AND ay_id=? AND semester=? FOR UPDATE',
            'iii',[$scope['college_id'],$term['ay_id'],$term['semester']]);
        synk_evaluation_query($conn,'SELECT faculty_id FROM tbl_faculty WHERE faculty_id=? FOR UPDATE','i',[$facultyId]);
        // Legacy ownership depends on these memberships, including foreign ones.
        synk_evaluation_query($conn,'SELECT student_enrollment_id FROM tbl_student_management_enrolled_subjects WHERE faculty_id=? AND ay_id=? AND semester=? FOR UPDATE',
            'iii',[$facultyId,$term['ay_id'],$term['semester']]);
    }
    $source=synk_dean_evaluations_source($conn,$scope,$term,$filters['instrument_version']);
    $headers=synk_dean_evaluations_query($conn,'SELECT e.* '.$source['sql'].' AND e.faculty_id=? ORDER BY e.evaluation_id'.($lock?' FOR UPDATE':''),
        $source['types'].'i',array_merge($source['params'],[$facultyId]));
    if (!$headers) throw new DomainException('No evaluations are available for this faculty in your college, term and instrument.',404);
    if (count($headers)>10000) throw new DomainException('This faculty has more than 10,000 responses. Ask an administrator to review the clearing request.',422);
    $ids=array_map('intval',array_column($headers,'evaluation_id'));
    $answers=synk_dean_evaluations_query($conn,'SELECT * FROM tbl_student_faculty_evaluation_answers WHERE evaluation_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY answer_id'.($lock?' FOR UPDATE':''),str_repeat('i',count($ids)),$ids);
    $faculty=synk_evaluation_query($conn,"SELECT CONCAT(last_name, ', ',TRIM(CONCAT_WS(' ',first_name,NULLIF(middle_name,''),NULLIF(ext_name,'')))) AS name FROM tbl_faculty WHERE faculty_id=?",'i',[$facultyId]);
    $target=['faculty_id'=>$facultyId,'faculty_name'=>$faculty[0]['name'] ?? $headers[0]['faculty_name'],
        'campus_id'=>$scope['campus_id'],'college_id'=>$scope['college_id'],'college_name'=>$scope['college_name'],
        'ay_id'=>(int)$term['ay_id'],'semester'=>(int)$term['semester'],'term_text'=>$years[0]['ay'].' / '.synk_semester_label((int)$term['semester']),
        'instrument_version'=>$version,'instrument_label'=>$version==='all'?'All instruments':synk_student_faculty_evaluation_instrument($version)['label']];
    $snapshot=json_encode(['target'=>$target,'evaluations'=>$headers,'answers'=>$answers],JSON_UNESCAPED_UNICODE);
    if ($snapshot===false) throw new RuntimeException('The clearing history could not be prepared.');
    $submitted=count(array_filter($headers,static function(array $row):bool{return $row['submission_status']==='submitted';}));
    return ['target'=>$target,'ids'=>$ids,'snapshot'=>$snapshot,'fingerprint'=>hash('sha256',$snapshot),
        'counts'=>['submitted'=>$submitted,'drafts'=>count($headers)-$submitted,'answers'=>count($answers)]];
}

function synk_dean_evaluation_clear_preview(mysqli $conn,array $scope,array $term,int $facultyId,string $version): array
{
    $scope=synk_dean_evaluation_clear_actor($conn,$scope);
    if (!synk_dean_evaluation_clear_ready($conn)) throw new DomainException('Evaluation clearing history is not prepared. Ask your administrator to apply the faculty evaluation clearing update.',503);
    $plan=synk_dean_evaluation_clear_plan($conn,$scope,$term,$facultyId,$version);
    $pending=(array)($_SESSION['dean_evaluation_clear_previews'] ?? []);
    foreach ($pending as $key=>$preview) if (($preview['expires_at'] ?? 0)<time()) unset($pending[$key]);
    while (count($pending)>=5) array_shift($pending);
    $token=bin2hex(random_bytes(32));
    $pending[$token]=['user_id'=>(int)$_SESSION['user_id'],'target'=>$plan['target'],'fingerprint'=>$plan['fingerprint'],'expires_at'=>time()+900];
    $_SESSION['dean_evaluation_clear_previews']=$pending;
    return ['preview_token'=>$token,'target'=>$plan['target'],'counts'=>$plan['counts']];
}

function synk_dean_evaluation_clear_save(mysqli $conn,array $scope,string $token): array
{
    $pending=$_SESSION['dean_evaluation_clear_previews'][$token] ?? null;
    if (!is_array($pending) || ($pending['expires_at'] ?? 0)<time() || $pending['user_id']!==(int)($_SESSION['user_id'] ?? 0)) throw new DomainException('This clearing preview expired. Review the faculty again.',409);
    if (!synk_dean_evaluation_clear_ready($conn)) throw new DomainException('Evaluation clearing history is unavailable. Nothing was cleared.',503);
    $target=$pending['target'];
    if ($target['college_id']!==$scope['college_id'] || $target['campus_id']!==$scope['campus_id']) throw new DomainException('The preview is outside your current college.',403);
    if (!$conn->begin_transaction()) throw new RuntimeException('The evaluation clearing transaction could not be started.');
    try {
        $scope=synk_dean_evaluation_clear_actor($conn,$scope,true);
        $plan=synk_dean_evaluation_clear_plan($conn,$scope,$target,$target['faculty_id'],$target['instrument_version'],true);
        if (!hash_equals($pending['fingerprint'],$plan['fingerprint'])) throw new DomainException('These evaluations changed after preview. Nothing was cleared. Review the latest counts and try again.',409);
        $actor=synk_find_useraccount_by_id($conn,(int)$_SESSION['user_id']);
        $values=[$token,$scope['campus_id'],$scope['college_id'],$target['faculty_id'],$target['ay_id'],$target['semester'],$target['instrument_version'],
            $plan['counts']['submitted'],$plan['counts']['drafts'],$plan['snapshot'],(int)$_SESSION['user_id'],$actor['username']];
        synk_evaluation_query($conn,'INSERT INTO tbl_college_faculty_evaluation_clear_history
            (operation_key,campus_id,college_id,faculty_id,ay_id,semester,instrument_version,submitted_count,draft_count,evaluation_snapshot,actor_user_id,actor_name,cleared_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())','siiiiisiisis',$values);
        $slots=implode(',',array_fill(0,count($plan['ids']),'?'));
        synk_evaluation_query($conn,'DELETE FROM tbl_student_faculty_evaluation_answers WHERE evaluation_id IN ('.$slots.')',str_repeat('i',count($plan['ids'])),$plan['ids']);
        $stmt=$conn->prepare('DELETE FROM tbl_student_faculty_evaluations WHERE evaluation_id IN ('.$slots.')');
        try {
            synk_bind_dynamic_params($stmt,str_repeat('i',count($plan['ids'])),$plan['ids']);
            if (!$stmt->execute() || $stmt->affected_rows!==count($plan['ids'])) throw new RuntimeException('The evaluations could not be cleared together.');
        } finally { $stmt->close(); }
        if (!$conn->commit()) throw new RuntimeException('The evaluation clearing could not be committed.');
        unset($_SESSION['dean_evaluation_clear_previews'][$token]);
        return ['target'=>$plan['target'],'counts'=>$plan['counts'],'message'=>'Cleared '.$plan['counts']['submitted'].' submitted evaluation(s) and '.$plan['counts']['drafts'].' saved draft(s) for '.$plan['target']['faculty_name'].'.'];
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
