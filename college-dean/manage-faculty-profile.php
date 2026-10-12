<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/college_dean_profile_helper.php';
synk_college_dean_require_login($conn);
$scope = synk_college_dean_dashboard_require_scope($conn, [], true);
$collegeDeanPortalContext = synk_college_dean_portal_context($conn);
$term = synk_fetch_current_academic_term($conn);
header('Cache-Control: no-store, private');
$facultyId = synk_dean_profile_id($_GET['faculty_id'] ?? null);
if (!$facultyId) { http_response_code(400); exit('Select a valid faculty member from Faculty profile.'); }
$targets = synk_dean_profile_faculties($conn, $scope, $term, $facultyId);
if (!$targets) { http_response_code(404); exit('This faculty member is unavailable in your assigned college.'); }
$target = $targets[0];
$actorId = (int)$_SESSION['user_id'];
$today = new DateTimeImmutable('today');
$errors = [];
$saveError = '';
$savedNotice = '';
$notice = $_SESSION['dean_faculty_profile_notice'] ?? null;
if (is_array($notice) && ($notice['faculty_id'] ?? 0) === $facultyId) {
    $savedNotice = (string)$notice['message'];
    unset($_SESSION['dean_faculty_profile_notice']);
}
try {
    synk_dean_profile_prepare_storage($conn);
    $eligibilities = synk_faculty_profile_eligibility_catalog($conn);
    $ranks = synk_faculty_profile_ranks($conn);
    $locations = synk_faculty_profile_locations($conn);
    $account = ['username'=>$target['faculty_name'], 'email'=>$target['profile_email'],
        'campus_name'=>$scope['campus_name'], 'college_name'=>$scope['college_name']];
    $storedProfile = synk_dean_profile_load($conn, $target);
    $profile = $storedProfile ?? synk_faculty_profile_defaults($account, $target, (string)$target['employment_classification']);
    $fields = synk_faculty_profile_fields($ranks, $storedProfile ?? [], $locations);
    $profile['salary_grade'] = synk_faculty_profile_salary((string)$profile['faculty_rank'], $ranks, $storedProfile ?? []);
} catch (Throwable $exception) {
    error_log('Dean faculty profile load: ' . $exception->getMessage());
    http_response_code(503);
    exit('Faculty profiles are temporarily unavailable. Please try again.');
}
$owner = synk_dean_profile_owner($target, $storedProfile);
$imageUserId = strpos($owner, 'account:') === 0 ? $target['profile_user_id'] : 0;
if (empty($_SESSION['dean_faculty_profile_csrf'])) $_SESSION['dean_faculty_profile_csrf'] = bin2hex(random_bytes(32));
$action = 'manage-faculty-profile.php?faculty_id=' . $facultyId;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $savedNotice = '';
    [$submitted, $errors] = synk_faculty_profile_validate($_POST, $today, $ranks, $storedProfile ?? [], $eligibilities, $locations);
    $profile = array_merge($profile, $submitted);
    $token = $_POST['csrf_token'] ?? null;
    $revision = $_POST['revision'] ?? null;
    $postedOwner = $_POST['profile_owner'] ?? null;
    if (!is_string($token) || !hash_equals((string)$_SESSION['dean_faculty_profile_csrf'], $token)) {
        http_response_code(403);
        $saveError = 'Your form session expired. Reload this page before saving again.';
    } elseif (!is_string($revision) || !ctype_digit($revision) || strlen($revision) > 10 || (float)$revision > 4294967295) {
        http_response_code(422);
        $saveError = 'The profile version is invalid. Reload this page before saving.';
    } elseif (!is_string($postedOwner) || !hash_equals($owner, $postedOwner)) {
        http_response_code(409);
        $saveError = 'The faculty account connection changed. Reload the latest profile before saving.';
    } else {
        $profile['revision'] = (int)$revision;
        $stagedImage = null;
        $replacement = null;
        if (!$errors) {
            try {
                $upload = $_FILES['profile_image'] ?? null;
                if ($upload !== null && !is_array($upload)) throw new InvalidArgumentException('Choose a valid profile image.');
                $stagedImage = synk_faculty_profile_stage_image($upload, $imageUserId, $facultyId);
            } catch (InvalidArgumentException $exception) {
                $errors['profile_image'] = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('Dean profile image preparation: ' . $exception->getMessage());
                $saveError = 'The profile image could not be prepared. Choose it again and retry.';
            }
        }
        if ($errors) {
            http_response_code(422);
        } elseif ($saveError !== '') {
            http_response_code(500);
        } else {
            try {
                $conn->begin_transaction();
                $freshScope = synk_college_dean_dashboard_scope($conn);
                if (!$freshScope || $freshScope['college_id'] !== $scope['college_id']) throw new DomainException('Your college access changed. Reload this page before saving.');
                $freshTargets = synk_dean_profile_faculties($conn, $freshScope, synk_fetch_current_academic_term($conn), $facultyId, true);
                if (!$freshTargets) throw new DomainException('This faculty member is no longer in your assigned college.');
                $freshProfile = synk_dean_profile_load($conn, $freshTargets[0]);
                if (!hash_equals($owner, synk_dean_profile_owner($freshTargets[0], $freshProfile))) throw new DomainException('The faculty account connection changed. Reload the latest profile before saving.');
                $saved = strpos($owner, 'account:') === 0
                    ? synk_faculty_profile_save($conn, $freshTargets[0]['profile_user_id'], $submitted, (int)$revision)
                    : synk_faculty_record_profile_save($conn, $facultyId, $submitted, (int)$revision, $actorId);
                if (!$saved) {
                    $conn->rollback();
                    http_response_code(409);
                    $saveError = 'This profile was saved in another window. Your entries are shown below. Reload the latest profile before saving again.';
                } else {
                    if ($stagedImage !== null) $replacement = synk_faculty_profile_publish_image($stagedImage, $imageUserId, $facultyId);
                    $conn->commit();
                    $_SESSION['dean_faculty_profile_notice'] = ['faculty_id'=>$facultyId, 'message'=>'The faculty profile has been saved.'];
                    header('Location: ' . $action, true, 303);
                    exit;
                }
            } catch (Throwable $exception) {
                if ($replacement !== null) {
                    try { synk_faculty_profile_restore_image($replacement); }
                    catch (Throwable $restoreException) { error_log('Dean profile image restore: ' . $restoreException->getMessage()); }
                }
                $conn->rollback();
                if ($exception instanceof DomainException) {
                    http_response_code(409);
                    $saveError = $exception->getMessage();
                } else {
                    error_log('Dean faculty profile save: ' . $exception->getMessage());
                    http_response_code(500);
                    $saveError = 'The profile could not be saved. Your entries are still shown below; please try again.';
                }
            } finally {
                if ($stagedImage !== null && is_file($stagedImage)) unlink($stagedImage);
            }
        }
    }
}

