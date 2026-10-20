-- Clear a class list without removing shared profiles or college directory access.
-- New table only; existing tables are unchanged.
CREATE TABLE IF NOT EXISTS tbl_student_class_clear_history (
    clear_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_key CHAR(64) CHARACTER SET ascii NOT NULL,
    student_enrollment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    student_number VARCHAR(32) CHARACTER SET ascii NOT NULL,
    campus_id INT UNSIGNED NOT NULL,
    college_id INT UNSIGNED NOT NULL,
    program_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    offering_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    faculty_id INT UNSIGNED NOT NULL,
    membership_snapshot LONGTEXT NOT NULL,
    actor_user_id INT UNSIGNED NOT NULL,
    actor_name VARCHAR(255) NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    directory_retained TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (clear_id),
    UNIQUE KEY uk_class_clear_membership (student_enrollment_id),
    KEY idx_class_clear_operation (operation_key),
    KEY idx_class_clear_directory (college_id,campus_id,directory_retained,student_id,ay_id,semester),
    KEY idx_class_clear_class (offering_id,recorded_at,clear_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
