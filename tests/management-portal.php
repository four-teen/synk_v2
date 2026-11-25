<?php
// php tests/management-portal.php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
$snapshot=static function()use($conn):array {
    $data=[];foreach(['tbl_useraccount','tbl_useraccount_roles','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_class_schedule','tbl_faculty_workload_sched','tbl_management_payments','tbl_management_payment_items']as$table){
        $data[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }return $data;
};
$before=$snapshot();$checks=0;$check=static function(bool$ok,string$message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$token=bin2hex(random_bytes(24));$name='management-qa-'.bin2hex(random_bytes(8)).'.php';$route=__DIR__.'/../tmp/'.$name;
file_put_contents($route,'<?php if(!in_array($_SERVER["REMOTE_ADDR"]??"",["127.0.0.1","::1"],true)||!hash_equals('.var_export($token,true).',(string)($_GET["qa"]??""))){http_response_code(404);exit;}define("SYNK_MANAGEMENT_QA",true);require '.var_export(__DIR__.'/fixtures/management-request.php',true).';');
$request=static function(string $scenario, array $params=[])use($name,$token):array {
    $context=stream_context_create(['http'=>['timeout'=>35,'ignore_errors'=>true,'follow_location'=>0,'header'=>"Connection: close\r\n"]]);
    $body=file_get_contents('http://localhost/synk/tmp/'.$name.'?'.http_build_query(['qa'=>$token,'scenario'=>$scenario]+$params),false,$context);
    $data=json_decode((string)$body,true);if(!is_array($data)||isset($data['fixture_error']))throw new RuntimeException($scenario.': '.substr((string)$body,0,2400));return $data;
};
$evaluationStatuses = static function (string $html): array {
    preg_match_all('/data-evaluation-status="([^"]+)">([^<]*)</', $html, $matches, PREG_SET_ORDER);
    return array_map(static fn(array $match): array => [$match[1],$match[2]], $matches);
};
$expectedEvaluations = [
    ['SUBMITTED','Submitted'], ['PENDING','Not submitted'], ['PARTIAL','1 of 2 submitted'],
    ['NO_LINKED_FACULTY','No linked faculty'], ['SUBMITTED','Submitted'],
    ['PENDING','Not submitted'], ['SUBMITTED','Submitted'], ['PENDING','Not submitted'],
];
try {
    $r=$request('officers_page');$check($r['status']===200&&strpos($r['response'],'officer-search-form')!==false&&strpos($r['response'],'Recent activity for students you supervise')!==false,'Faculty Student Officers page did not render.');
    $r=$request('officers_denied');$check($r['status']===403,'Student officer accessed faculty delegation page.');
    $r=$request('officers_attendance');$check($r['status']===200&&strpos($r['response'],'Scan attendance')!==false&&strpos($r['response'],'id="management-add" disabled')!==false,'Attendance officer cannot view scan operations or still edits events.');
    $check(strpos($r['response'],'officers.php?')===false&&strpos($r['response'],'id="management-current-period-settings" hidden')!==false,'Officer navigation exposes delegation or period administration.');
    $r=$request('officers_viewer');$check($r['status']===200&&strpos($r['response'],'Scan attendance')===false,'Viewer received attendance collection controls.');
    $r=$request('officers_payments');$check($r['status']===200&&strpos($r['response'],'Financial overview')!==false,'Treasurer cannot access financial overviews.');
    $r = $request('payment_corrections_dashboard');
    $check($r['status'] === 200 && strpos($r['response'],'id="management-dashboard-payment-corrections"') !== false
        && substr_count($r['response'],'href="payment-corrections.php?term='.rawurlencode($r['term']['key']).'&amp;exam_term=FINAL"') === 3,
        'Assigned faculty lost the Dashboard, desktop, or mobile Payment Corrections shortcut for the viewing period.');
    $r = $request('payment_corrections_financial');
    $check($r['status'] === 200 && strpos($r['response'],'id="management-financial-payment-corrections"') !== false,
        'The faculty Financial register lost its correction shortcut.');
    $r = $request('officers_payment_corrections_hidden');
    $check($r['status'] === 200 && strpos($r['response'],'management-payment-register') !== false
        && strpos($r['response'],'payment-corrections.php') === false,
        'The student treasurer lost the payment register or received the faculty correction shortcut.');
    foreach (['officers_payment_corrections_denied','officers_payment_corrections_post_denied',
        'payment_corrections_staff_denied','payment_corrections_foreign'] as $scenario) {
        $r = $request($scenario);
        $check($r['status'] === 403 && strpos($r['response'],'id="correction-search"') === false,
            'Correction access exposed receipts outside the assigned active faculty account: '.$scenario);
    }
    $r = $request('payment_corrections_search');
    $check($r['status'] === 200 && strpos($r['response'],'Matching students') !== false
        && strpos($r['response'],'correction-student-result') !== false && strpos($r['response'],'₱87.75') !== false,
        'Faculty receipt search did not load the assigned organization’s payment.');
    $r = $request('payment_corrections_account');
    $check($r['status'] === 200 && strpos($r['response'],'id="correction-editor"') !== false
        && strpos($r['response'],'href="payment-receipt.php?payment_id=') !== false
        && substr_count($r['response'],'data-correction-select-all') === 2 && substr_count($r['response'],'data-correction-item') === 3
        && strpos($r['response'],'name="action" value="delete"') !== false
        && strpos($r['response'],'action="payment-corrections.php?term='.rawurlencode($r['term']['key']).'&amp;exam_term=FINAL') !== false,
        'Student correction detail lost the receipt groups, checkboxes, single delete action, or selected term.');
    foreach (['payment_corrections_delete'=>'0.00','payment_corrections_delete_one'=>'25.25'] as $scenario=>$remaining) {
        $r = $request($scenario);
        $check($r['status'] === 303 && strpos($r['location'],'payment-corrections.php?term='.rawurlencode($r['term']['key']).'&exam_term=FINAL') === 0
            && strpos($r['session']['management_correction_notice'] ?? '','Selected payments deleted. Reference REV-') === 0
            && $r['correction'] === ['paid'=>[$remaining,'12.50'],'reasons'=>['Correction QA']],
            'Faculty deletion did not remove only the checked payments and save its reason: '.$scenario);
    }
    $invalidCorrections = [
        'payment_corrections_wrong_context'=>[409,'paid receipts changed'],
        'payment_corrections_csrf'=>[403,'This form expired'],
        'payment_corrections_reason_required'=>[422,'Enter reason'],
        'payment_corrections_empty_selection'=>[422,'Select at least one payment'],
    ];
    foreach ($invalidCorrections as $scenario=>[$status,$message]) {
        $r = $request($scenario);
        $check($r['status'] === $status && strpos($r['response'],$message) !== false
            && $r['correction'] === ['paid'=>['75.25','12.50'],'reasons'=>[]],
            'An invalid deletion did not preserve receipt credits: '.$scenario);
    }
    foreach(['contributions','events','violations']as$kind){
        $r=$request('officers_manage_'.$kind);$html=$r['response'];$check($r['status']===200&&strpos($html,'id="management-add"><')!==false,'Management-only officer cannot open/add '.$kind);
        $check(strpos($html,'officers.php?')===false&&strpos($html,'id="management-current-period-settings" hidden')!==false,'Catalog manager gained delegation or period settings.');
        foreach(['contributions','events','violations']as$otherKind)if($otherKind!==$kind)$check(strpos($html,$otherKind.'.php?')===false,'Catalog manager sees unassigned navigation: '.$otherKind);
    }
    $check($conn->query("SHOW COLUMNS FROM tbl_academic_settings LIKE 'current_exam_term'")->num_rows===0,'Management term leaked into shared Academic Settings storage.');
    require_once __DIR__.'/../backend/academic_term_helper.php';
    $check(!array_key_exists('exam_term',synk_fetch_current_academic_term($conn)),'Shared academic helper exposes a Management-only term.');
    $r=$request('login');$html=$r['response'];
    $check($r['status']===200 && strpos($html,'CCS Management.')!==false && strpos($html,'One Place.')!==false,'Management login branding is missing.');
    $check(strpos($html,'managementGoogleLogin')!==false && !preg_match('/<input[^>]+type=["\'](?:email|password)["\']/i',$html),'Management login is not Google-only.');
    foreach(['dashboard','financial','students','students_page','contributions','events','violations','exemptions']as$scenario){
        $r=$request($scenario);$html=$r['response'];
        $check($r['status']===200 && is_string($html) && !preg_match('/Fatal error|Parse error|xdebug-error|<b>Warning/i',$html),$scenario.': page failed to render: '.json_encode(['status'=>$r['status'],'response'=>is_string($html)?substr($html,0,500):$html]));
        $check((int)$r['session']['college_id']===$r['scope']['college_id'],'Management trusted a stale session college.');
        foreach(['Financial','Contributions','Events','Violations','Students','Dashboard']as$menu)$check(strpos($html,$menu)!==false,'A Management menu is missing: '.$menu);
        if($scenario==='financial')$check(strpos($html,'id="management-payment-register"')!==false && strpos($html,'No receipts recorded for this period')!==false,'Financial did not show the empty payment register.');
        if ($scenario==='students') {
            $check(strpos($html,$r['tag'].'Foreign')===false && substr_count($html,'<tr role="row">')===26,'Directory scope or pagination is incorrect.');
            $check(array_slice($evaluationStatuses($html),0,8)===$expectedEvaluations,
                'Evaluation status miscounted duplicate faculty, drafts, partial submissions, legacy ownership, or semester/college scope.');
            $check(strpos($html,'QA comment')===false, 'Student directory exposed evaluation comments.');
        }
        if($scenario==='students_page')$check(substr_count($html,'<tr role="row">')===4 && strpos($html,'Page 2 of 2')!==false,'Student directory did not deduplicate or paginate correctly.');
        if($scenario==='contributions'||$scenario==='events')$check(strpos($html,'id="management-add"')!==false && strpos($html,'id="management-record-form"')!==false,'Catalog management forms are missing.');
    }
    $register = static function (array $params = []) use ($request,$check): DOMXPath {
        $response = $request('financial_register',$params);
        $check($response['status']===200,'Payment register request failed.');
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">'.$response['response'],LIBXML_NOERROR|LIBXML_NOWARNING);
        return new DOMXPath($doc);
    };
    $registerRows = '//*[@id="management-payment-register"]//tbody/tr';
    $today = new DateTimeImmutable('today',new DateTimeZone('Asia/Manila'));
    $page = $register();
    $registerSummary = [
        $page->evaluate('string(//*[@data-register-today-collected])'),
        $page->evaluate('string(//*[@data-register-today-receipts])'),
        $page->evaluate('string(//*[@data-register-total])'),
        $page->query($registerRows)->length,
        $page->query($registerRows.'/td[6]/a')->length,
    ];
    $check($registerSummary===['₱175.50','2','₱345.25',25,25],
        'Financial totals leaked across the Manila day or Management scope, or counted split allocations twice: '.json_encode($registerSummary));
    $check($page->query($registerRows.'[1]/td[2][contains(., "-NEXT")]')->length===1
        && $page->query('//*[@id="management-register-cashier"]/option')->length===3,
        'The register lost newest-first ordering or used cashier options from another period or organization.');
    $page = $register(['page'=>2,'search'=>'payer']);
    $check($page->query($registerRows)->length===3 && $page->evaluate('string(//*[@data-register-total])')==='₱345.25'
        && strpos($page->evaluate('string(//nav[@aria-label="Receipt pages"]/div/a/@href)'),'search=payer')!==false,
        'The second page changed the filtered total or lost its search parameters.');
    $page = $register(['search'=>'edge-0001']);
    $check($page->query($registerRows)->length===1 && $page->evaluate('string(//*[@data-register-total])')==='₱125.50'
        && $page->query($registerRows.'/td[1]/time[@datetime="'.$today->format('Y-m-d').'T00:00:00+08:00"]')->length===1,
        'Receipt lookup lost case-insensitive matching, Philippine time, or the full posted amount.');
    foreach (['QA-2026-501','payer, aLeX'] as $search) {
        $page = $register(['search'=>$search]);
        $check($page->query($registerRows)->length===2 && $page->evaluate('string(//*[@data-register-total])')==='₱175.50',
            'The register search missed receipts by student ID or name.');
    }
    $dates = ['date_from'=>$today->format('Y-m-d'),'date_to'=>$today->format('Y-m-d')];
    $page = $register($dates);
    $check($page->query($registerRows)->length===2 && $page->evaluate('string(//*[@data-register-total])')==='₱175.50',
        'An inclusive Philippine date filter excluded an endpoint or included a neighboring day.');
    $page = $register($dates+['qa_cashier'=>'evening']);
    $check($page->query($registerRows)->length===1 && $page->evaluate('string(//*[@data-register-total])')==='₱50.00'
        && $page->evaluate('string(//*[@data-register-today-collected])')==='₱175.50',
        'Cashier filtering did not combine with the date range or it changed the daily cards.');
    $page = $register(['exam_term'=>'MIDTERM']);
    $check($page->query($registerRows)->length===1 && $page->query($registerRows.'/td[3][contains(., "Midterm Payer")]')->length===1
        && $page->evaluate('string(//*[@data-register-today-collected])')==='₱175.50'
        && strpos($page->evaluate('string(//*[@id="management-register-find-student"]/@href)'),'exam_term=FINAL')!==false,
        'Reviewing Midterm altered the current daily totals or sent new payments to the viewing period.');
    $page = $register(['search'=>'%']);
    $check($page->query($registerRows)->length===0 && $page->query('//*[@id="management-payment-register"]//h3[contains(., "No receipts match")]')->length===1,
        'The register treated search wildcards as operators or omitted the empty result state.');
    $page = $register(['date_from'=>$today->format('Y-m-d'),'date_to'=>$today->modify('-1 day')->format('Y-m-d')]);
    $check($page->query($registerRows)->length===0 && $page->query('//*[@id="management-payment-register"]//*[@role="alert"]')->length===1,
        'The register accepted a reversed date range without an inline error.');
    $r=$request('officers_financial_denied');
    $check($r['status']===403 && strpos($r['response'],'management-payment-register')===false,
        'An attendance-only officer accessed the payment register.');
    $r=$request('dashboard_collections');
    $check($r['status']===200 && preg_match('/data-stat="collected">[^<]*225\.50<\/strong>/', $r['response'])
        && strpos($r['response'],'id="management-collection-search-input"')!==false && strpos($r['response'],'Alex Payer')===false,
        'Dashboard did not show current collections or fetched student data before the card opened.');
    $r=$request('collection_list');
    $list=$r['response'];
    $check($r['status']===200 && $list['total_collected']==='225.50' && $list['pagination']['total']===2
        && $list['total_students']===2
        && array_column($list['rows'],'student_name')===['Alex Payer','Retained Payer']
        && array_column($list['rows'],'total_paid')===['175.50','50.00'] && (int)$list['rows'][0]['receipt_count']===2,
        'Collections list split a student or included another period or organization.');
    $r=$request('collection_search_name');
    $check($r['status']===200 && array_column($r['response']['rows'],'student_name')===['Alex Payer']
        && $r['response']['rows'][0]['total_paid']==='175.50' && (int)$r['response']['rows'][0]['receipt_count']===2
        && $r['response']['total_collected']==='225.50' && $r['response']['total_students']===2 && $r['response']['pagination']['total']===1,
        'Name search dropped an earlier receipt or changed the period total.');
    $r=$request('collection_search_id');
    $check($r['status']===200 && array_column($r['response']['rows'],'student_name')===['Retained Payer'],
        'ID search did not find a payer whose enrollments were removed.');
    foreach (['collection_search_wrong_period','collection_search_wildcard'] as $scenario) {
        $r=$request($scenario);
        $check($r['status']===200 && $r['response']['rows']===[] && $r['response']['pagination']['total']===0
            && $r['response']['total_collected']==='225.50',
            'Student search matched another period or treated a wildcard as search syntax.');
    }
    $r=$request('collection_detail');
    $detail=$r['response'];
    $check($r['status']===200 && $detail['total_paid']==='175.50' && count($detail['receipts'])===2
        && count($detail['receipts'][1]['items'])===2 && $detail['receipts'][0]['recorded_at']==='Nov 25, 2026 8:30 AM'
        && array_column($detail['categories'],'amount','label')===['Contributions'=>'100.00','Event absence fines'=>'25.50','Violation fines'=>'50.00'],
        'Expanded payment history lost receipt items, the local timestamp, or exact category totals.');
    foreach (['collection_foreign_student'=>404,'collection_stale'=>409,'collection_wrong_scope'=>403] as $scenario=>$status) {
        $r=$request($scenario);
        $check($r['status']===$status && !isset($r['response']['receipts']) && !isset($r['response']['rows']),
            $scenario.': collections accepted a request outside the card period or organization.');
    }
    $r=$request('analytics');$check($r['response']['students']===$r['baseline']['students']+28,'Analytics counted duplicate memberships or foreign-only students.');
    $check($r['response']['enrollments']===$r['baseline']['enrollments']+29,'Analytics included foreign-college imported classes.');
    $r=$request('students_empty');$check($r['status']===200&&strpos($r['response'],'No matching students')!==false,'Student empty-search state is missing.');
    $r=$request('students_evaluation_midterm');
    $check($r['status']===200 && array_slice($evaluationStatuses($r['response']),0,8)===$expectedEvaluations,
        'Changing the financial exam period changed evaluation completion for the same semester.');
    $r=$request('students_evaluation_history');
    $check($r['status']===200 && $evaluationStatuses($r['response'])===[['PARTIAL','2 of 3 submitted']],
        'The historical evaluation count omitted inactive enrollments or retained submissions.');
    foreach ([
        'submitted'=>['SUBMITTED',1,1,'Submitted'],
        'pending'=>['PENDING',1,0,'Not submitted'],
        'partial'=>['PARTIAL',2,1,'1 of 2 submitted'],
        'unlinked'=>['NO_LINKED_FACULTY',0,0,'No linked faculty'],
    ] as $state=>$expected) {
        $r=$request('students_detail_'.$state);
        $progress=$r['response']['evaluation_progress'] ?? [];
        $check($r['status']===200 && isset($r['response']['financial'])
            && array_values($progress)===$expected,
            'Student financial overview reported inaccurate evaluation progress for '.$state.'.');
    }
    foreach(['students_wildcard','students_otherterm']as$scenario){$r=$request($scenario);$check($r['status']===200&&strpos($r['response'],'No matching students')!==false,$scenario.': search matched unintended memberships.');}
    $r=$request('students_xss');$check($r['status']===200&&strpos($r['response'],'<img src=x onerror=alert(1)>')===false&&strpos($r['response'],'&lt;img src=x onerror=alert(1)&gt;')!==false,'Search text is not escaped.');
    foreach(['forged_college'=>403,'forged_campus'=>403,'bad_term'=>400,'students_array'=>400,'bad_exam_term'=>400,'array_exam_term'=>400]as$scenario=>$status){$r=$request($scenario);$check($r['status']===$status,$scenario.': invalid scope/filter was accepted.');}
    foreach(['view_final','view_final_fields'] as $scenario) {
        $r=$request($scenario); $html=$r['response'];
        $check($r['status']===200 && strpos($html,'"exam_term":"FINAL"')!==false && strpos($html,'Final</span>')!==false,'Final viewing period was not displayed.');
        $check(strpos($html,'exam_term=FINAL')!==false,'Management navigation lost the selected Final term.');
    }
    $r=$request('students_foreign');$check($r['status']===200 && strpos($r['response'],'No matching students')===false && strpos($r['response'],$r['tag'].'Foreign')===false,'A removed program filter still changed the current-period directory or leaked another college.');
    foreach(['revoked','no_google','wrong_role','anonymous']as$scenario){$r=$request($scenario);$check($r['status']===302&&strpos($r['location'],'login.php')===0,$scenario.': unauthorized session accessed Management.');}
    $r=$request('reassigned');$check($r['status']===200&&(int)$r['session']['college_id']===$r['other_scope']['college_id'],'Administrator reassignment was not refreshed.');
    foreach(['account_create','account_update','account_multi','account_remove']as$scenario){
        $r=$request($scenario);$check($r['response']==='success',$scenario.': account save failed: '.json_encode($r['response']));
        $account=end($r['after']['accounts']);
        if($scenario==='account_remove')$check($account['management_college_id']===null&&$account['management_organization_id']===null,'Removing Management retained its assignment.');
        else $check((int)$account['management_college_id']===$r['scope']['college_id']&&(int)$account['management_organization_id']===$r['scope']['organization_id'],'Management organization assignment did not save.');
        if($scenario==='account_multi')$check((int)$account['college_id']===$r['other_scope']['college_id'],'Management overwrote Program Chair scope.');
    }
    foreach(['account_missing'=>'need_management_college','account_invalid'=>'invalid_management_organization','account_array'=>'invalid_management_college','account_bad_csrf'=>'invalid_csrf','account_mixed_gmail'=>'invalid_domain','account_forbidden'=>'unauthorized']as$scenario=>$message){
        $r=$request($scenario);$check($r['response']===$message&&$r['before']===$r['after'],$scenario.': rejected account request changed data.');
    }
    foreach(['account_missing_org'=>'need_management_organization','account_foreign_org'=>'invalid_management_organization','account_foreign_campus'=>'invalid_management_organization','account_inactive_org'=>'invalid_management_organization'] as $scenario=>$message) {
        $r=$request($scenario); $check($r['response']===$message&&$r['before']===$r['after'],$scenario.': invalid organization assignment was accepted.');
    }
    $r=$request('account_identity_preserved');$account=end($r['after']['accounts']);$check($r['response']==='success'&&$account['google_sub']==='known-management-identity','Editing college access cleared the verified Google identity.');
    $r=$request('account_identity_changed');$account=end($r['after']['accounts']);$check($r['response']==='success'&&$account['google_sub']===null,'Changing email retained the previous Google identity binding.');
    $r=$request('account_load');$row=$r['response']['accounts'][0];$check(in_array('management',$row['roles'],true)&&(int)$row['management_college_id']===$r['scope']['college_id']&&$row['management_college_label']!=='','Administrator account list lost Management assignment.');
    $r=$request('account_page');foreach(['add','edit']as$mode)$check(strpos($r['response'],'id="'.$mode.'_role_management"')!==false&&strpos($r['response'],'id="'.$mode.'_management_college_id"')!==false,'Administrator role/college controls missing.');
    $r=$request('identity_ok');$check($r['response']['status']==='ok'&&$r['session']['role']==='management'&&$r['session']['auth_method']==='google'&&(int)$r['session']['college_id']===$r['scope']['college_id'],'Verified Google identity did not open Management in its own college.');
    foreach(['identity_unknown','identity_unverified','identity_bad_domain','identity_sub','identity_inactive','identity_revoked','identity_missing_college','identity_college_inactive','identity_missing_org','identity_inactive_org']as$scenario){$r=$request($scenario);$check($r['response']['status']==='error'&&$r['before']===$r['after']&&!isset($r['session']['user_id']),$scenario.': unauthorized Google identity was accepted.');}
    $r=$request('oauth_start');$query=[];parse_str((string)parse_url($r['location'],PHP_URL_QUERY),$query);
    $check($r['status']===302&&strpos($r['location'],'https://accounts.google.com/')===0&&!isset($query['hd'])&&strlen($query['state']??'')===64,'Management OAuth did not allow a Google account chooser without the staff domain hint.');
    $check($r['session']['google_oauth_state']==='main-state'&&$r['session']['student_google_oauth_state']==='student-state','Starting Management sign-in replaced another portal flow.');
    foreach(['oauth_cancelled'=>'google_cancelled','oauth_invalid'=>'google_state_invalid','oauth_expired'=>'google_state_invalid','oauth_start_bad_csrf'=>'login_request_invalid']as$scenario=>$status){$r=$request($scenario);$check($r['status']===302&&strpos($r['location'],'../management/login.php?auth_status='.$status)===0,$scenario.': callback returned to the wrong portal.');$check($r['session']['google_oauth_state']==='main-state'&&$r['session']['student_google_oauth_state']==='student-state','Management callback consumed another portal flow.');}
    foreach(['oauth_main_cancelled'=>'../index.php','oauth_student_cancelled'=>'../student/login.php']as$scenario=>$target){$r=$request($scenario);$check($r['status']===302&&strpos($r['location'],$target.'?auth_status=google_cancelled')===0&&($r['session']['management_google_oauth_state']??'')==='management-state','Staff/student callback consumed the Management sign-in flow.');}
    foreach(['legacy_login','legacy_choose_management']as$scenario){$r=$request($scenario);$check(!isset($r['session']['user_id'])&&($r['response']['status']??'')!=='success','Legacy authentication entered Management.');}
    $check($snapshot()===$before,'Fixtures left real records changed.');
    echo 'Management portal: '.$checks." HTTP checks passed. Test records rolled back.\n";
} finally {if(is_file($route))unlink($route);}
