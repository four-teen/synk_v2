-- Additive evaluation-only storage. Existing responses, rosters and workloads are unchanged.
CREATE TABLE IF NOT EXISTS tbl_college_evaluation_subject_exclusions (
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    excluded TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reason VARCHAR(1000) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (college_id,ay_id,semester,subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_college_evaluation_subject_exclusion_audit (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    college_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NOT NULL,
    previous_value TEXT NOT NULL,
    new_value TEXT NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY ix_evaluation_subject_audit (college_id,ay_id,semester,audit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- An immutable submitted response keeps the subject associations captured with its draft.
-- No foreign key: clearing retains these associations as historical evidence without
-- changing the existing evaluation clearing workflow. Missing headers never enter scores.
CREATE TABLE IF NOT EXISTS tbl_student_faculty_evaluation_subject_scope (
    evaluation_id INT UNSIGNED NOT NULL,
    subjects_json LONGTEXT NOT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (evaluation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
