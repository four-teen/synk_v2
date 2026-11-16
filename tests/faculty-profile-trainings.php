<?php
if (PHP_SAPI !== 'cli') {http_response_code(404); exit;}
require_once __DIR__.'/../backend/faculty_profile_training_helper.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$record=['training_title'=>'  Inclusive   teaching  ','agency'=>' Faculty Development Institute ','date_from'=>'2028-02-29','date_to'=>'2028-03-01','venue'=>'University Training Hall','mode'=>'Face to face'];
[$valid,$errors]=synk_faculty_profile_training_validate($record);
$check(!$errors&&$valid['training_title']==='Inclusive teaching'&&$valid['agency']==='Faculty Development Institute','Valid training is normalized and leap dates are accepted.');
[, $errors]=synk_faculty_profile_training_validate(array_replace($record,['date_to'=>'2028-02-29','mode'=>'Online','venue'=>'Zoom']));
$check(!$errors,'Same-day online training is accepted.');
$badCases=[['training_title'=>''],['agency'=>''],['date_from'=>''],['date_to'=>''],['venue'=>''],['mode'=>''],
    ['mode'=>'Hybrid'],['training_title'=>['array']],['date_from'=>['array']],['training_title'=>"Bad\0title"],['agency'=>"Invalid\xFF"],
    ['training_title'=>str_repeat('ñ',501)],['agency'=>str_repeat('a',301)],['venue'=>str_repeat('v',301)],
    ['date_from'=>'2026-02-29'],['date_to'=>'2028-04-31'],['date_from'=>'1899-12-31'],['date_to'=>'2028-02-28']];
foreach($badCases as $bad){[, $errors]=synk_faculty_profile_training_validate(array_replace($record,$bad));$check((bool)$errors,'Invalid training field rejected: '.implode(',',array_keys($bad)));}
foreach(['faculty:0','account:2147483648','other:1','faculty:1 OR 1=1'] as $owner){try{synk_faculty_profile_training_owner($owner);throw new RuntimeException('Bad owner accepted.');}catch(InvalidArgumentException $e){$check(true,'Invalid owner rejected.');}}
require_once __DIR__.'/../backend/db.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
synk_faculty_profile_training_ensure($conn);
$snapshot=static function()use($conn):array{return $conn->query('CHECKSUM TABLE tbl_faculty_profile_trainings,tbl_faculty_profile_researches,tbl_faculty_profiles,tbl_faculty_record_profiles,tbl_useraccount')->fetch_all(MYSQLI_ASSOC);};
$before=$snapshot();$conn->begin_transaction();
try{
    $tag='TrainingQA'.bin2hex(random_bytes(7));$email=$tag.'@example.test';
    $stmt=$conn->prepare("INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES (?,?,'unusable-test-password','professor','active')");
    $stmt->bind_param('ss',$tag,$email);$stmt->execute();$ownerId=(int)$conn->insert_id;$stmt->close();
    $owner='account:'.$ownerId;
    $check(synk_faculty_profile_training_save($conn,$owner,$valid,0,0),'A training record can be created.');
    $rows=synk_faculty_profile_training_records($conn,$owner);$id=(int)$rows[0]['training_id'];
    foreach(array_keys(synk_faculty_profile_training_fields()) as $key)$check($rows[0][$key]===$valid[$key],$key.' persists accurately.');
    $check(synk_faculty_profile_training_records($conn,'faculty:'.$ownerId)===[],'Owner types isolate training records.');
    $check(!synk_faculty_profile_training_save($conn,'account:'.($ownerId+1),$valid,$id,1),'Another account cannot edit training.');
    $check(!synk_faculty_profile_training_delete($conn,'faculty:'.$ownerId,$id,1),'Another owner type cannot remove training.');
    $changed=array_replace($valid,['mode'=>'Online','venue'=>'Zoom','date_to'=>'2028-02-29']);
    $check(synk_faculty_profile_training_save($conn,$owner,$changed,$id,1),'An existing training can be edited.');
    $rows=synk_faculty_profile_training_records($conn,$owner);
    $check((int)$rows[0]['revision']===2&&$rows[0]['mode']==='Online'&&$rows[0]['venue']==='Zoom'&&$rows[0]['date_to']==='2028-02-29','Editing updates the complete record and increments its version.');
    $check(!synk_faculty_profile_training_save($conn,$owner,$valid,$id,1)&&!synk_faculty_profile_training_delete($conn,$owner,$id,1),'Stale edits and deletes are rejected.');
    $check(synk_faculty_profile_training_records($conn,$owner)===$rows,'Rejected writes preserve the saved record.');
    $check(synk_faculty_profile_training_save($conn,$owner,$valid,0,0)&&count(synk_faculty_profile_training_records($conn,$owner))===2,'Multiple trainings are stored separately.');
    $check(synk_faculty_profile_training_delete($conn,$owner,$id,2)&&count(synk_faculty_profile_training_records($conn,$owner))===1,'Removing one training preserves other records.');
    $check(synk_faculty_profile_training_save($conn,'faculty:'.$ownerId,$valid,0,0),'Faculty-owned training is supported.');
}finally{$conn->rollback();}
$check($snapshot()===$before,'Training, research, profile and account fixture data roll back.');
echo 'Faculty profile training: ',$checks,' checks passed. Fixtures rolled back.',PHP_EOL;
