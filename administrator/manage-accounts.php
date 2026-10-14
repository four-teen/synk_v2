<?php 
    session_start();
    ob_start();
    include '../backend/db.php';
    require_once '../backend/professor_portal_helper.php';

    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: ../index.php');
        exit;
    }

    $collegeOptionsHtml = '';
    $collegeQuery = $conn->query("
        SELECT
            c.college_id,
            c.college_code,
            c.college_name,
            cp.campus_name
        FROM tbl_college c
        LEFT JOIN tbl_campus cp ON cp.campus_id = c.campus_id
        WHERE c.status = 'active'
        ORDER BY cp.campus_name ASC, c.college_name ASC, c.college_code ASC
    ");

    while ($collegeRow = $collegeQuery->fetch_assoc()) {
        $label = trim((string)$collegeRow['college_code']);
        $collegeName = trim((string)$collegeRow['college_name']);
        $campusName = trim((string)($collegeRow['campus_name'] ?? ''));

        if ($label !== '' && $collegeName !== '') {
            $label .= ' - ' . $collegeName;
        } elseif ($collegeName !== '') {
            $label = $collegeName;
        }

        if ($campusName !== '') {
            $label .= ' (' . $campusName . ')';
        }

        $collegeOptionsHtml .= "<option value='" . (int)$collegeRow['college_id'] . "'>" .
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
            "</option>";
    }

    $campusOptionsHtml = '';
    if (synk_table_exists($conn, 'tbl_campus')) {
        $campusQuery = $conn->query("
            SELECT
                campus_id,
                COALESCE(campus_code, '') AS campus_code,
                COALESCE(campus_name, '') AS campus_name
            FROM tbl_campus
            ORDER BY campus_name ASC, campus_code ASC, campus_id ASC
        ");

        while ($campusQuery instanceof mysqli_result && ($campusRow = $campusQuery->fetch_assoc())) {
            $label = trim((string)$campusRow['campus_code']);
            $campusName = trim((string)($campusRow['campus_name'] ?? ''));

            if ($label !== '' && $campusName !== '') {
                $label .= ' - ' . $campusName;
            } elseif ($campusName !== '') {
                $label = $campusName;
            }

            $campusOptionsHtml .= "<option value='" . (int)$campusRow['campus_id'] . "'>" .
                htmlspecialchars($label !== '' ? $label : ('Campus #' . (int)$campusRow['campus_id']), ENT_QUOTES, 'UTF-8') .
                "</option>";
        }

        if ($campusQuery instanceof mysqli_result) {
            $campusQuery->close();
        }
    }

    $facultyOptionsHtml = '';
    if (synk_table_exists($conn, 'tbl_faculty')) {
        $facultyQuery = $conn->query("
            SELECT
                faculty_id,
                first_name,
                COALESCE(middle_name, '') AS middle_name,
                last_name,
                COALESCE(ext_name, '') AS ext_name,
                COALESCE(status, 'active') AS status
            FROM tbl_faculty
            WHERE COALESCE(status, 'active') = 'active'
            ORDER BY last_name ASC, first_name ASC, middle_name ASC, ext_name ASC
        ");

        while ($facultyQuery instanceof mysqli_result && ($facultyRow = $facultyQuery->fetch_assoc())) {
            $facultyName = synk_professor_full_name_from_row($facultyRow);
            $facultyLabel = trim($facultyName . ' (Faculty ID: ' . (int)($facultyRow['faculty_id'] ?? 0) . ')');

            $facultyOptionsHtml .= "<option value='" . (int)$facultyRow['faculty_id'] . "' data-faculty-name='" . htmlspecialchars($facultyName, ENT_QUOTES, 'UTF-8') . "'>" .
                htmlspecialchars($facultyLabel, ENT_QUOTES, 'UTF-8') .
                "</option>";
        }

        if ($facultyQuery instanceof mysqli_result) {
            $facultyQuery->close();
        }
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
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />

    <title>User Accounts | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
      .accounts-header {
        gap: 1rem;
      }

      .accounts-header .btn {
        white-space: nowrap;
      }

      .accounts-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
        width: 100%;
        max-width: 32rem;
        margin-left: auto;
        min-width: 0;
      }

      .accounts-card-top {
        gap: 1rem;
      }

      .accounts-card-copy {
        min-width: 0;
      }

      .accounts-search-group {
        flex: 1 1 18rem;
        min-width: 0;
      }

      .accounts-search-group .input-group-text {
        border-right: 0;
        background: #fff;
      }

      .accounts-search-group .form-control {
        border-left: 0;
      }

      .accounts-search-group .form-control:focus {
        box-shadow: none;
      }

      #btnClearAccountSearch {
        flex: 0 0 auto;
        white-space: nowrap;
      }

      .accounts-loader {
        padding: 0.85rem 1rem;
        border: 1px solid rgba(105, 108, 255, 0.16);
        border-radius: 0.75rem;
        background: rgba(105, 108, 255, 0.04);
      }

      .accounts-loader .progress {
        height: 0.5rem;
        margin-top: 0.6rem;
      }

      .accounts-loader .progress-bar {
        transition: width 0.2s ease;
      }

      @media (prefers-reduced-motion: reduce) {
        .accounts-loader .progress-bar {
          transition: none;
          animation: none;
        }
      }

      .accounts-table-wrap {
        border: 1px solid rgba(105, 108, 255, 0.08);
        border-radius: 1rem;
        overflow: hidden;
      }

      #accountsTable {
        min-width: 960px;
      }

      #accountsTable th.account-role-heading,
      #accountsTable td.account-role-cell {
        min-width: 76px;
        padding-left: 0.35rem !important;
        padding-right: 0.35rem !important;
        text-align: center;
        vertical-align: middle;
      }

      #accountsTable th.account-role-heading {
        white-space: normal;
        font-size: 0.72rem;
        line-height: 1.15;
      }

      .account-role-heading-label {
        display: block;
        min-height: 2.1rem;
      }

      .account-role-toggle,
      .role-all-toggle {
        width: 1rem;
        height: 1rem;
        cursor: pointer;
      }

      .account-role-default {
        display: block;
        margin-top: 0.15rem;
        color: #696cff;
        font-size: 0.6rem;
        line-height: 1;
      }

      .account-role-choice {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        margin: 0;
        font-size: 0.72rem;
        white-space: nowrap;
      }

      .account-role-choice input:disabled + span {
        color: #a1acb8;
      }

      .account-mobile-role-choices {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem 0.7rem;
        margin-top: 0.45rem;
      }

      .account-mobile-role-bulk {
        border: 1px solid rgba(105, 108, 255, 0.12);
        border-radius: 0.75rem;
        background: #fff;
        padding: 0.85rem 1rem;
      }

      .account-mobile-role-bulk-options {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem 0.8rem;
      }

      .account-role-help {
        color: #697a8d;
        font-size: 0.78rem;
      }

      .account-faculty-link {
        line-height: 1.1;
      }

      .account-faculty-name {
        display: block;
        max-width: 200px;
        margin-top: 0.2rem;
        overflow: hidden;
        white-space: nowrap;
        text-overflow: ellipsis;
        font-size: 0.78rem;
        font-style: italic;
        color: #697a8d;
      }

      #accountsTable td {
        padding-top: 0.5rem !important;
        padding-bottom: 0.5rem !important;
      }

      #accountsTable tbody tr:last-child td {
        border-bottom: 0;
      }

      #accountsTable td:last-child {
        white-space: nowrap !important;
      }

      .account-display-name,
      .account-mobile-name {
        white-space: nowrap;
        text-transform: uppercase;
        line-height: 1.3;
      }

      .account-email,
      .account-mobile-email {
        margin-top: 0;
        font-style: italic;
        line-height: 1.3;
      }

      .account-email {
        font-size: 0.85rem;
        color: #697a8d;
        white-space: nowrap;
        text-transform: lowercase;
      }

      .account-role-list > div:last-child {
        margin-bottom: 0 !important;
      }

      .account-access {
        margin-top: 0.2rem;
      }

      .account-scope-codes {
        white-space: nowrap;
        overflow-x: auto;
        max-width: 100%;
      }

      .account-mobile-grid > div {
        min-width: 0;
      }

      .account-mobile-identity {
        min-width: 0;
        max-width: 100%;
      }

      .account-mobile-name {
        overflow-x: auto;
      }

      .account-mobile-list {
        display: grid;
        gap: 1rem;
      }

      .account-mobile-card {
        border: 1px solid rgba(105, 108, 255, 0.12);
        border-radius: 1rem;
        box-shadow: 0 10px 24px rgba(67, 89, 113, 0.08);
      }

      .account-mobile-card .card-body {
        padding: 1rem;
      }

      .account-mobile-top {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
      }

      .account-mobile-index {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 2rem;
        height: 2rem;
        padding: 0 0.65rem;
        border-radius: 999px;
        background: rgba(105, 108, 255, 0.12);
        color: #696cff;
        font-size: 0.8rem;
        font-weight: 700;
      }

      .account-mobile-name {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        color: #435971;
      }

      .account-mobile-email {
        color: #566a7f;
        font-size: 0.9rem;
        word-break: break-word;
      }

      .account-mobile-badges {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: 0.4rem;
      }

      .account-mobile-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid rgba(67, 89, 113, 0.08);
      }

      .account-mobile-meta-label {
        display: block;
        margin-bottom: 0.25rem;
        color: #8592a3;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
      }

      .account-mobile-meta-value {
        color: #435971;
        font-size: 0.92rem;
        font-weight: 600;
        word-break: break-word;
      }

      .account-mobile-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: 1rem;
      }

      .account-mobile-actions .btn {
        flex: 1 1 0;
      }

      .accounts-empty-state {
        border: 1px dashed rgba(67, 89, 113, 0.2);
        border-radius: 1rem;
        padding: 1.25rem;
        text-align: center;
        color: #8592a3;
        background: rgba(245, 246, 255, 0.7);
      }

      .college-access-select {
        min-height: 11rem;
      }

      .select2-container {
        width: 100% !important;
      }

      .select2-container--default .select2-selection--multiple {
        min-height: 3rem;
        border: 1px solid #d9dee3;
        border-radius: 0.5rem;
        padding: 0.35rem 0.5rem;
      }

      .select2-container--default .select2-selection--single {
        min-height: 3rem;
        border: 1px solid #d9dee3;
        border-radius: 0.5rem;
        padding: 0.5rem 2.35rem 0.5rem 0.75rem;
        display: flex;
        align-items: center;
      }

      .select2-container--default.select2-container--focus .select2-selection--multiple {
        border-color: #696cff;
        box-shadow: 0 0 0 0.2rem rgba(105, 108, 255, 0.16);
      }

      .select2-container--default.select2-container--focus .select2-selection--single,
      .select2-container--default.select2-container--open .select2-selection--single {
        border-color: #696cff;
        box-shadow: 0 0 0 0.2rem rgba(105, 108, 255, 0.16);
      }

      .select2-container--default .select2-selection--single .select2-selection__rendered {
        color: #435971;
        line-height: 1.5rem;
        padding-left: 0;
        padding-right: 0;
      }

      .select2-container--default .select2-selection--single .select2-selection__placeholder {
        color: #8592a3;
      }

      .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 100%;
        right: 0.75rem;
      }

      .select2-container--default .select2-selection--multiple .select2-selection__choice {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        margin-top: 0;
        margin-right: 0;
        background: rgba(105, 108, 255, 0.1);
        border: 0;
        border-radius: 999px;
        color: #435971;
        padding: 0.3rem 0.75rem;
      }

      .select2-container--default .select2-selection--multiple .select2-selection__rendered {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.35rem;
        padding: 0;
      }

      .select2-container--default .select2-selection--multiple .select2-selection__choice__display {
        padding: 0;
      }

      .select2-container--default .select2-selection--multiple .select2-search--inline {
        margin: 0;
      }

      .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        position: static;
        border: 0;
        background: transparent;
        color: #696cff;
        margin: 0;
        padding: 0;
        font-size: 1rem;
        line-height: 1;
      }

      .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
        color: #5f61e6;
        background: transparent;
      }

      .select2-dropdown {
        border: 1px solid #d9dee3;
        border-radius: 0.75rem;
        overflow: hidden;
        box-shadow: 0 0.5rem 1.2rem rgba(67, 89, 113, 0.16);
      }

      .college-access-dropdown {
        min-width: 0 !important;
      }

      .college-access-dropdown .select2-results,
      .faculty-record-dropdown .select2-results {
        max-height: 18rem;
        overflow-y: auto;
      }

      .select2-search--dropdown .select2-search__field {
        border: 1px solid #d9dee3;
        border-radius: 0.5rem;
        padding: 0.45rem 0.65rem;
      }

      .select2-results__option {
        padding: 0.55rem 0.75rem;
      }

      .account-role-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.75rem;
      }

      .account-role-card {
        display: block;
        padding: 0.85rem 0.95rem;
        border: 1px solid rgba(67, 89, 113, 0.16);
        border-radius: 0.85rem;
        background: rgba(245, 247, 255, 0.8);
        cursor: pointer;
        transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
      }

      .account-role-card:hover {
        border-color: rgba(105, 108, 255, 0.44);
        box-shadow: 0 10px 20px rgba(67, 89, 113, 0.08);
        transform: translateY(-1px);
      }

      .account-role-card .form-check-input {
        margin-top: 0.1rem;
      }

      .account-role-card .form-check-label {
        display: block;
        cursor: pointer;
      }

      .account-modal {
        --account-modal-gap: 1rem;
      }

      .account-modal .modal-dialog {
        width: calc(100% - 2 * var(--account-modal-gap));
        height: calc(100vh - 2 * var(--account-modal-gap));
        height: calc(100dvh - 2 * var(--account-modal-gap));
        min-height: 0;
        margin: var(--account-modal-gap) auto;
        padding: 0 !important;
      }

      .account-modal .modal-body {
        min-height: 0;
        overscroll-behavior: contain;
      }

      .account-modal .modal-footer {
        flex-shrink: 0;
        padding: 1rem 1.5rem;
        border-top: 1px solid #d9dee3;
        background: #fff;
      }

      .account-role-title {
        display: block;
        color: #435971;
        font-size: 0.94rem;
        font-weight: 700;
      }

      .account-role-note {
        display: block;
        margin-top: 0.2rem;
        color: #697a8d;
        font-size: 0.82rem;
        line-height: 1.45;
      }

      @media (max-width: 767.98px) {
        .accounts-header {
          align-items: stretch !important;
        }

        .accounts-header .btn {
          width: 100%;
        }

        .accounts-toolbar {
          width: 100%;
          max-width: none;
        }

        .accounts-search-group {
          flex-basis: 100%;
        }

        .account-mobile-top {
          flex-direction: column;
        }

        .account-mobile-badges {
          justify-content: flex-start;
        }

        .account-mobile-grid {
          grid-template-columns: 1fr;
        }

        .account-mobile-actions {
          flex-direction: column;
        }
      }
    </style>
