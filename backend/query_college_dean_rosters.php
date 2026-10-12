<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/college_dean_workload_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function synk_dean_roster_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') synk_dean_roster_response(['status'=>'error','message'=>'Use a POST request.'], 405);
foreach (['college_id','campus_id','ay_id','semester','faculty_id','offering_id','action','csrf_token','preview_token'] as $key) {
    if (isset($_POST[$key]) && !is_scalar($_POST[$key])) synk_dean_roster_response(['status'=>'error','message'=>'Invalid request.'], 400);
}
$scope = synk_college_dean_dashboard_require_scope($conn, $_POST);
if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null)
    || !hash_equals((string)$_SESSION['csrf_token'], $_POST['csrf_token'])) {
    synk_dean_roster_response(['status'=>'error','message'=>'Your session token has changed. Reload the page and try again.'], 403);
}
$term = synk_fetch_current_academic_term($conn);
$action = (string)($_POST['action'] ?? '');
if ($term['ay_id'] <= 0 || $term['semester'] <= 0) synk_dean_roster_response(['status'=>'error','message'=>'Set the current academic term before opening faculty workloads.'], 409);
foreach (['ay_id','semester'] as $key) {
    if (isset($_POST[$key]) && (string)$_POST[$key] !== (string)$term[$key]) {
        synk_dean_roster_response(['status'=>'error','message'=>'The academic term has changed. Reload this page.'], 409);
    }
}
try {
    if ($action === 'bootstrap') synk_dean_roster_response([
        'status'=>'ok','scope'=>$scope,'term'=>$term,'faculties'=>synk_dean_workload_faculties($conn,$scope,$term),
        'import_ready'=>synk_dean_roster_ready($conn),
    ]);
    if (!in_array($action, ['classes','roster','preview','confirm','cancel'], true)) {
        synk_dean_roster_response(['status'=>'error','message'=>'Unknown workload action.'], 400);
    }
    // Prune previews and bound session storage. Only a server-issued token can confirm an import.
    $pendingPreviews = (array)($_SESSION['dean_roster_previews'] ?? []);
    foreach ($pendingPreviews as $token=>$pending) {
        if ($pending['expires_at'] < time()) unset($pendingPreviews[$token]);
    }
    $_SESSION['dean_roster_previews'] = $pendingPreviews;
    if ($action === 'cancel') {
        unset($_SESSION['dean_roster_previews'][(string)($_POST['preview_token'] ?? '')]);
        synk_dean_roster_response(['status'=>'ok']);
    }
    $facultyId = filter_var($_POST['faculty_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$facultyId) throw new DomainException('Select a faculty member first.');
    if ($action === 'classes') {
        $faculties = synk_dean_workload_faculties($conn,$scope,$term,$facultyId);
        if (!$faculties) throw new DomainException('This faculty member is not assigned to your college in the current term.');
        synk_dean_roster_response(['status'=>'ok','faculty'=>$faculties[0],
            'classes'=>synk_dean_workload_classes($conn,$scope,$term,$facultyId)]);
    }
    if (!synk_dean_roster_ready($conn)) throw new DomainException('Student roster storage is not available yet.');
    $offeringId = filter_var($_POST['offering_id'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$offeringId) throw new DomainException('Select an assigned class first.');
    $class = synk_dean_workload_class($conn,$scope,$term,$facultyId,$offeringId);
    if ($action === 'roster') synk_dean_roster_response(['status'=>'ok','class'=>$class,'students'=>synk_dean_roster_rows($conn,$class)]);
    if ($action === 'confirm') {
        $token = (string)($_POST['preview_token'] ?? '');
        $pending = $_SESSION['dean_roster_previews'][$token] ?? null;
        if (!$pending || $pending['faculty_id'] !== $facultyId || $pending['offering_id'] !== $offeringId) throw new DomainException('This import preview is no longer available. Preview the CSV again.');
        $result = synk_dean_roster_save($conn,$scope,$term,$pending,(int)$_SESSION['user_id']);
        unset($_SESSION['dean_roster_previews'][$token]);
        synk_dean_roster_response(['status'=>'ok','result'=>$result,'message'=>$result['added'] . ' students added. ' . $result['skipped'] . ' existing students skipped.']);
    }
    $file = $_FILES['csv_file'] ?? null;
    if (!is_array($file) || !is_scalar($file['error'] ?? null) || (int)$file['error'] !== UPLOAD_ERR_OK
        || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        $error = is_array($file) && is_scalar($file['error'] ?? null) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
        $reason = [UPLOAD_ERR_INI_SIZE=>'The upload exceeds the server file-size limit.',
            UPLOAD_ERR_FORM_SIZE=>'The upload exceeds the form file-size limit.', UPLOAD_ERR_PARTIAL=>'Only part of the CSV reached the server.',
            UPLOAD_ERR_NO_FILE=>'No CSV file was received.', UPLOAD_ERR_NO_TMP_DIR=>'The server upload folder is unavailable.',
            UPLOAD_ERR_CANT_WRITE=>'The server could not store the temporary upload.', UPLOAD_ERR_EXTENSION=>'The server stopped this upload.'][$error] ?? 'The CSV upload could not be verified.';
        throw new SynkDeanRosterValidationException([synk_dean_roster_issue(null,'','CSV upload',$reason,'One complete CSV file, up to 2 MB',
            $reason, 'Choose the CSV and retry. If the upload folder or server limit is the issue, contact your administrator.')]);
    }
    if (!is_string($file['name'] ?? null)) throw new DomainException('Choose one CSV file with a valid file name.');
    $fileName = synk_student_management_normalize_csv_encoding(basename(str_replace('\\', '/', (string)$file['name'])));
    if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== 'csv' || strlen($fileName) > 255) throw new SynkDeanRosterValidationException([
        synk_dean_roster_issue(null,'','File name',$fileName,'A .csv file name up to 255 bytes',
            'The file extension or name is invalid.', 'Export as CSV UTF-8 (comma delimited), using a shorter file name. Do not rename an Excel workbook to .csv.')]);
    $parsed = synk_dean_roster_parse($file['tmp_name']);
    $preview = synk_dean_roster_preview($conn,$class,$parsed);
    $token = '';
    if ($preview['can_confirm']) {
        while (count($_SESSION['dean_roster_previews']) >= 3) array_shift($_SESSION['dean_roster_previews']);
        $token = bin2hex(random_bytes(32));
        $_SESSION['dean_roster_previews'][$token] = [
            'user_id'=>(int)$_SESSION['user_id'], 'college_id'=>$scope['college_id'],
            'ay_id'=>$term['ay_id'], 'semester'=>$term['semester'], 'faculty_id'=>$facultyId,
            'offering_id'=>$offeringId, 'expires_at'=>time()+1800, 'file_name'=>$fileName, 'parsed'=>$parsed,
        ];
    }
    // PHP removes the temporary upload after the request; keep only validated rows in this session.
    synk_dean_roster_response(['status'=>'ok','class'=>$class,'file_name'=>$fileName,'preview_token'=>$token,'preview'=>$preview]);
} catch (SynkDeanRosterValidationException $e) {
    synk_dean_roster_response(['status'=>'error','message'=>$e->getMessage(),'issues'=>$e->issues], 422);
} catch (DomainException $e) {
    synk_dean_roster_response(['status'=>'error','message'=>$e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('College Dean roster ' . $action . ': ' . $e->getMessage());
    synk_dean_roster_response(['status'=>'error','message'=>'Unable to complete this workload request. Please try again.'], 500);
}
