<?php
// backend/query_class_schedule.php
session_start();
include 'db.php';
require_once __DIR__ . '/scheduler_request_guard.php';
synk_scheduler_guard_request($conn, 'query_class_schedule.php');
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';
require_once __DIR__ . '/academic_schedule_policy_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/schedule_tba_helper.php';

header('Content-Type: application/json');

/* =====================================================
   RESPONSE HELPER
===================================================== */
function respond($status, $message = "", $extra = []) {
    echo json_encode(array_merge([
        "status"  => $status,
        "message" => $message
    ], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond("error", "Invalid request method.");
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'scheduler') {
    respond("error", "Unauthorized access.");
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    respond("error", "CSRF validation failed.");
}

$college_id = (int)($_SESSION['college_id'] ?? 0);
if ($college_id <= 0) {
    respond("error", "Missing college context.");
}
$schedulePolicy = synk_fetch_effective_schedule_policy($conn, $college_id);
$scheduleWriteActions = ['save_schedule_blocks','save_schedule','save_dual_schedule','clear_schedule',
    'clear_all_college_schedules','save_schedule_merge','save_schedule_set','load_schedule_set_into_live'];
foreach ($scheduleWriteActions as $scheduleWriteAction) {
    if (isset($_POST[$scheduleWriteAction])) {
        try { synk_schedule_acquire_write_lock($conn); }
        catch (Throwable $e) { respond('error', $e->getMessage()); }
        break;
    }
}


/* =====================================================
   HELPERS
===================================================== */
function time_fmt($t) {
    return date("h:i A", strtotime($t));
}

function days_fmt($arr) {
    return is_array($arr) ? implode("", $arr) : "";
}

function year_level_label($yearLevel) {
    $normalized = (int)$yearLevel;
    if ($normalized <= 0) {
        return "";
    }

    if ($normalized === 1) {
        return "1st Year";
    }

    if ($normalized === 2) {
        return "2nd Year";
    }

    if ($normalized === 3) {
        return "3rd Year";
    }

    return $normalized . "th Year";
}

function days_overlap($a, $b) {
    return is_array($a) && is_array($b) && count(array_intersect($a, $b)) > 0;
}

function time_overlap($s1, $e1, $s2, $e2) {
    return ($s1 < $e2) && ($e1 > $s2);
}

function normalize_time_input($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $value)) {
        return null;
    }

    return strlen($value) === 5 ? $value . ':00' : $value;
}

function validate_time_window($timeStart, $timeEnd, $label, array $policy) {
    if (!synk_schedule_policy_is_within_window($timeStart, $timeEnd, $policy)) {
        respond(
            "error",
            "{$label} must stay within the supported scheduling window of " . $policy['window_label'] . "."
        );
    }
}

function validate_schedule_policy($days, $timeStart, $timeEnd, $label, array $policy) {
    $disallowedDays = synk_schedule_policy_disallowed_days((array)$days, $policy);
    if (!empty($disallowedDays)) {
        respond(
            "error",
            "{$label} uses blocked day(s): " . implode(', ', $disallowedDays) . "."
        );
    }

    validate_time_window($timeStart, $timeEnd, $label, $policy);

    $blockedTime = synk_schedule_policy_blocked_time_overlap($timeStart, $timeEnd, $policy);
    if ($blockedTime !== null) {
        respond(
            "error",
            "{$label} overlaps the blocked time range of " .
            synk_schedule_policy_window_label(
                (string)($blockedTime['start'] ?? ''),
                (string)($blockedTime['end'] ?? '')
            ) . "."
        );
    }
}

function schedule_policy_issue_message($days, $timeStart, $timeEnd, $label, array $policy) {
    $disallowedDays = synk_schedule_policy_disallowed_days((array)$days, $policy);
    if (!empty($disallowedDays)) {
        return "{$label} uses blocked day(s): " . implode(', ', $disallowedDays) . ".";
    }

    if (!synk_schedule_policy_is_within_window($timeStart, $timeEnd, $policy)) {
        return "{$label} must stay within the supported scheduling window of " . $policy['window_label'] . ".";
    }

    $blockedTime = synk_schedule_policy_blocked_time_overlap($timeStart, $timeEnd, $policy);
    if ($blockedTime !== null) {
        return "{$label} overlaps the blocked time range of " .
            synk_schedule_policy_window_label(
                (string)($blockedTime['start'] ?? ''),
                (string)($blockedTime['end'] ?? '')
            ) . ".";
    }

    return null;
}

function normalize_days_array($days) {
    if (!is_array($days)) {
        return [];
    }

    $out = [];
    foreach ($days as $day) {
        $value = trim((string)$day);
        if ($value !== '') {
            $out[$value] = true;
        }
    }

    return array_keys($out);
}

function mark_offering_scheduled($conn, $offering_id) {
    $upd = $conn->prepare("
        UPDATE tbl_prospectus_offering
        SET status = 'active'
        WHERE offering_id = ?
          AND (status IS NULL OR status != 'locked')
    ");
    $upd->bind_param("i", $offering_id);
    $ok = $upd->execute();
    $upd->close();
    return $ok;
}

/* =====================================================
   LOAD OFFERING CONTEXT
===================================================== */
function load_context_any($conn, $offering_id, $college_id) {
    $sql = "
        SELECT
            o.section_id,
            o.ay_id,
            o.semester,
            o.status AS offering_status,
            sec.section_name,
            sec.full_section
        FROM tbl_prospectus_offering o
        LEFT JOIN tbl_sections sec ON sec.section_id = o.section_id
        JOIN tbl_program p ON p.program_id = o.program_id
        WHERE o.offering_id = ?
          AND p.college_id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $offering_id, $college_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function load_context_live($conn, $offering_id, $college_id) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            o.offering_id,
            o.program_id,
            o.section_id,
            o.ay_id,
            o.semester,
            o.status AS offering_status,
            sec.section_name,
            sec.full_section,
            sec.year_level,
            p.program_code,
            ps.sub_id,
            o.ps_id,
            sm.sub_code,
            sm.sub_description,
            ps.lec_units,
            ps.lab_units,
            ps.total_units
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        JOIN tbl_program p ON p.program_id = o.program_id
        JOIN tbl_subject_masterlist sm ON sm.sub_id = ps.sub_id
        WHERE o.offering_id = ?
          AND p.college_id = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $offering_id, $college_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function load_scoped_offerings_for_term($conn, $program_id, $ay_id, $semester, $college_id) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            o.offering_id,
            o.ay_id,
            o.semester,
            o.status AS offering_status,
            sec.section_name,
            sm.sub_code,
            sm.sub_description
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        WHERE o.program_id = ?
          AND o.ay_id = ?
          AND o.semester = ?
          AND p.college_id = ?
        ORDER BY sec.section_name ASC, sm.sub_code ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iiii", $program_id, $ay_id, $semester, $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'offering_id' => (int)$row['offering_id'],
            'ay_id' => (int)$row['ay_id'],
            'semester' => (int)$row['semester'],
            'offering_status' => strtolower(trim((string)($row['offering_status'] ?? 'pending'))),
            'section_name' => (string)($row['section_name'] ?? ''),
            'sub_code' => (string)($row['sub_code'] ?? ''),
            'sub_description' => (string)($row['sub_description'] ?? '')
        ];
    }

    $stmt->close();
    return $rows;
}

function load_scoped_offerings_for_college_term($conn, $ay_id, $semester, $college_id) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            o.offering_id,
            o.prospectus_id,
            o.ay_id,
            o.semester,
            o.status AS offering_status,
            sec.section_name,
            sm.sub_code,
            sm.sub_description
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        WHERE o.ay_id = ?
          AND o.semester = ?
          AND p.college_id = ?
        ORDER BY
            p.program_code ASC,
            sec.section_name ASC,
            sm.sub_code ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iii", $ay_id, $semester, $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'offering_id' => (int)$row['offering_id'],
            'prospectus_id' => (int)($row['prospectus_id'] ?? 0),
            'ay_id' => (int)$row['ay_id'],
            'semester' => (int)$row['semester'],
            'offering_status' => strtolower(trim((string)($row['offering_status'] ?? 'pending'))),
            'section_name' => (string)($row['section_name'] ?? ''),
            'sub_code' => (string)($row['sub_code'] ?? ''),
            'sub_description' => (string)($row['sub_description'] ?? '')
        ];
    }

    $stmt->close();
    return $rows;
}

function saved_schedule_tables_exist($conn) {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    foreach (['tbl_program_schedule_set', 'tbl_program_schedule_set_row'] as $table) {
        $q = $conn->query("SHOW TABLES LIKE '{$table}'");
        if (!($q && $q->num_rows > 0)) {
            $exists = false;
            return $exists;
        }
    }

    $exists = true;
    return $exists;
}

function saved_schedule_workload_table_name() {
    return 'tbl_program_schedule_set_workload_row';
}

function saved_schedule_workload_table_exists($conn) {
    $table = saved_schedule_workload_table_name();
    $q = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $q && $q->num_rows > 0;
}

function saved_schedule_workspace_state_table_name() {
    return 'tbl_program_schedule_live_workspace_state';
}

function saved_schedule_workspace_state_table_exists($conn) {
    $table = saved_schedule_workspace_state_table_name();
    $q = $conn->query("SHOW TABLES LIKE '{$table}'");
    return $q && $q->num_rows > 0;
}

