<?php
session_start();
ob_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'scheduler') {
    echo "unauthorized";
    exit;
}

$college_id = $_SESSION['college_id'] ?? 0;

/* ============================================================
   HELPER: TIME OVERLAP CHECK
   Returns true if two time intervals overlap
   ============================================================ */
function time_overlaps($start1, $end1, $start2, $end2) {
    // Assume times are in "HH:MM" or "HH:MM:SS"
    return ($start1 < $end2) && ($end1 > $start2);
}

/* ============================================================
   SAVE WORKLOAD
   ============================================================ */
if (isset($_POST['save_workload'])) {

    $faculty_id = intval($_POST['faculty_id']);
    $ay         = mysqli_real_escape_string($conn, $_POST['ay']);
    $semester   = mysqli_real_escape_string($conn, $_POST['semester']);

    $program_id = intval($_POST['program_id']);
    $year_level = intval($_POST['year_level']);
    $section_id = intval($_POST['section_id']);
    $subject_id = intval($_POST['subject_id']);

    // days JSON (e.g. ["M","W","F"])
    $days_raw = $_POST['days'] ?? '[]';
    $days_arr = json_decode($days_raw, true);
    if (!is_array($days_arr)) {
        $days_arr = [];
    }
    if (empty($days_arr)) {
        echo "Please select at least one day.";
        exit;
    }
    $days_json  = mysqli_real_escape_string($conn, $days_raw);

    $time_start = mysqli_real_escape_string($conn, $_POST['time_start']);
    $time_end   = mysqli_real_escape_string($conn, $_POST['time_end']);
    $room_id    = intval($_POST['room_id']);

    $units      = floatval($_POST['units']);
    $hours_lec  = floatval($_POST['hours_lec']);
    $hours_lab  = floatval($_POST['hours_lab']);
    $load_value = floatval($_POST['load_value']);

    // basic guard
    if (!$faculty_id || !$program_id || !$section_id || !$subject_id || !$ay) {
        echo "Invalid data.";
        exit;
    }

    if ($time_end <= $time_start) {
        echo "Invalid time range.";
        exit;
    }

    /* ========================================================
       CONFLICT CHECK 1: FACULTY SCHEDULE
       - same faculty, same AY, same semester
       - overlapping time
       - at least one common day
       ======================================================== */
    $sql_faculty_conflict = "
        SELECT 
            fw.*, 
            s.sub_code, 
            sec.section_name,
            r.room_code
        FROM tbl_faculty_workload fw
        JOIN tbl_subject_masterlist s ON fw.subject_id = s.sub_id
        JOIN tbl_sections sec         ON fw.section_id = sec.section_id
        JOIN tbl_rooms r              ON fw.room_id = r.room_id
        WHERE fw.faculty_id = '$faculty_id'
          AND fw.ay = '$ay'
          AND fw.semester = '$semester'
          AND fw.time_start < '$time_end'
          AND fw.time_end   > '$time_start'
    ";
    $run_fc = mysqli_query($conn, $sql_faculty_conflict);
    while ($row = mysqli_fetch_assoc($run_fc)) {

        $existing_days = json_decode($row['days_json'], true);
        if (!is_array($existing_days)) $existing_days = [];

        $common_days = array_intersect($days_arr, $existing_days);
        if (!empty($common_days)) {

            $days_display = implode(", ", $existing_days);
            $time_display = date('g:iA', strtotime($row['time_start'])) . '–' .
                            date('g:iA', strtotime($row['time_end']));

            echo "Faculty schedule conflict detected. The selected faculty already has a class on "
                 . implode(", ", $common_days) . " at {$time_display} "
                 . "({$row['sub_code']} - {$row['section_name']}, Room {$row['room_code']}).";
            exit;
        }
    }

    /* ========================================================
       CONFLICT CHECK 2: ROOM SCHEDULE
       - same room, same AY, same semester
       - overlapping time
       - at least one common day
       ======================================================== */
    $sql_room_conflict = "
        SELECT 
            fw.*, 
            s.sub_code, 
            sec.section_name
        FROM tbl_faculty_workload fw
        JOIN tbl_subject_masterlist s ON fw.subject_id = s.sub_id
        JOIN tbl_sections sec         ON fw.section_id = sec.section_id
        WHERE fw.room_id = '$room_id'
          AND fw.ay = '$ay'
          AND fw.semester = '$semester'
          AND fw.time_start < '$time_end'
          AND fw.time_end   > '$time_start'
    ";
    $run_rc = mysqli_query($conn, $sql_room_conflict);
    while ($row = mysqli_fetch_assoc($run_rc)) {

        $existing_days = json_decode($row['days_json'], true);
        if (!is_array($existing_days)) $existing_days = [];

        $common_days = array_intersect($days_arr, $existing_days);
        if (!empty($common_days)) {

            $days_display = implode(", ", $existing_days);
            $time_display = date('g:iA', strtotime($row['time_start'])) . '–' .
                            date('g:iA', strtotime($row['time_end']));

            echo "Room conflict detected. The selected room is already in use on "
                 . implode(", ", $common_days) . " at {$time_display} "
                 . "({$row['sub_code']} - {$row['section_name']}).";
            exit;
        }
    }

    /* ========================================================
       CONFLICT CHECK 3: SECTION SCHEDULE
       - same section, same AY, same semester
       - overlapping time
       - at least one common day
       ======================================================== */
    $sql_section_conflict = "
        SELECT 
            fw.*, 
            s.sub_code
        FROM tbl_faculty_workload fw
        JOIN tbl_subject_masterlist s ON fw.subject_id = s.sub_id
        WHERE fw.section_id = '$section_id'
          AND fw.ay = '$ay'
          AND fw.semester = '$semester'
          AND fw.time_start < '$time_end'
          AND fw.time_end   > '$time_start'
    ";
    $run_sc = mysqli_query($conn, $sql_section_conflict);
    while ($row = mysqli_fetch_assoc($run_sc)) {

        $existing_days = json_decode($row['days_json'], true);
        if (!is_array($existing_days)) $existing_days = [];

        $common_days = array_intersect($days_arr, $existing_days);
        if (!empty($common_days)) {

            $time_display = date('g:iA', strtotime($row['time_start'])) . '–' .
                            date('g:iA', strtotime($row['time_end']));

            echo "Section conflict detected. This section already has another class on "
                 . implode(", ", $common_days) . " at {$time_display} "
                 . "({$row['sub_code']}).";
            exit;
        }
    }

    /* ========================================================
       IF NO CONFLICTS → PROCEED TO INSERT
       ======================================================== */
    $sql_insert = "
        INSERT INTO tbl_faculty_workload 
        (faculty_id, program_id, section_id, year_level, subject_id, ay, semester,
         days_json, time_start, time_end, room_id, units, hours_lec, hours_lab, load_value)
        VALUES
        ('$faculty_id','$program_id','$section_id','$year_level','$subject_id','$ay','$semester',
         '$days_json','$time_start','$time_end','$room_id','$units','$hours_lec','$hours_lab','$load_value')
    ";

    if (mysqli_query($conn, $sql_insert)) {
        echo "success";
    } else {
        echo "DB Error: " . mysqli_error($conn);
    }
    exit;
}


