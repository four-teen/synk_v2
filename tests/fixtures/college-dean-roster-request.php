<?php
// Local test requests use an outer transaction; endpoint commits release only a savepoint.
if (PHP_SAPI !== 'cli' && !defined('SYNK_DEAN_ROSTER_QA')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/college_dean_workload_helper.php';
require_once $root . '/backend/student_email_helper.php';
class DeanRosterQAConnection extends mysqli
{
    public $failRosterInsert = false;
    public $failManualAudit = false;
    public $failClearAudit = false;
    public $hideClearTable = false;
    private $clearInserts = 0;
    private $rosterInserts = 0;
    #[\ReturnTypeWillChange]
    public function prepare($query)
    {
        if ($this->failClearAudit && preg_match('/INSERT INTO tbl_student_class_clear_history/',$query) && ++$this->clearInserts===2) throw new RuntimeException('Injected second clearing audit failure.');
        if ($this->failManualAudit && preg_match('/INSERT INTO tbl_student_class_roster_audit/',$query)) throw new RuntimeException('Injected audit failure.');
        if ($this->failRosterInsert && preg_match('/INSERT INTO tbl_student_management_enrolled_subjects/', $query)
            && ++$this->rosterInserts === 2) throw new RuntimeException('Injected second-row failure for rollback verification.');
        return parent::prepare($query);
    }
    #[\ReturnTypeWillChange]
    public function query($query, $result_mode = MYSQLI_STORE_RESULT)
    {
        if ($this->hideClearTable && strpos($query,"SHOW TABLES LIKE 'tbl_student_class_clear_history'")===0) return parent::query('SELECT 1 FROM DUAL WHERE 1=0');
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query)) throw new RuntimeException('Schema changes are forbidden in request tests.');
        return parent::query($query, $result_mode);
    }
    public function startFixtures(): void { parent::begin_transaction(); }
    public function discardFixtures(): void { parent::rollback(); }
    public function begin_transaction($flags = null, $name = null): bool { return $this->query('SAVEPOINT dean_roster_request'); }
    public function commit($flags = null, $name = null): bool { return $this->query('RELEASE SAVEPOINT dean_roster_request'); }
    public function rollback($flags = null, $name = null): bool
    {
        $this->query('ROLLBACK TO SAVEPOINT dean_roster_request');
        return $this->query('RELEASE SAVEPOINT dean_roster_request');
    }
}
$conn->close();
$conn = new DeanRosterQAConnection($servername, $username, $password, $dbase);
$conn->set_charset('utf8mb4');
$conn->startFixtures();
$scenario = (string)($_GET['scenario'] ?? ($argv[1] ?? 'page'));
$ownedPhotoPath = null;
$photoExpectedUrl = '';
$photoBeforeUrl = '';
$photoUserId = 0;
register_shutdown_function(static function () use (&$ownedPhotoPath): void {
    if ($ownedPhotoPath !== null && is_file($ownedPhotoPath)) unlink($ownedPhotoPath);
});
$term = synk_fetch_current_academic_term($conn);
$source = synk_professor_workload_assignment_source_sql();
$stmt = $conn->prepare("SELECT fw.faculty_id, p.college_id, c.campus_id, po.offering_id {$source}
    INNER JOIN tbl_program p ON p.program_id=po.program_id INNER JOIN tbl_college c ON c.college_id=p.college_id
    WHERE fw.ay_id=? AND fw.semester=? AND c.status='active'
    ORDER BY po.offering_id, fw.faculty_id LIMIT 1");
$stmt->bind_param('ii',$term['ay_id'],$term['semester']); $stmt->execute();
$target = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$target) throw new RuntimeException('A current assigned class is required for the request tests.');
$collegeId = (int)$target['college_id']; $facultyId = (int)$target['faculty_id']; $offeringId = (int)$target['offering_id'];
$tag = 'DeanRosterQA' . bin2hex(random_bytes(8));
$email = strtolower($tag) . '@sksu.edu.ph';
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id)
    VALUES ('{$tag}','{$email}','unusable-qa-password','college_dean','active',{$collegeId})");