function ensure_saved_schedule_workload_table_exists($conn) {
    if (saved_schedule_workload_table_exists($conn)) {
        return true;
    }

    $table = saved_schedule_workload_table_name();
    $sql = "
        CREATE TABLE IF NOT EXISTS `{$table}` (
            schedule_set_workload_row_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            schedule_set_id INT(10) UNSIGNED NOT NULL,
            schedule_set_row_id INT(10) UNSIGNED NOT NULL,
            offering_id INT(10) UNSIGNED NOT NULL,
            program_id INT(10) UNSIGNED NOT NULL,
            schedule_type VARCHAR(10) NOT NULL DEFAULT 'LEC',
            block_order SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
            faculty_id INT(10) UNSIGNED NOT NULL,
            faculty_name_snapshot VARCHAR(180) NOT NULL DEFAULT '',
            subject_code_snapshot VARCHAR(50) NOT NULL DEFAULT '',
            section_name_snapshot VARCHAR(120) NOT NULL DEFAULT '',
            date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (schedule_set_workload_row_id),
            UNIQUE KEY uq_pss_workload_row_faculty (schedule_set_id, schedule_set_row_id, faculty_id),
            KEY idx_pss_workload_set (schedule_set_id),
            KEY idx_pss_workload_set_row (schedule_set_row_id),
            KEY idx_pss_workload_faculty (faculty_id),
            CONSTRAINT fk_pss_workload_set
                FOREIGN KEY (schedule_set_id) REFERENCES tbl_program_schedule_set (schedule_set_id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$conn->query($sql)) {
        return false;
    }

    return true;
}

function ensure_saved_schedule_workspace_state_table_exists($conn) {
    if (saved_schedule_workspace_state_table_exists($conn)) {
        return true;
    }

    $table = saved_schedule_workspace_state_table_name();
    $sql = "
        CREATE TABLE IF NOT EXISTS `{$table}` (
            workspace_state_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            college_id INT(10) UNSIGNED NOT NULL,
            ay_id INT(10) UNSIGNED NOT NULL,
            semester TINYINT(3) UNSIGNED NOT NULL,
            last_loaded_schedule_set_id INT(10) UNSIGNED DEFAULT NULL,
            date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            date_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (workspace_state_id),
            UNIQUE KEY uq_pss_workspace_scope (college_id, ay_id, semester),
            KEY idx_pss_workspace_set (last_loaded_schedule_set_id),
            CONSTRAINT fk_pss_workspace_set
                FOREIGN KEY (last_loaded_schedule_set_id) REFERENCES tbl_program_schedule_set (schedule_set_id)
                ON DELETE SET NULL
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$conn->query($sql)) {
        return false;
    }

    return true;
}

function ensure_saved_schedule_tables_exist($conn) {
    if (!saved_schedule_tables_exist($conn)) {
        respond("error", "Create tbl_program_schedule_set and tbl_program_schedule_set_row first.");
    }

    if (!ensure_saved_schedule_workload_table_exists($conn)) {
        respond("error", "Create tbl_program_schedule_set_workload_row first.");
    }

    if (!ensure_saved_schedule_workspace_state_table_exists($conn)) {
        respond("error", "Create tbl_program_schedule_live_workspace_state first.");
    }
}

function saved_schedule_scope_metadata($programId, $programCode = '', $programName = '') {
    $programId = (int)$programId;
    $programCode = trim((string)$programCode);
    $programName = trim((string)$programName);

    $scopeLabel = 'College term';
    if ($programId > 0) {
        $scopeParts = array_filter([$programCode, $programName], static function ($value) {
            return trim((string)$value) !== '';
        });
        $scopeLabel = 'Legacy program set';
        if (!empty($scopeParts)) {
            $scopeLabel .= ': ' . implode(' - ', $scopeParts);
        }
    }

    return [
        'scope_key' => $programId > 0 ? 'program' : 'college',
        'scope_label' => $scopeLabel
    ];
}

function load_saved_schedule_workspace_state($conn, $college_id, $ay_id, $semester) {
    $table = saved_schedule_workspace_state_table_name();
    $sql = "
        SELECT
            ws.last_loaded_schedule_set_id,
            s.schedule_set_id,
            s.program_id,
            s.set_name,
            COALESCE(NULLIF(TRIM(p.program_code), ''), '') AS program_code,
            COALESCE(NULLIF(TRIM(p.program_name), ''), '') AS program_name
        FROM `{$table}` ws
        LEFT JOIN tbl_program_schedule_set s
            ON s.schedule_set_id = ws.last_loaded_schedule_set_id
           AND s.college_id = ws.college_id
           AND s.ay_id = ws.ay_id
           AND s.semester = ws.semester
        LEFT JOIN tbl_program p
            ON p.program_id = s.program_id
        WHERE ws.college_id = ?
          AND ws.ay_id = ?
          AND ws.semester = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("iii", $college_id, $ay_id, $semester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $scheduleSetId = (int)($row['schedule_set_id'] ?? $row['last_loaded_schedule_set_id'] ?? 0);
    if ($scheduleSetId <= 0) {
        return null;
    }

    $scopeMeta = saved_schedule_scope_metadata(
        (int)($row['program_id'] ?? 0),
        (string)($row['program_code'] ?? ''),
        (string)($row['program_name'] ?? '')
    );

    return [
        'last_loaded_schedule_set_id' => $scheduleSetId,
        'last_loaded_schedule_set_name' => (string)($row['set_name'] ?? ''),
        'last_loaded_scope_label' => (string)($scopeMeta['scope_label'] ?? '')
    ];
}

function save_saved_schedule_workspace_state($conn, $college_id, $ay_id, $semester, $schedule_set_id) {
    $table = saved_schedule_workspace_state_table_name();
    $scheduleSetId = max(0, (int)$schedule_set_id);
    $sql = "
        INSERT INTO `{$table}`
            (college_id, ay_id, semester, last_loaded_schedule_set_id, date_created, date_updated)
        VALUES (?, ?, ?, NULLIF(?, 0), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            last_loaded_schedule_set_id = NULLIF(?, 0),
            date_updated = CURRENT_TIMESTAMP
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("iiiii", $college_id, $ay_id, $semester, $scheduleSetId, $scheduleSetId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function saved_schedule_program_term_has_merge_rows($conn, $college_id, $program_id, $ay_id, $semester) {
    $mergeTable = synk_schedule_merge_table_name();
    if (!synk_table_exists($conn, $mergeTable)) {
        return false;
    }

    $sql = "
        SELECT 1
        FROM `{$mergeTable}` merge_map
        INNER JOIN tbl_prospectus_offering owner_offer
            ON owner_offer.offering_id = merge_map.owner_offering_id
        INNER JOIN tbl_program owner_program
            ON owner_program.program_id = owner_offer.program_id
        INNER JOIN tbl_prospectus_offering member_offer
            ON member_offer.offering_id = merge_map.member_offering_id
        INNER JOIN tbl_program member_program
            ON member_program.program_id = member_offer.program_id
        WHERE (
                owner_program.college_id = ?
            AND owner_offer.program_id = ?
            AND owner_offer.ay_id = ?
            AND owner_offer.semester = ?
        ) OR (
                member_program.college_id = ?
            AND member_offer.program_id = ?
            AND member_offer.ay_id = ?
            AND member_offer.semester = ?
        )
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "iiiiiiii",
        $college_id,
        $program_id,
        $ay_id,
        $semester,
        $college_id,
        $program_id,
        $ay_id,
        $semester
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return $row !== null;
}

function saved_schedule_college_term_has_merge_rows($conn, $college_id, $ay_id, $semester) {
    $mergeTable = synk_schedule_merge_table_name();
    if (!synk_table_exists($conn, $mergeTable)) {
        return false;
    }

    $sql = "
        SELECT 1
        FROM `{$mergeTable}` merge_map
        INNER JOIN tbl_prospectus_offering owner_offer
            ON owner_offer.offering_id = merge_map.owner_offering_id
        INNER JOIN tbl_program owner_program
            ON owner_program.program_id = owner_offer.program_id
        INNER JOIN tbl_prospectus_offering member_offer
            ON member_offer.offering_id = merge_map.member_offering_id
        INNER JOIN tbl_program member_program
            ON member_program.program_id = member_offer.program_id
        WHERE (
                owner_program.college_id = ?
            AND owner_offer.ay_id = ?
            AND owner_offer.semester = ?
        ) OR (
                member_program.college_id = ?
            AND member_offer.ay_id = ?
            AND member_offer.semester = ?
        )
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "iiiiii",
        $college_id,
        $ay_id,
        $semester,
        $college_id,
        $ay_id,
        $semester
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return $row !== null;
}

function load_saved_schedule_sets_for_scope($conn, $college_id, $program_id, $ay_id, $semester) {
    $workloadTable = saved_schedule_workload_table_name();
    $sql = "
        SELECT
            s.schedule_set_id,
            s.set_name,
            s.remarks,
            s.date_created,
            s.date_updated,
            COUNT(r.schedule_set_row_id) AS row_count,
            COUNT(DISTINCT r.offering_id) AS offering_count,
            (
                SELECT COUNT(*)
                FROM `{$workloadTable}` wr
                WHERE wr.schedule_set_id = s.schedule_set_id
            ) AS workload_row_count,
            (
                SELECT COUNT(DISTINCT wr.faculty_id)
                FROM `{$workloadTable}` wr
                WHERE wr.schedule_set_id = s.schedule_set_id
            ) AS workload_faculty_count
        FROM tbl_program_schedule_set s
        LEFT JOIN tbl_program_schedule_set_row r
            ON r.schedule_set_id = s.schedule_set_id
        WHERE s.college_id = ?
          AND s.program_id = ?
          AND s.ay_id = ?
          AND s.semester = ?
        GROUP BY
            s.schedule_set_id,
            s.set_name,
            s.remarks,
            s.date_created,
            s.date_updated
        ORDER BY s.date_updated DESC, s.schedule_set_id DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iiii", $college_id, $program_id, $ay_id, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'schedule_set_id' => (int)($row['schedule_set_id'] ?? 0),
            'set_name' => (string)($row['set_name'] ?? ''),
            'remarks' => (string)($row['remarks'] ?? ''),
            'date_created' => (string)($row['date_created'] ?? ''),
            'date_updated' => (string)($row['date_updated'] ?? ''),
            'row_count' => (int)($row['row_count'] ?? 0),
            'offering_count' => (int)($row['offering_count'] ?? 0),
            'workload_row_count' => (int)($row['workload_row_count'] ?? 0),
            'workload_faculty_count' => (int)($row['workload_faculty_count'] ?? 0)
        ];
    }

    $stmt->close();
    return $rows;
}

function load_saved_schedule_sets_for_college_term($conn, $college_id, $ay_id, $semester) {
    $workloadTable = saved_schedule_workload_table_name();
    $sql = "
        SELECT
            s.schedule_set_id,
            s.program_id,
            s.set_name,
            s.remarks,
            s.date_created,
            s.date_updated,
            COALESCE(NULLIF(TRIM(p.program_code), ''), '') AS program_code,
            COALESCE(NULLIF(TRIM(p.program_name), ''), '') AS program_name,
            COUNT(r.schedule_set_row_id) AS row_count,
            COUNT(DISTINCT r.offering_id) AS offering_count,
            COUNT(DISTINCT r.program_id) AS program_count,
            (
                SELECT COUNT(*)
                FROM `{$workloadTable}` wr
                WHERE wr.schedule_set_id = s.schedule_set_id
            ) AS workload_row_count,
            (
                SELECT COUNT(DISTINCT wr.faculty_id)
                FROM `{$workloadTable}` wr
                WHERE wr.schedule_set_id = s.schedule_set_id
            ) AS workload_faculty_count
        FROM tbl_program_schedule_set s
        LEFT JOIN tbl_program_schedule_set_row r
            ON r.schedule_set_id = s.schedule_set_id
        LEFT JOIN tbl_program p
            ON p.program_id = s.program_id
        WHERE s.college_id = ?
          AND s.ay_id = ?
          AND s.semester = ?
        GROUP BY
            s.schedule_set_id,
            s.program_id,
            s.set_name,
            s.remarks,
            s.date_created,
            s.date_updated,
            p.program_code,
            p.program_name
        ORDER BY
            CASE WHEN s.program_id = 0 THEN 0 ELSE 1 END ASC,
            s.date_updated DESC,
            s.schedule_set_id DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iii", $college_id, $ay_id, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $programId = (int)($row['program_id'] ?? 0);
        $scopeMeta = saved_schedule_scope_metadata(
            $programId,
            (string)($row['program_code'] ?? ''),
            (string)($row['program_name'] ?? '')
        );

        $rows[] = [
            'schedule_set_id' => (int)($row['schedule_set_id'] ?? 0),
            'program_id' => $programId,
            'set_name' => (string)($row['set_name'] ?? ''),
            'remarks' => (string)($row['remarks'] ?? ''),
            'date_created' => (string)($row['date_created'] ?? ''),
            'date_updated' => (string)($row['date_updated'] ?? ''),
            'row_count' => (int)($row['row_count'] ?? 0),
            'offering_count' => (int)($row['offering_count'] ?? 0),
            'program_count' => (int)($row['program_count'] ?? 0),
            'workload_row_count' => (int)($row['workload_row_count'] ?? 0),
            'workload_faculty_count' => (int)($row['workload_faculty_count'] ?? 0),
            'scope_key' => (string)($scopeMeta['scope_key'] ?? 'college'),
            'scope_label' => (string)($scopeMeta['scope_label'] ?? 'College term')
        ];
    }

    $stmt->close();
    return $rows;
}

function load_saved_schedule_set_record($conn, $schedule_set_id, $college_id, $program_id) {
    $sql = "
        SELECT
            schedule_set_id,
            college_id,
            program_id,
            ay_id,
            semester,
            set_name,
            remarks,
            date_created,
            date_updated
        FROM tbl_program_schedule_set
        WHERE schedule_set_id = ?
          AND college_id = ?
          AND program_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("iii", $schedule_set_id, $college_id, $program_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function load_saved_schedule_set_record_for_college($conn, $schedule_set_id, $college_id) {
    $sql = "
        SELECT
            schedule_set_id,
            college_id,
            program_id,
            ay_id,
            semester,
            set_name,
            remarks,
            date_created,
            date_updated
        FROM tbl_program_schedule_set
        WHERE schedule_set_id = ?
          AND college_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("ii", $schedule_set_id, $college_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function load_saved_schedule_set_by_name($conn, $college_id, $program_id, $ay_id, $semester, $set_name) {
    $sql = "
        SELECT
            schedule_set_id,
            set_name,
            remarks,
            date_created,
            date_updated
        FROM tbl_program_schedule_set
        WHERE college_id = ?
          AND program_id = ?
          AND ay_id = ?
          AND semester = ?
          AND set_name = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("iiiis", $college_id, $program_id, $ay_id, $semester, $set_name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function load_college_schedule_set_by_name($conn, $college_id, $ay_id, $semester, $set_name) {
    $sql = "
        SELECT
            schedule_set_id,
            set_name,
            remarks,
            date_created,
            date_updated
        FROM tbl_program_schedule_set
        WHERE college_id = ?
          AND program_id = 0
          AND ay_id = ?
          AND semester = ?
          AND set_name = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("iiis", $college_id, $ay_id, $semester, $set_name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function saved_schedule_set_name_exists_in_same_scope($conn, $college_id, $program_id, $ay_id, $semester, $set_name, $exclude_schedule_set_id = 0) {
    $sql = "
        SELECT 1
        FROM tbl_program_schedule_set
        WHERE college_id = ?
          AND program_id = ?
          AND ay_id = ?
          AND semester = ?
          AND set_name = ?
          AND schedule_set_id <> ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $excludeId = (int)$exclude_schedule_set_id;
    $stmt->bind_param(
        "iiiisi",
        $college_id,
        $program_id,
        $ay_id,
        $semester,
        $set_name,
        $excludeId
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();

    return $row !== null;
}

function load_saved_schedule_set_rows($conn, $schedule_set_id, $college_id, $program_id) {
    $sql = "
        SELECT
            r.schedule_set_row_id,
            r.schedule_set_id,
            r.offering_id,
            r.program_id,
            r.ps_id,
            r.section_id,
            r.schedule_type,
            r.schedule_status,
            r.block_order,
            r.room_id,
            r.days_json,
            r.time_start,
            r.time_end,
            r.subject_code_snapshot,
            r.subject_description_snapshot,
            r.section_name_snapshot,
            r.room_label_snapshot
        FROM tbl_program_schedule_set_row r
        INNER JOIN tbl_program_schedule_set s
            ON s.schedule_set_id = r.schedule_set_id
        WHERE r.schedule_set_id = ?
          AND s.college_id = ?
          AND s.program_id = ?
        ORDER BY
            r.offering_id ASC,
            r.block_order ASC,
            FIELD(r.schedule_type, 'LEC', 'LAB'),
            r.schedule_set_row_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iii", $schedule_set_id, $college_id, $program_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function load_saved_schedule_set_rows_for_college($conn, $schedule_set_id, $college_id) {
    $sql = "
        SELECT
            r.schedule_set_row_id,
            r.schedule_set_id,
            r.offering_id,
            r.program_id,
            r.ps_id,
            r.section_id,
            r.schedule_type,
            r.schedule_status,
            r.block_order,
            r.room_id,
            r.days_json,
            r.time_start,
            r.time_end,
            r.subject_code_snapshot,
            r.subject_description_snapshot,
            r.section_name_snapshot,
            r.room_label_snapshot
        FROM tbl_program_schedule_set_row r
        INNER JOIN tbl_program_schedule_set s
            ON s.schedule_set_id = r.schedule_set_id
        WHERE r.schedule_set_id = ?
          AND s.college_id = ?
        ORDER BY
            r.program_id ASC,
            r.offering_id ASC,
            r.block_order ASC,
            FIELD(r.schedule_type, 'LEC', 'LAB'),
            r.schedule_set_row_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("ii", $schedule_set_id, $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function delete_saved_schedule_workload_rows($conn, $schedule_set_id) {
    $table = saved_schedule_workload_table_name();
    $stmt = $conn->prepare("
        DELETE FROM `{$table}`
        WHERE schedule_set_id = ?
    ");
    if (!$stmt) {
        throw new RuntimeException("Failed to clear saved workload rows.");
    }

    $stmt->bind_param("i", $schedule_set_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException("Failed to clear saved workload rows.");
    }

    $deleted = max(0, (int)$stmt->affected_rows);
    $stmt->close();
    return $deleted;
}

function load_faculty_names_by_ids($conn, array $facultyIds) {
    $safeIds = array_values(array_filter(array_unique(array_map('intval', $facultyIds)), static function ($value) {
        return $value > 0;
    }));
    if (empty($safeIds)) {
        return [];
    }

    $facultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');
    $sql = "
        SELECT
            faculty_id,
            last_name,
            first_name,
            " . ($facultyHasExtName ? "COALESCE(ext_name, '') AS ext_name" : "'' AS ext_name") . "
        FROM tbl_faculty
        WHERE faculty_id IN (" . implode(',', $safeIds) . ")
    ";

    $res = $conn->query($sql);
    if (!$res) {
        return [];
    }

    $names = [];
    while ($row = $res->fetch_assoc()) {
        $facultyId = (int)($row['faculty_id'] ?? 0);
        if ($facultyId > 0) {
            $names[$facultyId] = format_faculty_display_name($row);
        }
    }

    return $names;
}

function load_workload_snapshot_rows_for_schedule_ids($conn, array $scheduleIds, $ay_id, $semester) {
    $safeIds = array_values(array_filter(array_unique(array_map('intval', $scheduleIds)), static function ($value) {
        return $value > 0;
    }));
    if (empty($safeIds)) {
        return [];
    }

    $facultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');
    $sql = "
        SELECT
            fw.schedule_id,
            fw.faculty_id,
            f.last_name,
            f.first_name,
            " . ($facultyHasExtName ? "COALESCE(f.ext_name, '') AS ext_name" : "'' AS ext_name") . "
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_faculty f
            ON f.faculty_id = fw.faculty_id
        WHERE fw.schedule_id IN (" . implode(',', $safeIds) . ")
          AND fw.ay_id = ?
          AND fw.semester = ?
        ORDER BY cs.offering_id ASC, FIELD(cs.schedule_type, 'LEC', 'LAB'), cs.time_start ASC, fw.faculty_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("ii", $ay_id, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'faculty_id' => (int)($row['faculty_id'] ?? 0),
            'faculty_name' => format_faculty_display_name($row)
        ];
    }

    $stmt->close();
    return $rows;
}

function load_saved_schedule_workload_rows_for_college($conn, $schedule_set_id, $college_id) {
    $table = saved_schedule_workload_table_name();
    $facultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');

    $sql = "
        SELECT
            wr.schedule_set_workload_row_id,
            wr.schedule_set_id,
            wr.schedule_set_row_id,
            wr.offering_id,
            wr.program_id,
            wr.schedule_type,
            wr.block_order,
            wr.faculty_id,
            wr.faculty_name_snapshot,
            wr.subject_code_snapshot,
            wr.section_name_snapshot,
            f.last_name,
            f.first_name,
            " . ($facultyHasExtName ? "COALESCE(f.ext_name, '') AS ext_name" : "'' AS ext_name") . "
        FROM `{$table}` wr
        INNER JOIN tbl_program_schedule_set s
            ON s.schedule_set_id = wr.schedule_set_id
        LEFT JOIN tbl_faculty f
            ON f.faculty_id = wr.faculty_id
        WHERE wr.schedule_set_id = ?
          AND s.college_id = ?
        ORDER BY
            wr.offering_id ASC,
            wr.block_order ASC,
            FIELD(wr.schedule_type, 'LEC', 'LAB'),
            wr.schedule_set_workload_row_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("ii", $schedule_set_id, $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $facultyId = (int)($row['faculty_id'] ?? 0);
        $facultyName = trim((string)($row['faculty_name_snapshot'] ?? ''));
        if (!empty($row['last_name']) || !empty($row['first_name'])) {
            $facultyName = format_faculty_display_name($row);
        }

        $rows[] = [
            'schedule_set_workload_row_id' => (int)($row['schedule_set_workload_row_id'] ?? 0),
            'schedule_set_id' => (int)($row['schedule_set_id'] ?? 0),
            'schedule_set_row_id' => (int)($row['schedule_set_row_id'] ?? 0),
            'offering_id' => (int)($row['offering_id'] ?? 0),
            'program_id' => (int)($row['program_id'] ?? 0),
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'block_order' => (int)($row['block_order'] ?? 0),
            'faculty_id' => $facultyId,
            'faculty_name' => $facultyName !== '' ? $facultyName : ('Faculty #' . $facultyId),
            'subject_code_snapshot' => (string)($row['subject_code_snapshot'] ?? ''),
            'section_name_snapshot' => (string)($row['section_name_snapshot'] ?? '')
        ];
    }

    $stmt->close();
    return $rows;
}

function schedule_set_signature_days($daysJson) {
    $days = normalize_days_array(json_decode((string)$daysJson, true));
    return implode(',', $days);
}

function saved_schedule_signature_from_set_rows(array $rows) {
    $items = [];
    foreach ($rows as $row) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        $items[] = [
            'program_id' => (int)($row['program_id'] ?? 0),
            'offering_id' => $offeringId,
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'room_id' => (int)($row['room_id'] ?? 0),
            'days' => schedule_set_signature_days((string)($row['days_json'] ?? '[]')),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? '')
        ];
    }

    usort($items, static function (array $left, array $right): int {
        return [
            $left['program_id'],
            $left['offering_id'],
            $left['schedule_type'],
            $left['room_id'],
            $left['days'],
            $left['time_start'],
            $left['time_end']
        ] <=> [
            $right['program_id'],
            $right['offering_id'],
            $right['schedule_type'],
            $right['room_id'],
            $right['days'],
            $right['time_start'],
            $right['time_end']
        ];
    });

    return empty($items) ? '' : sha1(json_encode($items));
}

function saved_schedule_signature_from_live_rows(array $rows) {
    $items = [];

    foreach ($rows as $row) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        $items[] = [
            'program_id' => (int)($row['program_id'] ?? 0),
            'offering_id' => $offeringId,
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'room_id' => (int)($row['room_id'] ?? 0),
            'days' => schedule_set_signature_days((string)($row['days_json'] ?? '[]')),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? '')
        ];
    }

    usort($items, static function (array $left, array $right): int {
        return [
            $left['program_id'],
            $left['offering_id'],
            $left['schedule_type'],
            $left['room_id'],
            $left['days'],
            $left['time_start'],
            $left['time_end']
        ] <=> [
            $right['program_id'],
            $right['offering_id'],
            $right['schedule_type'],
            $right['room_id'],
            $right['days'],
            $right['time_start'],
            $right['time_end']
        ];
    });

    return empty($items) ? '' : sha1(json_encode($items));
}

function saved_schedule_enrich_sets_with_live_state($conn, array $sets, $college_id, $ay_id, $semester) {
    $collegeLiveRows = null;
    $collegeLiveSignature = null;
    $programLiveSignatures = [];
    $activeSet = null;
    $workspaceState = load_saved_schedule_workspace_state($conn, $college_id, $ay_id, $semester);
    $lastLoadedSetId = (int)($workspaceState['last_loaded_schedule_set_id'] ?? 0);
    $liveMatchesLastLoaded = 0;

    foreach ($sets as $index => $set) {
        $scheduleSetId = (int)($set['schedule_set_id'] ?? 0);
        $programId = (int)($set['program_id'] ?? 0);
        $setRows = $scheduleSetId > 0 ? load_saved_schedule_set_rows_for_college($conn, $scheduleSetId, $college_id) : [];
        $setSignature = saved_schedule_signature_from_set_rows($setRows);

        if ($programId > 0) {
            if (!array_key_exists($programId, $programLiveSignatures)) {
                $programLiveRows = load_live_schedule_snapshot_rows_for_scope($conn, $programId, $ay_id, $semester, $college_id);
                $programLiveSignatures[$programId] = saved_schedule_signature_from_live_rows($programLiveRows);
            }
            $liveSignature = (string)$programLiveSignatures[$programId];
        } else {
            if ($collegeLiveRows === null) {
                $collegeLiveRows = load_live_schedule_snapshot_rows_for_college_term($conn, $ay_id, $semester, $college_id);
                $collegeLiveSignature = saved_schedule_signature_from_live_rows($collegeLiveRows);
            }
            $liveSignature = (string)$collegeLiveSignature;
        }

        $matchesLive = $setSignature !== '' && $setSignature === $liveSignature;
        $sets[$index]['matches_live'] = $matchesLive ? 1 : 0;
        $sets[$index]['is_last_loaded'] = ($lastLoadedSetId > 0 && $scheduleSetId === $lastLoadedSetId) ? 1 : 0;

        if ($matchesLive && $activeSet === null) {
            $activeSet = $sets[$index];
        } elseif (
            $matchesLive &&
            $activeSet !== null &&
            (int)($activeSet['program_id'] ?? 0) > 0 &&
            $programId <= 0
        ) {
            $activeSet = $sets[$index];
        }

        if ($lastLoadedSetId > 0 && $scheduleSetId === $lastLoadedSetId && $matchesLive) {
            $liveMatchesLastLoaded = 1;
        }
    }

    if ($collegeLiveRows === null) {
        $collegeLiveRows = load_live_schedule_snapshot_rows_for_college_term($conn, $ay_id, $semester, $college_id);
    }

    return [
        'sets' => $sets,
        'live_status' => [
            'set_count' => count($sets),
            'live_has_rows' => !empty($collegeLiveRows) ? 1 : 0,
            'active_schedule_set_id' => $activeSet ? (int)($activeSet['schedule_set_id'] ?? 0) : 0,
            'active_schedule_set_name' => $activeSet ? (string)($activeSet['set_name'] ?? '') : '',
            'active_scope_label' => $activeSet ? (string)($activeSet['scope_label'] ?? '') : '',
            'is_live_saved' => $activeSet ? 1 : 0,
            'last_loaded_schedule_set_id' => $lastLoadedSetId,
            'last_loaded_schedule_set_name' => (string)($workspaceState['last_loaded_schedule_set_name'] ?? ''),
            'last_loaded_scope_label' => (string)($workspaceState['last_loaded_scope_label'] ?? ''),
            'live_matches_last_loaded' => $liveMatchesLastLoaded
        ]
    ];
}

function delete_workload_assignments_for_offerings($conn, array $offeringIds, $ay_id, $semester) {
    $safeIds = array_values(array_filter(array_unique(array_map('intval', $offeringIds)), static function ($value) {
        return $value > 0;
    }));
    if (empty($safeIds)) {
        return 0;
    }

    $sql = "
        DELETE fw
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        WHERE cs.offering_id IN (" . implode(',', $safeIds) . ")
          AND fw.ay_id = ?
          AND fw.semester = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException("Failed to clear affected workload assignments.");
    }

    $stmt->bind_param("ii", $ay_id, $semester);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException("Failed to clear affected workload assignments.");
    }

    $deleted = max(0, (int)$stmt->affected_rows);
    $stmt->close();
    return $deleted;
}

function saved_workload_restore_label(array $row) {
    $subject = trim((string)($row['subject_code'] ?? $row['subject_code_snapshot'] ?? ''));
    $section = trim((string)($row['section_name'] ?? $row['section_name_snapshot'] ?? ''));
    $type = synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC'));

    $parts = array_filter([$subject, $section, $type], static function ($value) {
        return trim((string)$value) !== '';
    });

    return !empty($parts) ? implode(' / ', $parts) : 'Saved workload row';
}

function validate_saved_schedule_workload_restore($conn, array $workloadRows, array $normalizedRowsBySetRowId, array $affectedOfferingIds, $ay_id, $semester) {
    if (empty($workloadRows)) {
        return null;
    }

    $facultyIds = [];
    foreach ($workloadRows as $workloadRow) {
        $facultyId = (int)($workloadRow['faculty_id'] ?? 0);
        if ($facultyId > 0) {
            $facultyIds[] = $facultyId;
        }
    }

    $facultyNames = load_faculty_names_by_ids($conn, $facultyIds);
    $restoreRows = [];

    foreach ($workloadRows as $workloadRow) {
        $facultyId = (int)($workloadRow['faculty_id'] ?? 0);
        $setRowId = (int)($workloadRow['schedule_set_row_id'] ?? 0);

        if ($facultyId <= 0 || !isset($facultyNames[$facultyId])) {
            $snapshotName = trim((string)($workloadRow['faculty_name'] ?? 'Saved faculty'));
            return "Saved workload references <b>" . htmlspecialchars($snapshotName, ENT_QUOTES, 'UTF-8') . "</b>, but that faculty record is no longer available.";
        }

        if ($setRowId <= 0 || empty($normalizedRowsBySetRowId[$setRowId])) {
            return "Saved workload references a schedule row that is no longer available in this set.";
        }

        $scheduleRow = $normalizedRowsBySetRowId[$setRowId];
        $restoreRows[] = [
            'faculty_id' => $facultyId,
            'faculty_name' => $facultyNames[$facultyId],
            'schedule_set_row_id' => $setRowId,
            'offering_id' => (int)($scheduleRow['offering_id'] ?? 0),
            'schedule_type' => (string)($scheduleRow['schedule_type'] ?? 'LEC'),
            'subject_code' => (string)($scheduleRow['subject_code'] ?? $workloadRow['subject_code_snapshot'] ?? ''),
            'section_name' => (string)($scheduleRow['section_name'] ?? $workloadRow['section_name_snapshot'] ?? ''),
            'days' => (array)($scheduleRow['days'] ?? []),
            'time_start' => (string)($scheduleRow['time_start'] ?? ''),
            'time_end' => (string)($scheduleRow['time_end'] ?? '')
        ];
    }

    $count = count($restoreRows);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $left = $restoreRows[$i];
            $right = $restoreRows[$j];

            if ((int)$left['faculty_id'] !== (int)$right['faculty_id']) {
                continue;
            }

            if (!days_overlap((array)$left['days'], (array)$right['days'])) {
                continue;
            }

            if (!time_overlap((string)$left['time_start'], (string)$left['time_end'], (string)$right['time_start'], (string)$right['time_end'])) {
                continue;
            }

            return "<b>Faculty Workload Conflict</b><br><b>" .
                htmlspecialchars((string)$left['faculty_name'], ENT_QUOTES, 'UTF-8') .
                "</b> has overlapping saved workload rows: <b>" .
                htmlspecialchars(saved_workload_restore_label($left), ENT_QUOTES, 'UTF-8') .
                "</b> and <b>" .
                htmlspecialchars(saved_workload_restore_label($right), ENT_QUOTES, 'UTF-8') .
                "</b>.";
        }
    }

    $safeFacultyIds = array_values(array_filter(array_unique(array_map('intval', $facultyIds)), static function ($value) {
        return $value > 0;
    }));
    if (empty($safeFacultyIds)) {
        return null;
    }

    $safeAffectedIds = array_values(array_filter(array_unique(array_map('intval', $affectedOfferingIds)), static function ($value) {
        return $value > 0;
    }));

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            fw.faculty_id,
            cs.offering_id,
            cs.schedule_type,
            cs.schedule_status,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            sm.sub_code,
            sec.section_name
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        WHERE fw.faculty_id IN (" . implode(',', $safeFacultyIds) . ")
          AND fw.ay_id = ?
          AND fw.semester = ?
    ";

    if (!empty($safeAffectedIds)) {
        $sql .= " AND cs.offering_id NOT IN (" . implode(',', $safeAffectedIds) . ")";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return "Unable to validate saved workload faculty conflicts.";
    }

    $stmt->bind_param("ii", $ay_id, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $existingRows = [];
    while ($row = $res->fetch_assoc()) {
        $facultyId = (int)($row['faculty_id'] ?? 0);
        $existingRows[$facultyId][] = [
            'faculty_id' => $facultyId,
            'faculty_name' => $facultyNames[$facultyId] ?? ('Faculty #' . $facultyId),
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'subject_code' => (string)($row['sub_code'] ?? ''),
            'section_name' => (string)($row['section_name'] ?? ''),
            'days' => normalize_days_array(json_decode((string)($row['days_json'] ?? '[]'), true)),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? '')
        ];
    }
    $stmt->close();

    foreach ($restoreRows as $restoreRow) {
        $facultyId = (int)$restoreRow['faculty_id'];
        foreach ((array)($existingRows[$facultyId] ?? []) as $existingRow) {
            if (!days_overlap((array)$restoreRow['days'], (array)$existingRow['days'])) {
                continue;
            }

            if (!time_overlap((string)$restoreRow['time_start'], (string)$restoreRow['time_end'], (string)$existingRow['time_start'], (string)$existingRow['time_end'])) {
                continue;
            }

            return "<b>Faculty Workload Conflict</b><br><b>" .
                htmlspecialchars((string)$restoreRow['faculty_name'], ENT_QUOTES, 'UTF-8') .
                "</b> already has another assignment that overlaps with <b>" .
                htmlspecialchars(saved_workload_restore_label($restoreRow), ENT_QUOTES, 'UTF-8') .
                "</b>: <b>" .
                htmlspecialchars(saved_workload_restore_label($existingRow), ENT_QUOTES, 'UTF-8') .
                "</b>.";
        }
    }

    return null;
}

function load_live_schedule_snapshot_rows_for_scope($conn, $program_id, $ay_id, $semester, $college_id) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            cs.schedule_id,
            o.offering_id,
            o.program_id,
            o.ps_id,
            o.section_id,
            cs.schedule_type,
            cs.schedule_status,
            cs.room_id,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            sm.sub_code,
            sm.sub_description,
            sec.section_name,
            COALESCE(NULLIF(TRIM(r.room_code), ''), NULLIF(TRIM(r.room_name), ''), '') AS room_label
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        WHERE o.program_id = ?
          AND o.ay_id = ?
          AND o.semester = ?
          AND p.college_id = ?
          AND cs.schedule_type IN ('LEC', 'LAB')
        ORDER BY
            sec.section_name ASC,
            sm.sub_code ASC,
            o.offering_id ASC,
            FIELD(cs.schedule_type, 'LEC', 'LAB'),
            cs.time_start ASC,
            cs.schedule_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iiii", $program_id, $ay_id, $semester, $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['schedule_type'] = synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC'));
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function load_live_schedule_snapshot_rows_for_college_term($conn, $ay_id, $semester, $college_id) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            cs.schedule_id,
            o.offering_id,
            o.program_id,
            o.ps_id,
            o.section_id,
            cs.schedule_type,
            cs.schedule_status,
            cs.room_id,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            sm.sub_code,
            sm.sub_description,
            sec.section_name,
            COALESCE(NULLIF(TRIM(r.room_code), ''), NULLIF(TRIM(r.room_name), ''), '') AS room_label
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        WHERE o.ay_id = ?
          AND o.semester = ?
          AND p.college_id = ?
          AND cs.schedule_type IN ('LEC', 'LAB')
        ORDER BY
            p.program_code ASC,
            sec.section_name ASC,
            sm.sub_code ASC,
            o.offering_id ASC,
            FIELD(cs.schedule_type, 'LEC', 'LAB'),
            cs.time_start ASC,
            cs.schedule_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iii", $ay_id, $semester, $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['schedule_type'] = synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC'));
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function load_live_scheduled_offering_ids_for_scope($conn, $program_id, $ay_id, $semester, $college_id) {
    $sql = "
        SELECT DISTINCT cs.offering_id
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        WHERE o.program_id = ?
          AND o.ay_id = ?
          AND o.semester = ?
          AND p.college_id = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iiii", $program_id, $ay_id, $semester, $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId > 0) {
            $ids[] = $offeringId;
        }
    }

    $stmt->close();
    return $ids;
}

function load_live_scheduled_offering_ids_for_college_term($conn, $ay_id, $semester, $college_id) {
    $sql = "
        SELECT DISTINCT cs.offering_id
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        WHERE o.ay_id = ?
          AND o.semester = ?
          AND p.college_id = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iii", $ay_id, $semester, $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId > 0) {
            $ids[] = $offeringId;
        }
    }

    $stmt->close();
    return $ids;
}

function schedule_set_offering_label($subCode, $sectionName, $fallback = 'Offering') {
    $parts = [];

    $subCode = trim((string)$subCode);
    if ($subCode !== '') {
        $parts[] = $subCode;
    }

    $sectionName = trim((string)$sectionName);
    if ($sectionName !== '') {
        $parts[] = $sectionName;
    }

    $label = implode(' ', $parts);
    return $label !== '' ? $label : $fallback;
}

function schedule_set_row_label($row) {
    $type = synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC'));
    $typeLabel = $type === 'LAB' ? 'LAB' : 'LEC';
    $subject = trim((string)($row['subject_code_snapshot'] ?? ''));
    $section = trim((string)($row['section_name_snapshot'] ?? ''));

    $parts = [];
    if ($subject !== '') {
        $parts[] = $subject;
    }
    if ($section !== '') {
        $parts[] = $section;
    }
    $parts[] = $typeLabel;

    return implode(' / ', $parts);
}

function validate_saved_schedule_set_internal_conflicts($rows) {
    $count = count($rows);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $left = $rows[$i];
            $right = $rows[$j];

            if (!days_overlap($left['days'], $right['days'])) {
                continue;
            }

            if (!time_overlap($left['time_start'], $left['time_end'], $right['time_start'], $right['time_end'])) {
                continue;
            }

            $leftLabel = htmlspecialchars((string)($left['label'] ?? 'Saved schedule'), ENT_QUOTES, 'UTF-8');
            $rightLabel = htmlspecialchars((string)($right['label'] ?? 'Saved schedule'), ENT_QUOTES, 'UTF-8');

            if ((int)$left['offering_id'] === (int)$right['offering_id']) {
                return "<b>Saved Set Conflict</b><br><b>{$leftLabel}</b> overlaps with <b>{$rightLabel}</b> for the same offering.";
            }

            if ((int)$left['section_id'] > 0 && (int)$left['section_id'] === (int)$right['section_id']) {
                $sectionLabel = htmlspecialchars((string)($left['section_name'] ?? $right['section_name'] ?? 'Selected section'), ENT_QUOTES, 'UTF-8');
                return "<b>Saved Set Conflict</b><br>Section <b>{$sectionLabel}</b> has overlapping rows: <b>{$leftLabel}</b> and <b>{$rightLabel}</b>.";
            }

            if ((int)$left['room_id'] > 0 && (int)$left['room_id'] === (int)$right['room_id']) {
                $roomLabel = htmlspecialchars((string)($left['room_label'] ?? $right['room_label'] ?? 'Selected room'), ENT_QUOTES, 'UTF-8');
                return "<b>Saved Set Conflict</b><br>Room <b>{$roomLabel}</b> is double-booked by <b>{$leftLabel}</b> and <b>{$rightLabel}</b>.";
            }
        }
    }

    return null;
}

function find_conflict_excluding_offerings($conn, $ay_id, $semester, $excludedOfferingIds, $section_id, $section_name, $room_id, $days, $time_start, $time_end, $label) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $excludedOfferingIds = array_values(array_unique(array_map('intval', (array)$excludedOfferingIds)));

    $sql = "
        SELECT
            cs.room_id,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            o.section_id,
            sec.section_name
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        WHERE o.ay_id = ?
          AND o.semester = ?
          AND cs.time_start < ?
          AND cs.time_end > ?
    ";

    if (!empty($excludedOfferingIds)) {
        $sql .= " AND cs.offering_id NOT IN (" . implode(',', $excludedOfferingIds) . ")";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return "Unable to validate saved set conflicts.";
    }

    $stmt->bind_param("iiss", $ay_id, $semester, $time_end, $time_start);
    $stmt->execute();
    $res = $stmt->get_result();

    $safeLabel = htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
    $safeSectionName = htmlspecialchars((string)$section_name, ENT_QUOTES, 'UTF-8');

    while ($row = $res->fetch_assoc()) {
        $existingDays = normalize_days_array(json_decode((string)($row['days_json'] ?? '[]'), true));
        if (!days_overlap($days, $existingDays)) {
            continue;
        }

        $when = htmlspecialchars(
            days_fmt($existingDays) . ' ' . time_fmt((string)$row['time_start']) . ' - ' . time_fmt((string)$row['time_end']),
            ENT_QUOTES,
            'UTF-8'
        );

        if ((int)($row['section_id'] ?? 0) > 0 && (int)$row['section_id'] === (int)$section_id) {
            $stmt->close();
            return "<b>Section Conflict ({$safeLabel})</b><br>Section <b>{$safeSectionName}</b> already has a class<br>{$when}";
        }

        if ((int)($row['room_id'] ?? 0) > 0 && (int)$row['room_id'] === (int)$room_id) {
            $existingSection = htmlspecialchars((string)($row['section_name'] ?? 'another section'), ENT_QUOTES, 'UTF-8');
            $stmt->close();
            return "<b>Room Conflict ({$safeLabel})</b><br>Room is already used by Section <b>{$existingSection}</b><br>{$when}";
        }
    }

    $stmt->close();
    return null;
}

