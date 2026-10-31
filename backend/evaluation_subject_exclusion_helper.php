<?php
require_once __DIR__.'/evaluation_access_helper.php';

function synk_evaluation_subject_exclusions_ready(mysqli $conn): bool
{
    foreach (['tbl_college_evaluation_subject_exclusions','tbl_college_evaluation_subject_exclusion_audit','tbl_student_faculty_evaluation_subject_scope'] as $table) {
        if (!synk_evaluation_table_exists($conn,$table)) return false;
    }
    return true;
}

function synk_evaluation_subject_label(array $row): string
{
    return trim(trim((string)$row['subject_code']).' - '.trim((string)$row['descriptive_title']));
}

// Read only. Scheduling and workload assignments never participate in this rule.
function synk_evaluation_subject_candidates(mysqli $conn,int $collegeId,int $ayId,int $semester): array
{
    $rows=synk_evaluation_query($conn,'SELECT DISTINCT es.subject_id,es.subject_code,es.descriptive_title
        FROM tbl_student_management_enrolled_subjects es JOIN tbl_college c ON c.college_id=es.college_id AND c.campus_id=es.campus_id
        WHERE es.college_id=? AND es.ay_id=? AND es.semester=? AND es.subject_id>0 ORDER BY es.subject_code,es.subject_id',
        'iii',[$collegeId,$ayId,$semester]);
    $subjects=[];
    foreach ($rows as $row) {
        $id=(int)$row['subject_id'];
        if (!isset($subjects[$id])) $subjects[$id]=['subject_id'=>$id,'label'=>synk_evaluation_subject_label($row)];
    }
    // Preserve access to exclusions after an original roster is superseded or removed.
    if (synk_evaluation_subject_exclusions_ready($conn)) {
        foreach (synk_evaluation_query($conn,'SELECT x.*,s.sub_code,s.sub_description FROM tbl_college_evaluation_subject_exclusions x
            LEFT JOIN tbl_subject_masterlist s ON s.sub_id=x.subject_id WHERE x.college_id=? AND x.ay_id=? AND x.semester=?',
            'iii',[$collegeId,$ayId,$semester]) as $rule) {
            $id=(int)$rule['subject_id'];
            if (!isset($subjects[$id])) $subjects[$id]=['subject_id'=>$id,'label'=>trim(($rule['sub_code'] ?? '').' - '.($rule['sub_description'] ?? 'Subject #'.$id))];
            $subjects[$id]['rule']=$rule;
        }
    }
    uasort($subjects,static function(array $a,array $b):int{return strnatcasecmp($a['label'],$b['label']);});
    return $subjects;
}

// Called only inside a draft/final transaction, never for already submitted responses.
function synk_evaluation_capture_subject_scope(mysqli $conn,int $evaluationId,int $studentId,int $facultyId,int $collegeId,int $ayId,int $semester,string $summary): void
{
    if (!synk_evaluation_subject_exclusions_ready($conn)) return;
    $rows=synk_evaluation_query($conn,'SELECT es.subject_id,es.subject_code,es.descriptive_title
        FROM tbl_student_management_enrolled_subjects es JOIN tbl_college c ON c.college_id=es.college_id AND c.campus_id=es.campus_id
        WHERE es.student_id=? AND es.faculty_id=? AND es.college_id=? AND es.ay_id=? AND es.semester=? AND es.is_active=1 LOCK IN SHARE MODE',
        'iiiii',[$studentId,$facultyId,$collegeId,$ayId,$semester]);
    $subjects=synk_evaluation_resolve_subjects($summary,$rows);
    $encoded=json_encode($subjects,JSON_UNESCAPED_UNICODE);
    if ($encoded===false) throw new RuntimeException('Unable to preserve evaluation subject associations.');
    synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluation_subject_scope (evaluation_id,subjects_json)
        VALUES (?,?) ON DUPLICATE KEY UPDATE subjects_json=VALUES(subjects_json),captured_at=CURRENT_TIMESTAMP',
        'is',[$evaluationId,$encoded]);
}

