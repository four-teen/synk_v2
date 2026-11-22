<?php
require_once __DIR__.'/hr_faculty_pds_helper.php';

function synk_hr_pds_handle_save(mysqli $conn, array $session, $payload, array $uploads=[]): array
{
    $fail = static function (int $status,string $message,array $errors=[]): array {
        return ['status'=>$status,'body'=>['ok'=>false,'message'=>$message,'errors'=>$errors]];
    };
    $userId=(int)($session['user_id'] ?? 0);
    if ($userId<=0 || ($session['role'] ?? '')!=='professor') return $fail(401,'Sign in to your professor account before saving.');
    if (!is_array($payload)) return $fail(400,'Reload the page before saving this section.');
    $token=$payload['csrf_token'] ?? null;
    if (!is_string($token) || !is_string($session['faculty_profile_csrf'] ?? null) || !hash_equals($session['faculty_profile_csrf'],$token)) {
        return $fail(403,'Your form session expired. Reload the page before saving.');
    }
    $section=$payload['section'] ?? null; $owner=$payload['owner'] ?? null; $revision=$payload['revision'] ?? null;
    if (array_diff(array_keys($payload),['csrf_token','section','owner','revision','values','removed_attachments']) || !is_string($section) || !in_array($section,synk_hr_pds_sections(),true)
        || !is_string($owner) || !preg_match('/^(faculty|account):[1-9][0-9]{0,9}$/D',$owner)
        || (!is_int($revision) && !is_string($revision)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D',(string)$revision) || (float)$revision>2147483646) {
        return $fail(422,'The section or version is invalid. Reload before saving.');
    }
    if (synk_hr_pds_section_managed_by_hr($section)) return $fail(403,'Human Resources manages work history.');
    [$values,$errors]=synk_hr_pds_validate($section,$payload['values'] ?? null,new DateTimeImmutable('today'));
    $files=[]; $removed=[];
    if ($section!=='certification' && ($uploads || array_key_exists('removed_attachments',$payload))) return $fail(422,'Attachments belong to Photo & final review.');
    if ($section==='certification') {
        try { [$files,$removed,$fileErrors]=synk_hr_pds_prepare_attachments($uploads,$payload['removed_attachments'] ?? []); }
        catch (Throwable $error) { error_log('HR PDS upload processing failed ('.get_class($error).').'); return $fail(500,'The files could not be prepared. Your entries have not been saved. Try again.'); }
        $errors+=$fileErrors;
    }
    if ($errors) return $fail(422,'Please check the highlighted fields. Your entries have not been saved.',$errors);
    $transaction=false;
    try {
        synk_hr_pds_ensure($conn);
        $conn->begin_transaction(); $transaction=true;
        $saved=synk_hr_pds_save($conn,$userId,$owner,$section,$values,(int)$revision,$files,$removed);
        $conn->commit(); $transaction=false;
        return ['status'=>200,'body'=>['ok'=>true,'message'=>'Section saved. You can return to it anytime.','section'=>$section] + $saved];
    } catch (DomainException $error) {
        if ($transaction) $conn->rollback();
        return $fail(409,$error->getMessage());
    } catch (Throwable $error) {
        if ($transaction) $conn->rollback();
        error_log('HR PDS section save failed ('.get_class($error).', code '.$error->getCode().').');
        return $fail(500,'We could not save this section. Your entries are still here. Try again.');
    }
}
