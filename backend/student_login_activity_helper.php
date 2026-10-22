<?php
require_once __DIR__.'/schema_helper.php';
require_once __DIR__.'/academic_term_helper.php';

function synk_student_login_activity_term_ready(mysqli $conn): bool
{
    return synk_table_has_column($conn, 'tbl_student_login_activity', 'ay_id')
        && synk_table_has_column($conn, 'tbl_student_login_activity', 'semester');
}

function synk_student_login_activity_ready(mysqli $conn): bool
{
    $result=$conn->query("SHOW TABLES LIKE 'tbl_student_login_activity'");
    $ready=$result instanceof mysqli_result && $result->num_rows>0;
    if ($result instanceof mysqli_result) $result->free();
    return $ready;
}

function synk_student_login_activity_text(string $value, int $limit): string
{
    // Browser/request metadata is untrusted and must never break authentication.
    if (preg_match('//u',$value)!==1) {
        $value=function_exists('iconv')?(string)@iconv('UTF-8','UTF-8//IGNORE',$value):(string)preg_replace('/[^\x20-\x7e]/','?',$value);
    }
    $value=trim((string)preg_replace('/[\x00-\x1f\x7f]/u',' ',$value));
    if (function_exists('mb_substr')) return mb_substr($value,0,$limit,'UTF-8');
    preg_match('/^.{0,'.max(0,$limit).'}/us',$value,$match);
    return $match[0] ?? '';
}

function synk_student_login_activity_device(string $agent): array
{
    $browser='Unknown'; $os='Unknown'; $device='Desktop';
    foreach (['Edg/'=>'Edge','EdgA/'=>'Edge','EdgiOS/'=>'Edge','OPR/'=>'Opera','Opera/'=>'Opera',
        'Firefox/'=>'Firefox','FxiOS/'=>'Firefox','Chrome/'=>'Chrome','CriOS/'=>'Chrome','Safari/'=>'Safari'] as $needle=>$label) {
        if (stripos($agent,$needle)!==false) { $browser=$label; break; }
    }
    if (preg_match('/iPhone|iPad|iPod/i',$agent)) $os='iOS';
    elseif (stripos($agent,'Android')!==false) $os='Android';
    elseif (stripos($agent,'Windows')!==false) $os='Windows';
    elseif (stripos($agent,'Macintosh')!==false || stripos($agent,'Mac OS X')!==false) $os='macOS';
    elseif (stripos($agent,'CrOS')!==false) $os='ChromeOS';
    elseif (stripos($agent,'Linux')!==false) $os='Linux';
    if (preg_match('/iPad|Tablet/i',$agent) || ($os==='Android' && stripos($agent,'Mobile')===false)) $device='Tablet';
    elseif (preg_match('/Mobile|iPhone|iPod/i',$agent)) $device='Mobile';
    elseif ($agent==='') $device='Unknown';
    return ['browser'=>$browser,'operating_system'=>$os,'device_type'=>$device];
}

function synk_student_login_activity_reason(string $code): string
{
    $labels=[
        'login_success'=>'Google sign-in completed',
        'student_login_request_invalid'=>'Invalid or expired sign-in request',
        'student_login_rate_limited'=>'Too many sign-in requests',
        'student_email_invalid'=>'Invalid email format',
        'email_domain_denied'=>'Email is outside the permitted school domain',
        'student_email_mismatch'=>'Entered email differs from the verified Google account',
        'student_directory_access_denied'=>'Email was not found in the Student Directory',
        'student_email_ambiguous'=>'Email matches multiple student records',
        'google_cancelled'=>'Google sign-in was cancelled',
        'google_unavailable'=>'Google sign-in was unavailable',
        'google_state_invalid'=>'Google sign-in request expired or could not be verified',
        'google_code_missing'=>'Google did not return a sign-in code',
        'google_token_failed'=>'Google sign-in exchange failed',
        'google_token_missing'=>'Google sign-in response was incomplete',
        'google_id_token_missing'=>'Google identity response was incomplete',
        'google_id_token_invalid'=>'Google identity token could not be verified',
        'google_nonce_invalid'=>'Google identity challenge expired or did not match',
        'google_profile_failed'=>'Google account details could not be retrieved',
        'google_profile_invalid'=>'Google account identity could not be verified',
        'google_email_mismatch'=>'Google identity responses did not match',
        'student_portal_unavailable'=>'Student sign-in could not be completed',
    ];
    return $labels[$code] ?? 'Google sign-in could not be verified or completed';
}

