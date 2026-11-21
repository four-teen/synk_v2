<?php if (!isset($sefFacultyInfo, $sefReportForm)) { http_response_code(404); exit; } ?>
<div class="sef-report-sheet sef-word-sheet sef-word-training">
    <?php require __DIR__.'/evaluation-report-stationery.php'; ?>
    <div class="sef-report-body">
        <h1 class="sef-word-title" id="sefWordReportTitle">TRAINING RECOMMENDATION FORM</h1>
        <?php require __DIR__.'/evaluation-report-faculty-info.php'; ?>
        <section class="sef-training-reference">
            <h2 class="sef-word-heading"><span>B.</span>Evaluation Reference</h2>
            <ul class="sef-training-source">
                <li>Evaluation Source:
                    <span class="sef-word-choice"><?= $sefSupervisorScore && !$sefStudentScore ? '☑' : '☐' ?> Supervisor Evaluation</span>
                    <span class="sef-word-choice"><?= $sefStudentScore && !$sefSupervisorScore ? '☑' : '☐' ?> Student Evaluation</span>
                    <span class="sef-word-choice"><?= $sefStudentScore && $sefSupervisorScore ? '☑' : '☐' ?> Both</span>
                </li>
                <li>Evaluation Period: <span class="sef-training-period"><?= sef_h(synk_sef_report_term_label($sefTerm)) ?></span></li>
                <li>Key Performance Areas Needing Improvement:</li>
            </ul>
        </section>

        <section class="sef-training-plan">
            <h2 class="sef-word-heading"><span>C.</span>Recommended Training</h2>
            <table class="sef-word-table sef-training-table" aria-label="Recommended Training">
                <colgroup><col><col><col><col></colgroup>
                <thead>
                    <tr>
                        <th scope="col">Training/Activity</th>
                        <th scope="col">Objectives/Expected<br>Outcomes</th>
                        <th scope="col">Mode of Training<br>(Seminar, Workshop,<br>Coaching, etc.)</th>
                        <th scope="col">Recommended<br>Date/Period</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($row = 0; $row < 4; $row++): ?>
                    <tr><td></td><td></td><td></td><td></td></tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </section>

        <section class="sef-training-endorsement">
            <h2 class="sef-word-heading"><span>D.</span>Endorsement</h2>
            <p class="sef-word-dated-signature"><strong>Program Chairperson:</strong> _________________________ Date: ___________</p>
            <p class="sef-word-dated-signature"><strong>Dean/Head of Office:</strong> __________________________ Date: ___________</p>
            <p class="sef-word-dated-signature"><strong>L&amp;D Chairperson:</strong> __________________________ Date: ___________</p>
        </section>

        <section class="sef-word-acknowledgement sef-training-acknowledgement">
            <h2 class="sef-word-heading"><span>E.</span>Acknowledgement</h2>
            <p>I acknowledge the training recommendation provided and commit to attend the approved activity/activities.</p>
            <p class="sef-word-dated-signature"><strong>Signature of Faculty:</strong> __________________________ Date: ___________</p>
        </section>
    </div>
</div>
