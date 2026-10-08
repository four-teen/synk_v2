<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/workload_audit_helper.php';

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

date_default_timezone_set('Asia/Manila');
synk_workload_audit_ensure_table($conn);

function audit_page_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function audit_page_semester_label($semester): string
{
    switch ((int)$semester) {
        case 1:
            return '1st Semester';
        case 2:
            return '2nd Semester';
        case 3:
            return 'Midyear';
        default:
            return 'All semesters';
    }
}

function audit_page_date_label($dateValue): string
{
    $timestamp = strtotime((string)$dateValue);
    if (!$timestamp) {
        return '-';
    }

    return date('M d, Y h:i A', $timestamp);
}

function audit_page_details(array $row): array
{
    $json = trim((string)($row['details_json'] ?? ''));
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function audit_page_compact_ids(array $ids, int $limit = 8): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
        return $id > 0;
    })));

    if (empty($ids)) {
        return '-';
    }

    $visible = array_slice($ids, 0, $limit);
    $label = implode(', ', $visible);
    $extra = count($ids) - count($visible);

    return $extra > 0 ? $label . ' +' . $extra : $label;
}

function audit_page_action_class(string $actionType): string
{
    if ($actionType === 'workload_add' || $actionType === 'faculty_need_create') {
        return 'bg-label-success text-success';
    }

    if ($actionType === 'workload_bulk_delete') {
        return 'bg-label-warning text-warning';
    }

    if (strpos($actionType, 'delete') !== false) {
        return 'bg-label-danger text-danger';
    }

    return 'bg-label-info text-info';
}

function audit_page_assignment_label(array $row, array $details): string
{
    $assigneeType = (string)($row['assignee_type'] ?? '');
    $facultyName = trim((string)($details['faculty_name'] ?? ''));
    $needLabel = trim((string)($details['need_label'] ?? ''));

    if ($assigneeType === 'faculty_need') {
        return $needLabel !== '' ? $needLabel : 'Faculty need';
    }

    if ($facultyName !== '') {
        return $facultyName;
    }

    $facultyId = (int)($row['faculty_id'] ?? 0);
    return $facultyId > 0 ? 'Faculty #' . $facultyId : '-';
}

function audit_page_college_label(array $row, array $details): string
{
    $collegeName = trim((string)($row['college_name'] ?? ''));
    $detailCollegeLabel = trim((string)($details['college_label'] ?? ''));
    $collegeId = (int)($row['college_id'] ?? 0);

    if ($collegeName !== '') {
        return $collegeName;
    }

    if ($detailCollegeLabel !== '') {
        return $detailCollegeLabel;
    }

    return $collegeId > 0 ? 'College #' . $collegeId : 'College not captured';
}

function audit_page_scope_label(array $row, array $details): string
{
    $actionType = (string)($row['action_type'] ?? '');

    if ($actionType === 'workload_bulk_delete') {
        $deletedCount = (int)($details['deleted_count'] ?? $row['affected_count'] ?? 0);
        return $deletedCount . ' workload row(s) in college scope';
    }

    if (($row['entity_type'] ?? '') === 'faculty_need') {
        $needLabel = trim((string)($details['need_label'] ?? ''));
        return $needLabel !== '' ? $needLabel : 'Faculty need';
    }

    $subject = trim((string)($row['subject_code'] ?? $details['subject_code'] ?? ''));
    $section = trim((string)($row['section_label'] ?? $details['section_label'] ?? ''));
    $type = strtoupper(trim((string)($row['schedule_type'] ?? $details['schedule_type'] ?? '')));

    $label = $subject !== '' ? $subject : 'Class schedule';
    if ($section !== '') {
        $label .= ' - ' . $section;
    }

    if ($type !== '') {
        $label .= ' (' . $type . ')';
    }

    return $label;
}

