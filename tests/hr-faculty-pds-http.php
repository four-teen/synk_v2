<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/hr_faculty_pds_helper.php';
require_once __DIR__.'/fixtures/hr-faculty-pds-remaining-data.php';
synk_hr_pds_ensure($conn);
$token=bin2hex(random_bytes(24)); $root=dirname(__DIR__); $route=$root.'/.tmp/hr-pds-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define("SYNK_HR_PDS_QA",'.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/hr-faculty-pds-request.php',true).';');
$url='http://localhost/synk/.tmp/'.basename($route).'?qa='.$token;
$endpointRoute=$root.'/.tmp/hr-pds-endpoint-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($endpointRoute,'<?php define("SYNK_HR_PDS_QA",'.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/hr-faculty-pds-endpoint.php',true).';');
$endpointUrl='http://localhost/synk/.tmp/'.basename($endpointRoute).'?qa='.$token;
$downloadRoute=$root.'/.tmp/hr-pds-download-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($downloadRoute,'<?php define("SYNK_HR_PDS_QA",'.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/hr-faculty-pds-download.php',true).';');
$downloadUrl='http://localhost/synk/.tmp/'.basename($downloadRoute).'?qa='.$token;
$uploadPath=tempnam(sys_get_temp_dir(),'hrpdshttp');
$checks=0; $check=static function (bool $ok,string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$call=static function (string $url,$payload=null,string $method='POST',array $files=[],bool $decode=true): array {
    $curl=curl_init($url); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_CUSTOMREQUEST=>$method]);
    if ($files) curl_setopt($curl,CURLOPT_POSTFIELDS,['payload'=>json_encode($payload)]+$files);
    elseif ($payload!==null) curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload)]);
    $raw=curl_exec($curl); if ($raw===false) throw new RuntimeException(curl_error($curl));
    $headerSize=curl_getinfo($curl,CURLINFO_HEADER_SIZE); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    $body=substr($raw,$headerSize); $decoded=json_decode($body,true);
    if ($decode && !is_array($decoded)) throw new RuntimeException('HTTP '.$status.' returned a non-JSON fixture response: '.$body);
    return ['status'=>$status,'headers'=>substr($raw,0,$headerSize),'body'=>$decoded,'raw'=>$body];
};
try {
    $personal=array_fill_keys(array_keys(synk_hr_pds_fields('personal')),'');
    $personal=array_replace($personal,['surname'=>'Antonio','first_name'=>'Elbren','middle_name'=>'Osorio','name_extension'=>'Jr.','date_of_birth'=>'1977-07-01']);
    $request=['csrf_token'=>$token,'section'=>'personal','owner'=>'account:1','revision'=>0,'values'=>$personal];
    $result=$call($url,$request);
    $check($result['status']===200 && $result['body']['revision']===1,'HTTP personal save succeeds.');
    $check($result['body']['values']['surname']==='Antonio' && strpos($result['headers'],'X-QA-Personal-Revision: 1')!==false,'Saved names reload from database inside the HTTP request.');
    $check(strpos($result['headers'],'X-QA-Account-Unchanged: yes')!==false,'PDS personal saves preserve the Google account.');
    $contact=array_fill_keys(array_keys(synk_hr_pds_fields('contact')),'');
    $contact=array_replace($contact,['same_address'=>'1','residential_city'=>'QA City','residential_zip'=>'00123','umid'=>'00012345','email'=>'pds-contact@example.edu']);
    $contactRequest=array_replace($request,['section'=>'contact','values'=>$contact]);
    $result=$call($url,$contactRequest);
    $check($result['status']===200 && $result['body']['values']['umid']==='00012345' && $result['body']['values']['permanent_zip']==='00123','HTTP contact save preserves identifier zeros and copies the address.');
    $check(strpos($result['headers'],'X-QA-Account-Unchanged: yes')!==false,'Changing PDS contact email leaves login email unchanged.');
    foreach (hr_pds_remaining_examples() as $section=>$values) {
        $remainingRequest=array_replace($request,['section'=>$section,'values'=>$values]);
        $result=$call($url,$remainingRequest);
        if ($section==='work') {
            $check($result['status']===403 && strpos($result['headers'],'X-QA-Work-Revision: 0')!==false,
                'The professor endpoint refuses work history saves.');
            continue;
        }
        $check($result['status']===200 && $result['body']['values']===$values && strpos($result['headers'],'X-QA-Records-Reloaded: yes')!==false,'Web PHP saves and reloads all '.$section.' fields.');
    }
    $certRequest=array_replace($request,['section'=>'certification','values'=>hr_pds_empty_section('certification'),'removed_attachments'=>[]]);
    $image=imagecreatetruecolor(12,12); imagepng($image,$uploadPath); imagedestroy($image);
    foreach (['photo','thumbmark','signature_page_1','signature_page_2','signature_page_3','signature_page_4','oath_signature','unknown'] as $slot) {
        $result=$call($url.'&case=real_endpoint',$certRequest,'POST',[$slot=>new CURLFile($uploadPath,'image/png','printed-item.png')]);
        $check($result['status']===422 && isset($result['body']['errors']['_attachments']) && strpos($result['headers'],'X-QA-Certification-Revision: 0')!==false,'Reject '.$slot.' uploads without saving a section.');
    }
    $certRequest['values']['date_accomplished']='2026-11-15';
    $result=$call($url,$certRequest);
    $check($result['status']===200 && $result['body']['values']['date_accomplished']==='2026-11-15' && !isset($result['body']['values']['signature_date_1']),'The shared PDS date saves and reloads as one editable field.');
    $result=$call($downloadUrl.'&actor=owner&slot=signature_page_1',null,'GET',[],false);
    $check($result['status']===404 && strpos($result['raw'],'private sample')===false,'Previously uploaded signatures are not reused or served by the PDS.');
    $check(stripos($result['headers'],'X-Content-Type-Options: nosniff')!==false && stripos($result['headers'],'no-store')!==false,'Private downloads disable MIME sniffing and shared caching.');
    foreach (['foreign'=>404,'student'=>401] as $actor=>$status) {
        $result=$call($downloadUrl.'&actor='.$actor.'&slot=signature_page_1',null,'GET',[],false);
        $check($result['status']===$status && strpos($result['raw'],'private sample')===false,'A '.$actor.' session cannot download another profile attachment.');
    }
    $family=array_fill_keys(array_keys(synk_hr_pds_fields('family')),'');
    $family=array_replace($family,['children_apply'=>'Yes','father_surname'=>'Antonio','spouse_applies'=>'Not applicable','spouses'=>[],
        'children'=>[['full_name'=>'Ána Antonio','date_of_birth'=>'2015-04-01'],['full_name'=>'Partial child','date_of_birth'=>'']]]);
    $school=array_fill_keys(array_keys(synk_hr_pds_record_lists('education')['education']['fields']),'');
    $school=array_replace($school,['level'=>'College','institution'=>'Sample University','degree'=>'BS Education','year_from'=>'2010','year_to'=>'2014','year_graduated'=>'2014','status'=>'Completed']);
    foreach (['family'=>$family,'education'=>['education'=>[$school,$school]]] as $section=>$records) {
        $recordRequest=array_replace($request,['section'=>$section,'values'=>$records]);
        $result=$call($url,$recordRequest);
        $check($result['status']===200 && $result['body']['values']===$records && strpos($result['headers'],'X-QA-Records-Reloaded: yes')!==false,'HTTP '.$section.' saves and reloads ordered records on the web PHP runtime.');
        $check(strpos($result['headers'],'X-QA-Account-Unchanged: yes')!==false,'HTTP '.$section.' leaves login identity unchanged.');
        $bad=$recordRequest;
        if ($section==='family') { $bad['values']['children'][1]['date_of_birth']='2010-02-30'; $path='children.1.date_of_birth'; }
        else { $bad['values']['education'][1]['year_to']='2009'; $path='education.1.year_to'; }
        $result=$call($url,$bad);
        $check($result['status']===422 && isset($result['body']['errors'][$path]),'HTTP '.$section.' returns a nested row validation path.');
        $check(strpos($result['headers'],'X-QA-'.ucfirst($section).'-Revision: 0')!==false,'Invalid '.$section.' records save no section or rows.');
    }
    foreach (['csrf'=>403,'owner'=>409,'revision'=>409,'invalid_email'=>422,'invalid_name'=>422,'wrong_role'=>401] as $case=>$status) {
        $bad=$case==='invalid_email' ? $contactRequest : $request;
        if ($case==='csrf') $bad['csrf_token']='wrong';
        if ($case==='owner') $bad['owner']='account:2147483647';
        if ($case==='revision') $bad['revision']=9;
        if ($case==='invalid_email') $bad['values']['email']='invalid';
        if ($case==='invalid_name') $bad['values']['surname']=['bad'];
        $result=$call($url.'&case='.$case,$bad);
        $check($result['status']===$status && !$result['body']['ok'],'HTTP rejects '.$case.'.');
        $check(strpos($result['headers'],'X-QA-Personal-Revision: 0')!==false && strpos($result['headers'],'X-QA-Contact-Revision: 0')!==false,'Rejected '.$case.' saves neither section.');
    }
    $result=$call('http://localhost/synk/professor/pds-save.php',null,'GET');
    $check($result['status']===405 && strpos($result['headers'],'Allow: POST')!==false,'The real endpoint rejects GET requests.');
    $result=$call('http://localhost/synk/professor/pds-save.php',$request);
    $check($result['status']===401 && !$result['body']['ok'],'The real endpoint rejects unauthenticated writes as JSON.');
    $badToken=$request; $badToken['csrf_token']='expired-token';
    $result=$call($endpointUrl,$badToken);
    $check($result['status']===403 && $result['body']['ok']===false,'The authenticated real endpoint returns valid JSON for expired form tokens on the web PHP runtime.');
    $check(strpos($result['headers'],'Content-Type: application/json')!==false && strpos($result['raw'],'<')===false,'Authenticated responses contain only JSON, without PHP warning HTML.');
    $badEmail=$contactRequest; $badEmail['values']['email']='invalid-email';
    $result=$call($endpointUrl,$badEmail);
    $check($result['status']===422 && isset($result['body']['errors']['email']),'The real endpoint returns parseable field validation errors.');
    $result=$call($endpointUrl,$request);
    $check($result['status']===409 && $result['body']['ok']===false,'The real endpoint encodes database authorization responses without unsupported JSON flags.');
} finally { foreach ([$route,$endpointRoute,$downloadRoute,$uploadPath] as $temporaryRoute) if (is_file($temporaryRoute)) unlink($temporaryRoute); }
echo 'HR faculty PDS HTTP: ',$checks,' checks passed. Database writes rolled back.',PHP_EOL;