function room_access_table_exists($conn) {
    static $hasAccessTable = null;

    if ($hasAccessTable !== null) {
        return $hasAccessTable;
    }

    $q = $conn->query("SHOW TABLES LIKE 'tbl_room_college_access'");
    $hasAccessTable = $q && $q->num_rows > 0;
    return $hasAccessTable;
}

function room_is_accessible_in_term($conn, $room_id, $college_id, $ay_id, $semester) {
    return load_room_access_in_term($conn, $room_id, $college_id, $ay_id, $semester) !== null;
}

function normalize_room_type($type) {
    $value = strtolower(trim((string)$type));
    return in_array($value, ['lecture', 'laboratory', 'lec_lab'], true) ? $value : 'lecture';
}

function load_room_access_in_term($conn, $room_id, $college_id, $ay_id, $semester) {
    if (!room_access_table_exists($conn)) {
        return null;
    }

    $sql = "
        SELECT
            r.room_id,
            r.room_code,
            r.room_name,
            LOWER(COALESCE(r.room_type, 'lecture')) AS room_type,
            acc.access_type
        FROM tbl_room_college_access acc
        INNER JOIN tbl_rooms r ON r.room_id = acc.room_id
        WHERE acc.room_id = ?
          AND acc.college_id = ?
          AND acc.ay_id = ?
          AND acc.semester = ?
          AND r.status = 'active'
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $room_id, $college_id, $ay_id, $semester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $row['room_type'] = normalize_room_type($row['room_type'] ?? 'lecture');
    $row['access_type'] = strtolower((string)($row['access_type'] ?? 'owner'));
    return $row;
}

function room_type_allows_schedule($room_type, $schedule_type) {
    $roomType = normalize_room_type($room_type);
    $scheduleType = strtoupper(trim((string)$schedule_type));

    if ($scheduleType === 'LAB') {
        return in_array($roomType, ['laboratory', 'lec_lab'], true);
    }

    return in_array($roomType, ['lecture', 'lec_lab'], true);
}

function validate_room_for_schedule($conn, $room_id, $college_id, $ay_id, $semester, $schedule_type) {
    $room = load_room_access_in_term($conn, $room_id, $college_id, $ay_id, $semester);
    if (!$room) {
        respond("error", "Selected room is not available for this Academic Year and Semester.");
    }

    if (!room_type_allows_schedule($room['room_type'], $schedule_type)) {
        $roomLabel = trim((string)($room['room_code'] ?: $room['room_name'] ?: 'Selected room'));
        $typeLabel = strtoupper(trim((string)$schedule_type)) === 'LAB'
            ? 'laboratory'
            : 'lecture';
        respond("error", "{$roomLabel} is not compatible with {$typeLabel} scheduling.");
    }

    return $room;
}

function required_schedule_types_for_context($ctx) {
    return ((float)($ctx['lab_units'] ?? 0) > 0) ? ['LEC', 'LAB'] : ['LEC'];
}

function load_existing_schedule_rows($conn, $offering_id) {
    $sql = "
        SELECT
            cs.schedule_id,
            cs.schedule_type,
            cs.schedule_status,
            cs.schedule_group_id,
            cs.room_id,
            cs.days_json,
            cs.time_start,
            cs.time_end
        FROM tbl_class_schedule cs
        WHERE cs.offering_id = ?
        ORDER BY FIELD(cs.schedule_type, 'LEC', 'LAB'), cs.schedule_id
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $offering_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $type = strtoupper(trim((string)($row['schedule_type'] ?? '')));
        if (!in_array($type, ['LEC', 'LAB'], true)) {
            continue;
        }

        if (isset($rows[$type])) {
            respond("error", "Offering has multiple {$type} schedule rows. Resolve the existing schedule data first.");
        }

        $rows[$type] = $row;
    }

    $stmt->close();
    return $rows;
}

function load_schedule_block_rows($conn, $offering_id) {
    $sql = "
        SELECT
            cs.schedule_id,
            cs.schedule_type,
            cs.schedule_status,
            cs.schedule_group_id,
            cs.room_id,
            cs.days_json,
            cs.time_start,
            cs.time_end
        FROM tbl_class_schedule cs
        WHERE cs.offering_id = ?
          AND cs.schedule_type IN ('LEC', 'LAB')
        ORDER BY FIELD(cs.schedule_type, 'LEC', 'LAB'), cs.schedule_id
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $offering_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['schedule_id'] = (int)$row['schedule_id'];
        $row['schedule_group_id'] = isset($row['schedule_group_id']) ? (int)$row['schedule_group_id'] : 0;
        $row['room_id'] = (int)$row['room_id'];
        $row['schedule_type'] = synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC'));
        $row['days'] = normalize_days_array(json_decode((string)$row['days_json'], true));
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function load_schedule_block_rows_for_offerings($conn, array $offeringIds): array
{
    $rowsByOffering = [];
    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($normalizedIds)) {
        return $rowsByOffering;
    }

    $sql = "
        SELECT
            cs.offering_id,
            cs.schedule_id,
            cs.schedule_type,
            cs.schedule_status,
            cs.schedule_group_id,
            cs.room_id,
            cs.days_json,
            cs.time_start,
            cs.time_end
        FROM tbl_class_schedule cs
        WHERE cs.offering_id IN (" . implode(',', array_map('intval', $normalizedIds)) . ")
          AND cs.schedule_type IN ('LEC', 'LAB')
        ORDER BY cs.offering_id ASC, FIELD(cs.schedule_type, 'LEC', 'LAB'), cs.schedule_id ASC
    ";

    $res = $conn->query($sql);
    if (!($res instanceof mysqli_result)) {
        return $rowsByOffering;
    }

    while ($row = $res->fetch_assoc()) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        if (!isset($rowsByOffering[$offeringId])) {
            $rowsByOffering[$offeringId] = [];
        }

        $rowsByOffering[$offeringId][] = [
            'offering_id' => $offeringId,
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'schedule_group_id' => isset($row['schedule_group_id']) ? (int)$row['schedule_group_id'] : 0,
            'room_id' => (int)($row['room_id'] ?? 0),
            'schedule_status' => synk_schedule_status($row),
            'days_json' => (string)($row['days_json'] ?? '[]'),
            'days' => normalize_days_array(json_decode((string)($row['days_json'] ?? '[]'), true)),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? '')
        ];
    }

    return $rowsByOffering;
}

function load_effective_schedule_block_rows_by_offering($conn, array $offeringIds): array
{
    $rowsByOffering = [];
    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($normalizedIds)) {
        return $rowsByOffering;
    }

    $effectiveOwnerMap = synk_schedule_merge_load_effective_owner_map($conn, $normalizedIds);
    $sourceIds = synk_schedule_merge_collect_effective_owner_ids($effectiveOwnerMap);
    $rawRowsByOffering = load_schedule_block_rows_for_offerings($conn, $sourceIds);
    $sectionRows = synk_schedule_merge_load_section_rows_by_offering($conn, array_merge($normalizedIds, $sourceIds));

    foreach ($normalizedIds as $offeringId) {
        $rowsByOffering[$offeringId] = [];

        foreach (synk_schedule_merge_schedule_types() as $type) {
            $sourceOfferingId = (int)($effectiveOwnerMap[$offeringId][$type] ?? $offeringId);
            foreach ((array)($rawRowsByOffering[$sourceOfferingId] ?? []) as $row) {
                if (synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')) !== $type) {
                    continue;
                }

                $sourceRow = $sectionRows[$sourceOfferingId] ?? [];
                $sourceLabel = trim((string)($sourceRow['full_section'] ?? ''));
                if ($sourceLabel === '') {
                    $sourceLabel = trim((string)($sourceRow['section_name'] ?? ''));
                }

                $rowsByOffering[$offeringId][] = array_merge($row, [
                    'effective_offering_id' => $offeringId,
                    'source_offering_id' => $sourceOfferingId,
                    'is_inherited' => $sourceOfferingId !== $offeringId,
                    'is_editable' => $sourceOfferingId === $offeringId,
                    'source_label' => $sourceLabel
                ]);
            }
        }
    }

    return $rowsByOffering;
}

function mark_offering_schedule_state($conn, $offering_id, $state) {
    $allowed = ['active', 'pending'];
    $state = in_array($state, $allowed, true) ? $state : 'pending';

    $upd = $conn->prepare("
        UPDATE tbl_prospectus_offering
        SET status = ?
        WHERE offering_id = ?
          AND (status IS NULL OR status != 'locked')
    ");
    $upd->bind_param("si", $state, $offering_id);
    $ok = $upd->execute();
    $upd->close();
    return $ok;
}

function build_schedule_coverage_summary($ctx, $rows) {
    $required = synk_required_minutes_by_type(
        (float)($ctx['lec_units'] ?? 0),
        (float)($ctx['lab_units'] ?? 0),
        (float)($ctx['total_units'] ?? 0)
    );
    $scheduled = synk_sum_scheduled_minutes_by_type($rows);
    $requiredTypes = required_schedule_types_for_context($ctx);

    $hasAny = false;
    $isComplete = true;
    foreach ($requiredTypes as $type) {
        $requiredMinutes = (int)($required[$type] ?? 0);
        $scheduledMinutes = (int)($scheduled[$type] ?? 0);

        if ($scheduledMinutes > 0) {
            $hasAny = true;
        }

        if ($requiredMinutes > 0 && $scheduledMinutes < $requiredMinutes) {
            $isComplete = false;
        }
    }

    $hasPending = false;
    foreach ($rows as $row) { if (synk_schedule_status($row) !== 'scheduled') { $hasPending = true; break; } }
    if ($hasPending) {
        $status = 'tba';
    } elseif (!$hasAny) {
        $status = 'empty';
    } elseif ($isComplete) {
        $status = 'complete';
    } else {
        $status = 'partial';
    }

    return [
        'status' => $status,
        'required_minutes' => $required,
        'scheduled_minutes' => $scheduled
    ];
}

function load_workload_faculties_for_offering($conn, $offering_id, $ay_id, $semester) {
    $sql = "
        SELECT
            fw.faculty_id,
            CONCAT(
                f.last_name,
                ', ',
                f.first_name,
                CASE
                    WHEN COALESCE(f.ext_name, '') <> '' THEN CONCAT(' ', f.ext_name)
                    ELSE ''
                END
            ) AS faculty_name,
            COUNT(*) AS assigned_rows
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_faculty f
            ON f.faculty_id = fw.faculty_id
        WHERE cs.offering_id = ?
          AND fw.ay_id = ?
          AND fw.semester = ?
        GROUP BY fw.faculty_id, faculty_name
        ORDER BY faculty_name
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $offering_id, $ay_id, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $faculties = [];
    while ($row = $res->fetch_assoc()) {
        $faculties[] = [
            'faculty_id' => (int)$row['faculty_id'],
            'faculty_name' => (string)$row['faculty_name'],
            'assigned_rows' => (int)$row['assigned_rows']
        ];
    }

    $stmt->close();
    return $faculties;
}

function load_workload_faculty_map_for_offerings($conn, array $offering_ids, $ay_id, $semester) {
    $map = [];
    if (empty($offering_ids)) {
        return $map;
    }

    $safeIds = array_map('intval', array_values(array_unique($offering_ids)));
    $sql = "
        SELECT
            cs.offering_id,
            CONCAT(
                f.last_name,
                ', ',
                f.first_name,
                CASE
                    WHEN COALESCE(f.ext_name, '') <> '' THEN CONCAT(' ', f.ext_name)
                    ELSE ''
                END
            ) AS faculty_name
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_faculty f
            ON f.faculty_id = fw.faculty_id
        WHERE cs.offering_id IN (" . implode(',', $safeIds) . ")
          AND fw.ay_id = ?
          AND fw.semester = ?
        GROUP BY cs.offering_id, fw.faculty_id, faculty_name
        ORDER BY faculty_name ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $map;
    }

    $stmt->bind_param("ii", $ay_id, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $offeringId = (int)$row['offering_id'];
        if (!isset($map[$offeringId])) {
            $map[$offeringId] = [];
        }

        $map[$offeringId][] = (string)$row['faculty_name'];
    }

    $stmt->close();
    return $map;
}

function format_faculty_display_name(array $row) {
    $lastName = trim((string)($row['last_name'] ?? ''));
    $firstName = trim((string)($row['first_name'] ?? ''));
    $extName = trim((string)($row['ext_name'] ?? ''));

    $name = $lastName;
    if ($firstName !== '') {
        $name .= ($name !== '' ? ', ' : '') . $firstName;
    }

    if ($extName !== '') {
        $name .= ($name !== '' ? ' ' : '') . $extName;
    }

    $name = trim($name);
    if ($name !== '') {
        return $name;
    }

    $facultyId = (int)($row['faculty_id'] ?? 0);
    return $facultyId > 0 ? 'Faculty #' . $facultyId : 'Faculty';
}

function load_college_term_faculty_rows($conn, $college_id, $ay_id, $semester) {
    $assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
    $assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');
    $assignmentHasStatus = synk_table_has_column($conn, 'tbl_college_faculty', 'status');
    $facultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');

    $where = ['cf.college_id = ?'];
    $types = 'i';
    $params = [$college_id];

    if ($assignmentHasStatus) {
        $where[] = "LOWER(TRIM(COALESCE(cf.status, 'active'))) = 'active'";
    }

    if ($assignmentHasAyId) {
        $where[] = 'cf.ay_id = ?';
        $types .= 'i';
        $params[] = $ay_id;
    }

    if ($assignmentHasSemester) {
        $where[] = 'cf.semester = ?';
        $types .= 'i';
        $params[] = $semester;
    }

    $sql = "
        SELECT DISTINCT
            f.faculty_id,
            f.last_name,
            f.first_name,
            " . ($facultyHasExtName ? "COALESCE(f.ext_name, '') AS ext_name" : "'' AS ext_name") . "
        FROM tbl_college_faculty cf
        INNER JOIN tbl_faculty f
            ON f.faculty_id = cf.faculty_id
        WHERE " . implode("\n          AND ", $where) . "
        ORDER BY f.last_name ASC, f.first_name ASC, f.faculty_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    synk_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'faculty_id' => (int)($row['faculty_id'] ?? 0),
            'last_name' => (string)($row['last_name'] ?? ''),
            'first_name' => (string)($row['first_name'] ?? ''),
            'ext_name' => (string)($row['ext_name'] ?? ''),
            'faculty_name' => format_faculty_display_name($row)
        ];
    }

    $stmt->close();
    return $rows;
}

function load_faculty_schedule_counts_by_faculty($conn, array $facultyIds, $ay_id, $semester, $college_id) {
    $counts = [];
    $safeFacultyIds = array_map('intval', array_values(array_unique($facultyIds)));
    $safeFacultyIds = array_values(array_filter($safeFacultyIds, static function ($value) {
        return $value > 0;
    }));

    if (empty($safeFacultyIds)) {
        return $counts;
    }

    $classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
    $contextExpr = $classScheduleHasGroupId
        ? "CASE
                WHEN cs.schedule_group_id IS NOT NULL AND cs.schedule_group_id > 0
                    THEN CONCAT('group:', cs.schedule_group_id)
                ELSE CONCAT('offering:', cs.offering_id)
           END"
        : "CONCAT('offering:', cs.offering_id)";

    $sql = "
        SELECT
            fw.faculty_id,
            COUNT(DISTINCT fw.schedule_id) AS scheduled_block_count,
            COUNT(DISTINCT {$contextExpr}) AS scheduled_class_count
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        WHERE fw.faculty_id IN (" . implode(',', $safeFacultyIds) . ")
          AND fw.ay_id = ?
          AND fw.semester = ?
          AND p.college_id = ?
        GROUP BY fw.faculty_id
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $counts;
    }

    $stmt->bind_param("iii", $ay_id, $semester, $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $facultyId = (int)($row['faculty_id'] ?? 0);
        $counts[$facultyId] = [
            'scheduled_block_count' => (int)($row['scheduled_block_count'] ?? 0),
            'scheduled_class_count' => (int)($row['scheduled_class_count'] ?? 0)
        ];
    }

    $stmt->close();
    return $counts;
}

function load_college_term_faculty_record($conn, $college_id, $ay_id, $semester, $faculty_id) {
    $rows = load_college_term_faculty_rows($conn, $college_id, $ay_id, $semester);
    foreach ($rows as $row) {
        if ((int)($row['faculty_id'] ?? 0) === (int)$faculty_id) {
            return $row;
        }
    }

    return null;
}

function load_faculty_schedule_entries_for_term($conn, $faculty_id, $ay_id, $semester, $college_id, $currentOfferingId = 0) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
    $classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');

    $sql = "
        SELECT
            cs.schedule_id,
            cs.offering_id,
            " . ($classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id') . ",
            " . ($classScheduleHasType ? "cs.schedule_type AS schedule_type" : "'LEC' AS schedule_type") . ",
            sm.sub_code,
            sm.sub_description,
            sec.section_name,
            sec.full_section,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            COALESCE(NULLIF(TRIM(r.room_code), ''), NULLIF(TRIM(r.room_name), ''), 'TBA') AS room_label
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        WHERE fw.faculty_id = ?
          AND fw.ay_id = ?
          AND fw.semester = ?
          AND p.college_id = ?
        ORDER BY
            cs.time_start ASC,
            sec.section_name ASC,
            sm.sub_code ASC,
            " . ($classScheduleHasType ? "FIELD(cs.schedule_type, 'LEC', 'LAB')," : "") . "
            cs.schedule_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iiii", $faculty_id, $ay_id, $semester, $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $entries = [];
    while ($row = $res->fetch_assoc()) {
        $days = normalize_days_array(json_decode((string)($row['days_json'] ?? ''), true));
        $sectionLabel = trim((string)($row['full_section'] ?? ''));
        if ($sectionLabel === '') {
            $sectionLabel = trim((string)($row['section_name'] ?? ''));
        }

        $entries[] = [
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'offering_id' => (int)($row['offering_id'] ?? 0),
            'group_id' => (int)($row['group_id'] ?? 0),
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'subject_code' => (string)($row['sub_code'] ?? ''),
            'subject_description' => (string)($row['sub_description'] ?? ''),
            'section_label' => $sectionLabel,
            'room_label' => (string)($row['room_label'] ?? 'TBA'),
            'days' => $days,
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? ''),
            'is_current_offering' => (int)($row['offering_id'] ?? 0) === (int)$currentOfferingId
        ];
    }

    $stmt->close();
    return $entries;
}

function load_other_faculty_schedule_entries_for_term($conn, $selected_faculty_id, $ay_id, $semester, $college_id, $currentOfferingId = 0) {
    if ($ay_id <= 0 || $semester <= 0 || $college_id <= 0) {
        return [];
    }

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
    $classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');

    $sql = "
        SELECT
            cs.schedule_id,
            cs.offering_id,
            " . ($classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id') . ",
            " . ($classScheduleHasType ? "cs.schedule_type AS schedule_type" : "'LEC' AS schedule_type") . ",
            sm.sub_code,
            sm.sub_description,
            sec.section_name,
            sec.full_section,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            COALESCE(NULLIF(TRIM(r.room_code), ''), NULLIF(TRIM(r.room_name), ''), 'TBA') AS room_label,
            fw.faculty_id,
            CONCAT(
                f.last_name,
                ', ',
                f.first_name,
                CASE
                    WHEN COALESCE(f.ext_name, '') <> '' THEN CONCAT(' ', f.ext_name)
                    ELSE ''
                END
            ) AS faculty_name
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        INNER JOIN tbl_faculty f
            ON f.faculty_id = fw.faculty_id
        WHERE fw.ay_id = ?
          AND fw.semester = ?
          AND p.college_id = ?
        ORDER BY
            cs.time_start ASC,
            sec.section_name ASC,
            sm.sub_code ASC,
            " . ($classScheduleHasType ? "FIELD(cs.schedule_type, 'LEC', 'LAB')," : "") . "
            cs.schedule_id ASC,
            faculty_name ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iii", $ay_id, $semester, $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $entriesBySchedule = [];
    while ($row = $res->fetch_assoc()) {
        $scheduleId = (int)($row['schedule_id'] ?? 0);
        if ($scheduleId <= 0) {
            continue;
        }

        if (!isset($entriesBySchedule[$scheduleId])) {
            $days = normalize_days_array(json_decode((string)($row['days_json'] ?? ''), true));
            $sectionLabel = trim((string)($row['full_section'] ?? ''));
            if ($sectionLabel === '') {
                $sectionLabel = trim((string)($row['section_name'] ?? ''));
            }

            $entriesBySchedule[$scheduleId] = [
                'schedule_id' => $scheduleId,
                'offering_id' => (int)($row['offering_id'] ?? 0),
                'group_id' => (int)($row['group_id'] ?? 0),
                'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
                'subject_code' => (string)($row['sub_code'] ?? ''),
                'subject_description' => (string)($row['sub_description'] ?? ''),
                'section_label' => $sectionLabel,
                'room_label' => (string)($row['room_label'] ?? 'TBA'),
                'days' => $days,
                'time_start' => (string)($row['time_start'] ?? ''),
                'time_end' => (string)($row['time_end'] ?? ''),
                'is_current_offering' => (int)($row['offering_id'] ?? 0) === (int)$currentOfferingId,
                'is_other_faculty_assignment' => true,
                'owner_faculty_names' => [],
                '_owner_faculty_ids' => []
            ];
        }

        $facultyId = (int)($row['faculty_id'] ?? 0);
        $facultyName = trim((string)($row['faculty_name'] ?? ''));
        if ($facultyId > 0 && !in_array($facultyId, $entriesBySchedule[$scheduleId]['_owner_faculty_ids'], true)) {
            $entriesBySchedule[$scheduleId]['_owner_faculty_ids'][] = $facultyId;
        }
        if ($facultyName !== '' && !in_array($facultyName, $entriesBySchedule[$scheduleId]['owner_faculty_names'], true)) {
            $entriesBySchedule[$scheduleId]['owner_faculty_names'][] = $facultyName;
        }
    }

    $stmt->close();

    $entries = [];
    foreach ($entriesBySchedule as $entry) {
        if (in_array((int)$selected_faculty_id, $entry['_owner_faculty_ids'], true)) {
            continue;
        }

        unset($entry['_owner_faculty_ids']);
        if (!empty($entry['owner_faculty_names'])) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

function load_schedule_row_counts_for_offerings($conn, array $offering_ids) {
    $counts = [];
    if (empty($offering_ids)) {
        return $counts;
    }

    $safeIds = array_map('intval', array_values(array_unique($offering_ids)));
    $sql = "
        SELECT offering_id, COUNT(*) AS row_count
        FROM tbl_class_schedule
        WHERE offering_id IN (" . implode(',', $safeIds) . ")
        GROUP BY offering_id
    ";

    $res = $conn->query($sql);
    if (!($res instanceof mysqli_result)) {
        return $counts;
    }

    while ($row = $res->fetch_assoc()) {
        $counts[(int)$row['offering_id']] = (int)$row['row_count'];
    }

    return $counts;
}

function load_merge_context_rows_for_offerings($conn, array $offeringIds, $college_id) {
    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($normalizedIds)) {
        return [];
    }

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $idList = implode(',', array_map('intval', $normalizedIds));
    $sql = "
        SELECT
            o.offering_id,
            o.program_id,
            o.ps_id,
            o.section_id,
            o.ay_id,
            o.semester,
            o.status AS offering_status,
            ps.sub_id,
            sm.sub_code,
            sm.sub_description,
            sec.section_name,
            sec.full_section,
            COALESCE(NULLIF(TRIM(p.program_code), ''), '') AS program_code,
            ps.lec_units,
            ps.lab_units,
            ps.total_units
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        WHERE o.offering_id IN ({$idList})
          AND p.college_id = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        $rows[$offeringId] = [
            'offering_id' => $offeringId,
            'program_id' => (int)($row['program_id'] ?? 0),
            'ps_id' => (int)($row['ps_id'] ?? 0),
            'section_id' => (int)($row['section_id'] ?? 0),
            'ay_id' => (int)($row['ay_id'] ?? 0),
            'semester' => (int)($row['semester'] ?? 0),
            'offering_status' => strtolower(trim((string)($row['offering_status'] ?? 'pending'))),
            'sub_id' => (int)($row['sub_id'] ?? 0),
            'sub_code' => (string)($row['sub_code'] ?? ''),
            'sub_description' => (string)($row['sub_description'] ?? ''),
            'section_name' => (string)($row['section_name'] ?? ''),
            'full_section' => (string)($row['full_section'] ?? ''),
            'program_code' => (string)($row['program_code'] ?? ''),
            'lec_units' => (float)($row['lec_units'] ?? 0),
            'lab_units' => (float)($row['lab_units'] ?? 0),
            'total_units' => (float)($row['total_units'] ?? 0)
        ];
    }

    $stmt->close();
    return $rows;
}

