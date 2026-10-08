<?php
session_start();
ob_start();

include '../backend/db.php';
require_once __DIR__ . '/schedule_block_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo "ERROR|Unauthorized access.";
    exit;
}

$role = $_SESSION['role'];
$myCollege = intval($_SESSION['college_id'] ?? 0);
if (!in_array($role, ['admin', 'scheduler'], true)) {
    echo "ERROR|Unauthorized access.";
    exit;
}

$write_actions = [
    'save_header',
    'save_year_sem',
    'save_prospectus_subject',
    'delete_prospectus_subject',
    'delete_year_sem',
    'update_prospectus_subject'
];
$requires_csrf = false;
foreach ($write_actions as $action) {
    if (isset($_POST[$action])) {
        $requires_csrf = true;
        break;
    }
}

if ($requires_csrf) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        echo "ERROR|CSRF validation failed.";
        exit;
    }

    if ($role !== 'admin') {
        echo "ERROR|Administrator access required.";
        exit;
    }
}

function can_access_prospectus($conn, $prospectus_id, $role, $myCollege) {
    if ($prospectus_id <= 0) return false;
    if ($role === 'admin') return true;
    $sql = "SELECT h.prospectus_id
            FROM tbl_prospectus_header h
            INNER JOIN tbl_program p ON p.program_id = h.program_id
            WHERE h.prospectus_id = ?
              AND p.college_id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $prospectus_id, $myCollege);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function can_access_pys($conn, $pys_id, $role, $myCollege) {
    if ($pys_id <= 0) return false;
    if ($role === 'admin') return true;
    $sql = "SELECT y.pys_id
            FROM tbl_prospectus_year_sem y
            INNER JOIN tbl_prospectus_header h ON h.prospectus_id = y.prospectus_id
            INNER JOIN tbl_program p ON p.program_id = h.program_id
            WHERE y.pys_id = ?
              AND p.college_id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $pys_id, $myCollege);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function can_access_ps($conn, $ps_id, $role, $myCollege) {
    if ($ps_id <= 0) return false;
    if ($role === 'admin') return true;
    $sql = "SELECT ps.ps_id
            FROM tbl_prospectus_subjects ps
            INNER JOIN tbl_prospectus_year_sem y ON y.pys_id = ps.pys_id
            INNER JOIN tbl_prospectus_header h ON h.prospectus_id = y.prospectus_id
            INNER JOIN tbl_program p ON p.program_id = h.program_id
            WHERE ps.ps_id = ?
              AND p.college_id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $ps_id, $myCollege);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function prospectus_total_units_from_hours(float $lecHours, float $labHours): float
{
    return round(synk_subject_units_total($lecHours, $labHours, 0.0), 2);
}

function prospectus_display_values(float $lecHours, float $labValue, ?float $storedTotalUnits = null): array
{
    $safeLecHours = max(0.0, $lecHours);
    $displayLabHours = round(
        synk_lab_contact_hours($safeLecHours, max(0.0, $labValue), (float)($storedTotalUnits ?? 0.0)),
        2
    );

    return [
        'lec_hours' => round($safeLecHours, 2),
        'lab_hours' => $displayLabHours,
        'total_units' => prospectus_total_units_from_hours($safeLecHours, $displayLabHours)
    ];
}

