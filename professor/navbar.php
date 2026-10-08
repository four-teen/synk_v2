<?php
require_once '../backend/academic_term_helper.php';
require_once '../backend/user_avatar_helper.php';

$professorCurrentTerm = synk_fetch_current_academic_term($conn);
$professorTermText = trim((string)($professorCurrentTerm['term_text'] ?? 'Current academic term'));
$professorName = trim((string)($professorPortalDisplayName ?? ucfirst((string)($_SESSION['username'] ?? 'Professor'))));
$professorEmail = trim((string)($professorPortalDisplayEmail ?? (string)($_SESSION['email'] ?? '')));
$professorStatusLabel = trim((string)($professorPortalFacultyStatusLabel ?? 'Professor Portal'));
$professorAvatarFallback = synk_default_user_avatar_path();
$professorAvatarUrl = synk_resolve_user_avatar_url($professorEmail, (string)($_SESSION['user_avatar_url'] ?? ''), 80);
?>

<nav
  class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme professor-navbar"
  id="layout-navbar"
>
  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
    <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
      <i class="bx bx-menu bx-sm"></i>
    </a>
  </div>

  <div class="navbar-nav-right d-flex align-items-center justify-content-between gap-3 w-100" id="navbar-collapse">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="badge bg-label-primary text-uppercase">Professor Portal</span>
      <span class="badge bg-label-info"><?php echo htmlspecialchars($professorTermText, ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="badge bg-label-secondary"><?php echo htmlspecialchars($professorStatusLabel, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>

    <ul class="navbar-nav flex-row align-items-center ms-auto">
      <li class="nav-item lh-1 me-3 text-end d-none d-md-block">
        <span class="fw-semibold d-block"><?php echo htmlspecialchars($professorName, ENT_QUOTES, 'UTF-8'); ?></span>
        <small class="text-muted"><?php echo htmlspecialchars($professorEmail !== '' ? $professorEmail : 'professor@sksu.edu.ph', ENT_QUOTES, 'UTF-8'); ?></small>
      </li>

      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
          <div class="avatar avatar-online professor-avatar">
            <img src="<?php echo htmlspecialchars($professorAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($professorName, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($professorAvatarFallback, ENT_QUOTES, 'UTF-8'); ?>';" referrerpolicy="no-referrer" />
          </div>
        </a>

        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="#">
              <div class="d-flex align-items-center">
                <div class="flex-shrink-0 me-3">
                  <div class="avatar avatar-online professor-avatar">
                    <img src="<?php echo htmlspecialchars($professorAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($professorName, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($professorAvatarFallback, ENT_QUOTES, 'UTF-8'); ?>';" referrerpolicy="no-referrer" />
                  </div>
                </div>

                <div class="flex-grow-1">
                  <span class="fw-semibold d-block"><?php echo htmlspecialchars($professorName, ENT_QUOTES, 'UTF-8'); ?></span>
                  <small class="text-muted d-block">Professor</small>
                  <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($professorEmail, ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
              </div>
            </a>
          </li>

          <li><div class="dropdown-divider"></div></li>

          <li>
            <a class="dropdown-item" href="manage-profile.php">
              <i class="bx bx-id-card me-2"></i>
              <span class="align-middle">Manage Profile</span>
            </a>
          </li>

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
