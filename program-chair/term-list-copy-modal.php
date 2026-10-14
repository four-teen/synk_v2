<?php
if (!isset($termCopyKind, $termCopyCollegeId, $csrf_token)) return;
$termCopyConfig = ['ay_id'=>(int)$currentTerm['ay_id'], 'semester'=>(int)$currentTerm['semester'], 'kind' => $termCopyKind, 'college_id' => (int)$termCopyCollegeId, 'csrf_token' => (string)$csrf_token,
    'trigger_id' => $termCopyKind === 'faculty' ? 'btnCopyPreviousFaculty' : 'btnOpenCopyRooms'];
?>
<div class="modal fade" id="termListCopyModal" tabindex="-1" aria-labelledby="termListCopyTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="termListCopyTitle">Copy previous <?= $termCopyKind === 'faculty' ? 'faculty' : 'room' ?> list</h5><button type="button" class="btn-close" id="termListCopyClose" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <p class="scheduler-copy-intro">Select the entries to add to the current academic year and semester. Entries already listed will be skipped.<?= $termCopyKind === 'rooms' ? ' Owned rooms can be copied; sharing is set separately for the new term.' : ''; ?></p>
      <div class="scheduler-copy-context"><span>Copy to current term</span><strong id="termListCopyTarget">Loading current term…</strong></div>
      <label for="termListCopySource" class="form-label">Copy from</label><select id="termListCopySource" class="form-select mb-3" disabled><option value="">Loading previous terms…</option></select>
      <div id="termListCopyNotice" class="alert alert-info" role="status" hidden></div>
      <div class="scheduler-copy-selection"><label><input type="checkbox" class="form-check-input me-2" id="termListCopyAll" disabled>Select all available</label><span id="termListCopySelected" role="status" aria-live="polite">0 selected</span></div>
      <?php if ($termCopyKind === 'faculty'): ?><p class="text-muted small mb-2">The preview shows the previous term’s designation. It will be copied unless an appointment has already been updated for the current term.</p><?php endif; ?>
      <div class="table-responsive"><table class="table scheduler-copy-table"><thead><tr><th scope="col"><span class="visually-hidden">Select</span></th><th scope="col"><?= $termCopyKind === 'faculty' ? 'Faculty' : 'Room' ?></th><th scope="col">Preview</th></tr></thead><tbody id="termListCopyRows"><tr><td colspan="3" class="text-muted text-center py-4">Choose a previous term to preview its list.</td></tr></tbody></table></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" id="termListCopyCancel" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="termListCopyConfirm" disabled>Copy selected</button></div>
  </div></div>
</div>
<script id="termListCopyConfig" type="application/json"><?= json_encode($termCopyConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<link rel="stylesheet" href="../scheduler/term-list-copy.css?v=<?= filemtime(__DIR__ . '/../scheduler/term-list-copy.css') ?>">
<script src="shared-script.php?file=term-list-copy.js&amp;v=<?= filemtime(__DIR__ . '/../scheduler/term-list-copy.js') ?>" defer></script>
