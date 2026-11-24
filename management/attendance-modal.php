<?php if (!isset($scope)) { http_response_code(404);exit; } ?>
<div class="modal fade" id="management-attendance" tabindex="-1" aria-labelledby="attendance-title" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
<div class="modal-header"><div><span class="management-financial-kicker">EVENT ATTENDANCE</span><h2 class="modal-title fs-5" id="attendance-title">Take attendance</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
<div class="modal-body"><div id="attendance-error" class="alert alert-danger" role="alert" hidden></div>
<div class="management-attendance-summary"><span>Status <strong id="attendance-status">Loading…</strong></span><span>Roster <strong id="attendance-eligible">—</strong></span><span>Present <strong id="attendance-present">—</strong></span><span>Exempted <strong id="attendance-exempted">0</strong></span><span>Absent <strong id="attendance-absent">—</strong></span></div>
<p class="small text-muted mt-2 mb-3" id="attendance-event-context"></p>
<dl class="management-attendance-times" id="attendance-times" hidden><div><dt>Opened</dt><dd id="attendance-opened-at">—</dd></div><div><dt>Closed</dt><dd id="attendance-closed-at">—</dd></div></dl>
<details class="management-attendance-close mb-3" hidden><summary id="attendance-state-label">Close attendance</summary><div class="mt-3"><p id="attendance-close-description" class="small mb-2"></p><label class="d-flex gap-2 align-items-start"><input class="form-check-input" type="checkbox" id="attendance-confirm"><span class="small" id="attendance-confirm-label">I confirm this attendance change.</span></label><button class="btn btn-outline-danger mt-2" type="button" id="attendance-state-change" disabled>Close attendance</button></div></details>
<section id="attendance-roster" aria-labelledby="attendance-roster-title" hidden>
  <h3 class="fs-6" id="attendance-roster-title">Student attendance</h3>
  <form id="attendance-roster-filter" class="management-attendance-roster-filter mb-3">
    <div><label class="form-label" for="attendance-roster-search">Student name or ID</label><input type="search" class="form-control" id="attendance-roster-search" maxlength="150"></div>
    <div><label class="form-label" for="attendance-roster-status">Attendance status</label><select class="form-select" id="attendance-roster-status"><option value="">All statuses</option><option value="PRESENT">Present</option><option value="ABSENT">Absent</option><option value="EXEMPTED">Exempted</option></select></div>
    <button class="btn btn-outline-primary" type="submit">Search</button>
  </form>
  <form id="attendance-correction" class="management-attendance-correction mb-3" hidden>
    <h4 class="fs-6 mb-2" id="attendance-correction-student"></h4>
    <fieldset id="attendance-correction-checkpoints" class="d-flex gap-3 mb-2" hidden>
      <legend class="form-label fs-6">Verified attendance</legend>
      <label class="form-check"><input class="form-check-input" type="checkbox" id="attendance-correction-in"> IN</label>
      <label class="form-check"><input class="form-check-input" type="checkbox" id="attendance-correction-out"> OUT</label>
    </fieldset>
    <p class="form-text mb-2" id="attendance-correction-checkpoint-help" hidden>Confirm both IN and OUT to mark the student present.</p>
    <div id="attendance-correction-status-field" class="mb-2"><label class="form-label" for="attendance-correction-status">Corrected attendance</label><select class="form-select" id="attendance-correction-status"><option value="PRESENT">Present</option><option value="ABSENT">Absent</option></select></div>
    <label class="form-label" for="attendance-correction-reason">Reason or evidence reference</label>
    <textarea class="form-control mb-3" id="attendance-correction-reason" rows="2" maxlength="500" required></textarea>
    <div class="d-flex flex-wrap gap-2"><button type="submit" class="btn btn-primary" id="attendance-correction-save">Save correction</button><button type="button" class="btn btn-outline-secondary" id="attendance-correction-cancel">Cancel</button></div>
  </form>
  <p id="attendance-roster-notice" class="small text-success" role="status" hidden></p>
  <div class="management-table-scroll" role="region" aria-label="Finalized attendance roster" tabindex="0">
    <table class="table dean-students-table management-table management-mobile-records management-attendance-roster-table" role="table">
      <thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Student</th><th role="columnheader" scope="col" data-attendance-checkpoint hidden>IN</th><th role="columnheader" scope="col" data-attendance-checkpoint hidden>OUT</th><th role="columnheader" scope="col">Status</th><th role="columnheader" scope="col" data-roster-edit hidden>Actions</th></tr></thead>
      <tbody id="attendance-roster-rows" role="rowgroup"></tbody>
    </table>
  </div>
  <nav class="management-pagination px-0 pb-0" aria-label="Attendance roster pages"><span id="attendance-roster-page" role="status"></span><div><button type="button" class="btn btn-sm btn-outline-primary" id="attendance-roster-previous" disabled>Previous</button><button type="button" class="btn btn-sm btn-outline-primary" id="attendance-roster-next" disabled>Next</button></div></nav>
</section>
<div class="management-attendance-reader-grid" hidden><div class="management-attendance-camera-column">
<div class="management-attendance-camera"><video id="attendance-video" autoplay playsinline muted hidden></video><div id="attendance-camera-placeholder"><i class="bx bx-qr-scan" aria-hidden="true"></i><p>Point your camera at the student's attendance QR.</p></div></div>
<p class="management-attendance-camera-status" id="attendance-camera-status" role="status">Camera stopped</p>
<div class="mb-3" id="attendance-camera-picker" hidden><label for="attendance-camera-device" class="form-label">Camera</label><select class="form-select" id="attendance-camera-device"></select></div>
<div class="d-flex gap-2 flex-wrap my-3 management-attendance-camera-actions"><button type="button" class="btn btn-primary" id="attendance-camera-start">Start camera</button><button type="button" class="btn btn-outline-secondary" id="attendance-camera-stop" disabled>Stop camera</button><label class="btn btn-outline-primary mb-0" for="attendance-upload" id="attendance-upload-label" tabindex="0">Read QR image</label><input type="file" id="attendance-upload" accept="image/*" class="visually-hidden"></div>
<p class="small text-muted">Verify the student's name after each scan. Camera access uses HTTPS or localhost. You can also read a QR image or use a connected QR scanner.</p>
<details id="attendance-hardware"><summary>Use a connected QR scanner</summary><form id="attendance-code-form" class="d-flex gap-2 mt-2"><label class="visually-hidden" for="attendance-code">Student attendance QR</label><input class="form-control" id="attendance-code" autocomplete="off" maxlength="100" placeholder="Scan the student QR here"><button class="btn btn-outline-primary" id="attendance-code-submit" type="submit">Record</button></form></details>
</div><div class="management-attendance-result-column">
<div id="attendance-result" class="management-attendance-result" role="status" aria-live="polite" hidden><img id="attendance-student-photo" src="../student/profile-placeholder.svg" alt="Student profile photo"><div><strong id="attendance-result-status"></strong><h3 id="attendance-student-name"></h3><span id="attendance-student-number"></span><small id="attendance-recorded-at"></small></div></div>
<p class="small text-muted" id="attendance-ready-note">The scanned student’s photo and details will appear here after attendance is saved.</p>
<details class="management-attendance-recent mt-3"><summary>Recent attendance</summary><ul id="attendance-recent" class="list-group list-group-flush mt-2"></ul></details>
</div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Done</button></div>
</div></div></div>
