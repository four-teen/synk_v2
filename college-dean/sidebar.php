<?php
$collegeDeanSidebarCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
$collegePortalIsChair = ($_SESSION['role'] ?? '') === 'program_chair';
$collegeDeanSidebarItems = [
    [
        'key' => 'dashboard',
        'href' => 'index.php',
        'icon_bg' => 'bg-label-primary',
        'icon' => 'bx-home-circle',
        'title' => 'Dashboard',
        'description' => 'College metrics, scheduling, and faculty workload',
        'pages' => ['index.php'],
    ],
    [
        'key' => 'faculty-workload',
        'href' => 'faculty-workload.php',
        'icon_bg' => 'bg-label-primary',
        'icon' => 'bx-briefcase-alt-2',
        'title' => 'Faculty Workload',
        'description' => 'Assigned classes and student lists',
        'pages' => ['faculty-workload.php'],
    ],
    [
        'key' => 'faculty-profile',
        'href' => 'faculty-profile.php',
        'icon_bg' => 'bg-label-success',
        'icon' => 'bx-id-card',
        'title' => 'Faculty profile',
        'description' => 'Manage faculty details and qualifications',
        'pages' => ['faculty-profile.php', 'manage-faculty-profile.php'],
    ],
    [
        'key' => 'programs',
        'href' => 'programs.php',
        'icon_bg' => 'bg-label-info',
        'icon' => 'bx-book-content',
        'title' => 'Programs',
        'description' => 'All degree programs under the assigned college',
        'pages' => ['programs.php'],
    ],
    [
        'key' => 'students',
        'href' => 'students.php',
        'icon_bg' => 'bg-label-secondary',
        'icon' => 'bx-user-pin',
        'title' => 'Students',
        'description' => 'Unique students and imported subject lists',
        'pages' => ['students.php'],
    ],
    [
        'key' => 'faculty-evaluations',
        'href' => 'faculty-evaluations.php',
        'icon_bg' => 'bg-label-info',
        'icon' => 'bx-check-square',
        'title' => 'Faculty Evaluation',
        'description' => 'Current and previous student evaluation results',
        'pages' => ['faculty-evaluations.php'],
    ],
    [
        'key' => 'reports',
        'href' => 'reports.php',
        'icon_bg' => 'bg-label-dark',
        'icon' => 'bx-bar-chart-alt-2',
        'title' => 'Reports',
        'description' => 'Student and faculty summaries',
        'pages' => ['reports.php', 'student-statistics.php', 'faculty-statistics.php'],
        'children' => [
            ['href' => 'reports.php', 'title' => 'Faculty Student Summary', 'icon' => 'bx-list-ul'],
            ['href' => 'student-statistics.php', 'title' => 'Student Statistics', 'icon' => 'bx-group'],
            ['href' => 'faculty-statistics.php', 'title' => 'Faculty Statistics', 'icon' => 'bx-bar-chart-alt-2'],
        ],
    ],
];

if ($collegePortalIsChair) {
    array_splice($collegeDeanSidebarItems, 1, 0, [[
        'key' => 'manage-college-faculty', 'href' => 'manage-college-faculty.php',
        'icon_bg' => 'bg-label-success', 'icon' => 'bx-user-plus',
        'title' => 'College Faculty', 'description' => 'Manage faculty assignments and lists',
        'pages' => ['manage-college-faculty.php'],
    ]]);
}

