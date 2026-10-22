<?php
require_once __DIR__.'/college_dean_students_helper.php';
require_once __DIR__.'/student_workload_helper.php';

function synk_dean_student_workload_filters(array $input): array
{
    $search=trim((string)($input['search']??''));
    if (strlen($search)>600 || preg_match('//u',$search)!==1) throw new DomainException('Use a valid student name or ID.');
    $signal=(string)($input['signal']??'all'); $sort=(string)($input['sort']??'review');
    if (!in_array($signal,['all','review','conflicts'],true) || !in_array($sort,['review','subjects','units','name'],true)) throw new DomainException('Choose valid workload filters.');
    $page=filter_var($input['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100000]]);
    if ($page===false) throw new DomainException('Choose a valid results page.');
    return ['search'=>$search,'signal'=>$signal,'sort'=>$sort,'page'=>$page];
}

function synk_dean_student_workload(mysqli $conn, array $scope, array $term, array $filters): array
{
    $members=synk_student_workload_query($conn,synk_student_workload_select_sql().'
        WHERE es.is_active=1 AND es.college_id=? AND es.campus_id=? AND p.college_id=es.college_id AND es.ay_id=? AND es.semester=?
        ORDER BY s.last_name,s.first_name,s.middle_name,es.subject_code,es.student_enrollment_id','iiii',
        [$scope['college_id'],$scope['campus_id'],$term['ay_id'],$term['semester']]);
    $students=[];
    foreach ($members as $member) $students[(int)$member['student_id']][]=$member;
    $qualifying=[]; $offeringIds=[];
    foreach ($students as $id=>$rows) {
        $totals=synk_student_workload_totals($rows);
        if ($totals['subjects']<6) continue;
        $qualifying[$id]=['rows'=>$rows,'totals'=>$totals];
        foreach ($rows as $row) $offeringIds[]=(int)$row['offering_id'];
    }
    $schedules=synk_student_workload_schedules($conn,$offeringIds,$term);
    $results=[]; $summary=['students'=>count($qualifying),'needs_review'=>0,'schedule_conflicts'=>0,'highest_subjects'=>0,'highest_units'=>0.0];
    foreach ($qualifying as $id=>$entry) {
        $rows=$entry['rows']; $totals=$entry['totals']; $student=$rows[0];
        $programs=[]; $years=[]; $sections=[]; $subjects=[]; $codes=[];
        foreach ($rows as $row) {
            $programs[(int)$row['program_id']]=$row['program_code'];
            if ((int)$row['year_level']>0) $years[(int)$row['year_level']]=true;
            if (trim($row['section_text'])!=='') $sections[$row['section_text']]=true;
            $subjects[synk_student_workload_subject_key($row)][$row['student_enrollment_id']]=true;
            $codes[strtoupper(preg_replace('/\s+/u','',$row['subject_code']))][$row['student_enrollment_id']]=true;
        }
        $duplicates=max(count(array_filter($subjects,static function($items){return count($items)>1;})),count(array_filter($codes,static function($items){return count($items)>1;})));
        $conflicts=synk_student_workload_conflicts($rows,$schedules); $flags=[]; $score=0;
        if ($conflicts) { $flags[]=['kind'=>'conflict','label'=>count($conflicts).' schedule overlap'.(count($conflicts)===1?'':'s')]; $score+=100+count($conflicts); $summary['schedule_conflicts']++; }
        if ($duplicates) { $flags[]=['kind'=>'duplicate','label'=>$duplicates.' repeated subject'.($duplicates===1?'':'s')]; $score+=50; }
        if (count($programs)>1) { $flags[]=['kind'=>'program','label'=>count($programs).' programs']; $score+=10; }
        if (count($years)>1) { $flags[]=['kind'=>'year','label'=>count($years).' year levels']; $score+=5; }
        if (count($sections)>=4) { $flags[]=['kind'=>'section','label'=>count($sections).' sections']; $score+=3; }
        if ($score) $summary['needs_review']++;
        $summary['highest_subjects']=max($summary['highest_subjects'],$totals['subjects']);
        $summary['highest_units']=max($summary['highest_units'],$totals['units']);
        $results[]=['student_id'=>$id,'student_number'=>(string)$student['student_number'],'student_name'=>synk_dean_students_name($student),
            'subject_count'=>$totals['subjects'],'units'=>$totals['units'],'weekly_hours'=>$totals['weekly_hours'],'pending_subjects'=>$totals['pending_subjects'],
            'programs'=>array_values($programs),'sections'=>array_keys($sections),'flags'=>$flags,'score'=>$score,'conflicts'=>$conflicts];
    }
    $search=preg_split('/\s+/u',function_exists('mb_strtolower')?mb_strtolower($filters['search'],'UTF-8'):strtolower($filters['search']),-1,PREG_SPLIT_NO_EMPTY);
    $results=array_values(array_filter($results,static function($row)use($filters,$search){
        if ($filters['signal']==='review' && !$row['score']) return false;
        if ($filters['signal']==='conflicts' && !$row['conflicts']) return false;
        $haystack=$row['student_number'].' '.$row['student_name'];
        $haystack=function_exists('mb_strtolower')?mb_strtolower($haystack,'UTF-8'):strtolower($haystack);
        foreach ($search as $word) if (strpos($haystack,$word)===false) return false;
        return true;
    }));
    usort($results,static function($a,$b)use($filters){
        $name=strcmp($a['student_name'],$b['student_name'])?:($a['student_id']<=>$b['student_id']);
        if ($filters['sort']==='name') return $name;
        if ($filters['sort']==='units') return ($b['units']<=>$a['units'])?:($b['subject_count']<=>$a['subject_count'])?:$name;
        if ($filters['sort']==='subjects') return ($b['subject_count']<=>$a['subject_count'])?:($b['units']<=>$a['units'])?:$name;
        return ($b['score']<=>$a['score'])?:($b['subject_count']<=>$a['subject_count'])?:($b['units']<=>$a['units'])?:$name;
    });
    $total=count($results); $pageSize=10; $pages=max(1,(int)ceil($total/$pageSize)); $page=min($pages,$filters['page']); $offset=($page-1)*$pageSize;
    return ['rows'=>array_slice($results,$offset,$pageSize),'summary'=>$summary,
        'pagination'=>['page'=>$page,'pages'=>$pages,'total'=>$total,'from'=>$total?$offset+1:0,'to'=>min($offset+$pageSize,$total)],
        'term'=>['ay_id'=>(int)$term['ay_id'],'semester'=>(int)$term['semester'],'term_text'=>$term['term_text']]];
}
