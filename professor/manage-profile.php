<?php
session_start();
require_once __DIR__ . '/../backend/professor_portal_helper.php';
require_once __DIR__ . '/../backend/faculty_profile_helper.php';
require_once __DIR__ . '/../backend/faculty_profile_image_helper.php';
synk_professor_require_login();

header('Cache-Control: no-store, private');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../backend/db.php';

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

if (empty($_SESSION['faculty_profile_csrf'])) {
    $_SESSION['faculty_profile_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $savedNotice = '';
    [$submitted, $errors] = synk_faculty_profile_validate($_POST, $today, $ranks, $storedProfile ?? [], $eligibilities, $locations);
    $profile = array_merge($profile, $submitted);
    $token = $_POST['csrf_token'] ?? null;
    $revision = $_POST['revision'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['faculty_profile_csrf'], $token)) {
        http_response_code(403);
        $saveError = 'Your form session expired. Reload this page before saving again.';
    } elseif (!is_string($revision) || !ctype_digit($revision) || strlen($revision) > 10) {
        http_response_code(422);
        $saveError = 'The profile version is invalid. Reload this page before saving again.';
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
                $stagedImage = synk_faculty_profile_stage_image($upload, $userId);
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
                // Account identity and the linked faculty record ID stay session-owned.
                if (synk_faculty_profile_save($conn, $userId, $submitted, (int)$revision)) {
                    if ($stagedImage !== null) {
                        $imageReplacement = synk_faculty_profile_publish_image($stagedImage, $userId);
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
                error_log('Faculty profile save failed: ' . $exception->getMessage());
                http_response_code(500);
                $saveError = 'Your profile could not be saved. Your entries are still shown below; please try again.';
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
$profileImageUrl = synk_faculty_profile_image_url($userId);
foreach (array_slice(preg_split('/\s+/', trim($profile['full_name'])) ?: [], 0, 2) as $namePart) {
    $initials .= function_exists('mb_substr') ? mb_substr($namePart, 0, 1, 'UTF-8') : substr($namePart, 0, 1);
}

function faculty_profile_input(string $key, array $fields, array $profile, array $errors, string $today, string $help = ''): void
{
    $field = $fields[$key];
    $value = (string)($profile[$key] ?? '');
    if (in_array($key, ['campus_name', 'college_name'], true)) {
        $value = trim($value);
    }
    $error = $errors[$key] ?? '';
    $inputClass = isset($field['options']) ? 'form-select' : 'form-control';
    $attributes = ' id="' . $key . '" name="' . $key . '" class="' . $inputClass . ($error !== '' ? ' is-invalid' : '') . '"';
    if (isset($field['education_type'])) {
        $attributes .= ' data-education-type="' . $field['education_type'] . '" data-field-label="' . synk_professor_h($field['label']) . '"';
        $help = 'Select a saved entry or type a new one and press Enter.';
    }
    if (!empty($field['required'])) {
        $attributes .= ' required';
    }
    if (!empty($field['readonly'])) {
        $attributes .= ' readonly';
    }
    if (isset($field['max']) && !isset($field['options'])) {
        $attributes .= ' maxlength="' . $field['max'] . '"';
    }
    if (isset($field['placeholder'])) {
        $attributes .= ' placeholder="' . synk_professor_h($field['placeholder']) . '"';
    }
    $describedBy = [];
    if ($help !== '') {
        $describedBy[] = $key . '-help';
    }
    if ($error !== '') {
        $describedBy[] = $key . '-error';
        $attributes .= ' aria-invalid="true"';
    }
    if ($describedBy) {
        $attributes .= ' aria-describedby="' . implode(' ', $describedBy) . '"';
    }
    echo '<label class="form-label" for="' . $key . '">' . synk_professor_h($field['label']);
    if (!empty($field['required'])) {
        echo ' <span class="text-danger" aria-hidden="true">*</span>';
    }
    echo '</label>';
    if (isset($field['options'])) {
        echo '<select' . $attributes . '><option value="">Select ' . synk_professor_h(strtolower($field['label'])) . '</option>';
        if (in_array($key, ['campus_name', 'college_name'], true) && $value !== '' && !in_array($value, $field['options'], true)) {
            echo '<option value="' . synk_professor_h($value) . '" selected>' . synk_professor_h($value . ' (select a current option)') . '</option>';
        }
        foreach ($field['options'] as $option) {
            $rank = $field['rank_options'][$option] ?? null;
            $optionAttributes = $rank ? ' data-salary-grade="' . synk_professor_h($rank['salary_label']) . '"' : '';
            if (isset($field['campus_options'])) {
                $optionAttributes .= ' data-campuses="' . synk_professor_h(json_encode($field['campus_options'][$option], JSON_UNESCAPED_UNICODE)) . '"';
            }
            $optionLabel = $rank ? $option . ' — ' . (!empty($rank['legacy']) ? 'Previously saved' : $rank['salary_label']) : $option;
            echo '<option value="' . synk_professor_h($option) . '"' . ($value === $option ? ' selected' : '') . $optionAttributes . '>' . synk_professor_h($optionLabel) . '</option>';
        }
        echo '</select>';
    } elseif (!empty($field['textarea'])) {
        echo '<textarea' . $attributes . ' rows="3">' . synk_professor_h($value) . '</textarea>';
    } else {
        $type = !empty($field['date']) ? 'date' : 'text';
        if ($type === 'date') {
            $attributes .= ' min="1900-01-01" max="' . $today . '"';
        }
        if ($key === 'length_of_service' && !empty($profile['service_start_date'])) {
            $attributes .= ' readonly';
        }
        echo '<input type="' . $type . '"' . $attributes . ' value="' . synk_professor_h($value) . '">';
    }
    if ($error !== '') {
        echo '<div class="invalid-feedback" id="' . $key . '-error">' . synk_professor_h($error) . '</div>';
    }
    if ($help !== '') {
        echo '<div class="form-text" id="' . $key . '-help">' . synk_professor_h($help) . '</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage Faculty Profile | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css">
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css">
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
  <link rel="stylesheet" href="custom_css.css?v=<?php echo filemtime(__DIR__ . '/custom_css.css'); ?>">
  <link rel="stylesheet" href="faculty-profile.css?v=<?php echo filemtime(__DIR__ . '/faculty-profile.css'); ?>">
  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
</head>
<body>
  <div class="layout-wrapper layout-content-navbar">
    <div class="layout-container">
      <?php include __DIR__ . '/sidebar.php'; ?>
      <div class="layout-page">
        <?php include __DIR__ . '/navbar.php'; ?>
        <div class="content-wrapper">
          <main class="container-xxl flex-grow-1 container-p-y faculty-profile-page">
            <div class="profile-page-heading">
              <div>
                <div class="profile-eyebrow">PROFESSOR PORTAL / MANAGE PROFILE</div>
                <h1>My faculty profile</h1>
                <p>Keep your personal, employment, and academic qualifications in one place.</p>
              </div>
              <a href="index.php" class="btn btn-outline-secondary"><i class="bx bx-arrow-back me-2" aria-hidden="true"></i>Dashboard</a>
            </div>

            <?php if ($savedNotice !== ''): ?>
              <div class="alert alert-success" role="status"><?php echo synk_professor_h($savedNotice); ?></div>
            <?php endif; ?>
            <?php if ($saveError !== '' || $errors): ?>
              <div class="alert alert-danger" role="alert" tabindex="-1" id="profile-errors">
                <?php if ($saveError !== ''): ?><p class="mb-2"><?php echo synk_professor_h($saveError); ?></p><?php endif; ?>
                <?php if ($errors): ?>
                  <strong>Please review the following fields:</strong>
                  <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $key => $error): ?>
                      <li><a href="#<?php echo synk_professor_h($key); ?>"><?php echo synk_professor_h($error); ?></a></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <section class="card profile-completion<?php echo $completion['percent'] === 100 ? ' is-complete' : ''; ?>" id="profile-completion" aria-labelledby="profile-completion-title" data-completion-rules="<?php echo synk_professor_h(json_encode($completionRules, JSON_UNESCAPED_UNICODE)); ?>">
              <div class="profile-completion-heading">
                <div>
                  <h2 id="profile-completion-title">Profile completion</h2>
                  <p id="profile-completion-message" role="status" aria-live="polite"><?php echo $completion['percent'] === 100 ? 'All applicable details are filled in.' : 'Your profile needs attention. Complete the missing details below.'; ?></p>
                </div>
                <div class="profile-completion-score"><strong id="profile-completion-percent"><?php echo $completion['percent']; ?>%</strong><span id="profile-completion-label"><?php echo $completion['percent'] === 100 ? 'Details complete' : 'Needs attention'; ?></span></div>
              </div>
              <div class="profile-completion-track" id="profile-completion-progress" role="progressbar" aria-label="Profile completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $completion['percent']; ?>" aria-describedby="profile-completion-count">
                <div id="profile-completion-fill" style="width: <?php echo $completion['percent']; ?>%"></div>
              </div>
              <p class="profile-completion-count" id="profile-completion-count"><?php echo $completion['completed'] . ' of ' . $completion['total']; ?> applicable details complete.</p>
              <div class="profile-completion-sections">
                <?php foreach ($completionSections as $section => $label): $sectionCompletion = $completion['sections'][$section]; ?>
                  <a href="#<?php echo $section; ?>"><span><?php echo $label; ?></span><strong id="profile-completion-<?php echo $section; ?>"><?php echo $sectionCompletion['completed'] . '/' . $sectionCompletion['total']; ?></strong></a>
                <?php endforeach; ?>
              </div>
              <details class="profile-completion-missing" id="profile-completion-missing"<?php echo !$completion['missing'] ? ' hidden' : ''; ?>>
                <summary>View details needing attention <span id="profile-completion-remaining">(<?php echo count($completion['missing']); ?>)</span></summary>
                <ul>
                  <?php foreach ($completionRules as $key => $rule): ?>
                    <li data-completion-item="<?php echo $key; ?>"<?php echo !in_array($key, $completion['missing'], true) ? ' hidden' : ''; ?>><a href="#<?php echo $key; ?>" data-completion-target="<?php echo $key; ?>"><?php echo synk_professor_h($rule['label']); ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </details>
              <p class="profile-completion-note">Choose <strong>Not applicable</strong> for education you have not pursued. Optional credentials and notes do not affect the percentage. Progress updates as you edit; save your profile to keep your changes.</p>
            </section>

            <div class="profile-workspace">
              <aside class="profile-overview" aria-label="Profile overview">
                <div class="card profile-summary">
                  <div class="profile-monogram" id="profile-summary-image" aria-hidden="true">
                    <img<?php echo $profileImageUrl !== '' ? ' src="' . synk_professor_h($profileImageUrl) . '"' : ' hidden'; ?> alt="">
                    <span<?php echo $profileImageUrl !== '' ? ' hidden' : ''; ?>><?php echo synk_professor_h(strtoupper($initials ?: 'FP')); ?></span>
                  </div>
                  <span class="profile-eyebrow">FACULTY PROFILE</span>
                  <h2><?php echo synk_professor_h($profile['full_name']); ?></h2>
                  <p class="profile-account-email"><?php echo synk_professor_h($account['email']); ?></p>
                  <span class="badge <?php echo $storedProfile ? 'bg-label-success' : 'bg-label-warning'; ?>"><?php echo $storedProfile ? 'Profile saved' : 'Ready to complete'; ?></span>
                  <dl class="profile-account-details">
                    <div><dt>Faculty ID</dt><dd><?php echo (string)($profile['faculty_id_number'] ?? '') !== '' ? synk_professor_h($profile['faculty_id_number']) : 'Not entered'; ?></dd></div>
                    <div><dt>Last saved</dt><dd><?php echo !empty($profile['updated_at']) ? synk_professor_h(date('M j, Y, g:i A', strtotime($profile['updated_at']))) : 'Not saved yet'; ?></dd></div>
                  </dl>
                  <p class="profile-account-note">This profile belongs to your signed-in account.</p>
                </div>
                <nav class="card profile-section-nav" aria-label="Profile sections">
                  <a href="#personal"><span>01</span>Personal details<i class="bx bx-chevron-right" aria-hidden="true"></i></a>
                  <a href="#employment"><span>02</span>Employment<i class="bx bx-chevron-right" aria-hidden="true"></i></a>
                  <a href="#education"><span>03</span>Educational attainment<i class="bx bx-chevron-right" aria-hidden="true"></i></a>
                </nav>
                <?php if (!$portal['faculty_is_linked']): ?>
                  <div class="alert alert-info mt-3">You can save your profile now. Ask your administrator to link your faculty record for workload access.</div>
                <?php endif; ?>
              </aside>

              <form method="post" action="manage-profile.php" enctype="multipart/form-data" id="faculty-profile-form" class="profile-form" data-today="<?php echo $today->format('Y-m-d'); ?>" data-has-errors="<?php echo ($errors || $saveError !== '') ? '1' : '0'; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo synk_professor_h($_SESSION['faculty_profile_csrf']); ?>">
                <input type="hidden" name="revision" value="<?php echo (int)$profile['revision']; ?>">

                <section class="card profile-section" id="personal" aria-labelledby="personal-title">
                  <div class="profile-section-heading"><span class="profile-section-icon"><i class="bx bx-user" aria-hidden="true"></i></span><div><h2 id="personal-title">Personal details</h2><p>Your name and personal information. Fields marked * are required.</p></div></div>
                  <div class="row g-4">
                    <div class="col-12">
                      <div class="profile-photo-editor">
                        <div class="profile-photo-preview" aria-hidden="true">
                          <img id="profile-image-preview"<?php echo $profileImageUrl !== '' ? ' src="' . synk_professor_h($profileImageUrl) . '"' : ' hidden'; ?> alt="">
                          <span id="profile-image-initials"<?php echo $profileImageUrl !== '' ? ' hidden' : ''; ?>><?php echo synk_professor_h(strtoupper($initials ?: 'FP')); ?></span>
                        </div>
                        <div class="profile-photo-control">
                          <label class="form-label" for="profile_image">Profile image</label>
                          <input type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/webp" class="form-control<?php echo isset($errors['profile_image']) ? ' is-invalid' : ''; ?>" aria-describedby="profile-image-help profile-image-selection<?php echo isset($errors['profile_image']) ? ' profile-image-error' : ''; ?>"<?php echo isset($errors['profile_image']) ? ' aria-invalid="true"' : ''; ?>>
                          <?php if (isset($errors['profile_image'])): ?><div class="invalid-feedback" id="profile-image-error"><?php echo synk_professor_h($errors['profile_image']); ?></div><?php endif; ?>
                          <div class="form-text" id="profile-image-help">JPG, PNG, or WebP, up to 5 MB. Save your profile to replace the previous image. Only one profile image is kept.</div>
                          <div class="form-text" id="profile-image-selection" role="status" aria-live="polite"><?php echo ($errors || $saveError !== '') ? 'If you selected an image, choose it again before retrying.' : ''; ?></div>
                        </div>
                      </div>
                    </div>
                    <div class="col-12"><?php faculty_profile_input('full_name', $fields, $profile, $errors, $today->format('Y-m-d')); ?></div>
                    <div class="col-12"><?php faculty_profile_input('faculty_id_number', $fields, $profile, $errors, $today->format('Y-m-d'), 'Enter your university-issued ID. Letters, numbers, and hyphens are accepted; leading zeros are kept.'); ?></div>
                    <div class="col-md-6"><?php faculty_profile_input('gender', $fields, $profile, $errors, $today->format('Y-m-d')); ?></div>
                    <div class="col-md-6"><?php faculty_profile_input('civil_status', $fields, $profile, $errors, $today->format('Y-m-d')); ?></div>
                    <div class="col-md-6"><?php faculty_profile_input('date_of_birth', $fields, $profile, $errors, $today->format('Y-m-d')); ?></div>
                    <div class="col-md-6"><label class="form-label" for="profile-age">Age <span class="profile-auto-label">AUTOMATIC</span></label><input class="form-control" id="profile-age" type="text" readonly value="<?php echo synk_professor_h($age); ?>" placeholder="Calculated from date of birth"><div class="form-text">Based on today's date.</div></div>
                    <div class="col-md-6"><?php faculty_profile_input('campus_name', $fields, $profile, $errors, $today->format('Y-m-d')); ?></div>
                    <div class="col-md-6"><?php faculty_profile_input('college_name', $fields, $profile, $errors, $today->format('Y-m-d'), 'Select a campus to see its colleges.'); ?></div>
                  </div>
                </section>

                <section class="card profile-section" id="employment" aria-labelledby="employment-title">
                  <div class="profile-section-heading"><span class="profile-section-icon employment"><i class="bx bx-briefcase-alt" aria-hidden="true"></i></span><div><h2 id="employment-title">Employment</h2><p>Your appointment, eligibility, and service at the university.</p></div></div>
                  <div class="row g-4">
                    <?php foreach (['faculty_rank', 'salary_grade', 'employment_classification'] as $key): ?>
                      <div class="col-md-6"><?php faculty_profile_input($key, $fields, $profile, $errors, $today->format('Y-m-d'), $key === 'salary_grade' ? 'Fixed by your selected faculty rank.' : ($key === 'faculty_rank' ? 'Search by academic rank or salary grade.' : '')); ?></div>
                    <?php endforeach; ?>
                    <div class="col-12">
                      <label class="form-label" for="eligibility_ids">Eligibility / professional licenses</label>
                      <select id="eligibility_ids" name="eligibility_ids[]" class="form-select<?php echo isset($errors['eligibility_ids']) ? ' is-invalid' : ''; ?>" multiple size="6" aria-describedby="eligibility-help<?php echo isset($errors['eligibility_ids']) ? ' eligibility-error' : ''; ?>"<?php echo isset($errors['eligibility_ids']) ? ' aria-invalid="true"' : ''; ?>>
                        <?php $eligibilityGroup = null; foreach ($eligibilities as $option): ?>
                          <?php if ($eligibilityGroup !== $option['category']): ?>
                            <?php if ($eligibilityGroup !== null): ?></optgroup><?php endif; ?>
                            <?php $eligibilityGroup = $option['category']; ?>
                            <optgroup label="<?php echo synk_professor_h($eligibilityGroup); ?>">
                          <?php endif; ?>
                          <option value="<?php echo (int)$option['eligibility_id']; ?>" data-search-terms="<?php echo synk_professor_h($option['search_terms'] . ' ' . $option['issuing_body']); ?>"<?php echo in_array($option['eligibility_id'], $profile['eligibility_ids'] ?? [], true) ? ' selected' : ''; ?>><?php echo synk_professor_h($option['name']); ?></option>
                        <?php endforeach; if ($eligibilityGroup !== null): ?></optgroup><?php endif; ?>
                      </select>
                      <?php if (isset($errors['eligibility_ids'])): ?><div class="invalid-feedback d-block" id="eligibility-error"><?php echo synk_professor_h($errors['eligibility_ids']); ?></div><?php endif; ?>
                      <div class="form-text" id="eligibility-help">Search by credential, profession, or abbreviation (e.g. LET, CPA, CSC, NC II). Select all that apply; remove a selection using its &times; button.</div>
                    </div>
                    <div class="col-12"><?php faculty_profile_input('eligibility', $fields, $profile, $errors, $today->format('Y-m-d'), 'Optional. Existing entries are kept here. Add qualifications that are not in the selection list.'); ?></div>
                    <div class="col-md-6"><?php faculty_profile_input('service_start_date', $fields, $profile, $errors, $today->format('Y-m-d'), 'Optional. Enter a start date to calculate continuous service.'); ?></div>
                    <div class="col-md-6"><?php faculty_profile_input('length_of_service', $fields, $profile, $errors, $today->format('Y-m-d'), 'For credited service or service with breaks, leave the start date empty and enter the total here.'); ?></div>
                  </div>
                </section>

                <section class="card profile-section" id="education" aria-labelledby="education-title">
                  <div class="profile-section-heading"><span class="profile-section-icon education"><i class="bx bxs-graduation" aria-hidden="true"></i></span><div><h2 id="education-title">Educational attainment</h2><p>Search shared entries or add your own. New degrees, schools, school addresses, and specializations become available to other professors when you save your profile.</p></div></div>
                  <?php foreach ($educationLevels as $level => $label): ?>
                    <fieldset class="profile-education-level">
                      <legend><span class="profile-degree-dot <?php echo $level; ?>"></span><?php echo synk_professor_h($label); ?></legend>
                      <div class="row g-4">
                        <div class="col-md-6"><?php faculty_profile_input($level . '_status', $fields, $profile, $errors, $today->format('Y-m-d'), 'Choose Not applicable if you have no studies at this level.'); ?></div>
                        <div class="col-12"><?php faculty_profile_input($level . '_degree', $fields, $profile, $errors, $today->format('Y-m-d')); ?></div>
                        <div class="col-md-6"><?php faculty_profile_input($level . '_institution', $fields, $profile, $errors, $today->format('Y-m-d')); ?></div>
                        <div class="col-md-6"><?php faculty_profile_input($level . '_address', $fields, $profile, $errors, $today->format('Y-m-d')); ?></div>
                        <div class="col-12"><?php faculty_profile_input($level . '_specialization', $fields, $profile, $errors, $today->format('Y-m-d')); ?></div>
                      </div>
                    </fieldset>
                  <?php endforeach; ?>
                </section>

                <div class="profile-save-bar">
                  <div><strong id="profile-save-status" role="status" aria-live="polite"><?php echo $storedProfile ? 'Your profile is up to date' : 'Ready when you are'; ?></strong><small>Save your changes before closing this window.</small></div>
                  <button type="submit" class="btn btn-primary" id="profile-save-button"><i class="bx bx-save me-2" aria-hidden="true"></i>Save profile</button>
                </div>
              </form>
            </div>
          </main>
          <?php include __DIR__ . '/../footer.php'; ?>
        </div>
      </div>
    </div>
    <div class="layout-overlay layout-menu-toggle"></div>
  </div>
  <script src="../assets/vendor/libs/jquery/jquery.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script src="../assets/vendor/libs/popper/popper.js"></script>
  <script src="../assets/vendor/js/bootstrap.js"></script>
  <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
  <script src="../assets/vendor/js/menu.js"></script>
  <script src="../assets/js/main.js"></script>
  <script src="faculty-profile.js?v=<?php echo filemtime(__DIR__ . '/faculty-profile.js'); ?>"></script>
</body>
</html>