$collegeDeanSidebarActiveKey = 'dashboard';
foreach ($collegeDeanSidebarItems as $sidebarItem) {
    if (in_array($collegeDeanSidebarCurrentPage, $sidebarItem['pages'], true)) {
        $collegeDeanSidebarActiveKey = $sidebarItem['key'];
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
    #layout-menu .college-dean-menu-card {
      --college-dean-card-radius: 0.78rem;
      position: relative;
      display: flex;
      align-items: center;
      gap: 0.65rem;
      padding: 0.78rem 0.82rem;
      margin: 0.42rem 0.2rem;
      border: 1px solid #e4e8f0;
      border-radius: var(--college-dean-card-radius);
      background: #ffffff;
      transition: all 0.2s ease;
      white-space: normal;
      overflow: visible;
      isolation: isolate;
      text-decoration: none !important;
    }

    #layout-menu .college-dean-menu-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      width: 0.34rem;
      height: 0.34rem;
      border-radius: 999px;
      background: radial-gradient(circle, rgba(255, 221, 186, 0.98) 0%, rgba(244, 149, 63, 0.96) 38%, rgba(179, 83, 15, 0.88) 62%, rgba(179, 83, 15, 0) 100%);
      box-shadow: 0 0 4px rgba(201, 104, 28, 0.8), 0 0 8px rgba(201, 104, 28, 0.3);
      opacity: 0;
      offset-anchor: center;
      offset-path: inset(0.5px round calc(var(--college-dean-card-radius) - 0.5px));
      offset-distance: 0%;
      animation: college-dean-sidebar-border-orbit 4s linear infinite paused;
      transition: opacity 0.2s ease;
      pointer-events: none;
      z-index: 3;
    }

    #layout-menu .college-dean-menu-card:hover::before {
      opacity: 1;
      animation-play-state: running;
    }

    #layout-menu .college-dean-menu-card > * {
      position: relative;
      z-index: 2;
    }

    #layout-menu .college-dean-menu-card:hover {
      border-color: #d7b693;
      box-shadow: 0 6px 14px rgba(51, 71, 103, 0.09);
      transform: translateY(-1px);
    }

    #layout-menu .college-dean-menu-card.active {
      border-color: #696cff;
      background: #f6f7ff;
      box-shadow: 0 6px 14px rgba(105, 108, 255, 0.12);
    }

    #layout-menu .college-dean-menu-icon {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 34px;
      font-size: 1rem;
    }

    #layout-menu .college-dean-menu-content {
      min-width: 0;
    }

    #layout-menu .college-dean-menu-title {
      font-size: 0.87rem;
      font-weight: 600;
      line-height: 1.08rem;
      color: #364152;
    }

    #layout-menu .college-dean-menu-desc {
      display: block;
      margin-top: 0.14rem;
      font-size: 0.72rem;
      color: #8391a7;
      line-height: 0.92rem;
    }

    #layout-menu .college-dean-report-group > summary { list-style: none; cursor: pointer; }
    #layout-menu .college-dean-report-group > summary::-webkit-details-marker { display: none; }
    #layout-menu .college-dean-report-chevron { margin-left: auto; flex-shrink: 0; color: #8391a7; }
    #layout-menu .college-dean-report-group[open] .college-dean-report-chevron { transform: rotate(90deg); }
    #layout-menu .college-dean-report-links { list-style: none; padding: 0 0 .4rem 1rem; margin: 0 .2rem; }
    #layout-menu .college-dean-report-link { display: flex; align-items: center; gap: .55rem; padding: .7rem .6rem; margin: .15rem 0; border-radius: .5rem; color: #697a8d; font-size: .78rem; line-height: 1.3; }
    #layout-menu .college-dean-report-link:hover, #layout-menu .college-dean-report-link:focus-visible { background: #f2f2ff; color: #696cff; }
    #layout-menu .college-dean-report-link.is-active { background: #eeefff; color: #5457d5; font-weight: 600; }
    #layout-menu .college-dean-report-link i { flex-shrink: 0; font-size: 1rem; }

    @keyframes college-dean-sidebar-border-orbit {
      to {
        offset-distance: 100%;
      }
    }
  </style>

  <div class="menu-inner-shadow"></div>

  <ul class="menu-inner py-1">
    <li class="menu-header small text-uppercase mt-1">
      <span class="menu-header-text"><?php echo $collegePortalIsChair ? 'Program Chair Portal' : 'College Dean Portal'; ?></span>
    </li>

    <?php foreach ($collegeDeanSidebarItems as $sidebarItem): ?>
      <li class="menu-item px-2<?php echo $collegeDeanSidebarActiveKey === $sidebarItem['key'] ? ' active' : ''; ?>">
        <?php if (!empty($sidebarItem['children'])): ?>
          <details class="college-dean-report-group"<?php echo $collegeDeanSidebarActiveKey === $sidebarItem['key'] ? ' open' : ''; ?>>
            <summary class="college-dean-menu-card<?php echo $collegeDeanSidebarActiveKey === $sidebarItem['key'] ? ' active' : ''; ?>">
              <span class="college-dean-menu-icon <?php echo htmlspecialchars($sidebarItem['icon_bg'], ENT_QUOTES, 'UTF-8'); ?>"><i class="bx <?php echo htmlspecialchars($sidebarItem['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i></span>
              <div class="college-dean-menu-content"><div class="college-dean-menu-title"><?php echo htmlspecialchars($sidebarItem['title'], ENT_QUOTES, 'UTF-8'); ?></div><small class="college-dean-menu-desc"><?php echo htmlspecialchars($sidebarItem['description'], ENT_QUOTES, 'UTF-8'); ?></small></div>
              <i class="bx bx-chevron-right college-dean-report-chevron" aria-hidden="true"></i>
            </summary>
            <ul class="college-dean-report-links">
              <?php foreach ($sidebarItem['children'] as $reportItem): $reportIsActive = $collegeDeanSidebarCurrentPage === $reportItem['href']; ?>
                <li><a href="<?php echo htmlspecialchars($reportItem['href'], ENT_QUOTES, 'UTF-8'); ?>" class="college-dean-report-link<?php echo $reportIsActive ? ' is-active' : ''; ?>"<?php echo $reportIsActive ? ' aria-current="page"' : ''; ?>><i class="bx <?php echo htmlspecialchars($reportItem['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i><span><?php echo htmlspecialchars($reportItem['title'], ENT_QUOTES, 'UTF-8'); ?></span></a></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php else: ?>
        <a
          href="<?php echo htmlspecialchars($sidebarItem['href'], ENT_QUOTES, 'UTF-8'); ?>"
          class="menu-link college-dean-menu-card<?php echo $collegeDeanSidebarActiveKey === $sidebarItem['key'] ? ' active' : ''; ?>"
        >
          <span class="college-dean-menu-icon <?php echo htmlspecialchars($sidebarItem['icon_bg'], ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bx <?php echo htmlspecialchars($sidebarItem['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
          </span>
          <div class="college-dean-menu-content">
            <div class="college-dean-menu-title"><?php echo htmlspecialchars($sidebarItem['title'], ENT_QUOTES, 'UTF-8'); ?></div>
            <small class="college-dean-menu-desc"><?php echo htmlspecialchars($sidebarItem['description'], ENT_QUOTES, 'UTF-8'); ?></small>
          </div>
        </a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</aside>
