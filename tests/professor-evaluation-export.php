<?php
// Pure export regression checks, also runnable with the deployed PHP 7.1 binary.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/professor_evaluations_helper.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$detail=['students'=>[
    ['student_number'=>'2026-001','full_name'=>'José "QA", Student'],
    ['student_number'=>'2026-002','full_name'=>"Name\nwith newline"],
    ['student_number'=>'=2+2','full_name'=>'@unsafe'],
    ['student_number'=>'2026-004','full_name'=>'Trailing slash\\'],
], 'class'=>['subject_code'=>'GEC 001','descriptive_title'=>'Purposive Communication / Malayuning Komunikasyon',
    'program_label'=>'BSCrim - Bachelor of Science in Criminology','section_display'=>'BSCrim 1A'],
    'scope'=>['ay_label'=>'2026-2027','semester'=>1]];
$csv=synk_professor_evaluation_pending_csv($detail);
$check(strlen($csv)>3 && substr($csv,0,3)==="\xEF\xBB\xBF",'CSV has a UTF-8 marker and actual content.');
$stream=fopen('php://temp','w+'); fwrite($stream,substr($csv,3)); rewind($stream); $rows=[];
while (($row=fgetcsv($stream,0,',','"',chr(29)))!==false) $rows[]=$row;
fclose($stream);
$check(count($rows)===count($detail['students'])+1,'Every listed student is exported exactly once, plus a header.');
$check(count($rows[0])===8 && $rows[0][0]==='Student number' && $rows[0][1]==='Student name','All eight columns are present.');
$check($rows[1][0]==='2026-001' && $rows[1][1]===$detail['students'][0]['full_name'],'Accents, commas and quotes round-trip correctly.');
$check($rows[2][1]===$detail['students'][1]['full_name'] && $rows[4][1]===$detail['students'][3]['full_name'],'Newlines and trailing backslashes round-trip correctly.');
$check($rows[3][0]==="'=2+2" && $rows[3][1]==="'@unsafe",'Spreadsheet formulas remain escaped.');
$check($rows[1][2]==='GEC 001' && $rows[1][3]===$detail['class']['descriptive_title'] && $rows[1][4]===$detail['class']['program_label'] && $rows[1][5]==='BSCrim 1A','Subject title, course and section are included in the file.');
$filename=synk_professor_evaluation_pending_filename($detail);
$check($filename==='GEC 001 - Purposive Communication Malayuning Komunikasyon - BSCrim - BSCrim 1A - Not evaluated.csv','Default filename identifies the subject, course and section.');
$detail['students']=[];
$check(strpos(synk_professor_evaluation_pending_csv($detail),'"Student number"')!==false,'An empty list still produces valid column headers.');
$detail['class']['subject_code']='GEC<>:"/\\|?*001';
$detail['class']['descriptive_title']=str_repeat('Évaluation ',30);
$detail['class']['section_display']="BSIT 1A\r\nInjected";
$filename=synk_professor_evaluation_pending_filename($detail);
$check(!preg_match('/[<>:"\/\\\\|?*\x00-\x1F]/',$filename) && strlen($filename)<240 && strpos($filename,'BSCrim')!==false && strpos($filename,'BSIT 1A Injected')!==false && preg_match('//u',$filename)===1,'Long Unicode filenames remain safe and preserve the course and section.');
echo 'Professor evaluation export: ',$checks,' checks passed on PHP ',PHP_VERSION,'.',PHP_EOL;
