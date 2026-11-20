<?php
if (!isset($managingFaculty)) { http_response_code(404); exit; }

session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();
[$conn, $account] = synk_hris_require_admin();
$token = synk_hris_csrf();
$owner = $_GET['record'] ?? '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$validPost = $managingFaculty && synk_hris_valid_post();
$notice = $_SESSION['hris_review_saved'] ?? null;
if ($managingFaculty && !$isPost && is_string($owner) && $notice === $owner) {
    unset($_SESSION['hris_review_saved']);
}
session_write_close();

require_once __DIR__ . '/faculty-data.php';
require_once __DIR__ . '/../backend/user_avatar_helper.php';
require __DIR__ . '/views/navigation.php';

$view = 'faculty';
$pageTitle = $managingFaculty ? 'Manage Faculty Profile' : 'Preview Faculty Profile';
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
    require __DIR__ . '/views/layout-start.php';
    ?>
    <main id="main-content" class="hris-main" tabindex="-1">
        <section class="hris-card hris-panel">
            <h1><?= http_response_code() === 503 ? 'Faculty records are unavailable' : 'Faculty profile not found' ?></h1>
            <p class="hris-empty">Open a record from the faculty directory.</p>
            <div class="hris-hero-actions"><a class="hris-action" href="./?view=faculty">Faculty Profiles</a></div>
        </section>
    <?php
    require __DIR__ . '/views/layout-end.php';
    exit;
}

$recordQuery = '?record=' . rawurlencode($owner);
$manageUrl = 'manage-faculty.php' . $recordQuery;
$previewUrl = 'preview-faculty.php' . $recordQuery;
$profile = [];
$sectionData = null;
$loadError = '';
$saveError = '';
$reviewLocked = false;

if ($managingFaculty) {
    require_once __DIR__ . '/faculty-review.php';
    $reviewOptions = ['pending' => 'Not reviewed', 'follow-up' => 'Needs follow-up', 'reviewed' => 'Reviewed'];
    $review = null;
    try {
        $review = synk_hris_read_review($conn, $owner);
    } catch (mysqli_sql_exception $error) {
        $loadError = 'HR review could not be loaded. Try again later.';
        error_log('Kawani could not load faculty review. Database error ' . $error->getCode());
    }
    $reviewStatus = $review['review_status'] ?? 'pending';
    $reviewNote = $review['review_note'] ?? '';
    $reviewRevision = (int)($review['revision'] ?? 0);
    $reviewStale = $review && !hash_equals($review['data_version'], $record['data_version']);
    $reviewLocked = count($sources) !== 2 || $loadError !== '';

    if ($isPost && !$validPost) {
        http_response_code(403);
        $saveError = 'Your form session expired. Reload the page and try again.';
    } elseif ($isPost && !$reviewLocked) {
        $status = $_POST['review_status'] ?? null;
        $note = $_POST['review_note'] ?? null;
        $version = $_POST['data_version'] ?? null;
        $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $reviewStatus = is_string($status) && isset($reviewOptions[$status]) ? $status : $reviewStatus;
        $validNote = is_string($note) && strlen($note) <= 8000 && preg_match('/\A[^\x00]{0,2000}\z/us', $note);
        if (is_string($note) && strlen($note) <= 8000) $reviewNote = $note;

        if (!is_string($version) || !hash_equals($record['data_version'], $version) || $revision !== $reviewRevision) {
            http_response_code(409);
            $reviewLocked = true;
            $saveError = 'The faculty data or HR review changed while this page was open. Reload to review the latest version.';
        } elseif (!is_string($status) || !isset($reviewOptions[$status]) || !$validNote) {
            http_response_code(422);
            $saveError = 'Choose a review status and enter a note of up to 2,000 characters.';
        } else {
            try {
                if (synk_hris_write_review($conn, $owner, $status, trim($note), $record['data_version'], (int)$account['user_id'], $revision)) {
                    session_start();
                    $_SESSION['hris_review_saved'] = $owner;
                    session_write_close();
                    header('Location: ' . $manageUrl, true, 303);
                    exit;
                }
                http_response_code(409);
                $reviewLocked = true;
                $saveError = 'Another administrator saved this review. Reload to see their changes.';
            } catch (mysqli_sql_exception $error) {
                http_response_code(503);
                error_log('Kawani could not save faculty review. Database error ' . $error->getCode());
                $saveError = 'The HR review could not be saved. Your entries are still here.';
            }
        }
    } elseif ($isPost) {
        http_response_code(503);
    }
} else {
    if ($isPost) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        exit;
    }
    $showProfile = ($_GET['tab'] ?? ($record['saved_sections'] ? 'pds' : 'profile')) === 'profile';
    $sectionKey = $_GET['section'] ?? (array_keys($record['saved_sections'])[0] ?? 'personal');
    $sectionKey = is_string($sectionKey) && isset($sections[$sectionKey]) ? $sectionKey : 'personal';
    try {
        if ($showProfile) {
            $profile = $sources['profiles'][$owner] ?? [];
            if (!empty($profile['user_id'])) {
                $profileUser = (int)$profile['user_id'];
                $stmt = $conn->prepare('SELECT * FROM tbl_faculty_profiles WHERE user_id = ?');
                $stmt->bind_param('i', $profileUser);
                $stmt->execute();
                $profile = $stmt->get_result()->fetch_assoc() ?: [];
                $stmt->close();
            }
            if (!isset($sources['profiles'])) $loadError = 'The Professor Profile is unavailable.';
        } else {
            if ($record['pds_id'] && isset($record['saved_sections'][$sectionKey])) {
                $sectionData = synk_hr_pds_section($conn, $record['pds_id'], $sectionKey);
            }
            if (!isset($sources['pds'])) $loadError = 'The Personal Data Sheet is unavailable.';
        }
    } catch (mysqli_sql_exception $error) {
        $loadError = 'The selected data could not be loaded. Try again later.';
        error_log('Kawani could not preview faculty data. Database error ' . $error->getCode());
    }
}

