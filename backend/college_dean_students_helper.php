<?php
require_once __DIR__ . '/college_dean_dashboard_helper.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/student_identity_helper.php';

function synk_dean_students_ready(mysqli $conn): bool
{
    return synk_table_exists($conn,'tbl_student_management')
        && synk_table_exists($conn,'tbl_student_management_enrolled_subjects');
}

function synk_dean_students_query(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the student directory query.');
    try {
        synk_bind_dynamic_params($stmt,$types,$params);
        if (!$stmt->execute()) throw new RuntimeException('Unable to read the student directory.');
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

function synk_dean_students_filters(array $input): array
{
    $filters = ['program_id'=>0,'section_id'=>0,'ay_id'=>0,'semester'=>0,'term'=>'all','search'=>'','sort'=>'name','page'=>1,'page_size'=>25];
    foreach (['program_id','section_id','page','page_size'] as $field) {
        if (!isset($input[$field]) || (string)$input[$field] === '') continue;
        $value = filter_var($input[$field],FILTER_VALIDATE_INT,['options'=>['min_range'=>in_array($field,['page','page_size'],true) ? 1 : 0]]);
        if ($value === false) throw new DomainException('Choose valid student directory filters.');
        $filters[$field] = $value;
    }
    $filters['page_size'] = min(100,$filters['page_size']);
    $term = trim((string)($input['term'] ?? 'all'));
    if ($term !== '' && $term !== 'all') {
        if (!preg_match('/^([1-9][0-9]{0,9}):([1-3])$/D',$term,$matches)) throw new DomainException('Choose a valid academic term.');
        $filters['ay_id'] = (int)$matches[1]; $filters['semester'] = (int)$matches[2]; $filters['term'] = $term;
    }
    $filters['search'] = trim(synk_student_identity_utf8((string)($input['search'] ?? '')));
    $length = function_exists('mb_strlen') ? mb_strlen($filters['search'],'UTF-8') : strlen($filters['search']);
    if ($length > 150) throw new DomainException('Search using up to 150 characters.');
    $filters['sort'] = (string)($input['sort'] ?? 'name');
    if (!in_array($filters['sort'],['name','id','newest'],true)) throw new DomainException('Choose a valid sort order.');
    return $filters;
}

function synk_dean_students_source(array $scope, array $filters): array
{
    // A shared profile's first program/term is not its current college membership.
    $sql = "FROM tbl_student_management s
        JOIN tbl_student_management_enrolled_subjects es ON es.student_id=s.student_id
        JOIN tbl_program p ON p.program_id=es.program_id
        LEFT JOIN tbl_sections sec ON sec.section_id=es.section_id
        WHERE es.college_id=? AND es.campus_id=? AND p.college_id=? AND es.is_active=1";
    $params = [$scope['college_id'],$scope['campus_id'],$scope['college_id']]; $types = 'iii';
    foreach (['program_id'=>'es.program_id','section_id'=>'es.section_id','ay_id'=>'es.ay_id','semester'=>'es.semester'] as $field=>$column) {
        if (!empty($filters[$field])) { $sql .= ' AND ' . $column . '=?'; $params[]=$filters[$field]; $types.='i'; }
    }
    if (!empty($filters['search'])) {
        // Treat wildcard characters literally, while supporting any order of name words.
        foreach (preg_split('/\s+/u',$filters['search']) as $word) {
            $pattern = '%' . str_replace(['!','%','_'],['!!','!%','!_'],$word) . '%';
            $sql .= " AND (CONVERT(s.student_number USING utf8mb4) LIKE ? ESCAPE '!' OR CONCAT_WS(' ',s.last_name,s.first_name,s.middle_name,s.suffix_name) LIKE ? ESCAPE '!')";
            $params[]=$pattern; $params[]=$pattern; $types.='ss';
        }
    }
    return ['sql'=>$sql,'types'=>$types,'params'=>$params];
}

function synk_dean_students_name(array $row): string
{
    return $row['last_name'] . ', ' . trim(implode(' ',array_filter([$row['first_name'],$row['middle_name'],$row['suffix_name']])));
}

function synk_dean_students_options(mysqli $conn, array $scope): array
{
    $source = synk_dean_students_source($scope,[]);
    $programs = synk_dean_students_query($conn,"SELECT DISTINCT p.program_id,p.program_code,p.program_name,p.major {$source['sql']} ORDER BY p.program_code,p.major,p.program_id",$source['types'],$source['params']);
    foreach ($programs as &$row) {
        $row['program_id']=(int)$row['program_id'];
        $row['label']=trim($row['program_code']) ?: $row['program_name'];
        if (trim((string)$row['major']) !== '') $row['label'].=' - ' . $row['major'];
    }
    unset($row);
    $terms = synk_dean_students_query($conn,"SELECT DISTINCT es.ay_id,es.semester,
        (SELECT ay.ay FROM tbl_academic_years ay WHERE ay.ay_id=es.ay_id) AS ay_label
        {$source['sql']} ORDER BY ay_label DESC,es.semester DESC",$source['types'],$source['params']);
    foreach ($terms as &$row) {
        $row['ay_id']=(int)$row['ay_id']; $row['semester']=(int)$row['semester'];
        $row['key']=$row['ay_id'] . ':' . $row['semester'];
        $row['label']=($row['ay_label'] ?: 'Academic Year ' . $row['ay_id']) . ' - ' . (synk_semester_label($row['semester']) ?: 'Unspecified term');
    }
    unset($row);
    $sections = synk_dean_students_query($conn,"SELECT DISTINCT es.section_id,es.program_id,es.ay_id,es.semester,
        COALESCE(NULLIF(sec.full_section,''),NULLIF(es.section_text,''),CONCAT(p.program_code,' ',es.year_level)) AS section_label
        {$source['sql']} AND es.section_id>0 ORDER BY section_label,es.ay_id DESC,es.semester",$source['types'],$source['params']);
    foreach ($sections as &$row) {
        foreach (['section_id','program_id','ay_id','semester'] as $field) $row[$field]=(int)$row[$field];
        $row['term']=$row['ay_id'] . ':' . $row['semester'];
    }
    return ['programs'=>$programs,'terms'=>$terms,'sections'=>$sections];
}

function synk_dean_students_list(mysqli $conn, array $scope, array $filters): array
{
    $source=synk_dean_students_source($scope,$filters);
    $classKey="CONCAT_WS(':',es.program_id,es.ay_id,es.semester,es.section_id,es.subject_id)";
    $summary=synk_dean_students_query($conn,"SELECT COUNT(DISTINCT s.student_id) AS students,
        COUNT(DISTINCT {$classKey}) AS classes,COUNT(DISTINCT es.program_id) AS programs
        {$source['sql']}",$source['types'],$source['params'])[0];
    foreach ($summary as &$value) $value=(int)$value;
    unset($value);
    $pages=max(1,(int)ceil($summary['students']/$filters['page_size']));
    $page=min($pages,$filters['page']); $offset=($page-1)*$filters['page_size'];
    $sort=['name'=>'s.last_name,s.first_name,s.middle_name,s.student_id',
        'id'=>'s.student_number,s.student_id','newest'=>'last_imported DESC,s.last_name,s.first_name,s.student_id'][$filters['sort']];
    $rows=synk_dean_students_query($conn,"SELECT s.student_id,s.student_number,s.last_name,s.first_name,s.middle_name,s.suffix_name,s.email_address,
        GROUP_CONCAT(DISTINCT CONCAT_WS(' - ',COALESCE(NULLIF(p.program_code,''),p.program_name),NULLIF(p.major,'')) ORDER BY p.program_code SEPARATOR ', ') AS programs,
        GROUP_CONCAT(DISTINCT COALESCE(NULLIF(sec.full_section,''),NULLIF(es.section_text,''),CONCAT(p.program_code,' ',es.year_level)) ORDER BY sec.full_section SEPARATOR ', ') AS sections,
        COUNT(DISTINCT es.subject_id) AS subject_count,MAX(es.created_at) AS last_imported
        {$source['sql']}
        GROUP BY s.student_id,s.student_number,s.last_name,s.first_name,s.middle_name,s.suffix_name,s.email_address
        ORDER BY {$sort} LIMIT ? OFFSET ?",$source['types'].'ii',array_merge($source['params'],[$filters['page_size'],$offset]));
    foreach ($rows as &$row) {
        $row['student_id']=(int)$row['student_id']; $row['student_number']=(string)$row['student_number'];
        $row['subject_count']=(int)$row['subject_count']; $row['student_name']=synk_dean_students_name($row);
    }
    return ['rows'=>$rows,'summary'=>$summary,'pagination'=>['page'=>$page,'page_size'=>$filters['page_size'],
        'pages'=>$pages,'total'=>$summary['students'],'from'=>$rows ? $offset+1 : 0,'to'=>$offset+count($rows)]];
}

function synk_dean_students_detail(mysqli $conn, array $scope, int $studentId, array $filters): ?array
{
    // Check imported membership even when a guessed ID points to an existing master record.
    $source=synk_dean_students_source($scope,[]);
    $profiles=synk_dean_students_query($conn,"SELECT DISTINCT s.student_id,s.student_number,s.last_name,s.first_name,s.middle_name,s.suffix_name,s.email_address
        {$source['sql']} AND s.student_id=?",$source['types'].'i',array_merge($source['params'],[$studentId]));
    if (!$profiles) return null;
    $profile=$profiles[0]; $profile['student_id']=(int)$profile['student_id'];
    $profile['student_number']=(string)$profile['student_number']; $profile['student_name']=synk_dean_students_name($profile);
    // Detail shows every class in this college for the selected term, independent of program/section/search filters.
    $source=synk_dean_students_source($scope,['ay_id'=>$filters['ay_id'],'semester'=>$filters['semester']]);
    $rows=synk_dean_students_query($conn,"SELECT es.offering_id,es.subject_id,es.subject_code,es.descriptive_title,
        es.program_id,p.program_code,p.program_name,p.major,es.section_id,
        COALESCE(NULLIF(sec.full_section,''),NULLIF(es.section_text,''),CONCAT(p.program_code,' ',es.year_level)) AS section_label,
        es.ay_id,es.semester,es.year_level,
        (SELECT ay.ay FROM tbl_academic_years ay WHERE ay.ay_id=es.ay_id) AS ay_label,
        (SELECT TRIM(CONCAT_WS(' ',f.first_name,f.middle_name,f.last_name,f.ext_name)) FROM tbl_faculty f WHERE f.faculty_id=es.faculty_id) AS faculty_name,
        es.source_file_name,es.created_at AS imported_at
        {$source['sql']} AND s.student_id=? ORDER BY ay_label DESC,es.semester DESC,es.subject_code,section_label,es.student_enrollment_id",
        $source['types'].'i',array_merge($source['params'],[$studentId]));
    $classes=[];
    foreach ($rows as $row) {
        $key=implode(':',[$row['program_id'],$row['ay_id'],$row['semester'],$row['section_id'],$row['subject_id']]);
        if (isset($classes[$key])) continue;
        foreach (['offering_id','subject_id','program_id','section_id','ay_id','semester','year_level'] as $field) $row[$field]=(int)$row[$field];
        $row['term_label']=($row['ay_label'] ?: 'Academic Year ' . $row['ay_id']) . ' - ' . (synk_semester_label($row['semester']) ?: 'Unspecified term');
        $row['program_label']=trim($row['program_code']) ?: $row['program_name'];
        if (trim((string)$row['major']) !== '') $row['program_label'].=' - ' . $row['major'];
        $classes[$key]=$row;
    }
    return ['student'=>$profile,'subjects'=>array_values($classes)];
}
