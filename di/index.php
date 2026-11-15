<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/../backend/di_dashboard_helper.php';
$dashboardError='';
try {
    $dashboard=synk_di_dashboard($conn,$_GET);
    $moduleTerm=$dashboard['term'];
    $summary=$dashboard['summary'];
} catch (Throwable $exception) {
    http_response_code($exception instanceof DomainException ? 422 : 503);
    $dashboardError=$exception instanceof DomainException ? $exception->getMessage() : 'Dashboard analytics are temporarily unavailable. Please try again.';
    if (!$exception instanceof DomainException) error_log('DI dashboard: '.$exception->getMessage());
}
$pageTitle='DI Academic & Faculty Dashboard';
$pageStyles=['../assets/vendor/libs/apex-charts/apex-charts.css','dashboard.css?v='.filemtime(__DIR__.'/dashboard.css')];
$pageScripts=['../assets/vendor/libs/apex-charts/apexcharts.js','dashboard.js?v='.filemtime(__DIR__.'/dashboard.js')];
include __DIR__.'/page-start.php';
?>
<header class="di-dashboard-heading mb-4"><div><span class="di-eyebrow">DI / Director for Instruction</span><h1>Academic &amp; Faculty Dashboard</h1><p>Academic activity, campus faculty, and professor profile statistics for the current academic term.</p></div><div class="di-dashboard-term"><i class="bx bx-calendar" aria-hidden="true"></i><div><small>Reporting Period</small><strong><?= synk_leadership_h($moduleTerm['term_text']) ?></strong></div></div></header>
<?php if ($dashboardError): ?>
<div class="alert alert-warning" role="alert"><?= synk_leadership_h($dashboardError) ?> <a href="index.php">Reset filters</a></div>
<?php else: ?>
<div class="card mb-4"><div class="card-body">
  <form action="index.php" method="get" class="di-filters" aria-label="Filter dashboard">
    <div class="di-filter-field"><label class="form-label" for="campusFilter">Campus</label><select name="campus_id" id="campusFilter" class="form-select"><option value="0">All campuses</option><?php foreach ($dashboard['campuses'] as $id=>$campus): ?><option value="<?= $id ?>"<?= $id===$dashboard['campus_id'] ? ' selected' : '' ?>><?= synk_leadership_h($campus['campus_name']) ?></option><?php endforeach; ?></select></div>
    <div class="di-filter-field"><label class="form-label" for="collegeFilter">College</label><select name="college_id" id="collegeFilter" class="form-select"><option value="0">All colleges</option><?php foreach ($dashboard['colleges'] as $id=>$college): ?><option value="<?= $id ?>" data-campus="<?= (int)$college['campus_id'] ?>"<?= $id===$dashboard['college_id'] ? ' selected' : '' ?><?= $dashboard['campus_id'] && (int)$college['campus_id']!==$dashboard['campus_id'] ? ' hidden disabled' : '' ?>><?= synk_leadership_h($college['college_code'].' - '.$college['college_name']) ?></option><?php endforeach; ?></select></div>
    <button type="submit" class="btn btn-primary"><i class="bx bx-filter-alt me-1" aria-hidden="true"></i>Apply Filters</button><a class="btn btn-outline-secondary" href="index.php">Reset</a>
  </form>
  <p class="di-scope-note mb-0">Scope: <strong><?= synk_leadership_h($dashboard['scope_label']) ?></strong>. Academic activity and faculty assignments follow the reporting period above. Profile details reflect the latest saved professor profile.</p>
</div></div>
<?php if (!$dashboard['term_ready']): ?><div class="alert alert-warning" role="status">Set the current academic year and semester in Academic Settings to view term activity. Active campus and college counts remain available.</div><?php endif; ?>
<nav class="di-dashboard-jumps mb-4" aria-label="Dashboard sections"><a href="#academic">01 · Academic Overview</a><a href="#campus-faculty">02 · Faculty by Campus</a><a href="#qualifications">03 · Faculty Profile Statistics</a></nav>

