<?php
if (!isset($scope,$directory,$term)) { http_response_code(404); exit; }
$filters = $directory['filters']; $pagination = $directory['pagination'];
$studentUrl = static function (array $changes = []) use ($pageUrl,$filters): string {
    return $pageUrl('students.php',array_merge(['search'=>$filters['search'],'page_size'=>$filters['page_size']],$changes));
};
$canManageExemptions = $permissions['manage_exemptions'] && $term['period_key'] === $currentTerm['period_key'];
?>
<h1 class="visually-hidden">Students</h1>
<section class="management-stats management-catalog-stats management-student-stats" aria-label="Student overview"><?php foreach([['students','Unique students','bx-group','primary'],['classes','Imported classes','bx-book-open','success'],['programs','Programs','bx-buildings','info']] as $stat): ?><article class="card management-stat"><span class="management-stat-icon bg-label-<?= $stat[3] ?>"><i class="bx <?= $stat[2] ?>" aria-hidden="true"></i></span><div><span><?= $stat[1] ?></span><strong><?= number_format($directory['summary'][$stat[0]]) ?></strong></div></article><?php endforeach; ?></section>
<section class="card dean-page-card management-panel management-student-listing" aria-labelledby="directory-filters-title">
  <div class="management-panel-heading"><h2 id="directory-filters-title" class="management-student-heading"><span class="card-section-title mb-0">DIRECTORY</span><span aria-hidden="true">|</span><span>Student listing</span></h2></div>
  <form class="management-filters management-student-search-form" method="get" id="management-student-filters"><input type="hidden" name="term" value="<?= synk_management_h($term['key']) ?>"><input type="hidden" name="exam_term" value="<?= synk_management_h($term['exam_term']) ?>">
    <div class="management-filter-search"><label for="student-search">Search students</label><input class="form-control" id="student-search" type="search" name="search" maxlength="150" value="<?= synk_management_h($filters['search']) ?>" placeholder="Student ID or name"></div>
    <div class="management-student-page-size"><label for="student-page-size">Students per page</label><select class="form-select" id="student-page-size" name="page_size"><?php foreach([25,50,100] as $size): ?><option value="<?= $size ?>"<?= $filters['page_size']===$size ? ' selected' : '' ?>><?= $size ?> per page</option><?php endforeach; ?></select></div>
  </form>
</section>