</head>

<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>
    <!-- /Sidebar -->

    <!-- Layout page -->
    <div class="layout-page">

      <!-- Navbar -->
      <?php include 'navbar.php'; ?>
      <!-- /Navbar -->

      <!-- Content wrapper -->
      <div class="content-wrapper">

        <div class="container-xxl flex-grow-1 container-p-y">

          <!-- Title + Add Button -->
          <div class="accounts-header d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
            <h4 class="fw-bold mb-0">
              <i class="bx bx-user-circle me-2"></i> Access Accounts
            </h4>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
              <i class="bx bx-plus"></i> Add Account
            </button>
          </div>

          <!-- Card: Accounts List -->
          <div class="card">
            <div class="card-header">
              <div class="accounts-card-top d-flex flex-column flex-xl-row justify-content-between align-items-xl-center">
                <div class="accounts-card-copy">
                  <h5 class="m-0">Accounts List</h5>
                  <small class="text-muted">Search and manage shared administrator, scheduler, professor, program chair, college dean, registrar, VPAA, and DI accounts</small>
                </div>
                <div class="accounts-toolbar align-items-stretch">
                  <div class="input-group accounts-search-group">
                    <span class="input-group-text">
                      <i class="bx bx-search"></i>
                    </span>
                    <input
                      type="search"
                      id="accountSearch"
                      class="form-control"
                      placeholder="Search name, email, role, faculty, or assigned scope"
                      autocomplete="off"
                    >
                  </div>
                  <button type="button" class="btn btn-outline-secondary" id="btnClearAccountSearch">
                    Clear
                  </button>
                </div>
              </div>
              <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mt-3">
                <small class="text-muted" id="accountsMeta">Loading accounts...</small>
                <small class="text-muted" id="accountsScrollHint">Scroll to load more</small>
              </div>
              <div class="accounts-loader mt-3" id="accountsLoader">
                <div class="d-flex justify-content-between align-items-center gap-2">
                  <span class="small fw-semibold" id="accountsLoadingLabel" role="status" aria-live="polite">Loading account data...</span>
                  <span class="small fw-bold text-primary" id="accountsLoadingPercent" aria-hidden="true">0%</span>
                </div>
                <div class="progress">
                  <div class="progress-bar progress-bar-striped progress-bar-animated" id="accountsLoadingProgress" role="progressbar" aria-label="Estimated account loading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" style="width:0%"></div>
                </div>
                <small class="text-muted d-block mt-1" id="accountsLoadingEstimate">Estimated progress while waiting for account data.</small>
              </div>
              <div class="account-role-help mt-3">Professor checkboxes save immediately. The PROF header checkbox applies across the full account list; individual checkboxes apply to selected accounts. Professor assignment applies only to accounts linked to active faculty or matched to one unique active faculty record. Matching accounts are linked when assigned. Use Edit Account to assign Scheduler and manage other roles.</div>
            </div>

            <div class="card-body p-3">
              <div class="account-mobile-role-bulk d-lg-none mb-3">
                <div class="small fw-semibold mb-2">Assign or remove Professor for all eligible faculty accounts</div>
                <div class="account-mobile-role-bulk-options">
                  <label class="account-role-choice"><input type="checkbox" class="form-check-input role-all-toggle" data-role="professor" aria-label="Assign Professor to all eligible faculty accounts"><span>Professor</span></label>
                </div>
              </div>
              <div class="accounts-table-wrap d-none d-lg-block">
                <div class="table-responsive">
                  <table class="table table-hover align-middle mb-0" id="accountsTable">
                    <thead>
                      <tr>
                        <th style="width:60px;">#</th>
                        <th>Display Name</th>
                        <th class="account-role-heading" title="Professor"><span class="account-role-heading-label">PROF</span><input type="checkbox" class="form-check-input role-all-toggle" data-role="professor" aria-label="Assign Professor to all eligible faculty accounts"></th>
                        <th>Roles / Assignments</th>
                        <th>Faculty Link</th>
                        <th>Assigned Scope</th>
                        <th style="width:120px;">Status</th>
                        <th class="text-end" style="width:140px;">Actions</th>
                      </tr>
                    </thead>
                    <tbody></tbody>
                  </table>
                </div>
              </div>

              <div class="account-mobile-list d-lg-none" id="accountsMobileList"></div>
            </div>
          </div>

        </div>

        <!-- Footer -->
        <?php include '../footer.php'; ?>
        <!-- /Footer -->

        <div class="content-backdrop fade"></div>
      </div>
      <!-- /Content wrapper -->

    </div>
    <!-- /Layout page -->

  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
</div>

