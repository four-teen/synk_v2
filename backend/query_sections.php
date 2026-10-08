<?php
/**
 * ======================================================================
 * Backend Handler : Sections (AJAX)
 * File Path       : synk/backend/query_sections.php
 * ======================================================================
 * HANDLES:
 * - load_grouped_sections (filtered by college + ay_id + semester)
 * - load_program_prospectus_options
 * - get_next_section_index (scope-aware)
 * - save_sections (scope-aware + duplicate-safe)
 * - update_section_prospectus
 * - update_year_level_prospectus
 * - update_program_prospectus
 * - delete_section (college-safe)
 * ======================================================================
 */

session_start();
include 'db.php';
require_once __DIR__ . '/schema_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    exit('forbidden');
}

$college_id = (int)($_SESSION['college_id'] ?? 0);

function section_curriculum_enabled(mysqli $conn): bool
{
    return synk_table_exists($conn, 'tbl_section_curriculum');
}

function build_section_program_label(array $row): string
{
    $programCode = trim((string)($row['program_code'] ?? ''));
    $programName = trim((string)($row['program_name'] ?? ''));

    return trim($programCode !== '' ? ($programCode . ' - ' . $programName) : $programName);
}

function build_prospectus_label(array $row): string
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

function load_program_prospectus_options(mysqli $conn, int $collegeId, int $programId): array
{
    if ($programId <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT
            h.prospectus_id,
            h.program_id,
            COALESCE(h.cmo_no, '') AS cmo_no,
            COALESCE(h.effective_sy, '') AS effective_sy
        FROM tbl_prospectus_header h
        INNER JOIN tbl_program p
            ON p.program_id = h.program_id
        WHERE h.program_id = ?
          AND p.college_id = ?
        ORDER BY h.effective_sy DESC, h.prospectus_id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("ii", $programId, $collegeId);
    $stmt->execute();
    $res = $stmt->get_result();

    $options = [];
    while ($row = $res->fetch_assoc()) {
        $options[] = [
            'prospectus_id' => (int)($row['prospectus_id'] ?? 0),
            'program_id' => (int)($row['program_id'] ?? 0),
            'effective_sy' => (string)($row['effective_sy'] ?? ''),
            'cmo_no' => (string)($row['cmo_no'] ?? ''),
            'label' => build_prospectus_label($row)
        ];
    }

    $stmt->close();
    return $options;
}

function validate_program_access(mysqli $conn, int $collegeId, int $programId): bool
{
    if ($programId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM tbl_program
        WHERE program_id = ?
          AND college_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ii", $programId, $collegeId);
    $stmt->execute();
    $res = $stmt->get_result();
    $hasAccess = $res && $res->num_rows > 0;
    $stmt->close();

    return $hasAccess;
}

function validate_program_prospectus(mysqli $conn, int $collegeId, int $programId, int $prospectusId): bool
{
    if ($programId <= 0 || $prospectusId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM tbl_prospectus_header h
        INNER JOIN tbl_program p
            ON p.program_id = h.program_id
        WHERE h.prospectus_id = ?
          AND h.program_id = ?
          AND p.college_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("iii", $prospectusId, $programId, $collegeId);
    $stmt->execute();
    $res = $stmt->get_result();
    $isValid = $res && $res->num_rows > 0;
    $stmt->close();

    return $isValid;
}

function load_section_program_context(mysqli $conn, int $collegeId, int $sectionId): ?array
{
    $stmt = $conn->prepare("
        SELECT
            s.section_id,
            s.program_id,
            s.year_level
        FROM tbl_sections s
        INNER JOIN tbl_program p
            ON p.program_id = s.program_id
        WHERE s.section_id = ?
          AND p.college_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("ii", $sectionId, $collegeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function save_section_curriculum(mysqli $conn, int $sectionId, int $prospectusId): bool
{
    if ($sectionId <= 0 || $prospectusId <= 0 || !section_curriculum_enabled($conn)) {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_section_curriculum (section_id, prospectus_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE prospectus_id = VALUES(prospectus_id)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ii", $sectionId, $prospectusId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function count_sections_in_scope(mysqli $conn, int $collegeId, int $programId, int $ayId, int $semester, int $yearLevel): int
{
    if ($programId <= 0 || $ayId <= 0 || $semester <= 0 || $yearLevel <= 0) {
        return 0;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total_rows
        FROM tbl_sections s
        INNER JOIN tbl_program p
            ON p.program_id = s.program_id
        WHERE s.program_id = ?
          AND s.ay_id = ?
          AND s.semester = ?
          AND s.year_level = ?
          AND p.college_id = ?
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param("iiiii", $programId, $ayId, $semester, $yearLevel, $collegeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total_rows'] ?? 0);
}

function save_year_level_curriculum(
    mysqli $conn,
    int $collegeId,
    int $programId,
    int $ayId,
    int $semester,
    int $yearLevel,
    int $prospectusId
): bool {
    if (
        $collegeId <= 0 ||
        $programId <= 0 ||
        $ayId <= 0 ||
        $semester <= 0 ||
        $yearLevel <= 0 ||
        $prospectusId <= 0 ||
        !section_curriculum_enabled($conn)
    ) {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_section_curriculum (section_id, prospectus_id)
        SELECT
            s.section_id,
            ?
        FROM tbl_sections s
        INNER JOIN tbl_program p
            ON p.program_id = s.program_id
        WHERE s.program_id = ?
          AND s.ay_id = ?
          AND s.semester = ?
          AND s.year_level = ?
          AND p.college_id = ?
        ON DUPLICATE KEY UPDATE prospectus_id = VALUES(prospectus_id)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("iiiiii", $prospectusId, $programId, $ayId, $semester, $yearLevel, $collegeId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function save_program_curriculum(
    mysqli $conn,
    int $collegeId,
    int $programId,
    int $ayId,
    int $semester,
    int $prospectusId
): bool {
    if (
        $collegeId <= 0 ||
        $programId <= 0 ||
        $ayId <= 0 ||
        $semester <= 0 ||
        $prospectusId <= 0 ||
        !section_curriculum_enabled($conn)
    ) {
        return false;
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_section_curriculum (section_id, prospectus_id)
        SELECT
            s.section_id,
            ?
        FROM tbl_sections s
        INNER JOIN tbl_program p
            ON p.program_id = s.program_id
        WHERE s.program_id = ?
          AND s.ay_id = ?
          AND s.semester = ?
          AND p.college_id = ?
        ON DUPLICATE KEY UPDATE prospectus_id = VALUES(prospectus_id)
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("iiiii", $prospectusId, $programId, $ayId, $semester, $collegeId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/* =========================================================
   1) LOAD GROUPED SECTIONS (BY PROGRAM)
========================================================= */
if (isset($_POST['load_grouped_sections'])) {
    $ay_id = isset($_POST['ay_id']) ? (int)$_POST['ay_id'] : 0;
    $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : 0;

    if ($ay_id <= 0 || $semester <= 0) {
        echo json_encode([]);
        exit;
    }

    $curriculumEnabled = section_curriculum_enabled($conn);
    $prospectusSelect = $curriculumEnabled
        ? ",
            sc.prospectus_id,
            COALESCE(ph.effective_sy, '') AS effective_sy,
            COALESCE(ph.cmo_no, '') AS cmo_no
        "
        : ",
            NULL AS prospectus_id,
            '' AS effective_sy,
            '' AS cmo_no
        ";

    $prospectusJoin = $curriculumEnabled
        ? "
        LEFT JOIN tbl_section_curriculum sc
            ON sc.section_id = s.section_id
        LEFT JOIN tbl_prospectus_header ph
            ON ph.prospectus_id = sc.prospectus_id
           AND ph.program_id = s.program_id
        "
        : "";

    $sql = "
        SELECT
            s.section_id,
            s.program_id,
            s.year_level,
            s.section_name,
            s.full_section,
            s.status
            {$prospectusSelect},
            p.program_code,
            CONCAT(
                p.program_name,
                IF(
                    TRIM(COALESCE(p.major, '')) <> '',
                    CONCAT(' (Major in ', p.major, ')'),
                    ''
                )
            ) AS program_name
        FROM tbl_sections s
        INNER JOIN tbl_program p
            ON p.program_id = s.program_id
        {$prospectusJoin}
        WHERE p.college_id = ?
          AND s.ay_id = ?
          AND s.semester = ?
        ORDER BY
            p.program_code ASC,
            p.program_name ASC,
            p.major ASC,
            s.year_level ASC,
            s.section_name ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode([]);
        exit;
    }

    $stmt->bind_param("iii", $college_id, $ay_id, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $grouped = [];
    while ($row = $res->fetch_assoc()) {
        $programLabel = build_section_program_label($row);

        if (!isset($grouped[$programLabel])) {
            $grouped[$programLabel] = [];
        }

        $prospectusId = (int)($row['prospectus_id'] ?? 0);
        $prospectusLabel = build_prospectus_label($row);

        $grouped[$programLabel][] = [
            'section_id' => (int)($row['section_id'] ?? 0),
            'program_id' => (int)($row['program_id'] ?? 0),
            'year_level' => (string)($row['year_level'] ?? ''),
            'section_name' => (string)($row['section_name'] ?? ''),
            'full_section' => (string)($row['full_section'] ?? ''),
            'status' => (string)($row['status'] ?? 'inactive'),
            'prospectus_id' => $prospectusId,
            'prospectus_label' => $prospectusLabel,
            'has_curriculum' => $curriculumEnabled && $prospectusId > 0
        ];
    }

    $stmt->close();
    echo json_encode($grouped);
    exit;
}

/* =========================================================
   1.5) LOAD PROGRAM PROSPECTUS OPTIONS
========================================================= */
if (isset($_POST['load_program_prospectus_options'])) {
    $program_id = isset($_POST['program_id']) ? (int)$_POST['program_id'] : 0;
    $curriculumEnabled = section_curriculum_enabled($conn);

    echo json_encode([
        'curriculum_enabled' => $curriculumEnabled,
        'options' => $curriculumEnabled ? load_program_prospectus_options($conn, $college_id, $program_id) : []
    ]);
    exit;
}

/* =========================================================
   2) GET NEXT SECTION INDEX
========================================================= */
if (isset($_POST['get_next_section_index'])) {
    $program_id = isset($_POST['program_id']) ? (int)$_POST['program_id'] : 0;
    $year_level = isset($_POST['year_level']) ? (int)$_POST['year_level'] : 0;
    $ay_id = isset($_POST['ay_id']) ? (int)$_POST['ay_id'] : 0;
    $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : 0;

    if ($program_id <= 0 || $year_level <= 0 || $ay_id <= 0 || $semester <= 0) {
        echo json_encode(['next_index' => 0]);
        exit;
    }

    if (!validate_program_access($conn, $college_id, $program_id)) {
        echo json_encode(['next_index' => 0]);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT section_name
        FROM tbl_sections
        WHERE program_id = ?
          AND ay_id = ?
          AND semester = ?
          AND year_level = ?
    ");

    if (!$stmt) {
        echo json_encode(['next_index' => 0]);
        exit;
    }

    $stmt->bind_param("iiii", $program_id, $ay_id, $semester, $year_level);
    $stmt->execute();
    $res = $stmt->get_result();

    $maxIndex = 0;
    while ($row = $res->fetch_assoc()) {
        $name = (string)($row['section_name'] ?? '');
        $letter = strtoupper(substr($name, -1));

        if ($letter >= 'A' && $letter <= 'Z') {
            $idx = ord($letter) - ord('A') + 1;
            if ($idx > $maxIndex) {
                $maxIndex = $idx;
            }
        }
    }

    $stmt->close();
    echo json_encode(['next_index' => $maxIndex]);
    exit;
}

/* =========================================================
   3) SAVE SECTIONS
========================================================= */
if (isset($_POST['save_sections'])) {
    $program_id = isset($_POST['program_id']) ? (int)$_POST['program_id'] : 0;
    $program_code = trim((string)($_POST['program_code'] ?? ''));
    $ay_id = isset($_POST['ay_id']) ? (int)$_POST['ay_id'] : 0;
    $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : 0;
    $year_level = isset($_POST['year_level']) ? (int)$_POST['year_level'] : 0;
    $count = isset($_POST['count']) ? (int)$_POST['count'] : 0;
    $start_index = isset($_POST['start_index']) ? (int)$_POST['start_index'] : 0;
    $prospectus_id = isset($_POST['prospectus_id']) ? (int)$_POST['prospectus_id'] : 0;
    $curriculumEnabled = section_curriculum_enabled($conn);

    if ($program_id <= 0 || $ay_id <= 0 || $semester <= 0 || $year_level <= 0 || $count <= 0) {
        exit("Missing required data.");
    }

    if ($semester < 1 || $semester > 3) {
        exit("Invalid semester value.");
    }

    if (!validate_program_access($conn, $college_id, $program_id)) {
        exit("forbidden");
    }

    if ($curriculumEnabled) {
        if ($prospectus_id <= 0) {
            exit("Select a curriculum before saving sections.");
        }

        if (!validate_program_prospectus($conn, $college_id, $program_id, $prospectus_id)) {
            exit("The selected curriculum does not belong to this program.");
        }
    }

    $letters = range('A', 'Z');
    $sectionNames = [];
    for ($i = 0; $i < $count; $i++) {
        $idx = $start_index + $i;
        if (!isset($letters[$idx])) {
            break;
        }

        $sectionNames[] = $year_level . $letters[$idx];
    }

    if (count($sectionNames) === 0) {
        exit("No sections to save.");
    }

    $placeholders = implode(',', array_fill(0, count($sectionNames), '?'));
    $types = str_repeat('s', count($sectionNames));

    $dupSql = "
        SELECT COUNT(*) AS cnt
        FROM tbl_sections
        WHERE program_id = ?
          AND ay_id = ?
          AND semester = ?
          AND year_level = ?
          AND section_name IN ($placeholders)
    ";

    $dupStmt = $conn->prepare($dupSql);
    if (!$dupStmt) {
        exit("Unable to validate duplicate sections.");
    }

    $bindTypes = "iiii" . $types;
    $params = array_merge([$bindTypes, $program_id, $ay_id, $semester, $year_level], $sectionNames);
    $tmp = [];
    foreach ($params as $key => $value) {
        $tmp[$key] = &$params[$key];
    }
    call_user_func_array([$dupStmt, 'bind_param'], $tmp);

    $dupStmt->execute();
    $dupRes = $dupStmt->get_result();
    $dupRow = $dupRes ? $dupRes->fetch_assoc() : ['cnt' => 0];
    $dupStmt->close();

    if ((int)($dupRow['cnt'] ?? 0) > 0) {
        exit("Duplicate detected: Some sections already exist in this Academic Year + Semester scope.");
    }

    $conn->begin_transaction();

    try {
        $ins = $conn->prepare("
            INSERT INTO tbl_sections
                (program_id, ay_id, semester, year_level, section_name, full_section, status)
            VALUES
                (?, ?, ?, ?, ?, ?, 'active')
        ");

        if (!$ins) {
            throw new RuntimeException('Unable to prepare section insert.');
        }

        foreach ($sectionNames as $sectionName) {
            $fullSection = trim($program_code . ' ' . $sectionName);
            $ins->bind_param("iiiiss", $program_id, $ay_id, $semester, $year_level, $sectionName, $fullSection);
            $ins->execute();

            if ($curriculumEnabled) {
                $sectionId = (int)$conn->insert_id;
                if ($sectionId <= 0 || !save_section_curriculum($conn, $sectionId, $prospectus_id)) {
                    throw new RuntimeException('Unable to save section curriculum.');
                }
            }
        }

        $ins->close();
        $conn->commit();
        exit("success");
    } catch (Throwable $e) {
        $conn->rollback();
        exit("Error saving sections: " . $e->getMessage());
    }
}

/* =========================================================
   3.5) UPDATE SECTION CURRICULUM
========================================================= */
if (isset($_POST['update_section_prospectus'])) {
    if (!section_curriculum_enabled($conn)) {
        exit("curriculum_disabled");
    }

    $section_id = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
    $prospectus_id = isset($_POST['prospectus_id']) ? (int)$_POST['prospectus_id'] : 0;

    if ($section_id <= 0 || $prospectus_id <= 0) {
        exit("Missing required data.");
    }

    $sectionContext = load_section_program_context($conn, $college_id, $section_id);
    if (!$sectionContext) {
        exit("forbidden");
    }

    $program_id = (int)($sectionContext['program_id'] ?? 0);
    if (!validate_program_prospectus($conn, $college_id, $program_id, $prospectus_id)) {
        exit("The selected curriculum does not belong to this section's program.");
    }

    if (save_section_curriculum($conn, $section_id, $prospectus_id)) {
        exit("success");
    }

    exit("error");
}

/* =========================================================
   3.6) UPDATE YEAR-LEVEL CURRICULUM
========================================================= */
if (isset($_POST['update_year_level_prospectus'])) {
    if (!section_curriculum_enabled($conn)) {
        exit("curriculum_disabled");
    }

    $program_id = isset($_POST['program_id']) ? (int)$_POST['program_id'] : 0;
    $ay_id = isset($_POST['ay_id']) ? (int)$_POST['ay_id'] : 0;
    $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : 0;
    $year_level = isset($_POST['year_level']) ? (int)$_POST['year_level'] : 0;
    $prospectus_id = isset($_POST['prospectus_id']) ? (int)$_POST['prospectus_id'] : 0;

    if ($program_id <= 0 || $ay_id <= 0 || $semester <= 0 || $year_level <= 0 || $prospectus_id <= 0) {
        exit("Missing required data.");
    }

    if (!validate_program_access($conn, $college_id, $program_id)) {
        exit("forbidden");
    }

    if (!validate_program_prospectus($conn, $college_id, $program_id, $prospectus_id)) {
        exit("The selected curriculum does not belong to this program.");
    }

    if (count_sections_in_scope($conn, $college_id, $program_id, $ay_id, $semester, $year_level) <= 0) {
        exit("No sections were found for this program, year level, and term.");
    }

    if (save_year_level_curriculum($conn, $college_id, $program_id, $ay_id, $semester, $year_level, $prospectus_id)) {
        exit("success");
    }

    exit("error");
}

/* =========================================================
   3.7) UPDATE PROGRAM CURRICULUM
========================================================= */
if (isset($_POST['update_program_prospectus'])) {
    if (!section_curriculum_enabled($conn)) {
        exit("curriculum_disabled");
    }

    $program_id = isset($_POST['program_id']) ? (int)$_POST['program_id'] : 0;
    $ay_id = isset($_POST['ay_id']) ? (int)$_POST['ay_id'] : 0;
    $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : 0;
    $prospectus_id = isset($_POST['prospectus_id']) ? (int)$_POST['prospectus_id'] : 0;

    if ($program_id <= 0 || $ay_id <= 0 || $semester <= 0 || $prospectus_id <= 0) {
        exit("Missing required data.");
    }

    if (!validate_program_access($conn, $college_id, $program_id)) {
        exit("forbidden");
    }

    if (!validate_program_prospectus($conn, $college_id, $program_id, $prospectus_id)) {
        exit("The selected curriculum does not belong to this program.");
    }

    $programSectionCount = count_sections_in_scope($conn, $college_id, $program_id, $ay_id, $semester, 1)
        + count_sections_in_scope($conn, $college_id, $program_id, $ay_id, $semester, 2)
        + count_sections_in_scope($conn, $college_id, $program_id, $ay_id, $semester, 3)
        + count_sections_in_scope($conn, $college_id, $program_id, $ay_id, $semester, 4)
        + count_sections_in_scope($conn, $college_id, $program_id, $ay_id, $semester, 5)
        + count_sections_in_scope($conn, $college_id, $program_id, $ay_id, $semester, 6);

    if ($programSectionCount <= 0) {
        exit("No sections were found for this program and term.");
    }

    if (save_program_curriculum($conn, $college_id, $program_id, $ay_id, $semester, $prospectus_id)) {
        exit("success");
    }

    exit("error");
}

/* =========================================================
   4) DELETE SECTION
========================================================= */
if (isset($_POST['delete_section'])) {
    $section_id = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
    if ($section_id <= 0) {
        exit("error");
    }

    $sectionContext = load_section_program_context($conn, $college_id, $section_id);
    if (!$sectionContext) {
        exit("forbidden");
    }

    $del = $conn->prepare("DELETE FROM tbl_sections WHERE section_id = ? LIMIT 1");
    if (!$del) {
        exit("error");
    }

    $del->bind_param("i", $section_id);
    if ($del->execute()) {
        $del->close();
        exit("success");
    }

    $del->close();
    exit("error");
}

exit("invalid");
