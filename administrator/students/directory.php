<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../backend/academic_term_helper.php';

$flash = student_management_pull_flash();
$filters = [
    'search' => trim((string)($_GET['search'] ?? '')),
    'year_level' => max(0, (int)($_GET['year_level'] ?? 0)),
    'program_id' => max(0, (int)($_GET['program_id'] ?? 0)),
    'ay_id' => max(0, (int)($_GET['ay_id'] ?? 0)),
    'semester' => max(0, (int)($_GET['semester'] ?? 0)),
];

$programCatalog = [];
$batchOptions = [];
$currentTerm = [
    'ay_id' => 0,
    'semester' => 0,
    'ay_label' => '',
    'semester_label' => '',
    'term_text' => 'Current academic term',
];
$defaultAyId = 0;
$defaultSemester = 0;
$canAddStudents = false;

if ($studentManagementPageError === '') {
    try {
        $currentTerm = synk_fetch_current_academic_term($conn);
        $programCatalog = synk_student_management_fetch_program_catalog($conn);
        $batchOptions = synk_student_management_distinct_batches($conn);

        $defaultAyId = $filters['ay_id'] > 0
            ? $filters['ay_id']
            : (int)($currentTerm['ay_id'] ?? 0);
        $defaultSemester = $filters['semester'] > 0
            ? $filters['semester']
            : (int)($currentTerm['semester'] ?? 0);

        if (($defaultAyId <= 0 || $defaultSemester <= 0) && !empty($batchOptions)) {
            $defaultBatchOption = $batchOptions[0];

            foreach ($batchOptions as $batchOption) {
                $matchesAcademicYear = $filters['ay_id'] <= 0 || $filters['ay_id'] === (int)($batchOption['ay_id'] ?? 0);
                $matchesSemester = $filters['semester'] <= 0 || $filters['semester'] === (int)($batchOption['semester'] ?? 0);

                if ($matchesAcademicYear && $matchesSemester) {
                    $defaultBatchOption = $batchOption;
                    break;
                }
            }

            if ($defaultAyId <= 0) {
                $defaultAyId = (int)($defaultBatchOption['ay_id'] ?? 0);
            }
            if ($defaultSemester <= 0) {
                $defaultSemester = (int)($defaultBatchOption['semester'] ?? 0);
            }
        }

        $canAddStudents = !empty($programCatalog) && $defaultAyId > 0 && $defaultSemester > 0;
    } catch (Throwable $e) {
        $studentManagementPageError = $e->getMessage();
    }
}

