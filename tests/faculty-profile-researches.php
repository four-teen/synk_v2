<?php
if (PHP_SAPI !== 'cli') {http_response_code(404); exit;}
require_once __DIR__.'/../backend/faculty_profile_research_helper.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$record=['research_title'=>'  Community   learning  ','research_scope'=>'Local','funding_type'=>'Personal','conference_name'=>'Community Research Forum',
    'conference_date'=>'2028-02-29','publisher'=>'Sample Publisher','publisher_link'=>'https://publisher.example/article?id=1&lang=en','scopus_link'=>'https://www.scopus.com/record/display.uri?eid=123'];
[$valid,$errors]=synk_faculty_profile_research_validate($record);
$check(!$errors&&$valid['research_title']==='Community learning','Valid research is normalized and future leap-day conferences are accepted.');
[$minimal,$errors]=synk_faculty_profile_research_validate(['research_title'=>'Unpublished study','research_scope'=>'International','funding_type'=>'Funded']);
$check(!$errors&&$minimal['conference_date']===''&&$minimal['scopus_link']==='','Research can be recorded before conference and publication details are available.');
$badCases=[
    ['research_title'=>''],['research_title'=>str_repeat('ñ',501)],['research_title'=>['array']],['research_title'=>"Bad\0title"],['research_title'=>"Bad\xFFtitle"],
    ['research_scope'=>'National'],['funding_type'=>'Other'],['conference_date'=>'2026-02-29'],['conference_date'=>'2026-13-01'],['conference_date'=>'1899-01-01'],
    ['conference_date'=>'2026-01-02','conference_name'=>''],['publisher'=>str_repeat('p',301)],
    ['publisher_link'=>'javascript:alert(1)'],['publisher_link'=>'https://user:pass@example.test/article'],['publisher_link'=>'not-a-link'],['publisher_link'=>'https://example.test/a b'],
    ['scopus_link'=>'https://scopus.com.evil.example/record'],['scopus_link'=>'https://not-scopus.com/record'],['publisher_link'=>str_repeat('a',2049)],
];
foreach($badCases as $bad){[, $errors]=synk_faculty_profile_research_validate(array_replace($record,$bad));$check((bool)$errors,'Invalid research field must be rejected: '.implode(',',array_keys($bad)));}
foreach(['https://scopus.com/record','https://www.scopus.com/record','https://SCOPUS.COM/record'] as $link){[, $errors]=synk_faculty_profile_research_validate(array_replace($record,['scopus_link'=>$link]));$check(!$errors,'Valid Scopus hosts are accepted.');}
foreach(['faculty:0','account:2147483648','other:1','faculty:1 OR 1=1'] as $owner){try{synk_faculty_profile_research_owner($owner);throw new RuntimeException('Bad owner accepted.');}catch(InvalidArgumentException $e){$check(true,'Invalid owner rejected.');}}
require_once __DIR__.'/../backend/db.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
synk_faculty_profile_research_ensure($conn);
$snapshot=static function()use($conn):array{return $conn->query('CHECKSUM TABLE tbl_faculty_profile_researches,tbl_faculty_profiles,tbl_faculty_record_profiles,tbl_useraccount')->fetch_all(MYSQLI_ASSOC);};
$before=$snapshot();$conn->begin_transaction();
try{
    $tag='ResearchQA'.bin2hex(random_bytes(7));$email=$tag.'@example.test';
    $stmt=$conn->prepare("INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES (?,?,'unusable-test-password','professor','active')");
    $stmt->bind_param('ss',$tag,$email);$stmt->execute();$ownerId=(int)$conn->insert_id;$stmt->close();
    $owner='account:'.$ownerId;
    $check(synk_faculty_profile_research_save($conn,$owner,$valid,0,0),'A research record can be created.');
    $rows=synk_faculty_profile_research_records($conn,$owner);$id=(int)$rows[0]['research_id'];
    $check(count($rows)===1&&$rows[0]['publisher_link']===$record['publisher_link']&&$rows[0]['conference_date']==='2028-02-29','Every research field persists and reopens accurately.');
    $check(synk_faculty_profile_research_records($conn,'faculty:'.$ownerId)===[],'Owner types isolate research records.');
    $check(!synk_faculty_profile_research_save($conn,'account:'.($ownerId+1),$minimal,$id,1),'Another account cannot edit a research record.');
    $check(!synk_faculty_profile_research_delete($conn,'faculty:'.$ownerId,$id,1),'Another owner type cannot delete research.');
    $check(synk_faculty_profile_research_save($conn,$owner,$minimal,$id,1),'An existing research can be edited.');
    $rows=synk_faculty_profile_research_records($conn,$owner);
    $check((int)$rows[0]['revision']===2&&$rows[0]['conference_date']===null&&$rows[0]['publisher_link']==='','Editing increments the version and optional details can be cleared.');
    $check(!synk_faculty_profile_research_save($conn,$owner,$valid,$id,1)&&!synk_faculty_profile_research_delete($conn,$owner,$id,1),'Stale edits and deletes are rejected.');
    $check(synk_faculty_profile_research_records($conn,$owner)===$rows,'Rejected writes preserve the saved record.');
    $check(synk_faculty_profile_research_save($conn,$owner,$valid,0,0)&&count(synk_faculty_profile_research_records($conn,$owner))===2,'Multiple researches are stored as separate records.');
    $check(synk_faculty_profile_research_delete($conn,$owner,$id,2)&&count(synk_faculty_profile_research_records($conn,$owner))===1,'Removing one research preserves the other records.');
    $check(synk_faculty_profile_research_save($conn,'faculty:'.$ownerId,$valid,0,0)&&count(synk_faculty_profile_research_records($conn,'faculty:'.$ownerId))===1,'Faculty-owned profiles can record research independently.');
}finally{$conn->rollback();}
$check($snapshot()===$before,'Research, profile and account fixture data roll back.');
echo 'Faculty profile research: ',$checks,' checks passed. Fixtures rolled back.',PHP_EOL;
