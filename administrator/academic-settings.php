<?php
session_start();
ob_start();
include '../backend/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$academic_years = [];
$ayResult = mysqli_query($conn, "
    SELECT ay_id, ay
    FROM tbl_academic_years
    WHERE status = 'active'
    ORDER BY ay_id ASC
");
while ($row = mysqli_fetch_assoc($ayResult)) {
    $academic_years[] = $row;
}

$college_options = [];
$collegeResult = mysqli_query($conn, "
    SELECT college_id, college_code, college_name
    FROM tbl_college
    WHERE status = 'active'
    ORDER BY college_name ASC, college_code ASC
");
while ($row = mysqli_fetch_assoc($collegeResult)) {
    $code = trim((string)($row['college_code'] ?? ''));
    $name = trim((string)($row['college_name'] ?? ''));
    $college_options[] = [
        'college_id' => (int)($row['college_id'] ?? 0),
        'label' => $code !== '' ? ($code . ' - ' . $name) : $name
    ];
}
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
  <title>Academic Settings | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
  <style>
    .settings-hero {
      border: 0;
      overflow: hidden;
      background:
        radial-gradient(circle at top right, rgba(105, 108, 255, .2), transparent 34%),
        linear-gradient(135deg, #f7f9ff 0%, #ffffff 52%, #eef3ff 100%);
    }
    .settings-hero .settings-kicker {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      padding: .45rem .8rem;
      border-radius: 999px;
      background: rgba(105, 108, 255, .1);
      color: #696cff;
      font-size: .75rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
    .settings-overview-card {
      border: 1px solid #e7edf5;
      border-radius: 1rem;
      box-shadow: none;
      height: 100%;
    }
    .settings-overview-card .card-body {
      padding: 1.25rem;
    }
    .settings-stat-label {
      font-size: .75rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: #8592a3;
    }
    .settings-stat-value {
      font-size: 1.15rem;
      font-weight: 600;
      color: #233446;
    }
    .settings-tabs-card {
      border: 1px solid #e7edf5;
      border-radius: 1.15rem;
      overflow: hidden;
    }
    .settings-tab-nav {
      display: flex;
      flex-wrap: wrap;
      gap: .85rem;
      border-bottom: 0;
    }
    .settings-tab-nav .nav-item {
      flex: 1 1 16rem;
    }
    .settings-tab-nav .nav-link {
      width: 100%;
      height: 100%;
      border: 1px solid #e1e8f2;
      border-radius: 1rem;
      background: #f7f9fc;
      padding: 1rem 1.05rem;
      text-align: left;
      color: #566a7f;
      transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease, background-color .16s ease;
    }
    .settings-tab-nav .nav-link:hover {
      transform: translateY(-1px);
      border-color: #cfd9e8;
      box-shadow: 0 .45rem 1rem rgba(67, 89, 113, .08);
    }
    .settings-tab-nav .nav-link.active {
      color: #fff;
      border-color: #696cff;
      background: linear-gradient(135deg, #696cff 0%, #8791ff 100%);
      box-shadow: 0 .85rem 1.6rem rgba(105, 108, 255, .22);
    }
    .settings-tab-title {
      display: block;
      font-size: .95rem;
      font-weight: 600;
      margin-bottom: .15rem;
    }
    .settings-tab-copy {
      display: block;
      font-size: .8rem;
      opacity: .85;
      line-height: 1.45;
    }
    .settings-pane {
      border: 1px solid #e7edf5;
      border-radius: 1rem;
      padding: 1.35rem;
      background: #fff;
      height: 100%;
    }
    .settings-pane-soft {
      background: linear-gradient(180deg, #fbfcff 0%, #f4f7ff 100%);
    }
    .settings-kicker-sm {
      display: inline-block;
      font-size: .75rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: #8592a3;
      margin-bottom: .45rem;
    }
    .settings-impact-list {
      display: grid;
      gap: .95rem;
    }
    .settings-impact-item {
      display: flex;
      align-items: flex-start;
      gap: .8rem;
    }
    .settings-impact-icon {
      width: 2.35rem;
      height: 2.35rem;
      border-radius: .8rem;
      background: rgba(105, 108, 255, .12);
      color: #696cff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.1rem;
      flex: 0 0 auto;
    }
    .settings-impact-item h6 {
      font-size: .92rem;
      margin-bottom: .15rem;
    }
    .settings-impact-item p {
      margin-bottom: 0;
      color: #8592a3;
      font-size: .85rem;
    }
    .settings-live-pill {
      display: inline-flex;
      align-items: center;
      gap: .4rem;
      padding: .4rem .75rem;
      border-radius: 999px;
      background: rgba(105, 108, 255, .1);
      color: #696cff;
      font-size: .78rem;
      font-weight: 600;
    }
    .settings-save-row {
      display: flex;
      justify-content: flex-end;
      margin-top: 1.5rem;
    }
    .settings-summary-list {
      display: grid;
      gap: .85rem;
    }
    .settings-summary-item {
      padding: .85rem 1rem;
      border-radius: .9rem;
      background: #f8faff;
      border: 1px solid #e6edf7;
    }
    .settings-summary-item strong {
      display: block;
      margin-bottom: .2rem;
      color: #233446;
    }
    .policy-column {
      display: grid;
      gap: 1.15rem;
    }
    .policy-day-grid { display:flex; flex-wrap:wrap; gap:.5rem; }
    .policy-day-toggle {
      min-width:2.75rem;
      user-select:none;
      border-radius:.55rem;
      box-shadow:none !important;
      transition:background-color .15s ease, border-color .15s ease, color .15s ease;
    }
    .policy-day-toggle,
    .policy-day-toggle.policy-day-unselected,
    .policy-day-toggle.policy-day-unselected:hover,
    .policy-day-toggle.policy-day-unselected:focus,
    .policy-day-toggle.policy-day-unselected:active {
      background:#fff;
      border-color:#d0d7e2;
      color:#6b7280;
      box-shadow:none !important;
    }
    .policy-day-toggle.policy-day-selected,
    .policy-day-toggle.policy-day-selected:hover,
    .policy-day-toggle.policy-day-selected:focus,
    .policy-day-toggle.policy-day-selected:active {
      background:#696cff;
      border-color:#696cff;
      color:#fff;
      box-shadow:none !important;
    }
    .blocked-time-row { border:1px solid #e2e8f0; border-radius:.75rem; padding:.85rem; background:#f9fbff; }
    .blocked-time-empty { border:1px dashed #cbd5e1; border-radius:.75rem; padding:1rem; text-align:center; color:#6b7280; }
    .override-policy-list { display:grid; gap:.75rem; max-height:16rem; overflow:auto; padding-right:.25rem; }
    .override-policy-item { border:1px solid #e2e8f0; border-radius:.85rem; padding:1rem; background:#fff; }
    .override-policy-empty { border:1px dashed #cbd5e1; border-radius:.85rem; padding:1rem; text-align:center; color:#6b7280; }
    .signatory-grid { display:grid; gap:1rem; }
    .signatory-card {
      border: 1px solid #e6edf7;
      border-radius: 1rem;
      padding: 1rem;
      background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
    }
    .signatory-card h6 {
      margin-bottom: .25rem;
      color: #233446;
    }
    .signatory-card p {
      margin-bottom: 0;
      color: #8592a3;
      font-size: .84rem;
    }
    .signatory-preview-stack {
      display: grid;
      gap: .85rem;
    }
    .signatory-preview-item {
      padding: .85rem 1rem;
      border-radius: .9rem;
      border: 1px solid #e6edf7;
      background: #fff;
    }
    .signatory-preview-label {
      font-size: .73rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      color: #8592a3;
      margin-bottom: .2rem;
    }
    .signatory-preview-name {
      font-weight: 700;
      color: #233446;
    }
    .signatory-preview-title {
      color: #6b7280;
      font-size: .85rem;
    }
    @media (max-width: 991.98px) {
      .settings-tab-nav .nav-item {
        flex-basis: 100%;
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
          <div class="card shadow-sm settings-hero mb-4">
            <div class="card-body p-4 p-lg-5">
              <span class="settings-kicker mb-3"><i class="bx bx-slider-alt"></i> Settings workspace</span>
              <div class="row align-items-end g-4">
                <div class="col-lg-8">
                  <h4 class="fw-bold mb-2">Academic Settings</h4>
                  <p class="text-muted mb-0">Manage academic term configuration, the global default scheduling policy, and college-specific override policies from focused tabs.</p>
                </div>
                <div class="col-lg-4">
                  <div class="settings-live-pill"><i class="bx bx-check-circle"></i> Save each tab independently</div>
                </div>
              </div>
            </div>
          </div>

          <div class="row g-4 mb-4">
            <div class="col-xl-4">
              <div class="card settings-overview-card">
                <div class="card-body">
                  <div class="settings-stat-label mb-2">Active Academic Term</div>
                  <div class="settings-stat-value mb-1" id="currentAcademicYear">-</div>
                  <div class="text-muted" id="currentSemester">-</div>
                  <div class="settings-summary-list mt-4">
                    <div class="settings-summary-item">
                      <strong>What this controls</strong>
                      Dashboards, scheduler defaults, workload monitoring, and reports.
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-xl-4">
              <div class="card settings-overview-card">
                <div class="card-body">
                  <div class="settings-stat-label mb-2">Current Global Policy</div>
                  <div class="settings-summary-list">
                    <div class="settings-summary-item">
                      <strong>Window</strong>
                      <span id="currentScheduleWindow">-</span>
                    </div>
                    <div class="settings-summary-item">
                      <strong>Blocked Days</strong>
                      <span id="currentBlockedDays">-</span>
                    </div>
                    <div class="settings-summary-item">
                      <strong>Blocked Times</strong>
                      <span id="currentBlockedTimes">-</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="col-xl-4">
              <div class="card settings-overview-card">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                      <div class="settings-stat-label mb-2">College Overrides</div>
                      <div class="text-muted small">Colleges with their own enforced scheduling rules.</div>
                    </div>
                    <span class="badge bg-label-primary" id="overrideCollegeCount">0</span>
                  </div>
                  <div class="override-policy-list" id="overrideCollegesList">
                    <div class="override-policy-empty">No colleges are currently using college-specific override schedules.</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="card shadow-sm settings-tabs-card">
            <div class="card-header bg-transparent border-0 pb-0">
              <div class="d-flex flex-column flex-xl-row justify-content-between gap-3">
                <div>
                  <h5 class="mb-1">Configuration Tabs</h5>
                  <p class="text-muted mb-0">Open a focused tab for the setting type you want to review or update.</p>
                </div>
                <div class="text-muted small align-self-xl-center">Academic term, scheduling policies, and report signatories stay organized in separate tabs.</div>
              </div>

              <ul class="nav settings-tab-nav mt-4" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active" id="term-tab" data-bs-toggle="tab" data-bs-target="#term-pane" type="button" role="tab" aria-controls="term-pane" aria-selected="true">
                    <span class="settings-tab-title">Academic and Semester Setting</span>
                    <span class="settings-tab-copy">Choose the live academic year and semester used throughout the system.</span>
                  </button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="global-policy-tab" data-bs-toggle="tab" data-bs-target="#global-policy-pane" type="button" role="tab" aria-controls="global-policy-pane" aria-selected="false">
                    <span class="settings-tab-title">Global Default Policy</span>
                    <span class="settings-tab-copy">Set the default scheduling window, blocked days, and blocked time ranges.</span>
                  </button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="college-policy-tab" data-bs-toggle="tab" data-bs-target="#college-policy-pane" type="button" role="tab" aria-controls="college-policy-pane" aria-selected="false">
                    <span class="settings-tab-title">College Override Policy</span>
                    <span class="settings-tab-copy">Review or enforce a college-specific scheduling policy when needed.</span>
                  </button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="signatory-tab" data-bs-toggle="tab" data-bs-target="#signatory-pane" type="button" role="tab" aria-controls="signatory-pane" aria-selected="false">
                    <span class="settings-tab-title">Report Signatories</span>
                    <span class="settings-tab-copy">Manage the global signatories used in institutional printouts and approval pages.</span>
                  </button>
                </li>
              </ul>
            </div>

            <div class="card-body pt-4">
              <div class="tab-content">
                <div class="tab-pane fade show active" id="term-pane" role="tabpanel" aria-labelledby="term-tab">
                  <div class="row g-4">
                    <div class="col-xl-8">
                      <div class="settings-pane">
                        <span class="settings-kicker-sm">Academic Calendar</span>
                        <h5 class="mb-2">Academic and Semester Setting</h5>
                        <p class="text-muted mb-4">Select the academic year and semester that should be treated as the active term across the platform.</p>

                        <div class="row g-3">
                          <div class="col-lg-6">
                            <label class="form-label" for="academicYearSelect">Academic Year</label>
                            <select class="form-select" id="academicYearSelect">
                              <option value="">-- Select Academic Year --</option>
                              <?php foreach ($academic_years as $ay): ?>
                                <option value="<?= (int)$ay['ay_id'] ?>"><?= htmlspecialchars($ay['ay']) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="col-lg-6">
                            <label class="form-label d-block">Semester</label>
                            <div class="form-check"><input class="form-check-input" type="radio" name="semester" value="1" id="semester_1"><label class="form-check-label" for="semester_1">1st Semester</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" name="semester" value="2" id="semester_2"><label class="form-check-label" for="semester_2">2nd Semester</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" name="semester" value="3" id="semester_3"><label class="form-check-label" for="semester_3">Midyear</label></div>
                          </div>
                        </div>

                        <div class="alert alert-warning mt-4 mb-0">Changing the active term updates dashboards, workloads, scheduling views, and academic reports.</div>

                        <div class="settings-save-row">
                          <button class="btn btn-primary" id="saveTermBtn">Save Academic Term</button>
                        </div>
                      </div>
                    </div>
                    <div class="col-xl-4">
                      <div class="settings-pane settings-pane-soft">
                        <span class="settings-kicker-sm">Impact Preview</span>
                        <h5 class="mb-3">This update affects</h5>
                        <div class="settings-impact-list">
                          <div class="settings-impact-item">
                            <div class="settings-impact-icon"><i class="bx bx-grid-alt"></i></div>
                            <div>
                              <h6>Operational dashboards</h6>
                              <p>Counts, summaries, and academic monitoring widgets use the current term immediately.</p>
                            </div>
                          </div>
                          <div class="settings-impact-item">
                            <div class="settings-impact-icon"><i class="bx bx-calendar-event"></i></div>
                            <div>
                              <h6>Scheduling workflows</h6>
                              <p>Scheduling screens and generated offerings inherit the live academic context.</p>
                            </div>
                          </div>
                          <div class="settings-impact-item">
                            <div class="settings-impact-icon"><i class="bx bx-spreadsheet"></i></div>
                            <div>
                              <h6>Reports and workloads</h6>
                              <p>Academic and workload reports align to the academic year and semester selected here.</p>
                            </div>
                          </div>
                        </div>

                        <div class="settings-summary-item mt-4">
                          <strong>Current live term</strong>
                          <div class="fw-semibold mb-1" id="currentAcademicYearPreview">-</div>
                          <div class="text-muted" id="currentSemesterPreview">-</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="tab-pane fade" id="global-policy-pane" role="tabpanel" aria-labelledby="global-policy-tab">
                  <div class="settings-pane">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-start mb-4">
                      <div>
                        <span class="settings-kicker-sm">Default Scheduling Rules</span>
                        <h5 class="mb-1">Global Default Policy</h5>
                        <p class="text-muted mb-0">These rules are used by default for all colleges unless a college override is enabled.</p>
                      </div>
                      <button type="button" class="btn btn-outline-primary btn-sm" id="addGlobalBlockedTimeBtn">Add Blocked Time</button>
                    </div>

                    <div class="row g-4">
                      <div class="col-xl-5">
                        <div class="policy-column">
                          <div>
                            <label class="form-label" for="globalStart">Allowed Day Start</label>
                            <input type="time" class="form-control" id="globalStart" step="1800">
                          </div>
                          <div>
                            <label class="form-label" for="globalEnd">Allowed Day End</label>
                            <input type="time" class="form-control" id="globalEnd" step="1800">
                          </div>
                          <div>
                            <label class="form-label d-block">Blocked Days</label>
                            <div class="policy-day-grid" id="globalDays"></div>
                          </div>
                        </div>
                      </div>
                      <div class="col-xl-7">
                        <label class="form-label d-block">Blocked Time Ranges</label>
                        <div id="globalTimes" class="d-grid gap-3"></div>
                      </div>
                    </div>

                    <div class="settings-save-row">
                      <button class="btn btn-primary" id="saveGlobalBtn">Save Global Policy</button>
                    </div>
                  </div>
                </div>

                <div class="tab-pane fade" id="college-policy-pane" role="tabpanel" aria-labelledby="college-policy-tab">
                  <div class="settings-pane">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-start mb-4">
                      <div>
                        <span class="settings-kicker-sm">College-Specific Rules</span>
                        <h5 class="mb-1">College Override Policy</h5>
                        <p class="text-muted mb-0">Enable this only when a specific college needs its own scheduling window or blocked schedule rules.</p>
                      </div>
                      <button type="button" class="btn btn-outline-primary btn-sm" id="addCollegeBlockedTimeBtn">Add Blocked Time</button>
                    </div>

                    <div class="row g-4 mb-4">
                      <div class="col-xl-8">
                        <label class="form-label" for="collegeSelect">Selected College</label>
                        <select class="form-select" id="collegeSelect">
                          <option value="">-- Select College --</option>
                          <?php foreach ($college_options as $college): ?>
                            <option value="<?= (int)$college['college_id'] ?>"><?= htmlspecialchars($college['label']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-xl-4 d-flex align-items-end">
                        <div class="form-check form-switch">
                          <input class="form-check-input" type="checkbox" id="collegeOverrideEnabled">
                          <label class="form-check-label" for="collegeOverrideEnabled">Enable college override</label>
                        </div>
                      </div>
                    </div>

                    <div class="row g-4 mb-4">
                      <div class="col-xl-4">
                        <div class="settings-summary-item h-100">
                          <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                            <strong class="mb-0">Effective Policy Source</strong>
                            <span class="badge bg-label-info" id="collegeEffectiveBadge">Using Global Default</span>
                          </div>
                          <div class="text-muted small" id="collegeEffectiveSource">Global default</div>
                        </div>
                      </div>
                      <div class="col-xl-8">
                        <div class="settings-summary-list">
                          <div class="settings-summary-item">
                            <strong>Effective Window</strong>
                            <span id="collegeEffectiveWindow">-</span>
                          </div>
                          <div class="settings-summary-item">
                            <strong>Blocked Days</strong>
                            <span id="collegeEffectiveBlockedDays">-</span>
                          </div>
                          <div class="settings-summary-item">
                            <strong>Blocked Times</strong>
                            <span id="collegeEffectiveBlockedTimes">-</span>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="alert alert-info mb-4" id="collegeNotice">Select a college to review or override its schedule policy.</div>

                    <div class="row g-4">
                      <div class="col-xl-5">
                        <div class="policy-column">
                          <div>
                            <label class="form-label" for="collegeStart">Allowed Day Start</label>
                            <input type="time" class="form-control" id="collegeStart" step="1800">
                          </div>
                          <div>
                            <label class="form-label" for="collegeEnd">Allowed Day End</label>
                            <input type="time" class="form-control" id="collegeEnd" step="1800">
                          </div>
                          <div>
                            <label class="form-label d-block">Blocked Days</label>
                            <div class="policy-day-grid" id="collegeDays"></div>
                          </div>
                        </div>
                      </div>
                      <div class="col-xl-7">
                        <label class="form-label d-block">Blocked Time Ranges</label>
                        <div id="collegeTimes" class="d-grid gap-3"></div>
                      </div>
                    </div>

                    <div class="settings-save-row">
                      <button class="btn btn-primary" id="saveCollegeBtn">Save College Override</button>
                    </div>
                  </div>
                </div>

                <div class="tab-pane fade" id="signatory-pane" role="tabpanel" aria-labelledby="signatory-tab">
                  <div class="row g-4">
                    <div class="col-xl-8">
                      <div class="settings-pane">
                        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-start mb-4">
                          <div>
                            <span class="settings-kicker-sm">Global Print Signatories</span>
                            <h5 class="mb-1">Report Signatories</h5>
                            <p class="text-muted mb-0">Save the institution-wide signatories that appear on generated approval sheets and PDF signatory pages.</p>
                          </div>
                        </div>

                        <div class="alert alert-info mb-4">
                          <strong>Prepared by</strong> is intentionally excluded here.
                          It will be handled later in the scheduler college settings module so each college can manage its own preparer.
                        </div>

                        <div class="signatory-grid">
                          <div class="signatory-card">
                            <h6>Checked by (Left)</h6>
                            <p>Use this for the first checker shown on the signatory page.</p>
                            <div class="row g-3 mt-1">
                              <div class="col-lg-6">
                                <label class="form-label" for="checkedByLeftName">Name</label>
                                <input type="text" class="form-control" id="checkedByLeftName" maxlength="150">
                              </div>
                              <div class="col-lg-6">
                                <label class="form-label" for="checkedByLeftTitle">Title / Position</label>
                                <input type="text" class="form-control" id="checkedByLeftTitle" maxlength="150">
                              </div>
                            </div>
                          </div>

                          <div class="signatory-card">
                            <h6>Checked by (Right)</h6>
                            <p>Use this for the second checker shown beside the first checked-by entry.</p>
                            <div class="row g-3 mt-1">
                              <div class="col-lg-6">
                                <label class="form-label" for="checkedByRightName">Name</label>
                                <input type="text" class="form-control" id="checkedByRightName" maxlength="150">
                              </div>
                              <div class="col-lg-6">
                                <label class="form-label" for="checkedByRightTitle">Title / Position</label>
                                <input type="text" class="form-control" id="checkedByRightTitle" maxlength="150">
                              </div>
                            </div>
                          </div>

                          <div class="signatory-card">
                            <h6>Recommending Approval</h6>
                            <p>Use this for the signatory who recommends the report for approval.</p>
                            <div class="row g-3 mt-1">
                              <div class="col-lg-6">
                                <label class="form-label" for="recommendingApprovalName">Name</label>
                                <input type="text" class="form-control" id="recommendingApprovalName" maxlength="150">
                              </div>
                              <div class="col-lg-6">
                                <label class="form-label" for="recommendingApprovalTitle">Title / Position</label>
                                <input type="text" class="form-control" id="recommendingApprovalTitle" maxlength="150">
                              </div>
                            </div>
                          </div>

                          <div class="signatory-card">
                            <h6>Approved by</h6>
                            <p>Use this for the final approving authority shown on the printout.</p>
                            <div class="row g-3 mt-1">
                              <div class="col-lg-6">
                                <label class="form-label" for="approvedByName">Name</label>
                                <input type="text" class="form-control" id="approvedByName" maxlength="150">
                              </div>
                              <div class="col-lg-6">
                                <label class="form-label" for="approvedByTitle">Title / Position</label>
                                <input type="text" class="form-control" id="approvedByTitle" maxlength="150">
                              </div>
                            </div>
                          </div>
                        </div>

                        <div class="settings-save-row">
                          <button class="btn btn-primary" id="saveSignatoriesBtn">Save Report Signatories</button>
                        </div>
                      </div>
                    </div>

                    <div class="col-xl-4">
                      <div class="settings-pane settings-pane-soft">
                        <span class="settings-kicker-sm">Preview Order</span>
                        <h5 class="mb-3">Current signatory stack</h5>
                        <div class="signatory-preview-stack">
                          <div class="signatory-preview-item">
                            <div class="signatory-preview-label">Checked by (Left)</div>
                            <div class="signatory-preview-name" id="checkedByLeftPreviewName">-</div>
                            <div class="signatory-preview-title" id="checkedByLeftPreviewTitle">-</div>
                          </div>
                          <div class="signatory-preview-item">
                            <div class="signatory-preview-label">Checked by (Right)</div>
                            <div class="signatory-preview-name" id="checkedByRightPreviewName">-</div>
                            <div class="signatory-preview-title" id="checkedByRightPreviewTitle">-</div>
                          </div>
                          <div class="signatory-preview-item">
                            <div class="signatory-preview-label">Recommending Approval</div>
                            <div class="signatory-preview-name" id="recommendingApprovalPreviewName">-</div>
                            <div class="signatory-preview-title" id="recommendingApprovalPreviewTitle">-</div>
                          </div>
                          <div class="signatory-preview-item">
                            <div class="signatory-preview-label">Approved by</div>
                            <div class="signatory-preview-name" id="approvedByPreviewName">-</div>
                            <div class="signatory-preview-title" id="approvedByPreviewTitle">-</div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <?php include '../footer.php'; ?>
      </div>
    </div>
  </div>
</div>

<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/libs/popper/popper.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const DAYS = ["M","T","W","Th","F","S"];
const COLLEGES = <?= json_encode($college_options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const DEFAULT_COLLEGE_ID = COLLEGES.length ? Number(COLLEGES[0].college_id) : 0;

function normalizePolicy(policy) {
  const data = policy || {};
  return {
    day_start_input: String(data.day_start_input || "07:30"),
    day_end_input: String(data.day_end_input || "17:30"),
    window_label: String(data.window_label || "7:30 AM to 5:30 PM"),
    blocked_days: Array.isArray(data.blocked_days) ? data.blocked_days.filter(day => DAYS.includes(day)) : [],
    blocked_days_label: String(data.blocked_days_label || "None"),
    blocked_times: Array.isArray(data.blocked_times) ? data.blocked_times.map(row => ({
      start_input: String(row.start_input || ""),
      end_input: String(row.end_input || "")
    })) : [],
    blocked_times_label: String(data.blocked_times_label || "None"),
    is_override_enabled: Boolean(data.is_override_enabled),
    source_label: String(data.source_label || "Global default")
  };
}

function normalizeSignatorySettings(settings) {
  const source = settings || {};
  const defaults = {
    checked_by_left: { label: "Checked by (Left)" },
    checked_by_right: { label: "Checked by (Right)" },
    recommending_approval: { label: "Recommending Approval" },
    approved_by: { label: "Approved by" }
  };

  const normalized = {};
  Object.keys(defaults).forEach(slotCode => {
    const row = source[slotCode] || {};
    normalized[slotCode] = {
      slot_code: slotCode,
      label: String(row.label || defaults[slotCode].label),
      signatory_name: String(row.signatory_name || ""),
      signatory_title: String(row.signatory_title || "")
    };
  });

  return normalized;
}

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, char => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    "\"": "&quot;",
    "'": "&#39;"
  }[char]));
}

function setText(targetId, value) {
  const target = document.getElementById(targetId);
  if (target) {
    target.innerText = value;
  }
}

function setInputValue(targetId, value) {
  const target = document.getElementById(targetId);
  if (target) {
    target.value = value;
  }
}

function renderDays(targetId, name, selected) {
  const set = new Set(selected || []);
  document.getElementById(targetId).innerHTML = DAYS.map(day => `
    <button
      type="button"
      class="btn btn-sm policy-day-toggle ${name}-day ${set.has(day) ? "policy-day-selected" : "policy-day-unselected"}"
      data-prefix="${name}"
      data-day="${day}"
      aria-pressed="${set.has(day) ? "true" : "false"}"
    >${day}</button>
  `).join("");
}

function timeRow(prefix, start = "", end = "") {
  return `
    <div class="blocked-time-row ${prefix}-time-row">
      <div class="row g-3 align-items-end">
        <div class="col-md-5"><label class="form-label">Start</label><input type="time" class="form-control ${prefix}-time-start" step="1800" value="${start}"></div>
        <div class="col-md-5"><label class="form-label">End</label><input type="time" class="form-control ${prefix}-time-end" step="1800" value="${end}"></div>
        <div class="col-md-2 d-grid"><button type="button" class="btn btn-outline-danger remove-time" data-prefix="${prefix}">Remove</button></div>
      </div>
    </div>
  `;
}

function renderTimes(targetId, prefix, rows) {
  const target = document.getElementById(targetId);
  if (!rows || rows.length === 0) {
    target.innerHTML = `<div class="blocked-time-empty">No blocked time ranges configured.</div>`;
    return;
  }
  target.innerHTML = rows.map(row => timeRow(prefix, row.start_input || "", row.end_input || "")).join("");
}

function appendTime(targetId, prefix) {
  const target = document.getElementById(targetId);
  const empty = target.querySelector(".blocked-time-empty");
  if (empty) target.innerHTML = "";
  target.insertAdjacentHTML("beforeend", timeRow(prefix));
}

function collectDays(prefix) {
  return Array.from(document.querySelectorAll(`.policy-day-toggle[data-prefix="${prefix}"][aria-pressed="true"]`)).map(button => String(button.dataset.day || ""));
}

function collectTimes(prefix) {
  return Array.from(document.querySelectorAll(`.${prefix}-time-row`)).map(row => ({
    start: String(row.querySelector(`.${prefix}-time-start`)?.value || "").trim(),
    end: String(row.querySelector(`.${prefix}-time-end`)?.value || "").trim()
  })).filter(row => row.start !== "" || row.end !== "");
}

function setSemester(semester) {
  document.querySelectorAll('input[name="semester"]').forEach(input => {
    input.checked = Number(input.value) === Number(semester);
  });
}

function setPolicy(prefix, policy) {
  const data = normalizePolicy(policy);
  document.getElementById(prefix + "Start").value = data.day_start_input;
  document.getElementById(prefix + "End").value = data.day_end_input;
  renderDays(prefix + "Days", prefix, data.blocked_days);
  renderTimes(prefix + "Times", prefix, data.blocked_times);
}

function validatePolicy(payload) {
  if (!payload.schedule_day_start || !payload.schedule_day_end) return "Please complete the daily scheduling window.";
  if (payload.schedule_day_end <= payload.schedule_day_start) return "Allowed day end must be later than allowed day start.";
  const sorted = [...payload.blocked_times].sort((a, b) => a.start === b.start ? a.end.localeCompare(b.end) : a.start.localeCompare(b.start));
  for (let i = 0; i < sorted.length; i++) {
    if (!sorted[i].start || !sorted[i].end) return "Each blocked time range must include both start and end time.";
    if (sorted[i].end <= sorted[i].start) return "Each blocked time range must end later than it starts.";
    if (sorted[i].start < payload.schedule_day_start || sorted[i].end > payload.schedule_day_end) return "Blocked time ranges must stay inside the allowed daily scheduling window.";
    if (i > 0 && sorted[i].start < sorted[i - 1].end) return "Blocked time ranges must not overlap each other.";
  }
  return "";
}

function collegeLabel(id) {
  const row = COLLEGES.find(item => Number(item.college_id) === Number(id));
  return row ? row.label : "-";
}

function renderOverrideColleges(items) {
  const rows = Array.isArray(items) ? items : [];
  const target = document.getElementById("overrideCollegesList");
  document.getElementById("overrideCollegeCount").innerText = String(rows.length);
  if (rows.length === 0) {
    target.innerHTML = `<div class="override-policy-empty">No colleges are currently using college-specific override schedules.</div>`;
    return;
  }
  target.innerHTML = rows.map(item => {
    const policy = normalizePolicy(item);
    const label = escapeHtml(item.college_label || collegeLabel(item.college_id));
    return `
      <div class="override-policy-item">
        <div class="d-flex justify-content-between align-items-start gap-3">
          <div class="fw-semibold">${label}</div>
          <span class="badge bg-label-warning">Override</span>
        </div>
        <div class="small text-muted mt-2">Window: ${escapeHtml(policy.window_label)}</div>
        <div class="small text-muted mt-1">Blocked Days: ${escapeHtml(policy.blocked_days_label)}</div>
        <div class="small text-muted mt-1">Blocked Times: ${escapeHtml(policy.blocked_times_label)}</div>
      </div>
    `;
  }).join("");
}

function buildPayload(prefix) {
  return {
    schedule_day_start: String(document.getElementById(prefix + "Start").value || "").trim(),
    schedule_day_end: String(document.getElementById(prefix + "End").value || "").trim(),
    blocked_days: collectDays(prefix),
    blocked_times: collectTimes(prefix)
  };
}

function postSettings(formData, fallbackMessage) {
  return fetch("../backend/save_academic_settings.php", { method: "POST", body: formData })
    .then(res => res.json())
    .catch(() => ({
      status: "error",
      message: fallbackMessage
    }));
}

function updateTermSnapshot(academicYear, semester) {
  const yearLabel = academicYear || "-";
  const semesterLabel = semester || "-";
  setText("currentAcademicYear", yearLabel);
  setText("currentSemester", semesterLabel);
  setText("currentAcademicYearPreview", yearLabel);
  setText("currentSemesterPreview", semesterLabel);
}

function syncSignatoryPreviewFromInputs() {
  setText("checkedByLeftPreviewName", String(document.getElementById("checkedByLeftName")?.value || "").trim() || "-");
  setText("checkedByLeftPreviewTitle", String(document.getElementById("checkedByLeftTitle")?.value || "").trim() || "-");
  setText("checkedByRightPreviewName", String(document.getElementById("checkedByRightName")?.value || "").trim() || "-");
  setText("checkedByRightPreviewTitle", String(document.getElementById("checkedByRightTitle")?.value || "").trim() || "-");
  setText("recommendingApprovalPreviewName", String(document.getElementById("recommendingApprovalName")?.value || "").trim() || "-");
  setText("recommendingApprovalPreviewTitle", String(document.getElementById("recommendingApprovalTitle")?.value || "").trim() || "-");
  setText("approvedByPreviewName", String(document.getElementById("approvedByName")?.value || "").trim() || "-");
  setText("approvedByPreviewTitle", String(document.getElementById("approvedByTitle")?.value || "").trim() || "-");
}

function setSignatoryForm(settings) {
  const data = normalizeSignatorySettings(settings);

  setInputValue("checkedByLeftName", data.checked_by_left.signatory_name);
  setInputValue("checkedByLeftTitle", data.checked_by_left.signatory_title);
  setInputValue("checkedByRightName", data.checked_by_right.signatory_name);
  setInputValue("checkedByRightTitle", data.checked_by_right.signatory_title);
  setInputValue("recommendingApprovalName", data.recommending_approval.signatory_name);
  setInputValue("recommendingApprovalTitle", data.recommending_approval.signatory_title);
  setInputValue("approvedByName", data.approved_by.signatory_name);
  setInputValue("approvedByTitle", data.approved_by.signatory_title);

  syncSignatoryPreviewFromInputs();
}

function buildSignatoryPayload() {
  return {
    checked_by_left: {
      signatory_name: String(document.getElementById("checkedByLeftName")?.value || "").trim(),
      signatory_title: String(document.getElementById("checkedByLeftTitle")?.value || "").trim()
    },
    checked_by_right: {
      signatory_name: String(document.getElementById("checkedByRightName")?.value || "").trim(),
      signatory_title: String(document.getElementById("checkedByRightTitle")?.value || "").trim()
    },
    recommending_approval: {
      signatory_name: String(document.getElementById("recommendingApprovalName")?.value || "").trim(),
      signatory_title: String(document.getElementById("recommendingApprovalTitle")?.value || "").trim()
    },
    approved_by: {
      signatory_name: String(document.getElementById("approvedByName")?.value || "").trim(),
      signatory_title: String(document.getElementById("approvedByTitle")?.value || "").trim()
    }
  };
}

function renderCollegeEffectivePolicy(policy) {
  const data = normalizePolicy(policy);
  const badge = document.getElementById("collegeEffectiveBadge");
  if (badge) {
    badge.className = data.is_override_enabled ? "badge bg-label-warning" : "badge bg-label-info";
    badge.innerText = data.is_override_enabled ? "College Override Active" : "Using Global Default";
  }
  setText("collegeEffectiveSource", data.source_label || (data.is_override_enabled ? "College override" : "Global default"));
  setText("collegeEffectiveWindow", data.window_label);
  setText("collegeEffectiveBlockedDays", data.blocked_days_label);
  setText("collegeEffectiveBlockedTimes", data.blocked_times_label);
}

function updateCollegeNotice(enabled) {
  const notice = document.getElementById("collegeNotice");
  if (!document.getElementById("collegeSelect").value) {
    notice.className = "alert alert-info mb-4";
    notice.textContent = "Select a college to review or override its schedule policy.";
  } else if (enabled) {
    notice.className = "alert alert-warning mb-4";
    notice.textContent = "This college is using its own override policy instead of the global default.";
  } else {
    notice.className = "alert alert-info mb-4";
    notice.textContent = "This college inherits the global default unless you enable the override.";
  }
}

function loadSettings(collegeId = Number(document.getElementById("collegeSelect").value || DEFAULT_COLLEGE_ID || 0)) {
  const url = collegeId > 0 ? `../backend/get_academic_settings.php?college_id=${encodeURIComponent(collegeId)}` : "../backend/get_academic_settings.php";
  fetch(url)
    .then(res => res.json())
    .then(data => {
      const globalPolicy = normalizePolicy(data.schedule_policy);
      const overridePolicy = normalizePolicy(data.college_override_policy || data.schedule_policy || {});
      const effectivePolicy = normalizePolicy(data.college_effective_policy || data.college_override_policy || data.schedule_policy || {});

      renderOverrideColleges(data.override_colleges || []);
      updateTermSnapshot(data.academic_year || "-", data.semester || "-");
      setText("currentScheduleWindow", globalPolicy.window_label);
      setText("currentBlockedDays", globalPolicy.blocked_days_label);
      setText("currentBlockedTimes", globalPolicy.blocked_times_label);
      document.getElementById("academicYearSelect").value = data.current_ay_id || "";
      setSemester(data.current_semester || "");
      setPolicy("global", globalPolicy);
      setSignatoryForm(data.signatory_settings || {});

      document.getElementById("collegeOverrideEnabled").checked = overridePolicy.is_override_enabled;
      setPolicy("college", overridePolicy);
      renderCollegeEffectivePolicy(effectivePolicy);
      updateCollegeNotice(overridePolicy.is_override_enabled);
    })
    .catch(() => {
      Swal.fire({ icon: "error", title: "Unable to load settings", text: "Please refresh the page and try again." });
    });
}

document.getElementById("collegeSelect").value = DEFAULT_COLLEGE_ID ? String(DEFAULT_COLLEGE_ID) : "";
renderDays("globalDays", "global", []);
renderDays("collegeDays", "college", []);
renderTimes("globalTimes", "global", []);
renderTimes("collegeTimes", "college", []);
loadSettings();

document.getElementById("addGlobalBlockedTimeBtn").addEventListener("click", () => appendTime("globalTimes", "global"));
document.getElementById("addCollegeBlockedTimeBtn").addEventListener("click", () => appendTime("collegeTimes", "college"));
document.getElementById("collegeSelect").addEventListener("change", event => loadSettings(Number(event.target.value || 0)));
document.getElementById("collegeOverrideEnabled").addEventListener("change", event => updateCollegeNotice(event.target.checked));
["checkedByLeftName","checkedByLeftTitle","checkedByRightName","checkedByRightTitle","recommendingApprovalName","recommendingApprovalTitle","approvedByName","approvedByTitle"].forEach(id => {
  const target = document.getElementById(id);
  if (target) {
    target.addEventListener("input", syncSignatoryPreviewFromInputs);
  }
});

document.addEventListener("click", event => {
  const dayToggle = event.target.closest(".policy-day-toggle");
  if (dayToggle) {
    event.preventDefault();
    const isSelected = dayToggle.getAttribute("aria-pressed") === "true";
    dayToggle.setAttribute("aria-pressed", isSelected ? "false" : "true");
    dayToggle.classList.toggle("policy-day-selected", !isSelected);
    dayToggle.classList.toggle("policy-day-unselected", isSelected);
    dayToggle.classList.remove("active");
    window.requestAnimationFrame(() => dayToggle.blur());
    return;
  }

  const button = event.target.closest(".remove-time");
  if (!button) return;
  const prefix = String(button.dataset.prefix || "");
  const row = button.closest(`.${prefix}-time-row`);
  if (row) row.remove();
  if (prefix && document.querySelectorAll(`.${prefix}-time-row`).length === 0) {
    renderTimes(prefix + "Times", prefix, []);
  }
});

document.getElementById("saveTermBtn").addEventListener("click", () => {
  const semester = document.querySelector('input[name="semester"]:checked');
  const ayId = String(document.getElementById("academicYearSelect").value || "").trim();
  const selectedSemester = semester ? String(semester.value) : "";
  if (!ayId || !selectedSemester) {
    Swal.fire({ icon: "warning", title: "Incomplete settings", text: "Please select Academic Year and Semester." });
    return;
  }
  const formData = new FormData();
  formData.append("policy_scope", "academic_term");
  formData.append("ay_id", ayId);
  formData.append("semester", selectedSemester);
  postSettings(formData, "Failed to update the academic term.")
    .then(data => {
      if (data.status !== "success") {
        Swal.fire({ icon: "error", title: "Error", text: data.message || "Failed to update the academic term." });
        return;
      }
      Swal.fire({ icon: "success", title: "Updated!", text: data.message, timer: 1500, showConfirmButton: false });
      loadSettings(Number(document.getElementById("collegeSelect").value || 0));
    });
});

document.getElementById("saveGlobalBtn").addEventListener("click", () => {
  const payload = buildPayload("global");
  const validation = validatePolicy(payload);
  if (validation) {
    Swal.fire({ icon: "warning", title: "Incomplete settings", text: validation });
    return;
  }
  const formData = new FormData();
  formData.append("policy_scope", "global_policy");
  formData.append("schedule_day_start", payload.schedule_day_start);
  formData.append("schedule_day_end", payload.schedule_day_end);
  formData.append("blocked_days_json", JSON.stringify(payload.blocked_days));
  formData.append("blocked_times_json", JSON.stringify(payload.blocked_times));
  postSettings(formData, "Failed to update the global policy.")
    .then(data => {
      if (data.status !== "success") {
        Swal.fire({ icon: "error", title: "Error", text: data.message || "Failed to update the global policy." });
        return;
      }
      Swal.fire({ icon: "success", title: "Updated!", text: data.message, timer: 1500, showConfirmButton: false });
      loadSettings(Number(document.getElementById("collegeSelect").value || 0));
    });
});

document.getElementById("saveCollegeBtn").addEventListener("click", () => {
  const collegeId = Number(document.getElementById("collegeSelect").value || 0);
  if (collegeId <= 0) {
    Swal.fire({ icon: "warning", title: "Missing college", text: "Please select a college before saving an override." });
    return;
  }
  const payload = buildPayload("college");
  const validation = validatePolicy(payload);
  if (validation) {
    Swal.fire({ icon: "warning", title: "Incomplete override", text: validation });
    return;
  }
  const formData = new FormData();
  formData.append("policy_scope", "college_override");
  formData.append("college_id", String(collegeId));
  formData.append("college_override_enabled", document.getElementById("collegeOverrideEnabled").checked ? "1" : "0");
  formData.append("schedule_day_start", payload.schedule_day_start);
  formData.append("schedule_day_end", payload.schedule_day_end);
  formData.append("blocked_days_json", JSON.stringify(payload.blocked_days));
  formData.append("blocked_times_json", JSON.stringify(payload.blocked_times));
  postSettings(formData, "Failed to save the college override.")
    .then(data => {
      if (data.status !== "success") {
        Swal.fire({ icon: "error", title: "Error", text: data.message || "Failed to save the college override." });
        return;
      }
      Swal.fire({ icon: "success", title: "Updated!", text: data.message, timer: 1500, showConfirmButton: false });
      loadSettings(collegeId);
    });
});

document.getElementById("saveSignatoriesBtn").addEventListener("click", () => {
  const payload = buildSignatoryPayload();
  const formData = new FormData();
  formData.append("policy_scope", "report_signatories");
  formData.append("checked_by_left_name", payload.checked_by_left.signatory_name);
  formData.append("checked_by_left_title", payload.checked_by_left.signatory_title);
  formData.append("checked_by_right_name", payload.checked_by_right.signatory_name);
  formData.append("checked_by_right_title", payload.checked_by_right.signatory_title);
  formData.append("recommending_approval_name", payload.recommending_approval.signatory_name);
  formData.append("recommending_approval_title", payload.recommending_approval.signatory_title);
  formData.append("approved_by_name", payload.approved_by.signatory_name);
  formData.append("approved_by_title", payload.approved_by.signatory_title);

  postSettings(formData, "Failed to save the report signatories.")
    .then(data => {
      if (data.status !== "success") {
        Swal.fire({ icon: "error", title: "Error", text: data.message || "Failed to save the report signatories." });
        return;
      }

      Swal.fire({ icon: "success", title: "Updated!", text: data.message, timer: 1500, showConfirmButton: false });
      loadSettings(Number(document.getElementById("collegeSelect").value || 0));
    });
});
</script>
</body>
</html>
