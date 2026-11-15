<?php
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/di_profile_analytics_helper.php';
require_once __DIR__ . '/professor_portal_helper.php';

function synk_di_dashboard_query(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to read dashboard data.');
    try {
        if ($types !== '') $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) throw new RuntimeException('Unable to read dashboard data.');
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

function synk_di_dashboard_filter($value): int
{
    if ($value === '' || $value === '0' || $value === 0 || $value === null) return 0;
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (float)$value > 2147483647) throw new DomainException('Choose a valid campus and college.', 422);
    return (int)$value;
}

function synk_di_dashboard_profiles(mysqli $conn, array $facultyScopes): array
{
    if (!$facultyScopes) return [];
    $directory = synk_faculty_directory_records($conn);
    $ranks = synk_table_exists($conn, 'tbl_faculty_ranks') ? synk_faculty_profile_ranks($conn) : [];
    $eligibilities = synk_table_exists($conn, 'tbl_faculty_eligibilities') ? synk_faculty_profile_eligibility_catalog($conn) : [];
    $locations = synk_faculty_profile_locations($conn);
    $eligibilityIds = [];
    if (synk_table_exists($conn, 'tbl_faculty_profile_eligibilities')) {
        foreach (synk_di_dashboard_query($conn, 'SELECT user_id,eligibility_id FROM tbl_faculty_profile_eligibilities') as $row) $eligibilityIds[(int)$row['user_id']][] = (int)$row['eligibility_id'];
    }
    $today = new DateTimeImmutable('today'); $records = [];
    foreach ($facultyScopes as $facultyId => $scope) {
        $faculty = $directory['faculty'][$facultyId];
        $profile = $faculty['profile'];
        $userId = (int)($faculty['user_id'] ?? 0);
        $defaults = synk_faculty_profile_defaults(['username'=>$faculty['name']], ['faculty_name'=>$faculty['name']]);
        $raw = array_replace($defaults, $profile ?? []);
        foreach ($raw as $key => $value) if ($value === null) $raw[$key] = '';
        if (($raw['_storage'] ?? '') !== 'faculty') $raw['eligibility_ids'] = $eligibilityIds[$userId] ?? [];
        [$values,$errors] = synk_faculty_profile_validate($raw, $today, $ranks, $profile ?? [], $eligibilities, $locations);
        $completion = synk_faculty_profile_completion($values, synk_faculty_profile_completion_rules(synk_faculty_profile_fields($ranks,$profile ?? [],$locations)), $errors);
        if (!$profile) {
            $completion['percent'] = $completion['completed'] = 0;
            foreach ($completion['sections'] as &$section) $section['completed'] = 0;
            unset($section);
        }
        $analytics = [];
        foreach (['faculty_rank','employment_classification','gender','civil_status'] as $field) $analytics[$field] = $profile && !isset($errors[$field]) ? (string)($values[$field] ?? '') : '';
        $ongoing = [];
        foreach (['bachelor'=>"Bachelor's",'master'=>"Master's",'doctoral'=>'Doctoral'] as $key=>$label) {
            if ($profile && ($values[$key.'_status'] ?? '') === 'Ongoing' && ($values[$key.'_degree'] ?? '') !== '' && !isset($errors[$key.'_degree']) && !isset($errors[$key.'_status'])) $ongoing[] = $label;
        }
        $credentials = [];
        foreach ($values['eligibility_ids'] ?? [] as $id) if (isset($eligibilities[$id])) $credentials[] = $eligibilities[$id]['code'] . ' - ' . $eligibilities[$id]['name'];
        $highestDegreeName = '';
        foreach (['doctoral', 'master', 'bachelor'] as $level) {
            if ($profile && ($values[$level . '_status'] ?? '') === 'Completed'
                && trim((string)($values[$level . '_degree'] ?? '')) !== ''
                && !isset($errors[$level . '_status']) && !isset($errors[$level . '_degree'])) {
                $highestDegreeName = $values[$level . '_degree'];
                break;
            }
        }
        $records[] = [
            'faculty_id'=>(int)$facultyId,'user_id'=>$userId,'name'=>$faculty['name'],
            'profile_user_id'=>($profile['_storage'] ?? '') === 'faculty' ? 0 : $userId,
            'designation_name'=>(string)($faculty['designation_name'] ?? ''),'highest_degree_name'=>$highestDegreeName,
            'email'=>(string)($faculty['email'] ?? ''),'campus_ids'=>array_keys($scope['campuses']),'college_ids'=>array_keys($scope['colleges']),
            'has_profile'=>$profile !== null,'profile_status'=>!$profile ? 'not_started' : ($completion['percent']===100 ? 'complete' : 'in_progress'),
            'completion'=>$completion,'analytics_values'=>$analytics,
            'highest_degree'=>$profile ? synk_di_highest_completed_degree($values,$errors) : 'Not recorded',
            'ongoing'=>$ongoing,'eligibility_labels'=>array_values(array_unique($credentials)),
            'has_other_eligibility'=>$profile && !isset($errors['eligibility']) && trim((string)($values['eligibility'] ?? '')) !== '',
            'updated_at'=>(string)($profile['updated_at'] ?? ''),
        ];
    }
    usort($records, static function(array $a,array $b):int { return strnatcasecmp($a['name'],$b['name']) ?: $a['faculty_id']<=>$b['faculty_id']; });
    return $records;
}