$userId = (int)$conn->insert_id;
synk_persist_useraccount_roles($conn,$userId,['college_dean'],'college_dean');
session_save_path($root . '/temp'); session_start();
$_SESSION = ['user_id'=>$userId,'role'=>'college_dean','csrf_token'=>'dean-roster-qa'];
$scope = synk_college_dean_dashboard_scope($conn);
$class = synk_dean_workload_class($conn,$scope,$term,$facultyId,$offeringId);
if (strpos($scenario, 'photo_') === 0) {
    $photoTag = $tag . 'Photo';
    $photoEmail = strtolower($photoTag) . '@sksu.edu.ph';
    $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status)
        VALUES ('{$photoTag}','{$photoEmail}','unusable-qa-password','admin','active')");
    $photoUserId = (int)$conn->insert_id;
    synk_persist_useraccount_roles($conn,$photoUserId,['admin','professor'],'admin');
    // Existing links are changed only inside this fixture's rolled-back transaction.
    $conn->query('DELETE FROM tbl_useraccount_faculty_links WHERE faculty_id=' . $facultyId);
    if ($scenario !== 'photo_unlinked') {
        $conn->query("INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES ({$photoUserId},{$facultyId})");
    }
    if ($scenario !== 'photo_missing') {
        $photoPath = synk_faculty_profile_image_path($photoUserId);
        $file = fopen($photoPath,'x+b');
        if (!$file) throw new RuntimeException('A photo fixture must never overwrite an existing profile image.');
        $ownedPhotoPath = $photoPath;
        $image = imagecreatetruecolor(24,24);
        imagefill($image,0,0,imagecolorallocate($image,105,108,255));
        imagejpeg($image,$file); fclose($file);
        $photoBeforeUrl = synk_faculty_profile_image_url($photoUserId);
        if ($scenario === 'photo_replaced') {
            imagefill($image,0,0,imagecolorallocate($image,255,159,0));
            imagejpeg($image,$photoPath);
            clearstatcache(true,$photoPath);
        }
        imagedestroy($image);
        $photoExpectedUrl = synk_faculty_profile_image_url($photoUserId);
    }
    if ($scenario === 'photo_inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$photoUserId}");
}
$handler = 'backend/query_college_dean_rosters.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action'=>'classes','csrf_token'=>'dean-roster-qa','faculty_id'=>$facultyId,'offering_id'=>$offeringId,
    'ay_id'=>$term['ay_id'],'semester'=>$term['semester']];
