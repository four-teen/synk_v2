<?php
// Run on localhost. Optionally pass a workbook for a read-only CSSO preview.
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require __DIR__.'/../backend/db.php';
require __DIR__.'/../backend/management_portal_helper.php';
require __DIR__.'/../backend/management_attendance_workbook_helper.php';
require __DIR__.'/fixtures/management-frc-workbook.php';

$academic=synk_fetch_current_academic_term($conn);
$year=(int)synk_management_query($conn,'SELECT ay FROM tbl_academic_years WHERE ay_id=?','i',[$academic['ay_id']])[0]['ay'];
$dates=[1=>$year.'-08-01',2=>($year+1).'-01-01'];
$date=$dates[$academic['semester']] ?? null;
if (!$date || $date>=date('Y-m-d')) throw new RuntimeException('The upload fixture needs a past date in the current academic semester.');

$snapshot=static function()use($conn): array {
    $hashes=[];
    foreach (['tbl_management_events','tbl_management_attendance_events','tbl_management_attendance_logs','tbl_management_attendance_roster',
        'tbl_management_attendance_audit','tbl_management_attendance_checkpoints','tbl_management_attendance_corrections','tbl_management_period_settings','tbl_student_management','tbl_academic_settings'] as $table) {
        $hashes[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetch_all(MYSQLI_ASSOC)));
    }
    return $hashes;
};
$baseline=$snapshot();
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};

