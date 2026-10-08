<?php
$studentSidebarPage = basename($_SERVER['PHP_SELF'] ?? '');
$studentSidebarSections = [
    [
        'label' => 'Overview',
        'items' => [
            [
                'href' => 'index.php',
                'icon_bg' => 'bg-label-primary',
                'icon' => 'bx-home-circle',
                'title' => 'Dashboard',
                'description' => 'Student module summary and year-level chart',
                'pages' => ['index.php'],
            ],
        ],
    ],
    [
        'label' => 'Student Workflow',
        'items' => [
            [
                'href' => 'exam-permits.php',
                'icon_bg' => 'bg-label-info',
                'icon' => 'bx-upload',
                'title' => 'Backward Encoding CSV',
                'description' => 'Upload old-system student roster CSV files into the rebuilt student tables',
                'pages' => ['upload.php', 'exam-permits.php'],
            ],
            [
                'href' => 'directory.php',
                'icon_bg' => 'bg-label-success',
                'icon' => 'bx-group',
                'title' => 'Student Directory',
                'description' => 'Review and filter imported student records',
                'pages' => ['directory.php'],
            ],
            [
                'href' => 'faculty-evaluation-reports.php',
                'icon_bg' => 'bg-label-warning',
                'icon' => 'bx-bar-chart-alt-2',
                'title' => 'Faculty Evaluation Reports',
                'description' => 'Review EFP-02, EFP-04, and EFP-05 faculty evaluation reports',
                'pages' => ['faculty-evaluation-reports.php'],
            ],
        ],
    ],
];
?>

<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
  <div class="app-brand demo">
    <a href="index.php" class="app-brand-link">
      <span class="app-brand-logo demo">
        <i class="bx bx-id-card fs-3 text-primary"></i>
      </span>
      <span class="app-brand-text demo menu-text fw-bolder ms-2">Students</span>
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

    #layout-menu .student-sidebar-intro {
      margin: 0.95rem 0.95rem 1rem;
      padding: 1rem;
      border-radius: 1.1rem;
      background: linear-gradient(160deg, #14213d 0%, #1f3c88 100%);
      color: #fff;
    }

    #layout-menu .student-sidebar-intro .eyebrow {
      display: inline-flex;
      padding: 0.24rem 0.55rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.16);
      font-size: 0.67rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    #layout-menu .student-sidebar-intro h6 {
      margin: 0.9rem 0 0.4rem;
      color: #fff;
      font-size: 1rem;
    }

    #layout-menu .student-sidebar-intro p {
      margin: 0;
      font-size: 0.78rem;
      line-height: 1.18rem;
      color: rgba(255, 255, 255, 0.78);
    }

    #layout-menu .student-sidebar-card {
      display: flex;
      align-items: center;
      gap: 0.8rem;
      margin: 0.42rem 0.75rem;
      padding: 0.85rem 0.9rem;
      border: 1px solid #e4e8f0;
      border-radius: 1rem;
      background: #fff;
      transition: all 0.2s ease;
      white-space: normal;
    }

    #layout-menu .student-sidebar-card:hover {
      transform: translateY(-1px);
      border-color: #cfd6e4;
      box-shadow: 0 10px 22px rgba(31, 41, 55, 0.08);
    }

    #layout-menu .menu-item.active > .student-sidebar-card {
      border-color: #696cff;
      background: linear-gradient(135deg, #f6f7ff 0%, #eef2ff 100%);
      box-shadow: 0 12px 26px rgba(105, 108, 255, 0.12);
    }

    #layout-menu .student-sidebar-icon {
      width: 40px;
      height: 40px;
      border-radius: 0.9rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 40px;
      font-size: 1.1rem;
    }

    #layout-menu .student-sidebar-content {
      min-width: 0;
    }

    #layout-menu .student-sidebar-title {
      font-size: 0.88rem;
      font-weight: 700;
      color: #364152;
      line-height: 1.1rem;
    }

    #layout-menu .student-sidebar-desc {
      display: block;
      margin-top: 0.15rem;
      font-size: 0.72rem;
      line-height: 0.95rem;
      color: #7b8798;
    }

    #layout-menu .student-sidebar-back {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      margin: 1rem 0.75rem 0;
      padding: 0.85rem 0.9rem;
      border-radius: 1rem;
      border: 1px solid #d8e0ee;
      color: #364152;
      background: #f8fafc;
      font-weight: 600;
      transition: all 0.2s ease;
    }

    #layout-menu .student-sidebar-back:hover {
      color: #1f3c88;
      border-color: #c7d2fe;
      background: #eef2ff;
    }
  </style>

  <div class="menu-inner-shadow"></div>

  <ul class="menu-inner py-1">
    <li class="px-2">
      <div class="student-sidebar-intro">
        <span class="eyebrow">Separate Module</span>
        <h6>Student Management</h6>
        <p>Dedicated dashboard, upload, and directory workspace for administrator use.</p>
      </div>
    </li>

    <?php foreach ($studentSidebarSections as $index => $section): ?>
      <li class="menu-header small text-uppercase<?php echo $index === 0 ? ' mt-1' : ' mt-2'; ?>">
        <span class="menu-header-text"><?php echo htmlspecialchars($section['label'], ENT_QUOTES, 'UTF-8'); ?></span>
      </li>

      <?php foreach ($section['items'] as $item): ?>
        <?php $isActive = in_array($studentSidebarPage, $item['pages'], true); ?>
        <li class="menu-item px-2<?php echo $isActive ? ' active' : ''; ?>">
          <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>" class="menu-link student-sidebar-card">
            <span class="student-sidebar-icon <?php echo htmlspecialchars($item['icon_bg'], ENT_QUOTES, 'UTF-8'); ?>">
              <i class="bx <?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
            </span>
            <span class="student-sidebar-content">
              <span class="student-sidebar-title"><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></span>
              <small class="student-sidebar-desc"><?php echo htmlspecialchars($item['description'], ENT_QUOTES, 'UTF-8'); ?></small>
            </span>
          </a>
        </li>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <li class="menu-header small text-uppercase mt-2">
      <span class="menu-header-text">Navigation</span>
    </li>
    <li class="px-2">
      <a href="../index.php" class="student-sidebar-back text-decoration-none">
        <i class="bx bx-arrow-back"></i>
        <span>Back to Admin Dashboard</span>
      </a>
    </li>
  </ul>
</aside>
