<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../backend/db.php';
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):array{return $conn->query('CHECKSUM TABLE tbl_campus,tbl_college,tbl_academic_settings,tbl_faculty,tbl_college_faculty,tbl_program,tbl_prospectus_header,tbl_prospectus_year_sem,tbl_prospectus_subjects,tbl_prospectus_offering,tbl_class_schedule,tbl_faculty_workload_sched,tbl_faculty_profiles,tbl_faculty_record_profiles,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_designation,tbl_faculty_term_designations')->fetch_all(MYSQLI_ASSOC);};
$before=$snapshot();
$request=static function(string $case)use($check):array{
    $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/di-dashboard-request.php',$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    $body=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);$r=json_decode($body,true);
    if($code!==0||$errors!==''||!is_array($r))throw new RuntimeException($case.': '.$errors.substr($body,0,1000));
    $check($r['unchanged'],'Dashboard changed database records: '.$case);return $r;
};
$r=$request('all');$d=$r['data'];
$check($d['term']['ay_label']==='2091-2092'&&$d['term']['semester']===1,'The official academic term was not used.');
foreach(['programs'=>3,'subjects'=>2,'prospectuses'=>3,'sections'=>3,'classes'=>4] as $key=>$expected)$check($d['academic'][$key]===$expected,'Incorrect term academic count: '.$key);
$check($d['faculty_count']===3,'Faculty duplicates, inactive faculty, historical faculty, or mismatched workloads changed the distinct total.');
$check($d['rostered_count']===3,'Roster counts included another term, an inactive faculty record, or duplicate colleges.');
$campuses=$d['campus_rows'];
$check($campuses[$r['campus']]['teaching']===3 && $campuses[$r['campus_b']]['teaching']===1,'A faculty member in two colleges or campuses was not counted distinctly.');
$check($campuses[$r['empty_campus']]['teaching']===0,'An empty active campus must retain its zero-count card.');
$check(count($campuses)===$d['academic']['campuses'],'Campus card count differs from active campuses.');
$check($d['summary']['saved']===2 && $d['summary']['not_started']===1,'Account-owned, faculty-owned, and missing profiles were not represented correctly.');
$check($d['summary']['education']===["Master's"=>1,"Bachelor's"=>1,'Not recorded'=>1] || (count($d['summary']['education'])===3 && array_sum($d['summary']['education'])===3 && $d['summary']['education']["Master's"]===1 && $d['summary']['education']["Bachelor's"]===1),'Degree statistics are not based on the teaching cohort.');
$check(($d['summary']['ongoing']['Doctoral']??0)===1,'Ongoing doctoral study replaced the highest completed degree.');
$byFaculty=array_column($d['faculty'],null,'faculty_id');
$check($byFaculty[$r['faculty_ids']['second']]['completion']['percent']<25 && $byFaculty[$r['faculty_ids']['second']]['highest_degree']==="Bachelor's",'A valid degree from a partial professor profile was discarded.');
$check(!isset($byFaculty[$r['faculty_ids']['roster_only']])&&!isset($byFaculty[$r['faculty_ids']['history']])&&!isset($byFaculty[$r['faculty_ids']['next_semester']])&&!isset($byFaculty[$r['faculty_ids']['mismatch']])&&!isset($byFaculty[$r['faculty_ids']['stale']]),'Non-teaching or invalid-term faculty entered profile statistics.');
foreach(['education','employment','ranks','gender','civil_status'] as $key)$check(array_sum($d['summary'][$key])===3,'Profile chart denominator does not count each teaching faculty once: '.$key);
$r=$request('campus');$check($r['data']['academic']['programs']===2&&$r['data']['faculty_count']===3&&count($r['data']['campus_rows'])===1,'Campus filtering uses a home affiliation instead of teaching assignments.');
$r=$request('college');$check($r['data']['faculty_count']===1&&$r['data']['academic']['subjects']===1,'College filtering does not follow actual current-term teaching assignments.');
$r=$request('empty');$check($r['data']['faculty_count']===0&&$r['data']['summary']['total']===0&&$r['data']['academic']['programs']===0,'Empty scopes borrowed profiles or catalog activity from other campuses.');
$r=$request('next_semester');$check($r['data']['faculty_count']===1&&$r['data']['academic']['classes']===1&&$r['data']['faculty'][0]['faculty_id']===$r['faculty_ids']['next_semester'],'Changing official semester did not refresh all cohorts.');
$r=$request('missing_term');$check(!$r['data']['term_ready']&&$r['data']['faculty_count']===0&&$r['data']['academic']['classes']===0&&count($r['data']['campus_rows'])>0,'An unset official term must not fall back to all historical activity.');
foreach(['wrong_scope','array_scope'] as $case){$r=$request($case);$check($r['error']['status']===422&&$r['data']===null,'Invalid or mismatched filters were accepted: '.$case);}
$r=$request('page');$html=$r['html'];
$check($r['status']===200 && strpos($html,'id="academic"')<strpos($html,'id="campus-faculty"') && strpos($html,'id="campus-faculty"')<strpos($html,'id="qualifications"'),'Dashboard groups are missing or out of order.');
$check(strpos($html,'2091-2092 - 1st Semester')!==false&&strpos($html,'2090-2091')===false,'Dashboard accepted a URL or scheduler working-term override.');
$check(substr_count($html,'class="di-profile-row"')===3,'The faculty register duplicates shared-college faculty.');
$check(strpos($html,'25%+')===false,'The previous completion threshold still excludes teaching faculty.');
$check(strpos($html,'href="campus-faculty.php?campus_id='.$r['campus'].'"')!==false,'Campus cards do not open the faculty page.');
$r=$request('page_faculty');$html=$r['html'];
$check($r['status']===200 && substr_count($html,'data-faculty-id=')===5,'Campus page did not show all distinct current teaching faculty.');
$check(strpos($html,'Instructor I')!==false && strpos($html,'QA Current Chair')!==false && strpos($html,'Master of Science')!==false,'Campus cards omit saved rank, current designation, or completed degree name.');
$check(strpos($html,'Doctor of Philosophy')===false,'An ongoing degree replaced the highest completed degree on a card.');
$check(strpos($html,'Bachelor of Science')!==false && strpos($html,'Not recorded')!==false,'Partial and missing profiles were dropped from the campus cards.');
$check(strpos($html,'value="'.$r['college_c'].'"')===false && strpos($html,'value="'.$r['college_b'].'"')!==false,'Campus college filter exposes another campus or omits a valid college.');
preg_match_all('/data-faculty-id="(\d+)"/',$html,$matches);
$expected=array_map(static function(string $key)use($r):string{return (string)$r['faculty_ids'][$key];},['aardvark','no_profile','second','shared','zebra']);
$check($matches[1]===$expected,'Campus faculty cards are not sorted alphabetically or contain duplicate assignments.');
$r=$request('page_faculty_college');
$check($r['status']===200 && substr_count($r['html'],'data-faculty-id=')===1 && strpos($r['html'],'data-faculty-id="'.$r['faculty_ids']['shared'].'"')!==false,'College filtering did not follow campus teaching assignments.');
$check(strpos($r['html'],'college_id='.$r['college_b'])!==false,'View Details did not preserve the college filter.');
$r=$request('page_faculty_details');
$check($r['status']===200 && strpos($r['html'],'campus-faculty.php?campus_id='.$r['campus'].'&amp;college_id='.$r['college_b'])!==false,'Faculty details did not return to the selected campus and college.');
foreach(['page_faculty_invalid','page_faculty_missing','page_faculty_array'] as $case){$r=$request($case);$check($r['status']===422 && strpos($r['html'],'data-faculty-id=')===false,'Invalid campus filters exposed faculty: '.$case);}
foreach(['page_faculty_empty','page_faculty_no_term'] as $case){$r=$request($case);$check($r['status']===200 && strpos($r['html'],'No faculty to show')!==false && strpos($r['html'],'data-faculty-id=')===false,'Empty campus or missing term borrowed faculty: '.$case);}
$check($before===$snapshot(),'Dashboard test fixtures were not fully rolled back.');
echo 'DI dashboard: ',$checks,' checks passed. Reads only; fixtures rolled back.',PHP_EOL;
