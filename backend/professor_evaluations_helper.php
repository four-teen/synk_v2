<?php
require_once __DIR__ . '/professor_portal_helper.php';
require_once __DIR__ . '/evaluation_access_helper.php';
require_once __DIR__ . '/student_faculty_evaluation_instrument.php';
require_once __DIR__ . '/evaluation_subject_exclusion_helper.php';
require_once __DIR__ . '/supervisor_evaluation_instrument.php';
require_once __DIR__ . '/professor_workload_students_helper.php';

function synk_professor_evaluation_identity(mysqli $conn): array
{
    if (($_SESSION['role'] ?? '')!=='professor' || empty($_SESSION['user_id'])) throw new DomainException('Sign in to your professor account to view evaluations.');
    $account=synk_find_useraccount_by_id($conn,(int)$_SESSION['user_id']);
    if (!$account || ($account['status'] ?? '')!=='active' || !synk_useraccount_has_active_role($conn,$account,'professor')) throw new DomainException('Your professor access is no longer active.');
    $portal=synk_professor_resolve_portal_context($conn);
    if (empty($portal['faculty_id']) || empty($portal['faculty_is_active'])) throw new DomainException('An active faculty record must be linked to your account to view evaluation results.');
    return $portal;
}

// The viewing scope follows the administrator's term and the linked account.
// Older query-string selectors never override this context.
function synk_professor_evaluation_current_scope(mysqli $conn): array
{
    $portal=synk_professor_evaluation_identity($conn);
    $term=synk_fetch_current_academic_term($conn);
    $rows=synk_evaluation_query($conn,"SELECT c.college_id,c.college_name,ay.ay_id,ay.ay AS ay_label
        FROM tbl_useraccount u JOIN tbl_useraccount_faculty_links l ON l.user_id=u.user_id
        JOIN tbl_faculty f ON f.faculty_id=l.faculty_id
        JOIN tbl_college c ON c.college_id=COALESCE(NULLIF(u.college_id,0),f.college_id) AND c.status='active'
        JOIN tbl_campus cp ON cp.campus_id=c.campus_id AND cp.status='active'
        JOIN tbl_academic_years ay ON ay.ay_id=?
        WHERE u.user_id=? AND f.faculty_id=?",'iii',[(int)($term['ay_id'] ?? 0),(int)$portal['user_id'],(int)$portal['faculty_id']]);
    if (!$rows || !in_array((int)($term['semester'] ?? 0),[1,2,3],true)) throw new DomainException('Ask your administrator to check your linked college and the current academic term.');
    return $rows[0]+['semester'=>(int)$term['semester']];
}

function synk_professor_evaluation_h($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }

function synk_professor_evaluation_submitted_students(mysqli $conn,array $term): array
{
    $portal=synk_professor_evaluation_identity($conn);
    $college=(int)$term['college_id']; $owner=synk_evaluation_owner_sql($conn,'e',$college);
    $rows=synk_evaluation_query($conn,"SELECT DISTINCT e.student_id FROM tbl_student_faculty_evaluations e
        JOIN tbl_college_evaluation_settings s ON s.college_id={$college} AND s.ay_id=e.ay_id AND s.semester=e.semester AND s.faculty_released=1
        WHERE e.faculty_id=? AND e.ay_id=? AND e.semester=? AND e.submission_status='submitted' AND {$owner}",'iii',
        [(int)$portal['faculty_id'],(int)$term['ay_id'],(int)$term['semester']]);
    return array_fill_keys(array_column($rows,'student_id'),true);
}

// Every report, panel and export resolves the current account/term/release again.
function synk_professor_evaluation_require_scope(mysqli $conn,array $expected): array
{
    $scope=synk_professor_evaluation_current_scope($conn);
    foreach (['college_id','ay_id','semester'] as $field) {
        if ((int)($expected[$field] ?? 0)!==(int)$scope[$field]) throw new DomainException('Your college or academic term has changed. Reload the page before continuing.',409);
    }
    if (empty(synk_evaluation_settings($conn,(int)$scope['college_id'],(int)$scope['ay_id'],(int)$scope['semester'])['faculty_released'])) throw new DomainException('Your Dean has hidden these evaluation results. Reload the page for the latest status.',403);
    return $scope;
}

function synk_professor_evaluation_class_context(mysqli $conn,int $offeringId,array $expected): array
{
    $scope=synk_professor_evaluation_require_scope($conn,$expected);
    foreach (synk_professor_evaluation_subjects($conn,$scope) as $class) {
        if ((int)$class['offering_id']===$offeringId) return ['scope'=>$scope,'class'=>$class];
    }
    throw new DomainException('This class is no longer available in your assigned workload.',404);
}

// One union of response IDs, using exactly the same subject/roster matching for
// individual class panels and the consolidated report. A shared response enters
// this union once, regardless of how many class cards contain it.
function synk_professor_evaluation_subject_response_ids(mysqli $conn,array $scope,array $classes): array
{
    $portal=synk_professor_evaluation_identity($conn);
    if (!$classes) return ['ids'=>[],'combined_count'=>0];
    $college=(int)$scope['college_id']; $ay=(int)$scope['ay_id']; $semester=(int)$scope['semester'];
    $offeringIds=array_column($classes,'offering_id');
    $subjects=synk_evaluation_query($conn,'SELECT po.offering_id,ps.sub_id FROM tbl_prospectus_offering po JOIN tbl_prospectus_subjects ps ON ps.ps_id=po.ps_id WHERE po.offering_id IN ('.implode(',',array_fill(0,count($offeringIds),'?')).')',str_repeat('i',count($offeringIds)),$offeringIds);
    $rosters=synk_professor_workload_student_memberships($conn,$classes,$ay,$semester);
    $subjectMembers=[];
    foreach ($subjects as $subject) {
        $id=(int)$subject['sub_id'];
        if ($id<=0) continue;
        if (!isset($subjectMembers[$id])) $subjectMembers[$id]=[];
        $subjectMembers[$id]+=$rosters[$subject['offering_id']] ?? [];
    }
    $owner=synk_evaluation_owner_sql($conn,'e',$college);
    $snapshot=synk_evaluation_table_exists($conn,'tbl_student_faculty_evaluation_subject_scope');
    $snapshotSelect=$snapshot?'ss.subjects_json':'NULL AS subjects_json';
    $snapshotJoin=$snapshot?'LEFT JOIN tbl_student_faculty_evaluation_subject_scope ss ON ss.evaluation_id=e.evaluation_id':'';
    $headers=synk_evaluation_query($conn,"SELECT e.evaluation_id,e.student_id,e.subject_summary,{$snapshotSelect}
        FROM tbl_student_faculty_evaluations e {$snapshotJoin}
        WHERE e.faculty_id=? AND e.ay_id=? AND e.semester=? AND e.submission_status='submitted' AND {$owner}",'iii',[(int)$portal['faculty_id'],$ay,$semester]);
    $evidence=synk_evaluation_query($conn,'SELECT es.student_id,es.subject_id,es.subject_code,es.descriptive_title
        FROM tbl_student_management_enrolled_subjects es JOIN tbl_college c ON c.college_id=es.college_id AND c.campus_id=es.campus_id
        WHERE es.faculty_id=? AND es.college_id=? AND es.ay_id=? AND es.semester=?','iiii',[(int)$portal['faculty_id'],$college,$ay,$semester]);
    $byStudent=[];
    foreach ($evidence as $row) $byStudent[$row['student_id']][]=$row;
    $ids=[]; $combined=0;
    foreach ($headers as $header) {
        $savedSubjects=$header['subjects_json']!==null ? json_decode($header['subjects_json'],true)
            : synk_evaluation_resolve_subjects((string)$header['subject_summary'],$byStudent[$header['student_id']] ?? []);
        if (!is_array($savedSubjects)) continue;
        foreach ($savedSubjects as $subject) {
            $id=(int)($subject['subject_id'] ?? 0);
            if ($id>0 && isset($subjectMembers[$id][$header['student_id']])) {
                $ids[]=(int)$header['evaluation_id'];
                if (count($savedSubjects)>1) $combined++;
                break;
            }
        }
    }
    return ['ids'=>array_values(array_unique($ids)),'combined_count'=>$combined];
}

function synk_professor_evaluation_consolidated_results(mysqli $conn,array $expected): array
{
    $scope=synk_professor_evaluation_require_scope($conn,$expected);
    $portal=synk_professor_evaluation_identity($conn);
    $classes=synk_professor_evaluation_subjects($conn,$scope);
    $linked=synk_professor_evaluation_subject_response_ids($conn,$scope,$classes);
    $groups=synk_professor_evaluation_results($conn,$scope,$linked['ids']);
    $members=[];
    foreach (synk_professor_workload_student_memberships($conn,$classes,(int)$scope['ay_id'],(int)$scope['semester']) as $roster) $members+=$roster;
    $submitted=synk_professor_evaluation_submitted_students($conn,$scope);
    $evaluated=count(array_intersect_key($members,$submitted));
    synk_professor_evaluation_require_scope($conn,$expected);
    $latestPortal=synk_professor_evaluation_identity($conn);
    $latestClasses=synk_professor_evaluation_subjects($conn,$scope);
    $before=array_column($classes,'offering_id'); $after=array_column($latestClasses,'offering_id'); sort($before); sort($after);
    if ((int)$latestPortal['faculty_id']!==(int)$portal['faculty_id'] || $before!==$after) throw new DomainException('Your teaching assignments have changed. Reload the page for current results.',409);
    return ['scope'=>$scope,'groups'=>$groups,'class_count'=>count($classes),'combined_count'=>$linked['combined_count'],
        'submitted_count'=>count($linked['ids']),'counts'=>['total'=>count($members),'evaluated'=>$evaluated,'not_evaluated'=>count($members)-$evaluated]];
}

function synk_professor_evaluation_class_detail(mysqli $conn,int $offeringId,string $view,array $expected): array
{
    $context=synk_professor_evaluation_class_context($conn,$offeringId,$expected);
    $scope=$context['scope']; $class=$context['class'];
    $portal=synk_professor_evaluation_identity($conn);
    $college=(int)$scope['college_id']; $ay=(int)$scope['ay_id']; $semester=(int)$scope['semester'];
    $rosters=synk_professor_workload_student_memberships($conn,[$class],$ay,$semester);
    $members=$rosters[$offeringId] ?? [];
    $submitted=synk_professor_evaluation_submitted_students($conn,$scope);
    $context['counts']=['total'=>count($members),'evaluated'=>count(array_intersect_key($members,$submitted)),
        'not_evaluated'=>count(array_diff_key($members,$submitted))];
    if ($view==='pending') {
        $ids=array_keys(array_diff_key($members,$submitted)); $students=[];
        if ($ids) {
            $students=synk_evaluation_query($conn,'SELECT student_number,first_name,middle_name,last_name,suffix_name FROM tbl_student_management
                WHERE student_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY last_name,first_name,middle_name,student_number',str_repeat('i',count($ids)),$ids);
        }
        $context['students']=array_map(static function(array $student):array {
            $name=implode(' ',array_filter(array_map('trim',[$student['first_name'],$student['middle_name'] ?? '',$student['last_name'],$student['suffix_name'] ?? '']),'strlen'));
            return ['student_number'=>(string)$student['student_number'],'full_name'=>$name];
        },$students);
    } elseif ($view==='results') {
        $linked=synk_professor_evaluation_subject_response_ids($conn,$scope,[$class]);
        $context['groups']=synk_professor_evaluation_results($conn,$scope,$linked['ids']);
        $context['combined_count']=$linked['combined_count'];
    } else throw new DomainException('Choose a valid evaluation view.',422);
    // Recheck assignment, release, college and term after loading sensitive data.
    $latest=synk_professor_evaluation_class_context($conn,$offeringId,$expected);
    if ((int)$latest['class']['faculty_id']!==(int)$portal['faculty_id']) throw new DomainException('Your linked faculty record has changed. Reload the page before continuing.',409);
    return $context;
}

function synk_professor_evaluation_pending_csv(array $detail): string
{
    // RFC 4180 quoting works on the deployed PHP 7.1 server as well as newer CLI
    // versions. PHP <7.4 rejects fputcsv's empty escape argument and writes nothing.
    $encodeRow=static function(array $cells):string {
        return implode(',',array_map(static function($cell):string {
            return '"'.str_replace('"','""',(string)$cell).'"';
        },$cells))."\r\n";
    };
    $csv="\xEF\xBB\xBF".$encodeRow(['Student number','Student name','Subject code','Subject title','Course','Section','Academic year','Semester']);
    foreach ($detail['students'] as $student) {
        $cells=[$student['student_number'],$student['full_name'],$detail['class']['subject_code'],$detail['class']['descriptive_title'],
            $detail['class']['program_label'],$detail['class']['section_display'],$detail['scope']['ay_label'],synk_semester_label((int)$detail['scope']['semester'])];
        $cells=array_map(static function($value):string {
            $value=(string)$value;
            return preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u',$value) ? "'".$value : $value;
        },$cells);
        $csv.=$encodeRow($cells);
    }
    return $csv;
}

function synk_professor_evaluation_pending_filename(array $detail): string
{
    $clean=static function(string $value,int $limit):string {
        $value=trim(preg_replace('/[<>:"\/\\\\|?*\x00-\x1F\x7F]+/u',' ',$value));
        $value=preg_replace('/\s+/u',' ',$value);
        while (strlen($value)>$limit) $value=preg_replace('/.$/us','',$value);
        return trim($value," .-");
    };
    $class=$detail['class'];
    $course=explode(' - ',(string)($class['program_label'] ?? ''))[0];
    $parts=[$clean((string)($class['subject_code'] ?? 'Subject'),24),$clean((string)($class['descriptive_title'] ?? ''),80),
        $clean($course,24),$clean((string)($class['section_display'] ?? 'Section'),48),'Not evaluated'];
    return implode(' - ',array_filter($parts,'strlen')).'.csv';
}

// Completion counts use actual named rosters, not recorded enrollment estimates.
// A submitted faculty evaluation counts once for each class that student takes.
function synk_professor_evaluation_completion(mysqli $conn,array $term,array $classes): array
{
    if (!$classes) return [];
    $college=(int)$term['college_id'];
    $evaluated=synk_professor_evaluation_submitted_students($conn,$term);
    $rosters=synk_professor_workload_student_memberships($conn,$classes,(int)$term['ay_id'],(int)$term['semester']);
    $counts=[];
    foreach ($rosters as $offering=>$members) {
        $total=count($members); $done=count(array_intersect_key($members,$evaluated));
        $counts[$offering]=['total'=>$total,'evaluated'=>$done,'not_evaluated'=>$total-$done];
    }
    if (empty(synk_evaluation_settings($conn,$college,(int)$term['ay_id'],(int)$term['semester'])['faculty_released'])) return [];
    return $counts;
}

// Only the Dean's released college/term combinations are returned.
function synk_professor_evaluation_options(mysqli $conn, int $facultyId): array
{
    if (!synk_evaluation_table_exists($conn,'tbl_college_evaluation_settings')
        || !synk_evaluation_has_ownership($conn)) return [];
    $assignmentSource=synk_professor_workload_assignment_source_sql();
    $supervisorExists=synk_evaluation_table_exists($conn,'tbl_supervisor_evaluations')
        && synk_evaluation_table_exists($conn,'tbl_supervisor_evaluation_assignments')
        ? "OR EXISTS (SELECT 1 FROM tbl_supervisor_evaluations se
            JOIN tbl_supervisor_evaluation_assignments sa ON sa.assignment_id=se.assignment_id AND sa.revision=se.assignment_revision
            WHERE se.faculty_id={$facultyId} AND se.college_id=s.college_id AND se.ay_id=s.ay_id AND se.semester=s.semester AND se.submission_status='submitted')" : '';
    return synk_evaluation_query($conn,"SELECT s.college_id,s.ay_id,s.semester,c.college_name,ay.ay AS ay_label
        FROM tbl_college_evaluation_settings s JOIN tbl_college c ON c.college_id=s.college_id
        JOIN tbl_campus cp ON cp.campus_id=c.campus_id JOIN tbl_academic_years ay ON ay.ay_id=s.ay_id
        WHERE s.faculty_released=1 AND c.status='active' AND cp.status='active'
        AND (EXISTS (SELECT 1 FROM tbl_student_faculty_evaluations e
            WHERE e.faculty_id=? AND e.submission_status='submitted' AND e.ay_id=s.ay_id AND e.semester=s.semester
            AND (e.college_id=s.college_id OR (e.college_id=0
                AND EXISTS (SELECT 1 FROM tbl_student_management_enrolled_subjects es WHERE es.student_id=e.student_id
                    AND es.faculty_id=e.faculty_id AND es.ay_id=e.ay_id AND es.semester=e.semester AND es.college_id=s.college_id AND es.campus_id=c.campus_id)
                AND NOT EXISTS (SELECT 1 FROM tbl_student_management_enrolled_subjects es WHERE es.student_id=e.student_id
                    AND es.faculty_id=e.faculty_id AND es.ay_id=e.ay_id AND es.semester=e.semester AND es.college_id>0 AND es.college_id<>s.college_id))))
        {$supervisorExists}
        OR EXISTS (SELECT 1 {$assignmentSource} JOIN tbl_program p ON p.program_id=po.program_id
            WHERE fw.faculty_id={$facultyId} AND fw.ay_id=s.ay_id AND fw.semester=s.semester AND p.college_id=s.college_id))
        ORDER BY ay.ay DESC,s.ay_id DESC,s.semester DESC,c.college_name",'i',[$facultyId]);
}

// Reuse the workload's subject presentation and merge rules, scoped by college ID.
function synk_professor_evaluation_subjects(mysqli $conn,array $term): array
{
    $portal=synk_professor_evaluation_identity($conn);
    $facultyId=(int)$portal['faculty_id'];
    $source=synk_professor_workload_assignment_source_sql();
    $allowed=synk_evaluation_query($conn,"SELECT DISTINCT po.offering_id {$source}
        JOIN tbl_program p ON p.program_id=po.program_id
        JOIN tbl_college_evaluation_settings s ON s.college_id=p.college_id AND s.ay_id=fw.ay_id AND s.semester=fw.semester AND s.faculty_released=1
        WHERE fw.faculty_id=? AND fw.ay_id=? AND fw.semester=? AND p.college_id=?",'iiii',
        [$facultyId,(int)$term['ay_id'],(int)$term['semester'],(int)$term['college_id']]);
    $ids=array_fill_keys(array_column($allowed,'offering_id'),true);
    $rows=synk_professor_fetch_subject_rows_by_academic_year($conn,$facultyId,(int)$term['ay_id'],(int)$term['semester']);
    return array_values(array_filter($rows,static function(array $row)use($ids):bool { return isset($ids[$row['offering_id']]); }));
}

// Faculty-only projection. Never use the Dean/Chair reviewer API in this portal.
function synk_professor_supervisor_results(mysqli $conn,array $term): array
{
    $portal=synk_professor_evaluation_identity($conn);
    if (!synk_evaluation_table_exists($conn,'tbl_supervisor_evaluations')
        || !synk_evaluation_table_exists($conn,'tbl_supervisor_evaluation_assignments')) return [];
    $records=synk_evaluation_query($conn,"SELECT e.instrument_version,e.answers_snapshot,e.comment_text,e.total_score,e.question_count
        FROM tbl_supervisor_evaluations e
        JOIN tbl_supervisor_evaluation_assignments a ON a.assignment_id=e.assignment_id AND a.revision=e.assignment_revision
        JOIN tbl_college_evaluation_settings s ON s.college_id=e.college_id AND s.ay_id=e.ay_id AND s.semester=e.semester AND s.faculty_released=1
        JOIN tbl_college c ON c.college_id=s.college_id AND c.status='active'
        JOIN tbl_campus cp ON cp.campus_id=c.campus_id AND cp.status='active'
        WHERE e.faculty_id=? AND e.college_id=? AND e.ay_id=? AND e.semester=? AND e.submission_status='submitted'",'iiii',
        [(int)$portal['faculty_id'],(int)$term['college_id'],(int)$term['ay_id'],(int)$term['semester']]);
    $groups=[];
    foreach ($records as $record) {
        $categories=[];
        foreach (json_decode($record['answers_snapshot'],true) ?: [] as $question) {
            $key=$question['category_key'];
            if (!isset($categories[$key])) $categories[$key]=['title'=>$question['category_title'],'points'=>0,'answers'=>0,'questions'=>[]];
            $categories[$key]['points']+=(int)$question['rating']; $categories[$key]['answers']++;
            $categories[$key]['questions'][]=['text'=>$question['text'],'order'=>(int)$question['order'],'response_count'=>1,
                'score'=>synk_sef_score((float)$question['rating'],1)];
        }
        foreach ($categories as &$category) $category['score']=synk_sef_score($category['points'],$category['answers']);
        unset($category);
        $groups[]=['instrument_version'=>$record['instrument_version'],
            'instrument'=>['label'=>$record['instrument_version']===synk_sef_version() ? synk_sef_instrument()['label'] : $record['instrument_version']],
            'submitted_count'=>1,'response_count'=>1,'score'=>synk_sef_score((float)$record['total_score'],(int)$record['question_count']),
            'categories'=>$categories,'comments'=>trim($record['comment_text'])!=='' ? [$record['comment_text']] : []];
    }
    if (empty(synk_evaluation_settings($conn,(int)$term['college_id'],(int)$term['ay_id'],(int)$term['semester'])['faculty_released'])) return [];
    return $groups;
}

function synk_professor_evaluation_results(mysqli $conn, array $term,?array $evaluationIds=null): array
{
    // Re-read account and faculty link here. A supplied faculty_id is never trusted.
    $portal=synk_professor_evaluation_identity($conn);
    $facultyId=(int)$portal['faculty_id']; $collegeId=(int)$term['college_id'];
    $owner=synk_evaluation_owner_sql($conn,'e',$collegeId);
    // Release is checked in each data query, including comments and question means.
    $source="FROM tbl_student_faculty_evaluations e
        JOIN tbl_college_evaluation_settings s ON s.college_id={$collegeId} AND s.ay_id=e.ay_id AND s.semester=e.semester AND s.faculty_released=1
        JOIN tbl_college c ON c.college_id=s.college_id AND c.status='active'
        JOIN tbl_campus cp ON cp.campus_id=c.campus_id AND cp.status='active'
        WHERE e.faculty_id=? AND e.ay_id=? AND e.semester=? AND e.submission_status='submitted' AND {$owner}";
    $params=[$facultyId,(int)$term['ay_id'],(int)$term['semester']];
    if ($evaluationIds!==null) {
        $ids=array_values(array_unique(array_filter(array_map('intval',$evaluationIds),static function(int $id):bool{return $id>0;})));
        $source.=' AND e.evaluation_id IN ('.($ids?implode(',',$ids):'0').')';
    }
    $eligibleSql=synk_evaluation_score_predicate(synk_evaluation_score_states($conn,(int)$term['ay_id'],(int)$term['semester']));
    $headers=synk_evaluation_query($conn,"SELECT e.instrument_version,COUNT(*) AS submitted_count,
        SUM(CASE WHEN {$eligibleSql} THEN 1 ELSE 0 END) AS response_count,
        SUM(CASE WHEN {$eligibleSql} THEN e.total_score ELSE 0 END) AS points,
        SUM(CASE WHEN {$eligibleSql} THEN e.question_count ELSE 0 END) AS answers {$source} GROUP BY e.instrument_version",'iii',$params);
    $answerSource=str_replace('WHERE e.faculty_id','JOIN tbl_student_faculty_evaluation_answers a ON a.evaluation_id=e.evaluation_id WHERE e.faculty_id',$source).' AND '.$eligibleSql;
    $questions=synk_evaluation_query($conn,"SELECT e.instrument_version,a.category_key,a.category_title,a.question_key,a.question_order,a.question_text,SUM(a.rating) AS points,COUNT(*) AS answers {$answerSource} GROUP BY e.instrument_version,a.category_key,a.category_title,a.question_key,a.question_order,a.question_text ORDER BY a.question_order",'iii',$params);
    $comments=synk_evaluation_query($conn,"SELECT e.instrument_version,e.comment_text {$source} AND TRIM(COALESCE(e.comment_text,''))<>'' ORDER BY e.comment_text",'iii',$params);
    $groups=[];
    foreach ($headers as $header) {
        $version=$header['instrument_version'];
        $groups[$version]=['instrument_version'=>$version,'instrument'=>synk_student_faculty_evaluation_instrument($version),
            'total_points'=>(float)$header['points'],'answer_count'=>(int)$header['answers'],
            'submitted_count'=>(int)$header['submitted_count'],'response_count'=>(int)$header['response_count'],'score'=>(int)$header['answers']>0 ? synk_student_faculty_evaluation_score((float)$header['points'],(int)$header['answers']) : null,
            'categories'=>[],'comments'=>[]];
    }
    foreach ($questions as $question) {
        $version=$question['instrument_version']; $key=$question['category_key'];
        if (!isset($groups[$version])) continue;
        if (!isset($groups[$version]['categories'][$key])) $groups[$version]['categories'][$key]=['title'=>$question['category_title'],'points'=>0,'answers'=>0,'questions'=>[]];
        $category=&$groups[$version]['categories'][$key];
        $category['points']+=(float)$question['points']; $category['answers']+=(int)$question['answers'];
        $category['questions'][]=['text'=>$question['question_text'],'order'=>(int)$question['question_order'],'response_count'=>(int)$question['answers'],
            'score'=>synk_student_faculty_evaluation_score((float)$question['points'],(int)$question['answers'])];
        unset($category);
    }
    foreach ($groups as &$group) foreach ($group['categories'] as &$category) $category['score']=synk_student_faculty_evaluation_score($category['points'],$category['answers']);
    unset($group,$category);
    foreach ($comments as $comment) if (isset($groups[$comment['instrument_version']])) $groups[$comment['instrument_version']]['comments'][]=$comment['comment_text'];
    // A concurrent hide must suppress the already-read result as well.
    if (empty(synk_evaluation_settings($conn,$collegeId,(int)$term['ay_id'],(int)$term['semester'])['faculty_released'])) return [];
    return array_values($groups);
}
