<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../backend/di_program_helper.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$pageTitle = 'Courses / Programs';
$pageStyles = ['programs.css?v=' . filemtime(__DIR__ . '/programs.css')];
$pageScripts = ['../assets/js/sweetalert2.all.min.js', 'programs.js?v=' . filemtime(__DIR__ . '/programs.js')];
$programConfig = synk_di_program_options($conn) + [
    'endpoint'=>'../backend/query_di_catalog.php?catalog=program',
    'csrfToken'=>(string)$_SESSION['csrf_token'],
    'campusId'=>is_string($_GET['campus_id'] ?? null) ? $_GET['campus_id'] : '',
    'collegeId'=>is_string($_GET['college_id'] ?? null) ? $_GET['college_id'] : '',
];
include __DIR__ . '/page-start.php';
?>
<div class="di-directory-heading">
  <div><span class="di-eyebrow">DI / Curriculum Management</span><h1>Courses / Programs</h1><p>Choose a campus to view its programs, then narrow the list by college.</p></div>
  <button type="button" class="btn btn-primary" id="addProgramButton" disabled><i class="bx bx-plus me-1" aria-hidden="true"></i>Add Program</button>
</div>
<section class="card mb-4" aria-label="Program filters"><div class="card-body di-program-filters">
  <div><label class="form-label" for="programCampus">Campus</label><select class="form-select" id="programCampus"><option value="">Select a campus</option></select></div>
  <div><label class="form-label" for="programCollege">College</label><select class="form-select" id="programCollege" disabled><option value="">Select a campus first</option></select></div>
</div></section>
<div id="programNotice" class="alert d-none" role="status" aria-live="polite"></div>
<section class="di-program-metrics mb-4" aria-label="Program summary">
  <?php foreach ([['programs','Programs','bx-buildings','primary','programStatusCounts','Select a campus'],['colleges','Colleges','bx-building-house','info','collegeCountNote','In the selected campus / college'],['prospectuses','Prospectus Versions','bx-spreadsheet','success','','Across the selected programs'],['subjects','Linked Subjects','bx-book','warning','','Distinct subjects across prospectuses']] as $metric): ?>
    <div class="card"><div class="card-body"><div class="di-program-metric-label"><span><?= $metric[1] ?></span><span class="di-program-metric-icon bg-label-<?= $metric[3] ?>"><i class="bx <?= $metric[2] ?>" aria-hidden="true"></i></span></div><strong id="count-<?= $metric[0] ?>">—</strong><small<?= $metric[4] ? ' id="' . $metric[4] . '"' : '' ?>><?= $metric[5] ?></small></div></div>
  <?php endforeach; ?>
</section>
<section class="card" aria-labelledby="programListHeading" id="programList" aria-busy="false">
  <div class="card-header di-program-table-heading"><div><h2 class="h5 mb-1" id="programListHeading">Program List</h2><p class="mb-0 text-muted small" id="programScopeLabel">Select a campus to get started.</p></div><div class="di-program-search"><label class="visually-hidden" for="programSearch">Search programs</label><input class="form-control" type="search" id="programSearch" placeholder="Search code, name, or major" disabled></div></div>
  <div class="table-responsive"><table class="table table-hover align-middle di-program-table" id="programTable"><thead><tr><th scope="col">#</th><th scope="col">Program</th><th scope="col">Major</th><th scope="col">Prospectuses</th><th scope="col">Status</th><th scope="col" class="text-end">Actions</th></tr></thead><tbody id="programRows"><tr><td colspan="6" class="text-center text-muted py-5">Select a campus to view its programs.</td></tr></tbody></table></div>
  <div class="card-body di-list-footer py-3"><span class="di-list-meta" id="programResultCount" aria-live="polite">No campus selected</span><div class="di-pagination"><button class="btn btn-sm btn-outline-secondary" type="button" id="programPrevious" disabled>Previous</button><span class="small text-muted" id="programPage">Page 1 of 1</span><button class="btn btn-sm btn-outline-secondary" type="button" id="programNext" disabled>Next</button></div></div>
</section>
<div class="modal fade" id="programModal" tabindex="-1" aria-labelledby="programModalTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form id="programForm">
    <div class="modal-header"><h2 class="modal-title h5" id="programModalTitle">Add Program</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="alert alert-warning d-none" id="programFormError" role="alert"></div>
      <input type="hidden" name="program_id" id="editProgramId"><input type="hidden" name="campus_id" id="editProgramCampus">
      <div class="mb-3"><label class="form-label" for="programCampusLabel">Campus</label><input class="form-control" id="programCampusLabel" readonly></div>
      <div class="mb-3"><label class="form-label" for="editProgramCollege">College <span class="text-danger">*</span></label><select class="form-select" id="editProgramCollege" name="college_id" required></select></div>
      <div class="mb-3"><label class="form-label" for="editProgramCode">Program Code <span class="text-danger">*</span></label><input class="form-control" id="editProgramCode" name="program_code" maxlength="20" placeholder="e.g., BSIT" required></div>
      <div class="mb-3"><label class="form-label" for="editProgramName">Program Name <span class="text-danger">*</span></label><input class="form-control" id="editProgramName" name="program_name" maxlength="255" placeholder="e.g., Bachelor of Science in Information Technology" required></div>
      <div class="mb-3"><label class="form-label" for="editProgramMajor">Major <span class="text-muted">(optional)</span></label><input class="form-control" id="editProgramMajor" name="major" maxlength="255" placeholder="e.g., Network Technology"></div>
      <div id="programStatusField" hidden><label class="form-label" for="editProgramStatus">Status</label><select class="form-select" id="editProgramStatus" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select><small class="text-muted">Inactive programs retain their prospectuses and existing academic records.</small></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="saveProgramButton">Save Program</button></div>
  </form>
</div></div></div>
<script id="diProgramConfig" type="application/json"><?= json_encode($programConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<?php include __DIR__ . '/page-end.php'; ?>
