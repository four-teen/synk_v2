<?php
// Real HTTP routes with rollback-only college, term, role and response fixtures.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$snapshot=static function()use($conn):string {
    $data=[];
    foreach (['tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_college_evaluation_settings','tbl_college_evaluation_audit','tbl_academic_settings','tbl_academic_years','tbl_useraccount','tbl_useraccount_roles','tbl_useraccount_faculty_links','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_faculty'] as $table) $data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($data));
};
$before=$snapshot(); $token=bin2hex(random_bytes(24)); $route=__DIR__.'/../tmp/evaluation-access-qa-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).'); require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';');
$request=static function(string $case)use($route,$token,$check):array {
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>'evaluation_access_'.$case]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    $raw=curl_exec($curl); if ($raw===false) throw new RuntimeException(curl_error($curl));
    $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    $headers=substr($raw,0,$size); $body=substr($raw,$size); $state=[];
    if (preg_match('/X-QA-Evaluation-State: ([^\r\n]+)/i',$headers,$match)) $state=json_decode(rawurldecode($match[1]),true);
    $check(!preg_match('/Fatal error|Parse error|xdebug-error|Warning(?:<\/b>)?:/i',$body),'No PHP errors: '.$case.' '.substr(strip_tags($body),-700));
    $check(isset($state['responses'],$state['controls'],$state['audit']),'The protected fixture completed: '.$case.' HTTP '.$status.' '.substr(strip_tags($body),-700));
    return ['status'=>$status,'body'=>$body,'headers'=>$headers,'state'=>$state];
};
try {
    foreach (['student_nav_closed','student_nav_profile_closed','student_nav_prospectus_closed','student_nav_faculty_only','student_nav_past_only','student_nav_other_college','student_nav_inactive','student_nav_unlinked','preview_nav_closed','preview_nav_foreign_open','preview_nav_admin_closed'] as $case) {
        $r=$request($case);
        preg_match('/<aside id="layout-menu"[\s\S]*?<\/aside>/',$r['body'],$menu);
        $check($r['status']===200 && !empty($menu[0]) && strpos($menu[0],'class-program.php')===false,'The student evaluation menu is hidden unless collection is open for an enrolled current-term college: '.$case);
        $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Checking menu access never changes saved records: '.$case);
    }
    foreach (['student_nav_open','student_nav_shared_open','preview_nav_open','preview_nav_admin_open'] as $case) {
        $r=$request($case);
        preg_match('/<aside id="layout-menu"[\s\S]*?<\/aside>/',$r['body'],$menu);
        preg_match('/href="([^"]*class-program\.php[^"]*)"/',$menu[0] ?? '',$link);
        $query=[];
        if ($link) parse_str((string)parse_url(html_entity_decode($link[1],ENT_QUOTES,'UTF-8'),PHP_URL_QUERY),$query);
        $open=array_values(array_filter($r['state']['controls'],static function($row){return !empty($row['student_open']);}));
        $check($r['status']===200 && isset($query['evaluation_college_id'],$query['term_key']) && count($open)===1
            && (int)$query['evaluation_college_id']===(int)$open[0]['college_id']
            && $query['term_key']===$open[0]['ay_id'].'-'.$open[0]['semester'],'An open menu links directly to the correct available college and current term: '.$case);
        if (strpos($case,'preview_')===0) $check(isset($query['preview_student_id'],$query['return_to']),'Show Account keeps preview identity and return navigation when evaluations are open.');
    }
    $r=$request('student_closed');
    $check($r['status']===200 && strpos($r['body'],'Your college has closed evaluations')!==false && strpos($r['body'],'name="ratings[')===false,'Closed evaluation periods do not expose answer forms.');
    $r=$request('student_open');
    $check($r['status']===200 && substr_count($r['body'],'name="ratings[')===30 && strpos($r['body'],'name="csrf_token"')!==false,'Open current-term forms contain all revised statements and CSRF protection.');
    $check(strpos($r['body'],'id="term_key"')===false && strpos($r['body'],'id="faculty_key"')===false && strpos($r['body'],'id="evaluation_college_id"')===false && strpos($r['body'],'Load Faculty List')===false && strpos($r['body'],'Select Faculty</a>')===false,'The assigned term and college need no filter form or separate faculty selection.');
    $check(substr_count($r['body'],'data-faculty-evaluation-card')===2 && substr_count($r['body'],'class="student-professor-photo"')===2,'Each assigned faculty group uses the shared professor-photo subject rows.');
    $r=$request('student_stale_filter');
    $check($r['status']===200 && substr_count($r['body'],'data-faculty-evaluation-card')===2,'Old faculty filter URLs cannot hide other assigned professors.');
    $r=$request('student_multiple_subjects');
    $check($r['status']===200 && substr_count($r['body'],'data-faculty-evaluation-card')===1 && substr_count($r['body'],'class="student-professor-photo"')===2 && substr_count($r['body'],'faculty-evaluate-button"')===1,'Multiple subjects taught by one professor share one evaluation action.');
    foreach (['student_closed_post','student_bad_csrf','student_historical_post'] as $case) {
        $r=$request($case);
        $check($r['status']===422 && count($r['state']['responses'])===0,'Forged drafts cannot bypass the college, current-term or CSRF check: '.$case);
    }
    $r=$request('student_open_post');
    $check($r['status']===302 && count($r['state']['responses'])===1 && $r['state']['responses'][0]['submission_status']==='draft','An authorized student can save a current-term draft.');
    $r=$request('student_closed_final');
    $check($r['status']===422 && count(array_filter($r['state']['responses'],static function($row){return $row['submission_status']==='submitted';}))===0,'Closing collection also rejects final submission and keeps all drafts.');
    $r=$request('student_shared_final');
    $statuses=array_count_values(array_column($r['state']['responses'],'submission_status'));
    $check($r['status']===302 && ($statuses['submitted'] ?? 0)===2 && ($statuses['draft'] ?? 0)===1,'College A finalizes independently while the same professor remains a draft in closed College B.');
    foreach (['student_historical','student_archived_without_roster'] as $case) {
        $r=$request($case);
        $check($r['status']===200 && strpos($r['body'],'ARCHIVED_SUBMITTED_RESPONSE')!==false && strpos($r['body'],'View submitted evaluation')!==false,'Archived responses survive inactive and removed imports: '.$case);
        $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Reviewing the archive cannot alter responses: '.$case);
    }
    $r=$request('dean_get');
    $check($r['status']===200 && strpos($r['body'],'id="evaluationAccessControls"')!==false && strpos($r['body'],'Open evaluations')!==false && strpos($r['body'],'Release results')!==false,'The Dean sees two separate controls.');
    foreach (['dean_open','dean_release_past'] as $case) {
        $r=$request($case);
        $check($r['status']===302 && count($r['state']['audit'])===1 && (int)$r['state']['audit'][0]['new_value']===1,'A valid dean action is persisted with its audit entry: '.$case);
        $check(count($r['state']['responses'])===0,'An access-control change never creates or resets evaluations.');
    }
    $r=$request('dean_hide');
    $check($r['status']===302 && count($r['state']['audit'])===1 && (int)$r['state']['audit'][0]['previous_value']===1 && (int)$r['state']['audit'][0]['new_value']===0,'The Dean can hide released results again, with both audit values preserved.');
    foreach (['dean_bad_csrf','dean_foreign','dean_changed_scope','dean_stale','dean_open_past','chair_post'] as $case) {
        $r=$request($case);
        $check($r['status']===422 && count($r['state']['audit'])===0,'Unauthorized or stale controls are rejected: '.$case);
        $check(count(array_filter($r['state']['controls'],static function($row){return !empty($row['student_open']) || !empty($row['faculty_released']);}))===0,'Rejected controls cannot change any college settings: '.$case);
    }
    $r=$request('chair_get');
    $check(strpos($r['body'],'id="dean-mobile-nav"')===false,'Dean mobile navigation is not exposed on the shared Program Chair page.');
    $check($r['status']===200 && strpos($r['body'],'id="evaluationAccessControls"')===false,'The shared Program Chair page does not expose Dean controls.');
    $r=$request('professor_hidden');
    $check($r['status']===200 && strpos($r['body'],'No released results yet')!==false && strpos($r['body'],'RELEASED_A_FEEDBACK')===false,'Faculty results are hidden by default.');
    foreach (['professor_released','professor_foreign_faculty'] as $case) {
        $r=$request($case);
        $check($r['status']===200 && strpos($r['body'],'80.00% normalized score')!==false && strpos($r['body'],'RELEASED_A_FEEDBACK &lt;script&gt;bad()&lt;/script&gt;')!==false,'Only the linked faculty result is shown, with escaped comments: '.$case);
        foreach (['HIDDEN_B_FEEDBACK','OTHER_FACULTY_FEEDBACK','PRIVATE_DRAFT_COMMENT'] as $secret) $check(strpos($r['body'],$secret)===false,'Unreleased colleges, other faculty and drafts cannot leak: '.$secret);
        $check(strpos($r['body'],'Student Number:')===false && strpos($r['body'],'student_id=')===false,'The faculty page exposes no student identities or individual response links.');
    }
    foreach (['professor_hidden_direct','professor_rehidden'] as $case) {
        $r=$request($case);
        $check($r['status']===404 && strpos($r['body'],'data-professor-evaluation-instrument=')===false && strpos($r['body'],'HIDDEN_B_FEEDBACK')===false,'Direct links cannot bypass hidden or rehidden results: '.$case);
    }
    $r=$request('professor_historical');
    $check($r['status']===200 && strpos($r['body'],'ARCHIVED_RELEASED_FEEDBACK')!==false && strpos($r['body'],'100.00% normalized score')!==false && strpos($r['body'],'RELEASED_A_FEEDBACK')===false,'Past releases remain available independently of the current term.');
    $r=$request('professor_ambiguous_legacy');
    $check($r['status']===200 && strpos($r['body'],'No released results yet')!==false && strpos($r['body'],'RELEASED_A_FEEDBACK')===false,'An ambiguous historical response cannot be released through either college by inference.');
    $r=$request('professor_mixed');
    $check($r['status']===200 && substr_count($r['body'],'data-professor-evaluation-instrument=')===2 && strpos($r['body'],'80.00% normalized score')!==false && strpos($r['body'],'60.00% normalized score')!==false,'Faculty reports preserve the two original instruments and never combine their scores.');
    foreach (['professor_revoked','professor_inactive','professor_unlinked'] as $case) {
        $r=$request($case);
        $check($r['status']===403 && strpos($r['body'],'data-professor-evaluation-instrument=')===false,'Faculty identity is revalidated on every request: '.$case);
    }
} finally { if (is_file($route)) unlink($route); }
$check($before===$snapshot(),'All fixture records and settings rolled back; the original database is unchanged.');
echo 'Evaluation access: ',$checks,' checks passed. All fixture records rolled back.',PHP_EOL;