<section id="academic" class="mb-5" aria-labelledby="academicHeading">
  <div class="di-dashboard-group-heading"><div><span class="di-eyebrow">01 / Academic</span><h2 id="academicHeading">Academic Overview</h2><p>Programs, subjects, prospectuses, sections, and classes are counted from valid offerings in the current term. Campus and college counts show active locations in this scope.</p></div></div>
  <div class="di-dashboard-metrics">
    <?php foreach ([
      ['campuses','Campuses',$dashboard['academic']['campuses'],'Active campus locations','bx-buildings','primary'],
      ['colleges','Colleges',$dashboard['academic']['colleges'],'Active colleges in this scope','bx-building-house','info'],
      ['programs','Programs Offered',$dashboard['academic']['programs'],'Distinct programs with current-term classes','bx-book-content','success'],
      ['subjects','Distinct Subjects',$dashboard['academic']['subjects'],'Each subject counted once across programs','bx-book','warning'],
      ['prospectuses','Prospectuses in Use',$dashboard['academic']['prospectuses'],'Distinct curriculum versions used this term','bx-spreadsheet','primary'],
      ['sections','Sections',$dashboard['academic']['sections'],'Distinct sections with valid offerings','bx-grid-alt','info'],
      ['classes','Class Offerings',$dashboard['academic']['classes'],'One count per subject and section','bx-chalkboard','success'],
      ['faculty','Total Teaching Faculty',$dashboard['faculty_count'],'Distinct active faculty with assigned subjects','bx-user-voice','warning'],
    ] as $metric): ?>
    <article class="card di-dashboard-metric"><div class="card-body"><span class="di-dashboard-metric-icon bg-label-<?= $metric[5] ?>"><i class="bx <?= $metric[4] ?>" aria-hidden="true"></i></span><h3><?= $metric[1] ?></h3><strong data-metric="<?= $metric[0] ?>"><?= number_format($metric[2]) ?></strong><p><?= $metric[3] ?></p></div></article>
    <?php endforeach; ?>
  </div>
</section>

<section id="campus-faculty" class="mb-5" aria-labelledby="campusFacultyHeading">
  <div class="di-dashboard-group-heading"><div><span class="di-eyebrow">02 / Faculty</span><h2 id="campusFacultyHeading">Faculty by Campus</h2><p>Active teaching faculty have at least one assigned subject in the current academic year and semester. A faculty member serving two colleges is counted once within a campus.</p></div><span class="badge bg-label-primary di-dashboard-total"><?= number_format($dashboard['faculty_count']) ?> distinct teaching faculty</span></div>
  <div class="di-dashboard-roster-note"><i class="bx bx-info-circle" aria-hidden="true"></i><span><strong><?= number_format($dashboard['rostered_count']) ?> distinct active faculty added to college rosters</strong> for this term. Roster counts also include faculty awaiting subject assignments. Faculty teaching across campuses appear in each campus card and once in the university total.</span></div>
  <div class="di-campus-grid mt-3">
    <?php foreach ($dashboard['campus_rows'] as $id=>$campusRow): ?>
    <article class="card di-campus-card" data-campus="<?= $id ?>"><div class="card-body">
      <div class="di-campus-heading"><div><span class="di-eyebrow"><?= synk_leadership_h($campusRow['campus']['campus_code']) ?></span><h3><?= synk_leadership_h($campusRow['campus']['campus_name']) ?></h3></div><span class="di-dashboard-metric-icon bg-label-info"><i class="bx bx-buildings" aria-hidden="true"></i></span></div>
      <strong class="di-campus-faculty-count" data-campus-faculty="<?= $id ?>"><?= number_format($campusRow['teaching']) ?></strong><p class="di-campus-caption">Active faculty with subjects</p>
      <dl class="di-campus-detail"><div><dt>Added to rosters</dt><dd><?= number_format($campusRow['rostered']) ?></dd></div><div><dt>Profiles recorded</dt><dd><?= number_format($campusRow['summary']['saved']) ?> / <?= number_format($campusRow['teaching']) ?></dd></div><div><dt>Programs offered</dt><dd><?= number_format($campusRow['programs']) ?></dd></div><div><dt>Distinct subjects</dt><dd><?= number_format($campusRow['subjects']) ?></dd></div></dl>
      <a class="di-campus-link stretched-link" href="campus-faculty.php?campus_id=<?= $id ?>" aria-label="View faculty at <?= synk_leadership_h($campusRow['campus']['campus_name']) ?>">View campus faculty <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>
    </div></article>
    <?php endforeach; ?>
    <?php if (!$dashboard['campus_rows']): ?><div class="alert alert-info mb-0">No active campuses are recorded in this scope.</div><?php endif; ?>
  </div>
