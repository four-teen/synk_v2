<?php
require_once __DIR__ . '/faculty_profile_form_helper.php';
$profileEditor = array_merge([
    'theme_path' => dirname(__DIR__) . '/professor/custom_css.css',
    'sidebar_path' => dirname(__DIR__) . '/professor/sidebar.php',
    'navbar_path' => dirname(__DIR__) . '/professor/navbar.php',
    'eyebrow' => 'PROFESSOR PORTAL / MANAGE PROFILE',
    'title' => 'My faculty profile',
    'subtitle' => 'Keep your personal, employment, and academic qualifications in one place.',
    'back_url' => 'index.php', 'back_label' => 'Dashboard',
    'account_note' => 'This profile belongs to your signed-in account.',
    'show_link_notice' => empty($portal['faculty_is_linked']),
    'action' => 'manage-profile.php', 'education_url' => 'education-options.php',
    'csrf_token' => (string)($_SESSION['faculty_profile_csrf'] ?? ''), 'hidden_fields' => [],
], $profileEditor ?? []);
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
  <link rel="stylesheet" href="custom_css.css?v=<?php echo filemtime($profileEditor['theme_path']); ?>">
  <link rel="stylesheet" href="../professor/faculty-profile.css?v=<?php echo filemtime(dirname(__DIR__) . '/professor/faculty-profile.css'); ?>">
  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
