<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/student_portal_helper.php';

synk_student_require_login($conn, true);

function synk_student_eval_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $out .= function_exists('mb_substr')
            ? mb_strtoupper((string)mb_substr($part, 0, 1))
            : strtoupper(substr($part, 0, 1));

        if (strlen($out) >= 2) {
            break;
        }
    }

    return $out !== '' ? $out : 'NA';
}

function synk_student_eval_find_card(array $cards, string $facultyKey): ?array
{
    foreach ($cards as $card) {
        if ((string)($card['faculty_key'] ?? '') === $facultyKey) {
            return $card;
        }
    }

    return null;
}

function synk_student_eval_subject_summary(array $card): string
{
    $parts = [];
    foreach ((array)($card['subjects'] ?? []) as $subject) {
        $parts[] = trim((string)($subject['subject_code'] ?? '') . ' - ' . (string)($subject['descriptive_title'] ?? ''));
    }

    return implode('; ', array_filter($parts));
}

function synk_student_eval_term_qr_filename(array $submission): string
{
    $label = strtolower(trim(
        (string)($submission['term_label'] ?? 'term')
        . '-'
        . (string)($submission['final_submission_token'] ?? 'faculty-evaluation')
    ));
    $label = preg_replace('/[^a-z0-9]+/', '-', $label);
    $label = trim((string)($label ?? ''), '-');

    return ($label !== '' ? $label : 'faculty-evaluation-final') . '.png';
}

$ctx = synk_student_resolve_portal_context($conn);
$studentPortalDisplayName = (string)($ctx['student_name'] ?? 'Student');
$studentPortalDisplayEmail = trim((string)($ctx['student_email'] ?? (string)($_SESSION['email'] ?? '')));
$studentPortalPreviewMode = !empty($ctx['is_admin_preview']);
$studentPortalBackUrl = $studentPortalPreviewMode
    ? synk_student_preview_return_to_url('../administrator/students/directory.php')
    : '';
$studentRecord = is_array($ctx['directory_record'] ?? null) ? $ctx['directory_record'] : null;
$studentId = (int)($studentRecord['student_id'] ?? 0);

$questionBank = synk_student_faculty_evaluation_question_bank();
$ratingScale = synk_student_faculty_evaluation_scale();
$submittedRatings = is_array($_POST['ratings'] ?? null) ? $_POST['ratings'] : [];
$submittedComment = trim((string)($_POST['comment_text'] ?? ''));
$errorMessage = '';
$openModalFacultyKey = '';
$openReviewModal = false;

$currentTerm = synk_fetch_current_academic_term($conn);
$termOptions = synk_student_fetch_faculty_term_options($conn, $studentId);
$requestedTermKey = trim((string)($_POST['term_key'] ?? ($_GET['term_key'] ?? '')));
$requestedFacultyKey = trim((string)($_POST['faculty_key'] ?? ($_GET['faculty_key'] ?? '')));
$currentTermKey = ((int)($currentTerm['ay_id'] ?? 0) > 0 && (int)($currentTerm['semester'] ?? 0) > 0)
    ? ((int)$currentTerm['ay_id'] . '-' . (int)$currentTerm['semester'])
    : '';

$selectedTerm = null;
foreach ($termOptions as $termOption) {
    if ((string)($termOption['term_key'] ?? '') === $requestedTermKey) {
        $selectedTerm = $termOption;
        break;
    }
}

if (!$selectedTerm && $currentTermKey !== '') {
    foreach ($termOptions as $termOption) {
        if ((string)($termOption['term_key'] ?? '') === $currentTermKey) {
            $selectedTerm = $termOption;
            break;
        }
    }
}

if (!$selectedTerm && !empty($termOptions)) {
    $selectedTerm = $termOptions[0];
}

$selectedTermKey = (string)($selectedTerm['term_key'] ?? '');
$selectedAyId = (int)($selectedTerm['ay_id'] ?? 0);
$selectedSemester = (int)($selectedTerm['semester'] ?? 0);
$selectedTermLabel = (string)($selectedTerm['term_label'] ?? '');

$cards = ($studentId > 0 && $selectedAyId > 0 && $selectedSemester > 0)
    ? synk_student_fetch_faculty_cards_for_term($conn, $studentId, $selectedAyId, $selectedSemester)
    : [];

if ($requestedFacultyKey === '' && count($cards) === 1) {
    $requestedFacultyKey = (string)($cards[0]['faculty_key'] ?? '');
}

$evaluations = ($studentId > 0 && $selectedAyId > 0 && $selectedSemester > 0)
    ? synk_student_fetch_faculty_evaluations_for_term($conn, $studentId, $selectedAyId, $selectedSemester)
    : [];

foreach ($cards as $index => $card) {
    $facultyId = (int)($card['faculty_id'] ?? 0);
    $evaluation = $facultyId > 0 ? ($evaluations[$facultyId] ?? null) : null;
    $submissionStatus = strtolower(trim((string)($evaluation['submission_status'] ?? '')));

    $cards[$index]['evaluation'] = $evaluation;
    $cards[$index]['has_saved_evaluation'] = is_array($evaluation);
    $cards[$index]['is_draft_saved'] = is_array($evaluation) && $submissionStatus === 'draft';
    $cards[$index]['is_final_submitted'] = is_array($evaluation) && $submissionStatus === 'submitted';
}