function load_peer_section_rows_for_subject($conn, array $ctx, int $college_id): array
{
    $subjectId = (int)($ctx['sub_id'] ?? 0);
    $ayId = (int)($ctx['ay_id'] ?? 0);
    $semester = (int)($ctx['semester'] ?? 0);
    $programId = (int)($ctx['program_id'] ?? 0);
    $yearLevel = (int)($ctx['year_level'] ?? 0);

    if ($subjectId <= 0 || $ayId <= 0 || $semester <= 0 || $college_id <= 0 || $programId <= 0 || $yearLevel <= 0) {
        return [];
    }

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            o.offering_id,
            o.section_id,
            sec.section_name,
            sec.full_section,
            sec.year_level,
            p.program_code
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        WHERE o.ay_id = ?
          AND o.semester = ?
          AND p.college_id = ?
          AND o.program_id = ?
          AND ps.sub_id = ?
          AND sec.year_level = ?
        ORDER BY
            sec.section_name ASC,
            o.offering_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iiiiii", $ayId, $semester, $college_id, $programId, $subjectId, $yearLevel);
    $stmt->execute();
    $res = $stmt->get_result();

    $sections = [];
    while ($row = $res->fetch_assoc()) {
        $sectionId = (int)($row['section_id'] ?? 0);
        if ($sectionId <= 0 || isset($sections[$sectionId])) {
            continue;
        }

        $sections[$sectionId] = [
            'section_id' => $sectionId,
            'offering_id' => (int)($row['offering_id'] ?? 0),
            'section_name' => (string)($row['section_name'] ?? ''),
            'full_section' => (string)($row['full_section'] ?? ''),
            'year_level' => (int)($row['year_level'] ?? 0),
            'program_code' => (string)($row['program_code'] ?? '')
        ];
    }

    $stmt->close();
    return array_values($sections);
}

function load_term_offering_ids_by_section($conn, array $sectionIds, int $ayId, int $semester): array
{
    $rowsBySection = [];
    $safeSectionIds = array_values(array_unique(array_filter(array_map('intval', $sectionIds), static function ($value) {
        return $value > 0;
    })));

    foreach ($safeSectionIds as $sectionId) {
        $rowsBySection[$sectionId] = [];
    }

    if (empty($safeSectionIds) || $ayId <= 0 || $semester <= 0) {
        return $rowsBySection;
    }

    $sql = "
        SELECT section_id, offering_id
        FROM tbl_prospectus_offering
        WHERE ay_id = ?
          AND semester = ?
          AND section_id IN (" . implode(',', $safeSectionIds) . ")
        ORDER BY section_id ASC, offering_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $rowsBySection;
    }

    $stmt->bind_param("ii", $ayId, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $sectionId = (int)($row['section_id'] ?? 0);
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($sectionId > 0 && $offeringId > 0) {
            $rowsBySection[$sectionId][] = $offeringId;
        }
    }

    $stmt->close();
    return $rowsBySection;
}

function load_schedule_matrix_rows_by_offering($conn, array $offeringIds, int $ayId, int $semester): array
{
    $rowsByOffering = [];
    $safeOfferingIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($safeOfferingIds) || $ayId <= 0 || $semester <= 0) {
        return $rowsByOffering;
    }

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            cs.offering_id,
            cs.schedule_id,
            cs.schedule_type,
            cs.schedule_status,
            cs.time_start,
            cs.time_end,
            cs.days_json,
            sm.sub_code,
            sm.sub_description,
            COALESCE(
                NULLIF(TRIM(r.room_code), ''),
                NULLIF(TRIM(r.room_name), ''),
                'TBA'
            ) AS room_label
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        WHERE cs.offering_id IN (" . implode(',', array_map('intval', $safeOfferingIds)) . ")
          AND po.ay_id = ?
          AND po.semester = ?
          AND cs.schedule_type IN ('LEC', 'LAB')
        ORDER BY
            cs.offering_id ASC,
            cs.time_start ASC,
            sm.sub_code ASC,
            FIELD(cs.schedule_type, 'LEC', 'LAB'),
            cs.schedule_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $rowsByOffering;
    }

    $stmt->bind_param("ii", $ayId, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($res && ($row = $res->fetch_assoc())) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        if (!isset($rowsByOffering[$offeringId])) {
            $rowsByOffering[$offeringId] = [];
        }

        $rowsByOffering[$offeringId][] = [
            'offering_id' => $offeringId,
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? ''),
            'days' => normalize_days_array(json_decode((string)($row['days_json'] ?? '[]'), true)),
            'subject_code' => (string)($row['sub_code'] ?? ''),
            'subject_description' => (string)($row['sub_description'] ?? ''),
            'room_label' => (string)($row['room_label'] ?? 'TBA')
        ];
    }

    $stmt->close();
    return $rowsByOffering;
}

function load_effective_schedule_matrix_rows_by_offering($conn, array $offeringIds, int $ayId, int $semester): array
{
    $rowsByOffering = [];
    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($normalizedIds) || $ayId <= 0 || $semester <= 0) {
        return $rowsByOffering;
    }

    $effectiveOwnerMap = synk_schedule_merge_load_effective_owner_map($conn, $normalizedIds);
    $sourceIds = synk_schedule_merge_collect_effective_owner_ids($effectiveOwnerMap);
    $sourceRowsByOffering = load_schedule_matrix_rows_by_offering($conn, $sourceIds, $ayId, $semester);

    foreach ($normalizedIds as $offeringId) {
        $rowsByOffering[$offeringId] = [];

        foreach (synk_schedule_merge_schedule_types() as $type) {
            $sourceOfferingId = (int)($effectiveOwnerMap[$offeringId][$type] ?? $offeringId);
            foreach ((array)($sourceRowsByOffering[$sourceOfferingId] ?? []) as $row) {
                if (synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')) !== $type) {
                    continue;
                }

                $rowsByOffering[$offeringId][] = array_merge($row, [
                    'effective_offering_id' => $offeringId,
                    'source_offering_id' => $sourceOfferingId,
                    'is_inherited' => $sourceOfferingId !== $offeringId
                ]);
            }
        }
    }

    return $rowsByOffering;
}

function build_peer_section_schedule_matrix_payload($conn, array $ctx, int $college_id): array
{
    $peerSections = load_peer_section_rows_for_subject($conn, $ctx, $college_id);
    if (empty($peerSections)) {
        return [];
    }

    $sectionIds = array_map(static function (array $row): int {
        return (int)($row['section_id'] ?? 0);
    }, $peerSections);

    $sectionOfferingIds = load_term_offering_ids_by_section(
        $conn,
        $sectionIds,
        (int)($ctx['ay_id'] ?? 0),
        (int)($ctx['semester'] ?? 0)
    );

    $allOfferingIds = [];
    foreach ($sectionOfferingIds as $offeringIds) {
        $allOfferingIds = array_merge($allOfferingIds, $offeringIds);
    }

    $allOfferingIds = synk_schedule_merge_normalize_offering_ids($allOfferingIds);
    $rowsByOffering = load_effective_schedule_matrix_rows_by_offering(
        $conn,
        $allOfferingIds,
        (int)($ctx['ay_id'] ?? 0),
        (int)($ctx['semester'] ?? 0)
    );

    $sections = [];
    foreach ($peerSections as $sectionRow) {
        $sectionId = (int)($sectionRow['section_id'] ?? 0);
        $entries = [];

        foreach ($sectionOfferingIds[$sectionId] ?? [] as $sectionOfferingId) {
            foreach ($rowsByOffering[$sectionOfferingId] ?? [] as $entry) {
                $entries[] = $entry;
            }
        }

        usort($entries, static function (array $left, array $right): int {
            if ((string)($left['time_start'] ?? '') !== (string)($right['time_start'] ?? '')) {
                return strcmp((string)($left['time_start'] ?? ''), (string)($right['time_start'] ?? ''));
            }

            if ((string)($left['subject_code'] ?? '') !== (string)($right['subject_code'] ?? '')) {
                return strcmp((string)($left['subject_code'] ?? ''), (string)($right['subject_code'] ?? ''));
            }

            if ((string)($left['schedule_type'] ?? '') !== (string)($right['schedule_type'] ?? '')) {
                return strcmp((string)($left['schedule_type'] ?? ''), (string)($right['schedule_type'] ?? ''));
            }

            return (int)($left['schedule_id'] ?? 0) <=> (int)($right['schedule_id'] ?? 0);
        });

        $label = trim((string)($sectionRow['full_section'] ?? ''));
        if ($label === '') {
            $label = trim(
                (string)($sectionRow['program_code'] ?? '') . ' ' .
                (string)($sectionRow['section_name'] ?? '')
            );
        }

        $sections[] = [
            'section_id' => $sectionId,
            'offering_id' => (int)($sectionRow['offering_id'] ?? 0),
            'label' => $label,
            'full_section' => (string)($sectionRow['full_section'] ?? ''),
            'program_code' => (string)($sectionRow['program_code'] ?? ''),
            'section_name' => (string)($sectionRow['section_name'] ?? ''),
            'is_current' => $sectionId > 0 && $sectionId === (int)($ctx['section_id'] ?? 0),
            'entry_count' => count($entries),
            'entries' => $entries
        ];
    }

    return $sections;
}

function build_current_section_schedule_matrix_payload($conn, array $ctx): array
{
    $sectionId = (int)($ctx['section_id'] ?? 0);
    $ayId = (int)($ctx['ay_id'] ?? 0);
    $semester = (int)($ctx['semester'] ?? 0);
    $currentOfferingId = (int)($ctx['offering_id'] ?? 0);

    if ($sectionId <= 0 || $ayId <= 0 || $semester <= 0) {
        return [
            'entries' => [],
            'scheduled_subject_count' => 0
        ];
    }

    $sectionOfferingMap = load_term_offering_ids_by_section($conn, [$sectionId], $ayId, $semester);
    $sectionOfferingIds = synk_schedule_merge_normalize_offering_ids($sectionOfferingMap[$sectionId] ?? []);
    if (empty($sectionOfferingIds)) {
        return [
            'entries' => [],
            'scheduled_subject_count' => 0
        ];
    }

    $rowsByOffering = load_effective_schedule_matrix_rows_by_offering($conn, $sectionOfferingIds, $ayId, $semester);

    $entries = [];
    $scheduledSubjects = [];
    $seenEntryKeys = [];

    foreach ($sectionOfferingIds as $offeringId) {
        foreach ($rowsByOffering[$offeringId] ?? [] as $entry) {
            $scheduleId = (int)($entry['schedule_id'] ?? 0);
            $entryKey = $offeringId . ':' . $scheduleId;
            if ($scheduleId > 0 && isset($seenEntryKeys[$entryKey])) {
                continue;
            }

            if ($scheduleId > 0) {
                $seenEntryKeys[$entryKey] = true;
            }

            $entries[] = [
                'offering_id' => $offeringId,
                'effective_offering_id' => (int)($entry['source_offering_id'] ?? $offeringId),
                'schedule_id' => $scheduleId,
                'schedule_type' => synk_normalize_schedule_type((string)($entry['schedule_type'] ?? 'LEC')),
                'time_start' => (string)($entry['time_start'] ?? ''),
                'time_end' => (string)($entry['time_end'] ?? ''),
                'days' => normalize_days_array((array)($entry['days'] ?? [])),
                'subject_code' => (string)($entry['subject_code'] ?? ''),
                'subject_description' => (string)($entry['subject_description'] ?? ''),
                'room_label' => (string)($entry['room_label'] ?? 'TBA'),
                'is_current_offering' => $offeringId === $currentOfferingId
            ];

            $scheduledSubjects[$offeringId] = true;
        }
    }

    usort($entries, static function (array $left, array $right): int {
        if ((string)($left['time_start'] ?? '') !== (string)($right['time_start'] ?? '')) {
            return strcmp((string)($left['time_start'] ?? ''), (string)($right['time_start'] ?? ''));
        }

        if ((string)($left['subject_code'] ?? '') !== (string)($right['subject_code'] ?? '')) {
            return strcmp((string)($left['subject_code'] ?? ''), (string)($right['subject_code'] ?? ''));
        }

        if ((string)($left['schedule_type'] ?? '') !== (string)($right['schedule_type'] ?? '')) {
            return strcmp((string)($left['schedule_type'] ?? ''), (string)($right['schedule_type'] ?? ''));
        }

        return (int)($left['schedule_id'] ?? 0) <=> (int)($right['schedule_id'] ?? 0);
    });

    return [
        'entries' => $entries,
        'scheduled_subject_count' => count($scheduledSubjects)
    ];
}

function merge_required_minutes_signature(array $ctx): string {
    $required = synk_required_minutes_by_type(
        (float)($ctx['lec_units'] ?? 0),
        (float)($ctx['lab_units'] ?? 0),
        (float)($ctx['total_units'] ?? 0)
    );

    return (int)($required['LEC'] ?? 0) . '|' . (int)($required['LAB'] ?? 0);
}

function ensure_offering_not_merged_member($conn, $offering_id, $college_id) {
    $memberScopeMap = synk_schedule_merge_load_member_to_owner_scope_map($conn, [(int)$offering_id]);
    $ownerId = (int)($memberScopeMap[(int)$offering_id]['FULL'] ?? 0);
    if ($ownerId <= 0) {
        return;
    }

    $ownerCtx = load_context_any($conn, $ownerId, $college_id);
    $ownerLabel = trim((string)($ownerCtx['full_section'] ?? ''));
    if ($ownerLabel === '') {
        $ownerLabel = trim((string)($ownerCtx['section_name'] ?? 'this schedule owner'));
    }

    respond(
        "error",
        "This offering inherits schedule from <b>" . htmlspecialchars($ownerLabel, ENT_QUOTES, 'UTF-8') . "</b>. Unmerge it first before editing or clearing its schedule."
    );
}

function schedule_merge_offering_label(array $row): string
{
    $fullSection = trim((string)($row['full_section'] ?? ''));
    if ($fullSection !== '') {
        return $fullSection;
    }

    $sectionName = trim((string)($row['section_name'] ?? ''));
    if ($sectionName !== '') {
        $programCode = trim((string)($row['program_code'] ?? ''));
        return trim($programCode . ' ' . $sectionName);
    }

    return 'Offering ' . (int)($row['offering_id'] ?? 0);
}

function schedule_merge_scope_rows(array $rows, string $scope): array
{
    $normalizedScope = synk_schedule_merge_normalize_scope($scope);
    if ($normalizedScope === 'FULL') {
        return array_values($rows);
    }

    return array_values(array_filter($rows, static function (array $row) use ($normalizedScope): bool {
        return synk_normalize_schedule_type((string)($row['schedule_type'] ?? $row['type'] ?? 'LEC')) === $normalizedScope;
    }));
}

function schedule_merge_scope_summary(array $rows, string $scope): string
{
    $scopeRows = schedule_merge_scope_rows($rows, $scope);
    if (empty($scopeRows)) {
        return '';
    }

    $parts = [];
    foreach ($scopeRows as $row) {
        $days = implode('', (array)($row['days'] ?? []));
        $timeStart = (string)($row['time_start'] ?? '');
        $timeEnd = (string)($row['time_end'] ?? '');
        $timeLabel = ($timeStart !== '' && $timeEnd !== '')
            ? date('h:i A', strtotime($timeStart)) . ' - ' . date('h:i A', strtotime($timeEnd))
            : '';
        $type = synk_normalize_schedule_type((string)($row['schedule_type'] ?? $row['type'] ?? 'LEC'));

        $lineParts = array_filter([
            $days,
            $timeLabel,
            $type !== '' ? '(' . $type . ')' : ''
        ], static function ($value): bool {
            return trim((string)$value) !== '';
        });

        if (!empty($lineParts)) {
            $parts[] = implode(' ', $lineParts);
        }
    }

    return implode(' | ', array_slice($parts, 0, 3));
}

function schedule_merge_scope_required_minutes(array $ctx, string $scope): int
{
    $required = synk_required_minutes_by_type(
        (float)($ctx['lec_units'] ?? 0),
        (float)($ctx['lab_units'] ?? 0),
        (float)($ctx['total_units'] ?? 0)
    );

    $normalizedScope = synk_schedule_merge_normalize_scope($scope);
    if ($normalizedScope === 'FULL') {
        return (int)($required['LEC'] ?? 0) + (int)($required['LAB'] ?? 0);
    }

    return (int)($required[$normalizedScope] ?? 0);
}

function editable_schedule_types_for_context(array $ctx, array $mergeInfo = []): array
{
    $requiredTypes = required_schedule_types_for_context($ctx);
    $inheritedTypes = array_values(array_filter(array_map(static function ($type): string {
        return synk_normalize_schedule_type((string)$type);
    }, (array)($mergeInfo['inherited_types'] ?? []))));

    return array_values(array_filter($requiredTypes, static function (string $type) use ($inheritedTypes): bool {
        return !in_array($type, $inheritedTypes, true);
    }));
}

function has_outgoing_merge_dependencies(array $mergeInfo = []): bool
{
    if (!empty($mergeInfo['has_merged_members'])) {
        return true;
    }

    if (
        (int)($mergeInfo['owner_offering_id'] ?? 0) === (int)($mergeInfo['offering_id'] ?? 0) &&
        !empty($mergeInfo['member_offering_ids'])
    ) {
        return true;
    }

    $ownedByScope = (array)($mergeInfo['owned_member_ids_by_scope'] ?? []);
    foreach (['LEC', 'LAB'] as $scope) {
        if (!empty($ownedByScope[$scope])) {
            return true;
        }
    }

    return false;
}

function schedule_conflict_label_from_row(array $row, int $fallbackIndex = 0): string
{
    $explicit = trim((string)($row['label'] ?? ''));
    if ($explicit !== '') {
        return $explicit;
    }

    $type = synk_normalize_schedule_type((string)($row['schedule_type'] ?? $row['type'] ?? 'LEC'));
    $base = $type === 'LAB' ? 'Laboratory' : 'Lecture';
    if (!empty($row['is_inherited'])) {
        $sourceLabel = trim((string)($row['source_label'] ?? 'another offering'));
        return $base . ' inherited from ' . $sourceLabel;
    }

    return $fallbackIndex > 0 ? "{$base} {$fallbackIndex}" : $base;
}

function find_internal_schedule_conflict_message(array $rows): string
{
    $indexedRows = array_values($rows);

    for ($i = 0; $i < count($indexedRows); $i++) {
        for ($j = $i + 1; $j < count($indexedRows); $j++) {
            $left = $indexedRows[$i];
            $right = $indexedRows[$j];

            if (!days_overlap((array)($left['days'] ?? []), (array)($right['days'] ?? []))) {
                continue;
            }

            if (!time_overlap(
                (string)($left['time_start'] ?? ''),
                (string)($left['time_end'] ?? ''),
                (string)($right['time_start'] ?? ''),
                (string)($right['time_end'] ?? '')
            )) {
                continue;
            }

            return "<b>Internal Schedule Conflict</b><br><b>" .
                htmlspecialchars(schedule_conflict_label_from_row($left, $i + 1), ENT_QUOTES, 'UTF-8') .
                "</b> overlaps with <b>" .
                htmlspecialchars(schedule_conflict_label_from_row($right, $j + 1), ENT_QUOTES, 'UTF-8') .
                "</b>.";
        }
    }

    return "";
}

function load_other_faculty_workload_rows($conn, $faculty_id, $ay_id, $semester, $offering_id) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            cs.schedule_id,
            cs.schedule_type,
            cs.schedule_status,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            sm.sub_code,
            sec.section_name
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        WHERE fw.faculty_id = ?
          AND fw.ay_id = ?
          AND fw.semester = ?
          AND cs.offering_id <> ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $faculty_id, $ay_id, $semester, $offering_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['days'] = normalize_days_array(json_decode((string)$row['days_json'], true));
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function preview_room_validation_result($conn, $room_id, $college_id, $ay_id, $semester, $schedule_type) {
    $room = load_room_access_in_term($conn, $room_id, $college_id, $ay_id, $semester);
    if (!$room) {
        return [
            'room' => null,
            'message' => "Selected room is not available for this Academic Year and Semester."
        ];
    }

    if (!room_type_allows_schedule($room['room_type'], $schedule_type)) {
        $roomLabel = trim((string)($room['room_code'] ?: $room['room_name'] ?: 'Selected room'));
        $typeLabel = strtoupper(trim((string)$schedule_type)) === 'LAB'
            ? 'laboratory'
            : 'lecture';

        return [
            'room' => $room,
            'message' => "{$roomLabel} is not compatible with {$typeLabel} scheduling."
        ];
    }

    return [
        'room' => $room,
        'message' => null
    ];
}

function load_live_conflicts_for_schedule_block($conn, $ctx, $offering_id, $room_id, $days, $time_start, $time_end) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            cs.schedule_id,
            cs.room_id,
            cs.schedule_type,
            cs.schedule_status,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            o.section_id,
            sec.section_name,
            sm.sub_code,
            COALESCE(NULLIF(TRIM(r.room_code), ''), NULLIF(TRIM(r.room_name), ''), 'Selected room') AS room_label
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        WHERE o.ay_id = ?
          AND o.semester = ?
          AND cs.offering_id <> ?
          AND cs.time_start < ?
          AND cs.time_end > ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [
            'section_conflicts' => [],
            'room_conflicts' => []
        ];
    }

    $stmt->bind_param(
        "iiiss",
        $ctx['ay_id'],
        $ctx['semester'],
        $offering_id,
        $time_end,
        $time_start
    );
    $stmt->execute();
    $res = $stmt->get_result();

    $sectionConflicts = [];
    $roomConflicts = [];
    while ($row = $res->fetch_assoc()) {
        $existingDays = normalize_days_array(json_decode((string)($row['days_json'] ?? '[]'), true));
        if (!days_overlap($days, $existingDays)) {
            continue;
        }

        $entry = [
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'section_name' => (string)($row['section_name'] ?? ''),
            'subject_code' => (string)($row['sub_code'] ?? ''),
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'room_label' => (string)($row['room_label'] ?? 'Selected room'),
            'days' => $existingDays,
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? '')
        ];

        if ((int)($row['section_id'] ?? 0) > 0 && (int)$row['section_id'] === (int)($ctx['section_id'] ?? 0)) {
            $sectionConflicts[] = $entry;
        }

        if ((int)($row['room_id'] ?? 0) > 0 && (int)$row['room_id'] === (int)$room_id) {
            $roomConflicts[] = $entry;
        }
    }

    $stmt->close();

    return [
        'section_conflicts' => $sectionConflicts,
        'room_conflicts' => $roomConflicts
    ];
}

function load_faculty_conflicts_for_schedule_block($conn, $faculty_id, $ay_id, $semester, $offering_id, $days, $time_start, $time_end) {
    $conflicts = [];
    if ($faculty_id <= 0) {
        return $conflicts;
    }

    $otherSchedules = load_other_faculty_workload_rows($conn, $faculty_id, $ay_id, $semester, $offering_id);
    foreach ($otherSchedules as $other) {
        if (!days_overlap($days, $other['days'] ?? [])) {
            continue;
        }

        if (!time_overlap($time_start, $time_end, (string)($other['time_start'] ?? ''), (string)($other['time_end'] ?? ''))) {
            continue;
        }

        $conflicts[] = [
            'schedule_id' => (int)($other['schedule_id'] ?? 0),
            'subject_code' => (string)($other['sub_code'] ?? ''),
            'section_name' => (string)($other['section_name'] ?? ''),
            'schedule_type' => synk_normalize_schedule_type((string)($other['schedule_type'] ?? 'LEC')),
            'days' => normalize_days_array($other['days'] ?? []),
            'time_start' => (string)($other['time_start'] ?? ''),
            'time_end' => (string)($other['time_end'] ?? '')
        ];
    }

    return $conflicts;
}

