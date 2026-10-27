<?php
// Signed-in Student financial pages, native Management fixtures, rollback-only.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../backend/db.php';
require __DIR__.'/../backend/management_student_account_helper.php';
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):array{$rows=[];foreach(['tbl_student_management','tbl_student_management_enrolled_subjects','tbl_academic_settings','tbl_class_schedule','tbl_faculty_workload_sched','tbl_management_organizations','tbl_management_period_settings','tbl_management_contributions','tbl_management_events','tbl_management_violations','tbl_management_attendance_events','tbl_management_attendance_roster','tbl_management_attendance_logs','tbl_management_attendance_audit','tbl_useraccount','tbl_useraccount_roles']as$table)$rows[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));return $rows;};
$before=$snapshot();$token=bin2hex(random_bytes(24));$route=__DIR__.'/../tmp/student-finances-qa-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';');
$request=static function(string $scenario)use($route,$token):array{
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>$scenario]));curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);$raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$headers=substr($raw,0,$size);preg_match('/X-QA-Finance-Expected: ([^\r\n]+)/i',$headers,$match);
    $dom=new DOMDocument();@$dom->loadHTML(substr($raw,$size) ?: '<html></html>');return ['status'=>$status,'headers'=>$headers,'body'=>substr($raw,$size),'expected'=>isset($match[1])?json_decode(rawurldecode($match[1]),true):null,'xpath'=>new DOMXPath($dom)];
};
try {
    foreach(['finances_page','finances_tamper','finances_profile_other_college']as$scenario){
        $r=$request($scenario);$html=$r['body'];$xpath=$r['xpath'];
        $check($r['status']===200&&strpos($html,'My Finances')!==false,'The signed-in student could not open their finances: '.$scenario.' HTTP '.$r['status'].' '.substr(strip_tags($html),0,1000));
        foreach(['due','contributions','event_fines']as$key){$node=$xpath->query('//*[@data-finance-total="'.$key.'"]')->item(0);$check($node&&trim($node->textContent)===synk_management_account_money($r['expected'][$key]),'The student total differs from Management: '.$key);}
        foreach(['present','absent','pending','not_in_roster']as$key){$node=$xpath->query('//*[@data-attendance-count="'.$key.'"]')->item(0);$check($node&&(int)$node->textContent===$r['expected'][$key],'Attendance totals use another student or an unfinished event: '.$key);}
        $check(strpos($html,'PRESENT EVENT')!==false&&strpos($html,'ABSENT EVENT')!==false&&strpos($html,'PENDING EVENT')!==false&&strpos($html,'NOT IN ROSTER EVENT')!==false,'The event list omitted an attendance state.');
        foreach(['FOREIGN COLLEGE SECRET CONTRIBUTION','WRONG MANAGEMENT TERM','INACTIVE CONTRIBUTION','OLD ACADEMIC PERIOD','WRONG TERM EVENT','CATALOG TYPE ONLY - NOT A STUDENT VIOLATION']as$secret)$check(strpos($html,$secret)===false,'Unrelated financial data leaked: '.$secret);
        $check(strpos($html,'SECOND ORGANIZATION CONTRIBUTION')!==false,'A second eligible organization was omitted.');
        $check(strpos($html,'OWN CONTRIBUTION &lt;img')!==false&&strpos($html,'<img src=x onerror=alert(1)>')===false,'Financial titles were not escaped.');
        foreach(['student-contributions','student-events','student-violations','student-payments','student-account-total']as$id)$check($xpath->query('//*[@id="'.$id.'"]')->length===1,'A financial section is missing: '.$id);
        $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Reading student finances changed student or academic data.');
    }
    $r=$request('finances_empty');$check($r['status']===200&&strpos($r['body'],'No financial records are available for your current enrollment')!==false,'An empty student account did not render safely.');
    foreach(['finances_anonymous'=>302,'finances_staff'=>302,'finances_photo_required'=>302,'finances_post'=>405]as$scenario=>$status){$r=$request($scenario);$check($r['status']===$status,'Student finance access or request-method check failed: '.$scenario);}
    $check(synk_management_account_money('1234567890.01')==='₱1,234,567,890.01','Money formatting lost centavos.');
} finally {if(is_file($route))unlink($route);}
$check($before===$snapshot(),'Financial fixtures changed real student, Management, or scheduling data.');
echo 'Student finances: ',$checks,' HTTP checks passed; all fixtures rolled back.',PHP_EOL;
