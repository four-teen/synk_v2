<?php
// Presentation only. Subject labels are escaped by the existing curriculum reader.
if (!isset($diStructure) || !is_array($diStructure)) { http_response_code(404); exit; }
foreach ($diStructure as $yearLevel => $semesters):
    $yearLabel = year_label($yearLevel);
?>
<section class="di-structure-year" aria-labelledby="di-structure-year-<?= $yearLevel ?>">
  <div class="di-structure-year-heading"><h3 id="di-structure-year-<?= $yearLevel ?>"><?= $yearLabel ?></h3></div>
  <div class="di-structure-semesters">
    <?php foreach ($semesters as $semester): $pysId = $semester['pys_id']; ?>
    <article class="di-semester-panel di-semester-<?= $semester['semester'] ?> custom-yearsem-item" data-pys="<?= $pysId ?>" data-semester="<?= $semester['semester'] ?>">
      <header class="di-semester-header">
        <h4 class="di-semester-heading" id="heading<?= $pysId ?>">
          <button type="button" class="di-semester-toggle" data-bs-toggle="collapse" data-bs-target="#collapse<?= $pysId ?>" aria-expanded="true" aria-controls="collapse<?= $pysId ?>">
            <i class="bx bx-chevron-right di-semester-chevron" aria-hidden="true"></i>
            <span class="di-semester-label"><?= $semester['label'] ?> <span class="di-semester-count">– <?= count($semester['subjects']) ?> <?= count($semester['subjects']) === 1 ? 'Subject' : 'Subjects' ?></span></span>
          </button>
        </h4>
        <button type="button" class="btn btn-sm btn-outline-primary btnAddSubject" data-pys="<?= $pysId ?>" aria-label="Add subject to <?= $yearLabel . ' ' . $semester['label'] ?>"><i class="bx bx-plus me-1" aria-hidden="true"></i>Add Subject</button>
      </header>
      <div id="collapse<?= $pysId ?>" class="accordion-collapse collapse show" aria-labelledby="heading<?= $pysId ?>">
        <div class="di-semester-body">
          <table class="table table-sm di-semester-table" aria-label="<?= $yearLabel . ' ' . $semester['label'] ?> subjects">
            <thead><tr><th scope="col">Subject</th><th scope="col" class="di-subject-hours">Lec<br>Hrs</th><th scope="col" class="di-subject-hours">Lab<br>Hrs</th><th scope="col" class="di-subject-hours">Units</th><th scope="col" class="di-subject-actions"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($semester['subjects'] as $subject): ?>
              <tr>
                <td><span class="di-subject-code"><?= $subject['code'] ?></span><span class="di-subject-title"><?= $subject['title'] ?></span><?php if (trim($subject['prerequisites']) !== ''): ?><span class="di-subject-prerequisites">Prerequisite: <?= $subject['prerequisites'] ?></span><?php endif; ?></td>
                <td class="di-subject-hours"><?= prospectus_number_display($subject['lec_hours']) ?></td>
                <td class="di-subject-hours"><?= prospectus_number_display($subject['lab_hours']) ?></td>
                <td class="di-subject-hours"><?= prospectus_number_display($subject['units']) ?></td>
                <td class="di-subject-actions">
                  <div class="di-subject-action-group">
                    <button type="button" class="btn btn-sm btn-icon text-primary btnEditSubject" data-ps="<?= $subject['ps_id'] ?>" aria-label="Edit <?= $subject['code'] ?>" title="Edit Subject"><i class="bx bx-edit" aria-hidden="true"></i></button>
                    <button type="button" class="btn btn-sm btn-icon text-danger btnDeleteSubject" data-ps="<?= $subject['ps_id'] ?>" aria-label="Remove <?= $subject['code'] ?>" title="Remove Subject"><i class="bx bx-trash" aria-hidden="true"></i></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$semester['subjects']): ?><tr><td colspan="5" class="di-semester-empty">No subjects encoded yet for this year and semester.</td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr><th scope="row">Totals</th><td class="di-subject-hours"><?= prospectus_number_display($semester['lec_hours']) ?></td><td class="di-subject-hours"><?= prospectus_number_display($semester['lab_hours']) ?></td><td class="di-subject-hours"><?= prospectus_number_display($semester['units']) ?></td><td></td></tr></tfoot>
          </table>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
