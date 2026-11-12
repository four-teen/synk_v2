<?php
require_once __DIR__.'/db_lms.php';
require_once __DIR__.'/professor_class_helper_lms.php';
require_once __DIR__.'/gradebook_helper_lms.php';

function synk_gradebook_validate_scores_lms(array $entries, array $students, array $item, bool $attendanceSheet = false): array
{
    if (!$entries || count($entries)>1000 || array_values($entries)!==$entries) throw new DomainException('Save between 1 and 1,000 student scores at a time.',422);
    $validated=[];
    foreach ($entries as $entry) {
        if (!is_array($entry) || array_diff(array_keys($entry),['student_id','points','remark'])) throw new DomainException('Enter a valid student score.',422);
        $studentId=synk_grading_integer_lms($entry['student_id']??null);
        if (!isset($students[$studentId])) throw new DomainException('A student is not in this class roster. Reload the gradebook.',422);
        if (isset($validated[$studentId])) throw new DomainException('A student can appear only once in a score save.',422);
        $points=$entry['points']??null;
        if ($points==='') $points=null;
        if ($points!==null) $points=synk_gradebook_points_lms($points)/100;
        if ($attendanceSheet && $points===null) throw new DomainException('Mark every student present or absent before saving attendance.',422);
        if ($points!==null && $points>$item['max_points']) throw new DomainException('A score cannot exceed this entry\'s maximum points.',422);
        if ($points!==null && $item['entry_kind']==='attendance' && $points!=0 && $points!=1) throw new DomainException('Attendance is present = 1 or absent = 0. Leave unmarked attendance blank.',422);
        $remark=synk_grading_text_lms($entry['remark']??'','Score note',1000);
        if ($points!==null && $item['entry_kind']==='lms' && $remark==='') throw new DomainException('Add a note when manually grading or overriding an LMS result.',422);
        $validated[$studentId]=['student_id'=>$studentId,'points'=>$points,'remark'=>$remark];
    }
    if ($attendanceSheet && count($validated)!==count($students)) throw new DomainException('The class roster changed. Reload before recording attendance; every current student must be included.',422);
    return $validated;
}
function synk_gradebook_write_scores_lms(mysqli $lms, int $itemId, array $entries, int $actor, string $now): void
{
    foreach ($entries as $entry) {
        if ($entry['points']===null) synk_grading_query_lms($lms,'DELETE FROM tbl_lms_gradebook_scores WHERE item_id=? AND student_id=?','ii',[$itemId,$entry['student_id']]);
        else synk_grading_query_lms($lms,'INSERT INTO tbl_lms_gradebook_scores (item_id,student_id,points,remark,actor_user_id,updated_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE points=VALUES(points),remark=VALUES(remark),actor_user_id=VALUES(actor_user_id),updated_at=VALUES(updated_at)','iidsis',[$itemId,$entry['student_id'],$entry['points'],$entry['remark'],$actor,$now]);
    }
}

