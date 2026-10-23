<?php
require_once __DIR__.'/schema_helper.php';
require_once __DIR__.'/offering_scope_helper.php';
require_once __DIR__.'/schedule_tba_helper.php';
require_once __DIR__.'/workload_audit_helper.php';

class SynkWorkloadStudentsException extends DomainException
{
    public $classes;
    public function __construct(array $classes)
    {
        $this->classes=$classes; $first=$classes[0];
        parent::__construct('This class has '.$first['student_count'].' students. Clear its student list in the Dean module before removing the workload. '
            .$first['subject_code'].' - '.$first['section_label'].(count($classes)>1?' Another '.(count($classes)-1).' class(es) also have students. Nothing was removed.':''));
    }
}

function synk_workload_guard_query(mysqli $conn,string $sql,string $types,array $params): array
{
    $stmt=$conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the workload check.');
    try {
        if ($types!=='') synk_bind_dynamic_params($stmt,$types,$params);
        if (!$stmt->execute()) throw new RuntimeException('Unable to complete the workload check.');
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

function synk_workload_removal_ids(array $input): array
{
    $values=$input['workload_ids'] ?? [$input['workload_id'] ?? ''];
    if (is_string($values)) $values=explode(',',$values);
    if (!is_array($values) || !$values || count($values)>5000) throw new DomainException('Select between 1 and 5,000 workload rows.');
    $ids=[];
    foreach ($values as $value) {
        if (!is_scalar($value)) throw new DomainException('Choose valid workload rows.');
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id) throw new DomainException('Choose valid workload rows.');
        $ids[$id]=$id;
    }
    return array_values($ids);
}

function synk_workload_students_in_class(mysqli $conn,array $class,bool $lock=false): int
{
    if (!synk_table_exists($conn,'tbl_student_management_enrolled_subjects')) return 0;
    $rows=synk_workload_guard_query($conn,'SELECT es.student_id FROM tbl_student_management_enrolled_subjects es
        WHERE es.is_active=1 AND ((es.offering_id=? AND es.offering_id>0) OR (es.offering_id=0 AND es.program_id=? AND es.section_id=? AND es.subject_id=?))
        AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=?'.($lock?' FOR UPDATE':''),'iiiiiiii',[
            $class['offering_id'],$class['program_id'],$class['section_id'],$class['subject_id'],$class['college_id'],$class['campus_id'],$class['ay_id'],$class['semester']]);
    return count(array_unique(array_column($rows,'student_id')));
}

function synk_workload_remove_guarded(mysqli $conn,int $collegeId,string $type,array $ids,array $term=[]): array
{
    if (!$ids && !$term) throw new DomainException('Select workload rows or a specific academic term before removing workload.');
    $need=$type==='faculty_need'; $table=$need?'tbl_faculty_need_workload_sched':'tbl_faculty_workload_sched'; $column=$need?'need_workload_id':'workload_id';
    if (!synk_table_exists($conn,$table)) throw new DomainException('Workload storage is not available. Refresh the page.');
    synk_schedule_acquire_write_lock($conn);
    $lock=$conn->query("SELECT GET_LOCK('synk_college_dean_roster_import',5) AS acquired")->fetch_assoc();
    if ((int)$lock['acquired']!==1) throw new DomainException('A student list is being updated. Try removing the workload again shortly.');
    $transaction=false;
    try {
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin workload removal.');
        $transaction=true;
        $sql="SELECT fw.{$column} AS removal_id,fw.ay_id,fw.semester,cs.offering_id,o.program_id,o.section_id,
            ps.sub_id AS subject_id,p.college_id,c.campus_id,COALESCE(NULLIF(sm.sub_code,''),'Subject') AS subject_code,
            COALESCE(NULLIF(sec.full_section,''),CONCAT('Section ',o.section_id)) AS section_label
            FROM {$table} fw JOIN tbl_class_schedule cs ON cs.schedule_id=fw.schedule_id
            JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id
            JOIN tbl_program p ON p.program_id=o.program_id JOIN tbl_college c ON c.college_id=p.college_id
            LEFT JOIN tbl_prospectus_subjects ps ON ps.ps_id=o.ps_id LEFT JOIN tbl_subject_masterlist sm ON sm.sub_id=ps.sub_id
            LEFT JOIN tbl_sections sec ON sec.section_id=o.section_id WHERE p.college_id=?";
        $params=[$collegeId]; $types='i';
        if ($term) { $sql.=' AND fw.ay_id=? AND fw.semester=?'; $params[]=$term['ay_id']; $params[]=$term['semester']; $types.='ii'; }
        if ($ids) { $sql.=" AND fw.{$column} IN (".implode(',',array_fill(0,count($ids),'?')).')'; $params=array_merge($params,$ids); $types.=str_repeat('i',count($ids)); }
        $rows=synk_workload_guard_query($conn,$sql." ORDER BY fw.{$column} FOR UPDATE",$types,$params);
        if ($ids && count($rows)!==count($ids)) throw new DomainException('Some workload rows changed or are outside your college or selected term. Refresh the workload list.');
        $classes=[]; $blocked=[];
        foreach ($rows as $row) {
            if (empty($row['subject_id'])) throw new DomainException('This class offering has incomplete subject details. Verify it before removing workload.');
            $key=$row['offering_id'].':'.$row['ay_id'].':'.$row['semester'];
            if (isset($classes[$key])) continue;
            $classes[$key]=true; $count=synk_workload_students_in_class($conn,$row,true);
            if ($count>0) $blocked[]=['offering_id'=>(int)$row['offering_id'],'ay_id'=>(int)$row['ay_id'],'semester'=>(int)$row['semester'],
                'subject_code'=>$row['subject_code'],'section_label'=>$row['section_label'],'student_count'=>$count];
        }
        if ($blocked) throw new SynkWorkloadStudentsException($blocked);
        $removeIds=array_map('intval',array_column($rows,'removal_id'));
        $auditRows=$removeIds?synk_workload_audit_fetch_workload_rows($conn,$type,$removeIds):[];
        $deleted=0;
        foreach (array_chunk($removeIds,500) as $chunk) {
            $stmt=$conn->prepare("DELETE FROM {$table} WHERE {$column} IN (".implode(',',array_fill(0,count($chunk),'?')).')');
            synk_bind_dynamic_params($stmt,str_repeat('i',count($chunk)),$chunk);
            try {
                if (!$stmt->execute() || $stmt->affected_rows!==count($chunk)) throw new RuntimeException('Workload rows changed during removal. Nothing was removed.');
                $deleted+=$stmt->affected_rows;
            } finally { $stmt->close(); }
        }
        // Never run schema DDL inside the transaction.
        if ($deleted && synk_table_exists($conn,synk_workload_audit_table_name())) {
            if ($term && !$ids) {
                if (!synk_workload_audit_record_bulk_delete($conn,$collegeId,$term['ay_id'],$term['semester'],$auditRows,$deleted)) throw new RuntimeException('Unable to record workload removal.');
            } else foreach ($auditRows as $audit) {
                if (!synk_workload_audit_record_workload_event($conn,'workload_delete',$audit,['requested_workload_ids'=>$ids,'deleted_count'=>$deleted])) throw new RuntimeException('Unable to record workload removal.');
            }
        }
        if (!$conn->commit()) throw new RuntimeException('Unable to complete workload removal.');
        $transaction=false; return ['deleted_count'=>$deleted];
    } catch (Throwable $error) { if ($transaction) $conn->rollback(); throw $error; }
    finally { $conn->query("SELECT RELEASE_LOCK('synk_college_dean_roster_import')"); }
}