function build_faculty_schedule_preview_payload($conn, array $ctx, $college_id, $offering_id, $faculty_id, $blocksRaw, array $policy) {
    $payload = [
        'draft_entries' => [],
        'preview_issues' => [],
        'draft_block_count' => 0,
        'draft_conflict_count' => 0,
        'draft_ready_count' => 0
    ];

    if (!is_array($blocksRaw) || empty($blocksRaw)) {
        return $payload;
    }

    $allowedTypes = required_schedule_types_for_context($ctx);
    $sequencePreview = ['LEC' => 0, 'LAB' => 0];
    $normalized = [];
    $sectionLabel = trim((string)($ctx['full_section'] ?? ''));
    if ($sectionLabel === '') {
        $sectionLabel = trim((string)($ctx['section_name'] ?? 'Section'));
    }

    foreach ($blocksRaw as $block) {
        if (!is_array($block)) {
            continue;
        }

        $rawType = strtoupper(trim((string)($block['type'] ?? '')));
        if (!in_array($rawType, ['LEC', 'LAB'], true)) {
            $payload['preview_issues'][] = "A draft block uses an invalid schedule type and was ignored.";
            continue;
        }

        $sequencePreview[$rawType]++;
        $label = block_label_for_response($rawType, $sequencePreview[$rawType]);

        if (!in_array($rawType, $allowedTypes, true)) {
            $payload['preview_issues'][] = "{$label} is not allowed for this subject.";
            continue;
        }

        $roomId = (int)($block['room_id'] ?? 0);
        $timeStart = normalize_time_input($block['time_start'] ?? '');
        $timeEnd = normalize_time_input($block['time_end'] ?? '');
        $days = normalize_days_array(json_decode((string)($block['days_json'] ?? ''), true));
        if (empty($days) && isset($block['days']) && is_array($block['days'])) {
            $days = normalize_days_array($block['days']);
        }

        if ($roomId <= 0 || !$timeStart || !$timeEnd || empty($days)) {
            $payload['preview_issues'][] = "{$label} is incomplete and was not included in the preview.";
            continue;
        }

        if ($timeEnd <= $timeStart) {
            $payload['preview_issues'][] = "{$label} must end later than it starts.";
            continue;
        }

        $policyIssue = schedule_policy_issue_message($days, $timeStart, $timeEnd, $label, $policy);
        if ($policyIssue !== null) {
            $payload['preview_issues'][] = $policyIssue;
            continue;
        }

        $roomValidation = preview_room_validation_result(
            $conn,
            $roomId,
            $college_id,
            (int)($ctx['ay_id'] ?? 0),
            (int)($ctx['semester'] ?? 0),
            $rawType
        );
        if ($roomValidation['message'] !== null) {
            $payload['preview_issues'][] = "{$label}: {$roomValidation['message']}";
            continue;
        }

        $room = $roomValidation['room'] ?? [];
        $roomLabel = trim((string)(($room['room_code'] ?? '') ?: ($room['room_name'] ?? '') ?: 'Selected room'));

        $normalized[] = [
            'schedule_id' => 0,
            'offering_id' => (int)$offering_id,
            'schedule_type' => $rawType,
            'room_id' => $roomId,
            'subject_code' => (string)($ctx['sub_code'] ?? ''),
            'subject_description' => (string)($ctx['sub_description'] ?? ''),
            'section_label' => $sectionLabel,
            'room_label' => $roomLabel,
            'days' => $days,
            'time_start' => $timeStart,
            'time_end' => $timeEnd,
            'is_preview_block' => true,
            'is_preview_conflict' => false,
            'preview_label' => $label,
            'preview_conflict_types' => [],
            'preview_conflict_details' => [],
            'preview_status_note' => 'Ready to save'
        ];
    }

    for ($i = 0; $i < count($normalized); $i++) {
        for ($j = $i + 1; $j < count($normalized); $j++) {
            if (!days_overlap($normalized[$i]['days'], $normalized[$j]['days'])) {
                continue;
            }

            if (!time_overlap($normalized[$i]['time_start'], $normalized[$i]['time_end'], $normalized[$j]['time_start'], $normalized[$j]['time_end'])) {
                continue;
            }

            $leftLabel = (string)($normalized[$i]['preview_label'] ?? 'Draft block');
            $rightLabel = (string)($normalized[$j]['preview_label'] ?? 'Draft block');
            $leftMessage = "Internal conflict with {$rightLabel}.";
            $rightMessage = "Internal conflict with {$leftLabel}.";

            if (!in_array('Internal Conflict', $normalized[$i]['preview_conflict_types'], true)) {
                $normalized[$i]['preview_conflict_types'][] = 'Internal Conflict';
            }
            if (!in_array($leftMessage, $normalized[$i]['preview_conflict_details'], true)) {
                $normalized[$i]['preview_conflict_details'][] = $leftMessage;
            }

            if (!in_array('Internal Conflict', $normalized[$j]['preview_conflict_types'], true)) {
                $normalized[$j]['preview_conflict_types'][] = 'Internal Conflict';
            }
            if (!in_array($rightMessage, $normalized[$j]['preview_conflict_details'], true)) {
                $normalized[$j]['preview_conflict_details'][] = $rightMessage;
            }
        }
    }

    foreach ($normalized as &$entry) {
        $sectionRoomConflicts = load_live_conflicts_for_schedule_block(
            $conn,
            $ctx,
            $offering_id,
            (int)($entry['room_id'] ?? 0),
            $entry['days'],
            (string)$entry['time_start'],
            (string)$entry['time_end']
        );

        if (!empty($sectionRoomConflicts['section_conflicts'])) {
            if (!in_array('Section Conflict', $entry['preview_conflict_types'], true)) {
                $entry['preview_conflict_types'][] = 'Section Conflict';
            }

            $sectionItem = $sectionRoomConflicts['section_conflicts'][0];
            $entry['preview_conflict_details'][] = "Section already has a class at " .
                days_fmt($sectionItem['days']) . " " .
                time_fmt($sectionItem['time_start']) . " - " .
                time_fmt($sectionItem['time_end']) . ".";
        }

        if (!empty($sectionRoomConflicts['room_conflicts'])) {
            if (!in_array('Room Conflict', $entry['preview_conflict_types'], true)) {
                $entry['preview_conflict_types'][] = 'Room Conflict';
            }

            $roomItem = $sectionRoomConflicts['room_conflicts'][0];
            $entry['preview_conflict_details'][] = trim((string)($entry['room_label'] ?? 'Selected room')) .
                " is already used by " .
                trim((string)($roomItem['section_name'] ?? 'another section')) .
                " at " .
                days_fmt($roomItem['days']) . " " .
                time_fmt($roomItem['time_start']) . " - " .
                time_fmt($roomItem['time_end']) . ".";
        }

        $facultyConflicts = load_faculty_conflicts_for_schedule_block(
            $conn,
            (int)$faculty_id,
            (int)($ctx['ay_id'] ?? 0),
            (int)($ctx['semester'] ?? 0),
            $offering_id,
            $entry['days'],
            (string)$entry['time_start'],
            (string)$entry['time_end']
        );
        if (!empty($facultyConflicts)) {
            if (!in_array('Faculty Conflict', $entry['preview_conflict_types'], true)) {
                $entry['preview_conflict_types'][] = 'Faculty Conflict';
            }

            $facultyItem = $facultyConflicts[0];
            $entry['preview_conflict_details'][] = "Faculty already handles " .
                trim((string)($facultyItem['subject_code'] ?? 'another class')) .
                " (" . trim((string)($facultyItem['section_name'] ?? 'Section')) .
                ", " . trim((string)($facultyItem['schedule_type'] ?? 'LEC')) .
                ") at " .
                days_fmt($facultyItem['days']) . " " .
                time_fmt($facultyItem['time_start']) . " - " .
                time_fmt($facultyItem['time_end']) . ".";
        }

        $entry['preview_conflict_details'] = array_values(array_unique($entry['preview_conflict_details']));
        $entry['is_preview_conflict'] = !empty($entry['preview_conflict_types']);
        $entry['preview_status_note'] = $entry['is_preview_conflict']
            ? 'Not ready to save'
            : 'Ready to save';
    }
    unset($entry);

    $payload['draft_entries'] = $normalized;
    $payload['draft_block_count'] = count($normalized);
    $payload['draft_conflict_count'] = count(array_filter($normalized, static function ($entry) {
        return !empty($entry['is_preview_conflict']);
    }));
    $payload['draft_ready_count'] = count($normalized) - $payload['draft_conflict_count'];

    return $payload;
}

function check_assigned_faculty_conflicts($conn, $ctx, $offering_id, $draftSchedules) {
    $assignedFaculties = load_workload_faculties_for_offering(
        $conn,
        $offering_id,
        (int)$ctx['ay_id'],
        (int)$ctx['semester']
    );

    if (empty($assignedFaculties)) {
        return;
    }

    foreach ($assignedFaculties as $faculty) {
        $otherSchedules = load_other_faculty_workload_rows(
            $conn,
            (int)$faculty['faculty_id'],
            (int)$ctx['ay_id'],
            (int)$ctx['semester'],
            $offering_id
        );

        $items = [];
        foreach ($draftSchedules as $draft) {
            foreach ($otherSchedules as $other) {
                if (!days_overlap($draft['days'], $other['days'])) {
                    continue;
                }

                if (!time_overlap($draft['start'], $draft['end'], $other['time_start'], $other['time_end'])) {
                    continue;
                }

                $draftWhen = days_fmt($draft['days']) . " " . time_fmt($draft['start']) . " - " . time_fmt($draft['end']);
                $otherWhen = days_fmt($other['days']) . " " . time_fmt($other['time_start']) . " - " . time_fmt($other['time_end']);

                $items[] = "<li><b>{$draft['type']}</b> {$draftWhen} conflicts with <b>" .
                    htmlspecialchars(strtoupper((string)$other['sub_code']), ENT_QUOTES, 'UTF-8') .
                    " (" . htmlspecialchars((string)$other['section_name'], ENT_QUOTES, 'UTF-8') .
                    ", " . htmlspecialchars((string)$other['schedule_type'], ENT_QUOTES, 'UTF-8') .
                    ")</b><br><small>{$otherWhen}</small></li>";

                if (count($items) >= 8) {
                    break 2;
                }
            }
        }

        if (!empty($items)) {
            $facultyName = htmlspecialchars((string)$faculty['faculty_name'], ENT_QUOTES, 'UTF-8');
            respond(
                "conflict",
                "<b>Faculty Conflict</b><br>This class is already assigned to <b>{$facultyName}</b> in Faculty Workload. Updating the schedule would overlap with the faculty's other assigned classes.<ul class='mb-0 mt-2'>" .
                implode('', $items) .
                "</ul>"
            );
        }
    }
}

function update_schedule_row($conn, $schedule_id, $schedule_type, $group_id, $room_id, $days_json, $time_start, $time_end) {
    if ($group_id === null) {
        $sql = "
            UPDATE tbl_class_schedule
            SET schedule_group_id = NULL,
                room_id = ?,
                days_json = ?,
                time_start = ?,
                time_end = ?
            WHERE schedule_id = ?
              AND schedule_type = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isssis", $room_id, $days_json, $time_start, $time_end, $schedule_id, $schedule_type);
    } else {
        $sql = "
            UPDATE tbl_class_schedule
            SET schedule_group_id = ?,
                room_id = ?,
                days_json = ?,
                time_start = ?,
                time_end = ?
            WHERE schedule_id = ?
              AND schedule_type = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iisssis", $group_id, $room_id, $days_json, $time_start, $time_end, $schedule_id, $schedule_type);
    }

    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        throw new RuntimeException("Failed to update {$schedule_type} schedule.");
    }
}

function insert_schedule_row($conn, $offering_id, $schedule_type, $group_id, $room_id, $days_json, $time_start, $time_end, $user_id) {
    if ($group_id === null) {
        $sql = "
            INSERT INTO tbl_class_schedule
            (offering_id, schedule_type, room_id, days_json, time_start, time_end, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isisssi", $offering_id, $schedule_type, $room_id, $days_json, $time_start, $time_end, $user_id);
    } else {
        $sql = "
            INSERT INTO tbl_class_schedule
            (offering_id, schedule_type, schedule_group_id, room_id, days_json, time_start, time_end, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isiisssi", $offering_id, $schedule_type, $group_id, $room_id, $days_json, $time_start, $time_end, $user_id);
    }

    $ok = $stmt->execute();
    $insertedId = (int)$conn->insert_id;
    $stmt->close();

    if (!$ok) {
        throw new RuntimeException("Failed to insert {$schedule_type} schedule.");
    }

    return $insertedId;
}

function update_schedule_block_row($conn, $schedule_id, $schedule_type, $group_id, $room_id, $days_json, $time_start, $time_end) {
    if ($group_id === null) {
        $sql = "
            UPDATE tbl_class_schedule
            SET schedule_type = ?,
                schedule_group_id = NULL,
                room_id = ?,
                days_json = ?,
                time_start = ?,
                time_end = ?
            WHERE schedule_id = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sisssi", $schedule_type, $room_id, $days_json, $time_start, $time_end, $schedule_id);
    } else {
        $sql = "
            UPDATE tbl_class_schedule
            SET schedule_type = ?,
                schedule_group_id = ?,
                room_id = ?,
                days_json = ?,
                time_start = ?,
                time_end = ?
            WHERE schedule_id = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("siisssi", $schedule_type, $group_id, $room_id, $days_json, $time_start, $time_end, $schedule_id);
    }

    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        throw new RuntimeException("Failed to update schedule block.");
    }
}

function insert_schedule_block_row($conn, $offering_id, $schedule_type, $group_id, $room_id, $days_json, $time_start, $time_end, $user_id) {
    insert_schedule_row(
        $conn,
        $offering_id,
        $schedule_type,
        $group_id,
        $room_id,
        $days_json,
        $time_start,
        $time_end,
        $user_id
    );
}

/* =====================================================
   STANDARD CONFLICT CHECK (SECTION + ROOM)
   - ignores same offering_id
   - only checks live synced offerings
===================================================== */
function check_conflict($conn, $ctx, $offering_id, $room_id, $time_start, $time_end, $days, $label) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');

    $sql = "
        SELECT cs.room_id, cs.days_json, cs.time_start, cs.time_end,
               o.section_id, sec.section_name
        FROM tbl_class_schedule cs
        JOIN tbl_prospectus_offering o ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        WHERE o.ay_id = ?
          AND o.semester = ?
          AND cs.offering_id <> ?
          AND cs.time_start < ?
          AND cs.time_end > ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "iiiss",
        $ctx['ay_id'],
        $ctx['semester'],
        $offering_id,
        $time_end,
        $time_start
    );
    $stmt->execute();
    $res = $stmt->get_result();

    while ($x = $res->fetch_assoc()) {
        $xDays = json_decode($x['days_json'], true);
        if (!days_overlap($days, $xDays)) {
            continue;
        }

        $when = days_fmt($xDays) . " " .
                time_fmt($x['time_start']) . " - " .
                time_fmt($x['time_end']);

        if ($x['section_id'] == $ctx['section_id']) {
            respond(
                "conflict",
                "<b>Section Conflict ({$label})</b><br>
                 Section <b>{$ctx['section_name']}</b> already has a class<br>
                 {$when}"
            );
        }

        if ($room_id > 0 && $x['room_id'] == $room_id) {
            respond(
                "conflict",
                "<b>Room Conflict ({$label})</b><br>
                 Room is already used by Section <b>{$x['section_name']}</b><br>
                 {$when}"
            );
        }
    }
}

function block_label_for_response($type, $sequence) {
    $base = strtoupper(trim((string)$type)) === 'LAB' ? 'Laboratory' : 'Lecture';
    return $base . ' ' . max(1, (int)$sequence);
}

/* =====================================================
   LOAD DYNAMIC SCHEDULE BLOCKS
===================================================== */
if (isset($_POST['load_schedule_blocks'])) {
    $offering_id = (int)($_POST['offering_id'] ?? 0);
    if (!$offering_id) {
        respond("error", "Missing offering reference.");
    }

    ensure_offering_not_merged_member($conn, $offering_id, $college_id);

    $ctx = load_context_live($conn, $offering_id, $college_id);
    if (!$ctx) {
        respond("error", "Offering is out of sync. Re-run Generate Offerings first.");
    }

    $mergeContext = synk_schedule_merge_load_display_context($conn, [$offering_id]);
    $mergeInfo = $mergeContext[$offering_id] ?? [];
    $rows = load_effective_schedule_block_rows_by_offering($conn, [$offering_id])[$offering_id] ?? [];
    $coverage = build_schedule_coverage_summary($ctx, $rows);
    $sequenceByType = ['LEC' => 0, 'LAB' => 0];
    $blocks = [];

    foreach ($rows as $row) {
        $type = synk_normalize_schedule_type((string)$row['schedule_type']);
        $sequenceByType[$type]++;
        $metrics = synk_schedule_block_metrics_from_row([
            'schedule_type' => $type,
            'days' => $row['days'],
            'time_start' => (string)$row['time_start'],
            'time_end' => (string)$row['time_end'],
            'lec_units' => (float)($ctx['lec_units'] ?? 0),
            'lab_units' => (float)($ctx['lab_units'] ?? 0),
            'total_units' => (float)($ctx['total_units'] ?? 0)
        ]);

        $blocks[] = [
            'schedule_id' => (int)$row['schedule_id'],
            'group_id' => (int)($row['schedule_group_id'] ?? 0),
            'schedule_status' => synk_schedule_status($row),
            'type' => $type,
            'label' => block_label_for_response($type, $sequenceByType[$type]),
            'room_id' => (int)$row['room_id'],
            'time_start' => (string)$row['time_start'],
            'time_end' => (string)$row['time_end'],
            'days' => $row['days'],
            'days_json' => json_encode($row['days']),
            'weekly_minutes' => $metrics['weekly_minutes'],
            'units' => $metrics['units'],
            'hours_lec' => $metrics['hours_lec'],
            'hours_lab' => $metrics['hours_lab'],
            'faculty_load' => $metrics['faculty_load'],
            'is_inherited' => !empty($row['is_inherited']),
            'is_editable' => !empty($row['is_editable']),
            'source_label' => (string)($row['source_label'] ?? '')
        ];
    }

    $assignedBlocks = load_workload_faculties_for_offering($conn, $offering_id, (int)$ctx['ay_id'], (int)$ctx['semester']);
    $hasAssignedBlocks = !empty($assignedBlocks);
    $needTable = 'tbl_faculty_need_workload_sched';
    require_once __DIR__ . '/faculty_need_helper.php';
    $needTable = synk_faculty_need_workload_table_name();
    if (!$hasAssignedBlocks && synk_table_exists($conn, $needTable)) {
        $needCheck = $conn->query('SELECT 1 FROM `' . $needTable . '` nw JOIN tbl_class_schedule cs ON cs.schedule_id=nw.schedule_id WHERE cs.offering_id=' . (int)$offering_id . ' LIMIT 1');
        $hasAssignedBlocks = $needCheck && $needCheck->num_rows > 0;
    }
    respond("ok", "Loaded schedule blocks.", [
        'tba_ready' => synk_schedule_tba_schema_ready($conn),
        'has_assigned_blocks' => $hasAssignedBlocks,
        'status_key' => $coverage['status'],
        'required_minutes' => $coverage['required_minutes'],
        'scheduled_minutes' => $coverage['scheduled_minutes'],
        'required_types' => required_schedule_types_for_context($ctx),
        'editable_types' => editable_schedule_types_for_context($ctx, $mergeInfo),
        'inherited_types' => array_values((array)($mergeInfo['inherited_types'] ?? [])),
        'program_code' => (string)($ctx['program_code'] ?? ''),
        'year_level' => (int)($ctx['year_level'] ?? 0),
        'year_label' => year_level_label((int)($ctx['year_level'] ?? 0)),
        'section_name' => (string)($ctx['section_name'] ?? ''),
        'full_section' => (string)($ctx['full_section'] ?? ''),
        'blocks' => $blocks
    ]);
}

/* =====================================================
   LOAD PEER SECTION SCHEDULE MATRIX
===================================================== */
if (isset($_POST['load_section_schedule_matrix'])) {
    $offering_id = (int)($_POST['offering_id'] ?? 0);
    if ($offering_id <= 0) {
        respond("error", "Missing offering reference.");
    }

    $ctx = load_context_live($conn, $offering_id, $college_id);
    if (!$ctx) {
        respond("error", "Offering is out of sync. Re-run Generate Offerings first.");
    }

    $sections = build_peer_section_schedule_matrix_payload($conn, $ctx, $college_id);
    if (empty($sections)) {
        respond("ok", "No peer sections were found for this subject.", [
            'current_section_id' => (int)($ctx['section_id'] ?? 0),
            'subject_code' => (string)($ctx['sub_code'] ?? ''),
            'subject_description' => (string)($ctx['sub_description'] ?? ''),
            'sections' => []
        ]);
    }

    respond("ok", "Loaded peer section matrix.", [
        'current_section_id' => (int)($ctx['section_id'] ?? 0),
        'subject_code' => (string)($ctx['sub_code'] ?? ''),
        'subject_description' => (string)($ctx['sub_description'] ?? ''),
        'sections' => $sections
    ]);
}

/* =====================================================
   LOAD CURRENT SECTION SCHEDULE MATRIX
===================================================== */
if (isset($_POST['load_current_section_schedule_matrix'])) {
    $offering_id = (int)($_POST['offering_id'] ?? 0);
    if ($offering_id <= 0) {
        respond("error", "Missing offering reference.");
    }

    $ctx = load_context_live($conn, $offering_id, $college_id);
    if (!$ctx) {
        respond("error", "Offering is out of sync. Re-run Generate Offerings first.");
    }

    $payload = build_current_section_schedule_matrix_payload($conn, $ctx);
    respond("ok", "Loaded current section schedule matrix.", [
        'current_offering_id' => (int)($ctx['offering_id'] ?? 0),
        'section_id' => (int)($ctx['section_id'] ?? 0),
        'section_name' => (string)($ctx['section_name'] ?? ''),
        'full_section' => (string)($ctx['full_section'] ?? ''),
        'program_code' => (string)($ctx['program_code'] ?? ''),
        'year_level' => (int)($ctx['year_level'] ?? 0),
        'year_label' => year_level_label((int)($ctx['year_level'] ?? 0)),
        'subject_code' => (string)($ctx['sub_code'] ?? ''),
        'subject_description' => (string)($ctx['sub_description'] ?? ''),
        'scheduled_block_count' => count($payload['entries']),
        'scheduled_subject_count' => (int)($payload['scheduled_subject_count'] ?? 0),
        'entries' => $payload['entries']
    ]);
}

/* =====================================================
   LOAD FACULTY OPTIONS FOR BLOCK SCHEDULER
===================================================== */
if (isset($_POST['load_schedule_faculty_options'])) {
    $offering_id = (int)($_POST['offering_id'] ?? 0);
    if ($offering_id <= 0) {
        respond("error", "Missing offering reference.");
    }

    $ctx = load_context_live($conn, $offering_id, $college_id);
    if (!$ctx) {
        respond("error", "Offering is out of sync. Re-run Generate Offerings first.");
    }

    $facultyRows = load_college_term_faculty_rows(
        $conn,
        $college_id,
        (int)$ctx['ay_id'],
        (int)$ctx['semester']
    );

    $facultyIds = array_map(static function ($row) {
        return (int)($row['faculty_id'] ?? 0);
    }, $facultyRows);

    $countMap = load_faculty_schedule_counts_by_faculty(
        $conn,
        $facultyIds,
        (int)$ctx['ay_id'],
        (int)$ctx['semester'],
        $college_id
    );

    $assignedFaculties = load_workload_faculties_for_offering(
        $conn,
        $offering_id,
        (int)$ctx['ay_id'],
        (int)$ctx['semester']
    );
    $assignedSet = [];
    foreach ($assignedFaculties as $faculty) {
        $facultyId = (int)($faculty['faculty_id'] ?? 0);
        if ($facultyId > 0) {
            $assignedSet[$facultyId] = true;
        }
    }

    $faculty = [];
    foreach ($facultyRows as $row) {
        $facultyId = (int)($row['faculty_id'] ?? 0);
        $counts = $countMap[$facultyId] ?? [
            'scheduled_block_count' => 0,
            'scheduled_class_count' => 0
        ];

        $faculty[] = [
            'faculty_id' => $facultyId,
            'faculty_name' => (string)($row['faculty_name'] ?? format_faculty_display_name($row)),
            'scheduled_block_count' => (int)($counts['scheduled_block_count'] ?? 0),
            'scheduled_class_count' => (int)($counts['scheduled_class_count'] ?? 0),
            'is_assigned' => isset($assignedSet[$facultyId])
        ];
    }

    respond("ok", "Loaded faculty options.", [
        'assigned_faculty_ids' => array_values(array_map('intval', array_keys($assignedSet))),
        'faculty' => $faculty
    ]);
}

/* =====================================================
   LOAD FACULTY SCHEDULE OVERVIEW FOR BLOCK SCHEDULER
===================================================== */
if (isset($_POST['load_faculty_schedule_overview'])) {
    $offering_id = (int)($_POST['offering_id'] ?? 0);
    $faculty_id = (int)($_POST['faculty_id'] ?? 0);
    $blocksRaw = $_POST['blocks'] ?? [];

    if ($offering_id <= 0 || $faculty_id <= 0) {
        respond("error", "Missing faculty schedule context.");
    }

    $ctx = load_context_live($conn, $offering_id, $college_id);
    if (!$ctx) {
        respond("error", "Offering is out of sync. Re-run Generate Offerings first.");
    }

    $facultyRow = load_college_term_faculty_record(
        $conn,
        $college_id,
        (int)$ctx['ay_id'],
        (int)$ctx['semester'],
        $faculty_id
    );

    if (!$facultyRow) {
        respond("error", "Selected faculty is not available under this college term.");
    }

    $countMap = load_faculty_schedule_counts_by_faculty(
        $conn,
        [$faculty_id],
        (int)$ctx['ay_id'],
        (int)$ctx['semester'],
        $college_id
    );
    $counts = $countMap[$faculty_id] ?? [
        'scheduled_block_count' => 0,
        'scheduled_class_count' => 0
    ];

    $assignedFaculties = load_workload_faculties_for_offering(
        $conn,
        $offering_id,
        (int)$ctx['ay_id'],
        (int)$ctx['semester']
    );
    $isAssigned = false;
    foreach ($assignedFaculties as $faculty) {
        if ((int)($faculty['faculty_id'] ?? 0) === $faculty_id) {
            $isAssigned = true;
            break;
        }
    }

    $entries = load_faculty_schedule_entries_for_term(
        $conn,
        $faculty_id,
        (int)$ctx['ay_id'],
        (int)$ctx['semester'],
        $college_id,
        $offering_id
    );
    $otherAssignedEntries = load_other_faculty_schedule_entries_for_term(
        $conn,
        $faculty_id,
        (int)$ctx['ay_id'],
        (int)$ctx['semester'],
        $college_id,
        $offering_id
    );
    $previewPayload = build_faculty_schedule_preview_payload(
        $conn,
        $ctx,
        $college_id,
        $offering_id,
        $faculty_id,
        $blocksRaw,
        $schedulePolicy
    );
    respond("ok", "Loaded faculty schedule overview.", [
        'faculty' => [
            'faculty_id' => $faculty_id,
            'faculty_name' => (string)($facultyRow['faculty_name'] ?? format_faculty_display_name($facultyRow)),
            'scheduled_block_count' => (int)($counts['scheduled_block_count'] ?? 0),
            'scheduled_class_count' => (int)($counts['scheduled_class_count'] ?? 0),
            'is_assigned' => $isAssigned
        ],
        'entries' => $entries,
        'other_assigned_entries' => $otherAssignedEntries,
        'draft_entries' => $previewPayload['draft_entries'],
        'preview_issues' => $previewPayload['preview_issues'],
        'draft_block_count' => (int)($previewPayload['draft_block_count'] ?? 0),
        'draft_conflict_count' => (int)($previewPayload['draft_conflict_count'] ?? 0),
        'draft_ready_count' => (int)($previewPayload['draft_ready_count'] ?? 0)
    ]);
}