function synk_gradebook_mutate_lms(mysqli $core, mysqli $lms, array $context, string $action, array $payload, int $actor): array
{
    $period=synk_grading_period_lms($payload['grading_period']??null);
    $book=synk_gradebook_period_lms($lms,$context,$period,true);
    if (synk_grading_integer_lms($payload['version']??null)!==$book['version'] || synk_grading_integer_lms($payload['scheme_version']??null)!==$book['scheme']['version']) throw new DomainException('This gradebook changed in another tab. Reload before saving; your entries are kept on this page.',409);
    $scheme=$book['scheme'];$now=synk_assessment_now_lms();$details=[];
    if (!$scheme['scheme_id'] || !$scheme['components'] || abs($scheme['total_weight']-100)>0.00000001) throw new DomainException('Save a grading system totaling 100% for this period before recording scores.',422);
    if (!$book['book_id']) {
        synk_grading_query_lms($lms,'INSERT INTO tbl_lms_gradebook_periods (faculty_id,offering_id,ay_id,semester,grading_period,scheme_id,scheme_version,scheme_snapshot_json,version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,0,?,?)','iiiisiisss',array_merge(synk_grading_scope_lms($context),[$period,$scheme['scheme_id'],$scheme['version'],json_encode($scheme,JSON_UNESCAPED_UNICODE),$now,$now]));
        $book['book_id']=(int)$lms->insert_id;
    }
    $items=synk_gradebook_items_lms($lms,$book['book_id']);$itemMap=array_column($items,null,'item_id');$components=array_column($scheme['components'],null,'component_id');
    if ($action==='save_item' || $action==='save_attendance') {
        $id=synk_grading_integer_lms($payload['item_id']??0);$before=$itemMap[$id]??null;
        if ($id && !$before) throw new DomainException('This entry is unavailable in this gradebook.',404);
        if (!$id && count($items)>=300) throw new DomainException('A period can contain up to 300 active entries.',422);
        $component=synk_grading_integer_lms($payload['component_id']??null);
        if (!isset($components[$component])) throw new DomainException('Choose a component from this period\'s grading system.',422);
        $kind=$before?$before['entry_kind']:($action==='save_attendance'?'attendance':($payload['entry_kind']??''));
        if ($action==='save_attendance' && $kind!=='attendance') throw new DomainException('Choose an attendance entry from this grading period.',422);
        if (!is_string($kind) || !in_array($kind,['score','attendance','lms'],true)) throw new DomainException('Choose a manual score, attendance, or LMS assessment.',422);
        $title=synk_grading_text_lms($payload['title']??'','Entry title',200,true);
        $assessment=$before?$before['assessment_id']:null;
        $max=$action==='save_attendance'?1:synk_gradebook_points_lms($payload['max_points']??null,false)/100;
        if ($kind==='lms') {
            if ($before) $max=$before['max_points'];
            else {
                $assessment=synk_assessment_id_lms($payload['assessment_id']??null);
                $record=synk_assessment_detail_lms($lms,$context,$assessment,true);
                if ($record['grading_period']!==$period || !in_array($record['status'],['published','closed'],true)) throw new DomainException('Choose a published or closed LMS assessment from this class and grading period.',422);
                $max=synk_gradebook_points_lms($record['total_points'],false)/100;
                if (synk_grading_query_lms($lms,'SELECT i.item_id FROM tbl_lms_gradebook_items i JOIN tbl_lms_gradebook_periods b ON b.book_id=i.book_id WHERE b.faculty_id=? AND b.offering_id=? AND b.ay_id=? AND b.semester=? AND i.assessment_id=?','iiiii',array_merge(synk_grading_scope_lms($context),[$assessment]))) throw new DomainException('This LMS assessment has already been linked in this class, including archived entries. It cannot count twice across grading periods.',422);
            }
        } elseif ($kind==='attendance' && $max!=1) throw new DomainException('An attendance session uses 1 point: present = 1, absent = 0. Use a manual score entry for attendance totals or other credit.',422);
        if ($before) {
            $higher=synk_grading_query_lms($lms,'SELECT score_id FROM tbl_lms_gradebook_scores WHERE item_id=? AND points>? LIMIT 1','id',[$id,$max]);
            if ($higher) throw new DomainException('The new maximum is below an existing score. Correct the scores before lowering the maximum.',422);
        }
        $date=$payload['recorded_date']??null;
        if ($date==='') $date=null;
        if ($action==='save_attendance' && $date===null) throw new DomainException('Choose the attendance date.',422);
        if ($date!==null) {
            if (!is_string($date)) throw new DomainException('Enter a valid entry date.',422);
            $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            if (!$parsed || $parsed->format('Y-m-d')!==$date || substr($date,0,4)<'2000' || substr($date,0,4)>'2100') throw new DomainException('Enter a valid entry date between 2000 and 2100.',422);
        }
        if ($action==='save_attendance') {
            if (!is_array($payload['scores']??null)) throw new DomainException('Include the class attendance sheet.',422);
            $students=array_column(synk_gradebook_roster_lms($core,$context),null,'student_id');
            $validated=synk_gradebook_validate_scores_lms($payload['scores'],$students,['entry_kind'=>'attendance','max_points'=>1],true);
            $previousScores=$id?synk_grading_query_lms($lms,'SELECT student_id,points,remark FROM tbl_lms_gradebook_scores WHERE item_id=?','i',[$id]):[];
        }
        if ($id) synk_grading_query_lms($lms,'UPDATE tbl_lms_gradebook_items SET component_id=?,title=?,max_points=?,recorded_date=?,updated_at=? WHERE item_id=? AND book_id=?','isdssii',[$component,$title,$max,$date,$now,$id,$book['book_id']]);
        else {
            synk_grading_query_lms($lms,'INSERT INTO tbl_lms_gradebook_items (book_id,component_id,title,entry_kind,assessment_id,max_points,recorded_date,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)','iissidsss',[$book['book_id'],$component,$title,$kind,$assessment,$max,$date,$now,$now]);
            $id=(int)$lms->insert_id;
        }
        $details=['before'=>$before,'after'=>['item_id'=>$id,'component_id'=>$component,'title'=>$title,'entry_kind'=>$kind,'assessment_id'=>$assessment,'max_points'=>$max,'recorded_date'=>$date]];
        if ($action==='save_attendance') {
            synk_gradebook_write_scores_lms($lms,$id,$validated,$actor,$now);
            $details['scores']=['before'=>$previousScores,'after'=>array_values($validated)];
        }
    } elseif ($action==='archive_item') {
        $id=synk_grading_integer_lms($payload['item_id']??null);
        if (!isset($itemMap[$id])) throw new DomainException('This entry is unavailable in this period.',404);
        synk_grading_query_lms($lms,"UPDATE tbl_lms_gradebook_items SET status='archived',updated_at=? WHERE item_id=? AND book_id=?",'sii',[$now,$id,$book['book_id']]);
        $details=['before'=>$itemMap[$id],'after'=>['status'=>'archived']];
    } elseif ($action==='save_scores') {
        $id=synk_grading_integer_lms($payload['item_id']??null);
        if (!isset($itemMap[$id])) throw new DomainException('This entry is unavailable in this period.',404);
        $item=$itemMap[$id];$students=array_column(synk_gradebook_roster_lms($core,$context),null,'student_id');
        $entries=$payload['scores']??null;
        if (!is_array($entries)) throw new DomainException('Include the student scores.',422);
        $validated=synk_gradebook_validate_scores_lms($entries,$students,$item);
        $before=synk_grading_query_lms($lms,'SELECT student_id,points,remark FROM tbl_lms_gradebook_scores WHERE item_id=?','i',[$id]);
        synk_gradebook_write_scores_lms($lms,$id,$validated,$actor,$now);
        $details=['item_id'=>$id,'before'=>$before,'after'=>array_values($validated)];
    } elseif ($action==='use_latest_scheme') {
        $latest=$book['latest_scheme'];$latestIds=array_column($latest['components'],null,'component_id');
        if (!$latest['scheme_id'] || $latest['total_weight']!=100) throw new DomainException('Save a complete grading system before applying it.',422);
        foreach ($items as $item) if (!isset($latestIds[$item['component_id']])) throw new DomainException('A recorded entry uses a removed component. Edit that entry to move it to a retained component, or archive it, before applying the new grading system.',422);
        synk_grading_query_lms($lms,'UPDATE tbl_lms_gradebook_periods SET scheme_id=?,scheme_version=?,scheme_snapshot_json=? WHERE book_id=?','iisi',[$latest['scheme_id'],$latest['version'],json_encode($latest,JSON_UNESCAPED_UNICODE),$book['book_id']]);
        $details=['before'=>$scheme,'after'=>$latest];
    }
    $version=$book['version']+1;
    synk_grading_query_lms($lms,'UPDATE tbl_lms_gradebook_periods SET version=?,updated_at=? WHERE book_id=?','isi',[$version,$now,$book['book_id']]);
    synk_grading_query_lms($lms,'INSERT INTO tbl_lms_gradebook_logs (book_id,actor_user_id,version,action,details_json,recorded_at) VALUES (?,?,?,?,?,?)','iiisss',[$book['book_id'],$actor,$version,$action,json_encode($details,JSON_UNESCAPED_UNICODE),$now]);
    return in_array($action,['save_item','save_attendance'],true)?['saved_item_id'=>$id]:[];
}
function synk_gradebook_request_lms(mysqli $core, array $input): array
{
    if (($_SESSION['role']??'')!=='professor' || empty($_SESSION['user_id'])) throw new DomainException('Sign in as a professor to continue.',401);
    if (empty($_SESSION['lms_gradebook_csrf']) || !is_string($input['csrf_token']??null) || !hash_equals($_SESSION['lms_gradebook_csrf'],$input['csrf_token'])) throw new DomainException('Reload the gradebook before continuing.',403);
    if (array_diff(array_keys($input),['action','context','payload','csrf_token'])) throw new DomainException('This request contains unsupported gradebook fields.',400);
    if (!is_array($input['context']??null) || ($input['context']['view']??'')!=='gradebook') throw new DomainException('Open Grade Book from your class workspace.',403);
    $action=$input['action']??null;
    if (!is_string($action) || !in_array($action,['get','save_item','save_attendance','archive_item','save_scores','use_latest_scheme'],true)) throw new DomainException('Choose an available gradebook action.',400);
    $context=synk_professor_class_context_lms($core,$input['context']);$lms=synk_database_connection_lms($core);
    if (!synk_gradebook_ready_lms($lms)) throw new DomainException('The gradebook storage is not ready yet. Contact your administrator.',503);
    $payload=$input['payload']??[];
    $fields=['get'=>[],'save_item'=>['grading_period','version','scheme_version','item_id','component_id','title','entry_kind','assessment_id','max_points','recorded_date'],'archive_item'=>['grading_period','version','scheme_version','item_id'],'save_scores'=>['grading_period','version','scheme_version','item_id','scores'],'use_latest_scheme'=>['grading_period','version','scheme_version']];
    $fields['save_attendance']=['grading_period','version','scheme_version','item_id','component_id','title','recorded_date','scores'];
    if (!is_array($payload) || array_diff(array_keys($payload),$fields[$action])) throw new DomainException('Enter valid gradebook details.',400);
    if (!$lms->begin_transaction()) throw new RuntimeException('Unable to begin the gradebook request.');
    try {
        $mutation=$action==='get'?[]:synk_gradebook_mutate_lms($core,$lms,$context,$action,$payload,(int)$_SESSION['user_id']);
        $data=synk_gradebook_workspace_lms($core,$lms,$context)+$mutation;
        if (!$lms->commit()) throw new RuntimeException('Unable to complete the gradebook request.');
        return $data;
    } catch (Throwable $error) {
        $lms->rollback();
        if ($error instanceof mysqli_sql_exception && in_array((int)$error->getCode(),[1062,1205,1213],true)) throw new DomainException('This gradebook is being updated in another tab. Reload before saving.',409);
        throw $error;
    }
}
