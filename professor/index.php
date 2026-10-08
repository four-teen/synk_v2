<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/professor_portal_helper.php';
require_once '../backend/professor_dashboard_helper.php';

synk_professor_require_login($conn);

function professor_role_label(string $role): string
{
    $role = strtolower(trim($role));
    if ($role === 'admin') {
        return 'Administrator';
    }

    if ($role === 'scheduler') {
        return 'Scheduler';
    }

    if ($role === 'professor') {
        return 'Professor';
    }

    return strtoupper($role);
}

$professorPortalContext = synk_professor_resolve_portal_context($conn);
$facultyId = (int)($professorPortalContext['faculty_id'] ?? 0);
$facultyLink = is_array($professorPortalContext['faculty_link'] ?? null)
    ? $professorPortalContext['faculty_link']
    : null;
$facultyName = trim((string)($professorPortalContext['faculty_name'] ?? 'Professor'));
$accountName = trim((string)($professorPortalContext['account_name'] ?? 'Professor'));
$professorEmail = trim((string)($professorPortalContext['email'] ?? (string)($_SESSION['email'] ?? '')));
$facultyIsLinked = !empty($professorPortalContext['faculty_is_linked']);
$facultyIsActive = !array_key_exists('faculty_is_active', $professorPortalContext)
    || !empty($professorPortalContext['faculty_is_active']);
$currentTerm = synk_fetch_current_academic_term($conn);

$workloadTermOptions = $facultyId > 0
    ? synk_professor_fetch_workload_term_options($conn, $facultyId)
    : [];
$teachingSummary = synk_professor_dashboard_term_summary($workloadTermOptions, $currentTerm);
$dashboardProfile = null;
try {
    $dashboardProfile = synk_professor_dashboard_profile($conn, (int)$_SESSION['user_id'], $facultyLink);
} catch (Throwable $exception) {
    error_log('Professor dashboard profile summary failed: ' . $exception->getMessage());
}
$profileCompletion = $dashboardProfile['completion'] ?? null;
$profileSections = ['personal' => 'Personal details', 'employment' => 'Employment', 'education' => 'Education'];
$dashboardActions = synk_professor_dashboard_actions($dashboardProfile, $facultyIsLinked, $facultyIsActive);
$profileComplete = $profileCompletion !== null && $profileCompletion['percent'] === 100;

$availableRoles = array_values(array_filter(array_map('strval', (array)($_SESSION['available_roles'] ?? []))));
$otherRoles = array_values(array_filter($availableRoles, static function (string $role): bool {
    return strtolower(trim($role)) !== 'professor';
}));
$otherRolesLabel = empty($otherRoles)
    ? 'Professor only'
    : implode(' + ', array_map('professor_role_label', $otherRoles));

$facultyStatusLabel = 'Faculty linked';
$facultyStatusBadgeClass = 'bg-label-success';
if (!$facultyIsLinked) {
    $facultyStatusLabel = 'Needs faculty link';
    $facultyStatusBadgeClass = 'bg-label-warning';
} elseif (!$facultyIsActive) {
    $facultyStatusLabel = 'Inactive faculty record';
    $facultyStatusBadgeClass = 'bg-label-danger';
}

$workloadUrl = $teachingSummary['workload_url'];