function synk_di_dashboard_profile_summary(array $faculty): array
{
    $summary = ['total'=>count($faculty),'saved'=>0,'complete'=>0,'in_progress'=>0,'not_started'=>0,'with_eligibility'=>0,'average_completion'=>0,'coverage_percent'=>0,
        'education'=>[],'employment'=>[],'ranks'=>[],'gender'=>[],'civil_status'=>[],'eligibilities'=>[],'ongoing'=>[],
        'sections'=>['personal'=>['completed'=>0,'total'=>0],'employment'=>['completed'=>0,'total'=>0],'education'=>['completed'=>0,'total'=>0]]];
    $completionSum=0;
    foreach ($faculty as $record) {
        $summary[$record['profile_status']]++;
        $summary['saved'] += $record['has_profile'] ? 1 : 0;
        $completionSum += $record['completion']['percent'];
        foreach (['education'=>$record['highest_degree'],'employment'=>$record['analytics_values']['employment_classification'],'ranks'=>$record['analytics_values']['faculty_rank'],'gender'=>$record['analytics_values']['gender'],'civil_status'=>$record['analytics_values']['civil_status']] as $group=>$label) {
            $label = $label ?: 'Not recorded';
            $summary[$group][$label] = ($summary[$group][$label] ?? 0)+1;
        }
        foreach ($record['ongoing'] as $label) $summary['ongoing'][$label] = ($summary['ongoing'][$label] ?? 0)+1;
        $summary['with_eligibility'] += ($record['eligibility_labels'] || $record['has_other_eligibility']) ? 1 : 0;
        foreach ($record['eligibility_labels'] as $label) $summary['eligibilities'][$label] = ($summary['eligibilities'][$label] ?? 0)+1;
        if ($record['has_other_eligibility']) $summary['eligibilities']['Other recorded credentials'] = ($summary['eligibilities']['Other recorded credentials'] ?? 0)+1;
        foreach ($record['completion']['sections'] as $section=>$counts) foreach (['completed','total'] as $key) $summary['sections'][$section][$key] += $counts[$key];
    }
    if ($summary['total']) {
        $summary['coverage_percent'] = (int)round($summary['saved']*100/$summary['total']);
        $summary['average_completion'] = (int)round($completionSum/$summary['total']);
    }
    foreach (['education','employment','ranks','gender','civil_status','eligibilities','ongoing'] as $group) arsort($summary[$group], SORT_NUMERIC);
    return $summary;
}

