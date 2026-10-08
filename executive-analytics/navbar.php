<?php
$execNavbarTitle = $execNavbarTitle ?? 'Executive Analytics';
$execNavbarSubtitle = $execNavbarSubtitle ?? 'Institution-wide academic signal deck';
$execNavbarTermText = $execNavbarTermText ?? 'Current academic term';
$execNavbarViewer = $execNavbarViewer ?? ['role_label' => 'Executive Viewer'];
$execNavbarShowAllCampusesLink = !empty($execNavbarShowAllCampusesLink);
?>

<nav
  class="layout-navbar container-xxl navbar navbar-expand-xl align-items-center bg-navbar-theme exec-topbar"
  id="layout-navbar"
>
  <div class="exec-topbar-inner">
    <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none exec-topbar-toggle">
      <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
        <i class="bx bx-menu bx-sm"></i>
      </a>
    </div>

    <div class="navbar-nav-right d-flex align-items-center w-100" id="navbar-collapse">
      <div class="exec-topbar-copy">
        <span class="exec-topbar-kicker"><?php echo synk_exec_analytics_h($execNavbarSubtitle); ?></span>
        <h4 class="exec-topbar-title"><?php echo synk_exec_analytics_h($execNavbarTitle); ?></h4>
      </div>

      <div class="exec-topbar-actions ms-auto">
        <?php if ($execNavbarShowAllCampusesLink): ?>
          <a href="index.php" class="btn exec-toolbar-button exec-toolbar-button-home">
            <i class="bx bx-grid-alt"></i>
            <span>All Campuses</span>
          </a>
        <?php endif; ?>

        <span class="exec-toolbar-chip exec-toolbar-chip-term">
          <i class="bx bx-calendar"></i>
          <span><?php echo synk_exec_analytics_h($execNavbarTermText); ?></span>
        </span>

        <span class="exec-toolbar-chip accent exec-toolbar-chip-role">
          <i class="bx <?php echo (string)($execNavbarViewer['role_key'] ?? '') === 'president' ? 'bx-crown' : 'bx-briefcase-alt-2'; ?>"></i>
          <span><?php echo synk_exec_analytics_h((string)($execNavbarViewer['role_label'] ?? 'Executive Viewer')); ?></span>
        </span>

        <a href="logout.php" class="btn exec-toolbar-button danger exec-toolbar-button-logout" aria-label="Log out" title="Log out">
          <i class="bx bx-log-out"></i>
          <span>Log Out</span>
        </a>
      </div>
    </div>
  </div>
</nav>