function prospectus_number_display($value): string
{
    $formatted = number_format((float)$value, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

// ======================================================
// EDIT SUBJECT ADDED TO PROSPECTUS
// ======================================================
if (isset($_POST['load_subject_for_edit'])) {

    $ps_id = intval($_POST['ps_id'] ?? 0);
    if ($ps_id <= 0) { echo json_encode([]); exit; }
    if (!can_access_ps($conn, $ps_id, $role, $myCollege)) { echo json_encode([]); exit; }

    $sql = "SELECT ps_id, sub_id, lec_units, lab_units, total_units, sort_order, prerequisites, prerequisite_sub_ids
            FROM tbl_prospectus_subjects
            WHERE ps_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $ps_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode($row ?: []);
    exit;
}

if (isset($_POST['update_prospectus_subject'])) {

    $ps_id  = intval($_POST['ps_id'] ?? 0);
    $sub_id = intval($_POST['sub_id'] ?? 0);

    $lec   = (float)($_POST['lec_units'] ?? 0);
    $lab   = (float)($_POST['lab_units'] ?? 0);
    $total = prospectus_total_units_from_hours($lec, $lab);
    $sort  = intval($_POST['sort_order'] ?? 1);

    $prereq_text = $_POST['prerequisites'] ?? '';
    $prereq_ids  = $_POST['prerequisite_sub_ids'] ?? null;

    if ($ps_id <= 0 || $sub_id <= 0) {
        echo "ERROR|Invalid input|Please select a subject.";
        exit;
    }
    if (!can_access_ps($conn, $ps_id, $role, $myCollege)) {
        echo "ERROR|Unauthorized subject access.|Update blocked.";
        exit;
    }
    if ($lec < 0 || $lab < 0 || $sort < 1) {
        echo "ERROR|Invalid input|Units and sort order are invalid.";
        exit;
    }

    $sql = "UPDATE tbl_prospectus_subjects
            SET sub_id = ?, lec_units = ?, lab_units = ?, total_units = ?, sort_order = ?,
                prerequisites = ?, prerequisite_sub_ids = ?
            WHERE ps_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("idddissi", $sub_id, $lec, $lab, $total, $sort, $prereq_text, $prereq_ids, $ps_id);

    if ($stmt->execute()) {
        echo "OK|UPDATED|Subject updated successfully.";
    } else {
        echo "ERROR|DB_ERROR|Failed to update.";
    }
    $stmt->close();
    exit;
}


// ======================================================
// DELETE YEAR & SEM + ALL SUBJECTS INSIDE IT
// ======================================================
if (isset($_POST['delete_year_sem'])) {

    $pys_id = intval($_POST['pys_id'] ?? 0);

    if ($pys_id <= 0) {
        echo "ERROR|Invalid Year/Sem reference.";
        exit;
    }
    if (!can_access_pys($conn, $pys_id, $role, $myCollege)) {
        echo "ERROR|Unauthorized Year/Sem access.";
        exit;
    }

    global $conn;

    $conn->begin_transaction();
    try {
        // Delete all subject mappings under this PYS
        $sql = "DELETE FROM tbl_prospectus_subjects WHERE pys_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $pys_id);
        $stmt->execute();
        $stmt->close();

        // Delete the Year/Sem record itself
        $sql = "DELETE FROM tbl_prospectus_year_sem WHERE pys_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $pys_id);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            throw new Exception("Unable to delete Year & Semester.");
        }

        $conn->commit();
        echo "OK|Year & Semester removed successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        echo "ERROR|" . $e->getMessage();
    }
    exit;
}



if (isset($_POST['get_all_subjects'])) {

    $sql = "SELECT sub_id, sub_code, sub_description
            FROM tbl_subject_masterlist
            WHERE status='active'
            ORDER BY sub_code ASC";

    $result = $conn->query($sql);

    $rows = [];
    while ($r = $result->fetch_assoc()) {
        $rows[] = $r;
    }

    echo json_encode($rows);
    exit;
}



// ======================================================
// LOAD PROSPECTUS LIST (ADMIN: ONLY WITH AT LEAST 1 SUBJECT)
// PURPOSE:
// - Used by "Copy Prospectus" modal
// - Ensures only copy-ready prospectuses appear
// RULE:
// - Prospectus must have at least one subject
// - Admin can see prospectuses from ALL colleges
// ======================================================
if (isset($_POST['load_prospectus_list'])) {

    $role = $_SESSION['role'] ?? '';
    $myCollege = $_SESSION['college_id'] ?? 0;

    // --------------------------------------------------
    // ADMIN: All colleges, but ONLY prospectuses
    //        that already contain at least one subject
    // --------------------------------------------------
    if ($role === 'admin') {

        $sql = "
            SELECT DISTINCT
                h.prospectus_id,
                h.cmo_no,
                h.effective_sy,
                p.program_name,
                p.program_code,
                p.major,
                ca.campus_code
            FROM tbl_prospectus_header h
            INNER JOIN tbl_program p 
                ON p.program_id = h.program_id
            INNER JOIN tbl_college c 
                ON c.college_id = p.college_id
            INNER JOIN tbl_campus ca 
                ON ca.campus_id = c.campus_id
            WHERE EXISTS (
                SELECT 1
                FROM tbl_prospectus_year_sem ys
                INNER JOIN tbl_prospectus_subjects ps ON ps.pys_id = ys.pys_id
                WHERE ys.prospectus_id = h.prospectus_id
            )
            ORDER BY h.prospectus_id DESC
        ";


    }
    // --------------------------------------------------
    // NON-ADMIN (Scheduler): Same rule, but college-bound
    // --------------------------------------------------
    else {

        $sql = "
            SELECT DISTINCT
                h.prospectus_id,
                h.cmo_no,
                h.effective_sy,
                p.program_name,
                p.program_code,
                p.major
            FROM tbl_prospectus_header h
            INNER JOIN tbl_program p 
                ON p.program_id = h.program_id
            WHERE p.college_id = ?
              AND EXISTS (
                SELECT 1
                FROM tbl_prospectus_year_sem ys
                INNER JOIN tbl_prospectus_subjects ps ON ps.pys_id = ys.pys_id
                WHERE ys.prospectus_id = h.prospectus_id
              )
            ORDER BY h.prospectus_id DESC
        ";
    }

    // --------------------------------------------------
    // EXECUTE QUERY
    // --------------------------------------------------
    if ($role === 'admin') {
        $stmt = $conn->prepare($sql);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $myCollege);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    $stmt->close();

    echo json_encode($data);
    exit;
}



if (isset($_POST['list_headers'])) {

    $sql = "
        SELECT h.prospectus_id, h.cmo_no, h.effective_sy,
               p.program_name, p.program_code
        FROM tbl_prospectus_header h
        LEFT JOIN tbl_program p ON p.program_id = h.program_id
        ORDER BY h.prospectus_id DESC
    ";

    $res = $conn->query($sql);

    while ($row = $res->fetch_assoc()) {
        $id    = $row['prospectus_id'];
        $label = "{$row['program_code']} — {$row['cmo_no']} — {$row['effective_sy']}";
        echo "<option value='$id'>$label</option>";
    }
    exit;
}


// ======================================================================
// LOAD PROSPECTUS HEADER DETAILS (PIPE RESPONSE)
// ======================================================================
if (isset($_POST['load_header'])) {

    $prospectus_id = intval($_POST['prospectus_id'] ?? 0);

    if ($prospectus_id <= 0) {
        echo "ERROR|Invalid prospectus ID";
        exit;
    }
    if (!can_access_prospectus($conn, $prospectus_id, $role, $myCollege)) {
        echo "ERROR|Unauthorized prospectus access";
        exit;
    }

    global $conn;

    $sql = "SELECT program_id, cmo_no, effective_sy, remarks
            FROM tbl_prospectus_header
            WHERE prospectus_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $prospectus_id);
    $stmt->execute();
    $stmt->bind_result($program_id, $cmo_no, $effective_sy, $remarks);

    if ($stmt->fetch()) {
        $stmt->close();
        $cmo_no = str_replace('|', '/', $cmo_no);
        $effective_sy = str_replace('|', '/', $effective_sy);
        $remarks = str_replace('|', '/', (string)$remarks);
        echo "OK|$program_id|$cmo_no|$effective_sy|$remarks";
    } else {
        $stmt->close();
        echo "ERROR|Prospectus not found";
    }
    exit;
}




