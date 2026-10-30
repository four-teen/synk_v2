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

if (!$collegePortalIsChair) {
    $studentMenuIndex=array_search('students',array_column($collegeDeanSidebarItems,'key'),true);
    array_splice($collegeDeanSidebarItems,$studentMenuIndex+1,0,[[
        'key'=>'login-activity','href'=>'login-activity.php','icon_bg'=>'bg-label-success','icon'=>'bx-log-in-circle',
        'title'=>'Student Login Activity','description'=>'College sign-ins, failures and daily trends','pages'=>['login-activity.php'],
    ]]);
}

if ($collegePortalIsChair) {
    array_splice($collegeDeanSidebarItems, 1, 0, [[
        'key' => 'manage-college-faculty', 'href' => 'manage-college-faculty.php',
        'icon_bg' => 'bg-label-success', 'icon' => 'bx-user-plus',
        'title' => 'College Faculty', 'description' => 'Manage faculty assignments and lists',
        'pages' => ['manage-college-faculty.php'],
    ]]);
    $programMenuIndex = array_search('programs',array_column($collegeDeanSidebarItems,'key'),true);
    array_splice($collegeDeanSidebarItems,$programMenuIndex+1,0,[[
        'key'=>'curriculum','href'=>'manage-prospectus-browser.php','icon_bg'=>'bg-label-warning','icon'=>'bx-book-open',
        'title'=>'Curriculum','description'=>'Manage prospectuses and subjects in your college',
        'pages'=>['manage-prospectus.php','manage-prospectus-browser.php','view-prospectus.php'],
        'children'=>[
            ['href'=>'manage-prospectus-browser.php','title'=>'Curriculum Browser','icon'=>'bx-search-alt'],
            ['href'=>'manage-prospectus.php','title'=>'Curriculum Builder','icon'=>'bx-edit-alt'],
        ],
    ]]);

    $chairSidebarByKey = array_column($collegeDeanSidebarItems, null, 'key');
    $chairSidebarByKey['manage-college-faculty']['section'] = 'Faculty Management';
    $chairSidebarByKey['faculty-profile']['title'] = 'Faculty Profiles';
    $chairSidebarByKey['programs']['section'] = 'Academic Management';
    $chairSidebarByKey['reports']['section_separator'] = true;
    $chairSidebarByKey['reports']['children'][0]['title'] = 'Faculty & Student Summary';
    $collegeDeanSidebarItems = array_map(static function (string $key) use ($chairSidebarByKey): array {
        return $chairSidebarByKey[$key];
    }, ['dashboard','manage-college-faculty','faculty-profile','faculty-workload','faculty-evaluations',
        'programs','curriculum','students','reports']);
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
      <span class="app-brand-text demo menu-text fw-bolder ms-2"><span class="synk-wordmark">syn<span class="synk-wordmark-q">Q</span></span></span>
    </a>

    <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto d-block d-xl-none">
      <i class="bx bx-chevron-left bx-sm align-middle"></i>
    </a>
  </div>



  <div class="menu-inner-shadow"></div>

  <ul class="menu-inner py-1">
    <li class="menu-header small text-uppercase mt-1">
      <span class="menu-header-text"><?php echo $collegePortalIsChair ? 'Program Chair Portal' : 'College Dean Portal'; ?></span>
    </li>

    <?php foreach ($collegeDeanSidebarItems as $sidebarItem): ?>
      <?php if (!empty($sidebarItem['section'])): ?>
        <li class="menu-header small text-uppercase chair-sidebar-section"><span class="menu-header-text"><?= htmlspecialchars($sidebarItem['section'], ENT_QUOTES, 'UTF-8') ?></span></li>
      <?php endif; ?>
      <li class="menu-item px-2<?php echo $collegeDeanSidebarActiveKey === $sidebarItem['key'] ? ' active' : ''; ?><?php echo !empty($sidebarItem['section_separator']) ? ' chair-sidebar-section-start' : ''; ?>">
        <?php if (!empty($sidebarItem['children'])): ?>
          <details class="college-dean-report-group"<?php echo $collegeDeanSidebarActiveKey === $sidebarItem['key'] ? ' open' : ''; ?>>
            <summary class="college-dean-menu-card<?php echo $collegeDeanSidebarActiveKey === $sidebarItem['key'] ? ' active' : ''; ?>">
              <span class="college-dean-menu-icon <?php echo htmlspecialchars($sidebarItem['icon_bg'], ENT_QUOTES, 'UTF-8'); ?>"><i class="bx <?php echo htmlspecialchars($sidebarItem['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i></span>
              <div class="college-dean-menu-content"><div class="college-dean-menu-title"><?php echo htmlspecialchars($sidebarItem['title'], ENT_QUOTES, 'UTF-8'); ?></div><small class="college-dean-menu-desc"><?php echo htmlspecialchars($sidebarItem['description'], ENT_QUOTES, 'UTF-8'); ?></small></div>
              <i class="bx bx-chevron-right college-dean-report-chevron" aria-hidden="true"></i>
            </summary>
            <ul class="college-dean-report-links">
              <?php foreach ($sidebarItem['children'] as $reportItem):
                $reportIsActive = $collegeDeanSidebarCurrentPage === $reportItem['href']
                  || ($collegePortalIsChair && $collegeDeanSidebarCurrentPage === 'view-prospectus.php' && $reportItem['href'] === 'manage-prospectus-browser.php'); ?>
                <li><a href="<?php echo htmlspecialchars($reportItem['href'], ENT_QUOTES, 'UTF-8'); ?>" class="college-dean-report-link<?php echo $reportIsActive ? ' is-active' : ''; ?>"<?php echo $reportIsActive ? ' aria-current="page"' : ''; ?>><i class="bx <?php echo htmlspecialchars($reportItem['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i><span><?php echo htmlspecialchars($reportItem['title'], ENT_QUOTES, 'UTF-8'); ?></span></a></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php else: ?>
        <a
          href="<?php echo htmlspecialchars($sidebarItem['href'], ENT_QUOTES, 'UTF-8'); ?>"
          class="menu-link college-dean-menu-card<?php echo $collegeDeanSidebarActiveKey === $sidebarItem['key'] ? ' active' : ''; ?>"
          <?php if ($collegePortalIsChair && $collegeDeanSidebarActiveKey === $sidebarItem['key']): ?>aria-current="page"<?php endif; ?>
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