<!-- ========================= -->
<!-- ADD ACCOUNT MODAL         -->
<!-- ========================= -->
<div class="modal fade account-modal" id="addAccountModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Add Google Access Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="addAccountForm">
          <div class="row g-3">

            <div class="col-md-6">
              <label class="form-label">Display Name <span class="text-danger">*</span></label>
              <input type="text" name="username" id="add_username" class="form-control" required>
              <small class="text-muted d-block mt-1" id="add_username_help">Type the display name shown in account lists and the shared login.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label">SKSU Email <span class="text-danger">*</span></label>
              <input type="email" name="email" id="add_email" class="form-control" placeholder="name@sksu.edu.ph" required>
            </div>

            <div class="col-md-12">
              <label class="form-label">Module Roles <span class="text-danger">*</span></label>
              <div class="account-role-grid">
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="add" name="roles[]" id="add_role_admin" value="admin">
                    <div class="form-check-label">
                      <span class="account-role-title">Administrator</span>
                      <span class="account-role-note">Manage system setup, accounts, and academic configuration.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="add" name="roles[]" id="add_role_scheduler" value="scheduler">
                    <div class="form-check-label">
                      <span class="account-role-title">Scheduler</span>
                      <span class="account-role-note">Manage schedules and workload inside assigned college scopes.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="add" name="roles[]" id="add_role_professor" value="professor">
                    <div class="form-check-label">
                      <span class="account-role-title">Professor</span>
                      <span class="account-role-note">Use the shared login as a faculty account under the same email.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="add" name="roles[]" id="add_role_program_chair" value="program_chair">
                    <div class="form-check-label">
                      <span class="account-role-title">Program Chair</span>
                      <span class="account-role-note">Open the college portal and add faculty in the administrator-assigned college.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="add" name="roles[]" id="add_role_college_dean" value="college_dean">
                    <div class="form-check-label">
                      <span class="account-role-title">College Dean</span>
                      <span class="account-role-note">Open the shared login for college-dean enrollment and curriculum workflows.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="add" name="roles[]" id="add_role_registrar" value="registrar">
                    <div class="form-check-label">
                      <span class="account-role-title">Registrar</span>
                      <span class="account-role-note">Review submitted enrollment drafts inside the assigned campus queue.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="add" name="roles[]" id="add_role_VPAA" value="VPAA">
                    <div class="form-check-label">
                      <span class="account-role-title">VPAA</span>
                      <span class="account-role-note">Open the dedicated VPAA module with the shared login.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="add" name="roles[]" id="add_role_DI" value="DI">
                    <div class="form-check-label">
                      <span class="account-role-title">DI</span>
                      <span class="account-role-note">Open the dedicated DI module with the shared login.</span>
                    </div>
                  </div>
                </label>
              </div>
              <small class="text-muted">One SKSU email can carry multiple Synk roles.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label">Default Role on Login <span class="text-danger">*</span></label>
              <select name="primary_role" id="add_primary_role" class="form-select" required>
                <option value="">Select Default Role</option>
              </select>
              <small class="text-muted">This role appears first when the user signs in with one email and multiple roles.</small>
            </div>

            <div class="col-md-6" id="add_faculty_wrapper" style="display:none;">
              <label class="form-label">Faculty Record <span class="text-danger">*</span></label>
              <select name="faculty_id" id="add_faculty_id" class="form-select">
                <option value="">Select Faculty Record</option>
                <?= $facultyOptionsHtml ?>
              </select>
              <small class="text-muted">Selecting a faculty record will auto-fill the professor display name and link the workload correctly.</small>
            </div>

            <div class="col-md-6" id="add_program_chair_college_wrapper" style="display:none;">
              <label class="form-label">Assigned College <span class="text-danger">*</span></label>
              <select name="program_chair_college_id" id="add_program_chair_college_id" class="form-select">
                <option value="">Select Assigned College</option>
                <?= $collegeOptionsHtml ?>
              </select>
              <small class="text-muted">Program Chair will see all programs under this college and use it as the enrollment workspace base.</small>
            </div>

            <div class="col-md-6" id="add_college_dean_college_wrapper" style="display:none;">
              <label class="form-label">College Dean Assigned College <span class="text-danger">*</span></label>
              <select name="college_dean_college_id" id="add_college_dean_college_id" class="form-select">
                <option value="">Select Assigned College</option>
                <?= $collegeOptionsHtml ?>
              </select>
              <small class="text-muted">College Dean will see all programs under this college and use it as the enrollment workspace base.</small>
            </div>

            <div class="col-md-6" id="add_registrar_campus_wrapper" style="display:none;">
              <label class="form-label">Registrar Campus <span class="text-danger">*</span></label>
              <select name="registrar_campus_id" id="add_registrar_campus_id" class="form-select">
                <option value="">Select Registrar Campus</option>
                <?= $campusOptionsHtml ?>
              </select>
              <small class="text-muted">Registrar queue access is campus-based for now, so choose the campus this registrar account will review.</small>
            </div>

            <div class="col-md-12" id="add_college_wrapper" style="display:none;">
              <label class="form-label">Managed Colleges <span class="text-danger">*</span></label>
              <select name="college_ids[]" id="add_college_ids" class="form-select college-access-select" multiple>
                <?= $collegeOptionsHtml ?>
              </select>
              <small class="text-muted">Select every college this scheduler is allowed to manage.</small>
            </div>

            <div class="col-md-12" id="add_default_college_wrapper" style="display:none;">
              <label class="form-label">Default College on Login <span class="text-danger">*</span></label>
              <select name="default_college_id" id="add_default_college_id" class="form-select">
                <option value="">Select Default College</option>
              </select>
              <small class="text-muted">The scheduler can switch later, but this college opens first after sign in.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="status" id="add_status" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>

          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" form="addAccountForm" class="btn btn-primary" id="btnSaveAccount">Save</button>
      </div>

    </div>
  </div>
</div>

<!-- ========================= -->
<!-- EDIT ACCOUNT MODAL        -->
<!-- ========================= -->
<div class="modal fade account-modal" id="editAccountModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Edit Google Access Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="editAccountForm">
          <input type="hidden" name="user_id" id="edit_user_id">

          <div class="row g-3">

            <div class="col-md-6">
              <label class="form-label">Display Name <span class="text-danger">*</span></label>
              <input type="text" name="username" id="edit_username" class="form-control" required>
              <small class="text-muted d-block mt-1" id="edit_username_help">Type the display name shown in account lists and the shared login.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label">SKSU Email <span class="text-danger">*</span></label>
              <input type="email" name="email" id="edit_email" class="form-control" required>
            </div>

            <div class="col-md-12">
              <label class="form-label">Module Roles <span class="text-danger">*</span></label>
              <div class="account-role-grid">
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="edit" name="roles[]" id="edit_role_admin" value="admin">
                    <div class="form-check-label">
                      <span class="account-role-title">Administrator</span>
                      <span class="account-role-note">Manage system setup, accounts, and academic configuration.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="edit" name="roles[]" id="edit_role_scheduler" value="scheduler">
                    <div class="form-check-label">
                      <span class="account-role-title">Scheduler</span>
                      <span class="account-role-note">Manage schedules and workload inside assigned college scopes.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="edit" name="roles[]" id="edit_role_professor" value="professor">
                    <div class="form-check-label">
                      <span class="account-role-title">Professor</span>
                      <span class="account-role-note">Use the shared login as a faculty account under the same email.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="edit" name="roles[]" id="edit_role_program_chair" value="program_chair">
                    <div class="form-check-label">
                      <span class="account-role-title">Program Chair</span>
                      <span class="account-role-note">Open the college portal and add faculty in the administrator-assigned college.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="edit" name="roles[]" id="edit_role_college_dean" value="college_dean">
                    <div class="form-check-label">
                      <span class="account-role-title">College Dean</span>
                      <span class="account-role-note">Open the shared login for college-dean enrollment and curriculum workflows.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="edit" name="roles[]" id="edit_role_registrar" value="registrar">
                    <div class="form-check-label">
                      <span class="account-role-title">Registrar</span>
                      <span class="account-role-note">Review submitted enrollment drafts inside the assigned campus queue.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="edit" name="roles[]" id="edit_role_VPAA" value="VPAA">
                    <div class="form-check-label">
                      <span class="account-role-title">VPAA</span>
                      <span class="account-role-note">Open the dedicated VPAA module with the shared login.</span>
                    </div>
                  </div>
                </label>
                <label class="account-role-card">
                  <div class="form-check m-0">
                    <input type="checkbox" class="form-check-input account-role-checkbox" data-mode="edit" name="roles[]" id="edit_role_DI" value="DI">
                    <div class="form-check-label">
                      <span class="account-role-title">DI</span>
                      <span class="account-role-note">Open the dedicated DI module with the shared login.</span>
                    </div>
                  </div>
                </label>
              </div>
              <small class="text-muted">Assign every role this email should be able to open.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label">Default Role on Login <span class="text-danger">*</span></label>
              <select name="primary_role" id="edit_primary_role" class="form-select" required>
                <option value="">Select Default Role</option>
              </select>
              <small class="text-muted">This role becomes the default session choice when the user signs in.</small>
            </div>

            <div class="col-md-6" id="edit_faculty_wrapper" style="display:none;">
              <label class="form-label">Faculty Record <span class="text-danger">*</span></label>
              <select name="faculty_id" id="edit_faculty_id" class="form-select">
                <option value="">Select Faculty Record</option>
                <?= $facultyOptionsHtml ?>
              </select>
              <small class="text-muted">Selecting a faculty record will auto-fill the professor display name and keep the account-to-faculty link accurate.</small>
            </div>

            <div class="col-md-6" id="edit_program_chair_college_wrapper" style="display:none;">
              <label class="form-label">Assigned College <span class="text-danger">*</span></label>
              <select name="program_chair_college_id" id="edit_program_chair_college_id" class="form-select">
                <option value="">Select Assigned College</option>
                <?= $collegeOptionsHtml ?>
              </select>
              <small class="text-muted">Program Chair will see all programs under this college and use it as the enrollment workspace base.</small>
            </div>

            <div class="col-md-6" id="edit_college_dean_college_wrapper" style="display:none;">
              <label class="form-label">College Dean Assigned College <span class="text-danger">*</span></label>
              <select name="college_dean_college_id" id="edit_college_dean_college_id" class="form-select">
                <option value="">Select Assigned College</option>
                <?= $collegeOptionsHtml ?>
              </select>
              <small class="text-muted">College Dean will see all programs under this college and use it as the enrollment workspace base.</small>
            </div>

            <div class="col-md-6" id="edit_registrar_campus_wrapper" style="display:none;">
              <label class="form-label">Registrar Campus <span class="text-danger">*</span></label>
              <select name="registrar_campus_id" id="edit_registrar_campus_id" class="form-select">
                <option value="">Select Registrar Campus</option>
                <?= $campusOptionsHtml ?>
              </select>
              <small class="text-muted">Registrar queue access is campus-based for now, so choose the campus this registrar account will review.</small>
            </div>

            <div class="col-md-12" id="edit_college_wrapper" style="display:none;">
              <label class="form-label">Managed Colleges <span class="text-danger">*</span></label>
              <select name="college_ids[]" id="edit_college_ids" class="form-select college-access-select" multiple>
                <?= $collegeOptionsHtml ?>
              </select>
              <small class="text-muted">Select every college this scheduler is allowed to manage.</small>
            </div>

            <div class="col-md-12" id="edit_default_college_wrapper" style="display:none;">
              <label class="form-label">Default College on Login <span class="text-danger">*</span></label>
              <select name="default_college_id" id="edit_default_college_id" class="form-select">
                <option value="">Select Default College</option>
              </select>
              <small class="text-muted">This becomes the first workspace after sign in.</small>
            </div>

            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="status" id="edit_status" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>

          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" form="editAccountForm" class="btn btn-primary" id="btnUpdateAccount">Update</button>
      </div>

    </div>
  </div>
</div>


<!-- Core JS -->
<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="../assets/js/main.js"></script>

<script>

const accountState = {
  allAccounts: [],
  filteredAccounts: [],
  batchSize: 24,
  renderedCount: 0,
  roleMatrixBusy: false,
  loading: false
};

const accountLoadingState = {
  requestId: 0,
  percent: 0,
  timer: null,
  hideTimer: null
};

const ACCOUNT_ROLE_COLUMNS = [
  {code: "professor", label: "Professor"}
];

let accountSearchTimer = null;

function initializeCollegeAccessSelect2(mode) {
  const $select = $("#" + mode + "_college_ids");
  const $modal = $select.closest(".modal");

  if ($select.data("select2")) {
    $select.select2("destroy");
  }

  $select.select2({
    width: "100%",
    placeholder: "Search and select colleges",
    closeOnSelect: false,
    dropdownParent: $modal,
    dropdownAutoWidth: false,
    dropdownCssClass: "college-access-dropdown"
  });

  $select.off("select2:open.collegeWidth").on("select2:open.collegeWidth", function() {
    const dropdownWidth = $select.next(".select2-container").outerWidth();
    const $dropdown = $(".select2-container--open .select2-dropdown");

    if (dropdownWidth && $dropdown.length) {
      $dropdown.css({
        width: dropdownWidth + "px",
        minWidth: dropdownWidth + "px",
        maxWidth: dropdownWidth + "px"
      });
    }
  });
}

