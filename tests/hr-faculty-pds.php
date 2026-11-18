<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/hr_faculty_pds_controller.php';
$checks=0;
$check=static function (bool $ok,string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$empty=static function (string $section): array { return array_fill_keys(array_keys(synk_hr_pds_fields($section)),'') + []; };
$today=new DateTimeImmutable('2026-11-18');
foreach ([
    ['ELBREN OSORIO ANTONIO',['surname'=>'ANTONIO','first_name'=>'ELBREN','middle_name'=>'OSORIO','name_extension'=>'']],
    ['JUAN MIGUEL SANTOS JR.',['surname'=>'SANTOS','first_name'=>'JUAN','middle_name'=>'MIGUEL','name_extension'=>'JR.']],
    ['Juan Miguel Reyes Santos III',['surname'=>'Santos','first_name'=>'Juan Miguel','middle_name'=>'Reyes','name_extension'=>'III']],
    ['DE LA CRUZ, Juan Miguel Jr.',['surname'=>'DE LA CRUZ','first_name'=>'Juan','middle_name'=>'Miguel','name_extension'=>'Jr.']],
    ['Juan Reyes de la Cruz',['surname'=>'de la Cruz','first_name'=>'Juan','middle_name'=>'Reyes','name_extension'=>'']],
    ['Ana Santos',['surname'=>'Santos','first_name'=>'Ana','middle_name'=>'','name_extension'=>'']],
    ['Prince',['surname'=>'','first_name'=>'Prince','middle_name'=>'','name_extension'=>'']],
    ['', ['surname'=>'','first_name'=>'','middle_name'=>'','name_extension'=>'']],
] as [$name,$expected]) $check(synk_hr_pds_name_defaults($name)===$expected,'Name defaults: '.$name);
$linked=['first_name'=>'Juan Miguel','middle_name'=>'Osorio','last_name'=>'De la Cruz','ext_name'=>'II'];
$check(synk_hr_pds_name_defaults('Juan Miguel Osorio De la Cruz II',$linked)===['surname'=>'De la Cruz','first_name'=>'Juan Miguel','middle_name'=>'Osorio','name_extension'=>'II'],'Structured faculty names preserve compound given names and surnames.');
$check(synk_hr_pds_name_defaults('Elbren Osorio Antonio',$linked)['surname']==='Antonio','A different saved profile name takes priority over stale directory names.');
$personal=$empty('personal');
[$values,$errors]=synk_hr_pds_validate('personal',$personal,$today);
$check(!$errors,'Incomplete personal drafts can be saved.');
$contact=$empty('contact'); $contact['same_address']='0';
[$values,$errors]=synk_hr_pds_validate('contact',$contact,$today);
$check(!$errors,'Incomplete contact drafts can be saved.');
$personal=array_replace($personal,['surname'=>'  Antonio  ','first_name'=>'Elbren','middle_name'=>'Osorio','name_extension'=>'III',
    'date_of_birth'=>'1977-07-01','sex_at_birth'=>'Male','civil_status'=>'Other','civil_status_other'=>'Annulled',
    'height'=>'1.7','weight'=>'65.25','blood_type'=>'O+','citizenship'=>'Dual Citizenship','citizenship_basis'=>'By birth','citizenship_country'=>'Canada']);
[$values,$errors]=synk_hr_pds_validate('personal',$personal,$today);
$check(!$errors && $values['surname']==='Antonio' && $values['height']==='1.70','Personal data trims and normalizes measurements.');
foreach ([['date_of_birth','2026-02-30'],['date_of_birth','2027-01-01'],['date_of_birth','12/04/1990'],['height','-1'],['height','1.234'],['weight','0'],['sex_at_birth','Other'],['surname',['bad']],['surname',"Bad\nName"],['first_name',str_repeat('x',101)]] as [$key,$bad]) {
    $input=array_replace($personal,[$key=>$bad]); [, $errors]=synk_hr_pds_validate('personal',$input,$today);
    $check(isset($errors[$key]),'Reject invalid '.$key);
}
$input=$personal; unset($input['first_name']); [, $errors]=synk_hr_pds_validate('personal',$input,$today); $check(isset($errors['_section']),'Missing fields cannot silently erase saved data.');
$input=$personal; $input['user_id']='999'; [, $errors]=synk_hr_pds_validate('personal',$input,$today); $check(isset($errors['_section']),'Posted identity fields are rejected.');
$input=array_replace($personal,['citizenship'=>'Filipino','civil_status'=>'Single']); [$values,$errors]=synk_hr_pds_validate('personal',$input,$today);
$check(!$errors && $values['citizenship_country']==='' && $values['citizenship_basis']==='' && $values['civil_status_other']==='','Inapplicable follow-ups are cleared on the server.');
$contact=array_replace($contact,['residential_city'=>'Sample City','residential_zip'=>'00123','same_address'=>'1','permanent_city'=>'Forged City',
    'umid'=>'000001234','pagibig'=>'0012-34','philhealth'=>'0012345','philsys_pcn'=>'0000-1111-2222-3333','tin'=>'000-111-222','agency_employee_number'=>'00001-A','email'=>'pds@example.edu']);
[$values,$errors]=synk_hr_pds_validate('contact',$contact,$today);
$check(!$errors && $values['permanent_city']==='Sample City' && $values['permanent_zip']==='00123','The server copies every permanent-address component.');
$check($values['umid']==='000001234' && $values['agency_employee_number']==='00001-A','Leading zeros in identifiers are preserved.');
$bad=$contact; $bad['email']='not-an-email'; [, $errors]=synk_hr_pds_validate('contact',$bad,$today); $check(isset($errors['email']),'Contact email is validated.');
$bad=$contact; $bad['same_address']='yes'; [, $errors]=synk_hr_pds_validate('contact',$bad,$today); $check(isset($errors['same_address']),'Invalid address flags are rejected.');

$family=$empty('family')+['spouses'=>[],'children'=>[]];
[, $errors]=synk_hr_pds_validate('family',$family,$today); $check(!$errors,'Family drafts allow unanswered questions and empty lists.');
$spouse=array_fill_keys(array_keys(synk_hr_pds_record_lists('family')['spouses']['fields']),'');
$spouse=array_replace($spouse,['surname'=>'Santos','first_name'=>'Maria','employer'=>'University']);
$family=array_replace($family,['spouse_applies'=>'Yes','children_apply'=>'Yes','father_surname'=>'Antonio','mother_surname'=>'Osorio',
    'spouses'=>[$spouse],'children'=>[['full_name'=>'Ána Antonio','date_of_birth'=>'2010-02-28'],['full_name'=>'Partial child','date_of_birth'=>'']]]);
[$values,$errors]=synk_hr_pds_validate('family',$family,$today);
$check(!$errors && count($values['children'])===2 && $values['mother_surname']==='Osorio','Family preserves parents and ordered, partially filled records.');
foreach (['2027-01-01','2010-02-30'] as $date) {
    $bad=$family; $bad['children'][1]['date_of_birth']=$date; [, $errors]=synk_hr_pds_validate('family',$bad,$today);
    $check(isset($errors['children.1.date_of_birth']),'Child dates report the correct row.');
}
$bad=$family; $bad['spouses'][0]['hr_profile_id']=99; [, $errors]=synk_hr_pds_validate('family',$bad,$today);
$check(isset($errors['spouses.0._section']),'Nested ownership fields are rejected.');
$bad=$family; $bad['children']=[2=>$family['children'][0]]; [, $errors]=synk_hr_pds_validate('family',$bad,$today);
$check(isset($errors['children']),'Malformed list indexes cannot drop records silently.');
$bad=$family; $bad['spouse_applies']=''; [, $errors]=synk_hr_pds_validate('family',$bad,$today);
$check(isset($errors['spouse_applies']),'Unanswered applicability does not silently remove entered records.');
$none=array_replace($family,['spouse_applies'=>'Not applicable','children_apply'=>'None']);
[$values,$errors]=synk_hr_pds_validate('family',$none,$today);
$check(!$errors && !$values['spouses'] && !$values['children'],'Explicit None options clear their conditional records.');
$school=array_fill_keys(array_keys(synk_hr_pds_record_lists('education')['education']['fields']),'');
$school=array_replace($school,['level'=>'College','institution'=>'Sample University','degree'=>'BS Education','year_from'=>'2010','year_to'=>'2014','year_graduated'=>'2014','status'=>'Completed']);
$education=['education'=>[$school,array_replace($school,['level'=>'Graduate studies','degree'=>'MA Education','year_from'=>'2024','year_to'=>'','year_graduated'=>'','status'=>'Ongoing','units'=>'18 units'])]];
[, $errors]=synk_hr_pds_validate('education',$education,$today); $check(!$errors,'Education supports multiple degrees, ongoing study, and partial records.');
foreach ([['year_to','2009'],['year_graduated','2008'],['year_from','2027'],['year_to','2014.5'],['level','Unknown']] as [$key,$value]) {
    $bad=$education; $bad['education'][0][$key]=$value; [, $errors]=synk_hr_pds_validate('education',$bad,$today);
    $check(isset($errors['education.0.'.$key]),'Invalid education '.$key.' identifies its row.');
}
$bad=$education; $bad['education'][1]['year_graduated']='2025'; [, $errors]=synk_hr_pds_validate('education',$bad,$today);
$check(isset($errors['education.1.year_graduated']),'Ongoing education cannot claim a graduation year.');
[, $errors]=synk_hr_pds_validate('education',['education'=>[]],$today); $check(!$errors,'An empty education list is a valid saved draft.');
$bad=$education; $bad['education']=array_fill(0,101,$school); [, $errors]=synk_hr_pds_validate('education',$bad,$today);
$check(isset($errors['education']),'Record count is bounded.');
$bad=$education; unset($bad['education'][0]['honors']); [, $errors]=synk_hr_pds_validate('education',$bad,$today);
$check(isset($errors['education.0._section']),'Omitted nested fields cannot overwrite saved records.');

if (!in_array('--database',$argv,true)) { echo 'HR faculty PDS: ',$checks,' unit checks passed.',PHP_EOL; exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
synk_hr_pds_ensure($conn);
$conn->close();
// The real request handler uses savepoints inside a rollback-only outer transaction.
class HrPdsTestConnection extends mysqli {
    public bool $requestMode=false;
    public function begin_transaction($flags=0,$name=null): bool { return $this->requestMode ? $this->query('SAVEPOINT hr_pds_request') : parent::begin_transaction($flags,$name); }
    public function commit($flags=0,$name=null): bool { return $this->requestMode ? $this->query('RELEASE SAVEPOINT hr_pds_request') : parent::commit($flags,$name); }
    public function rollback($flags=0,$name=null): bool { return $this->requestMode ? $this->query('ROLLBACK TO SAVEPOINT hr_pds_request') : parent::rollback($flags,$name); }
}
$conn=new HrPdsTestConnection($servername,$username,$password,$dbase); $conn->set_charset('utf8mb4'); $conn->begin_transaction();
$snapshot=static function () use ($conn): string {
    $rows=[];
    foreach (['tbl_useraccount','tbl_faculty','tbl_faculty_profiles','tbl_faculty_record_profiles','tbl_useraccount_faculty_links','tbl_faculty_profile_additional_degrees'] as $table) $rows[$table]=$conn->query('SELECT * FROM '.$table)->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($rows));
};
$ids=[]; $faculties=[];
try {
    $tag='HrPdsQA'.bin2hex(random_bytes(6));
    foreach (['one','two','unlinked'] as $key) {
        $email=$tag.$key.'@example.edu';
        $statement=$conn->prepare("INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES ('HR PDS QA',?,'unusable-test-password','professor','active')");
        $statement->bind_param('s',$email); $statement->execute(); $ids[$key]=(int)$conn->insert_id; $statement->close();
    }
    foreach (['one','two','third'] as $key) {
        $statement=$conn->prepare("INSERT INTO tbl_faculty (first_name,last_name,status) VALUES (?,'PDS Test','active')");
        $statement->bind_param('s',$tag); $statement->execute(); $faculties[$key]=(int)$conn->insert_id; $statement->close();
    }
    $conn->query('INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES ('.$ids['one'].','.$faculties['one'].'),('.$ids['two'].','.$faculties['two'].')');
    $before=$snapshot();
    $session=['user_id'=>$ids['one'],'role'=>'professor','faculty_profile_csrf'=>'qa-token'];
    $request=['csrf_token'=>'qa-token','section'=>'personal','owner'=>'faculty:'.$faculties['one'],'revision'=>0,'values'=>$personal];
    $conn->requestMode=true;
    $result=synk_hr_pds_handle_save($conn,$session,$request);
    $check($result['status']===200 && $result['body']['revision']===1,'First personal save creates HR-owned storage.');
    $state=synk_hr_pds_load($conn,$ids['one'],$faculties['one']);
    $check($state['personal']['values']['surname']==='Antonio' && $state['contact']===null,'Saved names reload and unsaved contact stays separate.');
    $check(synk_hr_pds_load($conn,$ids['two'],$faculties['two'])['personal']===null,'Another faculty account cannot read the first profile.');
    $savedPersonal=$state['personal'];
    $contactRequest=['csrf_token'=>'qa-token','section'=>'contact','owner'=>$request['owner'],'revision'=>0,'values'=>$contact];
    $result=synk_hr_pds_handle_save($conn,$session,$contactRequest);
    $check($result['status']===200 && $result['body']['values']['umid']==='000001234','Contact identifiers save and round-trip as text.');
    $state=synk_hr_pds_load($conn,$ids['one'],$faculties['one']);
    $check($state['personal']===$savedPersonal,'Contact saving does not change personal fields or their version.');
    $check($before===$snapshot(),'PDS saves do not alter login email, directory profiles, account identity, or academic faculty records.');
    foreach (['csrf','foreign','stale','missing','inactive','role'] as $case) {
        $bad=$request; $actor=$session; $expected=422;
        if ($case==='csrf') { $bad['csrf_token']='wrong'; $expected=403; }
        if ($case==='foreign') { $bad['owner']='faculty:'.$faculties['two']; $expected=409; }
        if ($case==='stale') $expected=409;
        if ($case==='missing') unset($bad['values']['surname']);
        if ($case==='inactive') { $actor['user_id']=0; $expected=401; }
        if ($case==='role') { $actor['role']='student'; $expected=401; }
        $result=synk_hr_pds_handle_save($conn,$actor,$bad);
        $check($result['status']===$expected,'Reject '.$case.' without saving.');
        $check(synk_hr_pds_load($conn,$ids['one'],$faculties['one'])===$state,'Rejected '.$case.' preserves both sections.');
    }
    $request['revision']=1; $request['values']['surname']='Corrected'; $request['values']['middle_name']='';
    $result=synk_hr_pds_handle_save($conn,$session,$request);
    $check($result['status']===200 && $result['body']['revision']===2,'Faculty can correct prefilled names.');
    $state=synk_hr_pds_load($conn,$ids['one'],$faculties['one']);
    $check($state['personal']['values']['middle_name']==='','An intentionally cleared saved name stays blank on reload.');
    $check($state['contact']['revision']===1,'Personal saving does not invalidate the contact version.');
    $firstTwo=['personal'=>$state['personal'],'contact'=>$state['contact']];
    foreach (['family'=>$family,'education'=>$education] as $section=>$records) {
        $recordRequest=array_replace($request,['section'=>$section,'revision'=>0,'values'=>$records]);
        $result=synk_hr_pds_handle_save($conn,$session,$recordRequest);
        $check($result['status']===200 && $result['body']['revision']===1 && $result['body']['values']===$records,$section.' saves all scalar fields and ordered records.');
        $state=synk_hr_pds_load($conn,$ids['one'],$faculties['one']);
        $check($state[$section]['values']===$records,$section.' reloads its records after saving.');
        $check(synk_hr_pds_load($conn,$ids['two'],$faculties['two'])[$section]===null,'A different faculty cannot read '.$section.'.');
        $result=synk_hr_pds_handle_save($conn,$session,$recordRequest);
        $check($result['status']===409 && synk_hr_pds_load($conn,$ids['one'],$faculties['one'])===$state,'Stale '.$section.' saves cannot replace records.');
        $bad=array_replace($recordRequest,['revision'=>1,'owner'=>'faculty:'.$faculties['two']]);
        $result=synk_hr_pds_handle_save($conn,$session,$bad);
        $check($result['status']===409 && synk_hr_pds_load($conn,$ids['one'],$faculties['one'])===$state,'A forged owner cannot change '.$section.'.');
        $bad=array_replace($recordRequest,['revision'=>1]);
        if ($section==='family') $bad['values']['children'][0]['date_of_birth']='2010-02-30';
        else $bad['values']['education'][0]['year_to']='2009';
        $result=synk_hr_pds_handle_save($conn,$session,$bad);
        $check($result['status']===422 && synk_hr_pds_load($conn,$ids['one'],$faculties['one'])===$state,'Invalid '.$section.' edits leave all saved records intact.');
        $recordRequest['revision']=1; $recordRequest['values']=$section==='family'?$none:['education'=>[]];
        $result=synk_hr_pds_handle_save($conn,$session,$recordRequest);
        $state=synk_hr_pds_load($conn,$ids['one'],$faculties['one']);
        $check($result['status']===200 && $state[$section]['revision']===2 && ($section==='family'?!$state[$section]['values']['children'] && !$state[$section]['values']['spouses']:!$state[$section]['values']['education']),'Removing '.$section.' records persists on reload.');
    }
    $check($state['personal']===$firstTwo['personal'] && $state['contact']===$firstTwo['contact'],'Family and Education do not alter the first two sections.');
    $check($before===$snapshot(),'Family and Education preserve shared profiles, degrees, faculty records and account identity.');
    $unlinkedSession=array_replace($session,['user_id'=>$ids['unlinked']]);
    $unlinkedRequest=array_replace($request,['owner'=>'account:'.$ids['unlinked'],'revision'=>0]);
    $result=synk_hr_pds_handle_save($conn,$unlinkedSession,$unlinkedRequest);
    $check($result['status']===200,'Faculty without a directory link can save PDS details.');
    $conn->query('INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES ('.$ids['unlinked'].','.$faculties['third'].')');
    $result=synk_hr_pds_handle_save($conn,$unlinkedSession,$unlinkedRequest);
    $check($result['status']===409,'An old tab cannot save after the account receives a faculty link.');
    $unlinkedRequest['owner']='faculty:'.$faculties['third']; $unlinkedRequest['revision']=1;
    $result=synk_hr_pds_handle_save($conn,$unlinkedSession,$unlinkedRequest);
    $check($result['status']===200 && $result['body']['revision']===2,'An unlinked HR record connects to its faculty identity without losing edits.');
    $check(synk_hr_pds_load($conn,$ids['unlinked'],0)['personal']===null,'Unlinking cannot expose an old faculty record through an account fallback.');
    $check($conn->query('SELECT COUNT(*) FROM tbl_hr_faculty_profiles WHERE faculty_id='.$faculties['third'])->fetch_row()[0]==1,'Promotion creates only one HR profile.');
} finally {
    $conn->requestMode=false; $conn->rollback(); $conn->close();
}
echo 'HR faculty PDS: ',$checks,' checks passed. All database fixtures and writes rolled back.',PHP_EOL;
