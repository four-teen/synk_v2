<div class="modal fade" id="createProspectusModal" tabindex="-1" aria-labelledby="createProspectusTitle" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="createProspectusForm">
        <div class="modal-header">
          <h5 id="createProspectusTitle" class="modal-title">Create New Prospectus</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="create_program_label" class="form-label">Program</label>
            <textarea id="create_program_label" class="form-control" rows="2" readonly></textarea>
          </div>
          <div class="mb-3">
            <label for="create_cmo_no" class="form-label">CMO Number</label>
            <input type="text" id="create_cmo_no" class="form-control" placeholder="e.g., CMO 25, Series of 2015" required>
          </div>
          <div class="mb-3">
            <label for="create_effective_sy" class="form-label">Effectivity SY</label>
            <input type="text" id="create_effective_sy" class="form-control" placeholder="e.g., 2026-2027" required>
          </div>
          <p class="small text-muted mb-0">Create an empty version, then add its year/semester structure and subjects.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" id="btnConfirmCreateProspectus" class="btn btn-primary">Create Prospectus</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="copyProspectusModal" tabindex="-1" aria-labelledby="copyProspectusTitle" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="copyProspectusForm">
        <div class="modal-header">
          <h5 id="copyProspectusTitle" class="modal-title">Copy Prospectus</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="copy_source_label" class="form-label">Source Prospectus</label>
            <textarea id="copy_source_label" class="form-control" rows="3" readonly></textarea>
          </div>
          <div class="mb-3">
            <label for="copy_target_program" class="form-label">Destination Program</label>
            <select id="copy_target_program" class="form-select" required><option value="">Select Destination Program</option></select>
            <small class="text-muted">Choose this program or another program in your college.</small>
          </div>
          <div class="mb-3">
            <label for="copy_cmo_no" class="form-label">CMO Number</label>
            <input type="text" id="copy_cmo_no" class="form-control" required>
          </div>
          <div class="mb-3">
            <label for="copy_effective_sy" class="form-label">Effectivity SY</label>
            <input type="text" id="copy_effective_sy" class="form-control" required>
          </div>
          <p class="small text-muted mb-0">Copies the year/semester structure and subjects into a separate prospectus. For the same program, use a different CMO number or effectivity year.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" id="btnConfirmCopyProspectus" class="btn btn-primary">Copy Prospectus</button>
        </div>
      </form>
    </div>
  </div>
</div>
