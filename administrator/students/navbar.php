<?php
require_once '../../backend/academic_term_helper.php';
require_once '../../backend/user_avatar_helper.php';

$studentAdminName = ucfirst($_SESSION['username'] ?? 'User');
$studentAdminRole = ucfirst($_SESSION['role'] ?? 'Role');
$studentAdminEmail = trim((string)($_SESSION['email'] ?? ''));
$studentAvatarUrl = synk_resolve_user_avatar_url(
    $studentAdminEmail,
    (string)($_SESSION['user_avatar_url'] ?? ''),
    80
);
$studentAvatarFallback = '../../assets/img/avatars/1.png';
$studentCurrentTerm = synk_fetch_current_academic_term($conn);
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

  <div class="navbar-nav-right d-flex align-items-center gap-3 w-100" id="navbar-collapse">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div class="d-flex align-items-center gap-2">
        <span class="badge bg-label-primary px-3 py-2 text-uppercase fw-semibold">Student Management</span>
        <a href="../index.php" class="btn btn-sm btn-outline-secondary">
          <i class="bx bx-arrow-back me-1"></i> Back to Dashboard
        </a>
      </div>

      <div class="d-flex align-items-center gap-2">
        <i class="bx bx-calendar fs-4 text-primary"></i>
        <div class="d-flex flex-column">
          <small class="text-muted" style="line-height: 1.05;">Current Term</small>
          <span class="fw-semibold"><?php echo htmlspecialchars($studentCurrentTerm['term_text'] ?? 'Current academic term', ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      </div>
    </div>

    <ul class="navbar-nav flex-row align-items-center ms-auto">
      <li class="nav-item lh-1 me-3 text-end">
        <span class="fw-semibold d-block"><?php echo htmlspecialchars($studentAdminName, ENT_QUOTES, 'UTF-8'); ?></span>
        <small class="text-muted"><?php echo htmlspecialchars($studentAdminRole, ENT_QUOTES, 'UTF-8'); ?></small>
      </li>

      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
          <div class="avatar avatar-online">
            <img
              src="<?php echo htmlspecialchars($studentAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>"
              alt="<?php echo htmlspecialchars($studentAdminName, ENT_QUOTES, 'UTF-8'); ?>"
              class="w-px-40 h-auto rounded-circle"
              onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($studentAvatarFallback, ENT_QUOTES, 'UTF-8'); ?>';"
              referrerpolicy="no-referrer"
            />
          </div>
        </a>

        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="#">
              <div class="d-flex">
                <div class="flex-shrink-0 me-3">
                  <div class="avatar avatar-online">
                    <img
                      src="<?php echo htmlspecialchars($studentAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>"
                      alt="<?php echo htmlspecialchars($studentAdminName, ENT_QUOTES, 'UTF-8'); ?>"
                      class="w-px-40 h-auto rounded-circle"
                      onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($studentAvatarFallback, ENT_QUOTES, 'UTF-8'); ?>';"
                      referrerpolicy="no-referrer"
                    />
                  </div>
                </div>
                <div class="flex-grow-1">
                  <span class="fw-semibold d-block"><?php echo htmlspecialchars($studentAdminName, ENT_QUOTES, 'UTF-8'); ?></span>
                  <small class="text-muted"><?php echo htmlspecialchars($studentAdminRole, ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
              </div>
            </a>
          </li>

          <li><div class="dropdown-divider"></div></li>

          <li>
            <a class="dropdown-item" href="../academic-settings.php">
              <i class="bx bx-cog me-2"></i>
              <span class="align-middle">Academic Settings</span>
            </a>
          </li>

          <li>
            <a class="dropdown-item" href="../index.php">
              <i class="bx bx-grid-alt me-2"></i>
              <span class="align-middle">Admin Dashboard</span>
            </a>
          </li>

          <li><div class="dropdown-divider"></div></li>

          <li>
            <a class="dropdown-item" href="../../logout.php">
              <i class="bx bx-power-off me-2"></i>
              <span class="align-middle">Log Out</span>
            </a>
          </li>
        </ul>
      </li>
    </ul>
  </div>
</nav>