</head>
<body>
  <div class="layout-wrapper layout-content-navbar">
    <div class="layout-container">
      <?php include $profileEditor['sidebar_path']; ?>
      <div class="layout-page">
        <?php include $profileEditor['navbar_path']; ?>
        <div class="content-wrapper">
          <main class="container-xxl flex-grow-1 container-p-y faculty-profile-page">
            <div class="profile-page-heading">
              <div>
                <div class="profile-eyebrow"><?php echo synk_faculty_profile_h($profileEditor['eyebrow']); ?></div>
                <h1><?php echo synk_faculty_profile_h($profileEditor['title']); ?></h1>
                <p><?php echo synk_faculty_profile_h($profileEditor['subtitle']); ?></p>
              </div>
              <a href="<?php echo synk_faculty_profile_h($profileEditor['back_url']); ?>" class="btn btn-outline-secondary"><i class="bx bx-arrow-back me-2" aria-hidden="true"></i><?php echo synk_faculty_profile_h($profileEditor['back_label']); ?></a>
            </div>

            <?php if ($savedNotice !== ''): ?>
              <div class="alert alert-success" role="status"><?php echo synk_faculty_profile_h($savedNotice); ?></div>
            <?php endif; ?>
            <?php if ($saveError !== '' || $errors): ?>
              <div class="alert alert-danger" role="alert" tabindex="-1" id="profile-errors">
                <?php if ($saveError !== ''): ?><p class="mb-2"><?php echo synk_faculty_profile_h($saveError); ?></p><?php endif; ?>
                <?php if ($errors): ?>
                  <strong>Please review the following fields:</strong>
                  <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $key => $error): ?>
                      <li><a href="#<?php echo synk_faculty_profile_h($key); ?>"><?php echo synk_faculty_profile_h($error); ?></a></li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <section class="card profile-completion<?php echo $completion['percent'] === 100 ? ' is-complete' : ''; ?>" id="profile-completion" aria-labelledby="profile-completion-title" data-completion-rules="<?php echo synk_faculty_profile_h(json_encode($completionRules, JSON_UNESCAPED_UNICODE)); ?>">
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
                    <li data-completion-item="<?php echo $key; ?>"<?php echo !in_array($key, $completion['missing'], true) ? ' hidden' : ''; ?>><a href="#<?php echo $key; ?>" data-completion-target="<?php echo $key; ?>"><?php echo synk_faculty_profile_h($rule['label']); ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </details>
              <p class="profile-completion-note">Choose <strong>Not applicable</strong> for education you have not pursued. Optional credentials and notes do not affect the percentage. Progress updates as you edit; save your profile to keep your changes.</p>
            </section>

            <div class="profile-workspace">
              <aside class="profile-overview" aria-label="Profile overview">
                <div class="card profile-summary">
                  <div class="profile-monogram" id="profile-summary-image" aria-hidden="true">
                    <img<?php echo $profileImageUrl !== '' ? ' src="' . synk_faculty_profile_h($profileImageUrl) . '"' : ' hidden'; ?> alt="">
                    <span<?php echo $profileImageUrl !== '' ? ' hidden' : ''; ?>><?php echo synk_faculty_profile_h(strtoupper($initials ?: 'FP')); ?></span>
                  </div>
                  <span class="profile-eyebrow">FACULTY PROFILE</span>
                  <h2><?php echo synk_faculty_profile_h($profile['full_name']); ?></h2>
                  <p class="profile-account-email"><?php echo synk_faculty_profile_h($account['email']); ?></p>
                  <span class="badge <?php echo $storedProfile ? 'bg-label-success' : 'bg-label-warning'; ?>"><?php echo $storedProfile ? 'Profile saved' : 'Ready to complete'; ?></span>
                  <dl class="profile-account-details">
                    <div><dt>Faculty ID</dt><dd><?php echo (string)($profile['faculty_id_number'] ?? '') !== '' ? synk_faculty_profile_h($profile['faculty_id_number']) : 'Not entered'; ?></dd></div>
                    <div><dt>Last saved</dt><dd><?php echo !empty($profile['updated_at']) ? synk_faculty_profile_h(date('M j, Y, g:i A', strtotime($profile['updated_at']))) : 'Not saved yet'; ?></dd></div>
                  </dl>
                  <p class="profile-account-note"><?php echo synk_faculty_profile_h($profileEditor['account_note']); ?></p>
                </div>
                <nav class="card profile-section-nav" aria-label="Profile sections">
                  <a href="#personal"><span>01</span>Personal details<i class="bx bx-chevron-right" aria-hidden="true"></i></a>
                  <a href="#employment"><span>02</span>Employment<i class="bx bx-chevron-right" aria-hidden="true"></i></a>
                  <a href="#education"><span>03</span>Educational attainment<i class="bx bx-chevron-right" aria-hidden="true"></i></a>
                </nav>
                <?php if ($profileEditor['show_link_notice']): ?>
                  <div class="alert alert-info mt-3">You can save your profile now. Ask your administrator to link your faculty record for workload access.</div>
                <?php endif; ?>
              </aside>

              <form method="post" action="<?php echo synk_faculty_profile_h($profileEditor['action']); ?>" enctype="multipart/form-data" id="faculty-profile-form" class="profile-form" data-education-url="<?php echo synk_faculty_profile_h($profileEditor['education_url']); ?>" data-today="<?php echo $today->format('Y-m-d'); ?>" data-has-errors="<?php echo ($errors || $saveError !== '') ? '1' : '0'; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo synk_faculty_profile_h($profileEditor['csrf_token']); ?>">
                <input type="hidden" name="revision" value="<?php echo (int)$profile['revision']; ?>">
                <?php foreach ($profileEditor['hidden_fields'] as $hiddenName => $hiddenValue): ?>
                  <input type="hidden" name="<?= synk_faculty_profile_h($hiddenName) ?>" value="<?= synk_faculty_profile_h($hiddenValue) ?>">
                <?php endforeach; ?>

                <section class="card profile-section" id="personal" aria-labelledby="personal-title">
                  <div class="profile-section-heading"><span class="profile-section-icon"><i class="bx bx-user" aria-hidden="true"></i></span><div><h2 id="personal-title">Personal details</h2><p>Name and personal information. Fields marked * are required.</p></div></div>
                  <div class="row g-4">
                    <div class="col-12">
                      <div class="profile-photo-editor">
                        <div class="profile-photo-preview" aria-hidden="true">
                          <img id="profile-image-preview"<?php echo $profileImageUrl !== '' ? ' src="' . synk_faculty_profile_h($profileImageUrl) . '"' : ' hidden'; ?> alt="">
                          <span id="profile-image-initials"<?php echo $profileImageUrl !== '' ? ' hidden' : ''; ?>><?php echo synk_faculty_profile_h(strtoupper($initials ?: 'FP')); ?></span>
                        </div>
                        <div class="profile-photo-control">
                          <label class="form-label" for="profile_image">Profile image</label>
                          <input type="file" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/webp" class="form-control<?php echo isset($errors['profile_image']) ? ' is-invalid' : ''; ?>" aria-describedby="profile-image-help profile-image-selection<?php echo isset($errors['profile_image']) ? ' profile-image-error' : ''; ?>"<?php echo isset($errors['profile_image']) ? ' aria-invalid="true"' : ''; ?>>
                          <?php if (isset($errors['profile_image'])): ?><div class="invalid-feedback" id="profile-image-error"><?php echo synk_faculty_profile_h($errors['profile_image']); ?></div><?php endif; ?>
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
                    <div class="col-md-6"><label class="form-label" for="profile-age">Age <span class="profile-auto-label">AUTOMATIC</span></label><input class="form-control" id="profile-age" type="text" readonly value="<?php echo synk_faculty_profile_h($age); ?>" placeholder="Calculated from date of birth"><div class="form-text">Based on today's date.</div></div>
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
                            <optgroup label="<?php echo synk_faculty_profile_h($eligibilityGroup); ?>">
                          <?php endif; ?>
                          <option value="<?php echo (int)$option['eligibility_id']; ?>" data-search-terms="<?php echo synk_faculty_profile_h($option['search_terms'] . ' ' . $option['issuing_body']); ?>"<?php echo in_array($option['eligibility_id'], $profile['eligibility_ids'] ?? [], true) ? ' selected' : ''; ?>><?php echo synk_faculty_profile_h($option['name']); ?></option>
                        <?php endforeach; if ($eligibilityGroup !== null): ?></optgroup><?php endif; ?>
                      </select>
                      <?php if (isset($errors['eligibility_ids'])): ?><div class="invalid-feedback d-block" id="eligibility-error"><?php echo synk_faculty_profile_h($errors['eligibility_ids']); ?></div><?php endif; ?>
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
                      <legend><span class="profile-degree-dot <?php echo $level; ?>"></span><?php echo synk_faculty_profile_h($label); ?></legend>
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
  <script src="../professor/faculty-profile.js?v=<?php echo filemtime(dirname(__DIR__) . '/professor/faculty-profile.js'); ?>"></script>
</body>
</html>