$evaluableFacultyIds = [];
$totalSubjectCount = 0;
foreach ($cards as $card) {
    $facultyId = (int)($card['faculty_id'] ?? 0);
    if ($facultyId <= 0) {
        continue;
    }

    $evaluableFacultyIds[$facultyId] = $facultyId;
    $totalSubjectCount += (int)($card['subject_count'] ?? 0);
}
$evaluableFacultyIds = array_values($evaluableFacultyIds);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_faculty_evaluation_draft'])) {
    $openModalFacultyKey = $requestedFacultyKey;

    try {
        if ($studentPortalPreviewMode) {
            throw new RuntimeException('Preview mode can open the form, but only the real student account can save the faculty evaluation draft.');
        }

        $selectedCardForSave = synk_student_eval_find_card($cards, $requestedFacultyKey);
        if (!$selectedCardForSave) {
            throw new RuntimeException('Please select a faculty member before saving the evaluation draft.');
        }

        if ((int)($selectedCardForSave['faculty_id'] ?? 0) <= 0) {
            throw new RuntimeException('This faculty entry is not properly linked yet, so evaluation cannot be saved.');
        }

        synk_student_save_faculty_evaluation($conn, [
            'student_id' => $studentId,
            'faculty_id' => (int)($selectedCardForSave['faculty_id'] ?? 0),
            'ay_id' => $selectedAyId,
            'semester' => $selectedSemester,
            'faculty_name' => (string)($selectedCardForSave['faculty_name'] ?? ''),
            'student_number' => (string)($studentRecord['student_number'] ?? ''),
            'term_label' => $selectedTermLabel,
            'subject_summary' => synk_student_eval_subject_summary($selectedCardForSave),
            'comment_text' => $submittedComment,
        ], $submittedRatings);

        header('Location: ' . synk_student_build_portal_url('class-program.php', [
            'term_key' => $selectedTermKey,
            'draft_status' => 'saved',
        ]));
        exit;
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_final_faculty_evaluation'])) {
    $openReviewModal = true;

    try {
        if ($studentPortalPreviewMode) {
            throw new RuntimeException('Preview mode can review the final summary, but only the real student account can submit the final faculty evaluation.');
        }

        if (empty($evaluableFacultyIds)) {
            throw new RuntimeException('No linked faculty evaluations are available for final submission yet.');
        }

        $savedFacultyCount = 0;
        foreach ($cards as $card) {
            if ((int)($card['faculty_id'] ?? 0) > 0 && !empty($card['has_saved_evaluation'])) {
                $savedFacultyCount++;
            }
        }

        if ($savedFacultyCount !== count($evaluableFacultyIds)) {
            throw new RuntimeException('Please save draft evaluations for all linked faculty before the final submission.');
        }

        synk_student_finalize_faculty_evaluations_for_term(
            $conn,
            $studentId,
            $selectedAyId,
            $selectedSemester,
            $evaluableFacultyIds,
            [
                'term_label' => $selectedTermLabel,
                'student_number' => (string)($studentRecord['student_number'] ?? ''),
                'subject_count' => $totalSubjectCount,
            ]
        );

        header('Location: ' . synk_student_build_portal_url('class-program.php', [
            'term_key' => $selectedTermKey,
            'final_status' => 'success',
        ]));
        exit;
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$selectedCard = $requestedFacultyKey !== '' ? synk_student_eval_find_card($cards, $requestedFacultyKey) : null;
$selectedFacultyKey = $selectedCard ? (string)($selectedCard['faculty_key'] ?? '') : '';
$displayCards = $selectedCard ? [$selectedCard] : $cards;

$displaySubjectCount = 0;
foreach ($displayCards as $card) {
    $displaySubjectCount += (int)($card['subject_count'] ?? 0);
}

$evaluableCount = count($evaluableFacultyIds);
$savedCount = 0;
$draftCount = 0;
$finalCount = 0;
$finalTokens = [];
$finalSubmittedAtCandidates = [];

foreach ($cards as $card) {
    if ((int)($card['faculty_id'] ?? 0) <= 0) {
        continue;
    }

    if (!empty($card['has_saved_evaluation'])) {
        $savedCount++;
    }

    if (!empty($card['is_draft_saved'])) {
        $draftCount++;
    }

    if (!empty($card['is_final_submitted'])) {
        $finalCount++;
        $token = trim((string)($card['evaluation']['final_submission_token'] ?? ''));
        $submittedAt = trim((string)($card['evaluation']['final_submitted_at'] ?? ''));
        if ($token !== '') {
            $finalTokens[$token] = true;
        }
        if ($submittedAt !== '') {
            $finalSubmittedAtCandidates[] = $submittedAt;
        }
    }
}

$missingCount = max(0, $evaluableCount - $savedCount);
$termLocked = $finalCount > 0;
$allFacultyFinalized = $evaluableCount > 0 && $finalCount === $evaluableCount;
$canOpenFinalReview = !$termLocked && $evaluableCount > 0 && $savedCount === $evaluableCount;

$termFinalSubmission = null;
if ($allFacultyFinalized && count($finalTokens) === 1) {
    $finalTokenList = array_keys($finalTokens);
    $termFinalSubmission = [
        'term_label' => $selectedTermLabel,
        'student_number' => (string)($studentRecord['student_number'] ?? ''),
        'faculty_count' => $evaluableCount,
        'subject_count' => $totalSubjectCount,
        'faculty_names' => array_values(array_filter(array_map(static function (array $card): string {
            return trim((string)($card['faculty_name'] ?? ''));
        }, $cards))),
        'final_submission_token' => (string)($finalTokenList[0] ?? ''),
        'final_submitted_at' => !empty($finalSubmittedAtCandidates) ? max($finalSubmittedAtCandidates) : '',
    ];

    $termQrPayload = synk_student_build_term_evaluation_qr_payload($termFinalSubmission, (array)$studentRecord, $cards);
    $termFinalSubmission['qr_preview_url'] = synk_student_build_evaluation_qr_url($termQrPayload, 240);
    $termFinalSubmission['qr_download_url'] = synk_student_build_evaluation_qr_url($termQrPayload, 520);
    $termFinalSubmission['qr_download_filename'] = synk_student_eval_term_qr_filename($termFinalSubmission);
}

$studentProgramLabel = trim((string)($studentRecord['source_program_name'] ?? ''));
$draftStatus = trim((string)($_GET['draft_status'] ?? ''));
$finalStatus = trim((string)($_GET['final_status'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
  <title>Faculty Performance Evaluation | Synk Student</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <link rel="stylesheet" type="text/css" href="custom_css.css" />
  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
  <style>
    .faculty-page-heading {
      display: inline-flex;
      align-items: center;
      gap: 0.6rem;
      padding: 0.7rem 1rem;
      border: 1px solid #dfe3ff;
      border-radius: 999px;
      background: linear-gradient(135deg, #f1f3ff 0%, #f7f8ff 100%);
      color: #696cff;
      font-size: 1.05rem;
      font-weight: 800;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      box-shadow: 0 10px 24px rgba(105, 108, 255, 0.08);
    }

    .faculty-page-heading i {
      font-size: 1.2rem;
    }

    .faculty-page-notice {
      border: 1px solid #dfe3ff;
      border-radius: 18px;
      background: linear-gradient(135deg, #eef0ff 0%, #f7f8ff 100%);
      color: #5c62d6;
      line-height: 1.7;
      box-shadow: 0 12px 28px rgba(105, 108, 255, 0.08);
    }

    .faculty-page-notice strong {
      color: #4c53c7;
    }

    .faculty-action-note {
      color: #72839a;
      font-size: 0.88rem;
      line-height: 1.6;
    }

    .faculty-draft-summary,
    .faculty-final-summary {
      border: 1px solid #e1e7f3;
      border-radius: 18px;
      background: #fbfcff;
    }

    .faculty-final-summary {
      border-color: #cbe7d0;
      background: linear-gradient(135deg, #f7fff8 0%, #fcfffd 100%);
    }

    .faculty-final-qr-card {
      border: 1px solid #cbe7d0;
      border-radius: 22px;
      background: linear-gradient(135deg, #f7fff8 0%, #ffffff 100%);
      box-shadow: 0 18px 38px rgba(67, 89, 113, 0.08);
    }

    .faculty-review-list {
      display: grid;
      gap: 1rem;
    }

    .faculty-review-item {
      border: 1px solid #e2e8f3;
      border-radius: 16px;
      background: #fbfcff;
      padding: 1rem;
    }

    .faculty-review-item-head {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-start;
      justify-content: space-between;
      gap: 0.75rem;
    }

    .faculty-review-subjects {
      display: grid;
      gap: 0.55rem;
      margin-top: 0.9rem;
    }

    .faculty-review-subject {
      border: 1px solid #e5ebf4;
      border-radius: 14px;
      background: #fff;
      padding: 0.8rem 0.9rem;
    }

    .faculty-eval-modal .modal-dialog,
    .faculty-final-review-modal .modal-dialog {
      max-width: 1140px;
    }

    .faculty-eval-modal .modal-content,
    .faculty-final-review-modal .modal-content {
      max-height: calc(100vh - 2rem);
      overflow: hidden;
    }

    .faculty-eval-modal .modal-header,
    .faculty-eval-modal .modal-footer,
    .faculty-final-review-modal .modal-header,
    .faculty-final-review-modal .modal-footer {
      background: #fff;
      z-index: 2;
    }

    .faculty-eval-modal .modal-body,
    .faculty-final-review-modal .modal-body {
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
      max-height: calc(100vh - 190px);
    }

    @media (max-width: 575.98px) {
      .faculty-eval-modal .modal-content,
      .faculty-final-review-modal .modal-content {
        max-height: calc(100vh - 1rem);
      }

      .faculty-eval-modal .modal-body,
      .faculty-final-review-modal .modal-body {
        max-height: calc(100vh - 160px);
      }
    }
  </style>
</head>
<body>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php include 'sidebar.php'; ?>
<div class="layout-page"><?php include 'navbar.php'; ?>
<div class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">
  <div class="faculty-page-heading mb-3">
    <i class="bx bx-user-voice"></i>
    <span>Faculty Performance Evaluation</span>
  </div>

  <?php if ($studentPortalPreviewMode): ?>
    <div class="alert alert-warning">
      You are reviewing this page in student preview mode. Drafts and final submission are locked, but the evaluation forms and review flow can still be checked here.
    </div>
  <?php endif; ?>

  <?php if ($draftStatus === 'saved'): ?>
    <div class="alert alert-success">
      Faculty evaluation draft saved. Review the remaining faculty first, then use the final submission button once every linked faculty has a saved draft.
    </div>
  <?php endif; ?>

  <?php if ($finalStatus === 'success'): ?>
    <div class="alert alert-success">
      Final faculty evaluation submitted successfully. The QR proof is now available for this academic term.
    </div>
  <?php endif; ?>

  <?php if ($errorMessage !== ''): ?>
    <div class="alert alert-danger"><?php echo synk_student_h($errorMessage); ?></div>
  <?php endif; ?>

  <div class="faculty-page-notice mb-4 p-4">
    <strong>Dear Students:</strong><br>
    Please rate your subject teachers who handled you in the subjects that you are officially enrolled this semester.
    Your honest ratings and comments can help improve the school in achieving quality education.
    Please be assured that your comments are treated with utmost confidentiality.
  </div>

  <?php if ($termFinalSubmission): ?>
    <div class="card faculty-final-qr-card mb-4">
      <div class="card-body p-4">
        <div class="row g-4 align-items-center">
          <div class="col-lg-3 text-center">
            <?php if (trim((string)($termFinalSubmission['qr_preview_url'] ?? '')) !== ''): ?>
              <img
                src="<?php echo synk_student_h((string)($termFinalSubmission['qr_preview_url'] ?? '')); ?>"
                alt="Final Faculty Evaluation QR"
                class="img-fluid rounded-3 border p-2 bg-white"
              />
            <?php endif; ?>
          </div>
          <div class="col-lg-9">
            <div class="d-flex flex-wrap gap-2 mb-3">
              <span class="badge bg-label-primary"><?php echo synk_student_h((string)($termFinalSubmission['final_submission_token'] ?? '')); ?></span>
              <span class="badge bg-label-success">Final faculty evaluation submitted</span>
              <?php if ($selectedTermLabel !== ''): ?><span class="badge bg-label-info"><?php echo synk_student_h($selectedTermLabel); ?></span><?php endif; ?>
            </div>

            <p class="mb-2">
              All linked faculty for this academic term were finalized on
              <strong><?php echo synk_student_h((string)($termFinalSubmission['final_submitted_at'] ?? '')); ?></strong>.
            </p>
            <p class="text-muted mb-3">
              Show or download this QR later as proof that the complete faculty performance evaluation was submitted for the selected term.
            </p>

            <div class="d-flex flex-wrap gap-2">
              <?php if (trim((string)($termFinalSubmission['qr_download_url'] ?? '')) !== ''): ?>
                <a
                  href="<?php echo synk_student_h((string)($termFinalSubmission['qr_download_url'] ?? '')); ?>"
                  class="btn btn-outline-success"
                  target="_blank"
                  rel="noopener"
                  download="<?php echo synk_student_h((string)($termFinalSubmission['qr_download_filename'] ?? 'faculty-evaluation-final.png')); ?>"
                >
                  Download QR
                </a>
              <?php endif; ?>
              <span class="text-muted small align-self-center">
                On mobile, you can also long-press the QR image if the browser does not auto-download it.
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php elseif ($termLocked && $allFacultyFinalized): ?>
    <div class="alert alert-info mb-4">
      The faculty evaluations for this academic term are already locked. A combined final QR is not available for this older submission batch.
    </div>
  <?php endif; ?>

  <div class="card mb-4"><div class="card-body">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
      <div>
        <h5 class="mb-1">Select faculty for evaluation</h5>
        <p class="text-muted mb-0">
          Save each faculty evaluation as a draft first, review the whole term, then send the final submission only once.
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($selectedTermLabel !== ''): ?><span class="badge bg-label-primary"><?php echo synk_student_h($selectedTermLabel); ?></span><?php endif; ?>
        <span class="badge bg-label-info"><?php echo (int)$evaluableCount; ?> faculty</span>
        <span class="badge bg-label-success"><?php echo (int)$totalSubjectCount; ?> subjects</span>
        <?php if ($studentProgramLabel !== ''): ?><span class="badge bg-label-secondary"><?php echo synk_student_h($studentProgramLabel); ?></span><?php endif; ?>
        <?php if ($evaluableCount > 0): ?><span class="badge bg-label-warning"><?php echo (int)$savedCount; ?> / <?php echo (int)$evaluableCount; ?> drafts ready</span><?php endif; ?>
      </div>
    </div>

    <form method="get" action="<?php echo synk_student_h(synk_student_build_portal_url('class-program.php')); ?>" class="row g-3">
      <div class="col-md-6">
        <label for="term_key" class="form-label fw-semibold">Academic Term</label>
        <select id="term_key" name="term_key" class="form-select">
          <option value="">Select term</option>
          <?php foreach ($termOptions as $termOption): ?>
            <option
              value="<?php echo synk_student_h((string)($termOption['term_key'] ?? '')); ?>"
              <?php echo (string)($termOption['term_key'] ?? '') === $selectedTermKey ? ' selected' : ''; ?>
            >
              <?php echo synk_student_h((string)($termOption['term_label'] ?? 'Term') . ' (' . (int)($termOption['subject_count'] ?? 0) . ' subjects)'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label for="faculty_key" class="form-label fw-semibold">Faculty</label>
        <select id="faculty_key" name="faculty_key" class="form-select">
          <option value="">All faculty members</option>
          <?php foreach ($cards as $card): ?>
            <option
              value="<?php echo synk_student_h((string)($card['faculty_key'] ?? '')); ?>"
              <?php echo (string)($card['faculty_key'] ?? '') === $selectedFacultyKey ? ' selected' : ''; ?>
            >
              <?php echo synk_student_h((string)($card['faculty_name'] ?? 'Faculty') . ' (' . (int)($card['subject_count'] ?? 0) . ' subjects)'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary">Load Faculty List</button>
        <a href="<?php echo synk_student_h(synk_student_build_portal_url('class-program.php')); ?>" class="btn btn-outline-secondary">Reset</a>
        <?php if ($evaluableCount > 0): ?>
          <button
            type="button"
            class="btn btn-success"
            data-bs-toggle="modal"
            data-bs-target="#finalEvaluationReviewModal"
            <?php echo (!$canOpenFinalReview && !$allFacultyFinalized) ? 'disabled' : ''; ?>
          >
            Review Final Evaluation
          </button>
        <?php endif; ?>
      </div>
      <div class="col-12">
        <?php if ($allFacultyFinalized): ?>
          <div class="faculty-action-note">
            Final submission is already locked for this term. The faculty cards below are now read-only.
          </div>
        <?php elseif ($missingCount > 0): ?>
          <div class="faculty-action-note">
            Save draft evaluations for <?php echo (int)$missingCount; ?> more faculty member<?php echo $missingCount === 1 ? '' : 's'; ?> to unlock the final submission review.
          </div>
        <?php else: ?>
          <div class="faculty-action-note">
            All linked faculty now have saved drafts. Review the summary first, then send the final submission once.
          </div>
        <?php endif; ?>
      </div>
    </form>
  </div></div>

  <?php if ($studentId <= 0): ?>
    <div class="card"><div class="card-body text-center text-muted py-5"><i class="bx bx-user-x fs-1 d-block mb-2 text-primary"></i>Student record was not found in the encoded student management list yet.</div></div>
  <?php elseif (empty($termOptions)): ?>
    <div class="card"><div class="card-body text-center text-muted py-5"><i class="bx bx-spreadsheet fs-1 d-block mb-2 text-primary"></i>No enrolled-subject records with faculty data are available for this student yet.</div></div>
  <?php elseif (empty($displayCards)): ?>
    <div class="card"><div class="card-body text-center text-muted py-5"><i class="bx bx-filter-alt fs-1 d-block mb-2 text-primary"></i>No faculty matched the selected filter for <?php echo synk_student_h($selectedTermLabel !== '' ? $selectedTermLabel : 'the chosen term'); ?>.</div></div>
  <?php else: ?>
    <div class="row g-4">
      <?php foreach ($displayCards as $card): ?>
        <?php
        $isSelected = (string)($card['faculty_key'] ?? '') === $selectedFacultyKey;
        $cardEval = is_array($card['evaluation'] ?? null) ? $card['evaluation'] : null;
        $cardModalId = 'facultyEvaluationModal_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($card['faculty_key'] ?? 'card'));
        ?>
        <div class="col-12">
          <div class="card"><div class="card-body">
            <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 bg-label-primary text-primary fw-bold d-inline-flex align-items-center justify-content-center" style="width:56px;height:56px;">
                  <?php echo synk_student_h(synk_student_eval_initials((string)($card['faculty_name'] ?? 'Faculty'))); ?>
                </div>
                <div>
                  <h5 class="mb-1"><?php echo synk_student_h((string)($card['faculty_name'] ?? 'Faculty')); ?></h5>
                  <p class="text-muted mb-0">
                    <?php echo (int)($card['subject_count'] ?? 0); ?> subject<?php echo (int)($card['subject_count'] ?? 0) === 1 ? '' : 's'; ?>
                    in <?php echo synk_student_h($selectedTermLabel !== '' ? $selectedTermLabel : 'the selected term'); ?>.
                  </p>
                </div>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <?php if ((int)($card['faculty_id'] ?? 0) <= 0): ?>
                  <span class="badge bg-label-warning">Faculty Link Required</span>
                <?php elseif ($allFacultyFinalized || !empty($card['is_final_submitted'])): ?>
                  <button type="button" class="btn btn-success btn-sm" disabled>Final Submission Locked</button>
                <?php elseif (!empty($card['has_saved_evaluation'])): ?>
                  <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#<?php echo synk_student_h($cardModalId); ?>">Review / Edit Draft</button>
                  <?php if (!$isSelected): ?>
                    <a href="<?php echo synk_student_h(synk_student_build_portal_url('class-program.php', ['term_key' => $selectedTermKey, 'faculty_key' => (string)($card['faculty_key'] ?? '')])); ?>" class="btn btn-outline-primary btn-sm">Select Faculty</a>
                  <?php else: ?>
                    <span class="badge bg-label-primary align-self-center">Selected Faculty</span>
                  <?php endif; ?>
                <?php else: ?>
                  <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#<?php echo synk_student_h($cardModalId); ?>">Evaluate Faculty</button>
                  <?php if (!$isSelected): ?>
                    <a href="<?php echo synk_student_h(synk_student_build_portal_url('class-program.php', ['term_key' => $selectedTermKey, 'faculty_key' => (string)($card['faculty_key'] ?? '')])); ?>" class="btn btn-outline-primary btn-sm">Select Faculty</a>
                  <?php else: ?>
                    <span class="badge bg-label-primary align-self-center">Selected Faculty</span>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="row g-3 mt-1">
              <?php foreach ((array)($card['subjects'] ?? []) as $subject): ?>
                <div class="col-12">
                  <div class="border rounded-3 p-3">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                      <div>
                        <div class="fw-semibold"><?php echo synk_student_h((string)($subject['subject_code'] ?? 'NO CODE')); ?></div>
                        <div class="text-muted"><?php echo synk_student_h((string)($subject['descriptive_title'] ?? 'Untitled subject')); ?></div>
                      </div>
                      <div class="d-flex flex-wrap gap-2">
                        <?php if (trim((string)($subject['section_display'] ?? '')) !== ''): ?>
                          <span class="badge bg-label-primary"><?php echo synk_student_h((string)($subject['section_display'] ?? '')); ?></span>
                        <?php endif; ?>
                        <?php if (trim((string)($subject['room_name'] ?? '')) !== ''): ?>
                          <span class="badge bg-label-success"><?php echo synk_student_h((string)($subject['room_name'] ?? '')); ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <?php if (trim((string)($subject['schedule_text'] ?? '')) !== ''): ?>
                      <div class="text-muted mt-2"><i class="bx bx-time-five me-1"></i><?php echo synk_student_h((string)($subject['schedule_text'] ?? '')); ?></div>
                    <?php endif; ?>
                    <?php if ((int)($card['faculty_id'] ?? 0) <= 0): ?>
                      <div class="text-muted mt-2"><i class="bx bx-info-circle me-1"></i>This subject is waiting for proper faculty linking before evaluation is connected.</div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($cardEval): ?>
              <div class="card <?php echo !empty($card['is_final_submitted']) ? 'faculty-final-summary' : 'faculty-draft-summary'; ?> mt-4">
                <div class="card-body">
                  <div class="d-flex flex-wrap gap-2 mb-2">
                    <?php if (!empty($card['is_final_submitted'])): ?>
                      <span class="badge bg-label-success">Included in final submission</span>
                      <?php if (trim((string)($cardEval['final_submitted_at'] ?? '')) !== ''): ?>
                        <span class="badge bg-label-primary"><?php echo synk_student_h((string)($cardEval['final_submitted_at'] ?? '')); ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="badge bg-label-warning">Draft saved</span>
                      <?php if (trim((string)($cardEval['updated_at'] ?? '')) !== ''): ?>
                        <span class="badge bg-label-secondary"><?php echo synk_student_h((string)($cardEval['updated_at'] ?? '')); ?></span>
                      <?php endif; ?>
                    <?php endif; ?>
                    <span class="badge bg-label-info">Average <?php echo number_format((float)($cardEval['average_rating'] ?? 0), 2); ?></span>
                  </div>

                  <p class="mb-2 text-muted">
                    <?php if (!empty($card['is_final_submitted'])): ?>
                      This faculty evaluation is now locked because the final submission for the term has already been sent.
                    <?php else: ?>
                      This draft can still be reviewed and edited until the final term submission is sent.
                    <?php endif; ?>
                  </p>

                  <?php if (trim((string)($cardEval['subject_summary'] ?? '')) !== ''): ?>
                    <p class="text-muted mb-2"><i class="bx bx-book-content me-1"></i><?php echo synk_student_h((string)($cardEval['subject_summary'] ?? '')); ?></p>
                  <?php endif; ?>

                  <?php if (trim((string)($cardEval['comment_text'] ?? '')) !== ''): ?>
                    <p class="text-muted mb-0"><i class="bx bx-message-square-detail me-1"></i><?php echo synk_student_h((string)($cardEval['comment_text'] ?? '')); ?></p>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </div></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php include '../footer.php'; ?>
</div></div></div></div>

<?php foreach ($cards as $card): ?>
  <?php
  $facultyId = (int)($card['faculty_id'] ?? 0);
  $cardEval = is_array($card['evaluation'] ?? null) ? $card['evaluation'] : null;
  if ($facultyId <= 0 || !empty($card['is_final_submitted'])) {
      continue;
  }
  $cardModalId = 'facultyEvaluationModal_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($card['faculty_key'] ?? 'card'));
  $usePostedValues = $_SERVER['REQUEST_METHOD'] === 'POST'
      && isset($_POST['save_faculty_evaluation_draft'])
      && (string)($card['faculty_key'] ?? '') === $openModalFacultyKey;
  $questionNumber = 0;
  ?>
  <div class="modal fade faculty-eval-modal" id="<?php echo synk_student_h($cardModalId); ?>" tabindex="-1" aria-labelledby="<?php echo synk_student_h($cardModalId); ?>Label" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <form method="post" action="<?php echo synk_student_h(synk_student_build_portal_url('class-program.php')); ?>">
          <div class="modal-header">
            <div>
              <h5 class="modal-title" id="<?php echo synk_student_h($cardModalId); ?>Label">Faculty Evaluation Form</h5>
              <small class="text-muted">
                <?php echo synk_student_h((string)($card['faculty_name'] ?? 'Faculty')); ?>
                <?php if ($selectedTermLabel !== ''): ?> - <?php echo synk_student_h($selectedTermLabel); ?><?php endif; ?>
              </small>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="save_faculty_evaluation_draft" value="1">
            <input type="hidden" name="term_key" value="<?php echo synk_student_h($selectedTermKey); ?>">
            <input type="hidden" name="faculty_key" value="<?php echo synk_student_h((string)($card['faculty_key'] ?? '')); ?>">

            <div class="alert alert-primary">
              <strong>Dear Students:</strong><br>
              Please rate your subject teachers who handled you in the subjects that you are officially enrolled this semester. Your honest ratings and comments can help improve the school in achieving quality education. Please be assured that your comments are treated with utmost confidentiality.
            </div>

            <div class="row g-3 mb-4">
              <?php foreach ($ratingScale as $ratingValue => $ratingInfo): ?>
                <div class="col-md-4 col-lg">
                  <div class="border rounded-3 p-3 h-100 bg-label-light">
                    <div class="fw-bold text-primary"><?php echo (int)$ratingValue; ?></div>
                    <div class="fw-semibold"><?php echo synk_student_h((string)($ratingInfo['label'] ?? '')); ?></div>
                    <small class="text-muted"><?php echo synk_student_h((string)($ratingInfo['description'] ?? '')); ?></small>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="d-flex flex-wrap gap-2 mb-4">
              <?php foreach ((array)($card['subjects'] ?? []) as $subject): ?>
                <span class="badge bg-label-secondary">
                  <?php
                  $subjectChip = trim((string)($subject['subject_code'] ?? 'Subject'));
                  $sectionDisplay = trim((string)($subject['section_display'] ?? ''));
                  if ($sectionDisplay !== '') {
                      $subjectChip .= ' - ' . $sectionDisplay;
                  }
                  echo synk_student_h($subjectChip);
                  ?>
                </span>
              <?php endforeach; ?>
            </div>

            <div class="alert alert-warning">
              <strong>Instruction:</strong> Please write the number that corresponds with the performance of the teacher based on the given scale below on the provided answer sheet and utilize the back portion for your comments:
            </div>

            <?php foreach ($questionBank as $group): ?>
              <div class="border rounded-3 p-3 mb-4">
                <h6 class="fw-bold mb-3"><?php echo synk_student_h((string)($group['title'] ?? 'Category')); ?></h6>
                <?php foreach ((array)($group['items'] ?? []) as $itemIndex => $item): ?>
                  <?php
                  $questionNumber++;
                  $key = (string)($item['key'] ?? '');
                  $savedAnswers = is_array($cardEval['answers'] ?? null) ? $cardEval['answers'] : [];
                  $value = $usePostedValues
                      ? (string)($submittedRatings[$key] ?? '')
                      : (string)($savedAnswers[$key] ?? '');
                  ?>
                  <div class="<?php echo $itemIndex > 0 ? 'border-top pt-3 mt-3' : ''; ?>">
                    <label class="form-label fw-semibold" for="<?php echo synk_student_h($cardModalId . '_' . $key); ?>">
                      <?php echo (int)$questionNumber; ?>. <?php echo synk_student_h((string)($item['text'] ?? 'Question')); ?>
                    </label>
                    <select id="<?php echo synk_student_h($cardModalId . '_' . $key); ?>" name="ratings[<?php echo synk_student_h($key); ?>]" class="form-select" required>
                      <option value="">Select rating</option>
                      <?php foreach ($ratingScale as $ratingValue => $ratingInfo): ?>
                        <option value="<?php echo (int)$ratingValue; ?>"<?php echo $value === (string)$ratingValue ? ' selected' : ''; ?>>
                          <?php echo (int)$ratingValue; ?> - <?php echo synk_student_h((string)($ratingInfo['label'] ?? '')); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>

            <div>
              <label for="<?php echo synk_student_h($cardModalId); ?>_comment_text" class="form-label fw-semibold">Comments</label>
              <textarea id="<?php echo synk_student_h($cardModalId); ?>_comment_text" name="comment_text" class="form-control" rows="4" placeholder="Optional comments about the faculty performance..."><?php echo synk_student_h($usePostedValues ? $submittedComment : (string)($cardEval['comment_text'] ?? '')); ?></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <?php if ($studentPortalPreviewMode || $allFacultyFinalized): ?>
              <button type="button" class="btn btn-primary" disabled>Preview Only</button>
            <?php else: ?>
              <button type="submit" class="btn btn-primary">Save Draft Evaluation</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php if ($evaluableCount > 0): ?>
  <div class="modal fade faculty-final-review-modal" id="finalEvaluationReviewModal" tabindex="-1" aria-labelledby="finalEvaluationReviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <form method="post" action="<?php echo synk_student_h(synk_student_build_portal_url('class-program.php')); ?>" id="finalFacultyEvaluationForm">
          <div class="modal-header">
            <div>
              <h5 class="modal-title" id="finalEvaluationReviewModalLabel">Final Evaluation Review</h5>
              <small class="text-muted">Review all saved faculty evaluations for <?php echo synk_student_h($selectedTermLabel !== '' ? $selectedTermLabel : 'the selected term'); ?> before the final one-time submission.</small>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="submit_final_faculty_evaluation" value="1">
            <input type="hidden" name="term_key" value="<?php echo synk_student_h($selectedTermKey); ?>">
            <?php if ($selectedFacultyKey !== ''): ?>
              <input type="hidden" name="faculty_key" value="<?php echo synk_student_h($selectedFacultyKey); ?>">
            <?php endif; ?>

            <div class="alert alert-primary">
              <strong>Review first before final submission.</strong><br>
              Once submitted, the term evaluation is locked and can no longer be edited. The QR proof will only appear after this final submission is completed.
            </div>

            <div class="d-flex flex-wrap gap-2 mb-4">
              <?php if ($selectedTermLabel !== ''): ?><span class="badge bg-label-primary"><?php echo synk_student_h($selectedTermLabel); ?></span><?php endif; ?>
              <span class="badge bg-label-info"><?php echo (int)$evaluableCount; ?> faculty</span>
              <span class="badge bg-label-success"><?php echo (int)$totalSubjectCount; ?> subjects</span>
              <span class="badge bg-label-warning"><?php echo (int)$savedCount; ?> / <?php echo (int)$evaluableCount; ?> drafts saved</span>
            </div>

            <div class="faculty-review-list">
              <?php foreach ($cards as $card): ?>
                <?php
                if ((int)($card['faculty_id'] ?? 0) <= 0) {
                    continue;
                }
                $cardEval = is_array($card['evaluation'] ?? null) ? $card['evaluation'] : null;
                ?>
                <div class="faculty-review-item">
                  <div class="faculty-review-item-head">
                    <div>
                      <div class="fw-semibold"><?php echo synk_student_h((string)($card['faculty_name'] ?? 'Faculty')); ?></div>
                      <div class="text-muted"><?php echo (int)($card['subject_count'] ?? 0); ?> subject<?php echo (int)($card['subject_count'] ?? 0) === 1 ? '' : 's'; ?></div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                      <?php if (!empty($card['is_final_submitted'])): ?>
                        <span class="badge bg-label-success">Final submitted</span>
                      <?php elseif (!empty($card['is_draft_saved'])): ?>
                        <span class="badge bg-label-warning">Draft saved</span>
                      <?php else: ?>
                        <span class="badge bg-label-danger">Pending</span>
                      <?php endif; ?>
                      <?php if ($cardEval): ?>
                        <span class="badge bg-label-info">Average <?php echo number_format((float)($cardEval['average_rating'] ?? 0), 2); ?></span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <?php if ($cardEval && trim((string)($cardEval['comment_text'] ?? '')) !== ''): ?>
                    <p class="text-muted mt-3 mb-0"><i class="bx bx-message-square-detail me-1"></i><?php echo synk_student_h((string)($cardEval['comment_text'] ?? '')); ?></p>
                  <?php endif; ?>

                  <div class="faculty-review-subjects">
                    <?php foreach ((array)($card['subjects'] ?? []) as $subject): ?>
                      <div class="faculty-review-subject">
                        <div class="fw-semibold"><?php echo synk_student_h((string)($subject['subject_code'] ?? 'NO CODE')); ?></div>
                        <div class="text-muted"><?php echo synk_student_h((string)($subject['descriptive_title'] ?? 'Untitled subject')); ?></div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                          <?php if (trim((string)($subject['section_display'] ?? '')) !== ''): ?>
                            <span class="badge bg-label-primary"><?php echo synk_student_h((string)($subject['section_display'] ?? '')); ?></span>
                          <?php endif; ?>
                          <?php if (trim((string)($subject['room_name'] ?? '')) !== ''): ?>
                            <span class="badge bg-label-success"><?php echo synk_student_h((string)($subject['room_name'] ?? '')); ?></span>
                          <?php endif; ?>
                        </div>
                        <?php if (trim((string)($subject['schedule_text'] ?? '')) !== ''): ?>
                          <div class="text-muted mt-2"><i class="bx bx-time-five me-1"></i><?php echo synk_student_h((string)($subject['schedule_text'] ?? '')); ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <?php if ($studentPortalPreviewMode): ?>
              <button type="button" class="btn btn-primary" disabled>Preview Only</button>
            <?php elseif ($allFacultyFinalized): ?>
              <button type="button" class="btn btn-success" disabled>Already Submitted</button>
            <?php elseif ($canOpenFinalReview): ?>
              <button type="submit" class="btn btn-primary">Submit Final Evaluation</button>
            <?php else: ?>
              <button type="button" class="btn btn-primary" disabled>Complete All Faculty Drafts First</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/libs/popper/popper.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
<script src="../assets/js/sweetalert2.all.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  var finalForm = document.getElementById("finalFacultyEvaluationForm");
  if (finalForm && typeof Swal !== "undefined") {
    finalForm.addEventListener("submit", function (event) {
      if (finalForm.dataset.confirmed === "1") {
        return;
      }

      event.preventDefault();

      Swal.fire({
        icon: "warning",
        title: "Submit Final Evaluation?",
        text: "This will lock all saved faculty drafts for the selected term and generate the final QR proof. You will no longer be able to edit the evaluations after this step.",
        showCancelButton: true,
        confirmButtonText: "Yes, submit final evaluation",
        cancelButtonText: "Review Again",
        confirmButtonColor: "#696cff"
      }).then(function (result) {
        if (!result.isConfirmed) {
          return;
        }

        finalForm.dataset.confirmed = "1";
        finalForm.submit();
      });
    });
  }

  <?php if ($openModalFacultyKey !== ''): ?>
  (function () {
    var key = <?php echo json_encode($openModalFacultyKey); ?>;
    var modalId = "facultyEvaluationModal_" + key.replace(/[^A-Za-z0-9_-]/g, "_");
    var modalElement = document.getElementById(modalId);
    if (modalElement) {
      new bootstrap.Modal(modalElement).show();
    }
  })();
  <?php endif; ?>

  <?php if ($openReviewModal): ?>
  (function () {
    var reviewModalElement = document.getElementById("finalEvaluationReviewModal");
    if (reviewModalElement) {
      new bootstrap.Modal(reviewModalElement).show();
    }
  })();
  <?php endif; ?>
});
</script>
</body>
</html>
