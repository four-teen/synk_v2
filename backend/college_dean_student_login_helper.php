<?php
require_once __DIR__.'/college_dean_students_helper.php';
require_once __DIR__.'/student_login_activity_helper.php';

function synk_dean_student_login_history(mysqli $conn, array $scope, int $studentId, array $input): ?array
{
    $source=synk_dean_students_source($scope,[]);
    $students=synk_dean_students_query($conn,"SELECT DISTINCT s.student_id,s.student_number,s.first_name,s.last_name,s.middle_name,s.suffix_name,s.email_address
        {$source['sql']} AND s.student_id=?",$source['types'].'i',array_merge($source['params'],[$studentId]));
    if (!$students) return null;
    if (!synk_student_login_activity_ready($conn)) throw new RuntimeException('Student login activity is not available.');
    $student=$students[0]; $student['student_id']=(int)$student['student_id']; $student['student_name']=synk_dean_students_name($student);
    $outcome=(string)($input['activity_outcome'] ?? 'all');
    if (!in_array($outcome,['all','success','failure'],true)) throw new DomainException('Choose a valid login outcome.');
    $page=filter_var($input['activity_page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
    $snapshot=filter_var($input['activity_snapshot'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if ($page===false || $snapshot===false) throw new DomainException('Choose a valid login history page.');
    $latest=synk_dean_students_query($conn,'SELECT COALESCE(MAX(activity_id),0) AS latest FROM tbl_student_login_activity WHERE student_id=?','i',[$studentId])[0];
    $snapshot=$snapshot>0?min($snapshot,(int)$latest['latest']):(int)$latest['latest'];
    $summary=synk_dean_students_query($conn,"SELECT COUNT(*) AS total_attempts,
        COALESCE(SUM(outcome='success'),0) AS successful_logins,COALESCE(SUM(outcome='failure'),0) AS failed_attempts,
        MAX(CASE WHEN outcome='success' THEN occurred_at END) AS last_success_at,MAX(occurred_at) AS last_attempt_at
        FROM tbl_student_login_activity WHERE student_id=? AND activity_id<=?",'ii',[$studentId,$snapshot])[0];
    foreach (['total_attempts','successful_logins','failed_attempts'] as $key) $summary[$key]=(int)$summary[$key];
    foreach (['last_success_at','last_attempt_at'] as $key) $summary[$key]=synk_student_login_activity_iso($summary[$key]);
    $summary['success_rate']=$summary['total_attempts']>0?round($summary['successful_logins']/$summary['total_attempts']*100,1):0;
    $total=$outcome==='all'?$summary['total_attempts']:($outcome==='success'?$summary['successful_logins']:$summary['failed_attempts']);
    $pageSize=25; $pages=max(1,(int)ceil($total/$pageSize)); $page=min($page,$pages); $offset=($page-1)*$pageSize;
    $sql='SELECT activity_id,submitted_email,outcome,reason_code,identity_verified,auth_method,ip_address,user_agent,browser,operating_system,device_type,occurred_at
        FROM tbl_student_login_activity WHERE student_id=? AND activity_id<=?';
    $types='ii'; $params=[$studentId,$snapshot];
    if ($outcome!=='all') { $sql.=' AND outcome=?'; $types.='s'; $params[]=$outcome; }
    $sql.=' ORDER BY occurred_at DESC,activity_id DESC LIMIT ? OFFSET ?'; $types.='ii'; $params[]=$pageSize; $params[]=$offset;
    $rows=synk_dean_students_query($conn,$sql,$types,$params);
    foreach ($rows as &$row) {
        $row['activity_id']=(string)$row['activity_id']; $row['identity_verified']=(bool)$row['identity_verified'];
        $row['occurred_at']=synk_student_login_activity_iso($row['occurred_at']);
        $row['reason']=synk_student_login_activity_reason($row['reason_code']);
    }
    unset($row);
    return ['student'=>$student,'summary'=>$summary,'rows'=>$rows,'outcome'=>$outcome,'snapshot'=>$snapshot,
        'timezone'=>'Asia/Manila','pagination'=>['page'=>$page,'pages'=>$pages,'page_size'=>$pageSize,'total'=>$total,
            'from'=>$total?$offset+1:0,'to'=>$offset+count($rows)]];
}