function initializeFacultyRecordSelect2(mode) {
  const $select = $("#" + mode + "_faculty_id");
  const $modal = $select.closest(".modal");

  if ($select.data("select2")) {
    $select.select2("destroy");
  }

  $select.select2({
    width: "100%",
    placeholder: "Search and select faculty record",
    dropdownParent: $modal,
    dropdownAutoWidth: false,
    dropdownCssClass: "faculty-record-dropdown"
  });

  $select.off("select2:open.facultyWidth").on("select2:open.facultyWidth", function() {
    const dropdownWidth = $select.next(".select2-container").outerWidth();
    const $dropdown = $(".select2-container--open .select2-dropdown");

    if (dropdownWidth && $dropdown.length) {
      $dropdown.css({
        width: dropdownWidth + "px",
        minWidth: dropdownWidth + "px",
        maxWidth: dropdownWidth + "px"
      });
    }
  });
}

// INITIAL LOAD
initializeCollegeAccessSelect2("add");
initializeCollegeAccessSelect2("edit");
initializeFacultyRecordSelect2("add");
initializeFacultyRecordSelect2("edit");
resetRoleFields("add");
resetRoleFields("edit");
loadAccounts();

$(window).on("scroll", function() {
  maybeLoadMoreAccounts();
});

$("#accountSearch").on("input", function() {
  window.clearTimeout(accountSearchTimer);
  accountSearchTimer = window.setTimeout(function() {
    applyAccountSearch();
  }, 120);
});

$("#btnClearAccountSearch").on("click", function() {
  $("#accountSearch").val("");
  applyAccountSearch();
  $("#accountSearch").trigger("focus");
});

// ------------------------------------
// LOAD ACCOUNTS LIST
// ------------------------------------
function providerLabelText(provider) {
  return String(provider || "").toLowerCase() === "google" ? "GOOGLE" : "LEGACY";
}

function buildProviderBadgeHtml(provider) {
  return String(provider || "").toLowerCase() === "google"
    ? "<span class='badge bg-label-primary'>GOOGLE</span>"
    : "<span class='badge bg-label-secondary'>LEGACY</span>";
}

function buildStatusBadgeHtml(status) {
  return String(status || "").toLowerCase() === "active"
    ? "<span class='badge bg-success'>ACTIVE</span>"
    : "<span class='badge bg-secondary'>INACTIVE</span>";
}

function buildAssignedScopeHtml(scopeRows) {
  if (scopeRows.length === 0) {
    return "<span class='text-muted'>N/A</span>";
  }

  const codes = scopeRows.map(function(row) {
    const code = String(row.code || '').trim() || '—';
    return `<span title="${escapeHtml(row.label)}">${escapeHtml(code)}</span>`;
  }).join(", ");
  return `<div class="small account-scope-codes">${codes}</div>`;
}

function buildCollegeAccessText(accessRows, fallbackLabel) {
  if (Array.isArray(accessRows) && accessRows.length > 0) {
    return accessRows.map(function(row) {
      const label = row && row.display_label ? String(row.display_label) : "College";
      return row && row.is_default ? label + " Default" : label;
    }).join(" ");
  }

  const safeFallback = $.trim(String(fallbackLabel || ""));
  return safeFallback !== "" ? safeFallback + " Assigned" : "N/A";
}

function buildFacultyLinkHtml(facultyId, displayName, facultyText, professorMatch, needsLink, reason) {
  const isLinked = Number(facultyId) > 0;
  const label = isLinked ? "LINKED" : "NOT LINK";
  const color = isLinked ? "success" : "danger";
  const name = String(displayName || facultyText || professorMatch || "").toLowerCase();
  const detail = isLinked ? facultyText : (needsLink && professorMatch
    ? "Will link to " + professorMatch + " when assigned as Professor."
    : (reason ? roleMutationReason(reason) : "No faculty record linked."));

  return `<div class="account-faculty-link"><span class="badge bg-${color}" title="${escapeHtml(detail)}">${label}</span><em class="account-faculty-name" title="${escapeHtml(name)}">${escapeHtml(name)}</em></div>`;
}

function normalizeRoleCode(role) {
  const value = String(role || "").trim().toLowerCase();
  return ["vpaa", "di"].indexOf(value) !== -1 ? value.toUpperCase() : value;
}

function roleLabel(role) {
  const value = normalizeRoleCode(role);

  if (value === "admin") {
    return "Administrator";
  }

  if (value === "scheduler") {
    return "Scheduler";
  }

  if (value === "professor") {
    return "Professor";
  }

  if (value === "program_chair") {
    return "Program Chair";
  }

  if (value === "college_dean") {
    return "College Dean";
  }

  if (value === "registrar") {
    return "Registrar";
  }

  return value ? value.toUpperCase() : "Role";
}

function normalizeRolePayload(rawAccount) {
  const rawPayload = Array.isArray(rawAccount && rawAccount.role_payload) ? rawAccount.role_payload : [];

  if (rawPayload.length > 0) {
    return rawPayload.map(function(item) {
      const role = normalizeRoleCode(item && item.role ? item.role : "");

      return {
        role: role,
        label: String(item && item.label ? item.label : roleLabel(role)),
        is_primary: Boolean(item && item.is_primary)
      };
    }).filter(function(item) {
      return item.role !== "";
    });
  }

  const roleValue = normalizeRoleCode(rawAccount && rawAccount.role ? rawAccount.role : "");
  if (roleValue === "") {
    return [];
  }

  return [{
    role: roleValue,
    label: roleLabel(roleValue),
    is_primary: true
  }];
}

function buildAccountRoleToggle(account, role, mobileView) {
  const roleCode = normalizeRoleCode(role.code);
  const isAssigned = account.roles.indexOf(roleCode) !== -1;
  const isManaged = account.roles.length > 0;
  const unavailable = roleCode === "professor" && !isAssigned && !account.professorEligible;
  const isDefault = account.primaryRoleValue === roleCode;
  const ariaLabel = (isAssigned ? "Remove " : "Assign ") + role.label + " role " +
    (mobileView ? "for " : "to ") + (account.username || account.email);
  const title = !isManaged ? "This account has no supported role in this role matrix." : (unavailable ? roleMutationReason(account.professorLinkReason) : ariaLabel);
  const input = `<input type="checkbox" class="form-check-input account-role-toggle" data-account-id="${escapeHtml(account.id)}" data-role="${escapeHtml(roleCode)}" aria-label="${escapeHtml(ariaLabel)}" title="${escapeHtml(title)}" ${isAssigned ? "checked" : ""} ${isManaged && !unavailable ? "" : "disabled"}>`;
  const defaultMark = isDefault ? `<span class="account-role-default" title="Default login role">Default</span>` : "";

  if (mobileView) {
    return `<label class="account-role-choice">${input}<span>${escapeHtml(role.label)}${isDefault ? " (Default)" : ""}</span></label>`;
  }

  return `<div>${input}${defaultMark}</div>`;
}

function updateRoleHeaderToggles() {
  const managedAccounts = accountState.allAccounts.filter(function(account) {
    return account.roles.length > 0;
  });

  ACCOUNT_ROLE_COLUMNS.forEach(function(role) {
    let targetAccounts = role.code === "professor" ? managedAccounts.filter(account => account.professorEligible) : managedAccounts;
    if (role.code === "professor" && targetAccounts.length === 0) {
      targetAccounts = managedAccounts.filter(account => account.roles.indexOf("professor") !== -1);
    }
    const assignedCount = targetAccounts.filter(function(account) {
      return account.roles.indexOf(role.code) !== -1;
    }).length;
    const $toggles = $(`.role-all-toggle[data-role="${role.code}"]`);
    $toggles.prop("checked", targetAccounts.length > 0 && assignedCount === targetAccounts.length);
    $toggles.prop("indeterminate", assignedCount > 0 && assignedCount < targetAccounts.length);
    $toggles.prop("disabled", accountState.loading || accountState.roleMatrixBusy || targetAccounts.length === 0);
    if (role.code === "professor") {
      $toggles.attr("title", `${targetAccounts.length} faculty accounts eligible for Professor assignment. The faculty link is saved with the role.`);
    }
  });

  $(".account-role-toggle").each(function() {
    const account = accountState.allAccounts.find(item => item.id === String($(this).data("account-id")));
    const unavailable = account && normalizeRoleCode($(this).data("role")) === "professor" && account.roles.indexOf("professor") === -1 && !account.professorEligible;
    $(this).prop("disabled", accountState.loading || accountState.roleMatrixBusy || !account || account.roles.length === 0 || unavailable);
  });
}

function roleMutationReason(reason) {
  const messages = {
    need_faculty: "Assign an active faculty record in Edit Account first.",
    invalid_faculty: "The faculty record is inactive or unavailable.",
    faculty_in_use: "This faculty record is already linked to another account.",
    no_faculty_match: "No matching faculty record. Select the correct faculty in Edit Account.",
    ambiguous_faculty_match: "Multiple faculty records match this name. Select the correct faculty in Edit Account.",
    ambiguous_account_match: "Multiple accounts match this faculty. Select the correct account and faculty in Edit Account.",
    need_college: "Assign at least one college in Edit Account first.",
    need_program_chair_college: "Assign a college in Edit Account first.",
    need_college_dean_college: "Assign the College Dean college in Edit Account first.",
    invalid_college_dean_college: "Select an active college for College Dean in Edit Account.",
    need_registrar_campus: "Assign a registrar campus in Edit Account first.",
    last_role: "Every account must keep at least one role.",
    unsupported_account: "This account has no supported role in the role matrix.",
    not_found: "The account no longer exists.",
    save_failed: "The role assignment could not be saved."
  };
  return messages[reason] || "The role assignment could not be completed.";
}

function setRoleMatrixBusy(isBusy) {
  accountState.roleMatrixBusy = Boolean(isBusy);
  updateRoleHeaderToggles();
}

