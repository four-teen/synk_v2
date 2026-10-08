<?php
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
$activeCampusName = trim((string)($_SESSION['campus_name'] ?? ''));
$activeCollegeName = trim((string)($_SESSION['college_name'] ?? ''));
$schedulerScopeToken = (string)$_SESSION['csrf_token'];
$uEmail = trim((string)($_SESSION['email'] ?? ''));
$avatarFallback = synk_default_user_avatar_path();
$avatarUrl = synk_resolve_user_avatar_url($uEmail, (string)($_SESSION['user_avatar_url'] ?? ''), 80);
?>

<nav
  class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme"
  id="layout-navbar">

  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
    <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
      <i class="bx bx-menu bx-sm"></i>
    </a>
  </div>

  <div class="navbar-nav-right d-flex align-items-center gap-3 w-100" id="navbar-collapse">
    <div class="navbar-nav align-items-center flex-grow-1">
      <div class="nav-item d-flex align-items-center w-100">
        <i class="bx bx-search fs-4 lh-0"></i>
        <input
          type="text"
          class="form-control border-0 shadow-none"
          placeholder="Search..."
          aria-label="Search..."
        />
      </div>
    </div>

    <?php if ((string)($_SESSION['role'] ?? '') === 'scheduler'): ?>
      <div class="d-none d-md-flex align-items-center gap-2 flex-shrink-0">
        <span class="badge bg-label-info text-uppercase"><?= htmlspecialchars($activeCampusName !== '' ? $activeCampusName : 'Campus', ENT_QUOTES, 'UTF-8') ?></span>
        <?php if (count($schedulerCollegeAccess) > 1): ?>
          <select
            id="schedulerCollegeSwitch"
            class="form-select form-select-sm"
            style="min-width: 18rem;"
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
          <span class="badge bg-label-primary"><?= htmlspecialchars($activeCollegeName !== '' ? $activeCollegeName : 'Assigned College', ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <ul class="navbar-nav flex-row align-items-center ms-auto">
      <li class="nav-item lh-1 me-3 text-end">
        <span class="fw-semibold"><?= htmlspecialchars($uName, ENT_QUOTES, 'UTF-8') ?></span>
        <small class="text-muted ms-1">(<?= htmlspecialchars($uRole, ENT_QUOTES, 'UTF-8') ?>)</small>
      </li>

      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
          <div class="avatar avatar-online">
            <img src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($uName, ENT_QUOTES, 'UTF-8') ?>" class="w-px-40 h-auto rounded-circle" onerror="this.onerror=null;this.src='<?= htmlspecialchars($avatarFallback, ENT_QUOTES, 'UTF-8') ?>';" referrerpolicy="no-referrer" />
          </div>
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
            <a class="dropdown-item" href="#">
              <i class="bx bx-cog me-2"></i>
              <span class="align-middle">Settings</span>
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
