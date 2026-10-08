<?php
$sidebarCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
$sidebarActiveKey = 'dashboard';
$sidebarOpenGroupKey = 'access_term_control';

$sidebarSections = [
    [
        'label' => 'Overview',
        'items' => [
            [
                'key' => 'dashboard',
                'href' => 'index.php',
                'icon_bg' => 'bg-label-primary',
                'icon' => 'bx-home-circle',
                'title' => 'Dashboard',
                'description' => 'Institution-wide scheduling analytics',
                'pages' => ['index.php'],
            ],
        ],
    ],
    [
        'label' => 'Administration Workflow',
        'groups' => [
            [
                'key' => 'access_term_control',
                'icon_bg' => 'bg-label-primary',
                'icon' => 'bx-shield-quarter',
                'title' => 'Access and Term Control',
                'description' => 'Manage account access and define the active academic term',
                'badge' => 'Core Control',
                'items' => [
                    [
                        'key' => 'accounts',
                        'href' => 'manage-accounts.php',
                        'icon_bg' => 'bg-label-info',
                        'icon' => 'bx-user',
                        'title' => 'User Accounts',
                        'description' => 'Manage administrators and scheduler access',
                        'pages' => ['manage-accounts.php'],
                    ],
                    [
                        'key' => 'academic_settings',
                        'href' => 'academic-settings.php',
                        'icon_bg' => 'bg-label-success',
                        'icon' => 'bx-calendar-event',
                        'title' => 'Academic Settings',
                        'description' => 'Set the active academic year and semester',
                        'pages' => ['academic-settings.php'],
                    ],
                    [
                        'key' => 'term_data_reset',
                        'href' => 'manage-term-data.php',
                        'icon_bg' => 'bg-label-danger',
                        'icon' => 'bx-refresh',
                        'title' => 'Term Data Reset',
                        'description' => 'Clear scoped scheduling data for a college term',
                        'pages' => ['manage-term-data.php'],
                    ],
                    [
                        'key' => 'database_backup',
                        'href' => 'database-backup.php',
                        'icon_bg' => 'bg-label-success',
                        'icon' => 'bx-data',
                        'title' => 'Database Backup',
                        'description' => 'Download a full SQL backup from the application',
                        'pages' => ['database-backup.php'],
                    ],
                ],
            ],
            [
                'key' => 'institution_setup',
                'icon_bg' => 'bg-label-success',
                'icon' => 'bx-buildings',
                'title' => 'Institution Setup',
                'description' => 'Maintain the institution structure before scheduling begins',
                'badge' => 'Structure',
                'items' => [
                    [
                        'key' => 'campuses',
                        'href' => 'manage-campuses.php',
                        'icon_bg' => 'bg-label-success',
                        'icon' => 'bx-buildings',
                        'title' => 'Campuses',
                        'description' => 'Maintain campus records and locations',
                        'pages' => ['manage-campuses.php'],
                    ],
                    [
                        'key' => 'colleges',
                        'href' => 'manage-colleges.php',
                        'icon_bg' => 'bg-label-warning',
                        'icon' => 'bx-library',
                        'title' => 'Colleges',
                        'description' => 'Organize college structures',
                        'pages' => ['manage-colleges.php'],
                    ],
                    [
                        'key' => 'programs',
                        'href' => 'manage-programs.php',
                        'icon_bg' => 'bg-label-secondary',
                        'icon' => 'bx-book-content',
                        'title' => 'Programs',
                        'description' => 'Configure degree and course offerings',
                        'pages' => ['manage-programs.php'],
                    ],
                    [
                        'key' => 'designations',
                        'href' => 'manage-designations.php',
                        'icon_bg' => 'bg-label-info',
                        'icon' => 'bx-id-card',
                        'title' => 'Designation List',
                        'description' => 'Maintain designation names and corresponding unit values',
                        'pages' => ['manage-designations.php'],
                    ],
                    [
                        'key' => 'faculty',
                        'href' => 'manage-faculty.php',
                        'icon_bg' => 'bg-label-primary',
                        'icon' => 'bx-user-voice',
                        'title' => 'Faculty Masterlist',
                        'description' => 'Manage faculty records and profiles',
                        'pages' => ['manage-faculty.php'],
                    ],
                    [
                        'key' => 'student_management',
                        'href' => 'students/index.php',
                        'icon_bg' => 'bg-label-success',
                        'icon' => 'bx-group',
                        'title' => 'Student Management',
                        'description' => 'Open the separate student upload and directory module',
                        'pages' => [],
                    ],
                ],
            ],
            [
                'key' => 'curriculum_reporting',
                'icon_bg' => 'bg-label-warning',
                'icon' => 'bx-book-reader',
                'title' => 'Curriculum and Reporting',
                'description' => 'Prepare curriculum structures and review institutional outputs',
                'badge' => 'Analytics',
                'items' => [
                    [
                        'key' => 'subjects',
                        'href' => 'manage-subject-masterlist.php',
                        'icon_bg' => 'bg-label-danger',
                        'icon' => 'bx-notepad',
                        'title' => 'Subject Catalog',
                        'description' => 'Maintain the institutional subject masterlist',
                        'pages' => ['manage-subject-masterlist.php'],
                    ],
                    [
                        'key' => 'prospectus_builder',
                        'href' => 'manage-prospectus.php',
                        'icon_bg' => 'bg-label-primary',
                        'icon' => 'bx-book-bookmark',
                        'title' => 'Prospectus Builder',
                        'description' => 'Build curriculum flow by year and term',
                        'pages' => ['manage-prospectus.php'],
                    ],
                    [
                        'key' => 'prospectus_viewer',
                        'href' => 'manage-prospectus-browser.php',
                        'icon_bg' => 'bg-label-info',
                        'icon' => 'bx-search-alt-2',
                        'title' => 'Prospectus Viewer',
                        'description' => 'Review published curriculum structures',
                        'pages' => ['manage-prospectus-browser.php', 'view-prospectus.php'],
                    ],
                    [
                        'key' => 'offering_enrollees',
                        'href' => 'manage-offering-enrollees.php',
                        'icon_bg' => 'bg-label-warning',
                        'icon' => 'bx-group',
                        'title' => 'Offering Enrollees',
                        'description' => 'Set dummy enrollee counts for generated offerings',
                        'pages' => ['manage-offering-enrollees.php'],
                    ],
                    [
                        'key' => 'institutional_report',
                        'href' => 'institutional_report.php',
                        'icon_bg' => 'bg-label-info',
                        'icon' => 'bx-bar-chart-alt-2',
                        'title' => 'Institutional Reports',
                        'description' => 'View campus and academic summaries',
                        'pages' => ['institutional_report.php', 'report_academic_operations.php', 'campus_dashboard.php'],
                    ],
                ],
            ],
            [
                'key' => 'monitoring',
                'icon_bg' => 'bg-label-info',
                'icon' => 'bx-line-chart',
                'title' => 'Monitoring',
                'description' => 'Review campus-level monitoring reports and printable listings',
                'badge' => 'Reports',
                'items' => [
                    [
                        'key' => 'faculty_load_monitoring',
                        'href' => 'monitoring-faculty-load.php',
                        'icon_bg' => 'bg-label-info',
                        'icon' => 'bx-user-pin',
                        'title' => 'Faculty Load Monitoring',
                        'description' => 'Track faculty load, units, and preparations by campus and college',
                        'pages' => ['monitoring-faculty-load.php'],
                    ],
                    [
                        'key' => 'workload_transactions',
                        'href' => 'manage-workload-transactions.php',
                        'icon_bg' => 'bg-label-warning',
                        'icon' => 'bx-history',
                        'title' => 'User Transactions',
                        'description' => 'Audit workload changes across all scheduler accounts',
                        'pages' => ['manage-workload-transactions.php'],
                    ],
                    [
                        'key' => 'alphabetical_courses',
                        'href' => 'report_alphabetical_courses.php',
                        'icon_bg' => 'bg-label-primary',
                        'icon' => 'bx-list-ul',
                        'title' => 'Alphabetical List of Courses',
                        'description' => 'Print and export campus-scoped course listings',
                        'pages' => ['report_alphabetical_courses.php'],
                    ],
                ],
            ],
        ],
    ],
];

