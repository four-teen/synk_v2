<?php
require_once __DIR__ . '/../backend/user_avatar_helper.php';
$diAvatarFallback = synk_default_user_avatar_path();
$diAvatarUrl = synk_resolve_user_avatar_url((string)$moduleAccount['email'], (string)($_SESSION['user_avatar_url'] ?? ''), 80);
?>
<nav class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme" id="layout-navbar" aria-label="Account navigation">
  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none"><a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0);" aria-label="Open menu"><i class="bx bx-menu bx-sm"></i></a></div>
  <div class="navbar-nav-right d-flex align-items-center w-100" id="navbar-collapse">
    <div class="d-flex align-items-center di-term">
      <i class="bx bx-calendar fs-4 me-2 text-primary"></i>
      <div><small class="text-muted d-block">Current Term</small><span class="fw-semibold"><?= synk_leadership_h($moduleTerm['ay_label'] ?: 'Not set') ?></span> <span class="badge bg-label-success"><?= synk_leadership_h($moduleTerm['semester_label'] ?: 'Not set') ?></span></div>
    </div>
    <ul class="navbar-nav flex-row align-items-center ms-auto">
      <li class="nav-item text-end me-3 d-none d-md-block"><span class="fw-semibold d-block"><?= synk_leadership_h($moduleAccount['username']) ?></span><small class="text-muted">Director for Instruction</small></li>
      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown" aria-label="Open account menu"><span class="avatar avatar-online"><img src="<?= synk_leadership_h($diAvatarUrl) ?>" alt="User avatar" class="w-px-40 h-auto rounded-circle" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src='<?= synk_leadership_h($diAvatarFallback) ?>';" /></span></a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li class="px-3 py-2"><strong class="d-block"><?= synk_leadership_h($moduleAccount['username']) ?></strong><small class="text-muted d-block">DI · Director for Instruction</small><small class="text-muted di-email"><?= synk_leadership_h($moduleAccount['email']) ?></small></li>
          <li><div class="dropdown-divider"></div></li>
          <li><a class="dropdown-item" href="../logout.php"><i class="bx bx-power-off me-2"></i>Log Out</a></li>
        </ul>
      </li>
    </ul>
  </div>
</nav>
