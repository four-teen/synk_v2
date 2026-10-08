<?php
    session_start();
    ob_start();
    include '../backend/db.php';
    require_once '../backend/academic_term_helper.php';

    $currentAyId = 0;
    $currentSem = 0;
    $academicYearLabel = '';
    $semesterLabel = '';
    $academicTermText = 'Current academic term';
    $academicTermTextEscaped = htmlspecialchars($academicTermText, ENT_QUOTES, 'UTF-8');
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../index.php");
        exit;
    }

    if ($_SESSION['role'] !== 'admin') {
        header("Location: ../index.php");
        exit;
    }

    $currentTerm = synk_fetch_current_academic_term($conn);
    $currentAyId = (int)($currentTerm['ay_id'] ?? 0);
    $currentSem = (int)($currentTerm['semester'] ?? 0);
    $academicYearLabel = (string)($currentTerm['ay_label'] ?? '');
    $semesterLabel = (string)($currentTerm['semester_label'] ?? '');
    $academicTermText = trim((string)($currentTerm['term_text'] ?? 'Current academic term'));
    $academicTermTextEscaped = htmlspecialchars($academicTermText, ENT_QUOTES, 'UTF-8');

    // Load all campuses
    $campus_sql = $conn->query("SELECT campus_id, campus_code, campus_name FROM tbl_campus WHERE status = 'active' ORDER BY campus_id ASC");
    $campuses = [];
    while ($row = $campus_sql->fetch_assoc()) {
        $campuses[] = $row;
    }

    $campusAnalytics = [];
    foreach ($campuses as $campus) {
        $campusId = (int)$campus['campus_id'];
        $campusAnalytics[$campusId] = [
            'campus_id' => $campusId,
            'campus_name' => $campus['campus_name'],
            'faculty_count' => 0,
            'section_count' => 0,
            'schedule_count' => 0,
        ];
    }
    
/*
|--------------------------------------------------------------------------
| FACULTY COUNT PER CAMPUS (CURRENT TERM ONLY)
|--------------------------------------------------------------------------
| Source:
| - tbl_faculty_workload_sched (term-based workload)
| - resolved via class_schedule → prospectus_offering → sections → program → college → campus
|--------------------------------------------------------------------------
*/