function audit_page_id_label(array $row, array $details): string
{
    $lines = [];
    $workloadId = (int)($row['workload_id'] ?? 0);
    $needWorkloadId = (int)($row['need_workload_id'] ?? 0);
    $scheduleId = (int)($row['schedule_id'] ?? 0);
    $facultyId = (int)($row['faculty_id'] ?? 0);
    $facultyNeedId = (int)($row['faculty_need_id'] ?? 0);

    if ($workloadId > 0) {
        $lines[] = 'WL: ' . $workloadId;
    }

    if ($needWorkloadId > 0) {
        $lines[] = 'Need WL: ' . $needWorkloadId;
    }

    if ($scheduleId > 0) {
        $lines[] = 'Sched: ' . $scheduleId;
    }

    if ($facultyId > 0) {
        $lines[] = 'Faculty: ' . $facultyId;
    }

    if ($facultyNeedId > 0) {
        $lines[] = 'Need: ' . $facultyNeedId;
    }

    if (($row['action_type'] ?? '') === 'workload_bulk_delete') {
        $workloadIds = is_array($details['workload_ids'] ?? null) ? $details['workload_ids'] : [];
        $scheduleIds = is_array($details['schedule_ids'] ?? null) ? $details['schedule_ids'] : [];
        $lines[] = 'WL: ' . audit_page_compact_ids($workloadIds);
        $lines[] = 'Sched: ' . audit_page_compact_ids($scheduleIds);
    }

    return empty($lines) ? '-' : implode('<br>', array_map('audit_page_h', $lines));
}

function audit_page_details_json(array $details): string
{
    if (empty($details)) {
        return '';
    }

    $json = json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '';
}

$currentTerm = synk_fetch_current_academic_term($conn);
$defaultAyId = (int)($currentTerm['ay_id'] ?? 0);
$defaultSemester = (int)($currentTerm['semester'] ?? 0);

$selectedAction = trim((string)($_GET['action_type'] ?? ''));
$selectedActor = (int)($_GET['actor_user_id'] ?? 0);
$selectedCollegeId = (int)($_GET['college_id'] ?? 0);
$selectedAyId = array_key_exists('ay_id', $_GET) ? (int)($_GET['ay_id'] ?? 0) : $defaultAyId;
$selectedSemester = array_key_exists('semester', $_GET) ? (int)($_GET['semester'] ?? 0) : $defaultSemester;
$selectedDateFrom = trim((string)($_GET['date_from'] ?? ''));
$selectedDateTo = trim((string)($_GET['date_to'] ?? ''));
$selectedSearch = trim((string)($_GET['search'] ?? ''));
$selectedLimit = (int)($_GET['limit'] ?? 250);
$selectedLimit = in_array($selectedLimit, [100, 250, 500], true) ? $selectedLimit : 250;

$filters = [
    'action_type' => $selectedAction,
    'actor_user_id' => $selectedActor,
    'college_id' => $selectedCollegeId,
    'ay_id' => $selectedAyId,
    'semester' => $selectedSemester,
    'date_from' => $selectedDateFrom,
    'date_to' => $selectedDateTo,
    'search' => $selectedSearch,
];

$auditRows = synk_workload_audit_fetch_logs($conn, 0, $filters, $selectedLimit);
$userOptions = synk_workload_audit_fetch_users($conn, 0);
$actionCounts = synk_workload_audit_fetch_action_counts($conn, 0);

$knownActions = [
    'workload_add' => synk_workload_audit_action_label('workload_add'),
    'workload_delete' => synk_workload_audit_action_label('workload_delete'),
    'workload_bulk_delete' => synk_workload_audit_action_label('workload_bulk_delete'),
    'faculty_need_create' => synk_workload_audit_action_label('faculty_need_create'),
    'faculty_need_delete' => synk_workload_audit_action_label('faculty_need_delete'),
    'faculty_need_workload_delete' => synk_workload_audit_action_label('faculty_need_workload_delete'),
];

