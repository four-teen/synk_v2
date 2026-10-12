<?php
session_start();
require_once __DIR__ . '/../backend/professor_portal_helper.php';
require_once __DIR__ . '/../backend/faculty_profile_helper.php';
require_once __DIR__ . '/../backend/faculty_profile_image_helper.php';
synk_professor_require_login();

header('Cache-Control: no-store, private');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../backend/db.php';

$userId = (int)$_SESSION['user_id'];
$today = new DateTimeImmutable('today');
$errors = [];
$saveError = '';
$savedNotice = (string)($_SESSION['faculty_profile_notice'] ?? '');
unset($_SESSION['faculty_profile_notice']);

try {
    $account = synk_faculty_profile_account($conn, $userId);
    if (!$account) {
        http_response_code(403);
        exit('Your account is unavailable. Please sign in again.');
    }
    $portal = synk_professor_resolve_portal_context($conn);
    $facultyLink = $portal['faculty_link'];
    $classification = '';
    if ($facultyLink && synk_table_has_column($conn, 'tbl_faculty', 'employment_classification')) {
        $stmt = $conn->prepare('SELECT employment_classification FROM tbl_faculty WHERE faculty_id = ?');
        $stmt->bind_param('i', $portal['faculty_id']);
        $stmt->execute();
        $classification = (string)($stmt->get_result()->fetch_assoc()['employment_classification'] ?? '');
        $stmt->close();
    }
    synk_faculty_profile_ensure_table($conn);
    synk_faculty_profile_ensure_ranks($conn);
    synk_faculty_profile_ensure_eligibilities($conn);
    synk_faculty_profile_ensure_education($conn);
    $eligibilities = synk_faculty_profile_eligibility_catalog($conn);
    $ranks = synk_faculty_profile_ranks($conn);
    $locations = synk_faculty_profile_locations($conn);
    $storedProfile = synk_faculty_profile_fetch($conn, $userId);
    $profile = $storedProfile ?? synk_faculty_profile_defaults($account, $facultyLink, $classification);
    $fields = synk_faculty_profile_fields($ranks, $storedProfile ?? [], $locations);
    $profile['salary_grade'] = synk_faculty_profile_salary((string)$profile['faculty_rank'], $ranks, $storedProfile ?? []);
} catch (Throwable $exception) {
    error_log('Faculty profile load failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('Faculty profiles are temporarily unavailable. Please try again or contact your administrator.');
}

$profileOwner = synk_faculty_profile_owner($userId, (int)$portal['faculty_id'], $storedProfile);
if (empty($_SESSION['faculty_profile_csrf'])) {
    $_SESSION['faculty_profile_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $savedNotice = '';
    [$submitted, $errors] = synk_faculty_profile_validate($_POST, $today, $ranks, $storedProfile ?? [], $eligibilities, $locations);
    $profile = array_merge($profile, $submitted);
    $token = $_POST['csrf_token'] ?? null;
    $revision = $_POST['revision'] ?? null;
    $postedOwner = $_POST['profile_owner'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['faculty_profile_csrf'], $token)) {
        http_response_code(403);
        $saveError = 'Your form session expired. Reload this page before saving again.';
    } elseif (!is_string($revision) || !ctype_digit($revision) || strlen($revision) > 10) {
        http_response_code(422);
        $saveError = 'The profile version is invalid. Reload this page before saving again.';
    } elseif (!is_string($postedOwner) || !hash_equals($profileOwner, $postedOwner)) {
        http_response_code(409);
        $saveError = 'Your faculty account connection changed. Reload the latest profile before saving.';
    } else {
        $profile['revision'] = (int)$revision;
        $stagedImage = null;
        $imageReplacement = null;
        if (!$errors) {
            try {
                $upload = $_FILES['profile_image'] ?? null;
                if ($upload !== null && !is_array($upload)) {
                    throw new InvalidArgumentException('Choose a valid profile image.');
                }
                $stagedImage = synk_faculty_profile_stage_image($upload,
                    ($storedProfile['_storage'] ?? '') === 'faculty' ? 0 : $userId, (int)$portal['faculty_id']);
            } catch (InvalidArgumentException $exception) {
                $errors['profile_image'] = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('Faculty profile image preparation failed: ' . $exception->getMessage());
                $saveError = 'Your profile image could not be prepared. Choose the image again and retry.';
            }
        }
        if ($errors) {
            http_response_code(422);
        } elseif ($saveError !== '') {
            http_response_code(500);
        } else {
            try {
                $conn->begin_transaction();
                // Lock the account link before saving a faculty-owned profile.
                $freshLink = null;
                if (synk_professor_faculty_link_table_exists($conn)) {
                    $linkLock = $conn->prepare('SELECT faculty_id FROM tbl_useraccount_faculty_links WHERE user_id=? FOR UPDATE');
                    $linkLock->bind_param('i', $userId);
                    $linkLock->execute();
                    $freshLink = $linkLock->get_result()->fetch_assoc();
                    $linkLock->close();
                }
                $freshProfile = synk_faculty_profile_fetch($conn, $userId);
                if (!hash_equals($profileOwner, synk_faculty_profile_owner($userId, (int)($freshLink['faculty_id'] ?? 0), $freshProfile))) {
                    throw new DomainException('Your faculty account connection changed. Reload the latest profile before saving.');
                }
                // Account identity and the linked faculty record ID stay session-owned.
                if (synk_faculty_profile_save($conn, $userId, $submitted, (int)$revision)) {
                    if ($stagedImage !== null) {
                        $imageReplacement = synk_faculty_profile_publish_image($stagedImage,
                            ($storedProfile['_storage'] ?? '') === 'faculty' ? 0 : $userId, (int)$portal['faculty_id']);
                    }
                    $conn->commit();
                    $_SESSION['faculty_profile_notice'] = 'Your faculty profile has been saved.';
                    header('Location: manage-profile.php', true, 303);
                    exit;
                }
                $conn->rollback();
                http_response_code(409);
                $saveError = 'This profile was saved in another window. Your entries are shown below. Copy any changes you need, then reload the latest profile before saving.';
            } catch (Throwable $exception) {
                // Restore before releasing the row lock to another save request.
                if ($imageReplacement !== null) {
                    try {
                        synk_faculty_profile_restore_image($imageReplacement);
                    } catch (Throwable $restoreException) {
                        error_log('Faculty profile image restore failed: ' . $restoreException->getMessage());
                    }
                }
                $conn->rollback();
                if ($exception instanceof DomainException) {
                    http_response_code(409);
                    $saveError = $exception->getMessage();
                } else {
                    error_log('Faculty profile save failed: ' . $exception->getMessage());
                    http_response_code(500);
                    $saveError = 'Your profile could not be saved. Your entries are still shown below; please try again.';
                }
            } finally {
                if ($stagedImage !== null && is_file($stagedImage)) {
                    unlink($stagedImage);
                }
            }
        }
    }
}

$birthDate = synk_faculty_profile_date((string)($profile['date_of_birth'] ?? ''));
$age = $birthDate && $birthDate <= $today ? $birthDate->diff($today)->y . ' years old' : '';
if (!empty($profile['service_start_date']) && !isset($errors['service_start_date'])) {
    $profile['length_of_service'] = synk_faculty_profile_service($profile['service_start_date'], $today);
}
$professorPortalDisplayName = $portal['faculty_name'];
$professorPortalDisplayEmail = $account['email'];
$professorPortalFacultyStatusLabel = $portal['faculty_is_linked'] ? 'Faculty linked' : 'Needs faculty link';
$educationLevels = ['bachelor' => "Bachelor's degree", 'master' => "Master's degree", 'doctoral' => 'Doctoral degree'];
$completionRules = synk_faculty_profile_completion_rules($fields);
[$completionValues, $completionErrors] = synk_faculty_profile_validate($profile, $today, $ranks, $storedProfile ?? [], $eligibilities, $locations);
$completion = synk_faculty_profile_completion($completionValues, $completionRules, $completionErrors);
$completionSections = ['personal' => 'Personal details', 'employment' => 'Employment', 'education' => 'Education'];
$initials = '';
$profileImageUrl = synk_faculty_profile_image_url(($storedProfile['_storage'] ?? '') === 'faculty' ? 0 : $userId, (int)$portal['faculty_id']);
foreach (array_slice(preg_split('/\s+/', trim($profile['full_name'])) ?: [], 0, 2) as $namePart) {
    $initials .= function_exists('mb_substr') ? mb_substr($namePart, 0, 1, 'UTF-8') : substr($namePart, 0, 1);
}

$profileEditor = ['hidden_fields'=>['profile_owner'=>$profileOwner]];
require __DIR__ . '/../backend/faculty_profile_editor_view.php';
