<?php
if (!isset($class,$onlineAttendanceReady) || $view !== 'attendance' || ($_SESSION['role'] ?? '') !== 'professor') {
    http_response_code(404);
    return;
}
?>
<section id="onlineAttendanceWorkspace" class="oa-workspace" aria-labelledby="oaTitle">
  <header class="oa-heading">
    <div>
      <h2 id="oaTitle">Online Attendance</h2>
      <p><i class="bx bx-time-five" aria-hidden="true"></i> Philippine time (UTC+8)</p>
    </div>
    <div class="oa-actions">
      <a class="btn btn-outline-secondary" href="<?= synk_professor_h(synk_professor_class_url_lms($class,'gradebook')) ?>">Grade Book</a>
      <?php if ($onlineAttendanceReady): ?><button class="btn btn-primary" id="oaNewSession" type="button" disabled><i class="bx bx-plus me-1" aria-hidden="true"></i>Schedule session</button><?php endif; ?>
    </div>
  </header>
  <?php if (!$onlineAttendanceReady): ?>
    <p class="alert alert-info" role="status">Online Attendance needs the LMS database update. Contact your administrator.</p>
  <?php else: ?>
    <div class="oa-period-bar">
      <div class="oa-periods" role="group" aria-label="Attendance grading period">
        <button class="btn btn-primary" type="button" data-oa-period="midterm" aria-pressed="true">Midterm</button>
        <button class="btn btn-outline-primary" type="button" data-oa-period="final" aria-pressed="false">Final</button>
      </div>
      <button class="btn btn-outline-secondary" id="oaRefresh" type="button"><i class="bx bx-refresh me-1" aria-hidden="true"></i>Refresh</button>
    </div>
    <p id="oaNotice" role="status" class="oa-notice mb-3">Loading attendance…</p>
    <div id="oaError" class="alert alert-danger" role="alert" hidden></div>

    <div id="oaContent" hidden>
      <section class="card oa-panel" aria-labelledby="oaSessionsTitle">
        <div class="oa-panel-heading"><h3 id="oaSessionsTitle">Sessions</h3></div>
        <div id="oaSessions"></div>
      </section>
      <section class="card oa-panel" aria-labelledby="oaTotalsTitle">
        <div class="oa-panel-heading">
          <div><h3 id="oaTotalsTitle">Attendance totals</h3><p>Closed sessions count toward these totals. Each student’s total uses their eligible sessions.</p></div>
          <span id="oaClosedCount" class="oa-session-count"></span>
        </div>
        <div id="oaTotals"></div>
        <form id="oaGradeForm" class="oa-grade-form">
          <div><strong>Apply to Grade Book</strong><p id="oaGradeState" class="mb-0 text-muted"></p></div>
          <div id="oaComponentField">
            <label class="form-label" for="oaComponent">Grading component</label>
            <select class="form-select" id="oaComponent" required></select>
          </div>
          <button class="btn btn-primary" id="oaApply" type="submit" disabled>Apply attendance scores</button>
          <p id="oaGradeHelp" class="oa-grade-help mb-0 text-muted"></p>
        </form>
      </section>
    </div>

    <div class="modal fade" id="oaSessionModal" tabindex="-1" aria-labelledby="oaSessionTitle">
      <div class="modal-dialog modal-dialog-centered"><form id="oaSessionForm" class="modal-content">
        <div class="modal-header"><h3 id="oaSessionTitle" class="modal-title h5">Schedule attendance</h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <p id="oaSchedulePeriod" class="oa-notice"></p>
          <label class="form-label" for="oaSessionName">Session title</label>
          <input class="form-control mb-3" id="oaSessionName" maxlength="200" required>
          <div class="oa-dates">
            <div><label class="form-label" for="oaOpens">Opens (PHT)</label><input class="form-control" type="datetime-local" id="oaOpens" required></div>
            <div><label class="form-label" for="oaCloses">Closes (PHT)</label><input class="form-control" type="datetime-local" id="oaCloses" required></div>
          </div>
          <p class="small text-muted mb-0 mt-3">The attendance roster is captured when you save the session.</p>
          <div id="oaScheduleError" class="alert alert-danger mt-3 mb-0" role="alert" hidden></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary" id="oaSaveSession">Schedule session</button></div>
      </form></div>
    </div>

    <div class="modal fade" id="oaReviewModal" tabindex="-1" aria-labelledby="oaReviewTitle">
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h3 id="oaReviewTitle" class="modal-title h5">Session attendance</h3><p id="oaReviewTime" class="small text-muted mb-0"></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <p id="oaReviewNotice" role="status" class="oa-notice"></p>
          <div id="oaReviewError" class="alert alert-danger" role="alert" hidden></div>
          <form id="oaReviewForm" class="oa-review-form" hidden>
            <strong id="oaReviewFormTitle"></strong>
            <div id="oaResultField"><label class="form-label" for="oaResult">Result</label><select class="form-select" id="oaResult">
              <option value="present">Present</option><option value="absent">Absent</option><option value="automatic">Use automatic result</option>
            </select></div>
            <div><label class="form-label" for="oaReason">Reason</label><textarea class="form-control" id="oaReason" rows="2" maxlength="500" required></textarea></div>
            <div class="oa-actions"><button class="btn btn-outline-secondary" type="button" id="oaCloseReviewForm">Back</button><button class="btn btn-primary" type="submit" id="oaSaveReview">Save correction</button></div>
          </form>
          <div id="oaReviewRoster"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-danger me-auto" id="oaCancelSession" hidden>Cancel session</button><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
      </div></div>
    </div>
    <script id="onlineAttendanceConfig" type="application/json"><?= json_encode([
        'endpoint'=>'online_attendance_api_lms.php','csrf_token'=>$_SESSION['lms_online_attendance_csrf'],
        'context'=>array_intersect_key($_GET,array_flip(['offering_id','ay_id','semester','view','signature'])),
        'period'=>($_GET['period'] ?? '') === 'final' ? 'final' : 'midterm',
    ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
</section>
