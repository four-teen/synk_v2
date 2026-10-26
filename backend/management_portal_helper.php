<?php
require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/auth_config.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/management_period_helper.php';

class SynkManagementConflict extends RuntimeException {}

function synk_management_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function synk_management_query(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the Management view.');
    try {
        if ($types !== '') synk_stmt_bind_params($stmt, $types, $values);
        if (!$stmt->execute()) throw new RuntimeException('Unable to read the Management view.');
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

function synk_management_complete_verified_login(mysqli $conn, array $identity): void
{
    $email = synk_normalize_email((string)($identity['email'] ?? ''));
    $sub = trim((string)($identity['sub'] ?? ''));
    if (!$sub || !filter_var($identity['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
        || !synk_account_email_allowed($email, ['management'], (string)synk_auth_settings()['allowed_domain'])) {
        throw new DomainException('management_access_denied');
    }
    $account = synk_find_useraccount_by_email($conn, $email);
    if (!$account || !synk_management_account_has_access($conn, $account)) throw new DomainException('management_access_denied');
    $existingSub = trim((string)($account['google_sub'] ?? ''));
    if ($existingSub !== '' && !hash_equals($existingSub, $sub)) throw new DomainException('google_identity_mismatch');
    synk_record_google_login($conn, (int)$account['user_id'], $sub, true, (string)($identity['name'] ?? ''));
    $_SESSION['auth_method'] = 'google';
    synk_complete_user_login($account, $conn, 'management');
    unset($_SESSION['management_login_csrf']);
}

function synk_management_require_scope(mysqli $conn, bool $api = false): array
{
    header('Cache-Control: no-store, private');
    $deny = static function (string $message, int $status, string $location) use ($api): void {
        if ($api) {
            http_response_code($status); header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status'=>'error','message'=>$message]); exit;
        }
        header('Location: ' . $location); exit;
    };
    if (($_SESSION['role'] ?? '') !== 'management' || empty($_SESSION['user_id'])) {
        $deny('Sign in to the Management portal to continue.', 401, 'login.php');
    }
    $account = synk_find_useraccount_by_id($conn, (int)$_SESSION['user_id']);
    if (($_SESSION['auth_method'] ?? '') !== 'google' || !$account || !synk_management_account_has_access($conn, $account)) {
        synk_reset_authenticated_session_context();
        $deny('Your Management access changed. Sign in again.', 403, 'login.php?auth_status=management_access_denied');
    }
    $scope = synk_management_query($conn, 'SELECT o.organization_id,o.organization_code,o.organization_name,c.college_id,c.college_code,c.college_name,cp.campus_id,cp.campus_name
        FROM tbl_management_organizations o JOIN tbl_college c ON c.college_id=o.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE o.organization_id=? AND c.college_id=?', 'ii', [(int)$account['management_organization_id'],(int)$account['management_college_id']])[0];
    foreach (['college_id','campus_id','organization_id'] as $key) {
        $scope[$key] = (int)$scope[$key];
        foreach ([$_GET, $api ? $_POST : []] as $input) if (isset($input[$key]) && (!is_scalar($input[$key]) || (string)$input[$key] !== (string)$scope[$key])) {
            if ($api) $deny('This view is outside your assigned organization.', 403, '');
            http_response_code(403); exit('This view is outside your assigned organization.');
        }
    }
    $_SESSION['college_id'] = $scope['college_id'];
    $_SESSION['college_name'] = $scope['college_name'];
    $_SESSION['campus_id'] = $scope['campus_id'];
    $_SESSION['campus_name'] = $scope['campus_name'];
    $_SESSION['username'] = $account['username'];
    $_SESSION['email'] = $account['email'];
    return $scope;
}

function synk_management_view_term(mysqli $conn, array $input, ?array $scope = null): array
{
    $current = $scope ? synk_management_fetch_current_period($conn,$scope) : synk_fetch_current_academic_term($conn);
    $key = $input['term'] ?? ($current['ay_id'] . ':' . $current['semester']);
    if (array_key_exists('view_ay_id', $input) || array_key_exists('view_semester', $input)) {
        if (!is_string($input['view_ay_id'] ?? null) || !is_string($input['view_semester'] ?? null)) throw new DomainException('Choose an academic year and semester.');
        $key = $input['view_ay_id'] . ':' . $input['view_semester'];
    }
    if (!is_string($key) || !preg_match('/^([1-9][0-9]{0,9}):([1-3])$/D', $key, $matches)) throw new DomainException('Choose a valid academic year and semester.');
    $years = synk_management_query($conn, 'SELECT ay_id,ay FROM tbl_academic_years WHERE ay_id=?', 'i', [(int)$matches[1]]);
    if (!$years) throw new DomainException('The academic year is unavailable.');
    $examTerm = synk_management_exam_term($input['exam_term'] ?? $current['exam_term'] ?? 'MIDTERM');
    $text = $years[0]['ay'] . ' - ' . synk_semester_label((int)$matches[2]);
    return ['key'=>$key,'ay_id'=>(int)$matches[1],'ay_label'=>$years[0]['ay'],'semester'=>(int)$matches[2],
        'term_text'=>$text,'exam_term'=>$examTerm,'exam_term_label'=>synk_management_exam_term_options()[$examTerm],
        'period_key'=>$key . ':' . $examTerm,'period_text'=>$text . ' - ' . synk_management_exam_term_options()[$examTerm]];
}

function synk_management_import_source(array $scope, array $term, array $filters = []): array
{
    $sql = 'FROM tbl_student_management s JOIN tbl_student_management_enrolled_subjects es ON es.student_id=s.student_id
        JOIN tbl_program p ON p.program_id=es.program_id AND p.college_id=es.college_id
        LEFT JOIN tbl_sections sec ON sec.section_id=es.section_id AND sec.program_id=es.program_id AND sec.ay_id=es.ay_id AND sec.semester=es.semester
        WHERE es.is_active=1 AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=?';
    $types = 'iiii'; $values = [$scope['college_id'],$scope['campus_id'],$term['ay_id'],$term['semester']];
    if (!empty($filters['program_id'])) { $sql .= ' AND es.program_id=?'; $types .= 'i'; $values[] = $filters['program_id']; }
    if (!empty($filters['search'])) {
        foreach (preg_split('/\s+/u', $filters['search']) as $word) {
            $pattern = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $word) . '%';
            $sql .= " AND (s.student_number LIKE ? ESCAPE '!' OR CONCAT_WS(' ',s.last_name,s.first_name,s.middle_name,s.suffix_name) LIKE ? ESCAPE '!')";
            $types .= 'ss'; $values[] = $pattern; $values[] = $pattern;
        }
    }
    return ['sql'=>$sql,'types'=>$types,'values'=>$values];
}

function synk_management_students_ready(mysqli $conn): bool
{
    return synk_table_exists($conn, 'tbl_student_management') && synk_table_exists($conn, 'tbl_student_management_enrolled_subjects');
}

function synk_management_analytics(mysqli $conn, array $scope, array $term): array
{
    $data = ['students'=>0,'programs'=>0,'sections'=>0,'enrollments'=>0,'last_import'=>null,'by_program'=>[]];
    if (!synk_management_students_ready($conn)) return $data;
    $source = synk_management_import_source($scope, $term);
    $row = synk_management_query($conn, "SELECT COUNT(DISTINCT s.student_id) AS students,COUNT(DISTINCT p.program_id) AS programs,
        COUNT(DISTINCT NULLIF(es.section_id,0)) AS sections,COUNT(DISTINCT es.student_enrollment_id) AS enrollments,MAX(es.created_at) AS last_import " . $source['sql'], $source['types'], $source['values'])[0];
    foreach (['students','programs','sections','enrollments'] as $key) $data[$key] = (int)$row[$key];
    $data['last_import'] = $row['last_import'];
    $data['by_program'] = synk_management_query($conn, 'SELECT p.program_id,p.program_code,p.program_name,p.major,COUNT(DISTINCT s.student_id) AS students '
        . $source['sql'] . ' GROUP BY p.program_id,p.program_code,p.program_name,p.major ORDER BY students DESC,p.program_code', $source['types'], $source['values']);
    return $data;
}

