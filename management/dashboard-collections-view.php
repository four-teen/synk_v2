<?php
if (!isset($collectionTotals, $currentTerm)) { http_response_code(404); exit; }
?>
<div class="modal fade management-collections-modal" id="management-collections" tabindex="-1"
  aria-labelledby="management-collections-title" aria-describedby="management-collections-period" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h2 class="modal-title fs-5" id="management-collections-title">Total Collected</h2>
          <p class="management-collections-period" id="management-collections-period"><?= synk_management_h($currentTerm['period_text']) ?></p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form class="management-collections-search" data-collection-search>
        <div>
          <label class="form-label" for="management-collection-search-input">Search students</label>
          <input class="form-control" type="search" id="management-collection-search-input" name="search"
            placeholder="Student name or ID" maxlength="150" autocomplete="off" enterkeyhint="search" aria-controls="management-collection-rows">
        </div>
        <span class="management-collections-matches" role="status" data-collection-matches hidden></span>
      </form>
      <div class="modal-body" tabindex="0" role="region" aria-label="Student payments">
        <div class="management-collections-overview" data-collection-summary hidden>
          <div><span>Collected this period</span><strong data-collection-total></strong></div>
          <span data-collection-payers></span>
        </div>
        <p class="management-collections-state" role="status" data-collection-state hidden></p>
        <div class="alert alert-danger mb-0" role="alert" data-collection-error hidden>
          <p class="mb-2"></p>
          <button type="button" class="btn btn-sm btn-outline-danger" data-collection-retry>Try again</button>
        </div>
        <div class="management-collections-students" data-collection-rows id="management-collection-rows"></div>
      </div>
      <div class="modal-footer management-collections-pagination" data-collection-pagination hidden>
        <span data-collection-page></span>
        <button type="button" class="btn btn-sm btn-outline-primary" data-collection-previous>Previous</button>
        <button type="button" class="btn btn-sm btn-outline-primary" data-collection-next>Next</button>
      </div>
    </div>
  </div>
</div>
