<?php
if (!isset($permits,$term,$scope)) { http_response_code(404); exit; }
$filters = $permits['filters'];
$courses = array_column($permits['sections'],'course_label','program_id');
$yearLevels = array_unique(array_column($permits['sections'],'year_level'));
sort($yearLevels,SORT_NUMERIC);
$subjectRows = 10;
$cards = [];
foreach ($permits['students'] as $student) {
    $subjectPages = array_chunk($student['subjects'],$subjectRows) ?: [[]];
    foreach ($subjectPages as $index=>$subjects) {
        $cards[] = [
            'last_name'=>$student['last_name'],
            'given_names'=>$student['given_names'],
            'subjects'=>array_pad($subjects,$subjectRows,''),
            'continued'=>$index>0,
        ];
    }
}
$sheets = array_chunk($cards,4);
$h = 'synk_management_h';
?>
<section class="card management-panel management-permit-controls" aria-label="Permit filters">
  <form action="student-permits.php" method="get" id="management-permit-form">
    <input type="hidden" name="term" value="<?= $h($term['key']) ?>">
    <div class="management-permit-filters">
      <div><label class="form-label" for="permit-course">Course</label><select class="form-select" id="permit-course" name="program_id" required>
        <option value="">Choose a course</option>
        <?php foreach ($courses as $id=>$label): ?><option value="<?= $id ?>"<?= $id===$filters['program_id']?' selected':'' ?>><?= $h($label) ?></option><?php endforeach; ?>
      </select></div>
      <div><label class="form-label" for="permit-year-level">Year level</label><select class="form-select" id="permit-year-level" name="year_level" required>
        <option value="">Choose a year</option>
        <?php foreach ($yearLevels as $level): ?><option value="<?= $level ?>"<?= $level===$filters['year_level']?' selected':'' ?>>Year <?= $level ?></option><?php endforeach; ?>
      </select></div>
      <div><label class="form-label" for="permit-section">Section</label><select class="form-select" id="permit-section" name="section_id" required>
        <option value="">Choose a section</option>
        <?php foreach ($permits['sections'] as $section): ?><option value="<?= $section['section_id'] ?>" data-program="<?= $section['program_id'] ?>" data-year="<?= $section['year_level'] ?>"<?= $section['section_id']===$filters['section_id']?' selected':'' ?>><?= $h($section['section_label']) ?></option><?php endforeach; ?>
      </select></div>
      <div><label class="form-label" for="permit-exam-term">Exam term</label><select class="form-select" id="permit-exam-term" name="exam_term" required>
        <?php foreach (synk_management_exam_term_options() as $value=>$label): ?><option value="<?= $value ?>"<?= $value===$term['exam_term']?' selected':'' ?>><?= $label ?></option><?php endforeach; ?>
      </select></div>
    </div>
    <div class="management-permit-actions">
      <button class="btn btn-primary" type="submit" name="generate" value="1" id="management-permit-generate"<?= !$courses?' disabled':'' ?>><i class="bx bx-file me-1" aria-hidden="true"></i>Generate preview</button>
      <button class="btn btn-outline-primary" type="button" id="management-permit-print"<?= !$sheets?' disabled':'' ?>><i class="bx bx-printer me-1" aria-hidden="true"></i>Print / Save as PDF</button>
      <span id="management-permit-stale" role="status" hidden>Generate a new preview to print these selections.</span>
    </div>
    <p class="management-permit-paper-note">Four permits per sheet. Use 8.5 × 13 in (Long Bond/Folio), portrait, at 100% scale. Turn off print headers and footers.</p>
    <?php if ($permits['error'] || !$courses): ?>
      <p class="alert alert-warning mb-0" role="alert"><?= $h($permits['error'] ?: 'No class sections with enrolled students were found for this semester.') ?></p>
    <?php endif; ?>
  </form>
</section>

<?php if ($sheets):
    $section = $permits['section'];
    $heading = strtoupper($term['exam_term_label']).' EXAMINATION PERMIT';
    $periodLabel = synk_semester_label($term['semester']).', A.Y. '.$term['ay_label'];
?>
<section id="management-permit-preview" aria-label="Student permit print preview">
  <header class="management-permit-preview-heading">
    <h2><?= $h($section['section_label']) ?></h2>
    <span><?= count($permits['students']) ?> <?= count($permits['students'])===1?'student':'students' ?> · <?= count($sheets) ?> <?= count($sheets)===1?'sheet':'sheets' ?></span>
  </header>
  <?php foreach ($sheets as $sheet): ?>
    <div class="management-permit-sheet">
      <?php foreach ($sheet as $permit): ?>
        <article class="management-permit-card">
          <header>
            <h3><?= $h($heading) ?></h3>
            <p><?= $h($periodLabel) ?><?= $permit['continued']?' · Subjects continued':'' ?></p>
          </header>
          <dl class="management-permit-student">
            <div><dt>Name</dt><dd class="management-permit-name">
              <span class="management-permit-last-name"><?= $h($permit['last_name']) ?></span>
              <span class="management-permit-given-names"><?= $h($permit['given_names']) ?></span>
            </dd></div>
            <div><dt>Course/Yr/Sec</dt><dd><?= $h($section['section_label']) ?></dd></div>
            <div><dt>Program</dt><dd><?= $h($section['program_code'] ?: '—') ?></dd></div>
          </dl>
          <table class="management-permit-subjects">
            <thead><tr><th scope="col">Course Code</th><th scope="col">Proctor’s Initial</th></tr></thead>
            <tbody><?php foreach ($permit['subjects'] as $code): ?><tr><td><?= $h($code) ?></td><td></td></tr><?php endforeach; ?></tbody>
          </table>
          <div class="management-permit-signature">Program Chairperson</div>
          <p class="management-permit-reminder"><strong>Proctor reminder.</strong> Collect students’ permits after their last examination and submit them to the Program Chairperson.</p>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
