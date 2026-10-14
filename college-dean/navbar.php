<?php
require_once __DIR__ . '/../backend/account_switch_helper.php';
require_once '../backend/user_avatar_helper.php';
$collegePortalRoleLabel = ($_SESSION['role'] ?? '') === 'program_chair' ? 'Program Chair' : 'College Dean';

$collegeDeanCollege = is_array($collegeDeanPortalContext['college'] ?? null)
    ? $collegeDeanPortalContext['college']
    : null;
$collegeDeanName = trim((string)($collegeDeanPortalDisplayName ?? ucfirst((string)($_SESSION['username'] ?? 'College Dean'))));
$collegeDeanEmail = trim((string)($collegeDeanPortalDisplayEmail ?? (string)($_SESSION['email'] ?? '')));
$collegeDeanCampusBadge = $collegeDeanCollege
    ? trim((string)($collegeDeanCollege['campus_name'] ?? 'Campus not set'))
    : 'College scope required';
$collegeDeanAvatarFallback = synk_default_user_avatar_path();
$collegeDeanAvatarUrl = synk_resolve_user_avatar_url($collegeDeanEmail, (string)($_SESSION['user_avatar_url'] ?? ''), 80);
$deanNavbarTerm = synk_dean_view_term($conn);
$deanNavbarYears = synk_dean_academic_years($conn);
$deanNavbarDefaultTerm = synk_fetch_current_academic_term($conn);
$deanNavbarPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$deanNavbarContext = [];
foreach (['faculty_id', 'offering_id', 'list_status'] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) $deanNavbarContext[$key] = $_GET[$key];
}
?>

<nav
  class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme dean-navbar"
  id="layout-navbar"
>
  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
    <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
      <i class="bx bx-menu bx-sm"></i>
    </a>
  </div>

  <div class="navbar-nav-right d-flex align-items-center justify-content-between gap-3 w-100" id="navbar-collapse">
    <div class="dean-navbar-context">
      <span class="badge bg-label-secondary"><?php echo htmlspecialchars($collegeDeanCampusBadge, ENT_QUOTES, 'UTF-8'); ?></span>
      <?php if ($deanNavbarYears): ?>
        <div class="dean-view-summary">
          <i class="bx bx-calendar" aria-hidden="true"></i>
          <span class="dean-view-label" id="dean-view-label"><?php echo htmlspecialchars($deanNavbarTerm['term_text'], ENT_QUOTES, 'UTF-8'); ?></span>
          <?php if (empty($deanNavbarTerm['is_current'])): ?><span class="badge bg-label-secondary dean-view-readonly">View only</span><?php endif; ?>
          <button type="button" class="btn btn-sm dean-view-settings" id="dean-term-settings-toggle" data-bs-toggle="modal" data-bs-target="#deanTermSettingsModal" aria-label="Change viewing academic year and semester" title="Viewing term settings"><i class="bx bx-cog" aria-hidden="true"></i></button>
        </div>
      <?php endif; ?>
    </div>

    <ul class="navbar-nav flex-row align-items-center ms-auto">
      <li class="nav-item lh-1 me-3 text-end d-none d-md-block">
        <span class="fw-semibold d-block"><?php echo htmlspecialchars($collegeDeanName, ENT_QUOTES, 'UTF-8'); ?></span>
        <small class="text-muted"><?php echo htmlspecialchars($collegeDeanEmail !== '' ? $collegeDeanEmail : 'collegedean@sksu.edu.ph', ENT_QUOTES, 'UTF-8'); ?></small>
      </li>

      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
          <div class="avatar avatar-online">
            <img src="<?php echo htmlspecialchars($collegeDeanAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($collegeDeanName, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-circle" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($collegeDeanAvatarFallback, ENT_QUOTES, 'UTF-8'); ?>';" referrerpolicy="no-referrer" />
          </div>
        </a>

        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="#">
              <div class="d-flex">
                <div class="flex-shrink-0 me-3">
                  <div class="avatar avatar-online">
                    <img src="<?php echo htmlspecialchars($collegeDeanAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($collegeDeanName, ENT_QUOTES, 'UTF-8'); ?>" class="rounded-circle" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($collegeDeanAvatarFallback, ENT_QUOTES, 'UTF-8'); ?>';" referrerpolicy="no-referrer" />
                  </div>
                </div>

                <div class="flex-grow-1">
                  <span class="fw-semibold d-block"><?php echo htmlspecialchars($collegeDeanName, ENT_QUOTES, 'UTF-8'); ?></span>
                  <small class="text-muted d-block"><?php echo $collegePortalRoleLabel; ?></small>
                  <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($collegeDeanEmail, ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
              </div>
            </a>
          </li>

          <li><div class="dropdown-divider"></div></li>

          <?php synk_render_account_switch($conn); ?>
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

<?php if ($deanNavbarYears): ?>
<div class="modal fade" id="deanTermSettingsModal" tabindex="-1" aria-labelledby="deanTermSettingsTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form class="dean-view-term" id="dean-view-term-form" action="<?php echo htmlspecialchars($deanNavbarPage, ENT_QUOTES, 'UTF-8'); ?>" method="get" data-current-year="<?php echo (int)$deanNavbarDefaultTerm['ay_id']; ?>" data-current-semester="<?php echo (int)$deanNavbarDefaultTerm['semester']; ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="deanTermSettingsTitle">Viewing term settings</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="dean-term-settings-copy">Choose the academic year and semester to review in your <?php echo $collegePortalRoleLabel; ?> portal.</p>
          <?php foreach ($deanNavbarContext as $key => $value): ?><input type="hidden" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><?php endforeach; ?>
          <input type="hidden" id="dean-view-current-choice" name="dean_term" value="current" disabled>
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label" for="dean-view-year">Academic year</label>
              <select class="form-select" id="dean-view-year" name="dean_ay_id"><?php foreach ($deanNavbarYears as $year): ?><option value="<?php echo $year['ay_id']; ?>"<?php echo $year['ay_id'] === $deanNavbarTerm['ay_id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($year['ay'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="dean-view-semester">Semester</label>
              <select class="form-select" id="dean-view-semester" name="dean_semester"><?php foreach ([1, 2, 3] as $semester): ?><option value="<?php echo $semester; ?>"<?php echo $semester === $deanNavbarTerm['semester'] ? ' selected' : ''; ?>><?php echo synk_semester_label($semester); ?></option><?php endforeach; ?></select>
            </div>
          </div>
          <div class="dean-term-default">
            <div><span>Current term</span><strong><?php echo htmlspecialchars($deanNavbarDefaultTerm['term_text'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
            <button type="button" class="btn btn-sm btn-outline-secondary dean-view-current"<?php echo $deanNavbarDefaultTerm['ay_id'] <= 0 || $deanNavbarDefaultTerm['semester'] <= 0 ? ' disabled' : ''; ?>>Use current term</button>
          </div>
          <p class="dean-term-settings-note">Other terms are for viewing only.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" id="dean-view-cancel" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="dean-view-apply">Apply</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script src="view-term-settings.js?v=<?php echo filemtime(__DIR__ . '/view-term-settings.js'); ?>" defer></script>
<?php endif; ?>
