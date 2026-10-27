<?php
// Integration checks for event periods, sessions and contribution links. All roll back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/management_catalog_helper.php';
class ManagementEventPeriodConnection extends mysqli
{
    public function start(): void { parent::begin_transaction(); }
    public function discard(): void { parent::rollback(); }
    public function begin_transaction($flags=0,$name=null): bool { return $this->query('SAVEPOINT event_period_test'); }
    public function commit($flags=0,$name=null): bool { return $this->query('RELEASE SAVEPOINT event_period_test'); }
    public function rollback($flags=0,$name=null): bool {
        $this->query('ROLLBACK TO SAVEPOINT event_period_test');
        return $this->query('RELEASE SAVEPOINT event_period_test');
    }
}
$snapshot=static function(mysqli $db):array {
    $out=[];
    foreach(['tbl_management_events','tbl_management_event_sessions','tbl_management_contributions','tbl_management_period_settings','tbl_academic_settings','tbl_useraccount','tbl_student_management']as$table) {
        $out[$table]=hash('sha256',json_encode($db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $out;
};
$before=$snapshot($conn);$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if(!$ok)throw new RuntimeException($message);$checks++; };
$db=new ManagementEventPeriodConnection($servername,$username,$password,$dbase);$db->set_charset('utf8mb4');$db->start();
try {
    $account=$db->query('SELECT * FROM tbl_useraccount WHERE management_organization_id IS NOT NULL ORDER BY user_id LIMIT 1')->fetch_assoc();
    if(!$account)throw new RuntimeException('A Management assignment is required.');
    $_SESSION=['user_id'=>(int)$account['user_id'],'role'=>'management','auth_method'=>'google'];
    $scope=['college_id'=>(int)$account['management_college_id'],'organization_id'=>(int)$account['management_organization_id']];
    $prefix='EVENT PERIOD QA '.bin2hex(random_bytes(6));
    $period=synk_management_fetch_current_period($db,$scope);
    $input=static function(array $period,array $values=[]):array {
        return array_merge(['current_term'=>$period['key'],'current_exam_term'=>$period['exam_term'],'current_period_version'=>$period['period_version'],
            'term'=>$period['key'],'exam_term'=>$period['exam_term'],'record_term'=>$period['key'],'title'=>'','description'=>'',
            'event_date'=>'2026-10-27','contribution_date'=>'2026-10-27','amount'=>'12.35','event_id'=>'','sessions'=>'[]'],$values);
    };
    $session=static function(string $date):array { return ['title'=>'MORNING','starts_at'=>$date.' 08:00:00','ends_at'=>$date.' 12:00:00','fine_per_minute'=>'0.1250','grace_minutes'=>'15']; };
    $add=static function(array $period,string $title,string $date,array $values=[])use($db,$scope,$input,$session):int {
        return synk_management_catalog_write($db,$scope,'events','save',$input($period,array_merge(['title'=>$title,'event_date'=>$date,'sessions'=>json_encode([$session($date)])],$values)))['record_id'];
    };
    $list=static function(array $period,array $values=[])use($db,$scope,$prefix):array {
        return synk_management_catalog_list($db,$scope,$period,'events',array_merge(['search'=>$prefix,'page_size'=>'100','catalog_scope'=>'all'],$values));
    };
    $reject=static function(array $values,string $action='save')use($db,$scope,$check):void {
        try { synk_management_catalog_write($db,$scope,'events',$action,$values);$check(false,'An event write outside its period was accepted.'); }
        catch(SynkManagementConflict $error){$check(true,'');}
    };
    $first=$add($period,$prefix.' FIRST','2099-12-31',['status'=>'INACTIVE']);
    $second=$add($period,$prefix.' SECOND','2000-01-01');
    $check(array_map('intval',array_column($list($period)['rows'],'event_id'))===[$second,$first],'New events did not appear first regardless of date.');
    $summary=$list($period)['summary'];
    $check((int)$summary['active_count']===1 && (int)$summary['inactive_count']===1,'Event status counts are incorrect.');
    $check(array_map('intval',array_column($list($period,['status'=>'ACTIVE'])['rows'],'event_id'))===[$second],'Active event filter is incorrect.');
    $old=synk_management_catalog_get($db,$scope,'events',$first);
    $check(synk_management_catalog_in_period($old,$period) && count($old['sessions'])===1 && $old['sessions'][0]['status']==='ACTIVE','Event/session defaults or stored period are incorrect.');
    synk_management_catalog_write($db,$scope,'contributions','save',$input($period,['title'=>$prefix.' LINKED','event_id'=>$first]));
    $old=synk_management_catalog_get($db,$scope,'events',$first);
    foreach(['DRAFT','OPEN','CLOSED','ARCHIVED']as$oldStatus){
        try { $add($period,$prefix.' INVALID','2026-10-27',['status'=>$oldStatus]);$check(false,'An old event status was accepted.'); }
        catch(DomainException $error){$check(true,'');}
    }
    $badSession=$session('2026-10-27');$badSession['status']='OPEN';
    try { $add($period,$prefix.' INVALID SESSION','2026-10-27',['sessions'=>json_encode([$badSession])]);$check(false,'An old session status was accepted.'); }
    catch(DomainException $error){$check(true,'');}
    $otherExam=$period['exam_term']==='MIDTERM'?'FINAL':'MIDTERM';
    synk_management_save_current_exam_term($db,$scope,$input($period,['exam_term'=>$otherExam]));
    $next=synk_management_fetch_current_period($db,$scope);
    $check($list($next)['pagination']['total']===0,'Another exam term exposed earlier events.');
    $third=$add($next,$prefix.' THIRD','2026-10-27',['record_exam_term'=>$period['exam_term']]);
    $record=synk_management_catalog_get($db,$scope,'events',$third);
    $check(synk_management_catalog_in_period($record,$next) && $record['status']==='ACTIVE','The client or October date overrode the saved current event period.');
    $check(array_map('intval',array_column($list($next)['rows'],'event_id'))===[$third] && $list($period)['pagination']['total']===2,'Midterm/Final event lists are mixed.');
    $options=synk_management_catalog_options($db,$scope,$next)['events'];
    $optionIds=array_map('intval',array_column($options,'event_id'));
    $check(in_array($third,$optionIds,true) && !in_array($first,$optionIds,true) && !in_array($second,$optionIds,true),'Contribution event options exposed another period.');
    try { synk_management_catalog_write($db,$scope,'contributions','save',$input($next,['title'=>$prefix.' WRONG LINK','event_id'=>$first]));$check(false,'A contribution linked an event from another exam term.'); }
    catch(DomainException $error){$check(true,'');}
    $reject($input($period,['title'=>$prefix.' STALE']));
    $reject($input($next,['exam_term'=>$period['exam_term'],'title'=>$prefix.' WRONG VIEW']));
    $reject($input($next,['record_id'=>$first,'version'=>$old['version'],'title'=>$old['title']]));
    $reject($input($next,['record_id'=>$first,'version'=>$old['version']]),'delete');
    $check(synk_management_catalog_get($db,$scope,'events',$first)===$old,'A rejected event write changed its sessions or original period.');
    $insert=static function(array $values)use($db,$scope,$next,$prefix):void {
        $row=array_merge(['college_id'=>$scope['college_id'],'organization_id'=>$scope['organization_id'],'ay_id'=>$next['ay_id'],'semester'=>$next['semester'],'exam_term'=>$next['exam_term'],'title'=>$prefix.' EXCLUDED','event_date'=>'2026-10-27'],$values);
        synk_management_execute($db,'INSERT INTO tbl_management_events (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',str_repeat('s',count($row)),array_values($row));
    };
    $insert(['exam_term'=>null]);$insert(['semester'=>$next['semester']===1?2:1]);
    $otherYear=$db->query('SELECT ay_id FROM tbl_academic_years WHERE ay_id<>'.$next['ay_id'].' LIMIT 1')->fetch_assoc();
    if($otherYear)$insert(['ay_id'=>(int)$otherYear['ay_id']]);
    foreach(['term','all','unassigned']as$catalogScope)$check(array_map('intval',array_column($list($next,['catalog_scope'=>$catalogScope])['rows'],'event_id'))===[$third],'An event scope parameter exposed another year, semester or unknown term.');
    $html=(static function(array $scope,array $catalog,array $term,array $currentTerm):string {
        $page='events';$pageTitle='Events';$catalogOptions=['events'=>[]];
        $pageUrl=static function(string $file,array $params=[])use($term):string { return $file.'?'.http_build_query(array_merge(['term'=>$term['key'],'exam_term'=>$term['exam_term']],$params)); };
        ob_start();require __DIR__.'/../management/catalog-view.php';return ob_get_clean();
    })($scope,$list($period),$period,$next);
    $document=new DOMDocument();@$document->loadHTML($html);
    $check($document->getElementById('management-add')->hasAttribute('disabled') && strpos($html,'data-edit=')===false && strpos($html,'data-delete=')===false,'Past event periods expose mutation controls.');
    synk_management_catalog_write($db,$scope,'events','save',$input($next,['record_id'=>$third,'version'=>$record['version'],'title'=>$prefix.' EDITED','status'=>'INACTIVE','sessions'=>json_encode($record['sessions'])]));
    $edited=synk_management_catalog_get($db,$scope,'events',$third);
    $check($edited['status']==='INACTIVE' && synk_management_catalog_in_period($edited,$next) && $edited['sessions'][0]['session_id']===$record['sessions'][0]['session_id'],'Editing lost event period, status or session identity.');
    synk_management_save_current_exam_term($db,$scope,$input($next,['exam_term'=>$period['exam_term']]));
    $check($list(synk_management_fetch_current_period($db,$scope))['pagination']['total']===2,'Switching back did not restore the earlier event list.');
    $reject($input($period,['title'=>$prefix.' OLD REVISION']));
} finally { $db->discard();$db->close(); }
$check($snapshot($conn)===$before,'Event period checks changed stored business data.');
echo 'Event periods: ',$checks," checks passed. Event/session records, links and term changes rolled back.\n";
