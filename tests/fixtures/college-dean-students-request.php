<?php
if (PHP_SAPI !== 'cli' && !defined('SYNK_DEAN_STUDENTS_QA')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__,2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/college_dean_students_helper.php';
class DeanStudentsQAConnection extends mysqli
{
    public $failEmailWrite=false;
    private $emailWrites=0;
    #[\ReturnTypeWillChange]
    public function prepare($query)
    {
        if ($this->failEmailWrite && strpos($query,'UPDATE tbl_student_management SET email_address=')===0 && ++$this->emailWrites===2) throw new RuntimeException('Injected second email failure.');
        return parent::prepare($query);
    }
    #[\ReturnTypeWillChange]
    public function query($query,$result_mode=MYSQLI_STORE_RESULT)
    {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i',$query)) throw new RuntimeException('Directory requests must not change the schema.');
        return parent::query($query,$result_mode);
    }
    public function startFixtures(): void { parent::begin_transaction(); }
    public function discardFixtures(): void { parent::rollback(); }
    public function begin_transaction($flags=null,$name=null): bool { return $this->query('SAVEPOINT dean_students_request'); }
    public function commit($flags=null,$name=null): bool { return $this->query('RELEASE SAVEPOINT dean_students_request'); }
    public function rollback($flags=null,$name=null): bool { return $this->query('ROLLBACK TO SAVEPOINT dean_students_request'); }
}
$conn->close(); $conn=new DeanStudentsQAConnection($servername,$username,$password,$dbase); $conn->set_charset('utf8mb4');
$conn->startFixtures();
$scenario=(string)($_GET['scenario'] ?? ($argv[1] ?? 'list'));
$term=synk_fetch_current_academic_term($conn);
$otherSemester=$term['semester']===1 ? 2 : 1;
$colleges=$conn->query("SELECT c.college_id,c.college_code,c.college_name,cp.campus_id,cp.campus_name,
    (SELECT MIN(p.program_id) FROM tbl_program p WHERE p.college_id=c.college_id) AS program_id
    FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id
    WHERE c.status='active' AND cp.status='active' AND EXISTS (SELECT 1 FROM tbl_program p WHERE p.college_id=c.college_id)
    ORDER BY c.college_id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
if (count($colleges)!==2) throw new RuntimeException('Two colleges with programs are required.');
$scope=$colleges[0]; $foreignScope=$colleges[1];
foreach (['college_id','campus_id','program_id'] as $field) { $scope[$field]=(int)$scope[$field]; $foreignScope[$field]=(int)$foreignScope[$field]; }
$tag='DeanStudentsQA' . bin2hex(random_bytes(7));
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id)
    VALUES ('{$tag}','{$tag}@sksu.edu.ph','unusable-test-password','college_dean','active'," . $scope['college_id'] . ')');
$userId=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$userId,['college_dean'],'college_dean');
session_save_path($root . '/temp'); session_start();
$_SESSION=['user_id'=>$userId,'role'=>'college_dean','csrf_token'=>'dean-students-qa'];
$subjectRows=$conn->query('SELECT sub_id,sub_code,sub_description FROM tbl_subject_masterlist ORDER BY sub_id LIMIT 2')->fetch_all(MYSQLI_ASSOC);
$section=static function (array $college,int $semester) use ($conn,$term,$tag): int {
    $suffix='Q' . substr($tag,-5);
    $full='QA 1' . $suffix;
    $stmt=$conn->prepare("INSERT INTO tbl_sections (program_id,ay_id,semester,year_level,section_name,full_section,status) VALUES (?,?,?,1,?,?,'active')");
    $stmt->bind_param('iiiss',$college['program_id'],$term['ay_id'],$semester,$suffix,$full); $stmt->execute(); $id=(int)$stmt->insert_id; $stmt->close(); return $id;
};
$ownSection=$section($scope,$term['semester']); $historicalSection=$section($scope,$otherSemester); $foreignSection=$section($foreignScope,$term['semester']);
$base=['ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'source_sheet_name'=>'DIRECTORY QA','source_file_name'=>'directory-qa.csv',
    'year_level'=>1,'suffix_name'=>'','email_address'=>'','program_id'=>$scope['program_id'],'uploaded_by'=>$userId,'source_row_number'=>8];
$prefix='000' . (string)(3800000000+random_int(1,10000000));
$profiles=[
    'shared'=>['student_number'=>$prefix,'last_name'=>'Peña','first_name'=>'José','middle_name'=>'Niño ' . $tag,'program_id'=>$foreignScope['program_id']],
    'own'=>['student_number'=>$prefix . '1','last_name'=>'Muñoz','first_name'=>'María','middle_name'=>'Güell ' . $tag],
    'foreign'=>['student_number'=>$prefix . '2','last_name'=>'ForeignQA','first_name'=>'Only','middle_name'=>$tag,'program_id'=>$foreignScope['program_id']],
    'unimported'=>['student_number'=>$prefix . '3','last_name'=>'UnimportedQA','first_name'=>'Only','middle_name'=>$tag],
    'archived'=>['student_number'=>$prefix . '4','last_name'=>'ArchivedQA','first_name'=>'Only','middle_name'=>$tag],
];
foreach ($profiles as &$profile) $profile['student_id']=synk_student_identity_insert_record($conn,$profile+$base);
unset($profile);
$link=static function (int $studentId,array $college,int $sectionId,int $semester,array $subject,int $active=1) use ($conn,$term,$tag,$userId): void {
    $stmt=$conn->prepare("INSERT INTO tbl_student_management_enrolled_subjects
        (import_batch_key,source_file_name,campus_id,college_id,program_id,ay_id,semester,year_level,section_id,section_text,
         offering_id,subject_id,subject_code,descriptive_title,student_id,faculty_id,room_id,room_text,schedule_text,source_row_number,uploaded_by,is_active)
        VALUES (?,'QA & subjects.csv',?,?,?,?,?,1,?,'QA Section',0,?,?,?, ?,0,0,'','',8,?,?)");
    $stmt->bind_param('siiiiiiissiii',$tag,$college['campus_id'],$college['college_id'],$college['program_id'],$term['ay_id'],$semester,
        $sectionId,$subject['sub_id'],$subject['sub_code'],$subject['sub_description'],$studentId,$userId,$active);
    $stmt->execute(); $stmt->close();
};
$link($profiles['shared']['student_id'],$scope,$ownSection,$term['semester'],$subjectRows[0]);
$link($profiles['shared']['student_id'],$scope,$ownSection,$term['semester'],$subjectRows[1]);
$link($profiles['shared']['student_id'],$scope,$historicalSection,$otherSemester,$subjectRows[0]);
$link($profiles['shared']['student_id'],$foreignScope,$foreignSection,$term['semester'],$subjectRows[0]);
$link($profiles['own']['student_id'],$scope,$ownSection,$term['semester'],$subjectRows[0]);
$link($profiles['foreign']['student_id'],$foreignScope,$foreignSection,$term['semester'],$subjectRows[0]);
$link($profiles['archived']['student_id'],$scope,$ownSection,$term['semester'],$subjectRows[0],0);
$handler='backend/query_college_dean_students.php';
$_SERVER['REQUEST_METHOD']='POST'; $_GET=[];
$_POST=['action'=>'list','csrf_token'=>'dean-students-qa','search'=>$tag];
switch ($scenario) {
    case 'page': $handler='college-dean/students.php'; $_GET=['college_id'=>$foreignScope['college_id']]; break;
    case 'bootstrap': $_POST['action']='bootstrap'; break;
    case 'list': break;
    case 'current': $_POST['term']=$term['ay_id'] . ':' . $term['semester']; break;
    case 'historical': $_POST['term']=$term['ay_id'] . ':' . $otherSemester; break;
    case 'section': $_POST['section_id']=$ownSection; break;
    case 'historical_section': $_POST['section_id']=$historicalSection; break;
    case 'program': $_POST['program_id']=$scope['program_id']; break;
    case 'foreign_program': $_POST['program_id']=$foreignScope['program_id']; break;
    case 'foreign_section': $_POST['section_id']=$foreignSection; break;
    case 'search_id': $_POST['search']=$profiles['own']['student_number']; break;
    case 'search_accent': $_POST['search']='JOSÉ PEÑA ' . $tag; break;
    case 'search_literal': $_POST['search']=$tag . ' %'; break;
    case 'search_sql': $_POST['search']="' OR 1=1 --"; break;
    case 'page_one': $_POST['page_size']=1; break;
    case 'page_two': $_POST['page_size']=1; $_POST['page']=2; break;
    case 'page_stale': $_POST['page_size']=1; $_POST['page']=99; break;
    case 'sort_id': $_POST['sort']='id'; break;
    case 'sort_newest': $_POST['sort']='newest'; break;
    case 'details': case 'details_current': case 'details_foreign': case 'details_unimported': case 'details_archived':
        $_POST['action']='detail';
        $key=['details'=>'shared','details_current'=>'shared','details_foreign'=>'foreign','details_unimported'=>'unimported','details_archived'=>'archived'][$scenario];
        $_POST['student_id']=$profiles[$key]['student_id'];
        if ($scenario==='details_current') $_POST['term']=$term['ay_id'] . ':' . $term['semester'];
        break;
    case 'reassigned': $conn->query('UPDATE tbl_useraccount SET college_dean_college_id=' . $foreignScope['college_id'] . ' WHERE user_id=' . $userId); break;
    case 'scope_tamper': $_POST['college_id']=$foreignScope['college_id']; break;
    case 'bad_csrf': $_POST['csrf_token']='invalid'; break;
    case 'revoked': $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='college_dean'"); break;
    case 'inactive': $conn->query('UPDATE tbl_useraccount SET status=\'inactive\' WHERE user_id=' . $userId); break;
    case 'other_role': $_SESSION['role']='program_chair'; break;
    case 'bad_method': $_SERVER['REQUEST_METHOD']='GET'; break;
    case 'bad_term': $_POST['term']='all OR 1=1'; break;
    case 'bad_sort': $_POST['sort']='s.student_id; DELETE'; break;
    case 'bad_page': $_POST['page']=-1; break;
    case 'array_input': $_POST['search']=[$tag]; break;
    case 'bad_action': $_POST['action']='delete'; break;
    default: throw new RuntimeException('Unknown students fixture scenario.');
}
if (defined('SYNK_STUDENT_EMAIL_QA')) require __DIR__ . '/student-emails-scenarios.php';
$takeState=static function () use ($conn): string {
    $rows=[];
    foreach (['tbl_student_management','tbl_student_management_enrolled_subjects'] as $table) $rows[$table]=$conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($rows));
};
$requestBefore=$takeState(); session_write_close(); ob_start();
register_shutdown_function(static function () use ($conn,$scope,$foreignScope,$profiles,$term,$requestBefore,$takeState): void {
    $body=''; while (ob_get_level()>0) $body=ob_get_clean() . $body;
    try {
        $result=['response'=>json_decode($body,true) ?: $body,'http_status'=>http_response_code() ?: 200,
            'scope'=>$scope,'foreign_scope'=>$foreignScope,'profiles'=>$profiles,'term'=>$term,'session_college'=>$_SESSION['college_id'] ?? 0,
            'records_unchanged'=>$requestBefore===$takeState(),
            'email_profiles'=>$conn->query('SELECT student_id,student_number,first_name,last_name,middle_name,email_address FROM tbl_student_management WHERE student_id IN (' . implode(',',array_column($profiles,'student_id')) . ') ORDER BY student_id')->fetch_all(MYSQLI_ASSOC),
            'email_pending_count'=>count($_SESSION['dean_student_email_previews'] ?? [])];
        $conn->discardFixtures();
        if (session_status()===PHP_SESSION_ACTIVE) session_destroy();
        http_response_code(200); header('Content-Type: application/json; charset=utf-8'); echo json_encode($result);
    } catch (Throwable $e) {
        $conn->discardFixtures(); if (session_status()===PHP_SESSION_ACTIVE) session_destroy();
        http_response_code(500); echo json_encode(['fixture_error'=>$e->getMessage(),'output'=>substr($body,0,1200)]);
    }
});
chdir(dirname($root . '/' . $handler)); require $root . '/' . $handler;