/* =====================================================
   SAVE DYNAMIC SCHEDULE BLOCKS
===================================================== */
if (isset($_POST['save_schedule_blocks'])) {
    $offering_id = (int)($_POST['offering_id'] ?? 0);
    $blocksRaw = $_POST['blocks'] ?? null;

    if ($offering_id <= 0 || !is_array($blocksRaw)) {
        respond("error", "Invalid schedule payload.");
    }

    ensure_offering_not_merged_member($conn, $offering_id, $college_id);

    $ctx = load_context_live($conn, $offering_id, $college_id);
    if (!$ctx) {
        respond("error", "Offering is out of sync. Re-run Generate Offerings first.");
    }

    $mergeContext = synk_schedule_merge_load_display_context($conn, [$offering_id]);
    $mergeInfo = $mergeContext[$offering_id] ?? [];
    $tbaExistingRows = load_schedule_block_rows($conn, $offering_id);
    $usesTba = false;
    foreach (array_merge($blocksRaw, $tbaExistingRows) as $tbaBlock) {
        if (is_array($tbaBlock) && isset($tbaBlock['schedule_status']) && $tbaBlock['schedule_status'] !== 'scheduled') { $usesTba = true; }
    }
    if ($usesTba) {
        require_once __DIR__ . '/schedule_tba_save_helper.php';
        try { $tbaResult = synk_schedule_save_tba_blocks($conn, $ctx, $college_id, $blocksRaw, $mergeInfo, $schedulePolicy); }
        catch (SynkScheduleTbaConflictException $e) { respond('conflict', $e->getMessage()); }
        catch (DomainException $e) { respond('error', $e->getMessage()); }
        catch (Throwable $e) { respond('error', 'Unable to save the pending schedule. No changes were applied.'); }
        respond('ok', 'Schedule blocks saved.', $tbaResult);
    }

    if (has_outgoing_merge_dependencies($mergeInfo)) {
        respond(
            "error",
            "This offering is currently used as a merge source. Manage the merge links first before changing its saved schedule blocks."
        );
    }

    $assignedFaculties = load_workload_faculties_for_offering(
        $conn,
        $offering_id,
        (int)$ctx['ay_id'],
        (int)$ctx['semester']
    );

    if (!empty($assignedFaculties)) {
        $names = array_map(static function ($faculty) {
            return htmlspecialchars((string)$faculty['faculty_name'], ENT_QUOTES, 'UTF-8');
        }, $assignedFaculties);

        respond(
            "error",
            "This schedule cannot be changed because it is already assigned in Faculty Workload to <b>" .
            implode('</b>, <b>', $names) .
            "</b>. Remove the workload assignment first."
        );
    }

    $existingRows = load_schedule_block_rows($conn, $offering_id);
    $existingById = [];
    $existingGroupId = null;

    foreach ($existingRows as $row) {
        $existingById[(int)$row['schedule_id']] = $row;
        $candidateGroupId = (int)($row['schedule_group_id'] ?? 0);
        if ($candidateGroupId > 0 && $existingGroupId === null) {
            $existingGroupId = $candidateGroupId;
        }
    }

    $allowedTypes = editable_schedule_types_for_context($ctx, $mergeInfo);
    $normalized = [];
    $sequencePreview = ['LEC' => 0, 'LAB' => 0];

    foreach ($blocksRaw as $blockIndex => $block) {
        if (!is_array($block)) {
            continue;
        }

        $isInheritedBlock = filter_var($block['is_inherited'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($isInheritedBlock) {
            continue;
        }

        $rawType = strtoupper(trim((string)($block['type'] ?? '')));
        if (!in_array($rawType, ['LEC', 'LAB'], true)) {
            respond("error", "Invalid schedule block type.");
        }

        if (!in_array($rawType, $allowedTypes, true)) {
            respond("error", "This subject does not allow {$rawType} schedule blocks.");
        }

        $scheduleId = (int)($block['schedule_id'] ?? 0);
        if ($scheduleId > 0 && !isset($existingById[$scheduleId])) {
            respond("error", "A schedule block could not be matched to this offering.");
        }

        $roomId = (int)($block['room_id'] ?? 0);
        $timeStart = normalize_time_input($block['time_start'] ?? '');
        $timeEnd = normalize_time_input($block['time_end'] ?? '');
        $days = normalize_days_array(json_decode((string)($block['days_json'] ?? ''), true));

        if (empty($days) && isset($block['days']) && is_array($block['days'])) {
            $days = normalize_days_array($block['days']);
        }

        $sequencePreview[$rawType]++;
        $label = block_label_for_response($rawType, $sequencePreview[$rawType]);

        if ($roomId <= 0 || !$timeStart || !$timeEnd || empty($days)) {
            respond("error", "{$label} is incomplete.");
        }

        if ($timeEnd <= $timeStart) {
            respond("error", "{$label} must end later than it starts.");
        }

        validate_schedule_policy($days, $timeStart, $timeEnd, $label, $schedulePolicy);
        validate_room_for_schedule($conn, $roomId, $college_id, (int)$ctx['ay_id'], (int)$ctx['semester'], $rawType);

        $normalized[] = [
            'schedule_id' => $scheduleId,
            'type' => $rawType,
            'label' => $label,
            'room_id' => $roomId,
            'time_start' => $timeStart,
            'time_end' => $timeEnd,
            'days' => $days,
            'days_json' => json_encode($days)
        ];
    }

    $effectiveRows = load_effective_schedule_block_rows_by_offering($conn, [$offering_id]);
    $inheritedRows = array_values(array_filter(
        (array)($effectiveRows[$offering_id] ?? []),
        static function (array $row): bool {
            return !empty($row['is_inherited']);
        }
    ));

    if (empty($normalized) && empty($inheritedRows)) {
        respond("error", "Add at least one lecture or laboratory block.");
    }

    $internalConflictMessage = find_internal_schedule_conflict_message(array_merge($normalized, $inheritedRows));
    if ($internalConflictMessage !== "") {
        respond("conflict", $internalConflictMessage);
    }

    foreach ($normalized as $block) {
        check_conflict(
            $conn,
            $ctx,
            $offering_id,
            (int)$block['room_id'],
            (string)$block['time_start'],
            (string)$block['time_end'],
            $block['days'],
            (string)$block['label']
        );
    }

    $group_id = count($normalized) > 1 ? ($existingGroupId ?: random_int(100000, 2147483647)) : null;
    $keepIds = [];

    $conn->begin_transaction();
    try {
        foreach ($normalized as $block) {
            if ((int)$block['schedule_id'] > 0) {
                $keepIds[(int)$block['schedule_id']] = true;
                update_schedule_block_row(
                    $conn,
                    (int)$block['schedule_id'],
                    (string)$block['type'],
                    $group_id,
                    (int)$block['room_id'],
                    (string)$block['days_json'],
                    (string)$block['time_start'],
                    (string)$block['time_end']
                );
                continue;
            }

            insert_schedule_block_row(
                $conn,
                $offering_id,
                (string)$block['type'],
                $group_id,
                (int)$block['room_id'],
                (string)$block['days_json'],
                (string)$block['time_start'],
                (string)$block['time_end'],
                (int)$_SESSION['user_id']
            );
        }

        $staleIds = [];
        foreach ($existingRows as $row) {
            $scheduleId = (int)$row['schedule_id'];
            if (!isset($keepIds[$scheduleId])) {
                $staleIds[] = $scheduleId;
            }
        }

        if (!empty($staleIds)) {
            $idList = implode(',', array_map('intval', $staleIds));
            $del = $conn->prepare("
                DELETE FROM tbl_class_schedule
                WHERE offering_id = ?
                  AND schedule_id IN ({$idList})
            ");
            $del->bind_param("i", $offering_id);
            if (!$del->execute()) {
                throw new RuntimeException("Failed to remove stale schedule blocks.");
            }
            $del->close();
        }

        $coverage = build_schedule_coverage_summary($ctx, array_merge($normalized, $inheritedRows));
        $offeringState = $coverage['status'] === 'complete' ? 'active' : 'pending';
        if (!mark_offering_schedule_state($conn, $offering_id, $offeringState)) {
            throw new RuntimeException("Failed to update offering status.");
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond("error", "Failed to save schedule blocks.");
    }

    respond("ok", "Schedule blocks saved.", [
        'status_key' => $coverage['status'],
        'required_minutes' => $coverage['required_minutes'],
        'scheduled_minutes' => $coverage['scheduled_minutes']
    ]);
}

/* =====================================================
   LOAD DUAL SCHEDULE (EDIT MODE)
===================================================== */
if (isset($_POST['load_dual_schedule'])) {
    $offering_id = (int)($_POST['offering_id'] ?? 0);
    if (!$offering_id) {
        respond("error", "Missing offering reference.");
    }

    ensure_offering_not_merged_member($conn, $offering_id, $college_id);

    $ctx = load_context_live($conn, $offering_id, $college_id);
    if (!$ctx) {
        respond("error", "Offering is out of sync. Re-run Generate Offerings first.");
    }

    if (!in_array('LAB', required_schedule_types_for_context($ctx), true)) {
        respond("error", "This subject does not require a laboratory schedule.");
    }

    $rows = load_existing_schedule_rows($conn, $offering_id);
    if (empty($rows)) {
        respond("error", "No saved lecture/laboratory schedule found for this offering.");
    }

    $data = ["group_id" => null, "LEC" => null, "LAB" => null];
    foreach ($rows as $r) {
        $data['group_id'] = $r['schedule_group_id'];
        $data[$r['schedule_type']] = [
            "room_id"    => $r['room_id'],
            "time_start" => $r['time_start'],
            "time_end"   => $r['time_end'],
            "days"       => json_decode($r['days_json'], true)
        ];
    }

    respond("ok", "Loaded", $data);
}

/* =====================================================
   CLEAR ALL SCHEDULES IN CURRENT COLLEGE SCOPE
===================================================== */
if (isset($_POST['clear_all_college_schedules'])) {
    $ay_id = (int)($_POST['ay_id'] ?? 0);
    $semester = (int)($_POST['semester'] ?? 0);

    if (!$ay_id || !in_array($semester, [1, 2, 3], true)) {
        respond("error", "Select Academic Year and Semester first.");
    }

    $offerings = load_scoped_offerings_for_college_term($conn, $ay_id, $semester, $college_id);
    if (empty($offerings)) {
        respond("ok", "No class offerings found for the selected college term.", [
            "scoped_offering_count" => 0,
            "clearable_offering_count" => 0,
            "cleared_offering_count" => 0,
            "deleted_schedule_row_count" => 0,
            "reset_offering_count" => 0,
            "skipped_count" => 0,
            "skipped" => []
        ]);
    }

    $offeringIds = array_map(static function ($offering) {
        return (int)$offering['offering_id'];
    }, $offerings);

    $scheduleCounts = load_schedule_row_counts_for_offerings($conn, $offeringIds);
    if (synk_schedule_tba_has_assigned_need($conn,$offeringIds,$ay_id,$semester)) {
        respond('error','Remove pending faculty-need assignments before clearing this schedule workspace.');
    }
    $mergeContext = synk_schedule_merge_load_display_context($conn, $offeringIds);
    $offeringsById = [];
    $groups = [];

    foreach ($offerings as $offering) {
        $offeringId = (int)$offering['offering_id'];
        $offeringsById[$offeringId] = $offering;
        $ownerId = (int)($mergeContext[$offeringId]['owner_offering_id'] ?? $offeringId);
        if (!isset($groups[$ownerId])) {
            $groups[$ownerId] = [];
        }
        $groups[$ownerId][] = $offeringId;
    }

    $clearableIds = [];
    $skipped = [];

    $deletedRows = 0;
    $resetOfferings = 0;
    $clearedOfferingCount = 0;
    $removedWorkloadRowCount = 0;

    foreach ($groups as $ownerId => $groupOfferingIds) {
        $groupOfferingIds = synk_schedule_merge_normalize_offering_ids($groupOfferingIds);
        $skipReason = "";

        foreach ($groupOfferingIds as $offeringId) {
            $offering = $offeringsById[$offeringId] ?? null;
            if (!$offering) {
                continue;
            }

            if (($offering['offering_status'] ?? '') === 'locked') {
                $skipReason = "This offering belongs to a merged group that includes a locked offering.";
                break;
            }
        }

        if ($skipReason !== "") {
            foreach ($groupOfferingIds as $offeringId) {
                $offering = $offeringsById[$offeringId] ?? null;
                if (!$offering) {
                    continue;
                }

                $skipped[] = [
                    "offering_id" => $offeringId,
                    "sub_code" => (string)$offering['sub_code'],
                    "section_name" => (string)$offering['section_name'],
                    "reason" => $skipReason
                ];
            }
            continue;
        }

        foreach ($groupOfferingIds as $offeringId) {
            $clearableIds[] = $offeringId;
            $mergeInfo = $mergeContext[$offeringId] ?? null;
            if (($scheduleCounts[$offeringId] ?? 0) > 0 || (int)($mergeInfo['group_size'] ?? 1) > 1) {
                $clearedOfferingCount++;
            }
        }
    }

    if (!empty($clearableIds)) {
        $safeIds = array_map('intval', array_values(array_unique($clearableIds)));
        $idList = implode(',', $safeIds);

        $conn->begin_transaction();
        try {
            $removedWorkloadRowCount = delete_workload_assignments_for_offerings(
                $conn,
                $safeIds,
                $ay_id,
                $semester
            );

            if (!$conn->query("
                DELETE FROM tbl_class_schedule
                WHERE offering_id IN ({$idList})
            ")) {
                throw new RuntimeException("Failed to clear college-term schedule rows.");
            }
            $deletedRows = max(0, (int)$conn->affected_rows);

            if (synk_schedule_merge_table_exists($conn) && !$conn->query("
                DELETE FROM `" . synk_schedule_merge_table_name() . "`
                WHERE owner_offering_id IN ({$idList})
                   OR member_offering_id IN ({$idList})
            ")) {
                throw new RuntimeException("Failed to clear college-term merge rows.");
            }

            if (!$conn->query("
                UPDATE tbl_prospectus_offering
                SET status = 'pending'
                WHERE offering_id IN ({$idList})
                  AND (status IS NULL OR status != 'locked')
            ")) {
                throw new RuntimeException("Failed to reset offering status.");
            }
            $resetOfferings = max(0, (int)$conn->affected_rows);

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            respond("error", "Failed to clear schedules for the selected college term.");
        }
    }

    $status = !empty($skipped) ? "partial" : "ok";
    $message = ($deletedRows > 0 || $resetOfferings > 0)
        ? "Schedules cleared for the selected college term."
        : "No existing schedules were found to clear for the selected college term.";

    respond($status, $message, [
        "scoped_offering_count" => count($offerings),
        "clearable_offering_count" => count($clearableIds),
        "cleared_offering_count" => $clearedOfferingCount,
        "removed_workload_row_count" => $removedWorkloadRowCount,
        "deleted_schedule_row_count" => $deletedRows,
        "reset_offering_count" => $resetOfferings,
        "skipped_count" => count($skipped),
        "skipped" => $skipped
    ]);
}

/* =====================================================
   CLEAR SCHEDULE (LECTURE + LAB)
===================================================== */
if (isset($_POST['clear_schedule'])) {
    $offering_id = (int)($_POST['offering_id'] ?? 0);
    if (!$offering_id) {
        respond("error", "Missing offering reference.");
    }

    $ctx = load_context_any($conn, $offering_id, $college_id);
    if (!$ctx) {
        respond("error", "Offering not found.");
    }
    if (synk_schedule_tba_has_assigned_need($conn,[$offering_id],(int)$ctx['ay_id'],(int)$ctx['semester'])) {
        respond('error','Remove the pending faculty-need assignment before clearing this offering.');
    }

    $mergeContext = synk_schedule_merge_load_display_context($conn, [$offering_id]);
    $mergeInfo = $mergeContext[$offering_id] ?? [];
    $memberToOwner = synk_schedule_merge_load_member_to_owner_map($conn, [$offering_id]);
    if (isset($memberToOwner[$offering_id])) {
        $ownerCtx = load_context_any($conn, (int)$memberToOwner[$offering_id], $college_id);
        $ownerLabel = trim((string)($ownerCtx['full_section'] ?? ''));
        if ($ownerLabel === '') {
            $ownerLabel = trim((string)($ownerCtx['section_name'] ?? 'this schedule owner'));
        }

        respond(
            "error",
            "This offering inherits schedule from <b>" . htmlspecialchars($ownerLabel, ENT_QUOTES, 'UTF-8') . "</b>. Unmerge it first before clearing."
        );
    }

    $inheritedTypes = array_values((array)($mergeInfo['inherited_types'] ?? []));
    if (!empty($inheritedTypes)) {
        respond(
            "error",
            "This offering still inherits <b>" . htmlspecialchars(implode(', ', $inheritedTypes), ENT_QUOTES, 'UTF-8') . "</b> schedule blocks from another source. Clear the merge settings first before clearing the offering."
        );
    }

    if (has_outgoing_merge_dependencies($mergeInfo)) {
        respond(
            "error",
            "This offering is currently used as a merge source. Manage the merge settings first before clearing its saved schedule."
        );
    }

    $assignedFaculties = load_workload_faculties_for_offering(
        $conn,
        $offering_id,
        (int)$ctx['ay_id'],
        (int)$ctx['semester']
    );

    if (!empty($assignedFaculties)) {
        $names = array_map(static function ($faculty) {
            return htmlspecialchars((string)$faculty['faculty_name'], ENT_QUOTES, 'UTF-8');
        }, $assignedFaculties);

        respond(
            "error",
            "Cannot clear this schedule because it is already assigned in Faculty Workload to <b>" .
            implode('</b>, <b>', $names) .
            "</b>. Remove the workload assignment first."
        );
    }

    $deleted = 0;

    $conn->begin_transaction();
    try {
        $del = $conn->prepare("
            DELETE FROM tbl_class_schedule
            WHERE offering_id = ?
        ");
        $del->bind_param("i", $offering_id);
        if (!$del->execute()) {
            throw new RuntimeException("Failed to clear schedule rows.");
        }
        $deleted = max(0, (int)$del->affected_rows);
        $del->close();

        $upd = $conn->prepare("
            UPDATE tbl_prospectus_offering
            SET status = 'pending'
            WHERE offering_id = ?
              AND (status IS NULL OR status != 'locked')
        ");
        $upd->bind_param("i", $offering_id);
        if (!$upd->execute()) {
            throw new RuntimeException("Failed to update offering status.");
        }
        $upd->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond("error", "Failed to clear schedule.");
    }

    respond(
        "ok",
        $deleted > 0 ? "Schedule cleared." : "No existing schedule to clear.",
        ["deleted" => $deleted]
    );
}

/* =====================================================
   LOAD MERGE OPTIONS FOR A TARGET OFFERING
===================================================== */
if (isset($_POST['load_schedule_merge_candidates'])) {
    $targetOfferingId = (int)($_POST['offering_id'] ?? 0);
    if ($targetOfferingId <= 0) {
        respond("error", "Missing offering reference.");
    }

    $targetRows = load_merge_context_rows_for_offerings($conn, [$targetOfferingId], $college_id);
    $target = $targetRows[$targetOfferingId] ?? null;
    if (!$target) {
        respond("error", "Offering is out of sync. Re-run Generate Offerings first.");
    }

    $mergeContext = synk_schedule_merge_load_display_context($conn, [$targetOfferingId]);
    $targetMerge = $mergeContext[$targetOfferingId] ?? [];
    $currentScopes = (array)($targetMerge['incoming_scope_owner_ids'] ?? ['FULL' => 0, 'LEC' => 0, 'LAB' => 0]);
    $requiredTypes = required_schedule_types_for_context($target);

    $blockingMessages = [];
    if (($target['offering_status'] ?? '') === 'locked') {
        $blockingMessages[] = "Locked offerings cannot change merge settings.";
    }
    if (has_outgoing_merge_dependencies($targetMerge)) {
        $blockingMessages[] = "This offering already serves as a merge source for other offerings. Clear those dependent merges first.";
    }

    $targetWorkload = load_workload_faculty_map_for_offerings(
        $conn,
        [$targetOfferingId],
        (int)$target['ay_id'],
        (int)$target['semester']
    );
    if (!empty($targetWorkload[$targetOfferingId])) {
        $blockingMessages[] = "This offering is already assigned in Faculty Workload to " . implode(', ', array_values($targetWorkload[$targetOfferingId])) . ". Remove the workload assignment first.";
    }

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $candidateIdSql = "
        SELECT o.offering_id
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        WHERE o.ay_id = ?
          AND o.semester = ?
          AND p.college_id = ?
          AND o.offering_id <> ?
        ORDER BY
            p.program_code ASC,
            sec.full_section ASC,
            sm.sub_code ASC,
            o.offering_id ASC
    ";

    $stmt = $conn->prepare($candidateIdSql);
    if (!$stmt) {
        respond("error", "Unable to load merge candidates.");
    }

    $stmt->bind_param("iiii", $target['ay_id'], $target['semester'], $college_id, $targetOfferingId);
    $stmt->execute();
    $res = $stmt->get_result();

    $candidateIds = [];
    while ($row = $res->fetch_assoc()) {
        $candidateId = (int)($row['offering_id'] ?? 0);
        if ($candidateId > 0) {
            $candidateIds[] = $candidateId;
        }
    }
    $stmt->close();

    $candidateRows = load_merge_context_rows_for_offerings($conn, $candidateIds, $college_id);
    $effectiveRowsByOffering = load_effective_schedule_block_rows_by_offering($conn, array_merge([$targetOfferingId], $candidateIds));
    $effectiveOwnerMap = synk_schedule_merge_load_effective_owner_map($conn, array_merge([$targetOfferingId], $candidateIds));

    $targetSignature = merge_required_minutes_signature($target);
    $targetMinutesByType = [
        'LEC' => schedule_merge_scope_required_minutes($target, 'LEC'),
        'LAB' => schedule_merge_scope_required_minutes($target, 'LAB')
    ];

    $options = ['FULL' => [], 'LEC' => [], 'LAB' => []];

    foreach ($candidateIds as $candidateId) {
        $candidate = $candidateRows[$candidateId] ?? null;
        if (!$candidate) {
            continue;
        }

        $isSameSubject = (int)($candidate['sub_id'] ?? 0) === (int)($target['sub_id'] ?? 0);

        $candidateLabel = schedule_merge_offering_label($candidate);
        $candidateSubject = trim(
            (string)($candidate['sub_code'] ?? '') .
            ((string)($candidate['sub_description'] ?? '') !== '' ? ' - ' . (string)($candidate['sub_description'] ?? '') : '')
        );
        $candidateEffectiveRows = (array)($effectiveRowsByOffering[$candidateId] ?? []);
        $isCurrentFullSelection = (int)($currentScopes['FULL'] ?? 0) === $candidateId;

        if (empty($candidateEffectiveRows) && !$isCurrentFullSelection) {
            continue;
        }

        $canSelect = true;
        $reason = '';

        if (merge_required_minutes_signature($candidate) !== $targetSignature) {
            $canSelect = false;
            $reason = 'Required lecture/laboratory hours do not match this offering.';
        } elseif (empty($candidateEffectiveRows)) {
            $canSelect = false;
            $reason = 'No saved schedule is available to inherit yet.';
        } elseif (
            (int)($effectiveOwnerMap[$candidateId]['LEC'] ?? 0) === $targetOfferingId ||
            (int)($effectiveOwnerMap[$candidateId]['LAB'] ?? 0) === $targetOfferingId
        ) {
            $canSelect = false;
            $reason = 'This option already depends on the current offering.';
        }

        $options['FULL'][] = [
            'offering_id' => $candidateId,
            'label' => $candidateLabel,
            'subject_label' => $candidateSubject,
            'schedule_summary' => schedule_merge_scope_summary($candidateEffectiveRows, 'FULL'),
            'is_selected' => (int)($currentScopes['FULL'] ?? 0) === $candidateId,
            'can_select' => $canSelect,
            'reason' => $reason
        ];

        foreach (['LEC', 'LAB'] as $scope) {
            if (!in_array($scope, $requiredTypes, true)) {
                continue;
            }

            if (!$isSameSubject) {
                continue;
            }

            if (schedule_merge_scope_required_minutes($candidate, $scope) !== $targetMinutesByType[$scope]) {
                continue;
            }

            $scopeRows = schedule_merge_scope_rows($candidateEffectiveRows, $scope);
            $canSelect = true;
            $reason = '';

            if (empty($scopeRows)) {
                $canSelect = false;
                $reason = 'No saved ' . strtolower($scope === 'LAB' ? 'laboratory' : 'lecture') . ' schedule is available yet.';
            } elseif ((int)($effectiveOwnerMap[$candidateId][$scope] ?? 0) === $targetOfferingId) {
                $canSelect = false;
                $reason = 'This option already depends on the current offering.';
            }

            $options[$scope][] = [
                'offering_id' => $candidateId,
                'label' => $candidateLabel,
                'subject_label' => $candidateSubject,
                'schedule_summary' => schedule_merge_scope_summary($scopeRows, $scope),
                'is_selected' => (int)($currentScopes[$scope] ?? 0) === $candidateId,
                'can_select' => $canSelect,
                'reason' => $reason
            ];
        }
    }

    respond("ok", "Merge options loaded.", [
        'target_offering_id' => $targetOfferingId,
        'target' => [
            'offering_id' => $targetOfferingId,
            'sub_code' => (string)($target['sub_code'] ?? ''),
            'sub_description' => (string)($target['sub_description'] ?? ''),
            'section_name' => (string)($target['section_name'] ?? ''),
            'full_section' => (string)($target['full_section'] ?? ''),
            'required_types' => $requiredTypes
        ],
        'current' => [
            'FULL' => (int)($currentScopes['FULL'] ?? 0),
            'LEC' => (int)($currentScopes['LEC'] ?? 0),
            'LAB' => (int)($currentScopes['LAB'] ?? 0)
        ],
        'blocking_messages' => $blockingMessages,
        'options' => $options
    ]);
}

/* =====================================================
   SAVE / UPDATE MERGE SETTINGS FOR A TARGET OFFERING
===================================================== */
if (isset($_POST['save_schedule_merge'])) {
    $targetOfferingId = (int)($_POST['target_offering_id'] ?? 0);
    if ($targetOfferingId <= 0) {
        respond("error", "Invalid merge payload.");
    }

    $requestedScopes = [
        'FULL' => (int)($_POST['full_owner_offering_id'] ?? 0),
        'LEC' => (int)($_POST['lec_owner_offering_id'] ?? 0),
        'LAB' => (int)($_POST['lab_owner_offering_id'] ?? 0)
    ];

    if ($requestedScopes['FULL'] > 0) {
        $requestedScopes['LEC'] = 0;
        $requestedScopes['LAB'] = 0;
    }

    $targetRows = load_merge_context_rows_for_offerings($conn, [$targetOfferingId], $college_id);
    $target = $targetRows[$targetOfferingId] ?? null;
    if (!$target) {
        respond("error", "Offering is out of sync. Re-run Generate Offerings first.");
    }

    $mergeContext = synk_schedule_merge_load_display_context($conn, [$targetOfferingId]);
    $targetMerge = $mergeContext[$targetOfferingId] ?? [];

    if (($target['offering_status'] ?? '') === 'locked') {
        respond("error", "Locked offerings cannot change merge settings.");
    }
    if (has_outgoing_merge_dependencies($targetMerge)) {
        respond("error", "This offering already serves as a merge source for other offerings. Clear those dependent merges first.");
    }

    $targetWorkload = load_workload_faculty_map_for_offerings(
        $conn,
        [$targetOfferingId],
        (int)$target['ay_id'],
        (int)$target['semester']
    );
    if (!empty($targetWorkload[$targetOfferingId])) {
        respond(
            "error",
            "This offering is already assigned in Faculty Workload to <b>" .
            implode('</b>, <b>', array_map(static function ($name): string {
                return htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');
            }, array_values($targetWorkload[$targetOfferingId]))) .
            "</b>. Remove the workload assignment first."
        );
    }

    $requiredTypes = required_schedule_types_for_context($target);
    $targetMinutesByType = [
        'LEC' => schedule_merge_scope_required_minutes($target, 'LEC'),
        'LAB' => schedule_merge_scope_required_minutes($target, 'LAB')
    ];

    $ownerIds = synk_schedule_merge_normalize_offering_ids(array_filter(array_values($requestedScopes)));
    $ownerRows = load_merge_context_rows_for_offerings($conn, $ownerIds, $college_id);
    if (count($ownerRows) !== count($ownerIds)) {
        respond("error", "One or more selected merge sources are out of sync. Reload the page and try again.");
    }

    $effectiveRowsByOffering = load_effective_schedule_block_rows_by_offering($conn, array_merge([$targetOfferingId], $ownerIds));
    $effectiveOwnerMap = synk_schedule_merge_load_effective_owner_map($conn, array_merge([$targetOfferingId], $ownerIds));
    $currentLocalRows = load_schedule_block_rows($conn, $targetOfferingId);
    if ($ownerIds) {
        foreach ($effectiveRowsByOffering as $mergeRows) {
            foreach ($mergeRows as $mergeRow) {
                if (synk_schedule_status($mergeRow) !== 'scheduled') {
                    respond('error','Arrange TBA meetings before merging offerings. Pending blocks must keep their local schedule owner.');
                }
            }
        }
    }

    if ($requestedScopes['FULL'] > 0) {
        $ownerId = (int)$requestedScopes['FULL'];
        $ownerRow = $ownerRows[$ownerId] ?? null;
        if (!$ownerRow) {
            respond("error", "The selected whole-subject source could not be loaded.");
        }
        if ($ownerId === $targetOfferingId) {
            respond("error", "An offering cannot inherit its own schedule.");
        }
        if (merge_required_minutes_signature($ownerRow) !== merge_required_minutes_signature($target)) {
            respond("error", "Required lecture/laboratory hours do not match the selected source schedule.");
        }
        if (
            (int)($effectiveOwnerMap[$ownerId]['LEC'] ?? 0) === $targetOfferingId ||
            (int)($effectiveOwnerMap[$ownerId]['LAB'] ?? 0) === $targetOfferingId
        ) {
            respond("error", "The selected source already depends on this offering.");
        }
        if (empty((array)($effectiveRowsByOffering[$ownerId] ?? []))) {
            respond("error", "Save the source offering schedule first before inheriting it.");
        }
    }

    foreach (['LEC', 'LAB'] as $scope) {
        $ownerId = (int)($requestedScopes[$scope] ?? 0);
        if ($ownerId <= 0) {
            continue;
        }

        if (!in_array($scope, $requiredTypes, true)) {
            respond("error", "This offering does not require {$scope} schedule blocks.");
        }

        $ownerRow = $ownerRows[$ownerId] ?? null;
        if (!$ownerRow) {
            respond("error", "A selected merge source could not be loaded.");
        }
        if ($ownerId === $targetOfferingId) {
            respond("error", "An offering cannot inherit its own schedule.");
        }
        if (schedule_merge_scope_required_minutes($ownerRow, $scope) !== $targetMinutesByType[$scope]) {
            respond("error", "{$scope} hours do not match the selected source schedule.");
        }
        if ((int)($effectiveOwnerMap[$ownerId][$scope] ?? 0) === $targetOfferingId) {
            respond("error", "The selected {$scope} source already depends on this offering.");
        }
        $scopeRows = schedule_merge_scope_rows((array)($effectiveRowsByOffering[$ownerId] ?? []), $scope);
        if (empty($scopeRows)) {
            respond("error", "Save the source {$scope} schedule first before inheriting it.");
        }
    }

    $prospectiveRows = [];
    if ($requestedScopes['FULL'] > 0) {
        $ownerId = (int)$requestedScopes['FULL'];
        $ownerLabel = schedule_merge_offering_label((array)($ownerRows[$ownerId] ?? []));
        foreach ((array)($effectiveRowsByOffering[$ownerId] ?? []) as $row) {
            $row['is_inherited'] = true;
            $row['source_label'] = $ownerLabel;
            $prospectiveRows[] = $row;
        }
    } else {
        foreach ($currentLocalRows as $row) {
            $scope = synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC'));
            if ((int)($requestedScopes[$scope] ?? 0) > 0) {
                continue;
            }
            $prospectiveRows[] = $row;
        }

        foreach (['LEC', 'LAB'] as $scope) {
            $ownerId = (int)($requestedScopes[$scope] ?? 0);
            if ($ownerId <= 0) {
                continue;
            }

            $ownerLabel = schedule_merge_offering_label((array)($ownerRows[$ownerId] ?? []));
            foreach (schedule_merge_scope_rows((array)($effectiveRowsByOffering[$ownerId] ?? []), $scope) as $row) {
                $row['is_inherited'] = true;
                $row['source_label'] = $ownerLabel;
                $prospectiveRows[] = $row;
            }
        }
    }

    $internalConflictMessage = find_internal_schedule_conflict_message($prospectiveRows);
    if ($internalConflictMessage !== "") {
        respond("conflict", $internalConflictMessage);
    }

    $scopesToInsert = [];
    foreach (['FULL', 'LEC', 'LAB'] as $scope) {
        $ownerId = (int)($requestedScopes[$scope] ?? 0);
        if ($ownerId > 0) {
            $scopesToInsert[$scope] = $ownerId;
        }
    }

    $deleteTypes = [];
    if ((int)$requestedScopes['FULL'] > 0) {
        $deleteTypes = ['LEC', 'LAB'];
    } else {
        foreach (['LEC', 'LAB'] as $scope) {
            if ((int)($requestedScopes[$scope] ?? 0) > 0) {
                $deleteTypes[] = $scope;
            }
        }
    }

    $conn->begin_transaction();
    try {
        if (!$conn->query("
            DELETE FROM `" . synk_schedule_merge_table_name() . "`
            WHERE member_offering_id = " . (int)$targetOfferingId . "
        ")) {
            throw new RuntimeException("Failed to reset merge settings.");
        }

        if (!empty($deleteTypes)) {
            if (count($deleteTypes) === 2) {
                if (!$conn->query("
                    DELETE FROM tbl_class_schedule
                    WHERE offering_id = " . (int)$targetOfferingId . "
                ")) {
                    throw new RuntimeException("Failed to clear local rows for inherited schedules.");
                }
            } else {
                $quotedTypes = array_map(static function (string $type): string {
                    return "'" . $type . "'";
                }, $deleteTypes);

                if (!$conn->query("
                    DELETE FROM tbl_class_schedule
                    WHERE offering_id = " . (int)$targetOfferingId . "
                      AND schedule_type IN (" . implode(',', $quotedTypes) . ")
                ")) {
                    throw new RuntimeException("Failed to clear local rows for inherited schedules.");
                }
            }
        }

        if (!empty($scopesToInsert)) {
            $insertStmt = $conn->prepare("
                INSERT INTO `" . synk_schedule_merge_table_name() . "`
                    (owner_offering_id, member_offering_id, merge_scope, created_by)
                VALUES (?, ?, ?, ?)
            ");
            if (!$insertStmt) {
                throw new RuntimeException("Failed to save merge settings.");
            }

            $createdBy = (int)($_SESSION['user_id'] ?? 0);
            foreach ($scopesToInsert as $scope => $ownerId) {
                $scopeValue = synk_schedule_merge_normalize_scope($scope);
                $insertStmt->bind_param("iisi", $ownerId, $targetOfferingId, $scopeValue, $createdBy);
                if (!$insertStmt->execute()) {
                    $insertStmt->close();
                    throw new RuntimeException("Failed to save merge settings.");
                }
            }
            $insertStmt->close();
        }

        $updatedEffectiveRows = load_effective_schedule_block_rows_by_offering($conn, [$targetOfferingId]);
        $coverage = build_schedule_coverage_summary($target, (array)($updatedEffectiveRows[$targetOfferingId] ?? []));
        $offeringState = $coverage['status'] === 'complete' ? 'active' : 'pending';

        if (!mark_offering_schedule_state($conn, $targetOfferingId, $offeringState)) {
            throw new RuntimeException("Failed to update offering status.");
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond("error", "Failed to save merge settings.");
    }

    respond("ok", "Merge settings saved.", [
        'target_offering_id' => $targetOfferingId,
        'status_key' => $offeringState
    ]);
}

/* =====================================================
   LOAD SAVED SCHEDULE SETS FOR CURRENT COLLEGE TERM
===================================================== */
if (isset($_POST['load_schedule_sets'])) {
    ensure_saved_schedule_tables_exist($conn);

    $ay_id = (int)($_POST['ay_id'] ?? 0);
    $semester = (int)($_POST['semester'] ?? 0);

    if (!$ay_id || !in_array($semester, [1, 2, 3], true)) {
        respond("error", "Select Academic Year and Semester first.");
    }

    $sets = load_saved_schedule_sets_for_college_term($conn, $college_id, $ay_id, $semester);
    $liveState = saved_schedule_enrich_sets_with_live_state($conn, $sets, $college_id, $ay_id, $semester);
    respond("ok", "Loaded saved schedule sets.", [
        "sets" => $liveState['sets'],
        "live_status" => $liveState['live_status']
    ]);
}

/* =====================================================
   UPDATE SAVED SCHEDULE SET NAME / NOTE
===================================================== */
if (isset($_POST['update_schedule_set_meta'])) {
    ensure_saved_schedule_tables_exist($conn);

    $schedule_set_id = (int)($_POST['schedule_set_id'] ?? 0);
    $set_name = trim((string)($_POST['set_name'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));

    $set_name = preg_replace('/\s+/', ' ', $set_name);
    $set_name = substr($set_name, 0, 120);
    $remarks = substr($remarks, 0, 255);

    if ($schedule_set_id <= 0) {
        respond("error", "Select a saved schedule set first.");
    }

    if ($set_name === '') {
        respond("error", "Provide a name for this saved schedule set.");
    }

    $setRecord = load_saved_schedule_set_record_for_college($conn, $schedule_set_id, $college_id);
    if (!$setRecord) {
        respond("error", "Saved schedule set not found in your scheduler workspace.");
    }

    $programId = (int)($setRecord['program_id'] ?? 0);
    $ayId = (int)($setRecord['ay_id'] ?? 0);
    $semester = (int)($setRecord['semester'] ?? 0);

    if (saved_schedule_set_name_exists_in_same_scope($conn, $college_id, $programId, $ayId, $semester, $set_name, $schedule_set_id)) {
        respond("error", "Another saved schedule set already uses this name in the same scope.");
    }

    $remarksForDb = $remarks !== '' ? $remarks : null;
    $stmt = $conn->prepare("
        UPDATE tbl_program_schedule_set
        SET set_name = ?,
            remarks = ?,
            date_updated = CURRENT_TIMESTAMP
        WHERE schedule_set_id = ?
          AND college_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        respond("error", "Failed to prepare saved set update.");
    }

    $stmt->bind_param("ssii", $set_name, $remarksForDb, $schedule_set_id, $college_id);
    if (!$stmt->execute()) {
        $stmt->close();
        respond("error", "Failed to update the saved schedule set.");
    }
    $stmt->close();

    respond("ok", "Saved schedule set updated.", [
        "schedule_set_id" => $schedule_set_id,
        "set_name" => $set_name,
        "remarks" => $remarks
    ]);
}

/* =====================================================
   DELETE SAVED SCHEDULE SET
===================================================== */
if (isset($_POST['delete_schedule_set'])) {
    ensure_saved_schedule_tables_exist($conn);

    $schedule_set_id = (int)($_POST['schedule_set_id'] ?? 0);
    if ($schedule_set_id <= 0) {
        respond("error", "Select a saved schedule set first.");
    }

    $setRecord = load_saved_schedule_set_record_for_college($conn, $schedule_set_id, $college_id);
    if (!$setRecord) {
        respond("error", "Saved schedule set not found in your scheduler workspace.");
    }

    $deleted = 0;
    $conn->begin_transaction();
    try {
        delete_saved_schedule_workload_rows($conn, $schedule_set_id);

        $rowStmt = $conn->prepare("
            DELETE FROM tbl_program_schedule_set_row
            WHERE schedule_set_id = ?
        ");
        if (!$rowStmt) {
            throw new RuntimeException("Failed to prepare saved set row deletion.");
        }
        $rowStmt->bind_param("i", $schedule_set_id);
        if (!$rowStmt->execute()) {
            $rowStmt->close();
            throw new RuntimeException("Failed to delete saved set rows.");
        }
        $rowStmt->close();

        $stmt = $conn->prepare("
            DELETE FROM tbl_program_schedule_set
            WHERE schedule_set_id = ?
              AND college_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new RuntimeException("Failed to prepare saved set deletion.");
        }

        $stmt->bind_param("ii", $schedule_set_id, $college_id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException("Failed to delete the saved schedule set.");
        }

        $deleted = (int)$stmt->affected_rows;
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond("error", "Failed to delete the saved schedule set.");
    }

    if ($deleted < 1) {
        respond("error", "Saved schedule set was not deleted.");
    }

    respond("ok", "Saved schedule set deleted.", [
        "schedule_set_id" => $schedule_set_id,
        "set_name" => (string)($setRecord['set_name'] ?? '')
    ]);
}

/* =====================================================
   SAVE CURRENT COLLEGE LIVE SCHEDULES AS A SET
===================================================== */
if (isset($_POST['save_schedule_set'])) {
    ensure_saved_schedule_tables_exist($conn);

    $ay_id = (int)($_POST['ay_id'] ?? 0);
    $semester = (int)($_POST['semester'] ?? 0);
    $set_name = trim((string)($_POST['set_name'] ?? ''));
    $remarks = trim((string)($_POST['remarks'] ?? ''));
    $overwriteExisting = (int)($_POST['overwrite_existing_set'] ?? 0) === 1;
    $includeWorkloadAssignments = (int)($_POST['include_workload_assignments'] ?? 0) === 1;

    $set_name = preg_replace('/\s+/', ' ', $set_name);
    $set_name = substr($set_name, 0, 120);
    $remarks = substr($remarks, 0, 255);

    if (!$ay_id || !in_array($semester, [1, 2, 3], true)) {
        respond("error", "Select Academic Year and Semester first.");
    }

    if ($set_name === '') {
        respond("error", "Provide a name for this saved schedule set.");
    }

    if (saved_schedule_college_term_has_merge_rows($conn, $college_id, $ay_id, $semester)) {
        respond("error", "Saved schedule sets are disabled while merged schedule groups exist in this college term. Unmerge the affected offerings first.");
    }

    $snapshotRows = load_live_schedule_snapshot_rows_for_college_term($conn, $ay_id, $semester, $college_id);
    if (empty($snapshotRows)) {
        respond("error", "No live schedules were found in the current college term to save.");
    }

    $existingSet = load_college_schedule_set_by_name($conn, $college_id, $ay_id, $semester, $set_name);
    if ($existingSet && !$overwriteExisting) {
        respond("exists", "A saved schedule set with this name already exists.", [
            "existing_set_id" => (int)($existingSet['schedule_set_id'] ?? 0),
            "existing_set_name" => (string)($existingSet['set_name'] ?? $set_name)
        ]);
    }

    $remarksForDb = $remarks !== '' ? $remarks : null;
    $scheduleSetId = 0;
    $blockOrderByOffering = [];
    $offeringMap = [];
    $sourceScheduleIdToSetRowId = [];
    $sourceScheduleIdToSetRowMeta = [];
    $savedWorkloadRowCount = 0;
    $savedWorkloadFacultyMap = [];

    $conn->begin_transaction();
    try {
        if ($existingSet) {
            $scheduleSetId = (int)($existingSet['schedule_set_id'] ?? 0);

            $updateSet = $conn->prepare("
                UPDATE tbl_program_schedule_set
                SET remarks = ?,
                    date_updated = CURRENT_TIMESTAMP
                WHERE schedule_set_id = ?
            ");
            if (!$updateSet) {
                throw new RuntimeException("Failed to update schedule set.");
            }

            $updateSet->bind_param("si", $remarksForDb, $scheduleSetId);
            if (!$updateSet->execute()) {
                $updateSet->close();
                throw new RuntimeException("Failed to update schedule set.");
            }
            $updateSet->close();

            delete_saved_schedule_workload_rows($conn, $scheduleSetId);

            $deleteRows = $conn->prepare("
                DELETE FROM tbl_program_schedule_set_row
                WHERE schedule_set_id = ?
            ");
            if (!$deleteRows) {
                throw new RuntimeException("Failed to refresh schedule set rows.");
            }

            $deleteRows->bind_param("i", $scheduleSetId);
            if (!$deleteRows->execute()) {
                $deleteRows->close();
                throw new RuntimeException("Failed to refresh schedule set rows.");
            }
            $deleteRows->close();
        } else {
            $insertSet = $conn->prepare("
                INSERT INTO tbl_program_schedule_set
                (college_id, program_id, ay_id, semester, set_name, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$insertSet) {
                throw new RuntimeException("Failed to create schedule set.");
            }

            $createdBy = (int)($_SESSION['user_id'] ?? 0);
            $collegeScheduleProgramId = 0;
            $insertSet->bind_param(
                "iiiissi",
                $college_id,
                $collegeScheduleProgramId,
                $ay_id,
                $semester,
                $set_name,
                $remarksForDb,
                $createdBy
            );
            if (!$insertSet->execute()) {
                $insertSet->close();
                throw new RuntimeException("Failed to create schedule set.");
            }
            $scheduleSetId = (int)$conn->insert_id;
            $insertSet->close();
        }

        $insertRow = $conn->prepare("
            INSERT INTO tbl_program_schedule_set_row
            (
                schedule_set_id,
                offering_id,
                program_id,
                ps_id,
                section_id,
                schedule_type,
                block_order,
                room_id,
                days_json,
                time_start,
                time_end,
                subject_code_snapshot,
                subject_description_snapshot,
                section_name_snapshot,
                room_label_snapshot,
                schedule_status
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$insertRow) {
            throw new RuntimeException("Failed to save schedule set rows.");
        }

        foreach ($snapshotRows as $row) {
            $offeringId = (int)($row['offering_id'] ?? 0);
            if ($offeringId <= 0) {
                continue;
            }

            $blockOrderByOffering[$offeringId] = (int)($blockOrderByOffering[$offeringId] ?? 0) + 1;
            $blockOrder = (int)$blockOrderByOffering[$offeringId];
            $programId = (int)($row['program_id'] ?? 0);
            $psId = (int)($row['ps_id'] ?? 0);
            $sectionId = (int)($row['section_id'] ?? 0);
            $scheduleType = synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC'));
            $scheduleStatus = synk_schedule_status($row);
            $roomId = $row['room_id'] === null ? null : (int)$row['room_id'];
            $daysJson = (string)($row['days_json'] ?? '[]');
            $timeStart = $row['time_start'];
            $timeEnd = $row['time_end'];
            $subjectCode = trim((string)($row['sub_code'] ?? ''));
            $subjectDescription = trim((string)($row['sub_description'] ?? ''));
            $sectionName = trim((string)($row['section_name'] ?? ''));
            $roomLabel = trim((string)($row['room_label'] ?? ''));

            $insertRow->bind_param(
                "iiiiisiissssssss",
                $scheduleSetId,
                $offeringId,
                $programId,
                $psId,
                $sectionId,
                $scheduleType,
                $blockOrder,
                $roomId,
                $daysJson,
                $timeStart,
                $timeEnd,
                $subjectCode,
                $subjectDescription,
                $sectionName,
                $roomLabel,
                $scheduleStatus
            );

            if (!$insertRow->execute()) {
                $insertRow->close();
                throw new RuntimeException("Failed to save schedule set rows.");
            }

            $scheduleSetRowId = (int)$conn->insert_id;
            $sourceScheduleId = (int)($row['schedule_id'] ?? 0);
            if ($sourceScheduleId > 0 && $scheduleSetRowId > 0) {
                $sourceScheduleIdToSetRowId[$sourceScheduleId] = $scheduleSetRowId;
                $sourceScheduleIdToSetRowMeta[$sourceScheduleId] = [
                    'offering_id' => $offeringId,
                    'program_id' => $programId,
                    'schedule_type' => $scheduleType,
                    'block_order' => $blockOrder,
                    'subject_code' => $subjectCode,
                    'section_name' => $sectionName
                ];
            }

            $offeringMap[$offeringId] = true;
        }

        $insertRow->close();

        if ($includeWorkloadAssignments && !empty($sourceScheduleIdToSetRowId)) {
            $workloadRows = load_workload_snapshot_rows_for_schedule_ids(
                $conn,
                array_keys($sourceScheduleIdToSetRowId),
                $ay_id,
                $semester
            );

            if (!empty($workloadRows)) {
                $workloadTable = saved_schedule_workload_table_name();
                $insertWorkload = $conn->prepare("
                    INSERT INTO `{$workloadTable}`
                    (
                        schedule_set_id,
                        schedule_set_row_id,
                        offering_id,
                        program_id,
                        schedule_type,
                        block_order,
                        faculty_id,
                        faculty_name_snapshot,
                        subject_code_snapshot,
                        section_name_snapshot
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$insertWorkload) {
                    throw new RuntimeException("Failed to save workload rows.");
                }

                foreach ($workloadRows as $workloadRow) {
                    $sourceScheduleId = (int)($workloadRow['schedule_id'] ?? 0);
                    $setRowId = (int)($sourceScheduleIdToSetRowId[$sourceScheduleId] ?? 0);
                    $meta = (array)($sourceScheduleIdToSetRowMeta[$sourceScheduleId] ?? []);
                    $facultyId = (int)($workloadRow['faculty_id'] ?? 0);
                    if ($setRowId <= 0 || $facultyId <= 0) {
                        continue;
                    }

                    $offeringId = (int)($meta['offering_id'] ?? 0);
                    $programId = (int)($meta['program_id'] ?? 0);
                    $scheduleType = synk_normalize_schedule_type((string)($meta['schedule_type'] ?? 'LEC'));
                    $blockOrder = (int)($meta['block_order'] ?? 1);
                    $facultyName = substr(trim((string)($workloadRow['faculty_name'] ?? '')), 0, 180);
                    $subjectCode = substr(trim((string)($meta['subject_code'] ?? '')), 0, 50);
                    $sectionName = substr(trim((string)($meta['section_name'] ?? '')), 0, 120);

                    $insertWorkload->bind_param(
                        "iiiisiisss",
                        $scheduleSetId,
                        $setRowId,
                        $offeringId,
                        $programId,
                        $scheduleType,
                        $blockOrder,
                        $facultyId,
                        $facultyName,
                        $subjectCode,
                        $sectionName
                    );

                    if (!$insertWorkload->execute()) {
                        $insertWorkload->close();
                        throw new RuntimeException("Failed to save workload rows.");
                    }

                    if ($insertWorkload->affected_rows > 0) {
                        $savedWorkloadRowCount++;
                        $savedWorkloadFacultyMap[$facultyId] = true;
                    }
                }

                $insertWorkload->close();
            }
        }

        if (!save_saved_schedule_workspace_state($conn, $college_id, $ay_id, $semester, $scheduleSetId)) {
            throw new RuntimeException("Failed to remember the active saved schedule set.");
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond("error", "Failed to save the current live schedules as a set.");
    }

    respond("ok", $existingSet ? "Saved schedule set updated." : "Saved schedule set created.", [
        "schedule_set_id" => $scheduleSetId,
        "set_name" => $set_name,
        "row_count" => count($snapshotRows),
        "offering_count" => count($offeringMap),
        "workload_row_count" => $savedWorkloadRowCount,
        "workload_faculty_count" => count($savedWorkloadFacultyMap),
        "include_workload_assignments" => $includeWorkloadAssignments ? 1 : 0
    ]);
}

/* =====================================================
   LOAD SAVED SCHEDULE SET INTO LIVE SCHEDULES
===================================================== */
if (isset($_POST['load_schedule_set_into_live'])) {
    ensure_saved_schedule_tables_exist($conn);

    $schedule_set_id = (int)($_POST['schedule_set_id'] ?? 0);
    $restoreWorkloadAssignments = (int)($_POST['restore_workload_assignments'] ?? 0) === 1;
    if ($schedule_set_id <= 0) {
        respond("error", "Select a saved schedule set first.");
    }

    $setRecord = load_saved_schedule_set_record_for_college($conn, $schedule_set_id, $college_id);
    if (!$setRecord) {
        respond("error", "Saved schedule set not found in your scheduler workspace.");
    }

    $setProgramId = (int)($setRecord['program_id'] ?? 0);
    $isCollegeTermSet = $setProgramId <= 0;

    if ($isCollegeTermSet) {
        if (saved_schedule_college_term_has_merge_rows($conn, $college_id, (int)$setRecord['ay_id'], (int)$setRecord['semester'])) {
            respond("error", "Loading a saved schedule set is disabled while merged schedule groups exist in this college term. Clear or unmerge the active merge groups first.");
        }
    } elseif (saved_schedule_program_term_has_merge_rows($conn, $college_id, $setProgramId, (int)$setRecord['ay_id'], (int)$setRecord['semester'])) {
        respond("error", "Loading this legacy saved schedule set is disabled while merged schedule groups exist in its program term. Clear or unmerge the active merge groups first.");
    }

    $setRows = load_saved_schedule_set_rows_for_college($conn, $schedule_set_id, $college_id);
    if (empty($setRows)) {
        respond("error", "This saved schedule set does not contain any rows.");
    }

    $savedWorkloadRows = load_saved_schedule_workload_rows_for_college($conn, $schedule_set_id, $college_id);
    $savedWorkloadRowCount = count($savedWorkloadRows);
    if ($restoreWorkloadAssignments && $savedWorkloadRowCount === 0) {
        respond("error", "This saved schedule set does not include Faculty Workload assignments.");
    }

    $scopeOfferings = $isCollegeTermSet
        ? load_scoped_offerings_for_college_term(
            $conn,
            (int)$setRecord['ay_id'],
            (int)$setRecord['semester'],
            $college_id
        )
        : load_scoped_offerings_for_term(
            $conn,
            $setProgramId,
            (int)$setRecord['ay_id'],
            (int)$setRecord['semester'],
            $college_id
        );

    if (empty($scopeOfferings)) {
        respond("error", "The saved set scope is no longer available. Re-run Generate Offerings first.");
    }

    $scopeMap = [];
    foreach ($scopeOfferings as $offering) {
        $scopeMap[(int)$offering['offering_id']] = $offering;
    }

    $setLabelsByOffering = [];
    $targetOfferingMap = [];
    foreach ($setRows as $row) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        $targetOfferingMap[$offeringId] = true;
        if (!isset($setLabelsByOffering[$offeringId])) {
            $setLabelsByOffering[$offeringId] = schedule_set_offering_label(
                (string)($row['subject_code_snapshot'] ?? ''),
                (string)($row['section_name_snapshot'] ?? ''),
                'Offering #' . $offeringId
            );
        }
    }

    $currentLiveOfferingIds = $isCollegeTermSet
        ? load_live_scheduled_offering_ids_for_college_term(
            $conn,
            (int)$setRecord['ay_id'],
            (int)$setRecord['semester'],
            $college_id
        )
        : load_live_scheduled_offering_ids_for_scope(
            $conn,
            $setProgramId,
            (int)$setRecord['ay_id'],
            (int)$setRecord['semester'],
            $college_id
        );

    $affectedOfferingMap = [];
    foreach ($currentLiveOfferingIds as $offeringId) {
        $affectedOfferingMap[(int)$offeringId] = true;
    }
    foreach (array_keys($targetOfferingMap) as $offeringId) {
        $affectedOfferingMap[(int)$offeringId] = true;
    }
    $affectedOfferingIds = array_map('intval', array_keys($affectedOfferingMap));

    if (empty($affectedOfferingIds)) {
        respond("error", "There are no affected live schedules to replace for this saved set.");
    }
    if (synk_schedule_tba_has_assigned_need($conn,$affectedOfferingIds,(int)$setRecord['ay_id'],(int)$setRecord['semester'])) {
        respond('error','Remove pending faculty-need assignments before replacing these live schedules.');
    }

    $outdatedLines = [];
    $lockedLines = [];
    foreach ($affectedOfferingIds as $offeringId) {
        $scopeOffering = $scopeMap[$offeringId] ?? null;
        $label = $scopeOffering
            ? schedule_set_offering_label($scopeOffering['sub_code'] ?? '', $scopeOffering['section_name'] ?? '', 'Offering #' . $offeringId)
            : ($setLabelsByOffering[$offeringId] ?? ('Offering #' . $offeringId));

        if (!$scopeOffering) {
            $outdatedLines[] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ': offering no longer exists in this saved set scope.';
            continue;
        }

        if (($scopeOffering['offering_status'] ?? '') === 'locked') {
            $lockedLines[] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ': offering is locked.';
        }
    }

    if (!empty($outdatedLines)) {
        respond(
            "error",
            "This saved set references outdated offerings.<br><br>" . implode("<br>", array_slice($outdatedLines, 0, 8))
        );
    }

    if (!empty($lockedLines)) {
        respond(
            "error",
            "This saved set cannot be loaded because one or more affected offerings are locked.<br><br>" .
            implode("<br>", array_slice($lockedLines, 0, 8))
        );
    }

    $workloadMap = load_workload_faculty_map_for_offerings(
        $conn,
        $affectedOfferingIds,
        (int)$setRecord['ay_id'],
        (int)$setRecord['semester']
    );

    if (!empty($workloadMap) && !$restoreWorkloadAssignments) {
        $workloadLines = [];
        foreach ($affectedOfferingIds as $offeringId) {
            if (empty($workloadMap[$offeringId])) {
                continue;
            }

            $scopeOffering = $scopeMap[$offeringId] ?? null;
            $label = $scopeOffering
                ? schedule_set_offering_label($scopeOffering['sub_code'] ?? '', $scopeOffering['section_name'] ?? '', 'Offering #' . $offeringId)
                : ($setLabelsByOffering[$offeringId] ?? ('Offering #' . $offeringId));

            $workloadLines[] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8') .
                ': assigned in Faculty Workload to ' .
                htmlspecialchars(implode(', ', $workloadMap[$offeringId]), ENT_QUOTES, 'UTF-8') . '.';
        }

        if (!empty($workloadLines)) {
            respond(
                "error",
                "Remove the affected Faculty Workload assignments before loading this saved set.<br><br>" .
                implode("<br>", array_slice($workloadLines, 0, 8)) .
                ($savedWorkloadRowCount > 0 ? "<br><br>Or load again with Restore saved Faculty Workload enabled." : "")
            );
        }
    }

    $contextCache = [];
    $normalizedRows = [];
    $normalizedRowsBySetRowId = [];

    foreach ($setRows as $row) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        if (!isset($contextCache[$offeringId])) {
            $ctx = load_context_live($conn, $offeringId, $college_id);
            if (!$ctx) {
                respond("error", "One or more offerings in this saved set are out of sync. Re-run Generate Offerings first.");
            }
            $contextCache[$offeringId] = $ctx;
        }

        $type = synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC'));
        $roomId = (int)($row['room_id'] ?? 0);
        $timeStart = normalize_time_input((string)($row['time_start'] ?? ''));
        $timeEnd = normalize_time_input((string)($row['time_end'] ?? ''));
        $days = normalize_days_array(json_decode((string)($row['days_json'] ?? '[]'), true));
        $label = schedule_set_row_label($row);

        $savedStatus = synk_schedule_status($row);
        if ($savedStatus !== 'tba' && (($savedStatus === 'scheduled' && $roomId <= 0) || !$timeStart || !$timeEnd || empty($days))) {
            respond("error", "{$label} is incomplete inside the saved set.");
        }
        if ($savedStatus === 'tba') { $roomId = null; $timeStart = null; $timeEnd = null; $days = []; }
        if ($savedStatus === 'room_tba') { $roomId = null; }

        if ($savedStatus !== 'tba' && $timeEnd <= $timeStart) {
            respond("error", "{$label} has an invalid time range inside the saved set.");
        }

        if ($savedStatus !== 'tba') { validate_schedule_policy($days, $timeStart, $timeEnd, $label, $schedulePolicy); }
        if ($savedStatus === 'scheduled') { validate_room_for_schedule(
            $conn,
            $roomId,
            $college_id,
            (int)$setRecord['ay_id'],
            (int)$setRecord['semester'],
            $type
        ); }

        $scheduleSetRowId = (int)($row['schedule_set_row_id'] ?? 0);
        $normalizedRow = [
            'schedule_set_row_id' => $scheduleSetRowId,
            'offering_id' => $offeringId,
            'section_id' => (int)($row['section_id'] ?? 0),
            'section_name' => (string)($row['section_name_snapshot'] ?? ($contextCache[$offeringId]['section_name'] ?? '')),
            'schedule_type' => $type,
            'schedule_status' => $savedStatus,
            'block_order' => (int)($row['block_order'] ?? 0),
            'room_id' => $roomId,
            'room_label' => (string)($row['room_label_snapshot'] ?? ''),
            'days' => $days,
            'days_json' => json_encode($days),
            'time_start' => $timeStart,
            'time_end' => $timeEnd,
            'subject_code' => (string)($row['subject_code_snapshot'] ?? ''),
            'label' => $label
        ];
        $normalizedRows[] = $normalizedRow;
        if ($scheduleSetRowId > 0) {
            $normalizedRowsBySetRowId[$scheduleSetRowId] = $normalizedRow;
        }
    }

    $internalConflict = validate_saved_schedule_set_internal_conflicts($normalizedRows);
    if ($internalConflict !== null) {
        respond("conflict", $internalConflict);
    }

    foreach ($normalizedRows as $row) {
        if ($row['schedule_status'] === 'tba') { continue; }
        $ctx = $contextCache[(int)$row['offering_id']];
        $externalConflict = find_conflict_excluding_offerings(
            $conn,
            (int)$setRecord['ay_id'],
            (int)$setRecord['semester'],
            $affectedOfferingIds,
            (int)($ctx['section_id'] ?? 0),
            (string)($ctx['section_name'] ?? $row['section_name'] ?? ''),
            (int)$row['room_id'],
            $row['days'],
            (string)$row['time_start'],
            (string)$row['time_end'],
            (string)$row['label']
        );

        if ($externalConflict !== null) {
            respond("conflict", $externalConflict);
        }
    }

    if ($restoreWorkloadAssignments) {
        $workloadConflict = validate_saved_schedule_workload_restore(
            $conn,
            $savedWorkloadRows,
            $normalizedRowsBySetRowId,
            $affectedOfferingIds,
            (int)$setRecord['ay_id'],
            (int)$setRecord['semester']
        );

        if ($workloadConflict !== null) {
            respond("conflict", $workloadConflict);
        }
    }

    $rowsByOffering = [];
    foreach ($normalizedRows as $row) {
        $rowsByOffering[(int)$row['offering_id']][] = $row;
    }

    $deletedRowCount = 0;
    $insertedRowCount = 0;
    $activatedOfferingCount = 0;
    $pendingOfferingCount = 0;
    $removedWorkloadRowCount = 0;
    $restoredWorkloadRowCount = 0;
    $insertedScheduleIdBySetRowId = [];

    $conn->begin_transaction();
    try {
        $idList = implode(',', array_map('intval', $affectedOfferingIds));

        $removedWorkloadRowCount = delete_workload_assignments_for_offerings(
            $conn,
            $affectedOfferingIds,
            (int)$setRecord['ay_id'],
            (int)$setRecord['semester']
        );

        if (!$conn->query("
            DELETE FROM tbl_class_schedule
            WHERE offering_id IN ({$idList})
        ")) {
            throw new RuntimeException("Failed to clear live schedule rows.");
        }
        $deletedRowCount = max(0, (int)$conn->affected_rows);

        if (!$conn->query("
            UPDATE tbl_prospectus_offering
            SET status = 'pending'
            WHERE offering_id IN ({$idList})
              AND (status IS NULL OR status != 'locked')
        ")) {
            throw new RuntimeException("Failed to reset offering status.");
        }

        foreach ($rowsByOffering as $offeringId => $offeringRows) {
            $groupId = count($offeringRows) > 1 ? random_int(100000, 2147483647) : null;

            foreach ($offeringRows as $row) {
                $newScheduleId = insert_schedule_row(
                    $conn,
                    (int)$offeringId,
                    (string)$row['schedule_type'],
                    $groupId,
                    $row['room_id'],
                    (string)$row['days_json'],
                    $row['time_start'],
                    $row['time_end'],
                    (int)($_SESSION['user_id'] ?? 0)
                );
                if ($row['schedule_status'] !== 'scheduled') {
                    $pendingStatus=$row['schedule_status']; $pendingRoom=$row['room_id']; $pendingStart=$row['time_start']; $pendingEnd=$row['time_end'];
                    $pendingStmt=$conn->prepare('UPDATE tbl_class_schedule SET schedule_status=?,room_id=?,time_start=?,time_end=? WHERE schedule_id=?');
                    $pendingStmt->bind_param('sissi',$pendingStatus,$pendingRoom,$pendingStart,$pendingEnd,$newScheduleId);
                    if (!$pendingStmt->execute()) { throw new RuntimeException('Unable to restore the pending schedule.'); }
                    $pendingStmt->close();
                    synk_schedule_tba_record_event($conn,$newScheduleId,(int)$offeringId,$college_id,[],$row);
                }
                $setRowId = (int)($row['schedule_set_row_id'] ?? 0);
                if ($setRowId > 0 && $newScheduleId > 0) {
                    $insertedScheduleIdBySetRowId[$setRowId] = $newScheduleId;
                }
                $insertedRowCount++;
            }

            $ctx = $contextCache[(int)$offeringId];
            $coverage = build_schedule_coverage_summary($ctx, $offeringRows);
            $state = $coverage['status'] === 'complete' ? 'active' : 'pending';

            if (!mark_offering_schedule_state($conn, (int)$offeringId, $state)) {
                throw new RuntimeException("Failed to update offering schedule state.");
            }

            if ($state === 'active') {
                $activatedOfferingCount++;
            } else {
                $pendingOfferingCount++;
            }
        }

        if ($restoreWorkloadAssignments && !empty($savedWorkloadRows)) {
            $setAyId = (int)$setRecord['ay_id'];
            $setSemester = (int)$setRecord['semester'];
            $insertWorkload = $conn->prepare("
                INSERT IGNORE INTO tbl_faculty_workload_sched
                    (schedule_id, faculty_id, ay_id, semester)
                VALUES (?, ?, ?, ?)
            ");
            if (!$insertWorkload) {
                throw new RuntimeException("Failed to restore saved workload assignments.");
            }

            foreach ($savedWorkloadRows as $workloadRow) {
                $setRowId = (int)($workloadRow['schedule_set_row_id'] ?? 0);
                $newScheduleId = (int)($insertedScheduleIdBySetRowId[$setRowId] ?? 0);
                $facultyId = (int)($workloadRow['faculty_id'] ?? 0);

                if ($setRowId <= 0 || $newScheduleId <= 0 || $facultyId <= 0) {
                    continue;
                }

                $insertWorkload->bind_param(
                    "iiii",
                    $newScheduleId,
                    $facultyId,
                    $setAyId,
                    $setSemester
                );

                if (!$insertWorkload->execute()) {
                    $insertWorkload->close();
                    throw new RuntimeException("Failed to restore saved workload assignments.");
                }

                if ($insertWorkload->affected_rows > 0) {
                    $restoredWorkloadRowCount++;
                }
            }

            $insertWorkload->close();
        }

        if (!save_saved_schedule_workspace_state(
            $conn,
            $college_id,
            (int)$setRecord['ay_id'],
            (int)$setRecord['semester'],
            (int)$setRecord['schedule_set_id']
        )) {
            throw new RuntimeException("Failed to remember the loaded saved schedule set.");
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond("error", "Failed to load the saved schedule set into live schedules.");
    }

    respond("ok", "Saved schedule set loaded into live schedules.", [
        "schedule_set_id" => (int)$setRecord['schedule_set_id'],
        "set_name" => (string)($setRecord['set_name'] ?? ''),
        "affected_offering_count" => count($affectedOfferingIds),
        "cleared_live_offering_count" => count($currentLiveOfferingIds),
        "deleted_schedule_row_count" => $deletedRowCount,
        "loaded_offering_count" => count($rowsByOffering),
        "loaded_row_count" => $insertedRowCount,
        "activated_offering_count" => $activatedOfferingCount,
        "pending_offering_count" => $pendingOfferingCount,
        "saved_workload_row_count" => $savedWorkloadRowCount,
        "removed_workload_row_count" => $removedWorkloadRowCount,
        "restored_workload_row_count" => $restoredWorkloadRowCount,
        "restore_workload_assignments" => $restoreWorkloadAssignments ? 1 : 0
    ]);
}

/* =====================================================
   VALIDATE REQUEST
===================================================== */
if (
    !isset($_POST['load_schedule_sets']) &&
    !isset($_POST['update_schedule_set_meta']) &&
    !isset($_POST['delete_schedule_set']) &&
    !isset($_POST['save_schedule_set']) &&
    !isset($_POST['load_schedule_set_into_live']) &&
    !isset($_POST['load_schedule_merge_candidates']) &&
    !isset($_POST['save_schedule_merge']) &&
    !isset($_POST['load_schedule_blocks']) &&
    !isset($_POST['load_section_schedule_matrix']) &&
    !isset($_POST['load_current_section_schedule_matrix']) &&
    !isset($_POST['load_schedule_faculty_options']) &&
    !isset($_POST['load_faculty_schedule_overview']) &&
    !isset($_POST['load_dual_schedule']) &&
    !isset($_POST['save_schedule']) &&
    !isset($_POST['save_dual_schedule']) &&
    !isset($_POST['save_schedule_blocks']) &&
    !isset($_POST['clear_schedule']) &&
    !isset($_POST['clear_all_college_schedules'])
) {
    respond("error", "Invalid request.");
}

/* =====================================================
   SINGLE SCHEDULE (LECTURE ONLY)
===================================================== */
if (isset($_POST['save_schedule']) || isset($_POST['save_dual_schedule'])) {
    $legacyOfferingId=(int)($_POST['offering_id'] ?? 0);
    $pendingLegacy=$conn->query("SELECT 1 FROM tbl_class_schedule WHERE offering_id={$legacyOfferingId} AND schedule_status<>'scheduled' LIMIT 1");
    if ($pendingLegacy && $pendingLegacy->num_rows) { respond('error','Use the schedule block editor to resolve TBA meetings.'); }
}

if (isset($_POST['save_schedule'])) {
    $offering_id = (int)$_POST['offering_id'];
    $room_id     = (int)$_POST['room_id'];
    $time_start  = normalize_time_input($_POST['time_start'] ?? '');
    $time_end    = normalize_time_input($_POST['time_end'] ?? '');
    $days        = normalize_days_array(json_decode($_POST['days_json'], true));

    if (!$offering_id || !$room_id || !$time_start || !$time_end || empty($days)) {
        respond("error", "Missing schedule fields.");
    }

    ensure_offering_not_merged_member($conn, $offering_id, $college_id);

    if ($time_end <= $time_start) {
        respond("error", "End time must be later than start time.");
    }

    validate_schedule_policy($days, $time_start, $time_end, 'Lecture schedule', $schedulePolicy);

    $ctx = load_context_live($conn, $offering_id, $college_id);
    if (!$ctx) {
        respond("error", "Offering is out of sync. Re-run Generate Offerings first.");
    }

    if (in_array('LAB', required_schedule_types_for_context($ctx), true)) {
        respond("error", "This subject requires both lecture and laboratory schedules. Use the lecture + laboratory scheduler.");
    }

    validate_room_for_schedule($conn, $room_id, $college_id, (int)$ctx['ay_id'], (int)$ctx['semester'], 'LEC');

    check_conflict($conn, $ctx, $offering_id, $room_id, $time_start, $time_end, $days, "LEC");

    $existingRows = load_existing_schedule_rows($conn, $offering_id);
    if (isset($existingRows['LAB'])) {
        respond("error", "This lecture-only offering still has a laboratory schedule row. Resolve the existing schedule data first.");
    }

    check_assigned_faculty_conflicts(
        $conn,
        $ctx,
        $offering_id,
        [[
            'type' => 'LEC',
            'start' => $time_start,
            'end' => $time_end,
            'days' => $days
        ]]
    );

    $conn->begin_transaction();
    try {
        if (isset($existingRows['LEC'])) {
            update_schedule_row(
                $conn,
                (int)$existingRows['LEC']['schedule_id'],
                'LEC',
                null,
                $room_id,
                json_encode($days),
                $time_start,
                $time_end
            );
        } else {
            insert_schedule_row(
                $conn,
                $offering_id,
                'LEC',
                null,
                $room_id,
                json_encode($days),
                $time_start,
                $time_end,
                (int)$_SESSION['user_id']
            );
        }

        if (!mark_offering_scheduled($conn, $offering_id)) {
            throw new RuntimeException("Failed to update offering status.");
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond("error", "Failed to save lecture schedule.");
    }

    respond("ok", "Lecture schedule saved.");
}

/* =====================================================
   DUAL SCHEDULE (LECTURE + LAB)
===================================================== */
if (isset($_POST['save_dual_schedule'])) {
    $offering_id = (int)$_POST['offering_id'];
    $schedules   = $_POST['schedules'];

    if (!$offering_id || empty($schedules) || !is_array($schedules)) {
        respond("error", "Invalid dual schedule.");
    }

    ensure_offering_not_merged_member($conn, $offering_id, $college_id);

    $ctx = load_context_live($conn, $offering_id, $college_id);
    if (!$ctx) {
        respond("error", "Offering is out of sync. Re-run Generate Offerings first.");
    }

    if (!in_array('LAB', required_schedule_types_for_context($ctx), true)) {
        respond("error", "This subject is lecture-only. Use the single schedule form.");
    }

    $norm = [];
    foreach ($schedules as $s) {
        $type = strtoupper(trim((string)($s['type'] ?? '')));
        $roomId = (int)($s['room_id'] ?? 0);
        $timeStart = normalize_time_input($s['time_start'] ?? '');
        $timeEnd = normalize_time_input($s['time_end'] ?? '');
        $daysJson = (string)($s['days_json'] ?? '');
        $days = normalize_days_array(json_decode($daysJson, true));

        if (!in_array($type, ['LEC', 'LAB'], true)) {
            respond("error", "Invalid schedule type.");
        }

        if (!$roomId || !$timeStart || !$timeEnd || !is_array($days) || empty($days)) {
            respond("error", "{$type} schedule is incomplete.");
        }

        if ($timeEnd <= $timeStart) {
            respond("error", "{$type} end time must be later than start.");
        }

        validate_schedule_policy(
            $days,
            $timeStart,
            $timeEnd,
            $type === 'LAB' ? 'Laboratory schedule' : 'Lecture schedule',
            $schedulePolicy
        );

        $norm[] = [
            "type" => $type,
            "room" => $roomId,
            "start" => $timeStart,
            "end" => $timeEnd,
            "days" => $days,
            "days_json" => json_encode($days)
        ];
    }

    if (count($norm) !== 2) {
        respond("error", "Lecture + laboratory scheduling requires exactly two schedule blocks.");
    }

    $typesSeen = array_column($norm, 'type');
    sort($typesSeen);
    if ($typesSeen !== ['LAB', 'LEC']) {
        respond("error", "Lecture + laboratory scheduling requires one LEC block and one LAB block.");
    }

    if (
        count($norm) == 2 &&
        days_overlap($norm[0]['days'], $norm[1]['days']) &&
        time_overlap($norm[0]['start'], $norm[0]['end'], $norm[1]['start'], $norm[1]['end'])
    ) {
        respond("conflict", "Lecture and Laboratory schedules overlap.");
    }

    foreach ($norm as $n) {
        validate_room_for_schedule(
            $conn,
            (int)$n['room'],
            $college_id,
            (int)$ctx['ay_id'],
            (int)$ctx['semester'],
            $n['type']
        );
    }

    foreach ($norm as $n) {
        check_conflict(
            $conn,
            $ctx,
            $offering_id,
            $n['room'],
            $n['start'],
            $n['end'],
            $n['days'],
            $n['type']
        );
    }

    check_assigned_faculty_conflicts($conn, $ctx, $offering_id, $norm);

    $existingRows = load_existing_schedule_rows($conn, $offering_id);
    $existingGroupId = null;
    foreach ($existingRows as $row) {
        $candidateGroupId = (int)($row['schedule_group_id'] ?? 0);
        if ($candidateGroupId > 0) {
            $existingGroupId = $candidateGroupId;
            break;
        }
    }
    $group_id = $existingGroupId ?: random_int(100000, 2147483647);

    $conn->begin_transaction();
    try {
        foreach ($norm as $n) {
            if (isset($existingRows[$n['type']])) {
                update_schedule_row(
                    $conn,
                    (int)$existingRows[$n['type']]['schedule_id'],
                    $n['type'],
                    $group_id,
                    (int)$n['room'],
                    (string)$n['days_json'],
                    (string)$n['start'],
                    (string)$n['end']
                );
            } else {
                insert_schedule_row(
                    $conn,
                    $offering_id,
                    $n['type'],
                    $group_id,
                    (int)$n['room'],
                    (string)$n['days_json'],
                    (string)$n['start'],
                    (string)$n['end'],
                    (int)$_SESSION['user_id']
                );
            }
        }

        if (!mark_offering_scheduled($conn, $offering_id)) {
            throw new RuntimeException("Failed to update offering status.");
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        respond("error", "Failed to save lecture and laboratory schedules.");
    }

    respond("ok", "Lecture and Laboratory schedules saved.");
}