// ------------------------------------
// Helper: Labels
// ------------------------------------
function year_label($y) {
    switch ($y) {
        case '1': return 'First Year';
        case '2': return 'Second Year';
        case '3': return 'Third Year';
        case '4': return 'Fourth Year';
        default:  return 'Year ' . $y;
    }
}
function sem_label($s) {
    switch ($s) {
        case '1': return 'First Semester';
        case '2': return 'Second Semester';
        case '3': return 'Summer';
        default:  return 'Semester ' . $s;
    }
}

// ======================================================================
// 1️⃣ SAVE / LOAD PROSPECTUS HEADER
//    Called from: #btnSaveProspectus click
//    RETURNS: STATUS|prospectus_id|message
// ======================================================================
if (isset($_POST['save_header'])) {

    $program_id   = intval($_POST['program_id'] ?? 0);
    $cmo_no       = trim($_POST['cmo_no'] ?? '');
    $effective_sy = trim($_POST['effective_sy'] ?? '');
    $remarks      = trim($_POST['remarks'] ?? '');

    if ($program_id <= 0 || $cmo_no === '' || $effective_sy === '') {
        echo "ERROR|0|Missing required fields (Program, CMO No, Effectivity SY).";
        exit;
    }
    if (strpos($cmo_no, '|') !== false || strpos($effective_sy, '|') !== false || strpos($remarks, '|') !== false) {
        echo "ERROR|0|Pipe character (|) is not allowed.";
        exit;
    }

    global $conn;

    if ($role === 'scheduler') {
        $chk = $conn->prepare("SELECT program_id FROM tbl_program WHERE program_id = ? AND college_id = ? LIMIT 1");
        $chk->bind_param("ii", $program_id, $myCollege);
        $chk->execute();
        $allowed = $chk->get_result()->num_rows > 0;
        $chk->close();
        if (!$allowed) {
            echo "ERROR|0|Unauthorized program selection.";
            exit;
        }
    }

    // Check if this header already exists
    $sql = "SELECT prospectus_id 
            FROM tbl_prospectus_header
            WHERE program_id = ? 
              AND cmo_no = ? 
              AND effective_sy = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $program_id, $cmo_no, $effective_sy);
    $stmt->execute();
    $stmt->bind_result($prospectus_id_existing);

    if ($stmt->fetch()) {
        // Existing header
        $stmt->close();
        echo "EXISTING|{$prospectus_id_existing}|Existing prospectus loaded.";
        exit;
    }
    $stmt->close();

    // Insert new header
    $sql = "INSERT INTO tbl_prospectus_header (program_id, cmo_no, effective_sy, remarks)
            VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $program_id, $cmo_no, $effective_sy, $remarks);
    $ok = $stmt->execute();

    if ($ok) {
        $new_id = $stmt->insert_id;
        $stmt->close();

        echo "OK|{$new_id}|New prospectus created successfully.";
        exit;
    } else {
        $err = $stmt->error;
        $stmt->close();

        echo "ERROR|0|Database error while creating prospectus: {$err}";
        exit;
    }
}

