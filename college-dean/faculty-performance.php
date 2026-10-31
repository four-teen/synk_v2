<?php
$sefPage='results';
require __DIR__.'/../backend/supervisor_evaluation_page.php';
$sefTitle='Faculty Performance Results';
$sefResults=synk_sef_performance($conn,$sefScope,$sefTerm,$sefActor);
require __DIR__.'/evaluation-page-header.php';
?>
<section class="card mb-4"><div class="card-body"><p>One list for faculty, teaching Deans and Program Chairs. Student and supervisor ratings contribute 60% and 40% respectively. Pending components remain blank until available.</p>
<p class="fw-semibold mb-0"><?= sef_h($sefTerm['term_text']) ?> <small class="text-muted">Follows your module viewing term.</small></p>
<label for="sefFacultySearch" class="form-label mt-3">Search faculty</label><input id="sefFacultySearch" class="form-control" type="search" placeholder="Faculty name" data-sef-search>
<p class="small text-muted mt-3 mb-0">Student scores use the revised 15-question SET instrument and respect subject exclusions. Previous student instruments remain available in Student Results. Only finalized supervisor ratings enter the 40% component. Combined scores use unrounded raw totals.</p></div></section>
<div class="card"><div class="card-body"><div class="sef-table-wrap"><table class="table sef-table"><thead><tr><th>Faculty</th><th>Student Mean /5</th><th>Student /60</th><th>Supervisor Mean /5</th><th>Supervisor /40</th><th>Total /100</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($sefResults as $faculty): ?><tr data-sef-row data-name="<?= sef_h($faculty['faculty_name']) ?>"><td data-label="Faculty"><strong><?= sef_h($faculty['faculty_name']) ?></strong><?php if ($faculty['is_chair'] || $faculty['is_dean']): ?><small class="d-block text-muted"><?= $faculty['is_chair']?'Program Chair':'Dean' ?></small><?php endif; ?><small class="d-block text-muted"><?= (int)$faculty['student']['included_count'] ?> student response(s) counted<?php if ($faculty['student']['excluded_count']): ?> · <?= (int)$faculty['student']['excluded_count'] ?> excluded<?php endif; ?></small></td>
<td data-label="Student Mean /5"><?= $faculty['student']['score']?number_format($faculty['student']['score']['mean'],2):'Pending' ?></td><td data-label="Student /60"><?= $faculty['student']['score']?number_format($faculty['student']['score']['component'],2):'—' ?></td><td data-label="Supervisor Mean /5"><?= $faculty['supervisor']?number_format($faculty['supervisor']['mean'],2):'Pending' ?></td><td data-label="Supervisor /40"><?= $faculty['supervisor']?number_format($faculty['supervisor']['component'],2):'—' ?></td><td data-label="Total /100"><strong><?= $faculty['total']!==null?number_format($faculty['total'],2).'%':'Pending' ?></strong></td><td data-label="Status"><?= sef_h($faculty['result_status']) ?></td><td data-label="Action"><a class="btn btn-sm btn-outline-primary" href="<?= sef_h(sef_url('faculty-performance-report.php',$sefQuery+['faculty_id'=>$faculty['faculty_id']])) ?>">View Report</a></td></tr><?php endforeach; ?>
</tbody></table></div><p class="text-muted" data-sef-empty<?= $sefResults?' hidden':'' ?>>No faculty match this college, term or search.</p></div></div>
<?php require __DIR__.'/evaluation-page-footer.php'; ?>
