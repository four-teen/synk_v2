<?php
// Run with PHP CLI. All database fixtures are rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/learning_material_api_helper_lms.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$checks = 0;
$check = static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$deny = static function(callable $fn,int $code)use($check):void {
    try { $fn(); throw new RuntimeException('Invalid learning material operation succeeded.'); }
    catch (DomainException $e) { $check($e->getCode()===$code,$e->getMessage().' / expected '.$code); }
};
$lms = synk_database_connection_lms($conn); $originalSession = $_SESSION ?? [];
$tables = 'tbl_lms_material_collections,tbl_lms_material_files';
$before = $lms->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);
$paths = []; $conn->begin_transaction(); $lms->begin_transaction();
try {
    $fixture = professor_students_fixtures($conn);
    $_SESSION = ['user_id'=>$fixture['user_id'],'role'=>'professor','lms_material_csrf'=>bin2hex(random_bytes(32))];
    $class = ['faculty_id'=>$fixture['faculty_id'],'offering_id'=>$fixture['offerings'][0]['offering_id'],'ay_id'=>$fixture['term']['ay_id'],'semester'=>$fixture['term']['semester']];
    parse_str(parse_url(synk_professor_class_url_lms($class,'materials'),PHP_URL_QUERY),$signed);
    $context = synk_professor_class_context_lms($conn,$signed);
    $check($context['view']==='materials','Materials use their own signed view.');
    $make = static function(string $name,string $data)use(&$paths):array {
        $path = tempnam(__DIR__.'/../temp','materialqa'); $paths[]=$path; file_put_contents($path,$data);
        return ['name'=>$name,'tmp_name'=>$path,'size'=>strlen($data),'error'=>UPLOAD_ERR_OK];
    };
    $pdf = $make('Syllabus.pdf',"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    $check(synk_material_validate_lms($pdf)['mime']==='application/pdf','PDF content is accepted.');
    $ppt = $make('Slides.PPT',"\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0",504).implode("\0",str_split('PowerPoint Document'))."\0");
    $check(synk_material_validate_lms($ppt)['mime']==='application/vnd.ms-powerpoint','Legacy PowerPoint content is recognized.');
    $zipFile = $make('Slides.pptx',''); $zip = new ZipArchive(); $zip->open($zipFile['tmp_name'],ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>');
    $zip->addFromString('ppt/presentation.xml','<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>'); $zip->close(); clearstatcache();
    $check(synk_material_validate_lms($zipFile)['mime']==='application/vnd.openxmlformats-officedocument.presentationml.presentation','PowerPoint Open XML content is accepted.');
    foreach (['notes.docx','script.php','image.png','report.exe','file.pdf.php'] as $name) $deny(static function()use($pdf,$name){synk_material_validate_lms(array_replace($pdf,['name'=>$name]));},422);
    foreach (['../file.pdf',"file\r\n.pdf",' slides.pdf',str_repeat('a',201).'.pdf'] as $name) $deny(static function()use($pdf,$name){synk_material_validate_lms(array_replace($pdf,['name'=>$name]));},422);
    foreach (['Fake.pdf'=>'<?php echo 1;','Fake.ppt'=>'not powerpoint','Fake.pptx'=>'not a zip','Empty.pdf'=>''] as $name=>$data) {
        $bad=$make($name,$data);$deny(static function()use($bad){synk_material_validate_lms($bad);},422);
    }
    $tooBig=$make('Large.pdf',str_repeat('x',synk_material_limit_lms()+1));
    $deny(static function()use($tooBig){synk_material_validate_lms($tooBig);},413);
    $deny(static function()use($pdf){synk_material_validate_lms(array_replace($pdf,['error'=>UPLOAD_ERR_PARTIAL]));},422);
    $deny(static function()use($pdf){synk_material_validate_lms(array_replace($pdf,['error'=>UPLOAD_ERR_INI_SIZE]));},413);
    $check(synk_material_list_lms($lms,$context)===[],'A fresh subject starts empty.');
    $rows=synk_material_upload_lms($lms,$context,[$pdf,$zipFile],$fixture['user_id']);
    $check(count($rows)===2&&!array_key_exists('file_data',$rows[0]),'Two files are saved and metadata omits their contents.');
    $first=(int)$rows[0]['material_id']; $second=(int)$rows[1]['material_id'];
    $check(synk_material_file_lms($lms,$context,$first,true)['file_data']===file_get_contents($pdf['tmp_name']),'Download data round-trips byte for byte.');
    $deny(static function()use($lms,$context,$pdf,$fixture){synk_material_upload_lms($lms,$context,[$pdf],$fixture['user_id']);},409);
    foreach (['faculty_id','offering_id','ay_id','semester'] as $key) {
        $foreign=$context;if($key==='faculty_id')$foreign['portal'][$key]++;else $foreign['class'][$key]++;
        $check(synk_material_list_lms($lms,$foreign)===[],'Materials are isolated by '.$key.'.');
        $deny(static function()use($lms,$foreign,$first){synk_material_file_lms($lms,$foreign,$first,true);},404);
        $deny(static function()use($lms,$foreign,$first){synk_material_remove_lms($lms,$foreign,$first);},404);
    }
    $check(count(synk_material_remove_lms($lms,$context,$first))===1,'Removal frees exactly one slot.');
    $deny(static function()use($lms,$context,$first){synk_material_remove_lms($lms,$context,$first);},404);
    $deny(static function()use($lms,$context,$pdf,$fixture){synk_material_upload_lms($lms,$context,[$pdf,$pdf],$fixture['user_id']);},409);
    $check(count(synk_material_upload_lms($lms,$context,[$ppt],$fixture['user_id']))===2,'The free slot accepts a replacement.');
    $request=['action'=>'list','context'=>$signed,'csrf_token'=>$_SESSION['lms_material_csrf']];
    $deny(static function()use($conn,$request){synk_material_request_lms($conn,array_replace($request,['csrf_token'=>'wrong']),[]);},403);
    $deny(static function()use($conn,$request){$request['context']['offering_id']=(string)((int)$request['context']['offering_id']+1);synk_material_request_lms($conn,$request,[]);},403);
    $deny(static function()use($conn,$request){synk_material_request_lms($conn,$request+['faculty_id'=>1],[]);},400);
    $_SESSION['role']='student';$deny(static function()use($conn,$request){synk_material_request_lms($conn,$request,[]);},401);
} finally {
    $lms->rollback();$conn->rollback();$_SESSION=$originalSession;
    foreach($paths as $path)if(is_file($path))unlink($path);
}
$check($lms->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC)===$before,'All material fixtures rolled back.');
echo 'LMS learning materials: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
