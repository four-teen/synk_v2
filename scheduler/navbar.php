<?php
require_once __DIR__ . '/term-context.php';
require_once __DIR__ . '/../backend/account_switch_helper.php';
require_once '../backend/scheduler_access_helper.php';
require_once '../backend/user_avatar_helper.php';

if ((string)($_SESSION['role'] ?? '') === 'scheduler') {
    synk_scheduler_bootstrap_session_scope($conn);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$uName = ucfirst((string)($_SESSION['username'] ?? 'User'));
$uRole = ucfirst((string)($_SESSION['role'] ?? 'User'));
$schedulerCollegeAccess = is_array($_SESSION['scheduler_college_access'] ?? null)
    ? $_SESSION['scheduler_college_access']
    : [];
$activeCollegeId = (int)($_SESSION['college_id'] ?? 0);
$activeCollegeName = trim((string)($_SESSION['college_name'] ?? ''));
$schedulerScopeToken = (string)$_SESSION['csrf_token'];
$uEmail = trim((string)($_SESSION['email'] ?? ''));
$avatarFallback = synk_default_user_avatar_path();
$avatarUrl = synk_resolve_user_avatar_url($uEmail, (string)($_SESSION['user_avatar_url'] ?? ''), 80);
$schedulerNavbarTerm = synk_scheduler_working_term($conn);
$schedulerNavbarYears = synk_scheduler_academic_years($conn);
$schedulerOfficialTerm = synk_fetch_current_academic_term($conn);
$schedulerTermConfig = ['term'=>$schedulerNavbarTerm,'years'=>$schedulerNavbarYears,'collegeId'=>$activeCollegeId,'csrfToken'=>$schedulerScopeToken,
  'backendPath'=>rtrim(dirname(dirname($_SERVER['PHP_SELF'] ?? '/synk/scheduler/index.php')), '/').'/backend/',
  'schedulerPath'=>rtrim(dirname($_SERVER['PHP_SELF'] ?? '/synk/scheduler/index.php'), '/').'/'];
?>
<link rel="stylesheet" href="working-term.css?v=<?= (int)filemtime(__DIR__.'/working-term.css') ?>">

<nav
  class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached bg-navbar-theme scheduler-navbar"
  id="layout-navbar">

  <div class="layout-menu-toggle d-xl-none">
    <button type="button" class="scheduler-menu-button" aria-label="Toggle navigation" aria-controls="layout-menu">
      <i class="bx bx-menu" aria-hidden="true"></i>
    </button>
  </div>

  <div class="navbar-nav-right" id="navbar-collapse">
    <button
      type="button"
      class="scheduler-term-control"
      id="scheduler-term-settings-toggle"
      data-bs-toggle="modal"
      data-bs-target="#schedulerTermSettingsModal"
      aria-label="Change working academic year and semester"
      aria-describedby="scheduler-working-term-label scheduler-working-term-status"
      aria-haspopup="dialog"
      title="Working term settings"
    >
      <span class="scheduler-term-labels">
        <span id="scheduler-working-term-label"><?= htmlspecialchars($schedulerNavbarTerm['term_text'],ENT_QUOTES,'UTF-8') ?></span>
        <span class="scheduler-term-status<?= $schedulerNavbarTerm['is_current'] ? ' is-current' : '' ?>" id="scheduler-working-term-status"><?= htmlspecialchars($schedulerNavbarTerm['mode'],ENT_QUOTES,'UTF-8') ?></span>
      </span>
      <i class="bx bx-calendar-edit scheduler-term-icon" aria-hidden="true"></i>
    </button>

    <?php if ((string)($_SESSION['role'] ?? '') === 'scheduler'): ?>
      <div class="d-none d-md-flex align-items-center justify-content-center scheduler-college-context">
        <?php if (count($schedulerCollegeAccess) > 1): ?>
          <select
            id="schedulerCollegeSwitch"
            class="form-select form-select-sm"
            aria-label="Switch active college workspace"
          >
            <?php foreach ($schedulerCollegeAccess as $accessRow): ?>
              <?php
                $optionCollegeId = (int)($accessRow['college_id'] ?? 0);
                $optionLabel = (string)($accessRow['display_label'] ?? $accessRow['college_name'] ?? 'College');
                if (!empty($accessRow['is_default'])) {
                    $optionLabel .= ' [Default]';
                }
              ?>
              <option value="<?= $optionCollegeId ?>" <?= $optionCollegeId === $activeCollegeId ? 'selected' : '' ?>>
                <?= htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <span class="scheduler-college-label"><?= htmlspecialchars($activeCollegeName !== '' ? $activeCollegeName : 'Assigned College', ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <ul class="navbar-nav scheduler-profile">
      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="scheduler-profile-toggle dropdown-toggle hide-arrow" href="javascript:void(0);" role="button" data-bs-toggle="dropdown" aria-expanded="false">
          <span class="avatar">
            <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" class="rounded-circle" onerror="this.onerror=null;this.src='<?= htmlspecialchars($avatarFallback, ENT_QUOTES, 'UTF-8') ?>';" referrerpolicy="no-referrer" />
          </span>
          <span class="scheduler-user-name">
            <span class="fw-bold text-uppercase"><?= htmlspecialchars($uName, ENT_QUOTES, 'UTF-8') ?></span>
            <small class="scheduler-user-role"><?= htmlspecialchars($uRole, ENT_QUOTES, 'UTF-8') ?></small>
          </span>
        </a>

        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="#">
              <div class="d-flex">
                <div class="flex-shrink-0 me-3">
                  <div class="avatar avatar-online">
                    <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($uName, ENT_QUOTES, 'UTF-8') ?>" class="w-px-40 h-auto rounded-circle" onerror="this.onerror=null;this.src='<?= htmlspecialchars($avatarFallback, ENT_QUOTES, 'UTF-8') ?>';" referrerpolicy="no-referrer" />
                  </div>
                </div>

                <div class="flex-grow-1">
                  <span class="fw-semibold d-block"><?= htmlspecialchars($uName, ENT_QUOTES, 'UTF-8') ?></span>
                  <small class="text-muted"><?= htmlspecialchars($uRole, ENT_QUOTES, 'UTF-8') ?></small>
                  <?php if ((string)($_SESSION['role'] ?? '') === 'scheduler' && $activeCollegeName !== ''): ?>
                    <small class="text-muted d-block mt-1"><?= htmlspecialchars($activeCollegeName, ENT_QUOTES, 'UTF-8') ?></small>
                  <?php endif; ?>
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
            <a class="dropdown-item" href="#schedulerTermSettingsModal" data-bs-toggle="modal" data-bs-target="#schedulerTermSettingsModal">
              <i class="bx bx-cog me-2"></i>
              <span class="align-middle">Settings</span>
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

<?php if (!$schedulerNavbarTerm['can_write']): ?>
<div class="scheduler-term-readonly" role="status">Select an academic year and semester in Working term settings before making changes.</div>
<?php endif; ?>
<div class="modal fade" id="schedulerTermSettingsModal" tabindex="-1" aria-labelledby="schedulerTermSettingsTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form id="scheduler-working-term-form" action="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'] ?? 'index.php'),ENT_QUOTES,'UTF-8') ?>" method="get">
      <div class="modal-header"><h5 class="modal-title" id="schedulerTermSettingsTitle">Working term settings</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p>Choose the academic year and semester for your scheduler workspace.</p>
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label" for="scheduler-working-year">Academic year</label><select class="form-select" id="scheduler-working-year" name="scheduler_ay_id" required><?php foreach ($schedulerNavbarYears as $year): ?><option value="<?= $year['ay_id'] ?>"<?= $year['ay_id'] === $schedulerNavbarTerm['ay_id'] ? ' selected' : '' ?>><?= htmlspecialchars($year['ay'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select></div>
          <div class="col-sm-6"><label class="form-label" for="scheduler-working-semester">Semester</label><select class="form-select" id="scheduler-working-semester" name="scheduler_semester" required><?php foreach ([1,2,3] as $sem): ?><option value="<?= $sem ?>"<?= $sem === $schedulerNavbarTerm['semester'] ? ' selected' : '' ?>><?= synk_semester_label($sem) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="scheduler-term-current"><div><span>Official current term</span><strong><?= htmlspecialchars($schedulerOfficialTerm['term_text'],ENT_QUOTES,'UTF-8') ?></strong></div><button type="submit" name="scheduler_term" value="current" class="btn btn-sm btn-outline-secondary">Use current term</button></div>
        <p class="small text-muted mt-3 mb-0">You can work in any listed academic year and semester, including previous and future terms. This does not change the official current term. Existing student protections and faculty visibility rules still apply.</p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Apply</button></div>
    </form>
  </div></div>
</div>
<script type="application/json" id="schedulerWorkingTermConfig"><?= json_encode($schedulerTermConfig,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="working-term.js?v=<?= (int)filemtime(__DIR__.'/working-term.js') ?>" defer></script>

<?php if ((string)($_SESSION['role'] ?? '') === 'scheduler' && count($schedulerCollegeAccess) > 1): ?>
  <script>
    document.addEventListener("change", function (event) {
      if (event.target.id !== "schedulerCollegeSwitch") {
        return;
      }

      const select = event.target;
      const originalValue = select.dataset.previousValue || select.value;
      select.disabled = true;

      const payload = new URLSearchParams({
        college_id: select.value,
        csrf_token: <?= json_encode($schedulerScopeToken) ?>
      });

      fetch("../backend/query_scheduler_scope.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
        },
        body: payload.toString()
      })
        .then(function (response) {
          return response.json();
        })
        .then(function (data) {
          if (!data || data.status !== "success") {
            throw new Error((data && data.message) || "Unable to switch workspace.");
          }

          window.location.reload();
        })
        .catch(function (error) {
          window.alert(error.message || "Unable to switch workspace.");
          select.value = originalValue;
        })
        .finally(function () {
          select.disabled = false;
        });
    });

    document.addEventListener("DOMContentLoaded", function () {
      const select = document.getElementById("schedulerCollegeSwitch");
      if (select) {
        select.dataset.previousValue = select.value;
      }
    });
  </script>
<?php endif; ?>
