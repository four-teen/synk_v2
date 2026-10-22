<?php if (!isset($csrfToken,$selectedCollegeId,$campusId,$currentTerm)) return; ?>
<section class="card shadow-sm college-student-workload-card" id="collegeStudentWorkload" aria-labelledby="studentWorkloadTitle">
  <div class="card-header student-workload-heading">
    <div><p class="card-section-title mb-1">Student workload activity</p><div class="d-flex flex-wrap align-items-center gap-2"><h5 class="m-0" id="studentWorkloadTitle">Suspicious Workload Review</h5><span class="badge bg-label-info"><?= htmlspecialchars($currentTerm['term_text'],ENT_QUOTES,'UTF-8') ?></span></div><p class="student-workload-copy mt-2 mb-0">Students enrolled in 6 or more subjects. Review unusual combinations and schedule overlaps.</p></div>
    <button type="button" class="btn btn-sm btn-outline-primary" id="studentWorkloadRefresh"><i class="bx bx-refresh me-1" aria-hidden="true"></i>Refresh</button>
  </div>
  <div class="card-body pt-0">
    <div class="student-workload-summary" id="studentWorkloadSummary" aria-live="polite"><span>Loading student workloads...</span></div>
    <form class="student-workload-toolbar" id="studentWorkloadFilters" role="search">
      <label class="student-workload-search"><span class="visually-hidden">Find a student by name or ID</span><i class="bx bx-search" aria-hidden="true"></i><input type="search" class="form-control form-control-sm" id="studentWorkloadSearch" placeholder="Search student name or ID" maxlength="150"></label>
      <label><span>Show</span><select class="form-select form-select-sm" id="studentWorkloadSignal"><option value="all">All students with 6+ subjects</option><option value="review">With review signals</option><option value="conflicts">With schedule overlaps</option></select></label>
      <label><span>Sort by</span><select class="form-select form-select-sm" id="studentWorkloadSort"><option value="review">Review priority</option><option value="subjects">Most subjects</option><option value="units">Highest units</option><option value="name">Student name</option></select></label>
    </form>
    <div class="student-workload-notice" id="studentWorkloadNotice" role="status" hidden></div>
    <div class="student-workload-list" id="studentWorkloadList" aria-busy="true"><p class="student-workload-copy p-3 mb-0">Loading students...</p></div>
    <div class="student-workload-footer"><span class="student-workload-copy" id="studentWorkloadPageInfo" aria-live="polite"></span><div class="d-flex align-items-center gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" id="studentWorkloadPrevious" disabled>Previous</button><span class="student-workload-copy" id="studentWorkloadPage">Page 1</span><button type="button" class="btn btn-sm btn-outline-secondary" id="studentWorkloadNext" disabled>Next</button></div></div>
    <p class="student-workload-copy mt-3 mb-0"><i class="bx bx-info-circle me-1" aria-hidden="true"></i>Review signals are clues, not confirmed errors. Irregular students may have subjects across programs or year levels. Hours are weekly curriculum contact hours.</p>
  </div>
</section>
<script id="studentWorkloadConfig" type="application/json"><?= json_encode(['csrf_token'=>$csrfToken,'college_id'=>$selectedCollegeId,'campus_id'=>$campusId,'ay_id'=>(int)$currentTerm['ay_id'],'semester'=>(int)$currentTerm['semester']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
