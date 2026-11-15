<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
set_error_handler(static function($severity,$message,$file,$line):void { throw new ErrorException($message,0,$severity,$file,$line); });
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__,2);
require_once $root.'/backend/db.php';
require_once $root.'/backend/di_dashboard_helper.php';
class DiDashboardFixtureConnection extends mysqli {
    public bool $reading=false;
    private function check(string $sql):void { if ($this->reading && preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql)) throw new RuntimeException('Dashboard attempted a database write.'); }
    #[\ReturnTypeWillChange] public function query($sql,$mode=MYSQLI_STORE_RESULT) { $this->check($sql); return parent::query($sql,$mode); }
    #[\ReturnTypeWillChange] public function prepare($sql) { $this->check($sql); return parent::prepare($sql); }
}
$conn->close(); $conn=new DiDashboardFixtureConnection($servername,$username,$password,$dbase); $conn->set_charset('utf8mb4');
$case=$argv[1]??'all'; $conn->begin_transaction();
$insert=static function(string $sql)use($conn):int{$conn->query($sql);return (int)$conn->insert_id;};
$tag='DQA'.bin2hex(random_bytes(4));
$actor=$insert("INSERT INTO tbl_useraccount(username,email,password,role,status) VALUES('DI Dashboard QA','{$tag}@sksu.edu.ph','unusable','DI','active')");
$conn->query("INSERT INTO tbl_useraccount_roles(user_id,role,status,is_primary) VALUES({$actor},'DI','active',1)");
$ay=$insert("INSERT INTO tbl_academic_years(ay) VALUES('2091-2092')"); $oldAy=$insert("INSERT INTO tbl_academic_years(ay) VALUES('2090-2091')");
$conn->query("UPDATE tbl_academic_settings SET current_ay_id={$ay},current_semester=1");
$campA=$insert("INSERT INTO tbl_campus(campus_code,campus_name,status) VALUES('{$tag}A','AA Instruction North','active')");
$campB=$insert("INSERT INTO tbl_campus(campus_code,campus_name,status) VALUES('{$tag}B','AB Instruction South','active')");
$emptyCampus=$insert("INSERT INTO tbl_campus(campus_code,campus_name,status) VALUES('{$tag}E','AC Instruction Central','active')");
$colA=$insert("INSERT INTO tbl_college(campus_id,college_code,college_name) VALUES({$campA},'{$tag}A','{$tag} College A')");
$colB=$insert("INSERT INTO tbl_college(campus_id,college_code,college_name) VALUES({$campA},'{$tag}B','{$tag} College B')");
$colC=$insert("INSERT INTO tbl_college(campus_id,college_code,college_name) VALUES({$campB},'{$tag}C','{$tag} College C')");
$colE=$insert("INSERT INTO tbl_college(campus_id,college_code,college_name) VALUES({$emptyCampus},'{$tag}E','{$tag} Empty College')");
$faculty=[];
foreach (['shared','second','no_profile','roster_only','inactive','history','next_semester','mismatch','stale'] as $name) $faculty[$name]=$insert("INSERT INTO tbl_faculty(first_name,last_name,status) VALUES('{$tag}','{$name}', '".($name==='inactive'?'inactive':'active')."')");
foreach ([$colA,$colB,$colC] as $col) $conn->query("INSERT INTO tbl_college_faculty(college_id,faculty_id,ay_id,semester) VALUES({$col},{$faculty['shared']},{$ay},1)");
foreach (['second','roster_only','inactive'] as $name) $conn->query("INSERT INTO tbl_college_faculty(college_id,faculty_id,ay_id,semester) VALUES({$colA},{$faculty[$name]},{$ay},1)");
$conn->query("INSERT INTO tbl_college_faculty(college_id,faculty_id,ay_id,semester) VALUES({$colA},{$faculty['history']},{$oldAy},1),({$colA},{$faculty['next_semester']},{$ay},2)");
$subX=$insert("INSERT INTO tbl_subject_masterlist(sub_code,sub_description) VALUES('{$tag}','{$tag} Subject X')");
$subY=$insert("INSERT INTO tbl_subject_masterlist(sub_code,sub_description) VALUES('{$tag}','{$tag} Subject Y')");
$makeClass=static function(int $college,int $year,int $semester,int $subject)use($insert,$tag):array{
    $program=$insert("INSERT INTO tbl_program(college_id,program_code,program_name) VALUES({$college},'{$tag}','{$tag} Program')");
    $header=$insert("INSERT INTO tbl_prospectus_header(program_id,cmo_no,effective_sy) VALUES({$program},'QA','2091-2092')");
    $pys=$insert("INSERT INTO tbl_prospectus_year_sem(prospectus_id,year_level,semester) VALUES({$header},1,{$semester})");
    $ps=$insert("INSERT INTO tbl_prospectus_subjects(pys_id,sub_id,lec_units,lab_units,total_units) VALUES({$pys},{$subject},3,0,3)");
    $section=$insert("INSERT INTO tbl_sections(program_id,ay_id,semester,year_level,section_name,full_section) VALUES({$program},{$year},{$semester},1,'A','{$tag}')");
    $insert("INSERT INTO tbl_section_curriculum(section_id,prospectus_id) VALUES({$section},{$header})");
    $offering=$insert("INSERT INTO tbl_prospectus_offering(program_id,prospectus_id,ps_id,year_level,semester,ay_id,section_id,status) VALUES({$program},{$header},{$ps},1,{$semester},{$year},{$section},'active')");
    return compact('program','header','pys','ps','section','offering');
};
$assign=static function(array $class,int $facultyId,int $year,int $semester,string $type='LEC')use($insert):int {
    $schedule=$insert("INSERT INTO tbl_class_schedule(offering_id,schedule_type,schedule_status,days_json) VALUES({$class['offering']},'{$type}','tba','[]')");
    $insert("INSERT INTO tbl_faculty_workload_sched(schedule_id,faculty_id,ay_id,semester) VALUES({$schedule},{$facultyId},{$year},{$semester})");
    return $schedule;
};
$classA=$makeClass($colA,$ay,1,$subX); $classB=$makeClass($colB,$ay,1,$subX); $classC=$makeClass($colC,$ay,1,$subX);
$assign($classA,$faculty['shared'],$ay,1); $assign($classA,$faculty['shared'],$ay,1,'LAB'); $assign($classA,$faculty['second'],$ay,1);
$assign($classB,$faculty['shared'],$ay,1); $assign($classC,$faculty['shared'],$ay,1);
$psY=$insert("INSERT INTO tbl_prospectus_subjects(pys_id,sub_id,lec_units,lab_units,total_units) VALUES({$classA['pys']},{$subY},3,0,3)");
$classY=$classA; $classY['offering']=$insert("INSERT INTO tbl_prospectus_offering(program_id,prospectus_id,ps_id,year_level,semester,ay_id,section_id,status) VALUES({$classA['program']},{$classA['header']},{$psY},1,1,{$ay},{$classA['section']},'active')");
$assign($classY,$faculty['inactive'],$ay,1); $assign($classY,$faculty['no_profile'],$ay,1);
$history=$makeClass($colA,$oldAy,1,$subX); $assign($history,$faculty['history'],$oldAy,1);
$assign($history,$faculty['mismatch'],$ay,1);
$next=$makeClass($colA,$ay,2,$subX); $assign($next,$faculty['next_semester'],$ay,2);
// Orphaned old curriculum must not reappear as a current live offering.
$staleHeader=$insert("INSERT INTO tbl_prospectus_header(program_id,cmo_no,effective_sy) VALUES({$classA['program']},'Stale','2091-2092')");
$stalePys=$insert("INSERT INTO tbl_prospectus_year_sem(prospectus_id,year_level,semester) VALUES({$staleHeader},1,1)");
$stalePs=$insert("INSERT INTO tbl_prospectus_subjects(pys_id,sub_id,lec_units,total_units) VALUES({$stalePys},{$subX},3,3)");
$stale=$classA; $stale['offering']=$insert("INSERT INTO tbl_prospectus_offering(program_id,prospectus_id,ps_id,year_level,semester,ay_id,section_id) VALUES({$classA['program']},{$staleHeader},{$stalePs},1,1,{$ay},{$classA['section']})");
$assign($stale,$faculty['stale'],$ay,1);
// Give campus cards enough records to exercise a full row and a second row.
if (strpos($case, 'page_faculty') === 0) {
    foreach (['aardvark', 'zebra'] as $name) {
        $faculty[$name] = $insert("INSERT INTO tbl_faculty(first_name,last_name,status) VALUES('{$tag}','{$name}','active')");
        $assign($classA, $faculty[$name], $ay, 1);
    }
}
$designation = $insert("INSERT INTO tbl_designation(designation_name,designation_units,status) VALUES('QA Campus Coordinator',3,'active')");
$conn->query("UPDATE tbl_faculty SET designation_id={$designation} WHERE faculty_id={$faculty['shared']}");
synk_faculty_term_designation_record($conn, $faculty['shared'], $ay, 1, ['designation_id'=>$designation,'designation_name'=>'QA Current Chair','designation_units'=>3], $actor);
// Account-owned profile and faculty-owned partial profile both contribute once.
$prof=$insert("INSERT INTO tbl_useraccount(username,email,password,role,status) VALUES('Dashboard Professor','{$tag}p@sksu.edu.ph','unusable','professor','active')");
$conn->query("INSERT INTO tbl_useraccount_faculty_links(user_id,faculty_id) VALUES({$prof},{$faculty['shared']})");
$profile=array_replace(array_fill_keys(array_keys(synk_faculty_profile_fields()),''),[
    'full_name'=>$tag.' Shared','campus_name'=>'AA Instruction North','college_name'=>$tag.' College A','gender'=>'Female','civil_status'=>'Single','employment_classification'=>'Permanent',
    'faculty_rank'=>'Instructor I','salary_grade'=>'12',
    'master_status'=>'Completed','master_degree'=>'Master of Science','doctoral_status'=>'Ongoing','doctoral_degree'=>'Doctor of Philosophy','date_of_birth'=>null,'service_start_date'=>null]);