// Only the authentication start/callback flow calls this writer. Reads and previews do not.
function synk_student_login_activity_record(mysqli $conn, string $submitted, string $outcome, string $reason, bool $verified=false, ?int $studentId=null): void
{
    try {
        if (!in_array($outcome,['success','failure'],true)) throw new InvalidArgumentException('Invalid sign-in outcome.');
        if (!synk_student_login_activity_ready($conn)) throw new RuntimeException('Student login activity schema is not prepared.');
        $email=synk_student_login_activity_text(strtolower(trim($submitted)),255);
        if ($studentId===null && filter_var($email,FILTER_VALIDATE_EMAIL)) {
            $stmt=$conn->prepare('SELECT student_id FROM tbl_student_management WHERE LOWER(TRIM(email_address))=? ORDER BY student_id LIMIT 2');
            $stmt->bind_param('s',$email); $stmt->execute();
            $students=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            if (count($students)===1) $studentId=(int)$students[0]['student_id'];
        }
        $agent=synk_student_login_activity_text((string)($_SERVER['HTTP_USER_AGENT'] ?? ''),512);
        $ip=(string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($ip,FILTER_VALIDATE_IP)) $ip='';
        $device=synk_student_login_activity_device($agent);
        $reason=synk_student_login_activity_text($reason,64); $identityVerified=$verified?1:0;
        $columns='student_id,submitted_email,outcome,reason_code,identity_verified,ip_address,user_agent,browser,operating_system,device_type';
        $types='isssisssss';
        $values=[$studentId,$email,$outcome,$reason,$identityVerified,$ip,$agent,$device['browser'],$device['operating_system'],$device['device_type']];
        if (synk_student_login_activity_term_ready($conn)) {
            // Capture the system term, independently of a staff/student viewing preference.
            $term=synk_fetch_current_academic_term($conn);
            $validTerm=$term['ay_id']>0 && in_array($term['semester'],[1,2,3],true);
            $columns.=',ay_id,semester'; $types.='ii';
            $values[]=$validTerm?$term['ay_id']:null; $values[]=$validTerm?$term['semester']:null;
        }
        $placeholders=implode(',',array_fill(0,count($values),'?'));
        $stmt=$conn->prepare("INSERT INTO tbl_student_login_activity ({$columns},occurred_at) VALUES ({$placeholders},UTC_TIMESTAMP())");
        synk_bind_dynamic_params($stmt,$types,$values);
        if (!$stmt->execute()) throw new RuntimeException('Unable to record student login activity.');
        $stmt->close();
    } catch (Throwable $error) {
        // Diagnostic storage must not lock a verified student out of the portal.
        error_log('Student sign-in activity could not be recorded. Check the activity table and database availability.');
    }
}

function synk_student_login_activity_iso(?string $timestamp): ?string
{
    if (!$timestamp) return null;
    return (new DateTimeImmutable($timestamp,new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

function synk_student_login_activity_counts(mysqli $conn, array $studentIds, ?array $term = null): array
{
    $ids=array_values(array_unique(array_filter(array_map('intval',$studentIds),static function($id){return $id>0;})));
    if (!$ids) return [];
    $ready=synk_student_login_activity_ready($conn); $counts=[];
    foreach ($ids as $id) $counts[$id]=['login_tracking_available'=>$ready,'login_success_count'=>0,'login_failure_count'=>0,'login_last_success_at'=>null];
    if (!$ready) return $counts;
    if ($term !== null && !synk_student_login_activity_term_ready($conn)) throw new RuntimeException('Academic-term login tracking is not prepared.');
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $scope=''; $types=str_repeat('i',count($ids)); $params=$ids;
    if ($term !== null) {
        $scope=' AND ay_id=? AND semester=?'; $types.='ii';
        $params[]=(int)$term['ay_id']; $params[]=(int)$term['semester'];
    }
    $stmt=$conn->prepare("SELECT student_id,SUM(outcome='success') AS successes,SUM(outcome='failure') AS failures,
        MAX(CASE WHEN outcome='success' THEN occurred_at END) AS last_success
        FROM tbl_student_login_activity WHERE student_id IN ({$placeholders}){$scope} GROUP BY student_id");
    try {
        synk_bind_dynamic_params($stmt,$types,$params);
        $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) $counts[(int)$row['student_id']]=['login_tracking_available'=>true,
            'login_success_count'=>(int)$row['successes'],'login_failure_count'=>(int)$row['failures'],
            'login_last_success_at'=>synk_student_login_activity_iso($row['last_success'])];
    } finally { $stmt->close(); }
    return $counts;
}
