<?php
$sefPage = 'report';
$sefReportKey = 'individual';
require __DIR__.'/../backend/supervisor_evaluation_page.php';
require __DIR__.'/evaluation-report-selection.php';

$studentDetail = ['categories' => [], 'comments' => []];
if ($sefReportVisible) {
    $studentDetail = synk_sef_student_detail($conn, $sefScope, $sefTerm, $sefReportFacultyId);
}

$record = $faculty['supervisor_record'] ?? null;
$supervisorCategories = [];
foreach ($record['answers'] ?? [] as $question) {
    $key = $question['category_key'];
    if (!isset($supervisorCategories[$key])) {
        $supervisorCategories[$key] = ['points' => 0, 'answers' => 0];
    }
    $supervisorCategories[$key]['points'] += (int)$question['rating'];
    $supervisorCategories[$key]['answers']++;
}

require __DIR__.'/evaluation-page-header.php';
require __DIR__.'/evaluation-report-toolbar.php';
if ($sefReportVisible):
    $studentScore = $faculty['student']['score'];
    $supervisorScore = $faculty['supervisor'];
    $ratingBreakdowns = [
        'STUDENT RATING' => [$studentScore, $studentDetail['categories']],
        'SUPERVISOR RATING' => [$supervisorScore, $supervisorCategories],
    ];
    $categoryHeadings = synk_sef_categories();
    $termLabel = $sefTerm['ay_label'] !== ''
        ? $sefTerm['semester_label'].', A.Y '.$sefTerm['ay_label']
        : $sefTerm['term_text'];
    $campusName = $record['context']['campus_name'] ?? $sefScope['campus_name'];
    $collegeName = $record['context']['college_name'] ?? $sefScope['college_name'];
    $academicRank = $record['context']['academic_rank'] ?? '';
    $signatures = [
        'Evaluated by:' => 'Chairperson, Faculty Performance Evaluation',
        'Recommending Approval:' => 'College Dean',
        'Approved:' => 'Campus Director',
    ];
