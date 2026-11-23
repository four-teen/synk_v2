<?php if (empty($canEditStudentEmail)) { http_response_code(404); return; } ?>
  <div class="modal fade csr-status-modal" id="csrEmailModal" tabindex="-1" aria-labelledby="csrEmailTitle">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="csrEmailForm">
        <div class="modal-header">
          <h3 class="modal-title" id="csrEmailTitle">Edit student email</h3>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close email editor"></button>
        </div>
        <div class="modal-body">
          <div class="csr-confirm-student"><i class="bx bx-user" aria-hidden="true"></i><div><strong id="csrEmailName"></strong><span id="csrEmailNumber"></span></div></div>
          <p class="csr-confirm-help" id="csrEmailHelp">Confirm the student’s actual SKSU email. This updates the address they use to sign in.</p>
          <label class="form-label" for="csrEmailAddress">School email</label>
          <input class="form-control" id="csrEmailAddress" type="email" maxlength="255" autocomplete="off" required aria-describedby="csrEmailHelp csrEmailError">
          <p class="csr-status-error" id="csrEmailError" role="status"></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="csrEmailSave" disabled>Save email</button>
        </div>
      </form>
    </div>
  </div>
