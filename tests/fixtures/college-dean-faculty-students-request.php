<?php
if (PHP_SAPI !== 'cli' && !defined('SYNK_DEAN_FACULTY_STUDENTS_QA')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__,2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/college_dean_faculty_students_helper.php';
class DeanFacultyStudentsQAConnection extends mysqli
{
    #[\ReturnTypeWillChange]
    public function query($query,$result_mode=MYSQLI_STORE_RESULT)
    {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i',$query)) throw new RuntimeException('Report requests must not change the schema.');
        return parent::query($query,$result_mode);
    }
}
$conn->close(); $conn = new DeanFacultyStudentsQAConnection($servername,$username,$password,$dbase); $conn->set_charset('utf8mb4');
$conn->begin_transaction();
$scenario = (string)($_GET['scenario'] ?? ($argv[1] ?? 'list'));
$term = synk_fetch_current_academic_term($conn);
$source = synk_professor_workload_assignment_source_sql();
$templates = synk_dean_faculty_students_query($conn,"SELECT DISTINCT p.college_id,c.campus_id,po.program_id,po.prospectus_id,po.ps_id,
        po.year_level,po.semester,ps.sub_id AS subject_id,sm.sub_code,sm.sub_description
    {$source} JOIN tbl_program p ON p.program_id=po.program_id JOIN tbl_college c ON c.college_id=p.college_id
    JOIN tbl_campus cp ON cp.campus_id=c.campus_id
    WHERE fw.ay_id=? AND fw.semester=? AND c.status='active' AND cp.status='active'
    ORDER BY p.college_id,po.program_id,ps.sub_id",'ii',[$term['ay_id'],$term['semester']]);
if (!$templates) throw new RuntimeException('Current scheduled offerings are required.');
$collegeId = (int)$templates[0]['college_id']; $campusId = (int)$templates[0]['campus_id'];
$own = array_values(array_filter($templates,static function(array $row) use($collegeId):bool{return (int)$row['college_id']===$collegeId;}));
$foreign = null; $programA = (int)$own[0]['program_id']; $first = $own[0]; $second = null; $otherProgram = null;
foreach ($own as $candidate) {
    if ((int)$candidate['program_id']===$programA && (int)$candidate['subject_id']!==(int)$first['subject_id'] && !$second) $second=$candidate;
    if ((int)$candidate['program_id']!==$programA && !$otherProgram) $otherProgram=$candidate;
}
foreach ($templates as $candidate) if ((int)$candidate['college_id']!==$collegeId) { $foreign=$candidate; break; }
$historical = $conn->query('SELECT ay_id,ay FROM tbl_academic_years WHERE ay_id<>' . $term['ay_id'] . ' ORDER BY ay_id LIMIT 1')->fetch_assoc();
if (!$second || !$otherProgram || !$foreign || !$historical) throw new RuntimeException('The report fixture needs two programs, two colleges and a previous academic year.');
$tag = 'ReportQA' . bin2hex(random_bytes(6));
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id)
    VALUES ('{$tag}','{$tag}@sksu.edu.ph','unusable-test-password','college_dean','active',{$collegeId})");
$userId = (int)$conn->insert_id; synk_persist_useraccount_roles($conn,$userId,['college_dean'],'college_dean');
session_save_path($root . '/temp'); session_start();
$_SESSION = ['user_id'=>$userId,'role'=>'college_dean','csrf_token'=>'faculty-students-qa'];
$faculty = [];
foreach (['a'=>['Peña','José','active'],'b'=>['Muñoz','María','active'],'c'=>['Unassigned','Only','active'],
    'inactive'=>['Inactive','Only','inactive'],'foreign'=>['Foreign','Only','active']] as $key=>$names) {
    $last = $names[0] . ' ' . $tag . $key; $firstName=$names[1]; $status=$names[2]; $middle='Niño';
    $stmt=$conn->prepare('INSERT INTO tbl_faculty (last_name,first_name,middle_name,status) VALUES (?,?,?,?)');
    $stmt->bind_param('ssss',$last,$firstName,$middle,$status);$stmt->execute();$faculty[$key]=(int)$stmt->insert_id;$stmt->close();
    $rosterCollege = $key==='foreign' ? (int)$foreign['college_id'] : $collegeId;
    $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$rosterCollege},{$faculty[$key]},{$term['ay_id']},{$term['semester']},'active')");
}
$makeClass = static function(array $template,int $ayId,array $owners) use($conn,$term,$tag,$userId):array {
    $suffix=substr($tag,-6) . bin2hex(random_bytes(2)); $full='QA ' . $suffix;
    $stmt=$conn->prepare("INSERT INTO tbl_sections (program_id,ay_id,semester,year_level,section_name,full_section,status) VALUES (?,?,?,?,?,?,'active')");
    $programId=(int)$template['program_id'];$semester=(int)$template['semester'];$year=(int)$template['year_level'];
    $stmt->bind_param('iiiiss',$programId,$ayId,$semester,$year,$suffix,$full);$stmt->execute();$sectionId=(int)$stmt->insert_id;$stmt->close();
    $prospectus=(int)$template['prospectus_id'];$psId=(int)$template['ps_id'];
    $conn->query("INSERT INTO tbl_section_curriculum (section_id,prospectus_id,assigned_by) VALUES ({$sectionId},{$prospectus},{$userId})");
    $conn->query("INSERT INTO tbl_prospectus_offering (program_id,prospectus_id,ps_id,year_level,semester,ay_id,section_id,status)
        VALUES ({$programId},{$prospectus},{$psId},{$year},{$semester},{$ayId},{$sectionId},'active')");
    $offering=(int)$conn->insert_id;
    foreach (['LEC','LAB'] as $type) {
        $conn->query("INSERT INTO tbl_class_schedule (offering_id,schedule_type,room_id,days_json,time_start,time_end,created_by)
            VALUES ({$offering},'{$type}',0,'[\"M\"]','09:00:00','10:00:00',{$userId})");
        $schedule=(int)$conn->insert_id;
        foreach ($owners as $owner) $conn->query("INSERT INTO tbl_faculty_workload_sched (schedule_id,faculty_id,ay_id,semester) VALUES ({$schedule},{$owner},{$ayId},{$semester})");
    }
    return ['offering_id'=>$offering,'section_id'=>$sectionId,'section_display'=>$full,'ay_id'=>$ayId,'semester'=>$semester]+$template;
};
$classes = [
    'a1'=>$makeClass($first,$term['ay_id'],[$faculty['a'],$faculty['b']]),
    'a2'=>$makeClass($second,$term['ay_id'],[$faculty['a']]),
    'a3'=>$makeClass($otherProgram,$term['ay_id'],[$faculty['a']]),
    'b1'=>$makeClass($otherProgram,$term['ay_id'],[$faculty['b']]),
    'old'=>$makeClass($first,(int)$historical['ay_id'],[$faculty['a']]),
    'foreign'=>$makeClass($foreign,$term['ay_id'],[$faculty['foreign']]),
    'inactive'=>$makeClass($first,$term['ay_id'],[$faculty['inactive']]),
];
$students=[];$prefix='000' . (string)(4100000000+random_int(1,10000000));
$wrongCampus=(int)$conn->query('SELECT campus_id FROM tbl_campus WHERE campus_id<>' . $campusId . ' ORDER BY campus_id LIMIT 1')->fetch_assoc()['campus_id'];
foreach (['shared','own','third','archived','wrong_scope','wrong_campus'] as $index=>$key) {
    $students[$key]=synk_student_identity_insert_record($conn,['student_number'=>$prefix . $index,'last_name'=>'Peña','first_name'=>'José',
        'middle_name'=>$tag . $key,'suffix_name'=>'','program_id'=>$key==='shared' ? (int)$foreign['program_id'] : $programA,
        'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'source_file_name'=>'report-qa.csv','source_sheet_name'=>'QA',
        'source_row_number'=>8,'year_level'=>1,'email_address'=>'','uploaded_by'=>$userId]);
}
$link = static function(string $student,string $classKey,int $active=1,bool $legacy=false,bool $wrongScope=false,bool $wrongCampusOnly=false) use($conn,$classes,$students,$tag,$userId,$faculty,$foreign,$wrongCampus):void {
    $class=$classes[$classKey];$offering=$legacy ? 0 : $class['offering_id'];$studentId=$students[$student];
    $college=$wrongScope ? (int)$foreign['college_id'] : (int)$class['college_id'];
    $campus=$wrongCampusOnly ? $wrongCampus : ($wrongScope ? (int)$foreign['campus_id'] : (int)$class['campus_id']);
    $stmt=$conn->prepare("INSERT INTO tbl_student_management_enrolled_subjects
        (import_batch_key,source_file_name,campus_id,college_id,program_id,ay_id,semester,year_level,section_id,section_text,offering_id,
         subject_id,subject_code,descriptive_title,student_id,faculty_id,room_id,room_text,schedule_text,source_row_number,uploaded_by,is_active)
        VALUES (?,'report-qa.csv',?,?,?,?,?,?,?, ?,?,?,?, ?,?,?,0,'','',8,?,?)");
    $stmt->bind_param('siiiiiiisiissiiii',$tag,$campus,$college,$class['program_id'],$class['ay_id'],$class['semester'],$class['year_level'],
        $class['section_id'],$class['section_display'],$offering,$class['subject_id'],$class['sub_code'],$class['sub_description'],$studentId,$faculty['c'],$userId,$active);
    $stmt->execute();$stmt->close();
};
$link('shared','a1');$link('own','a1');$link('shared','a2',1,true);$link('shared','b1');$link('third','b1');
$link('archived','a3',0);$link('wrong_scope','a3',1,false,true);$link('own','old');$link('shared','foreign');$link('wrong_scope','foreign');
$link('wrong_campus','a3',1,false,false,true);
$conn->query("INSERT INTO tbl_offering_enrollee_counts (offering_id,total_enrollees,source_kind,created_by,updated_by) VALUES ({$classes['a3']['offering_id']},99,'csv_roster',{$userId},{$userId})");
$_SERVER['REQUEST_METHOD']='POST'; $_POST=['action'=>'list','csrf_token'=>'faculty-students-qa','search'=>$tag];
$handler='backend/query_college_dean_faculty_students.php';
switch($scenario) {
    case 'page':$handler='college-dean/reports.php';$_GET=['college_id'=>$foreign['college_id']];break;
    case 'bootstrap':$_POST['action']='bootstrap';break;
    case 'list':break;
    case 'missing':$_POST['status']='missing';break;
    case 'complete':$_POST['status']='complete';break;
    case 'unassigned':$_POST['status']='unassigned';break;
    case 'program_a':$_POST['program_id']=$programA;break;
    case 'program_b':$_POST['program_id']=$otherProgram['program_id'];break;
    case 'historical':$_POST['term']=$historical['ay_id'] . ':' . $term['semester'];break;
    case 'detail':$_POST['action']='detail';$_POST['faculty_id']=$faculty['a'];break;
    case 'detail_program':$_POST['action']='detail';$_POST['faculty_id']=$faculty['a'];$_POST['program_id']=$programA;break;
    case 'detail_old':$_POST['action']='detail';$_POST['faculty_id']=$faculty['a'];$_POST['term']=$historical['ay_id'] . ':' . $term['semester'];break;
    case 'detail_foreign':$_POST['action']='detail';$_POST['faculty_id']=$faculty['foreign'];break;
    case 'detail_inactive':$_POST['action']='detail';$_POST['faculty_id']=$faculty['inactive'];break;
    case 'detail_unassigned':$_POST['action']='detail';$_POST['faculty_id']=$faculty['c'];break;
    case 'search_accent':$_POST['search']='PEÑA ' . $tag;break;
    case 'search_literal':$_POST['search']='%';break;
    case 'search_sql':$_POST['search']="' OR 1=1--";break;
    case 'reassigned':$conn->query("UPDATE tbl_useraccount SET college_dean_college_id={$foreign['college_id']} WHERE user_id={$userId}");break;
    case 'bad_csrf':$_POST['csrf_token']='wrong';break;
    case 'other_college':$_POST['college_id']=$foreign['college_id'];break;
    case 'other_campus':$_POST['campus_id']=2147483647;break;
    case 'other_role':$_SESSION['role']='program_chair';break;
    case 'revoked':$conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='college_dean'");break;
    case 'inactive':$conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$userId}");break;
    case 'bad_method':$_SERVER['REQUEST_METHOD']='GET';break;
    case 'array_input':$_POST['faculty_id']=[1];break;
    case 'bad_term':$_POST['term']='1:9';break;
    case 'bad_program':$_POST['program_id']=$foreign['program_id'];break;
    case 'bad_status':$_POST['status']='deleted';break;
    case 'bad_faculty':$_POST['faculty_id']=-1;break;
    case 'bad_action':$_POST['action']='save';break;
    default:throw new RuntimeException('Unknown report scenario.');
}
$tables=['tbl_useraccount','tbl_useraccount_roles','tbl_faculty','tbl_college_faculty','tbl_class_schedule','tbl_faculty_workload_sched',
    'tbl_sections','tbl_section_curriculum','tbl_prospectus_offering','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_offering_enrollee_counts'];
$snapshot=static function() use($conn,$tables):array{$rows=[];foreach($tables as $table)$rows[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));return $rows;};
$before=$snapshot();session_write_close();ob_start();
register_shutdown_function(static function() use($conn,$snapshot,$before,$faculty,$classes,$collegeId,$campusId,$programA,$otherProgram,$foreign,$historical,$term):void {
    $body='';while(ob_get_level()>0)$body=ob_get_clean().$body;
    try {
        $workloadCounts=[];
        foreach(synk_dean_workload_classes($conn,['college_id'=>$collegeId,'campus_id'=>$campusId],$term,$faculty['a']) as $class) $workloadCounts[$class['offering_id']]=$class['roster_count'];
        $result=['response'=>json_decode($body,true) ?: $body,'http_status'=>http_response_code() ?: 200,'records_unchanged'=>$snapshot()===$before,
            'workload_counts'=>$workloadCounts,
            'faculty'=>$faculty,'classes'=>$classes,'college_id'=>$collegeId,'campus_id'=>$campusId,'program_a'=>$programA,'program_b'=>(int)$otherProgram['program_id'],
            'foreign'=>$foreign,'historical'=>$historical,'term'=>$term,'session_college'=>(int)($_SESSION['college_id'] ?? 0)];
        $conn->rollback();if(session_status()===PHP_SESSION_ACTIVE)session_destroy();http_response_code(200);header('Content-Type: application/json; charset=utf-8');echo json_encode($result);
    } catch(Throwable $e) {$conn->rollback();http_response_code(500);echo json_encode(['fixture_error'=>$e->getMessage(),'output'=>substr($body,0,1800)]);}
});
chdir(dirname($root . '/' . $handler));require $root . '/' . $handler;
