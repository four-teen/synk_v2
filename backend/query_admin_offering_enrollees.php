<?php
session_start();
header('Content-Type: application/json');

include 'db.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/offering_enrollee_helper.php';

function synk_admin_enrollee_respond(string $status, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message,
    ], $extra));
    exit;
}

function synk_admin_enrollee_title_case(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', strtolower($value));
    return (string)preg_replace_callback('/(^|[\s\/-])([a-z])/', static function ($matches) {
        return $matches[1] . strtoupper($matches[2]);
    }, $value);
}

function synk_admin_enrollee_program_label(array $row): string
{
    $code = strtoupper(trim((string)($row['program_code'] ?? '')));
    $name = synk_admin_enrollee_title_case((string)($row['program_name'] ?? ''));
    $major = synk_admin_enrollee_title_case((string)($row['major'] ?? ''));

    $label = $code;
    if ($name !== '') {
        $label .= ($label !== '' ? ' - ' : '') . $name;
    }

    if ($major !== '') {
        $label .= ' (Major in ' . $major . ')';
    }

    return trim($label);
}

function synk_admin_enrollee_section_key(array $row): string
{
    $sectionId = (int)($row['section_id'] ?? 0);
    if ($sectionId > 0) {
        return 'section:' . $sectionId;
    }

    $programId = (int)($row['program_id'] ?? 0);
    $yearLevel = (int)($row['year_level'] ?? 0);
    $course = trim((string)($row['course'] ?? $row['full_section'] ?? $row['section_name'] ?? ''));
    if ($course === '') {
        $course = 'section';
    }

    return 'fallback:' . $programId . ':' . $yearLevel . ':' . strtolower($course);
}

function synk_admin_enrollee_resolve_section_count(array $counts, int $fallbackCount = 0): int
{
    $frequencyMap = [];

    foreach ($counts as $count) {
        $value = max(0, (int)$count);
        if ($value <= 0) {
            continue;
        }

        $frequencyMap[$value] = (int)($frequencyMap[$value] ?? 0) + 1;
    }

    if (empty($frequencyMap)) {
        return max(0, $fallbackCount);
    }

    $bestCount = max(0, $fallbackCount);
    $bestFrequency = -1;

    foreach ($frequencyMap as $value => $frequency) {
        $value = (int)$value;
        $frequency = (int)$frequency;

        if ($frequency > $bestFrequency || ($frequency === $bestFrequency && $value > $bestCount)) {
            $bestCount = $value;
            $bestFrequency = $frequency;
        }
    }

    return $bestCount;
}

function synk_admin_enrollee_scope_from_post(): array
{
    return [
        'college_id' => (int)($_POST['college_id'] ?? 0),
        'program_id' => (int)($_POST['program_id'] ?? 0),
        'ay_id' => (int)($_POST['ay_id'] ?? 0),
        'semester' => (int)($_POST['semester'] ?? 0),
    ];
}

function synk_admin_enrollee_validate_scope(mysqli $conn, array $scope): array
{
    $errors = [];
    $college = null;
    $program = null;
    $academicYear = null;

    if ((int)$scope['college_id'] <= 0) {
        $errors[] = 'Select a college.';
    } else {
        $stmt = $conn->prepare("
            SELECT college_id, college_code, college_name
            FROM tbl_college
            WHERE college_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $scope['college_id']);
        $stmt->execute();
        $college = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (!$college) {
            $errors[] = 'Selected college is invalid.';
        }
    }

    if ((int)$scope['ay_id'] <= 0) {
        $errors[] = 'Select an academic year.';
    } else {
        $stmt = $conn->prepare("
            SELECT ay_id, ay
            FROM tbl_academic_years
            WHERE ay_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $scope['ay_id']);
        $stmt->execute();
        $academicYear = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (!$academicYear) {
            $errors[] = 'Selected academic year is invalid.';
        }
    }

    if (!in_array((int)$scope['semester'], [1, 2, 3], true)) {
        $errors[] = 'Select a valid semester.';
    }

    if ((int)$scope['program_id'] > 0) {
        $stmt = $conn->prepare("
            SELECT program_id, college_id, program_code, program_name, COALESCE(major, '') AS major
            FROM tbl_program
            WHERE program_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $scope['program_id']);
        $stmt->execute();
        $program = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        if (!$program) {
            $errors[] = 'Selected program is invalid.';
        } elseif ($college && (int)$program['college_id'] !== (int)$college['college_id']) {
            $errors[] = 'Selected program does not belong to the selected college.';
        }
    }

    return [
        'errors' => $errors,
        'college' => $college,
        'program' => $program,
        'academic_year' => $academicYear,
    ];
}

