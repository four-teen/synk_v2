<?php
require_once __DIR__.'/academic_term_helper.php';
require_once __DIR__.'/professor_workload_students_helper.php';
require_once __DIR__.'/student_email_helper.php';

function synk_professor_student_email_record(mysqli $conn,array $context,int $studentId,bool $lock=false): array
{
    $class=$context['class'];
    if (!synk_professor_student_email_current_class($conn,$class)) {
        throw new DomainException('Student emails can only be edited from a class in the current academic term.',403);
    }
    $offeringId=(int)$class['offering_id'];
    $rosters=synk_professor_workload_student_memberships($conn,[$class],(int)$class['ay_id'],(int)$class['semester']);
    if (!isset($rosters[$offeringId][$studentId])) {
        throw new DomainException('This student is not enrolled in your selected class.',404);
    }
    $stmt=$conn->prepare('SELECT student_id,student_number,email_address FROM tbl_student_management WHERE student_id=?'.($lock?' FOR UPDATE':''));
    if (!$stmt) throw new RuntimeException('Unable to check the student directory.');
    try {
        $stmt->bind_param('i',$studentId);
        if (!$stmt->execute()) throw new RuntimeException('Unable to check the student directory.');
        $student=$stmt->get_result()->fetch_assoc();
    } finally { $stmt->close(); }
    if (!$student) throw new DomainException('This student is no longer in the directory.',404);
    return $student;
}

function synk_professor_student_email_current_class(mysqli $conn,array $class): bool
{
    $term=synk_fetch_current_academic_term($conn);
    return (int)$term['ay_id']>0 && in_array((int)$term['semester'],[1,2,3],true)
        && (int)$class['ay_id']===(int)$term['ay_id'] && (int)$class['semester']===(int)$term['semester'];
}

function synk_professor_student_email_view(array $student): array
{
    $email=(string)$student['email_address'];
    return ['email_address'=>$email,'version'=>hash('sha256',$student['student_id']."\n".$email)];
}

// Called with the shared email lock and core transaction held by the roster API.
function synk_professor_student_email_save(mysqli $conn,array $context,int $studentId,array $input): array
{
    $email=is_string($input['email_address']??null) ? strtolower(trim($input['email_address'])) : '';
    if (strlen($email)>255 || !filter_var($email,FILTER_VALIDATE_EMAIL) || substr($email,-12)!=='@sksu.edu.ph') {
        throw new DomainException('Enter the student’s valid school email ending in @sksu.edu.ph.',422);
    }
    $student=synk_professor_student_email_record($conn,$context,$studentId,true);
    $before=synk_professor_student_email_view($student);
    if (!is_string($input['version']??null) || !hash_equals($before['version'],$input['version'])) {
        throw new DomainException('This student’s email changed. Reopen the email editor before saving.',409);
    }
    if ($email===$before['email_address']) return $before;

    $stmt=$conn->prepare('SELECT student_id FROM tbl_student_management
        WHERE LOWER(TRIM(email_address))=? AND student_id<>? LIMIT 1 FOR UPDATE');
    if (!$stmt) throw new RuntimeException('Unable to check for duplicate student emails.');
    try {
        $stmt->bind_param('si',$email,$studentId);
        if (!$stmt->execute()) throw new RuntimeException('Unable to check for duplicate student emails.');
        $duplicate=$stmt->get_result()->fetch_assoc();
    } finally { $stmt->close(); }
    if ($duplicate) throw new DomainException('That school email already belongs to another student.',409);

    synk_student_email_sync_portal($conn,$student,$email);
    $stmt=$conn->prepare('UPDATE tbl_student_management SET email_address=? WHERE student_id=?');
    if (!$stmt) throw new RuntimeException('Unable to save the student email.');
    try {
        $stmt->bind_param('si',$email,$studentId);
        if (!$stmt->execute() || $stmt->affected_rows!==1) throw new RuntimeException('Unable to save the student email.');
    } finally { $stmt->close(); }
    $student['email_address']=$email;
    return synk_professor_student_email_view($student);
}