<section class="card dean-page-card management-panel" aria-label="Student directory">
  <?php if (!$directory['rows']): ?>
    <div class="management-empty"><i class="bx bx-search" aria-hidden="true"></i><h3>No matching students</h3><p>Try another student ID or name, or change the viewing period in the header.</p></div>
  <?php else: ?>
    <div class="management-table-scroll" role="region" aria-label="Student directory" tabindex="0">
      <table class="table dean-students-table management-table management-student-table" role="table">
        <thead role="rowgroup">
          <tr role="row">
            <th scope="col" role="columnheader" class="management-student-photo">Photo</th>
            <th scope="col" role="columnheader">Student<br> Name</th>
            <th scope="col" role="columnheader" class="management-student-course">Course /<br> Section</th>
            <th scope="col" role="columnheader" class="management-student-subjects">Total<br> subjects</th>
            <th scope="col" role="columnheader" class="management-student-balance">Remaining<br> balance</th>
            <th scope="col" role="columnheader" class="management-student-evaluation">Faculty<br> evaluation</th>
            <th scope="col" role="columnheader" class="management-student-action-cell text-end">Actions</th>
          </tr>
        </thead>
        <tbody role="rowgroup">
          <?php foreach ($directory['rows'] as $index => $student):
              $studentId = (int)$student['student_id'];
              $balance = $directory['balances'][$studentId] ?? null;
              $balanceStatus = $balance['payment_status'] ?? 'UNAVAILABLE';
              $balanceText = ['PAID'=>'Paid','NO_CHARGES'=>'No charges','UNAVAILABLE'=>'—'][$balanceStatus] ?? synk_management_account_money($balance['total_to_pay']);
              $evaluation = $directory['evaluation_progress'][$studentId] ?? [];
              $evaluationStatus = $evaluation['status'] ?? 'UNAVAILABLE';
              $evaluationText = synk_management_student_evaluation_label($evaluation);
          ?>
            <tr role="row">
              <td role="cell" class="management-student-photo">
                <img class="management-student-avatar" src="<?= synk_management_h(synk_management_student_avatar_url($studentId)) ?>" alt="" width="80" height="80" loading="<?= $index < 3 ? 'eager' : 'lazy' ?>" decoding="async" data-fallback="../student/profile-placeholder.svg">
              </td>
              <td role="cell" class="management-record-title dean-student-name">
                <div class="dean-student-id"><?= synk_management_h($student['student_number']) ?></div>
                <div class="dean-student-full-name"><?= synk_management_h($student['student_name']) ?></div>
                <div class="dean-student-contact"><em><?= synk_management_h($student['email_address'] ?: 'Email not provided') ?></em></div>
              </td>
              <td role="cell" class="management-student-course">
                <div class="management-student-field-label" aria-hidden="true">Course / Section</div>
                <strong><?= synk_management_h($student['programs'] ?: '—') ?></strong>
                <small><?= synk_management_h($student['sections'] ?: 'No section recorded') ?></small>
              </td>
              <td role="cell" class="management-student-subjects">
                <div class="management-student-field-label" aria-hidden="true">Total subjects</div>
                <span class="badge management-student-subject-count"><?= number_format($student['subject_count']) ?></span>
              </td>
              <td role="cell" class="management-student-balance">
                <div class="management-student-field-label" aria-hidden="true">Remaining balance</div>
                <span class="badge management-student-balance-value" data-student-balance data-balance-status="<?= synk_management_h($balanceStatus) ?>"><?= synk_management_h($balanceText) ?></span>
              </td>
              <td role="cell" class="management-student-evaluation">
                <div class="management-student-field-label" aria-hidden="true">Faculty evaluation</div>
                <span class="badge management-student-evaluation-value" data-evaluation-status="<?= synk_management_h($evaluationStatus) ?>"><?= synk_management_h($evaluationText) ?></span>
              </td>
              <td role="cell" class="management-student-action-cell">
                <div class="management-student-actions">
                  <button type="button" class="btn management-financial-open" data-student="<?= $studentId ?>"><i class="bx bx-receipt" aria-hidden="true"></i><span>Financial overview</span></button>
                  <?php if ($canManageExemptions): ?>
                    <button type="button" class="btn management-student-exemptions" data-exempt-open data-exempt-student="<?= $studentId ?>" data-exempt-name="<?= synk_management_h($student['student_name']) ?>" data-exempt-number="<?= synk_management_h($student['student_number']) ?>"><i class="bx bx-shield-quarter" aria-hidden="true"></i><span>Exemptions</span></button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <nav class="management-pagination" aria-label="Student result pages"><span>Page <?= $pagination['page'] ?> of <?= $pagination['pages'] ?> · <?= number_format($pagination['total']) ?> students<?php if($pagination['total']): ?> · Showing <?= $pagination['from'] ?>–<?= $pagination['to'] ?><?php endif; ?></span><div><?php foreach(['Previous'=>-1,'Next'=>1] as $label=>$step): $next=$pagination['page']+$step; ?><a class="btn btn-sm btn-outline-primary<?= $next<1 || $next>$pagination['pages'] ? ' disabled' : '' ?>"<?= $next<1 || $next>$pagination['pages'] ? ' aria-disabled="true" tabindex="-1"' : '' ?> href="<?= synk_management_h($studentUrl(['page'=>max(1,$next)])) ?>"><?= $label ?></a><?php endforeach; ?></div></nav>
  <p class="management-directory-note"><i class="bx bx-info-circle" aria-hidden="true"></i>Students appear once per selected scope, using the same directory source as the Dean's module.</p>
</section>
<div class="modal fade management-evaluation-reminder" id="management-evaluation-reminder" tabindex="-1"
  aria-labelledby="management-evaluation-reminder-title" aria-describedby="management-evaluation-reminder-status management-evaluation-reminder-note" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title fs-5" id="management-evaluation-reminder-title">Faculty evaluation incomplete</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning" id="management-evaluation-reminder-status">
          <p class="mb-1"><strong id="management-evaluation-reminder-student"></strong> still has faculty evaluations to submit for <?= synk_management_h($term['term_text']) ?>.</p>
          <strong id="management-evaluation-reminder-count"></strong>
        </div>
        <p class="mb-0" id="management-evaluation-reminder-note">Please verify completion before collecting payment.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="management-evaluation-reminder-back" data-bs-dismiss="modal">Go back</button>
        <button type="button" class="btn btn-primary" id="management-evaluation-reminder-open">Open overview</button>
      </div>
    </div>
  </div>
</div>
<div class="modal fade management-financial-modal" id="management-student-detail" tabindex="-1" aria-labelledby="management-student-detail-title" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div class="management-financial-modal-title"><span class="management-financial-kicker">STUDENT ACCOUNT</span><h2 class="modal-title fs-5" id="management-student-detail-title">Financial overview</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="management-student-detail-content" aria-live="polite"></div><div class="modal-footer"><span class="management-financial-footer-note">Amounts and records follow the period selected in the header.</span><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>
