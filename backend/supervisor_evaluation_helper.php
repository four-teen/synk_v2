<?php
require_once __DIR__.'/college_dean_dashboard_helper.php';
require_once __DIR__.'/evaluation_access_helper.php';
require_once __DIR__.'/evaluation_subject_exclusion_helper.php';
require_once __DIR__.'/student_faculty_evaluation_instrument.php';
require_once __DIR__.'/supervisor_evaluation_instrument.php';
require_once __DIR__.'/supervisor_evaluation_routing.php';

function synk_sef_ready(mysqli $conn): bool
{
    foreach (['tbl_supervisor_evaluation_settings','tbl_supervisor_evaluation_assignments','tbl_supervisor_evaluations','tbl_supervisor_evaluation_audit'] as $table) if (!synk_evaluation_table_exists($conn,$table)) return false;
    return true;
}
function synk_sef_json($value): string
{
    $json=json_encode($value,JSON_UNESCAPED_UNICODE);
    if ($json===false) throw new RuntimeException('Unable to preserve the evaluation snapshot.');
    return $json;
}
function synk_sef_actor(mysqli $conn,bool $lock=false): array
{
    $userId=(int)($_SESSION['user_id'] ?? 0);
    if ($lock) {
        synk_evaluation_query($conn,'SELECT user_id FROM tbl_useraccount WHERE user_id=? FOR UPDATE','i',[$userId]);
        synk_evaluation_query($conn,'SELECT user_id FROM tbl_useraccount_roles WHERE user_id=? FOR UPDATE','i',[$userId]);
    }
    $scope=synk_college_dean_dashboard_scope($conn);
    if (!$scope) throw new DomainException('Your college portal access changed. Sign in again.',403);
    if ($lock) {
        synk_evaluation_query($conn,'SELECT user_id FROM tbl_useraccount_faculty_links WHERE user_id=? LOCK IN SHARE MODE','i',[$userId]);
    }
    $links=synk_evaluation_query($conn,"SELECT l.faculty_id FROM tbl_useraccount_faculty_links l JOIN tbl_faculty f ON f.faculty_id=l.faculty_id AND f.status='active' WHERE l.user_id=?",'i',[$userId]);
    $account=synk_find_useraccount_by_id($conn,$userId);
    return ['user_id'=>$userId,'faculty_id'=>(int)($links[0]['faculty_id'] ?? 0),'role'=>(string)$_SESSION['role'],'name'=>(string)$account['username'],'scope'=>$scope];
}
function synk_sef_settings(mysqli $conn,array $scope,array $term): array
{
    if (!synk_sef_ready($conn)) return ['is_open'=>0,'version'=>0];
    return (synk_evaluation_query($conn,'SELECT is_open,version FROM tbl_supervisor_evaluation_settings WHERE college_id=? AND ay_id=? AND semester=?','iii',[$scope['college_id'],$term['ay_id'],$term['semester']])[0] ?? ['is_open'=>0,'version'=>0]);
}
// All membership/context queries below are read-only directory/class-list queries.
// They do not call scheduling or workload controllers, nor update their storage.
function synk_sef_catalog(mysqli $conn,array $scope,array $term): array
{
    $college=(int)$scope['college_id']; $campus=(int)$scope['campus_id'];
    $activeSql=synk_evaluation_is_current($term,synk_fetch_current_academic_term($conn))?' AND es.is_active=1':'';
    $rows=synk_evaluation_query($conn,"SELECT f.faculty_id,f.first_name,f.middle_name,f.last_name,f.ext_name,f.status,
        EXISTS(SELECT 1 FROM tbl_student_management_enrolled_subjects es WHERE es.faculty_id=f.faculty_id AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=?{$activeSql}) AS has_teaching
        FROM tbl_faculty f WHERE EXISTS(SELECT 1 FROM tbl_college_faculty cf WHERE cf.faculty_id=f.faculty_id AND cf.college_id=? AND LOWER(TRIM(cf.status))='active'
            AND ((cf.ay_id=? AND cf.semester=?) OR (cf.ay_id IS NULL AND cf.semester IS NULL)))
        OR EXISTS(SELECT 1 FROM tbl_student_management_enrolled_subjects es WHERE es.faculty_id=f.faculty_id AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=?{$activeSql})",
        'iiiiiiiiiii',[$college,$campus,$term['ay_id'],$term['semester'],$college,$term['ay_id'],$term['semester'],$college,$campus,$term['ay_id'],$term['semester']]);
    $workloads=synk_sef_workloads($conn,$scope,$term);
    $present=array_column($rows,null,'faculty_id');
    foreach ($workloads as $id=>$programLoads) {
        if (isset($present[$id])) { $present[$id]['has_teaching']=1; continue; }
        $extra=synk_evaluation_query($conn,'SELECT faculty_id,first_name,middle_name,last_name,ext_name,status,1 AS has_teaching FROM tbl_faculty WHERE faculty_id=?','i',[$id]);
        if ($extra) $present[$id]=$extra[0];
    }
    if (synk_sef_ready($conn)) foreach (synk_evaluation_query($conn,'SELECT DISTINCT f.faculty_id,f.first_name,f.middle_name,f.last_name,f.ext_name,f.status,1 AS has_teaching FROM tbl_supervisor_evaluations e JOIN tbl_supervisor_evaluation_assignments a ON a.assignment_id=e.assignment_id AND a.revision=e.assignment_revision JOIN tbl_faculty f ON f.faculty_id=e.faculty_id WHERE e.college_id=? AND e.ay_id=? AND e.semester=?','iii',[$college,$term['ay_id'],$term['semester']]) as $started) {
        if (!isset($present[(int)$started['faculty_id']])) $present[(int)$started['faculty_id']]=$started;
        else $present[(int)$started['faculty_id']]['has_teaching']=1;
    }
    $rows=array_values($present);
    $leaders=[];
    if (synk_evaluation_table_exists($conn,'tbl_faculty_college_appointments')) foreach (synk_evaluation_query($conn,"SELECT faculty_id,appointment_role FROM tbl_faculty_college_appointments WHERE college_id=? AND ay_id=? AND semester=? AND status='active'",'iii',[$college,$term['ay_id'],$term['semester']]) as $leader) $leaders[(int)$leader['faculty_id']][$leader['appointment_role']]=true;
    // A current portal role with an unambiguous faculty link also identifies
    // a chair even if the directory appointment has not yet been recorded.
    if (synk_evaluation_is_current($term,synk_fetch_current_academic_term($conn))) foreach (synk_evaluation_query($conn,"SELECT u.user_id,l.faculty_id,u.college_id,u.college_dean_college_id FROM tbl_useraccount u JOIN tbl_useraccount_faculty_links l ON l.user_id=u.user_id WHERE u.status='active' AND (u.college_id=? OR u.college_dean_college_id=?)",'ii',[$college,$college]) as $candidate) {
        $account=synk_find_useraccount_by_id($conn,(int)$candidate['user_id']);
        if ((int)$candidate['college_id']===$college && synk_program_chair_account_has_access($conn,$account)) $leaders[(int)$candidate['faculty_id']]['chair']=true;
        if ((int)$candidate['college_dean_college_id']===$college && synk_college_dean_account_has_access($conn,$account)) $leaders[(int)$candidate['faculty_id']]['dean']=true;
    }
    // Chairs are evaluable by the Dean even before a class-list import exists.
    foreach ($leaders as $id=>$roles) if (isset($roles['chair']) && !in_array($id,array_map('intval',array_column($rows,'faculty_id')),true)) {
        $extra=synk_evaluation_query($conn,'SELECT faculty_id,first_name,middle_name,last_name,ext_name,status,0 AS has_teaching FROM tbl_faculty WHERE faculty_id=?','i',[$id]);
        if ($extra) $rows[]=$extra[0];
    }
    $catalog=[];
    foreach ($rows as $row) {
        $id=(int)$row['faculty_id']; $roles=$leaders[$id] ?? [];
        // A Dean is routed to a chair only for teaching in the selected college.
        if (isset($roles['dean']) && !isset($roles['chair']) && !$row['has_teaching']) continue;
        $catalog[$id]=['faculty_id'=>$id,'faculty_name'=>trim($row['last_name'].', '.implode(' ',array_filter([$row['first_name'],$row['middle_name'],$row['ext_name']]))),
            'status'=>$row['status'],'is_chair'=>isset($roles['chair']),'is_dean'=>isset($roles['dean']),
            'reviewer_role'=>isset($roles['chair'])?'college_dean':'program_chair'];
    }
    uasort($catalog,static function(array $a,array $b):int{return strnatcasecmp($a['faculty_name'],$b['faculty_name']);});
    return $catalog;
}
function synk_sef_reviewers(mysqli $conn,array $scope): array
{
    $rows=synk_evaluation_query($conn,"SELECT u.user_id,l.faculty_id FROM tbl_useraccount u JOIN tbl_useraccount_faculty_links l ON l.user_id=u.user_id
        JOIN tbl_faculty f ON f.faculty_id=l.faculty_id AND f.status='active' WHERE u.status='active' AND (u.college_id=? OR u.college_dean_college_id=?)",'ii',[$scope['college_id'],$scope['college_id']]);
    $reviewers=[];
    foreach ($rows as $row) {
        $account=synk_find_useraccount_by_id($conn,(int)$row['user_id']);
        foreach (['program_chair','college_dean'] as $role) {
            $field=$role==='college_dean'?'college_dean_college_id':'college_id';
            $allowed=$role==='college_dean'?synk_college_dean_account_has_access($conn,$account):synk_program_chair_account_has_access($conn,$account);
            if ($allowed && (int)$account[$field]===(int)$scope['college_id']) $reviewers[$role.':'.$row['user_id']]=['user_id'=>(int)$row['user_id'],'faculty_id'=>(int)$row['faculty_id'],'role'=>$role,'name'=>(string)$account['username']];
        }
    }
    return $reviewers;
}
function synk_sef_options(array $faculty,array $reviewers): array
{
    return array_values(array_filter($reviewers,static function(array $reviewer)use($faculty):bool {
        return $reviewer['role']===$faculty['reviewer_role'] && $reviewer['faculty_id']!==$faculty['faculty_id'];
    }));
}
function synk_sef_assignments(mysqli $conn,array $scope,array $term): array
{
    if (!synk_sef_ready($conn)) return [];
    $rows=synk_evaluation_query($conn,'SELECT a.*,u.username AS reviewer_name FROM tbl_supervisor_evaluation_assignments a LEFT JOIN tbl_useraccount u ON u.user_id=a.reviewer_user_id WHERE a.college_id=? AND a.ay_id=? AND a.semester=?','iii',[$scope['college_id'],$term['ay_id'],$term['semester']]);
    $existing=array_column($rows,null,'faculty_id');
    $routes=synk_sef_routes($conn,$scope,$term,synk_sef_catalog($conn,$scope,$term),synk_sef_reviewers($conn,$scope));
    // Preserve the shared reviewer group once work has started. The original
    // identity and routing remain auditable even if workloads later change.
    $snapshots=synk_evaluation_query($conn,'SELECT e.faculty_id,e.context_snapshot FROM tbl_supervisor_evaluations e JOIN tbl_supervisor_evaluation_assignments a ON a.assignment_id=e.assignment_id AND a.revision=e.assignment_revision WHERE e.college_id=? AND e.ay_id=? AND e.semester=?','iii',[$scope['college_id'],$term['ay_id'],$term['semester']]);
    foreach ($snapshots as $row) {
        $context=json_decode($row['context_snapshot'],true) ?: [];
        if (isset($context['routing'])) $routes[(int)$row['faculty_id']]=$context['routing'];
    }
    $result=[];
    foreach ($routes as $id=>$route) $result[$id]=array_replace($existing[$id] ?? ['assignment_id'=>0,'revision'=>1,'faculty_id'=>$id,'reviewer_user_id'=>$route['reviewer_user_ids'][0] ?? 0],$route);
    return $result;
}
function synk_sef_records(mysqli $conn,array $scope,array $term,array $actor): array
{
    if (!synk_sef_ready($conn)) return [];
    $assignments=synk_sef_assignments($conn,$scope,$term);
    $rows=synk_evaluation_query($conn,"SELECT e.* FROM tbl_supervisor_evaluations e JOIN tbl_supervisor_evaluation_assignments a ON a.assignment_id=e.assignment_id AND a.revision=e.assignment_revision
        WHERE e.college_id=? AND e.ay_id=? AND e.semester=?",'iii',[$scope['college_id'],$term['ay_id'],$term['semester']]);
    $rows=array_values(array_filter($rows,static function(array $row)use($assignments,$actor):bool {
        $assignment=$assignments[$row['faculty_id']] ?? null;
        return $row['submission_status']==='submitted' || ($assignment && synk_sef_assignment_owned($actor,$assignment) && $actor['faculty_id']>0 && $actor['faculty_id']!==(int)$row['faculty_id'] && (!isset($assignment['reviewer_faculty_ids'][$actor['user_id']]) || (int)$assignment['reviewer_faculty_ids'][$actor['user_id']]===$actor['faculty_id']));
    }));
    foreach ($rows as &$row) { $row['answers']=json_decode($row['answers_snapshot'],true) ?: []; $row['context']=json_decode($row['context_snapshot'],true) ?: []; }
    unset($row);
    return array_column($rows,null,'faculty_id');
}
function synk_sef_context(mysqli $conn,array $scope,array $term,int $facultyId): array
{
    $activeSql=synk_evaluation_is_current($term,synk_fetch_current_academic_term($conn))?' AND is_active=1':'';
    $subjects=synk_evaluation_query($conn,'SELECT DISTINCT subject_id,subject_code,descriptive_title,year_level,program_id FROM tbl_student_management_enrolled_subjects WHERE college_id=? AND campus_id=? AND ay_id=? AND semester=? AND faculty_id=?'.$activeSql.' ORDER BY subject_code','iiiii',[$scope['college_id'],$scope['campus_id'],$term['ay_id'],$term['semester'],$facultyId]);
    $rank='';
    if (synk_evaluation_table_exists($conn,'tbl_faculty_record_profiles')) {
        $profile=synk_evaluation_query($conn,'SELECT profile_data FROM tbl_faculty_record_profiles WHERE faculty_id=?','i',[$facultyId]);
        if ($profile) $rank=(string)((json_decode($profile[0]['profile_data'],true) ?: [])['faculty_rank'] ?? '');
    }
    if (synk_evaluation_table_exists($conn,'tbl_faculty_profiles')) {
        $profile=synk_evaluation_query($conn,'SELECT p.faculty_rank FROM tbl_faculty_profiles p JOIN tbl_useraccount_faculty_links l ON l.user_id=p.user_id WHERE l.faculty_id=?','i',[$facultyId]);
        if ($profile) $rank=(string)$profile[0]['faculty_rank'];
    }
    return ['college_name'=>$scope['college_name'],'campus_name'=>$scope['campus_name'],'term_text'=>$term['term_text'],'academic_rank'=>$rank,'subjects'=>$subjects];
}
function synk_sef_audit(mysqli $conn,array $scope,array $term,int $facultyId,string $action,$before,$after): void
{
    synk_evaluation_query($conn,'INSERT INTO tbl_supervisor_evaluation_audit (college_id,ay_id,semester,faculty_id,actor_user_id,action_name,previous_snapshot,new_snapshot) VALUES (?,?,?,?,?,?,?,?)',
        'iiiiisss',[$scope['college_id'],$term['ay_id'],$term['semester'],$facultyId,(int)$_SESSION['user_id'],$action,synk_sef_json($before),synk_sef_json($after)]);
}
function synk_sef_int(array $input,string $key,int $minimum=1): int
{
    $value=$input[$key] ?? null;
    if ((!is_string($value) && !is_int($value)) || filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$minimum,'max_range'=>2147483647]])===false) throw new DomainException('Invalid evaluation scope or revision. Reload and try again.');
    return (int)$value;
}
function synk_sef_commit(mysqli $conn): void
{
    if (!$conn->commit()) throw new RuntimeException('The supervisor evaluation transaction could not be committed.');
}
function synk_sef_review_token(array $record): string
{
    return (int)$record['evaluation_id'].':'.(int)$record['assignment_revision'].':'.(int)$record['version'];
}
// One transaction serializes collection controls and shared drafts/finals.
// No DDL or academic/scheduling/workload mutation is performed here.
function synk_sef_write(mysqli $conn,array $input): string
{
    $token=$input['csrf_token'] ?? null;
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$token)) throw new DomainException('Your session token changed. Reload and try again.',403);
    $college=synk_sef_int($input,'college_id'); $ay=synk_sef_int($input,'ay_id'); $semester=synk_sef_int($input,'semester');
    $action=$input['action'] ?? null;
    if (!is_string($action) || !in_array($action,['collection','save','finalize'],true)) throw new DomainException('Reviewers are assigned automatically from teaching workload. Choose a valid evaluation action.');
    if (!synk_sef_ready($conn)) throw new DomainException('Supervisor evaluation storage is not prepared.');
    $initial=synk_sef_actor($conn);
    if ($initial['scope']['college_id']!==$college) throw new DomainException('This evaluation is outside your assigned college.',403);
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin the supervisor evaluation transaction.');
    try {
        // Lock the college before actor identity for all shared evaluation writes.
        synk_evaluation_query($conn,'SELECT college_id FROM tbl_college WHERE college_id=? FOR UPDATE','i',[$college]);
        $actor=synk_sef_actor($conn,true); $scope=$actor['scope'];
        if ($scope['college_id']!==$college) throw new DomainException('This evaluation is outside your assigned college.',403);
        $current=synk_evaluation_query($conn,'SELECT current_ay_id AS ay_id,current_semester AS semester FROM tbl_academic_settings LIMIT 1 LOCK IN SHARE MODE')[0] ?? [];
        $term=synk_dean_term_from_ids($conn,$ay,$semester);
        if (!synk_evaluation_is_current($term,synk_dean_view_term($conn))) throw new DomainException('The module viewing term changed. Reload before saving.');
        if (!synk_evaluation_is_current($term,$current)) throw new DomainException('Previous academic terms are read only. Use the current academic year and semester.');
        $setting=synk_sef_settings($conn,$scope,$term);
        if ($action==='collection') {
            if ($actor['role']!=='college_dean') throw new DomainException('Only the assigned Dean can open or close supervisor evaluations.',403);
            $value=synk_sef_int($input,'is_open',0); $version=synk_sef_int($input,'version',0);
            if ($value>1 || $version!==(int)$setting['version']) throw new DomainException('The collection setting changed. Reload before saving.');
            synk_evaluation_query($conn,'INSERT INTO tbl_supervisor_evaluation_settings (college_id,ay_id,semester,is_open,version,updated_by) VALUES (?,?,?,?,1,?) ON DUPLICATE KEY UPDATE is_open=VALUES(is_open),version=version+1,updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP','iiiii',[$college,$ay,$semester,$value,$actor['user_id']]);
            synk_sef_audit($conn,$scope,$term,0,'collection',$setting,['is_open'=>$value,'version'=>$version+1]);
            synk_sef_commit($conn); return 'Supervisor evaluation collection updated.';
        }
        $catalog=synk_sef_catalog($conn,$scope,$term); $reviewers=synk_sef_reviewers($conn,$scope);
        if (empty($setting['is_open'])) throw new DomainException('Supervisor evaluations are closed for this college. Saved work is preserved.');
        if (!$actor['faculty_id']) throw new DomainException('Your account must have an active faculty link before you can evaluate.');
        $assignments=synk_sef_assignments($conn,$scope,$term);
        if ($action==='save') {
            $facultyId=synk_sef_int($input,'faculty_id');
            $assignment=$assignments[$facultyId] ?? null; $faculty=$catalog[$facultyId] ?? null;
            synk_sef_require_assignment($actor,$faculty,$assignment,$reviewers);
            if ((int)$assignment['revision']!==synk_sef_int($input,'assignment_revision')) throw new DomainException('This evaluator assignment changed. Reload the faculty list.');
            if (!(int)$assignment['assignment_id']) {
                synk_evaluation_query($conn,'INSERT INTO tbl_supervisor_evaluation_assignments (college_id,ay_id,semester,faculty_id,reviewer_user_id,reviewer_role,revision,updated_by) VALUES (?,?,?,?,?,?,1,?)','iiiiisi',[$college,$ay,$semester,$facultyId,$actor['user_id'],$actor['role'],$actor['user_id']]);
                $assignment['assignment_id']=(int)$conn->insert_id;
                $assignment['reviewer_user_id']=$actor['user_id'];
                synk_sef_audit($conn,$scope,$term,$facultyId,'automatic_assignment',null,$assignment);
            }
            $answers=$input['ratings'] ?? null;
            if (!is_array($answers)) throw new DomainException('Answer all 15 supervisor statements.');
            $answers=synk_sef_normalize_answers($answers); $comment=$input['comment_text'] ?? null;
            if (!synk_student_faculty_evaluation_has_comment($comment) || mb_strlen($comment,'UTF-8')>10000) throw new DomainException('Enter a comment using visible text and no more than 10,000 characters.');
            $old=synk_evaluation_query($conn,'SELECT * FROM tbl_supervisor_evaluations WHERE assignment_id=? AND assignment_revision=? FOR UPDATE','ii',[(int)$assignment['assignment_id'],(int)$assignment['revision']])[0] ?? null;
            if ($old && $old['submission_status']==='submitted') throw new DomainException('This evaluation has already been submitted and is locked.');
            if ((int)($old['version'] ?? 0)!==synk_sef_int($input,'version',0)) throw new DomainException('This draft changed in another tab. Reload before saving.');
            $context=synk_sef_context($conn,$scope,$term,$facultyId);
            $context['routing']=array_intersect_key($assignment,array_flip(['reviewer_user_ids','reviewer_faculty_ids','reviewer_role','reviewer_name','routing_reason','workload_programs']));
            $context['faculty_role']=$faculty['is_chair']?'program_chair':($faculty['is_dean']?'dean':'faculty');
            $snapshot=[];
            foreach (synk_sef_questions() as $key=>$question) $snapshot[$key]=$question+['rating'=>$answers[$key]];
            $params=[(int)$assignment['assignment_id'],(int)$assignment['revision'],$college,$ay,$semester,$facultyId,$actor['user_id'],$actor['faculty_id'],$actor['role'],$actor['name'],$faculty['faculty_name'],synk_sef_json($context),synk_sef_version(),synk_sef_json($snapshot),trim($comment),array_sum($answers),15];
            synk_evaluation_query($conn,'INSERT INTO tbl_supervisor_evaluations (assignment_id,assignment_revision,college_id,ay_id,semester,faculty_id,evaluator_user_id,evaluator_faculty_id,evaluator_role,evaluator_name,faculty_name,context_snapshot,instrument_version,answers_snapshot,comment_text,total_score,question_count)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE evaluator_user_id=VALUES(evaluator_user_id),evaluator_role=VALUES(evaluator_role),evaluator_faculty_id=VALUES(evaluator_faculty_id),evaluator_name=VALUES(evaluator_name),faculty_name=VALUES(faculty_name),context_snapshot=VALUES(context_snapshot),instrument_version=VALUES(instrument_version),answers_snapshot=VALUES(answers_snapshot),comment_text=VALUES(comment_text),total_score=VALUES(total_score),question_count=VALUES(question_count),version=version+1,updated_at=CURRENT_TIMESTAMP',
                'iiiiiiiisssssssii',$params);
            synk_sef_audit($conn,$scope,$term,$facultyId,'save_draft',$old,['assignment_id'=>$assignment['assignment_id'],'assignment_revision'=>$assignment['revision'],'version'=>(int)($old['version'] ?? 0)+1,'answers'=>$snapshot,'comment_text'=>trim($comment)]);
            synk_sef_commit($conn); return 'Supervisor evaluation draft saved.';
        }
        $versions=$input['draft_versions'] ?? null;
        if (($input['confirm_final'] ?? null)!=='1') throw new DomainException('Confirm that you reviewed the final supervisor evaluations.');
        if (!is_array($versions) || !$versions) throw new DomainException('Review your saved drafts before final submission.');
        $expected=[];
        foreach ($assignments as $facultyId=>$assignment) if (synk_sef_assignment_owned($actor,$assignment)) {
            $record=synk_evaluation_query($conn,'SELECT * FROM tbl_supervisor_evaluations WHERE assignment_id=? AND assignment_revision=? FOR UPDATE','ii',[(int)$assignment['assignment_id'],(int)$assignment['revision']])[0] ?? null;
            if ($record && $record['submission_status']==='submitted') continue;
            synk_sef_require_assignment($actor,$catalog[$facultyId] ?? null,$assignment,$reviewers);
            if (!$record) throw new DomainException('Save a complete draft for every assigned faculty before final submission.');
            if ((int)$record['evaluator_user_id']===$actor['user_id'] && (int)$record['evaluator_faculty_id']!==$actor['faculty_id']) throw new DomainException('Your faculty account link changed. Review and save your drafts again before final submission.');
            if ($record['instrument_version']!==synk_sef_version()) throw new DomainException('Update all drafts to the current supervisor instrument.');
            $snapshot=json_decode($record['answers_snapshot'],true);
            if (!is_array($snapshot)) throw new RuntimeException('The saved answer snapshot is unreadable.');
            $validated=synk_sef_normalize_answers(array_column($snapshot,'rating','question_key'));
            if ((int)$record['question_count']!==15 || (int)$record['total_score']!==array_sum($validated)) throw new DomainException('The draft totals do not match its answers. Save that draft again before final submission.');
            if (!synk_student_faculty_evaluation_has_comment($record['comment_text'])) throw new DomainException('Complete the comment for every assigned faculty.');
            if (!isset($versions[$facultyId]) || !is_string($versions[$facultyId]) || !hash_equals(synk_sef_review_token($record),$versions[$facultyId])) throw new DomainException('Your drafts or reviewer assignments changed after review. Review them again.');
            $expected[$facultyId]=$record;
        }
        if (!$expected || count($expected)!==count($versions)) throw new DomainException('The final submission list changed or was already submitted. Review your assigned faculty again.');
        foreach ($expected as $facultyId=>$record) {
            $token=bin2hex(random_bytes(24));
            synk_evaluation_query($conn,"UPDATE tbl_supervisor_evaluations SET submission_status='submitted',submission_token=?,evaluator_user_id=?,evaluator_faculty_id=?,evaluator_role=?,evaluator_name=?,submitted_at=UTC_TIMESTAMP(),version=version+1,updated_at=CURRENT_TIMESTAMP WHERE evaluation_id=?",'siissi',[$token,$actor['user_id'],$actor['faculty_id'],$actor['role'],$actor['name'],(int)$record['evaluation_id']]);
            synk_sef_audit($conn,$scope,$term,(int)$facultyId,'finalize',['evaluation_id'=>$record['evaluation_id'],'version'=>$record['version']],['evaluation_id'=>$record['evaluation_id'],'submission_token'=>$token]);
        }
        synk_sef_commit($conn); return 'Supervisor evaluations submitted. Your submitted ratings are now locked.';
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
function synk_sef_require_assignment(array $actor,?array $faculty,?array $assignment,array $reviewers): void
{
    if (!$faculty || !$assignment || $faculty['status']!=='active' || !synk_sef_assignment_owned($actor,$assignment) || $faculty['reviewer_role']!==$actor['role']) throw new DomainException('This faculty member is not assigned to you for supervisor evaluation.',403);
    if (!$actor['faculty_id'] || $actor['faculty_id']===$faculty['faculty_id'] || !isset($reviewers[$actor['role'].':'.$actor['user_id']])) throw new DomainException('Self-evaluation is not allowed and an active evaluator faculty link is required.',403);
    if (isset($assignment['reviewer_faculty_ids'][$actor['user_id']]) && (int)$assignment['reviewer_faculty_ids'][$actor['user_id']]!==$actor['faculty_id']) throw new DomainException('Your evaluator faculty link changed. Contact your administrator.',403);
}

function synk_sef_performance(mysqli $conn,array $scope,array $term,array $actor): array
{
    $catalog=synk_sef_catalog($conn,$scope,$term);
    $assignments=synk_sef_assignments($conn,$scope,$term);
    $records=synk_sef_records($conn,$scope,$term,$actor);
    $states=synk_evaluation_score_states($conn,(int)$term['ay_id'],(int)$term['semester']);
    $students=[];
    if (synk_evaluation_table_exists($conn,'tbl_student_faculty_evaluations')) {
        $owner=synk_evaluation_owner_sql($conn,'e',(int)$scope['college_id']);
        $headers=synk_evaluation_query($conn,"SELECT e.evaluation_id,e.faculty_id,e.faculty_name,e.total_score,e.question_count
            FROM tbl_student_faculty_evaluations e WHERE e.ay_id=? AND e.semester=? AND e.submission_status='submitted' AND e.instrument_version=? AND {$owner}",
            'iis',[$term['ay_id'],$term['semester'],synk_student_faculty_evaluation_current_version()]);
        foreach ($headers as $header) {
            $id=(int)$header['faculty_id'];
            if (!isset($catalog[$id])) $catalog[$id]=['faculty_id'=>$id,'faculty_name'=>$header['faculty_name'],'status'=>'historical','is_chair'=>false,'is_dean'=>false,'reviewer_role'=>'program_chair'];
            if (!isset($students[$id])) $students[$id]=['points'=>0,'answers'=>0,'submitted_count'=>0,'included_count'=>0,'excluded_count'=>0,'review_count'=>0];
            $students[$id]['submitted_count']++;
            $state=$states[(int)$header['evaluation_id']]['state'] ?? 'included';
            if ($state==='excluded') { $students[$id]['excluded_count']++; continue; }
            if ($state==='review') $students[$id]['review_count']++;
            $students[$id]['included_count']++; $students[$id]['points']+=(int)$header['total_score']; $students[$id]['answers']+=(int)$header['question_count'];
        }
    }
    foreach ($records as $id=>$record) if ($record['submission_status']==='submitted' && !isset($catalog[$id])) $catalog[$id]=['faculty_id'=>(int)$id,'faculty_name'=>$record['faculty_name'],'status'=>'historical','is_chair'=>($record['context']['faculty_role'] ?? '')==='program_chair','is_dean'=>($record['context']['faculty_role'] ?? '')==='dean','reviewer_role'=>$record['evaluator_role']];
    foreach ($catalog as $id=>&$faculty) {
        $student=$students[$id] ?? ['points'=>0,'answers'=>0,'submitted_count'=>0,'included_count'=>0,'excluded_count'=>0,'review_count'=>0];
        $student['score']=$student['answers']>0 ? ['points'=>$student['points'],'answers'=>$student['answers'],'mean'=>round($student['points']/$student['answers'],2),'component'=>round($student['points']/($student['answers']*5)*60,2)] : null;
        $record=$records[$id] ?? null;
        $supervisor=$record && $record['submission_status']==='submitted' ? synk_sef_score((float)$record['total_score'],(int)$record['question_count']) : null;
        $faculty['student']=$student; $faculty['supervisor']=$supervisor; $faculty['supervisor_record']=$supervisor?$record:null;
        $faculty['assignment']=$assignments[$id] ?? null;
        $faculty['total']=synk_sef_combined_score($student['score'],$supervisor);
        $faculty['result_status']=$faculty['total']!==null?'Complete':(!$student['score'] && !$supervisor?'Pending evaluations':(!$student['score']?'Pending student component':'Pending supervisor component'));
    }
    unset($faculty);
    uasort($catalog,static function(array $a,array $b):int{return strnatcasecmp($a['faculty_name'],$b['faculty_name']);});
    return $catalog;
}
function synk_sef_student_detail(mysqli $conn,array $scope,array $term,int $facultyId): array
{
    $owner=synk_evaluation_owner_sql($conn,'e',(int)$scope['college_id']);
    $params=[$term['ay_id'],$term['semester'],$facultyId,synk_student_faculty_evaluation_current_version()];
    $where="WHERE e.ay_id=? AND e.semester=? AND e.faculty_id=? AND e.instrument_version=? AND e.submission_status='submitted' AND {$owner}";
    $eligible=synk_evaluation_score_predicate(synk_evaluation_score_states($conn,(int)$term['ay_id'],(int)$term['semester']));
    $answers=synk_evaluation_query($conn,"SELECT a.category_key,a.category_title,SUM(a.rating) AS points,COUNT(*) AS answers
        FROM tbl_student_faculty_evaluations e JOIN tbl_student_faculty_evaluation_answers a ON a.evaluation_id=e.evaluation_id {$where} AND {$eligible} GROUP BY a.category_key,a.category_title",'iiis',$params);
    $comments=synk_evaluation_query($conn,"SELECT e.comment_text FROM tbl_student_faculty_evaluations e {$where} AND TRIM(e.comment_text)<>'' ORDER BY e.evaluation_id",'iiis',$params);
    return ['categories'=>array_column($answers,null,'category_key'),'comments'=>array_column($comments,'comment_text')];
}