// ======================================================================
// 2️⃣ SAVE YEAR & SEMESTER
//    Called from: #btnSaveYearSem click
//    RETURNS: OK|pys_id|msg OR EXISTS|pys_id|msg OR ERROR|0|msg
// ======================================================================
if (isset($_POST['save_year_sem'])) {

    $prospectus_id = intval($_POST['prospectus_id'] ?? 0);
    $year_level    = trim($_POST['year_level'] ?? '');
    $semester      = trim($_POST['semester'] ?? '');

    if ($prospectus_id <= 0 || $year_level === '' || $semester === '') {
        echo "ERROR|0|Missing prospectus, year level, or semester.";
        exit;
    }
    if (!can_access_prospectus($conn, $prospectus_id, $role, $myCollege)) {
        echo "ERROR|0|Unauthorized prospectus access.";
        exit;
    }

    global $conn;

    // Check if already exists (unique per prospectus)
    $sql = "SELECT pys_id
            FROM tbl_prospectus_year_sem
            WHERE prospectus_id = ?
              AND year_level = ?
              AND semester = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $prospectus_id, $year_level, $semester);
    $stmt->execute();
    $stmt->bind_result($pys_id_existing);

    if ($stmt->fetch()) {
        $stmt->close();

        echo "EXISTS|{$pys_id_existing}|Year & semester already exists for this prospectus.";
        exit;
    }
    $stmt->close();

    // Insert a new Year/Sem entry
    $sql = "INSERT INTO tbl_prospectus_year_sem (prospectus_id, year_level, semester)
            VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $prospectus_id, $year_level, $semester);
    $ok = $stmt->execute();

    if ($ok) {
        $new_pys_id = $stmt->insert_id;
        $stmt->close();

        echo "OK|{$new_pys_id}|Year & semester added successfully.";
        exit;
    } else {
        $err = $stmt->error;
        $stmt->close();

        echo "ERROR|0|Database error while saving year & semester: {$err}";
        exit;
    }
}

