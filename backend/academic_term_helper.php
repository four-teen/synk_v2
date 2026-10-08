<?php

function synk_semester_label(int $semester): string
{
    switch ($semester) {
        case 1:
            return '1st Semester';
        case 2:
            return '2nd Semester';
        case 3:
            return 'Midyear';
        default:
            return '';
    }
}

function synk_fetch_current_academic_term(mysqli $conn): array
{
    $default = [
        'ay_id' => 0,
        'semester' => 0,
        'ay_label' => '',
        'semester_label' => '',
        'term_text' => 'Current academic term'
    ];

    $sql = "
        SELECT
            s.current_ay_id,
            s.current_semester,
            ay.ay AS ay_label
        FROM tbl_academic_settings s
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = s.current_ay_id
        LIMIT 1
    ";

    $result = $conn->query($sql);
    if (!$result || $result->num_rows === 0) {
        return $default;
    }

    $row = $result->fetch_assoc();

    $ayId = (int)($row['current_ay_id'] ?? 0);
    $semester = (int)($row['current_semester'] ?? 0);
    $ayLabel = (string)($row['ay_label'] ?? '');
    $semesterLabel = synk_semester_label($semester);

    $termParts = array_filter([$ayLabel, $semesterLabel], static function ($value) {
        return $value !== '';
    });

    return [
        'ay_id' => $ayId,
        'semester' => $semester,
        'ay_label' => $ayLabel,
        'semester_label' => $semesterLabel,
        'term_text' => !empty($termParts) ? implode(' - ', $termParts) : $default['term_text']
    ];
}
