<?php // Dashboard content is kept separate so the real view can be previewed with isolated records. ?>
<main class="container-xxl flex-grow-1 container-p-y professor-dashboard-page" id="main">
  <header class="dashboard-welcome">
    <div>
      <span class="dashboard-eyebrow">YOUR FACULTY WORKSPACE</span>
      <h1>Welcome, <?php echo synk_professor_h($facultyName); ?>.</h1>
      <p>A clear view of your teaching, research, and what needs your attention.</p>
    </div>
    <a href="<?php echo synk_professor_h($workloadUrl); ?>" class="dashboard-secondary-link"><i class="bx bx-calendar" aria-hidden="true"></i>View my workload<i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>
  </header>

  <section class="dashboard-metrics" aria-label="Teaching and research summary">
    <a class="dashboard-metric" href="<?php echo synk_professor_h($workloadUrl); ?>">
      <span class="dashboard-metric-icon"><i class="bx bx-book-open" aria-hidden="true"></i></span>
      <span class="dashboard-metric-label">Assigned classes</span><strong><?php echo number_format($teachingSummary['subjects']); ?></strong>
      <span class="dashboard-metric-note">Current academic term</span>
    </a>
    <a class="dashboard-metric" href="<?php echo synk_professor_h($workloadUrl); ?>">
      <span class="dashboard-metric-icon is-blue"><i class="bx bx-group" aria-hidden="true"></i></span>
      <span class="dashboard-metric-label">Student enrollments</span><strong><?php echo number_format($teachingSummary['enrollments']); ?></strong>
      <span class="dashboard-metric-note">Across your assigned classes</span>
    </a>
    <a class="dashboard-metric" href="oracle-research.php">
      <span class="dashboard-metric-icon is-green"><i class="bx bx-library" aria-hidden="true"></i></span>
      <span class="dashboard-metric-label">My researches</span><strong data-research-total aria-label="Research count is loading">&mdash;</strong>
      <span class="dashboard-metric-note" data-research-total-note>Loading from Oracle</span>
    </a>
    <a class="dashboard-metric" href="oracle-research.php">
      <span class="dashboard-metric-icon is-amber"><i class="bx bx-check-shield" aria-hidden="true"></i></span>
      <span class="dashboard-metric-label">Published researches</span><strong data-research-published aria-label="Published count is loading">&mdash;</strong>
      <span class="dashboard-metric-note" data-research-published-note>Within your research portfolio</span>
    </a>
  </section>

  <div class="dashboard-columns">
    <section class="card dashboard-research" id="dashboardResearch" aria-labelledby="dashboardResearchTitle" aria-busy="true">
      <div class="dashboard-card-heading">
        <div><span class="dashboard-eyebrow">RESEARCH PORTFOLIO</span><h2 id="dashboardResearchTitle">My researches</h2></div>
        <a href="oracle-research.php" class="dashboard-text-link">View all<i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>
      </div>
      <p class="dashboard-section-description">Your adviser, panelist, statistician, and English critic assignments from Oracle.</p>
      <div id="dashboardResearchLoading" class="dashboard-research-loading">
        <p class="dashboard-loading-label"><span class="dashboard-loading-dot" aria-hidden="true"></span>Loading your research portfolio&hellip;</p>
        <?php for ($skeleton = 0; $skeleton < 3; $skeleton++): ?>
          <div class="dashboard-skeleton" aria-hidden="true"><span></span><div><i></i><i></i><i></i></div></div>
        <?php endfor; ?>
      </div>
      <div id="dashboardResearchContent" hidden>
        <div class="dashboard-research-roles" id="dashboardResearchRoles" aria-label="Your research roles"></div>
        <figure class="dashboard-chart-figure" aria-labelledby="dashboardResearchChartTitle">
          <div class="dashboard-chart-toolbar"><div><h3 id="dashboardResearchChartTitle">Research across academic years</h3><p>A color for every program</p></div><div class="dashboard-chart-filter"><label for="dashboardResearchProgram">Program</label><select id="dashboardResearchProgram" class="form-select form-select-sm" aria-controls="dashboardResearchChart"><option value="">All programs</option></select></div></div>
          <div id="dashboardResearchChart" class="dashboard-chart research-trend-chart" role="img" aria-label="Assigned research counts by academic year and program"></div>
          <div id="dashboardResearchLegend" class="dashboard-chart-legend" aria-label="Programs and chart colors"></div>
          <p class="dashboard-chart-message" id="dashboardResearchChartMessage" hidden></p>
          <figcaption id="dashboardResearchChartNote" class="dashboard-chart-note">Each research is counted once, even when you hold several academic roles.</figcaption>
          <details class="dashboard-chart-data"><summary>View chart data</summary><div id="dashboardResearchChartTable" class="dashboard-chart-table-wrap"></div></details>
        </figure>
        <details class="dashboard-recent-records"><summary class="dashboard-recent-heading"><h3>Recently updated researches</h3><span>Latest 4 records</span><i class="bx bx-chevron-down" aria-hidden="true"></i></summary><div id="dashboardResearchList" class="dashboard-research-list"></div></details>
        <div class="dashboard-research-footer"><span><i class="bx bx-check-circle" aria-hidden="true"></i><span id="dashboardResearchAccount"></span></span><button type="button" id="dashboardResearchRefresh" class="dashboard-refresh" aria-label="Refresh research portfolio"><i class="bx bx-refresh" aria-hidden="true"></i>Refresh</button></div>
      </div>
      <div id="dashboardResearchEmpty" class="dashboard-empty" hidden>
        <span class="dashboard-empty-icon"><i class="bx bx-book-open" aria-hidden="true"></i></span>
        <h3>Your research portfolio starts here</h3><p>No adviser, panelist, statistician, or English critic assignments are currently linked to your Oracle account. Your assigned records will appear here automatically.</p>
        <a href="oracle-research.php" class="dashboard-text-link">Open My Researches<i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>
      </div>
      <div id="dashboardResearchError" class="dashboard-empty is-error" hidden>
        <span class="dashboard-empty-icon"><i class="bx bx-cloud" aria-hidden="true"></i></span>
        <h3>Research could not be loaded</h3><p id="dashboardResearchErrorMessage"></p>
        <button type="button" id="dashboardResearchRetry" class="btn btn-sm btn-outline-primary"><i class="bx bx-refresh me-1" aria-hidden="true"></i>Try again</button>
      </div>
      <p class="visually-hidden" id="dashboardResearchStatus" role="status" aria-live="polite" aria-atomic="true">Loading your Oracle research.</p>
      <noscript><p class="dashboard-noscript">Open <a href="oracle-research.php">My Researches</a> to view your complete Oracle portfolio.</p></noscript>
    </section>

    <div class="dashboard-aside">
      <section class="card dashboard-teaching" aria-labelledby="dashboard-teaching-title">
        <div class="dashboard-card-heading"><div><span class="dashboard-eyebrow">TEACHING</span><h2 id="dashboard-teaching-title">This academic term</h2></div><span class="dashboard-heading-icon"><i class="bx bx-briefcase-alt" aria-hidden="true"></i></span></div>
        <p class="dashboard-term"><i class="bx bx-calendar" aria-hidden="true"></i><?php echo synk_professor_h($currentTerm['term_text'] ?? 'Current term not set'); ?></p>
        <?php if ($dashboardWorkloadChart['classes']): ?>
          <figure class="dashboard-chart-figure workload-chart-figure" aria-labelledby="dashboardWorkloadChartTitle"><h3 id="dashboardWorkloadChartTitle">Enrollments by class</h3>
            <div class="dashboard-workload-scroll"><div id="dashboardWorkloadChart" class="dashboard-chart workload-enrollment-chart" role="img" aria-label="Student enrollments per assigned class for <?php echo synk_professor_h($dashboardWorkloadChart['term']); ?>"></div></div>
            <p id="dashboardWorkloadChartMessage" class="dashboard-chart-message" hidden></p>
            <figcaption class="dashboard-chart-note">Current academic year and semester set by the administrator. Enrollments are counted per class.</figcaption>
            <details class="dashboard-chart-data" id="dashboardWorkloadData"><summary>View enrollment breakdown</summary><div class="dashboard-chart-table-wrap"><table><caption class="visually-hidden">Current-term enrollments per assigned class</caption><thead><tr><th scope="col">Class</th><th scope="col">Enrolled</th></tr></thead><tbody><?php foreach ($dashboardWorkloadChart['classes'] as $class): ?><tr><th scope="row"><?php echo synk_professor_h($class['label']); ?></th><td><?php echo $class['enrollments']; ?></td></tr><?php endforeach; ?></tbody></table></div></details>
          </figure>
        <?php else: ?>
          <p class="dashboard-teaching-note"><?php echo !$facultyIsLinked ? 'Your teaching summary will appear once your account is linked to a faculty record.' : ((int)$dashboardWorkloadChart['ay_id'] < 1 || (int)$dashboardWorkloadChart['semester'] < 1 ? 'The administrator has not configured a current academic year and semester.' : 'No classes are assigned to you for the administrator’s current academic year and semester. Open Workload to review your teaching history.'); ?></p>
        <?php endif; ?>
        <div class="dashboard-history"><span>Teaching history</span><strong><?php echo number_format($teachingSummary['terms']); ?> <?php echo $teachingSummary['terms'] === 1 ? 'term' : 'terms'; ?></strong></div>
        <a href="<?php echo synk_professor_h($workloadUrl); ?>" class="dashboard-workload-link">Open workload<i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>
      </section>

      <section class="card dashboard-attention" aria-labelledby="dashboard-attention-title">
        <div class="dashboard-card-heading"><div><span class="dashboard-eyebrow">NEXT STEPS</span><h2 id="dashboard-attention-title"><?php echo $dashboardActions ? 'Needs attention' : 'You are all caught up'; ?></h2></div><span class="dashboard-action-count<?php echo !$dashboardActions ? ' is-complete' : ''; ?>"><?php echo $dashboardActions ? count($dashboardActions) : '<i class="bx bx-check" aria-hidden="true"></i>'; ?></span></div>
        <?php if ($dashboardActions): ?>
          <div class="dashboard-action-list">
            <?php foreach ($dashboardActions as $action): ?>
              <div class="dashboard-action">
                <span class="dashboard-action-icon"><i class="bx <?php echo synk_professor_h($action['icon']); ?>" aria-hidden="true"></i></span>
                <div><h3><?php echo synk_professor_h($action['title']); ?></h3><p><?php echo synk_professor_h($action['description']); ?></p>
                  <?php if ($action['url'] !== null): ?><a href="<?php echo synk_professor_h($action['url']); ?>"><?php echo synk_professor_h($action['action']); ?><i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>
                  <?php else: ?><small><?php echo synk_professor_h($action['action']); ?></small><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="dashboard-all-clear">Your profile is complete and your faculty access is active. You are ready for the term.</p>
        <?php endif; ?>
        <a class="dashboard-evaluation-link" href="faculty-evaluations.php"><span><i class="bx bx-bar-chart-alt-2" aria-hidden="true"></i>My evaluation results</span><i class="bx bx-chevron-right" aria-hidden="true"></i></a>
      </section>
    </div>
  </div>
  <script type="application/json" id="dashboardWorkloadDataSource"><?php echo json_encode($dashboardWorkloadChart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?: 'null'; ?></script>
</main>
