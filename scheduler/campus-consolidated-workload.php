<?php
session_start();
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/faculty_need_helper.php';
require_once '../backend/offering_scope_helper.php';
require_once '../backend/schema_helper.php';
require_once '../backend/scheduler_access_helper.php';
require_once '../backend/signatory_settings_helper.php';

synk_scheduler_bootstrap_session_scope($conn);

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

$campusId = (int)($_SESSION['campus_id'] ?? 0);
$campusName = trim((string)($_SESSION['campus_name'] ?? ''));
$collegeName = trim((string)($_SESSION['college_name'] ?? 'Assigned College'));

if ($campusId <= 0) {
    echo "Scheduler error: missing campus assignment.";
    exit;
}

if ($campusName === '' && synk_table_exists($conn, 'tbl_campus')) {
    $campusStmt = $conn->prepare("
        SELECT campus_name
        FROM tbl_campus
        WHERE campus_id = ?
        LIMIT 1
    ");

    if ($campusStmt instanceof mysqli_stmt) {
        $campusStmt->bind_param('i', $campusId);
        $campusStmt->execute();
        $campusResult = $campusStmt->get_result();
        $campusRow = $campusResult instanceof mysqli_result ? $campusResult->fetch_assoc() : null;
        $campusName = trim((string)($campusRow['campus_name'] ?? ''));
        $campusStmt->close();
    }
}

if ($campusName === '') {
    $campusName = 'Current Campus';
}

function scheduler_consolidated_title_case(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = strtolower($value);
    return (string)preg_replace_callback('/(^|[\s,\/-])([a-z])/', static function ($matches) {
        return $matches[1] . strtoupper($matches[2]);
    }, $value);
}

function scheduler_consolidated_format_faculty_name(array $row): string
{
    $lastName = trim((string)($row['last_name'] ?? ''));
    $firstName = trim((string)($row['first_name'] ?? ''));
    $middleName = trim((string)($row['middle_name'] ?? ''));
    $extName = trim((string)($row['ext_name'] ?? ''));
    $fullName = $lastName;

    if ($firstName !== '') {
        $fullName .= ($fullName !== '' ? ', ' : '') . $firstName;
    }

    if ($middleName !== '') {
        $fullName .= ' ' . strtoupper(substr($middleName, 0, 1)) . '.';
    }

    if ($extName !== '') {
        $fullName .= ', ' . $extName;
    }

    return trim($fullName, ' ,');
}

function scheduler_consolidated_unique_labels(array $labels): array
{
    $bucket = [];
    foreach ($labels as $label) {
        $label = trim((string)$label);
        if ($label !== '') {
            $bucket[$label] = true;
        }
    }

    $labels = array_keys($bucket);
    natcasesort($labels);
    return array_values($labels);
}

function scheduler_consolidated_report_signatory_slot(array $settings, string $slotCode, string $fallbackLabel): array
{
    $row = is_array($settings[$slotCode] ?? null) ? $settings[$slotCode] : [];

    return [
        'slot_code' => $slotCode,
        'label' => trim((string)($row['label'] ?? $fallbackLabel)) ?: $fallbackLabel,
        'signatory_name' => trim((string)($row['signatory_name'] ?? '')),
        'signatory_title' => trim((string)($row['signatory_title'] ?? '')),
    ];
}

$currentTerm = synk_fetch_current_academic_term($conn);
$currentAyId = (int)($currentTerm['ay_id'] ?? 0);
$currentSem = (int)($currentTerm['semester'] ?? 0);
$academicTermText = trim((string)($currentTerm['term_text'] ?? 'Current academic term'));
$assigneeMap = [];

if (
    synk_table_exists($conn, 'tbl_faculty')
    && synk_table_exists($conn, 'tbl_college_faculty')
    && synk_table_exists($conn, 'tbl_college')
    && synk_table_exists($conn, 'tbl_campus')
) {
    $assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
    $assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');
    $facultyHasMiddleName = synk_table_has_column($conn, 'tbl_faculty', 'middle_name');
    $facultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');
    $facultyHasStatus = synk_table_has_column($conn, 'tbl_faculty', 'status');
    $facultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
    $facultyDesignationTextColumn = null;

    foreach (['designation', 'designation_name'] as $candidate) {
        if (synk_table_has_column($conn, 'tbl_faculty', $candidate)) {
            $facultyDesignationTextColumn = $candidate;
            break;
        }
    }

    $designationTableExists = synk_table_exists($conn, 'tbl_designation');
    $designationHasName = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'designation_name');
    $designationHasUnits = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'designation_units');
    $designationHasStatus = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'status');
    $designationJoinSql = '';
    $designationSelectId = $facultyHasDesignationId ? 'f.designation_id' : '0';
    $facultyDesignationExpr = $facultyDesignationTextColumn !== null
        ? "NULLIF(TRIM(f.`{$facultyDesignationTextColumn}`), '')"
        : 'NULL';
    $designationNameExpr = "''";
    $designationUnitsExpr = '0';

    if ($facultyHasDesignationId && $designationTableExists && $designationHasName) {
        $designationJoinSql = "
            LEFT JOIN tbl_designation d
                ON d.designation_id = f.designation_id
               " . ($designationHasStatus ? "AND d.status = 'active'" : '') . "
        ";
        $designationNameExpr = "COALESCE(NULLIF(TRIM(d.designation_name), ''), {$facultyDesignationExpr}, '')";
        $designationUnitsExpr = $designationHasUnits ? 'COALESCE(d.designation_units, 0)' : '0';
    } elseif ($facultyDesignationExpr !== 'NULL') {
        $designationNameExpr = "COALESCE({$facultyDesignationExpr}, '')";
    }

    $facultyWhere = [
        "LOWER(TRIM(COALESCE(cf.status, 'active'))) = 'active'",
        'camp.campus_id = ?',
    ];
    $facultyTypes = 'i';
    $facultyParams = [$campusId];

    if ($assignmentHasAyId && $assignmentHasSemester && $currentAyId > 0 && $currentSem > 0) {
        $facultyWhere[] = '((cf.ay_id = ? AND cf.semester = ?) OR (cf.ay_id IS NULL AND cf.semester IS NULL))';
        $facultyTypes .= 'ii';
        $facultyParams[] = $currentAyId;
        $facultyParams[] = $currentSem;
    }

    $facultyGroupBy = ['f.faculty_id', 'f.last_name', 'f.first_name'];
    if ($facultyHasMiddleName) {
        $facultyGroupBy[] = 'f.middle_name';
    }
    if ($facultyHasExtName) {
        $facultyGroupBy[] = 'f.ext_name';
    }
    if ($facultyHasStatus) {
        $facultyGroupBy[] = 'f.status';
    }
    $facultyGroupBy[] = $designationSelectId;
    $facultyGroupBy[] = $designationNameExpr;
    $facultyGroupBy[] = $designationUnitsExpr;

    $facultySql = "
        SELECT
            f.faculty_id,
            f.last_name,
            f.first_name,
            " . ($facultyHasMiddleName ? 'f.middle_name' : 'NULL AS middle_name') . ",
            " . ($facultyHasExtName ? 'f.ext_name' : 'NULL AS ext_name') . ",
            " . ($facultyHasStatus ? "LOWER(TRIM(COALESCE(f.status, 'active'))) AS faculty_status" : "'active' AS faculty_status") . ",
            {$designationSelectId} AS designation_id,
            {$designationNameExpr} AS designation_name,
            {$designationUnitsExpr} AS designation_units,
            GROUP_CONCAT(DISTINCT col.college_name ORDER BY col.college_name SEPARATOR '||') AS college_names,
            GROUP_CONCAT(DISTINCT camp.campus_name ORDER BY camp.campus_name SEPARATOR '||') AS campus_names
        FROM tbl_college_faculty cf
        INNER JOIN tbl_faculty f
            ON f.faculty_id = cf.faculty_id
        INNER JOIN tbl_college col
            ON col.college_id = cf.college_id
        INNER JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id
        {$designationJoinSql}
        WHERE " . implode("\n          AND ", $facultyWhere) . "
        GROUP BY " . implode(",\n            ", $facultyGroupBy) . "
        ORDER BY f.last_name ASC, f.first_name ASC, f.faculty_id ASC
    ";

    $facultyStmt = $conn->prepare($facultySql);
    if ($facultyStmt instanceof mysqli_stmt && synk_bind_dynamic_params($facultyStmt, $facultyTypes, $facultyParams)) {
        $facultyStmt->execute();
        $facultyResult = $facultyStmt->get_result();

        if ($facultyResult instanceof mysqli_result) {
            while ($row = $facultyResult->fetch_assoc()) {
                $facultyId = (int)($row['faculty_id'] ?? 0);
                if ($facultyId <= 0) {
                    continue;
                }

                $status = strtolower(trim((string)($row['faculty_status'] ?? 'active')));
                if ($status !== 'inactive') {
                    $status = 'active';
                }

                $designationName = trim((string)($row['designation_name'] ?? ''));
                $collegeNames = scheduler_consolidated_unique_labels(explode('||', (string)($row['college_names'] ?? '')));
                $campusNames = scheduler_consolidated_unique_labels(explode('||', (string)($row['campus_names'] ?? '')));

                $assigneeMap['faculty:' . $facultyId] = [
                    'assignee_type' => 'faculty',
                    'faculty_id' => $facultyId,
                    'faculty_need_id' => 0,
                    'college_id' => 0,
                    'full_name' => scheduler_consolidated_format_faculty_name($row),
                    'status' => $status,
                    'designation_name' => $designationName,
                    'designation_label' => scheduler_consolidated_title_case($designationName),
                    'designation_units' => round((float)($row['designation_units'] ?? 0), 2),
                    'total_preparations' => 0,
                    'college_label' => implode(', ', $collegeNames),
                    'campus_label' => implode(', ', $campusNames),
                ];
            }
        }

        $facultyStmt->close();
    } elseif ($facultyStmt instanceof mysqli_stmt) {
        $facultyStmt->close();
    }

    if (
        $currentAyId > 0
        && $currentSem > 0
        && synk_table_exists($conn, 'tbl_faculty_workload_sched')
        && synk_table_exists($conn, 'tbl_class_schedule')
        && synk_table_exists($conn, 'tbl_prospectus_offering')
        && synk_table_exists($conn, 'tbl_program')
    ) {
        $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
        $workloadSql = "
            SELECT
                f.faculty_id,
                f.last_name,
                f.first_name,
                " . ($facultyHasMiddleName ? 'f.middle_name' : 'NULL AS middle_name') . ",
                " . ($facultyHasExtName ? 'f.ext_name' : 'NULL AS ext_name') . ",
                " . ($facultyHasStatus ? "LOWER(TRIM(COALESCE(f.status, 'active'))) AS faculty_status" : "'active' AS faculty_status") . ",
                {$designationSelectId} AS designation_id,
                {$designationNameExpr} AS designation_name,
                {$designationUnitsExpr} AS designation_units,
                GROUP_CONCAT(DISTINCT col.college_name ORDER BY col.college_name SEPARATOR '||') AS college_names,
                GROUP_CONCAT(DISTINCT camp.campus_name ORDER BY camp.campus_name SEPARATOR '||') AS campus_names
            FROM tbl_faculty_workload_sched fw
            INNER JOIN tbl_faculty f
                ON f.faculty_id = fw.faculty_id
            INNER JOIN tbl_class_schedule cs
                ON cs.schedule_id = fw.schedule_id
            INNER JOIN tbl_prospectus_offering o
                ON o.offering_id = cs.offering_id
            {$liveOfferingJoins}
            INNER JOIN tbl_program p
                ON p.program_id = o.program_id
            INNER JOIN tbl_college col
                ON col.college_id = p.college_id
            INNER JOIN tbl_campus camp
                ON camp.campus_id = col.campus_id
            {$designationJoinSql}
            WHERE fw.ay_id = ?
              AND fw.semester = ?
              AND camp.campus_id = ?
            GROUP BY " . implode(",\n                ", $facultyGroupBy) . "
            ORDER BY f.last_name ASC, f.first_name ASC, f.faculty_id ASC
        ";

        $workloadStmt = $conn->prepare($workloadSql);
        if ($workloadStmt instanceof mysqli_stmt) {
            $workloadStmt->bind_param('iii', $currentAyId, $currentSem, $campusId);
            $workloadStmt->execute();
            $workloadResult = $workloadStmt->get_result();

            if ($workloadResult instanceof mysqli_result) {
                while ($row = $workloadResult->fetch_assoc()) {
                    $facultyId = (int)($row['faculty_id'] ?? 0);
                    if ($facultyId <= 0 || isset($assigneeMap['faculty:' . $facultyId])) {
                        continue;
                    }

                    $status = strtolower(trim((string)($row['faculty_status'] ?? 'active')));
                    if ($status !== 'inactive') {
                        $status = 'active';
                    }

                    $designationName = trim((string)($row['designation_name'] ?? ''));
                    $collegeNames = scheduler_consolidated_unique_labels(explode('||', (string)($row['college_names'] ?? '')));
                    $campusNames = scheduler_consolidated_unique_labels(explode('||', (string)($row['campus_names'] ?? '')));

                    $assigneeMap['faculty:' . $facultyId] = [
                        'assignee_type' => 'faculty',
                        'faculty_id' => $facultyId,
                        'faculty_need_id' => 0,
                        'college_id' => 0,
                        'full_name' => scheduler_consolidated_format_faculty_name($row),
                        'status' => $status,
                        'designation_name' => $designationName,
                        'designation_label' => scheduler_consolidated_title_case($designationName),
                        'designation_units' => round((float)($row['designation_units'] ?? 0), 2),
                        'total_preparations' => 0,
                        'college_label' => implode(', ', $collegeNames),
                        'campus_label' => implode(', ', $campusNames),
                    ];
                }
            }

            $workloadStmt->close();
        }
    }
}

