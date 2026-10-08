<?php

require_once __DIR__ . '/schema_helper.php';

function synk_offering_enrollee_table_name(): string
{
    return 'tbl_offering_enrollee_counts';
}

function synk_offering_enrollee_table_exists(mysqli $conn): bool
{
    return synk_table_exists($conn, synk_offering_enrollee_table_name());
}

function synk_default_section_enrollee_count(): int
{
    return 50;
}

function synk_normalize_offering_ids(array $offeringIds): array
{
    $normalized = [];

    foreach ($offeringIds as $offeringId) {
        $value = (int)$offeringId;
        if ($value > 0) {
            $normalized[$value] = true;
        }
    }

    return array_keys($normalized);
}

function synk_fetch_offering_enrollee_count_map(mysqli $conn, array $offeringIds): array
{
    $normalizedIds = synk_normalize_offering_ids($offeringIds);
    if (empty($normalizedIds) || !synk_offering_enrollee_table_exists($conn)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
    $types = str_repeat('i', count($normalizedIds));
    $params = array_values($normalizedIds);

    $sql = "
        SELECT offering_id, total_enrollees
        FROM " . synk_offering_enrollee_table_name() . "
        WHERE offering_id IN ({$placeholders})
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    synk_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $res = $stmt->get_result();

    $countMap = [];
    while ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
        $countMap[(int)($row['offering_id'] ?? 0)] = max(0, (int)($row['total_enrollees'] ?? 0));
    }

    $stmt->close();

    return $countMap;
}

function synk_offering_enrollee_count_for_map(array $countMap, int $offeringId): int
{
    return max(0, (int)($countMap[$offeringId] ?? 0));
}

function synk_fetch_offering_imported_student_map(mysqli $conn, array $offeringIds, int $ayId, int $semester): array
{
    $normalizedIds = synk_normalize_offering_ids($offeringIds);
    $studentMap = array_fill_keys($normalizedIds, []);
    if (empty($normalizedIds) || $ayId <= 0 || $semester <= 0
        || !synk_table_exists($conn, 'tbl_student_management_enrolled_subjects')
        || !synk_table_exists($conn, 'tbl_student_management')) {
        return $studentMap;
    }

    $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
    // Match the Dean's class-list lookup, including older imports without an offering ID.
    $stmt = $conn->prepare("
        SELECT DISTINCT po.offering_id, s.student_id
        FROM tbl_prospectus_offering po
        INNER JOIN tbl_prospectus_subjects ps ON ps.ps_id = po.ps_id
        INNER JOIN tbl_program p ON p.program_id = po.program_id
        INNER JOIN tbl_college c ON c.college_id = p.college_id
        INNER JOIN tbl_student_management_enrolled_subjects es
            ON (es.offering_id = po.offering_id OR es.offering_id = 0)
           AND es.program_id = po.program_id
           AND es.section_id = po.section_id
           AND es.subject_id = ps.sub_id
           AND es.college_id = p.college_id
           AND es.campus_id = c.campus_id
        INNER JOIN tbl_student_management s ON s.student_id = es.student_id
        WHERE es.ay_id = ? AND es.semester = ? AND es.is_active = 1
          AND po.offering_id IN ({$placeholders})
    ");
    if (!$stmt) {
        return $studentMap;
    }

    $params = array_merge([$ayId, $semester], $normalizedIds);
    synk_bind_dynamic_params($stmt, str_repeat('i', count($params)), $params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
        $studentMap[(int)$row['offering_id']][(int)$row['student_id']] = true;
    }
    $stmt->close();

    return $studentMap;
}

function synk_offering_imported_student_count(array $studentMap, array $offeringIds): int
{
    $students = [];
    foreach (synk_normalize_offering_ids($offeringIds) as $offeringId) {
        foreach ($studentMap[$offeringId] ?? [] as $studentId => $present) {
            $students[$studentId] = true;
        }
    }

    return count($students);
}
