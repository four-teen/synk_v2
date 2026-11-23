<?php
require_once __DIR__.'/professor_workload_students_helper.php';
require_once __DIR__.'/professor_class_link_helper_lms.php';

function synk_professor_student_roster(mysqli $conn,int $facultyId,array $term): array
{
    $classes=synk_professor_fetch_subject_rows_by_academic_year($conn,$facultyId,$term['ay_id'],$term['semester']);
    $rosters=synk_professor_workload_student_memberships($conn,$classes,$term['ay_id'],$term['semester']);
    $assignments=[];
    $contexts=[];
    foreach ($classes as $class) {
        $offeringId=(int)$class['offering_id'];
        if (empty($rosters[$offeringId])) continue;
        $label=implode(' · ',array_filter([$class['subject_code'],$class['section_display']]));
        foreach ($rosters[$offeringId] as $studentId=>$present) {
            $assignments[$studentId][$offeringId]=$label;
        }
        $url=synk_professor_class_url_lms($class,'students');
        parse_str((string)parse_url($url,PHP_URL_QUERY),$contexts[$offeringId]);
    }
    $students=[];
    foreach (array_chunk(array_keys($assignments),500) as $ids) {
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$conn->prepare("SELECT student_id,student_number,first_name,middle_name,last_name,suffix_name,email_address
            FROM tbl_student_management WHERE student_id IN ({$marks})");
        if (!$stmt) throw new RuntimeException('Unable to load the student roster.');
        try {
            synk_bind_dynamic_params($stmt,str_repeat('i',count($ids)),$ids);
            if (!$stmt->execute()) throw new RuntimeException('Unable to load the student roster.');
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $id=(int)$row['student_id'];
                $nameParts=array_map('trim',[(string)$row['last_name'],(string)$row['first_name'],(string)$row['middle_name'],(string)$row['suffix_name']]);
                $name=implode(', ',array_filter([$nameParts[0],implode(' ',array_filter(array_slice($nameParts,1),'strlen'))],'strlen'));
                $students[]=['student_id'=>$id,'student_number'=>$row['student_number'],'name'=>$name ?: 'Student '.$id,
                    'email'=>(string)$row['email_address'],'classes'=>$assignments[$id]];
            }
        } finally { $stmt->close(); }
    }
    usort($students,static function(array $left,array $right): int {
        return strnatcasecmp($left['name'],$right['name']) ?: strcmp($left['student_number'],$right['student_number']);
    });
    return ['students'=>$students,'contexts'=>$contexts];
}
