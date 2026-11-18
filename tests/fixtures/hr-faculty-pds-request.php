<?php
// Only temporary loopback routes with an unpredictable token can invoke this fixture.
if (!defined('SYNK_HR_PDS_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_HR_PDS_QA,$_GET['qa'])) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../../backend/db.php';
require_once __DIR__.'/../../backend/hr_faculty_pds_controller.php';
class HrPdsHttpQAConnection extends mysqli {
    public $requestMode=false;
    public function begin_transaction($flags=0,$name=null): bool { return $this->requestMode ? $this->query('SAVEPOINT hr_pds_http_request') : ($name===null ? parent::begin_transaction($flags) : parent::begin_transaction($flags,$name)); }
    public function commit($flags=0,$name=null): bool { return $this->requestMode ? $this->query('RELEASE SAVEPOINT hr_pds_http_request') : ($name===null ? parent::commit($flags) : parent::commit($flags,$name)); }
    public function rollback($flags=0,$name=null): bool { return $this->requestMode ? $this->query('ROLLBACK TO SAVEPOINT hr_pds_http_request') : ($name===null ? parent::rollback($flags) : parent::rollback($flags,$name)); }
}
$conn->close(); $conn=new HrPdsHttpQAConnection($servername,$username,$password,$dbase); $conn->set_charset('utf8mb4'); $conn->begin_transaction();
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private');
try {
    $email='hrpdsqa'.bin2hex(random_bytes(6)).'@example.edu';
    $statement=$conn->prepare("INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES ('HR PDS HTTP QA',?,'unusable-test-password','professor','active')");
    $statement->bind_param('s',$email); $statement->execute(); $userId=(int)$conn->insert_id; $statement->close();
    $snapshot=static function () use ($conn,$userId): string {
        $row=$conn->query('SELECT * FROM tbl_useraccount WHERE user_id='.$userId)->fetch_assoc();
        return hash('sha256',json_encode($row));
    };
    $before=$snapshot();
    $session=['user_id'=>$userId,'role'=>'professor','faculty_profile_csrf'=>SYNK_HR_PDS_QA];
    if (($_GET['case'] ?? '')==='wrong_role') $session['role']='student';
    $multipart=stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''),'multipart/form-data')===0;
    $payload=json_decode($multipart?($_POST['payload'] ?? ''):file_get_contents('php://input'),true);
    if (is_array($payload) && ($payload['owner'] ?? '')==='account:1') $payload['owner']='account:'.$userId;
    $conn->requestMode=true;
    if (($_GET['case'] ?? '')==='real_endpoint' && $multipart) {
        session_save_path(dirname(__DIR__,2).'/temp'); session_id('hrpdsuploadqa'.bin2hex(random_bytes(12))); session_start();
        $_SESSION=$session; session_write_close();
        register_shutdown_function(static function (): void { if (session_status()!==PHP_SESSION_ACTIVE) session_start(); $_SESSION=[]; session_destroy(); });
        $_POST['payload']=json_encode($payload);
        ob_start(); require __DIR__.'/../../professor/pds-save.php'; $endpointBody=ob_get_clean();
        $response=['status'=>http_response_code(),'body'=>json_decode($endpointBody,true)];
    } else $response=synk_hr_pds_handle_save($conn,$session,$payload,$multipart?$_FILES:[]);
    http_response_code($response['status']);
    $state=synk_hr_pds_load($conn,$userId,0);
    header('X-QA-Account-Unchanged: '.($before===$snapshot()?'yes':'no'));
    header('X-QA-Personal-Revision: '.(int)($state['personal']['revision'] ?? 0));
    header('X-QA-Contact-Revision: '.(int)($state['contact']['revision'] ?? 0));
    foreach (synk_hr_pds_sections() as $section) {
        header('X-QA-'.ucfirst($section).'-Revision: '.(int)($state[$section]['revision'] ?? 0));
        if (($response['body']['ok'] ?? false) && ($response['body']['section'] ?? '')===$section) {
            header('X-QA-Records-Reloaded: '.($state[$section]['values']===$response['body']['values']?'yes':'no'));
        }
    }
    echo isset($endpointBody)?$endpointBody:json_encode($response['body'],JSON_UNESCAPED_UNICODE);
} finally { $conn->requestMode=false; $conn->rollback(); $conn->close(); }
