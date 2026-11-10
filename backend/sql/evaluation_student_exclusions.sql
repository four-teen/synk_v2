-- Evaluation-only rules. No response, profile, roster or academic setting is changed.
CREATE TABLE IF NOT EXISTS tbl_college_evaluation_student_exclusions (
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    excluded TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reason VARCHAR(1000) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (college_id,ay_id,semester,student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_college_evaluation_student_exclusion_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NOT NULL,
    previous_value TEXT NOT NULL,
    new_value TEXT NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY ix_evaluation_student_audit (college_id,ay_id,semester,audit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
