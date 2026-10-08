<?php
require_once '../backend/academic_term_helper.php';
require_once '../backend/user_avatar_helper.php';

$studentCurrentTerm = synk_fetch_current_academic_term($conn);
$studentTermText = trim((string)($studentCurrentTerm['term_text'] ?? 'Current academic term'));
$studentName = trim((string)($studentPortalDisplayName ?? ucfirst((string)($_SESSION['username'] ?? 'Student'))));
$studentEmail = trim((string)($studentPortalDisplayEmail ?? (string)($_SESSION['email'] ?? '')));
$studentPreviewMode = !empty($studentPortalPreviewMode);
$studentBackUrl = trim((string)($studentPortalBackUrl ?? ''));
$studentPortalBadge = $studentPreviewMode ? 'Student Preview' : 'Student Portal';
$studentRoleLabel = $studentPreviewMode ? 'Admin Preview' : 'Student';
$studentAvatarFallback = synk_default_user_avatar_path();
$studentAvatarUrl = synk_resolve_user_avatar_url($studentEmail, (string)($_SESSION['user_avatar_url'] ?? ''), 80);
?>

<nav
  class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme"
  id="layout-navbar"
>
  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
    <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
      <i class="bx bx-menu bx-sm"></i>
    </a>
  </div>

  <div class="navbar-nav-right d-flex align-items-center justify-content-between gap-3 w-100" id="navbar-collapse">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="badge bg-label-primary text-uppercase"><?php echo htmlspecialchars($studentPortalBadge, ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="badge bg-label-info"><?php echo htmlspecialchars($studentTermText, ENT_QUOTES, 'UTF-8'); ?></span>
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

          <li>
            <a class="dropdown-item" href="../logout.php">
              <i class="bx bx-power-off me-2"></i>
              <span class="align-middle">Log Out</span>
            </a>
          </li>
        </ul>
      </li>
    </ul>
  </div>
</nav>