/* ============================================================
   LOAD WORKLOAD LIST FOR FACULTY + AY + SEM
   ============================================================ */
if (isset($_POST['load_workload'])) {

    $faculty_id = intval($_POST['faculty_id']);
    $ay         = mysqli_real_escape_string($conn, $_POST['ay']);
    $semester   = mysqli_real_escape_string($conn, $_POST['semester']);

    $data = [];

    $sql = "
        SELECT 
            fw.workload_id,
            fw.days_json,
            fw.time_start,
            fw.time_end,
            fw.units,
            fw.hours_lec,
            fw.hours_lab,
            fw.load_value,
            s.sub_code,
            s.sub_description,
            sec.section_name,
            r.room_code
        FROM tbl_faculty_workload fw
        JOIN tbl_subject_masterlist s ON fw.subject_id = s.sub_id
        JOIN tbl_sections sec         ON fw.section_id = sec.section_id
        JOIN tbl_rooms r              ON fw.room_id = r.room_id
        WHERE fw.faculty_id = '$faculty_id'
          AND fw.ay = '$ay'
          AND fw.semester = '$semester'
        ORDER BY fw.year_level, sec.section_name, s.sub_code, fw.time_start
    ";

    $run = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($run)) {

        $days_arr = json_decode($row['days_json'], true);
        if (!is_array($days_arr)) $days_arr = [];

        // example: "M, W, F"
        $days_display = implode(", ", $days_arr);

        // time example: "7:30AM–9:00AM"
        $time_display = date('g:iA', strtotime($row['time_start'])) . '–' .
                        date('g:iA', strtotime($row['time_end']));

        $data[] = [
            'workload_id'  => $row['workload_id'],
            'subject_code' => $row['sub_code'],
            'subject_desc' => $row['sub_description'],
            'section_name' => $row['section_name'],
            'days_display' => $days_display,             // for list view
            'time_display' => $time_display,             // for list view
            'room_code'    => $row['room_code'],
            'units'        => $row['units'],
            'hours_lec'    => $row['hours_lec'],
            'hours_lab'    => $row['hours_lab'],
            'load_value'   => number_format($row['load_value'], 2, '.', ''),
            // 👇 NEW: raw data for timetable rendering
            'time_start'   => $row['time_start'],        // e.g. "07:30:00"
            'time_end'     => $row['time_end'],          // e.g. "09:00:00"
            'days_raw'     => $days_arr                  // e.g. ["M","W","F"]
        ];

    }

    echo json_encode($data);
    exit;
}


/* ============================================================
   LOAD ROOM SCHEDULE (PER ROOM, AY, SEMESTER)
   ============================================================ */
