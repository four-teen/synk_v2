<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function synk_qa_management_payment_correction_data(mysqli $db): array
{
    $insert = static function (string $table, array $row) use ($db): int {
        $columns = implode(',',array_keys($row));
        $parameters = implode(',',array_fill(0,count($row),'?'));
        synk_management_execute($db,"INSERT INTO $table ($columns) VALUES ($parameters)",str_repeat('s',count($row)),array_values($row));
        return (int)$db->insert_id;
    };
    $tag = 'CORR-QA-'.bin2hex(random_bytes(5));
    $college = $db->query("SELECT p.program_id,c.college_id,c.campus_id FROM tbl_program p
        JOIN tbl_college c ON c.college_id=p.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id
        WHERE p.status='active' AND c.status='active' AND cp.status='active' ORDER BY p.program_id LIMIT 1")->fetch_assoc();
    $academic = synk_fetch_current_academic_term($db);
    $organization = static function (string $code) use ($insert,$college,$academic): array {
        $id = $insert('tbl_management_organizations',['college_id'=>$college['college_id'],'organization_code'=>$code,'organization_name'=>$code]);
        $scope = ['college_id'=>(int)$college['college_id'],'campus_id'=>(int)$college['campus_id'],
            'organization_id'=>$id,'organization_code'=>$code,'organization_name'=>$code];
        $insert('tbl_management_period_settings',array_intersect_key($scope,array_flip(['college_id','organization_id']))
            +['ay_id'=>$academic['ay_id'],'semester'=>$academic['semester'],'current_exam_term'=>'FINAL']);
        return $scope;
    };
    $scope = $organization($tag);
    $otherScope = $organization($tag.'-OTHER');
    $period = synk_management_fetch_current_period($db,$scope);
    $midterm = synk_management_view_term($db,['term'=>$period['key'],'exam_term'=>'MIDTERM'],$scope);
    $makeUser = static function (string $role, string $label, array $values = []) use ($insert,$db,$tag): int {
        $id = $insert('tbl_useraccount',$values+['username'=>"$tag $label",'email'=>strtolower("$tag-$label").'@gmail.com',
            'password'=>'unused','role'=>$role,'status'=>'active']);
        synk_persist_useraccount_roles($db,$id,[$role],$role);
        return $id;
    };
    $admin = $makeUser('admin','admin');
    $assignment = ['management_college_id'=>$scope['college_id'],'management_organization_id'=>$scope['organization_id']];
    $cashier = $makeUser('management','management',$assignment);
    $supervisor = $makeUser('management','faculty',$assignment);
    $faculty = $insert('tbl_faculty',['first_name'=>'Payment QA','last_name'=>$tag,'status'=>'active']);
    $insert('tbl_useraccount_faculty_links',['user_id'=>$supervisor,'faculty_id'=>$faculty]);
    $makeStudent = static function (string $suffix) use ($insert,$college,$period,$tag): int {
        return $insert('tbl_student_management',['student_number'=>"$tag-$suffix",'first_name'=>'Correction',
            'last_name'=>"Student $suffix",'email_address'=>strtolower("$tag-$suffix").'@sksu.edu.ph','source_sheet_name'=>'DEAN MANUAL',
            'program_id'=>$college['program_id'],'ay_id'=>$period['ay_id'],'semester'=>$period['semester'],'year_level'=>2]);
    };
    $student = $makeStudent('ONE');
    $otherStudent = $makeStudent('TWO');
    $officer = $makeUser('management','officer',$assignment+['email'=>strtolower("$tag-TWO").'@sksu.edu.ph']);
    $insert('tbl_management_officers',['user_id'=>$officer,'student_id'=>$otherStudent,'supervisor_user_id'=>$supervisor,
        'organization_id'=>$scope['organization_id'],'college_id'=>$scope['college_id'],'ay_id'=>$period['ay_id'],
        'semester'=>$period['semester'],'can_view'=>1,'can_payments'=>1,'expires_on'=>date('Y-m-d',strtotime('+30 days'))]);
    $periodColumns = static function (array $org, array $term): array {
        return array_intersect_key($org,array_flip(['college_id','organization_id']))
            +array_intersect_key($term,array_flip(['ay_id','semester','exam_term']));
    };
    $current = $periodColumns($scope,$period);
    $contribution = static function (array $context, string $title, string $amount) use ($insert): int {
        return $insert('tbl_management_contributions',$context+['title'=>$title,'contribution_date'=>date('Y-m-d'),'amount'=>$amount]);
    };
    $dues = $contribution($current,'Organization dues','60.00');
    $materials = $contribution($current,'Materials','40.00');
    $pastContribution = $contribution($periodColumns($scope,$midterm),'Midterm dues','7.00');
    $foreignContribution = $contribution($periodColumns($otherScope,$period),'Other organization dues','8.00');
    $event = $insert('tbl_management_events',$current+['title'=>'Correction QA event','event_date'=>date('Y-m-d'),'absence_fine'=>'25.00']);
    $insert('tbl_management_attendance_events',$current+['event_id'=>$event,'absence_fine'=>'25.00',
        'attendance_status'=>'CLOSED','opened_by'=>$cashier,'closed_by'=>$cashier]);
    $insert('tbl_management_attendance_roster',['event_id'=>$event,'student_id'=>$student]);
    $type = $insert('tbl_management_violations',array_diff_key($current,['exam_term'=>true])+['violation_type'=>'Correction QA violation','amount'=>'10.00']);
    $violation = $insert('tbl_management_student_violations',$current+['student_id'=>$student,'violation_id'=>$type,
        'occurred_on'=>date('Y-m-d'),'violation_type'=>'Correction QA violation','amount'=>'10.00','recorded_by'=>$cashier,
        'recorded_by_name'=>"$tag management",'recorded_at'=>gmdate('Y-m-d H:i:s'),'request_key'=>bin2hex(random_bytes(32)),'payload_hash'=>str_repeat('a',64)]);
    $usedAt = (new DateTimeImmutable('yesterday 23:59:59',new DateTimeZone('Asia/Manila')))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $record = static function (array $org, array $term, int $id, string $suffix, int $total, array $allocations)
        use ($insert,$usedAt,$periodColumns,$tag,$cashier): array {
        $number = $tag.'-'.$suffix;
        $totalAmount = synk_management_money_text($total);
        $paymentId = $insert('tbl_management_payments',$periodColumns($org,$term)+[
            'student_id'=>$id,'receipt_number'=>$number,'request_key'=>bin2hex(random_bytes(32)),'payload_hash'=>str_repeat('a',64),
            'allocation_mode'=>'SELECTED','amount'=>$totalAmount,'balance_before'=>$totalAmount,'balance_after'=>'0.00',
            'student_number'=>$tag.($suffix === 'SIBLING' ? '-TWO' : '-ONE'),'student_name'=>'Correction Student '.($suffix === 'SIBLING' ? 'TWO' : 'ONE'),
            'organization_code'=>$org['organization_code'],'organization_name'=>$org['organization_name'],'period_text'=>$term['period_text'],
            'recorded_by'=>$cashier,'recorded_by_name'=>"$tag management",'recorded_at'=>$usedAt,
        ]);
        $items = [];
        $columns = ['C'=>'contribution_id','E'=>'event_id','V'=>'student_violation_id'];
        foreach ($allocations as [$kind,$chargeId,$title,$amount]) {
            $items[$kind] = $insert('tbl_management_payment_items',[
                'payment_id'=>$paymentId,'charge_key'=>"$kind:$chargeId",$columns[$kind]=>$chargeId,
                'title'=>$title,'charge_amount'=>$amount,'paid_before'=>'0.00','amount'=>$amount,'balance_after'=>'0.00',
            ]);
        }
        return ['id'=>$paymentId,'number'=>$number,'items'=>$items];
    };
    $split = $record($scope,$period,$student,'SPLIT',9500,[
        ['C',$dues,'Organization dues','60.00'],['E',$event,'Correction QA event (absence fine)','25.00'],
        ['V',$violation,'Correction QA violation (violation)','10.00'],
    ]);
    $single = $record($scope,$period,$student,'SINGLE',4000,[['C',$materials,'Materials','40.00']]);
    $historical = $record($scope,$midterm,$student,'MIDTERM',700,[['C',$pastContribution,'Midterm dues','7.00']]);
    $foreign = $record($otherScope,$period,$student,'FOREIGN',800,[['C',$foreignContribution,'Other organization dues','8.00']]);
    $sibling = $record($scope,$period,$otherStudent,'SIBLING',6000,[['C',$dues,'Organization dues','60.00']]);
    return compact('scope','otherScope','period','midterm','admin','cashier','supervisor','faculty','officer','student','otherStudent','dues','materials','event',
        'split','single','historical','foreign','sibling','tag');
}
