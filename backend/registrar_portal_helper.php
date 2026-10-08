<?php

require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/enrollment_draft_helper.php';

function synk_registrar_require_login(mysqli $conn): void
{
    if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'registrar') {
        $redirectPath = synk_role_redirect_path((string)($_SESSION['role'] ?? ''));
        header('Location: ../' . ($redirectPath ?? 'index.php'));
        exit;
    }
}

function synk_registrar_fetch_campus_context(mysqli $conn, int $campusId): ?array
{
    if ($campusId <= 0 || !synk_table_exists($conn, 'tbl_campus')) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            campus_id,
            COALESCE(campus_code, '') AS campus_code,
            COALESCE(campus_name, '') AS campus_name
        FROM tbl_campus
        WHERE campus_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $campusId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;

    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $row['campus_id'] = (int)($row['campus_id'] ?? 0);
    return $row;
}

function synk_registrar_build_role_badges(): array
{
    $availableRoles = array_values(array_filter(array_map('strval', (array)($_SESSION['available_roles'] ?? []))));
    $badges = [];

    foreach ($availableRoles as $role) {
        $label = trim((string)synk_role_label($role));
        if ($label === '') {
            continue;
        }

        $badges[] = [
            'role' => strtolower(trim($role)),
            'label' => $label,
            'is_active' => strtolower(trim($role)) === 'registrar',
        ];
    }

    return $badges;
}

function synk_registrar_portal_context(mysqli $conn): array
{
    $campusId = (int)($_SESSION['campus_id'] ?? 0);
    $campusRow = synk_registrar_fetch_campus_context($conn, $campusId);
    $currentTerm = synk_fetch_current_academic_term($conn);
    $accountName = trim((string)($_SESSION['username'] ?? 'Registrar'));
    $email = trim((string)($_SESSION['email'] ?? ''));

    return [
        'account_name' => $accountName !== '' ? $accountName : 'Registrar',
        'email' => $email,
        'role_badges' => synk_registrar_build_role_badges(),
        'campus_id' => $campusId,
        'campus' => $campusRow,
        'current_term' => $currentTerm,
        'has_campus_scope' => $campusRow !== null,
    ];
}

function synk_registrar_status_badge_class(string $status): string
{
    $safeStatus = strtolower(trim($status));
    if (in_array($safeStatus, ['approved', 'posted'], true)) {
        return 'bg-label-success';
    }
    if ($safeStatus === 'returned') {
        return 'bg-label-danger';
    }
    if ($safeStatus === 'submitted') {
        return 'bg-label-warning';
    }

    return 'bg-label-primary';
}

function synk_registrar_student_label(array $row): string
{
    $nameTail = trim(implode(' ', array_filter([
        (string)($row['first_name'] ?? ''),
        (string)($row['middle_name'] ?? ''),
        (string)($row['suffix_name'] ?? ''),
    ])));
    $name = trim(implode(', ', array_filter([
        (string)($row['last_name'] ?? ''),
        $nameTail,
    ])));
    $label = trim(implode(' ', array_filter([
        (string)($row['student_number'] ?? ''),
        $name,
    ])));

    return $label !== '' ? $label : 'Student profile pending';
}

function synk_registrar_fetch_term_transaction_rows(mysqli $conn, int $campusId, int $ayId, int $semester): array
{
    if ($campusId <= 0 || $ayId <= 0 || $semester <= 0 || !synk_enrollment_draft_tables_ready($conn)) {
        return [];
    }

    $stmt = $conn->prepare(synk_enrollment_header_select_sql("
        WHERE h.campus_id = ?
          AND h.ay_id = ?
          AND h.semester = ?
    ") . "
        ORDER BY
            CASE h.workflow_status
                WHEN 'submitted' THEN 0
                WHEN 'returned' THEN 1
                WHEN 'draft' THEN 2
                WHEN 'approved' THEN 3
                WHEN 'posted' THEN 4
                ELSE 5
            END ASC,
            COALESCE(h.submitted_at, h.updated_at, h.created_at) DESC,
            h.enrollment_id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iii', $campusId, $ayId, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['enrollment_id'] = (int)($row['enrollment_id'] ?? 0);
            $row['campus_id'] = (int)($row['campus_id'] ?? 0);
            $row['college_id'] = (int)($row['college_id'] ?? 0);
            $row['program_id'] = (int)($row['program_id'] ?? 0);
            $row['section_id'] = (int)($row['section_id'] ?? 0);
            $row['subject_count'] = (int)($row['subject_count'] ?? 0);
            $row['total_units'] = round((float)($row['total_units'] ?? 0), 2);
            $row['status_label'] = synk_enrollment_status_label((string)($row['workflow_status'] ?? 'draft'));
            $row['student_label'] = synk_registrar_student_label($row);
            $rows[] = $row;
        }

        $result->close();
    }

    $stmt->close();
    return $rows;
}

