<?php

require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/schema_helper.php';

function synk_student_preview_student_id_from_request(): int
{
    return max(0, (int)($_GET['preview_student_id'] ?? 0));
}

function synk_student_preview_return_to_url(string $fallback = ''): string
{
    $rawReturnTo = trim((string)($_GET['return_to'] ?? ''));
    if ($rawReturnTo === '' || preg_match('/[\r\n]/', $rawReturnTo)) {
        return $fallback;
    }

    $parts = parse_url($rawReturnTo);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $fallback;
    }

    $path = trim((string)($parts['path'] ?? ''));
    if ($path === '' || strpos($path, '//') === 0) {
        return $fallback;
    }

    $rebuilt = $path;
    if (isset($parts['query']) && trim((string)$parts['query']) !== '') {
        $rebuilt .= '?' . (string)$parts['query'];
    }
    if (isset($parts['fragment']) && trim((string)$parts['fragment']) !== '') {
        $rebuilt .= '#' . (string)$parts['fragment'];
    }

    return $rebuilt;
}

function synk_student_is_admin_preview_mode(): bool
{
    return (string)($_SESSION['role'] ?? '') === 'admin'
        && synk_student_preview_student_id_from_request() > 0;
}

function synk_student_build_portal_url(string $path, array $query = []): string
{
    if (synk_student_is_admin_preview_mode()) {
        $query['preview_student_id'] = synk_student_preview_student_id_from_request();

        $returnTo = synk_student_preview_return_to_url('');
        if ($returnTo !== '') {
            $query['return_to'] = $returnTo;
        }
    }

    $normalizedQuery = [];
    foreach ($query as $key => $value) {
        if ($value === null) {
            continue;
        }

        $stringValue = is_scalar($value) ? trim((string)$value) : '';
        if ($stringValue === '') {
            continue;
        }

        $normalizedQuery[$key] = $stringValue;
    }

    if (empty($normalizedQuery)) {
        return $path;
    }

    return $path . '?' . http_build_query($normalizedQuery);
}

function synk_student_require_login(?mysqli $conn = null, bool $allowAdminPreview = false): void
{
    if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
        header('Location: login.php');
        exit;
    }

    $role = (string)($_SESSION['role'] ?? '');
    if ($allowAdminPreview && $role === 'admin' && synk_student_preview_student_id_from_request() > 0) {
        return;
    }

    if ($role === 'student') {
        $studentEmail = synk_normalize_email((string)($_SESSION['email'] ?? ''));
        if ($studentEmail === '') {
            synk_logout_session();
            header('Location: login.php?auth_status=student_directory_access_denied');
            exit;
        }

        if ($conn instanceof mysqli && !synk_student_directory_email_exists($conn, $studentEmail)) {
            synk_logout_session();
            header('Location: login.php?auth_status=student_directory_access_denied');
            exit;
        }

        if ($conn instanceof mysqli) {
            $profile = synk_student_fetch_portal_profile($conn, $studentEmail);
            $currentPage = basename($_SERVER['PHP_SELF'] ?? '');
            if (!synk_student_portal_profile_is_complete($profile) && $currentPage !== 'index.php') {
                header('Location: index.php?profile_setup=required');
                exit;
            }
        }

        return;
    }

    $redirectPath = synk_role_redirect_path($role);
    header('Location: ../' . ($redirectPath ?? 'index.php'));
    exit;
}

function synk_student_portal_profile_table_name(): string
{
    return 'tbl_student_portal_profile';
}

