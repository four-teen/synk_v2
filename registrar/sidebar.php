<?php
$registrarSidebarCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
$registrarSidebarItems = [
    [
        'key' => 'dashboard',
        'href' => 'index.php',
        'icon_bg' => 'bg-label-primary',
        'icon' => 'bx-grid-alt',
        'title' => 'Dashboard',
        'description' => 'Registrar term snapshot, campus counts, and charts',
        'pages' => ['index.php'],
    ],
    [
        'key' => 'queue',
        'href' => 'queue.php',
        'icon_bg' => 'bg-label-danger',
        'icon' => 'bx-clipboard-check',
        'title' => 'Registrar Queue',
        'description' => 'Submitted drafts waiting for campus registrar review',
        'pages' => ['queue.php'],
    ],
    [
        'key' => 'colleges',
        'href' => 'colleges.php',
        'icon_bg' => 'bg-label-success',
        'icon' => 'bx-buildings',
        'title' => 'Colleges',
        'description' => 'College-level enrollment flow across the campus scope',
        'pages' => ['colleges.php'],
    ],
    [
        'key' => 'programs',
        'href' => 'programs.php',
        'icon_bg' => 'bg-label-info',
        'icon' => 'bx-book-content',
        'title' => 'Programs',
        'description' => 'Program and course snapshots by current registrar term',
        'pages' => ['programs.php'],
    ],
    [
        'key' => 'reports',
        'href' => 'reports.php',
        'icon_bg' => 'bg-label-secondary',
        'icon' => 'bx-bar-chart-alt-2',
        'title' => 'Reports',
        'description' => 'Operational summaries and registrar-ready reference tables',
        'pages' => ['reports.php'],
    ],
];
?>

<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
  <div class="app-brand demo">
    <a href="index.php" class="app-brand-link">
      <span class="app-brand-logo demo">
        <img src="../assets/img/favicon/synk-icon.png" width="40" height="40" alt="" />
      </span>
      <span class="app-brand-text demo menu-text fw-bolder ms-2">Synk</span>
    </a>

    <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto d-block d-xl-none">
      <i class="bx bx-chevron-left bx-sm align-middle"></i>
    </a>
  </div>

  <ul class="menu-inner py-1">
    <li class="menu-header small text-uppercase mt-1">
      <span class="menu-header-text">Registrar Portal</span>
    </li>

    <?php foreach ($registrarSidebarItems as $sidebarItem): ?>
      <?php $isActive = in_array($registrarSidebarCurrentPage, $sidebarItem['pages'], true); ?>
      <li class="menu-item px-2<?php echo $isActive ? ' active' : ''; ?>">
        <a href="<?php echo htmlspecialchars($sidebarItem['href'], ENT_QUOTES, 'UTF-8'); ?>" class="menu-link d-flex align-items-start gap-2 rounded-3 border<?php echo $isActive ? ' border-primary bg-light' : ' border-light-subtle'; ?>">
          <span class="d-inline-flex align-items-center justify-content-center rounded-3 <?php echo htmlspecialchars($sidebarItem['icon_bg'], ENT_QUOTES, 'UTF-8'); ?>" style="width:34px;height:34px;">
            <i class="bx <?php echo htmlspecialchars($sidebarItem['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
          </span>
          <div class="flex-grow-1">
            <div class="fw-semibold"><?php echo htmlspecialchars($sidebarItem['title'], ENT_QUOTES, 'UTF-8'); ?></div>
            <small class="text-muted d-block"><?php echo htmlspecialchars($sidebarItem['description'], ENT_QUOTES, 'UTF-8'); ?></small>
          </div>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</aside>
