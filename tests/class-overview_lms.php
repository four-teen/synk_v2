<?php
// Aggregate card counts and statuses; all core and LMS fixtures roll back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/db_lms.php';
require_once __DIR__.'/../backend/professor_class_helper_lms.php';
require_once __DIR__.'/../backend/class_overview_helper_lms.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function(mysqli $db,string $tables):array{return $db->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);};
$coreTables='tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty,tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects';
$lmsTables='tbl_lms_grading_schemes,tbl_lms_grading_components,tbl_lms_grading_logs,tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs';
$lms=synk_database_connection_lms($conn);$coreBefore=$snapshot($conn,$coreTables);$lmsBefore=$snapshot($lms,$lmsTables);
$tablesBefore=$lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);
$conn->begin_transaction();$lms->begin_transaction();
try {
    $fixture=professor_students_fixtures($conn);
    $context=['portal'=>['faculty_id'=>$fixture['faculty_id']],'class'=>array_replace($fixture['offerings'][0],['ay_id'=>$fixture['term']['ay_id'],'semester'=>$fixture['term']['semester']])];
    $summary=synk_class_overview_summary_lms($lms,$context);
    $check($summary===['assessment_count'=>0,'grading_periods'=>['midterm'=>false,'final'=>false]],'Unconfigured classes need zero assessments and both grading periods not yet set.');
    $assessment=['grading_period'=>'midterm','assessment_type'=>'quiz','title'=>'Overview test quiz','questions'=>[]];
    $scheme=['grading_period'=>'midterm','version'=>0,'components'=>[['name'=>'Coursework','weight'=>'100']]];
    synk_assessment_save_lms($lms,$context,$assessment,$fixture['user_id']);
    synk_assessment_save_lms($lms,$context,['grading_period'=>'final','assessment_type'=>'assignment','title'=>'Overview test assignment','total_points'=>'100','questions'=>[]],$fixture['user_id']);
    $archived=synk_assessment_save_lms($lms,$context,$assessment,$fixture['user_id']);
    synk_assessment_archive_lms($lms,$context,['assessment_id'=>$archived['assessment_id'],'version'=>$archived['version']],$fixture['user_id']);
    $check(synk_class_overview_summary_lms($lms,$context)['assessment_count']===2,'The card must count both periods and assessment types and exclude archived drafts.');
    $midterm=synk_grading_save_lms($lms,$context,$scheme,$fixture['user_id']);
    $check(synk_class_overview_summary_lms($lms,$context)['grading_periods']===['midterm'=>true,'final'=>false],'Only the saved grading period is set.');
    synk_grading_save_lms($lms,$context,array_replace($scheme,['grading_period'=>'final']),$fixture['user_id']);
    $check(synk_class_overview_summary_lms($lms,$context)['grading_periods']===['midterm'=>true,'final'=>true],'Both balanced grading setups must show Set.');
    foreach (['faculty_id','offering_id','ay_id','semester'] as $field) {
        $foreign=$context;if($field==='faculty_id')$foreign['portal'][$field]++;else $foreign['class'][$field]++;
        $check(synk_class_overview_summary_lms($lms,$foreign)===['assessment_count'=>0,'grading_periods'=>['midterm'=>false,'final'=>false]],'Card summaries must not expose another '.$field.' scope.');
        synk_assessment_save_lms($lms,$foreign,$assessment,$fixture['user_id']);
        synk_grading_save_lms($lms,$foreign,$scheme,$fixture['user_id']);
    }
    $check(synk_class_overview_summary_lms($lms,$context)['assessment_count']===2,'Other professor, class, year, or semester records must not inflate the card count.');
    synk_grading_query_lms($lms,"UPDATE tbl_lms_grading_components SET status='archived' WHERE scheme_id=?",'i',[$midterm['scheme_id']]);
    $check(synk_class_overview_summary_lms($lms,$context)['grading_periods']['midterm']===false,'A grading scheme without active components must not show Set.');
    synk_grading_query_lms($lms,"UPDATE tbl_lms_grading_components SET status='active',weight_bps=9000 WHERE scheme_id=?",'i',[$midterm['scheme_id']]);
    $check(synk_class_overview_summary_lms($lms,$context)['grading_periods']['midterm']===false,'A grading scheme below 100% must not show Set.');
    $check($lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM)===$tablesBefore,'Reading card summaries must not create storage.');
} finally {$lms->rollback();$conn->rollback();}
$check($snapshot($conn,$coreTables)===$coreBefore,'Overview counts changed core accounts, academic settings, rosters, or schedules.');
$check($snapshot($lms,$lmsTables)===$lmsBefore,'Overview test records were not rolled back.');
echo 'LMS class overview: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
