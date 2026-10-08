<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/program_chair_helper.php';
require_once '../backend/enrollment_draft_helper.php';

synk_program_chair_require_login($conn);

$programChairPortalContext = synk_program_chair_portal_context($conn);
$programChairPortalDisplayName = (string)($programChairPortalContext['account_name'] ?? 'Program Chair');
$programChairPortalDisplayEmail = (string)($programChairPortalContext['email'] ?? '');
$programChairPrograms = is_array($programChairPortalContext['program_rows'] ?? null)
    ? $programChairPortalContext['program_rows']
    : [];
$programChairCollege = is_array($programChairPortalContext['college'] ?? null)
    ? $programChairPortalContext['college']
    : null;
$programChairCurrentTerm = is_array($programChairPortalContext['current_term'] ?? null)
    ? $programChairPortalContext['current_term']
    : [];
$programChairTermText = trim((string)($programChairCurrentTerm['term_text'] ?? 'Current academic term'));
$programChairEnrollmentPrograms = synk_program_chair_active_program_rows($programChairPrograms);
if (empty($programChairEnrollmentPrograms)) {
    $programChairEnrollmentPrograms = $programChairPrograms;
}

$collegeId = (int)($programChairPortalContext['college_id'] ?? 0);
$collegeName = trim((string)($programChairCollege['college_name'] ?? 'Assigned College'));
$currentAyId = (int)($programChairCurrentTerm['ay_id'] ?? 0);
$currentSemester = (int)($programChairCurrentTerm['semester'] ?? 0);
$programChairSectionRows = synk_program_chair_fetch_section_rows($conn, $collegeId, $currentAyId, $currentSemester);
$draftTablesReady = synk_enrollment_draft_tables_ready($conn);
$requestedDraftId = max(0, (int)($_GET['draft_id'] ?? 0));
$initialDraftDetail = null;
if ($draftTablesReady && $requestedDraftId > 0 && $collegeId > 0) {
    $initialDraftDetail = synk_enrollment_fetch_program_chair_draft_detail(
        $conn,
        $requestedDraftId,
        (int)($_SESSION['user_id'] ?? 0),
        $collegeId,
        false
    );
}