foreach ($actionCounts as $actionRow) {
    $actionType = (string)($actionRow['action_type'] ?? '');
    if ($actionType !== '') {
        $knownActions[$actionType] = (string)($actionRow['action_label'] ?? synk_workload_audit_action_label($actionType));
    }
}

$academicYears = [];
$ayResult = $conn->query("SELECT ay_id, ay FROM tbl_academic_years ORDER BY ay DESC");
if ($ayResult instanceof mysqli_result) {
    while ($ayRow = $ayResult->fetch_assoc()) {
        $academicYears[] = [
            'ay_id' => (int)($ayRow['ay_id'] ?? 0),
            'ay' => (string)($ayRow['ay'] ?? ''),
        ];
    }
}

$collegeOptions = [];
$collegeResult = $conn->query("
    SELECT college_id, college_code, college_name
    FROM tbl_college
    WHERE status = 'active'
    ORDER BY college_name ASC, college_code ASC
");
if ($collegeResult instanceof mysqli_result) {
    while ($collegeRow = $collegeResult->fetch_assoc()) {
        $collegeCode = trim((string)($collegeRow['college_code'] ?? ''));
        $collegeName = trim((string)($collegeRow['college_name'] ?? ''));
        $collegeOptions[] = [
            'college_id' => (int)($collegeRow['college_id'] ?? 0),
            'label' => trim($collegeCode . ' - ' . $collegeName, ' -'),
        ];
    }
}

$visibleTransactionCount = count($auditRows);
$visibleAffectedCount = 0;
$visibleUsers = [];
$visibleColleges = [];
$visibleAdds = 0;
$visibleDeletes = 0;

foreach ($auditRows as $auditRow) {
    $visibleAffectedCount += max(1, (int)($auditRow['affected_count'] ?? 1));
    $actorId = (int)($auditRow['actor_user_id'] ?? 0);
    if ($actorId > 0) {
        $visibleUsers[$actorId] = true;
    }

    $rowCollegeId = (int)($auditRow['college_id'] ?? 0);
    if ($rowCollegeId > 0) {
        $visibleColleges[$rowCollegeId] = true;
    }

    $actionType = (string)($auditRow['action_type'] ?? '');
    if ($actionType === 'workload_add' || $actionType === 'faculty_need_create') {
        $visibleAdds++;
    }

    if (strpos($actionType, 'delete') !== false) {
        $visibleDeletes++;
    }
}

$selectedCollegeLabel = 'All Colleges';
foreach ($collegeOptions as $collegeOption) {
    if ((int)($collegeOption['college_id'] ?? 0) === $selectedCollegeId) {
        $selectedCollegeLabel = (string)($collegeOption['label'] ?? 'Selected College');
        break;
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
  <title>Workload Transactions | Synk Administrator</title>

  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />

  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>

  <style>
    .audit-hero {
      border: 1px solid #dfe7ef;
      background: #ffffff;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(34, 48, 62, 0.05);
    }

    .audit-hero-title {
      color: #263445;
      font-weight: 700;
      line-height: 1.15;
    }

    .audit-stat {
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      background: #fbfcfe;
      min-height: 86px;
    }

    .audit-stat-label {
      color: #718096;
      font-size: 0.76rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .audit-stat-value {
      color: #25364d;
      font-size: 1.35rem;
      font-weight: 700;
      line-height: 1.1;
    }

    .audit-filter {
      border: 1px solid #dfe7ef;
      border-radius: 8px;
      background: #ffffff;
    }

    .audit-table-card {
      border: 1px solid #dfe7ef;
      border-radius: 8px;
      background: #ffffff;
      box-shadow: 0 2px 10px rgba(34, 48, 62, 0.04);
    }

    .audit-table th {
      color: #64748b;
      font-size: 0.72rem;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .audit-table td {
      color: #40536b;
      vertical-align: top;
    }

    .audit-action-badge {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      font-size: 0.74rem;
      font-weight: 700;
      padding: 0.28rem 0.55rem;
      white-space: nowrap;
    }

    .audit-muted {
      color: #7a8ba2;
      font-size: 0.78rem;
      line-height: 1.3;
    }

    .audit-id-list {
      color: #51657e;
      font-family: Consolas, Monaco, monospace;
      font-size: 0.78rem;
      line-height: 1.35;
      white-space: nowrap;
    }

    .audit-details summary {
      color: #566a7f;
      cursor: pointer;
      font-size: 0.78rem;
      font-weight: 700;
    }

    .audit-details pre {
      max-width: 420px;
      max-height: 260px;
      overflow: auto;
      margin: 0.55rem 0 0;
      padding: 0.75rem;
      border: 1px solid #e3e9f2;
      border-radius: 6px;
      background: #f8fafc;
      color: #334155;
      font-size: 0.74rem;
      white-space: pre-wrap;
    }

    .audit-empty {
      border: 1px dashed #cbd5e1;
      border-radius: 8px;
      background: #f8fafc;
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
            <div class="audit-hero p-4 mb-4">
              <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                <div>
                  <div class="text-uppercase text-muted fw-semibold small mb-2">User Audit</div>
                  <h4 class="audit-hero-title mb-2">Workload Transactions</h4>
                  <p class="text-muted mb-0">
                    Review who added, removed, or cleared workload records across scheduler accounts and colleges.
                  </p>
                </div>
                <div class="d-flex align-items-start gap-2 flex-wrap">
                  <span class="badge bg-label-primary"><?= audit_page_h($selectedCollegeLabel); ?></span>
                  <span class="badge bg-label-info"><?= audit_page_h(audit_page_semester_label($selectedSemester)); ?></span>
                </div>
              </div>

              <div class="row g-3 mt-2">
                <div class="col-sm-6 col-xl-3">
                  <div class="audit-stat p-3">
                    <div class="audit-stat-label">Visible Transactions</div>
                    <div class="audit-stat-value mt-2"><?= number_format($visibleTransactionCount); ?></div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="audit-stat p-3">
                    <div class="audit-stat-label">Affected Records</div>
                    <div class="audit-stat-value mt-2"><?= number_format($visibleAffectedCount); ?></div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="audit-stat p-3">
                    <div class="audit-stat-label">Users In View</div>
                    <div class="audit-stat-value mt-2"><?= number_format(count($visibleUsers)); ?></div>
                  </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                  <div class="audit-stat p-3">
                    <div class="audit-stat-label">Colleges In View</div>
                    <div class="audit-stat-value mt-2"><?= number_format(count($visibleColleges)); ?></div>
                  </div>
                </div>
              </div>
            </div>

            <form method="get" class="audit-filter p-3 mb-4">
              <div class="row g-3 align-items-end">
                <div class="col-md-3 col-xl-2">
                  <label class="form-label" for="action_type">Action</label>
                  <select class="form-select" id="action_type" name="action_type">
                    <option value="">All actions</option>
                    <?php foreach ($knownActions as $actionType => $actionLabel): ?>
                      <option value="<?= audit_page_h($actionType); ?>" <?= $selectedAction === $actionType ? 'selected' : ''; ?>>
                        <?= audit_page_h($actionLabel); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-3 col-xl-2">
                  <label class="form-label" for="actor_user_id">User</label>
                  <select class="form-select" id="actor_user_id" name="actor_user_id">
                    <option value="0">All users</option>
                    <?php foreach ($userOptions as $userRow): ?>
                      <?php $actorId = (int)($userRow['actor_user_id'] ?? 0); ?>
                      <option value="<?= $actorId; ?>" <?= $selectedActor === $actorId ? 'selected' : ''; ?>>
                        <?= audit_page_h($userRow['actor_display_name'] ?? 'Unknown user'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-3 col-xl-2">
                  <label class="form-label" for="college_id">College</label>
                  <select class="form-select" id="college_id" name="college_id">
                    <option value="0">All colleges</option>
                    <?php foreach ($collegeOptions as $collegeOption): ?>
                      <?php $optionCollegeId = (int)($collegeOption['college_id'] ?? 0); ?>
                      <option value="<?= $optionCollegeId; ?>" <?= $selectedCollegeId === $optionCollegeId ? 'selected' : ''; ?>>
                        <?= audit_page_h($collegeOption['label'] ?? 'College'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-3 col-xl-2">
                  <label class="form-label" for="ay_id">Academic Year</label>
                  <select class="form-select" id="ay_id" name="ay_id">
                    <option value="0">All years</option>
                    <?php foreach ($academicYears as $ayRow): ?>
                      <?php $ayId = (int)($ayRow['ay_id'] ?? 0); ?>
                      <option value="<?= $ayId; ?>" <?= $selectedAyId === $ayId ? 'selected' : ''; ?>>
                        <?= audit_page_h($ayRow['ay'] ?? ''); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-3 col-xl-2">
                  <label class="form-label" for="semester">Semester</label>
                  <select class="form-select" id="semester" name="semester">
                    <option value="0">All semesters</option>
                    <option value="1" <?= $selectedSemester === 1 ? 'selected' : ''; ?>>1st Semester</option>
                    <option value="2" <?= $selectedSemester === 2 ? 'selected' : ''; ?>>2nd Semester</option>
                    <option value="3" <?= $selectedSemester === 3 ? 'selected' : ''; ?>>Midyear</option>
                  </select>
                </div>

                <div class="col-md-3 col-xl-2">
                  <label class="form-label" for="date_from">From</label>
                  <input type="date" class="form-control" id="date_from" name="date_from" value="<?= audit_page_h($selectedDateFrom); ?>">
                </div>

                <div class="col-md-3 col-xl-2">
                  <label class="form-label" for="date_to">To</label>
                  <input type="date" class="form-control" id="date_to" name="date_to" value="<?= audit_page_h($selectedDateTo); ?>">
                </div>

                <div class="col-md-6 col-xl-4">
                  <label class="form-label" for="search">Search</label>
                  <input
                    type="search"
                    class="form-control"
                    id="search"
                    name="search"
                    value="<?= audit_page_h($selectedSearch); ?>"
                    placeholder="Subject, section, user, ID, or action"
                  >
                </div>

                <div class="col-md-3 col-xl-2">
                  <label class="form-label" for="limit">Rows</label>
                  <select class="form-select" id="limit" name="limit">
                    <option value="100" <?= $selectedLimit === 100 ? 'selected' : ''; ?>>100</option>
                    <option value="250" <?= $selectedLimit === 250 ? 'selected' : ''; ?>>250</option>
                    <option value="500" <?= $selectedLimit === 500 ? 'selected' : ''; ?>>500</option>
                  </select>
                </div>

                <div class="col-md-3 col-xl-2 d-flex gap-2">
                  <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bx bx-filter-alt me-1"></i> Filter
                  </button>
                  <a href="manage-workload-transactions.php" class="btn btn-outline-secondary" title="Reset filters">
                    <i class="bx bx-reset"></i>
                  </a>
                </div>
              </div>
            </form>

            <div class="audit-table-card">
              <div class="d-flex flex-column flex-md-row justify-content-between gap-2 p-3 border-bottom">
                <div>
                  <h5 class="mb-1">Transaction Log</h5>
                <div class="audit-muted">Newest transactions are shown first. Audit rows are append-only and visible to administrators.</div>
                </div>
                <a href="monitoring-faculty-load.php" class="btn btn-outline-primary align-self-start">
                  <i class="bx bx-user-pin me-1"></i> Faculty Load Monitoring
                </a>
              </div>

              <?php if (empty($auditRows)): ?>
                <div class="audit-empty m-3 p-4 text-center">
                  <div class="fw-semibold text-muted mb-1">No workload transactions found.</div>
                  <div class="audit-muted">New add, remove, bulk remove, and faculty-need actions will appear here after they happen.</div>
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover mb-0 audit-table">
                    <thead>
                      <tr>
                        <th>Date / Time</th>
                        <th>User</th>
                        <th>College</th>
                        <th>Action</th>
                        <th>Assignment</th>
                        <th>Class / Scope</th>
                        <th>Affected IDs</th>
                        <th>Details</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($auditRows as $row): ?>
                        <?php
                          $details = audit_page_details($row);
                          $detailsJson = audit_page_details_json($details);
                          $actionType = (string)($row['action_type'] ?? '');
                          $actorName = trim((string)($row['actor_display_name'] ?? $row['actor_username'] ?? 'Unknown user'));
                          $actorRole = trim((string)($row['actor_role'] ?? ''));
                          $actorId = (int)($row['actor_user_id'] ?? 0);
                        ?>
                        <tr>
                          <td style="min-width: 150px;">
                            <div class="fw-semibold"><?= audit_page_h(audit_page_date_label($row['date_created'] ?? '')); ?></div>
                            <div class="audit-muted">Audit #<?= (int)($row['audit_id'] ?? 0); ?></div>
                          </td>
                          <td style="min-width: 170px;">
                            <div class="fw-semibold"><?= audit_page_h($actorName); ?></div>
                            <div class="audit-muted">
                              <?= $actorId > 0 ? 'User #' . $actorId : 'User not captured'; ?>
                              <?= $actorRole !== '' ? ' | ' . audit_page_h(ucfirst($actorRole)) : ''; ?>
                            </div>
                          </td>
                          <td style="min-width: 170px;">
                            <div class="fw-semibold"><?= audit_page_h(audit_page_college_label($row, $details)); ?></div>
                            <div class="audit-muted">
                              <?= (int)($row['college_id'] ?? 0) > 0 ? 'College #' . (int)$row['college_id'] : 'Scope not captured'; ?>
                            </div>
                          </td>
                          <td>
                            <span class="audit-action-badge <?= audit_page_h(audit_page_action_class($actionType)); ?>">
                              <?= audit_page_h($row['action_label'] ?? synk_workload_audit_action_label($actionType)); ?>
                            </span>
                            <div class="audit-muted mt-1">Affected: <?= number_format(max(1, (int)($row['affected_count'] ?? 1))); ?></div>
                          </td>
                          <td style="min-width: 170px;">
                            <div class="fw-semibold"><?= audit_page_h(audit_page_assignment_label($row, $details)); ?></div>
                            <div class="audit-muted"><?= audit_page_h((string)($row['assignee_type'] ?? '')); ?></div>
                          </td>
                          <td style="min-width: 220px;">
                            <div class="fw-semibold"><?= audit_page_h(audit_page_scope_label($row, $details)); ?></div>
                            <div class="audit-muted">
                              <?= audit_page_h((string)($row['ay_label'] ?? $details['ay_label'] ?? '')); ?>
                              <?= (int)($row['semester'] ?? 0) > 0 ? ' | ' . audit_page_h(audit_page_semester_label($row['semester'])) : ''; ?>
                            </div>
                          </td>
                          <td class="audit-id-list">
                            <?= audit_page_id_label($row, $details); ?>
                          </td>
                          <td style="min-width: 180px;">
                            <div class="audit-muted mb-1">
                              <?= audit_page_h((string)($row['ip_address'] ?? '')); ?>
                            </div>
                            <?php if ($detailsJson !== ''): ?>
                              <details class="audit-details">
                                <summary>View data</summary>
                                <pre><?= audit_page_h($detailsJson); ?></pre>
                              </details>
                            <?php else: ?>
                              <span class="audit-muted">No extra data</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
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
  <script src="../assets/vendor/libs/popper/popper.js"></script>
  <script src="../assets/vendor/js/bootstrap.js"></script>
  <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
  <script src="../assets/vendor/js/menu.js"></script>
  <script src="../assets/js/main.js"></script>
</body>
</html>
