<?php
ini_set('display_errors','0');
session_start();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$pageError='';$sheet=null;$class=null;
try {
    if (!in_array($_SERVER['REQUEST_METHOD']??'',['GET','HEAD'],true)) {
        header('Allow: GET, HEAD');throw new DomainException('Open the grade sheet from Grade Book.',405);
    }
    if (($_SESSION['role']??'')!=='professor' || empty($_SESSION['user_id'])) throw new DomainException('Sign in as a professor to print this grade sheet.',401);
    require_once __DIR__.'/../backend/db.php';
    require_once __DIR__.'/../backend/db_lms.php';
    require_once __DIR__.'/../backend/professor_class_helper_lms.php';
    require_once __DIR__.'/../backend/gradesheet_helper_lms.php';
    if (($_GET['view']??'')!=='gradebook') throw new DomainException('Open the grade sheet from Grade Book.',403);
    $context=synk_professor_class_context_lms($conn,$_GET);$class=$context['class'];
    $lms=synk_database_connection_lms($conn);
    if (!synk_gradebook_ready_lms($lms)) throw new DomainException('Gradebook storage is not ready. Contact your administrator.',503);
    // One read transaction keeps both grading periods and their summary consistent.
    if (!$lms->begin_transaction()) throw new RuntimeException('Unable to read the gradebook.');
    try {
        $workspace=synk_gradebook_workspace_lms($conn,$lms,$context);
        $metadata=synk_gradesheet_metadata_lms($conn,$context,$workspace['roster']);
        $sheet=synk_gradesheet_build_lms($workspace,$metadata);
        if (!$lms->commit()) throw new RuntimeException('Unable to complete the gradebook read.');
    } catch (Throwable $error) {$lms->rollback();throw $error;}
    $updated=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('F d, Y');
    $professor=$context['portal']['faculty_name'];
    $campus=trim((string)$class['campus_name']);
    if ($campus!=='' && !preg_match('/\bcampus\s*$/i',$campus)) $campus.=' Campus';
    $backUrl='class_lms.php?'.http_build_query(array_intersect_key($_GET,array_flip(['offering_id','ay_id','semester','view','signature'])));
} catch (Throwable $error) {
    $known=$error instanceof DomainException && in_array($error->getCode(),[400,401,403,404,405,503],true);
    http_response_code($known?$error->getCode():503);
    if (!$known) error_log('LMS grade sheet: '.$error->getMessage());
    $pageError=$known?$error->getMessage():'The grade sheet could not be loaded. Return to Grade Book and try again.';
    $sheet=null;
}
$h=static function($value):string {return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow"><meta name="referrer" content="same-origin">
  <title><?= $class?$h($class['subject_code']).' | ':'' ?>Grade sheet | synQ</title>
  <link rel="stylesheet" href="gradesheet_lms.css?v=<?= (int)filemtime(__DIR__.'/gradesheet_lms.css') ?>">
  <script src="gradesheet_lms.js?v=<?= (int)filemtime(__DIR__.'/gradesheet_lms.js') ?>" defer></script>
</head>
<body>
<?php if ($sheet===null): ?>
  <main class="gs-error"><h1>Grade sheet unavailable</h1><p><?= $h($pageError) ?></p><a href="workload.php">Return to My Workload</a></main>
<?php else: ?>
  <header class="gs-toolbar">
    <div><a class="gs-back" href="<?= $h($backUrl) ?>">&larr; Back to Grade Book</a><h1>Print grade sheet</h1><p><?= $h($class['subject_code']) ?> &middot; <?= $sheet['counts']['total'] ?> students &middot; <span id="gsSheetCount">Preparing sheets&hellip;</span></p></div>
    <div class="gs-print-actions"><p>Long bond paper &middot; 8.5 &times; 13 in &middot; Portrait<br>Choose Save as PDF or your printer.</p><button id="gsPrint" type="button" disabled>Preparing print&hellip;</button></div>
  </header>
  <aside class="gs-guidance" aria-label="Grade sheet information">
    Uses the latest saved LMS grades for the entire class. Unrecorded scores follow the Grade Book&rsquo;s zero-score rule; unconfigured grading periods stay blank.
    <?php if ($sheet['counts']['unknown']): ?> Male/Female totals are unavailable because sex is not recorded for <?= $sheet['counts']['unknown'] ?> <?= $sheet['counts']['unknown']===1?'student':'students' ?>.<?php endif; ?>
    <span id="gsPrintStatus" role="status" aria-live="polite"></span>
  </aside>
  <noscript><p class="gs-guidance">Enable JavaScript to prepare the printable grade sheets.</p></noscript>
  <main class="gs-preview" aria-label="Printable grade sheets" aria-busy="true">
    <article class="gs-paper gs-first-page gs-last-page" aria-label="Grade sheet">
      <img class="gs-stationery" src="assets/gradesheet_lms/stationery.jpg" alt="" aria-hidden="true">
      <header class="gs-letterhead">
        <img class="gs-bagong" src="assets/gradesheet_lms/bagong-pilipinas.png" alt="Bagong Pilipinas">
        <img class="gs-seal" src="assets/gradesheet_lms/university-seal.png" alt="Sultan Kudarat State University seal">
        <div class="gs-university"><p>Republic of the Philippines</p><strong>SULTAN KUDARAT STATE UNIVERSITY</strong><p><i>EJC Montilla, City of Tacurong, 9800</i></p><p><i>Province of Sultan Kudarat</i></p></div>
        <div class="gs-document-code">SKSU-INS-MCI-09<br>Revision: 00<br>Effective Date: July 07, 2025</div>
      </header>
      <div class="gs-body">
        <div class="gs-intro">
        <p class="gs-updated">Updated as of: <span><?= $h($updated) ?></span></p>
        <div class="gs-title"><h2>GRADESHEET</h2><p><?= $campus!==''?$h($campus):'__________ Campus' ?></p><p><?= $h($class['semester_label']) ?>, A.Y. <?= $h($class['academic_year_label']) ?></p></div>
        <div class="gs-course"><p>Course Code: <strong><?= $h($class['subject_code']) ?></strong><span>Course Description: <strong><?= $h($class['descriptive_title']) ?></strong></span></p><p>Name of Instructor / Professor: <strong><?= $h($professor) ?></strong></p></div>
        </div>
        <table class="gs-grades" aria-label="Student grades">
          <colgroup><col style="width:34%"><col style="width:12%"><col style="width:11%"><col style="width:11%"><col style="width:10%"><col style="width:11%"><col style="width:11%"></colgroup>
          <thead><tr><th scope="col">Name of Students<br>(Family, First, MI)</th><th scope="col">Program,<br>Yr &amp; Sec</th><th scope="col">Midterm Grade<br>Equivalent<br>%</th><th scope="col">Final Grade<br>Equivalent<br>%</th><th scope="col">Average</th><th scope="col">Numerical<br>Rating</th><th scope="col">Remarks</th></tr></thead>
          <tbody>
            <?php foreach ($sheet['rows'] as $student): ?>
            <tr data-student-id="<?= $student['student_id'] ?>"><td class="gs-student-name<?= strlen($student['name'])>45?' gs-long-name':'' ?>"><?= $h($student['name']) ?></td><td><?= $h($student['program_section']) ?></td><td><?= synk_gradesheet_number_lms($student['midterm'],true) ?></td><td><?= synk_gradesheet_number_lms($student['final'],true) ?></td><td><?= synk_gradesheet_number_lms($student['average'],true) ?></td><td><?= synk_gradesheet_number_lms($student['rating']) ?></td><td><?= $h($student['remark']) ?></td></tr>
            <?php endforeach; ?>
              <?php foreach (['SUMMARY','MALE = '.($sheet['counts']['unknown']?'—':$sheet['counts']['male']),'FEMALE = '.($sheet['counts']['unknown']?'—':$sheet['counts']['female']),'TOTAL = '.$sheet['counts']['total']] as $total): ?><tr class="gs-summary"><th scope="row"><?= $h($total) ?></th><?php for ($column=1;$column<7;$column++): ?><td></td><?php endfor; ?></tr><?php endforeach; ?>
          </tbody>
        </table>
          <div class="gs-signatures"><div><b>Attested by:</b><p class="gs-signature-line"></p><p>Program Chairman/Date</p></div><div><b>Certified Correct:</b><p class="gs-signature-line"></p><p>Signature of Professor/Instructor/Date</p></div></div>
      </div>
      <div class="gs-completion">
      <section class="gs-legend" aria-label="Rating legend"><h3>Legend:</h3><table><tbody>
        <?php foreach ([['1.0','99 – 100'],['1.25','96 – 98'],['1.50','93 – 95'],['1.75','90 – 92'],['2.00','87 – 89'],['2.25','84 – 86'],['2.50','81 – 83'],['2.75','78 – 80'],['3.00','75 – 77'],['4.00','73 – 74 – CONDITIONAL'],['5.00','72 AND BELOW – FAILED']] as $band): ?><tr><td><?= $band[0] ?></td><td><?= $h($band[1]) ?></td></tr><?php endforeach; ?>
      </tbody></table></section>
      <div class="gs-approved"><b>Approved:</b><p class="gs-signature-line"></p><p>Dean/Date</p></div>
      <section class="gs-receipt" aria-label="Acknowledgement receipt">
        <svg class="gs-receipt-border" viewBox="0 0 266 105" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect x="0.5" y="0.5" width="265" height="104" fill="white" stroke="black" stroke-width="1" stroke-dasharray="8 3 1 3" vector-effect="non-scaling-stroke"/></svg>
        <h3>ACKNOWLEDGEMENT RECEIPT:</h3><p>Date:</p><p>Time:</p><p>By: _________________________</p><p class="gs-receipt-name">Name &amp; Signature of</p><p class="gs-receipt-name">Authorized Representative</p>
      </section>
      </div>
      <footer class="gs-footer"><p><b>VISION:</b> A leading University in advancing scholarly innovation, multi-cultural convergence, and responsive public service in a borderless Region. | <b>MISSION:</b> The University shall primarily provide advanced instruction and professional training in science and technology, agriculture, fisheries, education and other relevant fields of study. It shall also</p><p>undertake research and extension services, and provide progressive leadership in its areas of specialization. | <b>MAXIM:</b> Generator of Solutions.</p><p>| <b>CORE VALUES:</b> Patriotism, Respect, Integrity, Zeal, Excellence in Public Service.</p></footer>
      <p class="gs-page-number"></p>
    </article>
  </main>
<?php endif; ?>
</body>
</html>
