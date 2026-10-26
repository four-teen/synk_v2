<?php
if (!isset($scope,$term,$years)) { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/user_avatar_helper.php';
$avatarFallback = synk_default_user_avatar_path();
$avatar = synk_resolve_user_avatar_url((string)$_SESSION['email'],(string)($_SESSION['user_avatar_url'] ?? ''),80);
?>
<nav class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme dean-navbar" id="layout-navbar" aria-label="Account and viewing term">
  <div class="navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none"><button type="button" class="nav-item nav-link px-0 me-xl-4 layout-menu-toggle management-menu-toggle" id="management-menu-toggle" aria-label="Open navigation" aria-controls="layout-menu" aria-expanded="false"><i class="bx bx-menu bx-sm" aria-hidden="true"></i></button></div>
  <div class="navbar-nav-right d-flex align-items-center justify-content-between gap-3 w-100" id="navbar-collapse"><div class="dean-navbar-context"><span class="badge bg-label-secondary"><?= synk_management_h($scope['campus_name']) ?></span><div class="dean-view-summary"><i class="bx bx-calendar" aria-hidden="true"></i><span class="dean-view-label"><?= synk_management_h($term['period_text']) ?></span><button type="button" class="btn btn-sm dean-view-settings" id="management-term-settings" data-bs-toggle="modal" data-bs-target="#management-term-modal" aria-label="Change viewing academic year, semester, and term" title="Viewing period settings"><i class="bx bx-cog" aria-hidden="true"></i></button></div></div>
    <ul class="navbar-nav flex-row align-items-center ms-auto"><li class="nav-item lh-1 me-3 text-end d-none d-md-block"><span class="fw-semibold d-block"><?= synk_management_h($accountName) ?></span><small class="text-muted"><?= synk_management_h($_SESSION['email']) ?></small></li><li class="nav-item navbar-dropdown dropdown-user dropdown"><button type="button" class="nav-link dropdown-toggle hide-arrow management-avatar-button" data-bs-toggle="dropdown" aria-label="Account menu"><span class="avatar avatar-online"><img src="<?= synk_management_h($avatar) ?>" alt="" class="rounded-circle" onerror="this.onerror=null;this.src='<?= synk_management_h($avatarFallback) ?>';" referrerpolicy="no-referrer"></span></button><ul class="dropdown-menu dropdown-menu-end"><li><div class="dropdown-item-text"><span class="fw-semibold d-block"><?= synk_management_h($accountName) ?></span><small class="text-muted d-block">Management · <?= synk_management_h($organizationLabel.' · '.$collegeLabel) ?></small><small class="text-muted d-block mt-1"><?= synk_management_h($_SESSION['email']) ?></small></div></li><li><div class="dropdown-divider"></div></li><?php synk_render_account_switch($conn); ?><li><a class="dropdown-item" href="../logout.php"><i class="bx bx-power-off me-2" aria-hidden="true"></i><span class="align-middle">Log Out</span></a></li></ul></li></ul>
  </div>
</nav>
<div class="modal fade" id="management-term-modal" tabindex="-1" aria-labelledby="management-term-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="get" class="dean-view-term" id="management-term-form">
      <div class="modal-header"><h5 class="modal-title" id="management-term-title">Viewing period settings</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="dean-term-settings-copy">Choose the academic year, semester, and term to view.</p>
        <input type="hidden" id="management-term" name="term" value="<?= synk_management_h($term['key']) ?>">
        <div class="mb-3"><label class="form-label" for="management-view-year">Academic year</label>
          <select class="form-select" id="management-view-year" name="view_ay_id" required><?php foreach($years as $year): ?><option value="<?= (int)$year['ay_id'] ?>"<?= (int)$year['ay_id']===$term['ay_id'] ? ' selected' : '' ?>><?= synk_management_h($year['ay']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="mb-3"><label class="form-label" for="management-view-semester">Semester</label>
          <select class="form-select" id="management-view-semester" name="view_semester" required><?php for($semester=1;$semester<=3;$semester++): ?><option value="<?= $semester ?>"<?= $semester===$term['semester'] ? ' selected' : '' ?>><?= synk_management_h(synk_semester_label($semester)) ?></option><?php endfor; ?></select>
        </div>
        <div class="mb-3"><label class="form-label" for="management-view-exam-term">Term</label>
          <select class="form-select" id="management-view-exam-term" name="exam_term" required><?php foreach(synk_management_exam_term_options() as $value=>$label): ?><option value="<?= $value ?>"<?= $value===$term['exam_term'] ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
        </div>
        <p class="small text-muted">Student lists, contributions, events, and violation definitions cover the whole semester. Midterm and Final identify the selected reporting period.</p>
        <div class="dean-term-default"><div><span>Current Management period</span><strong><?= synk_management_h($currentTerm['period_text']) ?></strong></div><button type="button" class="btn btn-sm btn-outline-secondary" id="management-current-term" data-term="<?= synk_management_h($currentTerm['key']) ?>" data-exam-term="<?= synk_management_h($currentTerm['exam_term']) ?>">Use current period</button></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Apply</button></div>
    </form>
    <details class="px-4 pb-4" id="management-current-period-settings">
      <summary class="fw-semibold">Set current Management term</summary>
      <form id="management-current-period-form" method="post" class="mt-3">
        <p class="small text-muted">For <?= synk_management_h($organizationLabel) ?> · <?= synk_management_h($currentTerm['term_text']) ?>. This setting applies only to Management.</p>
        <div class="alert alert-danger" id="management-current-period-error" role="alert" hidden></div>
        <label class="form-label" for="management-current-exam-term">Current Management term</label>
        <select class="form-select" id="management-current-exam-term" required><?php foreach(synk_management_exam_term_options() as $value=>$label): ?><option value="<?= $value ?>"<?= $value===$currentTerm['exam_term'] ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn btn-primary mt-3" id="management-current-period-save">Save Management term</button>
      </form>
    </details>
  </div></div>
</div>