// A text summary is historical evidence, not a reason to guess from today's roster.
// Any absent or ambiguous exact label remains unknown and cannot silently remove a score.
function synk_evaluation_resolve_subjects(string $summary,array $rows): array
{
    $labels=[];
    foreach ($rows as $row) $labels[synk_evaluation_subject_label($row)][(int)$row['subject_id']]=true;
    $subjects=[];
    foreach (array_unique(explode('; ',trim($summary))) as $label) {
        if ($label==='') continue;
        $ids=array_keys($labels[$label] ?? []);
        $subjects[]=['subject_id'=>count($ids)===1 && $ids[0]>0 ? $ids[0] : 0,'label'=>$label];
    }
    return $subjects;
}

function synk_evaluation_classify_subjects(array $subjects,array $rules): string
{
    if (!$rules) return 'included';
    $excluded=0; $included=0; $unknown=!$subjects;
    foreach ($subjects as $subject) {
        $id=(int)($subject['subject_id'] ?? 0);
        if (!$id) { $unknown=true; continue; }
        if (isset($rules[$id])) $excluded++;
        else $included++;
    }
    if ($excluded && !$included && !$unknown) return 'excluded';
    if ($unknown || ($excluded && $included)) return 'review';
    return 'included';
}

// Shared by all scoring reports. Collection and completion never use these states.
function synk_evaluation_score_states(mysqli $conn,int $ayId,int $semester,bool $includeAll=false): array
{
    if (!synk_evaluation_table_exists($conn,'tbl_college_evaluation_subject_exclusions')) return [];
    $rules=[];
    foreach (synk_evaluation_query($conn,'SELECT college_id,subject_id FROM tbl_college_evaluation_subject_exclusions
        WHERE ay_id=? AND semester=? AND excluded=1','ii',[$ayId,$semester]) as $rule) $rules[(int)$rule['college_id']][(int)$rule['subject_id']]=$rule;
    if (!$rules && !$includeAll) return [];
    $snapshotReady=synk_evaluation_table_exists($conn,'tbl_student_faculty_evaluation_subject_scope');
    $snapshotColumn=$snapshotReady ? 'ss.subjects_json' : 'NULL AS subjects_json';
    $snapshotJoin=$snapshotReady ? 'LEFT JOIN tbl_student_faculty_evaluation_subject_scope ss ON ss.evaluation_id=e.evaluation_id' : '';
    $headers=synk_evaluation_query($conn,"SELECT e.evaluation_id,e.student_id,e.faculty_id,e.college_id,e.subject_summary,{$snapshotColumn}
        FROM tbl_student_faculty_evaluations e {$snapshotJoin}
        WHERE e.ay_id=? AND e.semester=? AND e.submission_status='submitted'",'ii',[$ayId,$semester]);
    $members=[]; $owners=[];
    foreach (synk_evaluation_query($conn,'SELECT DISTINCT es.student_id,es.faculty_id,es.college_id,es.subject_id,es.subject_code,es.descriptive_title,c.college_id AS valid_college_id
        FROM tbl_student_management_enrolled_subjects es LEFT JOIN tbl_college c ON c.college_id=es.college_id AND c.campus_id=es.campus_id
        WHERE es.ay_id=? AND es.semester=?','ii',[$ayId,$semester]) as $row) {
        $key=$row['student_id'].':'.$row['faculty_id'];
        $college=(int)$row['college_id'];
        if ($college>0) $owners[$key][$college]=true;
        if (!empty($row['valid_college_id'])) $members[$key.':'.$college][]=$row;
    }
    $states=[];
    foreach ($headers as $header) {
        $key=$header['student_id'].':'.$header['faculty_id']; $college=(int)$header['college_id'];
        if (!$college && count($owners[$key] ?? [])===1) $college=(int)array_keys($owners[$key])[0];
        if (empty($rules[$college]) && !$includeAll) continue;
        $subjects=$header['subjects_json']!==null ? json_decode($header['subjects_json'],true) : synk_evaluation_resolve_subjects((string)$header['subject_summary'],$members[$key.':'.$college] ?? []);
        if (!is_array($subjects)) $subjects=[];
        $states[(int)$header['evaluation_id']]=['state'=>synk_evaluation_classify_subjects($subjects,$rules[$college] ?? []),'subjects'=>$subjects];
    }
    return $states;
}

// IDs originate exclusively from the database. Keep one predicate for totals,
// categories and question means so excluded ratings cannot leak back into scores.
function synk_evaluation_score_predicate(array $states,string $alias='e'): string
{
    $ids=[];
    foreach ($states as $id=>$state) if ($state['state']==='excluded') $ids[]=(int)$id;
    return $ids ? $alias.'.evaluation_id NOT IN ('.implode(',',$ids).')' : '1=1';
}

function synk_evaluation_save_subject_exclusion(mysqli $conn,array $input): void
{
    if (($_SESSION['role'] ?? '')!=='college_dean' || empty($_SESSION['user_id'])) throw new DomainException('Only the assigned College Dean can manage subject exclusions.');
    foreach (['csrf_token','college_id','ay_id','semester','subject_id','version','excluded','reason'] as $key) {
        if (!isset($input[$key]) || !is_scalar($input[$key])) throw new DomainException('Invalid subject exclusion request.');
    }
    if (isset($input['exclude_mixed'])) throw new DomainException('Combined subject responses remain counted for Dean review.');
    if (!is_string($input['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$input['csrf_token'])) throw new DomainException('Your session token changed. Reload and try again.');
    foreach (['college_id','ay_id','semester','subject_id','version'] as $key) {
        if (filter_var($input[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>$key==='version'?0:1,'max_range'=>2147483647]])===false) throw new DomainException('Choose a valid subject and academic term.');
    }
    if (!in_array((string)$input['excluded'],['0','1'],true)) throw new DomainException('Choose a valid exclusion action.');
    $reason=trim((string)$input['reason']);
    if ($reason==='' || preg_match('//u',$reason)!==1 || mb_strlen($reason,'UTF-8')>1000) throw new DomainException('Enter a reason using 1 to 1,000 characters.');
    $college=(int)$input['college_id']; $ay=(int)$input['ay_id']; $semester=(int)$input['semester']; $subject=(int)$input['subject_id']; $actor=(int)$_SESSION['user_id'];
    if ($semester>3 || !synk_evaluation_query($conn,'SELECT ay_id FROM tbl_academic_years WHERE ay_id=?','i',[$ay])) throw new DomainException('Choose an available academic term.');
    if (!synk_evaluation_subject_exclusions_ready($conn)) throw new DomainException('Subject exclusion storage is not prepared.');
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to start the subject exclusion transaction.');
    try {
        synk_evaluation_query($conn,'SELECT user_id FROM tbl_useraccount WHERE user_id=? FOR UPDATE','i',[$actor]);
        synk_evaluation_query($conn,'SELECT user_id FROM tbl_useraccount_roles WHERE user_id=? FOR UPDATE','i',[$actor]);
        $account=synk_find_useraccount_by_id($conn,$actor);
        if (!$account || !synk_college_dean_account_has_access($conn,$account) || (int)$account['college_dean_college_id']!==$college) throw new DomainException('Your college assignment changed. Reload the page.');
        $active=synk_evaluation_query($conn,"SELECT c.college_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.college_id=? AND c.status='active' AND cp.status='active' FOR UPDATE",'i',[$college]);
        if (!$active) throw new DomainException('An active college and campus are required.');
        $subjects=synk_evaluation_subject_candidates($conn,$college,$ay,$semester);
        if (!isset($subjects[$subject])) throw new DomainException('This subject is outside your college and selected term.');
        $previous=synk_evaluation_query($conn,'SELECT excluded,reason,version FROM tbl_college_evaluation_subject_exclusions WHERE college_id=? AND ay_id=? AND semester=? AND subject_id=? FOR UPDATE','iiii',[$college,$ay,$semester,$subject]);
        $old=$previous[0] ?? ['excluded'=>0,'reason'=>'','version'=>0];
        if ((int)$old['version']!==(int)$input['version']) throw new DomainException('This subject exclusion changed in another request. Reload before saving.');
        $new=['excluded'=>(int)$input['excluded'],'reason'=>$reason,'version'=>(int)$old['version']+1];
        synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_subject_exclusions (college_id,ay_id,semester,subject_id,excluded,reason,version,updated_by)
            VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE excluded=VALUES(excluded),reason=VALUES(reason),version=VALUES(version),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP',
            'iiiiisii',[$college,$ay,$semester,$subject,$new['excluded'],$reason,$new['version'],$actor]);
        synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_subject_exclusion_audit (college_id,ay_id,semester,subject_id,actor_user_id,previous_value,new_value) VALUES (?,?,?,?,?,?,?)',
            'iiiiiss',[$college,$ay,$semester,$subject,$actor,json_encode($old),json_encode($new)]);
        if (!$conn->commit()) throw new RuntimeException('Unable to commit the subject exclusion change.');
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