function synk_registrar_dashboard_snapshot(array $rows): array
{
    $snapshot = [
        'total_records' => count($rows),
        'ongoing_enrollees' => 0,
        'chair_drafts' => 0,
        'registrar_queue' => 0,
        'approved_posted' => 0,
        'total_subjects' => 0,
        'total_units' => 0,
        'college_count' => 0,
        'program_count' => 0,
    ];

    $collegeMap = [];
    $programMap = [];

    foreach ($rows as $row) {
        $status = strtolower(trim((string)($row['workflow_status'] ?? 'draft')));
        if ($status !== 'cancelled') {
            $snapshot['ongoing_enrollees']++;
        }
        if (in_array($status, ['draft', 'returned'], true)) {
            $snapshot['chair_drafts']++;
        }
        if ($status === 'submitted') {
            $snapshot['registrar_queue']++;
        }
        if (in_array($status, ['approved', 'posted'], true)) {
            $snapshot['approved_posted']++;
        }

        $snapshot['total_subjects'] += (int)($row['subject_count'] ?? 0);
        $snapshot['total_units'] += round((float)($row['total_units'] ?? 0), 2);

        $collegeId = (int)($row['college_id'] ?? 0);
        if ($collegeId > 0) {
            $collegeMap[$collegeId] = true;
        }

        $programId = (int)($row['program_id'] ?? 0);
        if ($programId > 0) {
            $programMap[$programId] = true;
        }
    }

    $snapshot['college_count'] = count($collegeMap);
    $snapshot['program_count'] = count($programMap);

    return $snapshot;
}

function synk_registrar_college_summary_rows(array $rows): array
{
    $summary = [];

    foreach ($rows as $row) {
        $collegeId = (int)($row['college_id'] ?? 0);
        if ($collegeId <= 0) {
            continue;
        }

        if (!isset($summary[$collegeId])) {
            $summary[$collegeId] = [
                'college_id' => $collegeId,
                'college_code' => (string)($row['college_code'] ?? ''),
                'college_name' => (string)($row['college_name'] ?? ''),
                'record_count' => 0,
                'submitted_count' => 0,
                'returned_count' => 0,
                'draft_count' => 0,
                'approved_count' => 0,
                'subject_count' => 0,
                'total_units' => 0,
                'program_ids' => [],
            ];
        }

        $status = strtolower(trim((string)($row['workflow_status'] ?? 'draft')));
        $summary[$collegeId]['record_count']++;
        $summary[$collegeId]['subject_count'] += (int)($row['subject_count'] ?? 0);
        $summary[$collegeId]['total_units'] += round((float)($row['total_units'] ?? 0), 2);
        $summary[$collegeId]['program_ids'][(int)($row['program_id'] ?? 0)] = true;

        if ($status === 'submitted') {
            $summary[$collegeId]['submitted_count']++;
        } elseif ($status === 'returned') {
            $summary[$collegeId]['returned_count']++;
        } elseif ($status === 'draft') {
            $summary[$collegeId]['draft_count']++;
        } elseif (in_array($status, ['approved', 'posted'], true)) {
            $summary[$collegeId]['approved_count']++;
        }
    }

    foreach ($summary as &$item) {
        $item['program_count'] = count(array_filter(array_keys($item['program_ids'])));
        unset($item['program_ids']);
    }
    unset($item);

    usort($summary, static function (array $a, array $b): int {
        return [$b['record_count'], $a['college_code']] <=> [$a['record_count'], $b['college_code']];
    });

    return $summary;
}

