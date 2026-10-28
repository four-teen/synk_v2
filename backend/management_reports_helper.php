<?php
require_once __DIR__.'/management_catalog_helper.php';
require_once __DIR__.'/management_student_financial_helper.php';
require_once __DIR__.'/management_attendance_helper.php';

function synk_management_report_types(array $permissions): array
{
    $types=[];
    if(synk_management_operation_allowed($permissions,'records')){
        $types['collection_summary']=['label'=>'Collection Summary','description'=>'Amounts actually received, separated by payment category. Split receipts are counted once in the overall total.'];
        $types['collection_register']=['label'=>'Detailed Collections','description'=>'Receipt allocations grouped into contributions and event absence fines, with student and cashier details.'];
        $types['absence_fines']=['label'=>'Event Absence Fines','description'=>'Assessed, collected, and outstanding event absence fines for students on finalized attendance rosters.'];
    }
    if(synk_management_operation_allowed($permissions,'events'))$types['event_attendance']=['label'=>'Event Attendance','description'=>'One event, with absent students first and present students second. Numbering continues across both categories. Open attendance is provisional.'];
    return $types;
}

function synk_management_report_period_values(array $scope,array $period): array
{
    return [$scope['college_id'],$scope['organization_id'],$period['ay_id'],$period['semester'],$period['exam_term']];
}

