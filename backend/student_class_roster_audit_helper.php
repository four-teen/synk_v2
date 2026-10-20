<?php
require_once __DIR__.'/schema_helper.php';

function synk_roster_audit_ready(mysqli $conn): bool
{
    return synk_table_exists($conn,'tbl_student_class_roster_audit');
}

function synk_roster_manual_reasons(): array
{
    return ['late_enrollee'=>'Late enrollee','transferee'=>'Transferee','list_correction'=>'Class-list correction'];
}

// An older CSV must not silently reverse a reviewed manual section transfer.
function synk_roster_transferred_students(mysqli $conn,array $class): array
{
    if (!synk_roster_audit_ready($conn)) return [];
    $stmt=$conn->prepare("SELECT DISTINCT s.student_number FROM tbl_student_class_roster_audit a
        JOIN tbl_student_management_enrolled_subjects es ON es.student_enrollment_id=a.student_enrollment_id AND es.is_active=1
        JOIN tbl_student_management s ON s.student_id=es.student_id
        WHERE a.college_id=? AND a.campus_id=? AND a.ay_id=? AND a.semester=? AND a.subject_id=? AND a.action='transfer'
        AND NOT (a.program_id=? AND a.section_id=?) AND es.student_id=a.student_id
        AND es.offering_id=a.offering_id AND es.college_id=a.college_id AND es.campus_id=a.campus_id
        AND es.ay_id=a.ay_id AND es.semester=a.semester AND es.subject_id=a.subject_id");
    $params=[$class['college_id'],$class['campus_id'],$class['ay_id'],$class['semester'],$class['subject_id'],$class['program_id'],$class['section_id']];
    synk_bind_dynamic_params($stmt,'iiiiiii',$params);
    try { if (!$stmt->execute()) throw new RuntimeException('Unable to verify section transfers.'); return array_fill_keys(array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'student_number'),true); }
    finally { $stmt->close(); }
}
