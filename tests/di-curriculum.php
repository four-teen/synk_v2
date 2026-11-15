<?php
// Run: php tests/di-curriculum.php
require_once __DIR__ . '/../backend/db.php';
$checks=0;
$check=static function(bool $value,string $message) use (&$checks): void { if(!$value) throw new RuntimeException($message); $checks++; };
$snapshot=static function() use ($conn): array { return $conn->query('CHECKSUM TABLE tbl_useraccount,tbl_useraccount_roles,tbl_program,tbl_subject_masterlist,tbl_prospectus_header,tbl_prospectus_year_sem,tbl_prospectus_subjects,tbl_prospectus_offering,tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings')->fetch_all(MYSQLI_ASSOC); };
$before=$snapshot();
$request=static function(string $case) use ($check): array {
    $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/di-curriculum-request.php',$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    $body=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
    $data=json_decode($body,true);
    $expectedLog=$case==='copy_failure' && trim($errors)==='DI curriculum: Injected copy failure.';
    if($code!==0 || ($errors!=='' && !$expectedLog) || !is_array($data) || isset($data['fixture_error'])) throw new RuntimeException($case.': '.$errors.substr($body,0,1200));
    $check($data['protected_unchanged'],'Schedules, offerings, workloads, students, section assignments or settings changed: '.$case);
    $check($data['session_unchanged'],'DI role or scheduling context changed: '.$case);
    return $data;
};
foreach(['manage-programs','manage-subject-masterlist','manage-prospectus','manage-prospectus-browser','view-prospectus'] as $page) {
    $r=$request('page_'.$page); $html=$r['body'];
    $check($r['status']===200 && is_string($html) && !preg_match('/Fatal error|Parse error|<b>Warning|<b>Notice/i',$html),'DI page failed: '.$page);
    $check(strpos($html,'aria-label="DI navigation"')!==false && strpos($html,'Director for Instruction')!==false,'DI navigation missing: '.$page);
    $check(strpos($html,'query_di_')!==false && strpos($html,'../backend/query_prospectus.php')===false,'DI page used an Admin API: '.$page);
    if($page==='manage-prospectus-browser') $check(strpos($html,'id="filterCampus"')!==false && strpos($html,'id="filterCollege"')!==false && strpos($html,'related workload records')===false,'DI browser scope or safe deletion copy is incorrect.');
    if($page==='manage-prospectus') {
        $check(stripos($html,$r['tag'].' Other Program')!==false,'DI builder must include other colleges.');
        foreach(['programProspectusRows','loadedProspectusCard','createProspectusModal','copy_source_label','btnCreateProspectus'] as $id) $check(strpos($html,'id="'.$id.'"')!==false,'DI builder omitted the Program Chair workflow: '.$id);
        $check(strpos($html,'id="btnSaveHeader"')===false && strpos($html,'id="loadProspectusModal"')===false,'DI retained the old header form and load modal.');
        $check(strpos($html,'query_program_chair_')===false && strpos($html,'../program-chair/prospectus-builder.js?')!==false,'DI did not reuse the controller through DI endpoints.');
        $check(strpos($html,'prospectus-structure.css?')!==false && strpos($html,'prospectus-structure.js?')!==false && strpos($html,'data-di-structure-expand="hide"')!==false,'DI semester layout assets or display controls are missing.');
    }
    preg_match_all('/<script(?![^>]*\btype="application\/json")(?:\s[^>]*)?>(.*?)<\/script>/s',$html,$scripts);
    $tmp=dirname(__DIR__).'/tmp/di-rendered-'.bin2hex(random_bytes(6)).'.js'; file_put_contents($tmp,implode("\n",$scripts[1]));
    $output=[]; exec('node --check '.escapeshellarg($tmp).' 2>&1',$output,$status); unlink($tmp);
    $check($status===0,'DI rendered JavaScript failed: '.implode("\n",$output));
}
foreach(['load_programs','load_subjects','load_header','load_year_sem','load_subject_for_edit','get_all_subjects','load_prospectus_list','list_headers','load_browser_filters','load_browser_prospectuses','load_prospectus_by_program','view'] as $case) {
    $r=$request($case); $check($r['status']===200 && $r['unchanged'],'DI curriculum browsing failed or changed data: '.$case);
    $check(is_array($r['body']) || (is_string($r['body']) && strpos($r['body'],'ERROR|')===false),'Read response failed: '.$case);
}
$r=$request('structure_layout');
$check($r['status']===200 && $r['unchanged'],'Semester layout read changed curriculum records.');
$dom=new DOMDocument(); @$dom->loadHTML($r['body']); $xpath=new DOMXPath($dom);
$check($xpath->query('//section[contains(@class,"di-structure-year")]')->length===2,'Existing semester groups were not grouped by their saved year.');
$check($xpath->query('//article[@data-semester]')->length===4 && $xpath->query('//article[@data-semester="3"]')->length===1,'Missing semesters were created or the saved Summer was omitted.');
$check($xpath->query('//div[contains(@class,"accordion-collapse") and contains(concat(" ",@class," ")," show ")]')->length===4 && $xpath->query('//*[@data-bs-parent]')->length===0,'Semester panels did not start independently expanded.');
$check($xpath->query('//button[contains(@class,"btnAddSubject")]')->length===4 && $xpath->query('//button[contains(@class,"btnEditSubject")]')->length===2 && $xpath->query('//button[contains(@class,"btnDeleteSubject")]')->length===2 && $xpath->query('//button[contains(@class,"btnDeleteYearSem")]')->length===0,'DI subject actions are missing or year/semester removal remains available.');
$check($xpath->query('//div[contains(@class,"di-semester-footer")]')->length===0 && strpos($r['body'],'Total Units:')===false,'The extra semester footer remains visible.');
foreach($xpath->query('//tbody/tr[td/span[contains(@class,"di-subject-code")]]') as $row) {
    $hours=[]; foreach($xpath->query('td[contains(@class,"di-subject-hours")]',$row) as $cell) $hours[]=trim($cell->textContent);
    $check($hours===['3','3','4'],'The layout changed lecture/lab conversion or units.');
}
$check(stripos($r['body'],'<script>')===false && stripos($r['body'],'&lt;script&gt;')!==false && strpos($r['body'],'&lt;b&gt;unsafe&lt;/b&gt;')!==false,'Subject title or prerequisites were not escaped.');
$check(strpos($r['body'],'di-structure-year-2')===false && strpos($r['body'],'di-structure-year-3')!==false,'A missing year was created or a later saved year disappeared.');
$r=$request('structure_empty');
$check($r['status']===200 && $r['unchanged'] && strpos($r['body'],'No subjects encoded yet')!==false && strpos($r['body'],'<tfoot>')!==false,'An empty saved semester or its table totals were hidden.');
$r=$request('structure_none');
$check($r['status']===200 && $r['unchanged'] && strpos($r['body'],'No year & semester entries yet')!==false && strpos($r['body'],'data-semester=')===false,'An empty prospectus was populated by the layout.');
foreach(['save_program','update_program','delete_program','used_delete_program','program_reactivate','save_subject','update_subject','delete_subject','save_header','save_year_sem','save_prospectus_subject','update_prospectus_subject','delete_prospectus_subject','update_prospectus_header','transfer_prospectus','delete_prospectus','copy_prospectus','used_copy_prospectus'] as $case) {
    $r=$request($case);
    $check($r['status']===200 && !$r['unchanged'] && preg_match('/^(?:OK\||success$|updated$|deleted$)/',(string)$r['body']),'DI curriculum write failed: '.$case.' '.json_encode($r['body']));
    if($case==='save_prospectus_subject') {
        $row=end($r['subjects']);
        $check((float)$row['lec_units']===3.0 && (float)$row['lab_units']===3.0 && (float)$row['total_units']===4.0,'Lecture/lab conversion changed.');
        $check(json_decode($row['prerequisite_sub_ids'],true)===[(string)$r['sub_a']],'Prerequisite IDs were not preserved.');
    }
    if($case==='copy_prospectus') $check(count($r['headers'])===2 && count($r['subjects'])===2 && (int)end($r['headers'])['program_id']===$r['target_program'],'Cross-college copying did not preserve the original and create a new version.');
    if($case==='delete_prospectus') $check(count($r['headers'])===0 && count($r['subjects'])===0,'Unused prospectus deletion left curriculum rows.');
    if($case==='delete_prospectus_subject') $check(count($r['headers'])===1 && count($r['subjects'])===0,'Subject deletion removed the prospectus or failed to remove the selected subject.');
    if(in_array($case,['delete_program','used_delete_program'],true)) {
        $id=$case==='delete_program'?$r['unused_program']:$r['program'];
        $programs=array_column($r['programs'],null,'program_id');
        $check(count($r['programs'])===3 && isset($programs[$id]) && $programs[$id]['status']==='inactive','Program deletion must only set its status inactive.');
        $check(count($r['headers'])===1 && count($r['subjects'])===1,'Program deactivation changed its prospectus or subjects.');
    }
    if($case==='program_reactivate') $check(array_column($r['programs'],null,'program_id')[$r['program']]['status']==='active','An inactive program could not be reactivated.');
}
$rejections=['anonymous'=>403,'wrong_role'=>403,'revoked'=>403,'inactive'=>403,'method'=>405,'array_input'=>400,'multiple_actions'=>400,'bad_hours'=>422,'self_prerequisite'=>422,'duplicate_program'=>409,'duplicate_subject'=>409,'copy_failure'=>500,
    'section_delete_prospectus'=>409,'section_subject_delete_year_sem'=>403,'saved_update_prospectus_subject'=>409,
    'delete_year_sem'=>403,'used_delete_year_sem'=>403,
    'programs_missing_campus'=>422,'programs_wrong_college'=>422,'programs_foreign_college'=>422,'program_wrong_campus'=>422];