function synk_admin_enrollee_fetch_scope_offerings(mysqli $conn, array $scope): array
{
    $empty = [
        'rows' => [],
        'summary' => [
            'total_offerings' => 0,
            'total_sections' => 0,
            'programs_in_scope' => 0,
            'total_dummy_enrollees' => 0,
            'default_section_capacity' => synk_default_section_enrollee_count(),
        ],
    ];

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $types = 'iii';
    $params = [
        (int)$scope['ay_id'],
        (int)$scope['semester'],
        (int)$scope['college_id'],
    ];

    $where = [
        'o.ay_id = ?',
        'o.semester = ?',
        'p.college_id = ?',
    ];

    if ((int)$scope['program_id'] > 0) {
        $where[] = 'p.program_id = ?';
        $types .= 'i';
        $params[] = (int)$scope['program_id'];
    }

    $sql = "
        SELECT
            o.offering_id,
            o.program_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            pys.year_level,
            sec.section_id,
            sec.section_name,
            sec.full_section,
            sm.sub_code,
            sm.sub_description
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        WHERE " . implode("\n          AND ", $where) . "
        ORDER BY
            p.program_code ASC,
            p.program_name ASC,
            p.major ASC,
            sec.full_section ASC,
            sec.section_name ASC,
            sm.sub_code ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $empty;
    }

    synk_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
        $rows[] = [
            'offering_id' => (int)($row['offering_id'] ?? 0),
            'program_id' => (int)($row['program_id'] ?? 0),
            'program_code' => (string)($row['program_code'] ?? ''),
            'program_name' => (string)($row['program_name'] ?? ''),
            'major' => (string)($row['major'] ?? ''),
            'program_label' => synk_admin_enrollee_program_label($row),
            'year_level' => (int)($row['year_level'] ?? 0),
            'section_id' => (int)($row['section_id'] ?? 0),
            'section_name' => (string)($row['section_name'] ?? ''),
            'course' => trim((string)($row['full_section'] ?? '')) !== ''
                ? (string)$row['full_section']
                : (string)($row['section_name'] ?? ''),
            'sub_code' => (string)($row['sub_code'] ?? ''),
            'sub_description' => (string)($row['sub_description'] ?? ''),
        ];
    }

    $stmt->close();

    $countMap = synk_fetch_offering_enrollee_count_map(
        $conn,
        array_column($rows, 'offering_id')
    );

    $defaultSectionCount = synk_default_section_enrollee_count();
    $sectionSavedCounts = [];

    foreach ($rows as $row) {
        $sectionKey = synk_admin_enrollee_section_key($row);
        if (!isset($sectionSavedCounts[$sectionKey])) {
            $sectionSavedCounts[$sectionKey] = [];
        }

        $savedCount = synk_offering_enrollee_count_for_map($countMap, (int)$row['offering_id']);
        if ($savedCount > 0) {
            $sectionSavedCounts[$sectionKey][] = $savedCount;
        }
    }

    $sectionCountMap = [];
    foreach ($rows as $row) {
        $sectionKey = synk_admin_enrollee_section_key($row);
        if (isset($sectionCountMap[$sectionKey])) {
            continue;
        }

        $sectionCountMap[$sectionKey] = synk_admin_enrollee_resolve_section_count(
            $sectionSavedCounts[$sectionKey] ?? [],
            $defaultSectionCount
        );
    }

    foreach ($rows as &$row) {
        $row['section_key'] = synk_admin_enrollee_section_key($row);
        $row['total_enrollees'] = (int)($sectionCountMap[$row['section_key']] ?? $defaultSectionCount);
    }
    unset($row);

    return [
        'rows' => $rows,
        'summary' => [
            'total_offerings' => count($rows),
            'total_sections' => count($sectionCountMap),
            'programs_in_scope' => count(array_unique(array_column($rows, 'program_id'))),
            'total_dummy_enrollees' => array_sum($sectionCountMap),
            'default_section_capacity' => $defaultSectionCount,
        ],
    ];
}

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'admin') {
    synk_admin_enrollee_respond('error', 'Unauthorized access.');
}

$action = trim((string)($_POST['action'] ?? ''));
if ($action === '') {
    synk_admin_enrollee_respond('error', 'Missing action.');
}

$scope = synk_admin_enrollee_scope_from_post();
$scopeCheck = synk_admin_enrollee_validate_scope($conn, $scope);
if (!empty($scopeCheck['errors'])) {
    synk_admin_enrollee_respond('error', implode(' ', $scopeCheck['errors']));
}