function synk_registrar_program_summary_rows(array $rows): array
{
    $summary = [];

    foreach ($rows as $row) {
        $programId = (int)($row['program_id'] ?? 0);
        if ($programId <= 0) {
            continue;
        }

        if (!isset($summary[$programId])) {
            $displayName = trim(implode(' - ', array_filter([
                (string)($row['program_code'] ?? ''),
                (string)($row['program_name'] ?? ''),
            ])));
            $major = trim((string)($row['major'] ?? ''));
            if ($major !== '' && stripos($displayName, $major) === false) {
                $displayName .= ' (Major in ' . $major . ')';
            }

            $summary[$programId] = [
                'program_id' => $programId,
                'program_code' => (string)($row['program_code'] ?? ''),
                'program_name' => (string)($row['program_name'] ?? ''),
                'major' => $major,
                'display_name' => $displayName !== '' ? $displayName : 'Program #' . $programId,
                'college_code' => (string)($row['college_code'] ?? ''),
                'college_name' => (string)($row['college_name'] ?? ''),
                'record_count' => 0,
                'submitted_count' => 0,
                'returned_count' => 0,
                'draft_count' => 0,
                'approved_count' => 0,
                'subject_count' => 0,
                'total_units' => 0,
            ];
        }

        $status = strtolower(trim((string)($row['workflow_status'] ?? 'draft')));
        $summary[$programId]['record_count']++;
        $summary[$programId]['subject_count'] += (int)($row['subject_count'] ?? 0);
        $summary[$programId]['total_units'] += round((float)($row['total_units'] ?? 0), 2);

        if ($status === 'submitted') {
            $summary[$programId]['submitted_count']++;
        } elseif ($status === 'returned') {
            $summary[$programId]['returned_count']++;
        } elseif ($status === 'draft') {
            $summary[$programId]['draft_count']++;
        } elseif (in_array($status, ['approved', 'posted'], true)) {
            $summary[$programId]['approved_count']++;
        }
    }

    $summary = array_values($summary);
    usort($summary, static function (array $a, array $b): int {
        return [$b['record_count'], $a['display_name']] <=> [$a['record_count'], $b['display_name']];
    });

    return $summary;
}

function synk_registrar_status_chart_data(array $rows): array
{
    $counts = [
        'Draft' => 0,
        'Submitted' => 0,
        'Returned' => 0,
        'Approved / Posted' => 0,
    ];

    foreach ($rows as $row) {
        $status = strtolower(trim((string)($row['workflow_status'] ?? 'draft')));
        if ($status === 'submitted') {
            $counts['Submitted']++;
        } elseif ($status === 'returned') {
            $counts['Returned']++;
        } elseif (in_array($status, ['approved', 'posted'], true)) {
            $counts['Approved / Posted']++;
        } else {
            $counts['Draft']++;
        }
    }

    return [
        'labels' => array_keys($counts),
        'series' => array_values($counts),
    ];
}

function synk_registrar_recent_activity_chart_data(array $rows, int $days = 7): array
{
    $days = max(3, $days);
    $dayKeys = [];
    $counts = [];

    for ($offset = $days - 1; $offset >= 0; $offset--) {
        $key = date('Y-m-d', strtotime("-{$offset} day"));
        $dayKeys[] = $key;
        $counts[$key] = 0;
    }

    foreach ($rows as $row) {
        $dateValue = (string)($row['submitted_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? '');
        $key = $dateValue !== '' ? date('Y-m-d', strtotime($dateValue)) : '';
        if ($key !== '' && isset($counts[$key])) {
            $counts[$key]++;
        }
    }

    return [
        'categories' => array_map(static function (string $key): string {
            return date('M d', strtotime($key));
        }, $dayKeys),
        'series' => array_values($counts),
    ];
}
