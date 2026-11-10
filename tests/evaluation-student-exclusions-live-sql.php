<?php
// Executes the exact deployment SQL in preview mode, then exercises data paths
// under savepoints. No fixture or deployment data is committed.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/evaluation_access_helper.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$sql=file_get_contents(__DIR__.'/../backend/sql/evaluation_student_exclusions_live_ccsi.sql');
$check($sql!==false,'The deployment SQL exists.');
preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',$sql,$emailMatches);
$check(array_values(array_unique($emailMatches[0]))===['ccsi@sksu.edu.ph'],'The deployment SQL targets only the requested test email.');
// The workstation accepts SELECT ... WHERE without FROM; older live MariaDB
// rejects it. Preserve the portable syntax in all three conditional inserts.
preg_match_all('/INSERT INTO tbl_(?:student_management|college_evaluation_student_exclusion_audit|college_evaluation_student_exclusions)\b[^;]*;/i',$sql,$conditionalInserts);
$check(count($conditionalInserts[0])===3,'All three table-less conditional inserts are covered by the MariaDB compatibility check.');
foreach ($conditionalInserts[0] as $insert) $check(preg_match('/\bFROM\s+DUAL\s+WHERE\b/i',$insert)===1,'A table-less conditional insert has FROM DUAL before WHERE for older MariaDB.');
$snapshot=static function()use($conn):string {
    $data=[];
    foreach (['tbl_student_management','tbl_student_management_enrolled_subjects','tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_college_evaluation_student_exclusions','tbl_college_evaluation_student_exclusion_audit','tbl_useraccount','tbl_useraccount_roles','tbl_academic_settings','tbl_subject_masterlist','tbl_sections','tbl_prospectus_offering','tbl_class_schedule','tbl_faculty_workload_sched'] as $table) $data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
};
$run=static function(string $script)use($conn):array {
    $outputs=[];
    $conn->multi_query($script);
    do {
        if ($result=$conn->store_result()) { $outputs[]=$result->fetch_all(MYSQLI_ASSOC); $result->free(); }
        if (!$conn->more_results()) break;
        $conn->next_result();
    } while (true);
    foreach ($outputs as $rows) if (isset($rows[0]['result'])) return $rows[0];
    throw new RuntimeException('No deployment result was returned.');
};
$before=$snapshot();
$conn->query('SET @synk_ccsi_apply=0');
$result=$run($sql);
$check($result['result']==='VALIDATED - rolled back (preview)','The exact deployment script validates the local live mappings: '.json_encode($result));
$check((int)$result['uniquely_matched_subjects']===3 && (int)$result['verified_enrollments']===3,'All three enrolled subjects resolve through live catalogs and workload assignments.');
$check($before===$snapshot(),'The exact preview leaves source data and the existing exclusion untouched.');
$check($conn->query("PREPARE ccsi_commit_support FROM 'COMMIT'"),'The server supports the deployment commit statement.');
$conn->query('DEALLOCATE PREPARE ccsi_commit_support');

