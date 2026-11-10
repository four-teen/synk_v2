<?php
// Run with the CLI PHP: php tests/program-chair-attention.php --http
require_once __DIR__ . '/../backend/program_chair_attention_helper.php';
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$term = ['ay_id'=>2, 'semester'=>1];
$curricula = [];
foreach ([10=>6,20=>10] as $id=>$count) {
    for ($i=1; $i<=$count; $i++) $curricula[] = ['prospectus_id'=>$id, 'effective_sy'=>'2023-2024', 'cmo_no'=>'Test curriculum',
        'ps_id'=>$id*100+$i, 'sub_id'=>$i, 'total_units'=>3, 'year_level'=>3, 'semester'=>1];
}
$member = ['student_enrollment_id'=>1, 'student_id'=>1, 'student_number'=>'QA-1', 'last_name'=>'Student', 'first_name'=>'Test',
    'middle_name'=>'', 'suffix_name'=>'', 'program_id'=>1, 'year_level'=>3, 'profile_ay_id'=>2, 'profile_semester'=>1,
    'profile_program_id'=>1, 'profile_year_level'=>3, 'subject_id'=>1, 'subject_code'=>'TEST1', 'descriptive_title'=>'Test subject',
    'section_prospectus_id'=>10, 'offering_prospectus_id'=>10, 'linked_units'=>3, 'section_display'=>'QA 3A'];
$rows = [];
for ($i=1; $i<=7; $i++) $rows[] = array_replace($member, ['student_enrollment_id'=>$i, 'subject_id'=>$i, 'subject_code'=>'TEST'.$i,
    'year_level'=>$i===7?1:3, 'section_display'=>$i===7?'QA 1A':'QA 3A']);
$review = static function (array $items, ?array $catalog = null, array $sections = []) use ($curricula, $term): array {
    return synk_chair_attention_analyze($items, $catalog ?? $curricula, $sections, 1, $term);
};
$r = $review($rows);
$check($r['flagged']===1 && $r['groups'][3][0]['units']==21 && $r['groups'][3][0]['unit_limit']==18 && $r['groups'][3][0]['excess_units']==3,
    'A third-year 21-unit student exceeds the assigned 18-unit curriculum, including a lower-year extra subject.');
$check($r['groups'][3][0]['unit_limit']!=30, 'A newer curriculum must not override the section assignment.');
$check($review(array_merge($rows, [$rows[0], array_replace($rows[0], ['section_display'=>'QA 3B'])]))['groups'][3][0]['units']==21,
    'Repeated subjects and multiple class records must not inflate credits.');
$check($review(array_slice($rows,0,6))['flagged']===0, 'Exactly the curriculum limit must not be flagged.');
$check($review(array_slice($rows,0,5))['flagged']===0, 'A load below the limit must not be flagged.');
$otherProgram=$rows; $otherProgram[6]['program_id']=2;
$check($review($otherProgram)['groups'][3][0]['units']==21, 'Subjects taken from another program still contribute to the home-program load.');
$otherStudent=array_map(static function($row){$row['profile_program_id']=2;return $row;},$rows);
$check($review($otherStudent)['students']===0, 'Taking one subject in this program must not change a student home program.');
$oldProfile=array_map(static function($row){$row['profile_ay_id']=1;return $row;},$rows);
$r=$review($oldProfile);
$check($r['incomplete']===1 && $r['flagged']===0, 'A stale profile and mixed class years need review instead of guessing a student year.');
$oldSingle=array_map(static function($row){$row['year_level']=3;return $row;},$oldProfile);
$check($review($oldSingle)['flagged']===1, 'A single year from selected-term records can resolve a stale profile.');
$ambiguous=$rows; $ambiguous[0]['section_prospectus_id']=20;
$check($review($ambiguous)['incomplete']===1 && $review($ambiguous)['flagged']===0, 'Conflicting curriculum assignments must not produce a guessed limit.');
$unlinked=$rows; $unlinked[0]['linked_units']=null;
$check($review($unlinked)['groups'][3][0]['units']==21, 'Unlinked subject units can be resolved from the assigned curriculum.');
$unknown=$rows; $unknown[6]['linked_units']=null;
$r=$review($unknown);
$check($r['incomplete']===1 && $r['flagged']===0, 'Missing extra-subject credits are incomplete rather than a false zero or all-clear.');
$partial=$rows; $partial[]=array_replace($member,['student_enrollment_id'=>8,'subject_id'=>99,'linked_units'=>null]);
$r=$review($partial);
$check($r['flagged']===1 && $r['incomplete']===1 && $r['groups'][3][0]['pending_subjects']===1,
    'A known subtotal already above the limit remains flagged with incomplete credits disclosed.');
