<?php
require_once __DIR__ . '/faculty_term_designation_helper.php';

require_once __DIR__ . '/college_dean_dashboard_helper.php';
require_once __DIR__ . '/faculty_profile_helper.php';
require_once __DIR__ . '/faculty_profile_image_helper.php';

function synk_dean_profile_id($value): ?int
{
    if (is_int($value)) return $value > 0 ? $value : null;
    return is_string($value) && preg_match('/^[1-9]\d{0,8}$/D', $value) ? (int)$value : null;
}

function synk_dean_profile_designation_group(string $designation): string
{
    if (preg_match('/\bDEAN\b/i', $designation)) return 'dean';
    // Existing reference data also includes PROGRAM CHAIRMAN and CHAIPERSON.
    if (preg_match('/\bPROGRAM[\s_-]+CHAI(?:R(?:PERSON|MAN|WOMAN)?|PERSON)\b|\bPC\b/i', $designation)) return 'chair';
    return 'faculty';
}

function synk_dean_profile_prepare_storage(mysqli $conn): void
{
    synk_faculty_profile_ensure_table($conn);
    synk_faculty_profile_ensure_ranks($conn);
    synk_faculty_profile_ensure_eligibilities($conn);
    synk_faculty_profile_ensure_education($conn);
    synk_faculty_record_profiles_ensure($conn);
}

function synk_dean_profile_faculties(mysqli $conn, array $scope, array $term, int $facultyId = 0, bool $lock = false): array
{
    // A permanent home college wins over teaching/roster links. A current roster
    // supplies scope only when no permanent college has been recorded.
    $sql = "SELECT f.*, d.designation_id AS designation_id, COALESCE(d.designation_name,'') AS designation_name,
            COALESCE(l.user_id,0) AS profile_user_id, COALESCE(u.email,'') AS profile_email,
            COALESCE(u.username,'') AS account_name
        FROM tbl_faculty f " . synk_faculty_designation_join_sql($conn, (int)$term['ay_id'], (int)$term['semester']) . "
        LEFT JOIN tbl_useraccount_faculty_links l ON l.faculty_id=f.faculty_id
        LEFT JOIN tbl_useraccount u ON u.user_id=l.user_id
        WHERE ((f.college_id=? AND (COALESCE(f.campus_id,0)=0 OR f.campus_id=?))
            OR (COALESCE(f.college_id,0)=0 AND (COALESCE(f.campus_id,0)=0 OR f.campus_id=?)
                AND EXISTS (SELECT 1 FROM tbl_college_faculty cf WHERE cf.faculty_id=f.faculty_id
                    AND cf.college_id=? AND cf.ay_id=? AND cf.semester=? AND cf.status='active')))
            AND (?=0 OR f.faculty_id=?)
        ORDER BY f.last_name,f.first_name,f.faculty_id" . ($lock ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiiiiiii', $scope['college_id'], $scope['campus_id'], $scope['campus_id'],
        $scope['college_id'], $term['ay_id'], $term['semester'], $facultyId, $facultyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['faculty_id'] = (int)$row['faculty_id'];
        $row['profile_user_id'] = (int)$row['profile_user_id'];
        $row['faculty_name'] = trim(implode(' ', array_filter([$row['first_name'], $row['middle_name'], $row['last_name'], $row['ext_name']])));
        $row['designation_group'] = synk_dean_profile_designation_group($row['designation_name']);
    }
    unset($row);
    return $rows;
}

function synk_dean_profile_load(mysqli $conn, array $faculty): ?array
{
    return $faculty['profile_user_id'] > 0
        ? synk_faculty_profile_fetch($conn, $faculty['profile_user_id'])
        : synk_faculty_record_profile_fetch($conn, $faculty['faculty_id']);
}

function synk_dean_profile_owner(array $faculty, ?array $profile): string
{
    return synk_faculty_profile_owner($faculty['profile_user_id'], $faculty['faculty_id'], $profile);
}

function synk_dean_profile_directory(mysqli $conn, array $scope, array $term): array
{
    $ranks = synk_faculty_profile_ranks($conn);
    $locations = synk_faculty_profile_locations($conn);
    $eligibilities = synk_faculty_profile_eligibility_catalog($conn);
    $rows = synk_dean_profile_faculties($conn, $scope, $term);
    foreach ($rows as &$row) {
        $profile = synk_dean_profile_load($conn, $row);
        $row['display_name'] = trim((string)($profile['full_name'] ?? '')) ?: $row['faculty_name'];
        $row['profile_state'] = 'not_started';
        $row['completion'] = 0;
        if ($profile) {
            [$values, $errors] = synk_faculty_profile_validate($profile, new DateTimeImmutable('today'), $ranks, $profile, $eligibilities, $locations);
            $fields = synk_faculty_profile_fields($ranks, $profile, $locations);
            $row['completion'] = synk_faculty_profile_completion($values, synk_faculty_profile_completion_rules($fields), $errors)['percent'];
            $row['profile_state'] = $row['completion'] === 100 ? 'complete' : 'in_progress';
        }
        $imageUser = ($profile['_storage'] ?? '') === 'faculty' ? 0 : $row['profile_user_id'];
        $row['profile_image_url'] = synk_faculty_profile_image_url($imageUser, $row['faculty_id']) ?: 'faculty-placeholder.svg';
        $row['faculty_id_number'] = (string)($profile['faculty_id_number'] ?? '');
        // Directory cards do not expose birthdays, salary or other form-only details.
    }
    unset($row);
    return $rows;
}