if ($action === 'load_offerings') {
    $data = synk_admin_enrollee_fetch_scope_offerings($conn, $scope);
    synk_admin_enrollee_respond('success', 'Offerings loaded.', [
        'rows' => $data['rows'],
        'summary' => $data['summary'],
        'storage_ready' => synk_offering_enrollee_table_exists($conn),
    ]);
}

if ($action === 'save_counts') {
    $sessionCsrfToken = (string)($_SESSION['csrf_token'] ?? '');
    $requestCsrfToken = (string)($_POST['csrf_token'] ?? '');

    if ($sessionCsrfToken === '' || !hash_equals($sessionCsrfToken, $requestCsrfToken)) {
        synk_admin_enrollee_respond('error', 'Session expired. Refresh the page and try again.');
    }

    if (!synk_offering_enrollee_table_exists($conn)) {
        synk_admin_enrollee_respond(
            'error',
            'The enrollee storage table is missing. Create tbl_offering_enrollee_counts first, then save again.'
        );
    }

    $entries = json_decode((string)($_POST['entries_json'] ?? '[]'), true);
    if (!is_array($entries)) {
        synk_admin_enrollee_respond('error', 'Invalid enrollee payload.');
    }

    $scopeRows = synk_admin_enrollee_fetch_scope_offerings($conn, $scope);
    $allowedOfferingSections = [];
    $sectionOfferingIds = [];
    foreach (($scopeRows['rows'] ?? []) as $row) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        $sectionKey = trim((string)($row['section_key'] ?? ''));
        if ($offeringId <= 0 || $sectionKey === '') {
            continue;
        }

        $allowedOfferingSections[$offeringId] = $sectionKey;
        if (!isset($sectionOfferingIds[$sectionKey])) {
            $sectionOfferingIds[$sectionKey] = [];
        }

        $sectionOfferingIds[$sectionKey][] = $offeringId;
    }

    $sectionEntries = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $offeringId = (int)($entry['offering_id'] ?? 0);
        if ($offeringId <= 0 || !isset($allowedOfferingSections[$offeringId])) {
            continue;
        }

        $count = max(0, (int)($entry['total_enrollees'] ?? 0));
        $sectionKey = $allowedOfferingSections[$offeringId];

        if (!isset($sectionEntries[$sectionKey])) {
            $sectionEntries[$sectionKey] = [];
        }

        $sectionEntries[$sectionKey][] = $count;
    }

    $normalizedEntries = [];
    foreach ($sectionEntries as $sectionKey => $counts) {
        $sectionCount = synk_admin_enrollee_resolve_section_count($counts, 0);

        foreach (($sectionOfferingIds[$sectionKey] ?? []) as $offeringId) {
            $normalizedEntries[(int)$offeringId] = $sectionCount;
        }
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $savedCount = 0;
    $clearedCount = 0;

    $conn->begin_transaction();

    try {
        $upsertStmt = $conn->prepare("
            INSERT INTO " . synk_offering_enrollee_table_name() . " (
                offering_id,
                total_enrollees,
                source_kind,
                created_by,
                updated_by
            ) VALUES (?, ?, 'manual_dummy', ?, ?)
            ON DUPLICATE KEY UPDATE
                total_enrollees = VALUES(total_enrollees),
                source_kind = VALUES(source_kind),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        $deleteStmt = $conn->prepare("
            DELETE FROM " . synk_offering_enrollee_table_name() . "
            WHERE offering_id = ?
        ");

        if (!$upsertStmt || !$deleteStmt) {
            throw new RuntimeException('Could not prepare enrollee save statements.');
        }

        foreach ($normalizedEntries as $offeringId => $count) {
            if ($count > 0) {
                $upsertStmt->bind_param('iiii', $offeringId, $count, $userId, $userId);
                $upsertStmt->execute();
                $savedCount++;
                continue;
            }

            $deleteStmt->bind_param('i', $offeringId);
            $deleteStmt->execute();
            $clearedCount++;
        }

        $upsertStmt->close();
        $deleteStmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        synk_admin_enrollee_respond('error', 'Failed to save dummy enrollee counts. Please try again.');
    }

    $freshData = synk_admin_enrollee_fetch_scope_offerings($conn, $scope);
    synk_admin_enrollee_respond('success', 'Dummy enrollee counts saved.', [
        'rows' => $freshData['rows'],
        'summary' => $freshData['summary'],
        'saved_count' => $savedCount,
        'cleared_count' => $clearedCount,
        'storage_ready' => true,
    ]);
}

synk_admin_enrollee_respond('error', 'Unknown action.');