$programOptionsJson = json_encode(array_values($programChairEnrollmentPrograms), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sectionOptionsJson = json_encode(array_values($programChairSectionRows), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$currentTermJson = json_encode($programChairCurrentTerm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$initialDraftJson = json_encode($initialDraftDetail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>Program Chair Enrollment | Synk</title>
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
      .enroll-card,.enroll-hero,.enroll-note{border:1px solid #dce5f1;border-radius:22px;box-shadow:0 18px 38px rgba(67,89,113,.08)}
      .enroll-hero{background:linear-gradient(135deg,#f8fbff 0%,#eef5ff 56%,#f3f9ef 100%)}
      .enroll-note{background:linear-gradient(135deg,#fff8ea 0%,#fffdf6 100%);color:#855b16}
      .enroll-kicker{display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .8rem;border-radius:999px;background:#eef4ff;color:#4f6595;font-size:.72rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
      .hero-metric-grid,.enroll-tab-grid,.subject-grid{display:grid;gap:.95rem}
      .hero-metric-grid{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
      .enroll-tab-grid{grid-template-columns:repeat(auto-fit,minmax(185px,1fr))}
      .subject-grid{grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
      .hero-metric,.enroll-tab-btn,.subject-card,.summary-box,.support-box{border:1px solid #dfe7f2;border-radius:18px;background:#fff}
      .hero-metric{padding:1rem 1.1rem}
      .hero-metric-label,.panel-label{display:block;color:#7d8ea5;font-size:.75rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
      .hero-metric-value{display:block;margin-top:.4rem;color:#33475b;font-size:1rem;font-weight:700;line-height:1.55}
      .enroll-tab-btn{width:100%;padding:1rem 1.05rem;text-align:left;transition:.18s ease;display:flex;gap:.85rem;align-items:flex-start}
      .enroll-tab-btn:hover{border-color:#cad7f3;box-shadow:0 14px 26px rgba(67,89,113,.08)}
      .enroll-tab-btn.is-active{border-color:#8fb3ff;background:#f4f8ff;box-shadow:0 16px 30px rgba(67,89,113,.1)}
      .enroll-tab-btn.is-complete{border-color:#cae8cf;background:#f5fbf6}
      .tab-badge{width:36px;height:36px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:#eef4ff;color:#556edc;font-weight:800;flex:0 0 36px}
      .enroll-tab-btn.is-complete .tab-badge{background:#e7f7eb;color:#2f8f49}
      .tab-copy{color:#70829a;display:block;margin-top:.15rem;line-height:1.45}
      .enroll-panel{border:1px solid #dde5f0;border-radius:22px;background:#fff}
      .enroll-panel-title{margin:.75rem 0 .35rem;color:#243246;font-size:1.18rem;font-weight:800}
      .enroll-panel-copy{margin:0;color:#5a6c84;line-height:1.55}
      .support-box{padding:.95rem 1rem;background:#f8fbff;color:#5d718c}
      .support-box strong{color:#2f425b}
      .readonly-box{background:#f8fbff !important}
      .student-mode-group{display:flex;gap:.7rem;flex-wrap:wrap}
      .student-mode-card{flex:1 1 235px;border:1px solid #dde6f0;border-radius:16px;padding:.95rem 1rem;background:#fbfdff;cursor:pointer}
      .student-mode-card.active{border-color:#bcd2f7;background:#f3f8ff}
      .student-mode-card input{margin-right:.55rem}
      .subject-card{padding:1rem;display:flex;flex-direction:column;gap:.75rem;height:100%}
      .subject-card.added{border-color:#cae8cf;background:#f7fcf8}
      .subject-meta{display:flex;flex-wrap:wrap;gap:.45rem}
      .subject-title{margin:0;color:#1f3145;font-size:1rem;font-weight:800;line-height:1.35}
      .subject-detail-list{display:grid;gap:.45rem}
      .subject-detail-line{color:#5b6f89;line-height:1.5}
      .subject-detail-line strong{color:#31445e}
      .draft-empty,.status-box{border:1px dashed #d6e1ef;border-radius:18px;padding:1rem;background:#f9fbfe;color:#5d718c}
      .draft-table td,.draft-table th{vertical-align:middle}
      .sticky-stack{position:sticky;top:100px}
      .summary-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .7rem;border-radius:999px;font-size:.76rem;font-weight:700}
      .summary-pill.ready{background:#eaf8ee;color:#2f8f49}
      .summary-pill.pending{background:#fff3de;color:#9d6500}
      .summary-box{padding:.85rem 1rem;background:#fbfdff}
      .summary-box + .summary-box{margin-top:.75rem}
      .checklist,.workflow-list{display:grid;gap:.85rem}
      .check-item,.workflow-item{display:flex;gap:.75rem;align-items:flex-start}
      .check-icon,.workflow-icon{width:34px;height:34px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;background:#eef3ff;color:#5470e3;flex:0 0 34px}
      .check-item.done .check-icon,.workflow-item.active .workflow-icon{background:#e9f8ec;color:#2f8f49}
      .tab-panel{display:none}
      .tab-panel.is-active{display:block}
      .tab-actions{display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-top:1.5rem}
      .progress{height:.7rem}
      @media (max-width:991.98px){.sticky-stack{position:static}.hero-metric-grid,.enroll-tab-grid,.subject-grid{grid-template-columns:1fr}}
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
              <?php if (!$programChairCollege): ?>
                <div class="card enroll-note mb-4"><div class="card-body p-3">Assign a college to this Program Chair account in Access Accounts so the enrollment workspace can load the correct programs and sections.</div></div>
              <?php endif; ?>

              <div class="card enroll-hero mb-4">
                <div class="card-body p-4">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-8">
                      <span class="enroll-kicker"><i class="bx bx-spreadsheet"></i> Enrollment Draft Builder</span>
                      <h3 class="mt-3 mb-2">Program Chair enrollment workspace</h3>
                      <p class="text-muted mb-0">Build a clean draft load from the current term section setup, capture the student profile, then prepare the handoff summary for campus registrar review.</p>
                    </div>
                    <div class="col-lg-4">
                      <div class="hero-metric-grid">
                        <div class="hero-metric"><span class="hero-metric-label">Current Term</span><span class="hero-metric-value"><?php echo htmlspecialchars($programChairTermText, ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="hero-metric"><span class="hero-metric-label">Assigned College</span><span class="hero-metric-value"><?php echo htmlspecialchars($collegeName, ENT_QUOTES, 'UTF-8'); ?></span></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <?php if (empty($programChairSectionRows)): ?>
                <div class="card enroll-note mb-4"><div class="card-body p-3">No current-term sections are available yet for this college. Program Chair enrollment pulls live section offerings and schedules from the Scheduler module, so set up the section there first before drafting loads here.</div></div>
              <?php endif; ?>

              <?php if (!$draftTablesReady): ?>
                <div class="card enroll-note mb-4"><div class="card-body p-3">Enrollment draft tables are not ready yet. Run the provided SQL first so Program Chair can save drafts, reopen them from Draft List, and submit them to the registrar queue.</div></div>
              <?php elseif ($requestedDraftId > 0 && !$initialDraftDetail): ?>
                <div class="card enroll-note mb-4"><div class="card-body p-3">The requested enrollment draft could not be opened for this Program Chair account. It may belong to another college scope or may no longer exist.</div></div>
              <?php endif; ?>

              <div class="enroll-tab-grid mb-4" id="enrollmentTabs">
                <button type="button" class="enroll-tab-btn is-active" data-tab="setup"><span class="tab-badge">1</span><span><strong>Setup</strong><span class="tab-copy">Choose the program and section that already carries the assigned curriculum for this term.</span></span></button>
                <button type="button" class="enroll-tab-btn" data-tab="student"><span class="tab-badge">2</span><span><strong>Student Profile</strong><span class="tab-copy">Encode or verify the student details that will appear in the draft handoff.</span></span></button>
                <button type="button" class="enroll-tab-btn" data-tab="draft"><span class="tab-badge">3</span><span><strong>Draft Load</strong><span class="tab-copy">Review offered subjects and schedules from Scheduler, then add the needed load items.</span></span></button>
                <button type="button" class="enroll-tab-btn" data-tab="handoff"><span class="tab-badge">4</span><span><strong>Registrar Handoff</strong><span class="tab-copy">Check readiness, add advising notes, and review the chair summary before submission.</span></span></button>
              </div>

              <div class="row g-4">
                <div class="col-xl-8">
                  <div class="card enroll-panel tab-panel is-active" data-panel="setup"><div class="card-body p-4">
                    <span class="enroll-kicker"><i class="bx bx-cog"></i> Step 1</span>
                    <h4 class="enroll-panel-title">Draft setup</h4>
                    <p class="enroll-panel-copy">Start from the current term section. The curriculum basis already follows the section assignment created in Scheduler, so there is no separate prospectus pick here.</p>
                    <div class="row g-3 mt-1">
                      <div class="col-md-6"><label for="program_id" class="form-label">Program</label><select id="program_id" class="form-select"><option value="">Select program</option><?php foreach ($programChairEnrollmentPrograms as $programRow): ?><option value="<?php echo (int)($programRow['program_id'] ?? 0); ?>"><?php echo htmlspecialchars((string)($programRow['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                      <div class="col-md-6"><label for="section_id" class="form-label">Section</label><select id="section_id" class="form-select" disabled><option value="">Select program first</option></select></div>
                      <div class="col-md-4"><label for="year_level_basis" class="form-label">Year Level Basis</label><input id="year_level_basis" class="form-control readonly-box" type="text" readonly placeholder="Auto-filled from section" /></div>
                      <div class="col-md-4"><label for="semester_basis_display" class="form-label">Semester Basis</label><input id="semester_basis_display" class="form-control readonly-box" type="text" readonly value="<?php echo htmlspecialchars($programChairTermText, ENT_QUOTES, 'UTF-8'); ?>" /></div>
                      <div class="col-md-4"><label for="enrollment_type" class="form-label">Enrollment Type</label><select id="enrollment_type" class="form-select"><option value="regular">Regular</option><option value="irregular">Irregular</option><option value="first_year">First Year</option><option value="transferee">Transferee</option><option value="returnee">Returnee</option></select></div>
                    </div>
                    <div class="support-box mt-3" id="setupHint">Select a program to load the current term sections under <?php echo htmlspecialchars($collegeName !== '' ? $collegeName : 'your assigned college', ENT_QUOTES, 'UTF-8'); ?>.</div>
                    <div class="tab-actions"><span class="text-muted small">Sections and offered subjects are read from the current Scheduler setup.</span><button type="button" class="btn btn-primary" data-nav-tab="student">Continue to Student Profile</button></div>
                  </div></div>

                  <div class="card enroll-panel tab-panel" data-panel="student"><div class="card-body p-4">
                    <span class="enroll-kicker"><i class="bx bx-user-pin"></i> Step 2</span>
                    <h4 class="enroll-panel-title">Student profile and verification</h4>
                    <p class="enroll-panel-copy">Encode first-year details when the student is not yet linked to the master record, or capture the available identity details first for verification later.</p>
                    <div class="student-mode-group mt-3 mb-3" id="studentModeGroup">
                      <label class="student-mode-card active"><input type="radio" name="student_record_mode" value="first_year" checked /><strong>First-year encoding</strong><br /><small>Use for newly encoded students whose master account will be verified later.</small></label>
                      <label class="student-mode-card"><input type="radio" name="student_record_mode" value="existing" /><strong>Existing student</strong><br /><small>Capture the known student details first while preparing the draft load.</small></label>
                    </div>
                    <div id="firstYearFields" class="row g-3">
                      <div class="col-lg-3 col-md-6"><label class="form-label" for="fy_last_name">Last Name</label><input id="fy_last_name" class="form-control" type="text" /></div>
                      <div class="col-lg-3 col-md-6"><label class="form-label" for="fy_first_name">First Name</label><input id="fy_first_name" class="form-control" type="text" /></div>
                      <div class="col-lg-3 col-md-6"><label class="form-label" for="fy_middle_name">Middle Name</label><input id="fy_middle_name" class="form-control" type="text" /></div>
                      <div class="col-lg-3 col-md-6"><label class="form-label" for="fy_suffix">Suffix</label><input id="fy_suffix" class="form-control" type="text" /></div>
                      <div class="col-md-4"><label class="form-label" for="fy_sex">Sex</label><select id="fy_sex" class="form-select"><option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option></select></div>
                      <div class="col-md-4"><label class="form-label" for="fy_birthdate">Birthdate</label><input id="fy_birthdate" class="form-control" type="date" /></div>
                      <div class="col-md-4"><label class="form-label" for="fy_contact">Contact Number</label><input id="fy_contact" class="form-control" type="text" /></div>
                      <div class="col-12"><label class="form-label" for="fy_email">Student Email</label><input id="fy_email" class="form-control readonly-box" type="email" readonly placeholder="Auto-generated from first and last name" /><div class="form-text">Generated automatically using the standard `firstname...lastname@sksu.edu.ph` format.</div></div>
                    </div>
                    <div id="existingStudentFields" class="row g-3 d-none">
                      <div class="col-lg-3 col-md-6"><label class="form-label" for="existing_last_name">Last Name</label><input id="existing_last_name" class="form-control" type="text" /></div>
                      <div class="col-lg-3 col-md-6"><label class="form-label" for="existing_first_name">First Name</label><input id="existing_first_name" class="form-control" type="text" /></div>
                      <div class="col-lg-3 col-md-6"><label class="form-label" for="existing_middle_name">Middle Name</label><input id="existing_middle_name" class="form-control" type="text" /></div>
                      <div class="col-lg-3 col-md-6"><label class="form-label" for="existing_suffix">Suffix</label><input id="existing_suffix" class="form-control" type="text" /></div>
                      <div class="col-md-4"><label class="form-label" for="student_number">Student Number</label><input id="student_number" class="form-control" type="text" /></div>
                      <div class="col-md-4"><label class="form-label" for="existing_email">Student Email</label><input id="existing_email" class="form-control readonly-box" type="email" readonly placeholder="Auto-generated from name" /></div>
                      <div class="col-md-4"><label class="form-label" for="existing_note">Verification Note</label><input id="existing_note" class="form-control" type="text" placeholder="Directory note or manual verification reference" /></div>
                    </div>
                    <div class="tab-actions"><button type="button" class="btn btn-outline-secondary" data-nav-tab="setup">Back to Setup</button><button type="button" class="btn btn-primary" data-nav-tab="draft">Continue to Draft Load</button></div>
                  </div></div>

                  <div class="card enroll-panel tab-panel" data-panel="draft"><div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start"><div><span class="enroll-kicker"><i class="bx bx-book-open"></i> Step 3</span><h4 class="enroll-panel-title">Section offering pool</h4><p class="enroll-panel-copy">The list below comes from the selected section offerings and schedule blocks already prepared in Scheduler. Add only the subjects needed for the current draft load.</p></div><a href="programs.php" class="btn btn-outline-primary btn-sm"><i class="bx bx-copy-alt me-1"></i> Open Programs Prospectus</a></div>
                    <div class="row g-3 mt-1 mb-3">
                      <div class="col-md-6"><label for="subject_search" class="form-label">Search Subject</label><input id="subject_search" class="form-control" type="text" placeholder="Search by code, title, faculty, room, or schedule" /></div>
                      <div class="col-md-6 d-flex align-items-end"><div class="status-box w-100" id="subjectPoolStatus">Select a program and section to load offered subjects from Scheduler.</div></div>
                    </div>
                    <div id="subjectPoolContainer" class="subject-grid"></div>
                    <div class="mt-4"><h5 class="mb-2">Current draft load</h5><p class="text-muted mb-3">Review the selected section offerings below before handing them to the campus registrar.</p><div id="draftLoadContainer" class="draft-empty">No offered subjects have been added to the draft yet.</div></div>
                    <div class="tab-actions"><button type="button" class="btn btn-outline-secondary" data-nav-tab="student">Back to Student Profile</button><button type="button" class="btn btn-primary" data-nav-tab="handoff">Continue to Registrar Handoff</button></div>
                  </div></div>

                  <div class="card enroll-panel tab-panel" data-panel="handoff"><div class="card-body p-4">
                    <span class="enroll-kicker"><i class="bx bx-send"></i> Step 4</span>
                    <h4 class="enroll-panel-title">Registrar handoff</h4>
                    <p class="enroll-panel-copy">Use this step to check readiness, add chair notes, and review the handoff summary that will guide the registrar validation stage later.</p>
                    <div class="workflow-list mt-3 mb-4">
                      <div class="workflow-item active"><span class="workflow-icon"><i class="bx bx-edit"></i></span><div><div class="fw-semibold">Program Chair Drafting</div><small>Build the section-based load using the live Scheduler setup and the student details captured above.</small></div></div>
                      <div class="workflow-item"><span class="workflow-icon"><i class="bx bx-send"></i></span><div><div class="fw-semibold">Campus Registrar Review</div><small>The registrar checks the student identity, the section load, and any advising notes attached to the handoff.</small></div></div>
                      <div class="workflow-item"><span class="workflow-icon"><i class="bx bx-check-shield"></i></span><div><div class="fw-semibold">Approval and Posting</div><small>The final enrollment and certificate of registration are processed after the registrar approval workflow.</small></div></div>
                    </div>
                    <div class="row g-4">
                      <div class="col-lg-6"><label for="chair_notes" class="form-label">Chair Notes for Registrar</label><textarea id="chair_notes" class="form-control" rows="7" placeholder="Add advising notes, irregular load reasons, or student verification reminders for the registrar."></textarea></div>
                      <div class="col-lg-6"><div class="support-box h-100"><strong>Before handoff:</strong><br />Confirm the section is correct, the student details are ready, and the draft load reflects the offered subjects and schedules prepared in Scheduler.</div></div>
                    </div>
                    <div class="mt-4">
                      <div class="d-flex flex-wrap gap-2">
                        <button type="button" id="reviewHandoffBtn" class="btn btn-primary">Review Draft Summary</button>
                        <button type="button" id="saveDraftBtn" class="btn btn-outline-primary" <?php echo !$draftTablesReady ? 'disabled' : ''; ?>>Save Draft</button>
                        <button type="button" id="submitDraftBtn" class="btn btn-success" <?php echo !$draftTablesReady ? 'disabled' : ''; ?>>Submit to Registrar</button>
                        <button type="button" id="resetDraftBtn" class="btn btn-outline-secondary">Reset Draft</button>
                      </div>
                      <div id="draftActionNotice" class="alert alert-info mt-3 d-none mb-0"></div>
                      <div id="handoffPreview" class="mt-3 d-none"></div>
                    </div>
                    <div class="tab-actions">
                      <button type="button" class="btn btn-outline-secondary" data-nav-tab="draft">Back to Draft Load</button>
                      <div class="d-flex flex-wrap gap-2">
                        <a href="drafts.php" class="btn btn-outline-primary btn-sm">Open Draft List</a>
                        <span class="text-muted small align-self-center">Saved drafts stay with Program Chair until the status changes to Submitted to Registrar.</span>
                      </div>
                    </div>
                  </div></div>
                </div>

                <div class="col-xl-4">
                  <div class="sticky-stack">
                    <div class="card enroll-panel"><div class="card-body p-4">
                      <span class="enroll-kicker"><i class="bx bx-notepad"></i> Draft Summary</span>
                      <h5 class="mt-3 mb-3">Current enrollment draft</h5>
                      <div id="draftReadinessBadge" class="summary-pill pending mb-3"><i class="bx bx-time-five"></i> Needs completion</div>
                      <div class="progress mb-3"><div id="readinessProgress" class="progress-bar bg-primary" style="width: 0%"></div></div>
                      <div id="readinessChecklist" class="checklist mb-3"></div>
                      <div class="summary-box"><span class="panel-label">Draft Reference</span><div id="summaryReference" class="fw-semibold mt-1">Not saved yet</div></div>
                      <div class="summary-box"><span class="panel-label">Workflow Status</span><div id="summaryStatus" class="fw-semibold mt-1">Draft builder only</div></div>
                      <div class="summary-box"><span class="panel-label">Program</span><div id="summaryProgram" class="fw-semibold mt-1">Not selected</div></div>
                      <div class="summary-box"><span class="panel-label">Section</span><div id="summarySection" class="fw-semibold mt-1">Not selected</div></div>
                      <div class="summary-box"><span class="panel-label">Basis</span><div id="summaryBasis" class="fw-semibold mt-1"><?php echo htmlspecialchars($programChairTermText, ENT_QUOTES, 'UTF-8'); ?></div></div>
                      <div class="summary-box"><span class="panel-label">Enrollment Type</span><div id="summaryType" class="fw-semibold mt-1">Regular</div></div>
                      <div class="summary-box"><span class="panel-label">Student</span><div id="summaryStudent" class="fw-semibold mt-1">No student profile encoded</div></div>
                      <div class="summary-box"><span class="panel-label">Draft Load</span><div id="summaryLoad" class="fw-semibold mt-1">0 subjects - 0 units</div></div>
                      <div class="d-grid gap-2 mt-3">
                        <a href="programs.php" class="btn btn-outline-primary btn-sm">Open Programs Page</a>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-nav-tab="setup">Return to Setup</button>
                      </div>
                    </div></div>
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
    <script>
      const CURRENT_TERM = <?= $currentTermJson ?: '{}' ?>;
      const PROGRAM_OPTIONS = <?= $programOptionsJson ?: '[]' ?>;
      const SECTION_OPTIONS = <?= $sectionOptionsJson ?: '[]' ?>;
      const INITIAL_DRAFT = <?= $initialDraftJson ?: 'null' ?>;
      const DRAFT_TABLES_READY = <?= $draftTablesReady ? 'true' : 'false' ?>;
      const COLLEGE_LABEL = <?= json_encode($collegeName !== '' ? $collegeName : 'your assigned college', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      const offeringCache = {};
      let draftItems = [];
      let activeTab = 'setup';
      let handoffReviewed = false;
      let currentDraftId = INITIAL_DRAFT && Number(INITIAL_DRAFT.enrollment_id || 0) > 0 ? Number(INITIAL_DRAFT.enrollment_id) : 0;
      let currentDraftReference = INITIAL_DRAFT ? String(INITIAL_DRAFT.enrollment_reference || '') : '';
      let currentDraftStatus = INITIAL_DRAFT ? String(INITIAL_DRAFT.workflow_status || 'draft') : 'draft';

      function escapeHtml(value) {
        return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
      }
      function yearLabel(year) { return {1:'First Year',2:'Second Year',3:'Third Year',4:'Fourth Year',5:'Fifth Year'}[Number(year)] || `Year ${escapeHtml(year)}`; }
      function semesterLabel(semester) { return {1:'1st Semester',2:'2nd Semester',3:'Midyear'}[Number(semester)] || `Semester ${escapeHtml(semester)}`; }
      function normalizeEmailToken(value) {
        let token = String(value ?? '');
        if (typeof token.normalize === 'function') token = token.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        return token.toLowerCase().replace(/[^a-z0-9\s]/g, ' ').replace(/\s+/g, '');
      }
      function buildStudentEmail(firstName, lastName) {
        const emailLocal = `${normalizeEmailToken(firstName)}${normalizeEmailToken(lastName)}`;
        return emailLocal ? `${emailLocal}@sksu.edu.ph` : '';
      }
      function formatStudentName(lastName, firstName, middleName, suffix) {
        const trailing = [firstName, middleName, suffix].filter(Boolean).join(' ');
        if (lastName && trailing) return `${lastName}, ${trailing}`;
        return lastName || trailing || '';
      }
      function draftStatusLabel(status) {
        return {
          draft: 'Draft',
          submitted: 'Submitted to Registrar',
          returned: 'Returned to Program Chair',
          approved: 'Approved',
          posted: 'Posted',
          cancelled: 'Cancelled'
        }[String(status || '').toLowerCase()] || 'Draft';
      }
      function draftIsEditableStatus(status) {
        return ['draft', 'returned', ''].includes(String(status || '').toLowerCase());
      }
      function draftEditable() {
        return draftIsEditableStatus(currentDraftStatus);
      }
      function setDraftIdentity(detail) {
        currentDraftId = detail && Number(detail.enrollment_id || 0) > 0 ? Number(detail.enrollment_id) : 0;
        currentDraftReference = detail ? String(detail.enrollment_reference || '') : '';
        currentDraftStatus = detail ? String(detail.workflow_status || 'draft') : 'draft';
        if (typeof window.history.replaceState === 'function') {
          const nextUrl = currentDraftId > 0 ? `${window.location.pathname}?draft_id=${encodeURIComponent(currentDraftId)}` : window.location.pathname;
          window.history.replaceState({}, '', nextUrl);
        }
      }
      function setDraftNotice(message, type = 'info') {
        const $notice = $('#draftActionNotice');
        if (!message) {
          $notice.addClass('d-none').removeClass('alert-info alert-success alert-warning alert-danger').html('');
          return;
        }

        const classMap = {
          info: 'alert-info',
          success: 'alert-success',
          warning: 'alert-warning',
          danger: 'alert-danger'
        };

        $notice
          .removeClass('d-none alert-info alert-success alert-warning alert-danger')
          .addClass(classMap[type] || 'alert-info')
          .html(message);
      }
      function buildDraftPayload() {
        const firstYear = selectedMode() === 'first_year';
        return {
          enrollment_id: currentDraftId,
          program_id: selectedProgramId(),
          section_id: selectedSectionId(),
          enrollment_type: $('#enrollment_type').val() || 'regular',
          student_record_mode: selectedMode(),
          student_number: firstYear ? '' : $('#student_number').val().trim(),
          last_name: firstYear ? $('#fy_last_name').val().trim() : $('#existing_last_name').val().trim(),
          first_name: firstYear ? $('#fy_first_name').val().trim() : $('#existing_first_name').val().trim(),
          middle_name: firstYear ? $('#fy_middle_name').val().trim() : $('#existing_middle_name').val().trim(),
          suffix_name: firstYear ? $('#fy_suffix').val().trim() : $('#existing_suffix').val().trim(),
          sex: firstYear ? $('#fy_sex').val() : '',
          birthdate: firstYear ? $('#fy_birthdate').val() : '',
          email_address: firstYear ? $('#fy_email').val().trim() : $('#existing_email').val().trim(),
          contact_number: firstYear ? $('#fy_contact').val().trim() : '',
          verification_note: firstYear ? '' : $('#existing_note').val().trim(),
          chair_notes: $('#chair_notes').val().trim(),
          draft_items_json: JSON.stringify(draftItems.map((item) => ({
            offering_id: Number(item.offering_id) || 0
          })))
        };
      }
      async function hydrateDraftFromDetail(detail) {
        if (!detail) {
          return;
        }

        setDraftIdentity(detail);
        $('#program_id').val(String(detail.program_id || ''));
        populateSectionOptions(String(detail.program_id || ''));
        $('#section_id').val(String(detail.section_id || ''));
        $('#enrollment_type').val(String(detail.enrollment_type || 'regular'));

        const mode = String(detail.student_record_mode || 'first_year');
        $(`input[name="student_record_mode"][value="${mode}"]`).prop('checked', true);

        $('#fy_last_name').val(detail.last_name || '');
        $('#fy_first_name').val(detail.first_name || '');
        $('#fy_middle_name').val(detail.middle_name || '');
        $('#fy_suffix').val(detail.suffix_name || '');
        $('#fy_sex').val(detail.sex || '');
        $('#fy_birthdate').val(detail.birthdate || '');
        $('#fy_contact').val(detail.contact_number || '');
        $('#fy_email').val(detail.email_address || '');

        $('#existing_last_name').val(detail.last_name || '');
        $('#existing_first_name').val(detail.first_name || '');
        $('#existing_middle_name').val(detail.middle_name || '');
        $('#existing_suffix').val(detail.suffix_name || '');
        $('#student_number').val(detail.student_number || '');
        $('#existing_email').val(detail.email_address || '');
        $('#existing_note').val(detail.verification_note || '');
        $('#chair_notes').val(detail.chair_notes || '');

        updateStudentModeUI();
        syncSectionBasis();

        draftItems = Array.isArray(detail.subjects) ? detail.subjects.map((item) => ({
          offering_id: Number(item.offering_id) || 0,
          subject_code: item.subject_code || '',
          subject_description: item.descriptive_title || '',
          total_units: Number(item.units) || 0,
          schedule_text: item.schedule_text || 'Schedule pending',
          room_text: item.room_text || 'TBA',
          faculty_text: item.faculty_text || 'TBA',
          section_display: item.section_text || (detail.full_section || detail.section_name || 'Selected section')
        })) : [];

        renderDraftTable();
        renderSubjectPoolState('Loading offered subjects and schedules from Scheduler...');
        if (detail.section_id) {
          try {
            await ensureSectionOfferingsLoaded(detail.section_id);
            renderSubjectPool();
          } catch (error) {
            renderSubjectPoolState(error.message || 'Unable to load the section offerings.');
          }
        }

        if (!draftEditable()) {
          setDraftNotice(`This draft is already marked as <strong>${escapeHtml(draftStatusLabel(currentDraftStatus))}</strong>. It stays visible here for review, but editing is locked on the Program Chair side.`, 'info');
        } else if (currentDraftReference !== '') {
          setDraftNotice(`Loaded saved draft <strong>${escapeHtml(currentDraftReference)}</strong>.`, 'info');
        }

        updateSummary();
      }
      function getProgramRecord(programId) { return PROGRAM_OPTIONS.find((program) => Number(program.program_id) === Number(programId)) || null; }
      function getSectionRecord(sectionId) { return SECTION_OPTIONS.find((section) => Number(section.section_id) === Number(sectionId)) || null; }
      function sectionsForProgram(programId) { return SECTION_OPTIONS.filter((section) => Number(section.program_id) === Number(programId)); }
      function selectedMode() { return $('input[name="student_record_mode"]:checked').val() || 'first_year'; }
      function selectedProgramId() { return $('#program_id').val(); }
      function selectedSectionId() { return $('#section_id').val(); }
      function selectedSectionRecord() { return getSectionRecord(selectedSectionId()); }
      function selectedYearLevel() {
        const section = selectedSectionRecord();
        return section ? String(section.year_level || '') : '';
      }
      function currentSemesterLabel() { return CURRENT_TERM.term_text || semesterLabel(CURRENT_TERM.semester || ''); }
      function sectionBasisLabel() {
        const section = selectedSectionRecord();
        if (!section) return CURRENT_TERM.term_text || 'Current academic term';
        return `${yearLabel(section.year_level)} - ${semesterLabel(CURRENT_TERM.semester || section.semester || '')}`;
      }
      function invalidateHandoffReview() {
        handoffReviewed = false;
        $('#handoffPreview').addClass('d-none').html('');
        updateSummary();
      }
      function activateTab(tabKey) {
        activeTab = tabKey;
        $('#enrollmentTabs [data-tab]').each(function () { $(this).toggleClass('is-active', $(this).data('tab') === tabKey); });
        $('[data-panel]').each(function () { $(this).toggleClass('is-active', $(this).data('panel') === tabKey); });
      }
      function populateSectionOptions(programId) {
        const select = $('#section_id');
        const sectionRows = sectionsForProgram(programId);
        select.empty();
        if (!programId) {
          select.append('<option value="">Select program first</option>').prop('disabled', true);
          return;
        }
        if (!sectionRows.length) {
          select.append('<option value="">No current term section found</option>').prop('disabled', true);
          return;
        }
        select.append('<option value="">Select section</option>');
        sectionRows.forEach((section) => {
          const label = `${section.section_display} - ${yearLabel(section.year_level)}`;
          select.append(`<option value="${section.section_id}">${escapeHtml(label)}</option>`);
        });
        select.prop('disabled', false);
      }
      function syncSectionBasis() {
        const programId = selectedProgramId();
        const programRecord = getProgramRecord(programId);
        const section = selectedSectionRecord();
        $('#year_level_basis').val(section ? yearLabel(section.year_level) : '');
        $('#semester_basis_display').val(currentSemesterLabel());
        if (!programId) {
          $('#setupHint').html(`Select a program to load the current term sections under <strong>${escapeHtml(COLLEGE_LABEL)}</strong>.`);
          return;
        }
        if (!section) {
          const sectionRows = sectionsForProgram(programId);
          if (!sectionRows.length) $('#setupHint').html(`No current term section is available for <strong>${escapeHtml(programRecord ? programRecord.display_name : 'the selected program')}</strong>. Create or complete the section setup in Scheduler first.`);
          else $('#setupHint').html(`Select the exact section for <strong>${escapeHtml(programRecord ? programRecord.display_name : 'the selected program')}</strong>. Its assigned curriculum and current term basis will be used automatically.`);
          return;
        }
        $('#setupHint').html(`Using <strong>${escapeHtml(section.section_display)}</strong> as the basis. The draft load will read the live offered subjects and schedules already prepared in Scheduler for this section.`);
      }
      function syncAutoEmails() {
        $('#fy_email').val(buildStudentEmail($('#fy_first_name').val(), $('#fy_last_name').val()));
        $('#existing_email').val(buildStudentEmail($('#existing_first_name').val(), $('#existing_last_name').val()));
      }
      function currentOfferingRows() {
        const data = offeringCache[selectedSectionId()] || null;
        return data && Array.isArray(data.rows) ? data.rows : [];
      }
      function renderSubjectPoolState(message) {
        $('#subjectPoolStatus').text(message);
        $('#subjectPoolContainer').html('');
      }

      async function ensureSectionOfferingsLoaded(sectionId) {
        if (!sectionId) return null;
        if (offeringCache[sectionId]) return offeringCache[sectionId];
        const response = await fetch(`../backend/query_program_chair_enrollment.php?load_section_offerings=1&section_id=${encodeURIComponent(sectionId)}`, { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok || data.status !== 'ok') throw new Error(data.message || 'Unable to load the section offerings.');
        offeringCache[sectionId] = data;
        return data;
      }

      function renderSubjectPool() {
        const sectionId = selectedSectionId();
        if (!selectedProgramId() || !sectionId) {
          renderSubjectPoolState('Select a program and section to load offered subjects from Scheduler.');
          updateSummary();
          return;
        }
        const data = offeringCache[sectionId];
        if (!data) {
          renderSubjectPoolState('Loading offered subjects and schedules from Scheduler...');
          return;
        }
        const search = $('#subject_search').val().trim().toLowerCase();
        const rows = currentOfferingRows().filter((row) => {
          const haystack = [row.subject_code, row.subject_description, row.faculty_text, row.room_text, row.schedule_text].join(' ').toLowerCase();
          return !search || haystack.includes(search);
        });
        const scheduledCount = rows.filter((row) => Number(row.is_scheduled) === 1 || row.is_scheduled === true).length;
        $('#subjectPoolStatus').text(`${rows.length} offered subject${rows.length === 1 ? '' : 's'} loaded for ${data.meta.section_display}. ${scheduledCount} already reflect schedule blocks from Scheduler.`);
        if (!rows.length) {
          $('#subjectPoolContainer').html('<div class="draft-empty">No offered subject matched the current search for this section.</div>');
          updateSummary();
          return;
        }
        $('#subjectPoolContainer').html(rows.map((row) => {
          const added = draftItems.some((item) => Number(item.offering_id) === Number(row.offering_id));
          const scheduled = Number(row.is_scheduled) === 1 || row.is_scheduled === true;
          const locked = !draftEditable();
          return `<div class="subject-card${added ? ' added' : ''}"><div class="subject-meta"><span class="badge bg-label-primary">${escapeHtml(row.subject_code || 'Subject')}</span><span class="badge bg-label-info">${escapeHtml(row.total_units)} Units</span><span class="badge ${scheduled ? 'bg-label-success' : 'bg-label-warning'}">${scheduled ? 'Scheduled' : 'Schedule Pending'}</span></div><h6 class="subject-title">${escapeHtml(row.subject_description || 'Untitled subject')}</h6><div class="subject-detail-list"><div class="subject-detail-line"><strong>Section:</strong> ${escapeHtml(data.meta.section_display || row.full_section || row.section_name || 'Not set')}</div><div class="subject-detail-line"><strong>Schedule:</strong> ${escapeHtml(row.schedule_text || 'Schedule pending')}</div><div class="subject-detail-line"><strong>Room:</strong> ${escapeHtml(row.room_text || 'TBA')}</div><div class="subject-detail-line"><strong>Faculty:</strong> ${escapeHtml(row.faculty_text || 'TBA')}</div></div><div class="d-flex justify-content-between align-items-center gap-2 mt-auto"><small class="text-muted">${escapeHtml(yearLabel(data.meta.year_level || selectedYearLevel()))}</small><button type="button" class="btn ${added ? 'btn-success' : (locked ? 'btn-outline-secondary' : 'btn-outline-primary')} btn-sm add-subject-btn" data-offering-id="${escapeHtml(row.offering_id)}" ${(added || locked) ? 'disabled' : ''}>${added ? 'Added to Draft' : (locked ? 'Locked' : 'Add to Draft')}</button></div></div>`;
        }).join(''));
        updateSummary();
      }

      function addSubjectToDraft(offeringId) {
        if (!draftEditable()) return;
        const data = offeringCache[selectedSectionId()] || null;
        if (!data) return;
        const row = (data.rows || []).find((item) => Number(item.offering_id) === Number(offeringId));
        if (!row || draftItems.some((item) => Number(item.offering_id) === Number(offeringId))) return;
        draftItems.push({ offering_id: Number(row.offering_id), subject_code: row.subject_code || '', subject_description: row.subject_description || '', total_units: Number(row.total_units) || 0, schedule_text: row.schedule_text || 'Schedule pending', room_text: row.room_text || 'TBA', faculty_text: row.faculty_text || 'TBA', section_display: data.meta.section_display || row.full_section || row.section_name || 'Selected section' });
        renderDraftTable();
        renderSubjectPool();
      }

      function renderDraftTable() {
        if (!draftItems.length) {
          $('#draftLoadContainer').html('<div class="draft-empty">No offered subjects have been added to the draft yet.</div>');
          updateSummary();
          return;
        }
        const totalUnits = draftItems.reduce((sum, item) => sum + (Number(item.total_units) || 0), 0);
        $('#draftLoadContainer').html(`<div class="table-responsive"><table class="table draft-table mb-0"><thead><tr><th>Course</th><th>Schedule</th><th>Faculty / Room</th><th class="text-center">Units</th><th class="text-center">Action</th></tr></thead><tbody>${draftItems.map((item) => `<tr><td><strong>${escapeHtml(item.subject_code)}</strong><div>${escapeHtml(item.subject_description)}</div><div class="small text-muted">${escapeHtml(item.section_display)}</div></td><td>${escapeHtml(item.schedule_text)}</td><td><div>${escapeHtml(item.faculty_text)}</div><div class="small text-muted">${escapeHtml(item.room_text)}</div></td><td class="text-center">${escapeHtml(item.total_units)}</td><td class="text-center"><button type="button" class="btn ${draftEditable() ? 'btn-outline-danger' : 'btn-outline-secondary'} btn-sm remove-subject-btn" data-offering-id="${escapeHtml(item.offering_id)}" ${draftEditable() ? '' : 'disabled'}>${draftEditable() ? 'Remove' : 'Locked'}</button></td></tr>`).join('')}</tbody><tfoot><tr><th colspan="3" class="text-end">Total Units</th><th class="text-center">${escapeHtml(totalUnits)}</th><th></th></tr></tfoot></table></div>`);
        updateSummary();
      }

      function studentComplete() {
        if (selectedMode() === 'existing') return $('#student_number').val().trim() !== '' || ($('#existing_last_name').val().trim() !== '' && $('#existing_first_name').val().trim() !== '');
        return $('#fy_last_name').val().trim() !== '' && $('#fy_first_name').val().trim() !== '';
      }

      function studentLabel() {
        if (selectedMode() === 'existing') {
          const fullName = formatStudentName($('#existing_last_name').val().trim(), $('#existing_first_name').val().trim(), $('#existing_middle_name').val().trim(), $('#existing_suffix').val().trim());
          const studentNumber = $('#student_number').val().trim();
          if (studentNumber && fullName) return `${studentNumber} - ${fullName}`;
          return studentNumber || fullName || 'Existing student not yet identified';
        }
        const fullName = formatStudentName($('#fy_last_name').val().trim(), $('#fy_first_name').val().trim(), $('#fy_middle_name').val().trim(), $('#fy_suffix').val().trim());
        return fullName || 'First-year student not yet encoded';
      }

      function baseReadinessState() {
        return [
          { key: 'program', label: 'Program selected', done: !!selectedProgramId() },
          { key: 'section', label: 'Section selected', done: !!selectedSectionId() },
          { key: 'student', label: 'Student profile encoded', done: studentComplete() },
          { key: 'draft', label: 'At least one subject added to draft', done: draftItems.length > 0 }
        ];
      }

      function readinessState() {
        const baseItems = baseReadinessState();
        const baseDone = baseItems.every((item) => item.done);
        return [...baseItems, { key: 'review', label: 'Draft summary reviewed for registrar handoff', done: baseDone && handoffReviewed }];
      }

      function updateChecklist() {
        const items = readinessState();
        const doneCount = items.filter((item) => item.done).length;
        const percent = Math.round((doneCount / items.length) * 100);
        const allDone = doneCount === items.length;
        $('#readinessProgress').css('width', `${percent}%`);
        $('#readinessChecklist').html(items.map((item) => `<div class="check-item${item.done ? ' done' : ''}"><span class="check-icon"><i class="bx ${item.done ? 'bx-check' : 'bx-minus'}"></i></span><div><div class="fw-semibold">${escapeHtml(item.label)}</div><small>${item.done ? 'Completed' : 'Pending'}</small></div></div>`).join(''));
        $('#draftReadinessBadge').attr('class', `summary-pill ${allDone ? 'ready' : 'pending'} mb-3`).html(allDone ? '<i class="bx bx-check-circle"></i> Ready for registrar review' : '<i class="bx bx-time-five"></i> Needs completion');
      }
      function syncReadonlyFields() {
        const locked = currentDraftId > 0 && !draftEditable();
        $('#program_id, #section_id, #enrollment_type, #subject_search, #chair_notes, #reviewHandoffBtn').prop('disabled', locked);
        $('input[name="student_record_mode"]').prop('disabled', locked);
        $('#firstYearFields').find('input, select').prop('disabled', locked);
        $('#existingStudentFields').find('input, select').prop('disabled', locked);
      }
      function syncActionButtons() {
        const baseReady = baseReadinessState().every((item) => item.done);
        const submitReady = readinessState().every((item) => item.done);
        const editable = draftEditable();
        syncReadonlyFields();
        $('#saveDraftBtn').prop('disabled', !DRAFT_TABLES_READY || !editable || !selectedProgramId() || !selectedSectionId());
        $('#submitDraftBtn').prop('disabled', !DRAFT_TABLES_READY || !editable || currentDraftId <= 0 || !submitReady || !baseReady);
      }

      function stepCompletionState() {
        const baseItems = baseReadinessState();
        return { setup: baseItems[0].done && baseItems[1].done, student: baseItems[2].done, draft: baseItems[3].done, handoff: readinessState().every((item) => item.done) };
      }

      function updateTabStates() {
        const states = stepCompletionState();
        $('#enrollmentTabs [data-tab]').each(function () {
          const tabKey = $(this).data('tab');
          $(this).toggleClass('is-complete', !!states[tabKey]);
          $(this).toggleClass('is-active', tabKey === activeTab);
        });
      }

      function updateSummary() {
        const programRecord = getProgramRecord(selectedProgramId());
        const section = selectedSectionRecord();
        const totalUnits = draftItems.reduce((sum, item) => sum + (Number(item.total_units) || 0), 0);
        const referenceText = currentDraftReference !== '' ? currentDraftReference : 'Not saved yet';
        const statusText = currentDraftId > 0 ? draftStatusLabel(currentDraftStatus) : 'Draft builder only';
        $('#summaryProgram').text(programRecord ? programRecord.display_name : 'Not selected');
        $('#summarySection').text(section ? section.section_display : 'Not selected');
        $('#summaryBasis').text(section ? sectionBasisLabel() : (CURRENT_TERM.term_text || 'Current academic term'));
        $('#summaryType').text($('#enrollment_type option:selected').text() || 'Regular');
        $('#summaryStudent').text(studentLabel());
        $('#summaryLoad').text(`${draftItems.length} subject${draftItems.length === 1 ? '' : 's'} - ${totalUnits} units`);
        $('#summaryReference').text(referenceText);
        $('#summaryStatus').text(statusText);
        updateChecklist();
        updateTabStates();
        syncActionButtons();
      }

      function updateStudentModeUI() {
        const firstYear = selectedMode() === 'first_year';
        $('#firstYearFields').toggleClass('d-none', !firstYear);
        $('#existingStudentFields').toggleClass('d-none', firstYear);
        $('#studentModeGroup .student-mode-card').removeClass('active');
        $(`#studentModeGroup input[value="${selectedMode()}"]`).closest('.student-mode-card').addClass('active');
        syncAutoEmails();
        updateSummary();
      }

      function renderHandoffPreview() {
        handoffReviewed = true;
        const ready = readinessState().every((item) => item.done);
        const section = selectedSectionRecord();
        const studentEmail = selectedMode() === 'existing' ? $('#existing_email').val().trim() : $('#fy_email').val().trim();
        $('#handoffPreview').removeClass('d-none').html(`<div class="support-box"><div class="fw-semibold mb-2">${ready ? 'Draft is ready for registrar review.' : 'Draft still needs completion before registrar handoff.'}</div><div class="small mb-2"><strong>Program:</strong> ${escapeHtml($('#summaryProgram').text())}</div><div class="small mb-2"><strong>Section:</strong> ${escapeHtml(section ? section.section_display : 'Not selected')}</div><div class="small mb-2"><strong>Basis:</strong> ${escapeHtml($('#summaryBasis').text())}</div><div class="small mb-2"><strong>Student:</strong> ${escapeHtml(studentLabel())}</div><div class="small mb-2"><strong>Email:</strong> ${escapeHtml(studentEmail || 'Auto-generated email pending')}</div><div class="small mb-2"><strong>Draft Load:</strong> ${escapeHtml($('#summaryLoad').text())}</div><div class="small"><strong>Chair Notes:</strong> ${escapeHtml($('#chair_notes').val().trim() || 'No notes added')}</div></div>`);
        updateSummary();
      }
      async function saveDraft() {
        if (!DRAFT_TABLES_READY) {
          setDraftNotice('Enrollment draft tables are not ready yet. Run the provided SQL first.', 'warning');
          return;
        }

        if (!selectedProgramId() || !selectedSectionId()) {
          setDraftNotice('Select the program and section first before saving the draft.', 'warning');
          activateTab('setup');
          return;
        }

        const originalText = $('#saveDraftBtn').text();
        $('#saveDraftBtn').prop('disabled', true).text('Saving...');
        setDraftNotice('', 'info');

        try {
          const payload = buildDraftPayload();
          payload.save_draft = 1;
          const response = await fetch('../backend/query_program_chair_drafts.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams(payload)
          });
          const data = await response.json();

          if (!response.ok || data.status !== 'ok') {
            throw new Error(data.message || 'The draft could not be saved right now.');
          }

          await hydrateDraftFromDetail(data.detail || null);
          setDraftNotice(`Draft <strong>${escapeHtml(currentDraftReference || 'saved')}</strong> was saved successfully.`, 'success');
        } catch (error) {
          setDraftNotice(error.message || 'The draft could not be saved right now.', 'danger');
        } finally {
          $('#saveDraftBtn').text(originalText);
          updateSummary();
        }
      }
      async function submitDraft() {
        if (!DRAFT_TABLES_READY) {
          setDraftNotice('Enrollment draft tables are not ready yet. Run the provided SQL first.', 'warning');
          return;
        }

        if (currentDraftId <= 0) {
          setDraftNotice('Save the draft first before submitting it to registrar.', 'warning');
          return;
        }

        if (!readinessState().every((item) => item.done)) {
          setDraftNotice('Complete the checklist and review the handoff summary first before submitting to registrar.', 'warning');
          activateTab('handoff');
          return;
        }

        const originalText = $('#submitDraftBtn').text();
        $('#submitDraftBtn').prop('disabled', true).text('Submitting...');
        setDraftNotice('', 'info');

        try {
          const payload = new URLSearchParams({
            submit_draft: '1',
            enrollment_id: String(currentDraftId)
          });
          const response = await fetch('../backend/query_program_chair_drafts.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: payload
          });
          const data = await response.json();

          if (!response.ok || data.status !== 'ok') {
            throw new Error(data.message || 'The draft could not be submitted to registrar.');
          }

          await hydrateDraftFromDetail(data.detail || null);
          setDraftNotice(`Draft <strong>${escapeHtml(currentDraftReference || '')}</strong> is now in the registrar queue with status <strong>${escapeHtml(draftStatusLabel(currentDraftStatus))}</strong>.`, 'success');
        } catch (error) {
          setDraftNotice(error.message || 'The draft could not be submitted to registrar.', 'danger');
        } finally {
          $('#submitDraftBtn').text(originalText);
          updateSummary();
        }
      }

      function resetEnrollmentDraft() {
        setDraftIdentity(null);
        $('#program_id').val('');
        $('#section_id').html('<option value="">Select program first</option>').prop('disabled', true);
        $('#year_level_basis').val('');
        $('#semester_basis_display').val(CURRENT_TERM.term_text || '');
        $('#enrollment_type').val('regular');
        $('#subject_search').val('');
        $('#chair_notes').val('');
        $('input[type="text"], input[type="email"], input[type="date"]').not('#subject_search').val('');
        $('#fy_sex').val('');
        $('input[name="student_record_mode"][value="first_year"]').prop('checked', true);
        draftItems = [];
        handoffReviewed = false;
        updateStudentModeUI();
        syncSectionBasis();
        renderDraftTable();
        renderSubjectPoolState('Select a program and section to load offered subjects from Scheduler.');
        $('#handoffPreview').addClass('d-none').html('');
        setDraftNotice('', 'info');
        activateTab('setup');
        updateSummary();
      }

      $(document).ready(async function () {
        activateTab('setup');
        updateStudentModeUI();
        syncSectionBasis();
        renderDraftTable();
        renderSubjectPoolState('Select a program and section to load offered subjects from Scheduler.');
        updateSummary();

        if (INITIAL_DRAFT) {
          await hydrateDraftFromDetail(INITIAL_DRAFT);
        }

        $('#enrollmentTabs [data-tab]').on('click', function () { activateTab($(this).data('tab')); });
        $(document).on('click', '[data-nav-tab]', function () { activateTab($(this).data('navTab')); });
        $('#program_id').on('change', function () {
          if (!draftEditable()) {
            return;
          }
          populateSectionOptions($(this).val());
          $('#section_id').val('');
          $('#subject_search').val('');
          draftItems = [];
          syncSectionBasis();
          renderDraftTable();
          renderSubjectPoolState('Select a section to load offered subjects from Scheduler.');
          invalidateHandoffReview();
        });
        $('#section_id').on('change', async function () {
          if (!draftEditable()) {
            return;
          }
          $('#subject_search').val('');
          draftItems = [];
          syncSectionBasis();
          renderDraftTable();
          invalidateHandoffReview();
          if (!$(this).val()) {
            renderSubjectPoolState('Select a section to load offered subjects from Scheduler.');
            return;
          }
          renderSubjectPoolState('Loading offered subjects and schedules from Scheduler...');
          try {
            await ensureSectionOfferingsLoaded($(this).val());
            renderSubjectPool();
          } catch (error) {
            renderSubjectPoolState(error.message || 'Unable to load the section offerings.');
          }
        });
        $('#subject_search').on('input', function () { renderSubjectPool(); });
        $(document).on('click', '.add-subject-btn', function () { addSubjectToDraft($(this).data('offeringId')); });
        $(document).on('click', '.remove-subject-btn', function () {
          if (!draftEditable()) {
            return;
          }
          draftItems = draftItems.filter((item) => Number(item.offering_id) !== Number($(this).data('offeringId')));
          renderDraftTable();
          renderSubjectPool();
          invalidateHandoffReview();
        });
        $('input[name="student_record_mode"]').on('change', function () {
          updateStudentModeUI();
          invalidateHandoffReview();
        });
        $('#firstYearFields input, #firstYearFields select, #existingStudentFields input').on('input change', function () {
          syncAutoEmails();
          invalidateHandoffReview();
        });
        $('#enrollment_type, #chair_notes').on('input change', function () { invalidateHandoffReview(); });
        $('#reviewHandoffBtn').on('click', renderHandoffPreview);
        $('#saveDraftBtn').on('click', saveDraft);
        $('#submitDraftBtn').on('click', submitDraft);
        $('#resetDraftBtn').on('click', resetEnrollmentDraft);
      });
    </script>
  </body>
</html>