foreach(['save_year_sem','save_prospectus_subject','update_prospectus_subject','delete_prospectus_subject','update_prospectus_header','transfer_prospectus','delete_prospectus','update_program','delete_subject','update_subject'] as $action) $rejections['used_'.$action]=409;
foreach(['save_program','update_program','delete_program','save_subject','update_subject','delete_subject','save_header','save_year_sem','copy_prospectus','delete_prospectus'] as $action) $rejections['csrf_'.$action]=403;
foreach(['load_header','load_browser_filters','copy_prospectus'] as $action) $rejections['direct_'.$action]=403;
foreach($rejections as $case=>$status) {
    $r=$request($case); $check($r['status']===$status && $r['unchanged'] && strpos((string)$r['body'],'ERROR|')===0,'Rejected request changed records or returned the wrong status: '.$case.' '.json_encode($r));
}
require_once __DIR__.'/../backend/di_program_helper.php';
foreach(['programs_campus','programs_college','programs_inactive'] as $case) {
    $r=$request($case); $body=$r['body'];
    $check($r['status']===200 && $r['unchanged'] && is_array($body),'Scoped program browsing failed: '.$case);
    $collegeMap=array_column(synk_di_curriculum_query($conn,'SELECT college_id,campus_id FROM tbl_college'),null,'college_id');
    $check(count(array_filter($body['rows'],static function($row)use($collegeMap,$r):bool{return (int)$collegeMap[$row['college_id']]['campus_id']!==$r['campus'];}))===0,'A program from another campus leaked into the list.');
    if($case==='programs_college') $check(count(array_filter($body['rows'],static function($row)use($r):bool{return (int)$row['college_id']!==$r['college'];}))===0 && $body['summary']['colleges']===1,'College filtering or college count is incorrect.');
    $check($body['summary']['programs']===count($body['rows']) && $body['summary']['active']+$body['summary']['inactive']===count($body['rows']),'Program summary does not match the list.');
    $check($body['summary']['prospectuses']===array_sum(array_column($body['rows'],'prospectus_count')),'Prospectus count is multiplied by subjects or versions.');
    $check($body['summary']['subjects']>=1,'Linked subjects were not counted.');
    if($case==='programs_inactive') $check(array_column($body['rows'],null,'program_id')[$r['unused_program']]['status']==='inactive','Inactive programs disappeared from the list.');
}
$check($before===$snapshot(),'Database fixtures were not fully rolled back.');
echo 'DI curriculum: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
