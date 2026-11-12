<?php
// LMS grade sheet checks. All database fixtures roll back.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/db_lms.php';
require_once __DIR__.'/../backend/professor_class_helper_lms.php';
require_once __DIR__.'/../backend/gradesheet_helper_lms.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void {if (!$ok) throw new RuntimeException($message);$checks++;};
$check(synk_gradesheet_name_lms(['last_name'=>'Dela Cruz','first_name'=>'Ana Maria','middle_name'=>'Peña Reyes','suffix_name'=>'Jr.'])==='DELA CRUZ, ANA MARIA JR. P. R.','Family, given name, suffix and middle initials must retain the real name.');
$check(synk_gradesheet_name_lms(['full_name'=>'Student without name parts'])==='STUDENT WITHOUT NAME PARTS','Missing name parts must retain the roster display name.');
$check(synk_gradesheet_number_lms(null)==='' && synk_gradesheet_number_lms(null,true)==='','An unconfigured grade must print blank.');
$check(synk_gradesheet_number_lms(0,true)==='0.00%' && synk_gradesheet_number_lms(0)==='0.00','An actual zero must never print blank.');
$check(synk_gradesheet_number_lms(81.254,true)==='81.25%' && synk_gradesheet_number_lms(2.5)==='2.50','Printed precision must match the gradebook.');
$fake=['roster'=>[],'summary'=>[]];$metadata=['students'=>[],'sex'=>[]];
for ($i=1;$i<=40;$i++) {
    $fake['roster'][]=['student_id'=>$i,'full_name'=>sprintf('Student %02d',$i)];
    $fake['summary'][$i]=['midterm'=>null,'final'=>0,'average'=>null,'rating'=>null,'remark'=>'—'];
    if ($i<=20) $metadata['sex'][$i]='male';
    elseif ($i<=35) $metadata['sex'][$i]='female';
}
$sheet=synk_gradesheet_build_lms($fake,$metadata);
$check(count($sheet['rows'])===40 && $sheet['rows'][0]['student_id']===1 && $sheet['rows'][39]['student_id']===40,'All ordered student rows must reach the print layout without fixed-size page chunks.');
$check($sheet['counts']===['male'=>20,'female'=>15,'unknown'=>5,'total'=>40],'Unknown sex must stay distinct from recorded male/female counts.');
$check(count(array_unique(array_column($sheet['rows'],'student_id')))===40,'Each student must occur exactly once.');
$check($sheet['rows'][0]['midterm']===null && $sheet['rows'][0]['final']===0 && $sheet['rows'][0]['remark']==='','Missing grading schemes must not create a semester rating or remark.');
$empty=synk_gradesheet_build_lms(['roster'=>[],'summary'=>[]],['students'=>[],'sex'=>[]]);
$check($empty['rows']===[] && $empty['counts']['total']===0,'An empty roster must still supply a printable zero-student summary.');
$coreTables='tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty';
$lmsTables='tbl_lms_gradebook_periods,tbl_lms_gradebook_items,tbl_lms_gradebook_scores,tbl_lms_gradebook_logs,tbl_lms_grading_schemes,tbl_lms_grading_components,tbl_lms_grading_logs';
$snapshot=static function(mysqli $db,string $tables):array {return $db->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);};
$lms=synk_database_connection_lms($conn);$coreBefore=$snapshot($conn,$coreTables);$lmsBefore=$snapshot($lms,$lmsTables);
$originalSession=$_SESSION??[];$conn->begin_transaction();$lms->begin_transaction();
try {
    $fixture=professor_students_fixtures($conn);
    $_SESSION=['user_id'=>$fixture['user_id'],'role'=>'professor'];
    $params=[];parse_str((string)parse_url(synk_professor_class_url_lms(['faculty_id'=>$fixture['faculty_id'],'offering_id'=>$fixture['offerings'][0]['offering_id'],'ay_id'=>$fixture['term']['ay_id'],'semester'=>$fixture['term']['semester']],'gradebook'),PHP_URL_QUERY),$params);
    $context=synk_professor_class_context_lms($conn,$params);
    $student=(int)$fixture['students']['active'];
    foreach (['midterm'=>80,'final'=>90] as $period=>$points) {
        $scheme=synk_grading_save_lms($lms,$context,['grading_period'=>$period,'version'=>0,'components'=>[['name'=>'Exam','weight'=>100]]],$fixture['user_id']);
        $lms->query("INSERT INTO tbl_lms_gradebook_periods (faculty_id,offering_id,ay_id,semester,grading_period,scheme_id,scheme_version,scheme_snapshot_json,version,created_at,updated_at) VALUES (".(int)$fixture['faculty_id'].','.(int)$context['class']['offering_id'].','.(int)$context['class']['ay_id'].','.(int)$context['class']['semester'].",'".$period."',".(int)$scheme['scheme_id'].",1,'".$lms->real_escape_string(json_encode($scheme))."',1,NOW(),NOW())");
        $bookId=(int)$lms->insert_id;
        $lms->query("INSERT INTO tbl_lms_gradebook_items (book_id,component_id,title,entry_kind,max_points,created_at,updated_at) VALUES (".$bookId.','.(int)$scheme['components'][0]['component_id'].",'Exam','score',100,NOW(),NOW())");
        $itemId=(int)$lms->insert_id;
        $lms->query('INSERT INTO tbl_lms_gradebook_scores (item_id,student_id,points,remark,actor_user_id,updated_at) VALUES ('.$itemId.','.$student.','.$points.", 'Recorded exam',".(int)$fixture['user_id'].',NOW())');
    }
    $workspace=synk_gradebook_workspace_lms($conn,$lms,$context);
    $meta=synk_gradesheet_metadata_lms($conn,$context,$workspace['roster']);
    $sheet=synk_gradesheet_build_lms($workspace,$meta);$rows=array_column($sheet['rows'],null,'student_id');
    $check(count($rows)===count($workspace['roster']) && count($rows)===3,'Printed membership must match the exact authorized gradebook roster.');
    $check($rows[$student]['midterm']==80 && $rows[$student]['final']==90 && $rows[$student]['average']==85 && $rows[$student]['rating']==2.25 && $rows[$student]['remark']==='Passed','The print report must retain real period totals, 50/50 average, numerical rating and remark.');
    foreach ((array)$workspace['summary'] as $id=>$grade) foreach (['midterm','final','average','rating','remark'] as $field) {
        $check($rows[$id][$field]===$grade[$field],'A printed field differs from the LMS summary: '.$field);
    }
    $check(!isset($rows[(int)$fixture['students']['foreign']]),'A student from another class must not appear.');
    $check($meta['students'][$student]['program_section']!=='','Program/year/section must come from class enrollment.');
    $check(strpos($rows[$student]['name'],',')!==false,'The printed name must use the template family-first format.');
    $bad=$params;$bad['offering_id']=(string)$fixture['offerings'][2]['offering_id'];
    try {synk_professor_class_context_lms($conn,$bad);$check(false,'A modified signed offering was accepted.');}
    catch (DomainException $error) {$check($error->getCode()===403,'A modified signed offering must be rejected.');}
} finally {$lms->rollback();$conn->rollback();$_SESSION=$originalSession;}
$check($snapshot($conn,$coreTables)===$coreBefore,'Grade sheet checks changed shared student, scheduling, account or academic data.');
$check($snapshot($lms,$lmsTables)===$lmsBefore,'Grade sheet fixtures did not roll back.');
echo 'LMS grade sheet: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
