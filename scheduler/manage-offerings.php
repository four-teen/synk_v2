<?php
session_start();
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/schema_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

if (!isset($_SESSION['college_id']) || (int)($_SESSION['college_id'] ?? 0) <= 0) {
    echo "Scheduler error: missing college assignment.";
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];
$currentTerm = synk_fetch_current_academic_term($conn);
$default_ay_id = (int)($currentTerm['ay_id'] ?? 0);
$default_semester = (int)($currentTerm['semester'] ?? 0);
$collegeId = (int)($_SESSION['college_id'] ?? 0);
$sectionCurriculumEnabled = synk_table_exists($conn, 'tbl_section_curriculum');

function synk_title_case_display($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', $value);
    $value = ucwords(strtolower($value));
    $smallWords = ['And', 'Of', 'In', 'On', 'To', 'For', 'The', 'A', 'An', 'At', 'By', 'From'];
    $parts = explode(' ', $value);

    foreach ($parts as $index => $part) {
        if ($index > 0 && in_array($part, $smallWords, true)) {
            $parts[$index] = strtolower($part);
        }
    }

    return implode(' ', $parts);
}

$programOptions = [];
$programStmt = $conn->prepare("
    SELECT
        program_id,
        program_code,
        program_name,
        COALESCE(major, '') AS major
    FROM tbl_program
    WHERE college_id = ?
    ORDER BY program_code ASC, program_name ASC, major ASC
");

if ($programStmt instanceof mysqli_stmt) {
    $programStmt->bind_param("i", $collegeId);
    $programStmt->execute();
    $programRes = $programStmt->get_result();

    while ($programRes && ($row = $programRes->fetch_assoc())) {
        $programCode = strtoupper(trim((string)($row['program_code'] ?? '')));
        $programName = synk_title_case_display($row['program_name'] ?? '');
        $major = synk_title_case_display($row['major'] ?? '');
        if ($major !== '') {
            $programName .= ' (Major in ' . $major . ')';
        }

        $programOptions[] = [
            'program_id' => (int)($row['program_id'] ?? 0),
            'label' => trim($programCode . ' - ' . $programName)
        ];
    }

    $programStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed">
<head>
    <meta charset="utf-8" />
    <title>Generate Offerings | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css">
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
        .select2-selection--single {
            height: 40px !important;
            padding: 6px !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 38px !important;
        }
        .page-loader-inline {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            color: #6f7f95;
            font-weight: 600;
        }
        .page-loader-inline .spinner-border {
            width: 1rem;
            height: 1rem;
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

          <h4 class="fw-bold mb-4">
            <i class="bx bx-layer-plus me-2"></i> Generate Unified Term Offerings
          </h4>

          <?php if (!$sectionCurriculumEnabled): ?>
            <div class="alert alert-warning">
              Create <code>tbl_section_curriculum</code> first before generating mixed-curriculum term offerings.
            </div>
          <?php endif; ?>

          <div class="card mb-4">
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label">Program</label>
                  <select id="program_id" class="form-select" <?= $sectionCurriculumEnabled ? '' : 'disabled' ?>>
                    <option value="">Select Program...</option>
                    <?php foreach ($programOptions as $programOption): ?>
                      <option value="<?= (int)$programOption['program_id'] ?>">
                        <?= htmlspecialchars((string)$programOption['label']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-3">
                  <label class="form-label">Academic Year</label>
                  <select id="ay_id" class="form-select" <?= $sectionCurriculumEnabled ? '' : 'disabled' ?>>
                    <option value="">Select Academic Year...</option>
                    <?php
                    $ayQuery = $conn->query("SELECT ay_id, ay FROM tbl_academic_years ORDER BY ay DESC");
                    while ($ayRow = $ayQuery->fetch_assoc()) {
                        $selected = ((int)$ayRow['ay_id'] === $default_ay_id) ? ' selected' : '';
                        echo '<option value="' . (int)$ayRow['ay_id'] . '"' . $selected . '>' . htmlspecialchars((string)$ayRow['ay']) . '</option>';
                    }
                    ?>
                  </select>
                </div>

                <div class="col-md-3">
                  <label class="form-label">Semester</label>
                  <select id="semester" class="form-select" <?= $sectionCurriculumEnabled ? '' : 'disabled' ?>>
                    <option value="">Select...</option>
                    <option value="1"<?= $default_semester === 1 ? ' selected' : '' ?>>First Semester</option>
                    <option value="2"<?= $default_semester === 2 ? ' selected' : '' ?>>Second Semester</option>
                    <option value="3"<?= $default_semester === 3 ? ' selected' : '' ?>>Midyear</option>
                  </select>
                </div>

                <div class="col-md-2 d-grid">
                  <label class="form-label">&nbsp;</label>
                  <button class="btn btn-primary" id="btnGenerateOfferings" <?= $sectionCurriculumEnabled ? '' : 'disabled' ?>>
                    <i class="bx bx-refresh me-1"></i> Generate
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <h5 class="m-0">Unified Program Offerings</h5>
              <small class="text-muted">
                One term list for the selected program, academic year, and semester. Each section follows its assigned curriculum.
              </small>
            </div>

            <div class="d-flex justify-content-end align-items-center gap-2 px-3 pb-2">
              <span id="totalOfferingsBadge" class="badge bg-primary">Total Offerings: 0</span>
            </div>

            <div class="table-responsive p-3">
              <table class="table table-bordered table-hover" id="offeringsTable">
                <thead>
                  <tr>
                    <th>Year</th>
                    <th>Section</th>
                    <th>Curriculum</th>
                    <th>Subject Code</th>
                    <th>Description</th>
                    <th>LEC</th>
                    <th>LAB</th>
                    <th>Units</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td colspan="9" class="text-center text-muted">
                      Select program, academic year, and semester to load offerings.
                    </td>
                  </tr>
                </tbody>
              </table>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
<script src="../assets/js/main.js"></script>
<script src="../assets/js/dashboards-analytics.js"></script>

<script>
$(document).ready(function () {
  const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
  const SECTION_CURRICULUM_ENABLED = <?= $sectionCurriculumEnabled ? 'true' : 'false' ?>;

  $.ajaxPrefilter(function (options) {
    const method = (options.type || options.method || "GET").toUpperCase();
    if (method !== "POST") return;

    if (typeof options.data === "string") {
      const tokenPair = "csrf_token=" + encodeURIComponent(CSRF_TOKEN);
      options.data = options.data ? (options.data + "&" + tokenPair) : tokenPair;
      return;
    }

    if (Array.isArray(options.data)) {
      options.data.push({ name: "csrf_token", value: CSRF_TOKEN });
      return;
    }

    if ($.isPlainObject(options.data)) {
      options.data.csrf_token = CSRF_TOKEN;
      return;
    }

    if (!options.data) {
      options.data = { csrf_token: CSRF_TOKEN };
    }
  });

  $('#program_id').select2({ placeholder: "Select Program", width: '100%' });
  $('#ay_id').select2({ placeholder: "Select Academic Year", width: '100%' });
  $('#semester').select2({ placeholder: "Select Semester", width: '100%' });

  function buildLoaderRow(colspan, message) {
    return `
      <tr>
        <td colspan="${colspan}" class="text-center text-muted py-4">
          <div class="page-loader-inline">
            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
            <span>${message}</span>
          </div>
        </td>
      </tr>
    `;
  }

  function extractAjaxError(xhr, fallbackMsg) {
    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
      return xhr.responseJSON.message;
    }

    const text = (xhr && xhr.responseText ? String(xhr.responseText) : "").trim();
    if (!text) return fallbackMsg;

    try {
      const parsed = JSON.parse(text);
      if (parsed && parsed.message) return parsed.message;
    } catch (e) {}

    return text.length > 300 ? fallbackMsg : text;
  }

  function semLabel(sem) {
    if (String(sem) === "1") return "First Semester";
    if (String(sem) === "2") return "Second Semester";
    if (String(sem) === "3") return "Midyear";
    return `Semester ${sem}`;
  }

  function toDisplayCase(value) {
    const text = String(value || "").trim().replace(/\s+/g, " ");
    if (!text) return "";

    const lower = text.toLowerCase();
    const titled = lower.replace(/\b([a-z])/g, function (_, letter) {
      return letter.toUpperCase();
    });
    const words = titled.split(" ");
    const smallWords = new Set(["and", "of", "in", "on", "to", "for", "the", "a", "an", "at", "by", "from"]);

    return words.map(function (word, index) {
      if (index > 0 && smallWords.has(word.toLowerCase())) {
        return word.toLowerCase();
      }
      return word;
    }).join(" ");
  }

  function formatProgramDisplayLabel(programCode, programName, major) {
    const code = String(programCode || "").trim().toUpperCase();
    const name = toDisplayCase(programName);
    const majorLabel = toDisplayCase(major);
    let label = [code, name].filter(Boolean).join(" - ");

    if (majorLabel) {
      label += `${label ? " " : ""}(Major in ${majorLabel})`;
    }

    return label;
  }

  function buildValidationHtml(v) {
    const summary = v.summary || {};
    const blockers = Array.isArray(v.blockers) ? v.blockers : [];
    const warnings = Array.isArray(v.warnings) ? v.warnings : [];
    const curriculumLabels = Array.isArray(v.curriculum_labels) ? v.curriculum_labels : [];
    const fullProgramLabel = formatProgramDisplayLabel(v.program_code, v.program_name, v.major);

    let html = `
      <div class="text-start">
        <div><strong>Program:</strong> ${fullProgramLabel || "-"}</div>
        <div><strong>AY:</strong> ${v.ay_label || "-"}</div>
        <div><strong>Semester:</strong> ${semLabel(v.semester || "")}</div>
        <hr class="my-2">
        <div><strong>Active Sections:</strong> ${summary.total_active_sections ?? 0}</div>
        <div><strong>Sections with Curriculum:</strong> ${summary.sections_with_curriculum ?? 0}</div>
        <div><strong>Sections Missing Curriculum:</strong> ${summary.sections_missing_curriculum ?? 0}</div>
        <div><strong>Sections without Subject Rows:</strong> ${summary.sections_without_subject_rows ?? 0}</div>
        <div><strong>Curriculum Versions in Use:</strong> ${summary.curriculum_versions_in_use ?? 0}</div>
        <div><strong>Potential Offerings:</strong> ${summary.potential_offerings ?? 0}</div>
        <div><strong>Existing Rows:</strong> ${summary.total_existing_rows ?? 0}</div>
        <div><strong>Live Synced Rows:</strong> ${summary.current_synced_rows ?? 0}</div>
        <div><strong>Out-of-Scope Retained:</strong> ${summary.out_of_scope_existing ?? 0}</div>
      `;

    if (curriculumLabels.length) {
      html += `<hr class="my-2"><div><strong>Curricula in Use:</strong><ul class="mb-0">`;
      curriculumLabels.forEach(function (label) {
        html += `<li>${label}</li>`;
      });
      html += `</ul></div>`;
    }

    if (blockers.length) {
      html += `<hr class="my-2"><div class="text-danger"><strong>Blockers:</strong><ul class="mb-0">`;
      blockers.forEach(function (item) { html += `<li>${item}</li>`; });
      html += `</ul></div>`;
    }

    if (warnings.length) {
      html += `<hr class="my-2"><div class="text-warning"><strong>Warnings:</strong><ul class="mb-0">`;
      warnings.forEach(function (item) { html += `<li>${item}</li>`; });
      html += `</ul></div>`;
    }

    html += `</div>`;
    return html;
  }

  function tryAutoLoadOfferings() {
    const programId = $('#program_id').val();
    const ay = $('#ay_id').val();
    const sem = $('#semester').val();

    $('#totalOfferingsBadge').text('Total Offerings: 0');

    if (SECTION_CURRICULUM_ENABLED && programId && ay && sem) {
      $('#offeringsTable tbody').html(buildLoaderRow(9, "Loading unified offerings..."));
      loadOfferings(programId, ay, sem);
    }
  }

  function executeGenerate(programId, ay, sem) {
    $('#btnGenerateOfferings').prop('disabled', true);
    Swal.fire({
      title: "Generating...",
      allowOutsideClick: false,
      didOpen: () => Swal.showLoading()
    });

    $.ajax({
      url: "../backend/query_generate_offerings.php",
      type: "POST",
      dataType: "json",
      data: {
        generate_offerings: 1,
        program_id: programId,
        ay_id: ay,
        semester: sem
      },
      success: function (out) {
        if (out.status !== 'ok') {
          Swal.fire("Error", out.message || "Unknown error.", "error");
          return;
        }

        Swal.fire({
          icon: "success",
          title: "Sync Complete",
          text: `Added: ${out.inserted}, Synced Existing: ${out.synced_existing || 0}, Retained Hidden: ${out.retained_out_of_scope || 0}`,
          confirmButtonText: "Done"
        }).then(function () {
          loadOfferings(programId, ay, sem);
        });
      },
      error: function (xhr) {
        Swal.fire("Error", extractAjaxError(xhr, "Generation failed."), "error");
      },
      complete: function () {
        $('#btnGenerateOfferings').prop('disabled', false);
      }
    });
  }

  function loadOfferings(programId, ay, sem) {
    $('#offeringsTable tbody').html(buildLoaderRow(9, "Loading unified offerings..."));

    $.post("../backend/load_offerings.php", {
      program_id: programId,
      ay_id: ay,
      semester: sem
    }, function (rows) {
      $('#offeringsTable tbody').html(rows);
      const count = $('#offeringsTable tbody tr').not(':has(td[colspan])').length;
      $('#totalOfferingsBadge').text('Total Offerings: ' + count);
    }).fail(function (xhr) {
      $('#offeringsTable tbody').html("<tr><td colspan='9' class='text-danger text-center'>Error loading offerings.</td></tr>");
      $('#totalOfferingsBadge').text('Total Offerings: 0');
      console.error(xhr.responseText);
    });
  }

  $('#program_id, #ay_id, #semester').on('change', tryAutoLoadOfferings);

  $('#btnGenerateOfferings').on('click', function () {
    const programId = $('#program_id').val();
    const ay = $('#ay_id').val();
    const sem = $('#semester').val();

    if (!SECTION_CURRICULUM_ENABLED) {
      Swal.fire("Missing Table", "Create tbl_section_curriculum first before generating offerings.", "warning");
      return;
    }

    if (!programId || !ay || !sem) {
      Swal.fire("Missing Data", "Fill all fields.", "warning");
      return;
    }

    Swal.fire({
      title: "Validating...",
      allowOutsideClick: false,
      didOpen: () => Swal.showLoading()
    });
    $('#btnGenerateOfferings').prop('disabled', true);

    $.ajax({
      url: "../backend/query_generate_offerings.php",
      type: "POST",
      dataType: "json",
      data: {
        validate_offerings_context: 1,
        program_id: programId,
        ay_id: ay,
        semester: sem
      },
      success: function (v) {
        if (v.status !== "ok" || v.can_generate !== true) {
          $('#btnGenerateOfferings').prop('disabled', false);
          Swal.fire({
            icon: "error",
            title: "Cannot Generate",
            html: buildValidationHtml(v),
            confirmButtonText: "Close"
          });
          return;
        }

        Swal.fire({
          title: "Sync Unified Offerings?",
          html: buildValidationHtml(v),
          icon: "question",
          showCancelButton: true,
          confirmButtonText: "Proceed"
        }).then(function (res) {
          if (!res.isConfirmed) {
            $('#btnGenerateOfferings').prop('disabled', false);
            return;
          }

          executeGenerate(programId, ay, sem);
        });
      },
      error: function (xhr) {
        $('#btnGenerateOfferings').prop('disabled', false);
        Swal.fire("Error", extractAjaxError(xhr, "Validation failed."), "error");
      }
    });
  });
});
</script>
</body>
</html>
