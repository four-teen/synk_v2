<?php
require_once __DIR__ . '/college_dean_students_helper.php';
require_once __DIR__ . '/student_email_helper.php';

function synk_dean_student_email_rows(mysqli $conn, array $scope, array $filters, bool $lock=false): array
{
    $source=synk_dean_students_source($scope,$filters,$conn);
    return synk_dean_students_query($conn,"SELECT DISTINCT s.student_id,s.student_number,s.first_name,s.last_name,s.middle_name,s.suffix_name,s.email_address {$source['sql']} ORDER BY s.last_name,s.first_name,s.student_id" . ($lock ? ' FOR UPDATE' : ''),
        $source['types'],$source['params']);
}

function synk_dean_student_email_preview(mysqli $conn, array $scope, array $filters): array
{
    $students=synk_dean_student_email_rows($conn,$scope,$filters);
    if (count($students)>5000) throw new DomainException('Select a program or section to preview up to 5,000 students at a time.');
    $catalog=synk_student_email_catalog($conn);
    $rows=[]; $approved=[]; $summary=['ready'=>0,'preserved'=>0,'review'=>0,'students'=>count($students)];
    foreach ($students as $student) {
        $candidate=synk_student_email_check($student,$catalog['owners']);
        $id=(int)$student['student_id'];
        $rows[]=['student_id'=>$id,'student_number'=>(string)$student['student_number'],
            'student_name'=>synk_dean_students_name($student)] + $candidate;
        $summary[$candidate['status']]++;
        if ($candidate['status']==='ready') $approved[$id]=['student'=>$student,'email'=>$candidate['email']];
    }
    $token=bin2hex(random_bytes(32));
    if (!isset($_SESSION['dean_student_email_previews'])) $_SESSION['dean_student_email_previews']=[];
    while (count($_SESSION['dean_student_email_previews'])>=3) array_shift($_SESSION['dean_student_email_previews']);
    $_SESSION['dean_student_email_previews'][$token]=['user_id'=>(int)$_SESSION['user_id'],
        'college_id'=>$scope['college_id'],'campus_id'=>$scope['campus_id'],'filters'=>$filters,
        'expires_at'=>time()+1800,'approved'=>$approved];
    return ['preview_token'=>$token,'rows'=>$rows,'summary'=>$summary];
}

function synk_dean_student_email_save(mysqli $conn, array $scope, string $token, array $selected): array
{
    $pending=$_SESSION['dean_student_email_previews'][$token] ?? null;
    if (!$pending || $pending['expires_at'] < time() || $pending['user_id'] !== (int)$_SESSION['user_id']
        || $pending['college_id'] !== $scope['college_id'] || $pending['campus_id'] !== $scope['campus_id']) {
        throw new DomainException('This email preview expired or your access changed. Preview again.');
    }
    if (!$selected || count($selected)>5000) throw new DomainException('Select at least one proposed email to save.');
    $ids=[];
    foreach ($selected as $value) {
        if (!is_int($value) || $value<=0 || !isset($pending['approved'][$value]) || isset($ids[$value])) throw new DomainException('Only eligible students from this preview can be selected.');
        $ids[$value]=true;
    }
    synk_student_email_lock($conn);
    $transaction=false;
    try {
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin student email update.');
        $transaction=true;
        // Lock the identity directory too: administrators may update emails without using the named lock.
        $catalog=synk_student_email_catalog($conn,true);
        $freshScope=synk_college_dean_dashboard_scope($conn);
        if (!$freshScope || $freshScope['college_id']!==$scope['college_id'] || $freshScope['campus_id']!==$scope['campus_id']) {
            throw new DomainException('Your assigned college changed. Reload the page.');
        }
        $members=array_column(synk_dean_student_email_rows($conn,$freshScope,$pending['filters'],true),null,'student_id');
        foreach ($ids as $id=>$unused) {
            $before=$pending['approved'][$id]; $student=$catalog['students'][$id] ?? null;
            $unchanged=(bool)$student;
            foreach (['student_number','first_name','last_name','middle_name','suffix_name','email_address'] as $field) {
                if (!$student || $student[$field] !== $before['student'][$field]) $unchanged=false;
            }
            if (!$unchanged || !isset($members[$id])) {
                throw new DomainException('A selected student name, email, or college membership changed. Nothing was saved. Preview again.');
            }
            $candidate=synk_student_email_check($student,$catalog['owners']);
            if ($candidate['status']!=='ready' || $candidate['email']!==$before['email']) {
                throw new DomainException('A proposed email now conflicts with another student. Nothing was saved. Preview again.');
            }
        }
        foreach ($ids as $id=>$unused) synk_student_email_fill($conn,$id,$pending['approved'][$id]['email']);
        if (!$conn->commit()) throw new RuntimeException('Unable to save student emails.');
        $transaction=false;
        unset($_SESSION['dean_student_email_previews'][$token]);
        return ['saved'=>count($ids),'message'=>count($ids) . ' missing student emails saved. Existing emails were kept.'];
    } catch (Throwable $e) {
        if ($transaction) $conn->rollback();
        unset($_SESSION['dean_student_email_previews'][$token]);
        throw $e;
    } finally { synk_student_email_unlock($conn); }
}