$professorPortalDisplayName = $facultyName !== '' ? $facultyName : $accountName;
$professorPortalDisplayEmail = $professorEmail;
$professorPortalFacultyStatusLabel = $facultyStatusLabel;
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />

    <title>Professor Dashboard | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <link rel="stylesheet" href="dashboard.css?v=<?php echo filemtime(__DIR__ . '/dashboard.css'); ?>" />
  </head>

  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include 'sidebar.php'; ?>

        <div class="layout-page">
          <?php include 'navbar.php'; ?>

          <div class="content-wrapper">
            <main class="container-xxl flex-grow-1 container-p-y professor-dashboard-page">
              <div class="card professor-dashboard-hero mb-4">
                <div class="card-body p-4">
                  <div class="row align-items-center g-4">
                    <div class="col-lg-8">
                      <span class="professor-dashboard-kicker">
                        <i class="bx bx-home-circle"></i>
                        Dashboard
                      </span>
                      <h1 class="mt-3 mb-2">Welcome, <?php echo synk_professor_h($facultyName); ?>.</h1>
                      <p class="mb-3 text-muted">
                        Keep your faculty profile up to date and stay on top of your teaching assignments.
                      </p>
                      <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-label-primary"><?php echo synk_professor_h($currentTerm['term_text'] ?? 'Current academic term'); ?></span>
                        <span class="badge <?php echo synk_professor_h($facultyStatusBadgeClass); ?>"><?php echo synk_professor_h($facultyStatusLabel); ?></span>
                        <span class="badge bg-label-info"><?php echo synk_professor_h($otherRolesLabel); ?></span>
                      </div>
                    </div>
                    <div class="col-lg-4 text-center">
                      <img
                        src="../assets/img/illustrations/man-with-laptop-light.png"
                        alt="Professor Dashboard Overview"
                        class="img-fluid"
                        style="max-height: 150px;"
                      />
                    </div>
                  </div>
                </div>
              </div>

              <div class="dashboard-overview">
                <section class="card dashboard-profile<?php echo $profileComplete ? ' is-complete' : ''; ?>" aria-labelledby="dashboard-profile-title">
                  <div class="dashboard-card-heading">
                    <div><span class="dashboard-eyebrow">YOUR FACULTY PROFILE</span><h2 id="dashboard-profile-title">Profile completion</h2></div>
                    <span class="dashboard-status<?php echo $profileComplete ? ' is-success' : ''; ?>"><?php echo $profileCompletion === null ? 'Unavailable' : ($profileComplete ? 'Details complete' : 'Needs attention'); ?></span>
                  </div>
                  <?php if ($profileCompletion !== null): ?>
                    <div class="dashboard-profile-progress">
                      <div class="dashboard-progress-ring" style="--progress: <?php echo $profileCompletion['percent']; ?>%;" role="progressbar" aria-label="Faculty profile completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $profileCompletion['percent']; ?>">
                        <div><strong><?php echo $profileCompletion['percent']; ?>%</strong><span>complete</span></div>
                      </div>
                      <div>
                        <h3><?php echo $profileComplete ? 'Your details are complete' : 'Finish your faculty profile'; ?></h3>
                        <p><?php echo $profileCompletion['completed'] . ' of ' . $profileCompletion['total']; ?> applicable details completed.</p>
                        <small><?php echo $profileComplete ? 'Review your profile whenever your information changes.' : 'Complete your personal, employment, and education information.'; ?></small>
                      </div>
                    </div>
                    <div class="dashboard-profile-sections">
                      <?php foreach ($profileSections as $section => $label): $progress = $profileCompletion['sections'][$section]; $sectionComplete = $progress['completed'] === $progress['total']; ?>
                        <a href="manage-profile.php#<?php echo $section; ?>" class="dashboard-profile-section<?php echo $sectionComplete ? ' is-complete' : ''; ?>">
                          <i class="bx <?php echo $sectionComplete ? 'bx-check-circle' : 'bx-circle'; ?>" aria-hidden="true"></i>
                          <span><?php echo $label; ?></span>
                          <strong><?php echo $progress['completed'] . '/' . $progress['total']; ?></strong>
                          <i class="bx bx-chevron-right" aria-hidden="true"></i>
                        </a>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="dashboard-empty"><i class="bx bx-info-circle" aria-hidden="true"></i><p>Profile progress is temporarily unavailable. You can still open Manage Profile to review your information.</p></div>
                  <?php endif; ?>
                  <div class="dashboard-card-footer">
                    <small><?php echo !empty($dashboardProfile['updated_at']) ? 'Last saved ' . synk_professor_h(date('M j, Y', strtotime($dashboardProfile['updated_at']))) : 'Save your profile to keep your details up to date.'; ?></small>
                    <a href="manage-profile.php" class="btn btn-primary"><?php echo $profileComplete ? 'Review profile' : 'Complete profile'; ?><i class="bx bx-right-arrow-alt ms-1" aria-hidden="true"></i></a>
                  </div>
                </section>

                <section class="card dashboard-teaching" aria-labelledby="dashboard-teaching-title">
                  <div class="dashboard-card-heading"><div><span class="dashboard-eyebrow">CURRENT ACADEMIC TERM</span><h2 id="dashboard-teaching-title">Your teaching at a glance</h2></div><span class="dashboard-heading-icon"><i class="bx bx-briefcase-alt" aria-hidden="true"></i></span></div>
                  <p class="dashboard-term"><?php echo synk_professor_h($currentTerm['term_text'] ?? 'Current term not set'); ?></p>
                  <div class="dashboard-teaching-stats">
                    <div><span class="dashboard-stat-icon"><i class="bx bx-book-open" aria-hidden="true"></i></span><strong><?php echo $teachingSummary['subjects']; ?></strong><span>Assigned classes</span></div>
                    <div><span class="dashboard-stat-icon students"><i class="bx bx-group" aria-hidden="true"></i></span><strong><?php echo $teachingSummary['enrollments']; ?></strong><span>Student enrollments</span></div>
                  </div>
                  <p class="dashboard-teaching-note"><?php echo !$facultyIsLinked ? 'Your teaching summary will appear once your account is linked to a faculty record.' : ($teachingSummary['subjects'] === 0 ? 'No classes are assigned for the current term yet. Check Workload for available records.' : 'Enrollment counts are added across your assigned classes. Open Workload for schedules, rooms, and sections.'); ?></p>
                  <div class="dashboard-history"><i class="bx bx-calendar" aria-hidden="true"></i><span><strong><?php echo $teachingSummary['terms']; ?></strong> <?php echo $teachingSummary['terms'] === 1 ? 'term' : 'terms'; ?> in your teaching history</span></div>
                  <a href="<?php echo synk_professor_h($workloadUrl); ?>" class="btn btn-outline-primary dashboard-workload-link">Open workload<i class="bx bx-right-arrow-alt ms-1" aria-hidden="true"></i></a>
                </section>
              </div>

              <section class="card dashboard-attention" aria-labelledby="dashboard-attention-title">
                <div class="dashboard-card-heading">
                  <div><span class="dashboard-eyebrow">YOUR NEXT STEPS</span><h2 id="dashboard-attention-title"><?php echo $dashboardActions ? 'Needs attention' : 'You are all caught up'; ?></h2></div>
                  <span class="dashboard-status<?php echo !$dashboardActions ? ' is-success' : ''; ?>"><?php echo $dashboardActions ? count($dashboardActions) . (count($dashboardActions) === 1 ? ' action' : ' actions') : 'Up to date'; ?></span>
                </div>
                <?php if ($dashboardActions): ?>
                  <div class="dashboard-action-list">
                    <?php foreach ($dashboardActions as $action): ?>
                      <div class="dashboard-action">
                        <span class="dashboard-action-icon"><i class="bx <?php echo synk_professor_h($action['icon']); ?>" aria-hidden="true"></i></span>
                        <div><h3><?php echo synk_professor_h($action['title']); ?></h3><p><?php echo synk_professor_h($action['description']); ?></p></div>
                        <?php if ($action['url'] !== null): ?>
                          <a href="<?php echo synk_professor_h($action['url']); ?>" aria-label="<?php echo synk_professor_h($action['title']); ?>"><?php echo synk_professor_h($action['action']); ?><i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>
                        <?php else: ?>
                          <span class="dashboard-action-instruction"><?php echo synk_professor_h($action['action']); ?></span>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <p class="dashboard-all-clear"><i class="bx bx-check-circle" aria-hidden="true"></i>Your profile details are complete and your faculty access is active. Keep your information current as it changes.</p>
                <?php endif; ?>
              </section>
            </main>

            <?php include '../footer.php'; ?>
          </div>
        </div>
      </div>
    </div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
  </body>
</html>
