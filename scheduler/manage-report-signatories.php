<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/scheduler_access_helper.php';
require_once '../backend/signatory_settings_helper.php';
require_once '../backend/program_chair_signatory_helper.php';

synk_scheduler_bootstrap_session_scope($conn);

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'scheduler') {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['csrf_token'];
$userId = (int)($_SESSION['user_id'] ?? 0);
$collegeId = (int)($_SESSION['college_id'] ?? 0);

if ($collegeId <= 0) {
    echo 'Scheduler error: missing college assignment.';
    exit;
}

function page_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function page_signatory_slot(array $settings, string $slotCode, string $fallbackLabel): array
{
    $row = is_array($settings[$slotCode] ?? null) ? $settings[$slotCode] : [];

    return [
        'slot_code' => $slotCode,
        'label' => trim((string)($row['label'] ?? $fallbackLabel)) ?: $fallbackLabel,
        'signatory_name' => trim((string)($row['signatory_name'] ?? '')),
        'signatory_title' => trim((string)($row['signatory_title'] ?? '')),
    ];
}

$collegeLabel = trim((string)($_SESSION['college_name'] ?? '')) ?: 'College not assigned';
$currentTerm = synk_fetch_current_academic_term($conn);
$currentAyId = (int)($currentTerm['ay_id'] ?? 0);
$currentSemester = (int)($currentTerm['semester'] ?? 0);
$currentTermText = (string)($currentTerm['term_text'] ?? 'Current academic term');

if ($collegeId > 0) {
    $collegeStmt = $conn->prepare("
        SELECT college_name, college_code
        FROM tbl_college
        WHERE college_id = ?
        LIMIT 1
    ");

    if ($collegeStmt instanceof mysqli_stmt) {
        $collegeStmt->bind_param('i', $collegeId);
        $collegeStmt->execute();
        $collegeResult = $collegeStmt->get_result();
        $collegeRow = ($collegeResult instanceof mysqli_result) ? $collegeResult->fetch_assoc() : null;

        if (is_array($collegeRow)) {
            $collegeCode = trim((string)($collegeRow['college_code'] ?? ''));
            $collegeName = trim((string)($collegeRow['college_name'] ?? ''));
            $collegeLabel = trim($collegeCode . ' - ' . $collegeName, ' -');
        }

        $collegeStmt->close();
    }
}

$pageAlert = null;
$postedPreparedByName = '';
$postedPreparedByTitle = '';
$postedProgramChairProgramId = 0;
$postedProgramChairFacultyId = 0;
$preparedByFormWasSubmitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = trim((string)($_POST['csrf_token'] ?? ''));
    $isProgramChairRequest = isset($_POST['program_chair_action']);

    if (
        $postedToken === ''
        || !hash_equals((string)$_SESSION['csrf_token'], $postedToken)
    ) {
        $pageAlert = [
            'type' => 'danger',
            'message' => 'Invalid request token. Please reload the page and try again.',
        ];
    } elseif ($isProgramChairRequest) {
        $programChairAction = trim((string)($_POST['program_chair_action'] ?? 'save'));
        $postedProgramChairProgramId = (int)($_POST['program_chair_program_id'] ?? 0);
        $postedProgramChairFacultyId = (int)($_POST['program_chair_faculty_id'] ?? 0);

        if ($postedProgramChairProgramId <= 0) {
            $pageAlert = [
                'type' => 'warning',
                'message' => 'Please select a program for the Program Chair assignment.',
            ];
        } elseif (!synk_program_chair_signatory_program_belongs_to_college($conn, $collegeId, $postedProgramChairProgramId)) {
            $pageAlert = [
                'type' => 'danger',
                'message' => 'The selected program does not belong to your assigned college.',
            ];
        } elseif ($programChairAction === 'remove') {
            $removed = synk_program_chair_signatory_remove($conn, $collegeId, $postedProgramChairProgramId, $userId);

            if ($removed) {
                $pageAlert = [
                    'type' => 'success',
                    'message' => 'Program Chair assignment removed successfully.',
                ];
                $postedProgramChairProgramId = 0;
                $postedProgramChairFacultyId = 0;
            } else {
                $pageAlert = [
                    'type' => 'danger',
                    'message' => 'Unable to remove the Program Chair assignment right now.',
                ];
            }
        } else {
            if ($postedProgramChairFacultyId <= 0) {
                $pageAlert = [
                    'type' => 'warning',
                    'message' => 'Please select a faculty member for the Program Chair assignment.',
                ];
            } elseif (!synk_program_chair_signatory_faculty_is_assigned(
                $conn,
                $collegeId,
                $postedProgramChairFacultyId,
                $currentAyId,
                $currentSemester
            )) {
                $pageAlert = [
                    'type' => 'danger',
                    'message' => 'The selected faculty member is not active in this college for the current term.',
                ];
            } else {
                $saved = synk_program_chair_signatory_save(
                    $conn,
                    $collegeId,
                    $postedProgramChairProgramId,
                    $postedProgramChairFacultyId,
                    $userId
                );

                if ($saved) {
                    $pageAlert = [
                        'type' => 'success',
                        'message' => 'Program Chair assignment saved successfully.',
                    ];
                    $postedProgramChairProgramId = 0;
                    $postedProgramChairFacultyId = 0;
                } else {
                    $pageAlert = [
                        'type' => 'danger',
                        'message' => 'Unable to save the Program Chair assignment right now.',
                    ];
                }
            }
        }
    } else {
        $preparedByFormWasSubmitted = true;
        $action = trim((string)($_POST['signatory_action'] ?? 'save'));
        $postedPreparedByName = trim((string)($_POST['prepared_by_name'] ?? ''));
        $postedPreparedByTitle = trim((string)($_POST['prepared_by_title'] ?? ''));

        if ($action === 'clear') {
            $saved = synk_save_signatory_settings(
                $conn,
                'college',
                $collegeId,
                [
                    'prepared_by' => [
                        'faculty_id' => 0,
                        'signatory_name' => '',
                        'signatory_title' => '',
                    ],
                ],
                $userId
            );

            if ($saved) {
                $pageAlert = [
                    'type' => 'success',
                    'message' => 'Prepared by signatory cleared successfully.',
                ];
                $postedPreparedByName = '';
                $postedPreparedByTitle = '';
            } else {
                $pageAlert = [
                    'type' => 'danger',
                    'message' => 'Unable to clear the prepared by signatory right now.',
                ];
            }
        } else {
            if ($postedPreparedByName === '' || $postedPreparedByTitle === '') {
                $pageAlert = [
                    'type' => 'warning',
                    'message' => 'Please enter both the prepared by signatory name and designation.',
                ];
            } else {
                $saved = synk_save_signatory_settings(
                    $conn,
                    'college',
                    $collegeId,
                    [
                        'prepared_by' => [
                            'faculty_id' => 0,
                            'signatory_name' => $postedPreparedByName,
                            'signatory_title' => $postedPreparedByTitle,
                        ],
                    ],
                    $userId
                );

                if ($saved) {
                    $pageAlert = [
                        'type' => 'success',
                        'message' => 'Prepared by signatory saved successfully.',
                    ];
                } else {
                    $pageAlert = [
                        'type' => 'danger',
                        'message' => 'Unable to save the prepared by signatory right now.',
                    ];
                }
            }
        }
    }
}

$collegeSignatorySettings = synk_fetch_signatory_settings($conn, 'college', $collegeId);
$adminSignatorySettings = synk_fetch_signatory_settings($conn, 'global', 0);

$preparedBySignatory = page_signatory_slot($collegeSignatorySettings, 'prepared_by', 'Prepared by');
$checkedByLeftSignatory = page_signatory_slot($adminSignatorySettings, 'checked_by_left', 'Checked by (Left)');
$checkedByRightSignatory = page_signatory_slot($adminSignatorySettings, 'checked_by_right', 'Checked by (Right)');
$recommendingApprovalSignatory = page_signatory_slot($adminSignatorySettings, 'recommending_approval', 'Recommending Approval');
$approvedBySignatory = page_signatory_slot($adminSignatorySettings, 'approved_by', 'Approved by');

$programChairPrograms = synk_program_chair_signatory_fetch_program_options($conn, $collegeId);
$programChairFacultyOptions = synk_program_chair_signatory_fetch_faculty_options($conn, $collegeId, $currentAyId, $currentSemester);
$programChairAssignments = synk_program_chair_signatory_fetch_assignments($conn, $collegeId);

$preparedByFormName = $preparedByFormWasSubmitted
    ? $postedPreparedByName
    : $preparedBySignatory['signatory_name'];
$preparedByFormTitle = $preparedByFormWasSubmitted
    ? $postedPreparedByTitle
    : $preparedBySignatory['signatory_title'];
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
<head>
  <meta charset="utf-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
  />

  <title>Report Signatories | Synk</title>

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
    .signatory-hero {
      border: 1px solid #e5ecf6;
      border-radius: 1.2rem;
      overflow: hidden;
      background:
        radial-gradient(circle at top right, rgba(105, 108, 255, 0.16), transparent 34%),
        linear-gradient(135deg, #fbfcff 0%, #ffffff 52%, #f2f6ff 100%);
      box-shadow: 0 18px 40px rgba(31, 45, 61, 0.06);
    }

    .signatory-kicker {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.45rem 0.8rem;
      border-radius: 999px;
      background: rgba(105, 108, 255, 0.1);
      color: #696cff;
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }

    .signatory-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.55rem;
      padding: 0.55rem 0.85rem;
      border-radius: 999px;
      border: 1px solid #dbe5fb;
      background: #f6f9ff;
      color: #4b6282;
      font-weight: 600;
    }

    .signatory-card {
      border: 1px solid #e5ecf6;
      border-radius: 1.15rem;
      box-shadow: 0 18px 40px rgba(31, 45, 61, 0.06);
      background: #fff;
      height: 100%;
    }

    .signatory-pane {
      border: 1px solid #e8eef6;
      border-radius: 1rem;
      padding: 1.2rem;
      background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
      height: 100%;
    }

    .signatory-pane-soft {
      background: linear-gradient(180deg, #fbfcff 0%, #f4f7ff 100%);
    }

    .signatory-section-label {
      display: inline-block;
      margin-bottom: 0.45rem;
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8592a3;
    }

    .signatory-live-preview {
      padding: 1rem;
      border-radius: 0.95rem;
      border: 1px solid #e6edf7;
      background: #fff;
    }

    .signatory-live-label {
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8592a3;
      margin-bottom: 0.25rem;
    }

    .signatory-live-name {
      font-weight: 700;
      color: #233446;
    }

    .signatory-live-title {
      color: #6b7280;
      font-size: 0.86rem;
    }

    .signatory-reference-stack {
      display: grid;
      gap: 0.9rem;
    }

    .signatory-reference-item {
      padding: 0.95rem 1rem;
      border-radius: 0.95rem;
      border: 1px solid #e6edf7;
      background: #fff;
    }

    .signatory-reference-item.is-readonly {
      background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
    }

    .signatory-reference-label {
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8592a3;
      margin-bottom: 0.25rem;
    }

    .signatory-reference-name {
      font-weight: 700;
      color: #233446;
    }

    .signatory-reference-title {
      color: #6b7280;
      font-size: 0.86rem;
      line-height: 1.35;
    }

    .signatory-note {
      display: inline-flex;
      align-items: center;
      gap: 0.55rem;
      margin-top: 0.85rem;
      padding: 0.7rem 0.9rem;
      border-radius: 0.9rem;
      background: #f5f8ff;
      border: 1px solid #dce5fb;
      color: #4b6282;
      font-size: 0.92rem;
      font-weight: 600;
    }

    .signatory-save-row {
      display: flex;
      justify-content: flex-end;
      gap: 0.65rem;
      margin-top: 1.35rem;
      flex-wrap: wrap;
    }

    .program-chair-assignment-table th {
      font-size: 0.76rem;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: #667a93;
    }

    .program-chair-assignment-table td {
      vertical-align: middle;
      color: #4f6078;
    }

    .program-chair-program-name {
      font-weight: 700;
      color: #233446;
    }

    .program-chair-faculty-name {
      font-weight: 700;
      color: #2f3f57;
    }

    .program-chair-empty {
      padding: 1rem;
      border-radius: 0.95rem;
      border: 1px dashed #ccd8ea;
      background: #fbfcff;
      color: #6b7280;
      font-weight: 600;
    }

    @media (max-width: 767.98px) {
      .signatory-save-row .btn {
        width: 100%;
      }
    }
  </style>
</head>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">
    <?php include 'sidebar.php'; ?>

    <div class="layout-page">
      <?php include 'navbar.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">
          <div class="card signatory-hero mb-4">
            <div class="card-body p-4 p-lg-5">
              <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-start">
                <div>
                  <span class="signatory-kicker mb-3"><i class="bx bx-pen"></i> Scheduler signatories</span>
                  <h4 class="fw-bold mb-2">Report Signatories</h4>
                  <p class="text-muted mb-0">Save the college-specific <strong>Prepared by</strong> signatory here. Administrator-controlled approval signatories are shown below as read-only reference for printed reports.</p>
                  <div class="signatory-note">
                    <i class="bx bx-info-circle"></i>
                    <span>This page no longer limits prepared by to program chair assignments. Enter the exact name and designation you want to appear in reports.</span>
                  </div>
                </div>
                <div class="d-flex flex-column gap-2 align-items-stretch align-items-lg-end">
                  <div class="fw-bold" style="font-size: 16px;"><?= page_h($collegeLabel) ?></div>
                  <div class="signatory-chip">
                    <span>Current Term</span>
                    <strong><?= page_h($currentTermText) ?></strong>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <?php if (is_array($pageAlert)): ?>
            <div class="alert alert-<?= page_h($pageAlert['type'] ?? 'info') ?> mb-4" role="alert">
              <?= page_h($pageAlert['message'] ?? '') ?>
            </div>
          <?php endif; ?>

          <div class="row g-4">
            <div class="col-xl-6">
              <div class="card signatory-card">
                <div class="card-body p-4">
                  <div class="signatory-pane">
                    <span class="signatory-section-label">Editable by scheduler</span>
                    <h5 class="mb-2">Prepared by Signatory</h5>
                    <p class="text-muted mb-4">This value is used as the college-level prepared by signatory in workload print previews and report signatory pages.</p>

                    <form method="POST" novalidate>
                      <input type="hidden" name="csrf_token" value="<?= page_h($csrfToken) ?>">

                      <div class="mb-3">
                        <label class="form-label" for="prepared_by_name">Prepared by Name</label>
                        <input
                          type="text"
                          class="form-control"
                          id="prepared_by_name"
                          name="prepared_by_name"
                          value="<?= page_h($preparedByFormName) ?>"
                          placeholder="Enter signatory full name"
                          maxlength="150"
                        >
                      </div>

                      <div class="mb-0">
                        <label class="form-label" for="prepared_by_title">Prepared by Designation</label>
                        <input
                          type="text"
                          class="form-control"
                          id="prepared_by_title"
                          name="prepared_by_title"
                          value="<?= page_h($preparedByFormTitle) ?>"
                          placeholder="Enter signatory designation"
                          maxlength="150"
                        >
                      </div>

                      <div class="signatory-save-row">
                        <button type="submit" name="signatory_action" value="clear" class="btn btn-outline-secondary">
                          Clear Prepared by
                        </button>
                        <button type="submit" name="signatory_action" value="save" class="btn btn-primary">
                          Save Prepared by
                        </button>
                      </div>
                    </form>

                    <div class="signatory-live-preview mt-4">
                      <div class="signatory-live-label">Current saved prepared by</div>
                      <div class="signatory-live-name"><?= page_h($preparedBySignatory['signatory_name'] !== '' ? $preparedBySignatory['signatory_name'] : '-') ?></div>
                      <div class="signatory-live-title"><?= page_h($preparedBySignatory['signatory_title'] !== '' ? $preparedBySignatory['signatory_title'] : '-') ?></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-xl-6">
              <div class="card signatory-card">
                <div class="card-body p-4">
                  <div class="signatory-pane signatory-pane-soft">
                    <span class="signatory-section-label">Administrator reference</span>
                    <h5 class="mb-2">Read-only Institutional Signatories</h5>
                    <p class="text-muted mb-4">These signatories are controlled from the administrator module and are shown here so you can review the full print signatory stack.</p>

                    <div class="signatory-reference-stack">
                      <div class="signatory-reference-item is-readonly">
                        <div class="signatory-reference-label">Checked by (Left)</div>
                        <div class="signatory-reference-name"><?= page_h($checkedByLeftSignatory['signatory_name'] !== '' ? $checkedByLeftSignatory['signatory_name'] : '-') ?></div>
                        <div class="signatory-reference-title"><?= page_h($checkedByLeftSignatory['signatory_title'] !== '' ? $checkedByLeftSignatory['signatory_title'] : '-') ?></div>
                      </div>

                      <div class="signatory-reference-item is-readonly">
                        <div class="signatory-reference-label">Checked by (Right)</div>
                        <div class="signatory-reference-name"><?= page_h($checkedByRightSignatory['signatory_name'] !== '' ? $checkedByRightSignatory['signatory_name'] : '-') ?></div>
                        <div class="signatory-reference-title"><?= page_h($checkedByRightSignatory['signatory_title'] !== '' ? $checkedByRightSignatory['signatory_title'] : '-') ?></div>
                      </div>

                      <div class="signatory-reference-item is-readonly">
                        <div class="signatory-reference-label">Recommending Approval</div>
                        <div class="signatory-reference-name"><?= page_h($recommendingApprovalSignatory['signatory_name'] !== '' ? $recommendingApprovalSignatory['signatory_name'] : '-') ?></div>
                        <div class="signatory-reference-title"><?= page_h($recommendingApprovalSignatory['signatory_title'] !== '' ? $recommendingApprovalSignatory['signatory_title'] : '-') ?></div>
                      </div>

                      <div class="signatory-reference-item is-readonly">
                        <div class="signatory-reference-label">Approved by</div>
                        <div class="signatory-reference-name"><?= page_h($approvedBySignatory['signatory_name'] !== '' ? $approvedBySignatory['signatory_name'] : '-') ?></div>
                        <div class="signatory-reference-title"><?= page_h($approvedBySignatory['signatory_title'] !== '' ? $approvedBySignatory['signatory_title'] : '-') ?></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12">
              <div class="card signatory-card">
                <div class="card-body p-4">
                  <div class="signatory-pane">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                      <div>
                        <span class="signatory-section-label">Program-level signatories</span>
                        <h5 class="mb-2">Program Chair Assignments</h5>
                        <p class="text-muted mb-0">Assign the Program Chair per program using faculty already assigned to this college for the current term. Saving an existing program updates its chair only.</p>
                      </div>
                      <div class="signatory-chip align-self-start">
                        <span>Scope</span>
                        <strong><?= page_h($collegeLabel) ?></strong>
                      </div>
                    </div>

                    <form method="POST" class="row g-3 align-items-end" novalidate>
                      <input type="hidden" name="csrf_token" value="<?= page_h($csrfToken) ?>">
                      <input type="hidden" name="program_chair_action" value="save">

                      <div class="col-lg-5">
                        <label class="form-label" for="program_chair_program_id">Program</label>
                        <select class="form-select" id="program_chair_program_id" name="program_chair_program_id" required>
                          <option value="">Select program</option>
                          <?php foreach ($programChairPrograms as $programOption): ?>
                            <?php $programIdOption = (int)($programOption['program_id'] ?? 0); ?>
                            <option value="<?= $programIdOption ?>" <?= $postedProgramChairProgramId === $programIdOption ? 'selected' : '' ?>>
                              <?= page_h($programOption['label'] ?? '') ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <div class="col-lg-5">
                        <label class="form-label" for="program_chair_faculty_id">Faculty / Program Chair</label>
                        <select class="form-select" id="program_chair_faculty_id" name="program_chair_faculty_id" required>
                          <option value="">Select faculty</option>
                          <?php foreach ($programChairFacultyOptions as $facultyOption): ?>
                            <?php
                              $facultyIdOption = (int)($facultyOption['faculty_id'] ?? 0);
                              $facultyLabel = trim((string)($facultyOption['full_name'] ?? ''));
                              $designationLabel = trim((string)($facultyOption['designation_name'] ?? ''));
                              if ($designationLabel !== '') {
                                  $facultyLabel .= ' - ' . $designationLabel;
                              }
                            ?>
                            <option value="<?= $facultyIdOption ?>" <?= $postedProgramChairFacultyId === $facultyIdOption ? 'selected' : '' ?>>
                              <?= page_h($facultyLabel) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <div class="col-lg-2 d-grid">
                        <button type="submit" class="btn btn-primary">
                          Save Chair
                        </button>
                      </div>
                    </form>

                    <div class="mt-4">
                      <h6 class="mb-3">Current program chair assignments</h6>

                      <?php if (empty($programChairPrograms)): ?>
                        <div class="program-chair-empty">No active programs are available for this college.</div>
                      <?php else: ?>
                        <div class="table-responsive">
                          <table class="table table-hover align-middle mb-0 program-chair-assignment-table">
                            <thead class="table-light">
                              <tr>
                                <th>Program</th>
                                <th>Assigned Program Chair</th>
                                <th>Designation</th>
                                <th class="text-end">Action</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($programChairPrograms as $programOption): ?>
                                <?php
                                  $programIdOption = (int)($programOption['program_id'] ?? 0);
                                  $assignment = is_array($programChairAssignments[$programIdOption] ?? null)
                                      ? $programChairAssignments[$programIdOption]
                                      : null;
                                ?>
                                <tr>
                                  <td>
                                    <div class="program-chair-program-name"><?= page_h($programOption['label'] ?? '') ?></div>
                                  </td>
                                  <td>
                                    <div class="program-chair-faculty-name"><?= page_h($assignment['faculty_name'] ?? '-') ?></div>
                                  </td>
                                  <td><?= page_h($assignment['designation_name'] ?? '-') ?></td>
                                  <td class="text-end">
                                    <?php if (is_array($assignment)): ?>
                                      <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= page_h($csrfToken) ?>">
                                        <input type="hidden" name="program_chair_action" value="remove">
                                        <input type="hidden" name="program_chair_program_id" value="<?= $programIdOption ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                          Remove
                                        </button>
                                      </form>
                                    <?php else: ?>
                                      <span class="text-muted small">Not assigned</span>
                                    <?php endif; ?>
                                  </td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <?php include '../footer.php'; ?>

        <div class="content-backdrop fade"></div>
      </div>
    </div>
  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
</div>

<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
</body>
</html>
