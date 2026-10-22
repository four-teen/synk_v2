<?php
session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/college_dean_profile_helper.php';
require_once __DIR__ . '/../backend/faculty_profile_form_helper.php';
synk_college_dean_require_login($conn);
$scope = synk_college_dean_dashboard_require_scope($conn, [], true);
$collegeDeanPortalContext = synk_college_dean_portal_context($conn);
$term = synk_dean_view_term($conn);
header('Cache-Control: no-store, private');
$loadError = '';
$faculties = [];
try {
    synk_dean_profile_prepare_storage($conn);
    $faculties = synk_dean_profile_directory($conn, $scope, $term);
} catch (Throwable $exception) {
    error_log('College Dean faculty profiles: ' . $exception->getMessage());
    http_response_code(503);
    $loadError = 'Faculty profiles could not be loaded. Please try again.';
}
$groups = ['dean' => ['title' => 'College Dean', 'copy' => 'College leadership'],
    'chair' => ['title' => 'Program Chairs', 'copy' => 'Program leadership'],
    'faculty' => ['title' => 'Faculty members', 'copy' => 'Teaching and academic staff']];
$statuses = ['active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Inactive', 'retired' => 'Retired', 'resigned' => 'Resigned', 'other' => 'Other'];
$activeCount = 0;
$completeCount = 0;
foreach ($faculties as $faculty) {
    if (in_array($faculty['status'], ['active','on_leave'], true)) {
        $activeCount++;
        if ($faculty['profile_state'] === 'complete') $completeCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Faculty profile | College Dean | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css">
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css">
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="custom_css.css?v=<?= filemtime(__DIR__ . '/custom_css.css'); ?>">
  <link rel="stylesheet" href="faculty-profile.css?v=<?= filemtime(__DIR__ . '/faculty-profile.css') ?>">
  <script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script>
</head>
<body>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="layout-page">
    <?php include __DIR__ . '/navbar.php'; ?>
    <div class="content-wrapper"><main class="container-xxl flex-grow-1 container-p-y dean-profile-directory" id="dean-profile-directory">
      <header class="dean-profiles-heading">
        <div><span class="dean-profiles-eyebrow">COLLEGE DEAN / PEOPLE</span><h1>Faculty profile</h1>
          <p><?= synk_faculty_profile_h($scope['college_name']) ?><span aria-hidden="true"> · </span><?= synk_faculty_profile_h($scope['campus_name']) ?></p></div>
        <span class="dean-profiles-term"><i class="bx bx-calendar" aria-hidden="true"></i><?= synk_faculty_profile_h($term['term_text']) ?></span>
      </header>
      <?php if ($loadError): ?><div class="alert alert-danger" role="alert"><?= synk_faculty_profile_h($loadError) ?></div><?php endif; ?>
      <div class="dean-profiles-intro"><div><h2>Your college, at a glance</h2><p>Leadership reflects appointments in this college for the selected term. Shared teachers appear as faculty members; profiles from another home college are shown for reference.</p></div>
        <div class="dean-profiles-stats"><span><strong><?= $activeCount ?></strong>Current faculty</span><span><strong><?= $completeCount ?></strong>Profiles complete</span><span><strong><?= $activeCount - $completeCount ?></strong>Need attention</span></div>
      </div>
      <section class="card dean-profiles-toolbar" aria-label="Find faculty profiles">
        <div class="dean-profiles-search"><label for="dean-profile-search">Find a faculty member</label><div><i class="bx bx-search" aria-hidden="true"></i><input type="search" id="dean-profile-search" class="form-control" placeholder="Search name, faculty ID, or designation" maxlength="150" autocomplete="off"></div></div>
        <div><label for="dean-profile-designation">Designation</label><select id="dean-profile-designation" class="form-select"><option value="all">All designations</option><option value="dean">College Dean</option><option value="chair">Program Chairs</option><option value="faculty">Faculty members</option></select></div>
        <div><label for="dean-profile-completion">Profile</label><select id="dean-profile-completion" class="form-select"><option value="all">All profiles</option><option value="needs_attention">Needs attention</option><option value="not_started">Not started</option><option value="in_progress">In progress</option><option value="complete">Complete</option></select></div>
        <div><label for="dean-profile-status">Faculty status</label><select id="dean-profile-status" class="form-select"><option value="current">Active / on leave</option><option value="all">All statuses</option><?php foreach ($statuses as $key=>$label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select></div>
        <button type="button" class="btn btn-outline-secondary" id="dean-profile-reset" aria-label="Clear faculty filters"><i class="bx bx-reset" aria-hidden="true"></i></button>
      </section>
      <p class="dean-profiles-result" id="dean-profile-result" role="status" aria-live="polite">Select an available profile to manage its details.</p>
      <div class="dean-profiles-hierarchy">
      <?php foreach ($groups as $key=>$group): $groupRows = array_values(array_filter($faculties, static function(array $row) use($key): bool { return $row['designation_group'] === $key; })); ?>
        <section class="dean-profiles-section is-<?= $key ?>" data-profile-section="<?= $key ?>" aria-labelledby="dean-profiles-<?= $key ?>">
          <div class="dean-profiles-section-heading"><div><span><?= $group['copy'] ?></span><h2 id="dean-profiles-<?= $key ?>"><?= $group['title'] ?></h2></div><span class="dean-profiles-group-count" data-profile-count><?= count($groupRows) ?></span></div>
          <div class="dean-profiles-grid">
          <?php foreach ($groupRows as $faculty): $current = in_array($faculty['status'], ['active','on_leave'], true); $canManage = $faculty['can_manage_profile']; $searchText = implode(' ',[$faculty['display_name'],$faculty['faculty_name'],$faculty['designation_name'],$faculty['faculty_id_number']]); ?>
            <<?= $canManage ? 'a href="manage-faculty-profile.php?faculty_id=' . $faculty['faculty_id'] . '"' : 'article' ?> class="dean-faculty-card<?= $canManage ? '' : ' is-reference' ?>"
              data-faculty-card data-designation="<?= $key ?>" data-profile="<?= $faculty['profile_state'] ?>" data-status="<?= synk_faculty_profile_h($faculty['status'] ?? '') ?>"
              data-search="<?= synk_faculty_profile_h($searchText) ?>" aria-label="<?= $canManage ? 'Manage profile for ' : 'Faculty profile for ' ?><?= synk_faculty_profile_h($faculty['display_name']) ?>"<?= !$current ? ' hidden' : '' ?>>
              <img src="<?= synk_faculty_profile_h($faculty['profile_image_url']) ?>" alt="" width="112" height="112" loading="lazy" class="dean-faculty-photo" data-placeholder="faculty-placeholder.svg">
              <span class="dean-faculty-role is-<?= $key ?>"><?= $key === 'dean' ? 'College Dean' : ($key === 'chair' ? 'Program Chair' : 'Faculty') ?></span>
              <h3><?= synk_faculty_profile_h($faculty['display_name']) ?></h3>
              <p class="dean-faculty-designation" title="<?= synk_faculty_profile_h($faculty['designation_name']) ?>"><?= synk_faculty_profile_h($faculty['designation_name'] ?: 'Faculty member') ?></p>
              <?php if (!$current || $faculty['status'] === 'on_leave'): ?><span class="badge bg-label-secondary"><?= $statuses[$faculty['status']] ?? 'Not recorded' ?></span><?php endif; ?>
              <div class="dean-faculty-progress"><span><?= $faculty['profile_state'] === 'not_started' ? 'Profile not started' : $faculty['completion'] . '% complete' ?></span><div aria-hidden="true"><span style="width:<?= $faculty['completion'] ?>%"></span></div></div>
              <span class="dean-faculty-action"><?php if ($canManage): ?><?= $faculty['profile_state'] === 'not_started' ? 'Create profile' : 'Manage profile' ?><i class="bx bx-arrow-right" aria-hidden="true"></i><?php else: ?>Managed by home college<?php endif; ?></span>
            </<?= $canManage ? 'a' : 'article' ?>>
          <?php endforeach; ?>
          </div>
          <?php if (!$groupRows): ?><p class="dean-profiles-section-empty"><?= $key === 'dean' ? 'No College Dean appointment is recorded for this college and term.' : ($key === 'chair' ? 'No Program Chair appointment is recorded for this college and term.' : 'No faculty members are recorded in this group.') ?></p><?php endif; ?>
        </section>
      <?php endforeach; ?>
      </div>
      <div class="card dean-profiles-empty" id="dean-profiles-empty" hidden><i class="bx bx-search-alt" aria-hidden="true"></i><h2>No faculty profiles found</h2><p>Try a different name or clear your filters.</p><button type="button" class="btn btn-outline-primary" id="dean-profile-clear">Clear filters</button></div>
    </main><?php include __DIR__ . '/../footer.php'; ?></div>
  </div>
</div><div class="layout-overlay layout-menu-toggle"></div></div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script>
<script src="faculty-profile.js?v=<?= filemtime(__DIR__ . '/faculty-profile.js') ?>"></script>
</body></html>
