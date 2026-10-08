<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../backend/faculty_profile_helper.php';
$directory = synk_faculty_directory_records($conn);
$facultyId = synk_faculty_directory_id($_GET['faculty_id'] ?? null) ?? 0;
$record = $directory['faculty'][$facultyId] ?? null;
$pageTitle = 'Review Faculty';
if (!$record) {
    http_response_code(404);
    include __DIR__ . '/page-start.php';
    echo '<div class="alert alert-warning">This faculty record was not found.</div><a class="btn btn-outline-primary" href="professors.php">Back to professors</a>';
    include __DIR__ . '/page-end.php';
    exit;
}
if (empty($_SESSION['di_faculty_csrf'])) $_SESSION['di_faculty_csrf'] = bin2hex(random_bytes(32));
$formValues = ['status' => $record['status'], 'campus_id' => (int)($record['campus_id'] ?? 0),
    'college_id' => (int)($record['college_id'] ?? 0), 'remarks' => (string)($record['remarks'] ?? '')];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $revision = synk_faculty_directory_id($_POST['revision'] ?? null);
    [$formValues, $errors] = synk_faculty_directory_validate($_POST, $directory);
    if (!is_string($token) || !hash_equals($_SESSION['di_faculty_csrf'], $token)) {
        http_response_code(403);
        $errors['form'] = 'The form could not be verified. Reload this page and try again.';
    } elseif ($revision === null) $errors['form'] = 'Reload this faculty record before saving.';
    if (!$errors) {
        try {
            if (synk_faculty_directory_save($conn, $facultyId, $revision, (int)$moduleAccount['user_id'], $formValues)) {
                $_SESSION['di_faculty_flash'] = $facultyId;
                header('Location: faculty-profile.php?faculty_id=' . $facultyId);
                exit;
            }
            http_response_code(409);
            $errors['form'] = 'This record changed while you were reviewing it. Reload the page to see the latest information.';
        } catch (Throwable $exception) {
            error_log('DI faculty review save failed: ' . $exception->getMessage());
            http_response_code(500);
            $errors['form'] = 'The faculty record could not be saved. Please try again.';
        }
    }
}
$saved = ($_SESSION['di_faculty_flash'] ?? 0) === $facultyId;
unset($_SESSION['di_faculty_flash']);
$statuses = synk_faculty_record_statuses();
$profile = $record['profile'];
$profileFields = synk_faculty_profile_fields();
$photoPath = $record['user_id'] > 0 ? __DIR__ . '/../uploads/profile/profile_' . $record['user_id'] . '.jpg' : '';
$photoUrl = $photoPath && is_file($photoPath) ? '../uploads/profile/profile_' . $record['user_id'] . '.jpg?v=' . substr(hash_file('sha256', $photoPath), 0, 12) : '';
$fieldGroups = ['Personal details' => ['full_name', 'faculty_id_number', 'gender', 'date_of_birth', 'civil_status', 'campus_name', 'college_name'],
    'Employment and credentials' => ['faculty_rank', 'salary_grade', 'employment_classification', 'service_start_date', 'length_of_service', 'eligibility']];
