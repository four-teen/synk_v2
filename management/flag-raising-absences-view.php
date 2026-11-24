<?php if (!isset($ceremonyAbsences,$term,$pageUrl)) { http_response_code(404); exit; } ?>
<section class="card dean-page-card management-panel mt-4" id="ceremony-absences" aria-labelledby="ceremony-absence-title">
  <div class="management-panel-heading">
    <div><h2 id="ceremony-absence-title">Absences by student</h2>
      <p>Totals cover closed ceremonies in <?= synk_management_h($term['term_text']) ?>. Approved exemptions are excluded.</p>
    </div>
  </div>
  <form class="management-filters" method="get" action="flag-raising.php#ceremony-absences">
    <input type="hidden" name="term" value="<?= synk_management_h($term['key']) ?>">
    <input type="hidden" name="exam_term" value="<?= synk_management_h($term['exam_term']) ?>">
    <div class="management-filter-search"><label for="ceremony-student-search">Student name or ID</label>
      <input type="search" class="form-control" id="ceremony-student-search" name="absence_search" value="<?= synk_management_h($ceremonyAbsences['search']) ?>" maxlength="150">
    </div>
    <button class="btn btn-outline-primary" type="submit">Search students</button>
  </form>
  <?php if ($ceremonyAbsences['rows']): ?>
    <div class="management-table-scroll" role="region" aria-label="Ceremony absence counts" tabindex="0">
      <table class="table dean-students-table management-table">
        <thead><tr><th scope="col">Student ID</th><th scope="col">Student</th><th scope="col">Absences</th></tr></thead>
        <tbody>
          <?php foreach ($ceremonyAbsences['rows'] as $student): ?>
            <tr><td><?= synk_management_h($student['student_number']) ?></td><td><?= synk_management_h($student['student_name']) ?></td><td><?= number_format((int)$student['absences']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <nav class="management-pagination" aria-label="Student absence pages">
      <span>Page <?= $ceremonyAbsences['page'] ?> of <?= $ceremonyAbsences['pages'] ?> · <?= number_format($ceremonyAbsences['total']) ?> students</span>
      <div>
        <?php foreach (['Previous'=>-1,'Next'=>1] as $label=>$step): $target=$ceremonyAbsences['page']+$step; ?>
          <?php if ($target>=1 && $target<=$ceremonyAbsences['pages']): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= synk_management_h($pageUrl('flag-raising.php',['absence_search'=>$ceremonyAbsences['search'],'absence_page'=>$target])) ?>#ceremony-absences"><?= $label ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </nav>
  <?php else: ?>
    <p class="px-4 pb-3 text-muted"><?= $ceremonyAbsences['search']==='' ? 'Student totals appear after attendance closes.' : 'No matching students in closed ceremony rosters.' ?></p>
  <?php endif; ?>
</section>
