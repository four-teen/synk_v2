<?php
// Actual PHP 7.1 multipart endpoints. Use an empty assigned class, tag every QA file,
// and remove only these exact records afterward. Core records are read-only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/learning_material_api_helper_lms.php';
$root=dirname(__DIR__);$lms=synk_database_connection_lms($conn);$tag='MaterialHttpQA'.bin2hex(random_bytes(10));$token=bin2hex(random_bytes(24));
$route=$root.'/tmp/'.$tag.'.php';$paths=[];$jars=[$root.'/temp/'.$tag.'1.cookies',$root.'/temp/'.$tag.'2.cookies'];$checks=0;$boot=null;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$coreTables='tbl_academic_settings,tbl_faculty,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_class_schedule,tbl_faculty_workload_sched,tbl_student_management,tbl_student_management_enrolled_subjects';
$coreBefore=$conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC);
$materialBefore=$lms->query('CHECKSUM TABLE tbl_lms_material_collections,tbl_lms_material_files')->fetch_all(MYSQLI_ASSOC);
$bootstrap= <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)||!is_string($_GET['qa']??null)||!hash_equals(QA_TOKEN,$_GET['qa'])){http_response_code(404);exit;}
require CORE_DB;require MATERIAL_HELPER;
session_start();header('Content-Type: application/json');
if(!empty($_GET['logout'])){$_SESSION=[];session_destroy();echo '{"ok":true}';exit;}
$lms=synk_database_connection_lms($conn);
$users=$conn->query("SELECT DISTINCT u.user_id,l.faculty_id FROM tbl_useraccount u JOIN tbl_useraccount_faculty_links l ON l.user_id=u.user_id JOIN tbl_faculty f ON f.faculty_id=l.faculty_id WHERE u.status='active' AND f.status='active' ORDER BY u.user_id")->fetch_all(MYSQLI_ASSOC);
$term=synk_fetch_current_academic_term($conn);
foreach($users as $user){
    $account=synk_find_useraccount_by_id($conn,(int)$user['user_id']);if(!synk_useraccount_has_active_role($conn,$account,'professor'))continue;
    $classes=synk_professor_fetch_subject_rows_by_academic_year($conn,(int)$user['faculty_id'],(int)$term['ay_id'],(int)$term['semester']);
    foreach($classes as $class){
        if(isset($_GET['offering'])&&(int)$_GET['offering']!==(int)$class['offering_id'])continue;
        $existing=synk_assessment_query_lms($lms,'SELECT collection_id FROM tbl_lms_material_collections WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=?','iiii',[(int)$user['faculty_id'],(int)$class['offering_id'],(int)$class['ay_id'],(int)$class['semester']]);
        if($existing)continue;
        $_SESSION=['user_id'=>(int)$user['user_id'],'role'=>'professor','username'=>'Learning material QA','lms_material_csrf'=>bin2hex(random_bytes(32))];
        parse_str(parse_url(synk_professor_class_url_lms($class,'materials'),PHP_URL_QUERY),$context);
        $other=null;foreach($classes as $alternate)if($alternate['offering_id']!==$class['offering_id']){parse_str(parse_url(synk_professor_class_url_lms($alternate,'materials'),PHP_URL_QUERY),$other);break;}
        echo json_encode(['context'=>$context,'csrf_token'=>$_SESSION['lms_material_csrf'],'faculty_id'=>(int)$user['faculty_id'],'other'=>$other,'php'=>PHP_VERSION]);exit;
    }
}
http_response_code(503);echo '{"message":"No empty assigned learning material workspace is available."}';
PHP;
file_put_contents($route,str_replace(['QA_TOKEN','CORE_DB','MATERIAL_HELPER'],[var_export($token,true),var_export($root.'/backend/db.php',true),var_export($root.'/backend/learning_material_api_helper_lms.php',true)],$bootstrap));
$base='http://localhost/synk/';$bootUrl=$base.'tmp/'.basename($route).'?qa='.$token;$api=$base.'professor/learning_materials_api_lms.php';
$curlHandle=static function(string $url,?array $data,int $session=0,string $method='POST')use($jars){
    $curl=curl_init($url);$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>35,CURLOPT_HEADER=>true];
    if($session>=0){$options[CURLOPT_COOKIEFILE]=$jars[$session];$options[CURLOPT_COOKIEJAR]=$jars[$session];}
    if($method==='POST'){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=$data ?? [];}
    if($method==='HEAD')$options[CURLOPT_NOBODY]=true;
    curl_setopt_array($curl,$options);return $curl;
};
$response=static function($curl,?string $body=null):array{
    if($body===null)$body=curl_exec($curl);if($body===false)throw new RuntimeException(curl_error($curl));
    $headerSize=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $headers=substr($body,0,$headerSize);$body=substr($body,$headerSize);return ['status'=>$status,'headers'=>$headers,'body'=>$body,'data'=>json_decode($body,true)];
};
$request=static function(string $url,?array $data=null,int $session=0,string $method='POST')use($curlHandle,$response):array{return $response($curlHandle($url,$data,$session,$method));};
$make=static function(string $name,string $data)use(&$paths,$root):array{
    $path=tempnam($root.'/temp','materialhttp');$paths[]=$path;file_put_contents($path,$data);return ['path'=>$path,'name'=>$name,'data'=>$data];
};
try {
    $r=$request($bootUrl,null,0,'GET');$check($r['status']===200,'Unable to start HTTP test session: '.$r['body']);$boot=$r['data'];
    $r=$request($bootUrl.'&offering='.$boot['context']['offering_id'],null,1,'GET');$check($r['status']===200,'Unable to start the competing test session.');$second=$r['data'];
    $check(strpos($boot['php'],'7.1.')===0,'Test uses the actual PHP 7.1 web runtime.');
    $fields=static function(string $action,array $files=[],array $overrides=[],?array $session=null)use($boot):array{
        $session=$session??$boot;$data=['action'=>$action,'csrf_token'=>$session['csrf_token'],'context'=>json_encode($session['context'])];
        foreach($files as $index=>$file)$data['materials['.$index.']']=new CURLFile($file['path'],'application/octet-stream',$file['name']);
        return array_replace($data,$overrides);
    };
    $call=static function(string $action,array $files=[],array $overrides=[])use($request,$api,$fields):array{return $request($api,$fields($action,$files,$overrides));};
    $pdf=$make($tag.'-syllabus.pdf',"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    $pptx=$make($tag.'-slides.pptx','');$zip=new ZipArchive();$zip->open($pptx['path'],ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>');
    $zip->addFromString('ppt/presentation.xml','<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>');$zip->close();$pptx['data']=file_get_contents($pptx['path']);
    $bad=$make($tag.'-fake.pdf','<?php echo "not pdf";');$txt=$make($tag.'-notes.txt','Not allowed');
    $r=$request($api,null,-1,'GET');$check($r['status']===405,'API requires POST.');
    $r=$request($api,$fields('list'),-1);$check($r['status']===401,'Anonymous callers are denied.');
    $r=$call('list',[],['csrf_token'=>'wrong']);$check($r['status']===403,'CSRF is enforced.');
    $forged=$boot['context'];$forged['semester']=$forged['semester']==='1'?'2':'1';
    $r=$call('list',[],['context'=>json_encode($forged)]);$check($r['status']===403,'Edited signed context is denied.');
    $r=$call('list');$check($r['status']===200&&$r['data']['materials']===[],'Empty class loads without inventing files.');
    foreach([[$bad],[$txt],[$pdf,$txt],[$pdf,$pdf,$pdf]] as $files){$r=$call('upload',$files);$check($r['status']===422,'Invalid uploads are rejected: '.$r['body']);}
    $r=$call('list');$check($r['data']['materials']===[],'A mixed invalid batch saves nothing.');
    $r=$call('upload',[$pdf,$pptx]);$check($r['status']===200&&count($r['data']['materials'])===2,'PDF and PPTX upload together: '.$r['body']);$rows=$r['data']['materials'];
    foreach($rows as $index=>$row){
        $url=$base.'professor/learning_material_download_lms.php?'.http_build_query($boot['context']+['material_id'=>$row['material_id']]);
        $r=$request($url,null,0,'GET');$check($r['status']===200&&$r['body']===[$pdf,$pptx][$index]['data'],'Downloads preserve the exact original bytes.');
        $check(stripos($r['headers'],'Content-Disposition: attachment;')!==false&&stripos($r['headers'],'X-Content-Type-Options: nosniff')!==false,'Download headers force safe attachments.');
        $r=$request($url,null,0,'HEAD');$check($r['status']===200&&$r['body']==='','HEAD downloads do not send content.');
        $r=$request($url,null,-1,'GET');$check($r['status']===401,'Anonymous downloads are denied.');
        if($boot['other']){$otherUrl=$base.'professor/learning_material_download_lms.php?'.http_build_query($boot['other']+['material_id'=>$row['material_id']]);$r=$request($otherUrl,null,0,'GET');$check($r['status']===404,'A valid different class cannot download these files.');}
    }
    $r=$call('upload',[$pdf]);$check($r['status']===409,'A third file is refused by the server.');
    $r=$call('remove',[],['material_id'=>(string)$rows[0]['material_id']]);$check($r['status']===200&&count($r['data']['materials'])===1,'Removal frees a slot.');
    $r=$call('remove',[],['material_id'=>(string)$rows[0]['material_id']]);$check($r['status']===404,'Repeated removal is rejected.');
    // Separate PHP sessions avoid PHP session locking masking a database race.
    $multi=curl_multi_init();$handles=[];
    foreach([$boot,$second] as $index=>$session){$handle=$curlHandle($api,$fields('upload',[$pdf],[],$session),$index);curl_multi_add_handle($multi,$handle);$handles[]=$handle;}
    do{$result=curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1);}while($running&&$result===CURLM_OK);
    $statuses=[];foreach($handles as $handle){$body=curl_multi_getcontent($handle);curl_multi_remove_handle($multi,$handle);$statuses[]=$response($handle,$body)['status'];}curl_multi_close($multi);sort($statuses);
    $check($statuses===[200,409],'Only one competing upload can use the remaining slot: '.json_encode($statuses));
    $r=$call('list');$check(count($r['data']['materials'])===2,'Concurrent uploads leave exactly two files.');
    $check(strpos($r['body'],'file_data')===false,'List responses expose metadata only.');
    $r=$request($base.'professor/learning_materials_view_lms.php',null,-1,'GET');$check($r['status']===404&&$r['body']==='','The view include cannot be requested directly.');
} finally {
    if($boot){
        $scope=[(int)$boot['faculty_id'],(int)$boot['context']['offering_id'],(int)$boot['context']['ay_id'],(int)$boot['context']['semester']];
        $collections=synk_assessment_query_lms($lms,'SELECT collection_id FROM tbl_lms_material_collections WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=?','iiii',$scope);
        foreach($collections as $collection){
            $id=(int)$collection['collection_id'];
            synk_assessment_query_lms($lms,'DELETE FROM tbl_lms_material_files WHERE collection_id=? AND original_name LIKE ?','is',[$id,$tag.'-%']);
            synk_assessment_query_lms($lms,'DELETE FROM tbl_lms_material_collections WHERE collection_id=? AND NOT EXISTS (SELECT 1 FROM tbl_lms_material_files WHERE collection_id=?)','ii',[$id,$id]);
        }
    }
    foreach([0,1] as $index){try{$request($bootUrl.'&logout=1',null,$index,'GET');}catch(Throwable $ignored){}}
    foreach(array_merge($paths,$jars,[$route]) as $path)if(is_file($path))unlink($path);
}
$check($conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC)===$coreBefore,'Core academic, account and workload records remain unchanged.');
$check($lms->query('CHECKSUM TABLE tbl_lms_material_collections,tbl_lms_material_files')->fetch_all(MYSQLI_ASSOC)===$materialBefore,'Only tagged test records were created and all were removed.');
echo 'LMS learning material HTTP: ',$checks,' checks passed, including multipart uploads, protected downloads and concurrent slot limits.',PHP_EOL;
