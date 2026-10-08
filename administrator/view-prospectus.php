<?php
session_start();
include '../backend/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$pid = intval($_GET['pid'] ?? 0);
if ($pid <= 0) {
    echo "Invalid Prospectus.";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed">
<head>
<meta charset="utf-8" />
    <title>Prospectus Builder | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />

    <link rel="stylesheet" type="text/css" href="custom_css.css">
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

<style>
.prospectus-title {
    text-align:center;
    font-weight:bold;
    margin-bottom:20px;
}

.semester-header {
    background:#e8f5e9;
    padding:6px;
    font-weight:bold;
    text-align:center;
    border:1px solid #ccc;
}

table.prospectus-table th,
table.prospectus-table td {
    font-size:12px;
    padding:4px;
}

.total-row {
    background:#f1f8e9;
    font-weight:bold;
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
<div class="container-xxl container-p-y">

<div id="prospectusContent"></div>

</div>
</div>
</div>
<!-- JS -->
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

function formatUnits(value) {
  const numeric = Number(value) || 0;
  return Number.isInteger(numeric) ? String(numeric) : numeric.toFixed(2).replace(/\.?0+$/, "");
}

function renderSemesterTable(list) {

  let lecSum = 0, labSum = 0, unitSum = 0;

  let rows = "";

  if (!list || list.length === 0) {
    rows = `
      <tr>
        <td colspan="6" class="text-center text-muted py-3">
          No subjects encoded yet for this semester.
        </td>
      </tr>
    `;
  } else {
    list.forEach(r => {
      const lec = Number(r.lec_units) || 0;
      const lab = Number(r.lab_units) || 0;
      const totalUnits = Number(r.total_units) || 0;

      lecSum  += lec;
      labSum  += lab;
      unitSum += totalUnits;

      rows += `
        <tr>
          <td>${r.sub_code}</td>
          <td>${r.sub_description}</td>
          <td class="text-center">${formatUnits(lec)}</td>
          <td class="text-center">${formatUnits(lab)}</td>
          <td class="text-center">${formatUnits(totalUnits)}</td>
          <td>${r.prerequisites || 'None'}</td>
        </tr>
      `;
    });

    rows += `
      <tr class="total-row">
        <td colspan="2" class="text-end">Total</td>
        <td class="text-center">${formatUnits(lecSum)}</td>
        <td class="text-center">${formatUnits(labSum)}</td>
        <td class="text-center">${formatUnits(unitSum)}</td>
        <td></td>
      </tr>
    `;
  }

  return `
    <div class="table-responsive">
      <table class="table table-bordered table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:120px;">Course Code</th>
            <th>Course Title</th>
            <th class="text-center" style="width:60px;">Lec Hrs</th>
            <th class="text-center" style="width:60px;">Lab Hrs</th>
            <th class="text-center" style="width:70px;">Units</th>
            <th style="width:140px;">Pre-Req</th>
          </tr>
        </thead>
        <tbody>
          ${rows}
        </tbody>
      </table>
    </div>
  `;
}


$(function () {

  $.getJSON("../backend/query_view_prospectus.php", { prospectus_id: <?= (int)$pid ?> })
    .done(function(payload){

      if (payload.error) {
        $("#prospectusContent").html(`<div class="alert alert-danger">${payload.error}</div>`);
        return;
      }

      const header = payload.header;
      const structure = payload.structure || {};
      const subjects = payload.subjects || {};

      let programLabel = header.program_name + " (" + header.program_code + ")";
      if (header.major && header.major.trim() !== "") {
        programLabel = header.program_name + " major in " + header.major + " (" + header.program_code + ")";
      }

      let html = `
        <div class="card mb-3">
          <div class="card-body">
            <h5 class="mb-1 fw-bold">${programLabel}</h5>
            <div class="text-muted">
              <span class="me-3"><b>CMO:</b> ${header.cmo_no}</span>
              <span><b>Effectivity:</b> ${header.effective_sy}</span>
            </div>
          </div>
        </div>
      `;

      const semLabel = (s) => s === "1" ? "1st Semester" : (s === "2" ? "2nd Semester" : "Summer");

Object.keys(structure)
  .sort((a,b)=>parseInt(a)-parseInt(b))
  .forEach(year => {

    html += `<h5 class="mt-4 mb-3 fw-bold">YEAR ${year}</h5>`;
    html += `<div class="row">`;

    /* ========= 1ST & 2ND SEMESTER SIDE-BY-SIDE ========= */
    ["1", "2"].forEach(sem => {

      if (!structure[year][sem]) return;

      const list = (subjects[year] && subjects[year][sem]) ? subjects[year][sem] : [];
      const semTitle = sem === "1" ? "1st Semester" : "2nd Semester";

      html += `
        <div class="col-md-6 mb-3">
          <div class="card h-100">
            <div class="card-header py-2" style="background:#e8f5e9;">
              <b>${semTitle}</b>
            </div>
            <div class="card-body p-0">
              ${renderSemesterTable(list)}
            </div>
          </div>
        </div>
      `;
    });

    html += `</div>`;

    /* ========= SUMMER (FULL WIDTH) ========= */
    if (structure[year]["3"]) {

      const list = (subjects[year] && subjects[year]["3"]) ? subjects[year]["3"] : [];

      html += `
        <div class="row">
          <div class="col-12 mb-3">
            <div class="card">
              <div class="card-header py-2" style="background:#fff3cd;">
                <b>Summer</b>
              </div>
              <div class="card-body p-0">
                ${renderSemesterTable(list)}
              </div>
            </div>
          </div>
        </div>
      `;
    }

});


      $("#prospectusContent").html(html);
    })
    .fail(function(xhr){
      $("#prospectusContent").html(`<div class="alert alert-danger">AJAX failed</div>`);
      console.log(xhr.responseText);
    });

});
</script>

</body>
</html>