if ($currentAyId > 0 && $currentSem > 0 && synk_table_exists($conn, 'tbl_college')) {
    synk_faculty_need_ensure_tables($conn);

    $needStmt = $conn->prepare("
        SELECT
            fn.faculty_need_id,
            fn.college_id,
            fn.need_label,
            COALESCE(col.college_name, '') AS college_name,
            COALESCE(camp.campus_name, '') AS campus_name
        FROM `" . synk_faculty_need_table_name() . "` fn
        INNER JOIN tbl_college col
            ON col.college_id = fn.college_id
        LEFT JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id
        WHERE fn.ay_id = ?
          AND fn.semester = ?
          AND fn.status = 'active'
          AND camp.campus_id = ?
        ORDER BY col.college_name ASC, fn.faculty_need_id ASC
    ");

    if ($needStmt instanceof mysqli_stmt) {
        $needStmt->bind_param('iii', $currentAyId, $currentSem, $campusId);
        $needStmt->execute();
        $needResult = $needStmt->get_result();

        if ($needResult instanceof mysqli_result) {
            while ($row = $needResult->fetch_assoc()) {
                $facultyNeedId = (int)($row['faculty_need_id'] ?? 0);
                $needCollegeId = (int)($row['college_id'] ?? 0);
                $needLabel = trim((string)($row['need_label'] ?? ''));
                if ($facultyNeedId <= 0 || $needCollegeId <= 0 || $needLabel === '') {
                    continue;
                }

                $assigneeMap['need:' . $facultyNeedId] = [
                    'assignee_type' => 'faculty_need',
                    'faculty_id' => 0,
                    'faculty_need_id' => $facultyNeedId,
                    'college_id' => $needCollegeId,
                    'full_name' => $needLabel,
                    'status' => 'active',
                    'designation_name' => '',
                    'designation_label' => '',
                    'designation_units' => 0,
                    'total_preparations' => 0,
                    'college_label' => trim((string)($row['college_name'] ?? '')),
                    'campus_label' => trim((string)($row['campus_name'] ?? '')),
                ];
            }
        }

        $needStmt->close();
    }
}

$assignees = array_values($assigneeMap);
usort($assignees, static function (array $left, array $right): int {
    $nameCompare = strnatcasecmp((string)($left['full_name'] ?? ''), (string)($right['full_name'] ?? ''));
    if ($nameCompare !== 0) {
        return $nameCompare;
    }

    return strnatcasecmp((string)($left['assignee_type'] ?? ''), (string)($right['assignee_type'] ?? ''));
});

$assigneesJson = json_encode($assignees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($assigneesJson === false) {
    $assigneesJson = '[]';
}

$globalSignatorySettings = synk_fetch_signatory_settings($conn, 'global', 0);
$reportSignatories = [
    'prepared_by' => scheduler_consolidated_report_signatory_slot([], 'prepared_by', 'Prepared by'),
    'checked_by_left' => scheduler_consolidated_report_signatory_slot($globalSignatorySettings, 'checked_by_left', 'Checked by'),
    'checked_by_right' => scheduler_consolidated_report_signatory_slot($globalSignatorySettings, 'checked_by_right', 'Checked by'),
    'recommending_approval' => scheduler_consolidated_report_signatory_slot($globalSignatorySettings, 'recommending_approval', 'Recommending Approval'),
    'approved_by' => scheduler_consolidated_report_signatory_slot($globalSignatorySettings, 'approved_by', 'Approved by'),
];

$reportScope = [
    'view_type' => 'campus',
    'campus_id' => $campusId,
    'campus_name' => $campusName,
    'college_name' => 'All Colleges',
    'scope_label' => $campusName . ' Campus',
];

$academicTermTextEscaped = htmlspecialchars($academicTermText, ENT_QUOTES, 'UTF-8');
$campusNameEscaped = htmlspecialchars($campusName, ENT_QUOTES, 'UTF-8');
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
  <title>Campus Consolidated Workload | Synk Scheduler</title>

  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <link rel="stylesheet" type="text/css" href="custom_css.css" />

  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>

  <style id="consolidatedReportStyleTag">
    .report-hero {
      border: 1px solid #e3e8f2;
      box-shadow: 0 12px 30px rgba(67, 89, 113, 0.07);
    }

    .report-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.65rem;
    }

    .consolidated-report-preview-root {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
      align-items: stretch;
      overflow-x: auto;
    }

    .consolidated-report-loading {
      min-height: 50vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .consolidated-report-loading-card,
    .consolidated-report-empty-state {
      width: min(100%, 560px);
      margin: 0 auto;
      background: #fff;
      border: 1px solid #dbe5f1;
      border-radius: 1rem;
      box-shadow: 0 20px 45px rgba(22, 40, 75, 0.08);
      padding: 1.5rem;
      text-align: center;
    }

    .consolidated-report-loading-card .spinner-border,
    .consolidated-report-empty-state i {
      width: 2.1rem;
      height: 2.1rem;
      margin-bottom: 0.75rem;
    }

    .consolidated-report-empty-state i {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      color: #5d68f4;
    }

    .consolidated-report-loading-title,
    .consolidated-report-empty-title {
      font-size: 1.08rem;
      font-weight: 700;
      color: #304257;
      margin-bottom: 0.35rem;
    }

    .consolidated-report-loading-copy,
    .consolidated-report-empty-copy {
      font-size: 0.92rem;
      color: #6a7d94;
      margin-bottom: 0.9rem;
    }

    .consolidated-report-progress {
      display: flex;
      flex-direction: column;
      gap: 0.45rem;
      align-items: stretch;
    }

    .consolidated-report-progress-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      font-size: 0.82rem;
      color: #61748b;
    }

    .consolidated-report-progress-track {
      width: 100%;
      height: 0.55rem;
      background: #e6edf7;
      border-radius: 999px;
      overflow: hidden;
    }

    .consolidated-report-progress-bar {
      width: 0;
      height: 100%;
      background: linear-gradient(90deg, #198754 0%, #5d68f4 100%);
      transition: width 0.2s ease;
    }

    .consolidated-report-page {
      --consolidated-page-width: 1344px;
      --consolidated-page-height: 816px;
      position: relative;
      width: var(--consolidated-page-width);
      height: var(--consolidated-page-height);
      margin: 0 auto;
      background: #fff;
      box-shadow: 0 22px 50px rgba(25, 42, 70, 0.16);
      overflow: hidden;
      flex: 0 0 auto;
    }

    .consolidated-report-page.is-measure {
      --consolidated-page-width: 1344px;
      --consolidated-page-height: 816px;
      box-shadow: none;
      margin: 0;
    }

    .consolidated-report-page-background {
      position: absolute;
      inset: 0;
      background-image: var(--consolidated-report-bg);
      background-repeat: no-repeat;
      background-position: center;
      background-size: 100% 100%;
    }

    .consolidated-report-page-inner {
      position: absolute;
      inset: 188px 48px 68px 74px;
      display: flex;
      flex-direction: column;
      min-height: 0;
    }

    .consolidated-report-page-header {
      text-align: center;
      padding: 0 0 0.32rem;
    }

    .consolidated-report-title {
      font-family: "Arial Narrow", Arial, sans-serif;
      font-size: 1.14rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #0f3140;
      margin-bottom: 0.14rem;
    }

    .consolidated-report-subtitle,
    .consolidated-report-term,
    .consolidated-report-page-count {
      font-family: Arial, sans-serif;
      color: #10212f;
      line-height: 1.15;
    }

    .consolidated-report-subtitle {
      font-size: 0.78rem;
      font-weight: 700;
      text-transform: uppercase;
    }

    .consolidated-report-term {
      margin-top: 0.12rem;
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.02em;
    }

    .consolidated-report-page-count {
      margin-top: 0.12rem;
      font-size: 0.68rem;
      font-weight: 600;
      color: #4b5f70;
    }

    .consolidated-report-table-wrap {
      flex: 1 1 auto;
      min-height: 0;
      padding-inline: 24px;
      box-sizing: border-box;
    }

    .consolidated-report-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      font-family: Arial, sans-serif;
      font-size: 9.25px;
      color: #000;
      background: #fff;
    }

    .consolidated-report-table thead th,
    .consolidated-report-table tbody td {
      border: 1px solid #000;
      padding: 0.18rem 0.22rem;
      vertical-align: top;
      box-sizing: border-box;
      overflow-wrap: anywhere;
      word-break: normal;
    }

    .consolidated-report-table thead th {
      background: #fff;
      color: #000;
      font-size: 8.4px;
      font-weight: 700;
      line-height: 1.15;
      text-align: center;
      text-transform: uppercase;
    }

    .consolidated-report-table thead .is-lower {
      text-transform: none;
    }

    .consolidated-report-table tbody td {
      line-height: 1.18;
    }

    .consolidated-cell-index,
    .consolidated-cell-name,
    .consolidated-cell-prep,
    .consolidated-cell-students,
    .consolidated-cell-units,
    .consolidated-cell-hours,
    .consolidated-cell-load,
    .consolidated-cell-designation,
    .consolidated-cell-total,
    .consolidated-cell-remark {
      text-align: center;
      vertical-align: top !important;
    }

    .consolidated-cell-index,
    .consolidated-cell-name,
    .consolidated-cell-prep,
    .consolidated-cell-designation,
    .consolidated-cell-total,
    .consolidated-cell-remark {
      padding-top: 0.28rem !important;
    }

    .consolidated-cell-index {
      font-weight: 700;
    }

    .consolidated-cell-name {
      font-weight: 700;
      text-transform: uppercase;
      line-height: 1.12;
    }

    .consolidated-cell-prep,
    .consolidated-cell-code,
    .consolidated-cell-load,
    .consolidated-cell-total,
    .consolidated-cell-remark {
      font-weight: 700;
    }

    .consolidated-campus-term {
      font-size: 9px !important;
      font-weight: 700 !important;
      text-transform: none !important;
      padding: 0.28rem 0.2rem !important;
    }

    .consolidated-campus-term-line {
      display: block;
      line-height: 1.2;
    }

    .consolidated-campus-term-line + .consolidated-campus-term-line {
      margin-top: 0.08rem;
    }

    .consolidated-course-title,
    .consolidated-designation-title {
      font-weight: 700;
      color: #000;
    }

    .consolidated-schedule-line {
      display: block;
      white-space: pre-line;
    }

    .consolidated-schedule-line + .consolidated-schedule-line {
      margin-top: 0.12rem;
    }

    .consolidated-remark-text {
      display: inline-block;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      color: #000;
    }

    .consolidated-faculty-total-row td {
      border-top-width: 1px;
      font-weight: 700;
      vertical-align: middle !important;
    }

    .consolidated-faculty-block,
    .consolidated-report-table tr {
      page-break-inside: avoid;
      break-inside: avoid;
    }

    .consolidated-cell-remark-total {
      text-align: center;
      vertical-align: middle !important;
    }

    .consolidated-signatory-page .consolidated-report-page-inner {
      inset: 208px 90px 88px 100px;
    }

    .consolidated-signatory-wrap {
      margin-top: auto;
      padding-top: 1rem;
      font-family: Arial, sans-serif;
      color: #182b3d;
    }

    .consolidated-signatory-top {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1.6rem;
    }

    .consolidated-signatory-bottom {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 2rem;
      margin-top: 2.5rem;
      max-width: 31%;
    }

    .consolidated-signatory-label {
      margin-bottom: 1.5rem;
      font-size: 0.92rem;
      font-weight: 700;
    }

    .consolidated-signatory-name {
      min-height: 1.2rem;
      font-size: 0.86rem;
      font-weight: 800;
      text-transform: uppercase;
      border-bottom: 1px solid #455a6f;
      padding-bottom: 0.08rem;
    }

    .consolidated-signatory-title {
      min-height: 1.15rem;
      margin-top: 0.28rem;
      font-size: 0.82rem;
      color: #50657c;
    }

    #consolidatedReportMeasureRoot {
      position: absolute;
      left: -20000px;
      top: 0;
      visibility: hidden;
      pointer-events: none;
      width: 0;
      height: 0;
      overflow: hidden;
    }

    @media (max-width: 1199.98px) {
      .consolidated-report-preview-root {
        align-items: flex-start;
      }
    }

    @media (max-width: 767.98px) {
      .consolidated-signatory-top {
        grid-template-columns: 1fr;
        gap: 1.2rem;
      }

      .consolidated-signatory-bottom {
        max-width: 100%;
      }
    }

    @media print {
      html,
      body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      body.consolidated-report-print-body .consolidated-report-preview-root {
        gap: 0 !important;
      }

      body.consolidated-report-print-body .consolidated-report-page {
        --consolidated-page-width: 14in;
        --consolidated-page-height: 8.5in;
        width: 14in !important;
        height: 8.5in !important;
        margin: 0 !important;
        box-shadow: none !important;
        page-break-after: always;
        break-after: page;
      }

      body.consolidated-report-print-body .consolidated-report-page:last-child {
        page-break-after: auto;
        break-after: auto;
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
            <div class="card report-hero mb-4">
              <div class="card-body d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
                <div>
                  <p class="text-muted text-uppercase fw-semibold small mb-1">Monitoring</p>
                  <h4 class="fw-bold mb-1">Campus Consolidated Faculty Workload</h4>
                  <p class="text-muted mb-0">
                    <?= $campusNameEscaped; ?> Campus | <?= $academicTermTextEscaped; ?>
                  </p>
                </div>
                <div class="report-toolbar">
                  <button type="button" class="btn btn-outline-primary" id="btnRefreshConsolidatedReport">
                    <i class="bx bx-refresh me-1"></i> Refresh Preview
                  </button>
                  <button type="button" class="btn btn-outline-success" id="btnExportConsolidatedExcel" disabled>
                    <i class="bx bx-spreadsheet me-1"></i> Export Excel
                  </button>
                  <button type="button" class="btn btn-primary" id="btnPrintConsolidatedReport" disabled>
                    <i class="bx bx-printer me-1"></i> Print / Save PDF
                  </button>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                <div>
                  <h5 class="m-0">Report Preview</h5>
                  <small class="text-muted">The report is automatically scoped to the campus assigned to your scheduler account.</small>
                </div>
                <span class="badge bg-label-info"><?= count($assignees); ?> assignee<?= count($assignees) === 1 ? '' : 's'; ?> found</span>
              </div>
              <div id="consolidatedReportPreviewRoot" class="consolidated-report-preview-root">
                <div class="consolidated-report-loading">
                  <div class="consolidated-report-loading-card">
                    <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                    <div class="consolidated-report-loading-title">Preparing consolidated faculty workload</div>
                    <div class="consolidated-report-loading-copy">The campus preview will generate automatically.</div>
                    <div class="consolidated-report-progress">
                      <div class="consolidated-report-progress-meta">
                        <span id="consolidatedReportProgressLabel">Waiting to start</span>
                        <span id="consolidatedReportProgressValue">0%</span>
                      </div>
                      <div class="consolidated-report-progress-track">
                        <div id="consolidatedReportProgressBar" class="consolidated-report-progress-bar"></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div id="consolidatedReportMeasureRoot" aria-hidden="true"></div>
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

  <script>
    document.addEventListener("DOMContentLoaded", function () {
      var reportCampusId = <?= (int)$campusId; ?>;
      var currentAyId = <?= (int)$currentAyId; ?>;
      var currentSem = <?= (int)$currentSem; ?>;
      var currentAyLabel = <?= json_encode((string)($currentTerm['ay_label'] ?? '')); ?>;
      var academicTermText = <?= json_encode($academicTermText); ?>;
      var reportAssignees = <?= $assigneesJson; ?>;
      var reportScope = <?= json_encode($reportScope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      var reportSignatories = <?= json_encode($reportSignatories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      var reportBackgroundUrl = new URL("../assets/img/print/consolidated-faculty-workload-template.png", window.location.href).href;
      var reportGeneratedHtml = "";
      var reportRecords = [];
      var reportIsBuilding = false;

      function escapeHtml(value) {
        return String(value == null ? "" : value)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/'/g, "&#039;");
      }

      function toNumber(value) {
        var numericValue = Number(value);
        return Number.isFinite(numericValue) ? numericValue : 0;
      }

      function formatNumber(value) {
        var numericValue = toNumber(value);
        return Number.isInteger(numericValue) ? String(numericValue) : String(parseFloat(numericValue.toFixed(2)));
      }

      function formatStudentCount(value) {
        var numericValue = Math.round(toNumber(value));
        return numericValue > 0 ? String(numericValue) : "";
      }

      function uppercaseDisplayText(value) {
        return String(value == null ? "" : value).trim().toUpperCase();
      }

      function semesterUiLabel(value) {
        var semester = Number(value) || 0;
        if (semester === 1) {
          return "1st";
        }
        if (semester === 2) {
          return "2nd";
        }
        if (semester === 3) {
          return "Midyear";
        }
        return "Current";
      }

      function getTermContext() {
        return {
          ayId: Number(currentAyId) || 0,
          ayText: String(currentAyLabel || "").trim(),
          semesterNum: Number(currentSem) || 0,
          semesterUi: semesterUiLabel(currentSem),
          termText: String(academicTermText || "Current academic term").trim()
        };
      }

      function getReportTermLabel(termContext) {
        if (termContext && termContext.ayText) {
          return String(termContext.semesterUi || "Current") + " Semester AY " + termContext.ayText;
        }
        return String(termContext && termContext.termText || academicTermText || "Current academic term");
      }

      function getCampusHeaderLines(termContext) {
        var campusSource = String(reportScope && reportScope.campus_name || "Campus").trim();
        var campusLabel = campusSource.replace(/\s+campus$/i, "").trim().toUpperCase() || "CAMPUS";
        var semesterLabel = String(termContext && termContext.semesterUi || "").trim() || "Semester";
        var ayLabel = String(termContext && termContext.ayText || "").trim();

        return [
          campusLabel,
          "Campus",
          semesterLabel,
          ayLabel ? "Semester AY " + ayLabel : "Semester AY"
        ];
      }

      function getNormalLoadUnits(preparationCount) {
        var prepCount = Math.max(0, Math.floor(toNumber(preparationCount)));
        return prepCount >= 2 ? 18 : 21;
      }

      function getLoadStatus(loadValue, preparationCount) {
        var numericLoad = toNumber(loadValue);
        var normalLoadUnits = getNormalLoadUnits(preparationCount);
        var tolerance = 0.0001;

        if (numericLoad > normalLoadUnits + tolerance) {
          return { label: "Overload", className: "overload" };
        }
        if (numericLoad >= normalLoadUnits - tolerance) {
          return { label: "", className: "normal" };
        }
        return { label: "Underload", className: "underload" };
      }

      function getRemarkMeta(loadStatus) {
        var statusClass = String(loadStatus && loadStatus.className || "normal").trim() || "normal";
        if (statusClass === "overload") {
          return { code: "OL", className: "is-overload" };
        }
        if (statusClass === "underload") {
          return { code: "UL", className: "is-underload" };
        }
        return { code: "NL", className: "is-normal" };
      }

      function formatRemarkVariance(value) {
        var n = Math.abs(toNumber(value));
        return n > 0.0001 ? n.toFixed(2) : "";
      }

      function getWorkloadGroupKey(row) {
        var groupId = Number(row && row.group_id) || 0;
        if (groupId > 0) {
          return "group:" + groupId;
        }

        var offeringId = Number(row && row.offering_id) || 0;
        if (offeringId > 0) {
          return "offering:" + offeringId;
        }

        return "workload:" + (Number(row && row.workload_id) || 0);
      }

      function calculateWorkloadMetricTotals(rowsData) {
        var sourceRows = Array.isArray(rowsData) ? rowsData : [];
        var countedGroups = new Set();
        var preparationSet = new Set();
        var totals = {
          unit: 0,
          lab: 0,
          lec: 0,
          load: 0,
          preparations: 0
        };

        sourceRows.forEach(function (row) {
          var groupKey = getWorkloadGroupKey(row);
          var preparationKey = String(row && row.sub_code || "").trim();

          if (!countedGroups.has(groupKey)) {
            countedGroups.add(groupKey);
            totals.unit += toNumber(row && row.units);
            totals.lab += toNumber(row && row.lab);
            totals.lec += toNumber(row && row.lec);
            totals.load += toNumber(row && row.faculty_load);
          }

          if (preparationKey !== "") {
            preparationSet.add(preparationKey);
          }
        });

        totals.preparations = preparationSet.size;
        return totals;
      }

      function normalizeWorkloadResponse(payload) {
        if (Array.isArray(payload)) {
          return { rows: payload, external_rows: [], meta: {} };
        }
        if (!payload || !Array.isArray(payload.rows)) {
          return null;
        }
        return {
          rows: payload.rows,
          external_rows: Array.isArray(payload.external_rows) ? payload.external_rows : [],
          meta: payload.meta || {}
        };
      }

      function formatDesignationDisplay(meta, option) {
        var sourceMeta = meta || {};
        var designationName = String(sourceMeta.designation_name || option && option.designation_name || "").trim();
        var designationLabel = String(sourceMeta.designation_label || option && (option.designation_label || option.designation_name) || "").trim();
        var collegeLabel = String(option && option.college_label || "").trim();

        if (!designationLabel) {
          return "";
        }

        if (designationName.toUpperCase() === "DEAN" && collegeLabel !== "") {
          return designationLabel + ", " + collegeLabel;
        }

        return designationLabel;
      }

      function setReportControlsEnabled(enabled) {
        $("#btnPrintConsolidatedReport, #btnExportConsolidatedExcel").prop("disabled", !enabled);
      }

      function setReportProgress(percent, label) {
        var safePercent = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
        $("#consolidatedReportProgressLabel").text(String(label || "Preparing preview..."));
        $("#consolidatedReportProgressValue").text(safePercent + "%");
        $("#consolidatedReportProgressBar").css("width", safePercent + "%");
      }

      function showReportLoading(label, percent) {
        var safePercent = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
        reportGeneratedHtml = "";
        reportRecords = [];
        setReportControlsEnabled(false);
        $("#consolidatedReportPreviewRoot").html(
          '<div class="consolidated-report-loading">' +
            '<div class="consolidated-report-loading-card">' +
              '<div class="spinner-border text-primary" role="status" aria-hidden="true"></div>' +
              '<div class="consolidated-report-loading-title">Preparing consolidated faculty workload</div>' +
              '<div class="consolidated-report-loading-copy">The preview is built from actual scheduler workload in your assigned campus.</div>' +
              '<div class="consolidated-report-progress">' +
                '<div class="consolidated-report-progress-meta">' +
                  '<span id="consolidatedReportProgressLabel">' + escapeHtml(label || "Preparing preview...") + "</span>" +
                  '<span id="consolidatedReportProgressValue">' + escapeHtml(String(safePercent)) + "%</span>" +
                "</div>" +
                '<div class="consolidated-report-progress-track">' +
                  '<div id="consolidatedReportProgressBar" class="consolidated-report-progress-bar" style="width:' + safePercent + '%;"></div>' +
                "</div>" +
              "</div>" +
            "</div>" +
          "</div>"
        );
      }

      function setReportEmptyState(title, message) {
        reportGeneratedHtml = "";
        reportRecords = [];
        setReportControlsEnabled(false);
        $("#consolidatedReportPreviewRoot").html(
          '<div class="consolidated-report-empty-state">' +
            '<i class="bx bx-file-blank"></i>' +
            '<div class="consolidated-report-empty-title">' + escapeHtml(title) + "</div>" +
            '<div class="consolidated-report-empty-copy">' + escapeHtml(message) + "</div>" +
          "</div>"
        );
      }

      function fetchAssigneeWorkload(option) {
        var assigneeType = String(option && option.assignee_type || "faculty").trim() === "faculty_need" ? "faculty_need" : "faculty";
        var facultyId = assigneeType === "faculty" ? (Number(option && option.faculty_id) || 0) : 0;
        var facultyNeedId = assigneeType === "faculty_need" ? (Number(option && option.faculty_need_id) || 0) : 0;
        var scopeCollegeId = assigneeType === "faculty_need" ? (Number(option && option.college_id) || 0) : 0;

        return new Promise(function (resolve, reject) {
          $.ajax({
            url: "../backend/query_admin_faculty_workload.php",
            type: "POST",
            dataType: "json",
            data: {
              assignee_type: assigneeType,
              faculty_id: facultyId,
              faculty_need_id: facultyNeedId,
              college_id: scopeCollegeId,
              campus_id: reportCampusId
            }
          }).done(resolve).fail(reject);
        });
      }

      function buildScheduleLine(row, includeTypeTag) {
        var dayLabel = String(row && row.days || "").trim();
        var timeLabel = String(row && row.time || "").trim();
        var typeLabel = String(row && (row.type || row.schedule_type) || "").trim().toUpperCase();
        var parts = [];
        var line;

        if (dayLabel) {
          parts.push(dayLabel);
        }
        if (timeLabel) {
          parts.push(timeLabel);
        }

        line = parts.join(" ").trim() || "-";
        if (includeTypeTag && typeLabel) {
          line += " (" + (typeLabel === "LAB" ? "Lab" : "Lec") + ")";
        }

        return line;
      }

      function groupRowsForReport(rowsData) {
        var groups = [];
        var groupedRows = new Map();
        var orderedRows = Array.isArray(rowsData) ? rowsData : [];

        orderedRows.forEach(function (row) {
          var groupKey = getWorkloadGroupKey(row);
          if (!groupedRows.has(groupKey)) {
            groupedRows.set(groupKey, []);
          }
          groupedRows.get(groupKey).push(row);
        });

        groupedRows.forEach(function (groupRows) {
          var firstRow = groupRows[0] || {};
          var typeMap = new Map();
          var scheduleLines = [];
          var uniqueTypes;

          groupRows.forEach(function (groupRow) {
            var typeLabel = String(groupRow && (groupRow.type || groupRow.schedule_type) || "").trim().toUpperCase();
            if (typeLabel) {
              typeMap.set(typeLabel, true);
            }
          });
          uniqueTypes = Array.from(typeMap.keys());

          groupRows.forEach(function (groupRow) {
            var line = buildScheduleLine(groupRow, uniqueTypes.length > 1);
            if (line && scheduleLines.indexOf(line) === -1) {
              scheduleLines.push(line);
            }
          });

          groups.push({
            sub_code: String(firstRow && firstRow.sub_code || "").trim(),
            desc: String(firstRow && firstRow.desc || "").trim(),
            course: String(firstRow && (firstRow.course || firstRow.section) || "").trim(),
            student_count: groupRows.reduce(function (maxValue, groupRow) {
              return Math.max(maxValue, Math.round(toNumber(groupRow && groupRow.student_count)));
            }, 0),
            units: toNumber(firstRow && firstRow.units),
            lec: toNumber(firstRow && firstRow.lec),
            lab: toNumber(firstRow && firstRow.lab),
            faculty_load: toNumber(firstRow && firstRow.faculty_load),
            schedule_lines: scheduleLines.length > 0 ? scheduleLines : ["-"],
            type_labels: uniqueTypes
          });
        });

        return groups;
      }

      function buildFacultyRecord(option, payload) {
        var sourcePayload = normalizeWorkloadResponse(payload);
        var rows;
        var entries;
        var meta;
        var totals;
        var designationUnits;
        var totalPreparations;
        var teachingLoad;
        var totalWorkload;
        var normalLoadUnits;
        var loadStatus;
        var remarkMeta;
        var overloadExcess;

        if (!sourcePayload) {
          return null;
        }

        rows = Array.isArray(sourcePayload.rows) ? sourcePayload.rows : [];
        entries = groupRowsForReport(rows);
        meta = sourcePayload.meta || {};
        totals = calculateWorkloadMetricTotals(rows);
        designationUnits = Math.max(toNumber(meta.designation_units), toNumber(option && option.designation_units));
        totalPreparations = Math.max(
          Number(meta.total_preparations) || 0,
          Number(option && option.total_preparations) || 0,
          totals.preparations
        );
        teachingLoad = totals.load;
        totalWorkload = teachingLoad + designationUnits;
        normalLoadUnits = getNormalLoadUnits(totalPreparations);
        loadStatus = getLoadStatus(totalWorkload, totalPreparations);
        remarkMeta = getRemarkMeta(loadStatus);
        overloadExcess = totalWorkload - normalLoadUnits;

        if (String(option && option.status || "").toLowerCase() === "inactive" && entries.length === 0 && teachingLoad <= 0.0001) {
          return null;
        }

        if (entries.length === 0 && totalWorkload <= 0.0001) {
          return null;
        }

        return {
          assignee_type: String(option && option.assignee_type || "faculty").trim() === "faculty_need" ? "faculty_need" : "faculty",
          faculty_id: Number(option && option.faculty_id) || 0,
          faculty_need_id: Number(option && option.faculty_need_id) || 0,
          full_name: String(option && option.full_name || "").trim(),
          display_name: uppercaseDisplayText(option && option.full_name || ""),
          total_preparations: totalPreparations,
          designation_text: formatDesignationDisplay(meta, option),
          designation_units: designationUnits,
          teaching_load: teachingLoad,
          total_workload: totalWorkload,
          normal_load_units: normalLoadUnits,
          remark_code: remarkMeta.code,
          remark_class: remarkMeta.className,
          remark_total: overloadExcess > 0.0001 ? overloadExcess : 0,
          entries: entries.length > 0 ? entries : [{
            sub_code: "",
            desc: "",
            course: "",
            student_count: 0,
            units: 0,
            lec: 0,
            lab: 0,
            faculty_load: 0,
            schedule_lines: ["-"],
            type_labels: []
          }]
        };
      }

      function renderReportTableHead(termContext) {
        var campusLines = getCampusHeaderLines(termContext).map(function (line) {
          return '<span class="consolidated-campus-term-line">' + escapeHtml(line) + "</span>";
        }).join("");

        return (
          "<thead>" +
            "<tr>" +
              '<th rowspan="3" colspan="2" class="consolidated-campus-term">' + campusLines + "</th>" +
              '<th rowspan="3">No. of<br>Preparations</th>' +
              '<th colspan="9">Actual Teaching Loads</th>' +
              '<th rowspan="3">Designation &amp; Load<br>Displacement</th>' +
              '<th rowspan="3">Total<br>Workload</th>' +
              '<th rowspan="3">Remarks<br>(OL/UL)</th>' +
            "</tr>" +
            "<tr>" +
              '<th rowspan="2">Course<br>Code</th>' +
              '<th rowspan="2">Course Title</th>' +
              '<th rowspan="2">Day &amp; Time</th>' +
              '<th rowspan="2">Course,<br>Year &amp; Section</th>' +
              '<th rowspan="2">No. of<br>Students</th>' +
              '<th rowspan="2">No. of<br>Units</th>' +
              '<th colspan="2">No of Hours</th>' +
              '<th rowspan="2">Teaching<br>Load</th>' +
            "</tr>" +
            "<tr>" +
              '<th class="is-lower">Lec</th>' +
              '<th class="is-lower">Lab</th>' +
            "</tr>" +
          "</thead>"
        );
      }

      function renderReportColGroup() {
        var columnWidths = [
          3.1, 11.4, 4.4, 6.8, 15.6,
          10.9, 9.1, 4.8, 4.4, 4,
          4, 4.8, 8.3, 4.3, 4.1
        ];

        return "<colgroup>" + columnWidths.map(function (width) {
          return '<col style="width:' + width + '%">';
        }).join("") + "</colgroup>";
      }

      function renderFacultyBlock(record, options) {
        var opts = options || {};
        var rows = Array.isArray(opts.entries)
          ? opts.entries
          : (Array.isArray(record && record.entries) ? record.entries : []);
        var showTotalRow = opts.showTotalRow !== false;
        var contentRowspan = Math.max(rows.length, 1);
        var blockRowspan = contentRowspan + (showTotalRow ? 1 : 0);
        var designationHtml = record.designation_text
          ? '<div class="consolidated-designation-title">' + escapeHtml(record.designation_text) + "</div>"
          : (record.designation_units > 0 ? '<div class="consolidated-designation-title">Load Displacement</div>' : "&nbsp;");
        var remarkTotalText = record.remark_code === "OL" ? formatRemarkVariance(record.remark_total) : "";
        var blockClasses = ["consolidated-faculty-block"];

        if (opts.continuation) {
          blockClasses.push("is-continuation");
        }

        return (
          '<tbody class="' + blockClasses.join(" ") + '">' +
            rows.map(function (entry, index) {
              var scheduleHtml = (Array.isArray(entry && entry.schedule_lines) ? entry.schedule_lines : ["-"]).map(function (line) {
                return '<span class="consolidated-schedule-line">' + escapeHtml(line) + "</span>";
              }).join("");

              return (
                "<tr>" +
                  (index === 0
                    ? '<td rowspan="' + blockRowspan + '" class="consolidated-cell-index">' + escapeHtml(String(record.sequence || "")) + "</td>" +
                      '<td rowspan="' + blockRowspan + '" class="consolidated-cell-name">' + escapeHtml(record.display_name || record.full_name || "") + "</td>" +
                      '<td rowspan="' + blockRowspan + '" class="consolidated-cell-prep">' + escapeHtml(String(record.total_preparations || 0)) + "</td>"
                    : "") +
                  '<td class="consolidated-cell-code">' + escapeHtml(entry && entry.sub_code || "") + "</td>" +
                  '<td class="consolidated-cell-title"><span class="consolidated-course-title">' + escapeHtml(entry && entry.desc || "") + "</span></td>" +
                  '<td class="consolidated-cell-schedule">' + scheduleHtml + "</td>" +
                  '<td class="consolidated-cell-course">' + escapeHtml(entry && entry.course || "") + "</td>" +
                  '<td class="consolidated-cell-students">' + escapeHtml(formatStudentCount(entry && entry.student_count || 0)) + "</td>" +
                  '<td class="consolidated-cell-units">' + escapeHtml(formatNumber(entry && entry.units || 0)) + "</td>" +
                  '<td class="consolidated-cell-hours">' + escapeHtml(formatNumber(entry && entry.lec || 0)) + "</td>" +
                  '<td class="consolidated-cell-hours">' + escapeHtml(formatNumber(entry && entry.lab || 0)) + "</td>" +
                  '<td class="consolidated-cell-load">' + escapeHtml(formatNumber(entry && entry.faculty_load || 0)) + "</td>" +
                  (index === 0
                    ? '<td rowspan="' + contentRowspan + '" class="consolidated-cell-designation">' + designationHtml + "</td>" +
                      '<td rowspan="' + contentRowspan + '" class="consolidated-cell-total">' + escapeHtml(formatNumber(record.total_workload || 0)) + "</td>" +
                      '<td rowspan="' + contentRowspan + '" class="consolidated-cell-remark"><span class="consolidated-remark-text">' + escapeHtml(record.remark_code || "NL") + "</span></td>"
                    : "") +
                "</tr>"
              );
            }).join("") +
            (showTotalRow
              ? '<tr class="consolidated-faculty-total-row">' +
                  '<td class="consolidated-cell-code">&nbsp;</td>' +
                  '<td class="consolidated-cell-title">&nbsp;</td>' +
                  '<td class="consolidated-cell-schedule">&nbsp;</td>' +
                  '<td class="consolidated-cell-course">&nbsp;</td>' +
                  '<td class="consolidated-cell-students">&nbsp;</td>' +
                  '<td class="consolidated-cell-units">&nbsp;</td>' +
                  '<td class="consolidated-cell-hours">&nbsp;</td>' +
                  '<td class="consolidated-cell-hours">&nbsp;</td>' +
                  '<td class="consolidated-cell-load">' + escapeHtml(formatNumber(record.teaching_load || 0)) + "</td>" +
                  '<td class="consolidated-cell-designation">' + (record.designation_units > 0 ? escapeHtml(formatNumber(record.designation_units)) : "&nbsp;") + "</td>" +
                  '<td class="consolidated-cell-total">&nbsp;</td>' +
                  '<td class="consolidated-cell-remark-total">' + (remarkTotalText ? escapeHtml(remarkTotalText) : "&nbsp;") + "</td>" +
                "</tr>"
              : "") +
          "</tbody>"
        );
      }

      function renderSignatoryBlock(label, signatory) {
        var name = uppercaseDisplayText(signatory && signatory.signatory_name || "");
        var title = String(signatory && signatory.signatory_title || "").trim();

        return (
          '<div class="consolidated-signatory-block">' +
            '<div class="consolidated-signatory-label">' + escapeHtml(label) + "</div>" +
            '<div class="consolidated-signatory-name">' + (name ? escapeHtml(name) : "&nbsp;") + "</div>" +
            '<div class="consolidated-signatory-title">' + (title ? escapeHtml(title) : "&nbsp;") + "</div>" +
          "</div>"
        );
      }

      function createReportElement(templateHtml) {
        var template = document.createElement("template");
        template.innerHTML = String(templateHtml || "").trim();
        return template.content.firstElementChild;
      }

      function createReportPage(termContext, options) {
        var opts = options || {};
        var page = document.createElement("section");
        var campusLabel = uppercaseDisplayText(reportScope && reportScope.campus_name || "Campus");
        var collegeLabel = uppercaseDisplayText(reportScope && reportScope.college_name || "All Colleges");
        var titleLabel = opts.signatoryPage ? "CONSOLIDATED FACULTY WORKLOAD SIGNATORIES" : "CONSOLIDATED FACULTY WORKLOAD";
        var bodyHtml;

        page.className = ("consolidated-report-page " + (opts.measure ? "is-measure " : "") + (opts.signatoryPage ? "consolidated-signatory-page" : "")).trim();
        page.style.setProperty("--consolidated-report-bg", 'url("' + reportBackgroundUrl + '")');

        bodyHtml = opts.signatoryPage
          ? '<div class="consolidated-signatory-wrap">' +
              '<div class="consolidated-signatory-top">' +
                renderSignatoryBlock("Prepared by:", reportSignatories && reportSignatories.prepared_by || {}) +
                renderSignatoryBlock("Checked by:", reportSignatories && reportSignatories.checked_by_left || {}) +
                renderSignatoryBlock("Checked by:", reportSignatories && reportSignatories.checked_by_right || {}) +
              "</div>" +
              '<div class="consolidated-signatory-bottom">' +
                renderSignatoryBlock("Recommending Approval:", reportSignatories && reportSignatories.recommending_approval || {}) +
                renderSignatoryBlock("Approved by:", reportSignatories && reportSignatories.approved_by || {}) +
              "</div>" +
            "</div>"
          : '<div class="consolidated-report-table-wrap">' +
              '<table class="consolidated-report-table">' +
                renderReportColGroup() +
                renderReportTableHead(termContext) +
              "</table>" +
            "</div>";

        page.innerHTML =
          '<div class="consolidated-report-page-background"></div>' +
          '<div class="consolidated-report-page-inner">' +
            '<div class="consolidated-report-page-header">' +
              '<div class="consolidated-report-title">' + escapeHtml(titleLabel) + "</div>" +
              '<div class="consolidated-report-subtitle">' + escapeHtml(campusLabel) + "</div>" +
              '<div class="consolidated-report-subtitle">' + escapeHtml(collegeLabel) + "</div>" +
              '<div class="consolidated-report-term">' + escapeHtml(getReportTermLabel(termContext)) + "</div>" +
              '<div class="consolidated-report-page-count">Page <span class="consolidated-page-number">1</span> of <span class="consolidated-page-total">1</span></div>' +
            "</div>" +
            bodyHtml +
          "</div>";

        return {
          page: page,
          inner: page.querySelector(".consolidated-report-page-inner"),
          table: page.querySelector(".consolidated-report-table")
        };
      }

      function updatePageMeta(pageElements) {
        var pages = Array.isArray(pageElements) ? pageElements : [];
        var total = pages.length;
        pages.forEach(function (page, index) {
          $(page).find(".consolidated-page-number").text(index + 1);
          $(page).find(".consolidated-page-total").text(total);
        });
      }

      function createMeasurePageBundle(measureRoot, generatedPages, termContext) {
        var pageBundle = createReportPage(termContext, { measure: true });
        measureRoot.appendChild(pageBundle.page);
        generatedPages.push(pageBundle.page);
        return pageBundle;
      }

      function pageOverflows(pageBundle) {
        if (!pageBundle || !pageBundle.inner) {
          return false;
        }
        return pageBundle.inner.scrollHeight > pageBundle.inner.clientHeight + 1;
      }

      function pageHasRows(pageBundle) {
        return Boolean(pageBundle && pageBundle.table && pageBundle.table.querySelector("tbody"));
      }

      function createFacultyChunkElement(record, entries, options) {
        return createReportElement(renderFacultyBlock(record, {
          entries: entries,
          continuation: Boolean(options && options.continuation),
          showTotalRow: !(options && options.showTotalRow === false)
        }));
      }

      function facultyChunkFits(pageBundle, record, entries, options) {
        var blockElement = createFacultyChunkElement(record, entries, options);
        var fits;
        if (!(blockElement instanceof HTMLElement) || !pageBundle || !pageBundle.table) {
          return false;
        }

        pageBundle.table.appendChild(blockElement);
        fits = !pageOverflows(pageBundle);
        pageBundle.table.removeChild(blockElement);
        return fits;
      }

      function getFittableEntryCount(pageBundle, record, entries, startIndex) {
        var safeEntries = Array.isArray(entries) ? entries : [];
        var remainingCount = safeEntries.length - startIndex;
        var bestCount = 0;
        var count;

        for (count = 1; count <= remainingCount; count += 1) {
          var isFinalChunk = (startIndex + count) >= safeEntries.length;
          var chunkEntries = safeEntries.slice(startIndex, startIndex + count);
          var fits = facultyChunkFits(pageBundle, record, chunkEntries, {
            continuation: startIndex > 0,
            showTotalRow: isFinalChunk
          });

          if (!fits) {
            break;
          }
          bestCount = count;
        }

        return bestCount;
      }

      function appendFacultyChunk(pageBundle, record, entries, options) {
        var blockElement = createFacultyChunkElement(record, entries, options);
        if (!(blockElement instanceof HTMLElement) || !pageBundle || !pageBundle.table) {
          return false;
        }
        pageBundle.table.appendChild(blockElement);
        return true;
      }

      function buildPreviewHtml(records, termContext) {
        var measureRoot = document.getElementById("consolidatedReportMeasureRoot");
        var generatedPages = [];
        var pageBundle;
        var signatoryBundle;
        var printablePages;

        if (!measureRoot) {
          return "";
        }

        measureRoot.innerHTML = "";
        pageBundle = createMeasurePageBundle(measureRoot, generatedPages, termContext);

        records.forEach(function (record) {
          var entries = Array.isArray(record && record.entries) && record.entries.length > 0 ? record.entries : [];
          var startIndex = 0;

          while (startIndex < entries.length) {
            var fitCount = getFittableEntryCount(pageBundle, record, entries, startIndex);
            var chunkEntries;
            var isFinalChunk;

            if (fitCount <= 0 && pageHasRows(pageBundle)) {
              pageBundle = createMeasurePageBundle(measureRoot, generatedPages, termContext);
              fitCount = getFittableEntryCount(pageBundle, record, entries, startIndex);
            }

            if (fitCount <= 0) {
              fitCount = 1;
            }

            chunkEntries = entries.slice(startIndex, startIndex + fitCount);
            isFinalChunk = (startIndex + fitCount) >= entries.length;

            if (!appendFacultyChunk(pageBundle, record, chunkEntries, {
              continuation: startIndex > 0,
              showTotalRow: isFinalChunk
            })) {
              break;
            }

            startIndex += fitCount;
          }
        });

        signatoryBundle = createReportPage(termContext, { measure: true, signatoryPage: true });
        measureRoot.appendChild(signatoryBundle.page);
        generatedPages.push(signatoryBundle.page);

        printablePages = generatedPages.map(function (page) {
          return page.cloneNode(true);
        });
        updatePageMeta(printablePages);
        measureRoot.innerHTML = "";

        return printablePages.map(function (page) {
          return page.outerHTML;
        }).join("");
      }

      async function openReportPreview() {
        var termContext = getTermContext();
        var assignees = (Array.isArray(reportAssignees) ? reportAssignees : []).slice();
        var records = [];
        var index;

        if (reportIsBuilding) {
          return;
        }

        if (!termContext.ayId || !termContext.semesterNum) {
          setReportEmptyState("Missing academic term", "Configure the current academic year and semester before generating the consolidated workload.");
          return;
        }

        if (!assignees.length) {
          setReportEmptyState("No faculty available", "No faculty or faculty need is available in your assigned campus.");
          return;
        }

        reportIsBuilding = true;
        showReportLoading("Loading faculty workload...", 5);
        $("#btnRefreshConsolidatedReport").prop("disabled", true);

        try {
          assignees.sort(function (left, right) {
            return String(left && left.full_name || "").localeCompare(String(right && right.full_name || ""));
          });

          for (index = 0; index < assignees.length; index += 1) {
            var option = assignees[index];
            var percent = Math.round(((index + 1) / assignees.length) * 86);
            var payload;
            var record;

            setReportProgress(percent, "Loading " + String(option && option.full_name || "assignee") + " (" + (index + 1) + " of " + assignees.length + ")...");

            try {
              payload = await fetchAssigneeWorkload(option);
              record = buildFacultyRecord(option, payload);
              if (record) {
                records.push(record);
              }
            } catch (facultyError) {
              console.error("Failed to load consolidated workload", option, facultyError);
            }
          }

          records.sort(function (left, right) {
            return String(left && left.full_name || "").localeCompare(String(right && right.full_name || ""));
          });
          records.forEach(function (record, recordIndex) {
            record.sequence = recordIndex + 1;
          });

          if (!records.length) {
            setReportEmptyState("No report rows available", "There is no assigned scheduler workload yet for your assigned campus and term.");
            return;
          }

          setReportProgress(95, "Building legal landscape preview...");
          reportRecords = records;
          reportGeneratedHtml = buildPreviewHtml(records, termContext);

          if (!reportGeneratedHtml) {
            reportRecords = [];
            setReportEmptyState("Preview unavailable", "The consolidated faculty workload preview could not be generated right now.");
            return;
          }

          $("#consolidatedReportPreviewRoot").html(reportGeneratedHtml);
          setReportProgress(100, "Preview ready");
          setReportControlsEnabled(true);
        } catch (reportError) {
          console.error("Failed to build consolidated workload preview", reportError);
          setReportEmptyState("Preview unavailable", "The consolidated faculty workload preview could not be generated right now.");
        } finally {
          reportIsBuilding = false;
          $("#btnRefreshConsolidatedReport").prop("disabled", false);
        }
      }

      function excelFilename() {
        var parts = [
          "campus-consolidated-faculty-workload",
          reportScope && reportScope.campus_name || "campus",
          currentAyLabel || "current-ay",
          semesterUiLabel(currentSem) || "semester"
        ];

        return parts.join("-")
          .replace(/[^A-Za-z0-9]+/g, "-")
          .replace(/^-+|-+$/g, "")
          .toLowerCase() + ".xls";
      }

      function renderExcelSignatoryRows() {
        var rows = [
          ["Prepared by:", reportSignatories.prepared_by || {}],
          ["Checked by:", reportSignatories.checked_by_left || {}],
          ["Checked by:", reportSignatories.checked_by_right || {}],
          ["Recommending Approval:", reportSignatories.recommending_approval || {}],
          ["Approved by:", reportSignatories.approved_by || {}]
        ];

        return (
          '<table class="consolidated-excel-signatories">' +
            '<tr><th colspan="3">Signatories</th></tr>' +
            rows.map(function (row) {
              var label = row[0];
              var signatory = row[1] || {};
              return (
                "<tr>" +
                  '<td class="signatory-label">' + escapeHtml(label) + "</td>" +
                  '<td class="signatory-name">' + escapeHtml(uppercaseDisplayText(signatory.signatory_name || "")) + "</td>" +
                  '<td>' + escapeHtml(signatory.signatory_title || "") + "</td>" +
                "</tr>"
              );
            }).join("") +
          "</table>"
        );
      }

      function buildExcelWorkbookHtml(records, termContext) {
        var reportRows = (Array.isArray(records) ? records : []).map(function (record) {
          return renderFacultyBlock(record, {
            entries: Array.isArray(record && record.entries) ? record.entries : [],
            showTotalRow: true
          });
        }).join("");

        return (
          '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">' +
          "<head>" +
            '<meta charset="utf-8">' +
            "<style>" +
              "table{border-collapse:collapse;font-family:Arial,sans-serif;font-size:10pt;}" +
              ".consolidated-report-table th,.consolidated-report-table td{border:1px solid #000;padding:4px;vertical-align:top;mso-number-format:\\@;}" +
              ".consolidated-report-table th{text-align:center;font-weight:bold;background:#f2f2f2;}" +
              ".consolidated-cell-index,.consolidated-cell-name,.consolidated-cell-prep,.consolidated-cell-students,.consolidated-cell-units,.consolidated-cell-hours,.consolidated-cell-load,.consolidated-cell-designation,.consolidated-cell-total,.consolidated-cell-remark,.consolidated-cell-remark-total{text-align:center;}" +
              ".consolidated-cell-name,.consolidated-cell-code,.consolidated-cell-load,.consolidated-cell-total,.consolidated-cell-remark,.consolidated-course-title,.consolidated-designation-title{font-weight:bold;}" +
              ".consolidated-report-title-row th{font-size:13pt;text-align:center;border:0;background:#fff;}" +
              ".consolidated-report-subtitle-row th{font-size:11pt;text-align:center;border:0;background:#fff;}" +
              ".consolidated-faculty-total-row td{font-weight:bold;background:#f8f8f8;}" +
              ".consolidated-schedule-line{display:block;}" +
              ".consolidated-excel-signatories{margin-top:18px;}" +
              ".consolidated-excel-signatories th,.consolidated-excel-signatories td{border:1px solid #000;padding:6px;}" +
              ".signatory-label{font-weight:bold;}" +
              ".signatory-name{font-weight:bold;text-transform:uppercase;}" +
            "</style>" +
          "</head>" +
          "<body>" +
            '<table class="consolidated-report-table">' +
              '<tr class="consolidated-report-title-row"><th colspan="15">CONSOLIDATED FACULTY WORKLOAD</th></tr>' +
              '<tr class="consolidated-report-subtitle-row"><th colspan="15">' + escapeHtml(uppercaseDisplayText(reportScope && reportScope.campus_name || "Campus")) + "</th></tr>" +
              '<tr class="consolidated-report-subtitle-row"><th colspan="15">' + escapeHtml(uppercaseDisplayText(reportScope && reportScope.college_name || "All Colleges")) + "</th></tr>" +
              '<tr class="consolidated-report-subtitle-row"><th colspan="15">' + escapeHtml(getReportTermLabel(termContext)) + "</th></tr>" +
              renderReportTableHead(termContext) +
              reportRows +
            "</table>" +
            renderExcelSignatoryRows() +
          "</body>" +
          "</html>"
        );
      }

      function exportExcel() {
        var records = Array.isArray(reportRecords) ? reportRecords : [];
        var termContext = getTermContext();
        var workbookHtml;
        var blob;
        var url;
        var link;

        if (!records.length) {
          return;
        }

        workbookHtml = buildExcelWorkbookHtml(records, termContext);
        blob = new Blob(["\ufeff", workbookHtml], {
          type: "application/vnd.ms-excel;charset=utf-8;"
        });
        url = URL.createObjectURL(blob);
        link = document.createElement("a");
        link.href = url;
        link.download = excelFilename();
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(function () {
          URL.revokeObjectURL(url);
        }, 1000);
      }

      function printReportPreview() {
        var reportStyleTag;
        var frameId = "consolidatedReportPrintFrame";
        var existingFrame = document.getElementById(frameId);
        var printFrame;
        var printDocument;
        var printTriggered = false;

        if (!reportGeneratedHtml) {
          return;
        }

        reportStyleTag = document.getElementById("consolidatedReportStyleTag");
        if (existingFrame) {
          existingFrame.remove();
        }

        printFrame = document.createElement("iframe");
        printFrame.id = frameId;
        printFrame.style.position = "fixed";
        printFrame.style.right = "0";
        printFrame.style.bottom = "0";
        printFrame.style.width = "0";
        printFrame.style.height = "0";
        printFrame.style.border = "0";
        printFrame.setAttribute("aria-hidden", "true");
        document.body.appendChild(printFrame);

        printDocument = printFrame.contentWindow && printFrame.contentWindow.document;
        if (!printDocument || !printFrame.contentWindow) {
          printFrame.remove();
          return;
        }

        printDocument.open();
        printDocument.write(
          '<!DOCTYPE html>' +
          '<html lang="en">' +
          "<head>" +
            '<meta charset="utf-8">' +
            '<meta name="viewport" content="width=device-width, initial-scale=1.0">' +
            "<title>Campus Consolidated Faculty Workload</title>" +
            "<style>@media print {@page { size: legal landscape; margin: 0; }}</style>" +
            (reportStyleTag ? reportStyleTag.outerHTML : "") +
          "</head>" +
          '<body class="consolidated-report-print-body">' +
            '<div class="consolidated-report-preview-root">' + reportGeneratedHtml + "</div>" +
          "</body>" +
          "</html>"
        );
        printDocument.close();

        function triggerPrint() {
          if (printTriggered) {
            return;
          }

          printTriggered = true;
          setTimeout(function () {
            try {
              printFrame.contentWindow.focus();
              printFrame.contentWindow.print();
            } finally {
              setTimeout(function () {
                printFrame.remove();
              }, 1500);
            }
          }, 350);
        }

        printFrame.addEventListener("load", triggerPrint, { once: true });
        setTimeout(triggerPrint, 700);
      }

      $("#btnRefreshConsolidatedReport").on("click", function () {
        openReportPreview();
      });

      $("#btnExportConsolidatedExcel").on("click", function () {
        exportExcel();
      });

      $("#btnPrintConsolidatedReport").on("click", function () {
        printReportPreview();
      });

      openReportPreview();
    });
  </script>
</body>
</html>