// Keep script behavior, replacing only schema setup and transaction boundaries
// so fixtures can remain inside the enclosing transaction.
$initial=substr($sql,0,strpos($sql,'CREATE TABLE IF NOT EXISTS'));
$fixtureSql=$initial.substr($sql,strpos($sql,'DROP TEMPORARY TABLE IF EXISTS synk_ccsi_seed;'));
$fixtureSql=str_replace('START TRANSACTION;','SAVEPOINT ccsi_live_sql_request;',$fixtureSql);
$fixtureSql=str_replace("IF(@synk_ccsi_commit,'COMMIT','ROLLBACK')","'SELECT 1'",$fixtureSql);
$student=synk_evaluation_query($conn,"SELECT student_id,student_number FROM tbl_student_management WHERE email_address='ccsi@sksu.edu.ph'")[0];
$studentId=(int)$student['student_id'];
$cases=['idempotent','missing_enrollments','new_exclusion','restored_exclusion','missing_student','duplicate_email','missing_subject','missing_faculty','audit_failure'];
foreach ($cases as $case) {
    $conn->begin_transaction();
    try {
        $script=$fixtureSql;
        if ($case==='missing_enrollments') synk_evaluation_query($conn,'DELETE FROM tbl_student_management_enrolled_subjects WHERE student_id=?','i',[$studentId]);
        if ($case==='new_exclusion') synk_evaluation_query($conn,'DELETE FROM tbl_college_evaluation_student_exclusions WHERE student_id=?','i',[$studentId]);
        if (in_array($case,['restored_exclusion','audit_failure'],true)) synk_evaluation_query($conn,'UPDATE tbl_college_evaluation_student_exclusions SET excluded=0 WHERE student_id=?','i',[$studentId]);
        if ($case==='missing_student') synk_evaluation_query($conn,"UPDATE tbl_student_management SET email_address='ccsi-sql-fixture@example.test',student_number='999990000099' WHERE student_id=?",'i',[$studentId]);
        if ($case==='duplicate_email') synk_evaluation_query($conn,"INSERT INTO tbl_student_management (student_number,first_name,last_name,email_address) VALUES ('999990000098','Duplicate','Fixture','ccsi@sksu.edu.ph')");
        if ($case==='missing_subject') $script=str_replace("(1,'CPC 001'","(1,'MISSING-SUBJECT'",$script);
        if ($case==='missing_faculty') $script=str_replace("'CECILIA','ESLAVA','GENER'","'MISSING-TEACHER','ESLAVA','GENER'",$script);
        // Simulate a client continuing after an audit INSERT did not complete.
        if ($case==='audit_failure') {
            $start=strpos($script,'INSERT INTO tbl_college_evaluation_student_exclusion_audit');
            $end=strpos($script,';',$start);
            $script=substr_replace($script,'SELECT 1 /* deliberate missing audit */',$start,$end-$start);
        }
        $fixtureBefore=$snapshot();
        $conn->query('SET @synk_ccsi_apply=1');
        $result=$run($script);
        if (in_array($case,['duplicate_email','missing_subject','missing_faculty','audit_failure'],true)) {
            $check(strpos($result['result'],'NOT APPLIED')===0,'Unsafe/incomplete deployment rolls back: '.$case.' '.json_encode($result));
            $conn->query('ROLLBACK TO SAVEPOINT ccsi_live_sql_request');
            $check($fixtureBefore===$snapshot(),'A rejected deployment leaves every source record unchanged: '.$case);
        } else {
            $check($result['result']==='APPLIED' && (int)$result['verified_enrollments']===3,'Deployment applies all three subjects and the student exclusion: '.$case.' '.json_encode($result));
            $count=(int)synk_evaluation_query($conn,"SELECT COUNT(*) n FROM tbl_student_management WHERE email_address='ccsi@sksu.edu.ph'")[0]['n'];
            $check($count===1,'Only one target student exists: '.$case);
            $auditCount=(int)synk_evaluation_query($conn,'SELECT COUNT(*) n FROM tbl_college_evaluation_student_exclusion_audit WHERE student_id=?','i',[(int)$result['live_student_id']])[0]['n'];
            $studentRows=synk_evaluation_query($conn,'SELECT student_id,student_number FROM tbl_student_management WHERE student_id=?','i',[(int)$result['live_student_id']]);
            $resultAgain=$run($script);
            $check($resultAgain['result']==='APPLIED','Re-running the deployment remains valid: '.$case);
            $check($auditCount===(int)synk_evaluation_query($conn,'SELECT COUNT(*) n FROM tbl_college_evaluation_student_exclusion_audit WHERE student_id=?','i',[(int)$result['live_student_id']])[0]['n'],'An identical rerun creates no duplicate exclusion audit: '.$case);
            $check($studentRows===synk_evaluation_query($conn,'SELECT student_id,student_number FROM tbl_student_management WHERE student_id=?','i',[(int)$result['live_student_id']]),'An existing student profile remains unchanged: '.$case);
        }
    } finally { $conn->rollback(); }
    $check($before===$snapshot(),'All deployment fixture data rolled back: '.$case);
}
echo 'Student exclusion live SQL: ',$checks,' checks passed. Exact SQL preview and every fixture rolled back.',PHP_EOL;
