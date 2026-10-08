<?php
require_once '../backend/user_avatar_helper.php';


/*
|--------------------------------------------------------------------------
| ADMIN NAVBAR (GLOBAL)
|--------------------------------------------------------------------------
| Purpose:
| - Replaces Search bar with Current Academic Term display (AY + Semester)
| - Reads from tbl_academic_settings (single row) + tbl_academic_years
| - Shows a clear badge across all admin pages for term awareness
|
| Requirements:
| - The parent page should include ../backend/db.php BEFORE including navbar.php
|--------------------------------------------------------------------------
*/

$uName = ucfirst($_SESSION['username'] ?? 'User');
$uRole = ucfirst($_SESSION['role'] ?? 'Role');
$uEmail = trim((string)($_SESSION['email'] ?? ''));
$avatarFallback = synk_default_user_avatar_path();
$avatarUrl = synk_resolve_user_avatar_url($uEmail, (string)($_SESSION['user_avatar_url'] ?? ''), 80);

/*
|--------------------------------------------------------------------------
| LOAD CURRENT ACADEMIC TERM (GLOBAL DISPLAY)
|--------------------------------------------------------------------------
*/
$currentAyText   = 'Not Set';
$currentSemText  = 'Not Set';
$termBadgeClass  = 'bg-label-warning';

$semMap = [
  1 => '1st Semester',
  2 => '2nd Semester',
  3 => 'Midyear'
];

if (isset($conn)) {
  $sqlTerm = "
      SELECT s.current_ay_id, s.current_semester, ay.ay
      FROM tbl_academic_settings s
      JOIN tbl_academic_years ay ON ay.ay_id = s.current_ay_id
      LIMIT 1
  ";
  if ($resTerm = mysqli_query($conn, $sqlTerm)) {
    if ($rowTerm = mysqli_fetch_assoc($resTerm)) {
      $currentAyText  = $rowTerm['ay'] ?? 'Not Set';
      $currentSemText = $semMap[(int)$rowTerm['current_semester']] ?? 'Not Set';
      $termBadgeClass = 'bg-label-success';
    }
  }
}
?>

<nav
  class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme"
  id="layout-navbar">

  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
    <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
      <i class="bx bx-menu bx-sm"></i>
    </a>
  </div>

  <div class="navbar-nav-right d-flex align-items-center" id="navbar-collapse">

    <!-- CURRENT ACADEMIC TERM (REPLACES SEARCH) -->
    <div class="navbar-nav align-items-center">
      <div class="nav-item d-flex align-items-center">
        <i class="bx bx-calendar fs-4 lh-0 me-2 text-primary"></i>

        <div class="d-flex flex-column">
          <small class="text-muted" style="line-height: 1.1;">Current Term</small>
          <div class="d-flex align-items-center gap-2">
            <span class="fw-semibold"><?= htmlspecialchars($currentAyText) ?></span>
            <span class="badge <?= $termBadgeClass ?>"><?= htmlspecialchars($currentSemText) ?></span>

            <a href="academic-settings.php" class="btn btn-sm btn-outline-primary ms-1">
              <i class="bx bx-cog"></i>
            </a>
          </div>
        </div>
      </div>
    </div>
    <!-- /CURRENT ACADEMIC TERM -->

    <ul class="navbar-nav flex-row align-items-center ms-auto">

      <!-- Username & Role Display -->
      <li class="nav-item lh-1 me-3">
        <span class="fw-semibold"><?= htmlspecialchars($uName) ?></span>
        <small class="text-muted ms-1">(<?= htmlspecialchars($uRole) ?>)</small>
      </li>

      <!-- User Dropdown -->
      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
          <div class="avatar avatar-online">
            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($uName) ?>" class="w-px-40 h-auto rounded-circle" onerror="this.onerror=null;this.src='<?= htmlspecialchars($avatarFallback) ?>';" referrerpolicy="no-referrer" />
          </div>
        </a>

        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="#">
              <div class="d-flex">
                <div class="flex-shrink-0 me-3">
                  <div class="avatar avatar-online">
                    <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($uName) ?>" class="w-px-40 h-auto rounded-circle" onerror="this.onerror=null;this.src='<?= htmlspecialchars($avatarFallback) ?>';" referrerpolicy="no-referrer" />
                  </div>
                </div>

                <div class="flex-grow-1">
                  <span class="fw-semibold d-block"><?= htmlspecialchars($uName) ?></span>
                  <small class="text-muted"><?= htmlspecialchars($uRole) ?></small>
                </div>
              </div>
            </a>
          </li>

          <li><div class="dropdown-divider"></div></li>

          <li>
            <a class="dropdown-item" href="#">
              <i class="bx bx-user me-2"></i>
              <span class="align-middle">My Profile</span>
            </a>
          </li>

          <li>
            <a class="dropdown-item" href="academic-settings.php">
              <i class="bx bx-cog me-2"></i>
              <span class="align-middle">Academic Settings</span>
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
      <!-- /User -->

    </ul>
  </div>
</nav>
