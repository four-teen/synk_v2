<?php
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../backend/faculty_profile_degrees_helper.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$degree=['level'=>'bachelor','status'=>'Completed','degree'=>'BS Computer Science','institution'=>'Sample University','address'=>'Sample City','specialization'=>'Computing'];
[$valid,$errors]=synk_faculty_profile_degrees_validate([$degree,array_replace($degree,['degree'=>'BS Nursing'])]);
$check(!$errors&&count($valid)===2,'Two degrees at the same level are accepted.');
foreach(['bachelor','master','doctoral'] as $level){[$rows,$errors]=synk_faculty_profile_degrees_validate([array_replace($degree,['level'=>$level,'status'=>'Ongoing'])]);$check(!$errors,'Every supported degree level accepts ongoing studies.');}
foreach([null,'bad',array_fill(0,51,$degree),[['owner_id'=>1]],[$degree+['faculty_id'=>1]],[array_replace($degree,['level'=>'foreign'])],[array_replace($degree,['status'=>'Not applicable'])],[array_replace($degree,['degree'=>''])],[array_replace($degree,['degree'=>['bad']])],[array_replace($degree,['institution'=>str_repeat('ñ',301)])]] as $bad){[, $errors]=synk_faculty_profile_degrees_validate($bad);$check((bool)$errors,'Malformed and incomplete additional degree entries are rejected.');}
[$rows,$errors]=synk_faculty_profile_degrees_validate([array_replace($degree,['degree'=>"  BS   Computing\n ",'institution'=>''])]);
$check(!$errors&&$rows[0]['degree']==='BS Computing'&&$rows[0]['institution']==='','Whitespace is normalized and partial school information remains optional.');
$check(synk_faculty_profile_degrees_validate([])===[[],[]],'All additional degrees can be cleared.');
foreach(['account:0','faculty:99999999999','other:1','account:1 OR 1=1'] as $owner){try{synk_faculty_profile_degree_owner($owner);throw new RuntimeException('Bad owner accepted.');}catch(InvalidArgumentException $e){$check(true,'Owner IDs are validated.');}}

mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
synk_faculty_profile_degrees_ensure($conn);
$tables='tbl_faculty_profiles,tbl_faculty_profile_additional_degrees,tbl_faculty_education_options,tbl_useraccount';
$snapshot=static function()use($conn,$tables):array{return $conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);};
$before=$snapshot();$conn->begin_transaction();
try{
    $tag='DegreeQA'.bin2hex(random_bytes(8));
    $stmt=$conn->prepare("INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES (?,?,'unusable-qa-password','professor','active')");
    $email=$tag.'@example.test';$stmt->bind_param('ss',$tag,$email);$stmt->execute();$user=(int)$conn->insert_id;$stmt->close();
    $owner='account:'.$user;
    [$profile,$errors]=synk_faculty_profile_validate(['full_name'=>$tag,'bachelor_status'=>'Completed','bachelor_degree'=>'Original primary degree'],new DateTimeImmutable('today'),synk_faculty_profile_ranks($conn),[],synk_faculty_profile_eligibility_catalog($conn),synk_faculty_profile_locations($conn));
    $check(!$errors&&synk_faculty_profile_save($conn,$user,$profile,0),'The existing profile save still succeeds.');
    synk_faculty_profile_degrees_save($conn,$owner,[$degree,array_replace($degree,['level'=>'doctoral','degree'=>'Doctor of Education'])]);
    $saved=synk_faculty_profile_degrees_fetch($conn,$owner);
    $check(count($saved)===2&&array_column($saved,'level')===['bachelor','doctoral']&&$saved[1]['degree']==='Doctor of Education','Additional Bachelor and Doctoral degrees persist and reopen under their own levels.');
    $check(synk_faculty_profile_fetch($conn,$user)['bachelor_degree']==='Original primary degree','Additional degrees preserve the primary education fields.');
    $check(synk_faculty_profile_degrees_fetch($conn,'faculty:'.$user)===[]&&synk_faculty_profile_degrees_fetch($conn,'account:'.($user+1))===[],'Owner type and owner ID both isolate degrees.');
    $check(!synk_faculty_profile_save($conn,$user,$profile,0)&&synk_faculty_profile_degrees_fetch($conn,$owner)===$saved,'A stale existing revision cannot authorize an additional degree update.');
    $check(synk_faculty_profile_save($conn,$user,$profile,1)&&synk_faculty_profile_degrees_fetch($conn,$owner)===$saved,'Older profile save callers preserve additional degrees.');
    $conn->query('SAVEPOINT degree_atomic');
    try{
        $changed=$profile;$changed['full_name']='This edit must roll back';synk_faculty_profile_save($conn,$user,$changed,2);
        synk_faculty_profile_degrees_save($conn,$owner,[array_replace($degree,['degree'=>''])]);
        throw new RuntimeException('Invalid degree saved.');
    }catch(InvalidArgumentException $e){$conn->query('ROLLBACK TO SAVEPOINT degree_atomic');}
    $check(synk_faculty_profile_fetch($conn,$user)['full_name']===$tag&&synk_faculty_profile_degrees_fetch($conn,$owner)===$saved,'Failed additional degrees roll back the parent profile update.');
    synk_faculty_profile_degrees_save($conn,$owner,[]);$check(synk_faculty_profile_degrees_fetch($conn,$owner)===[],'Removing all degrees persists an empty list.');
    $facultyOwner='faculty:'.$user;synk_faculty_profile_degrees_save($conn,$facultyOwner,[$degree]);
    $check(count(synk_faculty_profile_degrees_fetch($conn,$facultyOwner))===1&&synk_faculty_profile_degrees_fetch($conn,$owner)===[],'Shared faculty profiles have independent degree storage.');
}finally{$conn->rollback();}
$check($snapshot()===$before,'All profile, account, degree and suggestion fixtures roll back.');
echo 'Faculty profile additional degrees: ',$checks,' checks passed. Fixtures rolled back.',PHP_EOL;
