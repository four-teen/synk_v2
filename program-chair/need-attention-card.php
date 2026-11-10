<?php
if (($_SESSION['role'] ?? '') !== 'program_chair' || !isset($conn, $collegeDeanDashboardScope, $currentTerm)) return;
require_once __DIR__ . '/../backend/program_chair_attention_helper.php';
$attentionDashboard = null;
try {
    if (empty($currentTerm['ay_id']) || empty($currentTerm['semester'])) throw new DomainException('An academic term is required.');
    $attentionDashboard = synk_chair_attention_dashboard($conn, $collegeDeanDashboardScope, $currentTerm);
} catch (Throwable $exception) {
    error_log('Program Chair dashboard Need Attention: ' . $exception->getMessage());
}
$attentionCardEscape = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$attentionYearLabels = [1=>'First Year', 2=>'Second Year', 3=>'Third Year', 4=>'Fourth Year', 5=>'Fifth Year', 6=>'Sixth Year'];
$attentionCardTerm = ['dean_ay_id'=>(int)$currentTerm['ay_id'], 'dean_semester'=>(int)$currentTerm['semester']];
?>
<section class="card shadow-sm chair-attention-card" id="chairNeedAttention" aria-labelledby="chairAttentionTitle">
  <div class="card-header d-flex flex-wrap align-items-start justify-content-between gap-3">
    <div>
      <p class="card-section-title mb-1">Student subject issues</p>
      <div class="d-flex flex-wrap align-items-center gap-2"><h5 class="m-0" id="chairAttentionTitle">Need Attention</h5><span class="badge bg-label-info"><?= $attentionCardEscape($currentTerm['term_text'] ?? '') ?></span></div>
      <p class="chair-attention-copy mt-2 mb-0">Students above their curriculum unit limit. Select a year to view student details.</p>
    </div>
    <a class="btn btn-sm btn-outline-primary" href="need-attention.php?<?= $attentionCardEscape(http_build_query($attentionCardTerm)) ?>">Open Need Attention<i class="bx bx-right-arrow-alt ms-1" aria-hidden="true"></i></a>
  </div>
  <div class="card-body pt-0">
    <?php if ($attentionDashboard === null): ?>
      <div class="alert alert-warning mb-0" role="status">Issue counts are unavailable. Open Need Attention to review the records or try again.</div>
    <?php elseif (!$attentionDashboard['programs']): ?>
      <p class="chair-attention-copy mb-0">No programs are available in your assigned college.</p>
    <?php else: ?>
      <?php if ($attentionDashboard['incomplete']): ?><p class="small text-warning mb-3">Some student records need year level, curriculum, or subject unit review. Counts include confirmed overloads.</p><?php endif; ?>
      <div class="chair-attention-programs">
        <?php foreach ($attentionDashboard['programs'] as $program): ?>
          <section class="chair-attention-program" aria-labelledby="chairAttentionProgram<?= $program['program_id'] ?>">
            <h6 class="mb-3" id="chairAttentionProgram<?= $program['program_id'] ?>"><?= $attentionCardEscape(synk_program_chair_program_display_name($program)) ?></h6>
            <div class="chair-attention-years">
              <?php foreach ($program['year_counts'] as $year=>$count):
                  $url = 'need-attention.php?' . http_build_query(['program_id'=>$program['program_id'], 'year_level'=>$year] + $attentionCardTerm);
                  $yearLabel = $attentionYearLabels[$year] ?? 'Year ' . $year;
                  $accessibleLabel = synk_program_chair_program_display_name($program) . ', ' . $yearLabel . ': '
                      . ($program['unavailable'] ? 'counts unavailable' : $count . ' students need attention'); ?>
                <a class="chair-attention-year<?= !$program['unavailable'] && $count > 0 ? ' has-issues' : '' ?>" href="<?= $attentionCardEscape($url) ?>" aria-label="<?= $attentionCardEscape($accessibleLabel) ?>" data-attention-program="<?= $program['program_id'] ?>" data-attention-year="<?= (int)$year ?>"<?= !$program['unavailable'] ? ' data-attention-count="' . (int)$count . '"' : '' ?>>
                  <span class="chair-attention-year-label"><?= $attentionCardEscape($yearLabel) ?></span>
                  <strong class="chair-attention-count"><?= $program['unavailable'] ? '&mdash;' : (int)$count ?></strong>
                  <span class="chair-attention-year-note"><?= $program['unavailable'] ? 'Counts unavailable' : 'need attention' ?><i class="bx bx-right-arrow-alt" aria-hidden="true"></i></span>
                </a>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