$columns=array_keys($profile);$values=array_values($profile);$values[]=$prof;
$stmt=$conn->prepare('INSERT INTO tbl_faculty_profiles (`'.implode('`,`',$columns).'`,user_id) VALUES('.implode(',',array_fill(0,count($values),'?')).')');
$stmt->bind_param(str_repeat('s',count($profile)).'i',...$values);$stmt->execute();$stmt->close();
$partial=json_encode(['full_name'=>$tag.' Partial','bachelor_status'=>'Completed','bachelor_degree'=>'Bachelor of Science']);
$stmt=$conn->prepare('INSERT INTO tbl_faculty_record_profiles(faculty_id,profile_data,updated_by) VALUES(?,?,?)'); $stmt->bind_param('isi',$faculty['second'],$partial,$actor); $stmt->execute(); $stmt->close();
$filters=[];
if ($case==='campus' || $case==='page_campus') $filters=['campus_id'=>(string)$campA];
if ($case==='college') $filters=['campus_id'=>(string)$campA,'college_id'=>(string)$colB];
if ($case==='empty' || $case==='page_empty') $filters=['campus_id'=>(string)$emptyCampus];
if ($case==='wrong_scope') $filters=['campus_id'=>(string)$campA,'college_id'=>(string)$colC];
if ($case==='array_scope') $filters=['campus_id'=>[$campA]];
if ($case==='next_semester' || $case==='page_next_semester') $conn->query('UPDATE tbl_academic_settings SET current_semester=2');
if ($case==='missing_term' || $case==='page_missing_term') $conn->query('DELETE FROM tbl_academic_settings');
$pageRoute = 'index.php';
if (strpos($case, 'page_faculty') === 0) {
    $pageRoute = 'campus-faculty.php';
    $filters = ['campus_id'=>(string)$campA];
    if ($case==='page_faculty_college') $filters['college_id']=(string)$colB;
    if ($case==='page_faculty_empty') $filters['campus_id']=(string)$emptyCampus;
    if ($case==='page_faculty_invalid') $filters['college_id']=(string)$colC;
    if ($case==='page_faculty_missing') $filters=[];
    if ($case==='page_faculty_array') $filters['campus_id']=[$campA];
    if ($case==='page_faculty_no_term') $conn->query('DELETE FROM tbl_academic_settings');
    if ($case==='page_faculty_details') {
        $pageRoute='faculty-profile.php';
        $filters+=['faculty_id'=>(string)$faculty['shared'],'college_id'=>(string)$colB];
    }
}
$snapshot=static function()use($conn):array {return $conn->query('CHECKSUM TABLE tbl_campus,tbl_college,tbl_academic_settings,tbl_faculty,tbl_college_faculty,tbl_program,tbl_prospectus_header,tbl_prospectus_year_sem,tbl_prospectus_subjects,tbl_prospectus_offering,tbl_class_schedule,tbl_faculty_workload_sched,tbl_faculty_profiles,tbl_faculty_record_profiles,tbl_designation,tbl_faculty_term_designations')->fetch_all(MYSQLI_ASSOC);};
$before=$snapshot(); $conn->reading=true;
if (strpos($case,'page')===0) {
    session_save_path($root.'/temp'); session_start(); $_SESSION=['user_id'=>$actor,'role'=>'DI','csrf_token'=>'di-dashboard-qa','scheduler_working_ay_id'=>$oldAy,'scheduler_working_semester'=>3];
    $_GET=$filters+['ay_id'=>(string)$oldAy,'semester'=>'3']; $_POST=[]; $_SERVER['PHP_SELF']='/synk/di/'.$pageRoute; $_SERVER['REQUEST_METHOD']='GET';
    session_write_close(); ob_start();
    register_shutdown_function(static function()use($conn,$snapshot,$before,$ay,$oldAy,$campA,$campB,$emptyCampus,$colA,$colB,$colC,$faculty):void{
        while(ob_get_level()>1)ob_end_flush(); $html=ob_get_clean();
        $data=['html'=>$html,'status'=>http_response_code()?:200,'unchanged'=>$before===$snapshot(),'ay'=>$ay,'old_ay'=>$oldAy,'campus'=>$campA,'campus_b'=>$campB,'empty_campus'=>$emptyCampus,'college'=>$colA,'college_b'=>$colB,'college_c'=>$colC,'faculty_ids'=>$faculty];
        $conn->reading=false; $conn->rollback(); if(session_status()!==PHP_SESSION_ACTIVE)session_start();session_destroy();echo json_encode($data);
    });
    require $root.'/di/'.$pageRoute; exit;
}
try {$data=synk_di_dashboard($conn,$filters);$error=null;}catch(DomainException $exception){$data=null;$error=['status'=>$exception->getCode(),'message'=>$exception->getMessage()];}
$result=['data'=>$data,'error'=>$error,'unchanged'=>$before===$snapshot(),'campus'=>$campA,'campus_b'=>$campB,'empty_campus'=>$emptyCampus,'college'=>$colA,'college_b'=>$colB,'faculty_ids'=>$faculty];
$conn->reading=false;$conn->rollback();echo json_encode($result);