$savedCount = count($record['saved_sections']);
$employment = $record['appointment'] ?: $record['work_status'];
$profileDocument = !$managingFaculty && $showProfile && $profile && $loadError === '';
require __DIR__ . '/views/layout-start.php';
?>
    <main id="main-content" class="hris-main hris-detail" tabindex="-1">
        <nav class="hris-detail-path" aria-label="Breadcrumb">
            <a class="hris-text-link" href="./?view=faculty"><i class="bx bx-left-arrow-alt" aria-hidden="true"></i> Faculty Profiles</a>
            <span aria-current="page"><?= $managingFaculty ? 'Manage' : 'Preview' ?></span>
        </nav>

        <?php if (!$profileDocument): ?>
            <header class="hris-card hris-detail-hero">
                <div class="hris-faculty-identity">
                    <?php require __DIR__ . '/views/faculty-avatar.php'; ?>
                    <div class="hris-faculty-info">
                        <h1><?= synk_hris_h($record['name']) ?></h1>
                        <p><?= synk_hris_h($record['employee_id'] !== '' ? $record['employee_id'] : 'Employee ID not recorded') ?></p>
                        <?php if ($employment !== ''): ?><span class="hris-status hris-tone-green"><?= synk_hris_h($employment) ?></span><?php endif; ?>
                    </div>
                </div>
                <a class="hris-action<?= $managingFaculty ? '' : ' hris-action-primary' ?>" href="<?= synk_hris_h($managingFaculty ? $previewUrl : $manageUrl) ?>">
                    <i class="bx <?= $managingFaculty ? 'bx-show' : 'bx-edit' ?>" aria-hidden="true"></i> <?= $managingFaculty ? 'Preview profile' : 'Manage review' ?>
                </a>
            </header>
        <?php endif; ?>

        <?php if ($managingFaculty): ?>
            <?php require __DIR__ . '/views/manage-faculty.php'; ?>
        <?php else: ?>
            <?php require __DIR__ . '/views/preview-faculty.php'; ?>
        <?php endif; ?>
<?php require __DIR__ . '/views/layout-end.php'; ?>
