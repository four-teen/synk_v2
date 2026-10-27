<?php
// Real multipart uploads and mandatory-photo pages; fixture rows/files are removed.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../backend/db.php';
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):string{$rows=[];foreach(['tbl_student_management','tbl_student_management_enrolled_subjects','tbl_student_portal_profile','tbl_class_schedule','tbl_faculty_workload_sched','tbl_academic_settings','tbl_useraccount']as$table)$rows[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($rows));};
$files=static function():array{$result=[];foreach(array_merge(glob(__DIR__.'/../uploads/student-profile/student_*.jpg'),glob(__DIR__.'/../uploads/profile/.profile-upload-*'))as$file)$result[basename($file)]=hash_file('sha256',$file);ksort($result);return $result;};
$before=$snapshot();$filesBefore=$files();$token=bin2hex(random_bytes(24));$nonce=bin2hex(random_bytes(8));
$route=__DIR__.'/../tmp/student-photo-qa-'.$nonce.'.php';$fixtures=[];
file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';');
$png=static function(int $width,int $height,string $color):string{
    $chunk=static function(string $type,string $data):string{return pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));};
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0)).$chunk('IDAT',gzcompress(str_repeat("\0".str_repeat($color,$width),$height))).$chunk('IEND','');
};
foreach(['valid'=>$png(16,12,"\x90\x30\x10"),'wide'=>$png(6001,1,"\x10\x20\x30"),'fake'=>'This is not a photo','large'=>str_repeat('x',5*1024*1024+1)]as$key=>$bytes){$file=__DIR__.'/../.tmp/student-photo-'.$nonce.'-'.$key.'.png';file_put_contents($file,$bytes);$fixtures[$key]=$file;}
$request=static function(string $scenario,?string $file=null)use($route,$token):array{
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>$scenario]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    if($file!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,['profile_photo'=>new CURLFile($file,'image/png','photo.png')]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $headers=substr($raw,0,$size);preg_match('/X-QA-Student-Photos: ([^\r\n]+)/i',$headers,$match);
    return ['status'=>$status,'headers'=>$headers,'body'=>substr($raw,$size),'photos'=>isset($match[1])?json_decode(rawurldecode($match[1]),true):[]];
};
try{
    foreach(['photo_required_dashboard','photo_required_qr','photo_required_prospectus']as$scenario){$r=$request($scenario);$check($r['status']===302&&strpos($r['headers'],'Location: profile.php?photo_required=1')!==false,'A missing photo did not require Manage Profile: '.$scenario);}
    foreach(['photo_required_page','photo_required_previous_login','photo_required_corrupt']as$scenario){$r=$request($scenario);$check($r['status']===200&&strpos($r['body'],'id="studentProfilePhotoModal"')!==false&&strpos($r['body'],'data-required="1"')!==false&&strpos($r['body'],'First-Time Student Setup')===false,'A new or previously signed-in student did not receive the photo-only prompt.');$check(strpos($r['body'],'name="student_number"')===false&&strpos($r['body'],'name="program_id"')===false&&strpos($r['body'],'name="first_name"')===false,'Imported identity remains editable.');}
    $r=$request('photo_required_upload',$fixtures['valid']);$check($r['status']===303&&strpos($r['headers'],'Location: index.php')!==false&&$r['photos']['first']['exists']&&$r['photos']['first']['before']===null,'The first photo did not save and continue to subjects.');
    $r=$request('photo_upload_replace',$fixtures['valid']);$photo=$r['photos']['first'];
    $check($r['status']===303&&strpos($r['headers'],'Location: profile.php?photo_updated=1')!==false,'Replacing a photo did not return to Manage Profile.');
    $check($photo['files']===1&&$photo['hash']!==$photo['before']&&$photo['mime']==='image/jpeg'&&$photo['width']===16&&$photo['height']===12,'Replacement did not leave exactly one re-encoded JPEG.');
    $check($r['photos']['other']['hash']===$r['photos']['other']['before'],'A posted student ID changed another student\'s photo.');
    $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Uploading a photo changed imported student information or academic data.');
    foreach(['photo_upload_bad_csrf'=>403,'photo_upload_empty'=>422,'photo_upload_fake'=>422,'photo_upload_wide'=>422,'photo_upload_large'=>422]as$scenario=>$status){$key=substr($scenario,strlen('photo_upload_'));$r=$request($scenario,$fixtures[$key] ?? ($key==='bad_csrf'?$fixtures['valid']:null));$check($r['status']===$status,'Invalid photo request was accepted: '.$scenario);$check($r['photos']['first']['hash']===$r['photos']['first']['before']&&$r['photos']['first']['files']===1,'An invalid replacement damaged the existing photo: '.$scenario);}
    foreach(['photo_upload_staff','photo_upload_anonymous']as$scenario){$r=$request($scenario,$fixtures['valid']);$check($r['status']===302&&$r['photos']['first']['hash']===$r['photos']['first']['before'],'An unauthenticated or staff request uploaded a student photo.');}
    $r=$request('dashboard');$check($r['status']===200&&strpos($r['headers'],'Location: profile.php')===false&&strpos($r['body'],'uploads/student-profile/student_')!==false,'A student with a photo is blocked or its navbar photo is missing.');
    $r=$request('photo_required_preview');$check($r['status']===200&&strpos($r['body'],'Read only')!==false&&strpos($r['body'],'id="studentProfilePhotoModal"')===false,'A staff preview was forced to upload the student\'s photo.');
    $r=$request('photo_upload_preview',$fixtures['valid']);$check($r['status']===403&&$r['photos']['first']['hash']===$r['photos']['first']['before'],'A staff preview uploaded a student photo.');
}finally{if(is_file($route))unlink($route);foreach($fixtures as$file)if(is_file($file))unlink($file);}
$check($snapshot()===$before,'Photo checks changed stored student or academic data.');
$check($files()===$filesBefore,'Photo fixtures or staged uploads were not cleaned up.');
echo 'Student profile photos: ',$checks,' checks passed, including real uploads and replacement. Fixtures removed.',PHP_EOL;