$noBasis=array_map(static function($row){$row['section_prospectus_id']=null;$row['offering_prospectus_id']=null;return $row;},$rows);
$check($review($noBasis)['incomplete']===1, 'No assigned or offered curriculum must not silently use the latest curriculum.');
$check($review($noBasis,null,[['year_level'=>3,'section_prospectus_id'=>10,'offering_prospectus_id'=>null]])['flagged']===1,
    'A unique curriculum offered to the student year in the selected term can supply the limit.');
$badCatalog=$curricula; $badCatalog[0]['total_units']=null;
$check($review($rows,$badCatalog)['incomplete']===1 && $review($rows,$badCatalog)['flagged']===0, 'An incomplete curriculum cannot create a false low limit.');
$duplicateCatalog=$curricula; $duplicateCatalog[]=$curricula[0];
$check($review($rows,$duplicateCatalog)['groups'][3][0]['unit_limit']==18, 'Duplicate curriculum records do not inflate the limit.');
$check(synk_chair_attention_units([0,0])===0.0 && synk_chair_attention_units([null])===null
    && synk_chair_attention_units([3,4])===null && synk_chair_attention_units([-1])===null,
    'Zero units remain valid while missing, negative, and conflicting credits require review.');
$check($review([])['students']===0, 'An empty term has no students and no issues.');
foreach ([['1'], '1bad', '-1', '0', '2147483648'] as $invalid) {
    try { synk_chair_attention_program_id($invalid); $check(false, 'Invalid program filter was accepted.'); }
    catch (DomainException $exception) { $checks++; }
}
$check(synk_chair_attention_program_id(null)===0 && synk_chair_attention_program_id('2')===2, 'The initial program state and valid program filters work.');
$check(synk_chair_attention_analyze($rows,$curricula,[],1,$term,3)['flagged']===1
    && synk_chair_attention_analyze($rows,$curricula,[],1,$term,1)['students']===0,
    'The year filter uses the student year while still counting subjects from other years.');
foreach ([['3'],'9','-1','3bad'] as $invalid) {
    try { synk_chair_attention_year_level($invalid); $check(false,'Invalid year filter was accepted.'); }
    catch (DomainException $exception) { $checks++; }
}
$check(synk_chair_attention_year_level(null)===0 && synk_chair_attention_year_level('3')===3, 'Optional and valid year filters work.');

