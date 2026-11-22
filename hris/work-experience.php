<?php
session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();
[$conn, $account] = synk_hris_require_admin();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/faculty-data.php';

header('Content-Type: application/json; charset=utf-8');
$reply = static function (int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    $reply(405, ['message' => 'Use the Work Experience form.']);
}
$saving = $method === 'POST';
$columns = synk_hr_pds_work_columns();
$token = $_SESSION['hris_csrf'] ?? '';
session_write_close();

$owner = $_GET['record'] ?? '';
if (!is_string($owner) || !preg_match('/\A(faculty|account):([1-9][0-9]{0,9})\z/', $owner, $parts)) {
    $reply(404, ['message' => 'This record has no faculty or account connection.']);
}
if ($saving) {
    $body = file_get_contents('php://input', false, null, 0, 6291457);
    if ($body === false || strlen($body) > 6291456) {
        $reply(413, ['message' => 'This work history is too large to save.']);
    }
    $payload = json_decode($body, true, 10);
    if (!is_array($payload) || !is_string($payload['csrf_token'] ?? null)
        || $token === '' || !hash_equals($token, $payload['csrf_token'])) {
        $reply(403, ['message' => 'Your form session expired. Reload the directory before saving.']);
    }
    $revision = $payload['revision'] ?? null;
    $profileId = $payload['profile_id'] ?? null;
    if (array_diff(array_keys($payload), ['csrf_token', 'revision', 'profile_id', 'values'])
        || !is_int($revision) || $revision < 0 || $revision > 2147483646 || !is_int($profileId) || $profileId < 0) {
        $reply(422, ['message' => 'The work history version is invalid. Reopen Work Experience.']);
    }
    $rows = $payload['values']['work'] ?? null;
    $limit = synk_hr_pds_record_lists('work')['work']['limit'];
    if (!is_array($rows) || count($rows) > $limit || array_values($rows) !== $rows) {
        $reply(422, ['message' => 'Use a valid list of up to ' . $limit . ' records.']);
    }
}

$transaction = false;
try {
    $sources = synk_hris_faculty_sources($conn);
    if (!isset($sources['pds'])) {
        $reply(503, ['message' => 'The Personal Data Sheet is currently unavailable.']);
    }
    if (!isset($sources['profiles'][$owner]) && !isset($sources['pds'][$owner])) {
        $reply(404, ['message' => 'This faculty record is unavailable. Refresh the directory.']);
    }

    $facultyId = $parts[1] === 'faculty' ? (int)$parts[2] : 0;
    $userId = $facultyId ? 0 : (int)$parts[2];
    if ($saving) synk_hr_pds_ensure_work_dates($conn);
    $conn->begin_transaction();
    $transaction = true;
    if ($facultyId) {
        $stmt = $conn->prepare('SELECT user_id FROM tbl_useraccount_faculty_links WHERE faculty_id = ? FOR UPDATE');
        $stmt->bind_param('i', $facultyId);
    } else {
        $stmt = $conn->prepare('SELECT faculty_id FROM tbl_useraccount_faculty_links WHERE user_id = ? FOR UPDATE');
        $stmt->bind_param('i', $userId);
    }
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$facultyId && $link) {
        throw new DomainException('The faculty connection changed. Refresh the directory and reopen Work Experience.');
    }
    if ($facultyId) $userId = (int)($link['user_id'] ?? 0);

    $profile = synk_hr_pds_profile($conn, $userId, $facultyId, true);
    $currentId = (int)($profile['hr_profile_id'] ?? 0);
    $state = $currentId ? synk_hr_pds_section($conn, $currentId, 'work') : null;
    if ($saving) {
        if ($profileId !== $currentId) {
            throw new DomainException('The PDS record changed. Reopen Work Experience to load the latest version.');
        }
        if (($state['revision'] ?? 0) !== $revision) {
            throw new DomainException('Work experience was saved in another window. Reopen the form to load the latest version.');
        }
        $existing = $state['values']['work'] ?? [];
        $seen = [];
        $work = [];
        $hiddenFields = array_diff_key(synk_hr_pds_record_lists('work')['work']['fields'], $columns);
        $hiddenDefaults = array_fill_keys(array_keys($hiddenFields), '');
        $expected = $columns + ['record_index' => true];
        foreach ($rows as $row) {
            if (!is_array($row) || array_diff_key($row, $expected) || array_diff_key($expected, $row)) {
                throw new DomainException('The work experience fields changed. Reopen the form before saving.');
            }
            $index = $row['record_index'];
            if ($index !== null && (!is_int($index) || !isset($existing[$index]) || isset($seen[$index]))) {
                throw new DomainException('A work experience row could not be matched. Reopen the form before saving.');
            }
            $preserved = $index === null ? $hiddenDefaults : array_diff_key($existing[$index], $columns);
            if ($index !== null) $seen[$index] = true;
            $preserved['current'] = '0';
            foreach (['position', 'employer', 'pay_grade', 'appointment_status'] as $field) {
                if (is_string($row[$field])) {
                    $row[$field] = function_exists('mb_strtoupper') ? mb_strtoupper($row[$field], 'UTF-8') : strtoupper($row[$field]);
                }
            }
            $work[] = array_intersect_key($row, $columns) + $preserved;
        }
        [$values, $errors] = synk_hr_pds_validate('work', ['work' => $work], new DateTimeImmutable('today'));
        if ($errors) {
            $conn->rollback();
            $reply(422, ['message' => 'Check the marked cells before saving.', 'errors' => $errors]);
        }
        $currentId = synk_hr_pds_prepare_profile($conn, $profile, $userId, $facultyId);
        $state = synk_hr_pds_save_section($conn, $currentId, synk_hris_actor_id($account), 'work', $values, $revision);
    }
    $conn->commit();
    $transaction = false;
    $work = [];
    foreach ($state['values']['work'] ?? [] as $index => $row) {
        if ($row['current'] === '1') $row['date_to'] = 'PRESENT';
        $work[] = array_intersect_key($row, $columns) + ['record_index' => $index];
    }
    $reply(200, ['profile_id' => $currentId, 'revision' => $state['revision'] ?? 0, 'values' => ['work' => $work]]);
} catch (DomainException $error) {
    if ($transaction) $conn->rollback();
    $reply(409, ['message' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($transaction) $conn->rollback();
    error_log('Kawani work experience request failed. Error ' . $error->getCode());
    $reply(503, ['message' => 'Work experience could not be ' . ($saving ? 'saved. Try again.' : 'loaded. Reopen the form to retry.')]);
}
