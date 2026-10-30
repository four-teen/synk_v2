<?php
// CLI fixtures only. The caller always rolls back the encompassing transaction.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function professor_students_fixtures(mysqli $conn): array
{
    $term = synk_fetch_current_academic_term($conn);
    $source = synk_professor_workload_assignment_source_sql();
    $rows = $conn->query("SELECT fw.schedule_id,po.offering_id,po.program_id,po.section_id,ps.sub_id,
        p.college_id,c.campus_id,sm.sub_code,sm.sub_description,sec.full_section
        {$source} JOIN tbl_program p ON p.program_id=po.program_id JOIN tbl_college c ON c.college_id=p.college_id
        WHERE fw.ay_id={$term['ay_id']} AND fw.semester={$term['semester']} ORDER BY po.offering_id,fw.schedule_id")->fetch_all(MYSQLI_ASSOC);
    $offerings = [];
    foreach ($rows as $row) if (!isset($offerings[$row['offering_id']])) $offerings[$row['offering_id']] = $row;
    $offerings = array_slice(array_values($offerings), 0, 3);
    if (count($offerings) < 3) throw new RuntimeException('Three current assigned offerings are required for workload fixtures.');
    $tag = 'WorkloadStudentsQA' . bin2hex(random_bytes(7));
    $email = strtolower($tag) . '@example.test';
    $conn->query("INSERT INTO tbl_faculty (first_name,last_name,employment_classification,status,campus_id,college_id)
        VALUES ('Workload','Student QA','permanent','active',{$offerings[0]['campus_id']},{$offerings[0]['college_id']})");
    $facultyId = (int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES ('{$tag}','{$email}','unusable-qa-password','professor','active')");
    $userId = (int)$conn->insert_id;
    synk_persist_useraccount_roles($conn, $userId, ['professor'], 'professor');
    $conn->query("INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES ({$userId},{$facultyId})");
    $mergeTable = synk_schedule_merge_table_name();
    $offeringIds = array_column($offerings, 'offering_id');
    $idList = implode(',', array_map('intval', $offeringIds));
    $conn->query("DELETE FROM {$mergeTable} WHERE owner_offering_id IN ({$idList}) OR member_offering_id IN ({$idList})");
    foreach (array_slice($offerings, 0, 2) as $index => $offering) {
        $conn->query("INSERT INTO tbl_faculty_workload_sched (schedule_id,faculty_id,ay_id,semester) VALUES
            ({$offering['schedule_id']},{$facultyId},{$term['ay_id']},{$term['semester']})");
        // Clear only these fixture rosters inside the transaction, including legacy matches.
        $conn->query("DELETE FROM tbl_student_management_enrolled_subjects WHERE offering_id={$offering['offering_id']}
            OR (offering_id=0 AND section_id={$offering['section_id']} AND subject_id={$offering['sub_id']}
                AND ay_id={$term['ay_id']} AND semester={$term['semester']})");
        $enrolled = $index === 0 ? 260 : 30;
        $conn->query("INSERT INTO tbl_offering_enrollee_counts (offering_id,total_enrollees,source_kind,created_by,updated_by)
            VALUES ({$offering['offering_id']},{$enrolled},'csv_roster',{$userId},{$userId})
            ON DUPLICATE KEY UPDATE total_enrollees=VALUES(total_enrollees)");
    }
    $students = [];
    $number = random_int(700000000000, 799999999990);
    foreach (['active'=>'José', 'inactive'=>'Ana', 'failed'=>'Failed', 'second'=>'Second', 'foreign'=>'Foreign', 'withdrawn'=>'Withdrawn', 'wrong_term'=>'Wrong term'] as $key => $name) {
        $number++;
        $stmt = $conn->prepare("INSERT INTO tbl_student_management (student_number,first_name,middle_name,last_name,suffix_name,email_address,program_id,ay_id,semester,source_sheet_name)
            VALUES (?,?,'Test','Student','Jr.',?, ?,?,?,'QA')");
        $studentEmail = strtolower($key . $email);
        $stmt->bind_param('sssiii', $number, $name, $studentEmail, $offerings[0]['program_id'], $term['ay_id'], $term['semester']);
        $stmt->execute(); $students[$key] = (int)$stmt->insert_id; $stmt->close();
    }
    $enroll = static function (int $studentId, array $offering, int $active = 1, bool $legacy = false, ?int $year = null) use ($conn, $term, $facultyId, $userId, $tag): void {
        $stmt = $conn->prepare('INSERT INTO tbl_student_management_enrolled_subjects
            (import_batch_key,source_file_name,campus_id,college_id,program_id,ay_id,semester,year_level,
            section_id,section_text,offering_id,subject_id,subject_code,descriptive_title,student_id,faculty_id,
            room_id,room_text,schedule_text,source_row_number,uploaded_by,is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $values = [$tag,'qa.csv',(int)$offering['campus_id'],(int)$offering['college_id'],(int)$offering['program_id'],
            $year ?? $term['ay_id'],$term['semester'],1,(int)$offering['section_id'],$offering['full_section'],
            $legacy ? 0 : (int)$offering['offering_id'],(int)$offering['sub_id'],$offering['sub_code'],$offering['sub_description'],
            $studentId,$facultyId,0,'','',1,$userId,$active];
        synk_bind_dynamic_params($stmt,'ssiiiiiiisiissiiissiii',$values);
        $stmt->execute(); $stmt->close();
    };
    $enroll($students['active'], $offerings[0]);
    $enroll($students['inactive'], $offerings[0], 1, true);
    $enroll($students['failed'], $offerings[0]);
    $enroll($students['withdrawn'], $offerings[0], 0);
    $enroll($students['wrong_term'], $offerings[0], 1, false, (int)$term['ay_id'] + 100000);
    $enroll($students['active'], $offerings[1]);
    $enroll($students['second'], $offerings[1]);
    foreach (['active'=>'success', 'failed'=>'failure', 'foreign'=>'success'] as $key => $outcome) {
        $reason = $outcome === 'success' ? 'login_success' : 'google_cancelled';
        $conn->query("INSERT INTO tbl_student_login_activity (student_id,submitted_email,outcome,reason_code,identity_verified,occurred_at)
            VALUES ({$students[$key]},'{$email}','{$outcome}','{$reason}',1,'2025-01-01 00:00:00')");
    }
    return ['term'=>$term,'offerings'=>$offerings,'faculty_id'=>$facultyId,'user_id'=>$userId,'students'=>$students];
}
