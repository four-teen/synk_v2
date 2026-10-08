<?php
$studentSidebarCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
$studentSidebarItems = [
    [
        'key' => 'dashboard',
        'href' => 'index.php',
        'icon_bg' => 'bg-label-primary',
        'icon' => 'bx-home-circle',
        'title' => 'Dashboard',
        'description' => 'Current term overview and student quick access',
        'pages' => ['index.php'],
    ],
    [
        'key' => 'prospectus',
        'href' => 'prospectus.php',
        'icon_bg' => 'bg-label-success',
        'icon' => 'bx-book-content',
        'title' => 'Prospectus Viewer',
        'description' => 'Browse curriculum subjects by program and version',
        'pages' => ['prospectus.php'],
    ],
    [
        'key' => 'class_program',
        'href' => 'class-program.php',
        'icon_bg' => 'bg-label-info',
        'icon' => 'bx-user-voice',
        'title' => 'Faculty Evaluation',
        'description' => 'Evaluate faculty members who handled your subjects',
        'pages' => ['class-program.php'],
    ],
];

$studentSidebarActiveKey = 'dashboard';
foreach ($studentSidebarItems as $sidebarItem) {
    if (in_array($studentSidebarCurrentPage, $sidebarItem['pages'], true)) {
        $studentSidebarActiveKey = $sidebarItem['key'];
        break;
    }
}
?>

<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
  <div class="app-brand demo">
    <a href="<?php echo htmlspecialchars(function_exists('synk_student_build_portal_url') ? synk_student_build_portal_url('index.php') : 'index.php', ENT_QUOTES, 'UTF-8'); ?>" class="app-brand-link">
      <span class="app-brand-logo demo">
        <img src="../assets/img/favicon/synk-icon.png" width="40" height="40" alt="" />
      </span>
      <span class="app-brand-text demo menu-text fw-bolder ms-2">Synk Student</span>
    </a>

    <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto d-block d-xl-none">
      <i class="bx bx-chevron-left bx-sm align-middle"></i>
    </a>
  </div>

  <style>
    #layout-menu .student-menu-card {
      --student-card-radius: 0.78rem;
      position: relative;
      display: flex;
      align-items: center;
      gap: 0.65rem;
      padding: 0.78rem 0.82rem;
      margin: 0.42rem 0.2rem;
      border: 1px solid #e4e8f0;
      border-radius: var(--student-card-radius);
      background: #ffffff;
      transition: all 0.2s ease;
      white-space: normal;
      overflow: visible;
      isolation: isolate;
      text-decoration: none !important;
    }

    #layout-menu .student-menu-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      width: 0.34rem;
      height: 0.34rem;
      border-radius: 999px;
      background: radial-gradient(
        circle,
        rgba(255, 221, 186, 0.98) 0%,
        rgba(244, 149, 63, 0.96) 38%,
        rgba(179, 83, 15, 0.88) 62%,
        rgba(179, 83, 15, 0) 100%
      );
      box-shadow:
        0 0 4px rgba(201, 104, 28, 0.8),
        0 0 8px rgba(201, 104, 28, 0.3);
      opacity: 0;
      offset-anchor: center;
      offset-path: inset(0.5px round calc(var(--student-card-radius) - 0.5px));
      offset-distance: 0%;
      animation: student-sidebar-border-orbit 4s linear infinite paused;
      transition: opacity 0.2s ease;
      pointer-events: none;
      z-index: 3;
    }

    #layout-menu .student-menu-card:hover::before {
      opacity: 1;
      animation-play-state: running;
    }

    #layout-menu .student-menu-card > * {
      position: relative;
      z-index: 2;
    }

    #layout-menu .student-menu-card:hover {
      border-color: #d7b693;
      box-shadow: 0 6px 14px rgba(51, 71, 103, 0.09);
      transform: translateY(-1px);
    }

    #layout-menu .student-menu-card.active {
      border-color: #696cff;
      background: #f6f7ff;
      box-shadow: 0 6px 14px rgba(105, 108, 255, 0.12);
    }

    #layout-menu .student-menu-icon {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 34px;
      font-size: 1rem;
    }

    #layout-menu .student-menu-content {
      min-width: 0;
    }

    #layout-menu .student-menu-title {
      font-size: 0.87rem;
      font-weight: 600;
      line-height: 1.08rem;
      color: #364152;
    }

    #layout-menu .student-menu-desc {
      display: block;
      margin-top: 0.14rem;
      font-size: 0.72rem;
      color: #8391a7;
      line-height: 0.92rem;
    }

    @keyframes student-sidebar-border-orbit {
      to {
        offset-distance: 100%;
      }
    }
  </style>

  <div class="menu-inner-shadow"></div>

  <ul class="menu-inner py-1">
    <li class="menu-header small text-uppercase mt-1">
      <span class="menu-header-text">Student Portal</span>
    </li>

    <?php foreach ($studentSidebarItems as $sidebarItem): ?>
      <li class="menu-item px-2<?php echo $studentSidebarActiveKey === $sidebarItem['key'] ? ' active' : ''; ?>">
        <a
          href="<?php echo htmlspecialchars(function_exists('synk_student_build_portal_url') ? synk_student_build_portal_url($sidebarItem['href']) : $sidebarItem['href'], ENT_QUOTES, 'UTF-8'); ?>"
          class="menu-link student-menu-card<?php echo $studentSidebarActiveKey === $sidebarItem['key'] ? ' active' : ''; ?>"
        >
          <span class="student-menu-icon <?php echo htmlspecialchars($sidebarItem['icon_bg'], ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bx <?php echo htmlspecialchars($sidebarItem['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
          </span>
          <div class="student-menu-content">
            <div class="student-menu-title"><?php echo htmlspecialchars($sidebarItem['title'], ENT_QUOTES, 'UTF-8'); ?></div>
            <small class="student-menu-desc"><?php echo htmlspecialchars($sidebarItem['description'], ENT_QUOTES, 'UTF-8'); ?></small>
          </div>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</aside>
