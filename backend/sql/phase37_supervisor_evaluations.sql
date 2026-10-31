-- Evaluation-only additions. No existing table, schedule or workload is altered.
CREATE TABLE IF NOT EXISTS tbl_supervisor_evaluation_settings (
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    is_open TINYINT UNSIGNED NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (college_id,ay_id,semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_supervisor_evaluation_assignments (
    assignment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    faculty_id INT UNSIGNED NOT NULL,
    reviewer_user_id INT UNSIGNED NOT NULL,
    reviewer_role ENUM('program_chair','college_dean') NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    updated_by INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assignment_id),
    UNIQUE KEY uq_sef_faculty_term (college_id,ay_id,semester,faculty_id),
    KEY ix_sef_reviewer (reviewer_user_id,college_id,ay_id,semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_supervisor_evaluations (
    evaluation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id BIGINT UNSIGNED NOT NULL,
    assignment_revision INT UNSIGNED NOT NULL,
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    faculty_id INT UNSIGNED NOT NULL,
    evaluator_user_id INT UNSIGNED NOT NULL,
    evaluator_faculty_id INT UNSIGNED NOT NULL,
    evaluator_role ENUM('program_chair','college_dean') NOT NULL,
    evaluator_name VARCHAR(255) NOT NULL,
    faculty_name VARCHAR(255) NOT NULL,
    context_snapshot LONGTEXT NOT NULL,
    instrument_version VARCHAR(64) NOT NULL,
    answers_snapshot LONGTEXT NOT NULL,
    comment_text TEXT NOT NULL,
    total_score SMALLINT UNSIGNED NOT NULL,
    question_count SMALLINT UNSIGNED NOT NULL,
    submission_status ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    submission_token VARCHAR(64) NULL,
    submitted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (evaluation_id),
    UNIQUE KEY uq_sef_assignment_revision (assignment_id,assignment_revision),
    UNIQUE KEY uq_sef_submission_token (submission_token),
    KEY ix_sef_results (college_id,ay_id,semester,submission_status,faculty_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_supervisor_evaluation_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    faculty_id INT UNSIGNED NOT NULL DEFAULT 0,
    actor_user_id INT UNSIGNED NOT NULL,
    action_name VARCHAR(40) NOT NULL,
    previous_snapshot LONGTEXT NOT NULL,
    new_snapshot LONGTEXT NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY ix_sef_audit (college_id,ay_id,semester,audit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
