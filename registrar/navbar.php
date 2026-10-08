<?php
require_once '../backend/user_avatar_helper.php';

$registrarCurrentTerm = is_array($registrarPortalContext['current_term'] ?? null)
    ? $registrarPortalContext['current_term']
    : [];
$registrarTermText = trim((string)($registrarCurrentTerm['term_text'] ?? 'Current academic term'));
$registrarCampus = is_array($registrarPortalContext['campus'] ?? null)
    ? $registrarPortalContext['campus']
    : null;
$registrarName = trim((string)($registrarPortalDisplayName ?? ucfirst((string)($_SESSION['username'] ?? 'Registrar'))));
$registrarEmail = trim((string)($registrarPortalDisplayEmail ?? (string)($_SESSION['email'] ?? '')));
$registrarCampusBadge = $registrarCampus
    ? trim((string)($registrarCampus['campus_code'] ?? '') . ' ' . (string)($registrarCampus['campus_name'] ?? ''))
    : 'Campus scope required';
$registrarAvatarFallback = synk_default_user_avatar_path();
$registrarAvatarUrl = synk_resolve_user_avatar_url($registrarEmail, (string)($_SESSION['user_avatar_url'] ?? ''), 80);
?>

<nav class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme" id="layout-navbar">
  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
    <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
      <i class="bx bx-menu bx-sm"></i>
    </a>
  </div>

  <div class="navbar-nav-right d-flex align-items-center justify-content-between gap-3 w-100" id="navbar-collapse">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="badge bg-label-danger text-uppercase">Registrar</span>
      <span class="badge bg-label-info"><?php echo htmlspecialchars($registrarTermText, ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="badge <?php echo $registrarCampus ? 'bg-label-success' : 'bg-label-warning'; ?>">
        <?php echo htmlspecialchars($registrarCampusBadge, ENT_QUOTES, 'UTF-8'); ?>
      </span>
    </div>

    <ul class="navbar-nav flex-row align-items-center ms-auto">
      <li class="nav-item lh-1 me-3 text-end d-none d-md-block">
        <span class="fw-semibold d-block"><?php echo htmlspecialchars($registrarName, ENT_QUOTES, 'UTF-8'); ?></span>
        <small class="text-muted"><?php echo htmlspecialchars($registrarEmail !== '' ? $registrarEmail : 'registrar@sksu.edu.ph', ENT_QUOTES, 'UTF-8'); ?></small>
      </li>

      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
          <div class="avatar avatar-online">
            <img src="<?php echo htmlspecialchars($registrarAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($registrarName, ENT_QUOTES, 'UTF-8'); ?>" class="w-px-40 h-auto rounded-circle" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($registrarAvatarFallback, ENT_QUOTES, 'UTF-8'); ?>';" referrerpolicy="no-referrer" />
          </div>
        </a>

        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="#">
              <div class="d-flex">
                <div class="flex-shrink-0 me-3">
                  <div class="avatar avatar-online">
                    <img src="<?php echo htmlspecialchars($registrarAvatarUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($registrarName, ENT_QUOTES, 'UTF-8'); ?>" class="w-px-40 h-auto rounded-circle" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($registrarAvatarFallback, ENT_QUOTES, 'UTF-8'); ?>';" referrerpolicy="no-referrer" />
                  </div>
                </div>
                <div class="flex-grow-1">
                  <span class="fw-semibold d-block"><?php echo htmlspecialchars($registrarName, ENT_QUOTES, 'UTF-8'); ?></span>
                  <small class="text-muted d-block">Registrar</small>
                  <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($registrarEmail, ENT_QUOTES, 'UTF-8'); ?></small>
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