$sidebarMatchFound = false;

foreach ($sidebarSections as $section) {
    foreach (($section['items'] ?? []) as $item) {
        if (in_array($sidebarCurrentPage, $item['pages'], true)) {
            $sidebarActiveKey = $item['key'];
            $sidebarMatchFound = true;
            break;
        }
    }

    if ($sidebarMatchFound) {
        break;
    }

    foreach (($section['groups'] ?? []) as $group) {
        foreach ($group['items'] as $item) {
            if (in_array($sidebarCurrentPage, $item['pages'], true)) {
                $sidebarActiveKey = $item['key'];
                $sidebarOpenGroupKey = $group['key'];
                $sidebarMatchFound = true;
                break 2;
            }
        }
    }

    if ($sidebarMatchFound) {
        break;
    }
}
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

  <style>
    #layout-menu {
      height: 100vh;
      max-height: 100vh;
      overflow: hidden;
    }

    #layout-menu .app-brand {
      flex: 0 0 auto;
    }

    #layout-menu .menu-inner {
      min-height: 0;
      height: auto;
      max-height: calc(100vh - 4.625rem);
      overflow-x: hidden !important;
      overflow-y: auto !important;
      padding-bottom: 1rem;
      overscroll-behavior: contain;
      -webkit-overflow-scrolling: touch;
      touch-action: pan-y;
      scrollbar-width: thin;
      scrollbar-color: rgba(105, 108, 255, 0.42) transparent;
    }

    #layout-menu .menu-inner::-webkit-scrollbar {
      width: 0.38rem;
    }

    #layout-menu .menu-inner::-webkit-scrollbar-track {
      background: transparent;
    }

    #layout-menu .menu-inner::-webkit-scrollbar-thumb {
      border-radius: 999px;
      background: rgba(105, 108, 255, 0.38);
    }

    #layout-menu .menu-inner:hover::-webkit-scrollbar-thumb {
      background: rgba(105, 108, 255, 0.58);
    }

    #layout-menu .menu-inner.ps {
      position: relative;
    }

    #layout-menu .menu-inner.ps > .ps__rail-x,
    #layout-menu .menu-inner.ps > .ps__rail-y {
      display: none !important;
    }

    #layout-menu .sidebar-action-card {
      --sidebar-card-radius: 0.75rem;
      position: relative;
      display: flex;
      align-items: center;
      gap: 0.65rem;
      padding: 0.72rem 0.78rem;
      margin: 0.42rem 0.2rem;
      border: 1px solid #e4e8f0;
      border-radius: var(--sidebar-card-radius);
      background: #ffffff;
      transition: all 0.2s ease;
      white-space: normal;
      overflow: visible;
      isolation: isolate;
    }

    #layout-menu .sidebar-action-card::before,
    #layout-menu .sidebar-subcard::before {
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
      offset-path: inset(0.5px round calc(var(--sidebar-card-radius) - 0.5px));
      offset-distance: 0%;
      animation: sidebar-border-orbit 4s linear infinite paused;
      transition: opacity 0.2s ease;
      pointer-events: none;
      z-index: 3;
    }

    #layout-menu .sidebar-action-card:hover::before,
    #layout-menu .sidebar-subcard:hover::before {
      opacity: 1;
      animation-play-state: running;
    }

    #layout-menu .sidebar-action-card > *,
    #layout-menu .sidebar-subcard > * {
      position: relative;
      z-index: 2;
    }

    #layout-menu .sidebar-action-card:hover {
      border-color: #d7b693;
      box-shadow: 0 6px 14px rgba(51, 71, 103, 0.09);
      transform: translateY(-1px);
    }

    #layout-menu .sidebar-action-card.active {
      border-color: #696cff;
      background: #f6f7ff;
    }

    #layout-menu .menu-item.open > .sidebar-group-card,
    #layout-menu .sidebar-group-card.is-open {
      border-color: #d5dbff;
      background: #f8f9ff;
    }

    #layout-menu .sidebar-group-card {
      padding-right: 2.1rem;
    }

    #layout-menu .sidebar-group-card::after {
      right: 0.9rem;
      color: #6b778c;
      z-index: 2;
    }

    #layout-menu .sidebar-action-icon {
      width: 32px;
      height: 32px;
      border-radius: 10px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 32px;
      font-size: 1rem;
    }

    #layout-menu .sidebar-action-content {
      min-width: 0;
    }

    #layout-menu .sidebar-action-title {
      font-size: 0.86rem;
      font-weight: 600;
      line-height: 1.05rem;
      color: #364152;
    }

    #layout-menu .sidebar-action-sub {
      display: block;
      margin-top: 0.14rem;
      font-size: 0.72rem;
      color: #8391a7;
      line-height: 0.92rem;
    }

    #layout-menu .sidebar-group-badge {
      display: inline-flex;
      margin-top: 0.35rem;
      padding: 0.16rem 0.42rem;
      border-radius: 999px;
      background: #eef2ff;
      color: #5561d7;
      font-size: 0.66rem;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }

    #layout-menu .sidebar-submenu {
      gap: 0.28rem;
      padding: 0.35rem 0.1rem 0.45rem;
      margin: 0 0.2rem 0.45rem;
    }

    #layout-menu .sidebar-submenu .menu-item {
      width: 100%;
    }

    #layout-menu .sidebar-subcard {
      --sidebar-card-radius: 0.72rem;
      position: relative;
      display: flex;
      align-items: center;
      gap: 0.58rem;
      padding: 0.62rem 0.75rem;
      border-radius: var(--sidebar-card-radius);
      border: 1px solid transparent;
      background: #f8fafc;
      white-space: normal;
      transition: all 0.2s ease;
      overflow: visible;
      isolation: isolate;
    }

    #layout-menu .sidebar-subcard:hover {
      background: #ffffff;
      border-color: #d7b693;
    }

    #layout-menu .sidebar-submenu .menu-item.active > .sidebar-subcard {
      border-color: #696cff;
      background: #f6f7ff;
      box-shadow: 0 6px 14px rgba(105, 108, 255, 0.12);
    }

    #layout-menu .sidebar-subicon {
      width: 28px;
      height: 28px;
      border-radius: 9px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 28px;
      font-size: 0.92rem;
    }

    #layout-menu .sidebar-subcontent {
      min-width: 0;
    }

    #layout-menu .sidebar-subtitle {
      font-size: 0.8rem;
      font-weight: 600;
      line-height: 1rem;
      color: #364152;
    }

    #layout-menu .sidebar-subdesc {
      display: block;
      margin-top: 0.12rem;
      font-size: 0.69rem;
      color: #8391a7;
      line-height: 0.88rem;
    }

    @keyframes sidebar-border-orbit {
      to {
        offset-distance: 100%;
      }
    }
  </style>

  <div class="menu-inner-shadow"></div>

  <ul class="menu-inner py-1">
    <?php foreach ($sidebarSections as $index => $section): ?>
      <li class="menu-header small text-uppercase<?php echo $index === 0 ? ' mt-1' : ' mt-2'; ?>">
        <span class="menu-header-text"><?php echo htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </li>

      <?php foreach (($section['items'] ?? []) as $item): ?>
        <li class="menu-item px-2<?php echo $sidebarActiveKey === $item['key'] ? ' active' : ''; ?>">
          <a
            href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
            class="menu-link sidebar-action-card<?php echo $sidebarActiveKey === $item['key'] ? ' active' : ''; ?>"
          >
            <span class="sidebar-action-icon <?php echo htmlspecialchars($item['icon_bg'], ENT_QUOTES, 'UTF-8'); ?>">
              <i class="bx <?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
            </span>
            <div class="sidebar-action-content">
              <div class="sidebar-action-title"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></div>
              <small class="sidebar-action-sub"><?php echo htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8'); ?></small>
            </div>
          </a>
        </li>
      <?php endforeach; ?>

      <?php foreach (($section['groups'] ?? []) as $group): ?>
        <?php
          $groupHasActiveItem = false;

          foreach ($group['items'] as $groupItem) {
              if ($sidebarActiveKey === $groupItem['key']) {
                  $groupHasActiveItem = true;
                  break;
              }
          }

          $groupIsOpen = $sidebarOpenGroupKey === $group['key'];
        ?>
        <li class="menu-item px-2<?php echo $groupHasActiveItem ? ' active' : ''; ?><?php echo $groupIsOpen ? ' open' : ''; ?>">
          <a
            href="javascript:void(0);"
            class="menu-link menu-toggle sidebar-action-card sidebar-group-card<?php echo $groupHasActiveItem ? ' active' : ''; ?><?php echo $groupIsOpen ? ' is-open' : ''; ?>"
          >
            <span class="sidebar-action-icon <?php echo htmlspecialchars($group['icon_bg'], ENT_QUOTES, 'UTF-8'); ?>">
              <i class="bx <?php echo htmlspecialchars($group['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
            </span>
            <div class="sidebar-action-content">
              <div class="sidebar-action-title"><?php echo htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8'); ?></div>
              <small class="sidebar-action-sub"><?php echo htmlspecialchars($group['description'], ENT_QUOTES, 'UTF-8'); ?></small>
              <span class="sidebar-group-badge"><?php echo htmlspecialchars($group['badge'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
          </a>

          <ul class="menu-sub sidebar-submenu">
            <?php foreach ($group['items'] as $item): ?>
              <li class="menu-item<?php echo $sidebarActiveKey === $item['key'] ? ' active' : ''; ?>">
                <a
                  href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
                  class="menu-link sidebar-subcard"
                >
                  <span class="sidebar-subicon <?php echo htmlspecialchars($item['icon_bg'], ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bx <?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                  </span>
                  <div class="sidebar-subcontent">
                    <div class="sidebar-subtitle"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <small class="sidebar-subdesc"><?php echo htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8'); ?></small>
                  </div>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </li>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </ul>
</aside>