foreach (['bachelor' => "Bachelor's education", 'master' => "Master's education", 'doctoral' => 'Doctoral education'] as $level => $label) {
    $fieldGroups[$label] = array_map(static function ($suffix) use ($level) { return $level . '_' . $suffix; }, ['status', 'degree', 'institution', 'address', 'specialization']);
}
$credentials = [];
if ($profile && synk_table_exists($conn, 'tbl_faculty_profile_eligibilities') && synk_table_exists($conn, 'tbl_faculty_eligibilities')) {
    $stmt = $conn->prepare('SELECT e.code, e.name FROM tbl_faculty_profile_eligibilities pe JOIN tbl_faculty_eligibilities e ON e.eligibility_id = pe.eligibility_id WHERE pe.user_id = ? ORDER BY e.code');
    $stmt->bind_param('i', $record['user_id']);
    $stmt->execute();
    $credentials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
include __DIR__ . '/page-start.php';
?>
<div class="di-directory-heading"><div><span class="di-eyebrow">DI / List of Professors</span><h1>Review faculty</h1><p>Review the saved profile and maintain the faculty record.</p></div><a class="btn btn-outline-secondary" href="professors.php"><i class="bx bx-arrow-back me-1"></i>Back to list</a></div>
<?php if ($saved): ?><div class="alert alert-success" role="status">Faculty status, affiliation, and remarks saved.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger di-form-errors" role="alert"><strong>Please check the review form.</strong><ul class="mb-0 mt-2"><?php foreach ($errors as $error): ?><li><?= synk_leadership_h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="di-review-grid">
  <div>
    <section class="card mb-4"><div class="card-body di-review-identity">
      <?php if ($photoUrl): ?><img class="di-review-portrait" src="<?= synk_leadership_h($photoUrl) ?>" alt="Profile image of <?= synk_leadership_h($record['name']) ?>"><?php else: ?><div class="di-review-portrait" aria-hidden="true"><i class="bx bx-user"></i></div><?php endif; ?>
      <h2><?= synk_leadership_h($record['name']) ?></h2><p class="di-email text-muted"><?= synk_leadership_h($record['account']['email'] ?? 'No linked professor account') ?></p>
      <span class="badge bg-label-<?= synk_faculty_record_status_color((string)$record['status']) ?>"><?= $statuses[$record['status']] ?? 'Not recorded' ?></span>
      <hr><dl class="di-review-details text-start"><div><dt>Faculty ID</dt><dd><?= synk_leadership_h($record['faculty_id_number'] ?: 'Not entered') ?></dd></div><div><dt>Designation</dt><dd><?= synk_leadership_h($record['designation_name'] ?: 'Not recorded') ?></dd></div><div><dt>College</dt><dd><?= synk_leadership_h($record['college_name']) ?></dd></div><div><dt>Campus</dt><dd><?= synk_leadership_h($record['campus_name']) ?></dd></div></dl>
      <p class="di-record-notice text-start mt-3 mb-0">Connection source: <?= synk_leadership_h($record['connection_source']) ?>.</p>
    </div></section>
    <section class="card di-review-section"><div class="card-body"><h2>Faculty record review</h2><p class="di-record-notice">Record the faculty's current status and home affiliation. These details apply across academic terms. Scheduling uses active faculty records.</p><p class="di-record-notice">Choose a home campus and college when the affiliation is verified. Leaving both unrecorded keeps the existing profile or roster connections.</p>
      <form method="post" class="di-review-form">
        <input type="hidden" name="csrf_token" value="<?= synk_leadership_h($_SESSION['di_faculty_csrf']) ?>"><input type="hidden" name="revision" value="<?= (int)$record['record_revision'] ?>">
        <div class="mb-3"><label for="review-status">Faculty status</label><select class="form-select" id="review-status" name="status" required><?php foreach ($statuses as $value => $label): ?><option value="<?= $value ?>"<?= $formValues['status'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label for="review-campus">Home campus</label><select class="form-select" id="review-campus" name="campus_id" data-campus-select data-require-campus="true"><option value="0">Not recorded</option><?php foreach ($directory['campuses'] as $id => $campus): ?><option value="<?= $id ?>"<?= $formValues['campus_id'] === $id ? ' selected' : '' ?>><?= synk_leadership_h($campus['campus_name']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label for="review-college">Home college</label><select class="form-select" id="review-college" name="college_id" data-college-select><option value="0">Not recorded</option><?php foreach ($directory['colleges'] as $id => $college): ?><option value="<?= $id ?>" data-campus="<?= (int)$college['campus_id'] ?>"<?= $formValues['college_id'] === $id ? ' selected' : '' ?>><?= synk_leadership_h($college['college_code'] . ' - ' . $college['college_name']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label for="review-remarks">Remarks</label><textarea class="form-control" id="review-remarks" name="remarks" maxlength="2000" placeholder="Reason for status, effective date, or other review notes"><?= synk_leadership_h($formValues['remarks']) ?></textarea><small class="text-muted">Required for Other. Up to 2,000 characters.</small></div>
        <?php if ($record['record_updated_at']): ?><p class="di-record-notice">Last reviewed: <?= synk_leadership_h($record['record_updated_at']) ?></p><?php endif; ?>
        <button type="submit" class="btn btn-primary w-100">Save faculty review</button>
      </form>
    </div></section>
  </div>
  <div>
    <?php if (!$profile): ?><section class="card"><div class="card-body"><h2 class="di-section-heading mb-3">Professor profile</h2><p class="text-muted mb-0"><?= $record['account'] ? 'This professor has not saved a profile yet.' : 'This faculty record does not have a linked professor account. The administrator can link the account when it is available.' ?></p></div></section><?php else: ?>
      <div class="alert alert-info di-record-notice">Personal and education details reflect the professor's latest saved profile. Last saved: <?= synk_leadership_h($profile['updated_at']) ?>.</div>
      <?php foreach ($fieldGroups as $label => $keys): ?><section class="card mb-4 di-review-section"><div class="card-body"><h2 class="mb-4"><?= synk_leadership_h($label) ?></h2><dl class="di-review-details">
        <?php foreach ($keys as $key): ?><div><dt><?= synk_leadership_h($profileFields[$key]['label']) ?></dt><dd><?= synk_leadership_h(trim((string)($profile[$key] ?? '')) ?: 'Not recorded') ?></dd></div><?php endforeach; ?>
        <?php if ($label === 'Employment and credentials'): ?><div><dt>Selected eligibility</dt><dd><?php if (!$credentials): ?>Not recorded<?php else: ?><?php foreach ($credentials as $credential): ?><span class="d-block"><?= synk_leadership_h($credential['code'] . ' - ' . $credential['name']) ?></span><?php endforeach; ?><?php endif; ?></dd></div><?php endif; ?>
      </dl></div></section><?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/page-end.php'; ?>
