<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/hr_faculty_pds_controller.php';
require_once __DIR__.'/fixtures/hr-faculty-pds-remaining-data.php';
$checks=0;
$check=static function (bool $ok,string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$today=new DateTimeImmutable('2026-11-18'); $examples=hr_pds_remaining_examples();
foreach ($examples as $section=>$example) {
    [$data,$errors]=synk_hr_pds_validate($section,hr_pds_empty_section($section),$today);
    $check(!$errors,'Blank '.$section.' can be saved as a draft.');
    [$data,$errors]=synk_hr_pds_validate($section,$example,$today);
    $check(!$errors && $data===$example,'All '.$section.' fields validate without silent changes.');
    $bad=$example; $bad['faculty_id']=99; [, $errors]=synk_hr_pds_validate($section,$bad,$today);
    $check(isset($errors['_section']),'Reject injected identity in '.$section.'.');
    foreach (synk_hr_pds_record_lists($section) as $key=>$list) {
        $bad=$example; $bad[$key][0]['record_id']='99'; [, $errors]=synk_hr_pds_validate($section,$bad,$today);
        $check(isset($errors[$key.'.0._section']),'Reject injected row identity in '.$section.'/'.$key.'.');
    }
}
foreach (['work'=>'work','learning'=>'trainings','voluntary'=>'voluntary'] as $section=>$list) {
    $bad=$examples[$section]; $bad[$list][0]['date_from']='2025-10-01'; $bad[$list][0]['date_to']='2025-09-01';
    if ($section==='work') $bad[$list][0]['current']='0';
    [, $errors]=synk_hr_pds_validate($section,$bad,$today); $check(isset($errors[$list.'.0.date_to']),'Attendance date ordering in '.$section.'.');
    $bad=$examples[$section]; $field=$section==='work'?'salary':'hours'; $bad[$list][0][$field]='-1';
    [, $errors]=synk_hr_pds_validate($section,$bad,$today); $check(isset($errors[$list.'.0.'.$field]),'Reject negative '.$field.'.');
    $bad[$list][0][$field]='0'; [$data,$errors]=synk_hr_pds_validate($section,$bad,$today);
    $check(!$errors && $data[$list][0][$field]==='0.00','Zero is distinct from an empty '.$field.'.');
}
$bad=$examples['work']; $bad['work'][0]['date_to']='not-a-date'; [$data,$errors]=synk_hr_pds_validate('work',$bad,$today);
$check(!$errors && $data['work'][0]['date_to']==='','Current employment clears an inapplicable end date.');
$bad=$examples['learning']; $bad['trainings'][0]['type']='Technical'; [$data,$errors]=synk_hr_pds_validate('learning',$bad,$today);
$check(!$errors && $data['trainings'][0]['type_other']==='','Changing L&D type clears its Other follow-up.');
$bad=$examples['voluntary']; $bad['voluntary_applies']='None'; [$data,$errors]=synk_hr_pds_validate('voluntary',$bad,$today);
$check(!$errors && $data['voluntary']===[],'Explicit None removes voluntary records.');
$bad=$examples['declarations']; $bad['q35b']='No'; $bad['q39']='No'; $bad['q40b']='No'; [$data,$errors]=synk_hr_pds_validate('declarations',$bad,$today);
$check(!$errors && $data['case_date']==='' && $data['case_status']==='' && $data['q35b_details']==='' && $data['resident_country']==='' && $data['q40b_id']==='','Only explicit No clears declaration follow-ups.');
$bad=$examples['declarations']; $bad['q35b']=''; [$data,$errors]=synk_hr_pds_validate('declarations',$bad,$today);
$check(!$errors && $data['q35b_details']===$bad['q35b_details'],'Unanswered declarations retain entered draft details.');
$bad=$examples['declarations']; $bad['q34a']='Maybe'; [, $errors]=synk_hr_pds_validate('declarations',$bad,$today);
$check(isset($errors['q34a']),'Declarations only accept explicit Yes, No, or an unanswered blank.');
$bad=$examples['certification']; $bad['date_accomplished']='2027-01-01'; [, $errors]=synk_hr_pds_validate('certification',$bad,$today);
$check(isset($errors['date_accomplished']),'Certification cannot record a future accomplishment date.');
$bad=$examples['certification']; $bad['signature_date_2']='2026-11-16'; [, $errors]=synk_hr_pds_validate('certification',$bad,$today);
$check(isset($errors['_section']),'A client cannot override an individual page date.');
foreach (['oath_date'=>'2026-11-15','oath_officer'=>'Sample Officer'] as $field=>$value) {
    $bad=$examples['certification']; $bad[$field]=$value; [, $errors]=synk_hr_pds_validate('certification',$bad,$today);
    $check(isset($errors['_section']),'Faculty saves cannot set attorney office field '.$field.'.');
}
foreach (['photo','thumbmark','signature_page_1','signature_page_2','signature_page_3','signature_page_4','oath_signature'] as $slot) {
    [,,$errors]=synk_hr_pds_prepare_attachments([$slot=>['error'=>UPLOAD_ERR_OK]],[]);
    $check(isset($errors['_attachments']),'Reject digital upload for '.$slot.'.');
}
[,,$errors]=synk_hr_pds_prepare_attachments([],['signature_page_1']);
$check(isset($errors['_attachments']),'Retired signature files cannot be deleted by a form save.');
if (!in_array('--database',$argv,true)) { echo 'Remaining PDS: ',$checks,' unit checks passed.',PHP_EOL; exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php'; synk_hr_pds_ensure($conn); $conn->close();
class HrPdsRemainingConnection extends mysqli {
    public $requestMode=false;
    public function begin_transaction($flags=0,$name=null): bool { return $this->requestMode?$this->query('SAVEPOINT hr_remaining'):parent::begin_transaction($flags); }
    public function commit($flags=0,$name=null): bool { return $this->requestMode?$this->query('RELEASE SAVEPOINT hr_remaining'):parent::commit($flags); }
    public function rollback($flags=0,$name=null): bool { return $this->requestMode?$this->query('ROLLBACK TO SAVEPOINT hr_remaining'):parent::rollback($flags); }
}
$conn=new HrPdsRemainingConnection($servername,$username,$password,$dbase); $conn->set_charset('utf8mb4'); $conn->begin_transaction();
try {
    $ids=[];
    foreach ([1,2] as $index) {
        $email='hrremainingqa'.bin2hex(random_bytes(6)).'@example.edu';
        $statement=$conn->prepare("INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES ('HR Remaining QA',?,'unusable-test-password','professor','active')");
        $statement->bind_param('s',$email); $statement->execute(); $ids[]=(int)$conn->insert_id; $statement->close();
    }
    $snapshot=static function () use ($conn): string {
        $rows=[]; foreach (['tbl_useraccount','tbl_faculty','tbl_faculty_profiles','tbl_faculty_record_profiles','tbl_useraccount_faculty_links','tbl_faculty_profile_additional_degrees','tbl_faculty_profile_trainings'] as $table) $rows[$table]=$conn->query('SELECT * FROM '.$table)->fetch_all(MYSQLI_ASSOC);
        return hash('sha256',json_encode($rows));
    };
    $before=$snapshot(); $session=['user_id'=>$ids[0],'role'=>'professor','faculty_profile_csrf'=>'remaining-qa']; $conn->requestMode=true;
    foreach ($examples as $section=>$values) {
        $request=['csrf_token'=>'remaining-qa','owner'=>'account:'.$ids[0],'section'=>$section,'revision'=>0,'values'=>$values];
        $result=synk_hr_pds_handle_save($conn,$session,$request);
        $check($result['status']===200 && $result['body']['values']===$values,$section.' saves every field.');
        $state=synk_hr_pds_load($conn,$ids[0],0);
        $check($state[$section]['values']===$values && $state[$section]['revision']===1,$section.' round-trips from its HR tables.');
        $check(synk_hr_pds_load($conn,$ids[1],0)[$section]===null,'Another account cannot read '.$section.'.');
        $result=synk_hr_pds_handle_save($conn,$session,$request);
        $check($result['status']===409 && synk_hr_pds_load($conn,$ids[0],0)===$state,'Stale '.$section.' saves do not modify records.');
        $request['revision']=1; $request['owner']='account:'.$ids[1];
        $result=synk_hr_pds_handle_save($conn,$session,$request);
        $check($result['status']===409 && synk_hr_pds_load($conn,$ids[0],0)===$state,'Forged ownership does not modify '.$section.'.');
        $request['owner']='account:'.$ids[0]; $request['values']=hr_pds_empty_section($section);
        $result=synk_hr_pds_handle_save($conn,$session,$request);
        $check($result['status']===200 && $result['body']['revision']===2 && $result['body']['values']===$request['values'],'Clearing '.$section.' persists a valid empty draft.');
    }
    $profile=synk_hr_pds_profile($conn,$ids[0],0); $profileId=(int)$profile['hr_profile_id'];
    $conn->query('SAVEPOINT hr_attach_atomic');
    $conn->query("UPDATE tbl_hr_faculty_pds_certification SET oath_date='2020-01-01',oath_officer='Recorded Officer' WHERE hr_profile_id=".$profileId);
    $saved=synk_hr_pds_save($conn,$ids[0],'account:'.$ids[0],'certification',$examples['certification'],2);
    $row=$conn->query('SELECT date_accomplished,signature_date_1,signature_date_2,signature_date_3,signature_date_4 FROM tbl_hr_faculty_pds_certification WHERE hr_profile_id='.$profileId)->fetch_assoc();
    $check(count(array_unique(array_values($row)))===1 && $row['date_accomplished']==='2026-11-15','One entered date is stored identically on all four PDS pages.');
    $conn->query("INSERT INTO tbl_hr_faculty_pds_attachments (hr_profile_id,slot,original_name,mime_type,byte_size,sha256,content,updated_by) VALUES (".$profileId.",'signature_page_1','old-signature.png','image/png',1,'old','x',".$ids[0].")");
    $check(synk_hr_pds_attachments($conn,$profileId)===[],'Previously uploaded signatures are not reused in the current PDS.');
    $emptyDate=$examples['certification']; $emptyDate['date_accomplished']='';
    $saved=synk_hr_pds_save($conn,$ids[0],'account:'.$ids[0],'certification',$emptyDate,3);
    $row=$conn->query('SELECT date_accomplished,signature_date_1,signature_date_2,signature_date_3,signature_date_4 FROM tbl_hr_faculty_pds_certification WHERE hr_profile_id='.$profileId)->fetch_assoc();
    $check(array_values($row)===array_fill(0,5,null),'Clearing the shared date clears all four page dates together.');
    $oath=$conn->query('SELECT oath_date,oath_officer FROM tbl_hr_faculty_pds_certification WHERE hr_profile_id='.$profileId)->fetch_assoc();
    $check($oath===['oath_date'=>'2020-01-01','oath_officer'=>'Recorded Officer'],'Faculty date saves preserve historical attorney office details.');
    $check($conn->query('SELECT COUNT(*) FROM tbl_hr_faculty_pds_attachments WHERE hr_profile_id='.$profileId)->fetch_row()[0]==1,'Saving dates does not delete previously stored signature files.');
    $conn->query('ROLLBACK TO SAVEPOINT hr_attach_atomic');
    $check(!synk_hr_pds_attachments($conn,$profileId) && synk_hr_pds_section($conn,$profileId,'certification')['revision']===2,'Transaction rollback restores both attachment bytes and the section revision.');
    $check($before===$snapshot(),'All remaining PDS edits preserve existing profiles, degrees, trainings, accounts, and faculty directory.');
} finally { $conn->requestMode=false; $conn->rollback(); $conn->close(); }
echo 'Remaining PDS: ',$checks,' checks passed. All database writes rolled back.',PHP_EOL;
