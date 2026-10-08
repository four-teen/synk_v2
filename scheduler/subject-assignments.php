<?php
session_start();
ob_start();
require_once '../backend/db.php';
require_once '../backend/subject_assignments_helper.php';
try {
    $subjectScope = synk_subject_assignments_scope($conn);
} catch (DomainException $error) {
    header('Location: ../index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Subject Assignments | Synk Scheduler</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="custom_css.css" />
    <link rel="stylesheet" href="subject-assignments.css?v=<?= (int)filemtime(__DIR__ . '/subject-assignments.css') ?>" />
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
                    <main class="container-xxl flex-grow-1 container-p-y" id="subjectAssignments" data-college-id="<?= (int)$subjectScope['college_id'] ?>">
                        <div class="card mb-4">
                            <div class="card-body">
                                <p class="sa-eyebrow mb-1">Monitoring</p>
                                <h4 class="mb-2">Subject Assignments</h4>
                                <p class="text-muted mb-4">Find who teaches each subject in your college. Each subject appears once per program for the selected term.</p>
                                <form id="saFilters" class="row g-3 align-items-end">
                                    <div class="col-sm-6 col-lg-3"><label for="saTerm" class="form-label">Academic Term</label><select id="saTerm" class="form-select" disabled><option value="">Loading terms…</option></select></div>
                                    <div class="col-sm-6 col-lg-3"><label for="saProgram" class="form-label">Program</label><select id="saProgram" class="form-select" disabled><option value="0">All programs</option></select></div>
                                    <div class="col-sm-6 col-lg-2"><label for="saAssignment" class="form-label">Assignments</label><select id="saAssignment" class="form-select" disabled><option value="all">All subjects</option><option value="assigned">With faculty</option><option value="unassigned">No faculty assigned</option><option value="needs">With Faculty Need</option></select></div>
                                    <div class="col-sm-6 col-lg-4"><label for="saSearch" class="form-label">Search Subjects or Faculty</label><input id="saSearch" type="search" class="form-control" maxlength="150" placeholder="Subject code, description, or faculty name" disabled /></div>
                                    <div class="col-12 d-flex gap-2 flex-wrap"><button id="saRefresh" type="button" class="btn btn-sm btn-outline-primary"><i class="bx bx-refresh me-1" aria-hidden="true"></i>Refresh</button><button id="saReset" type="button" class="btn btn-sm btn-outline-secondary" disabled>Reset Filters</button></div>
                                </form>
                                <div id="saNotice" class="alert alert-danger mb-0 mt-3 d-none" role="alert"></div>
                            </div>
                        </div>
                        <div class="row g-3 mb-4" aria-label="Filtered assignment totals">
                            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><p class="text-muted mb-2">Subject / Program Entries</p><h4 class="mb-0" id="saTotal">—</h4></div></div></div>
                            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><p class="text-muted mb-2">With Faculty</p><h4 class="mb-0" id="saAssigned">—</h4></div></div></div>
                            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><p class="text-muted mb-2">No Faculty Assigned</p><h4 class="mb-0" id="saUnassigned">—</h4></div></div></div>
                            <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><p class="text-muted mb-2">Distinct Faculty</p><h4 class="mb-0" id="saFaculty">—</h4></div></div></div>
                        </div>
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center gap-2"><h5 class="mb-0">Subjects &amp; Faculty</h5><span id="saTermLabel" class="badge bg-label-primary sa-term-label"></span></div>
                            <div class="table-responsive" id="saTableWrap" aria-busy="true">
                                <table class="table mb-0 sa-table"><thead><tr><th>Subject Code</th><th>Subject Description</th><th>Program</th><th>Faculty</th><th class="text-end">Action</th></tr></thead><tbody id="saRows"><tr><td colspan="5" class="text-center py-5">Loading subject assignments…</td></tr></tbody></table>
                            </div>
                            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2"><p id="saResultCount" class="small text-muted mb-0" role="status" aria-live="polite"></p><nav class="d-flex align-items-center gap-2" aria-label="Subject pages"><button id="saPrevious" type="button" class="btn btn-sm btn-outline-secondary" disabled>Previous</button><span id="saPage" class="small"></span><button id="saNext" type="button" class="btn btn-sm btn-outline-secondary" disabled>Next</button></nav></div>
                        </div>
                        <p class="small text-muted mt-3 mb-0">Faculty counts use saved workload assignments. Faculty Need placeholders are shown separately. TBA classes are included.</p>
                    </main>
                    <?php include '../footer.php'; ?>
                    <div class="content-backdrop fade"></div>
                </div>
            </div>
        </div>
        <div class="layout-overlay layout-menu-toggle"></div>
    </div>
    <div class="modal fade" id="saDetailModal" tabindex="-1" aria-labelledby="saDetailTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
            <div class="modal-header"><div><h5 class="modal-title" id="saDetailTitle">Subject Faculty</h5><p class="small text-muted mt-1 mb-0" id="saDetailSubtitle"></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body" id="saDetailContent"></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div></div>
    </div>
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="subject-assignments.js?v=<?= (int)filemtime(__DIR__ . '/subject-assignments.js') ?>"></script>
</body>
</html>
