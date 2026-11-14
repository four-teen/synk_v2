<?php
$studentSidebarCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
$studentSidebarItems = [
    [
        'key' => 'dashboard',
        'href' => 'index.php',
        'icon_bg' => 'bg-label-primary',
        'icon' => 'bx-home-circle',
        'title' => 'Dashboard',
        'mobile_title' => 'Home',
        'description' => 'Announcements and student updates',
        'pages' => ['index.php'],
    ],
    [
        'key' => 'profile',
        'href' => 'profile.php',
        'icon_bg' => 'bg-label-secondary',
        'icon' => 'bx-id-card',
        'title' => 'Manage Profile',
        'mobile_title' => 'Profile',
        'description' => 'Update your photo and review student details',
        'pages' => ['profile.php'],
    ],
    [
        'key' => 'prospectus',
        'href' => 'prospectus.php',
        'icon_bg' => 'bg-label-success',
        'icon' => 'bx-book-content',
        'title' => 'Prospectus Viewer',
        'mobile_title' => 'Prospectus',
        'description' => 'Browse curriculum subjects by program and version',
        'pages' => ['prospectus.php'],
    ],
    [
        'key' => 'class_program',
        'href' => 'class-program.php',
        'icon_bg' => 'bg-label-info',
        'icon' => 'bx-user-voice',
        'title' => 'Faculty Evaluation',
        'mobile_title' => 'Evaluation',
        'description' => 'Evaluate faculty members who handled your subjects',
        'pages' => ['class-program.php'],
    ],
];
if (($_SESSION['role'] ?? '')==='student') $studentSidebarItems[]=[
    'key'=>'assessments','href'=>'assessments_lms.php','icon_bg'=>'bg-label-primary','icon'=>'bx-task',
    'title'=>'Activities & assessments','mobile_title'=>'Assessments','description'=>'Your subjects, professors, and classwork','pages'=>['assessments_lms.php','gradebook_lms.php'],
];
if (($_SESSION['role'] ?? '')==='student') $studentSidebarItems[]=[
    'key'=>'finances','href'=>'finances.php','icon_bg'=>'bg-label-warning','icon'=>'bx-wallet',
    'title'=>'My Finances','mobile_title'=>'Finances','description'=>'Contributions, attendance and charges','pages'=>['finances.php'],
];
if (($_SESSION['role'] ?? '')==='student') $studentSidebarItems[]=[
    'key'=>'attendance_qr','href'=>'attendance-qr.php','icon_bg'=>'bg-label-primary','icon'=>'bx-qr',
    'title'=>'My QR','mobile_title'=>'QR','description'=>'Show or save your student QR','pages'=>['attendance-qr.php'],
];
$studentSidebarStudentId = synk_student_is_staff_preview_mode()
    ? synk_student_preview_student_id_from_request() : (int)($_SESSION['student_id'] ?? 0);
$studentSidebarEvaluationQuery = synk_student_evaluation_navigation($conn, $studentSidebarStudentId);
foreach ($studentSidebarItems as $index => $sidebarItem) {
    if ($sidebarItem['key'] !== 'class_program') continue;
    if ($studentSidebarEvaluationQuery === null) unset($studentSidebarItems[$index]);
    else $studentSidebarItems[$index]['query'] = $studentSidebarEvaluationQuery;
}
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
      <span class="app-brand-text demo menu-text fw-bolder ms-2"><span class="synk-wordmark">syn<span class="synk-wordmark-q">Q</span></span> <span class="synk-wordmark-role">Student</span></span>
    </a>

    <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto d-block d-xl-none">
      <i class="bx bx-chevron-left bx-sm align-middle"></i>
    </a>
  </div>

  <div class="menu-inner-shadow"></div>

  <ul class="menu-inner py-1 student-nav">
    <li class="menu-header small text-uppercase mt-1">
      <span class="menu-header-text">Student Portal</span>
    </li>

    <?php foreach ($studentSidebarItems as $sidebarItem): ?>
      <li class="menu-item px-2<?php echo $studentSidebarActiveKey === $sidebarItem['key'] ? ' active' : ''; ?>">
        <a
          href="<?php echo htmlspecialchars(function_exists('synk_student_build_portal_url') ? synk_student_build_portal_url($sidebarItem['href'], $sidebarItem['query'] ?? []) : $sidebarItem['href'], ENT_QUOTES, 'UTF-8'); ?>"
          class="menu-link student-menu-card<?php echo $studentSidebarActiveKey === $sidebarItem['key'] ? ' active' : ''; ?>"
          <?php if ($studentSidebarActiveKey === $sidebarItem['key']): ?>aria-current="page"<?php endif; ?>
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
<?php require __DIR__.'/mobile-navigation.php'; ?>
<script src="navigation.js?v=<?= (int)filemtime(__DIR__.'/navigation.js') ?>" defer></script>
