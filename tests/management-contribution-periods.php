<?php
// Focused integration checks; every diagnostic record and term change rolls back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/management_catalog_helper.php';
class ManagementContributionPeriodConnection extends mysqli
{
    public function start(): void { parent::begin_transaction(); }
    public function discard(): void { parent::rollback(); }
    public function begin_transaction($flags=0,$name=null): bool { return $this->query('SAVEPOINT contribution_period_test'); }
    public function commit($flags=0,$name=null): bool { return $this->query('RELEASE SAVEPOINT contribution_period_test'); }
    public function rollback($flags=0,$name=null): bool {
        $this->query('ROLLBACK TO SAVEPOINT contribution_period_test');
        return $this->query('RELEASE SAVEPOINT contribution_period_test');
    }
}
$snapshot=static function(mysqli $db):array {
    $rows=[];
    foreach(['tbl_management_contributions','tbl_management_period_settings','tbl_academic_settings','tbl_useraccount','tbl_student_management'] as $table) {
        $rows[$table]=hash('sha256',json_encode($db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $rows;
};
$before=$snapshot($conn);$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if(!$ok)throw new RuntimeException($message);$checks++; };
$db=new ManagementContributionPeriodConnection($servername,$username,$password,$dbase);$db->set_charset('utf8mb4');$db->start();
try {
    $account=$db->query('SELECT * FROM tbl_useraccount WHERE management_organization_id IS NOT NULL ORDER BY user_id LIMIT 1')->fetch_assoc();
    if(!$account)throw new RuntimeException('A Management assignment is required.');
    $_SESSION=['user_id'=>(int)$account['user_id'],'role'=>'management','auth_method'=>'google'];
    $scope=['college_id'=>(int)$account['management_college_id'],'organization_id'=>(int)$account['management_organization_id']];
    $prefix='CONTRIBUTION PERIOD QA '.bin2hex(random_bytes(6));
    $period=synk_management_fetch_current_period($db,$scope);
    $input=static function(array $period,array $values=[]):array {
        return array_merge(['current_term'=>$period['key'],'current_exam_term'=>$period['exam_term'],'current_period_version'=>$period['period_version'],
            'term'=>$period['key'],'exam_term'=>$period['exam_term'],'record_term'=>$period['key'],
            'title'=>'','description'=>'','contribution_date'=>'2026-10-27','amount'=>'12.35','event_id'=>''],$values);
    };
    $add=static function(array $period,string $title,string $date,array $values=[])use($db,$scope,$input):int {
        return synk_management_catalog_write($db,$scope,'contributions','save',$input($period,array_merge(['title'=>$title,'contribution_date'=>$date],$values)))['record_id'];
    };
    $list=static function(array $period,array $values=[])use($db,$scope,$prefix):array {
        return synk_management_catalog_list($db,$scope,$period,'contributions',array_merge(['search'=>$prefix,'page_size'=>'100','catalog_scope'=>'all'],$values));
    };
    $reject=static function(array $values,string $action='save')use($db,$scope,$check):void {
        try { synk_management_catalog_write($db,$scope,'contributions',$action,$values);$check(false,'An invalid period write was accepted.'); }
        catch(SynkManagementConflict $error){$check(true,'');}
    };
    $first=$add($period,$prefix.' FIRST','2099-12-31',['status'=>'INACTIVE']);
    $second=$add($period,$prefix.' SECOND','2000-01-01');
    $rows=$list($period);
    $check(array_map('intval',array_column($rows['rows'],'contribution_id'))===[$second,$first],'New contributions are not first regardless of event date.');
    $check((int)$rows['summary']['total']===2 && (int)$rows['summary']['active_count']===1 && (int)$rows['summary']['inactive_count']===1,'Active/inactive counts do not follow the selected period.');
    $check(array_map('intval',array_column($list($period,['status'=>'ACTIVE'])['rows'],'contribution_id'))===[$second] && array_map('intval',array_column($list($period,['status'=>'INACTIVE'])['rows'],'contribution_id'))===[$first],'Active/inactive filtering returned the wrong contributions.');
    foreach(['DRAFT','OPEN','CLOSED','ARCHIVED','UNKNOWN']as$invalidStatus){
        try { $add($period,$prefix.' INVALID','2026-10-27',['status'=>$invalidStatus]);$check(false,'An obsolete contribution status was saved.'); }
        catch(DomainException $error){$check(true,'');}
    }
    $saved=synk_management_catalog_get($db,$scope,'contributions',$second);
    $check($saved['status']==='ACTIVE','New contributions did not default to Active.');
    $check(synk_management_catalog_in_period($saved,$period),'A new contribution did not record the active year, semester, and exam term.');
    $reject($input($period,['term'=>$period['ay_id'].':'.($period['semester']===1?2:1),'title'=>$prefix.' WRONG VIEW']));
    $otherExam=$period['exam_term']==='MIDTERM'?'FINAL':'MIDTERM';
    $reject($input($period,['exam_term'=>$otherExam,'title'=>$prefix.' WRONG EXAM']));
    $change=$input($period,['exam_term'=>$otherExam]);
    synk_management_save_current_exam_term($db,$scope,$change);
    $next=synk_management_fetch_current_period($db,$scope);
    $check($next['exam_term']===$otherExam && $list($next)['pagination']['total']===0,'Changing Midterm/Final retained another term\'s contributions.');
    $third=$add($next,$prefix.' THIRD','2026-10-27',['record_exam_term'=>$period['exam_term']]);
    $record=synk_management_catalog_get($db,$scope,'contributions',$third);
    $check($record['exam_term']===$next['exam_term'] && (int)$record['ay_id']===$next['ay_id'] && (int)$record['semester']===$next['semester'],'A client value or October event date overrode the active period.');
    $check(array_map('intval',array_column($list($next)['rows'],'contribution_id'))===[$third] && $list($period)['pagination']['total']===2,'Midterm and Final lists are not isolated.');
    $html=(static function(array $scope,array $catalog,array $term,array $currentTerm):string {
        $page='contributions';$pageTitle='Contributions';$catalogOptions=['events'=>[]];
        $pageUrl=static function(string $file,array $params=[])use($term):string { return $file.'?'.http_build_query(array_merge(['term'=>$term['key'],'exam_term'=>$term['exam_term']],$params)); };
        ob_start();require __DIR__.'/../management/catalog-view.php';return ob_get_clean();
    })($scope,$list($period),$period,$next);
    $document=new DOMDocument();@$document->loadHTML($html);
    $check($document->getElementById('management-add')->hasAttribute('disabled') && strpos($html,'data-edit=')===false && strpos($html,'data-delete=')===false,'Another period still exposes contribution mutation controls.');
    $reject($input($period,['title'=>$prefix.' STALE']));
    $old=synk_management_catalog_get($db,$scope,'contributions',$first);
    $reject($input($next,['record_id'=>$first,'version'=>$old['version'],'title'=>$old['title']]));
    $reject($input($next,['record_id'=>$first,'version'=>$old['version']]),'delete');
    $insert=static function(array $values)use($db,$scope,$next,$prefix):int {
        $row=array_merge(['college_id'=>$scope['college_id'],'organization_id'=>$scope['organization_id'],'ay_id'=>$next['ay_id'],'semester'=>$next['semester'],
            'exam_term'=>$next['exam_term'],'title'=>$prefix.' EXCLUDED','contribution_date'=>'2026-10-27','amount'=>'1.00'],$values);
        synk_management_execute($db,'INSERT INTO tbl_management_contributions (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',str_repeat('s',count($row)),array_values($row));
        return (int)$db->insert_id;
    };
    $insert(['exam_term'=>null]);
    $insert(['semester'=>$next['semester']===1?2:1]);
    $otherYear=$db->query('SELECT ay_id FROM tbl_academic_years WHERE ay_id<>'.$next['ay_id'].' LIMIT 1')->fetch_assoc();
    if($otherYear)$insert(['ay_id'=>(int)$otherYear['ay_id']]);
    foreach(['term','all','unassigned']as$catalogScope)$check(array_map('intval',array_column($list($next,['catalog_scope'=>$catalogScope])['rows'],'contribution_id'))===[$third],'A scope parameter exposed another year, semester, or an unknown term.');
    $edited=$input($next,['record_id'=>$third,'version'=>$record['version'],'title'=>$prefix.' EDITED','contribution_date'=>'2026-10-27','status'=>'INACTIVE']);
    synk_management_catalog_write($db,$scope,'contributions','save',$edited);
    $check(synk_management_catalog_in_period(synk_management_catalog_get($db,$scope,'contributions',$third),$next),'Editing moved a contribution out of its period.');
    $check(synk_management_catalog_get($db,$scope,'contributions',$third)['status']==='INACTIVE','A contribution could not be changed to Inactive.');
    synk_management_save_current_exam_term($db,$scope,$input($next,['exam_term'=>$period['exam_term']]));
    $restored=synk_management_fetch_current_period($db,$scope);
    $check($list($restored)['pagination']['total']===2,'Switching back did not restore the original period\'s list.');
    $reject($input($period,['title'=>$prefix.' OLD REVISION']));
} finally { $db->discard();$db->close(); }
$check($snapshot($conn)===$before,'Period diagnostics changed stored business data.');
echo 'Contribution periods: ',$checks," checks passed. Diagnostic records and term changes rolled back.\n";
