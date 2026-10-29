<?php
require_once '../backend/academic_term_helper.php';
require_once __DIR__.'/../backend/student_profile_image_helper.php';

$studentCurrentTerm = $studentPortalViewTerm ?? synk_fetch_current_academic_term($conn);
$studentTermText = trim((string)($studentCurrentTerm['term_text'] ?? 'Current academic term'));
$studentName = trim((string)($studentPortalDisplayName ?? ucfirst((string)($_SESSION['username'] ?? 'Student'))));
$studentEmail = trim((string)($studentPortalDisplayEmail ?? (string)($_SESSION['email'] ?? '')));
$studentPreviewMode = !empty($studentPortalPreviewMode);
$studentBackUrl = trim((string)($studentPortalBackUrl ?? ''));
$studentPortalBadge = $studentPreviewMode ? 'Student Preview' : 'Student Portal';
$studentRoleLabel = $studentPreviewMode ? synk_student_preview_label().' · Read only' : 'Student';
$studentAvatarFallback = '../student/profile-placeholder.svg';
$studentPhotoId=$studentPreviewMode ? synk_student_preview_student_id_from_request() : (int)($_SESSION['student_id'] ?? 0);
$studentUploadedPhotoUrl=synk_student_profile_image_url($studentPhotoId);
$studentAvatarUrl = $studentUploadedPhotoUrl ?: $studentAvatarFallback;
?>

<nav
  class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme student-navbar<?= $studentPreviewMode ? ' student-preview-navbar' : '' ?>"
  id="layout-navbar"
>
  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
    <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
      <i class="bx bx-menu bx-sm"></i>
    </a>
  </div>

  <div class="navbar-nav-right d-flex align-items-center justify-content-between gap-3 w-100" id="navbar-collapse">
    <div class="d-flex align-items-center gap-2 student-header-cluster<?php echo $studentPreviewMode ? ' flex-wrap' : ''; ?>">
      <span class="badge bg-label-primary text-uppercase student-header-badge"><?php echo htmlspecialchars($studentPortalBadge, ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="student-header-term"><?php echo htmlspecialchars($studentTermText, ENT_QUOTES, 'UTF-8'); ?></span>
      <?php if ($studentPreviewMode && !empty($terms)): ?><button class="student-term-toggle" type="button" data-bs-toggle="modal" data-bs-target="#studentTermModal" aria-label="Choose academic year and semester" title="Choose academic year and semester"><i class="bx bx-cog" aria-hidden="true"></i></button><?php endif; ?>
      <?php if ($studentPreviewMode && $studentBackUrl !== ''): ?>
        <a href="<?php echo htmlspecialchars($studentBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-primary">
          <i class="bx bx-arrow-back me-1"></i> Back to Directory
        </a>
      <?php endif; ?>
    </div>

    <ul class="navbar-nav flex-row align-items-center ms-auto">
      <li class="nav-item lh-1 me-3 text-end d-none d-md-block">
        <span class="fw-semibold d-block"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></span>
        <small class="text-muted"><?php echo htmlspecialchars($studentEmail !== '' ? $studentEmail : 'student@sksu.edu.ph', ENT_QUOTES, 'UTF-8'); ?></small>
      </li>

      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
          <div class="avatar avatar-online">
            <img src="<?php echo htmlspecialchars($studentAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?>" class="w-px-40 h-auto rounded-circle" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($studentAvatarFallback, ENT_QUOTES, 'UTF-8'); ?>';" referrerpolicy="no-referrer" />
          </div>
        </a>

        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="#">
              <div class="d-flex">
                <div class="flex-shrink-0 me-3">
                  <div class="avatar avatar-online">
                    <img src="<?php echo htmlspecialchars($studentAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?>" class="w-px-40 h-auto rounded-circle" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($studentAvatarFallback, ENT_QUOTES, 'UTF-8'); ?>';" referrerpolicy="no-referrer" />
                  </div>
                </div>

                <div class="flex-grow-1">
                  <span class="fw-semibold d-block"><?php echo htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8'); ?></span>
                  <small class="text-muted d-block"><?php echo htmlspecialchars($studentRoleLabel, ENT_QUOTES, 'UTF-8'); ?></small>
                  <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($studentEmail, ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
              </div>
            </a>
          </li>

          <li><div class="dropdown-divider"></div></li>

          <li><a class="dropdown-item" href="<?= htmlspecialchars(synk_student_build_portal_url('profile.php'), ENT_QUOTES, 'UTF-8') ?>"><i class="bx bx-user-circle me-2" aria-hidden="true"></i><span class="align-middle">Manage Profile</span></a></li>

          <li>
            <a class="dropdown-item" href="<?= htmlspecialchars($studentPreviewMode ? $studentBackUrl : '../logout.php', ENT_QUOTES, 'UTF-8') ?>">
              <i class="bx <?= $studentPreviewMode ? 'bx-arrow-back' : 'bx-power-off' ?> me-2"></i>
              <span class="align-middle"><?= $studentPreviewMode ? 'Return to directory' : 'Log Out' ?></span>
            </a>
          </li>
        </ul>
      </li>
    </ul>
  </div>
</nav>
<?php if (!$studentPreviewMode && ($_SESSION['role'] ?? '') === 'student' && !empty($_SESSION['student_presence_csrf'])): ?>
<script id="studentPresenceConfig" type="application/json"><?= json_encode(['csrf_token'=>$_SESSION['student_presence_csrf'],'idle_seconds'=>900], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="presence.js?v=<?= (int)filemtime(__DIR__.'/presence.js') ?>" defer></script>
<?php endif; ?>
<?php if (!$studentPreviewMode && ($_SESSION['role'] ?? '')==='student' && $studentUploadedPhotoUrl!=='') require __DIR__.'/evaluation-prompt.php'; ?>
