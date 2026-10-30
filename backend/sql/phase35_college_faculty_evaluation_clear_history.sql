-- Select the intended Synk database. Additive storage only; no evaluations are cleared.
CREATE TABLE IF NOT EXISTS tbl_college_faculty_evaluation_clear_history (
    clear_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    campus_id INT UNSIGNED NOT NULL,
    college_id INT UNSIGNED NOT NULL,
    faculty_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    instrument_version VARCHAR(64) NOT NULL,
    submitted_count INT UNSIGNED NOT NULL,
    draft_count INT UNSIGNED NOT NULL,
    evaluation_snapshot LONGTEXT NOT NULL,
    actor_user_id INT UNSIGNED NOT NULL,
    actor_name VARCHAR(255) NOT NULL,
    cleared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (clear_id),
    UNIQUE KEY uq_college_faculty_evaluation_clear_operation (operation_key),
    KEY ix_college_faculty_evaluation_clear_scope (college_id,ay_id,semester,faculty_id,clear_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