function synk_student_portal_profile_ensure_schema(mysqli $conn): void
{
    $tableName = synk_student_portal_profile_table_name();
    $sql = "
        CREATE TABLE IF NOT EXISTS `{$tableName}` (
            `profile_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email_address` VARCHAR(255) NOT NULL DEFAULT '',
            `student_number` VARCHAR(32) NOT NULL DEFAULT '',
            `program_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `locked_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`profile_id`),
            UNIQUE KEY `uniq_student_portal_profile_email` (`email_address`),
            KEY `idx_student_portal_profile_program` (`program_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Unable to prepare the student profile setup table.');
    }

    $indexStatements = [
        'uniq_student_portal_profile_email' => "ALTER TABLE `{$tableName}` ADD UNIQUE INDEX `uniq_student_portal_profile_email` (`email_address`)",
        'idx_student_portal_profile_program' => "ALTER TABLE `{$tableName}` ADD INDEX `idx_student_portal_profile_program` (`program_id`)",
    ];

    foreach ($indexStatements as $indexName => $indexSql) {
        if (!synk_table_has_index($conn, $tableName, $indexName)) {
            if (!$conn->query($indexSql)) {
                throw new RuntimeException('Unable to optimize the student portal profile indexes.');
            }
        }
    }
}

function synk_student_portal_program_source_name(string $programName, string $major = ''): string
{
    $parts = [trim($programName)];
    $major = trim($major);
    if ($major !== '') {
        $parts[] = $major;
    }

    return trim(implode(' ', array_filter($parts)));
}

function synk_student_fetch_directory_record_by_student_id(mysqli $conn, int $studentId): ?array
{
    if ($studentId <= 0 || !synk_table_exists($conn, 'tbl_student_management')) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            sm.student_id,
            sm.ay_id,
            sm.semester,
            COALESCE(ay.ay, '') AS academic_year_label,
            COALESCE(c.college_name, '') AS college_name,
            COALESCE(ca.campus_name, '') AS campus_name,
            TRIM(CONCAT_WS(' ', p.program_name, NULLIF(p.major, ''))) AS source_program_name,
            sm.year_level,
            sm.student_number,
            sm.last_name,
            sm.first_name,
            sm.middle_name,
            sm.suffix_name,
            sm.email_address,
            sm.program_id,
            sm.source_file_name,
            sm.created_at,
            sm.updated_at
        FROM tbl_student_management sm
        LEFT JOIN tbl_program p
            ON p.program_id = sm.program_id
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = sm.ay_id
        WHERE sm.student_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $row['semester_label'] = function_exists('synk_semester_label')
        ? synk_semester_label((int)($row['semester'] ?? 0))
        : '';

    return $row;
}

function synk_student_fetch_directory_record_by_email(mysqli $conn, string $email): ?array
{
    $normalizedEmail = synk_normalize_email($email);
    if ($normalizedEmail === '' || !synk_student_directory_email_exists($conn, $normalizedEmail)) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            sm.student_id,
            sm.ay_id,
            sm.semester,
            COALESCE(ay.ay, '') AS academic_year_label,
            COALESCE(c.college_name, '') AS college_name,
            COALESCE(ca.campus_name, '') AS campus_name,
            TRIM(CONCAT_WS(' ', p.program_name, NULLIF(p.major, ''))) AS source_program_name,
            sm.year_level,
            sm.student_number,
            sm.last_name,
            sm.first_name,
            sm.middle_name,
            sm.suffix_name,
            sm.email_address,
            sm.program_id,
            sm.source_file_name,
            sm.created_at,
            sm.updated_at
        FROM tbl_student_management sm
        LEFT JOIN tbl_program p
            ON p.program_id = sm.program_id
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = sm.ay_id
        WHERE sm.email_address = ?
        ORDER BY sm.updated_at DESC, sm.created_at DESC, sm.student_id DESC
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $normalizedEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $row['semester_label'] = function_exists('synk_semester_label')
        ? synk_semester_label((int)($row['semester'] ?? 0))
        : '';

    return $row;
}

function synk_student_portal_enrollment_table_name(): string
{
    return 'tbl_student_management_enrolled_subjects';
}

function synk_student_fetch_subject_year_options(mysqli $conn, int $studentId): array
{
    if ($studentId <= 0 || !synk_table_exists($conn, synk_student_portal_enrollment_table_name())) {
        return [];
    }

    $tableName = synk_student_portal_enrollment_table_name();
    $stmt = $conn->prepare("
        SELECT
            es.ay_id,
            COALESCE(ay.ay, '') AS academic_year_label,
            COUNT(*) AS subject_count,
            MAX(es.updated_at) AS latest_activity_at
        FROM `{$tableName}` es
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = es.ay_id
        WHERE es.student_id = ?
          AND es.is_active = 1
        GROUP BY es.ay_id, ay.ay
        ORDER BY es.ay_id DESC, latest_activity_at DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'ay_id' => (int)($row['ay_id'] ?? 0),
                'academic_year_label' => (string)($row['academic_year_label'] ?? ''),
                'subject_count' => (int)($row['subject_count'] ?? 0),
            ];
        }
        $result->close();
    }
    $stmt->close();

    return $rows;
}

function synk_student_portal_faculty_filter_key(int $facultyId, string $facultyName): string
{
    if ($facultyId > 0) {
        return 'faculty-' . $facultyId;
    }

    $normalized = strtolower(trim($facultyName));
    $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized);
    $normalized = trim((string)($normalized ?? ''), '-');

    return 'faculty-name-' . ($normalized !== '' ? $normalized : 'unlinked');
}

function synk_student_fetch_faculty_term_options(mysqli $conn, int $studentId): array
{
    if ($studentId <= 0 || !synk_table_exists($conn, synk_student_portal_enrollment_table_name())) {
        return [];
    }

    $tableName = synk_student_portal_enrollment_table_name();
    $stmt = $conn->prepare("
        SELECT
            es.ay_id,
            es.semester,
            COALESCE(ay.ay, '') AS academic_year_label,
            COUNT(*) AS subject_count,
            MAX(es.updated_at) AS latest_activity_at
        FROM `{$tableName}` es
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = es.ay_id
        WHERE es.student_id = ?
          AND es.is_active = 1
        GROUP BY es.ay_id, es.semester, ay.ay
        ORDER BY es.ay_id DESC, es.semester DESC, latest_activity_at DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $ayId = (int)($row['ay_id'] ?? 0);
            $semester = (int)($row['semester'] ?? 0);
            $academicYearLabel = (string)($row['academic_year_label'] ?? '');
            $semesterLabel = function_exists('synk_semester_label')
                ? synk_semester_label($semester)
                : '';

            $rows[] = [
                'term_key' => $ayId . '-' . $semester,
                'ay_id' => $ayId,
                'semester' => $semester,
                'academic_year_label' => $academicYearLabel,
                'semester_label' => $semesterLabel,
                'term_label' => trim($academicYearLabel . ($semesterLabel !== '' ? ' - ' . $semesterLabel : '')),
                'subject_count' => (int)($row['subject_count'] ?? 0),
            ];
        }

        $result->close();
    }

    $stmt->close();
    return $rows;
}

function synk_student_fetch_faculty_cards_for_term(mysqli $conn, int $studentId, int $ayId, int $semester): array
{
    if (
        $studentId <= 0
        || $ayId <= 0
        || $semester <= 0
        || !synk_table_exists($conn, synk_student_portal_enrollment_table_name())
    ) {
        return [];
    }

    $tableName = synk_student_portal_enrollment_table_name();
    $stmt = $conn->prepare("
        SELECT
            es.student_enrollment_id,
            es.faculty_id,
            TRIM(CONCAT_WS(' ', f.first_name, NULLIF(f.middle_name, ''), f.last_name, NULLIF(f.ext_name, ''))) AS faculty_name,
            COALESCE(NULLIF(sec.full_section, ''), es.section_text) AS section_display,
            es.subject_code,
            es.descriptive_title,
            CASE
                WHEN NULLIF(TRIM(es.room_text), '') IS NOT NULL THEN TRIM(es.room_text)
                WHEN es.room_id > 0 THEN TRIM(CONCAT_WS(' - ', NULLIF(r.room_code, ''), NULLIF(r.room_name, '')))
                ELSE ''
            END AS room_name,
            es.schedule_text
        FROM `{$tableName}` es
        LEFT JOIN tbl_faculty f
            ON f.faculty_id = es.faculty_id
        LEFT JOIN tbl_sections sec
            ON sec.section_id = es.section_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = es.room_id
        WHERE es.student_id = ?
          AND es.ay_id = ?
          AND es.semester = ?
          AND es.is_active = 1
        ORDER BY
            CASE
                WHEN TRIM(CONCAT_WS(' ', f.first_name, NULLIF(f.middle_name, ''), f.last_name, NULLIF(f.ext_name, ''))) = '' THEN 1
                ELSE 0
            END ASC,
            TRIM(CONCAT_WS(' ', f.first_name, NULLIF(f.middle_name, ''), f.last_name, NULLIF(f.ext_name, ''))) ASC,
            es.subject_code ASC,
            es.descriptive_title ASC,
            es.student_enrollment_id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iii', $studentId, $ayId, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $cards = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $facultyId = (int)($row['faculty_id'] ?? 0);
            $facultyName = trim((string)($row['faculty_name'] ?? ''));
            if ($facultyName === '') {
                $facultyName = 'Instructor not linked';
            }

            $facultyKey = synk_student_portal_faculty_filter_key($facultyId, $facultyName);
            if (!isset($cards[$facultyKey])) {
                $cards[$facultyKey] = [
                    'faculty_key' => $facultyKey,
                    'faculty_id' => $facultyId,
                    'faculty_name' => $facultyName,
                    'subject_count' => 0,
                    'subjects' => [],
                ];
            }

            $cards[$facultyKey]['subjects'][] = [
                'student_enrollment_id' => (int)($row['student_enrollment_id'] ?? 0),
                'subject_code' => (string)($row['subject_code'] ?? ''),
                'descriptive_title' => (string)($row['descriptive_title'] ?? ''),
                'section_display' => (string)($row['section_display'] ?? ''),
                'room_name' => (string)($row['room_name'] ?? ''),
                'schedule_text' => (string)($row['schedule_text'] ?? ''),
            ];
            $cards[$facultyKey]['subject_count']++;
        }

        $result->close();
    }

    $stmt->close();

    return array_values($cards);
}

function synk_student_faculty_evaluation_table_name(): string
{
    return 'tbl_student_faculty_evaluations';
}

function synk_student_faculty_evaluation_answer_table_name(): string
{
    return 'tbl_student_faculty_evaluation_answers';
}

function synk_student_faculty_evaluation_ensure_schema(mysqli $conn): void
{
    $headerTable = synk_student_faculty_evaluation_table_name();
    $answerTable = synk_student_faculty_evaluation_answer_table_name();
    $didAddWorkflowColumns = false;

    $headerSql = "
        CREATE TABLE IF NOT EXISTS `{$headerTable}` (
            `evaluation_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `student_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `faculty_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `ay_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `semester` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `faculty_name` VARCHAR(255) NOT NULL DEFAULT '',
            `student_number` VARCHAR(32) NOT NULL DEFAULT '',
            `term_label` VARCHAR(120) NOT NULL DEFAULT '',
            `subject_summary` TEXT NULL,
            `comment_text` TEXT NULL,
            `question_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `total_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `average_rating` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `evaluation_token` VARCHAR(64) NOT NULL DEFAULT '',
            `submission_status` ENUM('draft', 'submitted') NOT NULL DEFAULT 'draft',
            `final_submission_token` VARCHAR(64) NOT NULL DEFAULT '',
            `final_submitted_at` DATETIME NULL DEFAULT NULL,
            `completed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`evaluation_id`),
            UNIQUE KEY `uniq_student_faculty_term` (`student_id`, `faculty_id`, `ay_id`, `semester`),
            UNIQUE KEY `uniq_student_faculty_eval_token` (`evaluation_token`),
            KEY `idx_student_faculty_term_lookup` (`student_id`, `ay_id`, `semester`),
            KEY `idx_student_faculty_eval_faculty` (`faculty_id`),
            KEY `idx_student_faculty_submission_lookup` (`student_id`, `ay_id`, `semester`, `submission_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$conn->query($headerSql)) {
        throw new RuntimeException('Unable to prepare the faculty evaluation records table.');
    }

    $answerSql = "
        CREATE TABLE IF NOT EXISTS `{$answerTable}` (
            `answer_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `evaluation_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `category_key` VARCHAR(64) NOT NULL DEFAULT '',
            `category_title` VARCHAR(255) NOT NULL DEFAULT '',
            `question_key` VARCHAR(32) NOT NULL DEFAULT '',
            `question_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `question_text` TEXT NULL,
            `rating` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`answer_id`),
            UNIQUE KEY `uniq_student_faculty_eval_answer` (`evaluation_id`, `question_key`),
            KEY `idx_student_faculty_eval_answer_parent` (`evaluation_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$conn->query($answerSql)) {
        throw new RuntimeException('Unable to prepare the faculty evaluation answer records table.');
    }

    $headerColumnStatements = [
        'submission_status' => "ALTER TABLE `{$headerTable}` ADD COLUMN `submission_status` ENUM('draft', 'submitted') NOT NULL DEFAULT 'draft' AFTER `evaluation_token`",
        'final_submission_token' => "ALTER TABLE `{$headerTable}` ADD COLUMN `final_submission_token` VARCHAR(64) NOT NULL DEFAULT '' AFTER `submission_status`",
        'final_submitted_at' => "ALTER TABLE `{$headerTable}` ADD COLUMN `final_submitted_at` DATETIME NULL DEFAULT NULL AFTER `final_submission_token`",
    ];

    foreach ($headerColumnStatements as $columnName => $columnSql) {
        if (synk_table_has_column($conn, $headerTable, $columnName)) {
            continue;
        }

        if (!$conn->query($columnSql)) {
            throw new RuntimeException('Unable to extend the faculty evaluation workflow columns.');
        }

        $didAddWorkflowColumns = true;
    }

    if (!synk_table_has_index($conn, $headerTable, 'idx_student_faculty_submission_lookup')) {
        if (!$conn->query("ALTER TABLE `{$headerTable}` ADD INDEX `idx_student_faculty_submission_lookup` (`student_id`, `ay_id`, `semester`, `submission_status`)")) {
            throw new RuntimeException('Unable to optimize the faculty evaluation submission workflow.');
        }
    }

    if ($didAddWorkflowColumns) {
        $legacySql = "
            UPDATE `{$headerTable}`
            SET
                submission_status = 'submitted',
                final_submission_token = CASE
                    WHEN TRIM(final_submission_token) <> '' THEN final_submission_token
                    WHEN TRIM(evaluation_token) <> '' THEN evaluation_token
                    ELSE CONCAT('LEGACY-', evaluation_id)
                END,
                final_submitted_at = COALESCE(final_submitted_at, completed_at)
            WHERE submission_status = 'draft'
              AND completed_at IS NOT NULL
        ";

        if (!$conn->query($legacySql)) {
            throw new RuntimeException('Unable to normalize legacy faculty evaluation records.');
        }
    }
}

function synk_student_faculty_evaluation_scale(): array
{
    return [
        5 => [
            'label' => 'Outstanding',
            'description' => 'The performance almost always exceeds the job requirements. The faculty is an exceptional role model.',
        ],
        4 => [
            'label' => 'Very Satisfactory',
            'description' => 'The performance meets and often exceeds the job requirements.',
        ],
        3 => [
            'label' => 'Satisfactory',
            'description' => 'The performance meets and sometimes exceeds the job requirements.',
        ],
        2 => [
            'label' => 'Fair',
            'description' => 'The performance needs some development to meet job requirements.',
        ],
        1 => [
            'label' => 'Poor',
            'description' => 'The faculty fails to meet job requirements.',
        ],
    ];
}

function synk_student_faculty_evaluation_question_bank(): array
{
    return [
        [
            'key' => 'commitment',
            'title' => 'Commitment',
            'items' => [
                ['key' => 'COM1', 'text' => 'Demonstrates sensitivity to students’ ability to attend and absorb content information.'],
                ['key' => 'COM2', 'text' => 'Integrates sensitivity to his/her learning objectives with those of the students in a collaborative process.'],
                ['key' => 'COM3', 'text' => 'Makes her/himself available to students beyond official time.'],
                ['key' => 'COM4', 'text' => 'Coordinates student needs with internal and external enabling groups.'],
                ['key' => 'COM5', 'text' => 'Supplements available resources.'],
            ],
        ],
        [
            'key' => 'subject_matter',
            'title' => 'Knowledge of Subject Matter',
            'items' => [
                ['key' => 'KSM1', 'text' => 'Discusses the subject matter without completely relying on the prescribed reading.'],
                ['key' => 'KSM2', 'text' => 'Draws and shares information on the state-of-the-art theory and practice in his/her discipline.'],
                ['key' => 'KSM3', 'text' => 'Integrates subject to practical circumstances and learning intents/purposes of students.'],
                ['key' => 'KSM4', 'text' => 'Explains the relevance of present topics to the previous lessons, and relates the subject matter to relevant current issues and/or daily life activities.'],
                ['key' => 'KSM5', 'text' => 'Demonstrates up-to-date knowledge and/or awareness on current trends and issues of the subject.'],
            ],
        ],
        [
            'key' => 'independent_learning',
            'title' => 'Teaching for Independent Learning',
            'items' => [
                ['key' => 'TIL1', 'text' => 'Employs teaching strategies that allow students to practice using concepts they need to understand (interactive discussion).'],
                ['key' => 'TIL2', 'text' => 'Provides exercises which develop critical and analytical thinking among the students.'],
                ['key' => 'TIL3', 'text' => 'Enhances student self-esteem through proper recognition of their abilities.'],
                ['key' => 'TIL4', 'text' => 'Allows students of the course to create their own use of well-defined objectives and realistic student-faculty rules.'],
                ['key' => 'TIL5', 'text' => 'Empowers students to make their own decisions and be accountable for their performance.'],
            ],
        ],
        [
            'key' => 'management_of_learning',
            'title' => 'Management of Learning',
            'items' => [
                ['key' => 'MOL1', 'text' => 'Creates opportunities for intensive and/or extensive contribution of students in the class activities (e.g. breaks class into dyads, triads or buzz/task groups).'],
                ['key' => 'MOL2', 'text' => 'Assumes roles as facilitator, resource person, coach, inquisitor, integrator, referee in drawing students to contribute to knowledge and understanding of the concepts at hand.'],
                ['key' => 'MOL3', 'text' => 'Designs and implements learning conditions and experience that promotes healthy exchange and/or confrontations.'],
                ['key' => 'MOL4', 'text' => 'Structures/re-structures learning and teaching-learning context to enhance attainment of collective learning objectives.'],
                ['key' => 'MOL5', 'text' => 'Stimulates students’ desire and interest to learn more about the subject matter.'],
            ],
        ],
    ];
}

function synk_student_faculty_evaluation_flat_questions(): array
{
    $questions = [];
    $order = 1;

    foreach (synk_student_faculty_evaluation_question_bank() as $category) {
        foreach ((array)($category['items'] ?? []) as $item) {
            $questionKey = (string)($item['key'] ?? '');
            if ($questionKey === '') {
                continue;
            }

            $questions[$questionKey] = [
                'category_key' => (string)($category['key'] ?? ''),
                'category_title' => (string)($category['title'] ?? ''),
                'question_key' => $questionKey,
                'question_order' => $order,
                'question_text' => (string)($item['text'] ?? ''),
            ];
            $order++;
        }
    }

    return $questions;
}

function synk_student_generate_faculty_evaluation_token(): string
{
    try {
        $random = strtoupper(bin2hex(random_bytes(6)));
    } catch (Throwable $e) {
        $random = strtoupper(substr(sha1((string)microtime(true) . mt_rand()), 0, 12));
    }

    return 'FEV-' . date('YmdHis') . '-' . $random;
}

function synk_student_generate_faculty_final_submission_token(): string
{
    try {
        $random = strtoupper(bin2hex(random_bytes(6)));
    } catch (Throwable $e) {
        $random = strtoupper(substr(sha1((string)microtime(true) . mt_rand()), 0, 12));
    }

    return 'FEV-FINAL-' . date('YmdHis') . '-' . $random;
}

function synk_student_fetch_faculty_evaluations_for_term(mysqli $conn, int $studentId, int $ayId, int $semester): array
{
    if ($studentId <= 0 || $ayId <= 0 || $semester <= 0) {
        return [];
    }

    synk_student_faculty_evaluation_ensure_schema($conn);
    $tableName = synk_student_faculty_evaluation_table_name();
    $stmt = $conn->prepare("
        SELECT
            evaluation_id,
            student_id,
            faculty_id,
            ay_id,
            semester,
            faculty_name,
            student_number,
            term_label,
            subject_summary,
            comment_text,
            question_count,
            total_score,
            average_rating,
            evaluation_token,
            submission_status,
            final_submission_token,
            final_submitted_at,
            completed_at,
            created_at,
            updated_at
        FROM `{$tableName}`
        WHERE student_id = ?
          AND ay_id = ?
          AND semester = ?
        ORDER BY completed_at DESC, evaluation_id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iii', $studentId, $ayId, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    $evaluationMap = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $facultyId = (int)($row['faculty_id'] ?? 0);
            if ($facultyId <= 0) {
                continue;
            }

            $rows[$facultyId] = [
                'evaluation_id' => (int)($row['evaluation_id'] ?? 0),
                'student_id' => (int)($row['student_id'] ?? 0),
                'faculty_id' => $facultyId,
                'ay_id' => (int)($row['ay_id'] ?? 0),
                'semester' => (int)($row['semester'] ?? 0),
                'faculty_name' => (string)($row['faculty_name'] ?? ''),
                'student_number' => (string)($row['student_number'] ?? ''),
                'term_label' => (string)($row['term_label'] ?? ''),
                'subject_summary' => (string)($row['subject_summary'] ?? ''),
                'comment_text' => (string)($row['comment_text'] ?? ''),
                'question_count' => (int)($row['question_count'] ?? 0),
                'total_score' => (int)($row['total_score'] ?? 0),
                'average_rating' => (float)($row['average_rating'] ?? 0),
                'evaluation_token' => (string)($row['evaluation_token'] ?? ''),
                'submission_status' => (string)($row['submission_status'] ?? 'draft'),
                'final_submission_token' => (string)($row['final_submission_token'] ?? ''),
                'final_submitted_at' => (string)($row['final_submitted_at'] ?? ''),
                'completed_at' => (string)($row['completed_at'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'answers' => [],
            ];

            $evaluationId = (int)($row['evaluation_id'] ?? 0);
            if ($evaluationId > 0) {
                $evaluationMap[$evaluationId] = $facultyId;
            }
        }

        $result->close();
    }

    $stmt->close();

    if (empty($evaluationMap)) {
        return $rows;
    }

    $answerTable = synk_student_faculty_evaluation_answer_table_name();
    $evaluationIds = array_map('intval', array_keys($evaluationMap));
    $placeholders = implode(', ', array_fill(0, count($evaluationIds), '?'));
    $answerStmt = $conn->prepare("
        SELECT
            evaluation_id,
            question_key,
            rating
        FROM `{$answerTable}`
        WHERE evaluation_id IN ({$placeholders})
        ORDER BY question_order ASC, answer_id ASC
    ");

    if ($answerStmt) {
        $bindValues = $evaluationIds;
        synk_bind_dynamic_params($answerStmt, str_repeat('i', count($bindValues)), $bindValues);
        $answerStmt->execute();
        $answerResult = $answerStmt->get_result();

        if ($answerResult instanceof mysqli_result) {
            while ($answerRow = $answerResult->fetch_assoc()) {
                $evaluationId = (int)($answerRow['evaluation_id'] ?? 0);
                $facultyId = (int)($evaluationMap[$evaluationId] ?? 0);
                $questionKey = (string)($answerRow['question_key'] ?? '');
                if ($facultyId <= 0 || $questionKey === '' || !isset($rows[$facultyId])) {
                    continue;
                }

                $rows[$facultyId]['answers'][$questionKey] = (int)($answerRow['rating'] ?? 0);
            }

            $answerResult->close();
        }

        $answerStmt->close();
    }

    return $rows;
}

function synk_student_save_faculty_evaluation(mysqli $conn, array $payload, array $answers): array
{
    synk_student_faculty_evaluation_ensure_schema($conn);

    $studentId = (int)($payload['student_id'] ?? 0);
    $facultyId = (int)($payload['faculty_id'] ?? 0);
    $ayId = (int)($payload['ay_id'] ?? 0);
    $semester = (int)($payload['semester'] ?? 0);
    $facultyName = trim((string)($payload['faculty_name'] ?? ''));
    $studentNumber = trim((string)($payload['student_number'] ?? ''));
    $termLabel = trim((string)($payload['term_label'] ?? ''));
    $subjectSummary = trim((string)($payload['subject_summary'] ?? ''));
    $commentText = trim((string)($payload['comment_text'] ?? ''));

    if ($studentId <= 0 || $facultyId <= 0 || $ayId <= 0 || $semester <= 0) {
        throw new RuntimeException('The selected faculty evaluation scope is incomplete.');
    }

    if ($facultyName === '') {
        throw new RuntimeException('The selected faculty does not have a valid name to evaluate.');
    }

    $questionMap = synk_student_faculty_evaluation_flat_questions();
    if (empty($questionMap)) {
        throw new RuntimeException('The faculty evaluation questionnaire is not available.');
    }

    $normalizedAnswers = [];
    $totalScore = 0;

    foreach ($questionMap as $questionKey => $questionMeta) {
        $rating = max(0, (int)($answers[$questionKey] ?? 0));
        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException('All faculty evaluation questions must be answered using the 1 to 5 scale.');
        }

        $normalizedAnswers[$questionKey] = $rating;
        $totalScore += $rating;
    }

    $questionCount = count($normalizedAnswers);
    $averageRating = $questionCount > 0 ? round($totalScore / $questionCount, 2) : 0;
    $savedAt = date('Y-m-d H:i:s');

    $headerTable = synk_student_faculty_evaluation_table_name();
    $answerTable = synk_student_faculty_evaluation_answer_table_name();

    $conn->begin_transaction();

    try {
        $checkStmt = $conn->prepare("
            SELECT
                evaluation_id,
                evaluation_token,
                submission_status,
                final_submission_token
            FROM `{$headerTable}`
            WHERE student_id = ?
              AND faculty_id = ?
              AND ay_id = ?
              AND semester = ?
            LIMIT 1
            FOR UPDATE
        ");

        if (!$checkStmt) {
            throw new RuntimeException('Unable to validate the one-time faculty evaluation rule.');
        }

        $checkStmt->bind_param('iiii', $studentId, $facultyId, $ayId, $semester);
        $checkStmt->execute();
        $existingResult = $checkStmt->get_result();
        $existingRow = $existingResult ? $existingResult->fetch_assoc() : null;
        if ($existingResult instanceof mysqli_result) {
            $existingResult->close();
        }
        $checkStmt->close();

        $evaluationId = (int)($existingRow['evaluation_id'] ?? 0);
        $evaluationToken = trim((string)($existingRow['evaluation_token'] ?? ''));
        $submissionStatus = strtolower(trim((string)($existingRow['submission_status'] ?? 'draft')));
        $finalSubmissionToken = trim((string)($existingRow['final_submission_token'] ?? ''));

        if ($evaluationId > 0 && ($submissionStatus === 'submitted' || $finalSubmissionToken !== '')) {
            throw new RuntimeException('This faculty evaluation has already been finalized for the selected term.');
        }

        if ($evaluationToken === '') {
            $evaluationToken = synk_student_generate_faculty_evaluation_token();
        }

        if ($evaluationId > 0) {
            $updateHeaderStmt = $conn->prepare("
                UPDATE `{$headerTable}`
                SET
                    faculty_name = ?,
                    student_number = ?,
                    term_label = ?,
                    subject_summary = ?,
                    comment_text = ?,
                    question_count = ?,
                    total_score = ?,
                    average_rating = ?,
                    evaluation_token = ?,
                    submission_status = 'draft',
                    final_submission_token = '',
                    final_submitted_at = NULL,
                    completed_at = ?,
                    updated_at = NOW()
                WHERE evaluation_id = ?
                LIMIT 1
            ");

            if (!$updateHeaderStmt) {
                throw new RuntimeException('Unable to update the saved faculty evaluation draft.');
            }

            $updateHeaderStmt->bind_param(
                'sssssiidssi',
                $facultyName,
                $studentNumber,
                $termLabel,
                $subjectSummary,
                $commentText,
                $questionCount,
                $totalScore,
                $averageRating,
                $evaluationToken,
                $savedAt,
                $evaluationId
            );

            if (!$updateHeaderStmt->execute()) {
                $updateHeaderStmt->close();
                throw new RuntimeException('Unable to update the saved faculty evaluation draft.');
            }

            $updateHeaderStmt->close();

            if (!$conn->query("DELETE FROM `{$answerTable}` WHERE evaluation_id = " . (int)$evaluationId)) {
                throw new RuntimeException('Unable to refresh the faculty evaluation answers.');
            }
        } else {
            $insertHeaderStmt = $conn->prepare("
                INSERT INTO `{$headerTable}` (
                    student_id,
                    faculty_id,
                    ay_id,
                    semester,
                    faculty_name,
                    student_number,
                    term_label,
                    subject_summary,
                    comment_text,
                    question_count,
                    total_score,
                    average_rating,
                    evaluation_token,
                    submission_status,
                    final_submission_token,
                    final_submitted_at,
                    completed_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', '', NULL, ?)
            ");

            if (!$insertHeaderStmt) {
                throw new RuntimeException('Unable to save the faculty evaluation draft.');
            }

            $insertHeaderStmt->bind_param(
                'iiiisssssiidss',
                $studentId,
                $facultyId,
                $ayId,
                $semester,
                $facultyName,
                $studentNumber,
                $termLabel,
                $subjectSummary,
                $commentText,
                $questionCount,
                $totalScore,
                $averageRating,
                $evaluationToken,
                $savedAt
            );

            if (!$insertHeaderStmt->execute()) {
                $insertHeaderStmt->close();
                throw new RuntimeException('Unable to save the faculty evaluation draft.');
            }

            $evaluationId = (int)$insertHeaderStmt->insert_id;
            $insertHeaderStmt->close();
        }

        $insertAnswerStmt = $conn->prepare("
            INSERT INTO `{$answerTable}` (
                evaluation_id,
                category_key,
                category_title,
                question_key,
                question_order,
                question_text,
                rating
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$insertAnswerStmt) {
            throw new RuntimeException('Unable to save the faculty evaluation answers.');
        }

        foreach ($questionMap as $questionKey => $questionMeta) {
            $categoryKey = (string)($questionMeta['category_key'] ?? '');
            $categoryTitle = (string)($questionMeta['category_title'] ?? '');
            $questionOrder = (int)($questionMeta['question_order'] ?? 0);
            $questionText = (string)($questionMeta['question_text'] ?? '');
            $rating = (int)$normalizedAnswers[$questionKey];

            $insertAnswerStmt->bind_param(
                'isssisi',
                $evaluationId,
                $categoryKey,
                $categoryTitle,
                $questionKey,
                $questionOrder,
                $questionText,
                $rating
            );

            if (!$insertAnswerStmt->execute()) {
                $insertAnswerStmt->close();
                throw new RuntimeException('Unable to save one of the faculty evaluation answers.');
            }
        }

        $insertAnswerStmt->close();
        $conn->commit();

        return [
            'evaluation_id' => $evaluationId,
            'student_id' => $studentId,
            'faculty_id' => $facultyId,
            'ay_id' => $ayId,
            'semester' => $semester,
            'faculty_name' => $facultyName,
            'student_number' => $studentNumber,
            'term_label' => $termLabel,
            'subject_summary' => $subjectSummary,
            'comment_text' => $commentText,
            'question_count' => $questionCount,
            'total_score' => $totalScore,
            'average_rating' => $averageRating,
            'evaluation_token' => $evaluationToken,
            'submission_status' => 'draft',
            'final_submission_token' => '',
            'final_submitted_at' => '',
            'completed_at' => $savedAt,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function synk_student_finalize_faculty_evaluations_for_term(
    mysqli $conn,
    int $studentId,
    int $ayId,
    int $semester,
    array $facultyIds,
    array $payload = []
): array {
    synk_student_faculty_evaluation_ensure_schema($conn);

    $normalizedFacultyIds = [];
    foreach ($facultyIds as $facultyId) {
        $facultyId = (int)$facultyId;
        if ($facultyId > 0) {
            $normalizedFacultyIds[$facultyId] = $facultyId;
        }
    }

    if ($studentId <= 0 || $ayId <= 0 || $semester <= 0 || empty($normalizedFacultyIds)) {
        throw new RuntimeException('The final faculty evaluation scope is incomplete.');
    }

    $expectedFacultyIds = array_values($normalizedFacultyIds);
    sort($expectedFacultyIds);
    $termLabel = trim((string)($payload['term_label'] ?? ''));
    $studentNumber = trim((string)($payload['student_number'] ?? ''));
    $subjectCount = max(0, (int)($payload['subject_count'] ?? 0));

    $headerTable = synk_student_faculty_evaluation_table_name();
    $placeholders = implode(', ', array_fill(0, count($expectedFacultyIds), '?'));
    $types = 'iii' . str_repeat('i', count($expectedFacultyIds));
    $params = array_merge([$studentId, $ayId, $semester], $expectedFacultyIds);
    $finalSubmissionToken = synk_student_generate_faculty_final_submission_token();
    $finalSubmittedAt = date('Y-m-d H:i:s');

    $conn->begin_transaction();

    try {
        $selectSql = "
            SELECT
                evaluation_id,
                faculty_id,
                faculty_name,
                question_count,
                submission_status,
                final_submission_token
            FROM `{$headerTable}`
            WHERE student_id = ?
              AND ay_id = ?
              AND semester = ?
              AND faculty_id IN ({$placeholders})
            FOR UPDATE
        ";

        $selectStmt = $conn->prepare($selectSql);
        if (!$selectStmt) {
            throw new RuntimeException('Unable to validate the final faculty evaluation submission.');
        }

        synk_bind_dynamic_params($selectStmt, $types, $params);
        $selectStmt->execute();
        $selectResult = $selectStmt->get_result();
        $draftRows = [];

        if ($selectResult instanceof mysqli_result) {
            while ($row = $selectResult->fetch_assoc()) {
                $facultyId = (int)($row['faculty_id'] ?? 0);
                if ($facultyId > 0) {
                    $draftRows[$facultyId] = $row;
                }
            }
            $selectResult->close();
        }

        $selectStmt->close();

        foreach ($expectedFacultyIds as $facultyId) {
            if (!isset($draftRows[$facultyId])) {
                throw new RuntimeException('Please save draft evaluations for all linked faculty before the final submission.');
            }

            $rowStatus = strtolower(trim((string)($draftRows[$facultyId]['submission_status'] ?? 'draft')));
            $rowFinalToken = trim((string)($draftRows[$facultyId]['final_submission_token'] ?? ''));
            if ($rowStatus === 'submitted' || $rowFinalToken !== '') {
                throw new RuntimeException('The final faculty evaluation has already been submitted for this academic term.');
            }
        }

        $updateSql = "
            UPDATE `{$headerTable}`
            SET
                submission_status = 'submitted',
                final_submission_token = ?,
                final_submitted_at = ?,
                completed_at = ?,
                updated_at = NOW()
            WHERE student_id = ?
              AND ay_id = ?
              AND semester = ?
              AND faculty_id IN ({$placeholders})
        ";

        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            throw new RuntimeException('Unable to finalize the saved faculty evaluations.');
        }

        $updateTypes = 'sssiii' . str_repeat('i', count($expectedFacultyIds));
        $updateParams = array_merge(
            [$finalSubmissionToken, $finalSubmittedAt, $finalSubmittedAt, $studentId, $ayId, $semester],
            $expectedFacultyIds
        );
        synk_bind_dynamic_params($updateStmt, $updateTypes, $updateParams);

        if (!$updateStmt->execute()) {
            $updateStmt->close();
            throw new RuntimeException('Unable to finalize the saved faculty evaluations.');
        }

        $updateStmt->close();
        $conn->commit();

        $facultyNames = [];
        foreach ($expectedFacultyIds as $facultyId) {
            $facultyName = trim((string)($draftRows[$facultyId]['faculty_name'] ?? ''));
            if ($facultyName !== '') {
                $facultyNames[] = $facultyName;
            }
        }

        return [
            'student_id' => $studentId,
            'student_number' => $studentNumber,
            'ay_id' => $ayId,
            'semester' => $semester,
            'term_label' => $termLabel,
            'faculty_count' => count($expectedFacultyIds),
            'subject_count' => $subjectCount,
            'faculty_names' => $facultyNames,
            'final_submission_token' => $finalSubmissionToken,
            'final_submitted_at' => $finalSubmittedAt,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function synk_student_build_term_evaluation_qr_payload(array $submission, array $studentRecord = [], array $facultyCards = []): string
{
    $facultyNames = [];
    if (!empty($submission['faculty_names']) && is_array($submission['faculty_names'])) {
        $facultyNames = array_map('strval', $submission['faculty_names']);
    } elseif (!empty($facultyCards)) {
        foreach ($facultyCards as $facultyCard) {
            $facultyName = trim((string)($facultyCard['faculty_name'] ?? ''));
            if ($facultyName !== '') {
                $facultyNames[] = $facultyName;
            }
        }
    }

    $lines = [
        'SYNK FACULTY PERFORMANCE EVALUATION COMPLETED',
        'Final Verification Code: ' . trim((string)($submission['final_submission_token'] ?? '')),
        'Student Number: ' . trim((string)($submission['student_number'] ?? ($studentRecord['student_number'] ?? ''))),
        'Student: ' . trim(implode(' ', array_filter([
            (string)($studentRecord['last_name'] ?? ''),
            (string)($studentRecord['first_name'] ?? ''),
            (string)($studentRecord['middle_name'] ?? ''),
            (string)($studentRecord['suffix_name'] ?? ''),
        ]))),
        'Term: ' . trim((string)($submission['term_label'] ?? '')),
        'Faculty Count: ' . (int)($submission['faculty_count'] ?? 0),
        'Subject Count: ' . (int)($submission['subject_count'] ?? 0),
        'Faculty: ' . implode(', ', array_filter($facultyNames)),
        'Final Submitted At: ' . trim((string)($submission['final_submitted_at'] ?? '')),
        'Status: Final Submitted',
    ];

    return implode("\n", array_filter($lines, static function ($line) {
        return trim((string)$line) !== '';
    }));
}

function synk_student_faculty_evaluation_percentage_from_mean(float $mean): float
{
    return round((max(0, $mean) / 5) * 100, 2);
}

function synk_student_fetch_faculty_evaluation_report_term_options(mysqli $conn): array
{
    synk_student_faculty_evaluation_ensure_schema($conn);

    $headerTable = synk_student_faculty_evaluation_table_name();
    $stmt = $conn->prepare("
        SELECT
            e.ay_id,
            e.semester,
            COALESCE(ay.ay, '') AS academic_year_label,
            COUNT(*) AS evaluation_count,
            COUNT(DISTINCT e.student_id) AS student_count,
            COUNT(DISTINCT e.faculty_id) AS faculty_count,
            MAX(COALESCE(e.final_submitted_at, e.completed_at)) AS latest_submitted_at
        FROM `{$headerTable}` e
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = e.ay_id
        WHERE e.submission_status = 'submitted'
        GROUP BY e.ay_id, e.semester, ay.ay
        ORDER BY e.ay_id DESC, e.semester DESC, latest_submitted_at DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $semester = (int)($row['semester'] ?? 0);
            $academicYearLabel = trim((string)($row['academic_year_label'] ?? ''));
            $semesterLabel = function_exists('synk_semester_label')
                ? synk_semester_label($semester)
                : '';

            $rows[] = [
                'term_key' => (int)($row['ay_id'] ?? 0) . '-' . $semester,
                'ay_id' => (int)($row['ay_id'] ?? 0),
                'semester' => $semester,
                'academic_year_label' => $academicYearLabel,
                'semester_label' => $semesterLabel,
                'term_label' => trim($academicYearLabel . ($semesterLabel !== '' ? ' - ' . $semesterLabel : '')),
                'evaluation_count' => (int)($row['evaluation_count'] ?? 0),
                'student_count' => (int)($row['student_count'] ?? 0),
                'faculty_count' => (int)($row['faculty_count'] ?? 0),
                'latest_submitted_at' => (string)($row['latest_submitted_at'] ?? ''),
            ];
        }

        $result->close();
    }

    $stmt->close();
    return $rows;
}

function synk_student_fetch_faculty_evaluation_report_summary(mysqli $conn, int $ayId, int $semester): array
{
    $summary = [
        'evaluation_count' => 0,
        'student_count' => 0,
        'faculty_count' => 0,
        'final_submission_count' => 0,
        'overall_mean' => 0.0,
        'overall_percentage' => 0.0,
        'latest_submitted_at' => '',
    ];

    if ($ayId <= 0 || $semester <= 0) {
        return $summary;
    }

    synk_student_faculty_evaluation_ensure_schema($conn);
    $headerTable = synk_student_faculty_evaluation_table_name();
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS evaluation_count,
            COUNT(DISTINCT e.student_id) AS student_count,
            COUNT(DISTINCT e.faculty_id) AS faculty_count,
            COUNT(DISTINCT NULLIF(TRIM(e.final_submission_token), '')) AS final_submission_count,
            AVG(e.average_rating) AS overall_mean,
            MAX(COALESCE(e.final_submitted_at, e.completed_at)) AS latest_submitted_at
        FROM `{$headerTable}` e
        WHERE e.submission_status = 'submitted'
          AND e.ay_id = ?
          AND e.semester = ?
    ");

    if (!$stmt) {
        return $summary;
    }

    $stmt->bind_param('ii', $ayId, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!is_array($row)) {
        return $summary;
    }

    $summary['evaluation_count'] = (int)($row['evaluation_count'] ?? 0);
    $summary['student_count'] = (int)($row['student_count'] ?? 0);
    $summary['faculty_count'] = (int)($row['faculty_count'] ?? 0);
    $summary['final_submission_count'] = (int)($row['final_submission_count'] ?? 0);
    $summary['overall_mean'] = round((float)($row['overall_mean'] ?? 0), 2);
    $summary['overall_percentage'] = synk_student_faculty_evaluation_percentage_from_mean($summary['overall_mean']);
    $summary['latest_submitted_at'] = (string)($row['latest_submitted_at'] ?? '');

    return $summary;
}

function synk_student_fetch_faculty_evaluation_rating_sheet_rows(mysqli $conn, int $ayId, int $semester): array
{
    if ($ayId <= 0 || $semester <= 0) {
        return [];
    }

    synk_student_faculty_evaluation_ensure_schema($conn);
    $headerTable = synk_student_faculty_evaluation_table_name();
    $stmt = $conn->prepare("
        SELECT
            e.evaluation_id,
            e.student_id,
            e.faculty_id,
            e.student_number,
            e.faculty_name,
            e.subject_summary,
            e.comment_text,
            e.average_rating,
            e.evaluation_token,
            e.final_submission_token,
            e.final_submitted_at,
            e.completed_at,
            e.term_label,
            sm.last_name,
            sm.first_name,
            sm.middle_name,
            sm.suffix_name,
            sm.email_address,
            TRIM(CONCAT_WS(' ', p.program_name, NULLIF(p.major, ''))) AS program_name,
            COALESCE(c.college_name, '') AS college_name,
            COALESCE(ca.campus_name, '') AS campus_name
        FROM `{$headerTable}` e
        LEFT JOIN tbl_student_management sm
            ON sm.student_id = e.student_id
        LEFT JOIN tbl_program p
            ON p.program_id = sm.program_id
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        WHERE e.submission_status = 'submitted'
          AND e.ay_id = ?
          AND e.semester = ?
        ORDER BY
            COALESCE(e.final_submitted_at, e.completed_at) DESC,
            e.faculty_name ASC,
            sm.last_name ASC,
            sm.first_name ASC,
            e.evaluation_id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $ayId, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $studentName = trim(synk_student_directory_display_name($row));
            if ($studentName === '') {
                $studentName = trim((string)($row['email_address'] ?? ''));
            }
            if ($studentName === '') {
                $studentName = 'Student record';
            }

            $averageMean = round((float)($row['average_rating'] ?? 0), 2);
            $rows[] = [
                'evaluation_id' => (int)($row['evaluation_id'] ?? 0),
                'student_id' => (int)($row['student_id'] ?? 0),
                'faculty_id' => (int)($row['faculty_id'] ?? 0),
                'student_number' => (string)($row['student_number'] ?? ''),
                'student_name' => $studentName,
                'faculty_name' => (string)($row['faculty_name'] ?? ''),
                'subject_summary' => (string)($row['subject_summary'] ?? ''),
                'comment_text' => (string)($row['comment_text'] ?? ''),
                'average_mean' => $averageMean,
                'average_percentage' => synk_student_faculty_evaluation_percentage_from_mean($averageMean),
                'evaluation_token' => (string)($row['evaluation_token'] ?? ''),
                'final_submission_token' => (string)($row['final_submission_token'] ?? ''),
                'final_submitted_at' => (string)($row['final_submitted_at'] ?? ''),
                'completed_at' => (string)($row['completed_at'] ?? ''),
                'term_label' => (string)($row['term_label'] ?? ''),
                'program_name' => (string)($row['program_name'] ?? ''),
                'college_name' => (string)($row['college_name'] ?? ''),
                'campus_name' => (string)($row['campus_name'] ?? ''),
            ];
        }

        $result->close();
    }

    $stmt->close();
    return $rows;
}

function synk_student_fetch_faculty_evaluation_individual_report_rows(mysqli $conn, int $ayId, int $semester): array
{
    if ($ayId <= 0 || $semester <= 0) {
        return [];
    }

    synk_student_faculty_evaluation_ensure_schema($conn);
    $headerTable = synk_student_faculty_evaluation_table_name();
    $answerTable = synk_student_faculty_evaluation_answer_table_name();
    $stmt = $conn->prepare("
        SELECT
            e.evaluation_id,
            e.faculty_id,
            e.faculty_name,
            e.student_number,
            e.comment_text,
            e.average_rating,
            a.category_key,
            a.category_title,
            a.rating
        FROM `{$headerTable}` e
        INNER JOIN `{$answerTable}` a
            ON a.evaluation_id = e.evaluation_id
        WHERE e.submission_status = 'submitted'
          AND e.ay_id = ?
          AND e.semester = ?
        ORDER BY
            e.faculty_name ASC,
            e.evaluation_id ASC,
            a.question_order ASC,
            a.answer_id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $ayId, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    $categoryOrder = [];
    foreach (synk_student_faculty_evaluation_question_bank() as $categoryIndex => $categoryMeta) {
        $categoryKey = (string)($categoryMeta['key'] ?? '');
        if ($categoryKey === '') {
            continue;
        }

        $categoryOrder[$categoryKey] = [
            'index' => $categoryIndex,
            'title' => (string)($categoryMeta['title'] ?? $categoryKey),
        ];
    }

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $facultyId = (int)($row['faculty_id'] ?? 0);
            $facultyName = trim((string)($row['faculty_name'] ?? ''));
            if ($facultyName === '') {
                $facultyName = 'Instructor not linked';
            }

            $groupKey = $facultyId > 0 ? 'faculty-' . $facultyId : 'faculty-name-' . strtolower($facultyName);
            if (!isset($rows[$groupKey])) {
                $rows[$groupKey] = [
                    'faculty_id' => $facultyId,
                    'faculty_name' => $facultyName,
                    'evaluations_count' => 0,
                    'students' => [],
                    'comments' => [],
                    'overall_sum' => 0.0,
                    'overall_count' => 0,
                    'categories' => [],
                    '_seen_evaluations' => [],
                ];

                foreach ($categoryOrder as $categoryKey => $categoryMeta) {
                    $rows[$groupKey]['categories'][$categoryKey] = [
                        'title' => $categoryMeta['title'],
                        'mean' => 0.0,
                        'percentage' => 0.0,
                        '_sum' => 0.0,
                        '_count' => 0,
                        '_index' => $categoryMeta['index'],
                    ];
                }
            }

            $evaluationId = (int)($row['evaluation_id'] ?? 0);
            if ($evaluationId > 0 && !isset($rows[$groupKey]['_seen_evaluations'][$evaluationId])) {
                $rows[$groupKey]['_seen_evaluations'][$evaluationId] = true;
                $rows[$groupKey]['evaluations_count']++;
                $rows[$groupKey]['overall_sum'] += (float)($row['average_rating'] ?? 0);
                $rows[$groupKey]['overall_count']++;

                $studentNumber = trim((string)($row['student_number'] ?? ''));
                if ($studentNumber !== '') {
                    $rows[$groupKey]['students'][$studentNumber] = $studentNumber;
                }

                $commentText = trim((string)($row['comment_text'] ?? ''));
                if ($commentText !== '') {
                    $commentKey = $studentNumber !== '' ? $studentNumber . '|' . $commentText : $commentText;
                    $rows[$groupKey]['comments'][$commentKey] = [
                        'student_number' => $studentNumber,
                        'comment_text' => $commentText,
                    ];
                }
            }

            $categoryKey = (string)($row['category_key'] ?? '');
            if ($categoryKey !== '' && isset($rows[$groupKey]['categories'][$categoryKey])) {
                $rows[$groupKey]['categories'][$categoryKey]['_sum'] += (float)($row['rating'] ?? 0);
                $rows[$groupKey]['categories'][$categoryKey]['_count']++;
            }
        }

        $result->close();
    }

    $stmt->close();

    foreach ($rows as &$facultyRow) {
        $facultyRow['student_count'] = count($facultyRow['students']);
        $facultyRow['overall_mean'] = $facultyRow['overall_count'] > 0
            ? round($facultyRow['overall_sum'] / $facultyRow['overall_count'], 2)
            : 0.0;
        $facultyRow['overall_percentage'] = synk_student_faculty_evaluation_percentage_from_mean($facultyRow['overall_mean']);
        $facultyRow['student_component_percentage'] = round($facultyRow['overall_percentage'] * 0.60, 2);

        uasort($facultyRow['categories'], static function (array $left, array $right): int {
            return (int)($left['_index'] ?? 0) <=> (int)($right['_index'] ?? 0);
        });

        foreach ($facultyRow['categories'] as &$categoryRow) {
            $categoryRow['mean'] = $categoryRow['_count'] > 0
                ? round($categoryRow['_sum'] / $categoryRow['_count'], 2)
                : 0.0;
            $categoryRow['percentage'] = synk_student_faculty_evaluation_percentage_from_mean($categoryRow['mean']);
            unset($categoryRow['_sum'], $categoryRow['_count'], $categoryRow['_index']);
        }
        unset($categoryRow);

        $facultyRow['comments'] = array_values($facultyRow['comments']);
        $facultyRow['students'] = array_values($facultyRow['students']);
        unset($facultyRow['_seen_evaluations'], $facultyRow['overall_sum'], $facultyRow['overall_count']);
    }
    unset($facultyRow);

    usort($rows, static function (array $left, array $right): int {
        return strcasecmp((string)($left['faculty_name'] ?? ''), (string)($right['faculty_name'] ?? ''));
    });

    return array_values($rows);
}

function synk_student_build_faculty_evaluation_consolidated_rows(array $individualRows): array
{
    $rows = [];

    foreach ($individualRows as $row) {
        $rows[] = [
            'faculty_id' => (int)($row['faculty_id'] ?? 0),
            'faculty_name' => (string)($row['faculty_name'] ?? ''),
            'student_count' => (int)($row['student_count'] ?? 0),
            'evaluations_count' => (int)($row['evaluations_count'] ?? 0),
            'student_rating_mean' => (float)($row['overall_mean'] ?? 0),
            'student_rating_percentage' => (float)($row['overall_percentage'] ?? 0),
            'student_rating_component' => (float)($row['student_component_percentage'] ?? 0),
            'supervisor_rating_percentage' => null,
            'supervisor_rating_component' => null,
            'total_rating_percentage' => null,
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        return strcasecmp((string)($left['faculty_name'] ?? ''), (string)($right['faculty_name'] ?? ''));
    });

    return $rows;
}

function synk_student_build_evaluation_qr_payload(array $evaluation, array $studentRecord = [], array $facultyCard = []): string
{
    $lines = [
        'SYNK FACULTY EVALUATION VERIFIED',
        'Verification Code: ' . trim((string)($evaluation['evaluation_token'] ?? '')),
        'Student Number: ' . trim((string)($evaluation['student_number'] ?? ($studentRecord['student_number'] ?? ''))),
        'Student: ' . trim(implode(' ', array_filter([
            (string)($studentRecord['last_name'] ?? ''),
            (string)($studentRecord['first_name'] ?? ''),
            (string)($studentRecord['middle_name'] ?? ''),
            (string)($studentRecord['suffix_name'] ?? ''),
        ]))),
        'Faculty: ' . trim((string)($evaluation['faculty_name'] ?? ($facultyCard['faculty_name'] ?? ''))),
        'Term: ' . trim((string)($evaluation['term_label'] ?? '')),
        'Subjects: ' . trim((string)($evaluation['subject_summary'] ?? '')),
        'Completed At: ' . trim((string)($evaluation['completed_at'] ?? '')),
        'Status: Completed',
    ];

    return implode("\n", array_filter($lines, static function ($line) {
        return trim((string)$line) !== '';
    }));
}

function synk_student_build_evaluation_qr_url(string $payload, int $size = 320): string
{
    $safeSize = max(120, min(640, $size));
    return 'https://api.qrserver.com/v1/create-qr-code/?size='
        . $safeSize . 'x' . $safeSize
        . '&margin=10&data=' . rawurlencode($payload);
}

function synk_student_fetch_subject_rows_by_academic_year(mysqli $conn, int $studentId, int $ayId, int $semester = 0): array
{
    if (
        $studentId <= 0
        || $ayId <= 0
        || !synk_table_exists($conn, synk_student_portal_enrollment_table_name())
    ) {
        return [];
    }

    $tableName = synk_student_portal_enrollment_table_name();
    $semesterWhereSql = '';
    if ($semester > 0) {
        $semesterWhereSql = " AND es.semester = ?";
    }

    $stmt = $conn->prepare("
        SELECT
            es.student_enrollment_id,
            es.ay_id,
            COALESCE(ay.ay, '') AS academic_year_label,
            es.semester,
            COALESCE(NULLIF(sec.full_section, ''), es.section_text) AS section_display,
            es.subject_code,
            es.descriptive_title,
            TRIM(CONCAT_WS(' ', f.first_name, NULLIF(f.middle_name, ''), f.last_name, NULLIF(f.ext_name, ''))) AS faculty_name,
            CASE
                WHEN NULLIF(TRIM(es.room_text), '') IS NOT NULL THEN TRIM(es.room_text)
                WHEN es.room_id > 0 THEN TRIM(CONCAT_WS(' - ', NULLIF(r.room_code, ''), NULLIF(r.room_name, '')))
                ELSE es.room_text
            END AS room_name,
            es.schedule_text
        FROM `{$tableName}` es
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = es.ay_id
        LEFT JOIN tbl_sections sec
            ON sec.section_id = es.section_id
        LEFT JOIN tbl_faculty f
            ON f.faculty_id = es.faculty_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = es.room_id
        WHERE es.student_id = ?
          AND es.ay_id = ?
          {$semesterWhereSql}
          AND es.is_active = 1
        ORDER BY
            es.semester ASC,
            es.subject_code ASC,
            es.descriptive_title ASC,
            es.student_enrollment_id ASC
    ");

    if (!$stmt) {
        return [];
    }

    if ($semester > 0) {
        $stmt->bind_param('iii', $studentId, $ayId, $semester);
    } else {
        $stmt->bind_param('ii', $studentId, $ayId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $semester = (int)($row['semester'] ?? 0);
            $rows[] = [
                'student_enrollment_id' => (int)($row['student_enrollment_id'] ?? 0),
                'ay_id' => (int)($row['ay_id'] ?? 0),
                'academic_year_label' => (string)($row['academic_year_label'] ?? ''),
                'semester' => $semester,
                'semester_label' => function_exists('synk_semester_label') ? synk_semester_label($semester) : '',
                'section_display' => (string)($row['section_display'] ?? ''),
                'subject_code' => (string)($row['subject_code'] ?? ''),
                'descriptive_title' => (string)($row['descriptive_title'] ?? ''),
                'faculty_name' => (string)($row['faculty_name'] ?? ''),
                'room_name' => (string)($row['room_name'] ?? ''),
                'schedule_text' => (string)($row['schedule_text'] ?? ''),
            ];
        }
        $result->close();
    }
    $stmt->close();

    return $rows;
}

function synk_student_group_subject_rows_by_semester(array $rows): array
{
    $grouped = [];

    foreach ($rows as $row) {
        $semester = max(0, (int)($row['semester'] ?? 0));
        if (!isset($grouped[$semester])) {
            $grouped[$semester] = [
                'semester' => $semester,
                'semester_label' => function_exists('synk_semester_label') ? synk_semester_label($semester) : '',
                'subjects' => [],
            ];
        }

        $grouped[$semester]['subjects'][] = $row;
    }

    ksort($grouped);

    return array_values($grouped);
}

function synk_student_resolve_portal_context(mysqli $conn): array
{
    $isAdminPreview = synk_student_is_admin_preview_mode();
    $previewStudentId = $isAdminPreview ? synk_student_preview_student_id_from_request() : 0;
    $studentEmail = synk_normalize_email((string)($_SESSION['email'] ?? ''));
    $directoryRecord = $isAdminPreview
        ? synk_student_fetch_directory_record_by_student_id($conn, $previewStudentId)
        : synk_student_fetch_directory_record_by_email($conn, $studentEmail);

    if ($isAdminPreview) {
        $studentEmail = synk_normalize_email((string)($directoryRecord['email_address'] ?? ''));
    }

    $portalProfile = $studentEmail !== ''
        ? synk_student_fetch_portal_profile($conn, $studentEmail)
        : null;
    $studentName = $directoryRecord
        ? trim(synk_student_directory_display_name($directoryRecord))
        : ($isAdminPreview ? 'Student Preview' : trim((string)($_SESSION['username'] ?? 'Student')));

    if ($studentName === '') {
        $studentName = $isAdminPreview ? 'Student Preview' : 'Student';
    }

    return [
        'is_admin_preview' => $isAdminPreview,
        'preview_student_id' => $previewStudentId,
        'student_email' => $studentEmail,
        'student_name' => $studentName,
        'directory_record' => $directoryRecord,
        'portal_profile' => $portalProfile,
        'profile_is_complete' => $isAdminPreview ? true : synk_student_portal_profile_is_complete($portalProfile),
    ];
}

function synk_student_directory_display_name(array $row): string
{
    $lastName = trim((string)($row['last_name'] ?? ''));
    $firstName = trim((string)($row['first_name'] ?? ''));
    $middleName = trim((string)($row['middle_name'] ?? ''));
    $suffixName = trim((string)($row['suffix_name'] ?? ''));

    $name = trim(implode(', ', array_filter([$lastName, $firstName], static function ($value) {
        return trim((string)$value) !== '';
    })));

    if ($middleName !== '') {
        $name .= ($name !== '' ? ' ' : '') . $middleName;
    }

    if ($suffixName !== '') {
        $name .= ($name !== '' ? ' ' : '') . $suffixName;
    }

    return trim($name);
}

function synk_student_fetch_profile_program_options(mysqli $conn): array
{
    $rows = [];
    $result = $conn->query("
        SELECT
            p.program_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            c.college_id,
            c.college_code,
            c.college_name,
            ca.campus_id,
            ca.campus_code,
            ca.campus_name
        FROM tbl_program p
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        INNER JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        WHERE p.status = 'active'
          AND c.status = 'active'
        ORDER BY ca.campus_name ASC, c.college_name ASC, p.program_name ASC, p.major ASC, p.program_code ASC
    ");

    if (!($result instanceof mysqli_result)) {
        return $rows;
    }

    while ($row = $result->fetch_assoc()) {
        $row['source_program_name'] = synk_student_portal_program_source_name(
            (string)($row['program_name'] ?? ''),
            (string)($row['major'] ?? '')
        );
        $rows[] = $row;
    }

    $result->close();
    return $rows;
}

function synk_student_find_profile_program_by_id(mysqli $conn, int $programId): ?array
{
    if ($programId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            p.program_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            c.college_id,
            c.college_code,
            c.college_name,
            ca.campus_id,
            ca.campus_code,
            ca.campus_name
        FROM tbl_program p
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        INNER JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        WHERE p.program_id = ?
          AND p.status = 'active'
          AND c.status = 'active'
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $row['source_program_name'] = synk_student_portal_program_source_name(
        (string)($row['program_name'] ?? ''),
        (string)($row['major'] ?? '')
    );

    return $row;
}

function synk_student_resolve_suggested_program_id(array $programOptions, ?array $directoryRecord): int
{
    if (!$directoryRecord) {
        return 0;
    }

    $directoryProgramId = (int)($directoryRecord['program_id'] ?? 0);
    if ($directoryProgramId > 0) {
        foreach ($programOptions as $programOption) {
            if ((int)($programOption['program_id'] ?? 0) === $directoryProgramId) {
                return $directoryProgramId;
            }
        }
    }

    $sourceProgramName = strtolower(trim((string)($directoryRecord['source_program_name'] ?? '')));
    if ($sourceProgramName === '') {
        return 0;
    }

    $collegeName = strtolower(trim((string)($directoryRecord['college_name'] ?? '')));
    $campusName = strtolower(trim((string)($directoryRecord['campus_name'] ?? '')));
    $fallbackProgramId = 0;

    foreach ($programOptions as $programOption) {
        $optionSourceName = strtolower(trim((string)($programOption['source_program_name'] ?? '')));
        if ($optionSourceName !== $sourceProgramName) {
            continue;
        }

        if ($fallbackProgramId === 0) {
            $fallbackProgramId = (int)($programOption['program_id'] ?? 0);
        }

        $sameCollege = $collegeName === '' || strtolower(trim((string)($programOption['college_name'] ?? ''))) === $collegeName;
        $sameCampus = $campusName === '' || strtolower(trim((string)($programOption['campus_name'] ?? ''))) === $campusName;
        if ($sameCollege && $sameCampus) {
            return (int)($programOption['program_id'] ?? 0);
        }
    }

    return $fallbackProgramId;
}

function synk_student_fetch_portal_profile(mysqli $conn, string $email): ?array
{
    synk_student_portal_profile_ensure_schema($conn);

    $normalizedEmail = synk_normalize_email($email);
    if ($normalizedEmail === '') {
        return null;
    }

    $tableName = synk_student_portal_profile_table_name();
    $stmt = $conn->prepare("
        SELECT
            sp.profile_id,
            sp.email_address,
            sp.student_number,
            sp.program_id,
            sp.locked_at,
            sp.created_at,
            sp.updated_at,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            c.college_id,
            c.college_code,
            c.college_name,
            ca.campus_id,
            ca.campus_code,
            ca.campus_name
        FROM `{$tableName}` sp
        LEFT JOIN tbl_program p
            ON p.program_id = sp.program_id
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        WHERE sp.email_address = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $normalizedEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    return is_array($row) ? $row : null;
}

function synk_student_portal_profile_is_complete(?array $profile): bool
{
    return is_array($profile)
        && trim((string)($profile['student_number'] ?? '')) !== ''
        && (int)($profile['program_id'] ?? 0) > 0
        && trim((string)($profile['locked_at'] ?? '')) !== '';
}

function synk_student_sync_locked_profile_to_directory(mysqli $conn, string $email, string $studentNumber, array $program): void
{
    if (!synk_student_directory_table_exists($conn)) {
        return;
    }

    $normalizedEmail = synk_normalize_email($email);
    $normalizedStudentNumber = trim($studentNumber);
    if ($normalizedEmail === '' || !preg_match('/^\d{4,10}$/', $normalizedStudentNumber)) {
        return;
    }

    $studentNumberInt = (int)$normalizedStudentNumber;
    $programId = (int)($program['program_id'] ?? 0);

    $stmt = $conn->prepare("
        UPDATE tbl_student_management
        SET
            student_number = ?,
            program_id = ?
        WHERE email_address = ?
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'iis',
        $studentNumberInt,
        $programId,
        $normalizedEmail
    );
    $stmt->execute();
    $stmt->close();
}

function synk_student_save_first_portal_profile_setup(
    mysqli $conn,
    string $email,
    int $programId,
    string $studentNumber
): array {
    synk_student_portal_profile_ensure_schema($conn);

    $normalizedEmail = synk_normalize_email($email);
    if ($normalizedEmail === '' || !synk_student_directory_email_exists($conn, $normalizedEmail)) {
        throw new RuntimeException('Your student email is not registered in the student directory.');
    }

    $normalizedStudentNumber = trim($studentNumber);
    if (!preg_match('/^\d{4,10}$/', $normalizedStudentNumber)) {
        throw new RuntimeException('Provide a valid ID number using digits only.');
    }

    $program = synk_student_find_profile_program_by_id($conn, $programId);
    if (!$program) {
        throw new RuntimeException('Select a valid enrolled program.');
    }

    $existingProfile = synk_student_fetch_portal_profile($conn, $normalizedEmail);
    if (synk_student_portal_profile_is_complete($existingProfile)) {
        throw new RuntimeException('Your enrolled program and ID number are already locked.');
    }

    $tableName = synk_student_portal_profile_table_name();

    if ($existingProfile && (int)($existingProfile['profile_id'] ?? 0) > 0) {
        $profileId = (int)$existingProfile['profile_id'];
        $stmt = $conn->prepare("
            UPDATE `{$tableName}`
            SET
                student_number = ?,
                program_id = ?,
                locked_at = COALESCE(locked_at, NOW())
            WHERE profile_id = ?
              AND locked_at IS NULL
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException('Unable to lock the student profile setup.');
        }

        $stmt->bind_param('sii', $normalizedStudentNumber, $programId, $profileId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            INSERT INTO `{$tableName}` (
                email_address,
                student_number,
                program_id,
                locked_at
            ) VALUES (?, ?, ?, NOW())
        ");

        if (!$stmt) {
            throw new RuntimeException('Unable to save the student profile setup.');
        }

        $stmt->bind_param('ssi', $normalizedEmail, $normalizedStudentNumber, $programId);
        $stmt->execute();
        $stmt->close();
    }

    synk_student_sync_locked_profile_to_directory($conn, $normalizedEmail, $normalizedStudentNumber, $program);

    $profile = synk_student_fetch_portal_profile($conn, $normalizedEmail);
    if (!synk_student_portal_profile_is_complete($profile)) {
        throw new RuntimeException('The student profile setup could not be finalized.');
    }

    return $profile;
}

function synk_student_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function synk_student_title_case(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    return ucwords(strtolower($trimmed));
}

function synk_student_format_program_label(array $row, bool $includeCollege = false): string
{
    $programCode = strtoupper(trim((string)($row['program_code'] ?? '')));
    $programName = synk_student_title_case((string)($row['program_name'] ?? ''));
    $major = synk_student_title_case((string)($row['major'] ?? $row['program_major'] ?? ''));

    $label = trim(implode(' - ', array_filter([$programCode, $programName], static function ($value) {
        return trim((string)$value) !== '';
    })));

    if ($major !== '') {
        $label .= ($label !== '' ? ' ' : '') . '(Major in ' . $major . ')';
    }

    if ($includeCollege) {
        $collegeName = trim((string)($row['college_name'] ?? ''));
        $campusCode = strtoupper(trim((string)($row['campus_code'] ?? '')));
        $scopeParts = [];
        if ($collegeName !== '') {
            $scopeParts[] = $collegeName;
        }
        if ($campusCode !== '') {
            $scopeParts[] = $campusCode;
        }
        if (!empty($scopeParts)) {
            $label .= ($label !== '' ? ' ' : '') . '[' . implode(' | ', $scopeParts) . ']';
        }
    }

    return $label !== '' ? $label : 'Program';
}

function synk_student_format_setup_program_label(array $row): string
{
    $programCode = strtoupper(trim((string)($row['program_code'] ?? '')));
    $major = synk_student_title_case((string)($row['major'] ?? $row['program_major'] ?? ''));
    $labelParts = array_values(array_filter([$programCode, $major], static function ($value) {
        return trim((string)$value) !== '';
    }));

    if (!empty($labelParts)) {
        return implode(' - ', $labelParts);
    }

    $programName = synk_student_title_case((string)($row['program_name'] ?? ''));
    return $programName !== '' ? $programName : 'Program';
}

function synk_student_select_valid_id(array $rows, int $selectedId, string $keyName): int
{
    if ($selectedId <= 0) {
        return 0;
    }

    foreach ($rows as $row) {
        if ((int)($row[$keyName] ?? 0) === $selectedId) {
            return $selectedId;
        }
    }

    return 0;
}

function synk_student_fetch_campuses(mysqli $conn): array
{
    $sql = "
        SELECT DISTINCT
            ca.campus_id,
            ca.campus_code,
            ca.campus_name
        FROM tbl_campus ca
        INNER JOIN tbl_college c
            ON c.campus_id = ca.campus_id
           AND c.status = 'active'
        INNER JOIN tbl_program p
            ON p.college_id = c.college_id
           AND p.status = 'active'
        ORDER BY ca.campus_name ASC, ca.campus_code ASC
    ";

    $result = $conn->query($sql);
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->close();
    }

    return $rows;
}

function synk_student_fetch_colleges(mysqli $conn, int $campusId = 0): array
{
    $sql = "
        SELECT DISTINCT
            c.college_id,
            c.college_code,
            c.college_name,
            ca.campus_id,
            ca.campus_code,
            ca.campus_name
        FROM tbl_college c
        INNER JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        INNER JOIN tbl_program p
            ON p.college_id = c.college_id
           AND p.status = 'active'
        WHERE c.status = 'active'
    ";

    $types = '';
    $params = [];

    if ($campusId > 0) {
        $sql .= " AND c.campus_id = ?";
        $types .= 'i';
        $params[] = $campusId;
    }

    $sql .= "
        ORDER BY ca.campus_name ASC, c.college_name ASC, c.college_code ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        synk_stmt_bind_params($stmt, $types, $params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function synk_student_fetch_dashboard_summary(mysqli $conn, int $ayId, int $semester, int $campusId = 0, int $collegeId = 0): array
{
    $summary = [
        'program_count' => 0,
        'prospectus_count' => 0,
        'section_count' => 0,
        'schedule_count' => 0,
    ];
    $scheduledOfferingJoin = synk_schedule_merge_scheduled_offering_join_sql($conn, 'sched', 'o');

    $sql = "
        SELECT
            COUNT(DISTINCT p.program_id) AS program_count,
            COUNT(DISTINCT h.prospectus_id) AS prospectus_count,
            COUNT(DISTINCT CASE WHEN sec.section_id IS NOT NULL THEN o.section_id END) AS section_count,
            COUNT(DISTINCT sched.offering_id) AS schedule_count
        FROM tbl_program p
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        INNER JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        LEFT JOIN tbl_prospectus_header h
            ON h.program_id = p.program_id
        LEFT JOIN tbl_prospectus_offering o
            ON o.program_id = p.program_id
           AND o.ay_id = ?
           AND o.semester = ?
        LEFT JOIN tbl_sections sec
            ON sec.section_id = o.section_id
           AND sec.status = 'active'
        {$scheduledOfferingJoin}
        WHERE p.status = 'active'
          AND c.status = 'active'
    ";

    $types = 'ii';
    $params = [$ayId, $semester];

    if ($campusId > 0) {
        $sql .= " AND c.campus_id = ?";
        $types .= 'i';
        $params[] = $campusId;
    }

    if ($collegeId > 0) {
        $sql .= " AND c.college_id = ?";
        $types .= 'i';
        $params[] = $collegeId;
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $summary;
    }

    synk_stmt_bind_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return $summary;
    }

    return [
        'program_count' => (int)($row['program_count'] ?? 0),
        'prospectus_count' => (int)($row['prospectus_count'] ?? 0),
        'section_count' => (int)($row['section_count'] ?? 0),
        'schedule_count' => (int)($row['schedule_count'] ?? 0),
    ];
}

function synk_student_fetch_dashboard_program_cards(
    mysqli $conn,
    int $ayId,
    int $semester,
    int $campusId = 0,
    int $collegeId = 0,
    int $limit = 8
): array {
    $scheduledOfferingJoin = synk_schedule_merge_scheduled_offering_join_sql($conn, 'sched', 'o');
    $sql = "
        SELECT
            p.program_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            c.college_id,
            c.college_code,
            c.college_name,
            ca.campus_id,
            ca.campus_code,
            ca.campus_name,
            COUNT(DISTINCT h.prospectus_id) AS prospectus_count,
            COUNT(DISTINCT CASE WHEN sec.section_id IS NOT NULL THEN o.section_id END) AS section_count,
            COUNT(DISTINCT sched.offering_id) AS schedule_count
        FROM tbl_program p
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        INNER JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        LEFT JOIN tbl_prospectus_header h
            ON h.program_id = p.program_id
        LEFT JOIN tbl_prospectus_offering o
            ON o.program_id = p.program_id
           AND o.ay_id = ?
           AND o.semester = ?
        LEFT JOIN tbl_sections sec
            ON sec.section_id = o.section_id
           AND sec.status = 'active'
        {$scheduledOfferingJoin}
        WHERE p.status = 'active'
          AND c.status = 'active'
    ";

    $types = 'ii';
    $params = [$ayId, $semester];

    if ($campusId > 0) {
        $sql .= " AND c.campus_id = ?";
        $types .= 'i';
        $params[] = $campusId;
    }

    if ($collegeId > 0) {
        $sql .= " AND c.college_id = ?";
        $types .= 'i';
        $params[] = $collegeId;
    }

    $sql .= "
        GROUP BY
            p.program_id,
            p.program_code,
            p.program_name,
            p.major,
            c.college_id,
            c.college_code,
            c.college_name,
            ca.campus_id,
            ca.campus_code,
            ca.campus_name
        HAVING prospectus_count > 0 OR section_count > 0
        ORDER BY
            schedule_count DESC,
            section_count DESC,
            prospectus_count DESC,
            p.program_name ASC,
            p.major ASC,
            p.program_code ASC
        LIMIT ?
    ";

    $safeLimit = max(1, min(24, $limit));
    $types .= 'i';
    $params[] = $safeLimit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    synk_stmt_bind_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function synk_student_fetch_programs_for_prospectus(mysqli $conn, int $campusId = 0, int $collegeId = 0): array
{
    $sql = "
        SELECT
            p.program_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            c.college_id,
            c.college_code,
            c.college_name,
            ca.campus_id,
            ca.campus_code,
            ca.campus_name,
            COUNT(DISTINCT h.prospectus_id) AS prospectus_count
        FROM tbl_program p
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        INNER JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        INNER JOIN tbl_prospectus_header h
            ON h.program_id = p.program_id
        WHERE p.status = 'active'
          AND c.status = 'active'
    ";

    $types = '';
    $params = [];

    if ($campusId > 0) {
        $sql .= " AND c.campus_id = ?";
        $types .= 'i';
        $params[] = $campusId;
    }

    if ($collegeId > 0) {
        $sql .= " AND c.college_id = ?";
        $types .= 'i';
        $params[] = $collegeId;
    }

    $sql .= "
        GROUP BY
            p.program_id,
            p.program_code,
            p.program_name,
            p.major,
            c.college_id,
            c.college_code,
            c.college_name,
            ca.campus_id,
            ca.campus_code,
            ca.campus_name
        ORDER BY
            ca.campus_name ASC,
            c.college_name ASC,
            p.program_name ASC,
            p.major ASC,
            p.program_code ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        synk_stmt_bind_params($stmt, $types, $params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function synk_student_fetch_prospectus_versions(mysqli $conn, int $programId): array
{
    if ($programId <= 0) {
        return [];
    }

    $sql = "
        SELECT
            h.prospectus_id,
            h.program_id,
            h.cmo_no,
            h.effective_sy,
            COUNT(DISTINCT ys.pys_id) AS term_count,
            COUNT(ps.ps_id) AS subject_count
        FROM tbl_prospectus_header h
        LEFT JOIN tbl_prospectus_year_sem ys
            ON ys.prospectus_id = h.prospectus_id
        LEFT JOIN tbl_prospectus_subjects ps
            ON ps.pys_id = ys.pys_id
        WHERE h.program_id = ?
        GROUP BY
            h.prospectus_id,
            h.program_id,
            h.cmo_no,
            h.effective_sy
        ORDER BY h.effective_sy DESC, h.prospectus_id DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function synk_student_normalize_prospectus_subject_values(float $lecHours, float $labValue, ?float $storedTotalUnits = null): array
{
    $safeLecHours = max(0.0, $lecHours);
    $displayLabHours = round(
        synk_lab_contact_hours($safeLecHours, max(0.0, $labValue), (float)($storedTotalUnits ?? 0.0)),
        2
    );

    return [
        'lec_units' => round($safeLecHours, 2),
        'lab_units' => $displayLabHours,
        'total_units' => round(synk_subject_units_total($safeLecHours, $displayLabHours, 0.0), 2),
    ];
}

function synk_student_fetch_prospectus_sheet(mysqli $conn, int $prospectusId): ?array
{
    if ($prospectusId <= 0) {
        return null;
    }

    $headerSql = "
        SELECT
            h.prospectus_id,
            h.program_id,
            h.cmo_no,
            h.effective_sy,
            p.program_name,
            p.program_code,
            COALESCE(p.major, '') AS major,
            c.college_name,
            c.college_code,
            ca.campus_name,
            ca.campus_code
        FROM tbl_prospectus_header h
        INNER JOIN tbl_program p
            ON p.program_id = h.program_id
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        INNER JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        WHERE h.prospectus_id = ?
        LIMIT 1
    ";

    $headerStmt = $conn->prepare($headerSql);
    if (!$headerStmt) {
        return null;
    }

    $headerStmt->bind_param('i', $prospectusId);
    $headerStmt->execute();
    $header = $headerStmt->get_result()->fetch_assoc();
    $headerStmt->close();

    if (!$header) {
        return null;
    }

    $structure = [];
    $subjects = [];

    $detailSql = "
        SELECT
            pys.pys_id,
            pys.year_level,
            pys.semester,
            ps.ps_id,
            s.sub_code,
            s.sub_description,
            ps.lec_units,
            ps.lab_units,
            ps.total_units,
            ps.prerequisites,
            ps.sort_order
        FROM tbl_prospectus_year_sem pys
        LEFT JOIN tbl_prospectus_subjects ps
            ON ps.pys_id = pys.pys_id
        LEFT JOIN tbl_subject_masterlist s
            ON s.sub_id = ps.sub_id
        WHERE pys.prospectus_id = ?
        ORDER BY pys.year_level ASC, pys.semester ASC, ps.sort_order ASC, s.sub_code ASC
    ";

    $detailStmt = $conn->prepare($detailSql);
    if (!$detailStmt) {
        return null;
    }

    $detailStmt->bind_param('i', $prospectusId);
    $detailStmt->execute();
    $detailResult = $detailStmt->get_result();

    while ($row = $detailResult->fetch_assoc()) {
        $year = (string)($row['year_level'] ?? '');
        $semester = (string)($row['semester'] ?? '');

        if (!isset($structure[$year])) {
            $structure[$year] = [];
        }
        if (!isset($structure[$year][$semester])) {
            $structure[$year][$semester] = [];
        }

        if ((int)($row['ps_id'] ?? 0) <= 0) {
            continue;
        }

        if (!isset($subjects[$year])) {
            $subjects[$year] = [];
        }
        if (!isset($subjects[$year][$semester])) {
            $subjects[$year][$semester] = [];
        }

        $normalized = synk_student_normalize_prospectus_subject_values(
            (float)($row['lec_units'] ?? 0),
            (float)($row['lab_units'] ?? 0),
            isset($row['total_units']) && $row['total_units'] !== null ? (float)$row['total_units'] : null
        );

        $subjects[$year][$semester][] = [
            'sub_code' => (string)($row['sub_code'] ?? ''),
            'sub_description' => (string)($row['sub_description'] ?? ''),
            'lec_units' => $normalized['lec_units'],
            'lab_units' => $normalized['lab_units'],
            'total_units' => $normalized['total_units'],
            'prerequisites' => trim((string)($row['prerequisites'] ?? '')) !== ''
                ? trim((string)$row['prerequisites'])
                : 'None',
        ];
    }

    $detailStmt->close();

    return [
        'header' => $header,
        'structure' => $structure,
        'subjects' => $subjects,
    ];
}

function synk_student_fetch_programs_for_schedule(
    mysqli $conn,
    int $ayId,
    int $semester,
    int $campusId = 0,
    int $collegeId = 0
): array {
    if ($ayId <= 0 || $semester <= 0) {
        return [];
    }

    $sql = "
        SELECT
            p.program_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            c.college_id,
            c.college_code,
            c.college_name,
            ca.campus_id,
            ca.campus_code,
            ca.campus_name,
            COUNT(DISTINCT sec.section_id) AS section_count
        FROM tbl_program p
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        INNER JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        INNER JOIN tbl_sections sec
            ON sec.program_id = p.program_id
           AND sec.ay_id = ?
           AND sec.semester = ?
           AND sec.status = 'active'
        INNER JOIN tbl_prospectus_offering o
            ON o.section_id = sec.section_id
           AND o.program_id = sec.program_id
           AND o.ay_id = sec.ay_id
           AND o.semester = sec.semester
        WHERE p.status = 'active'
          AND c.status = 'active'
    ";

    $types = 'ii';
    $params = [$ayId, $semester];

    if ($campusId > 0) {
        $sql .= " AND c.campus_id = ?";
        $types .= 'i';
        $params[] = $campusId;
    }

    if ($collegeId > 0) {
        $sql .= " AND c.college_id = ?";
        $types .= 'i';
        $params[] = $collegeId;
    }

    $sql .= "
        GROUP BY
            p.program_id,
            p.program_code,
            p.program_name,
            p.major,
            c.college_id,
            c.college_code,
            c.college_name,
            ca.campus_id,
            ca.campus_code,
            ca.campus_name
        ORDER BY
            ca.campus_name ASC,
            c.college_name ASC,
            p.program_name ASC,
            p.major ASC,
            p.program_code ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    synk_stmt_bind_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function synk_student_fetch_sections_for_program(mysqli $conn, int $ayId, int $semester, int $programId): array
{
    if ($ayId <= 0 || $semester <= 0 || $programId <= 0) {
        return [];
    }

    $sql = "
        SELECT
            sec.section_id,
            sec.full_section,
            sec.year_level,
            sec.section_name,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            c.college_name,
            ca.campus_name
        FROM tbl_sections sec
        INNER JOIN tbl_program p
            ON p.program_id = sec.program_id
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        INNER JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        INNER JOIN tbl_prospectus_offering o
            ON o.section_id = sec.section_id
           AND o.program_id = sec.program_id
           AND o.ay_id = sec.ay_id
           AND o.semester = sec.semester
        WHERE sec.program_id = ?
          AND sec.ay_id = ?
          AND sec.semester = ?
          AND sec.status = 'active'
        GROUP BY
            sec.section_id,
            sec.full_section,
            sec.year_level,
            sec.section_name,
            p.program_code,
            p.program_name,
            p.major,
            c.college_name,
            ca.campus_name
        ORDER BY
            sec.year_level ASC,
            sec.section_name ASC,
            sec.full_section ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iii', $programId, $ayId, $semester);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $fullSection = trim((string)($row['full_section'] ?? ''));
        $programCode = trim((string)($row['program_code'] ?? ''));
        $sectionName = trim((string)($row['section_name'] ?? ''));

        $row['label'] = $fullSection !== '' ? $fullSection : trim($programCode . ' ' . $sectionName);
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function synk_student_fetch_section_schedule(mysqli $conn, int $sectionId, int $ayId, int $semester): array
{
    $payload = [
        'meta' => [],
        'rows' => [],
        'rooms_text' => 'TBA',
    ];

    if ($sectionId <= 0 || $ayId <= 0 || $semester <= 0) {
        return $payload;
    }

    $contextSql = "
        SELECT
            sec.section_id,
            sec.section_name,
            sec.full_section,
            sec.year_level,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS program_major,
            c.college_name,
            c.college_code,
            ca.campus_name,
            ca.campus_code
        FROM tbl_sections sec
        INNER JOIN tbl_program p
            ON p.program_id = sec.program_id
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        INNER JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        WHERE sec.section_id = ?
          AND sec.ay_id = ?
          AND sec.semester = ?
          AND sec.status = 'active'
        LIMIT 1
    ";

    $contextStmt = $conn->prepare($contextSql);
    if (!$contextStmt) {
        return $payload;
    }

    $contextStmt->bind_param('iii', $sectionId, $ayId, $semester);
    $contextStmt->execute();
    $context = $contextStmt->get_result()->fetch_assoc();
    $contextStmt->close();

    if (!$context) {
        return $payload;
    }

    $offeringIds = [];
    $offeringStmt = $conn->prepare("
        SELECT offering_id
        FROM tbl_prospectus_offering
        WHERE section_id = ?
          AND ay_id = ?
          AND semester = ?
        ORDER BY offering_id ASC
    ");
    if (!$offeringStmt) {
        return $payload;
    }

    $offeringStmt->bind_param('iii', $sectionId, $ayId, $semester);
    $offeringStmt->execute();
    $offeringResult = $offeringStmt->get_result();

    while ($offeringResult && ($offeringRow = $offeringResult->fetch_assoc())) {
        $offeringId = (int)($offeringRow['offering_id'] ?? 0);
        if ($offeringId > 0) {
            $offeringIds[] = $offeringId;
        }
    }

    $offeringStmt->close();

    $effectiveOfferingIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (!empty($effectiveOfferingIds)) {
        $mergeContext = synk_schedule_merge_load_display_context($conn, $effectiveOfferingIds);
        $effectiveOfferingIds = [];

        foreach ($offeringIds as $offeringId) {
            $mergeInfo = $mergeContext[$offeringId] ?? null;
            $effectiveOfferingIds[] = (int)($mergeInfo['owner_offering_id'] ?? $offeringId);
        }

        $effectiveOfferingIds = synk_schedule_merge_normalize_offering_ids($effectiveOfferingIds);
    }

    if (empty($effectiveOfferingIds)) {
        $payload['meta'] = $context;
        return $payload;
    }

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 'sec', 'sc', 'ps', 'pys', 'ph');
    $scheduleSql = "
        SELECT
            cs.schedule_id,
            cs.schedule_type,
            cs.time_start,
            cs.time_end,
            cs.days_json,
            sm.sub_code AS subject_code,
            sm.sub_description AS subject_description,
            COALESCE(
                NULLIF(
                    TRIM(
                        GROUP_CONCAT(
                            DISTINCT CONCAT(f.last_name, ', ', f.first_name)
                            ORDER BY f.last_name ASC, f.first_name ASC
                            SEPARATOR ' / '
                        )
                    ),
                    ''
                ),
                'TBA'
            ) AS faculty_name,
            COALESCE(
                NULLIF(TRIM(r.room_code), ''),
                NULLIF(TRIM(r.room_name), ''),
                'TBA'
            ) AS room_label
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = po.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        LEFT JOIN tbl_faculty_workload_sched fws
            ON fws.schedule_id = cs.schedule_id
        LEFT JOIN tbl_faculty f
            ON f.faculty_id = fws.faculty_id
        WHERE cs.offering_id IN (" . implode(',', array_map('intval', $effectiveOfferingIds)) . ")
          AND po.ay_id = ?
          AND po.semester = ?
        GROUP BY
            cs.schedule_id,
            cs.schedule_type,
            cs.time_start,
            cs.time_end,
            cs.days_json,
            sm.sub_code,
            sm.sub_description,
            r.room_code,
            r.room_name
        ORDER BY
            cs.time_start ASC,
            sm.sub_code ASC,
            FIELD(cs.schedule_type, 'LEC', 'LAB'),
            cs.schedule_id ASC
    ";

    $scheduleStmt = $conn->prepare($scheduleSql);
    if (!$scheduleStmt) {
        return $payload;
    }

    $scheduleStmt->bind_param('ii', $ayId, $semester);
    $scheduleStmt->execute();
    $scheduleResult = $scheduleStmt->get_result();

    $rooms = [];
    $rows = [];

    while ($row = $scheduleResult->fetch_assoc()) {
        $daysRaw = json_decode((string)($row['days_json'] ?? '[]'), true);
        if (!is_array($daysRaw)) {
            $daysRaw = [];
        }

        $roomLabel = trim((string)($row['room_label'] ?? ''));
        if ($roomLabel !== '' && strtoupper($roomLabel) !== 'TBA') {
            $rooms[$roomLabel] = true;
        }

        $rows[] = [
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? ''),
            'subject_code' => (string)($row['subject_code'] ?? ''),
            'subject_description' => (string)($row['subject_description'] ?? ''),
            'faculty_name' => (string)($row['faculty_name'] ?? 'TBA'),
            'room_label' => $roomLabel !== '' ? $roomLabel : 'TBA',
            'days_raw' => $daysRaw,
        ];
    }

    $scheduleStmt->close();

    $context['rooms_text'] = !empty($rooms) ? implode(', ', array_keys($rooms)) : 'TBA';
    $payload['meta'] = $context;
    $payload['rows'] = $rows;
    $payload['rooms_text'] = $context['rooms_text'];

    return $payload;
}

function synk_student_schedule_day_columns(): array
{
    return [
        ['key' => 'M', 'label' => 'Mon'],
        ['key' => 'T', 'label' => 'Tue'],
        ['key' => 'W', 'label' => 'Wed'],
        ['key' => 'TH', 'label' => 'Thu'],
        ['key' => 'F', 'label' => 'Fri'],
        ['key' => 'S', 'label' => 'Sat'],
    ];
}

function synk_student_schedule_day_order(): array
{
    return [
        'M' => 1,
        'T' => 2,
        'W' => 3,
        'TH' => 4,
        'F' => 5,
        'S' => 6,
    ];
}

function synk_student_normalize_grid_day_token(string $day): string
{
    $token = strtoupper(trim($day));
    return $token === 'TH' ? 'TH' : $token;
}

function synk_student_normalize_grid_days($days): array
{
    if (!is_array($days)) {
        return [];
    }

    $order = synk_student_schedule_day_order();
    $seen = [];

    foreach ($days as $day) {
        $token = synk_student_normalize_grid_day_token((string)$day);
        if ($token !== '' && isset($order[$token])) {
            $seen[$token] = true;
        }
    }

    $tokens = array_keys($seen);
    usort($tokens, static function ($left, $right) use ($order) {
        return ($order[$left] ?? 99) <=> ($order[$right] ?? 99);
    });

    return $tokens;
}

function synk_student_time_to_minutes(string $time): int
{
    if (!preg_match('/^(\d{2}):(\d{2})/', trim($time), $matches)) {
        return 0;
    }

    return ((int)$matches[1] * 60) + (int)$matches[2];
}

function synk_student_minutes_to_ampm(int $minutes): string
{
    $safeMinutes = max(0, $minutes);
    $hour = (int)floor($safeMinutes / 60);
    $minute = $safeMinutes % 60;
    $period = $hour >= 12 ? 'PM' : 'AM';
    $hour = $hour % 12 ?: 12;

    return $hour . ':' . str_pad((string)$minute, 2, '0', STR_PAD_LEFT) . ' ' . $period;
}

function synk_student_format_time_range(int $startMinutes, int $endMinutes): string
{
    return synk_student_minutes_to_ampm($startMinutes) . ' - ' . synk_student_minutes_to_ampm($endMinutes);
}

function synk_student_build_schedule_matrix(array $rows): array
{
    $slotInterval = 30;
    $dayStart = 7 * 60;
    $dayEnd = 18 * 60;
    $slots = [];
    $occupancy = [];
    $warnings = [];

    foreach (synk_student_schedule_day_columns() as $dayColumn) {
        $occupancy[$dayColumn['key']] = [];
    }

    for ($minutes = $dayStart; $minutes < $dayEnd; $minutes += $slotInterval) {
        $slots[] = $minutes;
    }

    foreach ($rows as $row) {
        $start = synk_student_time_to_minutes((string)($row['time_start'] ?? ''));
        $end = synk_student_time_to_minutes((string)($row['time_end'] ?? ''));
        $days = synk_student_normalize_grid_days($row['days_raw'] ?? []);

        $start = max($dayStart, $start);
        $end = min($dayEnd, $end);

        if (empty($days) || $end <= $start) {
            continue;
        }

        $hasConflict = false;

        foreach ($days as $dayKey) {
            for ($cursor = $start; $cursor < $end; $cursor += $slotInterval) {
                if (isset($occupancy[$dayKey][$cursor])) {
                    $hasConflict = true;
                    break 2;
                }
            }
        }

        if ($hasConflict) {
            $subjectCode = trim((string)($row['subject_code'] ?? '')) ?: 'Scheduled class';
            $warnings[] = $subjectCode . ' overlaps an existing cell and was skipped in the grid view.';
            continue;
        }

        $block = $row;
        $block['_slot_span'] = max(1, (int)ceil(($end - $start) / $slotInterval));

        foreach ($days as $dayKey) {
            $occupancy[$dayKey][$start] = [
                'type' => 'start',
                'block' => $block,
            ];

            for ($cursor = $start + $slotInterval; $cursor < $end; $cursor += $slotInterval) {
                $occupancy[$dayKey][$cursor] = [
                    'type' => 'covered',
                    'start' => $start,
                ];
            }
        }
    }

    return [
        'slots' => $slots,
        'occupancy' => $occupancy,
        'warnings' => array_values(array_unique($warnings)),
    ];
}
