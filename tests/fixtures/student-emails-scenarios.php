<?php
if (!defined('SYNK_STUDENT_EMAIL_QA')) { http_response_code(404); exit; }
require_once $root . '/backend/college_dean_student_email_helper.php';
$emailScenario=SYNK_STUDENT_EMAIL_QA;
$nameTag=strtr(substr($tag,-14),'0123456789','ghijklmnop');
$setEmail=static function (int $id,string $email) use ($conn): void {
    $stmt=$conn->prepare('UPDATE tbl_student_management SET email_address=? WHERE student_id=?');
    $stmt->bind_param('si',$email,$id); $stmt->execute(); $stmt->close();
};
$setNames=static function (int $id,string $first,string $last) use ($conn): void {
    $stmt=$conn->prepare('UPDATE tbl_student_management SET first_name=?,last_name=? WHERE student_id=?');
    $stmt->bind_param('ssi',$first,$last,$id); $stmt->execute(); $stmt->close();
};
$setNames($profiles['shared']['student_id'],'José','Peña'.$nameTag);
$setNames($profiles['own']['student_id'],'Carol Jan','Ambrocio'.$nameTag);
$sharedEmail='josepena'.$nameTag.'@sksu.edu.ph';
if (in_array($emailScenario,['preserved','save_preserved'],true)) $setEmail($profiles['own']['student_id'],'verified.alias@sksu.edu.ph');
if (in_array($emailScenario,['collision_existing','collision_stale'],true)) {
    if ($emailScenario==='collision_existing') $setEmail($profiles['foreign']['student_id'],$sharedEmail);
}
if ($emailScenario==='collision_proposed') $setNames($profiles['foreign']['student_id'],'JOSE','PENA'.$nameTag);
if ($emailScenario==='unclear') $setNames($profiles['own']['student_id'],'C. Jan','Ambr??cio');
$_POST['action']='email_preview';
if ($emailScenario==='filtered') $_POST['section_id']=$historicalSection;
if ($emailScenario==='bad_csrf') $_POST['csrf_token']='wrong';
if ($emailScenario==='scope_tamper') $_POST['college_id']=$foreignScope['college_id'];
if ($emailScenario==='other_role') $_SESSION['role']='program_chair';
if (strpos($emailScenario,'save')===0 || in_array($emailScenario,['expired','other_user','reassigned','revoked','stale_name','stale_number','stale_email','stale_membership','collision_stale','failure','replay'],true)) {
    $preview=synk_dean_student_email_preview($conn,$scope,synk_dean_students_filters($_POST));
    $token=$preview['preview_token'];
    $_POST['action']='email_save'; $_POST['preview_token']=$token;
    $selected=[$profiles['shared']['student_id'],$profiles['own']['student_id']];
    if (in_array($emailScenario,['save_subset','save_preserved'],true)) $selected=[$profiles['shared']['student_id']];
    if ($emailScenario==='save_foreign') $selected=[$profiles['foreign']['student_id']];
    if ($emailScenario==='save_empty') $selected=[];
    if ($emailScenario==='save_duplicate') $selected=[$selected[0],$selected[0]];
    if ($emailScenario==='save_string') $selected=[(string)$selected[0]];
    if ($emailScenario==='save_forged') $_POST['preview_token']='forged';
    if ($emailScenario==='expired') $_SESSION['dean_student_email_previews'][$token]['expires_at']=time()-1;
    if ($emailScenario==='other_user') $_SESSION['dean_student_email_previews'][$token]['user_id']++;
    if ($emailScenario==='reassigned') $conn->query('UPDATE tbl_useraccount SET college_dean_college_id='.$foreignScope['college_id'].' WHERE user_id='.$userId);
    if ($emailScenario==='revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='college_dean'");
    if ($emailScenario==='stale_name') $setNames($profiles['own']['student_id'],'Carol Jane','Ambrocio'.$nameTag);
    if ($emailScenario==='stale_number') $conn->query("UPDATE tbl_student_management SET student_number=CONCAT('0',student_number) WHERE student_id=".$profiles['own']['student_id']);
    if ($emailScenario==='stale_email') $setEmail($profiles['own']['student_id'],'changed.school@sksu.edu.ph');
    if ($emailScenario==='stale_membership') $conn->query('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_id='.$profiles['own']['student_id']);
    if ($emailScenario==='collision_stale') $setEmail($profiles['foreign']['student_id'],$sharedEmail);
    if ($emailScenario==='failure') $conn->failEmailWrite=true;
    if ($emailScenario==='replay') synk_dean_student_email_save($conn,$scope,$token,$selected);
    $_POST['selected_ids']=json_encode($selected);
    if ($emailScenario==='save_bad_json') $_POST['selected_ids']='not JSON';
    if ($emailScenario==='save_object') $_POST['selected_ids']='{"student":1}';
    if ($emailScenario==='save_array') $_POST['selected_ids']=[1];
}
