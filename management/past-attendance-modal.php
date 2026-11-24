<?php if (!isset($scope) || empty($canRecordPastAttendance)) { http_response_code(404); exit; } ?>
<div class="modal fade" id="management-past-attendance" tabindex="-1" aria-labelledby="past-attendance-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="past-attendance-title">Import past attendance</h2>
        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="past-attendance-form" class="management-modal-form" method="post" enctype="multipart/form-data" data-template="<?= $isFlagRaising ? 'FRC' : 'EVENT' ?>">
        <div class="modal-body">
          <div class="alert alert-danger" id="past-attendance-error" role="alert" hidden></div>
          <fieldset class="row g-3" id="past-attendance-fields">
            <div class="col-12">
              <label for="past-event-title" class="form-label">Event title</label>
              <input class="form-control" id="past-event-title" name="title" maxlength="255" required>
            </div>
            <div class="col-12 col-md-6">
              <label for="past-attendance-date" class="form-label">Attendance date</label>
              <input class="form-control" id="past-attendance-date" name="event_date" type="date" max="<?= $isFlagRaising ? date('Y-m-d',strtotime('-1 day')) : date('Y-m-d') ?>" aria-describedby="past-attendance-date-help" required>
              <p class="form-text mb-0" id="past-attendance-date-help"><?= $isFlagRaising ? 'Enter the date shown in A1 of the workbook.' : 'Enter the date the event took place.' ?></p>
            </div>
            <div class="col-6 col-md-3">
              <label for="past-attendance-year" class="form-label">Academic year</label>
              <input class="form-control" id="past-attendance-year" value="<?= synk_management_h($currentTerm['ay_label']) ?>" readonly>
              <input type="hidden" name="import_ay_id" value="<?= (int)$currentTerm['ay_id'] ?>">
            </div>
            <div class="col-6 col-md-3">
              <label for="past-attendance-semester" class="form-label">Semester</label>
              <input class="form-control" id="past-attendance-semester" value="<?= synk_management_h($currentTerm['semester_label']) ?>" readonly>
              <input type="hidden" name="import_semester" value="<?= (int)$currentTerm['semester'] ?>">
            </div>
            <div class="col-12 col-md-6">
              <label for="past-attendance-term" class="form-label">Management term</label>
              <select class="form-select" id="past-attendance-term" name="import_exam_term" required>
                <option value="">Choose a term</option>
                <?php foreach (synk_management_exam_term_options() as $value=>$label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label for="past-attendance-template" class="form-label">Attendance template</label>
              <select class="form-select" id="past-attendance-template" name="template" required>
                <option value="">Choose a template</option>
                <?php if ($isFlagRaising): ?><option value="FRC">FRC (Flag Raising Ceremony)</option>
                <?php else: ?><option value="EVENT">EVENT</option><?php endif; ?>
              </select>
            </div>
            <?php if (!$isFlagRaising): ?>
              <div class="col-12 col-md-6">
                <label for="past-attendance-fine" class="form-label">Absence fine (PHP)</label>
                <input class="form-control" id="past-attendance-fine" name="absence_fine" type="number" min="0" max="9999999999.99" step="0.01" required>
              </div>
              <div class="col-12 col-md-6">
                <label for="past-attendance-description" class="form-label">Description</label>
                <textarea class="form-control" id="past-attendance-description" name="description" maxlength="10000" rows="2"></textarea>
              </div>
            <?php endif; ?>
            <div class="col-12">
              <label for="past-attendance-workbook" class="form-label">Excel workbook</label>
              <input class="form-control" id="past-attendance-workbook" name="workbook" type="file" accept=".xlsx" aria-describedby="past-attendance-format" required>
              <p class="form-text mb-0" id="past-attendance-format"><?= $isFlagRaising ? 'Use one workbook per event. FRC reads the date in A1 and student IDs in column A of the first worksheet.' : 'Use columns D (IN) and E (OUT). A student is present when both cells on the same row contain their ID. The event uses the full semester roster.' ?></p>
            </div>
          </fieldset>
          <div class="alert alert-info mt-3 mb-0" id="past-attendance-preview" hidden>
            <p class="mb-2" id="past-attendance-summary" role="status"></p>
            <p class="mb-3"><a class="alert-link" id="past-attendance-event-link" hidden><?= $isFlagRaising ? 'View ceremony' : 'View event' ?></a></p>
            <section class="border-top pt-2 mb-3" id="past-attendance-exceptions" aria-labelledby="past-attendance-issues-title" hidden>
              <h3 class="fs-6 mb-1" id="past-attendance-issues-title"></h3>
              <p class="small mb-2"><?= $isFlagRaising ? 'Check the listed cells in your workbook. Upload the corrected file for this event to add newly matched students while attendance is open.' : 'Check these cells before saving. Students without a verified IN and OUT pair are absent unless exempted.' ?></p>
              <ul class="ps-3 mb-0" id="past-attendance-issues"></ul>
            </section>
            <details class="border-top pt-2" id="past-attendance-duplicates" hidden>
              <summary id="past-attendance-duplicates-title"></summary>
              <p class="small mt-2 mb-2"><?= $isFlagRaising ? 'Each extra occurrence of a student ID is one duplicate row. Each matched student is marked present once.' : 'A student can be present once. One-sided entries on separate rows do not form a pair.' ?></p>
              <ul class="ps-3 mb-0" id="past-attendance-duplicate-rows"></ul>
            </details>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Done</button>
          <button class="btn btn-primary" type="submit" id="past-attendance-save"><?= $isFlagRaising ? 'Import attendance' : 'Preview attendance' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
