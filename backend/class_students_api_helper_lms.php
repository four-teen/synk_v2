<?php
require_once __DIR__.'/db_lms.php';require_once __DIR__.'/professor_class_helper_lms.php';require_once __DIR__.'/class_student_status_helper_lms.php';

function synk_class_students_request_lms(mysqli $core,array $input): array
{
    if (($_SESSION['role']??'')!=='professor' || empty($_SESSION['user_id'])) throw new DomainException('Sign in as a professor to continue.',401);
    if (empty($_SESSION['lms_class_students_csrf']) || !is_string($input['csrf_token']??null) || !hash_equals($_SESSION['lms_class_students_csrf'],$input['csrf_token'])) throw new DomainException('Reload the class roster before continuing.',403);
    if (array_diff(array_keys($input),['action','payload','context','csrf_token']) || !is_array($input['context']??null) || ($input['context']['view']??'')!=='students' || array_diff(array_keys($input['context']),['offering_id','ay_id','semester','view','signature'])) throw new DomainException('Open Students from your assigned class.',400);
    $action=$input['action']??null;$payload=$input['payload']??[];
    if (!is_string($action) || !in_array($action,['list','set_status'],true) || !is_array($payload) || ($action==='list' && $payload)) throw new DomainException('Choose a valid class roster action.',400);
    $context=synk_professor_class_context_lms($core,$input['context']);$lms=synk_database_connection_lms($core);
    if ($action==='list') return ['roster'=>synk_class_students_roster_lms($core,$lms,$context)];
    if (!$lms->begin_transaction()) throw new RuntimeException('Unable to begin the class status update.');
    try {$roster=synk_class_student_status_change_lms($core,$lms,$context,$payload,(int)$_SESSION['user_id']);if (!$lms->commit()) throw new RuntimeException('Unable to save the class status.');return ['roster'=>$roster];}
    catch (Throwable $error) {$lms->rollback();throw $error;}
}
