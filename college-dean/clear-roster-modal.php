<?php if (!$deanWorkloadConfig['manual_roster_enabled']) return; ?>
<div class="modal fade" id="deanClearRosterModal" tabindex="-1" aria-labelledby="deanClearRosterTitle" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><div><h5 class="modal-title" id="deanClearRosterTitle">Clear Student List</h5><p class="small text-muted mb-0 mt-1" id="deanClearClass"></p></div><button type="button" class="btn-close" id="deanClearClose" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <div class="alert alert-info">Students will remain in the Students list with their profiles and accounts. Other subjects, previous semesters, and submitted evaluations are preserved.</div>
      <p>This clears all imported and manually added students from the selected class. The subject will leave their current class list until they are added again. You can then remove or reassign the workload in the Scheduler and import the corrected list.</p>
      <div id="deanClearNotice" class="alert alert-danger d-none" role="status"></div>
      <div id="deanClearPreview" aria-live="polite"></div>
      <div class="form-check mt-3" id="deanClearAcknowledgement" hidden><input type="checkbox" class="form-check-input" id="deanClearConfirmed"><label class="form-check-label" for="deanClearConfirmed">I want to clear every student from this class only.</label></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" id="deanClearCancel" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-outline-primary" id="deanClearRetry" hidden>Preview Again</button><button type="button" class="btn btn-danger" id="deanClearConfirm" disabled>Clear Student List</button></div>
  </div></div>
</div>