</section>

<section id="qualifications" class="mb-4" aria-labelledby="profileStatisticsHeading">
  <div class="di-dashboard-group-heading"><div><span class="di-eyebrow">03 / Professor Profiles</span><h2 id="profileStatisticsHeading">Faculty Profile Statistics</h2><p>Based on <?= number_format($summary['total']) ?> distinct active teaching faculty in this term. Saved profile details are included at every completion level. Missing or invalid details appear as “Not recorded”.</p></div></div>
  <div id="coverage" class="di-dashboard-metrics mb-4">
    <?php foreach ([['Saved Profiles',$summary['saved'],'Of '.$summary['total'].' teaching faculty','bx-id-card','primary'],['Profile Coverage',$summary['coverage_percent'].'%','Faculty with a saved professor profile','bx-pie-chart-alt-2','info'],['Complete Profiles',$summary['complete'],$summary['in_progress'].' in progress · '.$summary['not_started'].' not started','bx-list-check','success'],['With Credentials',$summary['with_eligibility'],'Eligibility, license, or other recorded credential','bx-badge-check','warning']] as $metric): ?>
    <article class="card di-dashboard-metric"><div class="card-body"><span class="di-dashboard-metric-icon bg-label-<?= $metric[4] ?>"><i class="bx <?= $metric[3] ?>" aria-hidden="true"></i></span><h3><?= $metric[0] ?></h3><strong><?= synk_leadership_h($metric[1]) ?></strong><p><?= synk_leadership_h($metric[2]) ?></p></div></article>
    <?php endforeach; ?>
  </div>
  <div class="row g-4 mb-4">
    <?php foreach ([['educationChart','Highest Completed Degree','Completed studies only; ongoing degrees are shown separately.','bx-book-reader'],['employmentChart','Employment Classification','Classification recorded in the professor profile.','bx-briefcase-alt'],['rankChart','Academic Rank','Faculty rank recorded in the professor profile.','bx-bar-chart-alt-2'],['ongoingChart','Ongoing Studies','A faculty member may have more than one ongoing degree.','bx-book-open']] as $chart): ?>
    <div class="col-lg-6"><article class="card h-100"><div class="card-header"><h3 class="di-section-heading"><?= $chart[1] ?></h3><small class="text-muted"><?= $chart[2] ?></small></div><div id="<?= $chart[0] ?>" class="di-chart" role="img" aria-label="<?= $chart[1] ?> statistics"><div class="di-chart-empty"><i class="bx <?= $chart[3] ?>" aria-hidden="true"></i><span>No recorded data in this scope.</span></div></div></article></div>
    <?php endforeach; ?>
    <div class="col-lg-5"><article class="card h-100"><div class="card-header"><h3 class="di-section-heading">Eligibility &amp; Licenses</h3><small class="text-muted">Distinct faculty per credential; faculty can hold several.</small></div><div class="card-body"><ul class="di-credential-list"><?php foreach ($summary['eligibilities'] as $label=>$count): ?><li><span><?= synk_leadership_h($label) ?></span><span class="badge bg-label-info"><?= $count ?></span></li><?php endforeach; ?></ul><?php if (!$summary['eligibilities']): ?><p class="small text-muted mb-0">No credentials have been recorded for this teaching cohort.</p><?php endif; ?></div></article></div>
    <div class="col-lg-7"><article class="card h-100"><div class="card-header"><h3 class="di-section-heading">Profile Details &amp; Completion</h3><small class="text-muted">Average completion across all teaching faculty: <?= $summary['average_completion'] ?>%.</small></div><div class="card-body">
      <?php foreach (['gender'=>'Gender','civil_status'=>'Civil Status'] as $key=>$label): ?><h4 class="h6"><?= $label ?></h4><div class="di-gender-row mb-4"><?php foreach ($summary[$key] as $value=>$count): ?><span class="badge bg-label-secondary"><?= synk_leadership_h($value) ?> · <?= $count ?></span><?php endforeach; ?><?php if (!$summary[$key]): ?><span class="text-muted small">No teaching faculty in this scope.</span><?php endif; ?></div><?php endforeach; ?>
      <?php foreach (['personal'=>'Personal Details','employment'=>'Employment','education'=>'Education'] as $key=>$label): $counts=$summary['sections'][$key]; $percent=$counts['total'] ? (int)round(100*$counts['completed']/$counts['total']) : 0; ?><div class="di-completion-row"><div class="d-flex justify-content-between gap-2 mb-2"><span class="small"><?= $label ?></span><strong class="small"><?= $percent ?>%</strong></div><div class="progress di-progress" role="progressbar" aria-label="<?= $label ?> profile fields" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?= $percent ?>%"></div></div></div><?php endforeach; ?>
    </div></article></div>
  </div>
  <section id="profiles" class="card" aria-labelledby="profileRegisterHeading">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3"><div><h3 class="di-section-heading" id="profileRegisterHeading">Teaching Faculty Profile Register</h3><small class="text-muted">One row per faculty member with a current-term subject assignment.</small></div><div class="input-group di-table-search"><span class="input-group-text"><i class="bx bx-search" aria-hidden="true"></i></span><input type="search" id="profileSearch" class="form-control" placeholder="Search faculty or college" aria-label="Search teaching faculty profiles"></div></div>
    <div class="table-responsive"><table class="table di-profile-table mb-0"><thead><tr><th>Faculty</th><th>Teaching Campus / College</th><th>Academic Rank</th><th>Highest Degree</th><th>Profile Status</th><th>Completion</th><th>Last Saved</th></tr></thead><tbody>
      <?php foreach ($dashboard['faculty'] as $record): $statusLabel=['complete'=>'Complete','in_progress'=>'In progress','not_started'=>'Not started'][$record['profile_status']]; $statusColor=['complete'=>'success','in_progress'=>'warning','not_started'=>'secondary'][$record['profile_status']]; ?>
      <tr class="di-profile-row" data-search="<?= synk_leadership_h(implode(' ',[$record['name'],$record['campus_name'],$record['college_name'],$record['analytics_values']['faculty_rank'],$statusLabel])) ?>"><td class="di-person"><a class="fw-semibold" href="faculty-profile.php?faculty_id=<?= $record['faculty_id'] ?>"><?= synk_leadership_h($record['name']) ?></a></td><td><?= synk_leadership_h($record['campus_name']) ?><small class="text-muted d-block mt-1"><?= synk_leadership_h($record['college_name']) ?></small></td><td><?= synk_leadership_h($record['analytics_values']['faculty_rank'] ?: 'Not recorded') ?></td><td><?= synk_leadership_h($record['highest_degree']) ?></td><td><span class="badge bg-label-<?= $statusColor ?>"><?= $statusLabel ?></span></td><td class="di-progress-cell"><strong class="small"><?= $record['completion']['percent'] ?>%</strong><div class="progress di-progress mt-1"><div class="progress-bar" style="width:<?= $record['completion']['percent'] ?>%"></div></div></td><td><?= $record['updated_at'] ? synk_leadership_h(date('M j, Y',strtotime($record['updated_at']))) : 'Never saved' ?></td></tr>
      <?php endforeach; ?>
      <tr id="profileEmptyRow"<?= $dashboard['faculty'] ? ' hidden' : '' ?>><td colspan="7" class="text-center text-muted py-4">No teaching faculty match this view.</td></tr>
    </tbody></table></div>
    <div class="card-body py-3 d-flex flex-wrap align-items-center justify-content-between gap-3"><span id="profileTableCount" class="di-table-meta" aria-live="polite"><?= count($dashboard['faculty']) ?> teaching faculty</span><div class="di-pagination"><button id="profilePrevious" type="button" class="btn btn-sm btn-outline-secondary" disabled>Previous</button><span id="profilePage" class="small text-muted">Page 1</span><button id="profileNext" type="button" class="btn btn-sm btn-outline-secondary" disabled>Next</button></div></div>
  </section>
</section>
<script id="diChartData" type="application/json"><?= json_encode(['education'=>$summary['education'],'employment'=>$summary['employment'],'ranks'=>$summary['ranks'],'ongoing'=>$summary['ongoing']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<?php include __DIR__.'/page-end.php'; ?>