function showRoleMutationResult(response, role, assigned, bulk) {
  const roleInfo = ACCOUNT_ROLE_COLUMNS.find(function(item) {
    return item.code === normalizeRoleCode(role);
  });
  const label = roleInfo ? roleInfo.label : roleLabel(role);
  const skipped = Array.isArray(response && response.skipped) ? response.skipped : [];
  const changed = Number(response && response.changed ? response.changed : 0);
  const unchanged = Number(response && response.unchanged ? response.unchanged : 0);
  const autoLinked = Number(response && response.auto_linked ? response.auto_linked : 0);
  const autoLinkNote = autoLinked > 0
    ? `<p class="small text-muted mt-2 mb-2">Automatically linked ${autoLinked} account${autoLinked === 1 ? "" : "s"} to a unique matching active faculty record.</p>`
    : "";

  if (skipped.length > 0) {
    const details = skipped.slice(0, 6).map(function(item) {
      const accountLabel = item.username ? escapeHtml(item.username) : "Account #" + escapeHtml(item.user_id || "?");
      return `<li><strong>${accountLabel}:</strong> ${escapeHtml(roleMutationReason(item.reason))}</li>`;
    }).join("");
    const more = skipped.length > 6 ? `<p class="small text-muted mt-2 mb-0">And ${skipped.length - 6} more skipped account${skipped.length - 6 === 1 ? "" : "s"}.</p>` : "";
    Swal.fire({
      icon: changed > 0 ? "warning" : "error",
      title: changed > 0 ? `${changed} changed, ${skipped.length} skipped` : "No accounts changed",
      html: `<p class="mb-2">${escapeHtml(label)} role ${assigned ? "assignment" : "removal"} was processed.</p>${autoLinkNote}<ul class="text-start mb-0">${details}</ul>${more}`
    });
    return;
  }

  const summary = bulk
    ? `${changed} changed${autoLinked ? `; ${autoLinked} auto-linked` : ""}${unchanged ? `; ${unchanged} already matched` : ""}.`
    : `${label} role ${assigned ? "assigned" : "removed"}.`;
  Swal.fire({
    toast: true,
    icon: "success",
    title: summary,
    position: "top-end",
    showConfirmButton: false,
    timer: 1800
  });
}

function submitRoleMatrixChange(role, assigned, userIds, bulk) {
  setRoleMatrixBusy(true);
  $.ajax({
    url: "../backend/query_accounts.php",
    type: "POST",
    dataType: "json",
    data: {
      set_role: 1,
      response_format: "json",
      role: role,
      assigned: assigned ? 1 : 0,
      user_ids: userIds
    }
  }).done(function(response) {
    if (!response || response.status !== "ok") {
      Swal.fire("Role Update Failed", roleMutationReason(response && response.message), "error");
      return;
    }
    showRoleMutationResult(response, role, assigned, bulk);
  }).fail(function(xhr) {
    if (xhr && xhr.status === 403) {
      Swal.fire("Session Expired", "Please sign in again.", "warning").then(function() {
        window.location = "../index.php";
      });
      return;
    }
    Swal.fire("Role Update Failed", "The role changes could not be saved. Please try again.", "error");
  }).always(function() {
    accountState.roleMatrixBusy = false;
    loadAccounts();
  });
}

function buildRoleHtml(rolePayload) {
  if (!Array.isArray(rolePayload) || rolePayload.length === 0) {
    return "<span class='text-muted'>N/A</span>";
  }

  return rolePayload.map(function(item) {
    const label = escapeHtml(item && item.label ? item.label : roleLabel(item && item.role ? item.role : ""));
    const defaultBadge = item && item.is_primary
      ? " <span class='badge bg-label-info ms-1'>Default</span>"
      : "";

    return "<div class='small text-wrap mb-1'><span class='badge bg-label-secondary'>" + label + "</span>" + defaultBadge + "</div>";
  }).join("");
}

function buildRoleText(rolePayload) {
  if (!Array.isArray(rolePayload) || rolePayload.length === 0) {
    return "N/A";
  }

  return rolePayload.map(function(item) {
    const label = item && item.label ? String(item.label) : roleLabel(item && item.role ? item.role : "");
    return item && item.is_primary ? label + " Default" : label;
  }).join(" + ");
}

function normalizeAccountRecord(rawAccount) {
  const accessRows = Array.isArray(rawAccount && rawAccount.college_access) ? rawAccount.college_access : [];
  const registrarScopeRows = Array.isArray(rawAccount && rawAccount.registrar_scope) ? rawAccount.registrar_scope : [];
  const provider = String(rawAccount && rawAccount.provider ? rawAccount.provider : "legacy");
  const rolePayload = normalizeRolePayload(rawAccount);
  const roles = rolePayload.map(function(item) {
    return String(item && item.role ? item.role : "");
  }).filter(Boolean);
  const roleText = buildRoleText(rolePayload);
  const statusValue = String(rawAccount && rawAccount.status ? rawAccount.status : "inactive").toLowerCase();
  const collegeIds = Array.isArray(rawAccount && rawAccount.college_ids)
    ? rawAccount.college_ids.map(function(value) { return String(value); })
    : [];
  const programChairCollegeId = String(rawAccount && rawAccount.program_chair_college_id ? rawAccount.program_chair_college_id : "");
  const programChairCollegeLabel = String(rawAccount && rawAccount.program_chair_college_label ? rawAccount.program_chair_college_label : "");
  const hasProgramChairRole = roles.indexOf("program_chair") !== -1;
  const collegeDeanCollegeId = String(rawAccount && rawAccount.college_dean_college_id ? rawAccount.college_dean_college_id : "");
  const collegeDeanCollegeLabel = String(rawAccount && rawAccount.college_dean_college_label ? rawAccount.college_dean_college_label : "");
  const hasCollegeDeanRole = roles.indexOf("college_dean") !== -1;
  const registrarCampusId = String(rawAccount && rawAccount.registrar_campus_id ? rawAccount.registrar_campus_id : "");
  const registrarCampusLabel = String(rawAccount && rawAccount.registrar_campus_label ? rawAccount.registrar_campus_label : "");
  const hasRegistrarRole = roles.indexOf("registrar") !== -1;
  const scopeTextParts = [];
  if (accessRows.length > 0) {
    scopeTextParts.push(buildCollegeAccessText(accessRows, ""));
  }
  if (hasProgramChairRole && programChairCollegeLabel !== "") {
    scopeTextParts.push(programChairCollegeLabel + " Assigned");
  }
  if (hasCollegeDeanRole && collegeDeanCollegeLabel !== "") {
    scopeTextParts.push(collegeDeanCollegeLabel + " College Dean");
  }
  if (hasRegistrarRole && registrarCampusLabel !== "") {
    scopeTextParts.push(registrarCampusLabel + " Registrar");
  }
  const collegeText = scopeTextParts.length ? scopeTextParts.join(" | ") : "N/A";
  const facultyId = String(rawAccount && rawAccount.faculty_id ? rawAccount.faculty_id : "");
  const facultyText = String(rawAccount && rawAccount.faculty_label ? rawAccount.faculty_label : "");
  const professorEligible = Boolean(rawAccount && rawAccount.professor_eligible);
  const professorMatch = String(rawAccount && rawAccount.professor_match_faculty_label ? rawAccount.professor_match_faculty_label : "");
  const professorNeedsLink = Boolean(rawAccount && rawAccount.professor_needs_link);
  const professorLinkReason = String(rawAccount && rawAccount.professor_link_reason ? rawAccount.professor_link_reason : "");
  const scopeRows = accessRows.map(function(row) {
    return {code: row.college_code, label: row.display_label || ''};
  });
  if (hasProgramChairRole && programChairCollegeId !== "" && !accessRows.some(function(row) {
    return String(row.college_id) === programChairCollegeId;
  })) {
    scopeRows.push({code: rawAccount.program_chair_college_code, label: programChairCollegeLabel});
  }
  if (hasCollegeDeanRole && collegeDeanCollegeId !== "") {
    scopeRows.push({code: rawAccount.college_dean_college_code, label: collegeDeanCollegeLabel + " (College Dean)"});
  }
  if (hasRegistrarRole) {
    registrarScopeRows.forEach(function(row) {
      scopeRows.push({code: row.campus_code, label: row.display_label || ''});
    });
  }

  return {
    id: String(rawAccount && rawAccount.id ? rawAccount.id : ""),
    username: String(rawAccount && rawAccount.username ? rawAccount.username : ""),
    email: String(rawAccount && rawAccount.email ? rawAccount.email : ""),
    accessHtml: buildProviderBadgeHtml(provider),
    accessText: providerLabelText(provider),
    roleHtml: buildRoleHtml(rolePayload),
    roleText: roleText,
    roles: roles,
    rolesAttr: JSON.stringify(roles),
    primaryRoleValue: normalizeRoleCode(rawAccount && rawAccount.primary_role ? rawAccount.primary_role : (roles[0] || "")),
    facultyHtml: buildFacultyLinkHtml(facultyId, rawAccount && rawAccount.username ? rawAccount.username : "", facultyText, professorMatch, professorNeedsLink, professorLinkReason),
    facultyText: facultyText || (professorNeedsLink ? professorMatch + " (Auto-link on assignment)" : (professorLinkReason ? roleMutationReason(professorLinkReason) : "N/A")),
    facultyIdValue: facultyId,
    professorEligible: professorEligible,
    professorLinkReason: professorLinkReason,
    collegeHtml: buildAssignedScopeHtml(scopeRows),
    collegeText: collegeText,
    collegeIds: collegeIds,
    collegeIdsAttr: JSON.stringify(collegeIds),
    defaultCollegeValue: String(rawAccount && rawAccount.default_college_id ? rawAccount.default_college_id : ""),
    programChairCollegeValue: programChairCollegeId,
    collegeDeanCollegeValue: collegeDeanCollegeId,
    registrarCampusValue: registrarCampusId,
    statusHtml: buildStatusBadgeHtml(statusValue),
    statusText: statusValue.toUpperCase(),
    statusValue: statusValue,
    searchText: [
      rawAccount && rawAccount.username ? rawAccount.username : "",
      rawAccount && rawAccount.email ? rawAccount.email : "",
      providerLabelText(provider),
      roleText,
      facultyText,
      professorMatch,
      collegeText,
      scopeRows.map(function(row) { return row.code || ''; }).join(' '),
      statusValue.toUpperCase()
    ].join(" ").toLowerCase()
  };
}

function updateAccountLoadingProgress(percent) {
  accountLoadingState.percent = Math.max(accountLoadingState.percent, Math.min(100, Math.round(percent)));
  $("#accountsLoadingPercent").text(accountLoadingState.percent + "%");
  $("#accountsLoadingProgress")
    .css("width", accountLoadingState.percent + "%")
    .attr("aria-valuenow", accountLoadingState.percent);
}

function startAccountLoading() {
  window.clearInterval(accountLoadingState.timer);
  window.clearTimeout(accountLoadingState.hideTimer);
  accountState.loading = true;
  accountLoadingState.percent = 0;
  $("#accountsLoader").removeClass("d-none");
  $("#accountsLoadingLabel").text("Loading account data...");
  $("#accountsLoadingEstimate").removeClass("d-none");
  $("#accountsLoadingProgress")
    .addClass("progress-bar-striped progress-bar-animated")
    .attr("aria-label", "Estimated account loading progress");
  $("#accountsTable, #accountsMobileList").attr("aria-busy", "true");
  updateAccountLoadingProgress(5);
  updateRoleHeaderToggles();

  accountLoadingState.timer = window.setInterval(function() {
    if (accountLoadingState.percent < 85) {
      updateAccountLoadingProgress(accountLoadingState.percent + Math.max(1, (85 - accountLoadingState.percent) * 0.08));
    }
  }, 350);
}