// ======================================================================
// 3️⃣ SAVE SUBJECT UNDER A SPECIFIC YEAR/SEM
//    Called from: #btnSaveProspectusSubject click
//    RETURNS: OK|ps_id|msg OR EXISTS|ps_id|msg OR ERROR|0|msg
// ======================================================================
// ======================================================================
// 3️⃣ SAVE SUBJECT UNDER A SPECIFIC YEAR/SEM
// ======================================================================
if (isset($_POST['save_prospectus_subject'])) {

    // 🔒 SAFETY GUARD — THIS IS THE FIX
    if (isset($_POST['ps_id']) && intval($_POST['ps_id']) > 0) {
        echo "ERROR|EDIT_MODE|Insert blocked during edit.";
        exit;
    }    

    $pys_id        = intval($_POST['pys_id'] ?? 0);
    $sub_id        = intval($_POST['sub_id'] ?? 0);
    $lec_units     = (float)($_POST['lec_units'] ?? 0);
    $lab_units     = (float)($_POST['lab_units'] ?? 0);
    $total_units   = prospectus_total_units_from_hours($lec_units, $lab_units);
    $sort_order    = intval($_POST['sort_order'] ?? 1);
    $prerequisites = trim($_POST['prerequisites'] ?? '');
    $prereq_ids_json = $_POST['prerequisite_sub_ids'] ?? null;

    if ($pys_id <= 0 || $sub_id <= 0) {
        echo "ERROR|0|Missing Year/Sem (pys_id) or Subject.";
        exit;
    }
    if (!can_access_pys($conn, $pys_id, $role, $myCollege)) {
        echo "ERROR|0|Unauthorized Year/Sem access.";
        exit;
    }
    if ($lec_units < 0 || $lab_units < 0 || $sort_order < 1) {
        echo "ERROR|0|Invalid units or sort order.";
        exit;
    }

    global $conn;

    // CHECK IF SUBJECT ALREADY EXISTS
    $sql = "SELECT ps_id
            FROM tbl_prospectus_subjects
            WHERE pys_id = ?
              AND sub_id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $pys_id, $sub_id);
    $stmt->execute();
    $stmt->bind_result($ps_existing);

    if ($stmt->fetch()) {
        $stmt->close();
        echo "EXISTS|{$ps_existing}|Subject already exists in this year & semester.";
        exit;
    }
    $stmt->close();

    // INSERT NEW SUBJECT
    $sql = "INSERT INTO tbl_prospectus_subjects 
            (pys_id, sub_id, lec_units, lab_units, total_units, prerequisites, prerequisite_sub_ids, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        echo "ERROR|0|Prepare failed: " . $conn->error;
        exit;
    }

$stmt->bind_param(
    "iidddssi",
    $pys_id,
    $sub_id,
    $lec_units,
    $lab_units,
    $total_units,
    $prerequisites,
    $prereq_ids_json,
    $sort_order
);

    if ($stmt->execute()) {
        $new_ps_id = $stmt->insert_id;
        $stmt->close();
        echo "OK|{$new_ps_id}|Subject added successfully.";
        exit;
    } else {
        $err = $stmt->error;
        $stmt->close();
        echo "ERROR|0|Database error: {$err}";
        exit;
    }
}


