<?php if (!isset($deanWorkloadConfig) || empty($deanWorkloadConfig['manual_roster_enabled'])) return; ?>
<div class="modal fade" id="deanManualRosterModal" tabindex="-1" aria-labelledby="deanManualRosterTitle" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><div><h5 class="modal-title" id="deanManualRosterTitle">Add Student to Class</h5><p class="small text-muted mb-0 mt-1" id="deanManualClass"></p></div><button type="button" class="btn-close" id="deanManualClose" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <div id="deanManualNotice" class="alert d-none" role="alert"></div>
      <div id="deanManualEntry">
        <p class="small text-muted">For a late enrollee, transferee or class-list correction. Look up the exact student ID first to reuse the existing profile.</p>
        <form id="deanManualLookupForm" class="mb-4"><label class="form-label" for="deanManualNumber">Student ID</label><div class="d-flex flex-wrap gap-2"><input id="deanManualNumber" class="form-control dean-manual-number" inputmode="numeric" pattern="[0-9]{1,32}" maxlength="32" placeholder="Keep leading zeros" required><button type="submit" class="btn btn-outline-primary" id="deanManualLookup">Look Up Student</button></div></form>
        <div id="deanManualIdentity" class="alert alert-info d-none" role="status"></div>
        <form id="deanManualDetailsForm"><fieldset id="deanManualDetails" disabled>
          <fieldset id="deanManualNewFields" hidden disabled>
            <h6>Create student profile</h6><p class="small text-muted">This ID has no saved profile. Enter the student's official details.</p>
            <div class="row g-3 mb-4">
              <div class="col-sm-6"><label for="deanManualFirst" class="form-label">Complete first name</label><input class="form-control" id="deanManualFirst" maxlength="150" required></div>
              <div class="col-sm-6"><label for="deanManualLast" class="form-label">Last name</label><input class="form-control" id="deanManualLast" maxlength="150" required></div>
              <div class="col-sm-8"><label for="deanManualMiddle" class="form-label">Middle name</label><input class="form-control" id="deanManualMiddle" maxlength="150"></div>
              <div class="col-sm-4"><label for="deanManualSuffix" class="form-label">Suffix</label><input class="form-control" id="deanManualSuffix" maxlength="50" placeholder="Jr., III"></div>
              <div class="col-12"><label for="deanManualEmail" class="form-label">School email</label><input type="email" class="form-control" id="deanManualEmail" maxlength="254" placeholder="student@sksu.edu.ph"><div class="form-text">Leave blank while awaiting verification. A verified directory email is needed for student sign-in.</div></div>
              <div class="col-sm-8"><label for="deanManualProgram" class="form-label">Student's program</label><select class="form-select" id="deanManualProgram" required></select></div>
              <div class="col-sm-4"><label for="deanManualYear" class="form-label">Student's year level</label><select class="form-select" id="deanManualYear" required><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option><option value="5">5th Year</option></select></div>
            </div>
          </fieldset>
          <div class="row g-3"><div class="col-sm-5"><label for="deanManualReason" class="form-label">Reason for addition</label><select id="deanManualReason" class="form-select" required><option value="">Select a reason</option><option value="late_enrollee">Late enrollee</option><option value="transferee">Transferee</option><option value="list_correction">Class-list correction</option></select></div><div class="col-sm-7"><label for="deanManualNote" class="form-label">Note <span class="text-muted">(optional)</span></label><textarea id="deanManualNote" class="form-control" maxlength="500" rows="2" placeholder="Details supporting this class-list change"></textarea></div></div>
          <p class="small text-muted mt-3 mb-0">The selected subject, section, faculty and academic term stay fixed. An existing student's saved profile is reused.</p>
        </fieldset></form>
      </div>
      <section id="deanManualReview" hidden aria-labelledby="deanManualReviewTitle"><h6 id="deanManualReviewTitle">Review class addition</h6><div id="deanManualReviewContent"></div><div id="deanManualTransfer" class="alert alert-warning mt-3" hidden><h6>Confirm section transfer</h6><p>This student already has an active class membership for this subject in another section. Saving will move the student from the memberships listed below to the selected class.</p><div id="deanManualPrevious"></div><label class="d-flex align-items-start gap-2 mt-3 mb-0"><input type="checkbox" class="form-check-input flex-shrink-0" id="deanManualTransferConfirmed"><span>I confirm the listed section transfer. Previous memberships will remain in history as inactive records.</span></label></div></section>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" id="deanManualCancel" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-outline-secondary" id="deanManualBack" hidden>Back to Details</button><button type="submit" form="deanManualDetailsForm" class="btn btn-primary" id="deanManualReviewButton" disabled>Review Addition</button><button type="button" class="btn btn-primary" id="deanManualConfirm" hidden disabled>Confirm Addition</button></div>
  </div></div>
</div>