function synk_di_dashboard(mysqli $conn, array $filters = []): array
{
    // Only official shared settings select the reporting period; URL/session working terms are ignored.
    $term = synk_fetch_current_academic_term($conn);
    $ready = (int)$term['ay_id']>0 && in_array((int)$term['semester'],[1,2,3],true) && $term['ay_label']!=='';
    $campuses=[]; $colleges=[];
    foreach (synk_di_dashboard_query($conn, "SELECT campus_id,campus_name,campus_code FROM tbl_campus WHERE status='active' ORDER BY campus_name,campus_id") as $row) $campuses[(int)$row['campus_id']]=$row;
    foreach (synk_di_dashboard_query($conn, "SELECT c.college_id,c.campus_id,c.college_name,c.college_code FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.status='active' AND cp.status='active' ORDER BY c.college_name,c.college_id") as $row) $colleges[(int)$row['college_id']]=$row;
    $campusId=synk_di_dashboard_filter($filters['campus_id'] ?? 0); $collegeId=synk_di_dashboard_filter($filters['college_id'] ?? 0);
    if (($campusId && !isset($campuses[$campusId])) || ($collegeId && !isset($colleges[$collegeId])) || ($campusId && $collegeId && (int)$colleges[$collegeId]['campus_id']!==$campusId)) throw new DomainException('Choose a college under the selected campus.',422);
    $campusRows=[];
    foreach ($campuses as $id=>$campus) if ((!$campusId || $id===$campusId) && (!$collegeId || (int)$colleges[$collegeId]['campus_id']===$id)) $campusRows[$id]=['campus'=>$campus,'teaching'=>[],'rostered'=>[],'programs'=>[],'subjects'=>[],'colleges'=>[]];
    $scopedColleges=array_filter($colleges,static function(array $row)use($campusId,$collegeId):bool{return (!$campusId || (int)$row['campus_id']===$campusId) && (!$collegeId || (int)$row['college_id']===$collegeId);});
    $academic=['campuses'=>count($campusRows),'colleges'=>count($scopedColleges),'programs'=>0,'subjects'=>0,'prospectuses'=>0,'sections'=>0,'classes'=>0];
    $sets=['programs'=>[],'subjects'=>[],'prospectuses'=>[],'sections'=>[],'classes'=>[]];
    $facultyScopes=[]; $rostered=[];
    if ($ready) {
        $scopeSql=" AND c.status='active' AND cp.status='active' AND (?=0 OR c.campus_id=?) AND (?=0 OR c.college_id=?)";
        $params=[(int)$term['ay_id'],(int)$term['semester'],$campusId,$campusId,$collegeId,$collegeId]; $types='iiiiii';
        $joins=synk_section_curriculum_live_offering_join_sql('o','sec','sc','ps','pys','ph');
        $offerings=synk_di_dashboard_query($conn,"SELECT DISTINCT o.offering_id,o.program_id,ps.sub_id,o.prospectus_id,o.section_id,c.college_id,c.campus_id
            FROM tbl_prospectus_offering o {$joins} JOIN tbl_program p ON p.program_id=o.program_id JOIN tbl_subject_masterlist sm ON sm.sub_id=ps.sub_id
            JOIN tbl_college c ON c.college_id=p.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id
            WHERE o.ay_id=? AND o.semester=? {$scopeSql}",$types,$params);
        foreach ($offerings as $row) {
            foreach (['programs'=>'program_id','subjects'=>'sub_id','prospectuses'=>'prospectus_id','sections'=>'section_id','classes'=>'offering_id'] as $group=>$field) $sets[$group][(int)$row[$field]]=true;
            $id=(int)$row['campus_id'];
            foreach (['programs'=>'program_id','subjects'=>'sub_id','colleges'=>'college_id'] as $group=>$field) $campusRows[$id][$group][(int)$row[$field]]=true;
        }
        $source=synk_professor_workload_assignment_source_sql();
        $assignments=synk_di_dashboard_query($conn,"SELECT DISTINCT fw.faculty_id,c.campus_id,c.college_id {$source}
            JOIN tbl_program p ON p.program_id=po.program_id JOIN tbl_college c ON c.college_id=p.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id
            WHERE fw.ay_id=? AND fw.semester=? {$scopeSql}",$types,$params);
        foreach ($assignments as $row) {
            $id=(int)$row['faculty_id']; $cp=(int)$row['campus_id']; $col=(int)$row['college_id'];
            $facultyScopes[$id]['campuses'][$cp]=true; $facultyScopes[$id]['colleges'][$col]=true;
            $campusRows[$cp]['teaching'][$id]=true;
        }
        $added=synk_di_dashboard_query($conn,"SELECT DISTINCT cf.faculty_id,c.campus_id FROM tbl_college_faculty cf
            JOIN tbl_faculty f ON f.faculty_id=cf.faculty_id AND f.status='active'
            JOIN tbl_college c ON c.college_id=cf.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id
            WHERE cf.ay_id=? AND cf.semester=? AND cf.status='active' {$scopeSql}",$types,$params);
        foreach ($added as $row) { $id=(int)$row['faculty_id']; $rostered[$id]=true; $campusRows[(int)$row['campus_id']]['rostered'][$id]=true; }
    }
    foreach ($sets as $group=>$ids) $academic[$group]=count($ids);
    $faculty=synk_di_dashboard_profiles($conn,$facultyScopes);
    foreach ($faculty as &$record) {
        $record['campus_name']=implode(', ',array_map(static function(int $id)use($campuses):string{return $campuses[$id]['campus_name'];},$record['campus_ids']));
        $record['college_name']=implode(', ',array_map(static function(int $id)use($colleges):string{return $colleges[$id]['college_name'];},$record['college_ids']));
    }
    unset($record);
    foreach ($campusRows as $id=>&$row) {
        $inCampus=array_values(array_filter($faculty,static function(array $record)use($id):bool{return in_array($id,$record['campus_ids'],true);}));
        $row['summary']=synk_di_dashboard_profile_summary($inCampus);
        foreach (['teaching','rostered','programs','subjects','colleges'] as $key) $row[$key]=count($row[$key]);
    }
    unset($row);
    return ['term'=>$term,'term_ready'=>$ready,'campuses'=>$campuses,'colleges'=>$colleges,'campus_id'=>$campusId,'college_id'=>$collegeId,
        'scope_label'=>$collegeId ? $colleges[$collegeId]['college_name'] : ($campusId ? $campuses[$campusId]['campus_name'] : 'All campuses'),
        'academic'=>$academic,'faculty_count'=>count($faculty),'rostered_count'=>count($rostered),'faculty'=>$faculty,'campus_rows'=>$campusRows,
        'summary'=>synk_di_dashboard_profile_summary($faculty)];
}
