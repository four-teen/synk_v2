<?php
require_once __DIR__.'/db_lms.php';require_once __DIR__.'/professor_class_helper_lms.php';require_once __DIR__.'/class_student_status_helper_lms.php';
require_once __DIR__.'/professor_student_email_helper.php';

function synk_class_students_request_lms(mysqli $core,array $input): array
{
    if (($_SESSION['role']??'')!=='professor' || empty($_SESSION['user_id'])) throw new DomainException('Sign in as a professor to continue.',401);
    if (empty($_SESSION['lms_class_students_csrf']) || !is_string($input['csrf_token']??null) || !hash_equals($_SESSION['lms_class_students_csrf'],$input['csrf_token'])) throw new DomainException('Reload the class roster before continuing.',403);
    if (array_diff(array_keys($input),['action','payload','context','csrf_token']) || !is_array($input['context']??null) || ($input['context']['view']??'')!=='students' || array_diff(array_keys($input['context']),['offering_id','ay_id','semester','view','signature'])) throw new DomainException('Open Students from your assigned class.',400);
    $action=$input['action']??null;$payload=$input['payload']??[];
    if (!is_string($action) || !in_array($action,['list','set_status','get_email','set_email'],true) || !is_array($payload) || ($action==='list' && $payload)) throw new DomainException('Choose a valid class roster action.',400);
    $context=synk_professor_class_context_lms($core,$input['context']);
    if ($action==='get_email' || $action==='set_email') {
        $fields=$action==='set_email' ? ['student_id','email_address','version'] : ['student_id'];
        if (array_diff(array_keys($payload),$fields)) throw new DomainException('Only the student’s school email can be edited here.',400);
        $id=synk_assessment_id_lms($payload['student_id']??null);
        if ($action==='get_email') {
            return ['student_email'=>synk_professor_student_email_view(synk_professor_student_email_record($core,$context,$id))];
        }

        synk_student_email_lock($core);
        try {
            // Keep the checked class assignment and roster stable through the core write.
            if (!$core->query('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE') || !$core->begin_transaction()) {
                throw new RuntimeException('Unable to begin the student email update.');
            }
            try {
                $context=synk_professor_class_context_lms($core,$input['context']);
                $email=synk_professor_student_email_save($core,$context,$id,$payload);
                if (!$core->commit()) throw new RuntimeException('Unable to save the student email.');
                return ['student_email'=>$email];
            } catch (Throwable $error) {
                $core->rollback();
                throw $error;
            }
        } finally { synk_student_email_unlock($core); }
    }
    $lms=synk_database_connection_lms($core);
    if ($action==='list') return ['roster'=>synk_class_students_roster_lms($core,$lms,$context)];
    if (!$lms->begin_transaction()) throw new RuntimeException('Unable to begin the class status update.');
    try {$roster=synk_class_student_status_change_lms($core,$lms,$context,$payload,(int)$_SESSION['user_id']);if (!$lms->commit()) throw new RuntimeException('Unable to save the class status.');return ['roster'=>$roster];}
    catch (Throwable $error) {$lms->rollback();throw $error;}
}
