<?php
if (!defined('SYNK_STUDENT_MANAGEMENT_QA')) { http_response_code(404); exit; }
require_once $root.'/backend/college_dean_student_management_helper.php';
$manageScenario=SYNK_STUDENT_MANAGEMENT_QA;
$nameTag=strtr(substr($tag,-14),'0123456789','ghijklmnop');
foreach (['shared'=>['José','Peña','Niño'],'own'=>['Carol Jan','Ambrocio','Güell']] as $key=>$parts) {
    $stmt=$conn->prepare('UPDATE tbl_student_management SET first_name=?,last_name=?,middle_name=? WHERE student_id=?');
    $last=$parts[1].$nameTag; $id=$profiles[$key]['student_id']; $stmt->bind_param('sssi',$parts[0],$last,$parts[2],$id); $stmt->execute(); $stmt->close();
}
$_POST=['action'=>'list','csrf_token'=>'dean-students-qa','search'=>$prefix,'term'=>$term['ay_id'].':'.$term['semester']];
$ownId=$profiles['own']['student_id']; $sharedId=$profiles['shared']['student_id'];
$putEmail=static function (int $id,string $value) use ($conn): void {
    $stmt=$conn->prepare('UPDATE tbl_student_management SET email_address=? WHERE student_id=?'); $stmt->bind_param('si',$value,$id); $stmt->execute(); $stmt->close();
};
if (in_array($manageScenario,['home_verified','home_unapproved','home_conflict','home_foreign','home_other_term'],true)) {
    $insertHome=static function (array $college,int $sectionId,int $semester,string $status) use ($conn,$term,$userId,$tag,$sharedId,$profiles): void {
        $reference=$tag.'-'.bin2hex(random_bytes(4)); $number=$profiles['shared']['student_number'];
        $stmt=$conn->prepare("INSERT INTO tbl_enrollment_headers (enrollment_reference,workflow_status,campus_id,college_id,program_id,section_id,ay_id,semester,year_level,student_id,student_number,created_by,approved_by,approved_at) VALUES (?,?,?,?,?,?,?,?,1,?,?,?, ?,NOW())");
        $stmt->bind_param('ssiiiiiiisii',$reference,$status,$college['campus_id'],$college['college_id'],$college['program_id'],$sectionId,$term['ay_id'],$semester,$sharedId,$number,$userId,$userId); $stmt->execute(); $stmt->close();
    };
    if ($manageScenario==='home_foreign') $insertHome($foreignScope,$foreignSection,$term['semester'],'approved');
    elseif ($manageScenario==='home_other_term') $insertHome($scope,$historicalSection,$otherSemester,'approved');
    else {
        $insertHome($scope,$ownSection,$term['semester'],$manageScenario==='home_unapproved'?'submitted':'approved');
        if ($manageScenario==='home_conflict') {
            $duplicateSection=$section($scope,$term['semester']);
            $insertHome($scope,$duplicateSection,$term['semester'],'approved');
        }
    }
}
if ($manageScenario==='form_options') $_POST['action']='form_options';
if ($manageScenario==='selection') $_POST['action']='selection';
if ($manageScenario==='whole_year') $_POST['term']='year:'.$term['ay_id'];
if (strpos($manageScenario,'edit_load')===0) {
    $_POST['action']='edit_load';
    $_POST['student_id']=$profiles[$manageScenario==='edit_load_foreign'?'foreign':'own']['student_id'];
}
if (strpos($manageScenario,'propose')===0) {
    $_POST+=['student_number'=>$profiles['own']['student_number'],'first_name'=>'Carol Jan','last_name'=>'Ambrocio'.$nameTag];
    $_POST['action']='email_propose';
    if ($manageScenario==='propose_initial') $_POST['first_name']='C. Jan';
    if ($manageScenario==='propose_collision') $putEmail($profiles['foreign']['student_id'],'caroljanambrocio'.$nameTag.'@sksu.edu.ph');
}
if (strpos($manageScenario,'create')===0 || strpos($manageScenario,'save')===0) {
    $new=strpos($manageScenario,'create')===0;
    $before=synk_dean_student_manage_record($conn,$scope,$ownId);
    $_POST+=['student_number'=>$new?$prefix.'99':$before['student_number'],'first_name'=>'Carol Jane','last_name'=>'Ambrocio'.$nameTag,
        'middle_name'=>'Güell','suffix_name'=>'','email_address'=>'verified'.$nameTag.'@sksu.edu.ph',
        'student_id'=>$new?0:$ownId,'version'=>$before['version'],'record_program_id'=>$scope['program_id'],
        'record_term'=>$term['ay_id'].':'.$term['semester'],'year_level'=>2];
    $_POST['action']='student_save';
    if ($manageScenario==='create_blank_email') $_POST['email_address']='';
    if ($manageScenario==='create_duplicate') $_POST['student_number']=$profiles['shared']['student_number'];
    if ($manageScenario==='create_foreign_program') $_POST['record_program_id']=$foreignScope['program_id'];
    if ($manageScenario==='create_bad_term') $_POST['record_term']='year:'.$term['ay_id'];
    if ($manageScenario==='create_bad_year') $_POST['year_level']=8;
    if ($manageScenario==='save_foreign') $_POST['student_id']=$profiles['foreign']['student_id'];
    if ($manageScenario==='save_shared') {
        $before=synk_dean_student_manage_record($conn,$scope,$sharedId);
        $_POST['student_id']=$sharedId; $_POST['student_number']=$before['student_number']; $_POST['version']=$before['version'];
    }
    if ($manageScenario==='save_stale') $conn->query("UPDATE tbl_student_management SET first_name='Changed Name' WHERE student_id=".$ownId);
    if ($manageScenario==='save_id_change') $_POST['student_number']=$prefix.'100';
    if ($manageScenario==='save_collision') $putEmail($profiles['foreign']['student_id'],$_POST['email_address']);
    if ($manageScenario==='save_bad_email') $_POST['email_address']='wrong@example.com';
    if ($manageScenario==='save_damaged_name') $_POST['last_name']='Pe??a';
    if ($manageScenario==='save_blank_name') $_POST['first_name']='';
    if ($manageScenario==='save_array') $_POST['first_name']=['Carol'];
    if (in_array($manageScenario,['save_portal','save_portal_conflict'],true)) {
        $old='old'.$nameTag.'@sksu.edu.ph'; $putEmail($ownId,$old);
        $conn->query("INSERT INTO tbl_student_portal_profile (email_address,student_number,program_id,locked_at) VALUES ('{$old}','".$profiles['own']['student_number']."',".$scope['program_id'].",NOW())");
        $_POST['version']=synk_dean_student_manage_record($conn,$scope,$ownId)['version'];
        if ($manageScenario==='save_portal_conflict') $conn->query("INSERT INTO tbl_student_portal_profile (email_address,student_number,program_id) VALUES ('".$_POST['email_address']."','".$profiles['foreign']['student_number']."',".$foreignScope['program_id'].")");
    }
    if ($manageScenario==='save_bad_csrf') $_POST['csrf_token']='bad';
    if ($manageScenario==='save_revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='college_dean'");
}
if (strpos($manageScenario,'delete')===0) {
    $ids=[$sharedId,$ownId]; sort($ids);
    if (in_array($manageScenario,['delete_count','delete_count_failure','delete_manual_count'],true)) {
        $offering=$conn->query('SELECT po.offering_id FROM tbl_prospectus_offering po WHERE po.program_id='.$scope['program_id'].' ORDER BY po.offering_id DESC LIMIT 1')->fetch_assoc();
        if (!$offering) throw new RuntimeException('An offering is required for count verification.');
        $offeringId=(int)$offering['offering_id']; $GLOBALS['manage_count_offering_id']=$offeringId;
        $conn->query('UPDATE tbl_student_management_enrolled_subjects SET offering_id='.$offeringId.' WHERE student_id='.$ownId);
        $total=(int)$conn->query('SELECT COUNT(DISTINCT student_id) n FROM tbl_student_management_enrolled_subjects WHERE is_active=1 AND offering_id='.$offeringId)->fetch_assoc()['n'];
        $GLOBALS['manage_count_expected']=$total-1;
        $kind=$manageScenario==='delete_manual_count'?'manual':'csv_roster';
        $conn->query("INSERT INTO tbl_offering_enrollee_counts (offering_id,total_enrollees,source_kind,created_by,updated_by) VALUES ({$offeringId},{$total},'{$kind}',{$userId},{$userId}) ON DUPLICATE KEY UPDATE total_enrollees=VALUES(total_enrollees),source_kind=VALUES(source_kind)");
    }
    if ($manageScenario==='delete_all_terms') $_POST['term']='all';
    if ($manageScenario==='delete_year') $_POST['term']='year:'.$term['ay_id'];
    if ($manageScenario==='delete_section') $_POST['section_id']=$historicalSection;
    if ($manageScenario==='delete_section') $_POST['term']=$term['ay_id'].':'.$otherSemester;
    if ($manageScenario==='delete_foreign') $ids=[$profiles['foreign']['student_id']];
    if ($manageScenario==='delete_empty') $ids=[];
    if ($manageScenario==='delete_duplicate') $ids=[$ownId,$ownId];
    if ($manageScenario==='delete_section') $ids=[$sharedId];
    if ($manageScenario==='delete_manual') {
        $newNumber=$prefix.'99';
        $saved=synk_dean_student_manage_save($conn,$scope,['student_number'=>$newNumber,'first_name'=>'New Student','last_name'=>'Peña'.$nameTag,
            'student_id'=>0,'record_program_id'=>$scope['program_id'],'record_term'=>$_POST['term'],'year_level'=>1,'email_address'=>'']);
        $ids=[$saved['student_id']];
    }
    $_POST['action']='delete_preview'; $_POST['selected_ids']=json_encode($ids);
    if (!in_array($manageScenario,['delete_preview','delete_all_terms','delete_foreign','delete_empty','delete_duplicate'],true)) {
        $preview=synk_dean_students_delete_preview($conn,$scope,synk_dean_students_filters($_POST,$conn),$ids);
        $token=$preview['preview_token']; $_POST['action']='delete_save'; $_POST['preview_token']=$token;
        if ($manageScenario==='delete_expired') $_SESSION['dean_student_delete_previews'][$token]['expires_at']=time()-1;
        if ($manageScenario==='delete_other_user') $_SESSION['dean_student_delete_previews'][$token]['user_id']++;
        if ($manageScenario==='delete_forged') $_POST['preview_token']='forged';
        if ($manageScenario==='delete_stale') $conn->query('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_id='.$ownId);
        if ($manageScenario==='delete_reassigned') $conn->query('UPDATE tbl_useraccount SET college_dean_college_id='.$foreignScope['college_id'].' WHERE user_id='.$userId);
        if ($manageScenario==='delete_revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='college_dean'");
        if ($manageScenario==='delete_bad_csrf') $_POST['csrf_token']='bad';
        if ($manageScenario==='delete_count_failure') $conn->failStudentCountWrite=true;
        if ($manageScenario==='delete_replay') synk_dean_students_delete_save($conn,$scope,$token);
    }
}