function finishAccountLoading(succeeded) {
  window.clearInterval(accountLoadingState.timer);
  accountLoadingState.timer = null;
  accountState.loading = false;
  $("#accountsTable, #accountsMobileList").attr("aria-busy", "false");
  $("#accountsLoadingProgress").removeClass("progress-bar-striped progress-bar-animated");
  $("#accountsLoadingEstimate").addClass("d-none");
  updateRoleHeaderToggles();

  if (!succeeded) {
    $("#accountsLoader").addClass("d-none");
    return;
  }

  updateAccountLoadingProgress(100);
  $("#accountsLoadingProgress").attr("aria-label", "Account loading complete");
  $("#accountsLoadingLabel").text("Account table ready.");
  accountLoadingState.hideTimer = window.setTimeout(function() {
    $("#accountsLoader").addClass("d-none");
    accountLoadingState.hideTimer = null;
  }, 900);
}

function loadAccounts() {
  const requestId = ++accountLoadingState.requestId;
  startAccountLoading();
  $("#accountsMeta").text("Loading accounts...");
  $("#accountsScrollHint").text("");

  $.ajax({
    url: "../backend/query_accounts.php",
    type: "POST",
    dataType: "json",
    data: { load_accounts: 1, response_format: "json" },
    success: function(response) {
      if (requestId !== accountLoadingState.requestId) {
        return;
      }
      if (response && response.status === "unauthorized") {
        finishAccountLoading(false);
        window.location = "../index.php";
        return;
      }

      if (!response || response.status !== "ok" || !Array.isArray(response.accounts)) {
        finishAccountLoading(false);
        renderAccountsLoadError();
        return;
      }

      $("#accountsLoadingLabel").text("Preparing account table...");
      updateAccountLoadingProgress(90);
      accountState.allAccounts = response.accounts.map(normalizeAccountRecord);
      applyAccountSearch();
      finishAccountLoading(true);
    },
    error: function(xhr) {
      if (requestId !== accountLoadingState.requestId) {
        return;
      }
      finishAccountLoading(false);
      if (xhr && xhr.status === 403) {
        window.location = "../index.php";
        return;
      }

      renderAccountsLoadError();
    }
  });
}

function applyAccountSearch() {
  const query = $.trim($("#accountSearch").val()).toLowerCase();

  if (query === "") {
    accountState.filteredAccounts = accountState.allAccounts.slice();
  } else {
    accountState.filteredAccounts = accountState.allAccounts.filter(function(account) {
      return account.searchText.indexOf(query) !== -1;
    });
  }

  updateRoleHeaderToggles();
  accountState.renderedCount = 0;
  renderNextAccountBatch(true);
}

function renderNextAccountBatch(reset) {
  const $tableBody = $("#accountsTable tbody");
  const $mobileList = $("#accountsMobileList");
  const filteredTotal = accountState.filteredAccounts.length;

  if (reset) {
    $tableBody.empty();
    $mobileList.empty();
  }

  if (filteredTotal === 0) {
    renderAccountsEmptyState();
    updateAccountsMeta();
    return;
  }

  const startIndex = accountState.renderedCount;
  const nextItems = accountState.filteredAccounts.slice(
    startIndex,
    startIndex + accountState.batchSize
  );

  if (nextItems.length === 0) {
    updateAccountsMeta();
    return;
  }

  let desktopHtml = "";
  let mobileHtml = "";

  $.each(nextItems, function(offset, account) {
    const rowNumber = startIndex + offset + 1;
    desktopHtml += buildDesktopRowHtml(account, rowNumber);
    mobileHtml += buildMobileCardHtml(account, rowNumber);
  });

  $tableBody.append(desktopHtml);
  $mobileList.append(mobileHtml);
  accountState.renderedCount += nextItems.length;

  updateRoleHeaderToggles();
  updateAccountsMeta();
  window.setTimeout(fillViewportAccounts, 0);
}

function buildDesktopRowHtml(account, rowNumber) {
  return `
    <tr>
      <td>${rowNumber}</td>
      <td>
        <div class="account-display-name fw-semibold">${escapeHtml(account.username.toUpperCase())}</div>
        <div class="account-email">${escapeHtml(account.email.toLowerCase())}</div>
      </td>
      ${ACCOUNT_ROLE_COLUMNS.map(function(role) { return `<td class="account-role-cell">${buildAccountRoleToggle(account, role, false)}</td>`; }).join("")}
      <td class="account-role-list">${account.roleHtml}</td>
      <td>${account.facultyHtml}</td>
      <td>${account.collegeHtml || "<span class='text-muted'>N/A</span>"}</td>
      <td>${account.statusHtml}</td>
      <td class="text-end text-nowrap">${buildActionButtonsHtml(account, false)}</td>
    </tr>
  `;
}

function buildMobileCardHtml(account, rowNumber) {
  return `
    <div class="card account-mobile-card">
      <div class="card-body">
        <div class="account-mobile-top">
          <div class="account-mobile-identity">
            <span class="account-mobile-index">#${rowNumber}</span>
            <h6 class="account-mobile-name mt-3" title="${escapeHtml(account.username.toUpperCase())}">${escapeHtml(account.username.toUpperCase())}</h6>
            <div class="account-mobile-email">${escapeHtml(account.email.toLowerCase())}</div>
          </div>
          <div class="account-mobile-badges">
            ${account.statusHtml}
          </div>
        </div>
        <div class="account-mobile-grid">
          <div>
            <span class="account-mobile-meta-label">Roles / Assignments</span>
            <div class="account-mobile-meta-value account-role-list">${account.roleHtml}</div>
            <div class="account-mobile-role-choices" aria-label="Individual role assignments">
              ${ACCOUNT_ROLE_COLUMNS.map(function(role) { return buildAccountRoleToggle(account, role, true); }).join("")}
            </div>
          </div>
          <div>
            <span class="account-mobile-meta-label">Faculty Link</span>
            <div class="account-mobile-meta-value">${account.facultyHtml}</div>
          </div>
          <div>
            <span class="account-mobile-meta-label">Assigned Scope</span>
            <div class="account-mobile-meta-value">${account.collegeHtml}</div>
          </div>
        </div>
        <div class="account-mobile-actions">
          ${buildActionButtonsHtml(account, true)}
        </div>
      </div>
    </div>
  `;
}

function buildActionButtonsHtml(account, mobileView) {
  const editClasses = mobileView
    ? "btn btn-outline-warning btnEditAccount"
    : "btn btn-sm btn-warning btnEditAccount";
  const deleteClasses = mobileView
    ? "btn btn-outline-danger btnDeleteAccount"
    : "btn btn-sm btn-danger btnDeleteAccount";
  const editLabel = mobileView
    ? '<i class="bx bx-edit-alt me-1"></i>Edit'
    : '<i class="bx bx-edit-alt"></i>';
  const deleteLabel = mobileView
    ? '<i class="bx bx-trash me-1"></i>Delete'
    : '<i class="bx bx-trash"></i>';

  return `
    <button
      class="${editClasses}"
      data-id="${escapeHtml(account.id)}"
      data-username="${escapeHtml(account.username)}"
      data-email="${escapeHtml(account.email)}"
      data-roles="${escapeHtml(account.rolesAttr)}"
      data-primary-role="${escapeHtml(account.primaryRoleValue)}"
      data-faculty-id="${escapeHtml(account.facultyIdValue)}"
      data-program-chair-college="${escapeHtml(account.programChairCollegeValue)}"
      data-college-dean-college="${escapeHtml(account.collegeDeanCollegeValue)}"
      data-registrar-campus="${escapeHtml(account.registrarCampusValue)}"
      data-college-ids="${escapeHtml(account.collegeIdsAttr)}"
      data-default-college="${escapeHtml(account.defaultCollegeValue)}"
      data-status="${escapeHtml(account.statusValue)}"
    >
      ${editLabel}
    </button>
    <button
      class="${deleteClasses}"
      data-id="${escapeHtml(account.id)}"
    >
      ${deleteLabel}
    </button>
  `;
}

function fillViewportAccounts() {
  let safetyCounter = 0;

  while (
    accountState.renderedCount < accountState.filteredAccounts.length &&
    $(document).height() <= $(window).height() + 120 &&
    safetyCounter < 8
  ) {
    renderNextAccountBatch(false);
    safetyCounter++;
  }
}

function maybeLoadMoreAccounts() {
  if (accountState.renderedCount >= accountState.filteredAccounts.length) {
    return;
  }

  if ($(window).scrollTop() + $(window).height() >= $(document).height() - 220) {
    renderNextAccountBatch(false);
  }
}

function updateAccountsMeta() {
  const total = accountState.allAccounts.length;
  const filtered = accountState.filteredAccounts.length;
  const shown = Math.min(accountState.renderedCount, filtered);
  const hasQuery = $.trim($("#accountSearch").val()) !== "";

  if (filtered === 0) {
    $("#accountsMeta").text(hasQuery ? "No matching accounts found." : "No accounts found.");
    $("#accountsScrollHint").text(hasQuery ? "Try a different search term." : "");
    return;
  }

  if (hasQuery) {
    $("#accountsMeta").text("Showing " + shown + " of " + filtered + " matching accounts (" + total + " total)");
  } else {
    $("#accountsMeta").text("Showing " + shown + " of " + filtered + " accounts");
  }

  if (shown < filtered) {
    $("#accountsScrollHint").text("Scroll to load more");
  } else {
    $("#accountsScrollHint").text("All matching accounts loaded");
  }
}

function renderAccountsLoadError() {
  accountState.allAccounts = [];
  accountState.filteredAccounts = [];
  accountState.renderedCount = 0;

  $("#accountsTable tbody").html(`
    <tr>
      <td colspan="${7 + ACCOUNT_ROLE_COLUMNS.length}" class="text-center py-4 text-danger">
        Unable to load the account list right now.
      </td>
    </tr>
  `);

  $("#accountsMobileList").html(`
    <div class="accounts-empty-state text-danger">
      Unable to load the account list right now.
    </div>
  `);

  $("#accountsMeta").text("Account loading failed.");
  $("#accountsScrollHint").text("");
  updateRoleHeaderToggles();
}

