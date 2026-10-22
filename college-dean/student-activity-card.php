<?php if (!isset($csrfToken, $selectedCollegeId, $campusId, $currentTerm)) return; ?>
<section class="card shadow-sm college-student-activity-card" id="collegeStudentActivity" aria-labelledby="studentActivityTitle">
  <div class="card-header student-activity-heading">
    <div><p class="card-section-title mb-1">Student access</p><div class="d-flex flex-wrap align-items-center gap-2"><h5 class="m-0" id="studentActivityTitle">Student Login Activity</h5><span class="badge bg-label-info" id="studentActivityTerm"><?= htmlspecialchars($currentTerm['term_text'], ENT_QUOTES, 'UTF-8') ?></span></div><p class="student-activity-copy mb-0 mt-2">One square per student in the selected academic year and semester, alphabetically by surname.</p></div>
    <div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-sm btn-outline-primary" id="studentActivityRefresh"><i class="bx bx-refresh me-1" aria-hidden="true"></i>Refresh</button><a class="btn btn-sm btn-outline-secondary" href="login-activity.php">All login history</a></div>
  </div>
  <div class="card-body pt-0">
    <div class="student-activity-summary" id="studentActivitySummary" aria-live="polite"><span>Loading student activity...</span></div>
    <div class="student-activity-toolbar">
      <p class="student-activity-copy mb-0">Successful sign-ins recorded for the selected term. Hover, focus, or tap a square to see the student.</p>
      <label class="student-activity-search"><i class="bx bx-search" aria-hidden="true"></i><span class="visually-hidden">Find a student in the activity grid</span><input type="search" class="form-control form-control-sm" id="studentActivitySearch" placeholder="Find a student by name or ID" maxlength="150"></label>
    </div>
    <div class="student-activity-notice" id="studentActivityNotice" role="status" hidden></div>
    <div class="student-activity-map" id="studentActivityMap" aria-label="Student login activity squares" aria-busy="true"><p class="student-activity-copy mb-0">Loading students...</p></div>
    <div class="student-activity-footer">
      <span class="student-activity-copy" id="studentActivityUpdated">Refreshes every 30 seconds.</span>
      <div class="student-activity-legend" aria-label="Square color legend"><span><i class="student-activity-swatch level-0" aria-hidden="true"></i>0 logins</span><span><i class="student-activity-swatch level-1" aria-hidden="true"></i>1–4</span><span><i class="student-activity-swatch level-2" aria-hidden="true"></i>5–9</span><span><i class="student-activity-swatch level-3" aria-hidden="true"></i>10+</span><span id="studentActivityOnlineLegend"<?= empty($currentTerm['is_current'])?' hidden':'' ?>><i class="student-activity-swatch is-online" aria-hidden="true"></i>Online now</span></div>
    </div>
    <p class="student-activity-copy mt-3 mb-0" id="studentActivityPresenceNote"><?= empty($currentTerm['is_current']) ? 'Historical term. Live online status is shown only for the current academic term.' : 'Orange means a recently connected student session. After 15 minutes without activity, the session signs out and the square returns to its login-count color.' ?></p>
    <p class="student-activity-copy mt-2 mb-0">Previous sign-ins with a verified academic term are included in these counts.</p>
  </div>
</section>
<script id="studentActivityConfig" type="application/json"><?= json_encode(['csrf_token'=>$csrfToken,'college_id'=>$selectedCollegeId,'campus_id'=>$campusId,'ay_id'=>(int)$currentTerm['ay_id'],'semester'=>(int)$currentTerm['semester'],'live_presence'=>!empty($currentTerm['is_current'])], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
