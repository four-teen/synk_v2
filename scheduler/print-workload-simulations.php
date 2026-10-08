<?php
session_start();
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/workload_simulation_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$collegeName = trim((string)($_SESSION['college_name'] ?? ''));
$currentTerm = synk_fetch_current_academic_term($conn);
$ayId = (int)($_GET['ay_id'] ?? ($currentTerm['ay_id'] ?? 0));
$semester = (int)($_GET['semester'] ?? ($currentTerm['semester'] ?? 0));

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function semester_print_label(int $semester): string
{
    if ($semester === 1) {
        return 'FIRST SEMESTER';
    }

    if ($semester === 2) {
        return 'SECOND SEMESTER';
    }

    if ($semester === 3) {
        return 'MIDYEAR';
    }

    return 'SEMESTER';
}

function format_load_number($value): string
{
    $number = (float)$value;
    return abs($number - round($number)) < 0.0001
        ? (string)(int)round($number)
        : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

function sum_simulation_group_metrics(array $rows): array
{
    $totals = [
        'units' => 0.0,
        'lab' => 0.0,
        'lec' => 0.0,
        'faculty_load' => 0.0
    ];

    foreach ($rows as $row) {
        $totals['units'] += (float)($row['units'] ?? 0);
        $totals['lab'] += (float)($row['lab'] ?? 0);
        $totals['lec'] += (float)($row['lec'] ?? 0);
        $totals['faculty_load'] += (float)($row['faculty_load'] ?? 0);
    }

    foreach ($totals as $key => $value) {
        $totals[$key] = round((float)$value, 2);
    }

    return $totals;
}

function render_partner_bits(array $row, bool $showType): string
{
    if ($showType) {
        $type = strtoupper(trim((string)($row['schedule_type'] ?? 'LEC'))) === 'LAB' ? 'LAB' : 'LEC';
        return '<div class="desc-type-text">' . h($type) . '</div>';
    }

    return '';
}

$ayLabel = '';
if ($ayId > 0) {
    $ayStmt = $conn->prepare("SELECT ay FROM tbl_academic_years WHERE ay_id = ? LIMIT 1");
    if ($ayStmt) {
        $ayStmt->bind_param('i', $ayId);
        $ayStmt->execute();
        $ayRes = $ayStmt->get_result();
        $ayRow = $ayRes ? $ayRes->fetch_assoc() : null;
        $ayLabel = trim((string)($ayRow['ay'] ?? ''));
        $ayStmt->close();
    }
}

$facultyRows = [];
if ($collegeId > 0 && $ayId > 0 && $semester > 0 && synk_table_exists($conn, 'tbl_faculty_workload_simulation')) {
    $facultyStmt = $conn->prepare("
        SELECT DISTINCT
            sim.faculty_id,
            CONCAT(f.last_name, ', ', f.first_name, ' ', COALESCE(f.ext_name, '')) AS faculty_name
        FROM tbl_faculty_workload_simulation sim
        INNER JOIN tbl_faculty f
            ON f.faculty_id = sim.faculty_id
        WHERE sim.college_id = ?
          AND sim.ay_id = ?
          AND sim.semester = ?
        ORDER BY f.last_name, f.first_name
    ");

    if ($facultyStmt) {
        $facultyStmt->bind_param('iii', $collegeId, $ayId, $semester);
        $facultyStmt->execute();
        $facultyRes = $facultyStmt->get_result();
        while ($facultyRes && ($facultyRow = $facultyRes->fetch_assoc())) {
            $facultyRows[] = [
                'faculty_id' => (int)($facultyRow['faculty_id'] ?? 0),
                'faculty_name' => trim((string)($facultyRow['faculty_name'] ?? ''))
            ];
        }
        $facultyStmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Print Workload Simulations</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f4f6fa; color: #1f2937; }
        .page-shell { max-width: 230mm; margin: 0 auto; padding: 18px; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .toolbar .btn { padding: 10px 16px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; cursor: pointer; }
        .report-sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 16px;
            box-sizing: border-box;
            background: #fff;
            border: 1px solid #dbe5f1;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            padding: 12mm 12mm 14mm;
        }
        .report-header { text-align: center; margin-bottom: 18px; }
        .report-title { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .report-subtitle { font-size: 13px; color: #64748b; }
        .faculty-block { margin-top: 18px; page-break-inside: avoid; }
        .faculty-name { font-size: 18px; font-weight: 700; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #d7e1ec; padding: 6px 8px; font-size: 12px; vertical-align: middle; }
        th { background: #f8fafc; text-transform: uppercase; letter-spacing: 0.04em; font-size: 11px; color: #475569; }
        tfoot td, tfoot th { background: #f8fafc; font-weight: 700; }
        .code { font-weight: 700; white-space: nowrap; }
        .desc-type-text { margin-top: 4px; font-size: 10px; font-weight: 700; letter-spacing: 0.04em; color: #64748b; }
        .muted { color: #64748b; }
        .load-status { margin-left: 6px; font-size: 10px; text-transform: uppercase; }
        .faculty-sheet { break-after: page; page-break-after: always; }
        .faculty-sheet:last-of-type { break-after: auto; page-break-after: auto; }
        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            body { background: #fff; }
            .page-shell { max-width: none; padding: 0; }
            .toolbar { display: none; }
            .report-sheet {
                width: auto;
                min-height: auto;
                margin: 0;
                box-shadow: none;
                border: none;
                padding: 0;
            }
        }
    </style>
</head>
<body>
<div class="page-shell">
    <div class="toolbar">
        <div>
            <strong>Simulation Print Preview</strong>
        </div>
        <button type="button" class="btn" onclick="window.print()">Print</button>
    </div>

    <?php if (empty($facultyRows)): ?>
        <div class="report-sheet">
            <div class="report-header">
                <div class="report-title">Faculty Workload Simulations</div>
                <div class="report-subtitle">
                    <?= h($collegeName) ?> | <?= h(semester_print_label($semester)) ?><?= $ayLabel !== '' ? ' | AY ' . h($ayLabel) : '' ?>
                </div>
            </div>
            <p class="muted">No saved simulation workload was found for this term.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($facultyRows as $facultyRow): ?>
        <div class="report-sheet faculty-sheet">
            <div class="report-header">
                <div class="report-title">Faculty Workload Simulations</div>
                <div class="report-subtitle">
                    <?= h($collegeName) ?> | <?= h(semester_print_label($semester)) ?><?= $ayLabel !== '' ? ' | AY ' . h($ayLabel) : '' ?>
                </div>
            </div>
            <?php
            $rows = synk_fetch_saved_workload_simulation_rows($conn, $collegeId, (int)$facultyRow['faculty_id'], $ayId, $semester);
            $meta = synk_fetch_workload_simulation_designation_meta($conn, $collegeId, (int)$facultyRow['faculty_id'], $ayId, $semester);
            $grouped = [];
            foreach ($rows as $row) {
                $groupKey = (string)($row['context_key'] ?? $row['sim_key'] ?? '');
                if (!isset($grouped[$groupKey])) {
                    $grouped[$groupKey] = [];
                }
                $grouped[$groupKey][] = $row;
            }

            $totalUnit = 0.0;
            $totalLab = 0.0;
            $totalLec = 0.0;
            $totalLoad = 0.0;
            $prepKeys = [];
            ?>
            <div class="faculty-block">
                <div class="faculty-name"><?= h($facultyRow['faculty_name']) ?></div>
                <table>
                    <thead>
                        <tr>
                            <th rowspan="2">Course No.</th>
                            <th rowspan="2">Course Description</th>
                            <th rowspan="2">Course</th>
                            <th rowspan="2">Day</th>
                            <th rowspan="2">Time</th>
                            <th rowspan="2">Room</th>
                            <th rowspan="2">Unit</th>
                            <th colspan="2">No. of Hours</th>
                            <th rowspan="2">Load</th>
                            <th rowspan="2">Students</th>
                        </tr>
                        <tr>
                            <th>Lab Hrs</th>
                            <th>Lec Hrs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($grouped)): ?>
                            <tr>
                                <td colspan="11" class="muted">No simulated rows saved for this faculty.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($grouped as $groupRows): ?>
                            <?php
                            $first = $groupRows[0];
                            $metrics = sum_simulation_group_metrics($groupRows);
                            $totalUnit += (float)$metrics['units'];
                            $totalLab += (float)$metrics['lab'];
                            $totalLec += (float)$metrics['lec'];
                            $totalLoad += (float)$metrics['faculty_load'];
                            $prepCode = trim((string)($first['subject_code'] ?? ''));
                            if ($prepCode !== '') {
                                $prepKeys[$prepCode] = true;
                            }
                            ?>
                            <?php foreach ($groupRows as $index => $row): ?>
                                <tr>
                                    <td class="code"><?= h($row['subject_code'] ?? '') ?></td>
                                    <td>
                                        <?= h($row['subject_description'] ?? '') ?>
                                        <?= render_partner_bits($row, count($groupRows) > 1 || trim((string)($row['partner_label'] ?? '')) !== '') ?>
                                    </td>
                                    <td><?= h($row['course'] ?? $row['section_name'] ?? '') ?></td>
                                    <td><?= h($row['days'] ?? '') ?></td>
                                    <td><?= h($row['time'] ?? '') ?></td>
                                    <td><?= h($row['room_code'] ?? '') ?></td>
                                    <?php if ($index === 0): ?>
                                        <td rowspan="<?= count($groupRows) ?>"><?= h(format_load_number($metrics['units'])) ?></td>
                                        <td rowspan="<?= count($groupRows) ?>"><?= h(format_load_number($metrics['lab'])) ?></td>
                                        <td rowspan="<?= count($groupRows) ?>"><?= h(format_load_number($metrics['lec'])) ?></td>
                                        <td rowspan="<?= count($groupRows) ?>"><?= h(format_load_number($metrics['faculty_load'])) ?></td>
                                    <?php endif; ?>
                                    <td><?= h(format_load_number($row['student_count'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="2" style="text-align:left;">Designation:</th>
                            <td colspan="4"><?= h($meta['designation_label'] ?? '') ?></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td><?= h(format_load_number($meta['designation_units'] ?? 0)) ?></td>
                            <td></td>
                        </tr>
                        <tr>
                            <th colspan="2" style="text-align:left;">No. of Prep:</th>
                            <td colspan="4"><?= h((string)count($prepKeys)) ?></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <th colspan="6" style="text-align:right;">Total Load</th>
                            <td><?= h(format_load_number($totalUnit)) ?></td>
                            <td><?= h(format_load_number($totalLab)) ?></td>
                            <td><?= h(format_load_number($totalLec)) ?></td>
                            <?php $grandTotal = $totalLoad + (float)($meta['designation_units'] ?? 0); ?>
                            <td>
                                <?= h(format_load_number($grandTotal)) ?>
                                <?php if ($grandTotal > 21): ?>
                                    <span class="load-status">Overload</span>
                                <?php elseif ($grandTotal < 18): ?>
                                    <span class="load-status">Underload</span>
                                <?php endif; ?>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