?>
<article class="sef-report-preview" id="sefIndividualReport" aria-labelledby="sefReportTitle" tabindex="0">
    <div class="sef-report-sheet">
        <header class="sef-report-letterhead">
            <img class="sef-report-stationery" src="../assets/img/print/faculty-performance-letterhead.jpg" alt="">
            <img class="sef-report-bagong-pilipinas" src="../assets/img/print/faculty-performance-bagong-pilipinas.png" alt="Bagong Pilipinas">
            <img class="sef-report-university-seal" src="../assets/img/print/faculty-performance-university-seal.png" alt="">
            <div class="sef-report-university">
                <div>Republic of the Philippines</div>
                <strong>SULTAN KUDARAT STATE UNIVERSITY</strong>
                <address>EJC Montilla, City of Tacurong, 9800<br>Province of Sultan Kudarat</address>
            </div>
            <div class="sef-report-form-details">
                SKSU-INS-EFP-05<br>
                Revision: 0<br>
                Effective Date: July 07, 2025
            </div>
        </header>

        <div class="sef-report-body">
            <h1 class="sef-report-title" id="sefReportTitle">INDIVIDUAL FACULTY PERFORMANCE EVALUATION</h1>
            <p class="sef-report-term"><?= sef_h($termLabel) ?></p>
            <p class="sef-report-name">
                <span>Name of Faculty:</span>
                <span><?= sef_h($record['faculty_name'] ?? $faculty['faculty_name']) ?></span>
            </p>

            <table class="sef-report-table sef-report-summary" aria-labelledby="sefReportRatingTitle">
                <colgroup><col class="sef-report-student-mean"><col class="sef-report-student-percentage"></colgroup>
                <colgroup><col class="sef-report-supervisor-mean"><col class="sef-report-supervisor-percentage"></colgroup>
                <colgroup><col></colgroup>
                <tbody>
                    <tr>
                        <th colspan="4" id="sefReportRatingTitle">FACULTY PERFORMANCE RATING</th>
                        <th rowspan="3" class="sef-report-total">
                            Total (100%)
                            <strong data-sef-total><?= $faculty['total'] !== null ? number_format($faculty['total'], 2).'%' : 'Pending' ?></strong>
                        </th>
                    </tr>
                    <tr>
                        <th colspan="2" scope="colgroup">Students Rating (60%)</th>
                        <th colspan="2" scope="colgroup">Supervisors Rating (40%)</th>
                    </tr>
                    <tr>
                        <?php foreach ([$studentScore, $supervisorScore] as $score): ?>
                        <td>Mean<strong><?= $score ? number_format($score['mean'], 2) : 'Pending' ?></strong></td>
                        <td>Percentage<strong><?= $score ? number_format($score['component'], 2).'%' : 'Pending' ?></strong></td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>

            <?php foreach ($ratingBreakdowns as $heading => [$score, $categories]): ?>
            <table class="sef-report-table sef-category-grid" aria-label="<?= sef_h($heading) ?>">
                <thead>
                    <tr><th colspan="4" class="sef-report-category-title"><?= sef_h($heading) ?></th></tr>
                    <tr>
                        <?php foreach ($categoryHeadings as $category): ?>
                        <th scope="col"><?= sef_h($category['title']) ?></th>
                        <?php endforeach; ?>
                        <th scope="col">Overall Mean</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php foreach ($categoryHeadings as $key => $category):
                            $value = $categories[$key] ?? null;
                        ?>
                        <td>Mean<strong><?= $value && (int)$value['answers'] > 0 ? number_format((float)$value['points'] / (int)$value['answers'], 2) : 'Pending' ?></strong></td>
                        <?php endforeach; ?>
                        <td>Mean<strong><?= $score ? number_format($score['mean'], 2) : 'Pending' ?></strong></td>
                    </tr>
                </tbody>
            </table>
            <?php endforeach; ?>

            <section class="sef-report-comments">
                <h2>COMMENT/S (<em>Un edited</em>):</h2>
                <ol>
                    <?php foreach ($studentDetail['comments'] as $comment): ?>
                    <li><span class="sef-report-comment-text"><?= sef_h($comment) ?></span></li>
                    <?php endforeach; ?>
                    <?php if ($record): ?>
                    <li><strong><?= $record['evaluator_role'] === 'college_dean' ? 'Dean' : 'Program Chair' ?>:</strong> <span class="sef-report-comment-text"><?= sef_h($record['comment_text']) ?></span></li>
                    <?php endif; ?>
                </ol>
                <?php if (!$studentDetail['comments'] && !$record): ?>
                <p>No submitted comments available.</p>
                <?php endif; ?>
            </section>

            <div class="sef-report-signatures">
                <?php foreach ($signatures as $label => $role): ?>
                <div class="sef-report-signatory">
                    <strong><?= sef_h($label) ?></strong>
                    <div class="sef-report-signature-line"></div>
                    <div class="sef-report-signature-role"><?= sef_h($role) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</article>

<details class="card mt-4 sef-print-tools">
    <summary class="card-body">Evaluation details</summary>
    <div class="card-body pt-0">
        <p><?= sef_h($campusName.' · '.$collegeName) ?></p>
        <?php if ($academicRank !== ''): ?>
        <p><strong>Academic Rank:</strong> <?= sef_h($academicRank) ?></p>
        <?php endif; ?>
        <p><?= (int)$faculty['student']['submitted_count'] ?> submitted student response(s), <?= (int)$faculty['student']['included_count'] ?> counted, and <?= (int)$faculty['student']['excluded_count'] ?> excluded. <?= (int)$faculty['student']['review_count'] ?> counted response(s) require subject review.</p>
        <p>Student component = raw points ÷ maximum points × 60. Supervisor component = raw points ÷ maximum points × 40. Total uses unrounded components. The student and supervisor instruments each contain 15 statements.</p>
        <?php if ($record):
            $submittedLabel = $record['submitted_at']
                ? (new DateTimeImmutable($record['submitted_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('F j, Y g:i A').' PHT'
                : '';
        ?>
        <p><strong>Supervisor submitted by:</strong> <?= sef_h($record['evaluator_name']) ?> · <?= $record['evaluator_role'] === 'college_dean' ? 'College Dean' : 'Program Chair' ?><br>Submitted: <?= sef_h($submittedLabel) ?> · Reference: <?= sef_h($record['submission_token']) ?></p>
        <h2 class="h6">Submitted supervisor statements and ratings</h2>
        <?php foreach ($record['answers'] as $question): ?>
        <p><strong><?= (int)$question['order'] ?>. <?= sef_h($question['text']) ?></strong><br>Rating: <?= (int)$question['rating'] ?> / 5</p>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</details>
<?php endif; require __DIR__.'/evaluation-page-footer.php'; ?>
