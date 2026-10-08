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
   VALIDATE FILTERS
====================================================== */
$ay  = $_GET['ay']  ?? '';
$sem = $_GET['sem'] ?? '';

if ($ay === '' || $sem === '') {
    die('Academic Year and Semester are required.');
}

/* ======================================================
   RESOLVE AY → ay_id
====================================================== */
$stmt = $conn->prepare(
    "SELECT ay_id FROM tbl_academic_years WHERE ay = ? AND status = 'active' LIMIT 1"
);
$stmt->bind_param("s", $ay);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    die('Invalid Academic Year.');
}

$ay_id = $res->fetch_assoc()['ay_id'];

/* ======================================================
   FUNCTIONS
====================================================== */

function getTotalOfferings($conn, $ay_id, $sem) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM tbl_prospectus_offering
         WHERE ay_id = ? AND semester = ?"
    );
    $stmt->bind_param("is", $ay_id, $sem);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

function getTotalSections($conn, $ay_id, $sem) {
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT section_id) AS total
         FROM tbl_prospectus_offering
         WHERE ay_id = ? AND semester = ? AND section_id IS NOT NULL"
    );
    $stmt->bind_param("is", $ay_id, $sem);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['total'];
}

function getScheduleStats($conn, $ay_id, $sem) {
    $stats = [];

    // total schedules
    $stmt = $conn->prepare(
        "SELECT COUNT(cs.schedule_id) AS total
         FROM tbl_class_schedule cs
         JOIN tbl_prospectus_offering po ON po.offering_id = cs.offering_id
         WHERE po.ay_id = ? AND po.semester = ?"
    );
    $stmt->bind_param("is", $ay_id, $sem);
    $stmt->execute();
    $stats['total_schedules'] = $stmt->get_result()->fetch_assoc()['total'];

    // schedules with faculty
    $stmt = $conn->prepare(
        "SELECT COUNT(cs.schedule_id) AS total
         FROM tbl_class_schedule cs
         JOIN tbl_prospectus_offering po ON po.offering_id = cs.offering_id
         WHERE po.ay_id = ? AND po.semester = ?
         AND cs.faculty_id IS NOT NULL"
    );
    $stmt->bind_param("is", $ay_id, $sem);
    $stmt->execute();
    $stats['with_faculty'] = $stmt->get_result()->fetch_assoc()['total'];

    $stats['without_faculty'] =
        $stats['total_schedules'] - $stats['with_faculty'];

    return $stats;
}

/* ======================================================
   LOAD DATA
====================================================== */
$totalOfferings = getTotalOfferings($conn, $ay_id, $sem);
$totalSections  = getTotalSections($conn, $ay_id, $sem);
$scheduleStats  = getScheduleStats($conn, $ay_id, $sem);
?>
<!DOCTYPE html>
<html lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/">

<head>
  <meta charset="utf-8" />
  <title>Academic Operations Summary</title>
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
<div class="container-xxl container-p-y">

<!-- ======================================================
   REPORT HEADER
====================================================== -->
<div class="card mb-4">
  <div class="card-body">
    <h4 class="fw-bold">Academic Operations Summary</h4>
    <p class="text-muted mb-0">
      Academic Year: <strong><?= htmlspecialchars($ay); ?></strong> |
      Semester: <strong><?= htmlspecialchars($sem); ?></strong>
    </p>
  </div>
</div>

<!-- ======================================================
   KPI SUMMARY
====================================================== -->
<div class="row g-4 mb-4">

  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body text-center">
        <h6 class="text-muted">Total Offerings</h6>
        <h2 class="fw-bold"><?= $totalOfferings; ?></h2>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body text-center">
        <h6 class="text-muted">Total Sections</h6>
        <h2 class="fw-bold"><?= $totalSections; ?></h2>
      </div>
    </div>
  </div>

  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body text-center">
        <h6 class="text-muted">Total Schedules</h6>
        <h2 class="fw-bold"><?= $scheduleStats['total_schedules']; ?></h2>
      </div>
    </div>
  </div>

</div>

<!-- ======================================================
   INSTRUCTIONAL DEPLOYMENT
====================================================== -->
<div class="card mb-4">
  <div class="card-body">
    <h5 class="fw-bold mb-3">Instructional Deployment Status</h5>

    <ul class="list-group">
      <li class="list-group-item d-flex justify-content-between">
        <span>Schedules with Faculty Assigned</span>
        <strong><?= $scheduleStats['with_faculty']; ?></strong>
      </li>
      <li class="list-group-item d-flex justify-content-between">
        <span>Schedules without Faculty Assigned</span>
        <strong><?= $scheduleStats['without_faculty']; ?></strong>
      </li>
    </ul>
  </div>
</div>

<!-- ======================================================
   BACK BUTTON
====================================================== -->
<a href="institutional_report.php?ay=<?= urlencode($ay); ?>&sem=<?= urlencode($sem); ?>"
   class="btn btn-secondary">
  ← Back to Report Hub
</a>

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
