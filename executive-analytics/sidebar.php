<?php
$sidebarCampusCatalog = $sidebarCampusCatalog ?? [];
$sidebarCampusMetricsById = $sidebarCampusMetricsById ?? [];
$sidebarSelectedCampusId = (int)($sidebarSelectedCampusId ?? 0);
$sidebarSummary = $sidebarSummary ?? [];
?>

<aside id="layout-menu" class="layout-menu menu-vertical exec-sidebar">
  <div class="exec-sidebar-brand">
    <a href="index.php" class="exec-sidebar-brand-link">
      <span class="exec-sidebar-brand-mark">
        <i class="bx bx-line-chart"></i>
      </span>
      <span>
        <span class="exec-sidebar-brand-kicker">SKSU</span>
        <span class="exec-sidebar-brand-title">Executive Analytics</span>
      </span>
    </a>
  </div>

  <div class="menu-inner exec-sidebar-inner">
    <div class="exec-sidebar-panel">
      <div class="exec-sidebar-stat">
        <span class="exec-sidebar-stat-label">Active Campuses</span>
        <strong><?php echo number_format((int)($sidebarSummary['campus_count'] ?? count($sidebarCampusCatalog))); ?></strong>
      </div>
      <div class="exec-sidebar-stat">
        <span class="exec-sidebar-stat-label">Schedule Coverage</span>
        <strong><?php echo number_format((float)($sidebarSummary['schedule_coverage'] ?? 0), 1); ?>%</strong>
      </div>
    </div>

    <div class="exec-sidebar-section-label">Primary View</div>
    <nav class="exec-sidebar-nav">
      <a
        href="index.php"
        class="exec-nav-link <?php echo $sidebarSelectedCampusId <= 0 ? 'is-active' : ''; ?>"
      >
        <span class="exec-nav-link-icon">
          <i class="bx bx-home-smile"></i>
        </span>
        <span class="exec-nav-link-copy">
          <span class="exec-nav-link-title">All Campuses</span>
          <span class="exec-nav-link-note">University-wide overview first</span>
        </span>
      </a>
    </nav>

    <div class="exec-sidebar-section-label">Campus Command List</div>
    <div class="exec-campus-link-list">
      <?php foreach ($sidebarCampusCatalog as $campus): ?>
        <?php
        $campusId = (int)($campus['campus_id'] ?? 0);
        $campusMetric = $sidebarCampusMetricsById[$campusId] ?? [];
        $campusCoverage = (float)($campusMetric['schedule_coverage'] ?? 0);
        $campusSchedules = (int)($campusMetric['schedules'] ?? 0);
        ?>
        <a
          href="index.php?campus_id=<?php echo $campusId; ?>"
          class="exec-campus-link <?php echo $sidebarSelectedCampusId === $campusId ? 'is-active' : ''; ?>"
        >
          <div class="exec-campus-link-top">
            <span class="exec-campus-link-code"><?php echo synk_exec_analytics_h((string)($campus['campus_code'] ?? '')); ?></span>
            <span class="exec-campus-link-coverage"><?php echo number_format($campusCoverage, 1); ?>%</span>
          </div>
          <div class="exec-campus-link-name"><?php echo synk_exec_analytics_h((string)($campus['campus_name'] ?? 'Campus')); ?></div>
          <div class="exec-campus-link-meta">
            <span><?php echo number_format($campusSchedules); ?> schedules</span>
            <span>Coverage</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="exec-sidebar-footer">
      <div class="exec-sidebar-footer-chip">
        <i class="bx bx-shield-quarter"></i>
        <span>Separate executive access module</span>
      </div>
    </div>
  </div>
</aside>