$yearOptions = [
    1 => synk_student_management_year_label(1),
    2 => synk_student_management_year_label(2),
    3 => synk_student_management_year_label(3),
    4 => synk_student_management_year_label(4),
    5 => synk_student_management_year_label(5),
];
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Student Directory | Synk</title>

    <link rel="icon" type="image/png" href="../../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../../assets/css/demo.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />

    <script src="../../assets/vendor/js/helpers.js"></script>
    <script src="../../assets/js/config.js"></script>

    <style>
      .directory-hero {
        border: 0;
        border-radius: 1.25rem;
        background: linear-gradient(140deg, #ffffff 0%, #f8fbff 50%, #eef5ff 100%);
      }

      .section-card {
        border: 0;
        border-radius: 1.1rem;
        box-shadow: 0 16px 35px rgba(15, 23, 42, 0.08);
      }

      .filter-grid,
      .modal-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
      }

      .modal-form-row-4,
      .modal-form-row-2 {
        display: grid;
        gap: 1rem;
      }

      .modal-form-row-4 {
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }

      .modal-form-row-2 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .modal-span-full {
        grid-column: 1 / -1;
      }

      .student-table thead th {
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #6b7280;
        white-space: nowrap;
      }

      .student-table td {
        vertical-align: middle;
        white-space: nowrap;
        text-transform: uppercase;
      }

      .student-table td.email-cell {
        min-width: 240px;
      }

      .student-table td.subject-count-cell,
      .student-table th.subject-count-head {
        min-width: 130px;
        text-align: center;
      }

      .student-id-cell {
        font-variant-numeric: tabular-nums;
      }

      .student-name-cell {
        min-width: 320px;
        white-space: normal;
      }

      .student-name-last {
        font-weight: 700;
      }

      .student-name-first,
      .student-name-suffix {
        font-weight: 500;
      }

      .student-name-middle {
        font-style: italic;
        text-transform: lowercase;
      }

      .student-modal-shell .form-label,
      .student-modal-shell .form-control,
      .student-modal-shell .form-select,
      .student-modal-shell .form-text,
      .student-modal-shell .btn {
        text-transform: uppercase;
      }

      .dataTables_wrapper .dataTables_processing {
        border: 1px solid #dce5f1;
        border-radius: 1rem;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
      }

      .student-directory-table-shell .dataTables_wrapper .row {
        margin-left: 0;
        margin-right: 0;
      }

      .student-directory-table-shell .dataTables_wrapper .dataTables_length,
      .student-directory-table-shell .dataTables_wrapper .dataTables_info,
      .student-directory-table-shell .dataTables_wrapper .dataTables_paginate {
        padding-top: 1rem;
      }

      .student-directory-table-shell .dataTables_wrapper .dataTables_paginate .paginate_button {
        padding: 0 !important;
        margin-left: 0.25rem;
      }

      .student-directory-table-shell .dataTables_wrapper .dataTables_paginate .page-link {
        border-radius: 0.55rem;
      }

      .select2-container {
        width: 100% !important;
      }

      .select2-container--default .select2-selection--single {
        min-height: calc(2.25rem + 2px);
        border: 1px solid #d9dee3;
        border-radius: 0.5rem;
        padding: 0.375rem 0.75rem;
        display: flex;
        align-items: center;
      }

      .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: #566a7f;
        line-height: 1.4;
        padding-left: 0;
      }

      .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 100%;
        right: 0.65rem;
      }

      .select2-dropdown {
        border-color: #d9dee3;
        border-radius: 0.75rem;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
      }

      .empty-state {
        padding: 3rem 1rem;
        text-align: center;
        color: #6b7280;
      }

      @media (max-width: 991.98px) {
        .modal-form-row-4,
        .modal-form-row-2 {
          grid-template-columns: repeat(1, minmax(0, 1fr));
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
              <div class="card directory-hero mb-4">
                <div class="card-body p-4 p-lg-5">
                  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                    <div>
                      <span class="badge bg-label-primary mb-3">Student Directory Management</span>
                      <h3 class="fw-bold mb-2 text-primary">Manage student records directly from the directory page.</h3>
                      <p class="text-muted mb-0">
                        Add, edit, and delete student records here. The directory now uses a searchable, sortable DataTable and keeps only the requested identity columns.
                      </p>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                      <a href="index.php" class="btn btn-outline-primary">Back to Dashboard</a>
                      <button
                        type="button"
                        class="btn btn-primary"
                        id="openAddStudentBtn"
                        <?php echo $canAddStudents ? '' : 'disabled'; ?>
                        <?php if (!$canAddStudents): ?>
                          title="Set the current academic term and make sure active programs are available before adding students."
                        <?php endif; ?>
                      >
                        <i class="bx bx-plus me-1"></i> Add Student
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <div id="directoryFeedback">
                <?php if ($flash): ?>
                  <div class="alert alert-<?php echo htmlspecialchars((string)($flash['status'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?> alert-dismissible mb-4" role="alert">
                    <div class="fw-semibold"><?php echo htmlspecialchars((string)($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if (!empty($flash['details'])): ?>
                      <div class="small mt-1"><?php echo htmlspecialchars((string)$flash['details'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                  </div>
                <?php endif; ?>
              </div>

              <?php if ($studentManagementPageError !== ''): ?>
                <div class="alert alert-danger mb-4" role="alert">
                  <div class="fw-semibold">Student management is unavailable.</div>
                  <div class="small mt-1"><?php echo htmlspecialchars($studentManagementPageError, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
              <?php endif; ?>

              <div class="card section-card mb-4">
                <div class="card-header">
                  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
                    <div>
                      <h5 class="mb-1">Directory Filters</h5>
                      <p class="text-muted mb-0">Apply filters, then scroll the list to keep loading more student records.</p>
                    </div>
                    <div class="text-muted small">
                      <span id="resultCountLabel">Loading records...</span>
                    </div>
                  </div>
                </div>
                <div class="card-body">
                  <form id="directoryFilterForm">
                    <div class="filter-grid">
                      <div>
                        <label class="form-label" for="search">Search</label>
                        <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Name or email" />
                      </div>

                      <div>
                        <label class="form-label" for="year_level">Year Level</label>
                        <select class="form-select" id="year_level" name="year_level">
                          <option value="0">All year levels</option>
                          <?php foreach ($yearOptions as $yearValue => $yearLabel): ?>
                            <option value="<?php echo $yearValue; ?>" <?php echo (int)$filters['year_level'] === (int)$yearValue ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($yearLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <div>
                        <label class="form-label" for="ay_id">Academic Year</label>
                        <select class="form-select" id="ay_id" name="ay_id">
                          <option value="">All academic years</option>
                          <?php
                          $seenAcademicYears = [];
                          foreach ($batchOptions as $batchOption):
                              $ayId = (int)($batchOption['ay_id'] ?? 0);
                              $academicYearLabel = (string)($batchOption['academic_year_label'] ?? '');
                              if ($ayId <= 0 || $academicYearLabel === '' || isset($seenAcademicYears[$ayId])) {
                                  continue;
                              }
                              $seenAcademicYears[$ayId] = true;
                          ?>
                            <option value="<?php echo $ayId; ?>" <?php echo $filters['ay_id'] === $ayId ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($academicYearLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <div>
                        <label class="form-label" for="semester">Semester</label>
                        <select class="form-select" id="semester" name="semester">
                          <option value="">All semesters</option>
                          <?php
                          $seenSemesters = [];
                          foreach ($batchOptions as $batchOption):
                              $semesterValue = (int)($batchOption['semester'] ?? 0);
                              $semesterLabel = (string)($batchOption['semester_label'] ?? '');
                              if ($semesterValue <= 0 || $semesterLabel === '' || isset($seenSemesters[$semesterValue])) {
                                  continue;
                              }
                              $seenSemesters[$semesterValue] = true;
                          ?>
                            <option value="<?php echo $semesterValue; ?>" <?php echo $filters['semester'] === $semesterValue ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($semesterLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <div>
                        <label class="form-label" for="program_id_filter">Program</label>
                        <select class="form-select" id="program_id_filter" name="program_id">
                          <option value="">All programs</option>
                          <?php foreach ($programCatalog as $program): ?>
                            <option value="<?php echo (int)$program['program_id']; ?>" <?php echo $filters['program_id'] === (int)$program['program_id'] ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars((string)$program['source_program_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                      <button type="submit" class="btn btn-primary">
                        <i class="bx bx-filter-alt me-1"></i> Apply Filters
                      </button>
                      <button type="button" class="btn btn-outline-secondary" id="resetDirectoryFilters">Reset</button>
                    </div>
                  </form>
                </div>
              </div>

              <div class="card section-card">
                <div class="card-header">
                  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
                    <div>
                      <h5 class="mb-1">Student List</h5>
                      <p class="text-muted mb-0">Requested columns only, with DataTable paging, sorting, preview, editing, and deleting.</p>
                    </div>
                    <div class="small text-muted">DataTable enabled</div>
                  </div>
                </div>
                <div class="card-body student-directory-table-shell">
                  <div class="table-responsive">
                    <table class="table table-hover align-middle student-table" id="studentDirectoryTable">
                      <thead>
                        <tr>
                          <th>ID Number</th>
                          <th>Full Name</th>
                          <th>Email Address</th>
                          <th class="subject-count-head">Subject Count</th>
                          <th class="text-end">Actions</th>
                        </tr>
                      </thead>
                      <tbody id="studentListBody">
                        <tr>
                          <td colspan="5" class="empty-state">Loading student records...</td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>

            <?php include '../../footer.php'; ?>

            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>

      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <div class="modal fade" id="studentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content student-modal-shell">
          <div class="modal-header">
            <h5 class="modal-title" id="studentModalTitle">Add Student</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <form id="studentForm">
            <div class="modal-body">
              <input type="hidden" name="student_id" id="student_id" value="0" />
              <input type="hidden" name="ay_id" id="ay_id_hidden" value="0" />
              <input type="hidden" name="semester" id="semester_hidden" value="0" />
              <input type="hidden" name="program_id" id="program_id" value="0" />

              <div class="modal-form-grid">
                <div class="modal-form-row-2 modal-span-full">
                  <div>
                    <label class="form-label" for="program_select_modal">Program</label>
                    <select class="form-select" id="program_select_modal" required>
                      <option value="">Select program from program table</option>
                      <?php foreach ($programCatalog as $program): ?>
                        <option
                          value="<?php echo (int)$program['program_id']; ?>"
                          data-program-id="<?php echo (int)$program['program_id']; ?>"
                          data-source-name="<?php echo htmlspecialchars((string)$program['source_program_name'], ENT_QUOTES, 'UTF-8'); ?>"
                          data-college="<?php echo htmlspecialchars((string)$program['college_name'], ENT_QUOTES, 'UTF-8'); ?>"
                          data-campus="<?php echo htmlspecialchars((string)$program['campus_name'], ENT_QUOTES, 'UTF-8'); ?>"
                        >
                          <?php
                          $programLabelParts = array_filter([
                              (string)$program['program_code'],
                              (string)$program['program_name'],
                              (string)$program['major'],
                              (string)$program['campus_name'],
                          ]);
                          echo htmlspecialchars(implode(' | ', $programLabelParts), ENT_QUOTES, 'UTF-8');
                          ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div>
                    <label class="form-label" for="year_level_modal">Year Level</label>
                    <select class="form-select" id="year_level_modal" name="year_level" required>
                      <option value="">Select year level</option>
                      <?php foreach ($yearOptions as $yearValue => $yearLabel): ?>
                        <option value="<?php echo $yearValue; ?>"><?php echo htmlspecialchars($yearLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <div class="modal-span-full">
                  <label class="form-label" for="student_number_modal">ID Number</label>
                  <input type="text" inputmode="numeric" pattern="[0-9]+" class="form-control" id="student_number_modal" name="student_number" required />
                </div>

                <div class="modal-form-row-4 modal-span-full">
                  <div>
                    <label class="form-label" for="first_name_modal">First Name</label>
                    <input type="text" class="form-control" id="first_name_modal" name="first_name" required />
                  </div>

                  <div>
                    <label class="form-label" for="middle_name_modal">Middle Name</label>
                    <input type="text" class="form-control" id="middle_name_modal" name="middle_name" />
                  </div>

                  <div>
                    <label class="form-label" for="last_name_modal">Last Name</label>
                    <input type="text" class="form-control" id="last_name_modal" name="last_name" required />
                  </div>

                  <div>
                    <label class="form-label" for="suffix_name_modal">Suffix</label>
                    <input type="text" class="form-control" id="suffix_name_modal" name="suffix_name" />
                  </div>
                </div>

                <div class="modal-span-full">
                  <label class="form-label" for="email_address_modal">Email Address</label>
                  <div class="input-group">
                    <input type="email" class="form-control" id="email_address_modal" name="email_address" />
                    <button type="button" class="btn btn-outline-primary" id="generateEmailBtn">Generate</button>
                  </div>
                  <div class="form-text">Student emails should end with `@sksu.edu.ph`.</div>
                </div>
              </div>
            </div>

            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Save Student</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
      window.studentDirectoryConfig = <?php echo json_encode([
          'apiUrl' => 'directory_api.php',
          'previewBaseUrl' => '../../student/index.php',
          'initialFilters' => $filters,
          'defaultAyId' => $defaultAyId,
          'defaultSemester' => $defaultSemester,
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="../../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../../assets/vendor/libs/popper/popper.js"></script>
    <script src="../../assets/vendor/js/bootstrap.js"></script>
    <script src="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../../assets/vendor/js/menu.js"></script>
    <script src="../../assets/js/main.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="directory.js"></script>
  </body>
</html>