$token=bin2hex(random_bytes(24));
$routeName='management-frc-'.bin2hex(random_bytes(8)).'.php';
$route=__DIR__.'/../tmp/'.$routeName;
$fixture=__DIR__.'/fixtures/management-crud-request.php';
$paths=[];
try {
    file_put_contents($route,'<?php if(!in_array($_SERVER["REMOTE_ADDR"]??"",["127.0.0.1","::1"],true)||!hash_equals('
        .var_export($token,true).',(string)($_GET["qa"]??""))){http_response_code(404);exit;}define("SYNK_MANAGEMENT_CRUD_QA",true);require '
        .var_export($fixture,true).';');
    $prefix=(string)random_int(100000000,999999999);
    $first=$prefix.'1';
    $second=$prefix.'2';
    $missing=$prefix.'3';
    foreach ([[$first,$missing,$first,$missing],[$first,$second,$first,$second]] as $numbers) {
        $path=tempnam(sys_get_temp_dir(),'synk-frc-');
        if ($path===false) throw new RuntimeException('Unable to allocate a workbook fixture.');
        $paths[]=$path;
        synk_test_write_frc_workbook($path,$date,$numbers);
    }
    [$original,$corrected]=$paths;
    $upload=static function(string $mode,string $path,string $action,?string $initial=null,array $overrides=[])use($routeName,$token,$first,$second,$academic,$date): array {
        $mime='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $post=$overrides+['action'=>$action,'title'=>'FLAG RAISING CEREMONY QA','template'=>'FRC','first_number'=>$first,'second_number'=>$second,
            'event_date'=>$date,'import_ay_id'=>(string)$academic['ay_id'],'import_semester'=>(string)$academic['semester'],
            'import_exam_term'=>'MIDTERM','workbook'=>new CURLFile($path,$mime,'FRC upload.xlsx')];
        if ($initial!==null) $post['initial_workbook']=new CURLFile($initial,$mime,'FRC initial.xlsx');
        $url='http://localhost/synk/tmp/'.$routeName.'?qa='.$token.'&scenario=attendance_import_http_'.$mode;
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25]);
        $body=curl_exec($curl);
        $http=curl_getinfo($curl,CURLINFO_HTTP_CODE);
        curl_close($curl);
        $data=is_string($body) ? json_decode($body,true) : null;
        if ($http!==200 || !isset($data['response']) || !is_array($data['response'])) {
            throw new RuntimeException('The '.$mode.' multipart fixture did not return a usable HTTP response ('.$http.').');
        }
        return $data;
    };

    $preview=$upload('preview',$original,'attendance_past_preview');
    $review=[['student_number'=>$missing,'cells'=>['A3','A5']]];
    $check($preview['status']===200 && $preview['response']['preview']['event_date']===$date
        && $preview['response']['preview']['new_present']===1 && $preview['response']['preview']['duplicates']===2
        && $preview['response']['preview']['unmatched']===[$missing] && $preview['response']['preview']['review']['unmatched']===$review
        && $preview['response']['preview']['review']['duplicates']===[['student_number'=>$first,'cells'=>['A2','A4']],$review[0]]
        && $preview['before']===$preview['after'],
        'The real upload preview returned incorrect matches, cell references, or changes.');
    $stale=$upload('stale',$original,'attendance_past_save');
    $check($stale['status']===409 && $stale['before']===$stale['after'],'Saving without a current preview changed attendance.');
    foreach ([
        ['event_date','',422,'valid date'],
        ['event_date',date('Y-m-d',strtotime($date.' +1 day')),422,'match the workbook date'],
        ['import_ay_id','0',409,'academic year or semester'],
        ['import_semester','3',409,'academic year or semester'],
        ['import_exam_term','PRELIM',422,'Choose Midterm or Final'],
    ] as [$field,$value,$status,$message]) {
        $rejected=$upload('preview',$original,'attendance_past_preview',null,[$field=>$value]);
        $check($rejected['status']===$status && strpos($rejected['response']['message'],$message)!==false && $rejected['before']===$rejected['after'],
            'Invalid '.$field.' did not return a clear error with no changes.');
    }
    $saved=$upload('save',$original,'attendance_past_save');
    $check($saved['status']===200 && $saved['response']['recorded']===1 && $saved['response']['review']['unmatched']===$review
        && count($saved['after']['tbl_management_events'])===count($saved['before']['tbl_management_events'])+1
        && count($saved['after']['tbl_management_attendance_logs'])===count($saved['before']['tbl_management_attendance_logs'])+1,
        'The first upload did not save exactly one event and one present student.');
    $finalFrc=$upload('save',$original,'attendance_past_save',null,['import_exam_term'=>'FINAL']);
    $finalFrcId=$finalFrc['response']['event_id'] ?? 0;
    $finalFrcTerms=array_column($finalFrc['after']['tbl_management_events'],'exam_term','event_id');
    $check($finalFrc['status']===200 && $finalFrc['response']['exam_term']==='FINAL' && ($finalFrcTerms[$finalFrcId] ?? null)==='FINAL'
        && $finalFrc['before']['tbl_management_period_settings']===$finalFrc['after']['tbl_management_period_settings'],
        'The FRC upload did not save to the explicitly selected Final term.');
    $recheck=$upload('corrected_preview',$corrected,'attendance_past_preview',$original);
    $check($recheck['status']===200 && $recheck['response']['preview']['existing_event']
        && $recheck['response']['preview']['new_present']===1 && $recheck['response']['preview']['already_present']===1
        && $recheck['response']['preview']['review']['unmatched']===[] && $recheck['before']===$recheck['after'],
        'The corrected workbook did not clear its unmatched ID or recognize the existing attendance.');
    $resaved=$upload('corrected_save',$corrected,'attendance_past_save',$original);
    $check($resaved['status']===200 && $resaved['response']['recorded']===1 && $resaved['response']['review']['unmatched']===[]
        && $resaved['before']['tbl_management_events']===$resaved['after']['tbl_management_events']
        && count($resaved['after']['tbl_management_attendance_logs'])===count($resaved['before']['tbl_management_attendance_logs'])+1
        && $resaved['before']['tbl_management_period_settings']===$resaved['after']['tbl_management_period_settings'],
        'Correcting the workbook duplicated the event or changed the operating period.');

    $cud=tempnam(sys_get_temp_dir(),'synk-cud-');
    if ($cud===false) throw new RuntimeException('Unable to allocate the CUD fixture.');
    $paths[]=$cud;
    synk_test_write_cud_workbook($cud,[['D'=>$first,'E'=>$first],['D'=>$second],['E'=>$second],
        ['D'=>$first,'E'=>$first],['B'=>'Student','C'=>'Blank'],['D'=>$missing,'E'=>$missing],
        ['A'=>'NO.','D'=>'IN','E'=>'OUT'],['D'=>$second,'E'=>$first],['E'=>'SIGNED']]);
    $cudInputs=['title'=>'CLEAN UP DRIVE QA','template'=>'EVENT','absence_fine'=>'25.50','description'=>'Campus cleanup'];
    $cudPreview=$upload('preview',$cud,'attendance_past_preview',null,$cudInputs);
    $cudPlan=$cudPreview['response']['preview'];
    $check($cudPreview['status']===200 && $cudPreview['before']===$cudPreview['after'] && $cudPlan['present']===1
        && $cudPlan['absent']===$cudPlan['roster_count']-1 && $cudPlan['absence_fine']==='25.50'
        && $cudPlan['workbook_counts']===['complete'=>3,'one_entry'=>2,'blank'=>1] && $cudPlan['duplicates']===2
        && $cudPlan['review']['unmatched']===[['student_number'=>$missing,'cells'=>['D7','E7']]] && count($cudPlan['review']['problems'])===2,
        'CUD preview combined split entries, missed review rows, or miscounted fines.');
    $legacy=$upload('preview',$cud,'attendance_past_preview',null,['template'=>'CUD']+$cudInputs);
    $check($legacy['status']===200 && $legacy['before']===$legacy['after'] && $legacy['response']['preview']['present']===$cudPlan['present'],
        'An existing CUD upload request no longer follows the EVENT rules.');
    $wrongTemplate=$upload('preview',$original,'attendance_past_preview',null,$cudInputs);
    $check($wrongTemplate['status']===422 && $wrongTemplate['before']===$wrongTemplate['after'],'The CUD endpoint accepted the FRC layout.');
    $missingFine=$upload('preview',$cud,'attendance_past_preview',null,array_replace($cudInputs,['absence_fine'=>'']));
    $check($missingFine['status']===422 && $missingFine['before']===$missingFine['after'],'CUD accepted an unspecified fine.');
    $unconfirmed=$upload('save',$cud,'attendance_past_save',null,$cudInputs);
    $check($unconfirmed['status']===422 && $unconfirmed['before']===$unconfirmed['after'],'CUD closed attendance without confirmation.');
    $staleFine=$upload('cud_stale_fine',$cud,'attendance_past_save',null,$cudInputs+['confirm'=>'1']);
    $check($staleFine['status']===409 && $staleFine['before']===$staleFine['after'],'CUD saved an absence fine changed after review.');
    $cudSaved=$upload('save',$cud,'attendance_past_save',null,$cudInputs+['confirm'=>'1']);
    $receipt=$cudSaved['response'];
    $attendance=array_values(array_filter($cudSaved['after']['tbl_management_attendance_events'],static function($row)use($receipt){return (int)$row['event_id']===$receipt['event_id'];}))[0];
    $check($cudSaved['status']===200 && $receipt['exam_term']==='MIDTERM' && $receipt['attendance_status']==='CLOSED'
        && $receipt['present']===1 && $receipt['absent']===$cudPlan['absent'] && $attendance['attendance_status']==='CLOSED'
        && $attendance['absence_fine']==='25.50' && count($cudSaved['after']['tbl_management_attendance_logs'])===count($cudSaved['before']['tbl_management_attendance_logs'])+1,
        'CUD did not save exactly one present student or failed to close attendance with the fine.');
    $finalEventInput=['import_exam_term'=>'FINAL','confirm'=>'1']+$cudInputs;
    $finalEventPreview=$upload('preview',$cud,'attendance_past_preview',null,$finalEventInput);
    $check($finalEventPreview['status']===200 && $finalEventPreview['response']['preview']['exam_term']==='FINAL'
        && substr($finalEventPreview['response']['preview']['period'],-8)===' - Final',
        'The EVENT preview did not show the selected Final term.');
    $finalEvent=$upload('save',$cud,'attendance_past_save',null,$finalEventInput);
    $finalEventId=$finalEvent['response']['event_id'] ?? 0;
    $finalEventTerms=array_column($finalEvent['after']['tbl_management_events'],'exam_term','event_id');
    $finalAttendanceTerms=array_column($finalEvent['after']['tbl_management_attendance_events'],'exam_term','event_id');
    $check($finalEvent['status']===200 && $finalEvent['response']['exam_term']==='FINAL'
        && ($finalEventTerms[$finalEventId] ?? null)==='FINAL' && ($finalAttendanceTerms[$finalEventId] ?? null)==='FINAL'
        && $finalEvent['before']['tbl_management_period_settings']===$finalEvent['after']['tbl_management_period_settings'],
        'The EVENT upload did not save both the event and finalized attendance to the selected term.');
    $staleTerm=$upload('cud_stale_term',$cud,'attendance_past_save',null,$finalEventInput);
    $check($staleTerm['status']===409 && $staleTerm['before']===$staleTerm['after'],'The EVENT upload accepted a term changed after preview.');
    $replay=$upload('cud_replay',$cud,'attendance_past_save',null,$cudInputs+['confirm'=>'1']);
    $check($replay['status']===409 && $replay['before']===$replay['after'],'Repeating the save changed closed CUD attendance.');
    $roster=$upload('cud_roster',$cud,'attendance_roster',null,$cudInputs+['confirm'=>'1']);
    $student=$roster['response']['roster']['rows'][0];
    $check($roster['status']===200 && $roster['before']===$roster['after'] && $roster['response']['roster']['total']===1
        && $student['student_number']===$second && $student['checked_in'] && $student['checked_out'] && $student['attendance_status']==='ABSENT',
        'Closed attendance HTTP review lost the filtered student, their checkpoint evidence or the original status.');
    $correctedCud=$upload('cud_correction',$cud,'attendance_correct',null,$cudInputs+['confirm'=>'1']);
    $check($correctedCud['status']===200 && $correctedCud['response']['attendance']['attendance_status']==='CLOSED'
        && $correctedCud['response']['attendance']['present']===2 && $correctedCud['response']['attendance']['revision']==='3'
        && count($correctedCud['after']['tbl_management_attendance_corrections'])===count($correctedCud['before']['tbl_management_attendance_corrections'])+1
        && $correctedCud['before']['tbl_management_attendance_checkpoints']===$correctedCud['after']['tbl_management_attendance_checkpoints']
        && end($correctedCud['after']['tbl_management_attendance_corrections'])['reason']==='Verified paper attendance',
        'The HTTP correction did not retain closed attendance, update presence or record the correction.');

    if (isset($argv[1])) {
        $source=synk_management_parse_attendance_workbook($argv[1],basename($argv[1]),'FRC');
        $month=(int)substr($source['event_date'],5,2);
        $receipt=$upload('attached',$argv[1],'attendance_past_preview',null,['event_date'=>$source['event_date'],
            'import_exam_term'=>$month>=11 || ($month>=4 && $month<=6) ? 'FINAL' : 'MIDTERM']);
        $check($receipt['status']===200 && $receipt['before']===$receipt['after'],'The supplied workbook preview failed or changed stored data.');
        $result=$receipt['response']['preview'];
        echo 'Supplied workbook: ',$result['event_date'],', ',$result['new_present'],' matches, ',
            count($result['review']['unmatched']),' unmatched IDs with cell references, ',$result['duplicates'],' duplicate entries.',PHP_EOL;
    }
    if (isset($argv[2])) {
        $realCud=$upload('attached',$argv[2],'attendance_past_preview',null,$cudInputs);
        $check($realCud['status']===200 && $realCud['before']===$realCud['after']
            && $realCud['response']['preview']['workbook_counts']['complete']>0,'The supplied CUD workbook did not preview safely through Apache.');
    }
    $check($snapshot()===$baseline,'The HTTP fixture left stored records behind.');
    echo 'Management multipart uploads: ',$checks,' checks passed through Apache PHP ',$preview['php_version'],' (fixtures rolled back).',PHP_EOL;
} finally {
    foreach ($paths as $path) if (is_file($path)) unlink($path);
    if (is_file($route)) unlink($route);
}
