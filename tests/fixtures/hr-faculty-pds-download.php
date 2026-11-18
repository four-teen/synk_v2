<?php
if (!defined('SYNK_HR_PDS_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_HR_PDS_QA,$_GET['qa'])) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../../backend/db.php'; require_once __DIR__.'/../../backend/hr_faculty_pds_helper.php';
require_once __DIR__.'/hr-faculty-pds-remaining-data.php';
$conn->begin_transaction();
session_save_path(dirname(__DIR__,2).'/temp'); session_id('hrpdsdownloadqa'.bin2hex(random_bytes(12))); session_start();
register_shutdown_function(static function () use ($conn): void {
    $conn->rollback(); $conn->close();
    if (session_status()!==PHP_SESSION_ACTIVE) session_start(); $_SESSION=[]; session_destroy();
});
$ids=[];
foreach ([1,2] as $index) {
    $email='hrdownloadqa'.bin2hex(random_bytes(6)).'@example.edu';
    $statement=$conn->prepare("INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES ('HR Download QA',?,'unusable-test-password','professor','active')");
    $statement->bind_param('s',$email); $statement->execute(); $ids[]=(int)$conn->insert_id; $statement->close();
}
$bytes='%PDF-1.4 private sample %%EOF';
$file=['name'=>'signed.pdf','mime'=>'application/pdf','size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'content'=>$bytes];
synk_hr_pds_save($conn,$ids[0],'account:'.$ids[0],'certification',hr_pds_empty_section('certification'),0,['signature_page_1'=>$file]);
$_SESSION=['user_id'=>($_GET['actor'] ?? '')==='foreign'?$ids[1]:$ids[0],'role'=>($_GET['actor'] ?? '')==='student'?'student':'professor'];
session_write_close();
require __DIR__.'/../../professor/pds-attachment.php';
