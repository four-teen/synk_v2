-- feature/79 schema update, compared with main at 2447241.
-- Select the live main application database before running this file.
-- Requires the existing InnoDB tbl_management_attendance_roster table
-- with PRIMARY KEY (event_id, student_id), both INT UNSIGNED.

CREATE TABLE IF NOT EXISTS tbl_management_attendance_checkpoints (
    event_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    checked_in TINYINT UNSIGNED NOT NULL,
    checked_out TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id,student_id),
    CONSTRAINT fk_management_checkpoints_roster FOREIGN KEY (event_id,student_id)
        REFERENCES tbl_management_attendance_roster (event_id,student_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_attendance_corrections (
    correction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    previous_present TINYINT UNSIGNED NOT NULL,
    corrected_present TINYINT UNSIGNED NOT NULL,
    previous_in TINYINT UNSIGNED NULL,
    previous_out TINYINT UNSIGNED NULL,
    corrected_in TINYINT UNSIGNED NULL,
    corrected_out TINYINT UNSIGNED NULL,
    reason VARCHAR(500) NOT NULL,
    recorded_by INT NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (correction_id),
    KEY ix_management_attendance_correction (event_id,student_id,correction_id),
    CONSTRAINT fk_management_corrections_roster FOREIGN KEY (event_id,student_id)
        REFERENCES tbl_management_attendance_roster (event_id,student_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