$parsed = null;
$profileBefore = null;
$originalProfiles = [];
$beforeCounts = [];
$takeCounts = static function () use ($conn): array {
    $rows = [];
    foreach (['tbl_student_management','tbl_student_management_enrolled_subjects','tbl_offering_enrollee_counts'] as $table) {
        $rows[$table] = (int)$conn->query('SELECT COUNT(*) AS n FROM ' . $table)->fetch_assoc()['n'];
    }
    return $rows;
};
$beforeCounts = $takeCounts();
if (strpos($scenario,'manual_')===0) require __DIR__.'/manual-roster-scenarios.php';
if (strpos($scenario,'clear_')===0) require __DIR__.'/clear-roster-scenarios.php';
if (strpos($scenario,'confirm') === 0) {
    if (!isset($_FILES['csv_file'])) throw new RuntimeException('The confirm fixture requires a real HTTP upload.');
    $parsed = synk_dean_roster_parse($_FILES['csv_file']['tmp_name']);
    $pending = ['user_id'=>$userId,'college_id'=>$collegeId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],
        'faculty_id'=>$facultyId,'offering_id'=>$offeringId,'expires_at'=>time()+1800,'file_name'=>'qa-roster.csv','parsed'=>$parsed];
    if ($scenario === 'confirm_existing') synk_dean_roster_save($conn,$scope,$term,$pending,$userId);
    if ($scenario === 'confirm_multisubject') {
        synk_dean_roster_save($conn,$scope,$term,$pending,$userId);
        $originalProfiles = [];
        foreach ($parsed['students'] as $student) $originalProfiles[] = synk_student_identity_find($conn,$student['student_number']);
        $other = null;
        foreach (synk_dean_workload_classes($conn,$scope,$term,$facultyId) as $candidate) {
            if ($candidate['subject_id'] !== $class['subject_id']) { $other=$candidate; break; }
        }
        if (!$other) throw new RuntimeException('The multi-subject test needs two assigned subjects for this faculty.');
        $class=$other; $offeringId=$class['offering_id']; $_POST['offering_id']=$offeringId;
        $parsed['subject_section_raw']=$class['subject_code'] . ' - ' . $class['section_display'];
        $parsed['descriptive_title']=$class['descriptive_title'];
        $pending['offering_id']=$offeringId; $pending['parsed']=$parsed;
    }
    if ($scenario === 'confirm_partial') {
        $partial = $pending; $partial['parsed']['students'] = [$parsed['students'][0]];
        synk_dean_roster_save($conn,$scope,$term,$partial,$userId);
    }
    if (in_array($scenario,['confirm_profile','confirm_missing_email','confirm_email_collision'],true)) {
        $record = $parsed['students'][0] + ['ay_id'=>$term['ay_id'],'semester'=>$term['semester'],
            'source_sheet_name'=>'QA ORIGINAL','source_file_name'=>'qa-original.csv','year_level'=>$class['year_level'],
            'email_address'=>'existing-profile-qa@sksu.edu.ph','program_id'=>$class['program_id'],'uploaded_by'=>$userId];
        if ($scenario !== 'confirm_profile') $record['email_address']='';
        $profileId = synk_student_management_insert_master_student_record($conn,$record);
        $profileBefore = $conn->query('SELECT * FROM tbl_student_management WHERE student_id=' . $profileId)->fetch_assoc();
        if ($scenario === 'confirm_email_collision') {
            $collision=$record;
            $collision['student_number']='000'.(string)(5900000000+random_int(1,10000000));
            $collision['first_name']='OtherEmailQA'; $collision['last_name']='Student';
            $collision['email_address']=synk_student_email_candidate($record)['email'];
            synk_student_identity_insert_record($conn,$collision);
        }
    }
    if ($scenario === 'confirm_failure') $conn->failRosterInsert = true;
    if ($scenario === 'confirm_removed') $conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id=' . $facultyId . ' AND schedule_id IN (SELECT schedule_id FROM tbl_class_schedule WHERE offering_id=' . $offeringId . ')');
    if ($scenario === 'confirm_expired') $pending['expires_at'] = time()-1;
    if ($scenario === 'confirm_identity') {
        $student = $parsed['students'][0];
        $record = $student + ['ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'source_sheet_name'=>'QA','source_file_name'=>'qa.csv',
            'year_level'=>$class['year_level'],'email_address'=>'','program_id'=>$class['program_id'],'uploaded_by'=>$userId];
        $record['last_name'] = 'DifferentIdentityQA';
        synk_student_management_insert_master_student_record($conn,$record);
    }
    $_SESSION['dean_roster_previews'] = ['qa-preview-token'=>$pending];
    $_POST['action'] = 'confirm'; $_POST['preview_token'] = 'qa-preview-token';
    if ($scenario === 'confirm_forged') $_POST['preview_token'] = 'forged-token';
    if ($scenario === 'confirm_tampered') $_POST['offering_id'] = 2147483647;
    if ($scenario === 'confirm_other_user') $_SESSION['dean_roster_previews']['qa-preview-token']['user_id']++;
    $beforeCounts = $takeCounts();
}
switch ($scenario) {
    case 'page': $handler='college-dean/faculty-workload.php'; break;
    case 'bootstrap': $_POST['action']='bootstrap'; break;
    case 'classes': break;
    case 'photo_bootstrap': $_POST['action']='bootstrap'; break;
    case 'photo_classes': case 'photo_missing': case 'photo_unlinked': case 'photo_inactive': case 'photo_replaced': break;
    case 'photo_other_faculty': $_POST['faculty_id']=2147483647; break;
    case 'roster': $_POST['action']='roster'; break;
    case 'preview': $_POST['action']='preview'; break;
    case 'preview_missing_file': $_POST['action']='preview'; $_FILES=[]; break;
    case 'bad_csrf': $_POST['csrf_token']='bad'; break;
    case 'other_college': $_POST['college_id']=2147483647; break;
    case 'other_class': $_POST['action']='roster'; $_POST['offering_id']=2147483647; break;
    case 'other_role': $_SESSION['role']='program_chair'; break;
    case 'revoked': $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='college_dean'"); break;
    case 'invalid_term': $_POST['ay_id']=2147483647; break;
    case 'wrong_method': $_SERVER['REQUEST_METHOD']='GET'; break;
    case 'array_input': $_POST['faculty_id']=[1]; break;
    case 'unknown_action': $_POST['action']='delete'; break;
    case 'confirm': case 'confirm_existing': case 'confirm_removed': case 'confirm_expired':
    case 'confirm_identity': case 'confirm_forged': case 'confirm_tampered': case 'confirm_other_user':
    case 'confirm_profile': case 'confirm_partial': case 'confirm_failure': case 'confirm_multisubject':
    case 'confirm_missing_email': case 'confirm_email_collision': break;
    default: throw new RuntimeException('Unknown roster fixture scenario.');
}
session_write_close();
ob_start();
register_shutdown_function(static function () use ($conn,$scope,$term,$class,$facultyId,$offeringId,$userId,$scenario,$takeCounts,$beforeCounts,$profileBefore,$originalProfiles,$photoExpectedUrl,$photoBeforeUrl,$photoUserId,$parsed): void {
    $body = '';
    while (ob_get_level() > 0) $body = ob_get_clean() . $body;
    try {
        $result = ['response'=>json_decode($body,true) ?: $body,'http_status'=>http_response_code() ?: 200,
            'target'=>$class,'before_counts'=>$beforeCounts,'after_counts'=>$takeCounts(),
            'photo_expected_url'=>$photoExpectedUrl,'photo_before_url'=>$photoBeforeUrl,'photo_user_id'=>$photoUserId,
            'pending_count'=>count($_SESSION['dean_roster_previews'] ?? []),
            'roster'=>synk_dean_roster_rows($conn,$class), 'count_map'=>synk_fetch_offering_enrollee_count_map($conn,[$offeringId]),
            'profile_unchanged'=>$profileBefore === null || $conn->query('SELECT * FROM tbl_student_management WHERE student_id=' . (int)$profileBefore['student_id'])->fetch_assoc() === $profileBefore,
            'shared_profiles_unchanged'=>true,'shared_subject_counts'=>[]];
        $result['imported_profiles']=[];
        if (isset($GLOBALS['synk_manual_roster_qa'])) $result['manual_state']=$GLOBALS['synk_manual_roster_qa']();
        if (isset($GLOBALS['synk_clear_roster_qa'])) $result['clear_state']=$GLOBALS['synk_clear_roster_qa']();
        foreach (($parsed['students'] ?? []) as $student) {
            $profile=synk_student_identity_find($conn,$student['student_number']);
            if ($profile) $result['imported_profiles'][]=$profile;
        }
        $result['original_profile']=$profileBefore;
        foreach (($originalProfiles ?? []) as $profile) {
            $current=synk_student_identity_find($conn,$profile['student_number']);
            $result['shared_profiles_unchanged'] = $result['shared_profiles_unchanged'] && $current === $profile;
            $result['shared_subject_counts'][]=(int)$conn->query('SELECT COUNT(DISTINCT subject_id) n FROM tbl_student_management_enrolled_subjects WHERE is_active=1 AND student_id=' . (int)$profile['student_id'])->fetch_assoc()['n'];
        }
        $conn->discardFixtures();
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        http_response_code(200); header('Content-Type: application/json; charset=utf-8'); echo json_encode($result);
    } catch (Throwable $e) {
        $conn->discardFixtures();
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        http_response_code(500); echo json_encode(['fixture_error'=>$e->getMessage(),'output'=>substr($body,0,1500)]);
    }
});
chdir(dirname($root . '/' . $handler));
require $root . '/' . $handler;
