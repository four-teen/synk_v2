<?php
// php tests/scheduler-term-lists.php -- HTTP fixtures use savepoints and roll back.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
$checks=0;
$check=static function(bool $ok,string $message) use(&$checks):void { if(!$ok)throw new RuntimeException($message);$checks++; };
$snapshot=static function() use($conn):string {
    $rows=[]; foreach(['tbl_college','tbl_useraccount','tbl_useraccount_roles','tbl_faculty','tbl_college_faculty','tbl_rooms','tbl_room_college_access','tbl_faculty_workload_sched','tbl_class_schedule','tbl_academic_settings','tbl_designation','tbl_faculty_term_designations'] as $table) $rows[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($rows));
};
$before=$snapshot();$token=bin2hex(random_bytes(24));$route=__DIR__.'/../tmp/scheduler-lists-qa-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_SCHEDULER_LIST_QA\','.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/scheduler-term-lists-request.php',true).';');
$request=static function(string $scenario,bool $authorized=true) use($route,$token):array {
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$authorized?$token:'wrong','scenario'=>$scenario]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));
    $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $headers=substr($raw,0,$size);$body=substr($raw,$size);$decoded=json_decode($body,true);
    return ['status'=>$status,'headers'=>$headers,'body'=>$body,'data'=>$decoded];
};
$header=static function(array $response,string $name) {
    preg_match('/'.preg_quote($name,'/').': ([^\r\n]+)/i',$response['headers'],$m);
    return isset($m[1]) ? json_decode(rawurldecode($m[1]),true) : null;
};
try {
    $check($request('faculty_preview',false)['status']===404,'Fixture token is required.');
    foreach(['faculty','rooms'] as $kind) {
        $r=$request($kind.'_options');
        $check($r['status']===200 && ($r['data']['status']??'')==='ok' && count($r['data']['terms'])===1,'Options include previous terms for this college: '.$kind.' '.substr($r['body'],0,800));
        $r=$request($kind.'_preview');$rows=$r['data']['rows']??[];
        $check($r['status']===200 && count(array_filter($rows,static function($row){return $row['eligible'];}))===2,'Preview selects only missing available records: '.$kind.' '.substr($r['body'],0,1800));
        $check(strpos($r['headers'],'X-QA-Lists-Unchanged: yes')!==false,'Preview does not copy or remove list entries: '.$kind);
        $check(strpos($r['headers'],'X-QA-Master-Unchanged: yes')!==false && strpos($r['headers'],'X-QA-Previous-Unchanged: yes')!==false,'Preview leaves master records and previous terms untouched: '.$kind);
    }
    $r=$request('faculty_copy');$ids=$header($r,'X-QA-Faculty-Ids');$rows=array_column($header($r,'X-QA-Faculty-Rows'),null,'faculty_id');
    $check($r['status']===200 && $r['data']['copied']===2 && isset($rows[$ids['first']]) && $rows[$ids['second']]['status']==='active','Selected faculty are copied, including a previously removed legacy entry.');
    $check(count($rows)===3 && !isset($rows[$ids['unavailable']]) && !isset($rows[$ids['foreign']]),'Faculty copying skips duplicates and excludes unselected or foreign faculty.');
    $check(strpos($r['headers'],'X-QA-Master-Unchanged: yes')!==false && strpos($r['headers'],'X-QA-Previous-Unchanged: yes')!==false,'Copying faculty changes neither faculty records nor earlier lists or schedules.');
    $r=$request('faculty_one_copy');$ids=$header($r,'X-QA-Faculty-Ids');$rows=array_column($header($r,'X-QA-Faculty-Rows'),null,'faculty_id');
    $check($r['status']===200 && $r['data']['copied']===1 && $rows[$ids['first']]['status']==='active' && $rows[$ids['second']]['status']==='inactive','An unchecked faculty entry is not added to the current list.');
    $r=$request('faculty_legacy_master_copy');$ids=$header($r,'X-QA-Faculty-Ids');$rows=array_column($header($r,'X-QA-Faculty-Rows'),null,'faculty_id');
    $check($r['status']===200 && $r['data']['copied']===1 && isset($rows[$ids['first']]),'Faculty copying supports master tables without the unused college_id column. '.substr($r['body'],0,600));
    $check(strpos($r['headers'],'X-QA-Master-Unchanged: yes')!==false && strpos($r['headers'],'X-QA-Previous-Unchanged: yes')!==false,'Legacy-master copying preserves master records, source lists, and earlier appointments.');
    foreach (['faculty_missing_designations_copy','faculty_incomplete_designations_copy'] as $scenario) {
        $r=$request($scenario);
        $check($r['status']===409 && strpos($r['data']['message']??'','term-designation setup')!==false && strpos($r['data']['message']??'','Nothing was copied.')!==false,'Incomplete storage reports a setup message: '.$scenario.' '.substr($r['body'],0,600));
        $check(strpos($r['headers'],'X-QA-Lists-Unchanged: yes')!==false && strpos($r['headers'],'X-QA-Master-Unchanged: yes')!==false && strpos($r['headers'],'X-QA-Previous-Unchanged: yes')!==false,'Missing storage leaves all lists and prior records untouched: '.$scenario);
    }
    $r=$request('faculty_designation_failure_copy');
    $check($r['status']===500 && strpos($r['headers'],'X-QA-Lists-Unchanged: yes')!==false && strpos($r['headers'],'X-QA-Previous-Unchanged: yes')!==false,'A failed designation copy rolls back faculty-list inserts.');
    $r=$request('rooms_copy');$ids=$header($r,'X-QA-Room-Ids');$rows=$header($r,'X-QA-Room-Rows');
    $check($r['status']===200 && $r['data']['copied']===1 && count($rows)===2,'Only the selected room becomes available in the current term.');
    $check((int)$rows[0]['room_id']===$ids['first'] && $rows[0]['access_type']==='owner','Copied room access stays with its owner college.');
    $check(strpos($r['headers'],'X-QA-Master-Unchanged: yes')!==false && strpos($r['headers'],'X-QA-Previous-Unchanged: yes')!==false,'Rooms are reused without duplicating masters, previous sharing, or scheduled classes.');
    $r=$request('late_duplicate');$check($r['status']===200 && $r['data']['copied']===0 && $r['data']['skipped']===1,'An entry added after preview is skipped without duplication.');
    foreach(['foreign_id'=>403,'rooms_borrowed_copy'=>403,'source_changed'=>409,'term_changed'=>409,'token_expired'=>409,'replay'=>409,'invalid_source'=>422,'foreign_college'=>403,'bad_csrf'=>403,'wrong_role'=>401,'revoked'=>403,'inactive_actor'=>403,'legacy_rooms_copy'=>409] as $scenario=>$status) {
        $r=$request($scenario);$check($r['status']===$status && strpos($r['headers'],'X-QA-Lists-Unchanged: yes')!==false,'Invalid copying is rejected without changing lists: '.$scenario.' HTTP '.$r['status'].' '.substr($r['body'],0,500));
    }
    $r=$request('remove');$ids=$header($r,'X-QA-Faculty-Ids');$rows=array_column($header($r,'X-QA-Faculty-Rows'),null,'faculty_id');
    $check($r['status']===200 && $r['data']['status']==='removed' && !isset($rows[$ids['existing']]),'Removal deletes the current list entry.');
    $check(strpos($r['headers'],'X-QA-Master-Unchanged: yes')!==false && strpos($r['headers'],'X-QA-Previous-Unchanged: yes')!==false,'Removal preserves the faculty record, its status, workloads, and previous-term membership.');
    foreach(['remove_old'=>409,'remove_foreign'=>403] as $scenario=>$status) { $r=$request($scenario);$check($r['status']===$status && strpos($r['headers'],'X-QA-Lists-Unchanged: yes')!==false,'Removal cannot target another term or college: '.$scenario); }
    $r=$request('list');$check($r['status']===200 && count($r['data']['data']??[])===1,'Removed legacy assignments are excluded from the list.');
    foreach (['designation_remove'=>0,'designation_set'=>6] as $scenario=>$units) {
        $r=$request($scenario); $rows=$header($r,'X-QA-Term-Designations'); $ids=$header($r,'X-QA-Faculty-Ids');
        $records=array_values(array_filter($rows??[],static function($row)use($ids){return (int)$row['faculty_id']===$ids['existing'];}));
        $check($r['status']===200 && ($r['data']['status']??'')==='updated','A designation can be saved or removed: '.$scenario.' '.substr($r['body'],0,700));
        $check(count($records)===2 && (float)$records[0]['designation_units']===12.0 && (float)$records[1]['designation_units']===(float)$units,'Only the current semester changes: '.$scenario);
        $check(strpos($r['headers'],'X-QA-Master-Unchanged: yes')!==false && strpos($r['headers'],'X-QA-Previous-Unchanged: yes')!==false,'Designation changes preserve master records and historical appointments.');
        if($units===0)$check($r['data']['designation_id']===0 && $r['data']['designation_name']==='' && $records[1]['designation_id']===null,'Removing a designation persists an explicit empty appointment.');
    }
    foreach(['designation_old'=>409,'designation_foreign'=>403,'designation_removed_membership'=>403,'designation_invalid'=>400,'designation_array'=>400,'designation_inactive'=>422,'designation_bad_csrf'=>403,'designation_revoked'=>403] as $scenario=>$status) {
        $r=$request($scenario);$check($r['status']===$status && strpos($r['headers'],'X-QA-Previous-Unchanged: yes')!==false,'Invalid designation changes leave previous terms untouched: '.$scenario.' HTTP '.$r['status'].' '.substr($r['body'],0,400));
    }
    $r=$request('designation_list');$check($r['status']===200 && $r['data']['data'][0]['designation_id']===0 && $r['data']['data'][0]['designation_name']==='','The current list shows No designation instead of falling back to the global faculty value.');
    foreach(['designation_report_current'=>0,'designation_report_old'=>12,'designation_catalog_changed'=>12] as $scenario=>$units) {
        $r=$request($scenario);$summary=$r['data']['faculty'][0]??[];
        $check($r['status']===200 && (float)($summary['designation_units']??-1)===(float)$units,'Workload totals use the saved term units, including renamed or deactivated catalog entries: '.$scenario.' '.substr($r['body'],0,700));
        if($units===12)$check(strpos($summary['designation_name']??'','QA dean ')===0,'Historical reports retain the original designation name.');
    }
    $r=$request('faculty_one_copy');$ids=$header($r,'X-QA-Faculty-Ids');$records=array_values(array_filter($header($r,'X-QA-Term-Designations')??[],static function($row)use($ids){return (int)$row['faculty_id']===$ids['first'];}));
    $check($r['status']===200 && count($records)===2 && (float)$records[1]['designation_units']===6.0 && $records[1]['record_source']==='copied','Copying a previous faculty list carries the source appointment to the new semester.');
    $r=$request('faculty_copy_keeps_designation');$ids=$header($r,'X-QA-Faculty-Ids');$records=array_values(array_filter($header($r,'X-QA-Term-Designations')??[],static function($row)use($ids){return (int)$row['faculty_id']===$ids['first'];}));
    $check($r['status']===200 && count($records)===2 && (float)$records[1]['designation_units']===0.0 && $records[1]['record_source']==='selected','Copying or re-adding a faculty member preserves an explicitly removed current appointment.');
    foreach(['designation_print_current'=>0,'designation_print_old'=>12] as $scenario=>$units) {
        $r=$request($scenario);$check($r['status']===200 && (float)($r['data']['meta']['designation_units']??-1)===(float)$units,'Individual workload print data uses the selected semester designation: '.$scenario.' '.substr($r['body'],0,700));
    }
    $r=$request('designation_admin_set');$ids=$header($r,'X-QA-Faculty-Ids');$records=array_values(array_filter($header($r,'X-QA-Term-Designations')??[],static function($row)use($ids){return (int)$row['faculty_id']===$ids['existing'];}));
    $check($r['status']===200 && trim($r['body'])==='success' && count($records)===2 && (float)$records[0]['designation_units']===12.0 && (float)$records[1]['designation_units']===6.0,'Administrator updates also affect the current appointment while retaining earlier semesters. '.substr($r['body'],0,700));
    $r=$request('designation_admin_list');$ids=$header($r,'X-QA-Faculty-Ids');
    $check($r['status']===200 && preg_match('/class=\'btn btn-sm btn-warning btnEditFaculty\'[^>]*data-id=\''.$ids['existing'].'\'[^>]*data-designation=\'\'/s',$r['body'])===1,'The Administrator faculty editor reads the current appointment after the Scheduler removes it.');
    foreach(['faculty_page','rooms_page'] as $scenario) {
        $r=$request($scenario);$check($r['status']===200 && strpos($r['body'],'id="termListCopyModal"')!==false && strpos($r['body'],'term-list-copy.js?v=')!==false
            && !preg_match('/Fatal error|Parse error|xdebug-error/i',$r['body']),'The actual page includes the selectable copy preview: '.$scenario.' '.substr(strip_tags($r['body']),0,500));
        if($scenario==='faculty_page')$check(strpos($r['body'],'>Status</th>')===false && strpos($r['body'],'btn-reactivate-assignment')===false,'The faculty list has no assignment status or reactivation controls.');
    }
} finally { if(is_file($route))unlink($route); }
$check($before===$snapshot(),'All fixtures rolled back; real lists, faculty, rooms, and schedules are unchanged.');
echo 'Scheduler term lists: ',$checks,' checks passed. Fixtures rolled back.',PHP_EOL;
