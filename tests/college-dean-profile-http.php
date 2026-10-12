<?php
// Run: php -d extension=gd tests/college-dean-profile-http.php
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/college_dean_profile_helper.php';
synk_dean_profile_prepare_storage($conn);
$checks=0;
function dean_profile_check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
foreach (['Dean'=>'dean','COLLEGE DEAN & PROGRAM CHAIRPERSON, BSIT'=>'dean','PROGRAM CHAIRMAN'=>'chair','Program Chair'=>'chair','PROGRAM CHAIPERSON, BSCE'=>'chair','Extension / PC, BSN'=>'chair','Deanery coordinator'=>'faculty','Instructor'=>'faculty'] as $title=>$expected) {
    dean_profile_check(synk_dean_profile_designation_group($title)===$expected,'Designation: '.$title);
}
$tables=['tbl_campus','tbl_college','tbl_useraccount','tbl_useraccount_roles','tbl_faculty','tbl_designation','tbl_college_faculty','tbl_useraccount_faculty_links',
    'tbl_faculty_profiles','tbl_faculty_profile_eligibilities','tbl_faculty_record_profiles','tbl_faculty_education_options'];
$snapshot=static function() use($conn,$tables):array {$out=[];foreach($tables as $table)$out[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));return $out;};
$before=$snapshot();
$root=dirname(__DIR__);$token=bin2hex(random_bytes(24));$route=$root.'/tmp/dean-profile-http-'.bin2hex(random_bytes(8)).'.php';
if(!is_dir($root.'/tmp'))mkdir($root.'/tmp',0755,true);
$url='http://localhost/synk/tmp/'.basename($route);
$request=static function(string $scenario,?array $post=null,bool $authorized=true,array $query=[]) use($url,$token):array {
    $curl=curl_init($url.'?'.http_build_query(['qa'=>$authorized ? $token : 'wrong','scenario'=>$scenario]+$query));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20]);
    if($post!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));
    $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    return ['status'=>$status,'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
};
$valid=synk_faculty_profile_defaults(['username'=>'Dean Managed QA'],null);
unset($valid['updated_at']);$valid['revision']='0';$valid['csrf_token']=$token;
$valid['full_name']='Dean Managed QA';$valid['faculty_rank']='Instructor I';$valid['salary_grade']='SG 99';$valid['faculty_id_number']='00045-QA';
$credentialId=(int)$conn->query('SELECT eligibility_id FROM tbl_faculty_eligibilities ORDER BY eligibility_id LIMIT 1')->fetch_assoc()['eligibility_id'];
$valid['eligibility_ids[0]']=(string)$credentialId;unset($valid['eligibility_ids']);
$temporaryImage=$root.'/tmp/dean-profile-photo-'.bin2hex(random_bytes(8)).'.png';
file_put_contents($route,'<?php define(\'SYNK_DEAN_PROFILE_QA\','.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/college-dean-profile-request.php',true).';');
try {
    dean_profile_check($request('directory',null,false)['status']===404,'Fixture rejects a missing route token.');
    $directory=$request('directory');
    if($directory['status']!==200 || strpos($directory['body'],'Faculty Profile QA College')===false) throw new RuntimeException('Directory HTTP '.$directory['status'].': '.strip_tags($directory['body']));
    dean_profile_check($directory['status']===200 && strpos($directory['body'],'Faculty Profile QA College')!==false,'Dean directory renders.');
    dean_profile_check(strpos($directory['body'],'José')!==false && strpos($directory['body'],'Foreign QA Foreign')===false,'Directory includes own faculty and excludes foreign faculty.');
    dean_profile_check(strpos($directory['body'],'id="dean-profiles-dean"') < strpos($directory['body'],'id="dean-profiles-chair"'),'Dean precedes Program Chairs.');
    dean_profile_check(strpos($directory['body'],'faculty-placeholder.svg')!==false,'Missing photos use the uniform placeholder.');
    dean_profile_check(strpos($directory['headers'],'X-QA-Changed: no')!==false,'Viewing profiles does not change profile data.');
    $get=$request('get');
    dean_profile_check($get['status']===200 && strpos($get['body'],'Manage faculty profile')!==false,'Unlinked faculty have a working editor.');
    foreach (['foreign'=>404,'old_roster'=>404,'reassigned'=>404,'wrong_role'=>302,'revoked'=>302,'inactive'=>302,'bad_id'=>400] as $scenario=>$status) {
        $r=$request($scenario,$valid);
        dean_profile_check($r['status']===$status && strpos($r['headers'],'X-QA-Changed: no')!==false,'Denied request: '.$scenario);
    }
    foreach (['bad_csrf'=>403,'bad_owner'=>409,'professor_owner'=>409,'bad_revision'=>422,'invalid'=>422,'stale'=>409,'linked_stale'=>409] as $scenario=>$status) {
        $post=$valid;
        if($scenario==='bad_revision')$post['revision']='-1';
        if($scenario==='invalid')$post['full_name']='';
        $r=$request($scenario,$post);
        dean_profile_check($r['status']===$status && strpos($r['headers'],'X-QA-Changed: no')!==false,'Save rejection: '.$scenario);
    }
    $professorGet=$request('professor_get');
    dean_profile_check($professorGet['status']===200 && strpos($professorGet['body'],'José QA Record')!==false,'Professor opens the profile started by the Dean.');
    $professorPost=$valid;$professorPost['revision']='1';$professorSave=$request('professor_save',$professorPost);
    dean_profile_check($professorSave['status']===303 && strpos($professorSave['headers'],'X-QA-Record-Revision: 2')!==false
        && strpos($professorSave['headers'],'X-QA-Account-Storage: faculty')!==false,'Professor edits update the same shared faculty profile.');
    foreach (['record_save'=>['0','X-QA-Record-Revision: 1'],'record_update'=>['1','X-QA-Record-Revision: 2'],
        'linked_save'=>['1','X-QA-Account-Revision: 2'],'record_linked'=>['1','X-QA-Record-Revision: 2'],'roster'=>['0','X-QA-Record-Revision: 1']] as $scenario=>$expected) {
        $post=$valid;$post['revision']=$expected[0];$r=$request($scenario,$post);
        dean_profile_check($r['status']===303 && strpos($r['headers'],'Location: manage-faculty-profile.php?faculty_id=')!==false,'Successful redirect: '.$scenario);
        dean_profile_check(strpos($r['headers'],$expected[1])!==false,'Expected revision: '.$scenario);
        dean_profile_check(strpos($r['headers'],'X-QA-Directory-Name: Dean%20Managed%20QA')!==false,'Shared directory sees saved name: '.$scenario);
        if($scenario!=='linked_save')dean_profile_check(strpos($r['headers'],'X-QA-Credential-Count: 1')!==false,'Record credentials are included in exports: '.$scenario);
        if($scenario==='record_linked')dean_profile_check(strpos($r['headers'],'X-QA-Account-Storage: faculty')!==false,'Newly linked faculty keep their shared profile.');
    }
    $im=imagecreatetruecolor(90,60);imagepng($im,$temporaryImage);imagedestroy($im);
    $post=$valid;$post['profile_image']=new CURLFile($temporaryImage,'image/png','faculty.png');
    $photo=$request('photo',$post);
    dean_profile_check($photo['status']===303 && strpos($photo['headers'],'X-QA-Photo: yes')!==false,'Real multipart photo upload is accepted and saved.');
    $bad=$valid;$bad['profile_image']=new CURLFile(__FILE__,'image/png','fake.png');
    $fake=$request('fake_photo',$bad);
    dean_profile_check($fake['status']===422 && strpos($fake['headers'],'X-QA-Changed: no')!==false && strpos($fake['headers'],'X-QA-Photo: no')!==false,'Forged photo uploads cannot change data or photos.');
    $education=$request('education',null,true,['type'=>'degree','q'=>'Science','page'=>'1']);
    dean_profile_check($education['status']===200 && is_array(json_decode($education['body'],true)['results'] ?? null),'Dean editor can search education suggestions.');
} finally {
    if(is_file($route))unlink($route);
    if(is_file($temporaryImage))unlink($temporaryImage);
}
dean_profile_check($snapshot()===$before,'Every fixture change was rolled back; real records are unchanged.');
echo 'College Dean faculty profiles: ',$checks,' checks passed. Fixtures rolled back and test route removed.',PHP_EOL;
