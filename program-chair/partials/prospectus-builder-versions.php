<div class="card mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <h5 class="mb-1">Prospectus Versions</h5>
      <small id="programProspectusStatus" class="text-muted" aria-live="polite">Select a program to see its prospectuses.</small>
    </div>
    <button type="button" id="btnRefreshProspectuses" class="btn btn-sm btn-outline-secondary" disabled>
      <i class="bx bx-refresh me-1"></i> Refresh
    </button>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead class="table-light">
        <tr><th>CMO Number</th><th>Effectivity SY</th><th class="text-center">Subjects</th><th class="text-center">Total Units</th><th class="text-end">Action</th></tr>
      </thead>
      <tbody id="programProspectusRows">
        <tr><td colspan="5" class="text-center text-muted py-4">Select a program to view its prospectus versions.</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div id="loadedProspectusCard" class="card mb-4 border border-primary" style="display:none;" aria-live="polite">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div class="flex-grow-1">
        <span class="badge bg-label-primary mb-2">Loaded prospectus</span>
        <h6 id="loadedProgramName" class="mb-3"></h6>
        <div class="row g-3">
          <div class="col-sm-8"><small class="text-muted d-block">CMO NUMBER</small><span id="loadedCmo"></span></div>
          <div class="col-sm-4"><small class="text-muted d-block">EFFECTIVITY SY</small><span id="loadedEffectivity"></span></div>
        </div>
      </div>
      <button type="button" id="btnCopyProspectus" class="btn btn-outline-primary" disabled>
        <i class="bx bx-copy me-1"></i> Copy Prospectus
      </button>
    </div>
    <p class="small text-muted mt-3 mb-0">The year/semester structure and subjects below belong to this prospectus.</p>
  </div>
</div>
