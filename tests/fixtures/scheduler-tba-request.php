<?php
if (PHP_SAPI !== 'cli' && !defined('SYNK_SCHEDULER_TBA_QA')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__,2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/academic_term_helper.php';
require_once $root . '/backend/offering_scope_helper.php';
require_once $root . '/backend/schedule_tba_helper.php';
class SchedulerTbaQAConnection extends mysqli
{
    public $failHistory=false;
    private $historyWrites=0;
    #[\ReturnTypeWillChange]
    public function query($sql,$mode=MYSQLI_STORE_RESULT) {
        // Legacy workload helpers issue CREATE IF NOT EXISTS on already installed tables.
        // Suppress those no-ops here so they cannot implicitly commit the fixture transaction.
        if (preg_match('/^\s*CREATE TABLE IF NOT EXISTS `?([a-z0-9_]+)`?/i',$sql,$match)) {
            $existing=parent::query("SHOW TABLES LIKE '" . $this->real_escape_string($match[1]) . "'");
            if ($existing && $existing->num_rows) { return true; }
        }
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql)) { throw new RuntimeException('Request attempted schema mutation.'); }
        return parent::query($sql,$mode);
    }
    #[\ReturnTypeWillChange]
    public function prepare($sql) {
        if ($this->failHistory && strpos($sql,'INSERT INTO tbl_class_schedule_tba_event')!==false && ++$this->historyWrites===2) { throw new RuntimeException('Injected second event failure.'); }
        return parent::prepare($sql);
    }
    public function startFixtures():void { parent::begin_transaction(); }
    public function discardFixtures():void { parent::rollback(); }
    public function begin_transaction($flags=null,$name=null):bool { return $this->query('SAVEPOINT tba_request'); }
    public function commit($flags=null,$name=null):bool { return $this->query('RELEASE SAVEPOINT tba_request'); }
    public function rollback($flags=null,$name=null):bool { $this->query('ROLLBACK TO SAVEPOINT tba_request'); return $this->query('RELEASE SAVEPOINT tba_request'); }
}
$conn->close();$conn=new SchedulerTbaQAConnection($servername,$username,$password,$dbase);$conn->set_charset('utf8mb4');$conn->startFixtures();
$scenario=(string)($_GET['scenario'] ?? ($argv[1] ?? 'create'));
$incomingBlocks=$_POST['blocks'] ?? [];
$tag='TBAQA' . bin2hex(random_bytes(5));
$term=synk_fetch_current_academic_term($conn);
$joins=synk_section_curriculum_live_offering_join_sql('po','sec','sc','ps','pys','ph');
$template=$conn->query("SELECT po.*, p.college_id, c.campus_id, cs.room_id AS template_room, pys.pys_id
    FROM tbl_class_schedule cs JOIN tbl_prospectus_offering po ON po.offering_id=cs.offering_id {$joins}
    JOIN tbl_program p ON p.program_id=po.program_id JOIN tbl_college c ON c.college_id=p.college_id
    JOIN tbl_room_college_access acc ON acc.room_id=cs.room_id AND acc.college_id=p.college_id AND acc.ay_id=po.ay_id AND acc.semester=po.semester
    WHERE po.ay_id={$term['ay_id']} AND po.semester={$term['semester']} AND cs.schedule_type='LEC' AND ps.lab_units>0
    ORDER BY po.offering_id LIMIT 1")->fetch_assoc();
if (!$template) { throw new RuntimeException('A current lecture/lab offering with room access is required.'); }
$college=(int)$template['college_id'];$user=(int)$conn->query("SELECT user_id FROM tbl_useraccount WHERE status='active' ORDER BY user_id LIMIT 1")->fetch_assoc()['user_id'];
$clone=static function(string $table,array $row,array $values) use($conn):int {
    $columns=$conn->query('SHOW COLUMNS FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC);$fields=[];$params=[];
    foreach($columns as $column) {
        if (strpos($column['Extra'],'auto_increment')!==false || stripos($column['Extra'],'generated')!==false) continue;
        $field=$column['Field'];if (!array_key_exists($field,$values) && !array_key_exists($field,$row)) continue;
        $fields[]='`' . $field . '`';$params[]=array_key_exists($field,$values) ? $values[$field] : $row[$field];
    }
    $stmt=$conn->prepare('INSERT INTO `' . $table . '` (' . implode(',',$fields) . ') VALUES (' . implode(',',array_fill(0,count($fields),'?')) . ')');
    $types=str_repeat('s',count($params));$refs=[$types];foreach($params as &$param)$refs[]=&$param;unset($param);call_user_func_array([$stmt,'bind_param'],$refs);
    $stmt->execute();$id=(int)$conn->insert_id;$stmt->close();return $id;
};
$programmeRow=$conn->query('SELECT * FROM tbl_program WHERE program_id=' . (int)$template['program_id'])->fetch_assoc();
$programme=$clone('tbl_program',$programmeRow,['program_code'=>$tag,'program_name'=>'TBA QA Programme']);
$header=$conn->query('SELECT * FROM tbl_prospectus_header WHERE prospectus_id=' . (int)$template['prospectus_id'])->fetch_assoc();
$prospectus=$clone('tbl_prospectus_header',$header,['program_id'=>$programme]);
$yearRow=$conn->query('SELECT * FROM tbl_prospectus_year_sem WHERE pys_id=' . (int)$template['pys_id'])->fetch_assoc();
$pys=$clone('tbl_prospectus_year_sem',$yearRow,['prospectus_id'=>$prospectus]);
$subjectRow=$conn->query('SELECT * FROM tbl_prospectus_subjects WHERE ps_id=' . (int)$template['ps_id'])->fetch_assoc();
$ps=$clone('tbl_prospectus_subjects',$subjectRow,['pys_id'=>$pys]);
$sectionRow=$conn->query('SELECT * FROM tbl_sections WHERE section_id=' . (int)$template['section_id'])->fetch_assoc();
$makeOffering=static function(string $suffix,int $section=0) use($conn,$clone,$sectionRow,$template,$programme,$prospectus,$ps,$tag,$user):array {
    if (!$section) {
        $section=$clone('tbl_sections',$sectionRow,['program_id'=>$programme,'section_name'=>substr($tag,-6).$suffix,'full_section'=>$tag.$suffix]);
        $conn->query("INSERT INTO tbl_section_curriculum (section_id,prospectus_id,assigned_by) VALUES ({$section},{$prospectus},{$user})");
    }
    $offering=$clone('tbl_prospectus_offering',$template,['program_id'=>$programme,'prospectus_id'=>$prospectus,'ps_id'=>$ps,'section_id'=>$section,'status'=>'pending']);
    return ['offering_id'=>$offering,'section_id'=>$section];
};
$target=$makeOffering('A');$other=$makeOffering('B');
$facultyRow=$conn->query("SELECT * FROM tbl_faculty WHERE status='active' LIMIT 1")->fetch_assoc();
$faculty=$clone('tbl_faculty',$facultyRow,['last_name'=>'Peña '.$tag,'first_name'=>'José','middle_name'=>'Niño']);
$conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$college},{$faculty},{$term['ay_id']},{$term['semester']},'active')");
$roomRow=$conn->query('SELECT * FROM tbl_rooms WHERE room_id=' . (int)$template['template_room'])->fetch_assoc();
$room=$clone('tbl_rooms',$roomRow,['room_code'=>'Q'.substr($tag,-6).'1','room_name'=>'TBA QA Room 1','room_type'=>'lec_lab']);
$room2=$clone('tbl_rooms',$roomRow,['room_code'=>'Q'.substr($tag,-6).'2','room_name'=>'TBA QA Room 2','room_type'=>'lec_lab']);
$access=$conn->query('SELECT * FROM tbl_room_college_access WHERE room_id=' . (int)$template['template_room'] . ' AND college_id=' . $college . ' AND ay_id=' . $term['ay_id'] . ' AND semester=' . $term['semester'] . ' LIMIT 1')->fetch_assoc();
foreach([$room,$room2] as $roomId)$clone('tbl_room_college_access',$access,['room_id'=>$roomId]);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$_SESSION=['user_id'=>$user,'role'=>'scheduler','college_id'=>$college,'campus_id'=>(int)$template['campus_id'],'csrf_token'=>'tba-qa'];
$makeSchedule=static function(array $offering,string $type,string $status='tba',bool $assigned=false,int $chosenRoom=0,string $start='09:00:00',string $end='10:00:00') use($conn,$faculty,$term,$user):int {
    $days=$status==='tba' ? '[]' : '["M"]';$start=$status==='tba' ? null : $start;$end=$status==='tba' ? null : $end;$chosenRoom=$status==='scheduled' ? $chosenRoom : null;
    $stmt=$conn->prepare('INSERT INTO tbl_class_schedule (offering_id,schedule_type,schedule_status,room_id,days_json,time_start,time_end,created_by) VALUES (?,?,?,?,?,?,?,?)');
    $offeringId=$offering['offering_id'];$stmt->bind_param('ississsi',$offeringId,$type,$status,$chosenRoom,$days,$start,$end,$user);$stmt->execute();$id=(int)$stmt->insert_id;$stmt->close();
    if($assigned)$conn->query("INSERT INTO tbl_faculty_workload_sched (schedule_id,faculty_id,ay_id,semester) VALUES ({$id},{$faculty},{$term['ay_id']},{$term['semester']})");
    return $id;
};
$block=static function(string $type,string $status='tba',int $id=0,int $chosenRoom=0,string $start='09:00',string $end='10:00'):array {
    return ['schedule_id'=>$id,'type'=>$type,'schedule_status'=>$status,'is_inherited'=>'false','room_id'=>$status==='scheduled' ? $chosenRoom : '',
        'time_start'=>$status==='tba' ? '' : $start,'time_end'=>$status==='tba' ? '' : $end,'days_json'=>$status==='tba' ? '[]' : '["M"]'];
};
$_SERVER['REQUEST_METHOD']='POST';$_POST=['save_schedule_blocks'=>1,'csrf_token'=>'tba-qa','offering_id'=>$target['offering_id'],'blocks'=>[$block('LEC'),$block('LAB')]];
$handler='backend/query_class_schedule.php';$expectedIds=[];
if (strpos($scenario,'resolve')===0 || in_array($scenario,['assigned_edit','assigned_add','assigned_remove','load_assigned'],true)) {
    $lec=$makeSchedule($target,'LEC','tba',true);$lab=$makeSchedule($target,'LAB','tba',true);$expectedIds=[$lec,$lab];
    $_POST['blocks']=[$block('LEC','scheduled',$lec,$room,'10:00','12:00'),$block('LAB','tba',$lab)];
}
switch($scenario) {
case 'create':break;
case 'form_create':$_POST['blocks']=$incomingBlocks;break;
case 'create_room':$_POST['blocks'][0]=$block('LEC','room_tba');break;
case 'normal_save':$_POST['blocks']=[$block('LEC','scheduled',0,$room,'10:00','12:00'),$block('LAB','scheduled',0,$room2,'13:00','16:00')];break;
case 'missing_room':$_POST['blocks'][0]=$block('LEC','scheduled');break;
case 'invalid_slot':$_POST['blocks'][0]['time_start']='09:00';break;
case 'invalid_status':$_POST['blocks'][0]['schedule_status']='override';break;
case 'array_input':$_POST['blocks'][0]['room_id']=[1];break;
case 'bad_csrf':$_POST['csrf_token']='bad';break;
case 'wrong_role':$_SESSION['role']='professor';break;
case 'foreign_offering':$_SESSION['college_id']=2147483647;break;
case 'foreign_schedule':$_POST['blocks'][0]['schedule_id']=$makeSchedule($other,'LEC');break;
case 'locked':$conn->query("UPDATE tbl_prospectus_offering SET status='locked' WHERE offering_id={$target['offering_id']}");break;
case 'history_failure':$conn->failHistory=true;break;
case 'resolve_ok':break;
case 'resolve_all':$_POST['blocks'][1]=$block('LAB','scheduled',$lab,$room2,'13:00','16:00');break;
case 'resolve_room_ok':case 'resolve_room_change_time':
    $conn->query("UPDATE tbl_class_schedule SET schedule_status='room_tba',days_json='[\"M\"]',time_start='10:00:00',time_end='12:00:00' WHERE schedule_id={$lec}");
    if($scenario==='resolve_room_change_time'){$_POST['blocks'][0]['time_start']='13:00';$_POST['blocks'][0]['time_end']='15:00';}break;
case 'resolve_need_ok':case 'resolve_need_conflict':
    $need=$clone('tbl_faculty_need',[],['college_id'=>$college,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'need_label'=>$tag,'status'=>'active','created_by'=>$user]);
    $conn->query("DELETE FROM tbl_faculty_workload_sched WHERE schedule_id IN ({$lec},{$lab})");
    foreach([$lec,$lab] as $needSchedule)$conn->query("INSERT INTO tbl_faculty_need_workload_sched (faculty_need_id,schedule_id,ay_id,semester) VALUES ({$need},{$needSchedule},{$term['ay_id']},{$term['semester']})");
    if($scenario==='resolve_need_conflict'){$needOther=$makeSchedule($other,'LEC','scheduled',false,$room2,'10:00:00','12:00:00');$conn->query("INSERT INTO tbl_faculty_need_workload_sched (faculty_need_id,schedule_id,ay_id,semester) VALUES ({$need},{$needOther},{$term['ay_id']},{$term['semester']})");}break;
case 'resolve_faculty_conflict':$makeSchedule($other,'LEC','scheduled',true,$room2,'10:00:00','12:00:00');break;
case 'resolve_room_conflict':$makeSchedule($other,'LEC','scheduled',false,$room,'10:00:00','12:00:00');break;
case 'resolve_section_conflict':$same=$makeOffering('S',$target['section_id']);$makeSchedule($same,'LEC','scheduled',false,$room2,'10:00:00','12:00:00');break;
case 'resolve_internal':$_POST['blocks'][1]=$block('LAB','room_tba',$lab,0,'10:00','12:00');break;
case 'resolve_wrong_room':$_POST['blocks'][0]['room_id']=2147483647;break;
case 'resolve_lab_wrong_type':
    $conn->query("UPDATE tbl_rooms SET room_type='lecture' WHERE room_id={$room2}");$_POST['blocks'][1]=$block('LAB','scheduled',$lab,$room2,'13:00','16:00');break;
case 'resolve_external_faculty_conflict':
    $foreign=$conn->query("SELECT po.*,cs.room_id AS template_room FROM tbl_class_schedule cs JOIN tbl_prospectus_offering po ON po.offering_id=cs.offering_id {$joins}
        JOIN tbl_program p ON p.program_id=po.program_id WHERE p.college_id<>{$college} AND po.ay_id={$term['ay_id']} AND po.semester={$term['semester']} AND cs.schedule_type='LEC' ORDER BY po.offering_id LIMIT 1")->fetch_assoc();
    if(!$foreign)throw new RuntimeException('An offering from another college is required for global faculty-conflict verification.');
    $foreignSection=$clone('tbl_sections',$conn->query('SELECT * FROM tbl_sections WHERE section_id=' . (int)$foreign['section_id'])->fetch_assoc(),['section_name'=>substr($tag,-6).'F','full_section'=>$tag.'F']);
    $conn->query("INSERT INTO tbl_section_curriculum (section_id,prospectus_id,assigned_by) VALUES ({$foreignSection},{$foreign['prospectus_id']},{$user})");
    $foreignOffering=$clone('tbl_prospectus_offering',$foreign,['section_id'=>$foreignSection,'status'=>'pending']);
    $makeSchedule(['offering_id'=>$foreignOffering],'LEC','scheduled',true,(int)$foreign['template_room'],'10:00:00','12:00:00');break;
case 'resolve_blocked_time':$_POST['blocks'][0]['time_start']='03:00';$_POST['blocks'][0]['time_end']='05:00';break;
case 'assigned_edit':$conn->query("UPDATE tbl_class_schedule SET schedule_status='scheduled',room_id={$room},days_json='[\"M\"]',time_start='09:00:00',time_end='10:00:00' WHERE schedule_id={$lec}");break;
case 'assigned_add':$_POST['blocks'][]=$block('LEC');break;
case 'assigned_remove':array_pop($_POST['blocks']);break;
case 'load_assigned':$_POST=['load_schedule_blocks'=>1,'csrf_token'=>'tba-qa','offering_id'=>$target['offering_id']];break;
case 'available':$makeSchedule($target,'LEC');$makeSchedule($target,'LAB');$handler='backend/query_class_schedule_loader.php';$_POST=['faculty_id'=>$faculty,'ay_id'=>$term['ay_id'],'semester_num'=>$term['semester']];break;
case 'workload':$makeSchedule($target,'LEC','tba',true);$makeSchedule($target,'LAB','tba',true);$handler='backend/query_load_faculty_workload.php';$_POST=['faculty_id'=>$faculty,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'assignee_type'=>'faculty'];break;
case 'apply':case 'apply_room_conflict':case 'apply_duplicate':case 'apply_bad_csrf':case 'apply_foreign_faculty':
    $makeSchedule($other,'LEC','scheduled',true,$room2);$pending=$makeSchedule($target,'LEC',$scenario==='apply_room_conflict' ? 'room_tba' : 'tba',$scenario==='apply_duplicate');
    $handler='backend/query_apply_workload.php';$_POST=['faculty_id'=>$faculty,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'schedule_ids'=>[$pending],'csrf_token'=>'tba-qa'];
    if($scenario==='apply_bad_csrf')$_POST['csrf_token']='bad';
    if($scenario==='apply_foreign_faculty')$conn->query("UPDATE tbl_college_faculty SET status='inactive' WHERE faculty_id={$faculty}");
    break;
case 'normal_apply_conflict':
    $makeSchedule($other,'LEC','scheduled',true,$room2);$pending=$makeSchedule($target,'LEC','scheduled',false,$room);
    $handler='backend/query_apply_workload.php';$_POST=['faculty_id'=>$faculty,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'schedule_ids'=>[$pending],'csrf_token'=>'tba-qa'];break;
case 'room_tba_shared_time':$makeSchedule($other,'LEC','room_tba');$_POST['blocks'][0]=$block('LEC','room_tba');break;
case 'merge_pending':case 'merge_normal':
    $makeSchedule($other,'LEC',$scenario==='merge_pending' ? 'tba' : 'scheduled',false,$room,'10:00:00','12:00:00');
    $makeSchedule($other,'LAB',$scenario==='merge_pending' ? 'tba' : 'scheduled',false,$room2,'13:00:00','16:00:00');
    $_POST=['save_schedule_merge'=>1,'csrf_token'=>'tba-qa','target_offering_id'=>$target['offering_id'],'full_owner_offering_id'=>$other['offering_id']];break;
case 'clear_need':case 'clear_all_need':
    $need=$clone('tbl_faculty_need',[],['college_id'=>$college,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'need_label'=>$tag,'status'=>'active','created_by'=>$user]);
    $pending=$makeSchedule($target,'LEC');$conn->query("INSERT INTO tbl_faculty_need_workload_sched (faculty_need_id,schedule_id,ay_id,semester) VALUES ({$need},{$pending},{$term['ay_id']},{$term['semester']})");
    $_POST=$scenario==='clear_need' ? ['clear_schedule'=>1,'csrf_token'=>'tba-qa','offering_id'=>$target['offering_id']] : ['clear_all_college_schedules'=>1,'csrf_token'=>'tba-qa','ay_id'=>$term['ay_id'],'semester'=>$term['semester']];break;
case 'save_set':
    $makeSchedule($target,'LEC','tba',true);$makeSchedule($target,'LAB','room_tba',true);
    $_POST=['save_schedule_set'=>1,'csrf_token'=>'tba-qa','ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'set_name'=>$tag,'include_workload_assignments'=>1];break;
case 'restore_set':
    $set=(int)$clone('tbl_program_schedule_set',[],['college_id'=>$college,'program_id'=>$programme,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'set_name'=>$tag]);
    foreach(['LEC'=>'tba','LAB'=>'room_tba'] as $type=>$status) {
        $row=$clone('tbl_program_schedule_set_row',[],['schedule_set_id'=>$set,'offering_id'=>$target['offering_id'],'program_id'=>$programme,'ps_id'=>$ps,'section_id'=>$target['section_id'],
            'schedule_type'=>$type,'schedule_status'=>$status,'block_order'=>$type==='LEC' ? 1 : 2,'room_id'=>null,'days_json'=>$status==='tba' ? '[]' : '["M"]',
            'time_start'=>$status==='tba' ? null : '09:00:00','time_end'=>$status==='tba' ? null : '10:00:00','subject_code_snapshot'=>'QA','subject_description_snapshot'=>'QA','section_name_snapshot'=>$tag,'room_label_snapshot'=>'TBA']);
        $clone('tbl_program_schedule_set_workload_row',[],['schedule_set_id'=>$set,'schedule_set_row_id'=>$row,'offering_id'=>$target['offering_id'],'program_id'=>$programme,
            'schedule_type'=>$type,'block_order'=>$type==='LEC' ? 1 : 2,'faculty_id'=>$faculty,'faculty_name_snapshot'=>'Peña José','subject_code_snapshot'=>'QA','section_name_snapshot'=>$tag]);
    }
    $_POST=['load_schedule_set_into_live'=>1,'csrf_token'=>'tba-qa','schedule_set_id'=>$set,'restore_workload_assignments'=>1];break;
case 'page':$handler='scheduler/manage-class-schedule.php';$_POST=[];break;
default:throw new RuntimeException('Unknown TBA scenario.');
}
$take=static function() use($conn,$programme,$faculty,$tag):array {
    $rows=$conn->query('SELECT cs.*,ps.lec_units,ps.lab_units,ps.total_units FROM tbl_class_schedule cs JOIN tbl_prospectus_offering po ON po.offering_id=cs.offering_id JOIN tbl_prospectus_subjects ps ON ps.ps_id=po.ps_id WHERE po.program_id=' . $programme . ' ORDER BY cs.schedule_id')->fetch_all(MYSQLI_ASSOC);
    $load=$conn->query('SELECT * FROM tbl_faculty_workload_sched WHERE faculty_id=' . $faculty . ' ORDER BY workload_id')->fetch_all(MYSQLI_ASSOC);
    $needLoad=$conn->query('SELECT nw.* FROM tbl_faculty_need_workload_sched nw JOIN tbl_class_schedule cs ON cs.schedule_id=nw.schedule_id JOIN tbl_prospectus_offering po ON po.offering_id=cs.offering_id WHERE po.program_id=' . $programme . ' ORDER BY nw.need_workload_id')->fetch_all(MYSQLI_ASSOC);
    $events=$conn->query('SELECT ev.* FROM tbl_class_schedule_tba_event ev JOIN tbl_prospectus_offering po ON po.offering_id=ev.offering_id WHERE po.program_id=' . $programme . ' ORDER BY ev.tba_event_id')->fetch_all(MYSQLI_ASSOC);
    $sets=$conn->query("SELECT r.* FROM tbl_program_schedule_set_row r JOIN tbl_program_schedule_set s ON s.schedule_set_id=r.schedule_set_id WHERE s.set_name='" . $tag . "' ORDER BY r.schedule_set_row_id")->fetch_all(MYSQLI_ASSOC);
    return ['classes'=>$rows,'workload'=>$load,'need_workload'=>$needLoad,'events'=>$events,'saved_rows'=>$sets];
};
$before=$take();session_write_close();ob_start();
register_shutdown_function(static function() use($conn,$take,$before,$target,$faculty,$room,$room2,$expectedIds,$scenario):void {
    $body='';while(ob_get_level()>0)$body=ob_get_clean().$body;
    try {
        $after=$take();$result=['response'=>json_decode($body,true) ?: $body,'before'=>$before,'after'=>$after,'target'=>$target,
            'faculty_id'=>$faculty,'room_id'=>$room,'room2_id'=>$room2,'expected_ids'=>$expectedIds,'scenario'=>$scenario,'php_version'=>PHP_VERSION];
        $conn->discardFixtures();if(session_status()===PHP_SESSION_ACTIVE)session_destroy();http_response_code(200);header('Content-Type: application/json; charset=utf-8');echo json_encode($result,JSON_UNESCAPED_UNICODE);
    } catch(Throwable $e) {$conn->discardFixtures();http_response_code(500);echo json_encode(['fixture_error'=>$e->getMessage(),'output'=>substr($body,0,2200)]);}
});
// Keep the fixture connection while executing the unchanged production endpoint body.
$source=file_get_contents($root . '/' . $handler);
$source=preg_replace('/\b(?:include|require(?:_once)?)\s+[\'"](?:\.\.\/backend\/)?db\.php[\'"];/', '',$source);
$source=str_replace('__DIR__',var_export(dirname($root . '/' . $handler),true),$source);
chdir(dirname($root . '/' . $handler));eval('?>' . $source);