if (isset($_POST['load_room_schedule'])) {

    $ay       = mysqli_real_escape_string($conn, $_POST['ay'] ?? '');
    $semester = mysqli_real_escape_string($conn, $_POST['semester'] ?? '');
    $room_id  = intval($_POST['room_id'] ?? 0);

    if (!$ay || !$room_id) {
        echo json_encode([]);
        exit;
    }

    $data = [];

    $sql = "
        SELECT 
            fw.workload_id,
            fw.days_json,
            fw.time_start,
            fw.time_end,
            fw.units,
            fw.hours_lec,
            fw.hours_lab,
            fw.load_value,
            s.sub_code,
            s.sub_description,
            sec.section_name,
            r.room_code,
            CONCAT(f.last_name, ', ', f.first_name, ' ', COALESCE(f.ext_name,'')) AS faculty_name
        FROM tbl_faculty_workload fw
        JOIN tbl_subject_masterlist s ON fw.subject_id = s.sub_id
        JOIN tbl_sections sec         ON fw.section_id = sec.section_id
        JOIN tbl_rooms r              ON fw.room_id = r.room_id
        JOIN tbl_faculty f           ON fw.faculty_id = f.faculty_id
        WHERE fw.room_id = '$room_id'
          AND fw.ay = '$ay'
          AND fw.semester = '$semester'
        ORDER BY fw.time_start, sec.section_name, s.sub_code
    ";

    $run = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($run)) {

        $days_arr = json_decode($row['days_json'], true);
        if (!is_array($days_arr)) $days_arr = [];

        $data[] = [
            'workload_id'  => $row['workload_id'],
            'subject_code' => $row['sub_code'],
            'subject_desc' => $row['sub_description'],
            'section_name' => $row['section_name'],
            'room_code'    => $row['room_code'],
            'faculty_name' => $row['faculty_name'],
            'units'        => $row['units'],
            'hours_lec'    => $row['hours_lec'],
            'hours_lab'    => $row['hours_lab'],
            'load_value'   => number_format($row['load_value'], 2, '.', ''),
            'time_start'   => $row['time_start'],   // raw "HH:MM:SS"
            'time_end'     => $row['time_end'],
            'days_raw'     => $days_arr            // array ["M","W","F"]
        ];
    }

    echo json_encode($data);
    exit;
}


/* ============================================================
   LOAD ALL ROOM SCHEDULES (OVERVIEW MODE)
   ============================================================ */
if (isset($_POST['load_all_rooms'])) {

    $ay       = mysqli_real_escape_string($conn, $_POST['ay'] ?? '');
    $semester = mysqli_real_escape_string($conn, $_POST['semester'] ?? '');
    $college_id = $_SESSION['college_id'];
   

    if (!$ay) {
        echo json_encode([]);
        exit;
    }

    // Load all rooms first
    $rooms = [];
    $room_sql = "SELECT room_id, room_code, room_name 
                 FROM tbl_rooms 
                 WHERE college_id='$college_id'
                 ORDER BY room_code";
    $room_res = mysqli_query($conn, $room_sql);
    while ($r = mysqli_fetch_assoc($room_res)) {
        $rooms[$r['room_id']] = [
            'room_id'   => $r['room_id'],
            'room_code' => $r['room_code'],
            'room_name' => $r['room_name'],
            'items'     => []  // placeholder for schedules
        ];
    }

    // Load all schedules under the AY + Semester
$sql = "
    SELECT 
        fw.room_id,
        fw.days_json,
        fw.time_start,
        fw.time_end,
        s.sub_code,
        sec.section_name,
        CONCAT(f.first_name, ' ', f.last_name) AS faculty_name
    FROM tbl_faculty_workload fw
    JOIN tbl_subject_masterlist s ON fw.subject_id = s.sub_id
    JOIN tbl_sections sec         ON fw.section_id = sec.section_id
    JOIN tbl_faculty f            ON fw.faculty_id = f.faculty_id     -- ⭐ ADD THIS
    WHERE fw.ay = '$ay'
      AND fw.semester = '$semester'
    ORDER BY fw.time_start
";

    $run = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($run)) {

        $days_arr = json_decode($row['days_json'], true);
        if (!is_array($days_arr)) $days_arr = [];

        $rooms[$row['room_id']]['items'][] = [
            'days_raw'     => $days_arr,
            'time_start'   => $row['time_start'],
            'time_end'     => $row['time_end'],
            'subject_code' => $row['sub_code'],
            'section_name' => $row['section_name'],
            'faculty_name' => $row['faculty_name']   // ⭐ ADD THIS
        ];
    }

    echo json_encode(array_values($rooms));
    exit;
}


/* ============================================================
   DELETE WORKLOAD
   ============================================================ */
if (isset($_POST['delete_workload'])) {

    $id = intval($_POST['workload_id']);

    $sql = "DELETE FROM tbl_faculty_workload WHERE workload_id = '$id' LIMIT 1";

    if (mysqli_query($conn, $sql)) {
        echo "success";
    } else {
        echo "DB Error: " . mysqli_error($conn);
    }
    exit;
}

echo "no_action";