require_once __DIR__.'/../backend/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$snapshot = static function () use ($conn): array {
    return $conn->query('CHECKSUM TABLE tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects,
        tbl_sections,tbl_section_curriculum,tbl_prospectus_header,tbl_prospectus_year_sem,tbl_prospectus_subjects,
        tbl_prospectus_offering,tbl_class_schedule,tbl_faculty_workload_sched')->fetch_all(MYSQLI_ASSOC);
};
$before=$snapshot();
$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
try {
    $term=synk_fetch_current_academic_term($conn);
    $scopes=$conn->query('SELECT DISTINCT es.college_id,es.campus_id,es.program_id FROM tbl_student_management_enrolled_subjects es
        JOIN tbl_program p ON p.program_id=es.program_id AND p.college_id=es.college_id WHERE es.is_active=1')->fetch_all(MYSQLI_ASSOC);
    $dashboards=[];
    foreach ($scopes as $scope) {
        $r=synk_chair_attention_load($conn,$scope,$term,(int)$scope['program_id']);
        $scopeKey=$scope['college_id'].':'.$scope['campus_id'];
        if (!isset($dashboards[$scopeKey])) $dashboards[$scopeKey]=synk_chair_attention_dashboard($conn,$scope,$term);
        $programSummaries=array_column($dashboards[$scopeKey]['programs'],null,'program_id');
        $summary=$programSummaries[$scope['program_id']];
        $check(!$summary['unavailable'] && array_sum($summary['year_counts'])===$r['flagged'], 'Dashboard program counts match the detailed report.');
        foreach ([1,2,3,4] as $year) {
            $filtered=synk_chair_attention_load($conn,$scope,$term,(int)$scope['program_id'],$year);
            $check($summary['year_counts'][$year]===$filtered['flagged'] && !array_diff(array_keys($filtered['groups']),[$year]),
                'Each year-card count matches the year-filtered detail report.');
        }
        $check(strpos(json_encode($summary),'student_name')===false && strpos(json_encode($summary),'student_number')===false,
            'The dashboard summary returns counts without student identities.');
        foreach ($r['groups'] as $year=>$students) foreach ($students as $student) {
            $check($student['year_level']===$year && $student['excess_units']>0 && abs($student['units']-$student['unit_limit']-$student['excess_units'])<0.001,
                'Every real result is grouped in the correct year and exceeds its displayed limit.');
            $independent=synk_chair_attention_query($conn, 'SELECT es.subject_id,MAX(ps.total_units) AS units
                FROM tbl_student_management_enrolled_subjects es
                LEFT JOIN tbl_prospectus_offering o ON o.offering_id=es.offering_id AND o.program_id=es.program_id
                    AND o.section_id=es.section_id AND o.ay_id=es.ay_id AND o.semester=es.semester
                LEFT JOIN tbl_prospectus_subjects ps ON ps.ps_id=o.ps_id AND ps.sub_id=es.subject_id
                WHERE es.student_id=? AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=? AND es.is_active=1
                GROUP BY es.subject_id', 'iiiii', [$student['student_id'],$scope['college_id'],$scope['campus_id'],$term['ay_id'],$term['semester']]);
            if (!array_filter($independent,static function($row){return $row['units']===null;})) {
                $check(abs(array_sum(array_column($independent,'units'))-$student['units'])<0.001
                    && count($independent)===count($student['subjects']), 'Real enrolled credits and subject counts agree with an independent SQL aggregation.');
            }
        }
        $other=$term; $other['semester']=$term['semester']==1?2:1;
        $historical=synk_chair_attention_load($conn,$scope,$other,(int)$scope['program_id']);
        $expected=synk_chair_attention_query($conn, 'SELECT COUNT(DISTINCT student_id) AS students FROM tbl_student_management_enrolled_subjects
            WHERE is_active=1 AND college_id=? AND campus_id=? AND ay_id=? AND semester=? AND program_id=?',
            'iiiii',[$scope['college_id'],$scope['campus_id'],$other['ay_id'],$other['semester'],$scope['program_id']])[0]['students'];
        if ((int)$expected===0) $check($historical['students']===0, 'A different semester cannot include current-term students.');
    }
} finally { $conn->rollback(); }
$check($before===$snapshot(), 'Student review reads must preserve student profiles, academic settings, curriculum, schedules and workloads.');

$root=dirname(__DIR__); $http=in_array('--http',$argv,true); $route=null; $token=bin2hex(random_bytes(24)); $versions=[];
if ($http) {
    $route=$root.'/tmp/attention-qa-'.bin2hex(random_bytes(8)).'.php';
    file_put_contents($route,'<?php if (!in_array($_SERVER["REMOTE_ADDR"] ?? "",["127.0.0.1","::1"],true) || !is_string($_GET["qa"] ?? null) || !hash_equals('.var_export($token,true).',$_GET["qa"])) { http_response_code(404); exit; } define("SYNK_PROGRAM_CHAIR_QA_HTTP",true); require '.var_export($root.'/tests/fixtures/program-chair-request.php',true).';');
    register_shutdown_function(static function () use ($route): void { if (is_file($route)) unlink($route); });
}
$request=static function (string $scenario) use ($root,$http,$route,$token,&$versions): array {
    if ($http) {
        $context=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>30]]);
        $body=file_get_contents('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>$scenario]),false,$context);
    } else {
        $process=proc_open([PHP_BINARY,$root.'/tests/fixtures/program-chair-request.php',$scenario],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        $body=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process)!==0 || $errors!=='') throw new RuntimeException($scenario.': '.$errors.substr($body,0,500));
    }
    $result=json_decode($body,true);
    if (!is_array($result) || isset($result['fixture_error'])) throw new RuntimeException($scenario.': '.substr($body,0,1000));
    $versions[$result['php_version']]=true;
    return $result;
};
foreach (['page_need-attention'=>200,'attention_program'=>200,'attention_invalid'=>400,'attention_array'=>400,'attention_outside'=>400,
    'attention_term'=>200,'attention_wrong_role'=>302,'attention_unauthenticated'=>302,'attention_revoked'=>302,
    'attention_reassigned'=>400,'attention_dean_direct'=>404,'attention_query_failure'=>500,'attention_year'=>200,
    'attention_year_empty'=>200,'attention_year_invalid'=>400,'attention_year_array'=>400] as $scenario=>$status) {
    $r=$request($scenario); $body=$r['response'];
    $check($r['http_status']===$status && is_string($body) && !preg_match('/Parse error|Fatal error|<b>Warning<\/b>|xdebug-error/i',$body),
        'Page status and PHP rendering failed for '.$scenario.': '.$r['http_status']);
    $check($r['protected_unchanged'], 'Page requests must not modify schedules or workloads: '.$scenario);
    if ($status===200) {
        $check(strpos($body,'../college-dean/custom_css.css')!==false && strpos($body,'shared-script.php?file=view-term-settings.js')!==false,
            'Shared stylesheet and term-control scripts must resolve from Program Chair.');
        $check(preg_match('/class="menu-item px-2 active"[^>]*>\s*<a[^>]*href="need-attention.php"/s',$body)===1,
            'The Need Attention menu must be active inside Program Chair.');
    }
    if ($scenario==='page_need-attention') $check(strpos($body,'id="attentionResultsTitle"')===false, 'Results require choosing a program first.');
    if ($scenario==='attention_program' || $scenario==='attention_term') {
        $check(strpos($body,'id="attentionResultsTitle"')!==false && substr_count($body,'name="program_id"')===2,
            'Selected-program results render and the navbar term form retains the program selection.');
        $check(strpos($body,'name="dean_ay_id"')!==false && strpos($body,'name="dean_semester"')!==false,
            'The program filter retains the page viewing term across tabs.');
    }
    if ($scenario==='attention_query_failure') $check(strpos($body,'Unable to complete the student load review')!==false
        && strpos($body,'No students are above')===false && strpos($body,'students flagged')===false,
        'A database read failure must show a recoverable error, never false successful results.');
    if ($scenario==='attention_year' || $scenario==='attention_year_empty') {
        $year=$scenario==='attention_year'?3:1;
        $document=new DOMDocument(); @$document->loadHTML($body); $xpath=new DOMXPath($document);
        $check($xpath->query('//select[@id="attentionYear"]/option[@selected and @value="'.$year.'"]')->length===1,
            'Dashboard links preselect the requested year.');
        $check($xpath->query('//form[@id="dean-view-term-form"]/div//input[@name="year_level" and @value="'.$year.'"]')->length===1,
            'Changing the viewing term retains the selected year.');
        $check($year===3 ? strpos($body,'Year 1 <span')===false && strpos($body,'Year 4 <span')===false : strpos($body,'attention-table tbody')===false,
            'Year-filtered results exclude other student years.');
    }
    if ($scenario==='attention_wrong_role' || $scenario==='attention_unauthenticated' || $scenario==='attention_revoked' || $scenario==='attention_dean_direct') {
        $check(strpos($body,'id="attentionResultsTitle"')===false && strpos($body,'id="attentionProgram"')===false, 'Unauthorized requests must not see the report or program list.');
    }
}
foreach (['page_index','attention_dashboard_term','attention_dashboard_failure'] as $scenario) {
    $r=$request($scenario); $body=$r['response'];
    $check($r['http_status']===200 && !preg_match('/Parse error|Fatal error|<b>Warning<\/b>|xdebug-error/i',$body), 'Dashboard summary renders safely: '.$scenario);
    $check(strpos($body,'id="chairNeedAttention"')!==false && strpos($body,'student-workload.js')===false
        && strpos($body,'Suspicious Workload Review')===false && strpos($body,'student-workload.css')===false,
        'The summary replaces the former card and its dedicated assets.');
    $check($r['protected_unchanged'] && strpos($body,'id="collegeScheduleOverview"')!==false, 'The summary preserves scheduling and workloads.');
    $document=new DOMDocument(); @$document->loadHTML($body); $xpath=new DOMXPath($document);
    $links=$xpath->query('//section[@id="chairNeedAttention"]//a[@data-attention-year]');
    $check($links->length>=4 && $links->length%4===0, 'The dashboard includes first through fourth year cards for each program.');
    foreach ($links as $link) {
        parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$params);
        $check((string)$params['program_id']===$link->getAttribute('data-attention-program')
            && (string)$params['year_level']===$link->getAttribute('data-attention-year')
            && !empty($params['dean_ay_id']) && !empty($params['dean_semester']),
            'Every dashboard card carries the correct program, year and academic term.');
        if ($scenario==='attention_dashboard_failure') {
            $check(!$link->hasAttribute('data-attention-count') && strpos($link->textContent,'Counts unavailable')!==false,
                'Failed count queries show unavailable instead of false zero counts.');
        } elseif ($scenario==='attention_dashboard_term') {
            $check($link->getAttribute('data-attention-count')==='0', 'A term without memberships shows zero current issues.');
        }
    }
}
foreach (['program_chair'=>true,'college_dean'=>false] as $role=>$visible) {
    $_SESSION=['role'=>$role]; $_SERVER['PHP_SELF']='/synk/'.$role.'/need-attention.php';
    ob_start(); require $root.'/college-dean/sidebar.php'; $sidebar=ob_get_clean();
    $check((strpos($sidebar,'href="need-attention.php"')!==false)===$visible, 'Need Attention navigation is exclusive to Program Chair.');
    if ($visible) $check(strpos($sidebar,'Academic Management')<strpos($sidebar,'href="need-attention.php"')
        && strpos($sidebar,'href="need-attention.php"')<strpos($sidebar,'href="reports.php"'), 'Need Attention belongs under Academic Management.');
}
$check($before===$snapshot(), 'Page and access checks must preserve shared academic and student data.');
echo 'Need Attention: '.$checks.' checks passed; request PHP '.implode(', ',array_keys($versions)).'. Shared academic and student data unchanged.'.PHP_EOL;