function synk_management_report_events(mysqli $conn,array $scope,array $period): array
{
    if(!synk_management_catalog_ready($conn))return [];
    return synk_management_query($conn,'SELECT e.event_id,e.title,e.event_date,a.attendance_status
        FROM tbl_management_events e LEFT JOIN tbl_management_attendance_events a ON a.event_id=e.event_id
        WHERE e.college_id=? AND e.organization_id=? AND e.ay_id=? AND e.semester=? AND e.exam_term=?
        ORDER BY e.event_date,e.title,e.event_id','iiiis',synk_management_report_period_values($scope,$period));
}

function synk_management_report_collections(mysqli $conn,array $scope,array $period,bool $details): array
{
    if(!synk_management_payments_ready($conn))throw new RuntimeException('Payment storage is unavailable.');
    $values=synk_management_report_period_values($scope,$period);
    $where=' WHERE p.college_id=? AND p.organization_id=? AND p.ay_id=? AND p.semester=? AND p.exam_term=?';
    $totals=synk_management_query($conn,'SELECT COUNT(*) AS receipts,COUNT(DISTINCT p.student_id) AS students,COALESCE(SUM(p.amount),0) AS collected FROM tbl_management_payments p'.$where,'iiiis',$values)[0];
    // Classify by saved allocation identity, not an editable catalog title.
    $violationReady=synk_management_student_violations_ready($conn);
    $withoutViolation=$violationReady?' AND i.student_violation_id IS NULL':'';
    $category="CASE WHEN i.contribution_id IS NOT NULL AND i.event_id IS NULL".$withoutViolation." THEN 'contributions' WHEN i.event_id IS NOT NULL AND i.contribution_id IS NULL".$withoutViolation." THEN 'event_fines' ".($violationReady?"WHEN i.student_violation_id IS NOT NULL AND i.contribution_id IS NULL AND i.event_id IS NULL THEN 'violations' ":'')."ELSE 'unclassified' END";
    $categories=['contributions'=>['label'=>'Contributions','collected'=>'0.00','receipts'=>0,'students'=>0],
        'event_fines'=>['label'=>'Event absence fines','collected'=>'0.00','receipts'=>0,'students'=>0],
        'violations'=>['label'=>'Violation fines','collected'=>$violationReady?'0.00':null,'receipts'=>$violationReady?0:null,'students'=>$violationReady?0:null]];
    foreach(synk_management_query($conn,'SELECT '.$category.' AS category,COUNT(DISTINCT p.payment_id) AS receipts,COUNT(DISTINCT p.student_id) AS students,SUM(i.amount) AS collected FROM tbl_management_payment_items i JOIN tbl_management_payments p ON p.payment_id=i.payment_id'.$where.' GROUP BY category','iiiis',$values)as$row){
        $categories[$row['category']]=['label'=>$row['category']==='unclassified'?'Unclassified allocations':$categories[$row['category']]['label'],'collected'=>$row['collected'],'receipts'=>(int)$row['receipts'],'students'=>(int)$row['students']];
    }
    $allocated=0;foreach($categories as$row)if($row['collected']!==null)$allocated+=synk_management_money_cents($row['collected']);
    $totals['allocated']=synk_management_money_text($allocated);
    $totals['reconciled']=$allocated===synk_management_money_cents($totals['collected']);
    $rows=[];
    if($details)$rows=synk_management_query($conn,'SELECT '.$category.' AS category,p.payment_id,p.receipt_number,p.student_number,p.student_name,p.recorded_at,p.recorded_by_name,i.title,i.amount,i.payment_item_id
        FROM tbl_management_payment_items i JOIN tbl_management_payments p ON p.payment_id=i.payment_id'.$where.' ORDER BY category,p.recorded_at,p.payment_id,i.payment_item_id','iiiis',$values);
    foreach($rows as&$row)$row['recorded_at']=(new DateTimeImmutable($row['recorded_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A');
    unset($row);
    return ['totals'=>$totals,'categories'=>$categories,'rows'=>$rows];
}

function synk_management_report_attendance(mysqli $conn,array $scope,array $period,int $eventId): array
{
    if(!synk_management_attendance_ready($conn))throw new RuntimeException('Attendance storage is unavailable.');
    $event=synk_management_catalog_get($conn,$scope,'events',$eventId);
    if(!synk_management_catalog_in_period($event,$period))throw new OutOfBoundsException('Choose an event in the selected Management period.');
    $settings=synk_management_query($conn,'SELECT attendance_status,absence_fine,opened_at,closed_at FROM tbl_management_attendance_events WHERE event_id=? AND college_id=? AND organization_id=? AND ay_id=? AND semester=? AND exam_term=?','iiiiis',array_merge([$eventId],synk_management_report_period_values($scope,$period)));
    $status=$settings[0]['attendance_status'] ?? 'NOT_STARTED';
    $rows=synk_management_query($conn,"SELECT s.student_id,s.student_number,CONCAT_WS(' ',s.last_name,s.first_name,NULLIF(s.middle_name,''),NULLIF(s.suffix_name,'')) AS student_name,l.recorded_at
        FROM tbl_management_attendance_roster r JOIN tbl_student_management s ON s.student_id=r.student_id
        LEFT JOIN tbl_management_attendance_logs l ON l.event_id=r.event_id AND l.student_id=r.student_id
        WHERE r.event_id=? ORDER BY (l.student_id IS NOT NULL),s.last_name,s.first_name,s.student_id",'i',[$eventId]);
    $groups=['absent'=>[],'present'=>[]];$number=0;
    foreach($rows as$row){
        $row['number']=++$number;
        if($row['recorded_at']!==null)$row['recorded_at']=(new DateTimeImmutable($row['recorded_at'],new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A');
        $groups[$row['recorded_at']===null?'absent':'present'][]=$row;
    }
    return ['event'=>$event,'status'=>$status,'groups'=>$groups,'total'=>$number,'finalized'=>$status==='CLOSED'];
}

function synk_management_report_absence_fines(mysqli $conn,array $scope,array $period,?int $eventId): array
{
    if(!synk_management_attendance_ready($conn) || !synk_management_payments_ready($conn))throw new RuntimeException('Attendance and payment storage are required.');
    $values=synk_management_report_period_values($scope,$period);
    $where=' WHERE a.college_id=? AND a.organization_id=? AND a.ay_id=? AND a.semester=? AND a.exam_term=?';
    if($eventId){$where.=' AND a.event_id=?';$values[]=$eventId;}
    $types='iiiis'.($eventId?'i':'');
    $states=synk_management_query($conn,'SELECT a.attendance_status,COUNT(*) AS events FROM tbl_management_attendance_events a'.$where.' GROUP BY a.attendance_status',$types,$values);
    $rows=synk_management_query($conn,"SELECT a.event_id,e.title,e.event_date,s.student_id,s.student_number,CONCAT_WS(' ',s.last_name,s.first_name,NULLIF(s.middle_name,''),NULLIF(s.suffix_name,'')) AS student_name,a.absence_fine AS assessed,COALESCE(paid.amount,0) AS collected
        FROM tbl_management_attendance_events a JOIN tbl_management_events e ON e.event_id=a.event_id
        JOIN tbl_management_attendance_roster r ON r.event_id=a.event_id JOIN tbl_student_management s ON s.student_id=r.student_id
        LEFT JOIN tbl_management_attendance_logs l ON l.event_id=r.event_id AND l.student_id=r.student_id
        LEFT JOIN (SELECT p.student_id,i.event_id,SUM(i.amount) AS amount FROM tbl_management_payment_items i JOIN tbl_management_payments p ON p.payment_id=i.payment_id
            WHERE p.college_id=? AND p.organization_id=? AND p.ay_id=? AND p.semester=? AND p.exam_term=? AND i.event_id IS NOT NULL GROUP BY p.student_id,i.event_id) paid ON paid.student_id=r.student_id AND paid.event_id=a.event_id".$where." AND a.attendance_status='CLOSED' AND l.student_id IS NULL AND a.absence_fine>0 ORDER BY e.event_date,e.title,e.event_id,s.last_name,s.first_name,s.student_id",'iiiis'.$types,array_merge(synk_management_report_period_values($scope,$period),$values));
    $totals=['assessed'=>0,'collected'=>0,'outstanding'=>0];$students=[];$events=[];
    foreach($rows as&$row){$assessed=synk_management_money_cents($row['assessed']);$collected=synk_management_money_cents($row['collected']);$balance=max(0,$assessed-$collected);$row['outstanding']=synk_management_money_text($balance);$totals['assessed']+=$assessed;$totals['collected']+=$collected;$totals['outstanding']+=$balance;$students[$row['student_id']]=true;$events[$row['event_id']]=true;}
    unset($row);foreach($totals as&$amount)$amount=synk_management_money_text($amount);unset($amount);
    return ['rows'=>$rows,'totals'=>$totals,'states'=>array_column($states,'events','attendance_status'),'students'=>count($students),'events'=>count($events)];
}

function synk_management_reports_prepare(mysqli $conn,array $scope,array $period,array $input): array
{
    $permissions=synk_management_require_permission($conn,$scope,'reports');
    $types=synk_management_report_types($permissions);
    foreach(['report_type','event_id','generate','institution','prepared_by','checked_by','approved_by']as$key)if(isset($input[$key])&&!is_scalar($input[$key]))throw new DomainException('Choose valid report options.');
    $type=(string)($input['report_type'] ?? array_keys($types)[0]);
    if(!isset($types[$type]))throw new SynkManagementPermissionDenied('This report type is unavailable for your account.');
    if(isset($input['generate']) && (string)$input['generate']!=='1')throw new DomainException('Choose Generate report.');
    $eventId=synk_management_input_id($input['event_id'] ?? '',true);
    $events=in_array($type,['event_attendance','absence_fines'],true)?synk_management_report_events($conn,$scope,$period):[];
    if($eventId && $events && !in_array($eventId,array_map('intval',array_column($events,'event_id')),true))throw new OutOfBoundsException('Choose an event in your organization and selected Management period.');
    if($eventId && in_array($type,['event_attendance','absence_fines'],true) && !$events)throw new OutOfBoundsException('No matching event in this Management period.');
    $header=[];
    foreach(['institution'=>'INSTITUTION NAME','prepared_by'=>(string)($_SESSION['username'] ?? ''),'checked_by'=>'','approved_by'=>'']as$key=>$default){$value=trim((string)($input[$key] ?? $default));if(mb_strlen($value)>150 || !preg_match('//u',$value) || preg_match('/[\x00-\x1f\x7f]/u',$value))throw new DomainException('Use up to 150 characters for each print header field.');$header[$key]=$value;}
    if($header['institution']==='')$header['institution']='INSTITUTION NAME';
    $report=['types'=>$types,'type'=>$type,'events'=>$events,'event_id'=>$eventId,'header'=>$header,'generated'=>false,'data'=>null,'error'=>''];
    if(!isset($input['generate']))return $report;
    if($type==='event_attendance' && !$eventId){$report['error']='Choose an event, then generate the attendance report.';return $report;}
    // A read snapshot keeps totals, allocation rows and attendance categories consistent.
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try{
        synk_management_require_permission($conn,$scope,$type==='event_attendance'?'events':'records');
        if(in_array($type,['collection_summary','collection_register'],true))$data=synk_management_report_collections($conn,$scope,$period,$type==='collection_register');
        elseif($type==='event_attendance')$data=synk_management_report_attendance($conn,$scope,$period,$eventId);
        else $data=synk_management_report_absence_fines($conn,$scope,$period,$eventId);
        $conn->commit();
    }catch(Throwable $error){$conn->rollback();throw $error;}
    $report['generated']=true;$report['data']=$data;$report['generated_at']=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A');
    return $report;
}
