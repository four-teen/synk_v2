<?php
/**
 * ============================================================================
 * File Path : synk/backend/query_generate_offerings.php
 * Page Name : Generate Unified Term Offerings (AJAX)
 * ============================================================================
 * PURPOSE:
 * - Sync offerings for a selected program + AY + semester
 * - Read each active section's assigned curriculum from tbl_section_curriculum
 * - Generate one unified offering pool even when sections use different prospectuses
 * - Keep out-of-scope offerings for history/preservation
 * ============================================================================
 */

session_start();
header('Content-Type: application/json');
include 'db.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';

function respond_json(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function offering_pair_key(int $psId, int $sectionId): string
{
    return $psId . '|' . $sectionId;
}

function build_curriculum_label(array $row): string
{
    $effectiveSy = trim((string)($row['effective_sy'] ?? ''));
    $cmoNo = trim((string)($row['cmo_no'] ?? ''));

    if ($effectiveSy !== '' && $cmoNo !== '') {
        return 'SY ' . $effectiveSy . ' - ' . $cmoNo;
    }

    if ($effectiveSy !== '') {
        return 'SY ' . $effectiveSy;
    }

    if ($cmoNo !== '') {
        return $cmoNo;
    }

    $prospectusId = (int)($row['prospectus_id'] ?? 0);
    return $prospectusId > 0 ? ('Prospectus #' . $prospectusId) : '';
}

function generator_db_error_message(mysqli $conn, string $message): string
{
    $dbError = trim((string)$conn->error);
    if ($dbError === '') {
        return $message;
    }

    return $message . ' Database error: ' . $dbError;
}

function load_program_term_context(mysqli $conn, int $programId, int $ayId, int $semester, int $collegeId): array
{
    $ctx = [
        'program_id' => 0,
        'program_code' => '',
        'program_name' => '',
        'major' => '',
        'ay_label' => '',
        'sections' => [],
        'target_map' => [],
        'summary' => [
            'total_active_sections' => 0,
            'sections_with_curriculum' => 0,
            'sections_missing_curriculum' => 0,
            'sections_without_subject_rows' => 0,
            'curriculum_versions_in_use' => 0,
            'total_subject_rows' => 0,
            'potential_offerings' => 0,
            'total_existing_rows' => 0,
            'current_synced_rows' => 0,
            'out_of_scope_existing' => 0,
            'out_of_scope_scheduled' => 0,
            'duplicate_pairs' => 0
        ],
        'curriculum_labels' => [],
        'blockers' => [],
        'warnings' => []
    ];

    if (!synk_table_exists($conn, 'tbl_section_curriculum')) {
        $ctx['blockers'][] = 'Section curriculum ownership table is missing. Create tbl_section_curriculum first.';
        return $ctx;
    }

    $ayStmt = $conn->prepare('SELECT ay FROM tbl_academic_years WHERE ay_id = ? LIMIT 1');
    if ($ayStmt) {
        $ayStmt->bind_param('i', $ayId);
        $ayStmt->execute();
        $ctx['ay_label'] = (string)($ayStmt->get_result()->fetch_assoc()['ay'] ?? '');
        $ayStmt->close();
    }

    if ($ctx['ay_label'] === '') {
        $ctx['blockers'][] = 'Selected academic year was not found.';
        return $ctx;
    }

    $programStmt = $conn->prepare("
        SELECT
            program_id,
            program_code,
            program_name,
            COALESCE(major, '') AS major
        FROM tbl_program
        WHERE program_id = ?
          AND college_id = ?
        LIMIT 1
    ");

    if (!$programStmt) {
        $ctx['blockers'][] = 'Unable to load the selected program.';
        return $ctx;
    }

    $programStmt->bind_param('ii', $programId, $collegeId);
    $programStmt->execute();
    $program = $programStmt->get_result()->fetch_assoc();
    $programStmt->close();

    if (!$program) {
        $ctx['blockers'][] = 'Program not found or not allowed for your college.';
        return $ctx;
    }

    $ctx['program_id'] = (int)($program['program_id'] ?? 0);
    $ctx['program_code'] = (string)($program['program_code'] ?? '');
    $ctx['program_name'] = (string)($program['program_name'] ?? '');
    $ctx['major'] = trim((string)($program['major'] ?? ''));

    $sectionStmt = $conn->prepare("
        SELECT
            s.section_id,
            s.year_level,
            s.section_name,
            s.full_section,
            sc.prospectus_id,
            COALESCE(ph.effective_sy, '') AS effective_sy,
            COALESCE(ph.cmo_no, '') AS cmo_no
        FROM tbl_sections s
        LEFT JOIN tbl_section_curriculum sc
            ON sc.section_id = s.section_id
        LEFT JOIN tbl_prospectus_header ph
            ON ph.prospectus_id = sc.prospectus_id
           AND ph.program_id = s.program_id
        WHERE s.program_id = ?
          AND s.ay_id = ?
          AND s.semester = ?
          AND s.status = 'active'
        ORDER BY s.year_level ASC, s.section_name ASC, s.section_id ASC
    ");

    if (!$sectionStmt) {
        $ctx['blockers'][] = 'Unable to load active sections for the selected term.';
        return $ctx;
    }

    $sectionStmt->bind_param('iii', $programId, $ayId, $semester);
    $sectionStmt->execute();
    $sectionRes = $sectionStmt->get_result();

    $subjectStmt = $conn->prepare("
        SELECT ps.ps_id
        FROM tbl_prospectus_year_sem pys
        INNER JOIN tbl_prospectus_subjects ps
            ON ps.pys_id = pys.pys_id
        WHERE pys.prospectus_id = ?
          AND pys.year_level = ?
          AND pys.semester = ?
        ORDER BY ps.sort_order ASC, ps.ps_id ASC
    ");

    if (!$subjectStmt) {
        $sectionStmt->close();
        $ctx['blockers'][] = 'Unable to load prospectus subjects for the selected sections.';
        return $ctx;
    }

    $subjectCache = [];
    $curriculumLabels = [];

    while ($sectionRes && ($row = $sectionRes->fetch_assoc())) {
        $sectionId = (int)($row['section_id'] ?? 0);
        $yearLevel = (int)($row['year_level'] ?? 0);
        $prospectusId = (int)($row['prospectus_id'] ?? 0);
        $curriculumLabel = build_curriculum_label($row);
        $sectionLabel = trim((string)($row['full_section'] ?? '')) ?: trim((string)($row['section_name'] ?? ''));

        if ($sectionId <= 0) {
            continue;
        }

        $ctx['summary']['total_active_sections']++;

        $sectionRow = [
            'section_id' => $sectionId,
            'section_name' => (string)($row['section_name'] ?? ''),
            'full_section' => (string)($row['full_section'] ?? ''),
            'year_level' => $yearLevel,
            'prospectus_id' => $prospectusId,
            'curriculum_label' => $curriculumLabel,
            'subject_count' => 0
        ];

        if ($prospectusId <= 0) {
            $ctx['summary']['sections_missing_curriculum']++;
            $ctx['blockers'][] = "Assign a curriculum to section {$sectionLabel} before generating offerings.";
            $ctx['sections'][] = $sectionRow;
            continue;
        }

        $ctx['summary']['sections_with_curriculum']++;
        $curriculumLabels[$prospectusId] = $curriculumLabel !== '' ? $curriculumLabel : ('Prospectus #' . $prospectusId);

        $cacheKey = $prospectusId . '|' . $yearLevel . '|' . $semester;
        if (!array_key_exists($cacheKey, $subjectCache)) {
            $subjectStmt->bind_param('iii', $prospectusId, $yearLevel, $semester);
            $subjectStmt->execute();
            $subjectRes = $subjectStmt->get_result();

            $subjectCache[$cacheKey] = [];
            while ($subjectRes && ($subjectRow = $subjectRes->fetch_assoc())) {
                $psId = (int)($subjectRow['ps_id'] ?? 0);
                if ($psId > 0) {
                    $subjectCache[$cacheKey][] = $psId;
                }
            }
        }

        $psIds = $subjectCache[$cacheKey];
        $sectionRow['subject_count'] = count($psIds);
        $ctx['sections'][] = $sectionRow;

        if (empty($psIds)) {
            $ctx['summary']['sections_without_subject_rows']++;
            $ctx['warnings'][] = "Section {$sectionLabel} has no subjects in its assigned curriculum for " . ($yearLevel > 0 ? ($yearLevel . ' year') : 'this year level') . ".";
            continue;
        }

        $ctx['summary']['total_subject_rows'] += count($psIds);
        $ctx['summary']['potential_offerings'] += count($psIds);

        foreach ($psIds as $psId) {
            $ctx['target_map'][offering_pair_key($psId, $sectionId)] = [
                'program_id' => $programId,
                'prospectus_id' => $prospectusId,
                'ps_id' => $psId,
                'year_level' => $yearLevel,
                'semester' => $semester,
                'ay_id' => $ayId,
                'section_id' => $sectionId
            ];
        }
    }

    $subjectStmt->close();
    $sectionStmt->close();

    $ctx['curriculum_labels'] = array_values($curriculumLabels);
    $ctx['summary']['curriculum_versions_in_use'] = count($curriculumLabels);

    if ($ctx['summary']['total_active_sections'] === 0) {
        $ctx['warnings'][] = 'No active sections were found for the selected term.';
    }

    return $ctx;
}

function inspect_existing_offerings(mysqli $conn, int $programId, int $ayId, int $semester, array $targetMap): array
{
    $stats = [
        'total_existing_rows' => 0,
        'current_synced_rows' => 0,
        'out_of_scope_existing' => 0,
        'out_of_scope_scheduled' => 0,
        'duplicate_pairs' => 0
    ];

    $scheduledOfferingJoin = synk_schedule_merge_scheduled_offering_join_sql($conn, 'sched', 'o');
    $stmt = $conn->prepare("
        SELECT
            o.offering_id,
            o.ps_id,
            o.section_id,
            IF(sched.offering_id IS NULL, 0, 1) AS has_schedule
        FROM tbl_prospectus_offering o
        {$scheduledOfferingJoin}
        WHERE o.program_id = ?
          AND o.ay_id = ?
          AND o.semester = ?
        ORDER BY o.offering_id ASC
    ");

    if (!$stmt) {
        return $stats;
    }

    $stmt->bind_param('iii', $programId, $ayId, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $grouped = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $stats['total_existing_rows']++;
        $sectionId = (int)($row['section_id'] ?? 0);

        if ($sectionId <= 0) {
            $stats['out_of_scope_existing']++;
            if ((int)($row['has_schedule'] ?? 0) === 1) {
                $stats['out_of_scope_scheduled']++;
            }
            continue;
        }

        $grouped[offering_pair_key((int)($row['ps_id'] ?? 0), $sectionId)][] = $row;
    }

    $stmt->close();

    foreach ($grouped as $key => $rows) {
        if (count($rows) > 1) {
            $stats['duplicate_pairs'] += count($rows) - 1;
        }

        if (isset($targetMap[$key])) {
            $stats['current_synced_rows']++;
            continue;
        }

        $stats['out_of_scope_existing'] += count($rows);
        foreach ($rows as $row) {
            if ((int)($row['has_schedule'] ?? 0) === 1) {
                $stats['out_of_scope_scheduled']++;
            }
        }
    }

    return $stats;
}

function choose_canonical_existing_row(array $rows): int
{
    $bestIndex = 0;

    foreach ($rows as $idx => $row) {
        $best = $rows[$bestIndex];
        $rowLocked = strtolower(trim((string)($row['status'] ?? ''))) === 'locked';
        $bestLocked = strtolower(trim((string)($best['status'] ?? ''))) === 'locked';

        if ($rowLocked !== $bestLocked) {
            if ($rowLocked) {
                $bestIndex = $idx;
            }
            continue;
        }

        $rowHasSchedule = (int)($row['has_schedule'] ?? 0) === 1;
        $bestHasSchedule = (int)($best['has_schedule'] ?? 0) === 1;
        if ($rowHasSchedule !== $bestHasSchedule) {
            if ($rowHasSchedule) {
                $bestIndex = $idx;
            }
            continue;
        }

        if ((int)($row['offering_id'] ?? 0) < (int)($best['offering_id'] ?? 0)) {
            $bestIndex = $idx;
        }
    }

    return $bestIndex;
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'scheduler') {
    respond_json(['status' => 'error', 'message' => 'Unauthorized access']);
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
if ($collegeId <= 0) {
    respond_json(['status' => 'error', 'message' => 'Missing college context']);
}

if (!isset($_POST['generate_offerings']) && !isset($_POST['validate_offerings_context'])) {
    respond_json(['status' => 'error', 'message' => 'Invalid request']);
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    respond_json(['status' => 'error', 'message' => 'CSRF validation failed']);
}

$programId = (int)($_POST['program_id'] ?? 0);
$ayId = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);

if ($programId <= 0 || $ayId <= 0 || $semester <= 0) {
    respond_json(['status' => 'error', 'message' => 'Missing parameters']);
}

if (!in_array($semester, [1, 2, 3], true)) {
    respond_json(['status' => 'error', 'message' => 'Invalid semester value']);
}

$context = load_program_term_context($conn, $programId, $ayId, $semester, $collegeId);
$context['summary'] = array_merge(
    $context['summary'],
    inspect_existing_offerings($conn, $programId, $ayId, $semester, $context['target_map'])
);

if ($context['summary']['out_of_scope_existing'] > 0) {
    $context['warnings'][] = 'Existing out-of-scope offerings will be retained for history, but excluded from the live mixed-curriculum view.';
}

if ($context['summary']['out_of_scope_scheduled'] > 0) {
    $context['warnings'][] = 'Some out-of-scope offerings already have schedules. Those schedule rows will be preserved.';
}

if ($context['summary']['duplicate_pairs'] > 0) {
    $context['warnings'][] = 'Duplicate offering pairs were detected for this term. Review them after generation.';
}

if (isset($_POST['validate_offerings_context'])) {
    respond_json([
        'status' => empty($context['blockers']) ? 'ok' : 'error',
        'can_generate' => empty($context['blockers']),
        'message' => empty($context['blockers'])
            ? 'Validation passed.'
            : 'Validation failed. Please fix the required data first.',
        'program_code' => $context['program_code'],
        'program_name' => $context['program_name'],
        'major' => $context['major'],
        'ay_label' => $context['ay_label'],
        'semester' => $semester,
        'summary' => $context['summary'],
        'curriculum_labels' => $context['curriculum_labels'],
        'blockers' => $context['blockers'],
        'warnings' => $context['warnings']
    ]);
}

if (!empty($context['blockers'])) {
    respond_json([
        'status' => 'error',
        'message' => implode(' ', $context['blockers']),
        'blockers' => $context['blockers']
    ]);
}

$conn->begin_transaction();

try {
    $targetMap = $context['target_map'];
    $scheduledOfferingJoin = synk_schedule_merge_scheduled_offering_join_sql($conn, 'sched', 'o');

    $existingStmt = $conn->prepare("
        SELECT
            o.offering_id,
            o.program_id,
            o.prospectus_id,
            o.ps_id,
            o.year_level,
            o.semester,
            o.ay_id,
            o.section_id,
            o.status,
            IF(sched.offering_id IS NULL, 0, 1) AS has_schedule
        FROM tbl_prospectus_offering o
        {$scheduledOfferingJoin}
        WHERE o.program_id = ?
          AND o.ay_id = ?
          AND o.semester = ?
        ORDER BY o.offering_id ASC
    ");

    if (!$existingStmt) {
        throw new RuntimeException(generator_db_error_message($conn, 'Unable to inspect existing offerings.'));
    }

    $existingStmt->bind_param('iii', $programId, $ayId, $semester);
    $existingStmt->execute();
    $existingRes = $existingStmt->get_result();

    $existingByKey = [];
    while ($existingRes && ($row = $existingRes->fetch_assoc())) {
        $sectionId = (int)($row['section_id'] ?? 0);
        if ($sectionId <= 0) {
            $existingByKey['__invalid__'][] = $row;
            continue;
        }

        $existingByKey[offering_pair_key((int)($row['ps_id'] ?? 0), $sectionId)][] = $row;
    }
    $existingStmt->close();

    $insertStmt = $conn->prepare("
        INSERT INTO tbl_prospectus_offering
            (program_id, prospectus_id, ps_id, year_level, semester, ay_id, section_id, status, date_created)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $syncStmt = $conn->prepare("
        UPDATE tbl_prospectus_offering
        SET program_id = ?, prospectus_id = ?, year_level = ?, semester = ?, ay_id = ?, section_id = ?
        WHERE offering_id = ?
    ");

    if (!$insertStmt || !$syncStmt) {
        throw new RuntimeException(generator_db_error_message($conn, 'Unable to prepare offering sync statements.'));
    }

    $inserted = 0;
    $syncedExisting = 0;
    $updatedExisting = 0;
    $retainedOutOfScope = 0;
    $retainedOutOfScopeScheduled = 0;
    $duplicatePairs = 0;

    foreach ($targetMap as $key => $target) {
        if (!isset($existingByKey[$key]) || empty($existingByKey[$key])) {
            $insertProgramId = (int)$target['program_id'];
            $insertProspectusId = (int)$target['prospectus_id'];
            $insertPsId = (int)$target['ps_id'];
            $insertYearLevel = (int)$target['year_level'];
            $insertSemester = (int)$target['semester'];
            $insertAyId = (int)$target['ay_id'];
            $insertSectionId = (int)$target['section_id'];

            $insertStmt->bind_param(
                'iiiiiii',
                $insertProgramId,
                $insertProspectusId,
                $insertPsId,
                $insertYearLevel,
                $insertSemester,
                $insertAyId,
                $insertSectionId
            );
            $insertStmt->execute();
            $inserted++;
            continue;
        }

        $rows = $existingByKey[$key];
        if (count($rows) > 1) {
            $duplicatePairs += count($rows) - 1;
        }

        $canonicalIndex = choose_canonical_existing_row($rows);
        $canonical = $rows[$canonicalIndex];

        $needsUpdate =
            (int)($canonical['program_id'] ?? 0) !== (int)$target['program_id'] ||
            (int)($canonical['prospectus_id'] ?? 0) !== (int)$target['prospectus_id'] ||
            (int)($canonical['year_level'] ?? 0) !== (int)$target['year_level'] ||
            (int)($canonical['semester'] ?? 0) !== (int)$target['semester'] ||
            (int)($canonical['ay_id'] ?? 0) !== (int)$target['ay_id'] ||
            (int)($canonical['section_id'] ?? 0) !== (int)$target['section_id'];

        if ($needsUpdate) {
            $syncProgramId = (int)$target['program_id'];
            $syncProspectusId = (int)$target['prospectus_id'];
            $syncYearLevel = (int)$target['year_level'];
            $syncSemester = (int)$target['semester'];
            $syncAyId = (int)$target['ay_id'];
            $syncSectionId = (int)$target['section_id'];
            $syncOfferingId = (int)($canonical['offering_id'] ?? 0);

            $syncStmt->bind_param(
                'iiiiiii',
                $syncProgramId,
                $syncProspectusId,
                $syncYearLevel,
                $syncSemester,
                $syncAyId,
                $syncSectionId,
                $syncOfferingId
            );
            $syncStmt->execute();
            $updatedExisting += $syncStmt->affected_rows > 0 ? 1 : 0;
        }

        $syncedExisting++;
        unset($existingByKey[$key]);
    }

    $insertStmt->close();
    $syncStmt->close();

    foreach ($existingByKey as $rows) {
        foreach ($rows as $row) {
            $retainedOutOfScope++;
            if ((int)($row['has_schedule'] ?? 0) === 1) {
                $retainedOutOfScopeScheduled++;
            }
        }
    }

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');

    $toActive = $conn->prepare("
        UPDATE tbl_prospectus_offering o
        {$liveOfferingJoins}
        {$scheduledOfferingJoin}
        SET o.status = 'active'
        WHERE o.program_id = ?
          AND o.ay_id = ?
          AND o.semester = ?
          AND (o.status IS NULL OR o.status = 'pending')
          AND sched.offering_id IS NOT NULL
    ");

    if (!$toActive) {
        throw new RuntimeException(generator_db_error_message($conn, 'Unable to update active offering statuses.'));
    }

    $toActive->bind_param('iii', $programId, $ayId, $semester);
    $toActive->execute();
    $updatedActive = $toActive->affected_rows;
    $toActive->close();

    $toPending = $conn->prepare("
        UPDATE tbl_prospectus_offering o
        {$liveOfferingJoins}
        {$scheduledOfferingJoin}
        SET o.status = 'pending'
        WHERE o.program_id = ?
          AND o.ay_id = ?
          AND o.semester = ?
          AND o.status = 'active'
          AND sched.offering_id IS NULL
    ");

    if (!$toPending) {
        throw new RuntimeException(generator_db_error_message($conn, 'Unable to update pending offering statuses.'));
    }

    $toPending->bind_param('iii', $programId, $ayId, $semester);
    $toPending->execute();
    $updatedPending = $toPending->affected_rows;
    $toPending->close();

    $conn->commit();

    respond_json([
        'status' => 'ok',
        'inserted' => $inserted,
        'synced_existing' => $syncedExisting,
        'updated_existing' => $updatedExisting,
        'retained_out_of_scope' => $retainedOutOfScope,
        'retained_out_of_scope_scheduled' => $retainedOutOfScopeScheduled,
        'duplicate_pairs' => $duplicatePairs,
        'updated_active' => $updatedActive,
        'updated_pending' => $updatedPending
    ]);
} catch (Throwable $e) {
    if ($conn && $conn->ping()) {
        $conn->rollback();
    }

    error_log('query_generate_offerings.php error: ' . $e->getMessage());
    respond_json([
        'status' => 'error',
        'message' => 'Failed to sync offerings. Please contact administrator if this persists.'
    ]);
}