$birthDate = synk_faculty_profile_date((string)($profile['date_of_birth'] ?? ''));
$age = $birthDate && $birthDate <= $today ? $birthDate->diff($today)->y . ' years old' : '';
if (!empty($profile['service_start_date']) && !isset($errors['service_start_date'])) $profile['length_of_service'] = synk_faculty_profile_service($profile['service_start_date'], $today);
$educationLevels = ['bachelor'=>"Bachelor's degree", 'master'=>"Master's degree", 'doctoral'=>'Doctoral degree'];
$completionRules = synk_faculty_profile_completion_rules($fields);
[$completionValues, $completionErrors] = synk_faculty_profile_validate($profile, $today, $ranks, $storedProfile ?? [], $eligibilities, $locations);
$completion = synk_faculty_profile_completion($completionValues, $completionRules, $completionErrors);
$completionSections = ['personal'=>'Personal details', 'employment'=>'Employment', 'education'=>'Education'];
$initials = 'FP';
$profileImageUrl = synk_faculty_profile_image_url($imageUserId, $facultyId) ?: 'faculty-placeholder.svg';
$portal = ['faculty_is_linked'=>true];
$profileEditor = [
    'theme_path'=>__DIR__ . '/custom_css.css', 'sidebar_path'=>__DIR__ . '/sidebar.php', 'navbar_path'=>__DIR__ . '/navbar.php',
    'eyebrow'=>'COLLEGE DEAN / FACULTY PROFILE', 'title'=>'Manage faculty profile',
    'subtitle'=>$target['faculty_name'] . ($target['designation_name'] !== '' ? ' · ' . $target['designation_name'] : ''),
    'back_url'=>'faculty-profile.php', 'back_label'=>'Faculty directory',
    'account_note'=>'You are managing the selected faculty member’s profile.', 'show_link_notice'=>false,
    'action'=>$action, 'education_url'=>'education-options.php', 'csrf_token'=>$_SESSION['dean_faculty_profile_csrf'],
    'hidden_fields'=>['profile_owner'=>$owner],
];
require __DIR__ . '/../backend/faculty_profile_editor_view.php';
