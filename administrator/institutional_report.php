<?php
session_start();
ob_start();
include '../backend/db.php';

/* ======================================================
   ADMIN GUARD
====================================================== */
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

/* ======================================================
   FUNCTIONS
====================================================== */

/**
 * Fetch all academic years
 */
function getAcademicYears($conn) {
    $data = [];
    $sql = "
        SELECT ay_id, ay
        FROM tbl_academic_years
        WHERE status = 'active'
        ORDER BY ay DESC
    ";
    $res = $conn->query($sql);
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
    return $data;
}


/**
 * Static semester options
 */
function getSemesterOptions() {
    return [
        '1' => '1st Semester',
        '2' => '2nd Semester',
        'midyear' => 'Midyear'
    ];
}

/**
 * Build report link with AY & Semester
 */
function buildReportLink($page, $ay, $sem) {
    return $page . '?ay=' . urlencode($ay) . '&sem=' . urlencode($sem);
}

/* ======================================================
   LOAD FILTER DATA
====================================================== */
$academicYears = getAcademicYears($conn);
$semesters     = getSemesterOptions();

/* Selected filters (defaults) */
$selectedAY = $_GET['ay'] ?? ($academicYears[0]['ay'] ?? '');
$selectedSem = $_GET['sem'] ?? '1';
?>
<!DOCTYPE html>
<html lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free">

<head>
  <meta charset="utf-8" />
  <title>Institutional Reports | Admin</title>
    <meta name="description" content="" />

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />

    <!-- Icons. Uncomment required icon fonts -->
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />

    <!-- Core CSS -->
    <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />
    <script src="../assets/vendor/js/helpers.js"></script>

     <script src="../assets/js/config.js"></script>
</head>

<body>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">

<?php include 'sidebar.php'; ?>

<div class="layout-page">
<?php include 'navbar.php'; ?>

<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

<!-- ======================================================
     FILTER BAR
====================================================== -->
<div class="card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-3 align-items-end">

      <div class="col-md-4">
        <label class="form-label fw-semibold">Academic Year</label>
<select name="ay" class="form-select">
  <?php foreach ($academicYears as $ay): ?>
    <option value="<?= $ay['ay']; ?>"
      <?= ($selectedAY == $ay['ay']) ? 'selected' : ''; ?>>
      <?= $ay['ay']; ?>
    </option>
  <?php endforeach; ?>
</select>

      </div>

      <div class="col-md-4">
        <label class="form-label fw-semibold">Semester</label>
        <select name="sem" class="form-select">
          <?php foreach ($semesters as $key => $label): ?>
            <option value="<?= $key; ?>"
              <?= ($selectedSem == $key) ? 'selected' : ''; ?>>
              <?= $label; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <button class="btn btn-primary w-100">
          Apply Filters
        </button>
      </div>

    </form>
  </div>
</div>

<!-- ======================================================
     REPORT HUB
====================================================== -->
<div class="row g-4">

<!-- Academic Operations -->
<div class="col-md-6">
  <div class="card h-100 shadow-sm">
    <div class="card-body">
      <h5 class="fw-bold">Academic Operations Summary</h5>
      <p class="text-muted">
        Institution-wide overview of programs, sections, faculty,
        and instructional activity for the selected term.
      </p>
      <a href="<?= buildReportLink('report_academic_operations.php', $selectedAY, $selectedSem); ?>"
         class="btn btn-sm btn-primary">
        View Report
      </a>
    </div>
  </div>
</div>

<!-- Faculty Load -->
<div class="col-md-6">
  <div class="card h-100 shadow-sm">
    <div class="card-body">
      <h5 class="fw-bold">Faculty Load & Utilization</h5>
      <p class="text-muted">
        Teaching load distribution, overload and underload analysis
        across campuses and colleges.
      </p>
      <a href="<?= buildReportLink('report_faculty_load.php', $selectedAY, $selectedSem); ?>"
         class="btn btn-sm btn-primary">
        View Report
      </a>
    </div>
  </div>
</div>

<!-- Room Utilization -->
<div class="col-md-6">
  <div class="card h-100 shadow-sm">
    <div class="card-body">
      <h5 class="fw-bold">Room & Infrastructure Utilization</h5>
      <p class="text-muted">
        Lecture and laboratory room availability, usage, and capacity
        indicators per campus.
      </p>
      <a href="<?= buildReportLink('report_room_utilization.php', $selectedAY, $selectedSem); ?>"
         class="btn btn-sm btn-primary">
        View Report
      </a>
    </div>
  </div>
</div>

<!-- Campus Comparison -->
<div class="col-md-6">
  <div class="card h-100 shadow-sm">
    <div class="card-body">
      <h5 class="fw-bold">Campus Comparative Performance</h5>
      <p class="text-muted">
        Cross-campus comparison of faculty, programs, sections,
        and instructional capacity.
      </p>
      <a href="<?= buildReportLink('report_campus_comparison.php', $selectedAY, $selectedSem); ?>"
         class="btn btn-sm btn-primary">
        View Report
      </a>
    </div>
  </div>
</div>

<!-- Compliance -->
<div class="col-md-12">
  <div class="card h-100 shadow-sm border-warning">
    <div class="card-body">
      <h5 class="fw-bold text-warning">CHED & Accreditation Compliance Snapshot</h5>
      <p class="text-muted">
        Readiness indicators for instruction-related compliance:
        faculty assignment, scheduling completeness, and workload coverage.
      </p>
      <a href="<?= buildReportLink('report_compliance_snapshot.php', $selectedAY, $selectedSem); ?>"
         class="btn btn-sm btn-warning">
        View Compliance Snapshot
      </a>
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
    <!-- Core JS -->
    <!-- build:js assets/vendor/js/core.js -->
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>    
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/dashboards-analytics.js"></script>
</body>
</html>
