<?php
require_once __DIR__.'/db_lms.php';
require_once __DIR__.'/professor_class_helper_lms.php';
require_once __DIR__.'/student_assessment_helper_lms.php';
require_once __DIR__.'/announcement_helper_lms.php';

function synk_student_announcement_read_lms(mysqli $core, mysqli $lms, int $studentId, array $keys): array
{
    $visible = array_fill_keys(array_column(synk_student_announcement_feed_lms($core,$lms,$studentId),'event_key'),true);
    foreach ($keys as $key) if (!isset($visible[$key])) throw new DomainException('An announcement is no longer available. Refresh the dashboard.',404);
    $now = synk_assessment_now_lms();
    foreach (array_unique($keys) as $key) synk_assessment_query_lms($lms,'INSERT IGNORE INTO tbl_lms_announcement_reads (student_id,event_key,read_at) VALUES (?,?,?)','iss',[$studentId,$key,$now]);
    return ['read_keys'=>array_values(array_unique($keys))];
}

function synk_announcement_request_lms(mysqli $core, array $input, bool $student): array
{
    $csrf = $student ? 'student_announcement_csrf' : 'lms_announcement_csrf';
    if (empty($_SESSION[$csrf]) || !is_string($input['csrf_token'] ?? null) || !hash_equals($_SESSION[$csrf],$input['csrf_token'])) throw new DomainException('Reload announcements before continuing.',403);
    if (array_diff(array_keys($input),['csrf_token','action','context','payload'])) throw new DomainException('Unsupported announcement fields.',400);
    $action = $input['action'] ?? null; $payload = $input['payload'] ?? [];
    if (!is_string($action) || !is_array($payload)) throw new DomainException('Enter a valid announcement request.',400);
    if ($student) {
        $studentId = synk_student_assessment_identity_lms($core);
        if ($action !== 'read' || isset($input['context']) || array_diff(array_keys($payload),['event_keys'])) throw new DomainException('Choose a valid announcement action.',400);
        $keys = $payload['event_keys'] ?? null;
        if (!is_array($keys) || count($keys)>100 || !$keys) throw new DomainException('Choose valid announcements.',422);
        foreach ($keys as $key) if (!is_string($key) || !preg_match('/^(announcement|activity):[1-9][0-9]*:[1-9][0-9]*$/D',$key) || strlen($key)>80) throw new DomainException('Choose valid announcements.',422);
    } else {
        if (!in_array($action,['list','save','archive'],true) || !is_array($input['context'] ?? null) || ($input['context']['view'] ?? '') !== 'announcements') throw new DomainException('Open Announcements from your class workspace.',403);
        $allowed = $action === 'list' ? [] : ($action === 'archive' ? ['announcement_id','version'] : ['announcement_id','version','title','body']);
        if (array_diff(array_keys($payload),$allowed)) throw new DomainException('Unsupported announcement fields.',400);
        $context = synk_professor_class_context_lms($core,$input['context']);
    }
    $lms = synk_database_connection_lms($core);
    if (!synk_announcement_ready_lms($lms)) throw new DomainException('Announcements are temporarily unavailable. Please contact your administrator.',503);
    if (!$lms->begin_transaction()) throw new RuntimeException('Unable to begin announcement update.');
    try {
        if ($student) {
            $data = synk_student_announcement_read_lms($core,$lms,$studentId,$keys);
        } else {
            $data = $action === 'list' ? ['announcements'=>synk_announcement_list_lms($lms,$context)] : synk_announcement_write_lms($lms,$context,$action,$payload,(int)$_SESSION['user_id']);
        }
        if (!$lms->commit()) throw new RuntimeException('Unable to save announcements.');
        return $data;
    } catch (Throwable $error) { $lms->rollback(); throw $error; }
}
