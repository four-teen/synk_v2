<?php
session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();
[$conn, $account] = synk_hris_require_permission('employee_profiles');
$canManageEmployees = synk_hris_can($account, 'employee_manage');
$owner = $_GET['record'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
$previewPartial = ($_GET['partial'] ?? '') === 'preview';
if (!$previewPartial) $token = synk_hris_csrf();
session_write_close();

require_once __DIR__ . '/faculty-data.php';
require_once __DIR__ . '/personnel-data.php';
if (!$previewPartial) {
    require_once __DIR__ . '/../backend/user_avatar_helper.php';
    require __DIR__ . '/views/navigation.php';
}

$view = 'faculty';
$pageTitle = 'Preview Employee Profile';
$sections = array_column(synk_faculty_pds_preview_sections(), null, 'key');
$sources = [];
$record = null;
if (is_string($owner) && preg_match('/\A(?:faculty|account|pds):[1-9][0-9]{0,9}\z/', $owner)) {
    $sources = synk_hris_faculty_sources($conn);
    $matches = array_column(synk_hris_faculty_records($sources['profiles'] ?? [], $sources['pds'] ?? [], count($sections)), null, 'owner');
    $record = $matches[$owner] ?? null;
    if (!$record && count($sources) !== 2) http_response_code(503);
}

if (!$record) {
    if (http_response_code() !== 503) http_response_code(404);
    if ($previewPartial) exit;
    require __DIR__ . '/views/layout-start.php';
    ?>
    <main id="main-content" class="hris-main" tabindex="-1">
        <section class="hris-card hris-panel">
            <h1><?= http_response_code() === 503 ? 'Faculty records are unavailable' : 'Faculty profile not found' ?></h1>
            <p class="hris-empty">Open a record from the faculty directory.</p>
            <div class="hris-hero-actions">
                <a class="hris-action hris-action-pill hris-tone-blue" href="./?view=faculty"><i class="bx bx-left-arrow-alt" aria-hidden="true"></i> Employee Profiles</a>
            </div>
        </section>
    <?php
    require __DIR__ . '/views/layout-end.php';
    exit;
}

$previewUrl = 'preview-faculty.php?record=' . rawurlencode($owner);
$showProfile = ($_GET['tab'] ?? ($record['saved_sections'] ? 'pds' : 'profile')) === 'profile';
$sectionKey = $_GET['section'] ?? (array_keys($record['saved_sections'])[0] ?? 'personal');
$sectionKey = is_string($sectionKey) && isset($sections[$sectionKey]) ? $sectionKey : 'personal';
$showPdsEducation = !$showProfile && $sectionKey === 'education';
$profile = [];
$sectionData = null;
$loadError = '';
$educationNote = '';
$educationGroups = [];
$personnel = [];
try {
    $personnel = synk_hris_load_personnel_details($conn, $owner)[$owner] ?? [];
} catch (mysqli_sql_exception $error) {
    error_log('Kawani could not load preview personnel details. Database error ' . $error->getCode());
}

if ($showProfile || $showPdsEducation) {
    require_once __DIR__ . '/education-data.php';
    try {
        $profile = $sources['profiles'][$owner] ?? [];
        if (!empty($profile['user_id'])) {
            $profileUser = (int)$profile['user_id'];
            $stmt = $conn->prepare('SELECT * FROM tbl_faculty_profiles WHERE user_id = ?');
            $stmt->bind_param('i', $profileUser);
            $stmt->execute();
            $profile = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
        }
        if (!isset($sources['profiles'])) {
            if ($showProfile) $loadError = 'The Professor Profile is unavailable.';
            else $educationNote = 'Professor Profile education is unavailable.';
        }
    } catch (mysqli_sql_exception $error) {
        $profile = [];
        if ($showProfile) $loadError = 'The Professor Profile could not be loaded. Try again later.';
        else $educationNote = 'Professor Profile education could not be loaded.';
        error_log('Kawani could not preview Professor Profile data. Database error ' . $error->getCode());
    }
}

if (!$showProfile) {
    try {
        if ($record['pds_id'] && isset($record['saved_sections'][$sectionKey])) {
            $sectionData = synk_hr_pds_section($conn, $record['pds_id'], $sectionKey);
        }
        if (!isset($sources['pds'])) $loadError = 'The Personal Data Sheet is unavailable.';
    } catch (mysqli_sql_exception $error) {
        $loadError = 'The selected data could not be loaded. Try again later.';
        error_log('Kawani could not preview PDS data. Database error ' . $error->getCode());
    }
}

if ($showProfile || $showPdsEducation) {
    $additionalDegrees = [];
    try {
        if ($profile && synk_table_exists($conn, 'tbl_faculty_profile_additional_degrees')) {
            $degreeOwner = synk_faculty_profile_owner((int)($profile['user_id'] ?? 0), $record['photo_faculty_id'], $profile);
            $additionalDegrees = synk_faculty_profile_degrees_fetch($conn, $degreeOwner);
        }
    } catch (RuntimeException $error) {
        $educationNote = 'Additional Professor Profile degrees could not be loaded.';
        error_log('Kawani could not load additional degrees. Error ' . $error->getCode());
    }
    $educationGroups = synk_hris_education_groups(
        synk_hris_profile_education($profile, $additionalDegrees),
        $showPdsEducation ? ($sectionData['values']['education'] ?? []) : null
    );
}

$profileDocument = $showProfile && ($profile || $personnel) && $loadError === '';
if ($previewPartial) {
    if ($loadError !== '' && !($showPdsEducation && $educationGroups)) {
        http_response_code(503);
        exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    require __DIR__ . '/views/faculty-preview-content.php';
    exit;
}

$storedName = trim((string)($sources['profiles'][$owner]['full_name'] ?? '')) ?: ($sources['pds'][$owner]['owner_name'] ?? '');
try {
    $isPersonalSection = !$showProfile && $sectionKey === 'personal';
    $personal = $isPersonalSection ? $sectionData : null;
    if (!$isPersonalSection && $record['pds_id'] && isset($record['saved_sections']['personal'])) {
        $personal = synk_hr_pds_section($conn, $record['pds_id'], 'personal');
    }
    $nameParts = synk_hris_preview_name_parts($conn, $record['photo_faculty_id'], $storedName, $personal['values'] ?? []);
} catch (mysqli_sql_exception $error) {
    $nameParts = synk_hr_pds_name_defaults($storedName);
    error_log('Kawani could not load faculty name details. Database error ' . $error->getCode());
}

require __DIR__ . '/views/layout-start.php';
require __DIR__ . '/views/preview-faculty.php';
if ($canManageEmployees) require __DIR__ . '/views/work-experience.php';
require __DIR__ . '/views/layout-end.php';
