<?php
session_start();
include 'db.php';
require_once __DIR__ . '/offering_scope_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'scheduler') {
    echo json_encode([]);
    exit;
}

$college_id = (int)$_SESSION['college_id'];

/* ==========================================================
   MAP SEMESTER LABEL → DB VALUE
========================================================== */
function mapSemester($s) {
    if ($s === '1st') return '1';
    if ($s === '2nd') return '2';
    if ($s === 'Midyear') return '3';
    return '';
}

/* ==========================================================
   SINGLE ROOM SCHEDULE
========================================================== */
if (isset($_POST['load_room_schedule'])) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 'sec', 'sc', 'ps', 'pys', 'ph');

    $ay       = trim($_POST['ay']);
    $semester = mapSemester($_POST['semester']);
    $room_id  = (int)$_POST['room_id'];

    if (!$ay || !$semester || !$room_id) {
        echo json_encode([]);
        exit;
    }

    $sql = "
        SELECT
            cs.time_start,
            cs.time_end,
            cs.days_json,
            sm.sub_code AS subject_code,
            sec.full_section AS section_name,
            COALESCE(
                CONCAT(f.last_name, ', ', f.first_name),
                'TBA'
            ) AS faculty_name
        FROM tbl_class_schedule cs
        INNER JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        INNER JOIN tbl_academic_years ay
            ON ay.ay_id = po.ay_id
        LEFT JOIN tbl_faculty_workload_sched fws
            ON fws.schedule_id = cs.schedule_id
        LEFT JOIN tbl_faculty f
            ON f.faculty_id = fws.faculty_id
        WHERE cs.room_id = ?
          AND ay.ay = ?
          AND po.semester = ?
          AND r.college_id = ?
        ORDER BY cs.time_start
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issi", $room_id, $ay, $semester, $college_id);
    $stmt->execute();

    $res = $stmt->get_result();
    $data = [];

    while ($row = $res->fetch_assoc()) {
        $row['days_raw'] = json_decode($row['days_json'], true) ?: [];
        $data[] = $row;
    }

    echo json_encode($data);
    exit;
}

/* ==========================================================
   ALL ROOMS OVERVIEW
========================================================== */
if (isset($_POST['load_all_rooms'])) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 'sec', 'sc', 'ps', 'pys', 'ph');

    $ay       = trim($_POST['ay']);
    $semester = mapSemester($_POST['semester']);

    if (!$ay || !$semester) {
        echo json_encode([]);
        exit;
    }

    $rooms = [];

    $roomQ = $conn->query("
        SELECT room_id, room_code
        FROM tbl_rooms
        WHERE college_id = $college_id
          AND status = 'active'
        ORDER BY room_code
    ");

    while ($r = $roomQ->fetch_assoc()) {

        $groups = [];

        $sql = "
            SELECT
                cs.time_start,
                cs.time_end,
                cs.days_json,
                sm.sub_code AS subject_code,
                sec.full_section AS section_name,
                COALESCE(
                    CONCAT(f.last_name, ', ', f.first_name),
                    'TBA'
                ) AS faculty_name
            FROM tbl_class_schedule cs
            INNER JOIN tbl_prospectus_offering po
                ON po.offering_id = cs.offering_id
            {$liveOfferingJoins}
            INNER JOIN tbl_subject_masterlist sm
                ON sm.sub_id = ps.sub_id
            INNER JOIN tbl_academic_years ay
                ON ay.ay_id = po.ay_id
            LEFT JOIN tbl_faculty_workload_sched fws
                ON fws.schedule_id = cs.schedule_id
            LEFT JOIN tbl_faculty f
                ON f.faculty_id = fws.faculty_id
            WHERE cs.room_id = ?
              AND ay.ay = ?
              AND po.semester = ?
            ORDER BY cs.time_start
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $r['room_id'], $ay, $semester);
        $stmt->execute();

        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) {

            // Decode days_json → array (ex: ["M","W"])
            $days = json_decode($row['days_json'], true) ?: [];

            // Normalize day pattern key (MW, TTh, F, etc.)
            $dayKey = implode('', $days);

            if (!isset($groups[$dayKey])) {
                $groups[$dayKey] = [];
            }

            $groups[$dayKey][] = $row;
        }

        $rooms[] = [
            "room_id"   => $r['room_id'],
            "room_code" => $r['room_code'],
            "groups"    => $groups
        ];
    }

    echo json_encode($rooms);
    exit;
}

echo json_encode([]);
