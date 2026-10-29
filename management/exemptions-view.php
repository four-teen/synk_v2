<?php
if (!isset($scope,$term,$permissions)) { http_response_code(404);exit; }
$exemptionCanWrite=$permissions['manage_exemptions'] && $term['period_key']===$currentTerm['period_key'];
?>
<section class="card management-panel management-exemptions-page" id="management-exemptions-page">
  <div class="management-panel-heading"><div><p class="card-section-title mb-1">EVENTS & CONTRIBUTIONS</p><h2>Exemptions</h2></div><?php if ($exemptionCanWrite): ?><div class="d-flex gap-2 flex-wrap"><button type="button" class="btn btn-outline-primary" id="exemption-list-new">New list</button><button type="button" class="btn btn-primary" data-exempt-open>Grant exemptions</button></div><?php endif; ?></div>
  <aside class="management-exemption-notes" aria-label="Exemption notes"><strong>Notes</strong><div>
    <p>Maintain reusable student lists, then apply them to selected events or contributions.</p>
    <p>Each application saves its students. Removing a student from a list never changes exemptions already granted. Event exemptions also waive linked contributions.</p>
    <?php if (!$exemptionCanWrite): ?><p class="management-exemption-readonly">Viewing saved exemptions. Changes require Manage exemptions permission and the current Management period.</p><?php endif; ?>
  </div></aside>
  <div class="alert alert-danger mx-4" id="exemption-page-error" role="alert" hidden></div>
  <div class="alert alert-success mx-4" id="exemption-page-notice" role="status" hidden></div>
  <h3 class="management-exemption-section-title">Reusable lists</h3><div id="exemption-lists" class="management-exemption-lists" aria-live="polite">Loading lists…</div>
  <h3 class="management-exemption-section-title">Saved exemptions</h3>
  <form id="exemption-filter-form" class="management-exemption-filters px-4"><div><label class="form-label" for="exemption-filter-search">Student name or ID</label><input class="form-control" id="exemption-filter-search" type="search" maxlength="150"></div><div><label class="form-label" for="exemption-filter-event">Event</label><select class="form-select" id="exemption-filter-event"><option value="">All events and contributions</option></select></div><button class="btn btn-outline-primary" type="submit">Filter</button></form>
  <div id="exemption-grants" class="management-exemption-grants" aria-live="polite">Loading saved exemptions…</div>
  <div class="management-pagination"><span id="exemption-page-summary"></span><div><button type="button" class="btn btn-sm btn-outline-primary" id="exemption-previous">Previous</button><button type="button" class="btn btn-sm btn-outline-primary" id="exemption-next">Next</button></div></div>
  <details class="mx-4 mb-4"><summary>Recent exemption activity</summary><ul class="management-exemption-audit" id="exemption-audit"></ul></details>
</section>