if ($currentAyId && $currentSem) {

    $facultySql = "
        SELECT
            camp.campus_id,
            COUNT(DISTINCT fws.faculty_id) AS faculty_count
        FROM tbl_faculty_workload_sched fws

        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fws.schedule_id

        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id

        INNER JOIN tbl_sections s
            ON s.section_id = po.section_id

        INNER JOIN tbl_program p
            ON p.program_id = s.program_id

        INNER JOIN tbl_college col
            ON col.college_id = p.college_id

        INNER JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id

        WHERE fws.ay_id = ?
          AND fws.semester = ?

        GROUP BY camp.campus_id
    ";

    $stmt = $conn->prepare($facultySql);
    $stmt->bind_param("ii", $currentAyId, $currentSem);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $campusId = (int)$row['campus_id'];
        $facultyCount = (int)$row['faculty_count'];

        if (isset($campusAnalytics[$campusId])) {
            $campusAnalytics[$campusId]['faculty_count'] = $facultyCount;
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| ACTIVE SECTIONS PER CAMPUS (CURRENT TERM ONLY)
|--------------------------------------------------------------------------
| Source:
| - tbl_prospectus_offering
| - section campus resolved via sections → program → college → campus
|--------------------------------------------------------------------------
*/

if ($currentAyId && $currentSem) {

    $sectionsPerCampusSql = "
        SELECT
            camp.campus_id,
            COUNT(DISTINCT s.section_id) AS section_count
        FROM tbl_prospectus_offering po

        INNER JOIN tbl_sections s
            ON s.section_id = po.section_id

        INNER JOIN tbl_program p
            ON p.program_id = s.program_id

        INNER JOIN tbl_college col
            ON col.college_id = p.college_id

        INNER JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id

        WHERE po.ay_id = ?
          AND po.semester = ?
          AND p.status = 'active'

        GROUP BY camp.campus_id
    ";

    $stmt = $conn->prepare($sectionsPerCampusSql);
    $stmt->bind_param("ii", $currentAyId, $currentSem);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $campusId = (int)$row['campus_id'];
        if (isset($campusAnalytics[$campusId])) {
            $campusAnalytics[$campusId]['section_count'] = (int)$row['section_count'];
        }
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| SCHEDULE COUNT PER CAMPUS (CURRENT TERM ONLY)
|--------------------------------------------------------------------------
| Source:
| - tbl_class_schedule
| - term resolved via prospectus offering
|--------------------------------------------------------------------------
*/

if ($currentAyId && $currentSem) {

    $schedulesPerCampusSql = "
        SELECT
            camp.campus_id,
            COUNT(cs.schedule_id) AS schedule_count
        FROM tbl_class_schedule cs

        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id

        INNER JOIN tbl_sections s
            ON s.section_id = po.section_id

        INNER JOIN tbl_program p
            ON p.program_id = s.program_id

        INNER JOIN tbl_college col
            ON col.college_id = p.college_id

        INNER JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id

        WHERE po.ay_id = ?
          AND po.semester = ?

        GROUP BY camp.campus_id
    ";

    $stmt = $conn->prepare($schedulesPerCampusSql);
    $stmt->bind_param("ii", $currentAyId, $currentSem);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $campusId = (int)$row['campus_id'];
        if (isset($campusAnalytics[$campusId])) {
            $campusAnalytics[$campusId]['schedule_count'] = (int)$row['schedule_count'];
        }
    }

    $stmt->close();
}

$campusAnalyticsMap = $campusAnalytics;
$campusAnalytics = array_values($campusAnalyticsMap);

$activeCampusCount = 0;
$campusesWithSchedules = 0;
foreach ($campusAnalytics as $campusMetric) {
    $facultyCount = (int)($campusMetric['faculty_count'] ?? 0);
    $sectionCount = (int)($campusMetric['section_count'] ?? 0);
    $scheduleCount = (int)($campusMetric['schedule_count'] ?? 0);

    if (($facultyCount + $sectionCount + $scheduleCount) > 0) {
        $activeCampusCount++;
    }

    if ($scheduleCount > 0) {
        $campusesWithSchedules++;
    }
}

/*
|--------------------------------------------------------------------------
| TOTAL FACULTY (INSTITUTION-WIDE, CURRENT TERM)
|--------------------------------------------------------------------------
| Definition:
| - Counts DISTINCT faculty_id
| - From tbl_faculty_workload_sched
| - Filtered by current academic year and semester
|--------------------------------------------------------------------------
*/

$totalFaculty = 0;

if ($currentAyId && $currentSem) {

    $totalFacultySql = "
        SELECT COUNT(DISTINCT faculty_id) AS total_faculty
        FROM tbl_faculty_workload_sched
        WHERE ay_id = ?
          AND semester = ?
    ";

    $stmt = $conn->prepare($totalFacultySql);
    $stmt->bind_param("ii", $currentAyId, $currentSem);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $totalFaculty = (int)$row['total_faculty'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| TOTAL PROGRAMS OFFERED (CURRENT TERM)
|--------------------------------------------------------------------------
| Definition:
| - DISTINCT programs with at least one offering
| - Based on tbl_prospectus_offering
| - Filtered by current academic year and semester
|--------------------------------------------------------------------------
*/

$totalPrograms = 0;

if ($currentAyId && $currentSem) {

    $programSql = "
        SELECT COUNT(DISTINCT p.program_id) AS total_programs
        FROM tbl_prospectus_offering po

        INNER JOIN tbl_sections s
            ON s.section_id = po.section_id

        INNER JOIN tbl_program p
            ON p.program_id = s.program_id

        WHERE po.ay_id = ?
          AND po.semester = ?
          AND p.status = 'active'
    ";

    $stmt = $conn->prepare($programSql);
    $stmt->bind_param("ii", $currentAyId, $currentSem);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $totalPrograms = (int)$row['total_programs'];
    }

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| TOTAL ACTIVE SECTIONS (INSTITUTION-WIDE, CURRENT TERM)
|--------------------------------------------------------------------------
| Definition:
| - DISTINCT sections with at least one prospectus offering
| - Based on tbl_prospectus_offering
| - Filtered by current academic year and semester
| - Option A: Offering-based (no schedule/faculty required)
|--------------------------------------------------------------------------
*/

$totalSections = 0;

if ($currentAyId && $currentSem) {

    $sectionSql = "
        SELECT COUNT(DISTINCT s.section_id) AS total_sections
        FROM tbl_prospectus_offering po

        INNER JOIN tbl_sections s
            ON s.section_id = po.section_id

        INNER JOIN tbl_program p
            ON p.program_id = s.program_id

        WHERE po.ay_id = ?
          AND po.semester = ?
          AND p.status = 'active'
    ";

    $stmt = $conn->prepare($sectionSql);
    $stmt->bind_param("ii", $currentAyId, $currentSem);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $totalSections = (int)$row['total_sections'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| TOTAL SCHEDULES (SCHEDULED CLASSES)
|--------------------------------------------------------------------------
| Definition:
| - Counts class schedules
| - Includes unassigned faculty
| - Includes no room
| - Schedule belongs to an offering
| - Offering defines ay_id and semester
|--------------------------------------------------------------------------
*/

$totalSchedules = 0;

if ($currentAyId && $currentSem) {

    $scheduleSql = "
        SELECT COUNT(cs.schedule_id) AS total_schedules
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
        WHERE po.ay_id = ?
          AND po.semester = ?
    ";

    $stmt = $conn->prepare($scheduleSql);
    $stmt->bind_param("ii", $currentAyId, $currentSem);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $totalSchedules = (int)$row['total_schedules'];
    }

    $stmt->close();
}





?>

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

    <title>Admin Dashboard</title>

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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">

    <script src="../assets/vendor/js/helpers.js"></script>

     <script src="../assets/js/config.js"></script>
      <style>
.campus-scroll-area {
  max-height: 700px;
  overflow-y: auto;
  padding: 1rem 1.25rem 1.25rem;
  scrollbar-gutter: stable;
  display: flex;
  flex-direction: column;
  gap: 0.95rem;
  background: linear-gradient(180deg, #fbfcff 0%, #f6f8fc 100%);
}

.snapshot-overview-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.75rem;
}

.snapshot-overview-item {
  border: 1px solid #e6ebf4;
  border-radius: 14px;
  padding: 0.85rem 0.9rem;
  background: #fff;
  box-shadow: 0 8px 18px rgba(67, 89, 113, 0.06);
}

.snapshot-overview-item span {
  display: block;
  color: #8c9ab0;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.snapshot-overview-item strong {
  display: block;
  margin-top: 0.35rem;
  color: #2f3e52;
  font-size: 1.2rem;
  font-weight: 800;
  line-height: 1.1;
}

.campus-card-link {
  text-decoration: none !important;
  color: inherit;
  display: block;
}

.campus-card {
  border: 1px solid #e4eaf5;
  border-radius: 18px;
  padding: 1rem;
  background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
  box-shadow: 0 10px 24px rgba(67, 89, 113, 0.08);
  transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.campus-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 16px 28px rgba(67, 89, 113, 0.14);
  border-color: #ccd7ea;
}

.campus-card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.85rem;
  margin-bottom: 0.9rem;
}

.campus-card-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  margin-bottom: 0.5rem;
}

.campus-code-pill,
.campus-status-pill {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 0.28rem 0.6rem;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.campus-code-pill {
  background: #eef3ff;
  color: #5f67df;
}

.campus-status-pill.is-live {
  background: #e8f7ec;
  color: #1f8a4c;
}

.campus-status-pill.is-planning {
  background: #fff4de;
  color: #b97700;
}

.campus-status-pill.is-idle {
  background: #f1f3f7;
  color: #7b8ca4;
}

.campus-card-title {
  margin: 0;
  color: #2f3e52;
  font-size: 1rem;
  font-weight: 700;
}

.campus-card-subtitle {
  margin: 0.32rem 0 0;
  color: #8392a8;
  font-size: 0.82rem;
  line-height: 1.45;
}

.campus-icon {
  width: 42px;
  height: 42px;
  border-radius: 14px;
  display: flex;
  justify-content: center;
  align-items: center;
  flex-shrink: 0;
  background: linear-gradient(135deg, #696cff 0%, #858cff 100%);
  box-shadow: 0 12px 20px rgba(105, 108, 255, 0.24);
}

.campus-metrics-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.65rem;
}

.campus-metric {
  border: 1px solid #e8edf6;
  border-radius: 14px;
  padding: 0.72rem 0.78rem;
  background: #fff;
}

.campus-metric span {
  display: block;
  color: #91a0b8;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}

.campus-metric strong {
  display: block;
  margin-top: 0.28rem;
  color: #2f3e52;
  font-size: 1.08rem;
  font-weight: 800;
  line-height: 1.1;
}

.campus-metric.is-faculty {
  background: #f4f3ff;
  border-color: #e2dcff;
}

.campus-metric.is-sections {
  background: #eef9e9;
  border-color: #d7edca;
}

.campus-metric.is-schedules {
  background: #fff6de;
  border-color: #ffe1a6;
}

.campus-summary-card {
  border-color: #d5efe2;
  background: linear-gradient(135deg, #f4fbf7 0%, #ffffff 100%);
}

.campus-summary-card .campus-icon {
  background: linear-gradient(135deg, #56ca00 0%, #72d929 100%);
  box-shadow: 0 12px 20px rgba(86, 202, 0, 0.22);
}

@media (max-width: 575.98px) {
  .campus-scroll-area {
    padding: 0.85rem;
  }

  .snapshot-overview-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .campus-card {
    padding: 0.9rem;
  }

  .campus-card-header {
    flex-direction: column;
  }

  .campus-icon {
    width: 38px;
    height: 38px;
    border-radius: 12px;
  }
}

.kpi-box {
  padding: 10px;
}

.kpi-icon {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  display: flex;
  justify-content: center;
  align-items: center;
  font-size: 22px;
  color: white;
  margin: auto;
}

.kpi-value {
  font-size: 1.75rem;
  font-weight: 700;
}

.cursor-pointer {
  cursor: pointer;
}

.kpi-box:hover {
  transform: translateY(-3px);
  transition: 0.2s ease;
  box-shadow: 0 6px 18px rgba(0,0,0,0.08);
}


      </style>    
  </head>
  <body>
    <!-- Layout wrapper -->
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <!-- Menu -->

          <?php 
            include 'sidebar.php';
          ?>
        <!-- / Menu -->

        <!-- Layout container -->
        <div class="layout-page">
          <!-- Navbar -->

          <?php 
            include 'navbar.php';
           ?>

          <!-- / Navbar -->

          <!-- Content wrapper -->
          <div class="content-wrapper">
            <!-- Content -->

            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="row">
                <div class="col-lg-8 mb-4 order-0">
                    <div class="row">

<div class="col-lg-12 order-0 mb-4">
  <div class="card shadow-sm institutional-card">
    <div class="d-flex align-items-center row">
      
      <!-- LEFT TEXT SECTION -->
      <div class="col-sm-8 px-4 py-4">
        <h4 class="text-primary fw-bold mb-2">
          SKSU Institutional Dashboard
        </h4>

        <p class="mb-3 text-muted">
          Welcome back, <strong><?php echo $_SESSION['username'] ?? 'Administrator'; ?></strong>!  
          Below is a consolidated overview of all SKSU campuses, academic operations, 
          and faculty distribution for this term.
        </p>

        <a href="institutional_report.php" class="btn btn-sm btn-primary">
          View Full Institutional Reports
        </a>
      </div>

      <!-- RIGHT IMAGE -->
      <div class="col-sm-4 text-center">
        <img 
          src="../assets/img/illustrations/man-with-laptop-light.png"
          height="130"
          alt="Institutional Overview"
          class="img-fluid p-2"
          data-app-dark-img="illustrations/man-with-laptop-dark.png"
          data-app-light-img="illustrations/man-with-laptop-light.png"
        />
      </div>

    </div>
  </div>
</div>



                    </div>
<div class="row">
  <!-- Academic Operations Overview -->
  <div class="col-lg-12 order-0 mb-4">
    <div class="card h-100 shadow-sm">
      
      <div class="card-header d-flex align-items-center justify-content-between pb-0">
        <div class="card-title mb-0">
          <h5 class="m-0 me-2">Academic Operations Overview</h5>
          <small class="text-muted">Institution-wide academic indicators</small>
        </div>
        <span class="badge bg-label-primary">KPI Summary</span>
      </div>

      <div class="card-body px-4 py-3">
        <div class="row gy-4">

          <!-- TOTAL FACULTY -->
          <div class="col-md-3 col-6">
            <div class="kpi-box text-center">
              <div class="kpi-icon bg-primary">
                <i class="bx bx-user-voice"></i>
              </div>
              <h3 class="kpi-value text-primary mt-2"><?php echo $totalFaculty; ?></h3>
              <small class="text-muted">Total Faculty</small>
            </div>
          </div>

<!-- TOTAL PROGRAMS -->
<div class="col-md-3 col-6">
  <div class="kpi-box text-center cursor-pointer" id="kpiProgramsOffered">
    <div class="kpi-icon bg-info">
      <i class="bx bx-book-open"></i>
    </div>
    <h3 class="kpi-value text-info mt-2"><?php echo $totalPrograms; ?></h3>
    <small class="text-muted">Programs Offered</small>
  </div>
</div>


          <!-- TOTAL SECTIONS -->
          <div class="col-md-3 col-6">
            <div class="kpi-box text-center cursor-pointer" id="kpiActiveSections">
              <div class="kpi-icon bg-success">
                <i class="bx bx-group"></i>
              </div>
              <h3 class="kpi-value text-success mt-2">
                <?php echo $totalSections; ?>
              </h3>
              <small class="text-muted">Active Sections</small>
            </div>
          </div>

          <!-- TOTAL SCHEDULES -->
          <div class="col-md-3 col-6">
            <div class="kpi-box text-center cursor-pointer" id="kpiSchedules">
              <div class="kpi-icon bg-warning">
                <i class="bx bx-calendar"></i>
              </div>
              <h3 class="kpi-value text-warning mt-2">
                <?php echo $totalSchedules; ?>
              </h3>
              <small class="text-muted">Schedules</small>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>

                <!-- Campus Operations Overview -->
<div class="col-lg-12 order-1 mb-4">
  <div class="card h-100 shadow-sm">

    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <h5 class="m-0">Campus Academic Activity</h5>
        <small class="text-muted">Faculty coverage, section volume, and schedules by campus</small>
      </div>
      <span class="badge bg-label-info"><?= $academicTermTextEscaped ?></span>
    </div>

    <div class="card-body">

      <!-- Chart Container -->
      <div id="campusOperationsChart"></div>

      <!-- Summary List -->
      <div class="mt-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-2">
          <h6 class="fw-bold mb-0">Campus Breakdown</h6>
          <small class="text-muted">Most useful first-glance view: staffing, sections, and schedules.</small>
        </div>

        <?php if (!empty($campusAnalytics)): ?>
        <div class="list-group">
          <?php foreach ($campusAnalytics as $campusMetric): ?>
          <div class="list-group-item">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
              <div>
                <span class="fw-semibold"><?php echo htmlspecialchars($campusMetric['campus_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                <div>
                  <small class="text-muted">Current-term operating footprint</small>
                </div>
              </div>

              <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                <span class="badge bg-label-primary"><?php echo (int)$campusMetric['faculty_count']; ?> Faculty</span>
                <span class="badge bg-label-success"><?php echo (int)$campusMetric['section_count']; ?> Sections</span>
                <span class="badge bg-label-warning"><?php echo (int)$campusMetric['schedule_count']; ?> Schedules</span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="alert alert-light border mb-0">
          No campus analytics are available for the selected academic term.
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>



                <!--/ Campus Operations Overview -->

</div>

                <!--/ Order Statistics -->
                </div>


                <!-- Transactions -->
                <!-- RIGHT COLUMN : EXECUTIVE CAMPUS SNAPSHOT -->
                <div class="col-md-6 col-lg-4 order-2 mb-4">
                  <div class="card h-100 shadow-sm">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <div>
                        <h5 class="card-title m-0">Campus Snapshot</h5>
                        <small class="text-muted">Current-term dashboard by campus</small>
                      </div>
                      <span class="badge bg-label-primary">Live View</span>
                    </div>

                    <div class="card-body campus-scroll-area">
                      <div class="snapshot-overview-grid">
                        <div class="snapshot-overview-item">
                          <span>Campuses</span>
                          <strong><?php echo count($campuses); ?></strong>
                        </div>
                        <div class="snapshot-overview-item">
                          <span>Active</span>
                          <strong><?php echo $activeCampusCount; ?></strong>
                        </div>
                        <div class="snapshot-overview-item">
                          <span>Schedules</span>
                          <strong><?php echo $totalSchedules; ?></strong>
                        </div>
                      </div>

                      <?php foreach ($campuses as $c): ?>
                      <?php
                        $campusId = (int)$c['campus_id'];
                        $campusMetric = $campusAnalyticsMap[$campusId] ?? [
                          'faculty_count' => 0,
                          'section_count' => 0,
                          'schedule_count' => 0
                        ];
                        $facCount = (int)($campusMetric['faculty_count'] ?? 0);
                        $sectionCount = (int)($campusMetric['section_count'] ?? 0);
                        $scheduleCount = (int)($campusMetric['schedule_count'] ?? 0);
                        $campusCode = trim((string)($c['campus_code'] ?? ''));

                        if ($scheduleCount > 0) {
                          $campusStatusClass = 'is-live';
                          $campusStatusText = 'Schedules live';
                          $campusSummaryText = $facCount . ' faculty supporting ' . $sectionCount . ' sections this term.';
                        } elseif ($sectionCount > 0) {
                          $campusStatusClass = 'is-planning';
                          $campusStatusText = 'For scheduling';
                          $campusSummaryText = $sectionCount . ' sections are ready for scheduling this term.';
                        } else {
                          $campusStatusClass = 'is-idle';
                          $campusStatusText = 'No activity yet';
                          $campusSummaryText = 'No current-term sections or schedules recorded yet.';
                        }
                      ?>
                      <a href="campus_dashboard.php?campus_id=<?php echo $c['campus_id']; ?>" class="campus-card-link">
                        <div class="campus-card shadow-sm">
                          <div class="campus-card-header">
                            <div>
                              <div class="campus-card-meta">
                                <?php if ($campusCode !== ''): ?>
                                  <span class="campus-code-pill"><?php echo htmlspecialchars($campusCode, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                                <span class="campus-status-pill <?php echo $campusStatusClass; ?>">
                                  <?php echo $campusStatusText; ?>
                                </span>
                              </div>
                              <h6 class="campus-card-title">
                                <?php echo htmlspecialchars($c['campus_name'], ENT_QUOTES, 'UTF-8'); ?>
                              </h6>
                              <p class="campus-card-subtitle mb-0">
                                <?php echo htmlspecialchars($campusSummaryText, ENT_QUOTES, 'UTF-8'); ?>
                              </p>
                            </div>

                            <div class="campus-icon bg-primary">
                              <i class="bx bx-chevron-right text-white"></i>
                            </div>
                          </div>

                          <div class="campus-metrics-grid">
                            <div class="campus-metric is-faculty">
                              <span>Faculty</span>
                              <strong><?php echo $facCount; ?></strong>
                            </div>
                            <div class="campus-metric is-sections">
                              <span>Sections</span>
                              <strong><?php echo $sectionCount; ?></strong>
                            </div>
                            <div class="campus-metric is-schedules">
                              <span>Schedules</span>
                              <strong><?php echo $scheduleCount; ?></strong>
                            </div>
                          </div>
                        </div>
                      </a>
                      <?php endforeach; ?>

                      <!-- ALL CAMPUS SUMMARY -->
                      <a href="campus_dashboard.php?campus_id=all" class="campus-card-link">
                        <div class="campus-card campus-summary-card shadow-sm">
                          <div class="campus-card-header">
                            <div>
                              <div class="campus-card-meta">
                                <span class="campus-code-pill">SKSU</span>
                                <span class="campus-status-pill is-live">
                                  <?php echo $campusesWithSchedules; ?> campuses with schedules
                                </span>
                              </div>
                              <h6 class="campus-card-title">University Summary</h6>
                              <p class="campus-card-subtitle mb-0">Overall institutional performance across all active campuses.</p>
                            </div>
                            <div class="campus-icon bg-success">
                              <i class="bx bx-globe text-white"></i>
                            </div>
                          </div>

                          <div class="campus-metrics-grid">
                            <div class="campus-metric is-faculty">
                              <span>Faculty</span>
                              <strong><?php echo $totalFaculty; ?></strong>
                            </div>
                            <div class="campus-metric is-sections">
                              <span>Sections</span>
                              <strong><?php echo $totalSections; ?></strong>
                            </div>
                            <div class="campus-metric is-schedules">
                              <span>Schedules</span>
                              <strong><?php echo $totalSchedules; ?></strong>
                            </div>
                          </div>
                        </div>
                      </a>

                    </div>
                  </div>
                </div>
                <!--/ Transactions -->

              </div>
              <div class="row">

              </div>
            </div>
            <!-- / Content -->

            <!-- Footer -->
                        <?php 
                          include '../footer.php';
                        ?>
            <!-- / Footer -->

            <div class="content-backdrop fade"></div>
          </div>
          <!-- Content wrapper -->
        </div>
        <!-- / Layout page -->
      </div>

      <!-- Overlay -->
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>
    <!-- / Layout wrapper -->


<!-- ======================================================
     PROGRAMS OFFERED MODAL (KPI DRILL-DOWN)
====================================================== -->
<div class="modal fade" id="programsOfferedModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">

      <!-- MODAL HEADER -->
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bx bx-book-open me-2 text-info"></i>
          Programs Offered
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <!-- MODAL BODY -->
      <div class="modal-body">

        <!-- TERM CONTEXT -->
        <div class="alert alert-light border d-flex align-items-center mb-3">
          <i class="bx bx-calendar me-2 text-primary"></i>
          <strong class="me-2">Academic Term:</strong>
          <span id="modalAcademicTerm">
            <?= $academicTermTextEscaped ?>
          </span>
        </div>

        <!-- PROGRAM TABLE -->
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle">

            <thead class="table-light">
              <tr>
                <th>Program Code</th>
                <th>Program Name</th>
                <th>Major</th>
                <th>College</th>
                <th>Campus</th>
                <th class="text-center">Sections</th>
              </tr>
            </thead>

            <tbody id="programsOfferedTable">
              <!-- DATA WILL BE INJECTED HERE -->
              <tr>
                <td colspan="6" class="text-center text-muted py-4">
                  <i class="bx bx-loader-circle bx-spin me-1"></i>
                  Loading programs offered...
                </td>
              </tr>
            </tbody>

          </table>
        </div>

      </div>

      <!-- MODAL FOOTER -->
      <div class="modal-footer justify-content-between">
        <small class="text-muted">
          *Programs listed here have at least one active section this term.
        </small>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          Close
        </button>
      </div>

    </div>
  </div>
</div>

<!-- ======================================================
     ACTIVE SECTIONS MODAL (KPI DRILL-DOWN)
====================================================== -->
<div class="modal fade" id="activeSectionsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">

      <!-- MODAL HEADER -->
      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bx bx-group me-2 text-success"></i>
          Active Sections
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <!-- MODAL BODY -->
      <div class="modal-body">

        <!-- TERM CONTEXT -->
        <div class="alert alert-light border d-flex align-items-center mb-3">
          <i class="bx bx-calendar me-2 text-primary"></i>
          <strong class="me-2">Academic Term:</strong>
          <span id="sectionsAcademicTerm">
            <?= $academicTermTextEscaped ?>
          </span>
        </div>

        <!-- TABLE -->
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle" id="activeSectionsTable">

            <thead class="table-light">
              <tr>
                <th>Section</th>
                <th>Program</th>
                <th>College</th>
                <th>Campus</th>
                <th class="text-center">Offerings</th>
              </tr>
            </thead>

            <tbody>
              <tr>
                <td colspan="5" class="text-center text-muted py-4">
                  <i class="bx bx-loader-circle bx-spin me-1"></i>
                  Loading active sections...
                </td>
              </tr>
            </tbody>

          </table>
        </div>

      </div>

      <!-- MODAL FOOTER -->
      <div class="modal-footer justify-content-between">
        <small class="text-muted">
          *Sections listed here are offered in the current academic term.
        </small>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          Close
        </button>
      </div>

    </div>
  </div>
</div>

<!-- ======================================================
     SUBJECT OFFERINGS MODAL (LEVEL 3)
====================================================== -->
<div class="modal fade" id="sectionOfferingsModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bx bx-book-alt me-2 text-success"></i>
          Subjects Offered - <span id="sectionTitle"></span>
        </h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">

      <div class="alert alert-light border d-flex align-items-center mb-3">
        <i class="bx bx-calendar me-2 text-primary"></i>
        <strong class="me-2">Academic Term:</strong>
        <span><?= $academicTermTextEscaped ?></span>
      </div>

        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle" id="sectionOfferingsTable">
            <thead class="table-light">
              <tr>
                <th>Subject Code</th>
                <th>Subject Title</th>
                <th class="text-center">Units</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td colspan="3" class="text-center text-muted py-4">
                  <i class="bx bx-loader-circle bx-spin me-1"></i>
                  Loading subjects...
                </td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>

<!-- ======================================================
     SCHEDULES MODAL (KPI C)
====================================================== -->
<div class="modal fade" id="schedulesModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">
          <i class="bx bx-calendar me-2 text-warning"></i>
          Scheduled Classes
        </h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">

        <!-- TERM CONTEXT -->
        <div class="alert alert-light border d-flex align-items-center mb-3">
          <i class="bx bx-calendar me-2 text-primary"></i>
          <strong class="me-2">Academic Term:</strong>
          <span><?= $academicTermTextEscaped ?></span>
        </div>

        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle" id="schedulesTable">
            <thead class="table-light">
              <tr>
                <th>Subject</th>
                <th>Section</th>
                <th>Program</th>
                <th>College</th>
                <th>Campus</th>
                <th>Schedule</th>
                <th>Room</th>
                <th>Faculty</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td colspan="8" class="text-center text-muted py-4">
                  <i class="bx bx-loader-circle bx-spin me-1"></i>
                  Loading scheduled classes...
                </td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>

      <div class="modal-footer">
        <small class="text-muted">
          *Includes classes without faculty or room assignment.
        </small>
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>

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
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>


<script>
document.addEventListener("DOMContentLoaded", function () {

  var campusNames = <?= json_encode(array_column($campusAnalytics, 'campus_name')); ?>;
  var facultyCounts = <?= json_encode(array_column($campusAnalytics, 'faculty_count')); ?>;
  var sectionCounts = <?= json_encode(array_column($campusAnalytics, 'section_count')); ?>;
  var scheduleCounts = <?= json_encode(array_column($campusAnalytics, 'schedule_count')); ?>;

  if (!campusNames.length) {
    campusNames = ['No campuses'];
    facultyCounts = [0];
    sectionCounts = [0];
    scheduleCounts = [0];
  } else if (campusNames.length === 1) {
    campusNames = ['', campusNames[0]];
    facultyCounts = [0].concat(facultyCounts);
    sectionCounts = [0].concat(sectionCounts);
    scheduleCounts = [0].concat(scheduleCounts);
  }

  var chartMax = Math.max.apply(null, facultyCounts.concat(sectionCounts, scheduleCounts, [0])) + 2;

  var campusOperationsOptions = {
    series: [
      {
        name: 'Faculty Assigned',
        data: facultyCounts
      },
      {
        name: 'Active Sections',
        data: sectionCounts
      },
      {
        name: 'Scheduled Classes',
        data: scheduleCounts
      }
    ],

    chart: {
      type: 'line',
      height: 340,
      toolbar: { show: false },
      zoom: { enabled: false }
    },

    colors: ['#696cff', '#71dd37', '#ffab00'],

    stroke: {
      curve: 'smooth',
      width: 3,
      dashArray: [0, 4, 2]
    },

    markers: {
      size: 5,
      strokeWidth: 0,
      hover: {
        sizeOffset: 2
      }
    },

    dataLabels: {
      enabled: false
    },

    legend: {
      position: 'top',
      horizontalAlign: 'left'
    },

    tooltip: {
      shared: true,
      intersect: false
    },

    xaxis: {
      categories: campusNames,
      labels: {
        rotate: -12,
        style: { fontSize: '13px', colors: '#777' }
      }
    },

    yaxis: {
      min: 0,
      max: chartMax,
      tickAmount: 5,
      labels: {
        style: { colors: '#777' }
      },
      title: {
        text: 'Current Term Count',
        style: { color: '#777' }
      }
    },

    grid: {
      borderColor: '#eee',
      strokeDashArray: 4
    }
  };

  var campusOperationsChart = document.querySelector("#campusOperationsChart");
  if (campusOperationsChart) {
    var chart = new ApexCharts(campusOperationsChart, campusOperationsOptions);
    chart.render();
  }

  function formatDaysLabel(rawDays) {
    if (!rawDays) {
      return "TBA";
    }

    try {
      const parsed = JSON.parse(rawDays);
      if (Array.isArray(parsed) && parsed.length > 0) {
        return parsed.join("");
      }
    } catch (error) {
      // Keep fallback below when days are already plain text.
    }

    return String(rawDays);
  }

  function formatTimeLabel(rawTime) {
    if (!rawTime) {
      return "TBA";
    }

    const parts = String(rawTime).split(":");
    if (parts.length < 2) {
      return String(rawTime);
    }

    let hours = Number(parts[0]);
    const minutes = parts[1];
    if (Number.isNaN(hours)) {
      return String(rawTime);
    }

    const suffix = hours >= 12 ? "PM" : "AM";
    hours = hours % 12 || 12;
    return hours + ":" + minutes + " " + suffix;
  }

  function formatScheduleLabel(daysJson, timeStart, timeEnd) {
    return formatDaysLabel(daysJson) + " - " + formatTimeLabel(timeStart) + " to " + formatTimeLabel(timeEnd);
  }


  let programsDataTable = null;


  const programsKpi = document.getElementById("kpiProgramsOffered");
  const programsTableBody = document.getElementById("programsOfferedTable");

  if (programsKpi && programsTableBody) {
    programsKpi.addEventListener("click", function () {

    // Reset table with loading state
    programsTableBody.innerHTML = `
      <tr>
        <td colspan="6" class="text-center text-muted py-4">
          <i class="bx bx-loader-circle bx-spin me-1"></i>
          Loading programs offered...
        </td>
      </tr>
    `;

    // Show modal
    const modal = new bootstrap.Modal(
      document.getElementById("programsOfferedModal")
    );
    modal.show();

    // AJAX fetch
    fetch("../backend/get_programs_offered.php", { cache: "no-store" })
      .then(response => response.json())
      .then(result => {

        if (result.status !== 'success') {
          programsTableBody.innerHTML = `
            <tr>
              <td colspan="6" class="text-center text-danger py-4">
                Failed to load programs.
              </td>
            </tr>
          `;
          return;
        }

        if (result.data.length === 0) {
          programsTableBody.innerHTML = `
            <tr>
              <td colspan="6" class="text-center text-muted py-4">
                No programs offered this term.
              </td>
            </tr>
          `;
          return;
        }

        // Build rows
        let rows = '';
        result.data.forEach(p => {
          rows += `
            <tr>
              <td><strong>${p.program_code}</strong></td>
              <td>${p.program_name}</td>
              <td>${p.major ?? '-'}</td>
              <td>${p.college_name}</td>
              <td>${p.campus_name}</td>
              <td class="text-center">
                <span class="badge bg-primary">${p.section_count}</span>
              </td>
            </tr>
          `;
        });

        programsTableBody.innerHTML = rows;
/* =====================================================
   DATATABLE-AWARE INITIALIZATION
===================================================== */

// Destroy old DataTable if exists
if (programsDataTable) {
  programsDataTable.destroy();
}

// Initialize DataTable AFTER rows are ready
programsDataTable = $('#programsOfferedModal table').DataTable({
  pageLength: 10,
  lengthChange: true,
  searching: true,
  ordering: true,
  responsive: true,
  dom: 'Bfrtip',
  buttons: [
    { extend: 'copy',  className: 'btn btn-sm btn-outline-secondary' },
    { extend: 'excel', className: 'btn btn-sm btn-outline-success' },
    { extend: 'pdf',   className: 'btn btn-sm btn-outline-danger' },
    { extend: 'print', className: 'btn btn-sm btn-outline-primary' }
  ]
});


      })
      .catch(() => {
        programsTableBody.innerHTML = `
          <tr>
            <td colspan="6" class="text-center text-danger py-4">
              AJAX error occurred.
            </td>
          </tr>
        `;
      });

    });
  }

/* =====================================================
   ACTIVE SECTIONS KPI → MODAL → AJAX → DATATABLE
===================================================== */

let sectionsDataTable = null;

const sectionsKpi = document.getElementById("kpiActiveSections");

if (sectionsKpi) {

  sectionsKpi.addEventListener("click", function () {

    const tableBody = document.querySelector("#activeSectionsTable tbody");

    tableBody.innerHTML = `
      <tr>
        <td colspan="5" class="text-center text-muted py-4">
          <i class="bx bx-loader-circle bx-spin me-1"></i>
          Loading active sections...
        </td>
      </tr>
    `;

    const modal = new bootstrap.Modal(
      document.getElementById("activeSectionsModal")
    );
    modal.show();

    fetch("../backend/get_active_sections.php", { cache: "no-store" })
      .then(res => res.json())
      .then(result => {

        if (result.status !== 'success') {
          tableBody.innerHTML = `
            <tr>
              <td colspan="5" class="text-center text-danger py-4">
                Failed to load active sections.
              </td>
            </tr>
          `;
          return;
        }

        if (result.data.length === 0) {
          tableBody.innerHTML = `
            <tr>
              <td colspan="5" class="text-center text-muted py-4">
                No active sections this term.
              </td>
            </tr>
          `;
          return;
        }

        let rows = '';
        result.data.forEach(s => {
          rows += `
            <tr>
              <td><strong>${s.section_name}</strong></td>
              <td>${s.program_code} - ${s.program_name}</td>
              <td>${s.college_name}</td>
              <td>${s.campus_name}</td>
      <td class="text-center">
        <span
          class="badge bg-success cursor-pointer offerings-badge"
          data-section-id="${s.section_id}"
          data-section-name="${s.section_name}"
        >
          ${s.offering_count}
        </span>
      </td>
            </tr>
          `;
        });

        tableBody.innerHTML = rows;

        // Datatable re-init
        if (sectionsDataTable) {
          sectionsDataTable.destroy();
        }

        sectionsDataTable = $('#activeSectionsTable').DataTable({
          pageLength: 10,
          searching: true,
          ordering: true,
          responsive: true,
          dom: 'Bfrtip',
          buttons: [
            { extend: 'copy',  className: 'btn btn-sm btn-outline-secondary' },
            { extend: 'excel', className: 'btn btn-sm btn-outline-success' },
            { extend: 'pdf',   className: 'btn btn-sm btn-outline-danger' },
            { extend: 'print', className: 'btn btn-sm btn-outline-primary' }
          ]
        });

      })
      .catch(() => {
        tableBody.innerHTML = `
          <tr>
            <td colspan="5" class="text-center text-danger py-4">
              AJAX error occurred.
            </td>
          </tr>
        `;
      });

  });

}

/* =====================================================
   LEVEL 3 DRILL-DOWN: SECTION → SUBJECT OFFERINGS
   -----------------------------------------------------
   FIX:
   - Completely resets DataTable state
   - Prevents old data from persisting
   - Guarantees fresh data per section
===================================================== */

let sectionOfferingsDT = null;

$(document)
  .off("click", ".offerings-badge")
  .on("click", ".offerings-badge", function () {

    const sectionId   = this.dataset.sectionId;
    const sectionName = this.dataset.sectionName;

    $("#sectionTitle").text(sectionName);

    const table = $("#sectionOfferingsTable");
    const tbody = table.find("tbody");

    /* -----------------------------------------
       🔥 HARD RESET (THIS IS THE KEY)
    ----------------------------------------- */
    if ($.fn.DataTable.isDataTable(table)) {
      table.DataTable().clear().destroy();
    }

    tbody.empty(); // REMOVE old rows completely

    tbody.html(`
      <tr>
        <td colspan="3" class="text-center text-muted py-4">
          <i class="bx bx-loader-circle bx-spin me-1"></i>
          Loading subjects...
        </td>
      </tr>
    `);

    new bootstrap.Modal(
      document.getElementById("sectionOfferingsModal")
    ).show();

    fetch(
      `../backend/get_section_offerings.php?section_id=${sectionId}`,
      { cache: "no-store" }
    )
      .then(res => res.json())
      .then(result => {

        if (result.status !== "success") {
          tbody.html(`
            <tr>
              <td colspan="3" class="text-center text-danger py-4">
                Failed to load subjects.
              </td>
            </tr>
          `);
          return;
        }

        if (!result.data || result.data.length === 0) {
          tbody.html(`
            <tr>
              <td colspan="3" class="text-center text-muted py-4">
                No subjects found.
              </td>
            </tr>
          `);
          return;
        }

        /* -----------------------------------------
           RENDER FRESH ROWS ONLY
        ----------------------------------------- */
        let rows = "";
        result.data.forEach(r => {
          rows += `
            <tr>
              <td><strong>${r.sub_code}</strong></td>
              <td>${r.sub_description}</td>
              <td class="text-center">${r.total_units}</td>
            </tr>
          `;
        });

        tbody.html(rows);

        /* -----------------------------------------
           RE-INIT DATATABLE (AFTER DATA IS IN DOM)
        ----------------------------------------- */
        sectionOfferingsDT = table.DataTable({
          destroy: true,
          pageLength: 10,
          searching: true,
          ordering: true,
          responsive: true,
          dom: "Bfrtip",
          buttons: ["copy", "excel", "pdf", "print"]
        });

      })
      .catch(err => {
        console.error("Section offerings error:", err);
        tbody.html(`
          <tr>
            <td colspan="3" class="text-center text-danger py-4">
              Failed to load subjects.
            </td>
          </tr>
        `);
      });

  });


/* =====================================================
   SCHEDULES KPI -> MODAL -> AJAX
===================================================== */

let schedulesDT = null;

const schedulesKpi = document.getElementById("kpiSchedules");

if (schedulesKpi) {
  schedulesKpi.addEventListener("click", function () {

    const table = $("#schedulesTable");
    const tbody = table.find("tbody");

    if ($.fn.DataTable.isDataTable(table)) {
      table.DataTable().clear().destroy();
    }

    tbody.html(`
      <tr>
        <td colspan="8" class="text-center text-muted py-4">
          <i class="bx bx-loader-circle bx-spin me-1"></i>
          Loading scheduled classes...
        </td>
      </tr>
    `);

    new bootstrap.Modal(
      document.getElementById("schedulesModal")
    ).show();

    fetch("../backend/get_faculty_workloads.php", { cache: "no-store" })
      .then(res => res.json())
      .then(result => {

        if (result.status !== "success") {
          tbody.html(`
            <tr>
              <td colspan="8" class="text-center text-danger py-4">
                Failed to load scheduled classes.
              </td>
            </tr>
          `);
          return;
        }

        if (!result.data || result.data.length === 0) {
          tbody.html(`
            <tr>
              <td colspan="8" class="text-center text-muted py-4">
                No scheduled classes found for the current term.
              </td>
            </tr>
          `);
          return;
        }

        let rows = "";
        result.data.forEach(r => {
          const schedule = formatScheduleLabel(r.days_json, r.time_start, r.time_end);
          const room = r.room_name ?? "TBA";
          const faculty = r.faculty_name ?? "Unassigned";

          rows += `
            <tr>
              <td><strong>${r.sub_code}</strong></td>
              <td>${r.section_name}</td>
              <td>
                <strong>${r.program_code}</strong><br>
                <small class="text-muted">${r.program_name}</small>
              </td>
              <td>${r.college_name}</td>
              <td>${r.campus_name}</td>
              <td>${schedule}</td>
              <td>${room}</td>
              <td>${faculty}</td>
            </tr>
          `;
        });

        tbody.html(rows);

        schedulesDT = table.DataTable({
          pageLength: 10,
          ordering: true,
          order: [[2, 'asc']],
          dom: "Bfrtip",
          buttons: ["copy", "excel", "pdf", "print"]
        });

      })
      .catch(() => {
        tbody.html(`
          <tr>
            <td colspan="8" class="text-center text-danger py-4">
              Failed to load scheduled classes.
            </td>
          </tr>
        `);
      });

  });
}

});
</script>



  </body>
</html>
