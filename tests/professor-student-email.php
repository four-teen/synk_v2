<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/class_students_api_helper_lms.php';
require_once __DIR__.'/../backend/professor_student_roster_helper.php';
require_once __DIR__.'/../backend/student_login_helper.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';

$checks=0;
$check=static function (bool $ok,string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$deny=static function (callable $request,int $status) use ($check): void {
    try { $request(); } catch (DomainException $error) {
        $check($error->getCode()===$status,$error->getMessage());
        return;
    }
    throw new RuntimeException('An invalid email request succeeded.');
};

$tables='tbl_student_management,tbl_student_portal_profile,tbl_student_management_enrolled_subjects,tbl_faculty,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty_workload_sched,tbl_offering_enrollee_counts,'.synk_schedule_merge_table_name();
$before=$conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);
$conn->begin_transaction();
try {
    $fixture=professor_students_fixtures($conn);
    $term=$fixture['term'];
    $studentId=$fixture['students']['active'];
    $_SESSION=['role'=>'professor','user_id'=>$fixture['user_id'],'lms_class_students_csrf'=>bin2hex(random_bytes(32))];
    $classes=synk_professor_fetch_subject_rows_by_academic_year($conn,$fixture['faculty_id'],$term['ay_id'],$term['semester']);
    $class=array_column($classes,null,'offering_id')[$fixture['offerings'][0]['offering_id']];
    parse_str((string)parse_url(synk_professor_class_url_lms($class,'students'),PHP_URL_QUERY),$signedClass);
    $request=['action'=>'get_email','context'=>$signedClass,'payload'=>['student_id'=>$studentId],
        'csrf_token'=>$_SESSION['lms_class_students_csrf']];
    $load=static function (array $params=[]) use ($conn,$request): array {
        return synk_class_students_request_lms($conn,array_replace($request,$params));
    };
    $initial=$load()['student_email'];
    $check($initial['email_address']!=='' && isset($initial['version']),'The signed class action returns only the selected student email and version.');
    $roster=synk_professor_student_roster($conn,$fixture['faculty_id'],$term);
    $students=array_column($roster['students'],null,'student_id');
    $check(count($roster['students'])===4 && count($students[$studentId]['classes'])===2,
        'The Professor roster lists each student once and retains the subjects they share.');
    $check(!array_intersect(array_keys($students),[$fixture['students']['foreign'],$fixture['students']['withdrawn'],$fixture['students']['wrong_term']]),
        'The roster omits students outside the professor’s current active classes.');
    $rosterClass=key($students[$studentId]['classes']);
    $check($load(['context'=>$roster['contexts'][$rosterClass]])['student_email']===$initial,
        'The roster supplies a signed class context accepted by the shared email editor.');
    $deny(static function () use ($load) { $load(['csrf_token'=>'wrong']); },403);
    $deny(static function () use ($load,$signedClass) {
        $load(['context'=>array_replace($signedClass,['offering_id'=>'2147483647'])]);
    },403);
    foreach (['foreign','withdrawn','wrong_term'] as $key) {
        $deny(static function () use ($load,$fixture,$key) { $load(['payload'=>['student_id'=>$fixture['students'][$key]]]); },404);
    }
    $deny(static function () use ($load,$studentId) {
        $load(['action'=>'set_email','payload'=>['student_id'=>$studentId,'first_name'=>'Unexpected']]);
    },400);
    $context=synk_professor_class_context_lms($conn,$signedClass);
    $record=synk_professor_student_email_record($conn,$context,$studentId);
    $profile=$conn->prepare('INSERT INTO tbl_student_portal_profile (email_address,student_number,program_id) VALUES (?,?,?)');
    $programId=(int)$fixture['offerings'][0]['program_id'];
    $profile->bind_param('ssi',$record['email_address'],$record['student_number'],$programId);
    $profile->execute();
    $profileId=(int)$profile->insert_id;
    $profile->close();

    $newEmail='emailqa'.bin2hex(random_bytes(8)).'@sksu.edu.ph';
    $change=['email_address'=>strtoupper($newEmail),'version'=>$initial['version']];
    $deny(static function () use ($conn,$context,$studentId,$change) {
        synk_professor_student_email_save($conn,$context,$studentId,['email_address'=>'test@example.com']+$change);
    },422);
    $otherId=$fixture['students']['foreign'];
    $conn->query("UPDATE tbl_student_management SET email_address='{$newEmail}' WHERE student_id={$otherId}");
    $deny(static function () use ($conn,$context,$studentId,$change) {
        synk_professor_student_email_save($conn,$context,$studentId,$change);
    },409);
    $conn->query("UPDATE tbl_student_management SET email_address='' WHERE student_id={$otherId}");

    $original=$conn->query("SELECT first_name,last_name,student_number,program_id,ay_id,semester FROM tbl_student_management WHERE student_id={$studentId}")->fetch_assoc();
    $saved=synk_professor_student_email_save($conn,$context,$studentId,$change);
    $check($saved['email_address']===$newEmail && $saved['version']!==$initial['version'],'Correction normalizes the verified school address and changes the version.');
    $check($conn->query("SELECT email_address FROM tbl_student_portal_profile WHERE profile_id={$profileId}")->fetch_assoc()['email_address']===$newEmail,'The linked login profile gets the same address.');
    $check((int)synk_student_login_find_directory($conn,$newEmail)[0]['student_id']===$studentId
        && !synk_student_login_find_directory($conn,$initial['email_address']),'Student sign-in resolves the corrected address only.');
    $check($original===$conn->query("SELECT first_name,last_name,student_number,program_id,ay_id,semester FROM tbl_student_management WHERE student_id={$studentId}")->fetch_assoc(),'The remaining student fields are preserved.');
    $deny(static function () use ($conn,$context,$studentId,$change) {
        synk_professor_student_email_save($conn,$context,$studentId,$change);
    },409);
    $deny(static function () use ($conn,$context,$otherId,$change) {
        synk_professor_student_email_save($conn,$context,$otherId,$change);
    },404);
} finally { $conn->rollback(); }
$check($before===$conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC),'Fixture data was rolled back.');
echo "Professor student email: {$checks} checks passed. Fixtures rolled back.",PHP_EOL;
