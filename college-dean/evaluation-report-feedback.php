<?php if (!isset($sefFacultyInfo, $sefReportForm, $faculty)) { http_response_code(404); exit; }
$sefFeedbackSources = [
    'Supervisory Evaluation' => [$sefSupervisorScore, 40, synk_sef_categories()],
    'Student Evaluation' => [$sefStudentScore, 60, synk_student_faculty_evaluation_question_bank()],
];
?>
<div class="sef-report-sheet sef-word-sheet sef-word-feedback">
    <?php require __DIR__.'/evaluation-report-stationery.php'; ?>
    <div class="sef-report-body">
        <h1 class="sef-word-title" id="sefWordReportTitle">FACULTY EVALUATION FEEDBACK REPORT FORM</h1>
        <?php require __DIR__.'/evaluation-report-faculty-info.php'; ?>

        <section class="sef-feedback-results">
            <h2 class="sef-word-heading"><span>B.</span>Evaluation Results</h2>
            <table class="sef-word-table sef-feedback-table" aria-label="Evaluation Results">
                <colgroup><col><col><col></colgroup>
                <thead>
                    <tr>
                        <th scope="col">Evaluation<br>Source</th>
                        <th scope="col">Key Areas Assessed</th>
                        <th scope="col">Rating/Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sefFeedbackSources as $source => [$score, $weight, $categories]): ?>
                    <tr>
                        <th scope="row"><?= sef_h($source) ?></th>
                        <td>
                            <ul><?php foreach ($categories as $category): ?>
                                <li><?= sef_h($category['title']) ?></li>
                            <?php endforeach; ?></ul>
                        </td>
                        <td>
                            <?php if ($score): ?>
                            Mean: <?= number_format($score['mean'], 2) ?> / 5.00<br>
                            Weighted rating: <?= number_format($score['component'], 2) ?> / <?= (int)$weight ?>
                            <?php else: ?>
                            Pending
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="sef-feedback-overall">
                        <th scope="row">Overall<br>Performance</th>
                        <td><ul><li>Combined average from supervisor and student results</li></ul></td>
                        <td><strong><?= $faculty['total'] !== null ? number_format($faculty['total'], 2).'%' : 'Pending' ?></strong></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="sef-feedback-strengths">
            <h2 class="sef-word-heading"><span>C.</span>Strength/s Identified</h2>
            <div class="sef-word-writing-lines" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        </section>
        <section class="sef-feedback-improvements">
            <h2 class="sef-word-heading"><span>D.</span>Areas for Improvement</h2>
            <div class="sef-word-writing-lines" aria-hidden="true"><span></span><span></span><span></span></div>
        </section>
    </div>
</div>
<div class="sef-report-sheet sef-word-sheet sef-word-feedback">
    <?php require __DIR__.'/evaluation-report-stationery.php'; ?>
    <div class="sef-report-body">
        <section class="sef-feedback-recommendations">
            <h2 class="sef-word-heading"><span>E.</span>Recommendations for Faculty Development</h2>
            <ul>
                <li><span aria-hidden="true">☐</span> Attend Training/Seminar (Specify: ____________________________)</li>
                <li><span aria-hidden="true">☐</span> Peer Mentoring / Coaching</li>
                <li><span aria-hidden="true">☐</span> Re-observation of Classes</li>
                <li><span aria-hidden="true">☐</span> Research/Extension Involvement</li>
                <li><span aria-hidden="true">☐</span> Others: ________________________________________________</li>
            </ul>
        </section>
        <section class="sef-word-acknowledgement sef-feedback-acknowledgement">
            <h2 class="sef-word-heading"><span>F.</span>Acknowledgement</h2>
            <p>I acknowledge that I have received and reviewed the feedback results from the evaluation.</p>
            <p class="sef-word-dated-signature"><strong>Faculty’s Signature:</strong> ___________________________ Date: ___________</p>
            <p class="sef-word-dated-signature"><strong>Program Chairperson:</strong> _________________________ Date: ___________</p>
            <p class="sef-word-dated-signature"><strong>Dean/Head of Office:</strong> __________________________ Date: ___________</p>
        </section>
    </div>
</div>