// ======================================================================
// 4️⃣ DELETE SUBJECT FROM PROSPECTUS
//    Called from: .btnDeleteSubject
//    RETURNS: OK|msg OR ERROR|msg
// ======================================================================
if (isset($_POST['delete_prospectus_subject'])) {

    $ps_id = intval($_POST['ps_id'] ?? 0);

    if ($ps_id <= 0) {
        echo "ERROR|Invalid subject reference.";
        exit;
    }
    if (!can_access_ps($conn, $ps_id, $role, $myCollege)) {
        echo "ERROR|Unauthorized subject access.";
        exit;
    }

    global $conn;

    $sql  = "DELETE FROM tbl_prospectus_subjects WHERE ps_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $ps_id);
    $ok   = $stmt->execute();
    $stmt->close();

    if ($ok) {
        echo "OK|Subject removed from prospectus.";
        exit;
    } else {
        echo "ERROR|Unable to delete subject.";
        exit;
    }
}

// ======================================================================
// 5️⃣ LOAD YEAR/SEM ACCORDION + SUBJECT TABLES (HTML)
//    Called from: loadYearSem(prospectus_id)
// ======================================================================
if (isset($_POST['load_year_sem'])) {

    $prospectus_id = intval($_POST['prospectus_id'] ?? 0);

    if ($prospectus_id <= 0) {
        echo '<p class="text-muted">No prospectus selected.</p>';
        exit;
    }
    if (!can_access_prospectus($conn, $prospectus_id, $role, $myCollege)) {
        echo '<p class="text-muted">Unauthorized prospectus access.</p>';
        exit;
    }

    global $conn;

    $sql = "SELECT pys_id, year_level, semester
            FROM tbl_prospectus_year_sem
            WHERE prospectus_id = ?
            ORDER BY year_level ASC, semester ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $prospectus_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 0) {
        echo '<p class="text-muted mb-0">No year & semester entries yet. Click "Add Year / Semester" to start.</p>';
        exit;
    }

    // We will echo accordion items
    while ($row = $result->fetch_assoc()) {
        $pys_id     = $row['pys_id'];
        $year_level = $row['year_level'];
        $semester   = $row['semester'];

        $yl_label  = year_label($year_level);
        $sem_label = sem_label($semester);

        // Fetch subjects under this PYS
        $sub_sql = "SELECT ps.ps_id,
                   ps.lec_units,
                   ps.lab_units,
                   ps.total_units,
                   ps.prerequisites,
                   ps.sort_order,
                   s.sub_code,
                   s.sub_description
            FROM tbl_prospectus_subjects ps
            INNER JOIN tbl_subject_masterlist s ON ps.sub_id = s.sub_id
            WHERE ps.pys_id = ?
            ORDER BY ps.sort_order ASC, s.sub_code ASC";
        $sub_stmt = $conn->prepare($sub_sql);
        $sub_stmt->bind_param("i", $pys_id);
        $sub_stmt->execute();
        $sub_res = $sub_stmt->get_result();

        $rows_html       = '';
        $total_lec_sum   = 0;
        $total_lab_sum   = 0;
        $total_units_sum = 0;
        $ctr             = 1;

            while ($s = $sub_res->fetch_assoc()) {
                $lecu   = (float)$s['lec_units'];
                $labu   = (float)$s['lab_units'];
                $storedTotal = isset($s['total_units']) && $s['total_units'] !== null
                    ? (float)$s['total_units']
                    : null;
                $displayValues = prospectus_display_values($lecu, $labu, $storedTotal);
                $displayLecHours = $displayValues['lec_hours'];
                $displayLabHours = $displayValues['lab_hours'];
                $t_units = $displayValues['total_units'];
                $total_lec_sum += $displayLecHours;
                $total_lab_sum += $displayLabHours;
                $total_units_sum += $t_units;

                $ps_id   = $s['ps_id'];
                $code    = htmlspecialchars(strtoupper($s['sub_code']));
                $title   = htmlspecialchars(strtoupper($s['sub_description']));
                $prereq  = htmlspecialchars($s['prerequisites']);

                $rows_html .= '
                  <tr>
                    <td class="text-center">' . $ctr++ . '</td>
                    <td>' . $code . '</td>
                    <td>' . $title . '</td>
                    <td class="text-center">' . prospectus_number_display($displayLecHours) . '</td>
                    <td class="text-center">' . prospectus_number_display($displayLabHours) . '</td>
                    <td class="text-center">' . prospectus_number_display($t_units) . '</td>
                    <td>' . $prereq . '</td>
                    <td class="text-center text-nowrap">
                      <!-- EDIT (NEW) -->
                      <button type="button"
                              class="btn btn-sm btn-icon text-primary btnEditSubject"
                              data-ps="' . $ps_id . '"
                              title="Edit Subject">
                        <i class="bx bx-edit"></i>
                      </button>

                      <button type="button" 
                              class="btn btn-sm btn-icon text-danger btnDeleteSubject"
                              data-ps="' . $ps_id . '"
                              title="Remove Subject">
                        <i class="bx bx-trash"></i>
                      </button>
                    </td>
                  </tr>
                ';
            }
        $sub_stmt->close();

        if ($rows_html === '') {
            $rows_html = '
              <tr>
                <td colspan="8" class="text-center text-muted">
                  No subjects encoded yet for this year and semester.
                </td>
              </tr>
            ';
        }

        ?>
        <div class="accordion-item custom-yearsem-item">
            <h2 class="accordion-header" id="heading<?= $pys_id ?>">
              <div class="d-flex justify-content-between align-items-center w-100 px-2">

                <!-- LEFT SIDE: LABEL + TOTAL UNITS -->
                <button class="accordion-button collapsed flex-grow-1 text-start" 
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#collapse<?= $pys_id ?>"
                        aria-expanded="false"
                        aria-controls="collapse<?= $pys_id ?>">

                    <span class="fw-semibold">
                        <?= $yl_label . ' – ' . $sem_label ?>
                    </span>

                    <span class="badge bg-label-primary ms-2">
                        Total Units: <?= prospectus_number_display($total_units_sum) ?>
                    </span>

                </button>

                <!-- RIGHT SIDE: DELETE BUTTON -->
                <button type="button"
                        class="btn btn-sm btn-outline-danger ms-2 btnDeleteYearSem"
                        data-pys="<?= $pys_id ?>"
                        title="Delete Year & Semester">
                    <i class="bx bx-trash"></i>
                </button>

              </div>
            </h2>

          <div id="collapse<?= $pys_id ?>" class="accordion-collapse collapse"
               aria-labelledby="heading<?= $pys_id ?>"
               data-bs-parent="#yearSemAccordion">
            <div class="accordion-body">

              <div class="d-flex justify-content-between align-items-center mb-2">
                <small class="text-muted">
                  Subjects under <?= $yl_label . ' – ' . $sem_label ?>
                </small>
                <button type="button"
                        class="btn btn-sm btn-outline-primary btnAddSubject"
                        data-pys="<?= $pys_id ?>">
                  <i class="bx bx-plus"></i> Add Subject
                </button>
              </div>

              <div class="table-responsive">
                <table class="table table-sm table-striped table-bordered table-prospectus mb-2">
                  <thead class="table-light">
                    <tr>
                      <th style="width: 40px;" class="text-center">#</th>
                      <th style="width: 120px;">Course Code</th>
                      <th>Descriptive Title</th>
                      <th style="width: 70px;" class="text-center">Lec Hrs</th>
                      <th style="width: 70px;" class="text-center">Lab Hrs</th>
                      <th style="width: 80px;" class="text-center">Units</th>
                      <th style="width: 150px;">Pre-Requisites</th>
                      <th style="width: 60px;" class="text-center">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?= $rows_html ?>
                  </tbody>
                  <tfoot>
                    <tr>
                      <th colspan="3" class="text-end">Totals</th>
                      <th class="text-center"><?= prospectus_number_display($total_lec_sum) ?></th>
                      <th class="text-center"><?= prospectus_number_display($total_lab_sum) ?></th>
                      <th class="text-center"><?= prospectus_number_display($total_units_sum) ?></th>
                      <th colspan="2"></th>
                    </tr>
                  </tfoot>
                </table>
              </div>

            </div>
          </div>
        </div>
        <?php
    }

    exit;
}

// ======================================================================
// DEFAULT FALLBACK
// ======================================================================
echo "Invalid request.";
exit;