function renderAccountsEmptyState() {
  $("#accountsTable tbody").html(`
    <tr>
      <td colspan="${7 + ACCOUNT_ROLE_COLUMNS.length}" class="text-center py-4 text-muted">
        No accounts found.
      </td>
    </tr>
  `);

  $("#accountsMobileList").html(`
    <div class="accounts-empty-state">
      No accounts found.
    </div>
  `);
  updateRoleHeaderToggles();
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

function buildDefaultCollegeOptions(mode, preferredValue) {
  const $collegeSelect = $("#" + mode + "_college_ids");
  const $defaultSelect = $("#" + mode + "_default_college_id");
  const selectedValues = ($collegeSelect.val() || []).map(String);
  const placeholder = mode === "add" ? "Select Default College" : "Select Default College";

  $defaultSelect.empty().append(`<option value="">${placeholder}</option>`);

  selectedValues.forEach(function(value) {
    const option = $collegeSelect.find(`option[value="${value}"]`).first();
    if (option.length) {
      $defaultSelect.append(
        $("<option>", {
          value: value,
          text: option.text()
        })
      );
    }
  });

  if (selectedValues.length === 0) {
    $defaultSelect.val("");
    return;
  }

  const desiredValue = selectedValues.indexOf(String(preferredValue || "")) !== -1
    ? String(preferredValue)
    : selectedValues[0];

  $defaultSelect.val(desiredValue);
}

function resetCollegeAccessFields(mode) {
  $("#" + mode + "_college_ids").val(null).trigger("change");
  $("#" + mode + "_default_college_id")
    .empty()
    .append('<option value="">Select Default College</option>')
    .val("");
}

function getSelectedRoles(mode) {
  return $("#" + mode + "AccountForm .account-role-checkbox:checked").map(function() {
    return normalizeRoleCode($(this).val());
  }).get();
}

function setSelectedRoles(mode, roles) {
  const normalized = Array.isArray(roles)
    ? roles.map(normalizeRoleCode).filter(Boolean)
    : [];

  $("#" + mode + "AccountForm .account-role-checkbox").prop("checked", false);

  normalized.forEach(function(role) {
    $("#" + mode + "_role_" + role).prop("checked", true);
  });
}

function buildPrimaryRoleOptions(mode, preferredValue) {
  const selectedRoles = getSelectedRoles(mode);
  const $primarySelect = $("#" + mode + "_primary_role");

  $primarySelect.empty().append('<option value="">Select Default Role</option>');

  selectedRoles.forEach(function(role) {
    $primarySelect.append(
      $("<option>", {
        value: role,
        text: roleLabel(role)
      })
    );
  });

  if (selectedRoles.length === 0) {
    $primarySelect.val("");
    return;
  }

  const desiredValue = selectedRoles.indexOf(normalizeRoleCode(preferredValue)) !== -1
    ? normalizeRoleCode(preferredValue)
    : selectedRoles[0];

  $primarySelect.val(desiredValue);
}

function resetRoleFields(mode) {
  $("#" + mode + "AccountForm .account-role-checkbox").prop("checked", false);
  $("#" + mode + "_primary_role")
    .empty()
    .append('<option value="">Select Default Role</option>')
    .val("");
}

function rolesIncludeScheduler(roles) {
  return Array.isArray(roles) && roles.indexOf("scheduler") !== -1;
}

function rolesIncludeProfessor(roles) {
  return Array.isArray(roles) && roles.indexOf("professor") !== -1;
}

function rolesIncludeProgramChair(roles) {
  return Array.isArray(roles) && roles.indexOf("program_chair") !== -1;
}

function rolesIncludeCollegeDean(roles) {
  return Array.isArray(roles) && roles.indexOf("college_dean") !== -1;
}

function rolesIncludeRegistrar(roles) {
  return Array.isArray(roles) && roles.indexOf("registrar") !== -1;
}

function getSelectedFacultyName(mode) {
  const $selectedOption = $("#" + mode + "_faculty_id").find("option:selected");
  return $.trim(String($selectedOption.data("facultyName") || ""));
}

function resetProgramChairCollegeField(mode) {
  $("#" + mode + "_program_chair_college_id").val("");
}

function toggleProgramChairCollegeField(mode, roles, preferredCollegeId) {
  const $collegeSelect = $("#" + mode + "_program_chair_college_id");
  const isProgramChair = rolesIncludeProgramChair(Array.isArray(roles) ? roles : [roles]);
  $("#" + mode + "_program_chair_college_wrapper").toggle(isProgramChair);
  $collegeSelect.prop("required", isProgramChair);

  if (!isProgramChair) {
    resetProgramChairCollegeField(mode);
    return;
  }

  $collegeSelect.val(String(preferredCollegeId || ""));
}

function resetCollegeDeanCollegeField(mode) {
  $("#" + mode + "_college_dean_college_id").val("");
}

function toggleCollegeDeanCollegeField(mode, roles, preferredCollegeId) {
  const $collegeSelect = $("#" + mode + "_college_dean_college_id");
  const isCollegeDean = rolesIncludeCollegeDean(Array.isArray(roles) ? roles : [roles]);
  $("#" + mode + "_college_dean_college_wrapper").toggle(isCollegeDean);
  $collegeSelect.prop("required", isCollegeDean);

  if (!isCollegeDean) {
    resetCollegeDeanCollegeField(mode);
    return;
  }

  $collegeSelect.val(String(preferredCollegeId || ""));
}

function resetRegistrarCampusField(mode) {
  $("#" + mode + "_registrar_campus_id").val("");
}

function toggleRegistrarCampusField(mode, roles, preferredCampusId) {
  const $campusSelect = $("#" + mode + "_registrar_campus_id");
  const isRegistrar = rolesIncludeRegistrar(Array.isArray(roles) ? roles : [roles]);
  $("#" + mode + "_registrar_campus_wrapper").toggle(isRegistrar);
  $campusSelect.prop("required", isRegistrar);

  if (!isRegistrar) {
    resetRegistrarCampusField(mode);
    return;
  }

  $campusSelect.val(String(preferredCampusId || ""));
}

function toggleCollegeAccessFields(mode, roles, preferredDefaultValue) {
  const isScheduler = rolesIncludeScheduler(Array.isArray(roles) ? roles : [roles]);
  $("#" + mode + "_college_wrapper").toggle(isScheduler);
  $("#" + mode + "_default_college_wrapper").toggle(isScheduler);

  if (!isScheduler) {
    resetCollegeAccessFields(mode);
    return;
  }

  buildDefaultCollegeOptions(mode, preferredDefaultValue);
  $("#" + mode + "_college_ids").trigger("change.select2");
}

function resetFacultyField(mode) {
  $("#" + mode + "_faculty_id").val("").trigger("change.select2");
}

function toggleFacultyLinkField(mode, roles, preferredFacultyId) {
  const $facultySelect = $("#" + mode + "_faculty_id");
  const isProfessor = rolesIncludeProfessor(Array.isArray(roles) ? roles : [roles]);
  $("#" + mode + "_faculty_wrapper").toggle(isProfessor);
  $facultySelect.prop("required", isProfessor);

  if (!isProfessor) {
    resetFacultyField(mode);
    syncProfessorDisplayName(mode);
    return;
  }

  $facultySelect.val(String(preferredFacultyId || "")).trigger("change.select2");
  syncProfessorDisplayName(mode);
}

function syncProfessorDisplayName(mode) {
  const $username = $("#" + mode + "_username");
  const $help = $("#" + mode + "_username_help");
  const isProfessor = rolesIncludeProfessor(getSelectedRoles(mode));

  if (!isProfessor) {
    $username.prop("readonly", false).removeClass("bg-light");

    if ($username.data("professorLocked")) {
      const manualValue = String($username.data("manualValue") || "");
      if (manualValue !== "") {
        $username.val(manualValue);
      }
    }

    $username.removeData("professorLocked");
    $username.removeData("manualValue");

    if ($help.length) {
      $help.text("Type the display name shown in account lists and the shared login.");
    }

    return;
  }

  if (!$username.data("professorLocked")) {
    $username.data("manualValue", $.trim($username.val()));
  }

  const facultyName = getSelectedFacultyName(mode);
  $username.val(facultyName);
  $username.prop("readonly", true).addClass("bg-light");
  $username.data("professorLocked", "1");

  if ($help.length) {
    $help.text(
      facultyName !== ""
        ? "Display name is synced from the selected faculty record."
        : "Select a faculty record to fill the professor display name automatically."
    );
  }
}

function buildAccountPayload(mode) {
  const roles = getSelectedRoles(mode);
  const payload = {
    username: $.trim($("#" + mode + "_username").val()),
    email: $.trim($("#" + mode + "_email").val()),
    roles: roles,
    primary_role: $("#" + mode + "_primary_role").val(),
    status: $("#" + mode + "_status").val() || "active"
  };

  if (mode === "edit") {
    payload.user_id = $("#" + mode + "_user_id").val();
  }

  if (rolesIncludeScheduler(roles)) {
    payload.college_ids = ($("#" + mode + "_college_ids").val() || []).map(String);
    payload.default_college_id = $("#" + mode + "_default_college_id").val();
  }

  if (rolesIncludeProgramChair(roles)) {
    payload.program_chair_college_id = $("#" + mode + "_program_chair_college_id").val();
  }

  if (rolesIncludeCollegeDean(roles)) {
    payload.college_dean_college_id = $("#" + mode + "_college_dean_college_id").val();
  }

  if (rolesIncludeRegistrar(roles)) {
    payload.registrar_campus_id = $("#" + mode + "_registrar_campus_id").val();
  }

  if (rolesIncludeProfessor(roles)) {
    payload.faculty_id = $("#" + mode + "_faculty_id").val();
  }

  return payload;
}

function hideAccountModal(modalId) {
  const modalEl = document.getElementById(modalId);
  if (!modalEl) {
    return;
  }

  const modalInstance = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
  modalInstance.hide();
}

function showAccountModal(modalId) {
  const modalEl = document.getElementById(modalId);
  if (!modalEl) {
    return;
  }

  const modalInstance = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
  modalInstance.show();
}

function handleAccountMutationResponse(mode, responseText) {
  const res = $.trim(responseText);
  const actionLabel = mode === "add" ? "Save" : "Update";

  if (res === "unauthorized") {
    Swal.fire("Session Expired", "Please sign in again.", "warning").then(() => {
      window.location = "../index.php";
    });
    return;
  }
  if (res === "missing") {
    Swal.fire("Missing Data", "Please fill all required fields.", "warning");
    return;
  }
  if (res === "invalid_domain") {
    Swal.fire("Invalid Email", "Only @sksu.edu.ph email addresses are allowed.", "warning");
    return;
  }
  if (res === "invalid_role") {
    Swal.fire("Invalid Roles", "Select at least one supported role for this account.", "warning");
    return;
  }
  if (res === "invalid_primary_role") {
    Swal.fire("Default Role Required", "Choose which assigned role should be the default login role.", "warning");
    return;
  }
  if (res === "invalid_status") {
    Swal.fire("Invalid Status", "Please choose a valid account status.", "warning");
    return;
  }
  if (res === "need_college") {
    Swal.fire("Missing College Access", "Select at least one college for the scheduler.", "warning");
    return;
  }
  if (res === "need_program_chair_college") {
    Swal.fire("Missing Assigned College", "Select the college this Program Chair account belongs to.", "warning");
    return;
  }
  if (res === "need_college_dean_college") {
    Swal.fire("Missing Assigned College", "Select the college this College Dean account belongs to.", "warning");
    return;
  }
  if (res === "invalid_college_dean_college") {
    Swal.fire("Invalid College", "Select an active college for the College Dean assignment.", "warning");
    return;
  }
  if (res === "need_registrar_campus") {
    Swal.fire("Missing Registrar Campus", "Select the campus this registrar account should review.", "warning");
    return;
  }
  if (res === "need_faculty") {
    Swal.fire("Missing Faculty Link", "Select the faculty record that should use this professor login.", "warning");
    return;
  }
  if (res === "invalid_faculty") {
    Swal.fire("Invalid Faculty", "Choose a valid active faculty record for the professor role.", "warning");
    return;
  }
  if (res === "faculty_in_use") {
    Swal.fire("Faculty Already Linked", "That faculty record is already assigned to another professor account.", "warning");
    return;
  }
  if (res === "dup_email") {
    Swal.fire("Duplicate Email", "This email is already in use.", "warning");
    return;
  }
  if (res === "schema_error") {
    Swal.fire("Schema Mismatch", "The account form fields do not match the current tbl_useraccount columns.", "error");
    return;
  }
  if (res === "save_failed") {
    Swal.fire(actionLabel + " Failed", "The account could not be saved. Please verify the account schema and try again.", "error");
    return;
  }
  if (res !== "success") {
    Swal.fire(actionLabel + " Failed", res !== "" ? res : "Please try again later.", "error");
    return;
  }

  Swal.fire({
    icon: "success",
    title: mode === "add" ? "Saved!" : "Updated!",
    text: mode === "add" ? "Access account added successfully." : "Access account updated successfully.",
    timer: 1200,
    showConfirmButton: false
  });

  if (mode === "add") {
    $("#addAccountForm")[0].reset();
    resetRoleFields("add");
    resetFacultyField("add");
    resetProgramChairCollegeField("add");
    resetCollegeDeanCollegeField("add");
    resetRegistrarCampusField("add");
    resetCollegeAccessFields("add");
    syncProfessorDisplayName("add");
    $("#add_faculty_wrapper").hide();
    $("#add_program_chair_college_wrapper").hide();
    $("#add_college_dean_college_wrapper").hide();
    $("#add_registrar_campus_wrapper").hide();
    $("#add_college_wrapper").hide();
    $("#add_default_college_wrapper").hide();
    hideAccountModal("addAccountModal");
  } else {
    hideAccountModal("editAccountModal");
  }

  loadAccounts();
}

function submitAccountForm(mode) {
  const payload = buildAccountPayload(mode);
  payload[mode === "add" ? "save_account" : "update_account"] = 1;

  $.post("../backend/query_accounts.php", payload, function(res) {
    handleAccountMutationResponse(mode, res);
  }).fail(function() {
    Swal.fire(mode === "add" ? "Save Failed" : "Update Failed", "The account request could not be completed right now.", "error");
  });
}

$(document).on("change", ".account-role-checkbox", function() {
  const mode = String($(this).data("mode") || "");
  if (mode === "") {
    return;
  }

  buildPrimaryRoleOptions(mode, $("#" + mode + "_primary_role").val());
  toggleFacultyLinkField(mode, getSelectedRoles(mode), $("#" + mode + "_faculty_id").val());
  toggleProgramChairCollegeField(mode, getSelectedRoles(mode), $("#" + mode + "_program_chair_college_id").val());
  toggleCollegeDeanCollegeField(mode, getSelectedRoles(mode), $("#" + mode + "_college_dean_college_id").val());
  toggleRegistrarCampusField(mode, getSelectedRoles(mode), $("#" + mode + "_registrar_campus_id").val());
  toggleCollegeAccessFields(mode, getSelectedRoles(mode), $("#" + mode + "_default_college_id").val());
});

$(document).on("change", ".account-role-toggle", function() {
  if (accountState.roleMatrixBusy) {
    return;
  }

  const accountId = String($(this).data("account-id") || "");
  const role = normalizeRoleCode($(this).data("role") || "");
  const assigned = $(this).is(":checked");
  if (accountId === "" || role === "") {
    loadAccounts();
    return;
  }

  submitRoleMatrixChange(role, assigned, [accountId], false);
});

$(document).on("change", ".role-all-toggle", function() {
  if (accountState.roleMatrixBusy) {
    return;
  }

  const role = normalizeRoleCode($(this).data("role") || "");
  const assigned = $(this).is(":checked");
  const roleInfo = ACCOUNT_ROLE_COLUMNS.find(function(item) {
    return item.code === role;
  });
  const managedAccounts = accountState.allAccounts.filter(function(account) {
    if (account.roles.length === 0) return false;
    if (role !== "professor") return true;
    return assigned ? account.professorEligible : account.roles.indexOf("professor") !== -1;
  });

  if (!roleInfo || managedAccounts.length === 0) {
    updateRoleHeaderToggles();
    return;
  }

  Swal.fire({
    title: `${assigned ? "Assign" : "Remove"} ${roleInfo.label} role for ${role === "professor" ? "eligible faculty" : "all managed"} accounts?`,
    text: role === "professor"
      ? `${managedAccounts.length} faculty accounts across the full list will be processed.${assigned ? " Accounts with one unique active faculty match will be linked automatically. Accounts without an eligible faculty link are excluded." : " The Professor role will be removed from these accounts."}`
      : `This applies to ${managedAccounts.length} accounts across the full list, including accounts outside the current search and accounts not yet loaded on screen. Accounts missing required access will be skipped.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: assigned ? "Assign to All" : "Remove from All",
    cancelButtonText: "Cancel",
    confirmButtonColor: assigned ? "#696cff" : "#d9534f"
  }).then(function(result) {
    updateRoleHeaderToggles();
    if (!result.isConfirmed) {
      return;
    }
    const userIds = managedAccounts.map(function(account) {
      return account.id;
    });
    submitRoleMatrixChange(role, assigned, userIds, true);
  });
});

$("#add_college_ids").on("change", function() {
  buildDefaultCollegeOptions("add", $("#add_default_college_id").val());
});

$("#edit_college_ids").on("change", function() {
  buildDefaultCollegeOptions("edit", $("#edit_default_college_id").val());
});

$("#add_faculty_id").on("change", function() {
  syncProfessorDisplayName("add");
});

$("#edit_faculty_id").on("change", function() {
  syncProfessorDisplayName("edit");
});

// ------------------------------------
// SAVE ACCOUNT
// ------------------------------------
$("#addAccountForm").on("submit", function (event) {
  event.preventDefault();
  submitAccountForm("add");
});

// ------------------------------------
// OPEN EDIT MODAL
// ------------------------------------
$(document).on("click", ".btnEditAccount", function () {

  $("#edit_user_id").val($(this).data("id"));
  $("#edit_username").val($(this).data("username"));
  $("#edit_email").val($(this).data("email"));
  $("#edit_status").val($(this).data("status"));

  const rolesAttr = String($(this).attr("data-roles") || "[]");
  const primaryRole = String($(this).attr("data-primary-role") || "");
  const facultyId = String($(this).attr("data-faculty-id") || "");
  const programChairCollegeId = String($(this).attr("data-program-chair-college") || "");
  const collegeDeanCollegeId = String($(this).attr("data-college-dean-college") || "");
  const registrarCampusId = String($(this).attr("data-registrar-campus") || "");
  const collegeIdsAttr = String($(this).attr("data-college-ids") || "[]");
  const defaultCollegeId = String($(this).attr("data-default-college") || "");
  let roles = [];
  let collegeIds = [];

  try {
    roles = JSON.parse(rolesAttr);
  } catch (error) {
    roles = [];
  }

  try {
    collegeIds = JSON.parse(collegeIdsAttr);
  } catch (error) {
    collegeIds = [];
  }

  setSelectedRoles("edit", Array.isArray(roles) ? roles : []);
  buildPrimaryRoleOptions("edit", primaryRole);
  toggleFacultyLinkField("edit", roles, facultyId);
  toggleProgramChairCollegeField("edit", roles, programChairCollegeId);
  toggleCollegeDeanCollegeField("edit", roles, collegeDeanCollegeId);
  toggleRegistrarCampusField("edit", roles, registrarCampusId);

  if (rolesIncludeScheduler(Array.isArray(roles) ? roles : [])) {
    $("#edit_college_ids").val((Array.isArray(collegeIds) ? collegeIds : []).map(String)).trigger("change");
    toggleCollegeAccessFields("edit", roles, defaultCollegeId);
  } else {
    toggleCollegeAccessFields("edit", roles, "");
  }

  showAccountModal("editAccountModal");
});

$("#addAccountModal").on("shown.bs.modal", function() {
  if (rolesIncludeProfessor(getSelectedRoles("add"))) {
    if ($("#add_faculty_id").data("select2")) {
      $("#add_faculty_id").select2("open");
    } else {
      $("#add_faculty_id").trigger("focus");
    }
    return;
  }

  if (rolesIncludeProgramChair(getSelectedRoles("add"))) {
    $("#add_program_chair_college_id").trigger("focus");
    return;
  }

  if (rolesIncludeCollegeDean(getSelectedRoles("add"))) {
    $("#add_college_dean_college_id").trigger("focus");
    return;
  }

  if (rolesIncludeRegistrar(getSelectedRoles("add"))) {
    $("#add_registrar_campus_id").trigger("focus");
    return;
  }

  $("#add_username").trigger("focus");
});

$("#editAccountModal").on("shown.bs.modal", function() {
  if (rolesIncludeProfessor(getSelectedRoles("edit"))) {
    if ($("#edit_faculty_id").data("select2")) {
      $("#edit_faculty_id").select2("open");
    } else {
      $("#edit_faculty_id").trigger("focus");
    }
    return;
  }

  if (rolesIncludeProgramChair(getSelectedRoles("edit"))) {
    $("#edit_program_chair_college_id").trigger("focus");
    return;
  }

  if (rolesIncludeCollegeDean(getSelectedRoles("edit"))) {
    $("#edit_college_dean_college_id").trigger("focus");
    return;
  }

  if (rolesIncludeRegistrar(getSelectedRoles("edit"))) {
    $("#edit_registrar_campus_id").trigger("focus");
    return;
  }

  $("#edit_username").trigger("focus");
});

// ------------------------------------
// UPDATE ACCOUNT
// ------------------------------------
$("#editAccountForm").on("submit", function (event) {
  event.preventDefault();
  submitAccountForm("edit");
});

// ------------------------------------
// DELETE ACCOUNT
// ------------------------------------
$(document).on("click", ".btnDeleteAccount", function () {
  let id = $(this).data("id");

  Swal.fire({
    title: "Are you sure?",
    text: "This user account will be permanently deleted.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#aaa",
    confirmButtonText: "Delete"
  }).then((result) => {
    if (result.isConfirmed) {
      $.post(
        "../backend/query_accounts.php",
        { delete_account: 1, user_id: id },
        function(res) {
          res = res.trim();

          if (res === "unauthorized") {
            Swal.fire("Session Expired", "Please sign in again.", "warning").then(() => {
              window.location = "../index.php";
            });
            return;
          }
          if (res !== "deleted") {
            Swal.fire("Delete Failed", "Please try again later.", "error");
            return;
          }

          Swal.fire("Deleted!", "Access account removed.", "success");
          loadAccounts();
        }
      );
    }
  });

});

</script>

</body>
</html>
